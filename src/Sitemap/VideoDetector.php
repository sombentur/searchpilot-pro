<?php
/**
 * Video detection and metadata cache.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;

defined( 'ABSPATH' ) || exit;

/**
 * Finds YouTube, Vimeo and self-hosted videos in post content (embed
 * blocks, oEmbed URLs on their own line, iframes, [video] shortcodes and
 * <video> tags) and caches their metadata in post meta
 * `_searchpilot_videos`. Remote oEmbed metadata is fetched only in the
 * background (after a save and by an hourly backlog job) — never while a
 * sitemap or page is being served.
 *
 * Cached shape (per video): provider (youtube|vimeo|self), id, title,
 * description, thumbnail_loc, player_loc, content_loc, duration (seconds|0),
 * upload_date (ISO 8601 or '').
 */
class VideoDetector {

	const META      = '_searchpilot_videos';
	const META_HASH = '_searchpilot_videos_hash';
	const QUEUE     = 'searchpilot_video_queue';
	const QUEUE_MAX = 20000;
	const MAX_PER_POST = 10;
	const VIDEO_EXTENSIONS = array( 'mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings  Settings.
	 * @param State        $state     State.
	 * @param HttpClient   $http      HTTP client.
	 * @param Scheduler    $scheduler Scheduler.
	 * @param ContentTypes $types     Content types.
	 * @param Logger       $logger    Logger.
	 */
	public function __construct( Settings $settings, State $state, HttpClient $http, Scheduler $scheduler, ContentTypes $types, Logger $logger ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->http      = $http;
		$this->scheduler = $scheduler;
		$this->types     = $types;
		$this->logger    = $logger;
	}

	/**
	 * Lowest post id saved during an import in this request (backlog restart point).
	 *
	 * @var int
	 */
	private $import_min = 0;

	/**
	 * Hooks (called by SitemapModule).
	 */
	public function register() {
		add_action( 'save_post', array( $this, 'on_save' ), 30, 2 );
		add_action( 'searchpilot_video_drain', array( $this, 'drain' ) );
		add_action( 'searchpilot_video_backlog', array( $this, 'backlog' ) );
		// Legacy single events queued by earlier builds.
		add_action( 'searchpilot_video_refresh', array( $this, 'refresh_async' ) );
		// The backlog runs in its own request (TimeBudget is request-relative).
		add_action( 'searchpilot_hourly', array( $this, 'schedule_backlog' ), 40 );
	}

	/**
	 * Whether video data is used (video sitemap or video schema).
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'sitemap.video', true ) || (bool) $this->settings->get( 'schema.video', true );
	}

	/**
	 * Cached videos of a post (never fetches anything).
	 *
	 * @param int $post_id Post id.
	 * @return array[]
	 */
	public static function get_videos( $post_id ) {
		$videos = get_post_meta( (int) $post_id, self::META, true );
		if ( ! is_array( $videos ) ) {
			return array();
		}
		$out = array();
		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) || empty( $video['provider'] ) || empty( $video['id'] ) ) {
				continue;
			}
			$out[] = array(
				'provider'      => (string) $video['provider'],
				'id'            => (string) $video['id'],
				'title'         => (string) ( $video['title'] ?? '' ),
				'description'   => (string) ( $video['description'] ?? '' ),
				'thumbnail_loc' => (string) ( $video['thumbnail_loc'] ?? '' ),
				'player_loc'    => (string) ( $video['player_loc'] ?? '' ),
				'content_loc'   => (string) ( $video['content_loc'] ?? '' ),
				'duration'      => (int) ( $video['duration'] ?? 0 ),
				'upload_date'   => (string) ( $video['upload_date'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Extracts video references from post content (pure).
	 *
	 * @param string $content Raw post content (blocks/HTML/shortcodes).
	 * @return array<int,array{provider:string,id:string,url:string,poster:string}>
	 */
	public static function extract( $content ) {
		$content = (string) $content;
		$found   = array();
		$add     = static function ( $url, $poster = '' ) use ( &$found ) {
			$url    = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
			$parsed = self::parse_video_url( $url );
			if ( ! $parsed ) {
				return;
			}
			$key = $parsed['provider'] . ':' . $parsed['id'];
			if ( isset( $found[ $key ] ) ) {
				if ( '' === $found[ $key ]['poster'] && '' !== $poster ) {
					$found[ $key ]['poster'] = $poster;
				}
				return;
			}
			$found[ $key ] = array(
				'provider' => $parsed['provider'],
				'id'       => $parsed['id'],
				'url'      => $parsed['url'],
				'poster'   => (string) $poster,
			);
		};

		// Embed blocks (core/embed and the legacy core-embed/* variations).
		if ( preg_match_all( '#<!--\s+wp:(?:core-)?embed(?:/[a-z0-9\-]+)?\s+(\{.*?\})\s+/?-->#s', $content, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && ! empty( $attrs['url'] ) ) {
					$add( $attrs['url'] );
				}
			}
		}
		// Iframes.
		if ( preg_match_all( '#<iframe\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1#is', $content, $m ) ) {
			foreach ( $m[2] as $src ) {
				$add( $src );
			}
		}
		// <video> tags (src attribute or nested <source>), with posters.
		if ( preg_match_all( '#<video\b([^>]*)>(.*?)</video>#is', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $video ) {
				$poster = self::attr( $video[1], 'poster' );
				$src    = self::attr( $video[1], 'src' );
				if ( '' !== $src ) {
					$add( $src, $poster );
				}
				if ( preg_match_all( '#<source\b([^>]*)>#i', $video[2], $sources ) ) {
					foreach ( $sources[1] as $source_attrs ) {
						$add( self::attr( $source_attrs, 'src' ), $poster );
					}
				}
			}
		}
		// [video] shortcodes.
		if ( preg_match_all( '#\[video\b([^\]]*)\]#i', $content, $m ) ) {
			foreach ( $m[1] as $attrs ) {
				$poster = self::attr( $attrs, 'poster' );
				foreach ( array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'mov' ) as $name ) {
					$value = self::attr( $attrs, $name );
					if ( '' !== $value ) {
						$add( $value, $poster );
					}
				}
			}
		}
		// [embed]URL[/embed].
		if ( preg_match_all( '#\[embed\b[^\]]*\](.*?)\[/embed\]#is', $content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$add( wp_strip_all_tags( $url ) );
			}
		}
		// oEmbed URLs alone on a line (optionally inside a paragraph).
		if ( preg_match_all( '#^\s*(?:<p[^>]*>)?\s*(https?://[^\s<>"\']+)\s*(?:</p>)?\s*$#mi', $content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$add( $url );
			}
		}
		return array_values( $found );
	}

	/**
	 * Identifies a video URL (pure).
	 *
	 * @param string $url URL.
	 * @return array{provider:string,id:string,url:string}|null
	 */
	public static function parse_video_url( $url ) {
		$url = trim( (string) $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}
		$host  = strtolower( $parts['host'] );
		$host  = preg_replace( '/^(www|m|music)\./', '', $host );
		$path  = $parts['path'] ?? '/';
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		if ( in_array( $host, array( 'youtube.com', 'youtu.be', 'youtube-nocookie.com' ), true ) ) {
			$id = '';
			if ( 'youtu.be' === $host ) {
				$id = trim( $path, '/' );
			} elseif ( preg_match( '#^/(?:embed|v|e|shorts|live)/([A-Za-z0-9_\-]{11})#', $path, $m ) ) {
				$id = $m[1];
			} elseif ( '/watch' === rtrim( $path, '/' ) && ! empty( $query['v'] ) && is_string( $query['v'] ) ) {
				$id = $query['v'];
			}
			$id = (string) preg_replace( '#[/?&].*$#', '', $id );
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{11}$/', $id ) ) {
				return null;
			}
			return array(
				'provider' => 'youtube',
				'id'       => $id,
				'url'      => 'https://www.youtube.com/watch?v=' . $id,
			);
		}

		if ( in_array( $host, array( 'vimeo.com', 'player.vimeo.com' ), true ) ) {
			$id = '';
			if ( 'player.vimeo.com' === $host && preg_match( '#^/video/(\d{3,12})#', $path, $m ) ) {
				$id = $m[1];
			} elseif ( preg_match( '#^/(?:channels/[^/]+/|groups/[^/]+/videos/|album/\d+/video/|showcase/\d+/video/)?(\d{3,12})(?:/[a-f0-9]+)?/?$#i', $path, $m ) ) {
				$id = $m[1];
			}
			if ( '' === $id ) {
				return null;
			}
			return array(
				'provider' => 'vimeo',
				'id'       => $id,
				'url'      => 'https://vimeo.com/' . $id,
			);
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::VIDEO_EXTENSIONS, true ) ) {
			$clean = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path;
			return array(
				'provider' => 'self',
				'id'       => substr( md5( $clean ), 0, 16 ),
				'url'      => $clean,
			);
		}
		return null;
	}

	/**
	 * Reads an attribute from an attribute string (pure).
	 *
	 * @param string $attrs Attribute string.
	 * @param string $name  Attribute name.
	 * @return string
	 */
	private static function attr( $attrs, $name ) {
		if ( preg_match( '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', (string) $attrs, $m ) ) {
			return trim( $m[2] );
		}
		if ( preg_match( '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*([^\s"\'>\]]+)#i', (string) $attrs, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * save_post: refresh the cache in the background.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ) {
		if ( ! $this->enabled() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			// No per-post work during imports: the backlog rescans from the lowest imported id.
			if ( ! $this->import_min ) {
				add_action( 'shutdown', array( $this, 'after_import' ), 20 );
			}
			$this->import_min = $this->import_min ? min( $this->import_min, (int) $post_id ) : (int) $post_id;
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			if ( metadata_exists( 'post', $post_id, self::META ) ) {
				delete_post_meta( $post_id, self::META );
				delete_post_meta( $post_id, self::META_HASH );
			}
			return;
		}
		if ( ! in_array( $post->post_type, $this->types->sitemap_post_types(), true ) ) {
			return;
		}
		$hash = $this->content_hash( $post );
		if ( get_post_meta( $post_id, self::META_HASH, true ) === $hash ) {
			return;
		}
		$this->enqueue( (int) $post_id );
	}

	/**
	 * Adds a post to the refresh queue (one non-autoloaded option) and
	 * schedules ONE deduplicated drain event.
	 *
	 * @param int $post_id Post id.
	 */
	public function enqueue( $post_id ) {
		wp_cache_delete( self::QUEUE, 'options' );
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! in_array( (int) $post_id, $queue, true ) && count( $queue ) < self::QUEUE_MAX ) {
			$queue[] = (int) $post_id;
			update_option( self::QUEUE, $queue, false );
		}
		$this->scheduler->enqueue_async( 'searchpilot_video_drain', array(), 30 );
	}

	/**
	 * Drain event: refreshes queued posts within a time budget and re-queues
	 * itself while work remains.
	 */
	public function drain() {
		Lock::run(
			'video_drain',
			300,
			function () {
				$budget    = new TimeBudget( 20 );
				$processed = array();
				wp_cache_delete( self::QUEUE, 'options' );
				$queue = get_option( self::QUEUE, array() );
				foreach ( is_array( $queue ) ? $queue : array() as $post_id ) {
					if ( $budget->exhausted() ) {
						break;
					}
					$this->refresh( (int) $post_id );
					$processed[] = (int) $post_id;
				}
				// Re-read so posts queued meanwhile are kept.
				wp_cache_delete( self::QUEUE, 'options' );
				$current   = get_option( self::QUEUE, array() );
				$remaining = array_values( array_diff( array_map( 'intval', is_array( $current ) ? $current : array() ), $processed ) );
				if ( $remaining ) {
					update_option( self::QUEUE, $remaining, false );
					$this->scheduler->enqueue_async( 'searchpilot_video_drain', array(), 10 );
				} else {
					delete_option( self::QUEUE );
				}
			}
		);
	}

	/**
	 * Legacy per-post event from earlier builds: moves the post to the queue.
	 *
	 * @param int $post_id Post id.
	 */
	public function refresh_async( $post_id ) {
		$this->enqueue( (int) $post_id );
	}

	/**
	 * shutdown after an import: restart the backlog from the lowest imported id (one State write).
	 */
	public function after_import() {
		if ( ! $this->import_min ) {
			return;
		}
		$status = (array) $this->state->get( 'video_backlog', array() );
		$cursor = isset( $status['cursor'] ) ? (int) $status['cursor'] : 0;
		$this->state->set(
			'video_backlog',
			array(
				'cursor' => ! empty( $status['done'] ) ? $this->import_min - 1 : min( $cursor, $this->import_min - 1 ),
				'done'   => false,
			)
		);
		$this->import_min = 0;
		$this->scheduler->enqueue_async( 'searchpilot_video_backlog', array(), 120 );
	}

	/**
	 * Detects videos in a post and caches their metadata (background only).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $force   Refresh even when the content did not change.
	 * @return array[] Cached videos.
	 */
	public function refresh( $post_id, $force = false ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			if ( $post ) {
				delete_post_meta( $post->ID, self::META );
				delete_post_meta( $post->ID, self::META_HASH );
			}
			return array();
		}
		$hash = $this->content_hash( $post );
		if ( ! $force && get_post_meta( $post->ID, self::META_HASH, true ) === $hash ) {
			return self::get_videos( $post->ID );
		}
		$found  = array_slice( self::extract( $post->post_content ), 0, self::MAX_PER_POST );
		$videos = array();
		foreach ( $found as $ref ) {
			$video = $this->describe( $ref, $post );
			if ( $video ) {
				$videos[] = $video;
			}
		}
		if ( $videos ) {
			update_post_meta( $post->ID, self::META, $videos );
		} else {
			delete_post_meta( $post->ID, self::META );
		}
		update_post_meta( $post->ID, self::META_HASH, $hash );
		return $videos;
	}

	/**
	 * Hourly: starts the backlog scan in its own request while it is unfinished.
	 */
	public function schedule_backlog() {
		if ( ! $this->enabled() ) {
			return;
		}
		$status = (array) $this->state->get( 'video_backlog', array() );
		if ( empty( $status['done'] ) ) {
			$this->scheduler->enqueue_async( 'searchpilot_video_backlog', array(), 10 );
		}
	}

	/**
	 * Backlog event: scans older posts (published before the detector existed
	 * or imported) in batches until the time budget is used, then continues
	 * in a new request while posts remain.
	 */
	public function backlog() {
		if ( ! $this->enabled() ) {
			return;
		}
		$status = (array) $this->state->get( 'video_backlog', array() );
		if ( ! empty( $status['done'] ) ) {
			return;
		}
		Lock::run(
			'video_backlog',
			300,
			function () use ( $status ) {
				global $wpdb;
				$budget = new TimeBudget( 20 );
				$cursor = (int) ( $status['cursor'] ?? 0 );
				$types  = $this->types->sitemap_post_types();
				$done   = empty( $types );
				if ( ! $done ) {
					$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
					while ( ! $budget->exhausted() ) {
						$likes  = array( '%youtu%', '%vimeo%', '%[video%', '%<video%', '%wp:video%' );
						$params = array_merge( $likes, $types, array( $cursor ) );
						// Only ids and a cheap marker test in SQL; content is loaded for matches only.
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) AS has_video FROM {$wpdb->posts} WHERE post_type IN ({$holders}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 200", $params ) );
						if ( empty( $rows ) ) {
							$done = true;
							break;
						}
						$stopped = false;
						foreach ( $rows as $row ) {
							if ( $budget->exhausted() ) {
								$stopped = true;
								break;
							}
							if ( ! empty( $row->has_video ) ) {
								$this->refresh( (int) $row->ID );
							}
							$cursor = (int) $row->ID;
						}
						if ( ! $stopped && count( $rows ) < 200 ) {
							$done = true;
							break;
						}
					}
				}
				$this->state->set(
					'video_backlog',
					array(
						'cursor' => $cursor,
						'done'   => $done,
					)
				);
				if ( ! $done ) {
					$this->scheduler->enqueue_async( 'searchpilot_video_backlog', array(), 30 );
				}
			}
		);
	}

	/**
	 * Restarts the backlog scan (e.g. after video features were switched on).
	 */
	public function reset_backlog() {
		$this->state->set(
			'video_backlog',
			array(
				'cursor' => 0,
				'done'   => false,
			)
		);
		$this->scheduler->enqueue_async( 'searchpilot_video_backlog', array(), 60 );
	}

	/**
	 * Builds the cached record of one video.
	 *
	 * @param array    $ref  Reference from extract().
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	private function describe( array $ref, \WP_Post $post ) {
		$fallback_title = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$fallback_desc  = self::post_summary( $post );
		$video          = array(
			'provider'      => $ref['provider'],
			'id'            => $ref['id'],
			'title'         => $fallback_title,
			'description'   => $fallback_desc,
			'thumbnail_loc' => '',
			'player_loc'    => '',
			'content_loc'   => '',
			'duration'      => 0,
			'upload_date'   => '',
		);

		if ( 'youtube' === $ref['provider'] ) {
			$meta                   = $this->oembed( 'youtube', $ref['id'] );
			$video['title']         = self::clean_text( $meta['title'] ?? '', 200 ) ?: $fallback_title;
			$video['thumbnail_loc'] = 'https://i.ytimg.com/vi/' . rawurlencode( $ref['id'] ) . '/hqdefault.jpg';
			$video['player_loc']    = 'https://www.youtube.com/embed/' . rawurlencode( $ref['id'] );
			return $video;
		}

		if ( 'vimeo' === $ref['provider'] ) {
			$meta                   = $this->oembed( 'vimeo', $ref['id'] );
			$video['title']         = self::clean_text( $meta['title'] ?? '', 200 ) ?: $fallback_title;
			$description            = self::clean_text( $meta['description'] ?? '', 2048 );
			$video['description']   = '' !== $description ? $description : $fallback_desc;
			$thumb                  = esc_url_raw( (string) ( $meta['thumbnail_url'] ?? '' ), array( 'https' ) );
			$video['thumbnail_loc'] = $thumb;
			$video['player_loc']    = 'https://player.vimeo.com/video/' . rawurlencode( $ref['id'] );
			$video['duration']      = max( 0, min( 28800, (int) ( $meta['duration'] ?? 0 ) ) );
			$upload                 = (string) ( $meta['upload_date'] ?? '' );
			$ts                     = '' !== $upload ? strtotime( $upload . ' UTC' ) : false;
			$video['upload_date']   = $ts ? gmdate( 'c', $ts ) : '';
			return $video;
		}

		// Self-hosted file.
		$url = esc_url_raw( $ref['url'] );
		if ( '' === $url ) {
			return null;
		}
		$video['content_loc'] = $url;
		$attachment_id        = (int) attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( $attachment ) {
				$title                = wp_strip_all_tags( $attachment->post_title );
				$video['title']       = '' !== $title ? $title : $fallback_title;
				$desc                 = self::clean_text( '' !== $attachment->post_excerpt ? $attachment->post_excerpt : $attachment->post_content, 2048 );
				$video['description'] = '' !== $desc ? $desc : $fallback_desc;
				$video['upload_date'] = gmdate( 'c', strtotime( $attachment->post_date_gmt . ' UTC' ) );
			}
			$meta              = wp_get_attachment_metadata( $attachment_id );
			$video['duration'] = is_array( $meta ) && ! empty( $meta['length'] ) ? max( 0, min( 28800, (int) $meta['length'] ) ) : 0;
			$thumb_id          = (int) get_post_thumbnail_id( $attachment_id );
			if ( $thumb_id ) {
				$video['thumbnail_loc'] = (string) wp_get_attachment_url( $thumb_id );
			}
		}
		if ( '' === $video['thumbnail_loc'] && '' !== $ref['poster'] ) {
			$video['thumbnail_loc'] = esc_url_raw( \SearchPilot\Support\Url::absolute( $ref['poster'] ) );
		}
		if ( '' === $video['thumbnail_loc'] ) {
			$thumb_id               = (int) get_post_thumbnail_id( $post );
			$video['thumbnail_loc'] = $thumb_id ? (string) wp_get_attachment_url( $thumb_id ) : '';
		}
		return $video;
	}

	/**
	 * Fetches oEmbed metadata (cached one week, failures one day).
	 *
	 * The request URL is built from the parsed id only, so content cannot
	 * make SearchPilot fetch arbitrary addresses.
	 *
	 * @param string $provider youtube|vimeo.
	 * @param string $id       Video id.
	 * @return array
	 */
	private function oembed( $provider, $id ) {
		$key    = 'searchpilot_vid_' . md5( $provider . ':' . $id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'youtube' === $provider ) {
			$endpoint = 'https://www.youtube.com/oembed';
			$query    = array(
				'url'    => 'https://www.youtube.com/watch?v=' . $id,
				'format' => 'json',
			);
		} else {
			$endpoint = 'https://vimeo.com/api/oembed.json';
			$query    = array( 'url' => 'https://vimeo.com/' . $id );
		}
		$response = $this->http->get(
			$endpoint,
			array(
				'query'               => $query,
				'timeout'             => 8,
				'redirection'         => 2,
				'channel'             => 'sitemap',
				'log_errors'          => false,
				'limit_response_size' => 262144,
			)
		);
		$data = $response->ok() ? $response->json() : null;
		if ( ! is_array( $data ) ) {
			if ( 404 !== $response->code && 401 !== $response->code && 403 !== $response->code ) {
				$this->logger->debug( 'sitemap', sprintf( 'Video metadata for %s:%s unavailable (%s).', $provider, $id, $response->error_message() ) );
			}
			set_transient( $key, array(), DAY_IN_SECONDS );
			return array();
		}
		$clean = array(
			'title'         => self::clean_text( $data['title'] ?? '', 200 ),
			'description'   => self::clean_text( $data['description'] ?? '', 2048 ),
			'thumbnail_url' => esc_url_raw( (string) ( $data['thumbnail_url'] ?? '' ), array( 'https' ) ),
			'duration'      => (int) ( $data['duration'] ?? 0 ),
			'upload_date'   => sanitize_text_field( (string) ( $data['upload_date'] ?? '' ) ),
		);
		set_transient( $key, $clean, WEEK_IN_SECONDS );
		return $clean;
	}

	/**
	 * Plain-text summary of a post (excerpt or opening words).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function post_summary( \WP_Post $post ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ), 40, '…' );
		return self::clean_text( $text, 2048 );
	}

	/**
	 * Untrusted text → single-line plain text.
	 *
	 * @param mixed $text   Text.
	 * @param int   $length Max length.
	 * @return string
	 */
	private static function clean_text( $text, $length ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		return mb_substr( $text, 0, $length );
	}

	/**
	 * Hash of everything the cached record depends on.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function content_hash( \WP_Post $post ) {
		return md5( $post->post_content . '|' . $post->post_title . '|' . $post->post_excerpt . '|' . (int) get_post_thumbnail_id( $post ) );
	}
}

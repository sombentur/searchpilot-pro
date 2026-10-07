<?php
/**
 * Google Indexing API (job postings and livestreams only).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Google supports the Indexing API only for pages with JobPosting or
 * BroadcastEvent (livestream VideoObject) structured data and may revoke
 * access when it is used for anything else.
 *
 * Two stages keep ordinary articles out:
 *
 *   is_candidate()  cheap hint: an allowed custom post type the administrator
 *                   ticked, the editor's "Indexing API" flag, or crawl evidence;
 *   check()         proof: the published page's JSON-LD or microdata really
 *                   contains JobPosting, or BroadcastEvent inside a VideoObject
 *                   (crawler evidence or a cached loopback fetch).
 *
 * The worker only sends pages that pass check(); SearchPilot never outputs
 * these schema types itself, so the markup must come from the site's job or
 * event plugin/theme.
 */
class IndexingApi {

	const ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	const TYPES = array( 'URL_UPDATED', 'URL_DELETED' );

	/**
	 * Schema types of ordinary content: such post types can never be ticked.
	 */
	const ARTICLE_SCHEMAS = array( 'Article', 'BlogPosting', 'NewsArticle' );

	const CRAWL_META = '_searchpilot_crawl_schema';

	const CACHE_PREFIX = 'searchpilot_iapi_';

	const CACHE_TTL = 43200; // 12 hours.

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Auth.
	 *
	 * @var GoogleAuth
	 */
	private $auth;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param GoogleAuth   $auth     Auth.
	 * @param RateLimiter  $limiter  Rate limiter.
	 * @param MetaStore    $meta     Meta store.
	 * @param ContentTypes $types    Content types.
	 * @param HttpClient   $http     HTTP client.
	 * @param Logger       $logger   Logger.
	 */
	public function __construct( Settings $settings, GoogleAuth $auth, RateLimiter $limiter, MetaStore $meta, ContentTypes $types, HttpClient $http, Logger $logger ) {
		$this->settings = $settings;
		$this->auth     = $auth;
		$this->limiter  = $limiter;
		$this->meta     = $meta;
		$this->types    = $types;
		$this->http     = $http;
		$this->logger   = $logger;
	}

	/**
	 * Enabled in settings AND Google is connected with the Indexing API permission.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->setting_on() && $this->auth->is_configured() && $this->auth->has_scope( OAuthClient::SCOPE_INDEXING );
	}

	/**
	 * Whether the administrator switched the Indexing API on.
	 *
	 * @return bool
	 */
	public function setting_on() {
		return (bool) $this->settings->get( 'indexing.google_indexing_api', false );
	}

	/**
	 * Whether the OAuth grant (or service account) can use the Indexing API.
	 *
	 * @return bool
	 */
	public function scope_granted() {
		return $this->auth->is_configured() && $this->auth->has_scope( OAuthClient::SCOPE_INDEXING );
	}

	/**
	 * Post types that may be marked as job/livestream types: public custom
	 * types only — never posts, pages or types described as articles.
	 *
	 * @return array<string,string> name => label
	 */
	public function allowed_post_types() {
		$out = array();
		foreach ( $this->types->post_types() as $name => $type ) {
			if ( in_array( $name, array( 'post', 'page', 'attachment' ), true ) || in_array( (string) $type['schema'], self::ARTICLE_SCHEMAS, true ) ) {
				continue;
			}
			$out[ $name ] = (string) $type['label'];
		}
		return $out;
	}

	/**
	 * Ticked job/livestream post types (restricted to allowed_post_types()).
	 *
	 * @return string[]
	 */
	public function post_types() {
		$ticked = array_values( array_filter( array_map( 'sanitize_key', (array) $this->settings->get( 'indexing.indexing_api_post_types', array() ) ) ) );
		return array_values( array_intersect( $ticked, array_keys( $this->allowed_post_types() ) ) );
	}

	/**
	 * Cheap hint that a post may be a job posting or livestream. Never
	 * enough on its own: check() must confirm the structured data.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function is_candidate( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( in_array( $post->post_type, $this->post_types(), true ) ) {
			return true;
		}
		$field = strtolower( (string) $this->meta->get_post_field( $post->ID, 'indexing_api' ) );
		if ( in_array( $field, array( 'jobposting', 'broadcastevent' ), true ) ) {
			return true;
		}
		$crawl = get_post_meta( $post->ID, self::CRAWL_META, true );
		return is_array( $crawl ) && '' !== self::structured_data_type( array(), (array) ( $crawl['types'] ?? array() ) );
	}

	/**
	 * Verified eligibility from evidence already available (never fetches).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function is_eligible( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $this->is_candidate( $post ) ) {
			return false;
		}
		return (bool) $this->check( $post, false )['eligible'];
	}

	/**
	 * Confirms that the published page carries JobPosting or livestream
	 * (BroadcastEvent inside VideoObject) structured data.
	 *
	 * @param int|\WP_Post $post        Post.
	 * @param bool         $allow_fetch Fetch the page when no fresh evidence exists (background only).
	 * @return array{eligible:bool,type:string,reason:string,message:string,source:string}
	 */
	public function check( $post, $allow_fetch = true ) {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return self::result( false, '', 'not_published', 'none' );
		}
		if ( ! $this->is_candidate( $post ) ) {
			return self::result( false, '', 'not_candidate', 'none' );
		}
		$modified = (string) $post->post_modified_gmt;
		$cache    = get_transient( self::CACHE_PREFIX . $post->ID );
		if ( is_array( $cache ) && ( $cache['modified'] ?? '' ) === $modified && isset( $cache['result'] ) ) {
			return (array) $cache['result'];
		}

		// Crawler evidence newer than the last edit.
		$crawl = get_post_meta( $post->ID, self::CRAWL_META, true );
		if ( is_array( $crawl ) && isset( $crawl['types'], $crawl['at'] ) ) {
			$at = (int) strtotime( (string) $crawl['at'] );
			if ( $at >= (int) strtotime( $modified . ' UTC' ) && $at > time() - WEEK_IN_SECONDS ) {
				$type   = self::structured_data_type( array(), (array) $crawl['types'] );
				$result = self::result( '' !== $type, $type, '' !== $type ? 'ok' : 'no_markup', 'crawl' );
				$this->remember( $post, $result );
				return $result;
			}
		}
		if ( ! $allow_fetch ) {
			return self::result( false, '', 'unverified', 'none' );
		}

		$url      = (string) get_permalink( $post );
		$response = $this->http->get(
			$url,
			array(
				'channel'             => 'google',
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => 2 * MB_IN_BYTES,
				'log_errors'          => false,
			)
		);
		if ( 200 !== $response->code ) {
			// Not cached: a temporary failure must not decide eligibility for 12 hours.
			return self::result( false, '', 'fetch_failed', 'fetch' );
		}
		$doc    = HtmlDocument::parse( $response->body, $url );
		$type   = self::structured_data_type( array_column( (array) $doc->json_ld(), 'data' ), (array) $doc->microdata_types() );
		$result = self::result( '' !== $type, $type, '' !== $type ? 'ok' : 'no_markup', 'fetch' );
		$this->remember( $post, $result );
		return $result;
	}

	/**
	 * Caches a check result for the post's current revision.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $result Result.
	 */
	private function remember( \WP_Post $post, array $result ) {
		set_transient(
			self::CACHE_PREFIX . $post->ID,
			array(
				'modified' => (string) $post->post_modified_gmt,
				'result'   => $result,
			),
			self::CACHE_TTL
		);
	}

	/**
	 * Check result with a human explanation.
	 *
	 * @param bool   $eligible Eligible.
	 * @param string $type     JobPosting|BroadcastEvent|''.
	 * @param string $reason   ok|not_published|not_candidate|no_markup|unverified|fetch_failed.
	 * @param string $source   crawl|fetch|none.
	 * @return array
	 */
	private static function result( $eligible, $type, $reason, $source ) {
		return array(
			'eligible' => (bool) $eligible,
			'type'     => (string) $type,
			'reason'   => (string) $reason,
			'message'  => self::reason_message( $reason ),
			'source'   => (string) $source,
		);
	}

	/**
	 * Explanation of a check reason (shown on skipped queue rows).
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_message( $reason ) {
		switch ( $reason ) {
			case 'ok':
				return __( 'The page contains JobPosting or livestream structured data.', 'searchpilot-pro' );
			case 'not_published':
				return __( 'Not sent to the Indexing API: the page is not published.', 'searchpilot-pro' );
			case 'not_candidate':
				return __( 'Not sent to the Indexing API: the page is not marked as a job posting or livestream.', 'searchpilot-pro' );
			case 'no_markup':
				return __( 'Not sent to the Indexing API: the published page contains no JobPosting or livestream (BroadcastEvent inside VideoObject) structured data. Google accepts only those pages; SearchPilot does not add this markup itself.', 'searchpilot-pro' );
			case 'fetch_failed':
				return __( 'The page could not be fetched to confirm its structured data. SearchPilot tries again later.', 'searchpilot-pro' );
			case 'never_sent':
				return __( 'Not sent to the Indexing API: this URL was never submitted there, so no removal notice is needed.', 'searchpilot-pro' );
		}
		return __( 'Not sent to the Indexing API: its structured data has not been verified yet.', 'searchpilot-pro' );
	}

	/**
	 * Which Indexing-API type a page's structured data qualifies for.
	 *
	 * JSON-LD: any node typed JobPosting, or a BroadcastEvent nested in a
	 * VideoObject (VideoObject.publication). Microdata / flat type lists:
	 * JobPosting, or both VideoObject and BroadcastEvent. Pure.
	 *
	 * @param array    $json_ld   Decoded JSON-LD blocks.
	 * @param string[] $microdata Microdata / flat type list.
	 * @return string JobPosting|BroadcastEvent|''
	 */
	public static function structured_data_type( array $json_ld, array $microdata ) {
		$found = '';
		$clean = static function ( $type ) {
			return is_string( $type ) ? (string) preg_replace( '#^https?://schema\.org/#i', '', trim( $type ) ) : '';
		};
		$walk  = static function ( $node, $in_video ) use ( &$walk, &$found, $clean ) {
			if ( ! is_array( $node ) || '' !== $found ) {
				return;
			}
			$types = isset( $node['@type'] ) ? array_map( $clean, (array) $node['@type'] ) : array();
			if ( in_array( 'JobPosting', $types, true ) ) {
				$found = 'JobPosting';
				return;
			}
			if ( $in_video && in_array( 'BroadcastEvent', $types, true ) ) {
				$found = 'BroadcastEvent';
				return;
			}
			$video = $in_video || in_array( 'VideoObject', $types, true );
			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) && '@context' !== $key ) {
					$walk( $value, $video );
				}
			}
		};
		foreach ( $json_ld as $block ) {
			$walk( $block, false );
		}
		if ( '' !== $found ) {
			return $found;
		}
		$flat = array_map( $clean, $microdata );
		if ( in_array( 'JobPosting', $flat, true ) ) {
			return 'JobPosting';
		}
		if ( in_array( 'VideoObject', $flat, true ) && in_array( 'BroadcastEvent', $flat, true ) ) {
			return 'BroadcastEvent';
		}
		return '';
	}

	/**
	 * Publishes a URL notification.
	 *
	 * @param string $url  URL of this site.
	 * @param string $type URL_UPDATED|URL_DELETED.
	 * @return true|\WP_Error Error data: status, retryable, retry_after.
	 */
	public function publish( $url, $type = 'URL_UPDATED' ) {
		$type = in_array( $type, self::TYPES, true ) ? $type : 'URL_UPDATED';
		$url  = Url::normalize( $url );
		if ( '' === $url || ! Url::is_internal( $url ) ) {
			return new \WP_Error(
				'searchpilot_invalid_url',
				__( 'Only URLs of this site can be sent to the Indexing API.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( ! $this->is_enabled() ) {
			return new \WP_Error(
				'searchpilot_indexing_disabled',
				__( 'The Google Indexing API is switched off or not authorized.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( ! $this->limiter->attempt( 'google_indexing' ) ) {
			return SearchConsole::quota_error( 'google_indexing', $this->limiter->blocked_until( 'google_indexing' ) );
		}
		$response = $this->auth->request(
			'POST',
			self::ENDPOINT,
			array(
				'json'    => array(
					'url'  => $url,
					'type' => $type,
				),
				'channel' => 'google',
				'timeout' => 20,
			),
			array( OAuthClient::SCOPE_INDEXING )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response->ok() ) {
			return true;
		}
		if ( $response->is_rate_limited() ) {
			$retry = $response->retry_after();
			if ( $retry > 0 ) {
				$this->limiter->block( 'google_indexing', time() + $retry );
			} else {
				$this->limiter->block_until_reset( 'google_indexing' );
			}
			return SearchConsole::quota_error( 'google_indexing', $this->limiter->blocked_until( 'google_indexing' ) );
		}
		$message = $response->error_message();
		if ( 403 === $response->code ) {
			return new \WP_Error(
				'searchpilot_indexing_forbidden',
				/* translators: %s: Google's message. */
				sprintf( __( 'Google refused the Indexing API request (%s). The connected account must be an Owner of the Search Console property and the “Web Search Indexing API” must be enabled in the Google Cloud project.', 'searchpilot-pro' ), $message ),
				array(
					'status'    => 403,
					'retryable' => false,
				)
			);
		}
		return new \WP_Error(
			'searchpilot_indexing_failed',
			/* translators: %s: error message. */
			sprintf( __( 'Indexing API request failed: %s', 'searchpilot-pro' ), $message ),
			array(
				'status'      => $response->code,
				'retryable'   => $response->is_retryable(),
				'retry_after' => $response->retry_after(),
			)
		);
	}
}

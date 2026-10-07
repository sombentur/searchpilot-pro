<?php
/**
 * Sitemap health monitoring (SearchPilot's or the owning plugin's sitemap).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\HttpResponse;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\RobotsParser;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the active sitemap through a loopback request (origin IP when
 * configured), validates it, follows child sitemaps incrementally (a
 * limited number per run, continued hourly), counts URLs, compares
 * freshness with the newest content and reads Search Console's sitemap
 * report. In companion mode it marks `in_sitemap` on URL registry rows
 * found in the owner's sitemap.
 *
 * Results live in State `sitemap_health`; problems become issues.
 */
class SitemapHealth {

	const STATE           = 'sitemap_health';
	const FETCHES_PER_RUN = 25;
	const MAX_CHILDREN    = 500;
	const MAX_BYTES       = 20971520;

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
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Builder.
	 *
	 * @var SitemapBuilder
	 */
	private $builder;

	/**
	 * Cache.
	 *
	 * @var SitemapCache
	 */
	private $cache;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * Fetches made in this run.
	 *
	 * @var int
	 */
	private $fetches = 0;

	/**
	 * Companion cycle id (seen-URL chunks are stored under it).
	 *
	 * @var string
	 */
	private $seen_cycle = '';

	/**
	 * Number of seen-URL chunks written for the cycle.
	 *
	 * @var int
	 */
	private $seen_chunks = 0;

	/**
	 * Seen URL hashes not written yet.
	 *
	 * @var string[]
	 */
	private $seen_buffer = array();

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param State            $state     State.
	 * @param ConflictDetector $conflicts Conflict detector.
	 * @param HttpClient       $http      HTTP client.
	 * @param RateLimiter      $limiter   Rate limiter.
	 * @param IssueRepository  $issues    Issues.
	 * @param Logger           $logger    Logger.
	 * @param SitemapBuilder   $builder   Builder.
	 * @param SitemapCache     $cache     Cache.
	 * @param ContentTypes     $types     Content types.
	 * @param CachePurger      $purger    Cache purger.
	 */
	public function __construct( Settings $settings, State $state, ConflictDetector $conflicts, HttpClient $http, RateLimiter $limiter, IssueRepository $issues, Logger $logger, SitemapBuilder $builder, SitemapCache $cache, ContentTypes $types, CachePurger $purger ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->conflicts = $conflicts;
		$this->http      = $http;
		$this->limiter   = $limiter;
		$this->issues    = $issues;
		$this->logger    = $logger;
		$this->builder   = $builder;
		$this->cache     = $cache;
		$this->types     = $types;
		$this->purger    = $purger;
	}

	/**
	 * Shared instance.
	 *
	 * @return SitemapHealth
	 */
	private static function instance() {
		return \SearchPilot\Plugin::instance()->get( self::class );
	}

	/* ------------------------------------------------------------------ *
	 * Ownership & URLs
	 * ------------------------------------------------------------------ */

	/**
	 * Who generates the sitemap: searchpilot, a plugin id, core (WordPress) or off.
	 *
	 * @return string
	 */
	public function owner() {
		$owner = $this->conflicts->owner( 'sitemap' );
		if ( 'off' === $owner ) {
			return self::core_enabled() ? 'core' : 'off';
		}
		return $owner;
	}

	/**
	 * Human name of the sitemap owner.
	 *
	 * @param string $owner Owner id.
	 * @return string
	 */
	public function owner_name( $owner ) {
		if ( 'searchpilot' === $owner ) {
			return __( 'SearchPilot Pro', 'searchpilot-pro' );
		}
		if ( 'core' === $owner ) {
			return __( 'WordPress', 'searchpilot-pro' );
		}
		if ( 'off' === $owner ) {
			return __( 'Nobody (sitemaps are off)', 'searchpilot-pro' );
		}
		$plugins = $this->conflicts->detect();
		return (string) ( $plugins[ $owner ]['name'] ?? $owner );
	}

	/**
	 * Whether WordPress core sitemaps would be served.
	 *
	 * @return bool
	 */
	private static function core_enabled() {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}
		$server = wp_sitemaps_get_server();
		return is_object( $server ) && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled();
	}

	/**
	 * The sitemap index in use (ours or the owning plugin's).
	 *
	 * @return string '' when no sitemap is generated.
	 */
	public static function active_sitemap_url() {
		$self = self::instance();
		return $self->url_for_owner( $self->owner() );
	}

	/**
	 * Sitemap index URL of an owner.
	 *
	 * @param string $owner Owner id.
	 * @return string
	 */
	public function url_for_owner( $owner ) {
		/**
		 * Filters the sitemap index URL used for health checks, robots.txt and submissions.
		 *
		 * @param string $url   URL ('' = none).
		 * @param string $owner Owner id (searchpilot, core, off or a plugin id).
		 */
		return (string) apply_filters( 'searchpilot_active_sitemap_url', $this->default_url( $owner ), $owner );
	}

	/**
	 * Conventional sitemap index URL of an owner.
	 *
	 * @param string $owner Owner id.
	 * @return string
	 */
	private function default_url( $owner ) {
		$pretty = '' !== (string) get_option( 'permalink_structure', '' );
		switch ( $owner ) {
			case 'searchpilot':
				return SitemapModule::index_url();
			case 'yoast':
				return $pretty ? home_url( '/sitemap_index.xml' ) : add_query_arg( 'sitemap', '1', home_url( '/' ) );
			case 'rank_math':
				return home_url( '/sitemap_index.xml' );
			case 'seopress':
				return home_url( '/sitemaps.xml' );
			case 'aioseo':
			case 'tsf':
			case 'jetpack':
			case 'google_xml_sitemaps':
			case 'slim_seo':
			case 'squirrly':
			case 'smartcrawl':
			case 'xmlsf':
				return home_url( '/sitemap.xml' );
			case 'core':
				return $pretty ? home_url( '/wp-sitemap.xml' ) : add_query_arg( 'sitemap', 'index', home_url( '/' ) );
			case 'off':
				return '';
		}
		$health = (array) $this->state->get( self::STATE, array() );
		$robots = (array) ( $health['robots_sitemaps'] ?? array() );
		foreach ( $robots as $candidate ) {
			if ( Url::is_internal( (string) $candidate ) ) {
				return (string) $candidate;
			}
		}
		return home_url( '/sitemap.xml' );
	}

	/**
	 * Health summary (contract shape).
	 *
	 * @return array{owner:string,owner_name:string,index_url:string,status:string,checked_at:string|null,url_count:int,sitemaps:array,gsc:array,errors:string[]}
	 */
	public static function status() {
		$self   = self::instance();
		$owner  = $self->owner();
		$url    = $self->url_for_owner( $owner );
		$health = (array) $self->state->get( self::STATE, array() );
		$fresh  = ! empty( $health['index_url'] ) && Url::normalize( (string) $health['index_url'] ) === Url::normalize( $url ) && ( $health['owner'] ?? '' ) === $owner;
		$status = $fresh ? (string) ( $health['status'] ?? 'unknown' ) : 'unknown';
		if ( 'off' === $owner ) {
			$status = 'disabled';
		}
		$queue = (array) ( $health['cycle']['queue'] ?? array() );
		return array(
			'owner'           => $owner,
			'owner_name'      => $self->owner_name( $owner ),
			'index_url'       => $url,
			'status'          => $status,
			'checked_at'      => $fresh ? ( $health['checked_at'] ?? null ) : null,
			'url_count'       => $fresh ? (int) ( $health['url_count'] ?? 0 ) : 0,
			'sitemaps'        => $fresh ? array_values( (array) ( $health['sitemaps'] ?? array() ) ) : array(),
			'gsc'             => $fresh ? array_values( (array) ( $health['gsc'] ?? array() ) ) : array(),
			'gsc_connected'   => $fresh ? (bool) ( $health['gsc_connected'] ?? false ) : false,
			'bing'            => $fresh ? array_values( (array) ( $health['bing'] ?? array() ) ) : array(),
			'bing_connected'  => $fresh ? (bool) ( $health['bing_connected'] ?? false ) : false,
			'redirects'       => $fresh ? array_values( (array) ( $health['redirects'] ?? array() ) ) : array(),
			'errors'          => $fresh ? array_values( (array) ( $health['errors'] ?? array() ) ) : array(),
			'checks'          => $fresh ? array_values( (array) ( $health['checks'] ?? array() ) ) : array(),
			'robots_sitemaps' => array_values( (array) ( $health['robots_sitemaps'] ?? array() ) ),
			'pending'         => $fresh ? count( $queue ) : 0,
			'newest_content'  => $fresh ? ( $health['newest_content'] ?? null ) : null,
			'newest_lastmod'  => $fresh ? ( $health['newest_lastmod'] ?? null ) : null,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Runs
	 * ------------------------------------------------------------------ */

	/**
	 * Daily / initial sync / on-demand: starts a new check cycle.
	 *
	 * @param int $seconds Time budget.
	 * @return array Status.
	 */
	public function run( $seconds = 25 ) {
		$this->run_locked( true, $seconds );
		return self::status();
	}

	/**
	 * Hourly: continues an unfinished cycle (large sitemap sets).
	 */
	public function continue_cycle() {
		$health = (array) $this->state->get( self::STATE, array() );
		if ( empty( $health['cycle']['queue'] ) ) {
			return;
		}
		$this->run_locked( false, 25 );
	}

	/**
	 * Runs under a lock.
	 *
	 * @param bool $new_cycle Start over.
	 * @param int  $seconds   Budget.
	 */
	private function run_locked( $new_cycle, $seconds ) {
		Lock::run(
			'sitemap_health',
			300,
			function () use ( $new_cycle, $seconds ) {
				try {
					$this->check( $new_cycle, new TimeBudget( $seconds ) );
				} catch ( \Throwable $e ) {
					$this->logger->error( 'sitemap', 'Sitemap health check failed: ' . $e->getMessage() );
				}
			}
		);
	}

	/**
	 * The check itself.
	 *
	 * @param bool       $new_cycle Start a new cycle.
	 * @param TimeBudget $budget    Budget.
	 */
	private function check( $new_cycle, TimeBudget $budget ) {
		$this->fetches = 0;
		$previous      = (array) $this->state->get( self::STATE, array() );
		$owner         = $this->owner();
		$url           = $this->url_for_owner( $owner );
		$now           = current_time( 'mysql', true );
		$health        = array(
			'owner'           => $owner,
			'owner_name'      => $this->owner_name( $owner ),
			'index_url'       => $url,
			'status'          => 'healthy',
			'checked_at'      => gmdate( 'c' ),
			'url_count'       => 0,
			'sitemaps'        => array(),
			'gsc'             => array(),
			'gsc_connected'   => false,
			'bing'            => array(),
			'bing_connected'  => false,
			'redirects'       => array(),
			'errors'          => array(),
			'checks'          => array(),
			'robots_sitemaps' => array(),
			'cycle'           => array(),
			'newest_content'  => null,
			'newest_lastmod'  => null,
		);
		$same_target = ( $previous['index_url'] ?? '' ) === $url && ( $previous['owner'] ?? '' ) === $owner;

		if ( 'off' === $owner || '' === $url ) {
			$health['status'] = 'disabled';
			foreach ( array( 'sitemap_unavailable', 'sitemap_invalid', 'sitemap_gsc_errors', 'sitemap_not_submitted', 'sitemap_stale' ) as $type ) {
				$this->issues->resolve_unseen( $type, $now );
			}
			$this->state->set( self::STATE, $health );
			return;
		}

		// robots.txt Sitemap: lines.
		$robots = $this->fetch( home_url( '/robots.txt' ) );
		if ( $robots && $robots->ok() ) {
			$health['robots_sitemaps'] = array_values( array_unique( array_slice( RobotsParser::parse( $robots->body )->sitemaps(), 0, 20 ) ) );
		} else {
			$health['robots_sitemaps'] = (array) ( $previous['robots_sitemaps'] ?? array() );
		}
		if ( ! in_array( $owner, array( 'searchpilot', 'core', 'yoast', 'rank_math', 'seopress', 'aioseo', 'tsf', 'jetpack', 'google_xml_sitemaps', 'slim_seo', 'squirrly', 'smartcrawl', 'xmlsf' ), true ) && $health['robots_sitemaps'] ) {
			$url                 = (string) $health['robots_sitemaps'][0];
			$health['index_url'] = $url;
		}

		$resume = ! $new_cycle && $same_target && ! empty( $previous['cycle']['queue'] );
		if ( $resume ) {
			foreach ( (array) ( $previous['sitemaps'] ?? array() ) as $item ) {
				$health['sitemaps'][ (string) $item['url'] ] = $item;
			}
			$health['cycle']          = (array) $previous['cycle'];
			$health['checks']         = (array) ( $previous['checks'] ?? array() );
			$health['newest_lastmod'] = $previous['newest_lastmod'] ?? null;
			$health['redirects']      = (array) ( $previous['redirects'] ?? array() );
			$this->seen_cycle         = (string) ( $health['cycle']['seen'] ?? '' );
			$this->seen_chunks        = (int) ( $health['cycle']['chunks'] ?? 0 );
		} else {
			// A new cycle: forget seen-URL chunks of an unfinished previous one.
			self::delete_seen( (string) ( $previous['cycle']['seen'] ?? '' ), (int) ( $previous['cycle']['chunks'] ?? 0 ) );
			$trace = $this->fetch_traced( $url );
			$index = $trace['response'];
			if ( ! $index ) {
				// Out of self-audit budget: keep the previous result.
				return;
			}
			$health['redirects'] = $trace['hops'];
			if ( 200 !== $index->code ) {
				$static                     = 'searchpilot' === $owner ? self::static_sitemap_file() : '';
				if ( $trace['loop'] ) {
					$message = self::loop_message( $url, $trace['hops'] );
				} elseif ( $index->code >= 300 && $index->code < 400 ) {
					/* translators: 1: sitemap URL, 2: redirect path. */
					$message = sprintf( __( 'The sitemap %1$s redirects too many times: %2$s', 'searchpilot-pro' ), $url, self::hops_text( $trace['hops'] ) );
				} else {
					$message = 0 === $index->code
						/* translators: 1: sitemap URL, 2: error. */
						? sprintf( __( 'The sitemap %1$s could not be fetched: %2$s', 'searchpilot-pro' ), $url, $index->error_message() )
						/* translators: 1: sitemap URL, 2: HTTP status. */
						: sprintf( __( 'The sitemap %1$s answered HTTP %2$d.', 'searchpilot-pro' ), $url, $index->code );
				}
				if ( '' !== $static ) {
					/* translators: %s: file name. */
					$message .= ' ' . sprintf( __( 'A static %s file in the site’s root folder is served instead of the dynamic sitemap — delete or rename it.', 'searchpilot-pro' ), $static );
				}
				$health['status']           = 'error';
				$health['errors'][]         = $message;
				$health['checks'][]         = self::check_row( 'reachable', __( 'Sitemap reachable', 'searchpilot-pro' ), 'fail', $message );
				$this->issues->upsert(
					'sitemap_unavailable',
					array(
						'object_type' => 'site',
						'url'         => $url,
						'title'       => Url::display( $url ),
						'data'        => array(
							'url'    => $url,
							'status' => $index->code,
							'error'  => $trace['loop'] || ( $index->code >= 300 && $index->code < 400 ) ? $message : $index->error_message(),
							'owner'  => $health['owner_name'],
						),
					)
				);
				$this->issues->resolve_unseen( 'sitemap_unavailable', $now );
				$this->state->set( self::STATE, $health );
				return;
			}
			$this->issues->resolve_unseen( 'sitemap_unavailable', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
			/* translators: 1: HTTP status, 2: milliseconds. */
			$health['checks'][] = self::check_row( 'reachable', __( 'Sitemap reachable', 'searchpilot-pro' ), 'pass', sprintf( __( 'Answered HTTP %1$d in %2$d ms.', 'searchpilot-pro' ), $index->code, $index->duration_ms ) );
			if ( $trace['hops'] ) {
				$health['status']   = 'warning';
				$health['checks'][] = self::check_row(
					'redirects',
					__( 'Answers without redirects', 'searchpilot-pro' ),
					'warning',
					/* translators: %s: redirect path. */
					sprintf( __( 'The sitemap address redirects before answering (%s). Submit the final address, or remove the redirect — another plugin or a server rule adds it.', 'searchpilot-pro' ), self::hops_text( $trace['hops'] ) )
				);
			}
			if ( 'searchpilot' === $owner && get_transient( 'searchpilot_sitemap_redirect_loop' ) ) {
				$health['status']   = 'warning';
				$health['checks'][] = self::check_row(
					'loop',
					__( 'No redirect loop', 'searchpilot-pro' ),
					'warning',
					__( 'A redirect loop between /sitemap.xml and /wp-sitemap.xml was detected: another plugin or the server redirects /sitemap.xml to /wp-sitemap.xml. SearchPilot now serves its sitemap at both addresses to break the loop — remove the other redirect.', 'searchpilot-pro' )
				);
			}

			$valid = SitemapXml::validate( $index->body );
			if ( ! $valid['valid'] ) {
				$message            = self::invalid_message( $url, $valid['error'] );
				$health['status']   = 'error';
				$health['errors'][] = $message;
				$health['checks'][] = self::check_row( 'valid', __( 'Valid XML', 'searchpilot-pro' ), 'fail', $message );
				$this->issues->upsert(
					'sitemap_invalid',
					array(
						'object_type' => 'site',
						'url'         => $url,
						'title'       => Url::display( $url ),
						'data'        => array(
							'url'   => $url,
							'error' => $valid['error'],
						),
					)
				);
				$this->state->set( self::STATE, $health );
				return;
			}
			$health['checks'][] = self::check_row(
				'valid',
				__( 'Valid XML', 'searchpilot-pro' ),
				'pass',
				'sitemapindex' === $valid['root'] ? __( 'A valid sitemap index.', 'searchpilot-pro' ) : __( 'A valid sitemap (URL set).', 'searchpilot-pro' )
			);
			if ( 'searchpilot' === $owner && false === strpos( substr( $index->body, 0, 500 ), 'SearchPilot' ) ) {
				$static             = self::static_sitemap_file();
				$health['status']   = 'warning';
				$health['checks'][] = self::check_row(
					'origin',
					__( 'Served by SearchPilot', 'searchpilot-pro' ),
					'warning',
					'' !== $static
						/* translators: %s: file name. */
						? sprintf( __( 'A static %s file in the site’s root folder is served instead of SearchPilot’s sitemap. Delete or rename it.', 'searchpilot-pro' ), $static )
						: __( 'The document at this address was not generated by SearchPilot (a server rule, CDN or old cache may answer instead). Purge caches and check server redirects.', 'searchpilot-pro' )
				);
			}

			$this->seen_cycle  = substr( md5( $now . wp_rand() ), 0, 12 );
			$this->seen_chunks = 0;
			$health['cycle']   = array(
				'started' => $now,
				'queue'   => array(),
				'seen'    => $this->seen_cycle,
				'chunks'  => 0,
			);
			if ( 'sitemapindex' === $valid['root'] ) {
				$children = self::index_children( $index->body );
				$old      = array();
				foreach ( (array) ( $previous['sitemaps'] ?? array() ) as $item ) {
					$old[ (string) $item['url'] ] = $item;
				}
				foreach ( array_slice( $children, 0, self::MAX_CHILDREN ) as $child ) {
					$prev                                  = $same_target ? ( $old[ $child['loc'] ] ?? array() ) : array();
					$health['sitemaps'][ $child['loc'] ]   = array(
						'url'        => $child['loc'],
						'type'       => self::guess_type( $child['loc'] ),
						'urls'       => (int) ( $prev['urls'] ?? 0 ),
						'lastmod'    => '' !== $child['lastmod'] ? $child['lastmod'] : ( $prev['lastmod'] ?? '' ),
						'status'     => 'pending',
						'error'      => '',
						'checked_at' => $prev['checked_at'] ?? null,
					);
					$health['cycle']['queue'][]            = $child['loc'];
				}
			} else {
				$scan                      = $this->scan_urlset( $index->body, $owner );
				$health['sitemaps'][ $url ] = array(
					'url'        => $url,
					'type'       => 'urlset',
					'urls'       => $scan['count'],
					'lastmod'    => $scan['lastmod'],
					'status'     => 'ok',
					'error'      => '',
					'checked_at' => gmdate( 'c' ),
				);
			}
			$this->issues->resolve( 'sitemap_invalid', 'site', 0, $url );
		}

		// Child sitemaps, incrementally.
		$queue = (array) ( $health['cycle']['queue'] ?? array() );
		while ( $queue && ! $budget->exhausted( 4 ) && $this->fetches < self::FETCHES_PER_RUN ) {
			$child    = (string) array_shift( $queue );
			$response = $this->fetch( $child );
			if ( ! $response ) {
				array_unshift( $queue, $child );
				break;
			}
			$item               = (array) ( $health['sitemaps'][ $child ] ?? array( 'url' => $child, 'type' => self::guess_type( $child ), 'urls' => 0, 'lastmod' => '' ) );
			$item['checked_at'] = gmdate( 'c' );
			if ( 200 !== $response->code ) {
				$item['status'] = 'error';
				/* translators: %d: HTTP status. */
				$item['error']  = 0 === $response->code ? $response->error_message() : sprintf( __( 'HTTP %d', 'searchpilot-pro' ), $response->code );
			} else {
				$valid = SitemapXml::validate( $response->body );
				if ( ! $valid['valid'] ) {
					$item['status'] = 'invalid';
					$item['error']  = self::invalid_message( $child, $valid['error'] );
				} elseif ( 'sitemapindex' === $valid['root'] ) {
					// Nested index: queue its children.
					foreach ( self::index_children( $response->body ) as $nested ) {
						if ( count( $health['sitemaps'] ) >= self::MAX_CHILDREN ) {
							break;
						}
						if ( ! isset( $health['sitemaps'][ $nested['loc'] ] ) ) {
							$health['sitemaps'][ $nested['loc'] ] = array(
								'url'        => $nested['loc'],
								'type'       => self::guess_type( $nested['loc'] ),
								'urls'       => 0,
								'lastmod'    => $nested['lastmod'],
								'status'     => 'pending',
								'error'      => '',
								'checked_at' => null,
							);
							$queue[]                              = $nested['loc'];
						}
					}
					$item['status'] = 'ok';
					$item['type']   = 'index';
					$item['error']  = '';
				} else {
					$scan            = $this->scan_urlset( $response->body, $owner );
					$item['status']  = 'ok';
					$item['error']   = '';
					$item['urls']    = $scan['count'];
					$item['lastmod'] = '' !== $scan['lastmod'] ? $scan['lastmod'] : (string) ( $item['lastmod'] ?? '' );
				}
			}
			$health['sitemaps'][ $child ] = $item;
		}
		$health['cycle']['queue'] = array_values( $queue );
		$cycle_done               = empty( $queue );
		$this->flush_seen();
		$health['cycle']['chunks'] = $this->seen_chunks;

		// Child problems.
		$bad = array();
		foreach ( $health['sitemaps'] as $item ) {
			if ( in_array( $item['status'], array( 'error', 'invalid' ), true ) ) {
				$bad[] = $item;
			}
		}
		if ( $cycle_done ) {
			foreach ( $bad as $item ) {
				$this->issues->upsert(
					'sitemap_invalid',
					array(
						'object_type' => 'site',
						'url'         => $item['url'],
						'title'       => Url::display( $item['url'] ),
						'data'        => array(
							'url'   => $item['url'],
							'error' => $item['error'],
						),
					)
				);
			}
			$this->issues->resolve_unseen( 'sitemap_invalid', $now );
			if ( 'searchpilot' !== $owner && 'core' !== $owner ) {
				// Only a complete, error-free cycle may clear membership flags.
				if ( ! $bad ) {
					$this->finish_marking();
				}
				self::delete_seen( $this->seen_cycle, $this->seen_chunks );
				$health['cycle']['chunks'] = 0;
			}
		}
		$total   = 0;
		$newest  = '';
		foreach ( $health['sitemaps'] as $item ) {
			$total += (int) $item['urls'];
			if ( (string) $item['lastmod'] > $newest ) {
				$newest = (string) $item['lastmod'];
			}
		}
		$health['url_count']      = $total;
		$health['newest_lastmod'] = '' !== $newest ? $newest : null;
		$health['sitemaps']       = array_values( $health['sitemaps'] );
		$ok_children              = count( $health['sitemaps'] ) - count( $bad );
		$pending                  = count( $queue );
		$health['checks']         = array_values(
			array_filter(
				$health['checks'],
				static function ( $c ) {
					return in_array( $c['id'], array( 'reachable', 'redirects', 'loop', 'valid', 'origin' ), true );
				}
			)
		);
		if ( $health['sitemaps'] ) {
			$health['checks'][] = self::check_row(
				'children',
				__( 'Child sitemaps', 'searchpilot-pro' ),
				$bad ? 'fail' : ( $pending ? 'info' : 'pass' ),
				$pending
					/* translators: 1: checked, 2: total. */
					? sprintf( __( '%1$d of %2$d checked so far; the rest are checked in the background.', 'searchpilot-pro' ), count( $health['sitemaps'] ) - $pending, count( $health['sitemaps'] ) )
					/* translators: 1: working sitemaps, 2: total, 3: URL count. */
					: sprintf( __( '%1$d of %2$d sitemaps load correctly, listing %3$s URLs.', 'searchpilot-pro' ), $ok_children, count( $health['sitemaps'] ), number_format_i18n( $total ) )
			);
		}
		if ( $bad ) {
			$health['status'] = 'error';
			foreach ( array_slice( $bad, 0, 5 ) as $item ) {
				$health['errors'][] = Url::display( $item['url'] ) . ': ' . $item['error'];
			}
		}

		// robots.txt mentions the sitemap?
		$listed = false;
		foreach ( $health['robots_sitemaps'] as $line ) {
			if ( Url::normalize( (string) $line ) === Url::normalize( $url ) ) {
				$listed = true;
			}
		}
		$health['checks'][] = self::check_row(
			'robots',
			__( 'Listed in robots.txt', 'searchpilot-pro' ),
			$listed ? 'pass' : 'info',
			$listed ? __( 'robots.txt points crawlers to this sitemap.', 'searchpilot-pro' ) : __( 'robots.txt does not list this sitemap. Search engines still find it when it is submitted; SearchPilot can add the line (robots.txt tab).', 'searchpilot-pro' )
		);

		$this->check_search_console( $health, $url, $now );
		$this->check_bing( $health, $url );
		$this->check_freshness( $health, $url, $now );

		if ( 'error' !== $health['status'] && ( ! empty( $health['gsc_problem'] ) || ! empty( $health['stale'] ) ) ) {
			$health['status'] = 'warning';
		}
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$health['checks'][] = self::check_row( 'public', __( 'Search engine visibility', 'searchpilot-pro' ), 'fail', __( 'Settings → Reading asks search engines not to index this site, so sitemaps have no effect.', 'searchpilot-pro' ) );
			if ( 'error' !== $health['status'] ) {
				$health['status'] = 'warning';
			}
		}
		unset( $health['gsc_problem'], $health['stale'] );

		if ( 'searchpilot' === $owner ) {
			$this->sync_own_flags( $budget );
		}
		$this->state->set( self::STATE, $health );
	}

	/**
	 * Search Console sitemap report checks.
	 *
	 * @param array  $health Health (by reference).
	 * @param string $url    Active sitemap.
	 * @param string $now    Run start (MySQL UTC).
	 */
	private function check_search_console( array &$health, $url, $now ) {
		$class = '\SearchPilot\Api\Google\SearchConsole';
		if ( ! class_exists( $class ) ) {
			$health['checks'][] = self::check_row( 'submitted', __( 'Submitted to Google', 'searchpilot-pro' ), 'info', __( 'Connect Google Search Console to see Google’s sitemap report here.', 'searchpilot-pro' ) );
			return;
		}
		$gsc = \SearchPilot\Plugin::instance()->get( $class );
		if ( ! $gsc->is_connected() ) {
			$health['checks'][] = self::check_row( 'submitted', __( 'Submitted to Google', 'searchpilot-pro' ), 'info', __( 'Connect Google Search Console to see Google’s sitemap report here.', 'searchpilot-pro' ) );
			$this->issues->resolve_unseen( 'sitemap_not_submitted', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
			$this->issues->resolve_unseen( 'sitemap_gsc_errors', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
			return;
		}
		$health['gsc_connected'] = true;
		$rows                    = $gsc->list_sitemaps();
		if ( is_wp_error( $rows ) ) {
			$health['checks'][] = self::check_row( 'submitted', __( 'Submitted to Google', 'searchpilot-pro' ), 'info', $rows->get_error_message() );
			return;
		}
		$clean     = array();
		$submitted = false;
		$errors    = array();
		foreach ( (array) $rows as $row ) {
			$row     = (array) $row;
			$clean[] = array(
				'path'              => (string) ( $row['path'] ?? '' ),
				'last_submitted'    => $row['last_submitted'] ?? null,
				'last_downloaded'   => $row['last_downloaded'] ?? null,
				'is_pending'        => ! empty( $row['is_pending'] ),
				'is_sitemaps_index' => ! empty( $row['is_sitemaps_index'] ),
				'type'              => (string) ( $row['type'] ?? '' ),
				'warnings'          => (int) ( $row['warnings'] ?? 0 ),
				'errors'            => (int) ( $row['errors'] ?? 0 ),
				'contents'          => array_values( (array) ( $row['contents'] ?? array() ) ),
			);
			if ( Url::normalize( (string) ( $row['path'] ?? '' ) ) === Url::normalize( $url ) ) {
				$submitted = true;
			}
			if ( (int) ( $row['errors'] ?? 0 ) > 0 ) {
				$errors[] = end( $clean );
			}
		}
		$health['gsc'] = $clean;
		if ( $submitted ) {
			$health['checks'][] = self::check_row( 'submitted', __( 'Submitted to Google', 'searchpilot-pro' ), 'pass', __( 'Google Search Console has this sitemap.', 'searchpilot-pro' ) );
			$this->issues->resolve_unseen( 'sitemap_not_submitted', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
		} else {
			$health['gsc_problem'] = true;
			$health['checks'][]    = self::check_row( 'submitted', __( 'Submitted to Google', 'searchpilot-pro' ), 'warning', __( 'This sitemap is not in Google Search Console yet.', 'searchpilot-pro' ) );
			$this->issues->upsert(
				'sitemap_not_submitted',
				array(
					'object_type' => 'site',
					'url'         => $url,
					'title'       => Url::display( $url ),
					'data'        => array( 'url' => $url ),
				)
			);
			$this->issues->resolve_unseen( 'sitemap_not_submitted', $now );
		}
		if ( $errors ) {
			$health['gsc_problem'] = true;
			$health['checks'][]    = self::check_row(
				'gsc_errors',
				__( 'Google sitemap report', 'searchpilot-pro' ),
				'fail',
				/* translators: %d: number of sitemaps. */
				sprintf( _n( 'Google reports errors in %d sitemap.', 'Google reports errors in %d sitemaps.', count( $errors ), 'searchpilot-pro' ), count( $errors ) )
			);
			$this->issues->upsert(
				'sitemap_gsc_errors',
				array(
					'object_type' => 'site',
					'url'         => $url,
					'title'       => Url::display( $url ),
					'data'        => array( 'sitemaps' => array_slice( $errors, 0, 10 ) ),
				)
			);
		} else {
			$health['checks'][] = self::check_row( 'gsc_errors', __( 'Google sitemap report', 'searchpilot-pro' ), 'pass', __( 'Google reports no sitemap errors.', 'searchpilot-pro' ) );
			$this->issues->resolve_unseen( 'sitemap_gsc_errors', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
		}
	}

	/**
	 * Bing Webmaster Tools sitemap (feed) status, when the Bing module exposes it.
	 *
	 * @param array  $health Health (by reference).
	 * @param string $url    Active sitemap.
	 */
	private function check_bing( array &$health, $url ) {
		$class = '\SearchPilot\Api\Bing\BingWebmaster';
		if ( ! class_exists( $class ) ) {
			return;
		}
		$bing = \SearchPilot\Plugin::instance()->get( $class );
		if ( ! method_exists( $bing, 'is_connected' ) || ! $bing->is_connected() ) {
			return;
		}
		$health['bing_connected'] = true;
		if ( ! method_exists( $bing, 'feeds' ) ) {
			return;
		}
		$rows = $bing->feeds();
		if ( is_wp_error( $rows ) ) {
			$health['checks'][] = self::check_row( 'bing', __( 'Submitted to Bing', 'searchpilot-pro' ), 'info', $rows->get_error_message() );
			return;
		}
		if ( null === $rows ) {
			return; // Not fetched yet (the Bing module refreshes the list daily).
		}
		// BingWebmaster::feeds() returns { checked_at, items[] }.
		$rows   = isset( $rows['items'] ) && is_array( $rows['items'] ) ? $rows['items'] : ( isset( $rows[0] ) ? $rows : array() );
		$clean  = array();
		$listed = false;
		foreach ( (array) $rows as $row ) {
			$row     = (array) $row;
			$feed    = (string) ( $row['url'] ?? ( $row['Url'] ?? '' ) );
			$clean[] = array(
				'url'          => $feed,
				'status'       => (string) ( $row['status'] ?? ( $row['Status'] ?? '' ) ),
				'type'         => (string) ( $row['type'] ?? ( $row['Type'] ?? '' ) ),
				'submitted'    => self::bing_date( $row['submitted'] ?? ( $row['Submitted'] ?? ( $row['last_submitted'] ?? '' ) ) ),
				'last_crawled' => self::bing_date( $row['last_crawled'] ?? ( $row['LastCrawled'] ?? '' ) ),
				'url_count'    => (int) ( $row['url_count'] ?? ( $row['UrlCount'] ?? ( $row['urls'] ?? 0 ) ) ),
			);
			if ( '' !== $feed && Url::normalize( $feed ) === Url::normalize( $url ) ) {
				$listed = true;
			}
		}
		$health['bing']     = $clean;
		$health['checks'][] = self::check_row(
			'bing',
			__( 'Submitted to Bing', 'searchpilot-pro' ),
			$listed ? 'pass' : 'info',
			$listed ? __( 'Bing Webmaster Tools has this sitemap.', 'searchpilot-pro' ) : __( 'This sitemap is not in Bing Webmaster Tools yet; “Submit now” adds it.', 'searchpilot-pro' )
		);
	}

	/**
	 * ISO date from Bing's formats ("/Date(1700000000000)/" or a date string).
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function bing_date( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '#Date\((-?\d+)#', $value, $m ) ) {
			return gmdate( 'c', (int) floor( (int) $m[1] / 1000 ) );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	/**
	 * Fetches a URL following redirects one by one (to report redirects and loops).
	 *
	 * @param string $url URL.
	 * @return array{response:HttpResponse|null,hops:array,loop:bool}
	 */
	private function fetch_traced( $url ) {
		$hops    = array();
		$seen    = array( Url::normalize( $url ) => true );
		$current = $url;
		for ( $i = 0; $i < 6; $i++ ) {
			$response = $this->fetch( $current, 0 );
			if ( ! $response || $response->code < 300 || $response->code > 399 ) {
				return array(
					'response' => $response,
					'hops'     => $hops,
					'loop'     => false,
				);
			}
			$location = trim( $response->header( 'location' ) );
			if ( '' === $location ) {
				break;
			}
			$next   = Url::absolute( $location, $current );
			$hops[] = array(
				'url'  => $current,
				'code' => (int) $response->code,
				'to'   => $next,
			);
			$key = Url::normalize( $next );
			if ( isset( $seen[ $key ] ) ) {
				return array(
					'response' => $response,
					'hops'     => $hops,
					'loop'     => true,
				);
			}
			$seen[ $key ] = true;
			$current      = $next;
		}
		return array(
			'response' => isset( $response ) ? $response : null,
			'hops'     => $hops,
			'loop'     => false,
		);
	}

	/**
	 * "A → B → C" for redirect hops (paths only).
	 *
	 * @param array $hops Hops.
	 * @return string
	 */
	private static function hops_text( array $hops ) {
		if ( empty( $hops ) ) {
			return '';
		}
		$parts = array( Url::display( Url::to_path( (string) $hops[0]['url'] ) ) );
		foreach ( $hops as $hop ) {
			$parts[] = Url::display( Url::is_internal( (string) $hop['to'] ) ? Url::to_path( (string) $hop['to'] ) : (string) $hop['to'] );
		}
		return implode( ' → ', $parts );
	}

	/**
	 * Human message for a redirect loop on the sitemap address.
	 *
	 * @param string $url  Sitemap URL.
	 * @param array  $hops Hops.
	 * @return string
	 */
	private static function loop_message( $url, array $hops ) {
		$paths = array();
		foreach ( $hops as $hop ) {
			$paths[ Url::to_path( (string) $hop['url'] ) ] = true;
		}
		$paths = array_keys( $paths );
		if ( count( $paths ) >= 2 ) {
			return sprintf(
				/* translators: 1: first path, 2: second path, 3: first path. */
				__( 'Redirect loop between %1$s and %2$s (another plugin or the server redirects %3$s). Remove the other redirect so the sitemap answers directly.', 'searchpilot-pro' ),
				Url::display( $paths[0] ),
				Url::display( $paths[1] ),
				Url::display( $paths[0] )
			);
		}
		/* translators: %s: sitemap URL. */
		return sprintf( __( 'The sitemap %s redirects to itself.', 'searchpilot-pro' ), $url );
	}

	/**
	 * Freshness: newest lastmod vs. newest published content.
	 *
	 * @param array  $health Health (by reference).
	 * @param string $url    Active sitemap.
	 * @param string $now    Run start.
	 */
	private function check_freshness( array &$health, $url, $now ) {
		global $wpdb;
		$types = $this->types->sitemap_post_types();
		if ( empty( $types ) ) {
			return;
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// Ignore the last hour so caches have time to refresh.
		$params = array_merge( $types, array( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$newest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ({$holders}) AND post_status = 'publish' AND post_password = '' AND post_modified_gmt <= %s", $params ) );
		if ( 'searchpilot' === ( $health['owner'] ?? '' ) ) {
			// Our <lastmod> follows significant changes only; compare like with like.
			$newest = '';
			foreach ( $types as $type ) {
				$value = $this->builder->newest_lastmod( $type );
				if ( $value > $newest && strtotime( $value . ' UTC' ) <= time() - HOUR_IN_SECONDS ) {
					$newest = $value;
				}
			}
		}
		$health['newest_content'] = '' !== $newest ? SitemapXml::w3c_date( $newest ) : null;
		$lastmod                  = (string) ( $health['newest_lastmod'] ?? '' );
		if ( '' === $newest || '' === $lastmod || ! empty( $health['cycle']['queue'] ) ) {
			return;
		}
		$content_ts = strtotime( $newest . ' UTC' );
		$lastmod_ts = strtotime( $lastmod );
		if ( $content_ts && $lastmod_ts && $content_ts - $lastmod_ts > DAY_IN_SECONDS ) {
			$health['stale']    = true;
			$health['checks'][] = self::check_row(
				'fresh',
				__( 'Up to date', 'searchpilot-pro' ),
				'warning',
				/* translators: 1: sitemap date, 2: content date. */
				sprintf( __( 'The newest sitemap date is %1$s but content changed on %2$s. A cache may be serving an old copy.', 'searchpilot-pro' ), gmdate( 'Y-m-d H:i', $lastmod_ts ), gmdate( 'Y-m-d H:i', $content_ts ) )
			);
			$this->issues->upsert(
				'sitemap_stale',
				array(
					'object_type' => 'site',
					'url'         => $url,
					'title'       => Url::display( $url ),
					'data'        => array(
						'url'            => $url,
						'newest_lastmod' => gmdate( 'c', $lastmod_ts ),
						'newest_content' => gmdate( 'c', $content_ts ),
					),
				)
			);
			return;
		}
		$health['checks'][] = self::check_row( 'fresh', __( 'Up to date', 'searchpilot-pro' ), 'pass', __( 'Sitemap dates follow your latest content.', 'searchpilot-pro' ) );
		$this->issues->resolve_unseen( 'sitemap_stale', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
	}

	/**
	 * Loopback GET with self-audit budget.
	 *
	 * @param string $url       URL.
	 * @param int    $redirects Redirects to follow.
	 * @return HttpResponse|null Null when the budget is exhausted.
	 */
	private function fetch( $url, $redirects = 3 ) {
		if ( ! $this->limiter->attempt( 'self_audit' ) ) {
			return null;
		}
		++$this->fetches;
		return $this->http->get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => (int) $redirects,
				'channel'             => 'sitemap',
				'log_errors'          => false,
				'headers'             => array( 'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5' ),
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'resolve_to'          => (string) $this->settings->get( 'indexing.origin_ip', '' ),
				'limit_response_size' => self::MAX_BYTES,
			)
		);
	}

	/**
	 * A static sitemap file in the web root that shadows WordPress's dynamic one.
	 *
	 * @return string File name or ''.
	 */
	public static function static_sitemap_file() {
		foreach ( array( 'sitemap.xml', 'sitemap_index.xml', 'sitemap.xml.gz' ) as $file ) {
			if ( file_exists( ABSPATH . $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Child sitemaps of an index document.
	 *
	 * @param string $xml XML.
	 * @return array<int,array{loc:string,lastmod:string}>
	 */
	public static function index_children( $xml ) {
		$out = array();
		if ( ! class_exists( '\XMLReader' ) ) {
			if ( preg_match_all( '#<sitemap>\s*<loc>([^<]+)</loc>(?:\s*<lastmod>([^<]+)</lastmod>)?#i', (string) $xml, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $row ) {
					$out[] = array(
						'loc'     => html_entity_decode( trim( $row[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ),
						'lastmod' => isset( $row[2] ) ? trim( $row[2] ) : '',
					);
				}
			}
			return $out;
		}
		$reader = new \XMLReader();
		if ( ! $reader->XML( (string) $xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT ) ) {
			return $out;
		}
		$current  = null;
		$previous = libxml_use_internal_errors( true );
		while ( @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Untrusted XML.
			if ( \XMLReader::ELEMENT !== $reader->nodeType || SitemapXml::NS_SITEMAP !== $reader->namespaceURI ) {
				continue;
			}
			if ( 'sitemap' === $reader->localName ) {
				if ( $current && '' !== $current['loc'] ) {
					$out[] = $current;
				}
				$current = array(
					'loc'     => '',
					'lastmod' => '',
				);
			} elseif ( 'loc' === $reader->localName && $current ) {
				$current['loc'] = trim( $reader->readString() );
			} elseif ( 'lastmod' === $reader->localName && $current ) {
				$current['lastmod'] = trim( $reader->readString() );
			}
		}
		if ( $current && '' !== $current['loc'] ) {
			$out[] = $current;
		}
		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return array_values(
			array_filter(
				$out,
				static function ( $c ) {
					return (bool) preg_match( '#^https?://#i', $c['loc'] );
				}
			)
		);
	}

	/**
	 * Counts URLs of a urlset (streaming) and, in companion mode, marks
	 * matching registry rows as in the sitemap.
	 *
	 * @param string $xml   XML.
	 * @param string $owner Owner.
	 * @return array{count:int,lastmod:string}
	 */
	private function scan_urlset( $xml, $owner ) {
		$mark    = ! in_array( $owner, array( 'searchpilot', 'core' ), true );
		$count   = 0;
		$lastmod = '';
		$hashes  = array();
		if ( ! class_exists( '\XMLReader' ) ) {
			$count = (int) preg_match_all( '#<url>#i', (string) $xml );
			return array(
				'count'   => $count,
				'lastmod' => '',
			);
		}
		$reader   = new \XMLReader();
		$previous = libxml_use_internal_errors( true );
		if ( $reader->XML( (string) $xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT ) ) {
			$in_url = false;
			while ( @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Untrusted XML.
				if ( \XMLReader::ELEMENT !== $reader->nodeType || SitemapXml::NS_SITEMAP !== $reader->namespaceURI ) {
					continue;
				}
				if ( 'url' === $reader->localName ) {
					++$count;
					$in_url = true;
				} elseif ( 'loc' === $reader->localName && $in_url ) {
					if ( $mark ) {
						$hashes[] = Url::hash( trim( $reader->readString() ) );
						if ( count( $hashes ) >= 500 ) {
							$this->mark( $hashes );
							$hashes = array();
						}
					}
				} elseif ( 'lastmod' === $reader->localName && $in_url ) {
					$value = SitemapXml::w3c_date( trim( $reader->readString() ) );
					if ( $value > $lastmod ) {
						$lastmod = $value;
					}
				}
			}
			$reader->close();
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( $mark && $hashes ) {
			$this->mark( $hashes );
		}
		return array(
			'count'   => $count,
			'lastmod' => $lastmod,
		);
	}

	/**
	 * Marks registry rows as present in the (companion) sitemap and remembers
	 * them for this cycle (registry `updated_at` is left alone: other modules
	 * bump it, so it cannot tell which rows were seen).
	 *
	 * @param string[] $hashes URL hashes.
	 */
	private function mark( array $hashes ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}
		$table   = Tables::urls();
		$holders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET in_sitemap = 1 WHERE in_sitemap <> 1 AND url_hash IN ({$holders})", $hashes ) );
		if ( '' === $this->seen_cycle ) {
			return;
		}
		foreach ( $hashes as $hash ) {
			$this->seen_buffer[] = $hash;
		}
		if ( count( $this->seen_buffer ) >= 5000 ) {
			$this->flush_seen();
		}
	}

	/**
	 * Writes buffered seen hashes as one chunk transient (40 hex chars each).
	 */
	private function flush_seen() {
		if ( empty( $this->seen_buffer ) || '' === $this->seen_cycle ) {
			$this->seen_buffer = array();
			return;
		}
		set_transient( 'searchpilot_smseen_' . $this->seen_cycle . '_' . $this->seen_chunks, implode( '', $this->seen_buffer ), 2 * DAY_IN_SECONDS );
		++$this->seen_chunks;
		$this->seen_buffer = array();
	}

	/**
	 * Deletes the seen-hash chunks of a cycle.
	 *
	 * @param string $cycle  Cycle id.
	 * @param int    $chunks Chunks.
	 */
	private static function delete_seen( $cycle, $chunks ) {
		if ( '' === (string) $cycle ) {
			return;
		}
		for ( $i = 0; $i < (int) $chunks; $i++ ) {
			delete_transient( 'searchpilot_smseen_' . $cycle . '_' . $i );
		}
	}

	/**
	 * End of a complete companion cycle: registry rows marked in_sitemap that
	 * were not seen in any of the owner's sitemaps are cleared.
	 */
	private function finish_marking() {
		global $wpdb;
		$this->flush_seen();
		if ( '' === $this->seen_cycle || $this->seen_chunks < 1 ) {
			return; // Nothing seen: never wipe every flag on an empty/failed read.
		}
		$seen = array();
		for ( $i = 0; $i < $this->seen_chunks; $i++ ) {
			$chunk = get_transient( 'searchpilot_smseen_' . $this->seen_cycle . '_' . $i );
			if ( ! is_string( $chunk ) ) {
				return; // A chunk expired: the set is incomplete.
			}
			foreach ( str_split( $chunk, 40 ) as $hash ) {
				$seen[ $hash ] = true;
			}
			if ( count( $seen ) > 500000 ) {
				$this->logger->warning( 'sitemap', 'Companion sitemap too large to reconcile in_sitemap flags.' );
				return;
			}
		}
		$table = Tables::urls();
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, url_hash FROM {$table} WHERE in_sitemap = 1 AND id > %d ORDER BY id ASC LIMIT 2000", $after ) );
			$clear = array();
			foreach ( (array) $rows as $row ) {
				$after = (int) $row->id;
				if ( ! isset( $seen[ (string) $row->url_hash ] ) ) {
					$clear[] = (int) $row->id;
				}
			}
			if ( $clear ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "UPDATE {$table} SET in_sitemap = 0 WHERE id IN (" . implode( ',', $clear ) . ')' );
			}
		} while ( count( (array) $rows ) === 2000 );
	}

	/**
	 * While SearchPilot owns the sitemap, keeps registry `in_sitemap` equal
	 * to ContentTypes::post_in_sitemap() (small batches, cursor-based).
	 *
	 * @param TimeBudget $budget Budget.
	 */
	private function sync_own_flags( TimeBudget $budget ) {
		global $wpdb;
		$table  = Tables::urls();
		$cursor = (int) $this->state->get( 'sitemap_flag_cursor', 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, in_sitemap FROM {$table} WHERE object_type = 'post' AND status = 'active' AND id > %d ORDER BY id ASC LIMIT 300", $cursor ) );
		if ( empty( $rows ) ) {
			$this->state->set( 'sitemap_flag_cursor', 0 );
			return;
		}
		_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) ), false, true );
		$last = $cursor;
		foreach ( $rows as $row ) {
			if ( $budget->exhausted( 3 ) ) {
				break;
			}
			$last   = (int) $row->id;
			$wanted = $this->types->post_in_sitemap( (int) $row->object_id ) ? 1 : 0;
			if ( (int) $row->in_sitemap !== $wanted ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET in_sitemap = %d WHERE id = %d", $wanted, (int) $row->id ) );
			}
		}
		$this->state->set( 'sitemap_flag_cursor', count( $rows ) < 300 && (int) end( $rows )->id === $last ? 0 : $last );
	}

	/**
	 * Sitemap kind from its URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function guess_type( $url ) {
		$path = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( (string) $url, PHP_URL_QUERY ) );
		foreach ( array(
			'news'    => '#news#',
			'video'   => '#video#',
			'image'   => '#image#',
			'authors' => '#author|users#',
			'tax'     => '#tax-|category|post_tag|tag-|taxonomies|terms#',
			'pages'   => '#pt-page|page-sitemap|pages#',
			'posts'   => '#pt-|post|posts#',
		) as $type => $regex ) {
			if ( preg_match( $regex, $path ) ) {
				return $type;
			}
		}
		return 'sitemap';
	}

	/**
	 * Human message for a validation error.
	 *
	 * @param string $url   URL.
	 * @param string $error Error code/message.
	 * @return string
	 */
	private static function invalid_message( $url, $error ) {
		switch ( $error ) {
			case 'empty':
				/* translators: %s: URL. */
				return sprintf( __( '%s is empty.', 'searchpilot-pro' ), $url );
			case 'root':
				/* translators: %s: URL. */
				return sprintf( __( '%s is not a sitemap (an HTML page or another document was returned).', 'searchpilot-pro' ), $url );
			case 'namespace':
				/* translators: %s: URL. */
				return sprintf( __( '%s does not declare the sitemaps.org namespace.', 'searchpilot-pro' ), $url );
		}
		/* translators: 1: URL, 2: parser error. */
		return sprintf( __( '%1$s is not well-formed XML: %2$s', 'searchpilot-pro' ), $url, $error );
	}

	/**
	 * Check row for the UI.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $status  pass|warning|fail|info.
	 * @param string $message Message.
	 * @return array
	 */
	private static function check_row( $id, $label, $status, $message ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Issues
	 * ------------------------------------------------------------------ */

	/**
	 * Registers sitemap issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			'sitemap_unavailable',
			array(
				'label'    => __( 'Sitemap cannot be loaded', 'searchpilot-pro' ),
				'category' => 'sitemap',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$status = (int) ( $issue['data']['status'] ?? 0 );
					return $status
						/* translators: 1: URL, 2: HTTP status. */
						? sprintf( __( 'The sitemap %1$s answered HTTP %2$d instead of 200.', 'searchpilot-pro' ), Url::display( (string) $issue['url'] ), $status )
						/* translators: 1: URL, 2: error. */
						: sprintf( __( 'The sitemap %1$s could not be fetched (%2$s).', 'searchpilot-pro' ), Url::display( (string) $issue['url'] ), (string) ( $issue['data']['error'] ?? '' ) );
				},
				'why'      => __( 'Search engines use the sitemap to discover new and updated pages. While it fails, new articles are found more slowly.', 'searchpilot-pro' ),
				'fix'      => __( 'Open the sitemap in a browser. Check that the sitemap feature of the owning plugin is on, that permalinks are saved (Settings → Permalinks → Save), and that a firewall or CDN rule does not block it.', 'searchpilot-pro' ),
				'action'   => 'fix_sitemap',
			)
		);
		$registry->register(
			'sitemap_invalid',
			array(
				'label'    => __( 'Sitemap is not valid', 'searchpilot-pro' ),
				'category' => 'sitemap',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					/* translators: 1: URL, 2: error. */
					return sprintf( __( '%1$s could not be read as a sitemap: %2$s', 'searchpilot-pro' ), Url::display( (string) $issue['url'] ), (string) ( $issue['data']['error'] ?? '' ) );
				},
				'why'      => __( 'Search engines reject broken sitemaps, so the pages listed in them are not discovered through it.', 'searchpilot-pro' ),
				'fix'      => __( 'Look for PHP warnings or extra output before the XML (often a theme or plugin printing text), a caching plugin minifying XML, or a security plugin returning an HTML page.', 'searchpilot-pro' ),
				'action'   => 'fix_sitemap',
			)
		);
		$registry->register(
			'sitemap_gsc_errors',
			array(
				'label'    => __( 'Google reports sitemap errors', 'searchpilot-pro' ),
				'category' => 'sitemap',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$paths = array_map(
						static function ( $row ) {
							return Url::display( (string) ( $row['path'] ?? '' ) ) . ' (' . (int) ( $row['errors'] ?? 0 ) . ')';
						},
						(array) ( $issue['data']['sitemaps'] ?? array() )
					);
					/* translators: %s: sitemaps with error counts. */
					return sprintf( __( 'Search Console lists errors for: %s', 'searchpilot-pro' ), implode( ', ', $paths ) );
				},
				'why'      => __( 'Google may skip parts of a sitemap it cannot process.', 'searchpilot-pro' ),
				'fix'      => __( 'Open Search Console → Sitemaps for the error details, fix the cause, then resubmit from this screen.', 'searchpilot-pro' ),
				'action'   => 'fix_sitemap',
			)
		);
		$registry->register(
			'sitemap_not_submitted',
			array(
				'label'     => __( 'Sitemap not submitted to Google', 'searchpilot-pro' ),
				'category'  => 'sitemap',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					/* translators: %s: URL. */
					return sprintf( __( 'Google Search Console does not list %s.', 'searchpilot-pro' ), Url::display( (string) $issue['url'] ) );
				},
				'why'       => __( 'Submitting the sitemap lets Google report on it and discover new pages sooner.', 'searchpilot-pro' ),
				'fix'       => __( 'Submit the sitemap through the Search Console API.', 'searchpilot-pro' ),
				'fixer'     => array( $this, 'fix_not_submitted' ),
				'fix_label' => __( 'Submit sitemap', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_SUBMIT_SITEMAPS,
				'action'    => 'submit_sitemap',
			)
		);
		$registry->register(
			'sitemap_stale',
			array(
				'label'     => __( 'Sitemap looks out of date', 'searchpilot-pro' ),
				'category'  => 'sitemap',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					/* translators: 1: sitemap date, 2: content date. */
					return sprintf( __( 'The newest date in the sitemap is %1$s, but content was updated on %2$s.', 'searchpilot-pro' ), (string) ( $issue['data']['newest_lastmod'] ?? '' ), (string) ( $issue['data']['newest_content'] ?? '' ) );
				},
				'why'       => __( 'Search engines rely on lastmod dates to decide what to recrawl; a stale sitemap delays updates in search results.', 'searchpilot-pro' ),
				'fix'       => __( 'Regenerate the sitemap and purge page caches (LiteSpeed, Cloudflare) for the sitemap URLs.', 'searchpilot-pro' ),
				'fixer'     => array( $this, 'fix_stale' ),
				'fix_label' => __( 'Regenerate & purge', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_SAFE_FIXES,
				'action'    => 'fix_sitemap',
			)
		);
	}

	/**
	 * Fixer: submit the sitemap.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_not_submitted( array $issue ) {
		$class = '\SearchPilot\Queue\SubmissionQueue';
		if ( ! class_exists( $class ) ) {
			return new \WP_Error( 'searchpilot_unavailable', __( 'Search engine submission is not available.', 'searchpilot-pro' ) );
		}
		$url = (string) ( $issue['data']['url'] ?? self::active_sitemap_url() );
		\SearchPilot\Plugin::instance()->get( $class )->enqueue_sitemap( $url, 'fix', 'user' === ( $issue['source'] ?? '' ) );
		return true;
	}

	/**
	 * Fixer: regenerate (when ours) and purge caches of the sitemap URLs.
	 *
	 * @param array $issue Issue.
	 * @return true
	 */
	public function fix_stale( array $issue ) {
		$urls = array( self::active_sitemap_url() );
		if ( 'searchpilot' === $this->owner() ) {
			$this->cache->bump_all();
			foreach ( $this->builder->index_entries() as $child ) {
				$urls[] = $child['loc'];
			}
		} else {
			$health = (array) $this->state->get( self::STATE, array() );
			foreach ( (array) ( $health['sitemaps'] ?? array() ) as $item ) {
				$urls[] = (string) $item['url'];
			}
		}
		$this->purger->purge_urls( array_slice( array_unique( array_filter( $urls ) ), 0, 500 ) );
		return true;
	}
}

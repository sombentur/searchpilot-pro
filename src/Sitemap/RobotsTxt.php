<?php
/**
 * Virtual robots.txt management.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\RobotsParser;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Extends WordPress's virtual robots.txt (filter `robots_txt`, run late so
 * other SEO plugins' lines are already present): keeps the defaults,
 * appends validated custom rules and a `Sitemap:` line for the active
 * sitemap when none is listed. A physical robots.txt file in the web root
 * replaces the virtual file entirely — it is detected and reported.
 */
class RobotsTxt implements Module {

	const FIELDS = array(
		'user-agent'  => 'User-agent',
		'allow'       => 'Allow',
		'disallow'    => 'Disallow',
		'sitemap'     => 'Sitemap',
		'crawl-delay' => 'Crawl-delay',
		'clean-param' => 'Clean-param',
		'host'        => 'Host',
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $activity;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param IssueRepository $issues   Issues.
	 * @param HttpClient      $http     HTTP client.
	 * @param ActivityLog     $activity Activity log.
	 * @param CachePurger     $purger   Cache purger.
	 */
	public function __construct( Settings $settings, IssueRepository $issues, HttpClient $http, ActivityLog $activity, CachePurger $purger ) {
		$this->settings = $settings;
		$this->issues   = $issues;
		$this->http     = $http;
		$this->activity = $activity;
		$this->purger   = $purger;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter' ), 100000, 2 );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_daily', array( $this, 'check_physical' ), 25 );
		add_action( 'searchpilot_settings_updated', array( $this, 'on_settings' ), 30, 2 );
	}

	/**
	 * robots_txt filter.
	 *
	 * @param string $output Current output.
	 * @param string $public blog_public option.
	 * @return string
	 */
	public function filter( $output, $public ) {
		if ( ! $this->settings->get( 'robots.manage', true ) || '0' === (string) $public ) {
			return $output;
		}
		return self::compose( (string) $output, (string) $this->settings->get( 'robots.custom_rules', '' ), $this->settings->get( 'robots.sitemap_line', true ) ? SitemapHealth::active_sitemap_url() : '' );
	}

	/**
	 * Builds the final file from WordPress's output, custom rules and the sitemap line (pure).
	 *
	 * @param string $output      Output so far.
	 * @param string $custom      Custom rules text.
	 * @param string $sitemap_url Sitemap URL to list ('' = none).
	 * @return string
	 */
	public static function compose( $output, $custom, $sitemap_url ) {
		$output = rtrim( (string) $output );
		$clean  = self::clean_rules( $custom );
		if ( $clean['lines'] ) {
			$output .= ( '' !== $output ? "\n\n" : '' ) . implode( "\n", $clean['lines'] );
		}
		if ( '' !== (string) $sitemap_url && ! self::lists_sitemap( $output, $sitemap_url ) ) {
			$output .= ( '' !== $output ? "\n\n" : '' ) . 'Sitemap: ' . Url::encode_iri( $sitemap_url );
		}
		return $output . "\n";
	}

	/**
	 * Whether content already declares a sitemap URL (pure).
	 *
	 * @param string $content Content.
	 * @param string $url     URL.
	 * @return bool
	 */
	public static function lists_sitemap( $content, $url ) {
		$want = strtolower( rtrim( Url::encode_iri( trim( (string) $url ) ), '/' ) );
		foreach ( RobotsParser::parse( (string) $content )->sitemaps() as $line ) {
			if ( strtolower( rtrim( Url::encode_iri( trim( $line ) ), '/' ) ) === $want ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validates custom rules (pure).
	 *
	 * @param string $text Rules text.
	 * @return array{lines:string[],invalid:array<int,array{line:int,text:string,reason:string}>,notes:string[]}
	 */
	public static function clean_rules( $text ) {
		$lines   = array();
		$invalid = array();
		$notes   = array();
		$has_ua  = false;
		$number  = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $raw ) {
			++$number;
			$line = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $raw ) );
			if ( '' === $line ) {
				if ( $lines && '' !== end( $lines ) ) {
					$lines[] = '';
				}
				continue;
			}
			if ( '#' === $line[0] ) {
				$lines[] = $line;
				continue;
			}
			if ( false === strpos( $line, ':' ) ) {
				$invalid[] = array(
					'line'   => $number,
					'text'   => $line,
					'reason' => __( 'Not a “Field: value” line.', 'searchpilot-pro' ),
				);
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$comment               = '';
			if ( false !== strpos( $value, '#' ) && 'sitemap' !== strtolower( $field ) ) {
				$comment = ' ' . substr( $value, strpos( $value, '#' ) );
				$value   = trim( substr( $value, 0, strpos( $value, '#' ) ) );
			}
			$key = strtolower( $field );
			if ( 'noindex' === $key ) {
				$invalid[] = array(
					'line'   => $number,
					'text'   => $line,
					'reason' => __( 'Google stopped supporting “Noindex” in robots.txt in 2019. Use a robots meta tag instead.', 'searchpilot-pro' ),
				);
				continue;
			}
			if ( ! isset( self::FIELDS[ $key ] ) ) {
				$invalid[] = array(
					'line'   => $number,
					'text'   => $line,
					/* translators: %s: field name. */
					'reason' => sprintf( __( 'Unknown field “%s”.', 'searchpilot-pro' ), $field ),
				);
				continue;
			}
			switch ( $key ) {
				case 'user-agent':
					if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_\-.*]+$/', $value ) ) {
						$invalid[] = array(
							'line'   => $number,
							'text'   => $line,
							'reason' => __( 'A user-agent is a crawler name such as Googlebot, bingbot or *.', 'searchpilot-pro' ),
						);
						continue 2;
					}
					$has_ua = true;
					break;
				case 'allow':
				case 'disallow':
					if ( ! $has_ua ) {
						$invalid[] = array(
							'line'   => $number,
							'text'   => $line,
							'reason' => __( 'Add a “User-agent:” line before rules; crawlers ignore rules outside a group.', 'searchpilot-pro' ),
						);
						continue 2;
					}
					if ( '' !== $value && '/' !== $value[0] && '*' !== $value[0] ) {
						$invalid[] = array(
							'line'   => $number,
							'text'   => $line,
							'reason' => __( 'Paths must start with “/” (or “*”).', 'searchpilot-pro' ),
						);
						continue 2;
					}
					$value = Url::encode_iri( $value );
					break;
				case 'sitemap':
					if ( ! preg_match( '#^https?://\S+$#i', $value ) ) {
						$invalid[] = array(
							'line'   => $number,
							'text'   => $line,
							'reason' => __( 'Sitemap lines need a full URL starting with https://.', 'searchpilot-pro' ),
						);
						continue 2;
					}
					$value = Url::encode_iri( $value );
					break;
				case 'crawl-delay':
					if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 60 ) {
						$invalid[] = array(
							'line'   => $number,
							'text'   => $line,
							'reason' => __( 'Crawl-delay must be a number of seconds (0–60).', 'searchpilot-pro' ),
						);
						continue 2;
					}
					$notes[] = __( 'Google ignores Crawl-delay (Bing and Yandex respect it). Manage Googlebot’s crawl rate in Search Console.', 'searchpilot-pro' );
					break;
				case 'host':
					$notes[] = __( '“Host” is only read by Yandex.', 'searchpilot-pro' );
					break;
			}
			$lines[] = self::FIELDS[ $key ] . ': ' . $value . $comment;
		}
		while ( $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return array(
			'lines'   => $lines,
			'invalid' => $invalid,
			'notes'   => array_values( array_unique( $notes ) ),
		);
	}

	/**
	 * Warnings when the file blocks important sections (pure).
	 *
	 * @param string $content  Full robots.txt content.
	 * @param array  $critical label => path.
	 * @return array<int,array{severity:string,agent:string,path:string,label:string,rule:string,message:string}>
	 */
	public static function warnings( $content, array $critical ) {
		$parser = RobotsParser::parse( (string) $content );
		$out    = array();
		foreach ( array( 'Googlebot', 'bingbot' ) as $agent ) {
			foreach ( $critical as $label => $path ) {
				if ( '' === (string) $path || $parser->is_allowed( $path, $agent ) ) {
					continue;
				}
				$rule  = $parser->matching_rule( $path, $agent );
				$out[] = array(
					'severity' => in_array( $label, array( 'home', 'content' ), true ) || '/' === $path ? 'critical' : 'high',
					'agent'    => $agent,
					'path'     => (string) $path,
					'label'    => (string) $label,
					'rule'     => $rule ? ucfirst( $rule[0] ) . ': ' . $rule[1] : '',
					/* translators: 1: crawler, 2: section path, 3: rule. */
					'message'  => sprintf( __( '%1$s is blocked from %2$s by “%3$s”.', 'searchpilot-pro' ), $agent, $path, $rule ? ucfirst( $rule[0] ) . ': ' . $rule[1] : '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Important paths that must stay crawlable.
	 *
	 * @return array<string,string> label => root-relative path.
	 */
	public function critical_paths() {
		$paths = array(
			'home'    => Url::to_path( home_url( '/' ) ),
			'uploads' => Url::to_path( trailingslashit( (string) ( wp_get_upload_dir()['baseurl'] ?? content_url( 'uploads' ) ) ) . 'example.jpg' ),
			'css'     => Url::to_path( includes_url( 'css/dist/block-library/style.min.css' ) ),
			'js'      => Url::to_path( includes_url( 'js/jquery/jquery.min.js' ) ),
			'theme'   => Url::to_path( get_stylesheet_uri() ),
		);
		$latest = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => true,
			)
		);
		if ( $latest ) {
			$paths['content'] = Url::to_path( get_permalink( $latest[0] ) );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_array( $terms ) && $terms ) {
			$link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $link ) ) {
				$paths['category'] = Url::to_path( $link );
			}
		}
		$sitemap = SitemapHealth::active_sitemap_url();
		if ( '' !== $sitemap ) {
			$paths['sitemap'] = Url::to_path( $sitemap );
		}
		return $paths;
	}

	/**
	 * The virtual file WordPress would serve (same logic as do_robots()).
	 *
	 * @param string|null $custom       Custom rules override (for previews).
	 * @param bool|null   $sitemap_line Sitemap line override.
	 * @return string
	 */
	public function virtual_content( $custom = null, $sitemap_line = null ) {
		$public   = (string) get_option( 'blog_public' );
		$site_url = wp_parse_url( site_url() );
		$path     = ! empty( $site_url['path'] ) ? $site_url['path'] : '';
		$output   = "User-agent: *\n";
		$output  .= "Disallow: $path/wp-admin/\n";
		$output  .= "Allow: $path/wp-admin/admin-ajax.php\n";
		$override = null !== $custom || null !== $sitemap_line;
		if ( $override ) {
			remove_filter( 'robots_txt', array( $this, 'filter' ), 100000 );
		}
		/** This filter is documented in wp-includes/functions.php */
		$output = (string) apply_filters( 'robots_txt', $output, $public );
		if ( $override ) {
			add_filter( 'robots_txt', array( $this, 'filter' ), 100000, 2 );
			if ( $this->settings->get( 'robots.manage', true ) && '0' !== $public ) {
				$output = self::compose(
					$output,
					null !== $custom ? Settings::sanitize_raw_text( $custom ) : (string) $this->settings->get( 'robots.custom_rules', '' ),
					( null !== $sitemap_line ? $sitemap_line : $this->settings->get( 'robots.sitemap_line', true ) ) ? SitemapHealth::active_sitemap_url() : ''
				);
			}
		}
		return $output;
	}

	/**
	 * The robots.txt crawlers actually receive (loopback fetch).
	 *
	 * @return array{fetched:bool,status:int,content:string,error:string}
	 */
	public function effective() {
		$response = $this->http->get(
			home_url( '/robots.txt' ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'channel'             => 'sitemap',
				'log_errors'          => false,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'resolve_to'          => (string) $this->settings->get( 'indexing.origin_ip', '' ),
				'limit_response_size' => 524288,
			)
		);
		return array(
			'fetched' => 200 === $response->code,
			'status'  => (int) $response->code,
			'content' => 200 === $response->code ? mb_substr( $response->body, 0, 100000 ) : '',
			'error'   => 200 === $response->code ? '' : $response->error_message(),
		);
	}

	/**
	 * Physical robots.txt in the web root (it replaces the virtual file).
	 *
	 * @return array{exists:bool,content:string,path:string}
	 */
	public function physical_file() {
		$file = ABSPATH . 'robots.txt';
		if ( ! file_exists( $file ) ) {
			return array(
				'exists'  => false,
				'content' => '',
				'path'    => '',
			);
		}
		$content = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 102400 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return array(
			'exists'  => true,
			'content' => $content,
			'path'    => 'robots.txt',
		);
	}

	/**
	 * Daily: reports a physical robots.txt.
	 *
	 * @return bool Whether one exists.
	 */
	public function check_physical() {
		$physical = $this->physical_file();
		if ( $physical['exists'] ) {
			$this->issues->upsert(
				'robots_physical_file',
				array(
					'object_type' => 'site',
					'url'         => home_url( '/robots.txt' ),
					'title'       => 'robots.txt',
					'data'        => array( 'managed' => (bool) $this->settings->get( 'robots.manage', true ) ),
				)
			);
		} else {
			$this->issues->resolve( 'robots_physical_file', 'site', 0, home_url( '/robots.txt' ) );
		}
		return $physical['exists'];
	}

	/**
	 * Full payload for the robots.txt screen.
	 *
	 * @param bool $fetch Fetch the live file.
	 * @return array
	 */
	public function report( $fetch = true ) {
		$physical = $this->physical_file();
		$this->check_physical();
		$virtual   = $this->virtual_content();
		$effective = $fetch ? $this->effective() : array(
			'fetched' => false,
			'status'  => 0,
			'content' => '',
			'error'   => '',
		);
		$custom    = (string) $this->settings->get( 'robots.custom_rules', '' );
		$clean     = self::clean_rules( $custom );
		$basis     = $effective['fetched'] ? $effective['content'] : ( $physical['exists'] ? $physical['content'] : $virtual );
		return array(
			'managed'       => (bool) $this->settings->get( 'robots.manage', true ),
			'public'        => '0' !== (string) get_option( 'blog_public', '1' ),
			'custom_rules'  => $custom,
			'sitemap_line'  => (bool) $this->settings->get( 'robots.sitemap_line', true ),
			'sitemap_url'   => SitemapHealth::active_sitemap_url(),
			'url'           => home_url( '/robots.txt' ),
			'physical'      => $physical,
			'virtual'       => $virtual,
			'effective'     => $effective,
			'invalid'       => $clean['invalid'],
			'notes'         => $clean['notes'],
			'warnings'      => self::warnings( $basis, $this->critical_paths() ),
			'lists_sitemap' => '' !== SitemapHealth::active_sitemap_url() && self::lists_sitemap( $basis, SitemapHealth::active_sitemap_url() ),
		);
	}

	/**
	 * Preview for unsaved custom rules (live validation in the editor).
	 *
	 * @param string $custom       Rules.
	 * @param bool   $sitemap_line Sitemap line.
	 * @return array{virtual:string,invalid:array,notes:array,warnings:array}
	 */
	public function preview( $custom, $sitemap_line ) {
		// Exactly what saving stores, so the preview equals the saved file.
		$custom  = Settings::sanitize_raw_text( $custom );
		$virtual = $this->virtual_content( $custom, (bool) $sitemap_line );
		$clean   = self::clean_rules( $custom );
		return array(
			'virtual'  => $virtual,
			'invalid'  => $clean['invalid'],
			'notes'    => $clean['notes'],
			'warnings' => self::warnings( $virtual, $this->critical_paths() ),
		);
	}

	/**
	 * Saves the robots settings (recorded like a settings change, so it can be undone).
	 *
	 * @param array $changes custom_rules (string), sitemap_line (bool), manage (bool).
	 * @return array{saved:int,errors:array}
	 */
	public function save( array $changes ) {
		$keys   = array(
			'custom_rules' => 'robots.custom_rules',
			'sitemap_line' => 'robots.sitemap_line',
			'manage'       => 'robots.manage',
		);
		$update = array();
		$before = array();
		foreach ( $keys as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$update[ $key ] = $changes[ $field ];
				$before[ $key ] = $this->settings->get( $key );
			}
		}
		if ( empty( $update ) ) {
			return array(
				'saved'  => 0,
				'errors' => array(),
			);
		}
		$result = $this->settings->update( $update );
		$after  = array();
		foreach ( array_keys( $update ) as $key ) {
			$value = $this->settings->get( $key );
			if ( wp_json_encode( $value ) !== wp_json_encode( $before[ $key ] ) ) {
				$after[ $key ] = $value;
			} else {
				unset( $before[ $key ] );
			}
		}
		if ( $after ) {
			$this->activity->record(
				array(
					'action'      => 'settings_updated',
					/* translators: %s: comma separated setting keys. */
					'description' => sprintf( __( 'Changed settings: %s', 'searchpilot-pro' ), implode( ', ', array_keys( $after ) ) ),
					'object_type' => 'settings',
					'handler'     => 'settings',
					'before'      => $before,
					'after'       => $after,
					'source'      => 'user',
				)
			);
		}
		return array(
			'saved'  => count( $after ),
			'errors' => $result['errors'],
		);
	}

	/**
	 * Tests a URL against the live (or virtual) robots.txt.
	 *
	 * @param string $url   URL or path.
	 * @param string $agent Crawler token.
	 * @return array|\WP_Error
	 */
	public function test( $url, $agent = 'Googlebot' ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Enter an address to test.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( preg_match( '#^https?://#i', $url ) && ! Url::is_internal( $url ) ) {
			return new \WP_Error( 'searchpilot_external', __( 'Test addresses on this site only.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$agent     = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $agent );
		$agent     = '' !== $agent ? $agent : 'Googlebot';
		$effective = $this->effective();
		$physical  = $this->physical_file();
		$source    = 'live';
		$content   = $effective['content'];
		if ( ! $effective['fetched'] ) {
			$source  = $physical['exists'] ? 'file' : 'virtual';
			$content = $physical['exists'] ? $physical['content'] : $this->virtual_content();
		}
		$parser = RobotsParser::parse( $content );
		$path   = Url::to_path( $url );
		$rule   = $parser->matching_rule( $path, $agent );
		return array(
			'url'     => Url::absolute( $path ),
			'path'    => $path,
			'agent'   => $agent,
			'allowed' => $parser->is_allowed( $path, $agent ),
			'rule'    => $rule ? array(
				'type'    => $rule[0],
				'pattern' => $rule[1],
				'line'    => ucfirst( $rule[0] ) . ': ' . $rule[1],
			) : null,
			'source'  => $source,
		);
	}

	/**
	 * Purges robots.txt from page caches when its settings change.
	 *
	 * @param array $new New settings.
	 * @param array $old Old settings.
	 */
	public function on_settings( $new, $old ) {
		if ( wp_json_encode( $new['robots'] ?? array() ) !== wp_json_encode( $old['robots'] ?? array() ) || wp_json_encode( $new['ownership'] ?? array() ) !== wp_json_encode( $old['ownership'] ?? array() ) ) {
			$this->purger->purge_urls( array( home_url( '/robots.txt' ) ) );
		}
	}

	/**
	 * Registers robots.txt issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			'robots_physical_file',
			array(
				'label'    => __( 'A robots.txt file overrides WordPress', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'medium',
				'what'     => __( 'A physical robots.txt file exists in the site’s root folder, so WordPress never serves its virtual robots.txt.', 'searchpilot-pro' ),
				'why'      => __( 'Rules and the sitemap line managed by SearchPilot (and your SEO plugin) are not applied while the file exists, and the file is easily forgotten after migrations.', 'searchpilot-pro' ),
				'fix'      => __( 'Copy any rules you still need into SearchPilot → Sitemaps → robots.txt, then delete or rename the robots.txt file on the server (via your host’s file manager or FTP).', 'searchpilot-pro' ),
				'action'   => 'fix_robots',
			)
		);
	}
}

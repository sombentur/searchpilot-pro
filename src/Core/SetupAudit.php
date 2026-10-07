<?php
/**
 * Quick website audit for the setup wizard and System screen.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Support\HtmlDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Fast, read-only checks that catch site-wide discoverability blockers.
 * Each check: {id, label, status: pass|warning|fail|info, message}.
 */
class SetupAudit {

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $env;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param Environment      $env       Environment.
	 * @param HttpClient       $http      HTTP.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param State            $state     State.
	 */
	public function __construct( Environment $env, HttpClient $http, ConflictDetector $conflicts, Scheduler $scheduler, State $state ) {
		$this->env       = $env;
		$this->http      = $http;
		$this->conflicts = $conflicts;
		$this->scheduler = $scheduler;
		$this->state     = $state;
	}

	/**
	 * Runs all checks and stores the result.
	 *
	 * @return array{checks:array,ran_at:string,score:int}
	 */
	public function run() {
		$checks = array();

		$production = 'production' === $this->env->type();
		$checks[]   = $this->check(
			'search_visibility',
			__( 'Search engine visibility', 'searchpilot-pro' ),
			$this->env->search_engines_discouraged() ? ( $production ? 'fail' : 'warning' ) : 'pass',
			$this->env->search_engines_discouraged()
				? __( 'Settings → Reading → “Discourage search engines from indexing this site” is ON. The whole site asks search engines not to index it.', 'searchpilot-pro' )
				: __( 'Search engines are allowed to index the site.', 'searchpilot-pro' )
		);

		$checks[] = $this->check(
			'permalinks',
			__( 'Permalinks', 'searchpilot-pro' ),
			$this->env->pretty_permalinks() ? 'pass' : 'fail',
			$this->env->pretty_permalinks()
				? __( 'Readable permalinks are enabled.', 'searchpilot-pro' )
				: __( 'Plain permalinks (?p=123) are in use. Choose a readable structure in Settings → Permalinks; sitemaps also need it.', 'searchpilot-pro' )
		);

		$checks[] = $this->check(
			'https',
			__( 'HTTPS', 'searchpilot-pro' ),
			$this->env->https() ? 'pass' : 'warning',
			$this->env->https()
				? __( 'The site address uses HTTPS.', 'searchpilot-pro' )
				: __( 'The site address uses HTTP. Move to HTTPS and update Settings → General so every URL is canonical on HTTPS.', 'searchpilot-pro' )
		);

		$reason   = $this->env->submission_block_reason();
		$checks[] = $this->check(
			'environment',
			__( 'Environment', 'searchpilot-pro' ),
			in_array( $reason, array( '', 'setup' ), true ) ? 'pass' : 'warning',
			in_array( $reason, array( '', 'setup' ), true )
				/* translators: %s: environment type. */
				? sprintf( __( 'Production site (%s): SearchPilot will notify search engines once setup is finished.', 'searchpilot-pro' ), $this->env->type() )
				: $this->env->submission_block_message()
		);

		$home = $this->fetch( home_url( '/' ) );
		if ( 0 === $home->code ) {
			$checks[] = $this->check( 'loopback', __( 'Self-check (loopback)', 'searchpilot-pro' ), 'warning', __( 'SearchPilot could not fetch the home page from the server itself. Page-level audits will rely on WordPress data only. A firewall, basic auth or CDN bot protection may be blocking the request; you can set an origin IP in Settings → Indexing.', 'searchpilot-pro' ) );
		} else {
			$checks[] = $this->check(
				'loopback',
				__( 'Self-check (loopback)', 'searchpilot-pro' ),
				$home->ok() ? 'pass' : 'warning',
				/* translators: 1: HTTP status, 2: milliseconds. */
				sprintf( __( 'Home page answered HTTP %1$d in %2$d ms.', 'searchpilot-pro' ), $home->code, $home->duration_ms )
			);
			if ( $home->ok() ) {
				$doc     = HtmlDocument::parse( $home->body, home_url( '/' ) );
				$robots  = $doc->robots();
				$header  = strtolower( $home->header( 'x-robots-tag' ) );
				$noindex = in_array( 'noindex', $robots, true ) || in_array( 'none', $robots, true ) || false !== strpos( $header, 'noindex' );
				$checks[] = $this->check(
					'home_noindex',
					__( 'Home page robots directives', 'searchpilot-pro' ),
					$noindex ? 'fail' : 'pass',
					$noindex
						? __( 'The home page tells search engines not to index it (noindex). This is usually accidental.', 'searchpilot-pro' )
						: __( 'The home page can be indexed.', 'searchpilot-pro' )
				);
				$canonicals = $doc->link_rel( 'canonical' );
				if ( count( $canonicals ) > 1 ) {
					$checks[] = $this->check( 'home_canonical', __( 'Home page canonical', 'searchpilot-pro' ), 'fail', __( 'The home page has more than one canonical tag — two plugins or the theme are both printing it.', 'searchpilot-pro' ) );
				}
			}
		}

		$robots_resp = $this->fetch( home_url( '/robots.txt' ) );
		if ( $robots_resp->ok() ) {
			$blocked  = $this->robots_blocks_all( $robots_resp->body );
			$checks[] = $this->check(
				'robots_txt',
				'robots.txt',
				$blocked ? 'fail' : 'pass',
				$blocked
					? __( 'robots.txt blocks the whole site for all crawlers (Disallow: /).', 'searchpilot-pro' )
					: __( 'robots.txt is reachable and does not block the whole site.', 'searchpilot-pro' )
			);
		} else {
			$checks[] = $this->check( 'robots_txt', 'robots.txt', 'warning', __( 'robots.txt could not be fetched. Crawlers treat a missing file as “allow all”, but a 5xx answer can stop crawling.', 'searchpilot-pro' ) );
		}

		$owner    = $this->conflicts->owner( 'sitemap' );
		$plugins  = $this->conflicts->detect();
		$checks[] = $this->check(
			'sitemap',
			__( 'XML sitemap', 'searchpilot-pro' ),
			'off' === $owner ? 'warning' : 'pass',
			'searchpilot' === $owner
				? __( 'SearchPilot will generate the XML sitemaps.', 'searchpilot-pro' )
				: ( 'off' === $owner
					? __( 'Sitemaps are switched off. Search engines discover pages more slowly without one.', 'searchpilot-pro' )
					/* translators: %s: plugin name. */
					: sprintf( __( 'Sitemaps are generated by %s. SearchPilot will monitor and submit them instead of creating duplicates.', 'searchpilot-pro' ), $plugins[ $owner ]['name'] ?? $owner ) )
		);

		$seo = array();
		foreach ( $plugins as $plugin ) {
			if ( 'seo' === $plugin['kind'] ) {
				$seo[] = $plugin['name'];
			}
		}
		// Two SEO plugins printing the same tags produce duplicate canonicals/descriptions.
		$overlap = array();
		foreach ( array( 'description', 'canonical' ) as $feature ) {
			$providers = $this->conflicts->providers( $feature );
			if ( count( $providers ) > 1 ) {
				foreach ( $providers as $id ) {
					$overlap[ $id ] = $plugins[ $id ]['name'] ?? $id;
				}
			}
		}
		if ( $overlap ) {
			$checks[] = $this->check(
				'seo_plugins',
				__( 'Other SEO plugins', 'searchpilot-pro' ),
				'warning',
				/* translators: %s: list of plugin names, e.g. "Rank Math SEO and WP Meta SEO". */
				sprintf( __( '%s each print meta descriptions and canonical links, so pages carry these tags twice. Keep one SEO plugin and deactivate the others.', 'searchpilot-pro' ), wp_sprintf( '%l', array_values( $overlap ) ) )
			);
		} else {
			$checks[] = $this->check(
				'seo_plugins',
				__( 'Other SEO plugins', 'searchpilot-pro' ),
				'info',
				$seo
					/* translators: %s: plugin names. */
					? sprintf( __( 'Detected: %s. SearchPilot runs in companion mode for the features they already handle, so nothing is output twice.', 'searchpilot-pro' ), implode( ', ', $seo ) )
					: __( 'No other SEO plugin detected. SearchPilot will handle titles, meta, schema and sitemaps.', 'searchpilot-pro' )
			);
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[]      = $this->check(
			'background',
			__( 'Background processing', 'searchpilot-pro' ),
			$this->scheduler->healthy() ? ( $cron_disabled ? 'info' : 'pass' ) : 'warning',
			$this->scheduler->healthy()
				? ( $cron_disabled ? __( 'WP-Cron is disabled in wp-config.php; make sure a server cron calls wp-cron.php every few minutes.', 'searchpilot-pro' ) : __( 'Background jobs are running.', 'searchpilot-pro' ) )
				: __( 'Background jobs have not run recently. Check that WP-Cron (or a server cron) is working.', 'searchpilot-pro' )
		);

		$checks[] = $this->check(
			'encryption',
			__( 'Credential encryption', 'searchpilot-pro' ),
			Crypto::available() ? 'pass' : 'fail',
			Crypto::available()
				? __( 'API credentials are encrypted before they are stored.', 'searchpilot-pro' )
				: __( 'Neither libsodium nor OpenSSL is available, so API credentials cannot be stored securely.', 'searchpilot-pro' )
		);

		$weights = array(
			'pass'    => 1,
			'info'    => 1,
			'warning' => 0.5,
			'fail'    => 0,
		);
		$sum     = 0.0;
		foreach ( $checks as $check ) {
			$sum += $weights[ $check['status'] ] ?? 0;
		}
		$result = array(
			'checks' => $checks,
			'ran_at' => gmdate( 'c' ),
			'score'  => (int) round( 100 * $sum / max( 1, count( $checks ) ) ),
		);
		$this->state->set( 'setup_audit', $result );
		return $result;
	}

	/**
	 * Last stored result.
	 *
	 * @return array|null
	 */
	public function last() {
		$last = $this->state->get( 'setup_audit' );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Fetches a URL of this site.
	 *
	 * @param string $url URL.
	 * @return Http\HttpResponse
	 */
	private function fetch( $url ) {
		return $this->http->get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 3,
				'channel'     => 'audit',
				'log_errors'  => false,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'resolve_to'  => (string) \SearchPilot\Plugin::instance()->get( Settings::class )->get( 'indexing.origin_ip' ),
			)
		);
	}

	/**
	 * Whether robots.txt disallows "/" for every crawler.
	 *
	 * @param string $body robots.txt.
	 * @return bool
	 */
	private function robots_blocks_all( $body ) {
		$group_is_star = false;
		$in_group      = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( ! $in_group ) {
					$group_is_star = false;
				}
				$in_group = true;
				if ( '*' === $value ) {
					$group_is_star = true;
				}
				continue;
			}
			$in_group = false;
			if ( $group_is_star && 'disallow' === $field && '/' === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check row.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $status  pass|warning|fail|info.
	 * @param string $message Message.
	 * @return array
	 */
	private function check( $id, $label, $status, $message ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}
}

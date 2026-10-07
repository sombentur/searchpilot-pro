<?php
/**
 * Environment facts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this the live site?" and similar environment questions.
 */
class Environment {

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
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param State    $state    State.
	 */
	public function __construct( Settings $settings, State $state ) {
		$this->settings = $settings;
		$this->state    = $state;
	}

	/**
	 * WordPress environment type.
	 *
	 * @return string production|staging|development|local
	 */
	public function type() {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}

	/**
	 * Whether the host looks local/private (never submit such URLs).
	 *
	 * @return bool
	 */
	public function is_local_host() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}
		foreach ( array( '.local', '.localhost', '.test', '.invalid', '.example', '.lan', '.internal' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Whether outgoing submissions are allowed: public host, setup wizard
	 * finished, and production (unless the environment guard is off).
	 *
	 * @return bool
	 */
	public function submissions_allowed() {
		return '' === $this->submission_block_reason();
	}

	/**
	 * Why submissions are held ('' when they are allowed).
	 *
	 * @return string local|setup|environment|''
	 */
	public function submission_block_reason() {
		$reason = '';
		if ( $this->is_local_host() ) {
			$reason = 'local';
		} elseif ( ! $this->state->get( 'wizard_completed', false ) ) {
			$reason = 'setup';
		} elseif ( $this->search_engines_discouraged() ) {
			$reason = 'discouraged';
		} elseif ( $this->address_changed() ) {
			$reason = 'moved';
		} elseif ( $this->settings->get( 'general.environment_guard', true ) && 'production' !== $this->type() ) {
			$reason = 'environment';
		}
		/**
		 * Filters why search-engine submissions are held ('' allows them).
		 * Used by development tooling; do not return '' on staging sites.
		 *
		 * @param string $reason local|setup|environment|''.
		 */
		return (string) apply_filters( 'searchpilot_submission_block_reason', $reason );
	}

	/**
	 * Human explanation of submission_block_reason().
	 *
	 * @return string
	 */
	public function submission_block_message() {
		switch ( $this->submission_block_reason() ) {
			case 'local':
				return __( 'This site has a local or private address, so nothing is sent to search engines.', 'searchpilot-pro' );
			case 'setup':
				return __( 'Submissions start once the setup wizard is finished.', 'searchpilot-pro' );
			case 'discouraged':
				return __( 'Settings → Reading → “Discourage search engines” is on, so nothing is sent to search engines.', 'searchpilot-pro' );
			case 'moved':
				/* translators: %s: address recorded when setup was finished. */
				return sprintf( __( 'This site’s address changed since setup was finished (it was %s) — for example a staging copy or a migration. Submissions are held until you finish the setup wizard again on this address.', 'searchpilot-pro' ), (string) $this->state->get( 'site_address', '' ) );
			case 'environment':
				return __( 'This is not the production site (wp_get_environment_type), so submissions are held. Settings → General → Environment guard controls this.', 'searchpilot-pro' );
		}
		return '';
	}

	/**
	 * The site's address as recorded for the submission guard: host + path,
	 * without scheme or "www." (an HTTP→HTTPS move is not a new site).
	 *
	 * @return string
	 */
	public static function site_address() {
		$parts = wp_parse_url( home_url( '/' ) );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host  = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		return $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Remembers the current address (called when the setup wizard is finished).
	 */
	public function record_site_address() {
		$this->state->set( 'site_address', self::site_address() );
	}

	/**
	 * Whether the address differs from the one recorded at setup (cloned or moved site).
	 *
	 * @return bool
	 */
	public function address_changed() {
		$recorded = (string) $this->state->get( 'site_address', '' );
		return '' !== $recorded && $recorded !== self::site_address();
	}

	/**
	 * Whether search engines are discouraged (Settings → Reading).
	 *
	 * @return bool
	 */
	public function search_engines_discouraged() {
		return '0' === (string) get_option( 'blog_public', '1' );
	}

	/**
	 * Whether pretty permalinks are enabled.
	 *
	 * @return bool
	 */
	public function pretty_permalinks() {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * Whether the site uses HTTPS.
	 *
	 * @return bool
	 */
	public function https() {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	/**
	 * Whether the request came through Cloudflare (best effort).
	 *
	 * @return bool
	 */
	public function behind_cloudflare() {
		return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Summary for the System screen.
	 *
	 * @return array
	 */
	public function summary() {
		global $wp_version, $wpdb;
		return array(
			'php'                 => PHP_VERSION,
			'wordpress'           => $wp_version,
			'mysql'               => $wpdb->db_version(),
			'environment'         => $this->type(),
			'multisite'           => is_multisite(),
			'https'               => $this->https(),
			'pretty_permalinks'   => $this->pretty_permalinks(),
			'discouraged'         => $this->search_engines_discouraged(),
			'local_host'          => $this->is_local_host(),
			'submissions_allowed' => $this->submissions_allowed(),
			'held_reason'         => $this->submission_block_message(),
			'object_cache'        => (bool) wp_using_ext_object_cache(),
			'cron_disabled'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'scheduler'           => Scheduler::backend(),
			'crypto'              => Crypto::available(),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'woocommerce'         => class_exists( 'WooCommerce' ),
			'cloudflare'          => $this->behind_cloudflare(),
		);
	}
}

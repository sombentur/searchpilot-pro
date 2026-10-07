<?php
/**
 * Environment requirement checks.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP and WordPress versions before anything else loads.
 */
final class Requirements {

	/**
	 * Whether the environment is supported.
	 *
	 * @return bool
	 */
	public static function met() {
		return empty( self::problems() );
	}

	/**
	 * Human readable problems.
	 *
	 * @return string[]
	 */
	public static function problems() {
		global $wp_version;
		$problems = array();
		if ( version_compare( PHP_VERSION, SEARCHPILOT_MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: current PHP version. */
			$problems[] = sprintf( __( 'SearchPilot Pro needs PHP %1$s or newer (this site runs %2$s).', 'searchpilot-pro' ), SEARCHPILOT_MIN_PHP, PHP_VERSION );
		}
		if ( isset( $wp_version ) && version_compare( $wp_version, SEARCHPILOT_MIN_WP, '<' ) ) {
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			$problems[] = sprintf( __( 'SearchPilot Pro needs WordPress %1$s or newer (this site runs %2$s).', 'searchpilot-pro' ), SEARCHPILOT_MIN_WP, $wp_version );
		}
		if ( ! function_exists( 'json_encode' ) || ! function_exists( 'mb_strlen' ) ) {
			$problems[] = __( 'SearchPilot Pro needs the PHP json and mbstring extensions.', 'searchpilot-pro' );
		}
		return $problems;
	}

	/**
	 * Admin notice listing problems.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( self::problems() as $problem ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $problem ) );
		}
	}
}

<?php
/**
 * Deactivation routine.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stops background work and removes rewrite rules. Data is kept.
 */
final class Deactivator {

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	/**
	 * Deactivates the current site.
	 */
	public static function deactivate_site() {
		Scheduler::unschedule_all();
		delete_transient( 'searchpilot_activation_redirect' );
		// Our rules are no longer registered at this point, so a flush removes them.
		delete_option( 'rewrite_rules' );
		delete_option( 'searchpilot_flush_rewrite' );
		foreach ( array( 'queue', 'crawl', 'inspect', 'analytics', 'audit', 'links', 'import' ) as $lock ) {
			Lock::release( $lock );
		}
		/**
		 * Fires after SearchPilot was deactivated on a site.
		 */
		do_action( 'searchpilot_deactivated' );
	}
}

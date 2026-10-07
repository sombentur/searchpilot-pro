<?php
/**
 * Uninstall routine.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Always removes secrets, schedules, locks and transients. Removes tables,
 * settings and SearchPilot post/term meta only when "Delete all data on
 * uninstall" is enabled (Settings → General).
 */
final class Uninstaller {

	/**
	 * Uninstalls on every site of the network (or the single site).
	 */
	public static function uninstall() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::uninstall_site();
				restore_current_blog();
			}
			return;
		}
		self::uninstall_site();
	}

	/**
	 * Uninstalls the current site.
	 */
	public static function uninstall_site() {
		global $wpdb;

		$settings = get_option( 'searchpilot_settings', array() );
		$delete   = is_array( $settings ) && ! empty( $settings['general']['delete_data_on_uninstall'] );

		// Always: credentials and runtime scaffolding.
		delete_option( 'searchpilot_credentials' );
		delete_option( 'searchpilot_quota' );
		delete_option( 'searchpilot_flush_rewrite' );
		delete_option( 'searchpilot_bing_daily_quota' );
		delete_option( 'searchpilot_purge_queue' );
		Scheduler::unschedule_all();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_searchpilot\\_%' OR option_name LIKE '\\_transient\\_timeout\\_searchpilot\\_%' OR option_name LIKE 'searchpilot\\_lock\\_%'" );

		if ( ! $delete ) {
			return;
		}

		Schema::drop();
		delete_option( 'searchpilot_settings' );
		delete_option( 'searchpilot_state' );
		delete_option( 'searchpilot_db_version' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'searchpilot\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_searchpilot\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\\_searchpilot\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'searchpilot\\_%'" );
		// phpcs:enable
		Capabilities::uninstall();
		wp_cache_flush();
	}
}

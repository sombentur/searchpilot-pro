<?php
/**
 * Activation routine.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Installs tables, capabilities, IndexNow key and schedules, per site.
 */
final class Activator {

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( ! Requirements::met() ) {
			deactivate_plugins( SEARCHPILOT_BASENAME );
			wp_die(
				esc_html( implode( ' ', Requirements::problems() ) ),
				esc_html__( 'Plugin activation failed', 'searchpilot-pro' ),
				array( 'back_link' => true )
			);
		}

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site( false );
				restore_current_blog();
			}
			self::reset_site_caches();
			return;
		}
		self::activate_site( true );
	}

	/**
	 * Installs for the current site.
	 *
	 * @param bool $redirect Offer the setup wizard after activation.
	 */
	public static function activate_site( $redirect = true ) {
		self::reset_site_caches();
		Migrator::upgrade( (string) get_option( Migrator::OPTION, '' ) );
		Capabilities::install();

		$plugin   = \SearchPilot\Plugin::instance();
		$settings = $plugin->get( Settings::class );
		$settings->flush();
		if ( '' === (string) $settings->get( 'indexing.indexnow_key' ) ) {
			$settings->update( array( 'indexing.indexnow_key' => self::generate_indexnow_key() ) );
		}

		$state = $plugin->get( State::class );
		if ( ! $state->get( 'installed_at' ) ) {
			$state->set( 'installed_at', gmdate( 'c' ) );
		}
		$state->set( 'activated_at', gmdate( 'c' ) );

		$plugin->get( Scheduler::class )->ensure_scheduled();

		// Rewrite rules for sitemaps and the IndexNow key file are added on init;
		// flush on the next request, once every module registered its rules.
		update_option( 'searchpilot_flush_rewrite', 1, true );

		if ( $redirect && ! $state->get( 'wizard_completed' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			set_transient( 'searchpilot_activation_redirect', get_current_user_id(), 60 );
		}

		/**
		 * Fires after SearchPilot was activated on a site.
		 */
		do_action( 'searchpilot_activated' );
	}

	/**
	 * Drops in-memory copies of per-site data (call after switch_to_blog()).
	 */
	public static function reset_site_caches() {
		$plugin = \SearchPilot\Plugin::instance();
		$plugin->get( Settings::class )->flush();
		$plugin->get( State::class )->refresh();
		$plugin->get( Compat\ConflictDetector::class )->reset();
	}

	/**
	 * IndexNow key: 32 lower-case hex characters (allowed: a-z, A-Z, 0-9, '-', 8–128 chars).
	 *
	 * @return string
	 */
	public static function generate_indexnow_key() {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * New site in a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site Site.
	 */
	public static function on_new_site( $site ) {
		if ( ! is_plugin_active_for_network( SEARCHPILOT_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::activate_site( false );
		restore_current_blog();
		self::reset_site_caches();
	}
}

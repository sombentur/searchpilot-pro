<?php
/**
 * Versioned database migrations.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Database;

use SearchPilot\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs dbDelta for the current schema plus ordered data migrations whenever
 * the stored schema version is older than SEARCHPILOT_DB_VERSION. Runs per
 * site, so multisite networks upgrade lazily site by site.
 */
final class Migrator {

	const OPTION = 'searchpilot_db_version';

	/**
	 * Data migrations: version => callable. Each runs once, in order, after
	 * the tables were brought up to date. Add new versions at the end.
	 *
	 * @return array<string,callable>
	 */
	public static function migrations() {
		return array(
			'1.0.0' => array( __CLASS__, 'migrate_100' ),
			'1.0.1' => array( __CLASS__, 'migrate_101' ),
			'1.1.0' => array( __CLASS__, 'migrate_110' ),
		);
	}

	/**
	 * Upgrades when needed. Cheap when current: one autoloaded option read.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::OPTION, '' );
		if ( SEARCHPILOT_DB_VERSION === $installed ) {
			return;
		}
		self::upgrade( (string) $installed );
	}

	/**
	 * Runs the upgrade under a short lock.
	 *
	 * @param string $from Installed version ('' when fresh).
	 */
	public static function upgrade( $from ) {
		$lock = 'searchpilot_migrating';
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			Schema::install();
			foreach ( self::migrations() as $version => $callback ) {
				if ( '' !== $from && version_compare( $from, $version, '>=' ) ) {
					continue;
				}
				call_user_func( $callback, $from );
			}
			update_option( self::OPTION, SEARCHPILOT_DB_VERSION, true );
			if ( '' !== $from ) {
				Logger::instance()->info(
					'core',
					'Database upgraded.',
					array(
						'from' => $from,
						'to'   => SEARCHPILOT_DB_VERSION,
					)
				);
			}
		} catch ( \Throwable $e ) {
			Logger::emergency_log( 'Migration failed: ' . $e->getMessage() );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Initial install data.
	 *
	 * @param string $from Previous version.
	 */
	public static function migrate_100( $from ) {
		// Seed state so first-run screens know when the plugin was installed.
		$state = get_option( 'searchpilot_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		if ( empty( $state['installed_at'] ) ) {
			$state['installed_at'] = gmdate( 'c' );
		}
		update_option( 'searchpilot_state', $state, false );
	}

	/**
	 * 1.0.1: tag/category archives that the SEO plugin sets to noindex by default
	 * (e.g. Rank Math "Tag archives: noindex") are intentional. Re-check the URL
	 * registry so their false "Page is set to noindex" issues close.
	 *
	 * @param string $from Previous version ('' on a fresh install).
	 */
	public static function migrate_101( $from ) {
		if ( '' === $from ) {
			return; // Fresh installs compute indexability correctly from the start.
		}
		// Runs in the background on the next cron tick; modules may not be booted yet.
		update_option( 'searchpilot_pending_reconcile', 'upgrade_101', false );

		// Heading order and several H1s do not affect rankings: open reports become opportunities.
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET severity = 'opportunity', severity_rank = %d WHERE type IN ('heading_structure','multiple_h1') AND status IN ('open','pending_approval')", \SearchPilot\Issues\IssueRegistry::rank( 'opportunity' ) ) );
	}

	/**
	 * 1.1.0: link_suggestions.kind (inline|related) is added by dbDelta in Schema::install().
	 * Existing installs also clean the URL registry of objects deleted while 1.0.x was running
	 * (deleted posts, trashed/unpublished content, deleted tags and users); the 5-minute job
	 * does the work in small slices.
	 *
	 * @param string $from Previous version ('' on a fresh install).
	 */
	public static function migrate_110( $from ) {
		if ( '' === $from ) {
			return;
		}
		if ( method_exists( '\SearchPilot\Indexer\RegistrySync', 'request_cleanup' ) ) {
			\SearchPilot\Indexer\RegistrySync::request_cleanup();
		}
		// Issues found by 1.0.x lack the data 1.1's fixers decide on (served title/description
		// source, the fix kind, the heading outline). Mark every analysis stale once; the analysis
		// backlog re-analyses posts in small background batches and rewrites that data.
		global $wpdb;
		$content = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$content} SET analyzed_at = NULL WHERE analyzed_at IS NOT NULL" );
	}
}

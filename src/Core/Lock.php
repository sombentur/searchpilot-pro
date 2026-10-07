<?php
/**
 * Cross-process job locks.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic locks stored as rows in the options table, written with direct SQL.
 *
 * add_option() is not atomic: when WordPress's in-process "notoptions" cache
 * says an option is missing it upserts, so a long-running cron process could
 * "acquire" a lock another process holds. Here acquisition is an
 * INSERT IGNORE (exactly one process gets rows_affected = 1), an expired
 * lock is taken over with a conditional UPDATE, and every read goes to the
 * database. The value is "<expiry>:<owner token>" so release() only deletes
 * a lock this process owns.
 */
final class Lock {

	/**
	 * Tokens of locks held by this process: name => token.
	 *
	 * @var array<string,string>
	 */
	private static $tokens = array();

	/**
	 * Acquires a lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered stale.
	 * @return bool
	 */
	public static function acquire( $name, $ttl = 300 ) {
		global $wpdb;
		$option = self::option( $name );
		$token  = wp_generate_password( 12, false );
		$value  = ( time() + max( 1, (int) $ttl ) ) . ':' . $token;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $value ) );
		if ( 1 !== (int) $inserted ) {
			// Take over only if the current holder's lock has expired.
			$current = self::read( $option );
			if ( null === $current || $current['expires'] >= time() ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $option, $current['raw'] ) );
			if ( 1 !== (int) $updated ) {
				return false;
			}
		}
		self::$tokens[ $name ] = $token;
		self::forget_cache( $option );
		return true;
	}

	/**
	 * Releases a lock held by this process.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( $name ) {
		global $wpdb;
		$option = self::option( $name );
		if ( isset( self::$tokens[ $name ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $option, '%:' . $wpdb->esc_like( self::$tokens[ $name ] ) ) );
			unset( self::$tokens[ $name ] );
		} else {
			// Marker locks (set by one request, cleared by another), e.g. cancel flags.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		}
		self::forget_cache( $option );
	}

	/**
	 * Whether a lock is currently held (by any process).
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function held( $name ) {
		$current = self::read( self::option( $name ) );
		return null !== $current && $current['expires'] > time();
	}

	/**
	 * Runs a callback under a lock. Returns null when the lock is busy.
	 *
	 * @param string   $name     Lock name.
	 * @param int      $ttl      TTL.
	 * @param callable $callback Callback.
	 * @return mixed|null
	 */
	public static function run( $name, $ttl, callable $callback ) {
		if ( ! self::acquire( $name, $ttl ) ) {
			return null;
		}
		try {
			return $callback();
		} finally {
			self::release( $name );
		}
	}

	/**
	 * Reads a lock row straight from the database.
	 *
	 * @param string $option Option name.
	 * @return array{expires:int,raw:string}|null
	 */
	private static function read( $option ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( null === $raw ) {
			return null;
		}
		$raw = (string) $raw;
		return array(
			'expires' => (int) strtok( $raw, ':' ),
			'raw'     => $raw,
		);
	}

	/**
	 * Keeps WordPress's option caches from serving a stale answer later.
	 *
	 * @param string $option Option name.
	 */
	private static function forget_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Option name.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( $name ) {
		return 'searchpilot_lock_' . sanitize_key( $name );
	}
}

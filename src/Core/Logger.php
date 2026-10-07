<?php
/**
 * Structured, redacting system logger.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the searchpilot_logs table. Secrets are redacted from messages
 * and context before anything is stored, so logs are always safe to show.
 */
class Logger {

	const LEVELS = array(
		'debug'    => 0,
		'info'     => 1,
		'notice'   => 2,
		'warning'  => 3,
		'error'    => 4,
		'critical' => 5,
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Shared instance.
	 *
	 * @return Logger
	 */
	public static function instance() {
		return \SearchPilot\Plugin::instance()->get( self::class );
	}

	/**
	 * Last-resort logging when the database or plugin is unavailable.
	 *
	 * @param string $message Message.
	 */
	public static function emergency_log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[SearchPilot] ' . self::redact_string( (string) $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Writes a log entry.
	 *
	 * @param string $level   Level (see LEVELS).
	 * @param string $channel Channel, e.g. queue, google, bing, indexnow, sitemap, audit.
	 * @param string $message Message.
	 * @param array  $context Context (redacted, JSON encoded).
	 */
	public function log( $level, $channel, $message, array $context = array() ) {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'info';
		}
		$min = (string) $this->settings->get( 'general.log_level', 'info' );
		if ( self::LEVELS[ $level ] < ( self::LEVELS[ $min ] ?? 1 ) ) {
			return;
		}
		if ( $this->writing ) {
			return;
		}
		$this->writing = true;
		global $wpdb;
		$row = array(
			'level'      => $level,
			'channel'    => substr( sanitize_key( $channel ), 0, 32 ),
			'message'    => self::redact_string( (string) $message ),
			'context'    => empty( $context ) ? null : wp_json_encode( self::redact( $context ) ),
			'created_at' => current_time( 'mysql', true ),
		);
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Tables::logs(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			self::emergency_log( $channel . ': ' . $message );
		}
		$this->writing = false;

		/**
		 * Fires after a log entry was written.
		 *
		 * @param string $level   Level.
		 * @param string $channel Channel.
		 * @param string $message Redacted message.
		 */
		do_action( 'searchpilot_logged', $level, $channel, $row['message'] );
	}

	/**
	 * Debug entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function debug( $channel, $message, array $context = array() ) {
		$this->log( 'debug', $channel, $message, $context );
	}

	/**
	 * Info entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( $channel, $message, array $context = array() ) {
		$this->log( 'info', $channel, $message, $context );
	}

	/**
	 * Warning entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function warning( $channel, $message, array $context = array() ) {
		$this->log( 'warning', $channel, $message, $context );
	}

	/**
	 * Error entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function error( $channel, $message, array $context = array() ) {
		$this->log( 'error', $channel, $message, $context );
	}

	/**
	 * Critical entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function critical( $channel, $message, array $context = array() ) {
		$this->log( 'critical', $channel, $message, $context );
	}

	/**
	 * Recursively redacts sensitive keys and token-like strings.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$out = array();
			foreach ( (array) $value as $k => $v ) {
				if ( is_string( $k ) && preg_match( '/(api_?key|apikey|token|secret|password|passwd|authorization|private|credential|cookie|signature|assertion|refresh|client_secret|bearer)/i', $k ) ) {
					$out[ $k ] = '[redacted]';
					continue;
				}
				$out[ $k ] = self::redact( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::redact_string( $value );
		}
		return $value;
	}

	/**
	 * Redacts secrets that may appear inside free text or URLs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact_string( $text ) {
		$patterns = array(
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[redacted private key]',
			'/([?&](?:apikey|api_key|key|access_token|refresh_token|client_secret|code)=)[^&\s"\']+/i' => '$1[redacted]',
			'/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i'                                     => '$1[redacted]',
			'/\bya29\.[A-Za-z0-9\-_\.]+/'                                               => '[redacted token]',
			'/\b1\/\/[A-Za-z0-9\-_]{20,}/'                                              => '[redacted token]',
			'/("(?:access_token|refresh_token|id_token|client_secret|private_key|apikey|api_key)"\s*:\s*")[^"]*"/i' => '$1[redacted]"',
		);
		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	}

	/**
	 * Removes old entries.
	 */
	public function prune() {
		global $wpdb;
		$days  = (int) $this->settings->get( 'general.log_retention_days', 30 );
		$table = Tables::logs();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );

		// Hard cap so a noisy site can never grow the table without bound.
		$cap = (int) apply_filters( 'searchpilot_log_row_cap', 100000 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $cap ) );
		if ( $cutoff ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) );
		}
	}
}

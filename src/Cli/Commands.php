<?php
/**
 * WP-CLI commands.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Cli;

use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manage SearchPilot Pro from the command line.
 *
 * Commands run through the same REST endpoints as the admin screens, so
 * behaviour and permission checks are identical. Run them as an
 * administrator (`--user=<admin>`); the first administrator is used when
 * no user is given.
 *
 * ## EXAMPLES
 *
 *     wp searchpilot status
 *     wp searchpilot queue process
 *     wp searchpilot sitemap regenerate
 *     wp searchpilot audit run
 *     wp searchpilot run daily
 */
class Commands {

	/**
	 * Shows the plugin, connection, queue and issue status.
	 *
	 * [--format=<format>]
	 * : table or json. Default table.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function status( $args, $assoc ) {
		$system = $this->request( 'GET', '/system' );
		$issues = $this->request( 'GET', '/issues/counts' );
		$rows   = array(
			array(
				'key'   => 'version',
				'value' => $system['version'] ?? '',
			),
			array(
				'key'   => 'environment',
				'value' => ( $system['environment']['environment'] ?? '' ) . ( ! empty( $system['environment']['submissions_allowed'] ) ? '' : ' (submissions held: ' . ( $system['environment']['held_reason'] ?? '' ) . ')' ),
			),
			array(
				'key'   => 'scheduler',
				'value' => ( $system['scheduler']['backend'] ?? '' ) . ( ! empty( $system['scheduler']['healthy'] ) ? ', healthy' : ', NOT running recently' ),
			),
			array(
				'key'   => 'missing tables',
				'value' => implode( ', ', (array) ( $system['tables']['missing'] ?? array() ) ) ?: 'none',
			),
			array(
				'key'   => 'issues',
				'value' => sprintf( 'critical %d, high %d, medium %d, opportunities %d', $issues['critical'] ?? 0, $issues['high'] ?? 0, $issues['medium'] ?? 0, $issues['opportunity'] ?? 0 ),
			),
		);
		$engines = $this->request( 'GET', '/engines', array(), false );
		if ( is_array( $engines ) ) {
			$status = $engines['status'] ?? $engines;
			foreach ( array( 'google', 'bing', 'indexnow' ) as $engine ) {
				if ( isset( $status[ $engine ] ) ) {
					$state  = ! empty( $status[ $engine ]['connected'] ) || ! empty( $status[ $engine ]['enabled'] ) ? 'on' : 'off';
					$rows[] = array(
						'key'   => $engine,
						'value' => $state . ( ! empty( $status[ $engine ]['error'] ) ? ' — ' . $status[ $engine ]['error'] : '' ),
					);
				}
			}
		}
		$queue = $this->request( 'GET', '/queue/stats', array(), false );
		if ( is_array( $queue ) ) {
			$rows[] = array(
				'key'   => 'queue',
				'value' => sprintf( 'pending %d, held %d, failed (24h) %d, done (24h) %d', $queue['pending'] ?? 0, $queue['held'] ?? 0, $queue['failed_24h'] ?? 0, $queue['done_24h'] ?? 0 ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Runs a recurring background job now.
	 *
	 * <job>
	 * : every_5min, hourly, daily or weekly.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function run( $args, $assoc ) {
		$result = $this->request( 'POST', '/system/run', array( 'job' => $args[0] ?? '' ) );
		\WP_CLI::success( sprintf( '%s finished in %d ms.', $result['job'] ?? $args[0], $result['duration_ms'] ?? 0 ) );
	}

	/**
	 * Manages the search-engine submission queue.
	 *
	 * <action>
	 * : process, release, retry-failed or stats.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function queue( $args, $assoc ) {
		$action = $args[0] ?? 'stats';
		$map    = array(
			'process'      => array( 'POST', '/queue/process' ),
			'release'      => array( 'POST', '/queue/release' ),
			'retry-failed' => array( 'POST', '/queue/retry-failed' ),
			'stats'        => array( 'GET', '/queue/stats' ),
		);
		if ( ! isset( $map[ $action ] ) ) {
			\WP_CLI::error( 'Unknown action. Use process, release, retry-failed or stats.' );
		}
		$this->print_result( $this->request( $map[ $action ][0], $map[ $action ][1] ) );
	}

	/**
	 * Regenerates or submits sitemaps.
	 *
	 * <action>
	 * : regenerate, submit or health.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function sitemap( $args, $assoc ) {
		$action = $args[0] ?? 'health';
		$map    = array(
			'regenerate' => '/sitemaps/regenerate',
			'submit'     => '/sitemaps/submit',
			'health'     => '/sitemaps/health',
		);
		if ( ! isset( $map[ $action ] ) ) {
			\WP_CLI::error( 'Unknown action. Use regenerate, submit or health.' );
		}
		$this->print_result( $this->request( 'POST', $map[ $action ] ) );
	}

	/**
	 * Runs the site audit.
	 *
	 * <action>
	 * : run.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function audit( $args, $assoc ) {
		$this->print_result( $this->request( 'POST', '/audit/run' ) );
	}

	/**
	 * Rebuilds the URL registry or runs a crawl batch.
	 *
	 * <action>
	 * : rebuild or crawl.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function registry( $args, $assoc ) {
		$action = $args[0] ?? 'crawl';
		$route  = 'rebuild' === $action ? '/indexing/rebuild' : '/indexing/crawl';
		$this->print_result( $this->request( 'POST', $route ) );
	}

	/**
	 * Syncs Search Console / Bing performance data now.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function analytics( $args, $assoc ) {
		$this->print_result( $this->request( 'POST', '/performance/sync' ) );
	}

	/**
	 * Imports SEO data from another plugin.
	 *
	 * <source>
	 * : yoast, rank_math, aioseo, seopress or tsf.
	 *
	 * [--overwrite]
	 * : Overwrite existing SearchPilot values.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function import( $args, $assoc ) {
		$this->print_result(
			$this->request(
				'POST',
				'/import/start',
				array(
					'source'    => $args[0] ?? '',
					'overwrite' => ! empty( $assoc['overwrite'] ),
					'include'   => array( 'posts', 'terms', 'settings' ),
				)
			)
		);
		\WP_CLI::log( 'The import runs in the background; check progress with: wp searchpilot status' );
	}

	/**
	 * Reads or changes a setting.
	 *
	 * <action>
	 * : get or set.
	 *
	 * <key>
	 * : Dot key, e.g. general.mode.
	 *
	 * [<value>]
	 * : New value for set (JSON is decoded when valid).
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function setting( $args, $assoc ) {
		list( $action, $key ) = array_pad( $args, 2, '' );
		$settings            = \SearchPilot\Plugin::instance()->get( Settings::class );
		if ( 'get' === $action ) {
			\WP_CLI::log( wp_json_encode( $settings->get( $key ) ) );
			return;
		}
		if ( 'set' === $action ) {
			$raw     = $args[2] ?? '';
			$decoded = json_decode( $raw, true );
			$value   = null === $decoded && 'null' !== $raw ? $raw : $decoded;
			$result  = $this->request( 'POST', '/settings', array( 'settings' => array( $key => $value ) ) );
			if ( ! empty( $result['errors'][ $key ] ) ) {
				\WP_CLI::error( $result['errors'][ $key ] );
			}
			\WP_CLI::success( $key . ' = ' . wp_json_encode( $settings->get( $key ) ) );
			return;
		}
		\WP_CLI::error( 'Use: wp searchpilot setting get|set <key> [<value>]' );
	}

	/**
	 * Undoes a change from the activity log.
	 *
	 * <id>
	 * : Activity entry id.
	 *
	 * [--force]
	 * : Undo even when the value was edited again since.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function undo( $args, $assoc ) {
		$this->request( 'POST', '/activity/' . absint( $args[0] ?? 0 ) . '/undo', array( 'force' => ! empty( $assoc['force'] ) ) );
		\WP_CLI::success( 'Change undone.' );
	}

	/**
	 * Runs a REST route internally.
	 *
	 * @param string $method   Method.
	 * @param string $route    Route under searchpilot/v1.
	 * @param array  $params   Params.
	 * @param bool   $required Fail on errors (false returns null).
	 * @return array|null
	 */
	private function request( $method, $route, array $params = array(), $required = true ) {
		$this->ensure_user();
		$request = new \WP_REST_Request( $method, '/searchpilot/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			if ( ! $required ) {
				return null;
			}
			$error = $response->as_error();
			\WP_CLI::error( $error->get_error_message() );
		}
		$data = $response->get_data();
		return is_array( $data ) ? $data : array( 'result' => $data );
	}

	/**
	 * Uses the first administrator when WP-CLI runs without --user.
	 */
	private function ensure_user() {
		if ( get_current_user_id() ) {
			return;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( $admins ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	/**
	 * Prints a result array.
	 *
	 * @param array $result Result.
	 */
	private function print_result( $result ) {
		\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}

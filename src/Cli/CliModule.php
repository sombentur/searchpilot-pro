<?php
/**
 * WP-CLI integration.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Cli;

use SearchPilot\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp searchpilot …` when running under WP-CLI.
 */
class CliModule implements Module {

	/**
	 * Hooks.
	 */
	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'searchpilot', Commands::class );
		}
	}
}

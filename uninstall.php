<?php
/**
 * Uninstall entry point.
 *
 * @package SearchPilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'SEARCHPILOT_DIR' ) ) {
	define( 'SEARCHPILOT_DIR', plugin_dir_path( __FILE__ ) );
}

require_once SEARCHPILOT_DIR . 'src/Autoloader.php';
\SearchPilot\Autoloader::register( 'SearchPilot\\', SEARCHPILOT_DIR . 'src/' );

\SearchPilot\Core\Uninstaller::uninstall();

<?php
/**
 * Plugin Name:       SearchPilot Pro
 * Description:       Autonomous technical SEO, indexing and search-performance suite. Notifies Google, Bing and IndexNow through their official APIs, keeps XML sitemaps and structured data healthy, monitors indexing, finds content opportunities from Search Console data and builds safe internal links — inside Google and Bing webmaster guidelines.
 * Version:           1.1.9
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            sombentur
 * Author URI:        https://github.com/sombentur
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       searchpilot-pro
 * Domain Path:       /languages
 * Network:           false
 *
 * @package SearchPilot
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEARCHPILOT_VERSION' ) ) {
	// Another copy of the plugin is already loaded (for example a second folder); do nothing.
	return;
}

define( 'SEARCHPILOT_VERSION', '1.1.9' );
define( 'SEARCHPILOT_DB_VERSION', '1.1.0' );
define( 'SEARCHPILOT_FILE', __FILE__ );
define( 'SEARCHPILOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEARCHPILOT_URL', plugin_dir_url( __FILE__ ) );
define( 'SEARCHPILOT_BASENAME', plugin_basename( __FILE__ ) );
define( 'SEARCHPILOT_MIN_PHP', '7.4' );
define( 'SEARCHPILOT_MIN_WP', '6.2' );

require_once SEARCHPILOT_DIR . 'src/Autoloader.php';
\SearchPilot\Autoloader::register( 'SearchPilot\\', SEARCHPILOT_DIR . 'src/' );

require_once SEARCHPILOT_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( '\SearchPilot\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SearchPilot\Core\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\SearchPilot\Plugin', 'boot' ), 5 );

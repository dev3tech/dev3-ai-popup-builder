<?php
/**
 * Plugin Name:       DEV3 AI Popup & Lead Capture Builder for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-popup-builder
 * Description:       Popups, slide-ins, floating bars and inline forms with smart triggers, targeting, A/B tests, lead storage and DEV3 AI copywriting.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-popup-builder
 * Domain Path:       /languages
 *
 * @package Dev3\AiPopupBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3PU_VERSION', '1.0.0' );
define( 'DEV3PU_FILE', __FILE__ );
define( 'DEV3PU_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3PU_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3PU_SLUG', 'dev3-ai-popup-builder' );
define( 'DEV3PU_NAME', 'DEV3 AI Popup & Lead Capture Builder for WordPress' );
define( 'DEV3PU_MIN_PHP', '7.4' );
define( 'DEV3PU_MIN_WP', '6.0' );
define( 'DEV3PU_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3PU_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiPopupBuilder\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3PU_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiPopupBuilder\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiPopupBuilder\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiPopupBuilder\Plugin', 'instance' ) );

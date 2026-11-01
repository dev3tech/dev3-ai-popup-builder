<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder;

use Dev3\AiPopupBuilder\Ai\Prompt_Library;
use Dev3\AiPopupBuilder\Core3\Core\Assets;
use Dev3\AiPopupBuilder\Core3\Core\Privacy;
use Dev3\AiPopupBuilder\Core3\Core\Schema;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3pu_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether bundles were registered (Assets::register re-adds the boot script on every call).
	 *
	 * @var bool
	 */
	private static $assets_registered = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3PU_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( has_filter( 'dev3pu_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			return;
		}
		add_filter( 'dev3pu_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3pu_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3pu_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Text domain (on init, so WP 6.7 does not flag early translation loading).
	 */
	public static function textdomain() {
		load_plugin_textdomain( 'dev3-ai-popup-builder', false, dirname( plugin_basename( DEV3PU_FILE ) ) . '/languages' );
	}

	/**
	 * Registers bundles once.
	 */
	public static function register_assets() {
		if ( self::$assets_registered ) {
			return;
		}
		self::$assets_registered = true;
		Assets::register( array( 'admin', 'block' ) );
	}

	/**
	 * Daily housekeeping.
	 */
	public static function daily() {
		Schema::prune_usage();
		Leads::prune( (int) Settings_Store::get( 'retention_days', 0 ) );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', array( __CLASS__, 'textdomain' ), 1 );
		self::register_schema();
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3PU_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 2 );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woo_compat' ) );
		Privacy::init();
		Leads::register_privacy();
		Frontend\Frontend::register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * HPOS + Cart/Checkout blocks: the plugin never reads or writes orders, and cart targeting
	 * works with the Store API (the cookie is refreshed on every total calculation).
	 */
	public static function declare_woo_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3PU_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3PU_FILE, true );
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Campaigns_Controller() )->register_routes();
		( new Rest\Leads_Controller() )->register_routes();
		( new Rest\Integrations_Controller() )->register_routes();
		( new Rest\Ai_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}

<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Admin;

use Dev3\AiPopupBuilder\Core3\Core\Assets;
use Dev3\AiPopupBuilder\Core3\Core\Capabilities;
use Dev3\AiPopupBuilder\Data\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3PU_FILE ), array( $this, 'links' ) );
		add_action( 'admin_post_dev3pu_export', array( $this, 'export' ) );
	}

	/**
	 * CSV download of leads (filters from the query string).
	 */
	public function export() {
		if ( ! current_user_can( Capabilities::manage() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dev3-ai-popup-builder' ), 403 );
		}
		check_admin_referer( 'dev3pu_export' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$filters = array(
			'campaign_id' => isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0,
			'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'      => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'from'        => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'          => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
		);
		// phpcs:enable
		$csv = Leads::to_csv( Leads::csv_rows( $filters ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leads-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells are guarded against formula injection.
		exit;
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3PU_NAME, __( 'DEV3 AI Popups', 'dev3-ai-popup-builder' ), Capabilities::manage(), DEV3PU_SLUG, array( $this, 'render' ), 'dashicons-format-chat', 58 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3pu-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3PU_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3pu-admin-root" class="dev3pu-root dev3pu-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			wp_enqueue_media();
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3PU_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-popup-builder' ) . '</a>' );
		return $links;
	}
}

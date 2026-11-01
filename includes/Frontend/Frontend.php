<?php
/**
 * Front end: which campaigns can run on this page, the small JSON config, assets only when needed,
 * shortcodes, blocks, inline auto-insert and the WooCommerce cart cookie.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Frontend;

use Dev3\AiPopupBuilder\Campaign\Page_Rules;
use Dev3\AiPopupBuilder\Core3\Core\Assets;
use Dev3\AiPopupBuilder\Core3\Core\Capabilities;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Campaigns;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend.
 */
class Frontend {

	const HANDLE      = 'dev3pu-front';
	const CART_COOKIE = 'dev3pu_cart';

	/**
	 * Overlay campaigns for this request (null until computed).
	 *
	 * @var array|null
	 */
	private static $overlays = null;

	/**
	 * Whether an inline form is on the page.
	 *
	 * @var bool
	 */
	private static $has_inline = false;

	/**
	 * Inline campaigns already auto-inserted (once per request each).
	 *
	 * @var array
	 */
	private static $inserted = array();

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_assets' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_blocks' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'async_style' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'auto_insert' ), 20 );
		add_shortcode( 'dev3pu_form', array( __CLASS__, 'shortcode_form' ) );
		add_shortcode( 'dev3pu_button', array( __CLASS__, 'shortcode_button' ) );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'cart_cookie' ) );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'cart_cookie_empty' ) );
	}

	/**
	 * Script + style (built by wp-scripts: build/frontend.js has no WordPress dependencies).
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$asset = is_readable( DEV3PU_PATH . 'build/frontend.asset.php' ) ? include DEV3PU_PATH . 'build/frontend.asset.php' : array(
			'dependencies' => array(),
			'version'      => DEV3PU_VERSION,
		);
		wp_register_script(
			self::HANDLE,
			DEV3PU_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style( self::HANDLE, DEV3PU_URL . 'build/frontend.css', array(), $asset['version'] );
	}

	/**
	 * Blocks: "Lead form" (inline campaign) and "Popup button" (click trigger).
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'dev3pu/form',
			array(
				'api_version'     => 2,
				'editor_script'   => Assets::handle( 'block' ),
				'editor_style'    => self::HANDLE,
				'render_callback' => array( __CLASS__, 'render_form_block' ),
				'attributes'      => array(
					'campaignId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'className'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
		register_block_type(
			'dev3pu/button',
			array(
				'api_version'     => 2,
				'editor_script'   => Assets::handle( 'block' ),
				'editor_style'    => self::HANDLE,
				'render_callback' => array( __CLASS__, 'render_button_block' ),
				'attributes'      => array(
					'campaignId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'label'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'className'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array( 'html' => false ),
			)
		);
	}

	/**
	 * Whether the current visitor is excluded by role.
	 *
	 * @return bool
	 */
	public static function hidden_for_user() {
		$roles = (array) Settings_Store::get( 'hide_roles', array() );
		if ( ! $roles || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Campaign id forced by "Preview on site" (managers only).
	 *
	 * @return int
	 */
	public static function preview_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview switch for managers.
		$id = isset( $_GET['dev3pu_preview'] ) ? absint( wp_unslash( $_GET['dev3pu_preview'] ) ) : 0;
		return $id && current_user_can( Capabilities::manage() ) ? $id : 0;
	}

	/**
	 * Overlay campaigns whose page rules match this request.
	 *
	 * @return array
	 */
	public static function overlays() {
		if ( null !== self::$overlays ) {
			return self::$overlays;
		}
		self::$overlays = array();
		$preview        = self::preview_id();
		if ( $preview ) {
			$campaign = Campaigns::get( $preview );
			if ( $campaign && 'inline' !== $campaign['type'] ) {
				self::$overlays = array( $campaign );
			}
			return self::$overlays;
		}
		if ( is_admin() || is_feed() || is_embed() || self::hidden_for_user() ) {
			return self::$overlays;
		}
		$ctx = Page_Rules::context();
		foreach ( Campaigns::active() as $campaign ) {
			if ( 'inline' === $campaign['type'] ) {
				continue;
			}
			if ( Page_Rules::matches( $campaign['config']['targeting']['pages'], $ctx ) ) {
				self::$overlays[] = $campaign;
			}
		}
		/**
		 * Filters the overlay campaigns that may run on this page.
		 *
		 * @param array $overlays Campaigns.
		 * @param array $ctx      Page context.
		 */
		self::$overlays = (array) apply_filters( 'dev3pu_page_campaigns', self::$overlays, $ctx );
		return self::$overlays;
	}

	/**
	 * Variants that are served: all when testing, the declared winner, or the first.
	 *
	 * @param array $campaign Campaign.
	 * @return array
	 */
	public static function serving_variants( array $campaign ) {
		$config = $campaign['config'];
		if ( $config['ab']['enabled'] ) {
			$live = array_values(
				array_filter(
					$config['variants'],
					static function ( $v ) {
						return $v['weight'] > 0;
					}
				)
			);
			return $live ? $live : array( $config['variants'][0] );
		}
		if ( '' !== $config['ab']['winner'] ) {
			foreach ( $config['variants'] as $variant ) {
				if ( $variant['id'] === $config['ab']['winner'] ) {
					return array( $variant );
				}
			}
		}
		return array( $config['variants'][0] );
	}

	/**
	 * Public JSON for one campaign: what the page needs, nothing more (no integrations, no codes).
	 *
	 * @param array $campaign Campaign.
	 * @param bool  $preview  Preview mode.
	 * @return array
	 */
	public static function public_campaign( array $campaign, $preview = false ) {
		$config   = $campaign['config'];
		$variants = array();
		foreach ( self::serving_variants( $campaign ) as $variant ) {
			$variants[] = array(
				'id'      => $variant['id'],
				'weight'  => $variant['weight'],
				'content' => $variant['content'],
				'design'  => $variant['design'],
			);
		}
		$t = $config['targeting'];
		return array(
			'id'          => $campaign['id'],
			'type'        => $campaign['type'],
			'priority'    => $campaign['priority'],
			'variants'    => $variants,
			'form'        => array(
				'mode'        => $config['form']['mode'],
				'fields'      => $config['form']['fields'],
				'consent'     => $config['form']['consent'],
				'cta_url'     => $config['form']['cta_url'],
				'cta_new_tab' => $config['form']['cta_new_tab'],
			),
			'triggers'    => $config['triggers'],
			'exit_mobile' => $config['exit_mobile'],
			'targeting'   => array(
				'devices'   => $t['devices'],
				'referrer'  => $t['referrer'],
				'utm'       => $t['utm'],
				'login'     => $t['login'],
				'visitor'   => $t['visitor'],
				'min_pages' => $t['min_pages'],
				'cart'      => $t['cart'],
			),
			'frequency'   => $config['frequency'],
			'preview'     => (bool) $preview,
		);
	}

	/**
	 * Boot data for the script.
	 *
	 * @return array
	 */
	public static function boot() {
		$preview   = self::preview_id();
		$campaigns = array();
		foreach ( self::overlays() as $campaign ) {
			$campaigns[] = self::public_campaign( $campaign, $preview === $campaign['id'] );
		}
		return array(
			'rest'      => esc_url_raw( rest_url( 'dev3pu/v1/public/' ) ),
			'nonce'     => $preview ? wp_create_nonce( 'wp_rest' ) : '',
			'campaigns' => $campaigns,
			'settings'  => array(
				'mobileSafe' => (bool) Settings_Store::get( 'mobile_safe', true ),
				'maxPerView' => (int) Settings_Store::get( 'max_per_view', 1 ),
				'minGap'     => (int) Settings_Store::get( 'min_gap', 30 ),
				'respectDnt' => (bool) Settings_Store::get( 'respect_dnt', false ),
				'tracking'   => (bool) Settings_Store::get( 'tracking', true ) && ! $preview,
			),
			'i18n'      => array(
				'close'    => __( 'Close', 'dev3-ai-popup-builder' ),
				'sending'  => __( 'Sending…', 'dev3-ai-popup-builder' ),
				'required' => __( 'This field is required.', 'dev3-ai-popup-builder' ),
				'email'    => __( 'Enter a valid email address.', 'dev3-ai-popup-builder' ),
				'consent'  => __( 'Please tick the box to continue.', 'dev3-ai-popup-builder' ),
				'error'    => __( 'Something went wrong. Please try again.', 'dev3-ai-popup-builder' ),
				'copy'     => __( 'Copy code', 'dev3-ai-popup-builder' ),
				'copied'   => __( 'Copied!', 'dev3-ai-popup-builder' ),
				'submit'   => __( 'Submit', 'dev3-ai-popup-builder' ),
				'choose'   => __( 'Choose…', 'dev3-ai-popup-builder' ),
			),
		);
	}

	/**
	 * Enqueues only when something can show on this page. Overlay-only pages load the CSS
	 * without blocking render (overlays appear later anyway).
	 */
	public static function enqueue() {
		$overlays = self::overlays();
		$inline   = self::page_has_inline();
		if ( ! $overlays && ! $inline ) {
			return;
		}
		self::load_assets( ! $inline );
	}

	/**
	 * Enqueues script + style with boot data (once).
	 *
	 * @param bool $async_css Load CSS without blocking render.
	 */
	public static function load_assets( $async_css = false ) {
		self::register_assets();
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			wp_add_inline_script( self::HANDLE, 'window.dev3puFront=' . wp_json_encode( self::boot() ) . ';', 'before' );
			wp_enqueue_script( self::HANDLE );
		}
		if ( ! wp_style_is( self::HANDLE, 'enqueued' ) ) {
			if ( $async_css ) {
				wp_style_add_data( self::HANDLE, 'dev3pu_async', true );
			}
			wp_enqueue_style( self::HANDLE );
		}
	}

	/**
	 * Non-blocking stylesheet tag for overlay-only pages.
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function async_style( $tag, $handle ) {
		if ( self::HANDLE !== $handle || ! wp_styles()->get_data( $handle, 'dev3pu_async' ) || self::$has_inline ) {
			return $tag;
		}
		return str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag );
	}

	/**
	 * Early detection of inline forms in the main content (so their CSS is in the head).
	 *
	 * @return bool
	 */
	public static function page_has_inline() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post || ! isset( $post->post_content ) ) {
			return false;
		}
		if ( has_shortcode( $post->post_content, 'dev3pu_form' ) || ( function_exists( 'has_block' ) && has_block( 'dev3pu/form', $post ) ) ) {
			return true;
		}
		foreach ( Campaigns::active() as $campaign ) {
			if ( 'inline' === $campaign['type'] && 'manual' !== $campaign['config']['inline']['placement'] && in_array( $post->post_type, $campaign['config']['inline']['post_types'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inline markup for a campaign id ('' when it cannot show).
	 *
	 * @param int $id Campaign id.
	 * @return string
	 */
	public static function inline_html( $id ) {
		$campaign = Campaigns::get( $id );
		$preview  = $campaign && current_user_can( Capabilities::manage() ) && ( self::preview_id() === $campaign['id'] || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
		if ( ! $campaign || 'inline' !== $campaign['type'] || ( 'active' !== $campaign['status'] && ! $preview ) ) {
			return '';
		}
		if ( self::hidden_for_user() && ! $preview ) {
			return '';
		}
		self::$has_inline = true;
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			self::load_assets( false );
		}
		return Renderer::inline( $campaign );
	}

	/**
	 * [dev3pu_form id="12"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_form( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'dev3pu_form' );
		return self::inline_html( absint( $atts['id'] ) );
	}

	/**
	 * [dev3pu_button id="12" label="Get the guide"]: opens an overlay campaign on click.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => '',
				'class' => '',
			),
			$atts,
			'dev3pu_button'
		);
		$id   = absint( $atts['id'] );
		if ( ! $id ) {
			return '';
		}
		$label = '' !== $atts['label'] ? $atts['label'] : __( 'Open', 'dev3-ai-popup-builder' );
		$class = trim( 'dev3pu-open dev3pu-open-' . $id . ' wp-element-button ' . sanitize_html_class( $atts['class'] ) );
		return '<button type="button" class="' . esc_attr( $class ) . '" data-dev3pu-open="' . esc_attr( (string) $id ) . '" aria-haspopup="dialog">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Form block.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_form_block( $attrs ) {
		$html = self::inline_html( isset( $attrs['campaignId'] ) ? (int) $attrs['campaignId'] : 0 );
		if ( '' === $html ) {
			return '';
		}
		$class = 'wp-block-dev3pu-form' . ( ! empty( $attrs['className'] ) ? ' ' . sanitize_html_class( $attrs['className'] ) : '' ) . ( ! empty( $attrs['align'] ) ? ' align' . sanitize_html_class( $attrs['align'] ) : '' );
		return '<div class="' . esc_attr( $class ) . '">' . $html . '</div>';
	}

	/**
	 * Button block.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_button_block( $attrs ) {
		$button = self::shortcode_button(
			array(
				'id'    => isset( $attrs['campaignId'] ) ? (int) $attrs['campaignId'] : 0,
				'label' => isset( $attrs['label'] ) ? (string) $attrs['label'] : '',
				'class' => '',
			)
		);
		if ( '' === $button ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && \WP_Block_Supports::$block_to_render ? get_block_wrapper_attributes( array( 'class' => 'wp-block-button' ) ) : 'class="wp-block-dev3pu-button wp-block-button"';
		return '<div ' . $wrapper . '>' . $button . '</div>';
	}

	/**
	 * Auto-inserts inline campaigns before/after the main post content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function auto_insert( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() || self::hidden_for_user() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || (int) get_queried_object_id() !== (int) $post->ID ) {
			return $content;
		}
		$ctx = null;
		foreach ( Campaigns::active() as $campaign ) {
			$inline = $campaign['config']['inline'];
			if ( 'inline' !== $campaign['type'] || 'manual' === $inline['placement'] || isset( self::$inserted[ $campaign['id'] ] ) || ! in_array( $post->post_type, $inline['post_types'], true ) ) {
				continue;
			}
			$ctx = $ctx ? $ctx : Page_Rules::context();
			if ( ! Page_Rules::matches( $campaign['config']['targeting']['pages'], $ctx ) ) {
				continue;
			}
			self::$inserted[ $campaign['id'] ] = true;
			$html                              = self::inline_html( $campaign['id'] );
			$content                           = 'before' === $inline['placement'] ? $html . $content : $content . $html;
		}
		return $content;
	}

	/**
	 * Cache-safe cart value for targeting: "subtotal|items" in a cookie, refreshed on every total calculation
	 * (classic cart, mini cart and the Store API used by the Cart/Checkout blocks).
	 *
	 * @param object $cart WC_Cart.
	 */
	public static function cart_cookie( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_subtotal' ) ) {
			return;
		}
		$value = self::cart_value( (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax(), (int) $cart->get_cart_contents_count() );
		self::set_cart_cookie( $value );
	}

	/**
	 * Emptied cart.
	 */
	public static function cart_cookie_empty() {
		self::set_cart_cookie( self::cart_value( 0, 0 ) );
	}

	/**
	 * Cookie value.
	 *
	 * @param float $subtotal Subtotal incl. tax.
	 * @param int   $items    Items.
	 * @return string
	 */
	public static function cart_value( $subtotal, $items ) {
		return number_format( max( 0, (float) $subtotal ), 2, '.', '' ) . '|' . max( 0, (int) $items );
	}

	/**
	 * Sets the cookie when it changed.
	 *
	 * @param string $value Value.
	 */
	private static function set_cart_cookie( $value ) {
		$current = isset( $_COOKIE[ self::CART_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CART_COOKIE ] ) ) : '';
		if ( $current === $value || headers_sent() ) {
			return;
		}
		$_COOKIE[ self::CART_COOKIE ] = $value;
		setcookie( self::CART_COOKIE, $value, 0, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
	}
}

<?php
/**
 * Front end: page eligibility, public payload, inline renderer, shortcodes, assets, cart cookie.
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- tests.

require_once __DIR__ . '/Dev3pu_TestCase.php';

use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Frontend\Frontend;
use Dev3\AiPopupBuilder\Frontend\Renderer;

class FrontendTest extends Dev3pu_TestCase {

	private function reset_frontend() {
		$ref = new ReflectionClass( Frontend::class );
		foreach ( array( 'overlays' => null, 'has_inline' => false, 'inserted' => array() ) as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $value );
		}
		wp_dequeue_script( Frontend::HANDLE );
		wp_dequeue_style( Frontend::HANDLE );
	}

	public function set_up() {
		parent::set_up();
		$this->reset_frontend();
	}

	public function test_overlays_follow_page_rules_status_and_roles() {
		$all    = $this->make_campaign( array( 'status' => 'active', 'title' => 'All' ) );
		$posts  = $this->make_campaign( array( 'status' => 'active', 'title' => 'Posts', 'config' => array( 'targeting' => array( 'pages' => array( 'mode' => 'include', 'rules' => array( array( 'type' => 'post_type', 'value' => 'post' ) ) ) ) ) ) );
		$this->make_campaign( array( 'status' => 'draft', 'title' => 'Draft' ) );
		$this->make_campaign( array( 'status' => 'active', 'type' => 'inline', 'title' => 'Inline' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( array( $all['id'] ), wp_list_pluck( Frontend::overlays(), 'id' ) );

		$this->reset_frontend();
		$this->go_to( get_permalink( self::factory()->post->create() ) );
		$this->assertEqualsCanonicalizing( array( $all['id'], $posts['id'] ), wp_list_pluck( Frontend::overlays(), 'id' ) );

		$this->reset_frontend();
		Settings_Store::update( array( 'hide_roles' => array( 'administrator' ) ) );
		Settings_Store::flush();
		$this->admin();
		$this->assertSame( array(), Frontend::overlays() );
	}

	public function test_preview_param_forces_a_draft_for_managers_only() {
		$draft            = $this->make_campaign( array( 'status' => 'draft' ) );
		$_GET['dev3pu_preview'] = (string) $draft['id'];
		$this->assertSame( array(), Frontend::overlays(), 'Visitors cannot preview' );
		$this->reset_frontend();
		$this->admin();
		$this->assertSame( array( $draft['id'] ), wp_list_pluck( Frontend::overlays(), 'id' ) );
		$boot = Frontend::boot();
		$this->assertTrue( $boot['campaigns'][0]['preview'] );
		$this->assertFalse( $boot['settings']['tracking'] );
		$this->assertNotSame( '', $boot['nonce'] );
		unset( $_GET['dev3pu_preview'] );
	}

	public function test_public_payload_hides_integrations_and_codes() {
		$c   = $this->make_campaign(
			array(
				'status' => 'active',
				'config' => array(
					'offer'        => array( 'type' => 'code', 'code' => 'SECRET10' ),
					'integrations' => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://hooks.example.com/x' ) ),
					'variants'     => array( array( 'id' => 'a', 'weight' => 70 ), array( 'id' => 'b', 'weight' => 30 ) ),
					'ab'           => array( 'enabled' => true ),
				),
			)
		);
		$pub  = Frontend::public_campaign( $c );
		$json = wp_json_encode( $pub );
		$this->assertStringNotContainsString( 'SECRET10', $json );
		$this->assertStringNotContainsString( 'hooks.example.com', $json );
		$this->assertArrayNotHasKey( 'pages', $pub['targeting'] );
		$this->assertCount( 2, $pub['variants'] );

		$c['config']['ab'] = array( 'enabled' => false, 'winner' => 'b' );
		$this->assertSame( array( 'b' ), wp_list_pluck( Frontend::serving_variants( $c ), 'id' ) );
		$c['config']['ab'] = array( 'enabled' => false, 'winner' => '' );
		$this->assertSame( array( 'a' ), wp_list_pluck( Frontend::serving_variants( $c ), 'id' ) );
	}

	public function test_assets_load_only_where_a_campaign_can_show() {
		$this->go_to( home_url( '/' ) );
		Frontend::enqueue();
		$this->assertFalse( wp_script_is( Frontend::HANDLE, 'enqueued' ), 'Nothing to show, nothing loaded' );
		$this->reset_frontend();
		$this->make_campaign( array( 'status' => 'active' ) );
		Frontend::register_assets();
		Frontend::enqueue();
		$this->assertTrue( wp_script_is( Frontend::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Frontend::HANDLE, 'enqueued' ) );
		$tag = Frontend::async_style( "<link rel='stylesheet' id='dev3pu-front-css' href='x.css' media='all' />", Frontend::HANDLE );
		$this->assertStringContainsString( "media='print'", $tag, 'Overlay-only pages load CSS without blocking render' );
		$inline = wp_scripts()->get_data( Frontend::HANDLE, 'before' );
		$this->assertStringContainsString( 'window.dev3puFront=', implode( '', (array) $inline ) );
	}

	public function test_inline_renderer_markup_is_accessible() {
		$c    = $this->make_campaign(
			array(
				'status' => 'active',
				'type'   => 'inline',
				'config' => array(
					'form' => array(
						'mode'    => 'form',
						'fields'  => array(
							array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
							array( 'key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => array( 'S', 'M' ) ),
						),
						'consent' => array( 'enabled' => true, 'label' => 'I agree to the <a href="/privacy">policy</a>', 'required' => true ),
					),
				),
			)
		);
		$html = Renderer::inline( $c );
		$this->assertStringContainsString( 'data-dev3pu-inline', $html );
		$this->assertStringContainsString( 'dev3pu-w--inline', $html );
		$this->assertMatchesRegularExpression( '/<label class="dev3pu-label" for="(dev3pu-[^"]+-email)">Email/', $html );
		preg_match( '/for="(dev3pu-[^"]+-email)"/', $html, $m );
		$this->assertStringContainsString( 'id="' . $m[1] . '"', $html );
		$this->assertStringContainsString( 'aria-describedby="' . $m[1] . '-e"', $html );
		$this->assertStringContainsString( 'name="dev3pu_hp" tabindex="-1"', $html );
		$this->assertStringContainsString( '<a href="/privacy">policy</a>', $html );
		$this->assertStringContainsString( '<option value="M">M</option>', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringNotContainsString( '<script', $html, 'No script without an A/B test' );
	}

	public function test_inline_ab_prints_all_variants_and_a_pre_paint_picker() {
		$c    = $this->make_campaign( array( 'status' => 'active', 'type' => 'inline', 'config' => array( 'variants' => array( array( 'id' => 'a' ), array( 'id' => 'b' ) ), 'ab' => array( 'enabled' => true ) ) ) );
		$html = Renderer::inline( $c );
		$this->assertSame( 2, substr_count( $html, '" data-dev3pu-inline data-dev3pu-id=' ) );
		$this->assertSame( 1, preg_match_all( '/data-dev3pu-variant="b" style="[^"]*" hidden>/', $html ) );
		$this->assertSame( 0, preg_match_all( '/data-dev3pu-variant="a" style="[^"]*" hidden>/', $html ) );
		$this->assertStringContainsString( 'var id=' . $c['id'] . ',', $html );
		$this->assertStringContainsString( '"dev3pu_ab_"+id', $html );
	}

	public function test_shortcodes_and_auto_insert() {
		$inactive = $this->make_campaign( array( 'type' => 'inline' ) );
		$this->assertSame( '', do_shortcode( '[dev3pu_form id="' . $inactive['id'] . '"]' ) );
		$active = $this->make_campaign( array( 'status' => 'active', 'type' => 'inline', 'config' => array( 'inline' => array( 'placement' => 'after', 'post_types' => array( 'post' ) ) ) ) );
		$this->assertStringContainsString( 'data-dev3pu-id="' . $active['id'] . '"', do_shortcode( '[dev3pu_form id="' . $active['id'] . '"]' ) );
		$btn = do_shortcode( '[dev3pu_button id="12" label="Get the guide"]' );
		$this->assertStringContainsString( 'dev3pu-open-12', $btn );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $btn );
		$this->assertStringContainsString( 'Get the guide', $btn );
		$this->assertStringContainsString( 'dev3pu-open-5', Frontend::render_button_block( array( 'campaignId' => 5, 'label' => 'Go' ) ) );

		$post = self::factory()->post->create( array( 'post_content' => 'Hello world' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( Frontend::page_has_inline() );
		global $wp_query;
		$wp_query->in_the_loop = true;
		setup_postdata( get_post( $post ) );
		$out = Frontend::auto_insert( 'Hello world' );
		$this->assertStringStartsWith( 'Hello world', $out );
		$this->assertStringContainsString( 'data-dev3pu-id="' . $active['id'] . '"', $out );
		$this->assertSame( 'Hello world', Frontend::auto_insert( 'Hello world' ), 'Only once per request' );
		$wp_query->in_the_loop = false;
	}

	public function test_cart_cookie_value() {
		$this->assertSame( '42.50|3', Frontend::cart_value( 42.5, 3 ) );
		$this->assertSame( '0.00|0', Frontend::cart_value( -5, -1 ) );
	}

	public function test_blocks_are_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3pu/form' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3pu/button' ) );
		$this->assertSame( '', Frontend::render_form_block( array( 'campaignId' => 0 ) ) );
	}
}

<?php
/**
 * Campaign model sanitiser, page rules, templates, repository.
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- tests.

require_once __DIR__ . '/Dev3pu_TestCase.php';

use Dev3\AiPopupBuilder\Campaign\Model;
use Dev3\AiPopupBuilder\Campaign\Page_Rules;
use Dev3\AiPopupBuilder\Campaign\Templates;
use Dev3\AiPopupBuilder\Data\Campaigns;

class ModelTest extends Dev3pu_TestCase {

	public function test_defaults_fill_missing_keys() {
		$c = Model::sanitize_config( array() );
		$this->assertCount( 1, $c['variants'] );
		$this->assertSame( 'a', $c['variants'][0]['id'] );
		$this->assertSame( 'email', $c['form']['fields'][0]['type'] );
		$this->assertSame( 'time', $c['triggers'][0]['type'] );
		$this->assertSame( array( 'desktop', 'tablet', 'mobile' ), $c['targeting']['devices'] );
		$this->assertFalse( $c['ab']['enabled'] );
	}

	public function test_variants_are_limited_and_ids_unique() {
		$raw = array_fill( 0, 6, array( 'id' => 'a', 'content' => array( 'headline' => '<b>Hi</b> there' ) ) );
		$c   = Model::sanitize_config( array( 'variants' => $raw, 'ab' => array( 'enabled' => true, 'winner' => 'z' ) ) );
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), wp_list_pluck( $c['variants'], 'id' ) );
		$this->assertSame( 'Hi there', $c['variants'][0]['content']['headline'] );
		$this->assertTrue( $c['ab']['enabled'] );
		$this->assertSame( '', $c['ab']['winner'] );
		$single = Model::sanitize_config( array( 'ab' => array( 'enabled' => true ) ) );
		$this->assertFalse( $single['ab']['enabled'], 'A test needs two variants' );
	}

	public function test_variant_content_and_design_are_cleaned() {
		$v = Model::sanitize_variant(
			array(
				'content' => array(
					'body'      => 'Hello <a href="https://x.test" onclick="evil()">link</a><script>x</script>',
					'image_url' => 'javascript:alert(1)',
				),
				'design'  => array(
					'bg'      => 'red',
					'accent'  => '#123456',
					'radius'  => 99,
					'overlay' => -5,
					'layout'  => 'weird',
				),
			),
			'a'
		);
		$this->assertStringNotContainsString( 'onclick', $v['content']['body'] );
		$this->assertStringNotContainsString( '<script', $v['content']['body'] );
		$this->assertStringContainsString( '<a href="https://x.test">', $v['content']['body'] );
		$this->assertSame( '', $v['content']['image_url'] );
		$this->assertSame( '#ffffff', $v['design']['bg'] );
		$this->assertSame( '#123456', $v['design']['accent'] );
		$this->assertSame( 32, $v['design']['radius'] );
		$this->assertSame( 0, $v['design']['overlay'] );
		$this->assertSame( 'stacked', $v['design']['layout'] );
	}

	public function test_form_always_has_an_email_field_in_form_mode() {
		$c = Model::sanitize_config(
			array(
				'form' => array(
					'mode'   => 'form',
					'fields' => array(
						array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
						array( 'key' => 'name', 'type' => 'select', 'label' => 'Pick', 'options' => "One\nTwo\n\n" ),
						array( 'type' => 'password' ),
					),
				),
			)
		);
		$this->assertSame( 'email', $c['form']['fields'][0]['type'] );
		$keys = wp_list_pluck( $c['form']['fields'], 'key' );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
		$this->assertSame( array( 'One', 'Two' ), $c['form']['fields'][2]['options'] );
		$this->assertSame( 'text', $c['form']['fields'][3]['type'], 'Unknown types become text' );
		$cta = Model::sanitize_config( array( 'form' => array( 'mode' => 'cta', 'fields' => array(), 'cta_url' => 'https://shop.test/sale' ) ) );
		$this->assertSame( 'https://shop.test/sale', $cta['form']['cta_url'] );
	}

	public function test_triggers_offer_and_frequency_are_clamped() {
		$c = Model::sanitize_config(
			array(
				'triggers'  => array(
					array( 'type' => 'time', 'value' => 9999 ),
					array( 'type' => 'scroll', 'value' => 0 ),
					array( 'type' => 'click', 'value' => '.open<script>' ),
					array( 'type' => 'teleport', 'value' => 1 ),
				),
				'offer'     => array( 'type' => 'coupon', 'discount_type' => 'percent', 'amount' => 150, 'code' => 'SAVE 10!', 'prefix' => 'ab-c' ),
				'frequency' => array( 'mode' => 'days', 'days' => 0, 'close_days' => -3 ),
			)
		);
		$this->assertCount( 3, $c['triggers'] );
		$this->assertSame( 600, $c['triggers'][0]['value'] );
		$this->assertSame( 50, $c['triggers'][1]['value'] );
		$this->assertSame( '.openscript>', $c['triggers'][2]['value'], 'Only selector characters survive' );
		$this->assertSame( 100.0, (float) $c['offer']['amount'] );
		$this->assertSame( 'SAVE10', $c['offer']['code'] );
		$this->assertSame( 'ABC', $c['offer']['prefix'] );
		$this->assertSame( 1, $c['frequency']['days'] );
		$this->assertSame( 0, $c['frequency']['close_days'] );
		$this->assertSame( array(), Model::sanitize_config( array(), 'inline' )['triggers'], 'Inline forms have no triggers' );
	}

	public function test_targeting_drops_invalid_rules() {
		$t = Model::sanitize_targeting(
			array(
				'pages'    => array(
					'mode'  => 'include',
					'rules' => array(
						array( 'type' => 'post_type', 'value' => 'Post' ),
						array( 'type' => 'ids', 'value' => '3, x, 7' ),
						array( 'type' => 'term', 'value' => 'category:abc' ),
						array( 'type' => 'evil', 'value' => '1' ),
						array( 'type' => 'url', 'value' => '' ),
					),
				),
				'devices'  => array( 'mobile', 'fridge' ),
				'utm'      => array( array( 'key' => 'utm_source', 'value' => 'news' ), array( 'key' => 'gclid', 'value' => 'x' ) ),
				'referrer' => array( 'mode' => 'search' ),
				'login'    => 'maybe',
			)
		);
		$this->assertSame( array( array( 'type' => 'post_type', 'value' => 'post' ), array( 'type' => 'ids', 'value' => '3,7' ) ), $t['pages']['rules'] );
		$this->assertSame( array( 'mobile' ), $t['devices'] );
		$this->assertCount( 1, $t['utm'] );
		$this->assertSame( 'equals', $t['utm'][0]['op'] );
		$this->assertSame( 'search', $t['referrer']['mode'] );
		$this->assertSame( 'any', $t['login'] );
		$this->assertSame( 'all', Model::sanitize_targeting( array( 'pages' => array( 'mode' => 'include', 'rules' => array() ) ) )['pages']['mode'], 'No rules means every page' );
	}

	public function test_page_rules() {
		$ctx = array( 'front' => false, 'post_type' => 'post', 'id' => 12, 'terms' => array( 'category:3' ), 'path' => '/blog/hello/?utm_source=x', 'search' => false, 'not_found' => false, 'woo' => array( 'product' ) );
		$this->assertTrue( Page_Rules::matches( array( 'mode' => 'all', 'rules' => array() ), $ctx ) );
		$inc = function ( $type, $value ) use ( $ctx ) {
			return Page_Rules::matches( array( 'mode' => 'include', 'rules' => array( array( 'type' => $type, 'value' => $value ) ) ), $ctx );
		};
		$this->assertTrue( $inc( 'post_type', 'post' ) );
		$this->assertFalse( $inc( 'post_type', 'page' ) );
		$this->assertTrue( $inc( 'ids', '5,12' ) );
		$this->assertTrue( $inc( 'term', 'category:3' ) );
		$this->assertTrue( $inc( 'url', 'hello' ) );
		$this->assertTrue( $inc( 'url', '/blog/*' ) );
		$this->assertFalse( $inc( 'url', '/shop/*' ) );
		$this->assertTrue( $inc( 'woo', 'product' ) );
		$this->assertFalse( $inc( 'front_page', '' ) );
		$this->assertFalse( Page_Rules::matches( array( 'mode' => 'exclude', 'rules' => array( array( 'type' => 'post_type', 'value' => 'post' ) ) ), $ctx ) );
		$this->assertTrue( Page_Rules::matches( array( 'mode' => 'exclude', 'rules' => array( array( 'type' => 'search', 'value' => '' ) ) ), $ctx ) );
	}

	public function test_page_context_from_a_real_query() {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$this->go_to( get_permalink( $post ) );
		$ctx = Page_Rules::context();
		$this->assertSame( 'post', $ctx['post_type'] );
		$this->assertSame( $post, $ctx['id'] );
		$this->assertContains( 'category:' . $cat, $ctx['terms'] );
	}

	public function test_templates_are_valid_drafts() {
		$all = Templates::all();
		$this->assertGreaterThanOrEqual( 12, count( $all ) );
		$types = array();
		foreach ( $all as $tpl ) {
			$this->assertSame( 'draft', $tpl['campaign']['status'] );
			$this->assertSame( $tpl['campaign'], Model::sanitize_campaign( $tpl['campaign'] ), "Template {$tpl['id']} is already clean" );
			$this->assertNotSame( '', $tpl['campaign']['config']['variants'][0]['content']['headline'] );
			$types[ $tpl['type'] ] = true;
		}
		$this->assertCount( 5, $types, 'Every format has a template' );
		$this->assertStringContainsString( 'assets/img/templates/envelope.svg', Templates::campaign( 'newsletter' )['config']['variants'][0]['content']['image_url'] );
		$this->assertNull( Templates::campaign( 'nope' ) );
	}

	public function test_repository_crud_duplicate_and_schedule() {
		$c = $this->make_campaign( array( 'title' => 'Spring', 'status' => 'active' ) );
		$this->assertSame( 'Spring', $c['title'] );
		$this->assertSame( 'active', $c['status'] );
		$this->assertCount( 1, Campaigns::active() );

		Campaigns::update( $c['id'], array( 'starts_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + DAY_IN_SECONDS ) ) );
		$this->assertCount( 0, Campaigns::active(), 'Not started yet' );
		Campaigns::update( $c['id'], array( 'starts_at' => '', 'ends_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 60 ) ) );
		$this->assertCount( 0, Campaigns::active(), 'Already ended' );
		Campaigns::update( $c['id'], array( 'ends_at' => '' ) );
		$this->assertCount( 1, Campaigns::active() );

		$copy = Campaigns::get( Campaigns::duplicate( $c['id'] ) );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertSame( 'Spring (copy)', $copy['title'] );
		$this->assertTrue( Campaigns::delete( $c['id'] ) );
		$this->assertNull( Campaigns::get( $c['id'] ) );
		$this->assertInstanceOf( WP_Error::class, Campaigns::update( 999999, array() ) );
	}
}

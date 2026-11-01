<?php
/**
 * REST permissions and behaviour (admin + public).
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- tests.

require_once __DIR__ . '/Dev3pu_TestCase.php';

use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Data\Stats;
use Dev3\AiPopupBuilder\Secrets;

class RestTest extends Dev3pu_TestCase {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	public function test_admin_routes_need_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( array( 'GET', 'campaigns' ), array( 'GET', 'leads' ), array( 'GET', 'integrations' ), array( 'GET', 'dashboard' ), array( 'POST', 'ai/copy' ), array( 'GET', 'templates' ) ) as $r ) {
			$this->assertContains( $this->rest( $r[0], $r[1] )->get_status(), array( 401, 403 ), "{$r[0]} {$r[1]}" );
		}
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'campaigns', array(), false )->get_status(), 'No nonce' );
		$this->assertSame( 200, $this->rest( 'GET', 'campaigns' )->get_status() );
	}

	public function test_create_from_template_is_always_a_draft_and_crud_works() {
		$this->admin();
		$res = $this->rest( 'POST', 'campaigns', array( 'template' => 'discount' ) );
		$this->assertSame( 201, $res->get_status() );
		$c = $res->get_data();
		$this->assertSame( 'draft', $c['status'] );
		$this->assertSame( 'code', $c['config']['offer']['type'] );

		$forced = $this->rest( 'POST', 'campaigns', array( 'campaign' => array( 'title' => 'X', 'status' => 'active' ) ) )->get_data();
		$this->assertSame( 'draft', $forced['status'], 'AI/template drafts never go live on creation' );

		$c['config']['variants'][0]['content']['headline'] = 'Changed';
		$put = $this->rest( 'PUT', 'campaigns/' . $c['id'], array( 'title' => 'Renamed', 'config' => $c['config'] ) )->get_data();
		$this->assertSame( 'Renamed', $put['title'] );
		$this->assertSame( 'Changed', $put['config']['variants'][0]['content']['headline'] );
		$this->assertStringContainsString( 'dev3pu_preview=' . $c['id'], $put['preview_url'] );

		$this->assertSame( 'active', $this->rest( 'POST', 'campaigns/' . $c['id'] . '/status', array( 'status' => 'active' ) )->get_data()['status'] );
		$this->assertSame( 400, $this->rest( 'POST', 'campaigns/' . $c['id'] . '/status', array( 'status' => 'live!' ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', 'campaigns/' . $c['id'] . '/duplicate' )->get_status() );
		$list = $this->rest( 'GET', 'campaigns' )->get_data();
		$this->assertCount( 3, $list );
		$this->assertArrayHasKey( 'rate', $list[0] );
		$this->assertSame( 200, $this->rest( 'DELETE', 'campaigns/' . $c['id'] )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'campaigns/' . $c['id'] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'campaigns', array( 'template' => 'nope' ) )->get_status() );
	}

	public function test_declare_winner_stops_the_test() {
		$this->admin();
		$c = $this->make_campaign( array( 'config' => array( 'variants' => array( array( 'id' => 'a' ), array( 'id' => 'b' ) ), 'ab' => array( 'enabled' => true ) ) ) );
		$res = $this->rest( 'POST', 'campaigns/' . $c['id'] . '/winner', array( 'variant' => 'b' ) )->get_data();
		$this->assertFalse( $res['config']['ab']['enabled'] );
		$this->assertSame( 'b', $res['config']['ab']['winner'] );
		$this->assertCount( 2, $res['config']['variants'], 'History kept' );
		$this->assertSame( 400, $this->rest( 'POST', 'campaigns/' . $c['id'] . '/winner', array( 'variant' => 'z' ) )->get_status() );
		$results = $this->rest( 'GET', 'campaigns/' . $c['id'] . '/results' )->get_data();
		$this->assertCount( 2, $results['variants'] );
	}

	public function test_dashboard_and_targeting_options() {
		$this->admin();
		$c = $this->make_campaign( array( 'status' => 'active' ) );
		Stats::set( $c['id'], 'a', current_time( 'Y-m-d' ), 40, 4 );
		$d = $this->rest( 'GET', 'dashboard' )->get_data();
		$this->assertSame( 40, $d['kpis']['current']['views'] );
		$this->assertSame( 1, $d['active'] );
		$this->assertCount( 30, $d['series'] );
		$o = $this->rest( 'GET', 'targeting/options' )->get_data();
		$this->assertContains( 'post', wp_list_pluck( $o['post_types'], 'value' ) );
		$this->assertContains( 'administrator', wp_list_pluck( $o['roles'], 'value' ) );
	}

	public function test_public_track_counts_and_validates() {
		$c   = $this->make_campaign( array( 'status' => 'active' ) );
		$res = $this->rest( 'POST', 'public/track', array( 'campaign_id' => $c['id'], 'variant' => 'a', 'event' => 'view' ), false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, Stats::by_variant( $c['id'] )['a']['views'] );
		$this->assertSame( 404, $this->rest( 'POST', 'public/track', array( 'campaign_id' => $c['id'], 'variant' => 'c', 'event' => 'view' ), false )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'public/track', array( 'campaign_id' => $c['id'], 'event' => 'hack' ), false )->get_status() );
		// A click is only a conversion for CTA campaigns.
		$this->rest( 'POST', 'public/track', array( 'campaign_id' => $c['id'], 'variant' => 'a', 'event' => 'click' ), false );
		$this->assertSame( 0, Stats::by_variant( $c['id'] )['a']['conversions'] );
		$cta = $this->make_campaign( array( 'status' => 'active', 'config' => array( 'form' => array( 'mode' => 'cta', 'cta_url' => 'https://x.test' ) ) ) );
		$this->rest( 'POST', 'public/track', array( 'campaign_id' => $cta['id'], 'variant' => 'a', 'event' => 'click' ), false );
		$this->assertSame( 1, Stats::by_variant( $cta['id'] )['a']['conversions'] );
		$draft = $this->make_campaign();
		$this->assertSame( 404, $this->rest( 'POST', 'public/track', array( 'campaign_id' => $draft['id'], 'variant' => 'a', 'event' => 'view' ), false )->get_status() );
	}

	public function test_public_submit_rejects_cross_origin_and_returns_field_errors() {
		$c = $this->make_campaign( array( 'status' => 'active' ) );
		$this->assertSame( 403, $this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'] ), false, array( 'Origin' => 'https://evil.example' ) )->get_status() );
		$bad = $this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'], 'fields' => array( 'email' => 'nope' ), 'elapsed' => 9000 ), false );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertArrayHasKey( 'email', $bad->get_data()['fields'] );
		$ok = $this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'], 'variant' => 'a', 'fields' => array( 'email' => 'ok@example.com' ), 'elapsed' => 9000 ), false, array( 'Origin' => home_url() ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'Thanks for subscribing!', $ok->get_data()['title'] );
		$this->assertArrayNotHasKey( 'integrations', $ok->get_data() );
		$this->assertSame( 1, Leads::query( array() )['total'] );
	}

	public function test_public_submit_is_rate_limited() {
		$c    = $this->make_campaign( array( 'status' => 'active' ) );
		$last = null;
		for ( $i = 0; $i < 11; $i++ ) {
			$last = $this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'], 'fields' => array( 'email' => "r{$i}@example.com" ), 'elapsed' => 9000 ), false );
		}
		$this->assertSame( 429, $last->get_status() );
	}

	public function test_leads_routes_and_export_link() {
		$this->admin();
		$c = $this->make_campaign( array( 'status' => 'active' ) );
		$this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'], 'fields' => array( 'email' => 'l1@example.com' ), 'elapsed' => 9000 ), false );
		$this->rest( 'POST', 'public/submit', array( 'campaign_id' => $c['id'], 'fields' => array( 'email' => 'l2@example.com' ), 'elapsed' => 9000 ), false );
		$list = $this->rest( 'GET', 'leads', array( 'search' => 'l1@' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 'Test', $list['items'][0]['campaign'] );
		$this->assertStringContainsString( 'action=dev3pu_export', $list['export_url'] );
		$this->assertStringNotContainsString( '&amp;', $list['export_url'] );
		$id = $list['items'][0]['id'];
		$this->assertSame( 'l1@example.com', $this->rest( 'GET', 'leads/' . $id )->get_data()['email'] );
		$this->assertSame( 200, $this->rest( 'POST', 'leads/' . $id . '/resync' )->get_status() );
		$this->assertSame( 1, $this->rest( 'DELETE', 'leads/' . $id )->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'GET', 'leads/' . $id )->get_status() );
	}

	public function test_integration_keys_are_encrypted_and_masked() {
		$this->admin();
		$res = $this->rest( 'POST', 'integrations', array( 'mailchimp_key' => 'abcdef-us21' ) )->get_data();
		$this->assertTrue( $res['mailchimp_key']['set'] );
		$this->assertStringEndsWith( 'us21', $res['mailchimp_key']['hint'] );
		$this->assertStringNotContainsString( 'abcdef', wp_json_encode( $res ) );
		$this->assertStringNotContainsString( 'abcdef', wp_json_encode( get_option( 'dev3pu_secrets' ) ) );
		$this->assertSame( 'abcdef-us21', Secrets::get( 'mailchimp_key' ) );
		$this->responses = array( array( 200, array( 'lists' => array( array( 'id' => 'L1', 'name' => 'Newsletter' ) ) ) ) );
		$lists           = $this->rest( 'GET', 'integrations/mailchimp/lists' )->get_data();
		$this->assertSame( array( array( 'value' => 'L1', 'label' => 'Newsletter' ) ), $lists );
		$this->assertStringContainsString( 'us21.api.mailchimp.com', $this->requests[0]['url'] );
		$test = $this->rest( 'POST', 'integrations/brevo/test' );
		$this->assertSame( 400, $test->get_status(), 'No Brevo key yet' );
	}
}

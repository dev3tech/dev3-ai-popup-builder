<?php
/**
 * Lead capture, integrations (HTTP mocked), coupons, stats, CSV and privacy.
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- tests.

require_once __DIR__ . '/Dev3pu_TestCase.php';

use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Data\Stats;
use Dev3\AiPopupBuilder\Integrations\Services;
use Dev3\AiPopupBuilder\Leads\Capture;
use Dev3\AiPopupBuilder\Leads\Coupons;
use Dev3\AiPopupBuilder\Secrets;

class LeadsTest extends Dev3pu_TestCase {

	private function form_campaign( array $config = array() ) {
		return $this->make_campaign(
			array(
				'status' => 'active',
				'config' => array_merge(
					array(
						'form' => array(
							'mode'    => 'form',
							'fields'  => array(
								array( 'key' => 'name', 'type' => 'text', 'label' => 'First name', 'required' => false ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
								array( 'key' => 'topic', 'type' => 'select', 'label' => 'Topic', 'required' => true, 'options' => array( 'Shoes', 'Bags' ) ),
							),
							'consent' => array( 'enabled' => true, 'label' => 'I agree to <a href="/privacy">emails</a>', 'required' => true ),
						),
					),
					$config
				),
			)
		);
	}

	private function submission( $campaign, array $over = array() ) {
		return array_merge(
			array(
				'campaign_id' => $campaign['id'],
				'variant'     => 'a',
				'fields'      => array( 'name' => 'Ana Lopez', 'email' => 'Ana@Example.com', 'topic' => 'Bags' ),
				'consent'     => true,
				'hp'          => '',
				'elapsed'     => 5000,
				'page_url'    => 'https://site.test/blog/?a=1',
				'referrer'    => 'https://www.google.com/',
				'utm'         => array( 'utm_source' => 'news', 'gclid' => 'drop' ),
				'device'      => 'mobile',
			),
			$over
		);
	}

	public function test_validation_errors_per_field() {
		$c   = $this->form_campaign();
		$err = Capture::submit( $this->submission( $c, array( 'fields' => array( 'email' => 'not-an-email', 'topic' => 'Hats' ), 'consent' => false ) ) );
		$this->assertWPError( $err );
		$this->assertSame( 'dev3pu_invalid', $err->get_error_code() );
		$fields = $err->get_error_data()['fields'];
		$this->assertArrayHasKey( 'email', $fields );
		$this->assertArrayHasKey( 'topic', $fields );
		$this->assertArrayHasKey( 'consent', $fields );
		$this->assertSame( 0, Leads::query( array() )['total'] );
	}

	public function test_successful_capture_stores_lead_and_counts_a_conversion() {
		$c   = $this->form_campaign( array( 'offer' => array( 'type' => 'code', 'code' => 'WELCOME10' ) ) );
		$res = Capture::submit( $this->submission( $c ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'WELCOME10', $res['code'] );
		$lead = Leads::query( array() )['items'][0];
		$this->assertSame( 'ana@example.com', $lead['email'] );
		$this->assertSame( 'Ana Lopez', $lead['name'] );
		$this->assertSame( array( 'topic' => 'Bags' ), $lead['fields'] );
		$this->assertTrue( $lead['consent'] );
		$this->assertStringContainsString( 'I agree', $lead['consent_text'] );
		$this->assertSame( array( 'utm_source' => 'news' ), $lead['utm'] );
		$this->assertSame( 'mobile', $lead['device'] );
		$this->assertSame( 'WELCOME10', $lead['coupon'] );
		$this->assertSame( 1, Stats::by_variant( $c['id'] )['a']['conversions'] );

		// Same email again: updated, not duplicated, not double counted.
		Capture::submit( $this->submission( $c, array( 'fields' => array( 'email' => 'ana@example.com', 'topic' => 'Shoes' ) ) ) );
		$this->assertSame( 1, Leads::query( array() )['total'] );
		$this->assertSame( 'Shoes', Leads::query( array() )['items'][0]['fields']['topic'] );
		$this->assertSame( 'Ana Lopez', Leads::query( array() )['items'][0]['name'], 'Earlier name kept' );
		$this->assertSame( 1, Stats::by_variant( $c['id'] )['a']['conversions'] );
	}

	public function test_spam_guards() {
		$c  = $this->form_campaign();
		$hp = Capture::submit( $this->submission( $c, array( 'hp' => 'http://spam.test' ) ) );
		$this->assertTrue( $hp['ok'], 'Bots get a fake success' );
		$this->assertSame( 0, Leads::query( array() )['total'] );
		$fast = Capture::submit( $this->submission( $c, array( 'elapsed' => 300 ) ) );
		$this->assertWPError( $fast );
		$this->assertSame( 'dev3pu_too_fast', $fast->get_error_code() );
	}

	public function test_inactive_or_non_form_campaigns_reject_submissions() {
		$draft = $this->make_campaign();
		$this->assertSame( 'dev3pu_not_found', Capture::submit( $this->submission( $draft ) )->get_error_code() );
		$cta = $this->make_campaign( array( 'status' => 'active', 'config' => array( 'form' => array( 'mode' => 'cta', 'cta_url' => 'https://x.test' ) ) ) );
		$this->assertSame( 'dev3pu_not_found', Capture::submit( $this->submission( $cta ) )->get_error_code() );
	}

	public function test_integrations_push_to_mailchimp_brevo_and_signed_webhook() {
		Secrets::update( array( 'mailchimp_key' => 'abc123-us21', 'brevo_key' => 'xkeysib-1', 'webhook_secret' => 's3cret' ) );
		$c = $this->form_campaign(
			array(
				'integrations' => array(
					'mailchimp' => array( 'enabled' => true, 'list_id' => 'list1', 'tags' => 'popup, spring', 'double' => true ),
					'brevo'     => array( 'enabled' => true, 'list_id' => '7' ),
					'webhook'   => array( 'enabled' => true, 'url' => 'https://example.com/lead' ),
				),
			)
		);
		Capture::submit( $this->submission( $c ) );
		$urls = wp_list_pluck( $this->requests, 'url' );
		$this->assertContains( 'https://us21.api.mailchimp.com/3.0/lists/list1/members/' . md5( 'ana@example.com' ), $urls );
		$this->assertContains( 'https://us21.api.mailchimp.com/3.0/lists/list1/members/' . md5( 'ana@example.com' ) . '/tags', $urls );
		$this->assertContains( 'https://api.brevo.com/v3/contacts', $urls );
		$this->assertContains( 'https://example.com/lead', $urls );
		$mc = $this->requests[0];
		$this->assertSame( 'PUT', $mc['method'] );
		$this->assertSame( 'pending', $mc['body']['status_if_new'] );
		$this->assertSame( 'Ana', $mc['body']['merge_fields']['FNAME'] );
		$brevo = $this->requests[ array_search( 'https://api.brevo.com/v3/contacts', $urls, true ) ];
		$this->assertSame( array( 7 ), $brevo['body']['listIds'] );
		$this->assertSame( 'xkeysib-1', $brevo['headers']['api-key'] );
		$hook = $this->requests[ array_search( 'https://example.com/lead', $urls, true ) ];
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $hook['raw'], 's3cret' ), $hook['headers']['X-DEV3PU-Signature'] );
		$this->assertSame( 'lead.captured', $hook['body']['event'] );
		$this->assertSame( 'ana@example.com', $hook['body']['lead']['email'] );
		$lead = Leads::query( array() )['items'][0];
		$this->assertSame( 'synced', $lead['status'] );
		$this->assertTrue( $lead['sync']['mailchimp']['ok'] );
	}

	public function test_failed_sync_is_visible_and_retryable() {
		Secrets::update( array( 'brevo_key' => 'xkeysib-1' ) );
		$c               = $this->form_campaign( array( 'integrations' => array( 'brevo' => array( 'enabled' => true, 'list_id' => '3' ) ) ) );
		$this->responses = array( array( 400, array( 'message' => 'Invalid list' ) ) );
		$res             = Capture::submit( $this->submission( $c ) );
		$this->assertTrue( $res['ok'], 'The visitor still sees success' );
		$lead = Leads::query( array() )['items'][0];
		$this->assertSame( 'failed', $lead['status'] );
		$this->assertStringContainsString( 'Invalid list', $lead['sync']['brevo']['message'] );
		$this->responses = array( array( 201, array( 'id' => 1 ) ) );
		Services::sync_lead( $lead, $c );
		$this->assertSame( 'synced', Leads::get( $lead['id'] )['status'] );
	}

	public function test_marketing_lists_are_skipped_without_consent() {
		Secrets::update( array( 'mailchimp_key' => 'abc123-us21' ) );
		$c = $this->form_campaign(
			array(
				'form'         => array(
					'mode'    => 'form',
					'fields'  => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ) ),
					'consent' => array( 'enabled' => true, 'label' => 'Yes', 'required' => false ),
				),
				'integrations' => array( 'mailchimp' => array( 'enabled' => true, 'list_id' => 'l1' ) ),
			)
		);
		Capture::submit( $this->submission( $c, array( 'consent' => false, 'fields' => array( 'email' => 'no@example.com' ) ) ) );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( 'Skipped', Leads::query( array() )['items'][0]['sync']['mailchimp']['message'] );
	}

	public function test_webhook_refuses_internal_hosts() {
		$res = Services::webhook( 'http://127.0.0.1:8080/hook', array( 'event' => 'x' ) );
		$this->assertWPError( $res );
		$this->assertSame( 'dev3pu_webhook_url', $res->get_error_code() );
	}

	public function test_unique_woocommerce_coupon_per_lead() {
		if ( ! Coupons::available() ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		$c    = $this->form_campaign( array( 'offer' => array( 'type' => 'coupon', 'discount_type' => 'percent', 'amount' => 15, 'expiry_days' => 3, 'prefix' => 'SAVE' ) ) );
		$res  = Capture::submit( $this->submission( $c ) );
		$code = $res['code'];
		$this->assertMatchesRegularExpression( '/^SAVE-[A-Z0-9]{8}$/', $code );
		$coupon = new WC_Coupon( $code );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 15, $coupon->get_amount() );
		$this->assertSame( 1, $coupon->get_usage_limit() );
		$this->assertSame( array( 'ana@example.com' ), $coupon->get_email_restrictions() );
		$this->assertNotNull( $coupon->get_date_expires() );
		$again = Capture::submit( $this->submission( $c ) );
		$this->assertSame( $code, $again['code'], 'The same lead keeps its coupon' );
	}

	public function test_stats_and_ab_confidence() {
		$c = $this->make_campaign();
		Stats::bump( $c['id'], 'a', 'view' );
		Stats::bump( $c['id'], 'a', 'view' );
		Stats::bump( $c['id'], 'a', 'convert' );
		Stats::bump( $c['id'], 'a', 'close' );
		$this->assertFalse( Stats::bump( $c['id'], 'a', 'teleport' ) );
		$row = Stats::by_variant( $c['id'] )['a'];
		$this->assertSame( array( 'views' => 2, 'conversions' => 1, 'closes' => 1, 'rate' => 50.0 ), $row );

		$this->assertEqualsWithDelta( 0.8427, Stats::erf( 1 ), 0.0001 );
		$this->assertEqualsWithDelta( 98.2, Stats::confidence( 1000, 50, 1000, 75 ), 0.5 );
		$this->assertLessThan( 50, Stats::confidence( 100, 5, 100, 6 ) );
		$this->assertSame( 0.0, Stats::confidence( 0, 0, 10, 1 ) );

		$ab = $this->make_campaign(
			array(
				'config' => array(
					'variants' => array( array( 'id' => 'a', 'weight' => 50 ), array( 'id' => 'b', 'weight' => 50 ) ),
					'ab'       => array( 'enabled' => true ),
				),
			)
		);
		Stats::set( $ab['id'], 'a', current_time( 'Y-m-d' ), 1000, 50 );
		Stats::set( $ab['id'], 'b', current_time( 'Y-m-d' ), 1000, 80 );
		$res = Stats::results( $ab );
		$this->assertSame( 'b', $res['leader'] );
		$this->assertTrue( $res['enough_data'] );
		$this->assertTrue( $res['variants'][1]['significant'] );
		$this->assertEquals( 60.0, $res['variants'][1]['uplift'] );
		$this->assertNull( $res['variants'][0]['confidence'] );
		$this->assertCount( 30, $res['series'] );
		$this->assertSame( 2002, Stats::kpis( 30 )['current']['views'] );
	}

	public function test_csv_export_guards_formulas_and_includes_extra_fields() {
		$c = $this->form_campaign();
		Capture::submit( $this->submission( $c, array( 'fields' => array( 'name' => '=HYPERLINK("x")', 'email' => 'eve@example.com', 'topic' => 'Shoes' ) ) ) );
		$rows = Leads::csv_rows( array() );
		$this->assertContains( 'topic', $rows[0] );
		$name = $rows[1][ array_search( 'name', $rows[0], true ) ];
		$this->assertSame( "'=HYPERLINK(\"x\")", $name );
		$csv = Leads::to_csv( $rows );
		$this->assertStringStartsWith( "\xEF\xBB\xBF" . 'id,created_at,email', $csv );
		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")"', $csv );
		$this->assertCount( 0, array_slice( Leads::csv_rows( array( 'search' => 'nobody' ) ), 1 ) );
	}

	public function test_privacy_export_erase_and_retention() {
		$c = $this->form_campaign();
		Capture::submit( $this->submission( $c ) );
		$export = Leads::export_personal_data( 'ana@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'dev3pu-leads', $export['data'][0]['group_id'] );
		$this->assertArrayHasKey( DEV3PU_SLUG . '-leads', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertTrue( Leads::erase_personal_data( 'ana@example.com' )['items_removed'] );
		$this->assertSame( 0, Leads::query( array() )['total'] );

		Leads::upsert(
			array(
				'campaign_id' => $c['id'], 'variant' => 'a', 'email' => 'old@example.com', 'name' => '', 'phone' => '', 'fields' => array(), 'consent' => false, 'consent_text' => '',
				'page_url' => '', 'referrer' => '', 'utm' => array(), 'device' => '', 'ip_hash' => '', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 0, Leads::prune( 0 ) );
		$this->assertSame( 1, Leads::prune( 30 ) );
	}

	public function test_ip_is_only_stored_hashed_when_enabled() {
		$c   = $this->form_campaign();
		$res = Capture::submit( $this->submission( $c ) );
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		global $wpdb;
		$table = $wpdb->prefix . 'dev3pu_leads';
		$this->assertEmpty( $wpdb->get_var( "SELECT ip_hash FROM {$table} LIMIT 1" ), 'get_var() returns null for an empty string' );
		Settings_Store::update( array( 'store_ip' => true ) );
		Settings_Store::flush();
		Capture::submit( $this->submission( $c, array( 'fields' => array( 'email' => 'b@example.com', 'topic' => 'Bags' ) ) ) );
		$this->assertSame( 40, strlen( $wpdb->get_var( "SELECT ip_hash FROM {$table} WHERE email = 'b@example.com'" ) ) );
	}
}

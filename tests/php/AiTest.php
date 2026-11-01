<?php
/**
 * DEV3 AI drafts with mocked Gemini: nothing is saved or published.
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- tests.

require_once __DIR__ . '/Dev3pu_TestCase.php';

use Dev3\AiPopupBuilder\Ai\Assistant;
use Dev3\AiPopupBuilder\Ai\Prompt_Library;
use Dev3\AiPopupBuilder\Core3\Api\Prompts;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Stats;

class AiTest extends Dev3pu_TestCase {

	public function set_up() {
		parent::set_up();
		Prompt_Library::register();
		do_action( 'rest_api_init' );
		$this->with_key();
		$this->admin();
	}

	public function test_prompts_are_registered_with_bounded_schemas() {
		foreach ( array( Prompt_Library::COPY, Prompt_Library::VARIANTS, Prompt_Library::TARGETING, Prompt_Library::EXPLAIN, Prompt_Library::CAMPAIGN ) as $id ) {
			$p = Prompts::get( $id );
			$this->assertNotEmpty( $p, $id );
			$json = wp_json_encode( $p['schema'] );
			preg_match_all( '/"type":"STRING"(?!,"maxLength")/', $json, $m );
			$this->assertCount( 0, $m[0], "{$id}: every STRING has maxLength" );
		}
		$this->assertSame( 'heavy', Prompts::get( Prompt_Library::CAMPAIGN )['profile'] );
		$args = Prompts::build( Prompt_Library::COPY, array( 'type' => 'popup', 'goal' => 'Grow', 'language' => 'English', 'brand_voice' => 'calm', 'site' => 'S', 'offer' => 'none', 'audience' => 'a', 'tone' => 't', 'current' => array( 'headline' => 'H' ), 'form' => 'form' ) );
		$this->assertStringContainsString( '"headline":"H"', $args['prompt'] );
		$this->assertStringContainsString( 'no fake countdowns', $args['system'] );
	}

	public function test_copy_route_returns_a_clean_draft_and_saves_nothing() {
		$c               = $this->make_campaign();
		$this->responses = array( $this->gemini( array( 'eyebrow' => 'New', 'headline' => '<b>Save 10%</b> today', 'body' => 'Join us.', 'button' => 'Get it', 'decline' => 'No thanks', 'success_title' => 'Yay', 'success_message' => 'Check inbox' ) ) );
		$res             = $this->rest( 'POST', 'ai/copy', array( 'type' => 'popup', 'goal' => 'More signups', 'current' => $c['config']['variants'][0]['content'] ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'Save 10% today', $data['draft']['headline'] );
		$this->assertSame( 150, $data['meta']['tokens'] );
		$this->assertStringContainsString( 'More signups', $this->requests[0]['body']['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 'Join our newsletter', Campaigns::get( $c['id'] )['config']['variants'][0]['content']['headline'], 'Campaign untouched' );
	}

	public function test_variants_route_returns_up_to_three_ideas() {
		$this->responses = array(
			$this->gemini(
				array(
					'variants' => array(
						array( 'angle' => 'Discount', 'offer' => '10% off', 'headline' => 'A', 'body' => 'a', 'button' => 'Go', 'rationale' => 'Price' ),
						array( 'angle' => 'Free guide', 'offer' => 'PDF', 'headline' => 'B', 'body' => 'b', 'button' => 'Get', 'rationale' => 'Value' ),
						array( 'angle' => 'Shipping', 'offer' => 'Free', 'headline' => 'C', 'body' => 'c', 'button' => 'Ship', 'rationale' => 'Ease' ),
						array( 'angle' => 'Extra', 'headline' => 'D', 'body' => 'd', 'button' => 'X', 'rationale' => 'x' ),
					),
				)
			),
		);
		$data = $this->rest( 'POST', 'ai/variants', array( 'type' => 'popup', 'count' => 3 ) )->get_data();
		$this->assertCount( 3, $data['variants'] );
		$this->assertSame( 'Free guide', $data['variants'][1]['angle'] );
		$this->assertSame( 'B', $data['variants'][1]['content']['headline'] );
	}

	public function test_targeting_suggestion_is_sanitised() {
		$this->responses = array(
			$this->gemini(
				array(
					'triggers'      => array( array( 'type' => 'exit', 'value' => '25' ), array( 'type' => 'teleport', 'value' => '1' ), array( 'type' => 'scroll', 'value' => '70' ) ),
					'devices'       => array( 'desktop', 'toaster' ),
					'page_mode'     => 'include',
					'page_rules'    => array( array( 'type' => 'post_type', 'value' => 'post' ), array( 'type' => 'sql', 'value' => 'DROP' ) ),
					'referrer_mode' => 'search',
					'visitor'       => 'new',
					'frequency'     => array( 'mode' => 'days', 'days' => 400, 'close_days' => 5 ),
					'reasons'       => array( 'Exit intent suits save offers.', '' ),
				)
			),
		);
		$data = $this->rest( 'POST', 'ai/targeting', array( 'type' => 'popup', 'goal' => 'Save leavers' ) )->get_data();
		$this->assertSame( array( array( 'type' => 'exit', 'value' => 25 ), array( 'type' => 'scroll', 'value' => 70 ) ), $data['draft']['triggers'] );
		$this->assertSame( array( 'desktop' ), $data['draft']['targeting']['devices'] );
		$this->assertSame( array( array( 'type' => 'post_type', 'value' => 'post' ) ), $data['draft']['targeting']['pages']['rules'] );
		$this->assertSame( 'search', $data['draft']['targeting']['referrer']['mode'] );
		$this->assertSame( 365, $data['draft']['frequency']['days'] );
		$this->assertSame( array( 'Exit intent suits save offers.' ), $data['reasons'] );
	}

	public function test_campaign_from_a_brief_is_a_draft_object_not_a_saved_row() {
		$this->responses = array(
			$this->gemini(
				array(
					'title' => 'Exit 10%', 'type' => 'popup', 'eyebrow' => 'Wait', 'headline' => 'Take 10% off', 'body' => 'Before you go.', 'button' => 'Get code', 'decline' => 'No thanks',
					'success_title' => 'Here', 'success_message' => 'Use it.', 'form_mode' => 'form', 'ask_name' => true, 'consent' => true, 'offer_code' => 'BYE10',
					'bg' => '#111827', 'text' => '#f9fafb', 'accent' => 'not-a-colour', 'button_text' => '#ffffff',
					'triggers' => array( array( 'type' => 'exit', 'value' => 20 ) ), 'devices' => array( 'desktop' ), 'visitor' => 'new', 'days' => 14,
				)
			),
		);
		$data = $this->rest( 'POST', 'ai/campaign', array( 'brief' => '10% for leaving visitors' ) )->get_data();
		$c    = $data['campaign'];
		$this->assertSame( 'draft', $c['status'] );
		$this->assertSame( 'Take 10% off', $c['config']['variants'][0]['content']['headline'] );
		$this->assertTrue( $c['config']['variants'][0]['ai'] );
		$this->assertSame( '#111827', $c['config']['variants'][0]['design']['bg'] );
		$this->assertSame( '#4f46e5', $c['config']['variants'][0]['design']['accent'], 'Invalid colour falls back' );
		$this->assertSame( 'name', $c['config']['form']['fields'][0]['key'] );
		$this->assertTrue( $c['config']['form']['consent']['enabled'] );
		$this->assertSame( 'BYE10', $c['config']['offer']['code'] );
		$this->assertSame( 'exit', $c['config']['triggers'][0]['type'] );
		$this->assertCount( 0, Campaigns::all(), 'Nothing saved until the admin clicks Create' );
		$this->assertSame( 400, $this->rest( 'POST', 'ai/campaign', array( 'brief' => '' ) )->get_status() );
	}

	public function test_explain_only_names_a_winner_with_enough_data() {
		$c = $this->make_campaign( array( 'config' => array( 'variants' => array( array( 'id' => 'a' ), array( 'id' => 'b' ) ), 'ab' => array( 'enabled' => true ) ) ) );
		Stats::set( $c['id'], 'a', current_time( 'Y-m-d' ), 20, 1 );
		Stats::set( $c['id'], 'b', current_time( 'Y-m-d' ), 20, 3 );
		$this->responses = array( $this->gemini( array( 'summary' => 'B looks better.', 'winner' => 'b', 'confidence_note' => 'Too early.', 'next_test' => 'Try images.' ) ) );
		$data            = $this->rest( 'POST', 'ai/explain', array( 'id' => $c['id'] ) )->get_data();
		$this->assertSame( '', $data['winner'], 'Below 100 views per variant' );
		$this->assertSame( 'B looks better.', $data['summary'] );
		$this->assertSame( 404, $this->rest( 'POST', 'ai/explain', array( 'id' => 999999 ) )->get_status() );
	}

	public function test_ai_off_returns_an_error_and_editor_data_still_loads() {
		Settings_Store::update( array( 'ai_enabled' => false ) );
		Settings_Store::flush();
		$res = $this->rest( 'POST', 'ai/copy', array( 'type' => 'popup' ) );
		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 200, $this->rest( 'GET', 'templates' )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', 'campaigns', array( 'template' => 'newsletter' ) )->get_status() );
	}

	public function test_language_follows_the_user_locale() {
		$this->assertSame( 'English', Assistant::language() );
		add_filter( 'locale', static fn() => 'de_DE' );
		$this->assertSame( 'German', Assistant::language() );
	}
}

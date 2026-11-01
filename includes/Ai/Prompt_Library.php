<?php
/**
 * Versioned prompts for every DEV3 AI feature (one place, unit-tested).
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Ai;

use Dev3\AiPopupBuilder\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt library.
 */
class Prompt_Library {

	const COPY      = 'dev3pu_copy';
	const VARIANTS  = 'dev3pu_variants';
	const TARGETING = 'dev3pu_targeting';
	const EXPLAIN   = 'dev3pu_explain';
	const CAMPAIGN  = 'dev3pu_campaign';

	/**
	 * Shared system rules.
	 */
	const RULES = 'You are a senior conversion copywriter for website popups and lead forms. Write in {{language}}. Rules: be honest and specific, no fake scarcity, no fake countdowns, no guilt-trip "No thanks" text, no ALL CAPS, no emojis unless the brand voice asks for them, no claims the offer does not support. Keep headlines under 60 characters and body copy under 160 characters. Plain text only (no HTML, no Markdown).';

	/**
	 * String schema helper.
	 *
	 * @param int $max Max length.
	 * @return array
	 */
	private static function str( $max ) {
		return array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
	}

	/**
	 * Copy schema properties.
	 *
	 * @return array
	 */
	public static function copy_properties() {
		return array(
			'eyebrow'         => self::str( 40 ),
			'headline'        => self::str( 90 ),
			'body'            => self::str( 240 ),
			'button'          => self::str( 32 ),
			'decline'         => self::str( 40 ),
			'success_title'   => self::str( 80 ),
			'success_message' => self::str( 200 ),
		);
	}

	/**
	 * Registers prompts.
	 */
	public static function register() {
		$copy = self::copy_properties();
		Prompts::register(
			self::COPY,
			array(
				'version'  => '1.0',
				'task'     => 'copy',
				'system'   => self::RULES . ' Brand voice: {{brand_voice}}',
				'template' => "Rewrite the copy for this {{type}} campaign.\nSite: {{site}}\nGoal: {{goal}}\nOffer: {{offer}}\nAudience: {{audience}}\nTone: {{tone}}\nCurrent copy (JSON): {{current}}\nForm: {{form}}\nReturn eyebrow (short label above the headline, may be empty), headline, body, button (verb first, 2-4 words), decline (a polite secondary link such as \"No thanks\", empty for bars), success_title and success_message.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => $copy,
					'required'   => array( 'headline', 'body', 'button', 'success_title', 'success_message' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			self::VARIANTS,
			array(
				'version'  => '1.0',
				'task'     => 'variants',
				'system'   => self::RULES . ' Brand voice: {{brand_voice}}',
				'template' => "Propose {{count}} A/B test variants for this {{type}} campaign. Each variant must test a clearly different angle or offer (for example: discount vs free shipping vs a free guide vs early access; or curiosity vs benefit vs social proof headlines). Only propose offers the site could really give; if the current offer is fixed, vary the angle instead.\nSite: {{site}}\nGoal: {{goal}}\nCurrent offer: {{offer}}\nWooCommerce shop: {{woo}}\nControl copy (JSON): {{current}}\nFor each variant return angle (2-4 words), offer (one line), the copy fields and a one-sentence rationale.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'variants' => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array_merge(
									array(
										'angle'     => self::str( 40 ),
										'offer'     => self::str( 120 ),
										'rationale' => self::str( 240 ),
									),
									$copy
								),
								'required'   => array( 'angle', 'headline', 'body', 'button', 'rationale' ),
							),
						),
					),
					'required'   => array( 'variants' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			self::TARGETING,
			array(
				'version'  => '1.0',
				'task'     => 'targeting',
				'system'   => 'You are a conversion-rate expert who configures website popups that respect visitors. Prefer fewer, better-timed impressions. On phones avoid showing modal popups on page load (Google intrusive interstitials guidance). Exit intent suits save offers; scroll depth suits content upgrades; time on page suits newsletters; inactivity suits help offers. Answer in {{language}}.',
				'template' => "Suggest triggers and targeting for this campaign.\nType: {{type}}\nGoal: {{goal}}\nCopy: {{copy}}\nSite structure: {{site}}\nCurrent triggers: {{triggers}}\nCurrent targeting: {{targeting}}\nCurrent frequency: {{frequency}}\nAllowed trigger types: load, time (seconds), scroll (percent), exit (sensitivity px 10-50), inactivity (seconds), click (CSS selector). Page rule types: front_page, post_type (slug), url (contains text or /path/* wildcard), search, 404, woo (shop|product|cart|checkout|account; only if WooCommerce). Devices: desktop, tablet, mobile. Referrer modes: any, search, social, direct, contains, not_contains. Frequency modes: always, session, days, max.\nReturn the suggested configuration and 2-5 short reasons.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'triggers'      => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'type'  => self::str( 12 ),
									'value' => self::str( 80 ),
								),
								'required'   => array( 'type', 'value' ),
							),
						),
						'devices'       => array(
							'type'  => 'ARRAY',
							'items' => self::str( 10 ),
						),
						'page_mode'     => self::str( 10 ),
						'page_rules'    => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'type'  => self::str( 12 ),
									'value' => self::str( 120 ),
								),
								'required'   => array( 'type', 'value' ),
							),
						),
						'referrer_mode' => self::str( 14 ),
						'visitor'       => self::str( 10 ),
						'frequency'     => array(
							'type'       => 'OBJECT',
							'properties' => array(
								'mode'       => self::str( 10 ),
								'days'       => array( 'type' => 'INTEGER' ),
								'close_days' => array( 'type' => 'INTEGER' ),
							),
						),
						'reasons'       => array(
							'type'  => 'ARRAY',
							'items' => self::str( 200 ),
						),
					),
					'required'   => array( 'triggers', 'devices', 'reasons' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			self::EXPLAIN,
			array(
				'version'  => '1.0',
				'task'     => 'explain',
				'system'   => 'You explain A/B test results to busy site owners in plain words. Be careful with small samples: below 100 views per variant or under 95% confidence, say the result is not conclusive yet. Never invent numbers. Answer in {{language}}.',
				'template' => "Campaign: {{title}} ({{type}})\nResults per variant (views, conversions, conversion rate %, uplift % vs control, confidence %): {{results}}\nVariant copy: {{copy}}\nExplain what the data says, which variant is ahead (or that it is too early), and one idea for the next test.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary'         => self::str( 600 ),
						'winner'          => self::str( 4 ),
						'confidence_note' => self::str( 240 ),
						'next_test'       => self::str( 240 ),
					),
					'required'   => array( 'summary', 'confidence_note', 'next_test' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			self::CAMPAIGN,
			array(
				'version'  => '1.0',
				'task'     => 'campaign',
				'system'   => self::RULES . ' You also choose sensible design colours with WCAG AA contrast between text and background and between button text and accent. Brand voice: {{brand_voice}}',
				'template' => "Create a complete lead capture campaign from this brief: {{brief}}\nSite: {{site}}\nWooCommerce shop: {{woo}}\nTypes: popup, slidein, bar, fullscreen, inline. Triggers: load, time (seconds), scroll (percent), exit, inactivity (seconds). Form modes: form (collects email), cta (button link), none.\nReturn title, type, copy fields, form mode, whether to ask for the first name, a consent checkbox flag, offer_code (only if the brief gives a code, else empty), colours as #rrggbb (bg, text, accent, button_text), one or two triggers, devices, visitor (any|new|returning) and frequency days.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array_merge(
						array(
							'title'       => self::str( 80 ),
							'type'        => self::str( 12 ),
							'form_mode'   => self::str( 6 ),
							'ask_name'    => array( 'type' => 'BOOLEAN' ),
							'consent'     => array( 'type' => 'BOOLEAN' ),
							'offer_code'  => self::str( 30 ),
							'bg'          => self::str( 7 ),
							'text'        => self::str( 7 ),
							'accent'      => self::str( 7 ),
							'button_text' => self::str( 7 ),
							'triggers'    => array(
								'type'  => 'ARRAY',
								'items' => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'type'  => self::str( 12 ),
										'value' => array( 'type' => 'INTEGER' ),
									),
									'required'   => array( 'type' ),
								),
							),
							'devices'     => array(
								'type'  => 'ARRAY',
								'items' => self::str( 10 ),
							),
							'visitor'     => self::str( 10 ),
							'days'        => array( 'type' => 'INTEGER' ),
						),
						$copy
					),
					'required'   => array( 'title', 'type', 'headline', 'body', 'button', 'form_mode', 'triggers' ),
				),
				'profile'  => 'heavy',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
	}
}

<?php
/**
 * DEV3 AI features. Every method returns a draft: nothing is saved, published or activated here.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Ai;

use Dev3\AiPopupBuilder\Campaign\Model;
use Dev3\AiPopupBuilder\Core3\Api\Prompts;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Assistant.
 */
class Assistant {

	/**
	 * Language name for prompts.
	 *
	 * @return string
	 */
	public static function language() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$map    = array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pt' => 'Portuguese',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'ar' => 'Arabic',
			'tr' => 'Turkish',
			'hi' => 'Hindi',
		);
		$lang   = strtolower( substr( (string) $locale, 0, 2 ) );
		return isset( $map[ $lang ] ) ? $map[ $lang ] : 'the language of locale ' . $locale;
	}

	/**
	 * Site description for prompts.
	 *
	 * @return string
	 */
	public static function site() {
		return trim( get_bloginfo( 'name' ) . ' - ' . get_bloginfo( 'description' ), ' -' );
	}

	/**
	 * Common vars.
	 *
	 * @return array
	 */
	private static function base_vars() {
		$voice = (string) Settings_Store::get( 'brand_voice', '' );
		return array(
			'language'    => self::language(),
			'site'        => self::site(),
			'brand_voice' => '' !== $voice ? $voice : 'friendly, clear and confident',
			'woo'         => class_exists( 'WooCommerce' ) ? 'yes' : 'no',
		);
	}

	/**
	 * Cleans copy fields from the model output.
	 *
	 * @param array $data Raw.
	 * @return array
	 */
	public static function clean_copy( array $data ) {
		$out = array();
		foreach ( array(
			'eyebrow'         => 60,
			'headline'        => 120,
			'body'            => 600,
			'button'          => 40,
			'decline'         => 60,
			'success_title'   => 120,
			'success_message' => 400,
		) as $key => $max ) {
			$out[ $key ] = isset( $data[ $key ] ) ? Model::text( $data[ $key ], $max ) : '';
		}
		return $out;
	}

	/**
	 * Draft meta for the UI.
	 *
	 * @param array $result Ai result.
	 * @return array
	 */
	private static function meta( array $result ) {
		return array(
			'model'  => isset( $result['model'] ) ? $result['model'] : '',
			'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Copy draft.
	 *
	 * @param array $input { type, goal, offer, audience, tone, current{}, form_mode }.
	 * @return array|\WP_Error { draft, meta }
	 */
	public static function copy( array $input ) {
		$current = isset( $input['current'] ) && is_array( $input['current'] ) ? self::clean_copy( $input['current'] ) : array();
		$result  = Prompts::run(
			Prompt_Library::COPY,
			array_merge(
				self::base_vars(),
				array(
					'type'     => self::type( $input ),
					'goal'     => self::field( $input, 'goal', 'Grow the email list' ),
					'offer'    => self::field( $input, 'offer', 'none' ),
					'audience' => self::field( $input, 'audience', 'site visitors' ),
					'tone'     => self::field( $input, 'tone', 'friendly' ),
					'current'  => $current,
					'form'     => self::field( $input, 'form_mode', 'form' ),
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'draft' => self::clean_copy( (array) $result['data'] ),
			'meta'  => self::meta( $result ),
		);
	}

	/**
	 * Offer / angle variants for an A/B test.
	 *
	 * @param array $input { type, goal, offer, current{}, count }.
	 * @return array|\WP_Error { variants: [ { angle, offer, rationale, content{} } ], meta }
	 */
	public static function variants( array $input ) {
		$count  = isset( $input['count'] ) ? min( 3, max( 1, (int) $input['count'] ) ) : 3;
		$result = Prompts::run(
			Prompt_Library::VARIANTS,
			array_merge(
				self::base_vars(),
				array(
					'type'    => self::type( $input ),
					'goal'    => self::field( $input, 'goal', 'Grow the email list' ),
					'offer'   => self::field( $input, 'offer', 'none' ),
					'current' => isset( $input['current'] ) && is_array( $input['current'] ) ? self::clean_copy( $input['current'] ) : array(),
					'count'   => $count,
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out = array();
		foreach ( array_slice( isset( $result['data']['variants'] ) ? (array) $result['data']['variants'] : array(), 0, $count ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'angle'     => Model::text( isset( $row['angle'] ) ? $row['angle'] : '', 40 ),
				'offer'     => Model::text( isset( $row['offer'] ) ? $row['offer'] : '', 120 ),
				'rationale' => Model::text( isset( $row['rationale'] ) ? $row['rationale'] : '', 240 ),
				'content'   => self::clean_copy( $row ),
			);
		}
		return array(
			'variants' => $out,
			'meta'     => self::meta( $result ),
		);
	}

	/**
	 * Targeting + trigger suggestion, sanitised by the same model as admin saves.
	 *
	 * @param array $input { type, goal, copy{}, triggers[], targeting{}, frequency{} }.
	 * @return array|\WP_Error { draft: { triggers, targeting, frequency }, reasons[], meta }
	 */
	public static function targeting( array $input ) {
		$type      = self::type( $input );
		$targeting = isset( $input['targeting'] ) && is_array( $input['targeting'] ) ? Model::sanitize_targeting( $input['targeting'] ) : Model::defaults()['targeting'];
		$frequency = isset( $input['frequency'] ) && is_array( $input['frequency'] ) ? $input['frequency'] : Model::defaults()['frequency'];
		$result    = Prompts::run(
			Prompt_Library::TARGETING,
			array_merge(
				self::base_vars(),
				array(
					'type'      => $type,
					'goal'      => self::field( $input, 'goal', 'Grow the email list' ),
					'copy'      => isset( $input['copy'] ) && is_array( $input['copy'] ) ? self::clean_copy( $input['copy'] ) : array(),
					'site'      => self::structure(),
					'triggers'  => isset( $input['triggers'] ) ? (array) $input['triggers'] : array(),
					'targeting' => $targeting,
					'frequency' => $frequency,
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data    = (array) $result['data'];
		$config  = Model::sanitize_config(
			array(
				'triggers'  => isset( $data['triggers'] ) ? (array) $data['triggers'] : array(),
				'targeting' => array_merge(
					$targeting,
					array(
						'devices'  => isset( $data['devices'] ) ? (array) $data['devices'] : $targeting['devices'],
						'pages'    => isset( $data['page_rules'] ) ? array(
							'mode'  => isset( $data['page_mode'] ) ? (string) $data['page_mode'] : 'include',
							'rules' => (array) $data['page_rules'],
						) : $targeting['pages'],
						'referrer' => isset( $data['referrer_mode'] ) && in_array( $data['referrer_mode'], array( 'any', 'search', 'social', 'direct' ), true ) ? array(
							'mode'  => $data['referrer_mode'],
							'value' => '',
						) : $targeting['referrer'],
						'visitor'  => isset( $data['visitor'] ) ? (string) $data['visitor'] : $targeting['visitor'],
					)
				),
				'frequency' => isset( $data['frequency'] ) && is_array( $data['frequency'] ) ? array_merge( $frequency, $data['frequency'] ) : $frequency,
			),
			$type
		);
		$reasons = array();
		foreach ( array_slice( isset( $data['reasons'] ) ? (array) $data['reasons'] : array(), 0, 5 ) as $reason ) {
			$reason = Model::text( $reason, 200 );
			if ( '' !== $reason ) {
				$reasons[] = $reason;
			}
		}
		return array(
			'draft'   => array(
				'triggers'  => $config['triggers'],
				'targeting' => $config['targeting'],
				'frequency' => $config['frequency'],
			),
			'reasons' => $reasons,
			'meta'    => self::meta( $result ),
		);
	}

	/**
	 * Plain-language A/B explanation.
	 *
	 * @param array $campaign Campaign.
	 * @return array|\WP_Error { summary, winner, confidence_note, next_test, meta }
	 */
	public static function explain( array $campaign ) {
		$results = Stats::results( $campaign );
		$rows    = array();
		foreach ( $results['variants'] as $row ) {
			$rows[] = array(
				'variant'     => $row['id'],
				'views'       => $row['views'],
				'conversions' => $row['conversions'],
				'rate'        => $row['rate'],
				'uplift'      => $row['uplift'],
				'confidence'  => $row['confidence'],
			);
		}
		$copy = array();
		foreach ( $campaign['config']['variants'] as $variant ) {
			$copy[ $variant['id'] ] = array(
				'headline' => $variant['content']['headline'],
				'button'   => $variant['content']['button'],
			);
		}
		$result = Prompts::run(
			Prompt_Library::EXPLAIN,
			array(
				'language' => self::language(),
				'title'    => $campaign['title'],
				'type'     => $campaign['type'],
				'results'  => $rows,
				'copy'     => $copy,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) $result['data'];
		$ids  = wp_list_pluck( $campaign['config']['variants'], 'id' );
		$win  = isset( $data['winner'] ) ? strtolower( Model::text( $data['winner'], 4 ) ) : '';
		return array(
			'summary'         => Model::text( isset( $data['summary'] ) ? $data['summary'] : '', 600 ),
			'winner'          => in_array( $win, $ids, true ) && $results['enough_data'] ? $win : '',
			'confidence_note' => Model::text( isset( $data['confidence_note'] ) ? $data['confidence_note'] : '', 240 ),
			'next_test'       => Model::text( isset( $data['next_test'] ) ? $data['next_test'] : '', 240 ),
			'meta'            => self::meta( $result ),
		);
	}

	/**
	 * Whole draft campaign from one sentence (status is always draft).
	 *
	 * @param string $brief Brief.
	 * @return array|\WP_Error { campaign, meta }
	 */
	public static function campaign( $brief ) {
		$brief = mb_substr( trim( sanitize_textarea_field( (string) $brief ) ), 0, 600 );
		if ( '' === $brief ) {
			return new \WP_Error( 'dev3pu_empty_brief', __( 'Describe the campaign you want.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run( Prompt_Library::CAMPAIGN, array_merge( self::base_vars(), array( 'brief' => $brief ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'campaign' => self::campaign_from_data( (array) $result['data'] ),
			'meta'     => self::meta( $result ),
		);
	}

	/**
	 * Maps model output to a sanitised draft campaign.
	 *
	 * @param array $data Model output.
	 * @return array
	 */
	public static function campaign_from_data( array $data ) {
		$type    = isset( $data['type'] ) && in_array( $data['type'], Model::TYPES, true ) ? $data['type'] : 'popup';
		$config  = Model::defaults();
		$variant = Model::variant( 'a' );
		$copy    = self::clean_copy( $data );
		foreach ( $copy as $key => $value ) {
			if ( '' !== $value || in_array( $key, array( 'eyebrow', 'decline' ), true ) ) {
				$variant['content'][ $key ] = $value;
			}
		}
		foreach ( array( 'bg', 'text', 'accent', 'button_text' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$variant['design'][ $key ] = Model::color( $data[ $key ], $variant['design'][ $key ] );
			}
		}
		if ( 'bar' === $type || 'slidein' === $type ) {
			$variant['design']['animation'] = 'slide';
			$variant['design']['size']      = 'sm';
		}
		$variant['ai']          = true;
		$config['variants']     = array( $variant );
		$mode                   = isset( $data['form_mode'] ) && in_array( $data['form_mode'], array( 'form', 'cta', 'none' ), true ) ? $data['form_mode'] : 'form';
		$config['form']['mode'] = $mode;
		if ( 'form' === $mode && ! empty( $data['ask_name'] ) ) {
			array_unshift(
				$config['form']['fields'],
				array(
					'key'         => 'name',
					'type'        => 'text',
					'label'       => __( 'First name', 'dev3-ai-popup-builder' ),
					'placeholder' => '',
					'required'    => false,
					'options'     => array(),
				)
			);
		}
		$config['form']['consent']['enabled'] = ! empty( $data['consent'] );
		if ( ! empty( $data['offer_code'] ) ) {
			$config['offer']['type'] = 'code';
			$config['offer']['code'] = (string) $data['offer_code'];
		}
		if ( isset( $data['triggers'] ) ) {
			$config['triggers'] = (array) $data['triggers'];
		}
		if ( isset( $data['devices'] ) ) {
			$config['targeting']['devices'] = (array) $data['devices'];
		}
		if ( isset( $data['visitor'] ) ) {
			$config['targeting']['visitor'] = (string) $data['visitor'];
		}
		if ( ! empty( $data['days'] ) ) {
			$config['frequency']['days'] = (int) $data['days'];
		}
		if ( 'inline' === $type ) {
			$config['inline']['placement'] = 'after';
		}
		return Model::sanitize_campaign(
			array(
				'title'  => isset( $data['title'] ) ? $data['title'] : __( 'AI campaign', 'dev3-ai-popup-builder' ),
				'type'   => $type,
				'status' => 'draft',
				'config' => $config,
			)
		);
	}

	/**
	 * Site structure summary for targeting prompts.
	 *
	 * @return array
	 */
	public static function structure() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$types[] = $pt->name;
			}
		}
		$cats = array();
		foreach ( get_terms(
			array(
				'taxonomy'   => 'category',
				'number'     => 8,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => true,
			)
		) as $term ) {
			if ( is_object( $term ) ) {
				$cats[] = $term->name;
			}
		}
		return array(
			'name'        => get_bloginfo( 'name' ),
			'post_types'  => $types,
			'categories'  => $cats,
			'woocommerce' => class_exists( 'WooCommerce' ),
		);
	}

	/**
	 * Campaign type from input.
	 *
	 * @param array $input Input.
	 * @return string
	 */
	private static function type( array $input ) {
		return isset( $input['type'] ) && in_array( $input['type'], Model::TYPES, true ) ? $input['type'] : 'popup';
	}

	/**
	 * Short text input.
	 *
	 * @param array  $input    Input.
	 * @param string $key      Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function field( array $input, $key, $fallback ) {
		$value = isset( $input[ $key ] ) ? Model::text( $input[ $key ], 300 ) : '';
		return '' !== $value ? $value : $fallback;
	}
}

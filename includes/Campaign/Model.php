<?php
/**
 * Campaign config model: defaults and the one sanitiser every write goes through
 * (admin saves, templates, AI drafts accepted by the admin).
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Campaign;

use Dev3\AiPopupBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign model.
 */
class Model {

	const TYPES       = array( 'popup', 'slidein', 'bar', 'fullscreen', 'inline' );
	const STATUSES    = array( 'draft', 'active', 'paused', 'archived' );
	const TRIGGERS    = array( 'load', 'time', 'scroll', 'exit', 'click', 'inactivity' );
	const FIELD_TYPES = array( 'email', 'text', 'tel', 'select', 'checkbox' );
	const LAYOUTS     = array( 'stacked', 'image-left', 'image-top', 'minimal' );
	const SIZES       = array( 'sm', 'md', 'lg' );
	const ANIMATIONS  = array( 'fade', 'slide', 'zoom', 'none' );
	const POSITIONS   = array( 'bottom-right', 'bottom-left', 'top', 'bottom' );
	const PAGE_RULES  = array( 'front_page', 'post_type', 'ids', 'term', 'url', 'search', '404', 'woo' );
	const DEVICES     = array( 'desktop', 'tablet', 'mobile' );
	const UTM_KEYS    = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
	const MAX_VARIANT = 4;

	/**
	 * Default variant.
	 *
	 * @param string $id Variant id.
	 * @return array
	 */
	public static function variant( $id = 'a' ) {
		return array(
			'id'      => $id,
			'name'    => strtoupper( $id ),
			'weight'  => 100,
			'ai'      => false,
			'content' => array(
				'eyebrow'         => '',
				'headline'        => 'Join our newsletter',
				'body'            => 'Get new articles and offers in your inbox. No spam, unsubscribe any time.',
				'button'          => 'Subscribe',
				'decline'         => '',
				'success_title'   => 'Thanks for subscribing!',
				'success_message' => 'Please check your inbox.',
				'image_url'       => '',
				'image_alt'       => '',
			),
			'design'  => array(
				'layout'      => 'stacked',
				'size'        => 'md',
				'align'       => 'center',
				'bg'          => '#ffffff',
				'text'        => '#1f2937',
				'accent'      => '#4f46e5',
				'button_text' => '#ffffff',
				'radius'      => 14,
				'overlay'     => 55,
				'animation'   => 'fade',
				'position'    => 'bottom-right',
			),
		);
	}

	/**
	 * Default config.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'variants'     => array( self::variant( 'a' ) ),
			'form'         => array(
				'mode'        => 'form',
				'fields'      => array(
					array(
						'key'         => 'email',
						'type'        => 'email',
						'label'       => 'Email address',
						'placeholder' => 'you@example.com',
						'required'    => true,
						'options'     => array(),
					),
				),
				'consent'     => array(
					'enabled'  => false,
					'label'    => 'I agree to receive emails and accept the privacy policy.',
					'required' => true,
				),
				'cta_url'     => '',
				'cta_new_tab' => false,
			),
			'success'      => array(
				'action'       => 'message',
				'redirect_url' => '',
			),
			'offer'        => array(
				'type'          => 'none',
				'code'          => '',
				'discount_type' => 'percent',
				'amount'        => 10,
				'expiry_days'   => 14,
				'prefix'        => 'WELCOME',
			),
			'triggers'     => array(
				array(
					'type'  => 'time',
					'value' => 8,
				),
			),
			'exit_mobile'  => 'scroll_up',
			'targeting'    => array(
				'pages'     => array(
					'mode'  => 'all',
					'rules' => array(),
				),
				'devices'   => self::DEVICES,
				'referrer'  => array(
					'mode'  => 'any',
					'value' => '',
				),
				'utm'       => array(),
				'login'     => 'any',
				'visitor'   => 'any',
				'min_pages' => 0,
				'cart'      => array(
					'enabled'   => false,
					'min'       => 0,
					'max'       => 0,
					'not_empty' => false,
				),
			),
			'frequency'    => array(
				'mode'           => 'days',
				'days'           => 7,
				'max'            => 3,
				'close_days'     => 3,
				'converted'      => 'never',
				'converted_days' => 90,
			),
			'inline'       => array(
				'placement'  => 'manual',
				'post_types' => array( 'post' ),
			),
			'integrations' => array(
				'mailchimp' => array(
					'enabled' => false,
					'list_id' => '',
					'tags'    => '',
					'double'  => false,
				),
				'brevo'     => array(
					'enabled' => false,
					'list_id' => '',
				),
				'webhook'   => array(
					'enabled' => false,
					'url'     => '',
				),
			),
			'ab'           => array(
				'enabled' => false,
				'winner'  => '',
			),
		);
	}

	/**
	 * Sanitises a full campaign (row-level fields + config).
	 *
	 * @param array $input   Raw campaign.
	 * @param array $current Existing campaign (for partial updates).
	 * @return array { title, type, status, priority, starts_at, ends_at, config }
	 */
	public static function sanitize_campaign( array $input, array $current = array() ) {
		$base = array_merge(
			array(
				'title'     => 'Untitled campaign',
				'type'      => 'popup',
				'status'    => 'draft',
				'priority'  => 10,
				'starts_at' => null,
				'ends_at'   => null,
				'config'    => self::defaults(),
			),
			$current
		);
		$out  = array(
			'title'     => isset( $input['title'] ) ? self::text( $input['title'], 190 ) : $base['title'],
			'type'      => isset( $input['type'] ) && in_array( $input['type'], self::TYPES, true ) ? $input['type'] : $base['type'],
			'status'    => isset( $input['status'] ) && in_array( $input['status'], self::STATUSES, true ) ? $input['status'] : $base['status'],
			'priority'  => isset( $input['priority'] ) ? min( 100, max( 0, (int) $input['priority'] ) ) : (int) $base['priority'],
			'starts_at' => array_key_exists( 'starts_at', $input ) ? self::datetime( $input['starts_at'] ) : $base['starts_at'],
			'ends_at'   => array_key_exists( 'ends_at', $input ) ? self::datetime( $input['ends_at'] ) : $base['ends_at'],
		);
		if ( '' === $out['title'] ) {
			$out['title'] = 'Untitled campaign';
		}
		$config        = isset( $input['config'] ) && is_array( $input['config'] ) ? $input['config'] : $base['config'];
		$out['config'] = self::sanitize_config( $config, $out['type'] );
		return $out;
	}

	/**
	 * Sanitises the config JSON. Unknown keys are dropped; missing keys get defaults.
	 *
	 * @param array  $in   Raw config.
	 * @param string $type Campaign type.
	 * @return array
	 */
	public static function sanitize_config( array $in, $type = 'popup' ) {
		$d   = self::defaults();
		$out = array();

		// Variants.
		$variants = array();
		$seen     = array();
		foreach ( array_slice( isset( $in['variants'] ) && is_array( $in['variants'] ) ? array_values( $in['variants'] ) : array(), 0, self::MAX_VARIANT ) as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$id = isset( $raw['id'] ) ? strtolower( preg_replace( '/[^a-z]/i', '', (string) $raw['id'] ) ) : '';
			if ( '' === $id || strlen( $id ) > 1 || isset( $seen[ $id ] ) ) {
				$id = self::next_variant_id( array_keys( $seen ) );
			}
			$seen[ $id ] = true;
			$variants[]  = self::sanitize_variant( $raw, $id, $i );
		}
		if ( ! $variants ) {
			$variants = $d['variants'];
		}
		$out['variants'] = $variants;

		// Form.
		$form   = isset( $in['form'] ) && is_array( $in['form'] ) ? $in['form'] : array();
		$fields = array();
		$keys   = array();
		foreach ( array_slice( isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array(), 0, 6 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$ftype = isset( $field['type'] ) && in_array( $field['type'], self::FIELD_TYPES, true ) ? $field['type'] : 'text';
			$key   = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
			$key   = '' === $key ? $ftype : substr( $key, 0, 30 );
			while ( isset( $keys[ $key ] ) ) {
				$key .= '_2';
			}
			$keys[ $key ] = true;
			$options      = array();
			if ( 'select' === $ftype && isset( $field['options'] ) ) {
				$list = is_array( $field['options'] ) ? $field['options'] : explode( "\n", (string) $field['options'] );
				foreach ( array_slice( $list, 0, 20 ) as $opt ) {
					$opt = self::text( $opt, 80 );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
			}
			$fields[] = array(
				'key'         => $key,
				'type'        => $ftype,
				'label'       => self::text( isset( $field['label'] ) ? $field['label'] : ucfirst( $key ), 120 ),
				'placeholder' => self::text( isset( $field['placeholder'] ) ? $field['placeholder'] : '', 120 ),
				'required'    => ! empty( $field['required'] ),
				'options'     => $options,
			);
		}
		$mode = isset( $form['mode'] ) && in_array( $form['mode'], array( 'form', 'cta', 'none' ), true ) ? $form['mode'] : 'form';
		if ( 'form' === $mode && ! in_array( 'email', wp_list_pluck( $fields, 'type' ), true ) ) {
			array_unshift( $fields, $d['form']['fields'][0] );
			$fields = array_slice( $fields, 0, 6 );
		}
		$consent     = isset( $form['consent'] ) && is_array( $form['consent'] ) ? $form['consent'] : array();
		$out['form'] = array(
			'mode'        => $mode,
			'fields'      => $fields ? $fields : $d['form']['fields'],
			'consent'     => array(
				'enabled'  => ! empty( $consent['enabled'] ),
				'label'    => isset( $consent['label'] ) ? mb_substr( wp_kses( (string) $consent['label'], Settings::inline_html() ), 0, 400 ) : $d['form']['consent']['label'],
				'required' => ! isset( $consent['required'] ) || ! empty( $consent['required'] ),
			),
			'cta_url'     => isset( $form['cta_url'] ) ? esc_url_raw( (string) $form['cta_url'] ) : '',
			'cta_new_tab' => ! empty( $form['cta_new_tab'] ),
		);

		// Success + offer.
		$success        = isset( $in['success'] ) && is_array( $in['success'] ) ? $in['success'] : array();
		$out['success'] = array(
			'action'       => isset( $success['action'] ) && in_array( $success['action'], array( 'message', 'redirect' ), true ) ? $success['action'] : 'message',
			'redirect_url' => isset( $success['redirect_url'] ) ? esc_url_raw( (string) $success['redirect_url'] ) : '',
		);
		$offer          = isset( $in['offer'] ) && is_array( $in['offer'] ) ? array_merge( $d['offer'], $in['offer'] ) : $d['offer'];
		$out['offer']   = array(
			'type'          => in_array( $offer['type'], array( 'none', 'code', 'coupon' ), true ) ? $offer['type'] : 'none',
			'code'          => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $offer['code'] ), 0, 40 ),
			'discount_type' => in_array( $offer['discount_type'], array( 'percent', 'fixed_cart' ), true ) ? $offer['discount_type'] : 'percent',
			'amount'        => min( 100000, max( 0, round( (float) $offer['amount'], 2 ) ) ),
			'expiry_days'   => min( 365, max( 0, (int) $offer['expiry_days'] ) ),
			'prefix'        => substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $offer['prefix'] ) ), 0, 12 ),
		);
		if ( 'percent' === $out['offer']['discount_type'] ) {
			$out['offer']['amount'] = min( 100, $out['offer']['amount'] );
		}

		// Triggers.
		$triggers = array();
		foreach ( array_slice( isset( $in['triggers'] ) && is_array( $in['triggers'] ) ? $in['triggers'] : array(), 0, 6 ) as $trigger ) {
			if ( ! is_array( $trigger ) || ! isset( $trigger['type'] ) || ! in_array( $trigger['type'], self::TRIGGERS, true ) ) {
				continue;
			}
			$triggers[] = array(
				'type'  => $trigger['type'],
				'value' => self::trigger_value( $trigger['type'], isset( $trigger['value'] ) ? $trigger['value'] : '' ),
			);
		}
		$out['triggers']    = 'inline' === $type ? array() : ( $triggers ? $triggers : $d['triggers'] );
		$out['exit_mobile'] = isset( $in['exit_mobile'] ) && in_array( $in['exit_mobile'], array( 'scroll_up', 'off' ), true ) ? $in['exit_mobile'] : 'scroll_up';

		$out['targeting'] = self::sanitize_targeting( isset( $in['targeting'] ) && is_array( $in['targeting'] ) ? $in['targeting'] : array() );

		// Frequency.
		$freq             = isset( $in['frequency'] ) && is_array( $in['frequency'] ) ? array_merge( $d['frequency'], $in['frequency'] ) : $d['frequency'];
		$out['frequency'] = array(
			'mode'           => in_array( $freq['mode'], array( 'always', 'session', 'days', 'max' ), true ) ? $freq['mode'] : 'days',
			'days'           => min( 365, max( 1, (int) $freq['days'] ) ),
			'max'            => min( 100, max( 1, (int) $freq['max'] ) ),
			'close_days'     => min( 365, max( 0, (int) $freq['close_days'] ) ),
			'converted'      => in_array( $freq['converted'], array( 'never', 'days' ), true ) ? $freq['converted'] : 'never',
			'converted_days' => min( 3650, max( 1, (int) $freq['converted_days'] ) ),
		);

		// Inline placement.
		$inline        = isset( $in['inline'] ) && is_array( $in['inline'] ) ? $in['inline'] : array();
		$out['inline'] = array(
			'placement'  => isset( $inline['placement'] ) && in_array( $inline['placement'], array( 'manual', 'before', 'after' ), true ) ? $inline['placement'] : 'manual',
			'post_types' => isset( $inline['post_types'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) $inline['post_types'] ) ) ) : array( 'post' ),
		);

		// Integrations.
		$int                 = isset( $in['integrations'] ) && is_array( $in['integrations'] ) ? $in['integrations'] : array();
		$mc                  = isset( $int['mailchimp'] ) && is_array( $int['mailchimp'] ) ? $int['mailchimp'] : array();
		$br                  = isset( $int['brevo'] ) && is_array( $int['brevo'] ) ? $int['brevo'] : array();
		$wh                  = isset( $int['webhook'] ) && is_array( $int['webhook'] ) ? $int['webhook'] : array();
		$webhook_url         = isset( $wh['url'] ) ? esc_url_raw( (string) $wh['url'], array( 'https', 'http' ) ) : '';
		$out['integrations'] = array(
			'mailchimp' => array(
				'enabled' => ! empty( $mc['enabled'] ),
				'list_id' => isset( $mc['list_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $mc['list_id'] ), 0, 40 ) : '',
				'tags'    => isset( $mc['tags'] ) ? self::text( $mc['tags'], 200 ) : '',
				'double'  => ! empty( $mc['double'] ),
			),
			'brevo'     => array(
				'enabled' => ! empty( $br['enabled'] ),
				'list_id' => isset( $br['list_id'] ) ? (string) absint( $br['list_id'] ) : '',
			),
			'webhook'   => array(
				'enabled' => ! empty( $wh['enabled'] ),
				'url'     => $webhook_url,
			),
		);
		if ( '0' === $out['integrations']['brevo']['list_id'] ) {
			$out['integrations']['brevo']['list_id'] = '';
		}

		// A/B.
		$ab        = isset( $in['ab'] ) && is_array( $in['ab'] ) ? $in['ab'] : array();
		$ids       = wp_list_pluck( $variants, 'id' );
		$winner    = isset( $ab['winner'] ) && in_array( $ab['winner'], $ids, true ) ? $ab['winner'] : '';
		$out['ab'] = array(
			'enabled' => ! empty( $ab['enabled'] ) && count( $variants ) > 1,
			'winner'  => $winner,
		);
		return $out;
	}

	/**
	 * Sanitises targeting (also used for AI suggestions).
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize_targeting( array $in ) {
		$d     = self::defaults()['targeting'];
		$pages = isset( $in['pages'] ) && is_array( $in['pages'] ) ? $in['pages'] : array();
		$rules = array();
		foreach ( array_slice( isset( $pages['rules'] ) && is_array( $pages['rules'] ) ? $pages['rules'] : array(), 0, 20 ) as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['type'] ) || ! in_array( $rule['type'], self::PAGE_RULES, true ) ) {
				continue;
			}
			$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
			switch ( $rule['type'] ) {
				case 'post_type':
					$value = sanitize_key( $value );
					break;
				case 'ids':
					$value = implode( ',', array_slice( array_filter( array_map( 'absint', explode( ',', $value ) ) ), 0, 50 ) );
					break;
				case 'term':
					$value = preg_match( '/^([a-z0-9_-]{1,32}):(\d+)$/', $value ) ? $value : '';
					break;
				case 'url':
					$value = self::text( $value, 200 );
					break;
				case 'woo':
					$value = in_array( $value, array( 'shop', 'product', 'cart', 'checkout', 'account' ), true ) ? $value : 'shop';
					break;
				default:
					$value = '';
			}
			if ( in_array( $rule['type'], array( 'post_type', 'ids', 'term', 'url' ), true ) && '' === $value ) {
				continue;
			}
			$rules[] = array(
				'type'  => $rule['type'],
				'value' => $value,
			);
		}
		$mode    = isset( $pages['mode'] ) && in_array( $pages['mode'], array( 'all', 'include', 'exclude' ), true ) ? $pages['mode'] : 'all';
		$devices = isset( $in['devices'] ) ? array_values( array_intersect( self::DEVICES, (array) $in['devices'] ) ) : $d['devices'];
		$ref     = isset( $in['referrer'] ) && is_array( $in['referrer'] ) ? $in['referrer'] : array();
		$utm     = array();
		foreach ( array_slice( isset( $in['utm'] ) && is_array( $in['utm'] ) ? $in['utm'] : array(), 0, 5 ) as $u ) {
			if ( ! is_array( $u ) || ! isset( $u['key'] ) || ! in_array( $u['key'], self::UTM_KEYS, true ) ) {
				continue;
			}
			$value = self::text( isset( $u['value'] ) ? $u['value'] : '', 100 );
			if ( '' === $value ) {
				continue;
			}
			$utm[] = array(
				'key'   => $u['key'],
				'op'    => isset( $u['op'] ) && 'contains' === $u['op'] ? 'contains' : 'equals',
				'value' => $value,
			);
		}
		$cart = isset( $in['cart'] ) && is_array( $in['cart'] ) ? $in['cart'] : array();
		return array(
			'pages'     => array(
				'mode'  => $rules ? $mode : 'all',
				'rules' => $rules,
			),
			'devices'   => $devices ? $devices : $d['devices'],
			'referrer'  => array(
				'mode'  => isset( $ref['mode'] ) && in_array( $ref['mode'], array( 'any', 'contains', 'not_contains', 'search', 'social', 'direct' ), true ) ? $ref['mode'] : 'any',
				'value' => self::text( isset( $ref['value'] ) ? $ref['value'] : '', 120 ),
			),
			'utm'       => $utm,
			'login'     => isset( $in['login'] ) && in_array( $in['login'], array( 'any', 'in', 'out' ), true ) ? $in['login'] : 'any',
			'visitor'   => isset( $in['visitor'] ) && in_array( $in['visitor'], array( 'any', 'new', 'returning' ), true ) ? $in['visitor'] : 'any',
			'min_pages' => isset( $in['min_pages'] ) ? min( 50, max( 0, (int) $in['min_pages'] ) ) : 0,
			'cart'      => array(
				'enabled'   => ! empty( $cart['enabled'] ),
				'min'       => isset( $cart['min'] ) ? max( 0, round( (float) $cart['min'], 2 ) ) : 0,
				'max'       => isset( $cart['max'] ) ? max( 0, round( (float) $cart['max'], 2 ) ) : 0,
				'not_empty' => ! empty( $cart['not_empty'] ),
			),
		);
	}

	/**
	 * Sanitises one variant.
	 *
	 * @param array  $raw   Raw.
	 * @param string $id    Id.
	 * @param int    $index Position.
	 * @return array
	 */
	public static function sanitize_variant( array $raw, $id, $index = 0 ) {
		$d       = self::variant( $id );
		$content = isset( $raw['content'] ) && is_array( $raw['content'] ) ? array_merge( $d['content'], $raw['content'] ) : $d['content'];
		$design  = isset( $raw['design'] ) && is_array( $raw['design'] ) ? array_merge( $d['design'], $raw['design'] ) : $d['design'];
		$out     = array(
			'id'      => $id,
			'name'    => self::text( isset( $raw['name'] ) && '' !== $raw['name'] ? $raw['name'] : strtoupper( $id ), 40 ),
			'weight'  => isset( $raw['weight'] ) ? min( 100, max( 0, (int) $raw['weight'] ) ) : ( 0 === $index ? 100 : 50 ),
			'ai'      => ! empty( $raw['ai'] ),
			'content' => array(
				'eyebrow'         => self::text( $content['eyebrow'], 60 ),
				'headline'        => self::text( $content['headline'], 120 ),
				'body'            => mb_substr( wp_kses( (string) $content['body'], Settings::inline_html() ), 0, 600 ),
				'button'          => self::text( $content['button'], 40 ),
				'decline'         => self::text( $content['decline'], 60 ),
				'success_title'   => self::text( $content['success_title'], 120 ),
				'success_message' => mb_substr( wp_kses( (string) $content['success_message'], Settings::inline_html() ), 0, 400 ),
				'image_url'       => esc_url_raw( (string) $content['image_url'] ),
				'image_alt'       => self::text( $content['image_alt'], 160 ),
			),
			'design'  => array(
				'layout'      => in_array( $design['layout'], self::LAYOUTS, true ) ? $design['layout'] : 'stacked',
				'size'        => in_array( $design['size'], self::SIZES, true ) ? $design['size'] : 'md',
				'align'       => in_array( $design['align'], array( 'left', 'center' ), true ) ? $design['align'] : 'center',
				'bg'          => self::color( $design['bg'], '#ffffff' ),
				'text'        => self::color( $design['text'], '#1f2937' ),
				'accent'      => self::color( $design['accent'], '#4f46e5' ),
				'button_text' => self::color( $design['button_text'], '#ffffff' ),
				'radius'      => min( 32, max( 0, (int) $design['radius'] ) ),
				'overlay'     => min( 90, max( 0, (int) $design['overlay'] ) ),
				'animation'   => in_array( $design['animation'], self::ANIMATIONS, true ) ? $design['animation'] : 'fade',
				'position'    => in_array( $design['position'], self::POSITIONS, true ) ? $design['position'] : 'bottom-right',
			),
		);
		return $out;
	}

	/**
	 * Next free variant id (a, b, c, d).
	 *
	 * @param array $used Used ids.
	 * @return string
	 */
	public static function next_variant_id( array $used ) {
		foreach ( array( 'a', 'b', 'c', 'd' ) as $id ) {
			if ( ! in_array( $id, $used, true ) ) {
				return $id;
			}
		}
		return 'd';
	}

	/**
	 * Trigger value.
	 *
	 * @param string $type  Type.
	 * @param mixed  $value Value.
	 * @return int|string
	 */
	public static function trigger_value( $type, $value ) {
		switch ( $type ) {
			case 'time':
				return min( 600, max( 0, (int) $value ) );
			case 'scroll':
				return min( 100, max( 1, (int) $value ? (int) $value : 50 ) );
			case 'exit':
				return min( 100, max( 1, (int) $value ? (int) $value : 20 ) );
			case 'inactivity':
				return min( 600, max( 5, (int) $value ? (int) $value : 30 ) );
			case 'click':
				// A CSS selector: keep a conservative character set.
				return substr( preg_replace( '/[^A-Za-z0-9_\-\.#\[\]=":\s,>]/', '', (string) $value ), 0, 200 );
		}
		return 0;
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function text( $value, $max ) {
		return mb_substr( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, $max );
	}

	/**
	 * Hex colour with fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function color( $value, $fallback ) {
		$color = sanitize_hex_color( is_string( $value ) ? $value : '' );
		return $color ? $color : $fallback;
	}

	/**
	 * Datetime (site timezone, "Y-m-d H:i:s") or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function datetime( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$time = strtotime( str_replace( 'T', ' ', $value ) );
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null;
	}
}

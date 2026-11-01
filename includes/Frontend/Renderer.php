<?php
/**
 * Server-side markup for inline campaigns (shortcode, block, auto-insert). It mirrors the JS
 * renderer in src/frontend/render.js class for class, so one stylesheet serves both.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Frontend;

use Dev3\AiPopupBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renderer.
 */
class Renderer {

	/**
	 * Unique id counter.
	 *
	 * @var int
	 */
	private static $uid = 0;

	/**
	 * CSS custom properties for a variant.
	 *
	 * @param array $design Design.
	 * @return string
	 */
	public static function style_vars( array $design ) {
		return sprintf(
			'--dev3pu-pbg:%1$s;--dev3pu-pfg:%2$s;--dev3pu-pacc:%3$s;--dev3pu-pon:%4$s;--dev3pu-prad:%5$dpx;--dev3pu-pdim:%6$s',
			$design['bg'],
			$design['text'],
			$design['accent'],
			$design['button_text'],
			(int) $design['radius'],
			number_format( $design['overlay'] / 100, 2, '.', '' )
		);
	}

	/**
	 * Wrapper classes.
	 *
	 * @param string $type   Type.
	 * @param array  $design Design.
	 * @param bool   $image  Has image.
	 * @param string $form_mode Form mode.
	 * @return string
	 */
	public static function classes( $type, array $design, $image, $form_mode = 'form' ) {
		$layout = $image ? $design['layout'] : ( 'minimal' === $design['layout'] ? 'minimal' : 'stacked' );
		return implode(
			' ',
			array(
				'dev3pu-w',
				'dev3pu-w--' . $type,
				'dev3pu-l--' . $layout,
				'dev3pu-s--' . $design['size'],
				'dev3pu-a--' . $design['align'],
				'dev3pu-anim--' . $design['animation'],
				'dev3pu-f--' . $form_mode,
			)
		);
	}

	/**
	 * Inline campaign markup with all variants (one visible; an inline script picks the A/B variant before paint).
	 *
	 * @param array $campaign Campaign.
	 * @return string
	 */
	public static function inline( array $campaign ) {
		$config   = $campaign['config'];
		$variants = Frontend::serving_variants( $campaign );
		$html     = '';
		foreach ( $variants as $i => $variant ) {
			$html .= self::variant( $campaign, $variant, count( $variants ) > 1 && $i > 0 );
		}
		if ( count( $variants ) > 1 ) {
			$weights = array();
			foreach ( $variants as $variant ) {
				$weights[ $variant['id'] ] = $variant['weight'];
			}
			// Runs synchronously right after the markup, before first paint: no layout shift.
			$html .= '<script>(function(){try{var id=' . (int) $campaign['id'] . ',w=' . wp_json_encode( $weights ) . ',k="dev3pu_ab_"+id,v=localStorage.getItem(k);if(!v||!(v in w)){var t=0,r,s;for(s in w){t+=w[s];}r=Math.random()*(t||1);for(s in w){r-=w[s];if(r<0||!t){v=s;break;}}localStorage.setItem(k,v);}var n=document.querySelectorAll(\'[data-dev3pu-id="\'+id+\'"][data-dev3pu-inline]\');for(var i=0;i<n.length;i++){n[i].hidden=n[i].getAttribute("data-dev3pu-variant")!==v;}}catch(e){}})();</script>';
		}
		unset( $config );
		return $html;
	}

	/**
	 * One variant.
	 *
	 * @param array $campaign Campaign.
	 * @param array $variant  Variant.
	 * @param bool  $hidden   Start hidden.
	 * @return string
	 */
	public static function variant( array $campaign, array $variant, $hidden ) {
		++self::$uid;
		$uid     = 'dev3pu-' . (int) $campaign['id'] . '-' . $variant['id'] . '-' . self::$uid;
		$c       = $variant['content'];
		$design  = $variant['design'];
		$config  = $campaign['config'];
		$image   = '' !== $c['image_url'];
		$classes = self::classes( 'inline', $design, $image, $config['form']['mode'] );
		$allowed = Settings::inline_html();

		$out  = '<div class="' . esc_attr( $classes ) . '" data-dev3pu-inline data-dev3pu-id="' . esc_attr( (string) $campaign['id'] ) . '" data-dev3pu-variant="' . esc_attr( $variant['id'] ) . '" style="' . esc_attr( self::style_vars( $design ) ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
		$out .= '<section class="dev3pu-box" aria-labelledby="' . esc_attr( $uid ) . '-t">';
		if ( $image ) {
			$out .= '<div class="dev3pu-media"><img src="' . esc_url( $c['image_url'] ) . '" alt="' . esc_attr( $c['image_alt'] ) . '" width="400" height="300" loading="lazy" decoding="async"></div>';
		}
		$out .= '<div class="dev3pu-main"><div class="dev3pu-content">';
		if ( '' !== $c['eyebrow'] ) {
			$out .= '<p class="dev3pu-eyebrow">' . esc_html( $c['eyebrow'] ) . '</p>';
		}
		$out .= '<h2 class="dev3pu-title" id="' . esc_attr( $uid ) . '-t">' . esc_html( $c['headline'] ) . '</h2>';
		if ( '' !== $c['body'] ) {
			$out .= '<div class="dev3pu-text">' . nl2br( wp_kses( $c['body'], $allowed ) ) . '</div>';
		}
		$out .= '</div>';
		$out .= self::action( $config, $c, $uid );
		$out .= '</div>';
		$out .= self::done( $c, $uid );
		$out .= '</section></div>';
		return $out;
	}

	/**
	 * Form / CTA.
	 *
	 * @param array  $config Config.
	 * @param array  $c      Content.
	 * @param string $uid    Unique id.
	 * @return string
	 */
	private static function action( array $config, array $c, $uid ) {
		$form = $config['form'];
		if ( 'none' === $form['mode'] ) {
			return '';
		}
		if ( 'cta' === $form['mode'] ) {
			if ( '' === $form['cta_url'] || '' === $c['button'] ) {
				return '';
			}
			$target = $form['cta_new_tab'] ? ' target="_blank" rel="noopener"' : '';
			return '<div class="dev3pu-actions"><a class="dev3pu-btn" data-dev3pu-cta href="' . esc_url( $form['cta_url'] ) . '"' . $target . '>' . esc_html( $c['button'] ) . '</a></div>';
		}
		$out = '<form class="dev3pu-form" novalidate data-dev3pu-form><div class="dev3pu-fields">';
		foreach ( $form['fields'] as $field ) {
			$out .= self::field( $field, $uid );
		}
		$out .= '</div>';
		if ( $form['consent']['enabled'] ) {
			$id   = $uid . '-consent';
			$out .= '<div class="dev3pu-field dev3pu-field--consent"><label class="dev3pu-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="dev3pu_consent" value="1"' . ( $form['consent']['required'] ? ' required aria-required="true"' : '' ) . ' aria-describedby="' . esc_attr( $id ) . '-e"> <span>' . wp_kses( $form['consent']['label'], Settings::inline_html() ) . '</span></label><p class="dev3pu-error" id="' . esc_attr( $id ) . '-e" hidden></p></div>';
		}
		$out .= '<div class="dev3pu-hp" aria-hidden="true"><label>Website <input type="text" name="dev3pu_hp" tabindex="-1" autocomplete="off"></label></div>';
		$out .= '<button type="submit" class="dev3pu-btn">' . esc_html( '' !== $c['button'] ? $c['button'] : __( 'Submit', 'dev3-ai-popup-builder' ) ) . '</button>';
		$out .= '<p class="dev3pu-status" role="status" aria-live="polite"></p>';
		$out .= '</form>';
		return $out;
	}

	/**
	 * One field.
	 *
	 * @param array  $field Field.
	 * @param string $uid   Unique id.
	 * @return string
	 */
	public static function field( array $field, $uid ) {
		$id       = $uid . '-' . $field['key'];
		$required = $field['required'] ? ' required aria-required="true"' : '';
		$describe = ' aria-describedby="' . esc_attr( $id ) . '-e"';
		$error    = '<p class="dev3pu-error" id="' . esc_attr( $id ) . '-e" hidden></p>';
		$star     = $field['required'] ? '<span class="dev3pu-req" aria-hidden="true">*</span>' : '';
		if ( 'checkbox' === $field['type'] ) {
			return '<div class="dev3pu-field dev3pu-field--checkbox"><label class="dev3pu-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field['key'] ) . '" value="1"' . $required . $describe . '> <span>' . esc_html( $field['label'] ) . $star . '</span></label>' . $error . '</div>';
		}
		$label = '<label class="dev3pu-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $star . '</label>';
		if ( 'select' === $field['type'] ) {
			$options = '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Choose…', 'dev3-ai-popup-builder' ) ) . '</option>';
			foreach ( $field['options'] as $opt ) {
				$options .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
			}
			return '<div class="dev3pu-field dev3pu-field--select">' . $label . '<select class="dev3pu-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field['key'] ) . '"' . $required . $describe . '>' . $options . '</select>' . $error . '</div>';
		}
		$auto = array(
			'email' => 'email',
			'tel'   => 'tel',
		);
		$ac   = isset( $auto[ $field['type'] ] ) ? $auto[ $field['type'] ] : ( 'name' === $field['key'] ? 'given-name' : 'off' );
		return '<div class="dev3pu-field dev3pu-field--' . esc_attr( $field['type'] ) . '">' . $label . '<input class="dev3pu-input" type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field['key'] ) . '" autocomplete="' . esc_attr( $ac ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '"' . $required . $describe . '>' . $error . '</div>';
	}

	/**
	 * Success state.
	 *
	 * @param array  $c   Content.
	 * @param string $uid Unique id.
	 * @return string
	 */
	private static function done( array $c, $uid ) {
		return '<div class="dev3pu-done" hidden tabindex="-1"><span class="dev3pu-check-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><h2 class="dev3pu-title" id="' . esc_attr( $uid ) . '-d">' . esc_html( $c['success_title'] ) . '</h2><div class="dev3pu-text">' . nl2br( wp_kses( $c['success_message'], Settings::inline_html() ) ) . '</div><div class="dev3pu-code" hidden><code></code><button type="button" class="dev3pu-copy">' . esc_html__( 'Copy code', 'dev3-ai-popup-builder' ) . '</button></div></div>';
	}
}

import { useEffect, useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { renderCampaign, showSuccess } from '../../frontend/render';
import { toPublic } from '../model';

const I18N = { close: __( 'Close', 'dev3-ai-popup-builder' ), copy: __( 'Copy code', 'dev3-ai-popup-builder' ), submit: __( 'Submit', 'dev3-ai-popup-builder' ), choose: __( 'Choose…', 'dev3-ai-popup-builder' ) };

/**
 * Live preview using the real front-end renderer + stylesheet, inside a mock page.
 * The frame has a transform, so the overlays' position: fixed is relative to it.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.campaign   Campaign being edited.
 * @param {string}  props.variantId  Variant to show.
 * @param {string}  props.device     desktop|mobile.
 * @param {boolean} props.mobileSafe Mobile-safe mode setting.
 * @param {boolean} props.success    Show the success state.
 * @param {boolean} props.thumb      Thumbnail mode (scaled down, inert).
 * @return {Element} Preview.
 */
export default function Preview( { campaign, variantId, device = 'desktop', mobileSafe = true, success = false, thumb = false } ) {
	const slot = useRef( null );
	const outer = useRef( null );
	const [ scale, setScale ] = useState( 1 );
	const mobile = device === 'mobile';
	// Virtual viewport, scaled down to the available width so the preview matches a real screen.
	let frameW = mobile ? 390 : 1100;
	let frameH = mobile ? 760 : 720;
	if ( thumb ) {
		frameW = 960;
		frameH = 600;
	}

	useLayoutEffect( () => {
		if ( ! outer.current ) {
			return undefined;
		}
		const measure = () => outer.current && setScale( Math.min( 1, ( outer.current.clientWidth - ( thumb ? 0 : 26 ) ) / frameW ) || 0.3 );
		measure();
		if ( typeof window.ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const ro = new window.ResizeObserver( measure );
		ro.observe( outer.current );
		return () => ro.disconnect();
	}, [ thumb, frameW ] );
	const frameStyle = { width: frameW, height: frameH, transform: `scale(${ scale })` };

	useEffect( () => {
		const el = slot.current;
		if ( ! el ) {
			return;
		}
		el.textContent = '';
		const pub = toPublic( campaign );
		const variant = pub.variants.find( ( v ) => v.id === variantId ) || pub.variants[ 0 ];
		const clickOnly = ( campaign.config.triggers || [] ).every( ( t ) => t.type === 'click' );
		const sheet = mobile && mobileSafe && ! clickOnly && ( campaign.type === 'popup' || campaign.type === 'fullscreen' );
		const { root } = renderCampaign( pub, variant, { doc: el.ownerDocument, mobile, sheet, i18n: I18N } );
		root.addEventListener( 'submit', ( e ) => e.preventDefault() );
		root.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( 'a' ) ) {
				e.preventDefault();
			}
		} );
		if ( success ) {
			showSuccess( root, { code: campaign.config.offer.type !== 'none' ? campaign.config.offer.code || `${ campaign.config.offer.prefix || 'SAVE' }-X7K2Q9` : '' }, I18N );
		}
		if ( thumb ) {
			root.setAttribute( 'inert', '' );
		}
		el.appendChild( root );
	}, [ campaign, variantId, mobile, mobileSafe, success, thumb ] );

	const inline = campaign.type === 'inline';
	const page = (
		<div className={ `dev3pu-pv-page${ inline ? ' is-inline' : '' }` } aria-hidden={ inline ? undefined : 'true' }>
			<div className="dev3pu-pv-nav">
				<span className="dev3pu-pv-logo" />
				<span className="dev3pu-pv-link" />
				<span className="dev3pu-pv-link" />
				<span className="dev3pu-pv-link" />
			</div>
			<div className="dev3pu-pv-article">
				<span className="dev3pu-pv-h" />
				<span className="dev3pu-pv-l" />
				<span className="dev3pu-pv-l" />
				<span className="dev3pu-pv-l is-short" />
				{ inline && <div ref={ slot } className="dev3pu-pv-inline" /> }
				<span className="dev3pu-pv-l" />
				<span className="dev3pu-pv-l is-short" />
			</div>
		</div>
	);
	const frame = (
		<div className={ `dev3pu-pv-frame is-${ device }` } style={ frameStyle }>
			{ page }
			{ ! inline && <div ref={ slot } className="dev3pu-pv-overlay" /> }
		</div>
	);
	return (
		<div ref={ outer } className={ thumb ? 'dev3pu-pv-thumb' : `dev3pu-pv-stage is-${ device }` } aria-hidden={ thumb ? 'true' : undefined }>
			<div className="dev3pu-pv-fit" style={ { width: frameW * scale, height: frameH * scale } }>
				{ frame }
			</div>
		</div>
	);
}

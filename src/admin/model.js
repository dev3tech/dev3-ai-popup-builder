/**
 * Admin-side helpers for the campaign config (mirrors includes/Campaign/Model.php).
 */
import { __ } from '@wordpress/i18n';

export const TYPE_LABELS = {
	popup: __( 'Popup', 'dev3-ai-popup-builder' ),
	slidein: __( 'Slide-in', 'dev3-ai-popup-builder' ),
	bar: __( 'Floating bar', 'dev3-ai-popup-builder' ),
	fullscreen: __( 'Full screen', 'dev3-ai-popup-builder' ),
	inline: __( 'Inline form', 'dev3-ai-popup-builder' ),
};

export const STATUS_TONES = { active: 'success', draft: 'neutral', paused: 'warning', archived: 'neutral' };
export const STATUS_LABELS = {
	active: __( 'Active', 'dev3-ai-popup-builder' ),
	draft: __( 'Draft', 'dev3-ai-popup-builder' ),
	paused: __( 'Paused', 'dev3-ai-popup-builder' ),
	archived: __( 'Archived', 'dev3-ai-popup-builder' ),
};

export const TRIGGER_LABELS = {
	load: __( 'On page load', 'dev3-ai-popup-builder' ),
	time: __( 'After time on page', 'dev3-ai-popup-builder' ),
	scroll: __( 'After scrolling', 'dev3-ai-popup-builder' ),
	exit: __( 'Exit intent', 'dev3-ai-popup-builder' ),
	click: __( 'On click', 'dev3-ai-popup-builder' ),
	inactivity: __( 'After inactivity', 'dev3-ai-popup-builder' ),
};

export const TRIGGER_DEFAULTS = { load: 0, time: 8, scroll: 50, exit: 20, click: '', inactivity: 30 };

export const PAGE_RULE_LABELS = {
	front_page: __( 'Front page', 'dev3-ai-popup-builder' ),
	post_type: __( 'Post type', 'dev3-ai-popup-builder' ),
	ids: __( 'Specific posts/pages (IDs)', 'dev3-ai-popup-builder' ),
	term: __( 'Category / tag', 'dev3-ai-popup-builder' ),
	url: __( 'URL contains / matches', 'dev3-ai-popup-builder' ),
	search: __( 'Search results', 'dev3-ai-popup-builder' ),
	404: __( '404 page', 'dev3-ai-popup-builder' ),
	woo: __( 'WooCommerce page', 'dev3-ai-popup-builder' ),
};

export const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

/**
 * Sets a nested value immutably: setIn( obj, [ 'a', 0, 'b' ], 1 ).
 *
 * @param {*}     obj   Source.
 * @param {Array} path  Path.
 * @param {*}     value Value.
 * @return {*} Copy.
 */
export function setIn( obj, path, value ) {
	if ( ! path.length ) {
		return value;
	}
	const [ key, ...rest ] = path;
	const copy = Array.isArray( obj ) ? obj.slice() : { ...( obj || {} ) };
	copy[ key ] = setIn( copy[ key ], rest, value );
	return copy;
}

/**
 * Next free variant id.
 *
 * @param {Array} variants Variants.
 * @return {string|null} Id or null when 4 exist.
 */
export function nextVariantId( variants ) {
	const used = ( variants || [] ).map( ( v ) => v.id );
	return [ 'a', 'b', 'c', 'd' ].find( ( id ) => ! used.includes( id ) ) || null;
}

/**
 * Adds a variant copied from the control (optionally with new content).
 *
 * @param {Object} config  Config.
 * @param {Object} content Content overrides.
 * @param {boolean} ai     Written by DEV3 AI.
 * @return {Object} Config.
 */
export function addVariant( config, content = {}, ai = false ) {
	const id = nextVariantId( config.variants );
	if ( ! id ) {
		return config;
	}
	const base = clone( config.variants[ 0 ] );
	const variant = { ...base, id, name: id.toUpperCase(), weight: 50, ai, content: { ...base.content, ...content } };
	const variants = config.variants.map( ( v, i ) => ( i === 0 && config.variants.length === 1 ? { ...v, weight: 50 } : v ) ).concat( [ variant ] );
	return { ...config, variants, ab: { ...config.ab, enabled: true, winner: '' } };
}

/**
 * Removes a variant (never the control).
 *
 * @param {Object} config Config.
 * @param {string} id     Variant id.
 * @return {Object} Config.
 */
export function removeVariant( config, id ) {
	if ( id === config.variants[ 0 ].id ) {
		return config;
	}
	const variants = config.variants.filter( ( v ) => v.id !== id );
	return { ...config, variants, ab: { ...config.ab, enabled: variants.length > 1 && config.ab.enabled } };
}

/**
 * Public-shaped campaign for the live preview renderer.
 *
 * @param {Object} campaign Campaign being edited.
 * @return {Object} Public campaign.
 */
export function toPublic( campaign ) {
	return { id: campaign.id || 0, type: campaign.type, form: campaign.config.form, variants: campaign.config.variants };
}

/**
 * Plain-language summary of triggers.
 *
 * @param {Array} triggers Triggers.
 * @return {string} Summary.
 */
export function triggerSummary( triggers ) {
	return ( triggers || [] )
		.map( ( t ) => {
			switch ( t.type ) {
				case 'time':
					/* translators: %d: seconds */
					return __( 'after %ds', 'dev3-ai-popup-builder' ).replace( '%d', t.value );
				case 'scroll':
					/* translators: %d: percent */
					return __( 'at %d%% scroll', 'dev3-ai-popup-builder' ).replace( '%d%%', `${ t.value }%` );
				case 'inactivity':
					/* translators: %d: seconds */
					return __( 'after %ds idle', 'dev3-ai-popup-builder' ).replace( '%d', t.value );
				default:
					return ( TRIGGER_LABELS[ t.type ] || t.type ).toLowerCase();
			}
		} )
		.join( ', ' );
}

export const pct = ( n ) => `${ Number( n || 0 ).toFixed( 1 ) }%`;

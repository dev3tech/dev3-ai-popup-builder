/**
 * Pure targeting / frequency / A/B logic (no DOM). Shared by the front end and unit tests.
 */

export const PHONE_MAX = 600;
export const TABLET_MAX = 1024;

const SEARCH = /(^|\.)(google|bing|duckduckgo|yahoo|baidu|yandex|ecosia|startpage|qwant|brave)\./i;
const SOCIAL = /(^|\.)(facebook|fb|instagram|t\.co|twitter|x\.com|linkedin|lnkd|pinterest|reddit|tiktok|youtube|threads|bsky|mastodon|whatsapp|telegram|snapchat)/i;

/**
 * Device class from the viewport width.
 *
 * @param {number} width Viewport width.
 * @return {string} desktop|tablet|mobile
 */
export function deviceFor( width ) {
	if ( width <= PHONE_MAX ) {
		return 'mobile';
	}
	return width <= TABLET_MAX ? 'tablet' : 'desktop';
}

/**
 * Host of a URL ('' when empty/invalid).
 *
 * @param {string} url URL.
 * @return {string} Host.
 */
export function hostOf( url ) {
	if ( ! url ) {
		return '';
	}
	const m = String( url ).match( /^[a-z][a-z0-9+.-]*:\/\/([^/?#:]+)/i );
	return m ? m[ 1 ].toLowerCase() : '';
}

/**
 * Referrer class.
 *
 * @param {string} referrer Referrer URL.
 * @param {string} ownHost  This site's host.
 * @return {string} direct|internal|search|social|other
 */
export function classifyReferrer( referrer, ownHost ) {
	const host = hostOf( referrer );
	if ( ! host ) {
		return 'direct';
	}
	if ( ownHost && host === String( ownHost ).toLowerCase() ) {
		return 'internal';
	}
	if ( SEARCH.test( host ) ) {
		return 'search';
	}
	return SOCIAL.test( host ) ? 'social' : 'other';
}

/**
 * Referrer rule.
 *
 * @param {Object} rule     { mode, value }.
 * @param {string} referrer First external referrer of the session.
 * @param {string} ownHost  Own host.
 * @return {boolean} Match.
 */
export function matchReferrer( rule, referrer, ownHost ) {
	const mode = ( rule && rule.mode ) || 'any';
	const value = String( ( rule && rule.value ) || '' ).toLowerCase();
	const ref = String( referrer || '' ).toLowerCase();
	switch ( mode ) {
		case 'contains':
			return !! value && ref.includes( value );
		case 'not_contains':
			return ! value || ! ref.includes( value );
		case 'search':
		case 'social':
		case 'direct':
			return classifyReferrer( referrer, ownHost ) === mode;
		default:
			return true;
	}
}

/**
 * UTM parameters from a query string.
 *
 * @param {string} search location.search.
 * @return {Object} utm_* => value.
 */
export function parseUtm( search ) {
	const out = {};
	String( search || '' )
		.replace( /^\?/, '' )
		.split( '&' )
		.forEach( ( pair ) => {
			const [ k, v = '' ] = pair.split( '=' );
			if ( /^utm_(source|medium|campaign|term|content)$/.test( k ) ) {
				try {
					out[ k ] = decodeURIComponent( v.replace( /\+/g, ' ' ) ).slice( 0, 100 );
				} catch ( e ) {
					out[ k ] = v.slice( 0, 100 );
				}
			}
		} );
	return out;
}

/**
 * All UTM rules must match.
 *
 * @param {Array}  rules [ { key, op, value } ].
 * @param {Object} utm   Session UTMs.
 * @return {boolean} Match.
 */
export function matchUtm( rules, utm ) {
	return ( rules || [] ).every( ( r ) => {
		const have = String( ( utm && utm[ r.key ] ) || '' ).toLowerCase();
		const want = String( r.value || '' ).toLowerCase();
		return r.op === 'contains' ? have.includes( want ) : have === want;
	} );
}

/**
 * Cart cookie "subtotal|items".
 *
 * @param {string} cookie document.cookie.
 * @return {Object|null} { total, items }
 */
export function parseCart( cookie ) {
	const m = String( cookie || '' ).match( /(?:^|;\s*)dev3pu_cart=([^;]*)/ );
	if ( ! m ) {
		return null;
	}
	const [ total, items ] = decodeURIComponent( m[ 1 ] ).split( '|' );
	return { total: parseFloat( total ) || 0, items: parseInt( items, 10 ) || 0 };
}

/**
 * WooCommerce cart rule.
 *
 * @param {Object}      rule { enabled, min, max, not_empty }.
 * @param {Object|null} cart Cart.
 * @return {boolean} Match.
 */
export function matchCart( rule, cart ) {
	if ( ! rule || ! rule.enabled ) {
		return true;
	}
	const c = cart || { total: 0, items: 0 };
	if ( rule.not_empty && c.items < 1 ) {
		return false;
	}
	if ( rule.min > 0 && c.total < rule.min ) {
		return false;
	}
	return ! ( rule.max > 0 && c.total > rule.max );
}

/**
 * Client-side targeting.
 *
 * @param {Object} t   Targeting.
 * @param {Object} ctx { device, referrer, host, utm, loggedIn, returning, pages, cart }.
 * @return {boolean} Match.
 */
export function matchTargeting( t, ctx ) {
	if ( ! t ) {
		return true;
	}
	if ( t.devices && t.devices.length && ! t.devices.includes( ctx.device ) ) {
		return false;
	}
	if ( t.login === 'in' && ! ctx.loggedIn ) {
		return false;
	}
	if ( t.login === 'out' && ctx.loggedIn ) {
		return false;
	}
	if ( t.visitor === 'new' && ctx.returning ) {
		return false;
	}
	if ( t.visitor === 'returning' && ! ctx.returning ) {
		return false;
	}
	if ( t.min_pages > 0 && ( ctx.pages || 0 ) < t.min_pages ) {
		return false;
	}
	if ( ! matchReferrer( t.referrer, ctx.referrer, ctx.host ) ) {
		return false;
	}
	if ( ! matchUtm( t.utm, ctx.utm ) ) {
		return false;
	}
	return matchCart( t.cart, ctx.cart );
}

const DAY = 86400000;

/**
 * Frequency caps.
 *
 * @param {Object} f       { mode, days, max, close_days, converted, converted_days }.
 * @param {Object} rec     Stored record { v: views, l: last shown, c: closed at, x: converted at, s: session id }.
 * @param {number} now     Now (ms).
 * @param {string} session Current session id.
 * @return {boolean} May show.
 */
export function frequencyAllows( f, rec, now, session ) {
	const r = rec || {};
	if ( r.x ) {
		if ( ! f || f.converted !== 'days' ) {
			return false;
		}
		if ( now - r.x < ( f.converted_days || 90 ) * DAY ) {
			return false;
		}
	}
	if ( r.c && f && f.close_days > 0 && now - r.c < f.close_days * DAY ) {
		return false;
	}
	const mode = ( f && f.mode ) || 'session';
	switch ( mode ) {
		case 'always':
			return true;
		case 'session':
			return r.s !== session;
		case 'days':
			return ! r.l || now - r.l >= ( f.days || 1 ) * DAY;
		case 'max':
			return ( r.v || 0 ) < ( f.max || 1 );
	}
	return true;
}

/**
 * Sticky weighted variant choice.
 *
 * @param {Array}  variants [ { id, weight } ].
 * @param {string} stored   Previously assigned id.
 * @param {number} rand     Random number in [0,1).
 * @return {string} Variant id.
 */
export function pickVariant( variants, stored, rand ) {
	const list = variants || [];
	if ( ! list.length ) {
		return 'a';
	}
	if ( stored && list.some( ( v ) => v.id === stored ) ) {
		return stored;
	}
	const total = list.reduce( ( s, v ) => s + Math.max( 0, v.weight || 0 ), 0 );
	if ( total <= 0 ) {
		return list[ 0 ].id;
	}
	let x = rand * total;
	for ( const v of list ) {
		x -= Math.max( 0, v.weight || 0 );
		if ( x < 0 ) {
			return v.id;
		}
	}
	return list[ list.length - 1 ].id;
}

/**
 * Whether a modal format is shown as a non-blocking bottom sheet (mobile-safe mode).
 *
 * @param {Object}  campaign Campaign.
 * @param {string}  device   Device.
 * @param {Object}  settings { mobileSafe }.
 * @param {boolean} viaClick Opened by the visitor.
 * @return {boolean} Sheet.
 */
export function useSheet( campaign, device, settings, viaClick ) {
	return !! ( settings && settings.mobileSafe ) && device === 'mobile' && ! viaClick && ( campaign.type === 'popup' || campaign.type === 'fullscreen' );
}

/**
 * Triggers adjusted for the device: on phones, mobile-safe mode turns "on load" into
 * "after 10 s or 40% scroll", and exit intent becomes a fast scroll up (or is skipped).
 *
 * @param {Object} campaign Campaign.
 * @param {string} device   Device.
 * @param {Object} settings Settings.
 * @return {Array} Triggers.
 */
export function effectiveTriggers( campaign, device, settings ) {
	const out = [];
	( campaign.triggers || [] ).forEach( ( t ) => {
		if ( device === 'mobile' && t.type === 'exit' ) {
			if ( campaign.exit_mobile !== 'off' ) {
				out.push( { type: 'scroll_up', value: 0 } );
			}
			return;
		}
		if ( device === 'mobile' && settings && settings.mobileSafe && ( t.type === 'load' || ( t.type === 'time' && t.value < 10 ) ) && campaign.type !== 'bar' ) {
			out.push( { type: 'time', value: 10 } );
			out.push( { type: 'scroll', value: 40 } );
			return;
		}
		out.push( t );
	} );
	return out;
}

/**
 * Campaigns by priority (high first), then id.
 *
 * @param {Array} list Campaigns.
 * @return {Array} Sorted copy.
 */
export function byPriority( list ) {
	return ( list || [] ).slice().sort( ( a, b ) => ( b.priority || 0 ) - ( a.priority || 0 ) || a.id - b.id );
}

/**
 * Global guard: at most N overlays per page view and a minimum gap between overlays.
 *
 * @param {Object} settings { maxPerView, minGap }.
 * @param {number} shown    Overlays shown in this page view.
 * @param {number} lastAt   Last overlay time in this session (ms).
 * @param {number} now      Now.
 * @return {boolean} Allowed.
 */
export function guardAllows( settings, shown, lastAt, now ) {
	const max = settings && settings.maxPerView !== undefined ? settings.maxPerView : 1;
	if ( max > 0 && shown >= max ) {
		return false;
	}
	const gap = ( ( settings && settings.minGap ) || 0 ) * 1000;
	return ! ( gap > 0 && lastAt && now - lastAt < gap );
}

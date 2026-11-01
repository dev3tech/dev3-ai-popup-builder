import { byPriority, classifyReferrer, deviceFor, effectiveTriggers, frequencyAllows, guardAllows, hostOf, matchCart, matchReferrer, matchTargeting, matchUtm, parseCart, parseUtm, pickVariant, useSheet } from '../../src/frontend/engine';

const DAY = 86400000;

describe( 'device + referrer', () => {
	it( 'classifies viewport widths', () => {
		expect( deviceFor( 375 ) ).toBe( 'mobile' );
		expect( deviceFor( 600 ) ).toBe( 'mobile' );
		expect( deviceFor( 768 ) ).toBe( 'tablet' );
		expect( deviceFor( 1440 ) ).toBe( 'desktop' );
	} );
	it( 'classifies referrers', () => {
		expect( hostOf( 'https://www.Google.com/search?q=x' ) ).toBe( 'www.google.com' );
		expect( classifyReferrer( '', 'shop.test' ) ).toBe( 'direct' );
		expect( classifyReferrer( 'https://shop.test/a', 'shop.test' ) ).toBe( 'internal' );
		expect( classifyReferrer( 'https://www.google.de/', 'shop.test' ) ).toBe( 'search' );
		expect( classifyReferrer( 'https://duckduckgo.com/', 'shop.test' ) ).toBe( 'search' );
		expect( classifyReferrer( 'https://l.facebook.com/x', 'shop.test' ) ).toBe( 'social' );
		expect( classifyReferrer( 'https://t.co/abc', 'shop.test' ) ).toBe( 'social' );
		expect( classifyReferrer( 'https://partner.example/', 'shop.test' ) ).toBe( 'other' );
	} );
	it( 'matches referrer rules', () => {
		expect( matchReferrer( { mode: 'any' }, '', 'a' ) ).toBe( true );
		expect( matchReferrer( { mode: 'contains', value: 'partner' }, 'https://partner.example/', 'a' ) ).toBe( true );
		expect( matchReferrer( { mode: 'contains', value: 'partner' }, '', 'a' ) ).toBe( false );
		expect( matchReferrer( { mode: 'not_contains', value: 'partner' }, 'https://partner.example/', 'a' ) ).toBe( false );
		expect( matchReferrer( { mode: 'search' }, 'https://bing.com/', 'a' ) ).toBe( true );
		expect( matchReferrer( { mode: 'direct' }, 'https://bing.com/', 'a' ) ).toBe( false );
	} );
} );

describe( 'utm + cart', () => {
	it( 'parses and matches UTM tags', () => {
		const utm = parseUtm( '?utm_source=News+letter&utm_campaign=spring%20sale&x=1' );
		expect( utm ).toEqual( { utm_source: 'News letter', utm_campaign: 'spring sale' } );
		expect( matchUtm( [ { key: 'utm_source', op: 'equals', value: 'news letter' } ], utm ) ).toBe( true );
		expect( matchUtm( [ { key: 'utm_campaign', op: 'contains', value: 'spring' } ], utm ) ).toBe( true );
		expect( matchUtm( [ { key: 'utm_medium', op: 'equals', value: 'email' } ], utm ) ).toBe( false );
		expect( matchUtm( [], {} ) ).toBe( true );
	} );
	it( 'reads the cart cookie and applies cart rules', () => {
		expect( parseCart( 'a=1; dev3pu_cart=42.50%7C3; b=2' ) ).toEqual( { total: 42.5, items: 3 } );
		expect( parseCart( 'a=1' ) ).toBeNull();
		const rule = { enabled: true, min: 30, max: 100, not_empty: true };
		expect( matchCart( rule, { total: 42.5, items: 3 } ) ).toBe( true );
		expect( matchCart( rule, { total: 12, items: 1 } ) ).toBe( false );
		expect( matchCart( rule, { total: 120, items: 1 } ) ).toBe( false );
		expect( matchCart( rule, null ) ).toBe( false );
		expect( matchCart( { enabled: false }, null ) ).toBe( true );
	} );
} );

describe( 'targeting', () => {
	const ctx = { device: 'desktop', referrer: '', host: 'shop.test', utm: {}, loggedIn: false, returning: false, pages: 1, cart: null };
	it( 'checks device, login, visitor and pages', () => {
		expect( matchTargeting( { devices: [ 'desktop' ] }, ctx ) ).toBe( true );
		expect( matchTargeting( { devices: [ 'mobile' ] }, ctx ) ).toBe( false );
		expect( matchTargeting( { login: 'in' }, ctx ) ).toBe( false );
		expect( matchTargeting( { login: 'out' }, ctx ) ).toBe( true );
		expect( matchTargeting( { visitor: 'returning' }, ctx ) ).toBe( false );
		expect( matchTargeting( { visitor: 'new' }, ctx ) ).toBe( true );
		expect( matchTargeting( { min_pages: 3 }, ctx ) ).toBe( false );
		expect( matchTargeting( { min_pages: 3 }, { ...ctx, pages: 3 } ) ).toBe( true );
		expect( matchTargeting( null, ctx ) ).toBe( true );
	} );
} );

describe( 'frequency', () => {
	const now = 1000 * DAY;
	it( 'never shows again after a conversion by default', () => {
		expect( frequencyAllows( { mode: 'always' }, { x: now - DAY }, now, 's' ) ).toBe( false );
		expect( frequencyAllows( { mode: 'always', converted: 'days', converted_days: 30 }, { x: now - 40 * DAY }, now, 's' ) ).toBe( true );
	} );
	it( 'hides after close for N days', () => {
		expect( frequencyAllows( { mode: 'always', close_days: 3 }, { c: now - DAY }, now, 's' ) ).toBe( false );
		expect( frequencyAllows( { mode: 'always', close_days: 3 }, { c: now - 4 * DAY }, now, 's' ) ).toBe( true );
	} );
	it( 'handles session, days and max modes', () => {
		expect( frequencyAllows( { mode: 'session' }, { s: 's1' }, now, 's1' ) ).toBe( false );
		expect( frequencyAllows( { mode: 'session' }, { s: 's1' }, now, 's2' ) ).toBe( true );
		expect( frequencyAllows( { mode: 'days', days: 7 }, { l: now - 2 * DAY }, now, 's' ) ).toBe( false );
		expect( frequencyAllows( { mode: 'days', days: 7 }, { l: now - 8 * DAY }, now, 's' ) ).toBe( true );
		expect( frequencyAllows( { mode: 'max', max: 2 }, { v: 2 }, now, 's' ) ).toBe( false );
		expect( frequencyAllows( { mode: 'max', max: 2 }, { v: 1 }, now, 's' ) ).toBe( true );
		expect( frequencyAllows( { mode: 'days', days: 7 }, {}, now, 's' ) ).toBe( true );
	} );
} );

describe( 'variants, sheet, triggers, guard', () => {
	const variants = [
		{ id: 'a', weight: 50 },
		{ id: 'b', weight: 50 },
	];
	it( 'picks weighted, sticky variants', () => {
		expect( pickVariant( variants, '', 0.1 ) ).toBe( 'a' );
		expect( pickVariant( variants, '', 0.9 ) ).toBe( 'b' );
		expect( pickVariant( variants, 'b', 0.1 ) ).toBe( 'b' );
		expect( pickVariant( variants, 'z', 0.1 ) ).toBe( 'a' );
		expect( pickVariant( [ { id: 'a', weight: 0 }, { id: 'b', weight: 0 } ], '', 0.9 ) ).toBe( 'a' );
		expect( pickVariant( [ { id: 'a', weight: 0 }, { id: 'b', weight: 100 } ], '', 0.01 ) ).toBe( 'b' );
	} );
	it( 'turns modal formats into a sheet on phones in mobile-safe mode', () => {
		const popup = { type: 'popup' };
		expect( useSheet( popup, 'mobile', { mobileSafe: true }, false ) ).toBe( true );
		expect( useSheet( popup, 'mobile', { mobileSafe: true }, true ) ).toBe( false );
		expect( useSheet( popup, 'desktop', { mobileSafe: true }, false ) ).toBe( false );
		expect( useSheet( popup, 'mobile', { mobileSafe: false }, false ) ).toBe( false );
		expect( useSheet( { type: 'slidein' }, 'mobile', { mobileSafe: true }, false ) ).toBe( false );
	} );
	it( 'adapts triggers for phones', () => {
		const c = { type: 'popup', exit_mobile: 'scroll_up', triggers: [ { type: 'load', value: 0 }, { type: 'exit', value: 20 }, { type: 'scroll', value: 60 } ] };
		expect( effectiveTriggers( c, 'desktop', { mobileSafe: true } ) ).toEqual( c.triggers );
		expect( effectiveTriggers( c, 'mobile', { mobileSafe: true } ) ).toEqual( [
			{ type: 'time', value: 10 },
			{ type: 'scroll', value: 40 },
			{ type: 'scroll_up', value: 0 },
			{ type: 'scroll', value: 60 },
		] );
		expect( effectiveTriggers( { ...c, exit_mobile: 'off', triggers: [ { type: 'exit', value: 20 } ] }, 'mobile', { mobileSafe: true } ) ).toEqual( [] );
		expect( effectiveTriggers( { type: 'bar', triggers: [ { type: 'load', value: 0 } ] }, 'mobile', { mobileSafe: true } ) ).toEqual( [ { type: 'load', value: 0 } ] );
	} );
	it( 'limits overlays per view and spaces them out', () => {
		expect( guardAllows( { maxPerView: 1, minGap: 0 }, 0, 0, 1000 ) ).toBe( true );
		expect( guardAllows( { maxPerView: 1, minGap: 0 }, 1, 0, 1000 ) ).toBe( false );
		expect( guardAllows( { maxPerView: 0, minGap: 30 }, 5, 1000, 20000 ) ).toBe( false );
		expect( guardAllows( { maxPerView: 0, minGap: 30 }, 5, 1000, 40000 ) ).toBe( true );
	} );
	it( 'orders by priority', () => {
		expect( byPriority( [ { id: 2, priority: 1 }, { id: 1, priority: 5 }, { id: 3, priority: 5 } ] ).map( ( c ) => c.id ) ).toEqual( [ 1, 3, 2 ] );
	} );
} );

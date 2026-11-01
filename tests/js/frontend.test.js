/**
 * Renderer + runtime (jsdom).
 */
import { appendSafeHtml, renderCampaign, showSuccess } from '../../src/frontend/render';
import { createRuntime } from '../../src/frontend/runtime';

const variant = ( id = 'a', extra = {} ) => ( {
	id,
	weight: 50,
	content: { eyebrow: 'Hi', headline: `Headline ${ id }`, body: 'Body <strong>bold</strong> <script>alert(1)</script><a href="javascript:x">bad</a>', button: 'Join', decline: 'No thanks', success_title: 'Thanks', success_message: 'Done', image_url: '', image_alt: '', ...extra },
	design: { layout: 'stacked', size: 'md', align: 'center', bg: '#ffffff', text: '#111111', accent: '#4f46e5', button_text: '#ffffff', radius: 12, overlay: 50, animation: 'none', position: 'bottom-right' },
} );
const form = { mode: 'form', fields: [ { key: 'email', type: 'email', label: 'Email', placeholder: '', required: true, options: [] } ], consent: { enabled: true, label: 'I agree', required: true }, cta_url: '', cta_new_tab: false };
const campaign = ( extra = {} ) => ( { id: 7, type: 'popup', priority: 10, variants: [ variant() ], form, triggers: [ { type: 'time', value: 2 } ], exit_mobile: 'scroll_up', targeting: { devices: [ 'desktop', 'tablet', 'mobile' ] }, frequency: { mode: 'session', close_days: 0, converted: 'never' }, preview: false, ...extra } );

describe( 'renderer', () => {
	it( 'renders an accessible modal popup', () => {
		const { root, box } = renderCampaign( campaign(), variant(), { doc: document } );
		expect( root.className ).toContain( 'dev3pu-w--popup' );
		expect( box.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( box.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		const title = root.querySelector( '.dev3pu-title' );
		expect( box.getAttribute( 'aria-labelledby' ) ).toBe( title.id );
		expect( root.querySelector( '.dev3pu-backdrop' ) ).not.toBeNull();
		expect( root.querySelector( '.dev3pu-x' ).getAttribute( 'aria-label' ) ).toBe( 'Close' );
		const input = root.querySelector( 'input[type="email"]' );
		expect( root.querySelector( `label[for="${ input.id }"]` ) ).not.toBeNull();
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( `${ input.id }-e` );
		expect( root.querySelector( '[name="dev3pu_consent"]' ).required ).toBe( true );
		expect( root.querySelector( '[name="dev3pu_hp"]' ).tabIndex ).toBe( -1 );
		expect( root.getAttribute( 'style' ) ).toContain( '--dev3pu-pacc:#4f46e5' );
	} );
	it( 'sanitises body html', () => {
		const { root } = renderCampaign( campaign(), variant(), { doc: document } );
		const text = root.querySelector( '.dev3pu-text' );
		expect( text.querySelector( 'strong' ).textContent ).toBe( 'bold' );
		expect( text.querySelector( 'script' ) ).toBeNull();
		expect( text.querySelector( 'a' ).hasAttribute( 'href' ) ).toBe( false );
		const p = document.createElement( 'p' );
		appendSafeHtml( p, 'a\nb <a href="https://x.test" target="_blank" onclick="x()">l</a>' );
		expect( p.querySelector( 'br' ) ).not.toBeNull();
		expect( p.querySelector( 'a' ).getAttribute( 'rel' ) ).toBe( 'noopener' );
		expect( p.querySelector( 'a' ).hasAttribute( 'onclick' ) ).toBe( false );
	} );
	it( 'renders a non-modal sheet, bar region and inline section', () => {
		const sheet = renderCampaign( campaign(), variant(), { doc: document, sheet: true, mobile: true } );
		expect( sheet.root.className ).toContain( 'dev3pu-w--sheet' );
		expect( sheet.root.className ).toContain( 'dev3pu-m' );
		expect( sheet.box.hasAttribute( 'aria-modal' ) ).toBe( false );
		expect( sheet.root.querySelector( '.dev3pu-backdrop' ) ).toBeNull();
		const bar = renderCampaign( campaign( { type: 'bar', form: { ...form, mode: 'cta', cta_url: '/shop/' } } ), variant(), { doc: document } );
		expect( bar.box.getAttribute( 'role' ) ).toBe( 'region' );
		expect( bar.root.querySelector( 'a.dev3pu-btn' ).getAttribute( 'href' ) ).toBe( '/shop/' );
		expect( bar.root.className ).toContain( 'dev3pu-pos--top' );
		const inline = renderCampaign( campaign( { type: 'inline' } ), variant(), { doc: document } );
		expect( inline.box.tagName ).toBe( 'SECTION' );
		expect( inline.root.querySelector( '.dev3pu-x' ) ).toBeNull();
		expect( inline.root.querySelector( '.dev3pu-decline' ) ).toBeNull();
		expect( inline.root.hasAttribute( 'data-dev3pu-inline' ) ).toBe( true );
	} );
	it( 'falls back to the stacked layout without an image', () => {
		const v = variant();
		v.design.layout = 'image-left';
		expect( renderCampaign( campaign(), v, { doc: document } ).root.className ).toContain( 'dev3pu-l--stacked' );
		const w = variant( 'a', { image_url: 'https://x.test/i.png' } );
		w.design.layout = 'image-left';
		const r = renderCampaign( campaign(), w, { doc: document } ).root;
		expect( r.className ).toContain( 'dev3pu-l--image-left' );
		expect( r.querySelector( 'img' ).getAttribute( 'width' ) ).toBe( '400' );
	} );
	it( 'shows the success state with a code', () => {
		const { root } = renderCampaign( campaign(), variant(), { doc: document } );
		const done = showSuccess( root, { title: 'Yay', message: 'Here', code: 'SAVE10' }, { copy: 'Copy' } );
		expect( done.hidden ).toBe( false );
		expect( root.classList.contains( 'dev3pu-is-done' ) ).toBe( true );
		expect( done.querySelector( 'code' ).textContent ).toBe( 'SAVE10' );
		expect( root.querySelector( '.dev3pu-box' ).getAttribute( 'aria-labelledby' ) ).toBe( done.querySelector( '.dev3pu-title' ).id );
	} );
} );

describe( 'runtime', () => {
	let fetchMock;
	const boot = ( cfgExtra = {}, width = 1280 ) => {
		window.innerWidth = width;
		return createRuntime( { rest: '/wp-json/dev3pu/v1/public/', nonce: '', campaigns: [ campaign() ], settings: { mobileSafe: true, maxPerView: 1, minGap: 0, tracking: true }, i18n: { close: 'Close', email: 'Bad email', required: 'Required', consent: 'Tick it', sending: 'Sending' }, ...cfgExtra }, window );
	};
	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = '<button id="before">Before</button>';
		document.documentElement.className = '';
		localStorage.clear();
		sessionStorage.clear();
		window.requestAnimationFrame = ( fn ) => fn();
		fetchMock = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { ok: true, title: 'Thanks!', message: 'Check inbox', code: 'WELCOME10', redirect: '' } ) } ) );
		window.fetch = fetchMock;
	} );
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'creates no DOM until a trigger fires, then opens a trapped modal', () => {
		document.getElementById( 'before' ).focus();
		boot();
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
		jest.advanceTimersByTime( 2100 );
		const root = document.querySelector( '.dev3pu-w--popup' );
		expect( root ).not.toBeNull();
		expect( document.documentElement.classList.contains( 'dev3pu-lock' ) ).toBe( true );
		expect( document.activeElement ).toBe( root.querySelector( '.dev3pu-box' ) );
		expect( fetchMock ).toHaveBeenCalledWith( '/wp-json/dev3pu/v1/public/track', expect.objectContaining( { method: 'POST' } ) );
		expect( JSON.parse( fetchMock.mock.calls[ 0 ][ 1 ].body ) ).toEqual( { campaign_id: 7, variant: 'a', event: 'view' } );
	} );

	it( 'closes on Escape, restores focus and records the close', () => {
		document.getElementById( 'before' ).focus();
		boot();
		jest.advanceTimersByTime( 2100 );
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		jest.advanceTimersByTime( 300 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
		expect( document.documentElement.classList.contains( 'dev3pu-lock' ) ).toBe( false );
		expect( document.activeElement.id ).toBe( 'before' );
		const rec = JSON.parse( localStorage.getItem( 'dev3pu_c_7' ) );
		expect( rec.c ).toBeGreaterThan( 0 );
		expect( rec.v ).toBe( 1 );
		expect( JSON.parse( fetchMock.mock.calls[ 1 ][ 1 ].body ).event ).toBe( 'close' );
	} );

	it( 'respects the once-per-visit cap on the next page view', () => {
		boot();
		jest.advanceTimersByTime( 2100 );
		document.querySelector( '.dev3pu-x' ).click();
		jest.advanceTimersByTime( 300 );
		window.dev3puRuntime = null;
		boot();
		jest.advanceTimersByTime( 5000 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
	} );

	it( 'shows a non-blocking bottom sheet on phones and delays the trigger', () => {
		boot( { campaigns: [ campaign( { triggers: [ { type: 'load', value: 0 } ] } ) ] }, 375 );
		jest.advanceTimersByTime( 5000 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
		jest.advanceTimersByTime( 5100 );
		const root = document.querySelector( '.dev3pu-w' );
		expect( root.className ).toContain( 'dev3pu-w--sheet' );
		expect( root.querySelector( '[aria-modal]' ) ).toBeNull();
		expect( document.documentElement.classList.contains( 'dev3pu-lock' ) ).toBe( false );
	} );

	it( 'opens on click of a .dev3pu-open-<id> element even when capped', () => {
		localStorage.setItem( 'dev3pu_c_7', JSON.stringify( { x: Date.now() } ) );
		document.body.insertAdjacentHTML( 'beforeend', '<a href="#dev3pu-open-7" id="opener">Open</a>' );
		boot();
		jest.advanceTimersByTime( 3000 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
		document.getElementById( 'opener' ).click();
		expect( document.querySelector( '.dev3pu-w--popup' ) ).not.toBeNull();
	} );

	it( 'validates, submits and shows the success state with the code', async () => {
		boot();
		jest.advanceTimersByTime( 2100 );
		const root = document.querySelector( '.dev3pu-w' );
		const formEl = root.querySelector( 'form' );
		formEl.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		const email = root.querySelector( 'input[type="email"]' );
		expect( email.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( document.getElementById( email.getAttribute( 'aria-describedby' ) ).textContent ).toBe( 'Bad email' );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		email.value = 'ana@example.com';
		root.querySelector( '[name="dev3pu_consent"]' ).checked = true;
		jest.advanceTimersByTime( 3000 );
		formEl.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		const body = JSON.parse( fetchMock.mock.calls[ 1 ][ 1 ].body );
		expect( body ).toEqual( expect.objectContaining( { campaign_id: 7, variant: 'a', consent: true, hp: '', fields: { email: 'ana@example.com' } } ) );
		expect( body.elapsed ).toBeGreaterThanOrEqual( 3000 );
		jest.useRealTimers();
		await new Promise( ( r ) => setTimeout( r, 0 ) );
		await new Promise( ( r ) => setTimeout( r, 0 ) );
		expect( root.querySelector( '.dev3pu-done' ).hidden ).toBe( false );
		expect( root.querySelector( '.dev3pu-code code' ).textContent ).toBe( 'WELCOME10' );
		expect( JSON.parse( localStorage.getItem( 'dev3pu_c_7' ) ).x ).toBeGreaterThan( 0 );
	} );

	it( 'maps server field errors back to the inputs', async () => {
		fetchMock.mockImplementation( ( url ) =>
			Promise.resolve( url.endsWith( 'submit' ) ? { ok: false, json: () => Promise.resolve( { code: 'dev3pu_invalid', message: 'Check fields', fields: { email: 'Server says no' } } ) } : { ok: true, json: () => Promise.resolve( {} ) } )
		);
		boot( { campaigns: [ campaign( { form: { ...form, consent: { enabled: false } } } ) ] } );
		jest.advanceTimersByTime( 2100 );
		const root = document.querySelector( '.dev3pu-w' );
		root.querySelector( 'input[type="email"]' ).value = 'x@example.com';
		root.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		jest.useRealTimers();
		await new Promise( ( r ) => setTimeout( r, 0 ) );
		await new Promise( ( r ) => setTimeout( r, 0 ) );
		const email = root.querySelector( 'input[type="email"]' );
		expect( document.getElementById( email.getAttribute( 'aria-describedby' ) ).textContent ).toBe( 'Server says no' );
		expect( root.querySelector( '.dev3pu-status' ).textContent ).toBe( 'Check fields' );
	} );

	it( 'skips campaigns whose targeting does not match', () => {
		boot( { campaigns: [ campaign( { targeting: { devices: [ 'mobile' ] } } ) ] } );
		jest.advanceTimersByTime( 5000 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
	} );

	it( 'allows only one overlay per page view', () => {
		boot( { campaigns: [ campaign(), { ...campaign(), id: 8, triggers: [ { type: 'time', value: 3 } ] } ] } );
		jest.advanceTimersByTime( 2100 );
		document.querySelector( '.dev3pu-x' ).click();
		jest.advanceTimersByTime( 2000 );
		expect( document.querySelector( '.dev3pu-w' ) ).toBeNull();
	} );

	it( 'binds server-rendered inline forms', () => {
		document.body.insertAdjacentHTML( 'beforeend', '<div class="dev3pu-w dev3pu-w--inline" data-dev3pu-inline data-dev3pu-id="9" data-dev3pu-variant="b"><section class="dev3pu-box"><form data-dev3pu-form><input type="email" name="email" required aria-describedby="e1"><p id="e1" hidden></p><button type="submit">Go</button><p class="dev3pu-status"></p></form></section></div>' );
		boot( { campaigns: [] } );
		const f = document.querySelector( '[data-dev3pu-inline] form' );
		expect( f.dataset.dev3puBound ).toBe( '1' );
		f.querySelector( 'input' ).value = 'z@example.com';
		f.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		const call = fetchMock.mock.calls.find( ( c ) => c[ 0 ].endsWith( 'submit' ) );
		expect( JSON.parse( call[ 1 ].body ) ).toEqual( expect.objectContaining( { campaign_id: 9, variant: 'b' } ) );
	} );
} );

describe( 'runtime click replacement', () => {
	it( 'replaces an automatic bar when the visitor clicks an opener', () => {
		jest.useFakeTimers();
		document.body.innerHTML = '<button class="dev3pu-open dev3pu-open-7" data-dev3pu-open="7">Open</button>';
		localStorage.clear();
		sessionStorage.clear();
		window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ) );
		window.innerWidth = 1280;
		const bar = { ...campaign( { type: 'bar', triggers: [ { type: 'load', value: 0 } ] } ), id: 3 };
		createRuntime( { rest: '/r/', campaigns: [ bar, campaign() ], settings: { maxPerView: 1, minGap: 0, tracking: false }, i18n: {} }, window );
		jest.advanceTimersByTime( 10 );
		expect( document.querySelector( '.dev3pu-w--bar' ) ).not.toBeNull();
		document.querySelector( '.dev3pu-open' ).click();
		jest.advanceTimersByTime( 300 );
		expect( document.querySelector( '.dev3pu-w--bar' ) ).toBeNull();
		expect( document.querySelector( '.dev3pu-w--popup' ) ).not.toBeNull();
		expect( document.documentElement.classList.contains( 'dev3pu-lock' ) ).toBe( true );
		expect( localStorage.getItem( 'dev3pu_c_3' ) ).not.toContain( '"c"' );
		jest.useRealTimers();
	} );
} );

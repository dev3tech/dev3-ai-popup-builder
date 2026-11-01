import apiFetch from '@wordpress/api-fetch';
import { act } from 'react';
import { ToastProvider } from '../../src/core3/ui';
import Campaigns from '../../src/admin/Campaigns';
import Editor from '../../src/admin/editor/Editor';
import Leads from '../../src/admin/Leads';
import Integrations from '../../src/admin/Integrations';
import { contrast } from '../../src/admin/editor/panes';
import { addVariant, removeVariant, setIn, triggerSummary } from '../../src/admin/model';
import { render } from './render';

const variant = ( id = 'a' ) => ( {
	id,
	name: id.toUpperCase(),
	weight: 100,
	ai: false,
	content: { eyebrow: '', headline: 'Join our list', body: 'Weekly tips.', button: 'Subscribe', decline: 'No thanks', success_title: 'Thanks', success_message: 'Check inbox', image_url: '', image_alt: '' },
	design: { layout: 'stacked', size: 'md', align: 'center', bg: '#ffffff', text: '#1f2937', accent: '#4f46e5', button_text: '#ffffff', radius: 14, overlay: 55, animation: 'fade', position: 'bottom-right' },
} );
const config = () => ( {
	variants: [ variant() ],
	form: { mode: 'form', fields: [ { key: 'email', type: 'email', label: 'Email', placeholder: '', required: true, options: [] } ], consent: { enabled: false, label: 'I agree', required: true }, cta_url: '', cta_new_tab: false },
	success: { action: 'message', redirect_url: '' },
	offer: { type: 'none', code: '', discount_type: 'percent', amount: 10, expiry_days: 14, prefix: 'WELCOME' },
	triggers: [ { type: 'time', value: 8 } ],
	exit_mobile: 'scroll_up',
	targeting: { pages: { mode: 'all', rules: [] }, devices: [ 'desktop', 'tablet', 'mobile' ], referrer: { mode: 'any', value: '' }, utm: [], login: 'any', visitor: 'any', min_pages: 0, cart: { enabled: false, min: 0, max: 0, not_empty: false } },
	frequency: { mode: 'days', days: 7, max: 3, close_days: 3, converted: 'never', converted_days: 90 },
	inline: { placement: 'manual', post_types: [ 'post' ] },
	integrations: { mailchimp: { enabled: false, list_id: '', tags: '', double: false }, brevo: { enabled: false, list_id: '' }, webhook: { enabled: false, url: '' } },
	ab: { enabled: false, winner: '' },
} );
const campaign = { id: 5, title: 'Newsletter', type: 'popup', status: 'draft', priority: 10, starts_at: null, ends_at: null, config: config(), preview_url: 'http://site.test/?dev3pu_preview=5' };
const options = { post_types: [ { value: 'post', label: 'Post' } ], terms: [], roles: [], woocommerce: false, coupons: false, currency: '' };

const route = ( map ) => ( { url, method = 'GET', data } ) => {
	const path = url.replace( '/wp-json/dev3pu/v1/', '' );
	const key = `${ method } ${ path.split( '?' )[ 0 ] }`;
	const handler = map[ key ];
	if ( ! handler ) {
		return Promise.reject( { code: 'nope', message: key } );
	}
	return Promise.resolve( typeof handler === 'function' ? handler( data, path ) : handler );
};
const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

describe( 'model helpers', () => {
	it( 'adds and removes variants and keeps the control', () => {
		const c = addVariant( config(), { headline: 'B copy' }, true );
		expect( c.variants.map( ( v ) => v.id ) ).toEqual( [ 'a', 'b' ] );
		expect( c.variants[ 1 ].content.headline ).toBe( 'B copy' );
		expect( c.variants[ 1 ].ai ).toBe( true );
		expect( c.variants[ 0 ].weight ).toBe( 50 );
		expect( c.ab.enabled ).toBe( true );
		const four = addVariant( addVariant( addVariant( c ) ) );
		expect( four.variants ).toHaveLength( 4 );
		expect( addVariant( four ).variants ).toHaveLength( 4 );
		expect( removeVariant( c, 'a' ).variants ).toHaveLength( 2 );
		const back = removeVariant( c, 'b' );
		expect( back.variants ).toHaveLength( 1 );
		expect( back.ab.enabled ).toBe( false );
	} );
	it( 'sets nested values immutably and summarises triggers', () => {
		const src = { a: [ { b: 1 } ] };
		const out = setIn( src, [ 'a', 0, 'b' ], 2 );
		expect( out.a[ 0 ].b ).toBe( 2 );
		expect( src.a[ 0 ].b ).toBe( 1 );
		expect( triggerSummary( [ { type: 'time', value: 8 }, { type: 'scroll', value: 50 }, { type: 'exit', value: 20 } ] ) ).toBe( 'after 8s, at 50% scroll, exit intent' );
	} );
	it( 'computes WCAG contrast', () => {
		expect( contrast( '#000000', '#ffffff' ) ).toBeCloseTo( 21, 0 );
		expect( contrast( '#777777', '#ffffff' ) ).toBeLessThan( 4.5 );
	} );
} );

describe( 'admin screens', () => {
	afterEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'lists campaigns with stats and actions', async () => {
		apiFetch.mockImplementation( route( { 'GET campaigns': [ { id: 5, title: 'Newsletter', type: 'popup', status: 'active', variants: 2, ab: true, views: 1200, leads: 60, rate: 5, priority: 10 } ] } ) );
		const view = await render( wrap( <Campaigns navigate={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Newsletter' ) ).toBeTruthy();
		expect( view.queryByText( '5.0%' ) ).toBeTruthy();
		expect( view.queryByText( 'A/B · 2 variants' ) ).toBeTruthy();
		expect( view.queryByText( 'Pause' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'creates a campaign from a template', async () => {
		const navigate = jest.fn();
		apiFetch.mockImplementation(
			route( {
				'GET campaigns': [],
				'GET templates': [ { id: 'newsletter', name: 'Newsletter signup', description: 'Classic', type: 'popup', category: 'grow', campaign } ],
				'POST campaigns': ( data ) => ( { ...campaign, id: 9, data } ),
			} )
		);
		const view = await render( wrap( <Campaigns navigate={ navigate } openNew /> ) );
		await view.flush();
		expect( view.getByText( 'Newsletter signup' ) ).toBeTruthy();
		await act( async () => view.$( '[aria-label="Use template: Newsletter signup"]' ).click() );
		await view.flush();
		const post = apiFetch.mock.calls.find( ( c ) => c[ 0 ].method === 'POST' );
		expect( post[ 0 ].data ).toEqual( { template: 'newsletter' } );
		expect( navigate ).toHaveBeenCalledWith( 'campaigns/9' );
		view.unmount();
	} );

	it( 'edits a campaign with a live preview and saves it', async () => {
		let saved = null;
		apiFetch.mockImplementation(
			route( {
				'GET campaigns/5': campaign,
				'GET targeting/options': options,
				'PUT campaigns/5': ( data ) => {
					saved = data;
					return { ...campaign, ...data, status: data.status || campaign.status };
				},
			} )
		);
		const view = await render( wrap( <Editor id={ 5 } settings={ { mobile_safe: true } } onUsage={ () => {} } /> ) );
		await view.flush();
		const preview = view.$( '.dev3pu-pv-overlay .dev3pu-title' );
		expect( preview.textContent ).toBe( 'Join our list' );
		const headline = view.$$( 'input' ).find( ( i ) => i.value === 'Join our list' );
		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
			setter.call( headline, 'Get 10% off' );
			headline.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await view.flush();
		expect( view.$( '.dev3pu-pv-overlay .dev3pu-title' ).textContent ).toBe( 'Get 10% off' );
		expect( view.queryByText( 'Unsaved changes' ) ).toBeTruthy();
		await act( async () => view.getByText( 'Publish' ).closest( 'button' ).click() );
		await view.flush();
		expect( saved.status ).toBe( 'active' );
		expect( saved.config.variants[ 0 ].content.headline ).toBe( 'Get 10% off' );
		view.unmount();
	} );

	it( 'applies an AI copy draft only after the admin accepts it', async () => {
		apiFetch.mockImplementation(
			route( {
				'GET campaigns/5': campaign,
				'GET targeting/options': options,
				'POST ai/copy': { draft: { eyebrow: 'New', headline: 'AI headline', body: 'AI body', button: 'Go', decline: 'Later', success_title: 'Yay', success_message: 'Done' }, meta: { model: 'gemini-2.5-flash', tokens: 120, cached: false } },
			} )
		);
		const view = await render( wrap( <Editor id={ 5 } settings={ { mobile_safe: true } } onUsage={ () => {} } /> ) );
		await view.flush();
		await act( async () => view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ).click() );
		await view.flush();
		expect( view.$( '.dev3pu-pv-overlay .dev3pu-title' ).textContent ).toBe( 'Join our list' );
		expect( apiFetch.mock.calls.some( ( c ) => c[ 0 ].method === 'PUT' ) ).toBe( false );
		await act( async () => view.getByText( 'Use this copy' ).closest( 'button' ).click() );
		await view.flush();
		expect( view.$( '.dev3pu-pv-overlay .dev3pu-title' ).textContent ).toBe( 'AI headline' );
		expect( apiFetch.mock.calls.some( ( c ) => c[ 0 ].method === 'PUT' ) ).toBe( false );
		view.unmount();
	} );

	it( 'shows leads with an export link', async () => {
		apiFetch.mockImplementation(
			route( {
				'GET leads': { items: [ { id: 1, email: 'ana@example.com', name: 'Ana', campaign: 'Newsletter', status: 'synced', created_at: '2026-10-20 10:00:00', fields: {}, utm: {}, sync: {}, variant: 'a' } ], total: 1, export_url: 'http://site.test/wp-admin/admin-post.php?action=dev3pu_export&_wpnonce=x' },
				'GET campaigns/options': [],
			} )
		);
		const view = await render( wrap( <Leads /> ) );
		await view.flush();
		expect( view.getByText( 'ana@example.com' ) ).toBeTruthy();
		expect( view.queryByText( 'Synced' ) ).toBeTruthy();
		const link = view.getByText( 'Export CSV' ).closest( 'a' );
		expect( link.getAttribute( 'href' ) ).toContain( 'action=dev3pu_export' );
		view.unmount();
	} );

	it( 'saves integration keys without echoing them back', async () => {
		let body = null;
		apiFetch.mockImplementation(
			route( {
				'GET integrations': { mailchimp_key: { set: false, hint: '' }, brevo_key: { set: false, hint: '' }, webhook_secret: { set: false, hint: '' } },
				'POST integrations': ( data ) => {
					body = data;
					return { mailchimp_key: { set: true, hint: '••••••••us21' }, brevo_key: { set: false, hint: '' }, webhook_secret: { set: false, hint: '' } };
				},
			} )
		);
		const view = await render( wrap( <Integrations /> ) );
		await view.flush();
		const input = view.$$( 'input[type="password"]' )[ 0 ];
		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
			setter.call( input, 'abc-us21' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => view.$$( 'button' ).find( ( b ) => b.textContent === 'Save' && ! b.disabled ).click() );
		await view.flush();
		expect( body ).toEqual( { mailchimp_key: 'abc-us21' } );
		expect( view.$$( 'input[type="password"]' )[ 0 ].value ).toBe( '' );
		expect( view.queryByText( 'Connected' ) ).toBeTruthy();
		view.unmount();
	} );
} );

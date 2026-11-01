/**
 * Front-end runtime: visitor context, triggers, display, accessibility, lead submit and counters.
 * No DOM is created until a trigger fires.
 */
import { byPriority, classifyReferrer, deviceFor, effectiveTriggers, frequencyAllows, guardAllows, matchTargeting, parseCart, parseUtm, pickVariant, useSheet } from './engine';
import { renderCampaign, showSuccess } from './render';

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([type="hidden"]):not([tabindex="-1"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

function storage( win, kind ) {
	try {
		const s = win[ kind ];
		s.setItem( '__dev3pu', '1' );
		s.removeItem( '__dev3pu' );
		return s;
	} catch ( e ) {
		return null;
	}
}

function read( s, key, fallback ) {
	try {
		const raw = s ? s.getItem( key ) : null;
		return raw ? JSON.parse( raw ) : fallback;
	} catch ( e ) {
		return fallback;
	}
}

function write( s, key, value ) {
	try {
		if ( s ) {
			s.setItem( key, JSON.stringify( value ) );
		}
	} catch ( e ) {}
}

const randomId = () => Math.random().toString( 36 ).slice( 2, 10 ) + Date.now().toString( 36 );

/**
 * Creates the runtime.
 *
 * @param {Object} cfg Boot config (window.dev3puFront).
 * @param {Window} win Window.
 * @return {Object} API { open, close, context, state }.
 */
export function createRuntime( cfg, win = window ) {
	const doc = win.document;
	const settings = cfg.settings || {};
	const i18n = cfg.i18n || {};
	const ls = storage( win, 'localStorage' );
	const ss = storage( win, 'sessionStorage' );
	const reduced = !! ( win.matchMedia && win.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	const now = () => Date.now();

	// Session + visitor.
	const host = win.location.hostname;
	const session = read( ss, 'dev3pu_s', null ) || { id: randomId(), pages: 0, ref: '', utm: {} };
	if ( ! session.pages ) {
		const refClass = classifyReferrer( doc.referrer, host );
		session.ref = refClass === 'internal' ? '' : doc.referrer || '';
	}
	session.pages = ( session.pages || 0 ) + 1;
	const utmNow = parseUtm( win.location.search );
	if ( Object.keys( utmNow ).length ) {
		session.utm = utmNow;
	}
	write( ss, 'dev3pu_s', session );
	const visitor = read( ls, 'dev3pu_v', null ) || { first: now(), sid: session.id };
	write( ls, 'dev3pu_v', visitor );

	const context = {
		device: deviceFor( win.innerWidth || doc.documentElement.clientWidth || 1280 ),
		referrer: session.ref,
		host,
		utm: session.utm || {},
		loggedIn: !! ( doc.body && doc.body.classList.contains( 'logged-in' ) ),
		returning: visitor.sid !== session.id,
		pages: session.pages,
		cart: parseCart( doc.cookie ),
	};
	const dnt = !! settings.respectDnt && ( win.navigator.doNotTrack === '1' || win.doNotTrack === '1' || win.navigator.globalPrivacyControl === true );

	const state = { shown: 0, open: null, fired: {}, cleanups: [] };
	const recKey = ( id ) => `dev3pu_c_${ id }`;
	const record = ( id ) => read( ls, recKey( id ), {} );
	const saveRecord = ( id, patch ) => write( ls, recKey( id ), { ...record( id ), ...patch } );

	function post( path, body, keepalive ) {
		const headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		return win.fetch( cfg.rest + path, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify( body ), keepalive: !! keepalive } );
	}

	function track( campaignId, variant, event ) {
		if ( ! settings.tracking || ! win.fetch ) {
			return;
		}
		post( 'track', { campaign_id: campaignId, variant, event }, true ).catch( () => {} );
	}

	// ---- Submit (overlay + inline) ----
	function fieldError( form, input, message ) {
		const errId = input ? input.getAttribute( 'aria-describedby' ) : '';
		const err = errId ? doc.getElementById( errId ) : null;
		if ( input ) {
			input.setAttribute( 'aria-invalid', message ? 'true' : 'false' );
		}
		if ( err ) {
			err.textContent = message || '';
			err.hidden = ! message;
		}
	}

	function validate( form ) {
		let first = null;
		form.querySelectorAll( 'input,select' ).forEach( ( input ) => {
			if ( input.name === 'dev3pu_hp' ) {
				return;
			}
			let message = '';
			const empty = input.type === 'checkbox' ? ! input.checked : ! String( input.value || '' ).trim();
			if ( input.required && empty ) {
				if ( input.name === 'dev3pu_consent' ) {
					message = i18n.consent;
				} else {
					message = input.type === 'email' ? i18n.email : i18n.required;
				}
			} else if ( input.type === 'email' && ! empty && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( input.value.trim() ) ) {
				message = i18n.email;
			}
			fieldError( form, input, message );
			if ( message && ! first ) {
				first = input;
			}
		} );
		return first;
	}

	function attachForm( root, campaign, variantId, startedAt, onConverted ) {
		const form = root.querySelector( '[data-dev3pu-form]' );
		if ( ! form || form.dataset.dev3puBound ) {
			return;
		}
		form.dataset.dev3puBound = '1';
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const invalid = validate( form );
			if ( invalid ) {
				invalid.focus();
				return;
			}
			const fields = {};
			let consent = false;
			let hp = '';
			form.querySelectorAll( 'input,select' ).forEach( ( input ) => {
				if ( input.name === 'dev3pu_hp' ) {
					hp = input.value;
				} else if ( input.name === 'dev3pu_consent' ) {
					consent = input.checked;
				} else if ( input.name ) {
					fields[ input.name ] = input.type === 'checkbox' ? input.checked : input.value;
				}
			} );
			const button = form.querySelector( 'button[type="submit"]' );
			const status = form.querySelector( '.dev3pu-status' );
			button.disabled = true;
			status.textContent = i18n.sending || '';
			post( 'submit', {
				campaign_id: campaign.id,
				variant: variantId,
				fields,
				consent,
				hp,
				elapsed: now() - startedAt,
				page_url: win.location.href.split( '#' )[ 0 ],
				referrer: context.referrer,
				utm: context.utm,
				device: context.device,
				preview: campaign.preview ? 1 : 0,
			} )
				.then( ( res ) => res.json().then( ( data ) => ( { ok: res.ok, data } ) ) )
				.then( ( { ok, data } ) => {
					button.disabled = false;
					status.textContent = '';
					if ( ! ok ) {
						if ( data && data.fields ) {
							let firstBad = null;
							Object.keys( data.fields ).forEach( ( key ) => {
								const input = form.querySelector( `[name="${ key === 'consent' ? 'dev3pu_consent' : key }"]` );
								fieldError( form, input, data.fields[ key ] );
								firstBad = firstBad || input;
							} );
							if ( firstBad ) {
								firstBad.focus();
							}
						}
						status.textContent = ( data && data.message ) || i18n.error;
						return;
					}
					const done = showSuccess( root, data, i18n );
					if ( done ) {
						done.focus( { preventScroll: true } );
						const copy = done.querySelector( '.dev3pu-copy' );
						if ( copy ) {
							copy.addEventListener( 'click', () => {
								const code = done.querySelector( 'code' ).textContent;
								const ok2 = () => ( copy.textContent = i18n.copied || 'Copied!' );
								if ( win.navigator.clipboard ) {
									win.navigator.clipboard.writeText( code ).then( ok2, () => {} );
								}
							} );
						}
					}
					if ( onConverted ) {
						onConverted();
					}
					if ( data.redirect ) {
						win.setTimeout( () => win.location.assign( data.redirect ), 1200 );
					}
				} )
				.catch( () => {
					button.disabled = false;
					status.textContent = i18n.error || '';
				} );
		} );
	}

	// ---- Overlays ----
	function lockScroll( on ) {
		const html = doc.documentElement;
		if ( on ) {
			const gap = win.innerWidth - html.clientWidth;
			if ( gap > 0 ) {
				html.style.paddingRight = `${ gap }px`;
			}
			html.classList.add( 'dev3pu-lock' );
		} else {
			html.classList.remove( 'dev3pu-lock' );
			html.style.paddingRight = '';
		}
	}

	function close( reason = 'close' ) {
		const open = state.open;
		if ( ! open ) {
			return;
		}
		state.open = null;
		open.cleanup.forEach( ( fn ) => fn() );
		if ( reason === 'close' && ! open.converted && ! open.campaign.preview ) {
			saveRecord( open.campaign.id, { c: now() } );
			track( open.campaign.id, open.variant, 'close' );
		}
		const remove = () => {
			open.root.remove();
			if ( open.modal && ! ( state.open && state.open.modal ) ) {
				lockScroll( false );
			}
			if ( reason !== 'replace' && open.lastFocus && open.lastFocus.focus && doc.contains( open.lastFocus ) ) {
				open.lastFocus.focus( { preventScroll: true } );
			}
		};
		if ( reduced ) {
			remove();
		} else {
			open.root.classList.remove( 'dev3pu-enter' );
			open.root.classList.add( 'dev3pu-leave' );
			win.setTimeout( remove, 180 );
		}
	}

	function show( campaign, via ) {
		if ( state.open ) {
			return false;
		}
		const variants = campaign.variants || [];
		if ( ! variants.length ) {
			return false;
		}
		const abKey = `dev3pu_ab_${ campaign.id }`;
		const variantId = variants.length > 1 ? pickVariant( variants, read( ls, abKey, '' ), Math.random() ) : variants[ 0 ].id;
		if ( variants.length > 1 ) {
			write( ls, abKey, variantId );
		}
		const variant = variants.find( ( v ) => v.id === variantId ) || variants[ 0 ];
		const sheet = useSheet( campaign, context.device, settings, via === 'click' );
		const modal = ! sheet && ( campaign.type === 'popup' || campaign.type === 'fullscreen' );
		const { root, box } = renderCampaign( campaign, variant, { doc, mobile: context.device === 'mobile', sheet, i18n } );
		root.classList.add( 'dev3pu-enter' );
		const open = { campaign, variant: variant.id, root, modal, converted: false, lastFocus: doc.activeElement, cleanup: [] };
		state.open = open;
		doc.body.appendChild( root );
		if ( modal ) {
			lockScroll( true );
			box.focus( { preventScroll: true } );
		} else if ( via === 'click' ) {
			box.focus( { preventScroll: true } );
		}

		const onKey = ( e ) => {
			if ( e.key === 'Escape' && ( modal || root.contains( doc.activeElement ) ) ) {
				e.preventDefault();
				close();
				return;
			}
			if ( e.key === 'Tab' && modal ) {
				const items = Array.from( box.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => el.getClientRects().length && ! el.closest( '[hidden]' ) && ! el.closest( '.dev3pu-hp' ) );
				if ( ! items.length ) {
					e.preventDefault();
					box.focus();
					return;
				}
				const first = items[ 0 ];
				const last = items[ items.length - 1 ];
				if ( e.shiftKey && ( doc.activeElement === first || doc.activeElement === box ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && doc.activeElement === last ) {
					e.preventDefault();
					first.focus();
				} else if ( ! box.contains( doc.activeElement ) ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		doc.addEventListener( 'keydown', onKey, true );
		open.cleanup.push( () => doc.removeEventListener( 'keydown', onKey, true ) );
		root.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '[data-dev3pu-close]' ) || e.target.hasAttribute( 'data-dev3pu-backdrop' ) ) {
				close();
			}
			const cta = e.target.closest( '[data-dev3pu-cta]' );
			if ( cta ) {
				open.converted = true;
				saveRecord( campaign.id, { x: now() } );
				track( campaign.id, variant.id, 'click' );
			}
		} );
		attachForm( root, campaign, variant.id, now(), () => {
			open.converted = true;
			saveRecord( campaign.id, { x: now() } );
		} );

		state.shown += 1;
		if ( campaign.preview ) {
			return true; // Previews never touch frequency records or counters.
		}
		const rec = record( campaign.id );
		saveRecord( campaign.id, { v: ( rec.v || 0 ) + 1, l: now(), s: session.id } );
		write( ss, 'dev3pu_last', now() );
		track( campaign.id, variant.id, 'view' );
		return true;
	}

	function eligible( campaign ) {
		return matchTargeting( campaign.targeting, context ) && frequencyAllows( campaign.frequency, record( campaign.id ), now(), session.id );
	}

	function fire( campaign, via ) {
		if ( via === 'click' && state.open && state.open.campaign.id !== campaign.id ) {
			// The visitor asked for this one: replace whatever opened on its own (no "close" recorded).
			close( 'replace' );
			state.open = null;
		}
		if ( state.fired[ campaign.id ] || state.open ) {
			return;
		}
		if ( via !== 'click' && ! campaign.preview ) {
			if ( ! guardAllows( settings, state.shown, read( ss, 'dev3pu_last', 0 ), now() ) || ! eligible( campaign ) ) {
				return;
			}
		}
		if ( show( campaign, via ) ) {
			state.fired[ campaign.id ] = true;
		}
	}

	// ---- Triggers ----
	const scrollHandlers = [];
	let ticking = false;
	const scrollPercent = () => {
		const el = doc.documentElement;
		const max = Math.max( 1, ( el.scrollHeight || 0 ) - win.innerHeight );
		return Math.min( 100, ( 100 * ( win.scrollY || el.scrollTop || 0 ) ) / max );
	};
	const onScroll = () => {
		if ( ticking ) {
			return;
		}
		ticking = true;
		win.requestAnimationFrame( () => {
			ticking = false;
			const pct = scrollPercent();
			scrollHandlers.slice().forEach( ( fn ) => fn( pct ) );
		} );
	};

	function arm( campaign ) {
		effectiveTriggers( campaign, context.device, settings ).forEach( ( t ) => {
			const go = () => fire( campaign, t.type );
			switch ( t.type ) {
				case 'load':
					win.setTimeout( go, 0 );
					break;
				case 'time':
					win.setTimeout( go, ( t.value || 0 ) * 1000 );
					break;
				case 'scroll':
					scrollHandlers.push( ( pct ) => pct >= ( t.value || 50 ) && go() );
					break;
				case 'scroll_up': {
					let maxY = 0;
					let samples = [];
					scrollHandlers.push( () => {
						const y = win.scrollY || 0;
						maxY = Math.max( maxY, y );
						const ts = now();
						samples = samples.filter( ( s ) => ts - s.t < 400 ).concat( [ { y, t: ts } ] );
						const deep = scrollPercent() > 0 && maxY > ( doc.documentElement.scrollHeight - win.innerHeight ) * 0.3;
						if ( deep && samples.length > 1 && samples[ 0 ].y - y > win.innerHeight * 0.25 ) {
							go();
						}
					} );
					break;
				}
				case 'exit': {
					const armedAt = now();
					const onOut = ( e ) => {
						if ( now() - armedAt > 2000 && ! e.relatedTarget && e.clientY <= ( t.value || 20 ) ) {
							go();
						}
					};
					doc.addEventListener( 'mouseout', onOut );
					break;
				}
				case 'inactivity': {
					let timer = win.setTimeout( go, ( t.value || 30 ) * 1000 );
					const reset = () => {
						win.clearTimeout( timer );
						timer = win.setTimeout( go, ( t.value || 30 ) * 1000 );
					};
					[ 'mousemove', 'keydown', 'scroll', 'touchstart', 'click' ].forEach( ( ev ) => win.addEventListener( ev, reset, { passive: true } ) );
					break;
				}
				case 'click':
					// Handled by the delegated listener below.
					break;
			}
		} );
	}

	const campaigns = byPriority( cfg.campaigns || [] );
	const findCampaign = ( id ) => campaigns.find( ( c ) => String( c.id ) === String( id ) );

	// Click triggers: [data-dev3pu-open], .dev3pu-open-<id>, a[href="#dev3pu-open-<id>"], custom selectors.
	doc.addEventListener( 'click', ( e ) => {
		const target = e.target && e.target.closest ? e.target : null;
		if ( ! target || ( state.open && state.open.root.contains( target ) ) ) {
			return;
		}
		let id = null;
		const opener = target.closest( '[data-dev3pu-open],[class*="dev3pu-open-"],a[href*="#dev3pu-open-"]' );
		if ( opener ) {
			const m = ( opener.getAttribute( 'data-dev3pu-open' ) || '' ).match( /\d+/ ) || ( opener.className && String( opener.className ).match( /dev3pu-open-(\d+)/ ) ) || ( opener.getAttribute( 'href' ) || '' ).match( /#dev3pu-open-(\d+)/ );
			id = m ? m[ m.length > 1 ? 1 : 0 ] : null;
		}
		let campaign = id ? findCampaign( id ) : null;
		if ( ! campaign ) {
			campaign = campaigns.find( ( c ) => ( c.triggers || [] ).some( ( t ) => {
				if ( t.type !== 'click' || ! t.value ) {
					return false;
				}
				try {
					return !! target.closest( t.value );
				} catch ( err ) {
					return false;
				}
			} ) );
		}
		if ( campaign ) {
			e.preventDefault();
			state.fired[ campaign.id ] = false;
			fire( campaign, 'click' );
		}
	} );

	if ( ! dnt ) {
		campaigns.forEach( ( c ) => {
			if ( c.preview ) {
				win.setTimeout( () => fire( c, 'load' ), 300 );
			} else if ( eligible( c ) ) {
				arm( c );
			}
		} );
	}
	if ( scrollHandlers.length ) {
		win.addEventListener( 'scroll', onScroll, { passive: true } );
	}

	// ---- Inline forms (server-rendered) ----
	const started = now();
	doc.querySelectorAll( '[data-dev3pu-inline]' ).forEach( ( root ) => {
		if ( root.hidden ) {
			return;
		}
		const campaign = { id: parseInt( root.getAttribute( 'data-dev3pu-id' ), 10 ), preview: false };
		const variant = root.getAttribute( 'data-dev3pu-variant' ) || 'a';
		attachForm( root, campaign, variant, started, null );
		root.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '[data-dev3pu-cta]' ) ) {
				track( campaign.id, variant, 'click' );
			}
		} );
		if ( 'IntersectionObserver' in win ) {
			const io = new win.IntersectionObserver(
				( entries ) => {
					if ( entries.some( ( en ) => en.isIntersecting ) ) {
						io.disconnect();
						track( campaign.id, variant, 'view' );
					}
				},
				{ threshold: 0.5 }
			);
			io.observe( root );
		}
	} );

	return {
		open: ( id ) => {
			const c = findCampaign( id );
			if ( c ) {
				state.fired[ c.id ] = false;
				fire( c, 'click' );
			}
		},
		close: () => close(),
		context,
		state,
	};
}

/**
 * Boots from window.dev3puFront after the browser is idle.
 *
 * @param {Window} win Window.
 */
export function boot( win = window ) {
	const cfg = win.dev3puFront;
	if ( ! cfg || win.dev3puRuntime ) {
		return;
	}
	const start = () => {
		win.dev3puRuntime = createRuntime( cfg, win );
	};
	if ( win.requestIdleCallback ) {
		win.requestIdleCallback( start, { timeout: 1500 } );
	} else {
		win.setTimeout( start, 1 );
	}
}

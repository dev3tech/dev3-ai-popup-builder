/**
 * Builds campaign DOM. Used by the front end and by the admin live preview, so the preview is exact.
 * Mirrors includes/Frontend/Renderer.php (inline markup) class for class.
 */

const ALLOWED = { A: [ 'href', 'target', 'rel' ], STRONG: [], EM: [], B: [], I: [], BR: [] };
let uidCounter = 0;

/**
 * Appends sanitised inline HTML (a, strong, em, b, i, br; newlines become <br>).
 *
 * @param {Element} parent Target.
 * @param {string}  html   HTML.
 */
export function appendSafeHtml( parent, html ) {
	const doc = parent.ownerDocument;
	const tpl = doc.createElement( 'template' );
	tpl.innerHTML = String( html || '' ).replace( /\r?\n/g, '<br>' );
	const walk = ( from, to ) => {
		Array.from( from.childNodes ).forEach( ( node ) => {
			if ( node.nodeType === 3 ) {
				to.appendChild( doc.createTextNode( node.textContent ) );
				return;
			}
			if ( node.nodeType !== 1 ) {
				return;
			}
			const tag = node.tagName.toUpperCase();
			if ( ! ALLOWED[ tag ] ) {
				walk( node, to );
				return;
			}
			const el = doc.createElement( tag.toLowerCase() );
			ALLOWED[ tag ].forEach( ( attr ) => {
				const value = node.getAttribute( attr );
				if ( value === null ) {
					return;
				}
				if ( attr === 'href' && ! /^(https?:|mailto:|tel:|\/|#)/i.test( value.trim() ) ) {
					return;
				}
				el.setAttribute( attr, value );
			} );
			if ( tag === 'A' && el.getAttribute( 'target' ) === '_blank' ) {
				el.setAttribute( 'rel', 'noopener' );
			}
			walk( node, el );
			to.appendChild( el );
		} );
	};
	walk( tpl.content, parent );
}

/**
 * Element helper.
 *
 * @param {Document} doc   Document.
 * @param {string}   tag   Tag.
 * @param {Object}   attrs Attributes (className, text, hidden, data-*, aria-*).
 * @param {Array}    kids  Children.
 * @return {Element} Element.
 */
function h( doc, tag, attrs = {}, kids = [] ) {
	const el = doc.createElement( tag );
	Object.keys( attrs ).forEach( ( key ) => {
		const value = attrs[ key ];
		if ( value === undefined || value === null || value === false ) {
			return;
		}
		if ( key === 'className' ) {
			el.className = value;
		} else if ( key === 'text' ) {
			el.textContent = value;
		} else if ( key === 'hidden' ) {
			el.hidden = true;
		} else {
			el.setAttribute( key, value === true ? '' : String( value ) );
		}
	} );
	kids.filter( Boolean ).forEach( ( kid ) => el.appendChild( kid ) );
	return el;
}

/**
 * CSS custom properties for a design.
 *
 * @param {Object} d Design.
 * @return {string} Style.
 */
export function styleVars( d ) {
	return `--dev3pu-pbg:${ d.bg };--dev3pu-pfg:${ d.text };--dev3pu-pacc:${ d.accent };--dev3pu-pon:${ d.button_text };--dev3pu-prad:${ parseInt( d.radius, 10 ) || 0 }px;--dev3pu-pdim:${ ( ( parseInt( d.overlay, 10 ) || 0 ) / 100 ).toFixed( 2 ) }`;
}

/**
 * Wrapper classes.
 *
 * @param {string}  type  Display type.
 * @param {Object}  d     Design.
 * @param {boolean} image Has image.
 * @return {string} Classes.
 */
export function wrapperClasses( type, d, image ) {
	let layout = d.layout || 'stacked';
	if ( ! image && layout !== 'minimal' ) {
		layout = 'stacked';
	}
	return [ 'dev3pu-w', `dev3pu-w--${ type }`, `dev3pu-l--${ layout }`, `dev3pu-s--${ d.size || 'md' }`, `dev3pu-a--${ d.align || 'center' }`, `dev3pu-anim--${ d.animation || 'fade' }` ].join( ' ' );
}

const AUTOCOMPLETE = { email: 'email', tel: 'tel' };

/**
 * One form field.
 *
 * @param {Document} doc   Document.
 * @param {Object}   field Field.
 * @param {string}   uid   Unique id.
 * @param {Object}   i18n  Strings.
 * @return {Element} Field wrapper.
 */
function renderField( doc, field, uid, i18n ) {
	const id = `${ uid }-${ field.key }`;
	const common = {
		id,
		name: field.key,
		required: field.required || undefined,
		'aria-required': field.required ? 'true' : undefined,
		'aria-describedby': `${ id }-e`,
	};
	const error = h( doc, 'p', { className: 'dev3pu-error', id: `${ id }-e`, hidden: true } );
	const star = field.required ? h( doc, 'span', { className: 'dev3pu-req', 'aria-hidden': 'true', text: '*' } ) : null;
	if ( field.type === 'checkbox' ) {
		return h( doc, 'div', { className: 'dev3pu-field dev3pu-field--checkbox' }, [
			h( doc, 'label', { className: 'dev3pu-check', for: id }, [ h( doc, 'input', { type: 'checkbox', value: '1', ...common } ), doc.createTextNode( ' ' ), h( doc, 'span', { text: field.label }, [ star ] ) ] ),
			error,
		] );
	}
	const label = h( doc, 'label', { className: 'dev3pu-label', for: id, text: field.label }, [ star ] );
	if ( field.type === 'select' ) {
		const select = h( doc, 'select', { className: 'dev3pu-input', ...common }, [ h( doc, 'option', { value: '', text: field.placeholder || i18n.choose || 'Choose…' } ) ].concat( ( field.options || [] ).map( ( o ) => h( doc, 'option', { value: o, text: o } ) ) ) );
		return h( doc, 'div', { className: 'dev3pu-field dev3pu-field--select' }, [ label, select, error ] );
	}
	const input = h( doc, 'input', {
		className: 'dev3pu-input',
		type: field.type,
		autocomplete: AUTOCOMPLETE[ field.type ] || ( field.key === 'name' ? 'given-name' : 'off' ),
		placeholder: field.placeholder || '',
		...common,
	} );
	return h( doc, 'div', { className: `dev3pu-field dev3pu-field--${ field.type }` }, [ label, input, error ] );
}

const CHECK_SVG = '<svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
const CLOSE_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>';

/**
 * Renders a campaign variant.
 *
 * @param {Object} campaign Public campaign { id, type, form, … }.
 * @param {Object} variant  Variant { id, content, design }.
 * @param {Object} opts     { doc, mobile, sheet, i18n, preview }.
 * @return {Object} { root, box, form, done }
 */
export function renderCampaign( campaign, variant, opts = {} ) {
	const doc = opts.doc || document;
	const i18n = opts.i18n || {};
	const c = variant.content || {};
	const d = variant.design || {};
	const form = campaign.form || { mode: 'none', fields: [] };
	const sheet = !! opts.sheet;
	const type = campaign.type;
	const overlay = type !== 'inline';
	const modal = ! sheet && ( type === 'popup' || type === 'fullscreen' );
	uidCounter += 1;
	const uid = `dev3pu-${ campaign.id }-${ variant.id }-j${ uidCounter }`;
	const image = !! c.image_url;

	let classes = wrapperClasses( sheet ? 'sheet' : type, d, image );
	if ( type === 'slidein' || type === 'bar' ) {
		classes += ` dev3pu-pos--${ type === 'bar' ? ( d.position === 'bottom' ? 'bottom' : 'top' ) : ( d.position === 'bottom-left' ? 'bottom-left' : 'bottom-right' ) }`;
	}
	if ( sheet ) {
		classes += ` dev3pu-w--from-${ type }`;
	}
	classes += ` dev3pu-f--${ form.mode || 'none' }`;
	if ( opts.mobile ) {
		classes += ' dev3pu-m';
	}

	const root = h( doc, 'div', { className: classes, style: styleVars( d ), 'data-dev3pu-id': campaign.id, 'data-dev3pu-variant': variant.id, 'data-dev3pu-inline': type === 'inline' ? true : undefined } );

	let boxAttrs;
	if ( type === 'inline' ) {
		boxAttrs = { className: 'dev3pu-box', 'aria-labelledby': `${ uid }-t` };
	} else if ( type === 'bar' ) {
		boxAttrs = { className: 'dev3pu-box', role: 'region', 'aria-labelledby': `${ uid }-t` };
	} else {
		boxAttrs = { className: 'dev3pu-box', role: 'dialog', 'aria-modal': modal ? 'true' : undefined, 'aria-labelledby': `${ uid }-t`, tabindex: '-1' };
	}
	const box = h( doc, type === 'inline' ? 'section' : 'div', boxAttrs );

	if ( overlay ) {
		const close = h( doc, 'button', { type: 'button', className: 'dev3pu-x', 'aria-label': i18n.close || 'Close', 'data-dev3pu-close': true } );
		close.innerHTML = CLOSE_SVG;
		box.appendChild( close );
	}
	if ( image ) {
		box.appendChild( h( doc, 'div', { className: 'dev3pu-media' }, [ h( doc, 'img', { src: c.image_url, alt: c.image_alt || '', width: 400, height: 300, decoding: 'async' } ) ] ) );
	}

	const content = h( doc, 'div', { className: 'dev3pu-content' }, [
		c.eyebrow ? h( doc, 'p', { className: 'dev3pu-eyebrow', text: c.eyebrow } ) : null,
		h( doc, 'h2', { className: 'dev3pu-title', id: `${ uid }-t`, text: c.headline || '' } ),
	] );
	if ( c.body ) {
		const text = h( doc, 'div', { className: 'dev3pu-text' } );
		appendSafeHtml( text, c.body );
		content.appendChild( text );
	}
	const main = h( doc, 'div', { className: 'dev3pu-main' }, [ content ] );

	let formEl = null;
	if ( form.mode === 'cta' && form.cta_url && c.button ) {
		main.appendChild(
			h( doc, 'div', { className: 'dev3pu-actions' }, [
				h( doc, 'a', { className: 'dev3pu-btn', href: form.cta_url, 'data-dev3pu-cta': true, target: form.cta_new_tab ? '_blank' : undefined, rel: form.cta_new_tab ? 'noopener' : undefined, text: c.button } ),
			] )
		);
	} else if ( form.mode === 'form' ) {
		formEl = h( doc, 'form', { className: 'dev3pu-form', novalidate: true, 'data-dev3pu-form': true } );
		formEl.appendChild( h( doc, 'div', { className: 'dev3pu-fields' }, ( form.fields || [] ).map( ( f ) => renderField( doc, f, uid, i18n ) ) ) );
		if ( form.consent && form.consent.enabled ) {
			const id = `${ uid }-consent`;
			const span = h( doc, 'span' );
			appendSafeHtml( span, form.consent.label );
			formEl.appendChild(
				h( doc, 'div', { className: 'dev3pu-field dev3pu-field--consent' }, [
					h( doc, 'label', { className: 'dev3pu-check', for: id }, [
						h( doc, 'input', { type: 'checkbox', id, name: 'dev3pu_consent', value: '1', required: form.consent.required || undefined, 'aria-required': form.consent.required ? 'true' : undefined, 'aria-describedby': `${ id }-e` } ),
						doc.createTextNode( ' ' ),
						span,
					] ),
					h( doc, 'p', { className: 'dev3pu-error', id: `${ id }-e`, hidden: true } ),
				] )
			);
		}
		formEl.appendChild( h( doc, 'div', { className: 'dev3pu-hp', 'aria-hidden': 'true' }, [ h( doc, 'label', { text: 'Website ' }, [ h( doc, 'input', { type: 'text', name: 'dev3pu_hp', tabindex: '-1', autocomplete: 'off' } ) ] ) ] ) );
		formEl.appendChild( h( doc, 'button', { type: 'submit', className: 'dev3pu-btn', text: c.button || i18n.submit || 'Submit' } ) );
		formEl.appendChild( h( doc, 'p', { className: 'dev3pu-status', role: 'status', 'aria-live': 'polite' } ) );
		main.appendChild( formEl );
	}
	if ( overlay && c.decline ) {
		main.appendChild( h( doc, 'button', { type: 'button', className: 'dev3pu-decline', 'data-dev3pu-close': true, text: c.decline } ) );
	}
	box.appendChild( main );

	const doneText = h( doc, 'div', { className: 'dev3pu-text' } );
	appendSafeHtml( doneText, c.success_message );
	const check = h( doc, 'span', { className: 'dev3pu-check-icon', 'aria-hidden': 'true' } );
	check.innerHTML = CHECK_SVG;
	const done = h( doc, 'div', { className: 'dev3pu-done', hidden: true, tabindex: '-1' }, [
		check,
		h( doc, 'h2', { className: 'dev3pu-title', id: `${ uid }-d`, text: c.success_title || '' } ),
		doneText,
		h( doc, 'div', { className: 'dev3pu-code', hidden: true }, [ h( doc, 'code' ), h( doc, 'button', { type: 'button', className: 'dev3pu-copy', text: i18n.copy || 'Copy code' } ) ] ),
	] );
	box.appendChild( done );

	if ( overlay && ( type === 'popup' || type === 'fullscreen' ) && ! sheet ) {
		root.appendChild( h( doc, 'div', { className: 'dev3pu-backdrop', 'data-dev3pu-backdrop': true } ) );
	}
	root.appendChild( box );
	return { root, box, form: formEl, done };
}

/**
 * Shows the success state inside a rendered campaign.
 *
 * @param {Element} root  Wrapper.
 * @param {Object}  state { title, message, code }.
 * @param {Object}  i18n  Strings.
 * @return {Element} The success element.
 */
export function showSuccess( root, state, i18n = {} ) {
	const done = root.querySelector( '.dev3pu-done' );
	if ( ! done ) {
		return null;
	}
	const title = done.querySelector( '.dev3pu-title' );
	const text = done.querySelector( '.dev3pu-text' );
	if ( state.title ) {
		title.textContent = state.title;
	}
	if ( state.message ) {
		text.textContent = '';
		appendSafeHtml( text, state.message );
	}
	const box = root.querySelector( '.dev3pu-box' );
	if ( box && title.id && box.getAttribute( 'aria-labelledby' ) ) {
		box.setAttribute( 'aria-labelledby', title.id );
	}
	const code = done.querySelector( '.dev3pu-code' );
	if ( state.code ) {
		code.hidden = false;
		code.querySelector( 'code' ).textContent = state.code;
		const copy = code.querySelector( '.dev3pu-copy' );
		copy.textContent = i18n.copy || copy.textContent;
	}
	root.classList.add( 'dev3pu-is-done' );
	done.hidden = false;
	return done;
}

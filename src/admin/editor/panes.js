import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Checkbox, ColorField, ErrorNotice, FormGrid, IconButton, Inline, Notice, NumberField, ResponsiveTable, Select, SkeletonCard, Stack, TextField, Textarea, Toggle, formatNumber, useToast } from '../../core3/ui';
import Chart from '../Chart';
import { PAGE_RULE_LABELS, TRIGGER_DEFAULTS, TRIGGER_LABELS, addVariant, clone, nextVariantId, pct, removeVariant } from '../model';

/* ---------- helpers ---------- */

function luminance( hex ) {
	const m = String( hex || '' ).replace( '#', '' );
	const full = m.length === 3 ? m.split( '' ).map( ( c ) => c + c ).join( '' ) : m;
	const n = parseInt( full, 16 );
	if ( Number.isNaN( n ) ) {
		return 1;
	}
	const ch = [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ].map( ( v ) => {
		const c = v / 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * ch[ 0 ] + 0.7152 * ch[ 1 ] + 0.0722 * ch[ 2 ];
}

/**
 * WCAG contrast ratio of two hex colours.
 *
 * @param {string} a Hex.
 * @param {string} b Hex.
 * @return {number} Ratio.
 */
export function contrast( a, b ) {
	const [ x, y ] = [ luminance( a ), luminance( b ) ].sort( ( p, q ) => q - p );
	return ( x + 0.05 ) / ( y + 0.05 );
}

function VariantNote( { campaign, variant } ) {
	if ( campaign.config.variants.length < 2 ) {
		return null;
	}
	return (
		<Notice tone="info">
			{ sprintf( /* translators: %s: variant name */ __( 'Editing variant %s. Switch variants above the preview.', 'dev3-ai-popup-builder' ), variant.name ) }
		</Notice>
	);
}

function pickImage( onPick ) {
	const wp = window.wp;
	if ( ! wp || ! wp.media ) {
		return;
	}
	const frame = wp.media( { title: __( 'Choose an image', 'dev3-ai-popup-builder' ), library: { type: 'image' }, multiple: false, button: { text: __( 'Use image', 'dev3-ai-popup-builder' ) } } );
	frame.on( 'select', () => {
		const a = frame.state().get( 'selection' ).first().toJSON();
		const size = a.sizes && ( a.sizes.medium_large || a.sizes.large || a.sizes.full );
		onPick( { url: size ? size.url : a.url, alt: a.alt || '' } );
	} );
	frame.open();
}

/* ---------- Content ---------- */

const COPY_FIELDS = [
	{ key: 'eyebrow', label: __( 'Eyebrow', 'dev3-ai-popup-builder' ), maxLength: 60 },
	{ key: 'headline', label: __( 'Headline', 'dev3-ai-popup-builder' ), maxLength: 120 },
	{ key: 'body', label: __( 'Body', 'dev3-ai-popup-builder' ), multiline: true, maxLength: 600 },
	{ key: 'button', label: __( 'Button', 'dev3-ai-popup-builder' ), maxLength: 40 },
	{ key: 'decline', label: __( '"No thanks" link', 'dev3-ai-popup-builder' ), maxLength: 60 },
	{ key: 'success_title', label: __( 'Success title', 'dev3-ai-popup-builder' ), maxLength: 120 },
	{ key: 'success_message', label: __( 'Success message', 'dev3-ai-popup-builder' ), multiline: true, maxLength: 400 },
];
const copyOf = ( content ) => COPY_FIELDS.reduce( ( acc, f ) => ( { ...acc, [ f.key ]: content[ f.key ] || '' } ), {} );

function CopyAi( { campaign, variant, vIndex, update, onUsage } ) {
	const [ goal, setGoal ] = useState( '' );
	const [ offer, setOffer ] = useState( '' );
	const [ audience, setAudience ] = useState( '' );
	const [ tone, setTone ] = useState( 'friendly' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const toast = useToast();
	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'ai/copy', { method: 'POST', data: { type: campaign.type, goal, offer, audience, tone, current: copyOf( variant.content ), form_mode: campaign.config.form.mode } } )
			.then( ( res ) => setDraft( { ...res, key: Date.now() } ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage && onUsage();
			} );
	};
	const accept = ( values ) => {
		update( [ 'config', 'variants', vIndex, 'content' ], { ...variant.content, ...values } );
		update( [ 'config', 'variants', vIndex, 'ai' ], true );
		setDraft( null );
		toast.success( __( 'Copy applied to the editor. Save to keep it.', 'dev3-ai-popup-builder' ) );
	};
	return (
		<Card tone="ai" title={ __( 'Write with DEV3 AI', 'dev3-ai-popup-builder' ) } description={ __( 'Get fresh copy for this variant. You review every change; nothing is saved or published automatically.', 'dev3-ai-popup-builder' ) }>
			<FormGrid>
				<TextField label={ __( 'Goal', 'dev3-ai-popup-builder' ) } value={ goal } onChange={ setGoal } placeholder={ __( 'Grow the newsletter', 'dev3-ai-popup-builder' ) } />
				<TextField label={ __( 'Offer', 'dev3-ai-popup-builder' ) } value={ offer } onChange={ setOffer } placeholder={ __( '10% off the first order', 'dev3-ai-popup-builder' ) } />
				<TextField label={ __( 'Audience', 'dev3-ai-popup-builder' ) } value={ audience } onChange={ setAudience } placeholder={ __( 'First-time visitors', 'dev3-ai-popup-builder' ) } />
				<Select
					label={ __( 'Tone', 'dev3-ai-popup-builder' ) }
					value={ tone }
					onChange={ setTone }
					options={ [
						{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-popup-builder' ) },
						{ value: 'professional', label: __( 'Professional', 'dev3-ai-popup-builder' ) },
						{ value: 'playful', label: __( 'Playful', 'dev3-ai-popup-builder' ) },
						{ value: 'urgent but honest', label: __( 'Urgent (honest)', 'dev3-ai-popup-builder' ) },
						{ value: 'luxury, understated', label: __( 'Luxury', 'dev3-ai-popup-builder' ) },
					] }
				/>
			</FormGrid>
			<div>
				<AiButton onClick={ generate } loading={ busy } disabled={ busy } />
			</div>
			<ErrorNotice error={ error } onRetry={ generate } onDismiss={ () => setError( null ) } />
			{ draft && <AiDraftReview key={ draft.key } fields={ COPY_FIELDS } original={ copyOf( variant.content ) } draft={ draft.draft } meta={ draft.meta } onAccept={ accept } onReject={ () => setDraft( null ) } onRegenerate={ generate } regenerating={ busy } acceptLabel={ __( 'Use this copy', 'dev3-ai-popup-builder' ) } /> }
		</Card>
	);
}

export function ContentPane( props ) {
	const { campaign, variant, vIndex, update } = props;
	const c = variant.content;
	const set = ( key ) => ( value ) => update( [ 'config', 'variants', vIndex, 'content', key ], value );
	const overlay = campaign.type !== 'inline';
	return (
		<Stack gap={ 16 }>
			<VariantNote campaign={ campaign } variant={ variant } />
			<Card title={ __( 'Copy', 'dev3-ai-popup-builder' ) }>
				<TextField label={ __( 'Eyebrow (small label above the headline)', 'dev3-ai-popup-builder' ) } value={ c.eyebrow } onChange={ set( 'eyebrow' ) } maxLength={ 60 } />
				<TextField label={ __( 'Headline', 'dev3-ai-popup-builder' ) } value={ c.headline } onChange={ set( 'headline' ) } maxLength={ 120 } required />
				<Textarea label={ __( 'Body', 'dev3-ai-popup-builder' ) } value={ c.body } onChange={ set( 'body' ) } rows={ 3 } maxLength={ 600 } help={ __( 'Links and bold text are allowed.', 'dev3-ai-popup-builder' ) } />
				<FormGrid>
					<TextField label={ __( 'Button text', 'dev3-ai-popup-builder' ) } value={ c.button } onChange={ set( 'button' ) } maxLength={ 40 } />
					{ overlay && <TextField label={ __( '"No thanks" link', 'dev3-ai-popup-builder' ) } value={ c.decline } onChange={ set( 'decline' ) } maxLength={ 60 } help={ __( 'Optional. Keep it polite.', 'dev3-ai-popup-builder' ) } /> }
				</FormGrid>
			</Card>
			{ campaign.type !== 'bar' && (
				<Card title={ __( 'Image', 'dev3-ai-popup-builder' ) } description={ __( 'Loaded only when the campaign opens. Hidden in the mobile bottom sheet.', 'dev3-ai-popup-builder' ) }>
					{ c.image_url && <img className="dev3pu-imgthumb" src={ c.image_url } alt="" /> }
					<Inline gap={ 8 }>
						<Button icon="file" onClick={ () => pickImage( ( img ) => {
							update( [ 'config', 'variants', vIndex, 'content' ], { ...c, image_url: img.url, image_alt: img.alt || c.image_alt } );
						} ) }>
							{ c.image_url ? __( 'Replace image', 'dev3-ai-popup-builder' ) : __( 'Choose image', 'dev3-ai-popup-builder' ) }
						</Button>
						{ c.image_url && (
							<Button variant="ghost" icon="trash" onClick={ () => set( 'image_url' )( '' ) }>
								{ __( 'Remove', 'dev3-ai-popup-builder' ) }
							</Button>
						) }
					</Inline>
					<TextField label={ __( 'Image URL', 'dev3-ai-popup-builder' ) } value={ c.image_url } onChange={ set( 'image_url' ) } type="url" />
					<TextField label={ __( 'Alt text', 'dev3-ai-popup-builder' ) } value={ c.image_alt } onChange={ set( 'image_alt' ) } maxLength={ 160 } help={ __( 'Leave empty for decorative images.', 'dev3-ai-popup-builder' ) } />
				</Card>
			) }
			<Card title={ __( 'After sign-up', 'dev3-ai-popup-builder' ) }>
				<TextField label={ __( 'Success title', 'dev3-ai-popup-builder' ) } value={ c.success_title } onChange={ set( 'success_title' ) } maxLength={ 120 } />
				<Textarea label={ __( 'Success message', 'dev3-ai-popup-builder' ) } value={ c.success_message } onChange={ set( 'success_message' ) } rows={ 2 } maxLength={ 400 } />
			</Card>
			<CopyAi { ...props } />
		</Stack>
	);
}

/* ---------- Form & offer ---------- */

const FIELD_TYPES = [
	{ value: 'email', label: __( 'Email', 'dev3-ai-popup-builder' ) },
	{ value: 'text', label: __( 'Text', 'dev3-ai-popup-builder' ) },
	{ value: 'tel', label: __( 'Phone', 'dev3-ai-popup-builder' ) },
	{ value: 'select', label: __( 'Dropdown', 'dev3-ai-popup-builder' ) },
	{ value: 'checkbox', label: __( 'Checkbox', 'dev3-ai-popup-builder' ) },
];

export function FormPane( { campaign, update, options } ) {
	const form = campaign.config.form;
	const offer = campaign.config.offer;
	const success = campaign.config.success;
	const setForm = ( key ) => ( value ) => update( [ 'config', 'form', key ], value );
	const setField = ( i, key ) => ( value ) => update( [ 'config', 'form', 'fields', i, key ], value );
	const setOffer = ( key ) => ( value ) => update( [ 'config', 'offer', key ], value );
	const addField = () => {
		const n = form.fields.length + 1;
		update( [ 'config', 'form', 'fields' ], form.fields.concat( [ { key: `field_${ n }`, type: 'text', label: __( 'New field', 'dev3-ai-popup-builder' ), placeholder: '', required: false, options: [] } ] ) );
	};
	const move = ( i, dir ) => {
		const list = form.fields.slice();
		const j = i + dir;
		if ( j < 0 || j >= list.length ) {
			return;
		}
		[ list[ i ], list[ j ] ] = [ list[ j ], list[ i ] ];
		update( [ 'config', 'form', 'fields' ], list );
	};
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'What the visitor does', 'dev3-ai-popup-builder' ) }>
				<Select
					label={ __( 'Action', 'dev3-ai-popup-builder' ) }
					value={ form.mode }
					onChange={ setForm( 'mode' ) }
					options={ [
						{ value: 'form', label: __( 'Fill in a form (collect leads)', 'dev3-ai-popup-builder' ) },
						{ value: 'cta', label: __( 'Click a button (link)', 'dev3-ai-popup-builder' ) },
						{ value: 'none', label: __( 'Nothing (announcement)', 'dev3-ai-popup-builder' ) },
					] }
				/>
				{ form.mode === 'cta' && (
					<>
						<TextField label={ __( 'Button link', 'dev3-ai-popup-builder' ) } value={ form.cta_url } onChange={ setForm( 'cta_url' ) } type="url" placeholder="https://" required />
						<Toggle label={ __( 'Open in a new tab', 'dev3-ai-popup-builder' ) } checked={ form.cta_new_tab } onChange={ setForm( 'cta_new_tab' ) } />
					</>
				) }
			</Card>
			{ form.mode === 'form' && (
				<Card title={ __( 'Form fields', 'dev3-ai-popup-builder' ) } description={ __( 'Up to 6 fields. Ask only for what you need: every field lowers sign-ups.', 'dev3-ai-popup-builder' ) }>
					{ form.fields.map( ( f, i ) => (
						<div key={ i } className="dev3pu-rowbox">
							<div className="dev3pu-rowbox__head">
								<strong>{ f.label || f.key }</strong>
								<Inline gap={ 4 }>
									<IconButton icon="sort" label={ __( 'Move up', 'dev3-ai-popup-builder' ) } onClick={ () => move( i, -1 ) } disabled={ i === 0 } />
									<IconButton icon="trash" label={ __( 'Remove field', 'dev3-ai-popup-builder' ) } onClick={ () => update( [ 'config', 'form', 'fields' ], form.fields.filter( ( x, j ) => j !== i ) ) } disabled={ f.type === 'email' && form.fields.filter( ( x ) => x.type === 'email' ).length === 1 } />
								</Inline>
							</div>
							<FormGrid>
								<Select label={ __( 'Type', 'dev3-ai-popup-builder' ) } value={ f.type } onChange={ setField( i, 'type' ) } options={ FIELD_TYPES } />
								<TextField label={ __( 'Label', 'dev3-ai-popup-builder' ) } value={ f.label } onChange={ setField( i, 'label' ) } maxLength={ 120 } />
								{ f.type !== 'checkbox' && <TextField label={ __( 'Placeholder', 'dev3-ai-popup-builder' ) } value={ f.placeholder } onChange={ setField( i, 'placeholder' ) } maxLength={ 120 } /> }
								<TextField label={ __( 'Field key', 'dev3-ai-popup-builder' ) } value={ f.key } onChange={ ( v ) => setField( i, 'key' )( v.toLowerCase().replace( /[^a-z0-9_]/g, '_' ) ) } maxLength={ 30 } help={ __( 'Column name in the CSV export.', 'dev3-ai-popup-builder' ) } />
							</FormGrid>
							{ f.type === 'select' && <Textarea label={ __( 'Options (one per line)', 'dev3-ai-popup-builder' ) } value={ ( f.options || [] ).join( '\n' ) } onChange={ ( v ) => setField( i, 'options' )( v.split( '\n' ) ) } rows={ 3 } /> }
							<Toggle label={ __( 'Required', 'dev3-ai-popup-builder' ) } checked={ f.required } onChange={ setField( i, 'required' ) } />
						</div>
					) ) }
					<div>
						<Button icon="plus" onClick={ addField } disabled={ form.fields.length >= 6 }>
							{ __( 'Add field', 'dev3-ai-popup-builder' ) }
						</Button>
					</div>
				</Card>
			) }
			{ form.mode === 'form' && (
				<Card title={ __( 'Consent (GDPR)', 'dev3-ai-popup-builder' ) } description={ __( 'The exact label is stored with each lead as proof of consent. Mailchimp and Brevo only receive leads who ticked it.', 'dev3-ai-popup-builder' ) }>
					<Toggle label={ __( 'Show a consent checkbox', 'dev3-ai-popup-builder' ) } checked={ form.consent.enabled } onChange={ ( v ) => update( [ 'config', 'form', 'consent', 'enabled' ], v ) } />
					{ form.consent.enabled && (
						<>
							<Textarea label={ __( 'Checkbox label', 'dev3-ai-popup-builder' ) } value={ form.consent.label } onChange={ ( v ) => update( [ 'config', 'form', 'consent', 'label' ], v ) } rows={ 2 } maxLength={ 400 } help={ __( 'You can link your privacy policy with <a href="…">…</a>.', 'dev3-ai-popup-builder' ) } />
							<Toggle label={ __( 'Required to submit', 'dev3-ai-popup-builder' ) } checked={ form.consent.required } onChange={ ( v ) => update( [ 'config', 'form', 'consent', 'required' ], v ) } />
						</>
					) }
				</Card>
			) }
			{ form.mode === 'form' && (
				<Card title={ __( 'Offer & success', 'dev3-ai-popup-builder' ) }>
					<Select
						label={ __( 'Offer shown after sign-up', 'dev3-ai-popup-builder' ) }
						value={ offer.type }
						onChange={ setOffer( 'type' ) }
						options={ [
							{ value: 'none', label: __( 'No code', 'dev3-ai-popup-builder' ) },
							{ value: 'code', label: __( 'Reveal a fixed code', 'dev3-ai-popup-builder' ) },
							{ value: 'coupon', label: options.coupons ? __( 'Unique one-time WooCommerce coupon', 'dev3-ai-popup-builder' ) : __( 'Unique coupon (needs WooCommerce)', 'dev3-ai-popup-builder' ), disabled: ! options.coupons },
						] }
					/>
					{ offer.type === 'code' && <TextField label={ __( 'Code', 'dev3-ai-popup-builder' ) } value={ offer.code } onChange={ ( v ) => setOffer( 'code' )( v.toUpperCase().replace( /[^A-Z0-9_-]/g, '' ) ) } maxLength={ 40 } help={ __( 'Only sent to the browser after a successful sign-up.', 'dev3-ai-popup-builder' ) } /> }
					{ offer.type === 'coupon' && (
						<FormGrid>
							<Select
								label={ __( 'Discount', 'dev3-ai-popup-builder' ) }
								value={ offer.discount_type }
								onChange={ setOffer( 'discount_type' ) }
								options={ [
									{ value: 'percent', label: __( 'Percent off', 'dev3-ai-popup-builder' ) },
									{ value: 'fixed_cart', label: __( 'Fixed amount off the cart', 'dev3-ai-popup-builder' ) },
								] }
							/>
							<NumberField label={ __( 'Amount', 'dev3-ai-popup-builder' ) } value={ offer.amount } onChange={ setOffer( 'amount' ) } min={ 0 } step={ 0.5 } />
							<NumberField label={ __( 'Valid for (days)', 'dev3-ai-popup-builder' ) } value={ offer.expiry_days } onChange={ setOffer( 'expiry_days' ) } min={ 0 } max={ 365 } help={ __( '0 = no expiry', 'dev3-ai-popup-builder' ) } />
							<TextField label={ __( 'Code prefix', 'dev3-ai-popup-builder' ) } value={ offer.prefix } onChange={ ( v ) => setOffer( 'prefix' )( v.toUpperCase().replace( /[^A-Z0-9]/g, '' ) ) } maxLength={ 12 } />
						</FormGrid>
					) }
					<Select
						label={ __( 'Then', 'dev3-ai-popup-builder' ) }
						value={ success.action }
						onChange={ ( v ) => update( [ 'config', 'success', 'action' ], v ) }
						options={ [
							{ value: 'message', label: __( 'Show the success message', 'dev3-ai-popup-builder' ) },
							{ value: 'redirect', label: __( 'Show the message, then go to a page', 'dev3-ai-popup-builder' ) },
						] }
					/>
					{ success.action === 'redirect' && <TextField label={ __( 'Redirect URL', 'dev3-ai-popup-builder' ) } value={ success.redirect_url } onChange={ ( v ) => update( [ 'config', 'success', 'redirect_url' ], v ) } type="url" placeholder="https://" /> }
				</Card>
			) }
		</Stack>
	);
}

/* ---------- Design ---------- */

export function DesignPane( { campaign, variant, vIndex, update, setConfig } ) {
	const d = variant.design;
	const set = ( key ) => ( value ) => update( [ 'config', 'variants', vIndex, 'design', key ], value );
	const textContrast = contrast( d.text, d.bg );
	const buttonContrast = contrast( d.button_text, d.accent );
	const type = campaign.type;
	const applyAll = () => setConfig( ( cfg ) => ( { ...cfg, variants: cfg.variants.map( ( v ) => ( { ...v, design: clone( d ) } ) ) } ) );
	return (
		<Stack gap={ 16 }>
			<VariantNote campaign={ campaign } variant={ variant } />
			<Card title={ __( 'Format', 'dev3-ai-popup-builder' ) }>
				<Select
					label={ __( 'Format', 'dev3-ai-popup-builder' ) }
					value={ type }
					onChange={ ( v ) => update( [ 'type' ], v ) }
					options={ [
						{ value: 'popup', label: __( 'Popup (centered)', 'dev3-ai-popup-builder' ) },
						{ value: 'slidein', label: __( 'Slide-in (corner)', 'dev3-ai-popup-builder' ) },
						{ value: 'bar', label: __( 'Floating bar', 'dev3-ai-popup-builder' ) },
						{ value: 'fullscreen', label: __( 'Full screen', 'dev3-ai-popup-builder' ) },
						{ value: 'inline', label: __( 'Inline form (in the page)', 'dev3-ai-popup-builder' ) },
					] }
				/>
				<FormGrid>
					{ type !== 'bar' && (
						<Select
							label={ __( 'Layout', 'dev3-ai-popup-builder' ) }
							value={ d.layout }
							onChange={ set( 'layout' ) }
							options={ [
								{ value: 'stacked', label: __( 'Stacked', 'dev3-ai-popup-builder' ) },
								{ value: 'image-left', label: __( 'Image left', 'dev3-ai-popup-builder' ) },
								{ value: 'image-top', label: __( 'Image on top', 'dev3-ai-popup-builder' ) },
								{ value: 'minimal', label: __( 'Minimal (no image)', 'dev3-ai-popup-builder' ) },
							] }
							help={ ! variant.content.image_url && ( d.layout === 'image-left' || d.layout === 'image-top' ) ? __( 'Add an image in Content to use this layout.', 'dev3-ai-popup-builder' ) : undefined }
						/>
					) }
					{ type !== 'bar' && type !== 'fullscreen' && (
						<Select
							label={ __( 'Size', 'dev3-ai-popup-builder' ) }
							value={ d.size }
							onChange={ set( 'size' ) }
							options={ [
								{ value: 'sm', label: __( 'Small', 'dev3-ai-popup-builder' ) },
								{ value: 'md', label: __( 'Medium', 'dev3-ai-popup-builder' ) },
								{ value: 'lg', label: __( 'Large', 'dev3-ai-popup-builder' ) },
							] }
						/>
					) }
					{ type !== 'bar' && (
						<Select
							label={ __( 'Text alignment', 'dev3-ai-popup-builder' ) }
							value={ d.align }
							onChange={ set( 'align' ) }
							options={ [
								{ value: 'center', label: __( 'Center', 'dev3-ai-popup-builder' ) },
								{ value: 'left', label: __( 'Left', 'dev3-ai-popup-builder' ) },
							] }
						/>
					) }
					{ ( type === 'slidein' || type === 'bar' ) && (
						<Select
							label={ __( 'Position', 'dev3-ai-popup-builder' ) }
							value={ type === 'bar' ? ( d.position === 'bottom' ? 'bottom' : 'top' ) : ( d.position === 'bottom-left' ? 'bottom-left' : 'bottom-right' ) }
							onChange={ set( 'position' ) }
							options={
								type === 'bar'
									? [
											{ value: 'top', label: __( 'Top', 'dev3-ai-popup-builder' ) },
											{ value: 'bottom', label: __( 'Bottom', 'dev3-ai-popup-builder' ) },
									  ]
									: [
											{ value: 'bottom-right', label: __( 'Bottom right', 'dev3-ai-popup-builder' ) },
											{ value: 'bottom-left', label: __( 'Bottom left', 'dev3-ai-popup-builder' ) },
									  ]
							}
						/>
					) }
					{ type !== 'inline' && (
						<Select
							label={ __( 'Animation', 'dev3-ai-popup-builder' ) }
							value={ d.animation }
							onChange={ set( 'animation' ) }
							options={ [
								{ value: 'fade', label: __( 'Fade', 'dev3-ai-popup-builder' ) },
								{ value: 'slide', label: __( 'Slide up', 'dev3-ai-popup-builder' ) },
								{ value: 'zoom', label: __( 'Zoom', 'dev3-ai-popup-builder' ) },
								{ value: 'none', label: __( 'None', 'dev3-ai-popup-builder' ) },
							] }
							help={ __( 'Turned off automatically for visitors who prefer reduced motion.', 'dev3-ai-popup-builder' ) }
						/>
					) }
				</FormGrid>
			</Card>
			<Card title={ __( 'Colours & shape', 'dev3-ai-popup-builder' ) } description={ __( 'Fonts come from your theme.', 'dev3-ai-popup-builder' ) }>
				<FormGrid>
					<ColorField label={ __( 'Background', 'dev3-ai-popup-builder' ) } value={ d.bg } onChange={ set( 'bg' ) } />
					<ColorField label={ __( 'Text', 'dev3-ai-popup-builder' ) } value={ d.text } onChange={ set( 'text' ) } />
					<ColorField label={ __( 'Accent / button', 'dev3-ai-popup-builder' ) } value={ d.accent } onChange={ set( 'accent' ) } />
					<ColorField label={ __( 'Button text', 'dev3-ai-popup-builder' ) } value={ d.button_text } onChange={ set( 'button_text' ) } />
					<NumberField label={ __( 'Corner radius (px)', 'dev3-ai-popup-builder' ) } value={ d.radius } onChange={ set( 'radius' ) } min={ 0 } max={ 32 } />
					{ ( type === 'popup' || type === 'fullscreen' ) && <NumberField label={ __( 'Backdrop darkness (%)', 'dev3-ai-popup-builder' ) } value={ d.overlay } onChange={ set( 'overlay' ) } min={ 0 } max={ 90 } step={ 5 } /> }
				</FormGrid>
				{ ( textContrast < 4.5 || buttonContrast < 4.5 ) && (
					<Notice tone="warning" title={ __( 'Low contrast', 'dev3-ai-popup-builder' ) }>
						{ sprintf( /* translators: 1: text contrast, 2: button contrast */ __( 'Text %1$s:1, button %2$s:1. Aim for at least 4.5:1 so everyone can read it (WCAG AA).', 'dev3-ai-popup-builder' ), textContrast.toFixed( 1 ), buttonContrast.toFixed( 1 ) ) }
					</Notice>
				) }
				{ campaign.config.variants.length > 1 && (
					<div>
						<Button size="sm" onClick={ applyAll }>
							{ __( 'Use this design for all variants', 'dev3-ai-popup-builder' ) }
						</Button>
					</div>
				) }
			</Card>
		</Stack>
	);
}

/* ---------- Triggers / placement ---------- */

export function TriggersPane( { campaign, update, options, campaignId } ) {
	const cfg = campaign.config;
	const set = ( i, key ) => ( value ) => update( [ 'config', 'triggers', i, key ], value );
	if ( campaign.type === 'inline' ) {
		return (
			<Stack gap={ 16 }>
				<Card title={ __( 'Where the form appears', 'dev3-ai-popup-builder' ) }>
					<Select
						label={ __( 'Placement', 'dev3-ai-popup-builder' ) }
						value={ cfg.inline.placement }
						onChange={ ( v ) => update( [ 'config', 'inline', 'placement' ], v ) }
						options={ [
							{ value: 'manual', label: __( 'Only where I add the block or shortcode', 'dev3-ai-popup-builder' ) },
							{ value: 'after', label: __( 'After the content of…', 'dev3-ai-popup-builder' ) },
							{ value: 'before', label: __( 'Before the content of…', 'dev3-ai-popup-builder' ) },
						] }
					/>
					{ cfg.inline.placement !== 'manual' && (
						<fieldset className="dev3pu-checks">
							<legend>{ __( 'Post types', 'dev3-ai-popup-builder' ) }</legend>
							{ options.post_types.map( ( pt ) => (
								<Checkbox
									key={ pt.value }
									label={ pt.label }
									checked={ cfg.inline.post_types.includes( pt.value ) }
									onChange={ ( on ) => update( [ 'config', 'inline', 'post_types' ], on ? cfg.inline.post_types.concat( [ pt.value ] ) : cfg.inline.post_types.filter( ( x ) => x !== pt.value ) ) }
								/>
							) ) }
						</fieldset>
					) }
					<Notice tone="info" title={ __( 'Embed anywhere', 'dev3-ai-popup-builder' ) }>
						{ __( 'Block: "Lead form". Shortcode:', 'dev3-ai-popup-builder' ) } <code>{ `[dev3pu_form id="${ campaignId }"]` }</code>
					</Notice>
				</Card>
			</Stack>
		);
	}
	const add = () => update( [ 'config', 'triggers' ], cfg.triggers.concat( [ { type: 'scroll', value: 50 } ] ) );
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'When it opens', 'dev3-ai-popup-builder' ) } description={ __( 'The first trigger that fires opens the campaign (once per page view).', 'dev3-ai-popup-builder' ) }>
				{ cfg.triggers.map( ( t, i ) => (
					<div key={ i } className="dev3pu-rowbox dev3pu-rowbox--inline">
						<Select
							label={ __( 'Trigger', 'dev3-ai-popup-builder' ) }
							value={ t.type }
							onChange={ ( v ) => update( [ 'config', 'triggers', i ], { type: v, value: TRIGGER_DEFAULTS[ v ] } ) }
							options={ Object.keys( TRIGGER_LABELS ).map( ( k ) => ( { value: k, label: TRIGGER_LABELS[ k ] } ) ) }
						/>
						{ t.type === 'time' && <NumberField label={ __( 'Seconds', 'dev3-ai-popup-builder' ) } value={ t.value } onChange={ set( i, 'value' ) } min={ 0 } max={ 600 } /> }
						{ t.type === 'scroll' && <NumberField label={ __( 'Percent scrolled', 'dev3-ai-popup-builder' ) } value={ t.value } onChange={ set( i, 'value' ) } min={ 1 } max={ 100 } /> }
						{ t.type === 'inactivity' && <NumberField label={ __( 'Idle seconds', 'dev3-ai-popup-builder' ) } value={ t.value } onChange={ set( i, 'value' ) } min={ 5 } max={ 600 } /> }
						{ t.type === 'exit' && <NumberField label={ __( 'Sensitivity (px from top)', 'dev3-ai-popup-builder' ) } value={ t.value } onChange={ set( i, 'value' ) } min={ 1 } max={ 100 } /> }
						{ t.type === 'click' && <TextField label={ __( 'CSS selector (optional)', 'dev3-ai-popup-builder' ) } value={ t.value } onChange={ set( i, 'value' ) } placeholder=".open-offer, #get-guide" /> }
						<IconButton icon="trash" label={ __( 'Remove trigger', 'dev3-ai-popup-builder' ) } onClick={ () => update( [ 'config', 'triggers' ], cfg.triggers.filter( ( x, j ) => j !== i ) ) } disabled={ cfg.triggers.length < 2 } />
					</div>
				) ) }
				<div>
					<Button icon="plus" onClick={ add } disabled={ cfg.triggers.length >= 6 }>
						{ __( 'Add trigger', 'dev3-ai-popup-builder' ) }
					</Button>
				</div>
				{ cfg.triggers.some( ( t ) => t.type === 'exit' ) && (
					<Select
						label={ __( 'Exit intent on phones', 'dev3-ai-popup-builder' ) }
						value={ cfg.exit_mobile }
						onChange={ ( v ) => update( [ 'config', 'exit_mobile' ], v ) }
						options={ [
							{ value: 'scroll_up', label: __( 'Use a fast scroll up', 'dev3-ai-popup-builder' ) },
							{ value: 'off', label: __( 'Do not use on phones', 'dev3-ai-popup-builder' ) },
						] }
						help={ __( 'Phones have no mouse, so "leaving" is detected by a quick scroll back up.', 'dev3-ai-popup-builder' ) }
					/>
				) }
				<Notice tone="info" title={ __( 'Open on click', 'dev3-ai-popup-builder' ) }>
					{ __( 'Any link to', 'dev3-ai-popup-builder' ) } <code>{ `#dev3pu-open-${ campaignId }` }</code>, { __( 'any element with the class', 'dev3-ai-popup-builder' ) } <code>{ `dev3pu-open-${ campaignId }` }</code>, { __( 'the "Popup button" block or', 'dev3-ai-popup-builder' ) } <code>{ `[dev3pu_button id="${ campaignId }" label="…"]` }</code>
				</Notice>
			</Card>
			<Card title={ __( 'Schedule & priority', 'dev3-ai-popup-builder' ) }>
				<FormGrid>
					<TextField label={ __( 'Start (optional)', 'dev3-ai-popup-builder' ) } type="datetime-local" value={ campaign.starts_at ? campaign.starts_at.replace( ' ', 'T' ).slice( 0, 16 ) : '' } onChange={ ( v ) => update( [ 'starts_at' ], v || null ) } />
					<TextField label={ __( 'End (optional)', 'dev3-ai-popup-builder' ) } type="datetime-local" value={ campaign.ends_at ? campaign.ends_at.replace( ' ', 'T' ).slice( 0, 16 ) : '' } onChange={ ( v ) => update( [ 'ends_at' ], v || null ) } />
					<NumberField label={ __( 'Priority', 'dev3-ai-popup-builder' ) } value={ campaign.priority } onChange={ ( v ) => update( [ 'priority' ], v === '' ? 0 : v ) } min={ 0 } max={ 100 } help={ __( 'Higher wins when several campaigns could open.', 'dev3-ai-popup-builder' ) } />
				</FormGrid>
			</Card>
		</Stack>
	);
}

/* ---------- Targeting ---------- */

function RuleValue( { rule, onChange, options } ) {
	switch ( rule.type ) {
		case 'post_type':
			return <Select label={ __( 'Post type', 'dev3-ai-popup-builder' ) } value={ rule.value } onChange={ onChange } options={ options.post_types } placeholder={ __( 'Choose…', 'dev3-ai-popup-builder' ) } />;
		case 'term':
			return <Select label={ __( 'Term', 'dev3-ai-popup-builder' ) } value={ rule.value } onChange={ onChange } options={ options.terms } placeholder={ __( 'Choose…', 'dev3-ai-popup-builder' ) } />;
		case 'ids':
			return <TextField label={ __( 'IDs (comma separated)', 'dev3-ai-popup-builder' ) } value={ rule.value } onChange={ onChange } placeholder="12, 48" />;
		case 'url':
			return <TextField label={ __( 'URL text or pattern', 'dev3-ai-popup-builder' ) } value={ rule.value } onChange={ onChange } placeholder="/blog/*" help={ __( 'Contains the text, or use * as a wildcard on the path.', 'dev3-ai-popup-builder' ) } />;
		case 'woo':
			return (
				<Select
					label={ __( 'WooCommerce page', 'dev3-ai-popup-builder' ) }
					value={ rule.value || 'shop' }
					onChange={ onChange }
					options={ [
						{ value: 'shop', label: __( 'Shop', 'dev3-ai-popup-builder' ) },
						{ value: 'product', label: __( 'Product', 'dev3-ai-popup-builder' ) },
						{ value: 'cart', label: __( 'Cart', 'dev3-ai-popup-builder' ) },
						{ value: 'checkout', label: __( 'Checkout', 'dev3-ai-popup-builder' ) },
						{ value: 'account', label: __( 'My account', 'dev3-ai-popup-builder' ) },
					] }
				/>
			);
	}
	return null;
}

function TargetingAi( { campaign, variant, setConfig, onUsage } ) {
	const toast = useToast();
	const [ goal, setGoal ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const cfg = campaign.config;
	const current = { triggers: cfg.triggers, targeting: cfg.targeting, frequency: cfg.frequency };
	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'ai/targeting', { method: 'POST', data: { type: campaign.type, goal, copy: variant.content, ...current } } )
			.then( ( res ) => setDraft( { ...res, key: Date.now() } ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage && onUsage();
			} );
	};
	const accept = ( value ) => {
		setConfig( ( c ) => ( { ...c, triggers: value.triggers || c.triggers, targeting: { ...c.targeting, ...( value.targeting || {} ) }, frequency: { ...c.frequency, ...( value.frequency || {} ) } } ) );
		setDraft( null );
		toast.success( __( 'Suggestion applied to the editor. Save to keep it.', 'dev3-ai-popup-builder' ) );
	};
	return (
		<Card tone="ai" title={ __( 'Suggest targeting with DEV3 AI', 'dev3-ai-popup-builder' ) } description={ __( 'Triggers, pages, devices and frequency based on your goal and site structure. Phone-friendly by default.', 'dev3-ai-popup-builder' ) }>
			<TextField label={ __( 'Goal', 'dev3-ai-popup-builder' ) } value={ goal } onChange={ setGoal } placeholder={ __( 'Recover visitors leaving the product pages', 'dev3-ai-popup-builder' ) } />
			<div>
				<AiButton onClick={ generate } loading={ busy } disabled={ busy }>
					{ __( 'Suggest with DEV3 AI', 'dev3-ai-popup-builder' ) }
				</AiButton>
			</div>
			<ErrorNotice error={ error } onRetry={ generate } onDismiss={ () => setError( null ) } />
			{ draft && draft.reasons && draft.reasons.length > 0 && (
				<ul className="dev3pu-reasons">
					{ draft.reasons.map( ( r, i ) => (
						<li key={ i }>{ r }</li>
					) ) }
				</ul>
			) }
			{ draft && <AiDraftReview key={ draft.key } format="json" original={ current } draft={ draft.draft } meta={ draft.meta } onAccept={ accept } onReject={ () => setDraft( null ) } onRegenerate={ generate } regenerating={ busy } acceptLabel={ __( 'Use these settings', 'dev3-ai-popup-builder' ) } /> }
		</Card>
	);
}

export function TargetingPane( props ) {
	const { campaign, update, options } = props;
	const t = campaign.config.targeting;
	const f = campaign.config.frequency;
	const setT = ( path ) => ( value ) => update( [ 'config', 'targeting' ].concat( path ), value );
	const setF = ( key ) => ( value ) => update( [ 'config', 'frequency', key ], value );
	const ruleTypes = Object.keys( PAGE_RULE_LABELS )
		.filter( ( k ) => k !== 'woo' || options.woocommerce )
		.map( ( k ) => ( { value: k, label: PAGE_RULE_LABELS[ k ] } ) );
	const devices = [
		{ value: 'desktop', label: __( 'Desktop', 'dev3-ai-popup-builder' ) },
		{ value: 'tablet', label: __( 'Tablet', 'dev3-ai-popup-builder' ) },
		{ value: 'mobile', label: __( 'Phone', 'dev3-ai-popup-builder' ) },
	];
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'Pages', 'dev3-ai-popup-builder' ) } description={ __( 'Checked on the server, so it works with page caching.', 'dev3-ai-popup-builder' ) }>
				<Select
					label={ __( 'Show on', 'dev3-ai-popup-builder' ) }
					value={ t.pages.mode }
					onChange={ setT( [ 'pages', 'mode' ] ) }
					options={ [
						{ value: 'all', label: __( 'Every page', 'dev3-ai-popup-builder' ) },
						{ value: 'include', label: __( 'Only pages matching a rule', 'dev3-ai-popup-builder' ) },
						{ value: 'exclude', label: __( 'Every page except matches', 'dev3-ai-popup-builder' ) },
					] }
				/>
				{ t.pages.mode !== 'all' && (
					<>
						{ t.pages.rules.map( ( r, i ) => (
							<div key={ i } className="dev3pu-rowbox dev3pu-rowbox--inline">
								<Select label={ __( 'Rule', 'dev3-ai-popup-builder' ) } value={ r.type } onChange={ ( v ) => setT( [ 'pages', 'rules', i ] )( { type: v, value: v === 'woo' ? 'shop' : '' } ) } options={ ruleTypes } />
								<RuleValue rule={ r } options={ options } onChange={ setT( [ 'pages', 'rules', i, 'value' ] ) } />
								<IconButton icon="trash" label={ __( 'Remove rule', 'dev3-ai-popup-builder' ) } onClick={ () => setT( [ 'pages', 'rules' ] )( t.pages.rules.filter( ( x, j ) => j !== i ) ) } />
							</div>
						) ) }
						<div>
							<Button icon="plus" onClick={ () => setT( [ 'pages', 'rules' ] )( t.pages.rules.concat( [ { type: 'post_type', value: 'post' } ] ) ) }>
								{ __( 'Add rule', 'dev3-ai-popup-builder' ) }
							</Button>
						</div>
					</>
				) }
			</Card>
			<Card title={ __( 'Visitors', 'dev3-ai-popup-builder' ) }>
				<fieldset className="dev3pu-checks">
					<legend>{ __( 'Devices', 'dev3-ai-popup-builder' ) }</legend>
					{ devices.map( ( dv ) => (
						<Checkbox key={ dv.value } label={ dv.label } checked={ t.devices.includes( dv.value ) } onChange={ ( on ) => setT( [ 'devices' ] )( on ? t.devices.concat( [ dv.value ] ) : t.devices.filter( ( x ) => x !== dv.value ) ) } />
					) ) }
				</fieldset>
				<FormGrid>
					<Select
						label={ __( 'Login', 'dev3-ai-popup-builder' ) }
						value={ t.login }
						onChange={ setT( [ 'login' ] ) }
						options={ [
							{ value: 'any', label: __( 'Everyone', 'dev3-ai-popup-builder' ) },
							{ value: 'out', label: __( 'Logged-out visitors', 'dev3-ai-popup-builder' ) },
							{ value: 'in', label: __( 'Logged-in users', 'dev3-ai-popup-builder' ) },
						] }
					/>
					<Select
						label={ __( 'New or returning', 'dev3-ai-popup-builder' ) }
						value={ t.visitor }
						onChange={ setT( [ 'visitor' ] ) }
						options={ [
							{ value: 'any', label: __( 'Everyone', 'dev3-ai-popup-builder' ) },
							{ value: 'new', label: __( 'New visitors', 'dev3-ai-popup-builder' ) },
							{ value: 'returning', label: __( 'Returning visitors', 'dev3-ai-popup-builder' ) },
						] }
					/>
					<NumberField label={ __( 'Minimum pages viewed', 'dev3-ai-popup-builder' ) } value={ t.min_pages } onChange={ setT( [ 'min_pages' ] ) } min={ 0 } max={ 50 } help={ __( 'In this visit.', 'dev3-ai-popup-builder' ) } />
					<Select
						label={ __( 'Referrer', 'dev3-ai-popup-builder' ) }
						value={ t.referrer.mode }
						onChange={ setT( [ 'referrer', 'mode' ] ) }
						options={ [
							{ value: 'any', label: __( 'Any source', 'dev3-ai-popup-builder' ) },
							{ value: 'search', label: __( 'From search engines', 'dev3-ai-popup-builder' ) },
							{ value: 'social', label: __( 'From social networks', 'dev3-ai-popup-builder' ) },
							{ value: 'direct', label: __( 'Direct / no referrer', 'dev3-ai-popup-builder' ) },
							{ value: 'contains', label: __( 'Referrer contains…', 'dev3-ai-popup-builder' ) },
							{ value: 'not_contains', label: __( 'Referrer does not contain…', 'dev3-ai-popup-builder' ) },
						] }
					/>
					{ ( t.referrer.mode === 'contains' || t.referrer.mode === 'not_contains' ) && <TextField label={ __( 'Referrer text', 'dev3-ai-popup-builder' ) } value={ t.referrer.value } onChange={ setT( [ 'referrer', 'value' ] ) } placeholder="partner-site.com" /> }
				</FormGrid>
			</Card>
			<Card title={ __( 'UTM campaign', 'dev3-ai-popup-builder' ) } description={ __( 'Matches the UTM tags the visitor arrived with in this visit.', 'dev3-ai-popup-builder' ) }>
				{ t.utm.map( ( u, i ) => (
					<div key={ i } className="dev3pu-rowbox dev3pu-rowbox--inline">
						<Select label={ __( 'Parameter', 'dev3-ai-popup-builder' ) } value={ u.key } onChange={ setT( [ 'utm', i, 'key' ] ) } options={ [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ].map( ( k ) => ( { value: k, label: k } ) ) } />
						<Select
							label={ __( 'Match', 'dev3-ai-popup-builder' ) }
							value={ u.op }
							onChange={ setT( [ 'utm', i, 'op' ] ) }
							options={ [
								{ value: 'equals', label: __( 'equals', 'dev3-ai-popup-builder' ) },
								{ value: 'contains', label: __( 'contains', 'dev3-ai-popup-builder' ) },
							] }
						/>
						<TextField label={ __( 'Value', 'dev3-ai-popup-builder' ) } value={ u.value } onChange={ setT( [ 'utm', i, 'value' ] ) } />
						<IconButton icon="trash" label={ __( 'Remove UTM rule', 'dev3-ai-popup-builder' ) } onClick={ () => setT( [ 'utm' ] )( t.utm.filter( ( x, j ) => j !== i ) ) } />
					</div>
				) ) }
				<div>
					<Button icon="plus" onClick={ () => setT( [ 'utm' ] )( t.utm.concat( [ { key: 'utm_source', op: 'equals', value: '' } ] ) ) } disabled={ t.utm.length >= 5 }>
						{ __( 'Add UTM rule', 'dev3-ai-popup-builder' ) }
					</Button>
				</div>
			</Card>
			{ options.woocommerce && (
				<Card title={ __( 'WooCommerce cart', 'dev3-ai-popup-builder' ) }>
					<Toggle label={ __( 'Target by cart', 'dev3-ai-popup-builder' ) } checked={ t.cart.enabled } onChange={ setT( [ 'cart', 'enabled' ] ) } />
					{ t.cart.enabled && (
						<FormGrid>
							<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Minimum cart value %s', 'dev3-ai-popup-builder' ), options.currency ? `(${ options.currency })` : '' ) } value={ t.cart.min } onChange={ setT( [ 'cart', 'min' ] ) } min={ 0 } step={ 1 } />
							<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Maximum cart value %s', 'dev3-ai-popup-builder' ), options.currency ? `(${ options.currency })` : '' ) } value={ t.cart.max } onChange={ setT( [ 'cart', 'max' ] ) } min={ 0 } step={ 1 } help={ __( '0 = no maximum', 'dev3-ai-popup-builder' ) } />
							<Toggle label={ __( 'Cart must not be empty', 'dev3-ai-popup-builder' ) } checked={ t.cart.not_empty } onChange={ setT( [ 'cart', 'not_empty' ] ) } />
						</FormGrid>
					) }
				</Card>
			) }
			<Card title={ __( 'Frequency', 'dev3-ai-popup-builder' ) } description={ __( 'Respectful defaults keep visitors happy.', 'dev3-ai-popup-builder' ) }>
				<FormGrid>
					<Select
						label={ __( 'Show', 'dev3-ai-popup-builder' ) }
						value={ f.mode }
						onChange={ setF( 'mode' ) }
						options={ [
							{ value: 'session', label: __( 'Once per visit', 'dev3-ai-popup-builder' ) },
							{ value: 'days', label: __( 'At most once every N days', 'dev3-ai-popup-builder' ) },
							{ value: 'max', label: __( 'At most N times in total', 'dev3-ai-popup-builder' ) },
							{ value: 'always', label: __( 'On every page view', 'dev3-ai-popup-builder' ) },
						] }
					/>
					{ f.mode === 'days' && <NumberField label={ __( 'Days', 'dev3-ai-popup-builder' ) } value={ f.days } onChange={ setF( 'days' ) } min={ 1 } max={ 365 } /> }
					{ f.mode === 'max' && <NumberField label={ __( 'Times', 'dev3-ai-popup-builder' ) } value={ f.max } onChange={ setF( 'max' ) } min={ 1 } max={ 100 } /> }
					<NumberField label={ __( 'After closing, hide for (days)', 'dev3-ai-popup-builder' ) } value={ f.close_days } onChange={ setF( 'close_days' ) } min={ 0 } max={ 365 } />
					<Select
						label={ __( 'After signing up', 'dev3-ai-popup-builder' ) }
						value={ f.converted }
						onChange={ setF( 'converted' ) }
						options={ [
							{ value: 'never', label: __( 'Never show again', 'dev3-ai-popup-builder' ) },
							{ value: 'days', label: __( 'Show again after N days', 'dev3-ai-popup-builder' ) },
						] }
					/>
					{ f.converted === 'days' && <NumberField label={ __( 'Days', 'dev3-ai-popup-builder' ) } value={ f.converted_days } onChange={ setF( 'converted_days' ) } min={ 1 } max={ 3650 } /> }
				</FormGrid>
			</Card>
			<TargetingAi { ...props } />
		</Stack>
	);
}

/* ---------- A/B ---------- */

function VariantsAi( { campaign, variant, setConfig, setVariantId, onUsage } ) {
	const toast = useToast();
	const [ goal, setGoal ] = useState( '' );
	const [ offer, setOffer ] = useState( '' );
	const [ ideas, setIdeas ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const full = campaign.config.variants.length >= 4;
	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'ai/variants', { method: 'POST', data: { type: campaign.type, goal, offer, current: variant.content, count: 3 } } )
			.then( ( res ) => setIdeas( res ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage && onUsage();
			} );
	};
	const use = ( idea, i ) => {
		const newId = nextVariantId( campaign.config.variants );
		setConfig( ( cfg ) => addVariant( cfg, idea.content, true ) );
		setIdeas( ( cur ) => ( { ...cur, variants: cur.variants.filter( ( x, j ) => j !== i ) } ) );
		if ( newId ) {
			setVariantId( newId );
		}
		toast.success( __( 'Variant added to the test. Save to keep it.', 'dev3-ai-popup-builder' ) );
	};
	return (
		<Card tone="ai" title={ __( 'Offer variants with DEV3 AI', 'dev3-ai-popup-builder' ) } description={ __( 'Three different angles or offers to test against your control. Add the ones you like; nothing goes live until you publish.', 'dev3-ai-popup-builder' ) }>
			<FormGrid>
				<TextField label={ __( 'Goal', 'dev3-ai-popup-builder' ) } value={ goal } onChange={ setGoal } placeholder={ __( 'More newsletter sign-ups', 'dev3-ai-popup-builder' ) } />
				<TextField label={ __( 'Offers you can give', 'dev3-ai-popup-builder' ) } value={ offer } onChange={ setOffer } placeholder={ __( '10% off, free shipping, a PDF guide', 'dev3-ai-popup-builder' ) } />
			</FormGrid>
			<div>
				<AiButton onClick={ generate } loading={ busy } disabled={ busy || full }>
					{ __( 'Generate variants with DEV3 AI', 'dev3-ai-popup-builder' ) }
				</AiButton>
			</div>
			{ full && <Notice tone="info">{ __( 'A test can have up to 4 variants. Remove one to add more.', 'dev3-ai-popup-builder' ) }</Notice> }
			<ErrorNotice error={ error } onRetry={ generate } onDismiss={ () => setError( null ) } />
			{ ideas && (
				<div className="dev3pu-ideas">
					{ ideas.variants.map( ( idea, i ) => (
						<div key={ i } className="dev3pu-idea">
							<Inline align="between">
								<Badge tone="brand">{ idea.angle }</Badge>
								{ idea.offer && <span className="dev3pu-muted">{ idea.offer }</span> }
							</Inline>
							<h4>{ idea.content.headline }</h4>
							<p>{ idea.content.body }</p>
							<p className="dev3pu-muted">
								<strong>{ idea.content.button }</strong> · { idea.rationale }
							</p>
							<Button size="sm" variant="secondary" icon="plus" onClick={ () => use( idea, i ) } disabled={ campaign.config.variants.length >= 4 }>
								{ __( 'Add to test', 'dev3-ai-popup-builder' ) }
							</Button>
						</div>
					) ) }
					{ ideas.meta && <p className="dev3pu-muted">{ sprintf( /* translators: 1: model, 2: tokens */ __( 'Not saved yet · %1$s · %2$s tokens', 'dev3-ai-popup-builder' ), ideas.meta.model, formatNumber( ideas.meta.tokens ) ) }</p> }
				</div>
			) }
		</Card>
	);
}

export function AbPane( props ) {
	const { campaign, update, setConfig, setVariantId } = props;
	const cfg = campaign.config;
	const total = cfg.variants.reduce( ( s, v ) => s + ( Number( v.weight ) || 0 ), 0 );
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'Variants', 'dev3-ai-popup-builder' ) } description={ __( 'Each visitor always sees the same variant. Views and sign-ups are counted per variant.', 'dev3-ai-popup-builder' ) }>
				<Toggle label={ __( 'Run an A/B test', 'dev3-ai-popup-builder' ) } checked={ cfg.ab.enabled } onChange={ ( v ) => update( [ 'config', 'ab' ], { enabled: v, winner: '' } ) } disabled={ cfg.variants.length < 2 } help={ cfg.variants.length < 2 ? __( 'Add a second variant to start a test.', 'dev3-ai-popup-builder' ) : undefined } />
				{ ! cfg.ab.enabled && cfg.ab.winner && <Notice tone="success">{ sprintf( /* translators: %s: variant */ __( 'Variant %s was declared the winner and is shown to everyone.', 'dev3-ai-popup-builder' ), cfg.ab.winner.toUpperCase() ) }</Notice> }
				{ cfg.variants.map( ( v, i ) => (
					<div key={ v.id } className="dev3pu-rowbox dev3pu-rowbox--inline">
						<TextField label={ i === 0 ? __( 'Control name', 'dev3-ai-popup-builder' ) : __( 'Variant name', 'dev3-ai-popup-builder' ) } value={ v.name } onChange={ ( val ) => update( [ 'config', 'variants', i, 'name' ], val ) } maxLength={ 40 } suffix={ v.ai ? 'AI' : undefined } />
						<NumberField label={ __( 'Traffic weight', 'dev3-ai-popup-builder' ) } value={ v.weight } onChange={ ( val ) => update( [ 'config', 'variants', i, 'weight' ], val === '' ? 0 : val ) } min={ 0 } max={ 100 } suffix={ total ? `${ Math.round( ( 100 * ( Number( v.weight ) || 0 ) ) / total ) }%` : undefined } />
						<Button size="sm" variant="ghost" icon="edit" onClick={ () => setVariantId( v.id ) }>
							{ __( 'Preview', 'dev3-ai-popup-builder' ) }
						</Button>
						<IconButton icon="trash" label={ sprintf( /* translators: %s: variant */ __( 'Remove variant %s', 'dev3-ai-popup-builder' ), v.name ) } onClick={ () => setConfig( ( c ) => removeVariant( c, v.id ) ) } disabled={ i === 0 } />
					</div>
				) ) }
				<div>
					<Button icon="plus" onClick={ () => setConfig( ( c ) => addVariant( c ) ) } disabled={ cfg.variants.length >= 4 }>
						{ __( 'Add variant (copy of the control)', 'dev3-ai-popup-builder' ) }
					</Button>
				</div>
			</Card>
			<VariantsAi { ...props } />
		</Stack>
	);
}

/* ---------- Integrations ---------- */

function ListPicker( { service, value, onChange } ) {
	const [ lists, setLists ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		request( `integrations/${ service }/lists` ).then( setLists ).catch( setError );
	}, [ service ] );
	if ( error ) {
		return (
			<Notice tone="warning" actions={ <Button size="sm" href="#/integrations">{ __( 'Open Integrations', 'dev3-ai-popup-builder' ) }</Button> }>
				{ error.message }
			</Notice>
		);
	}
	if ( ! lists ) {
		return <SkeletonCard lines={ 1 } />;
	}
	return <Select label={ service === 'mailchimp' ? __( 'Audience', 'dev3-ai-popup-builder' ) : __( 'List', 'dev3-ai-popup-builder' ) } value={ value } onChange={ onChange } options={ lists } placeholder={ __( 'Choose…', 'dev3-ai-popup-builder' ) } required />;
}

export function IntegrationsPane( { campaign, update } ) {
	const toast = useToast();
	const int = campaign.config.integrations;
	const [ testing, setTesting ] = useState( false );
	const set = ( service, key ) => ( value ) => update( [ 'config', 'integrations', service, key ], value );
	const test = () => {
		setTesting( true );
		request( 'integrations/webhook/test', { method: 'POST', data: { url: int.webhook.url } } )
			.then( ( r ) => toast.success( r.message ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setTesting( false ) );
	};
	return (
		<Stack gap={ 16 }>
			<Card title="Mailchimp">
				<Toggle label={ __( 'Send leads to Mailchimp', 'dev3-ai-popup-builder' ) } checked={ int.mailchimp.enabled } onChange={ set( 'mailchimp', 'enabled' ) } />
				{ int.mailchimp.enabled && (
					<>
						<ListPicker service="mailchimp" value={ int.mailchimp.list_id } onChange={ set( 'mailchimp', 'list_id' ) } />
						<TextField label={ __( 'Tags (comma separated)', 'dev3-ai-popup-builder' ) } value={ int.mailchimp.tags } onChange={ set( 'mailchimp', 'tags' ) } />
						<Toggle label={ __( 'Double opt-in (Mailchimp sends a confirmation email)', 'dev3-ai-popup-builder' ) } checked={ int.mailchimp.double } onChange={ set( 'mailchimp', 'double' ) } />
					</>
				) }
			</Card>
			<Card title="Brevo">
				<Toggle label={ __( 'Send leads to Brevo', 'dev3-ai-popup-builder' ) } checked={ int.brevo.enabled } onChange={ set( 'brevo', 'enabled' ) } />
				{ int.brevo.enabled && <ListPicker service="brevo" value={ int.brevo.list_id } onChange={ set( 'brevo', 'list_id' ) } /> }
			</Card>
			<Card title={ __( 'Webhook', 'dev3-ai-popup-builder' ) } description={ __( 'POSTs each lead as JSON (Zapier, Make, n8n, your CRM). Signed with X-DEV3PU-Signature when a secret is set under Integrations.', 'dev3-ai-popup-builder' ) }>
				<Toggle label={ __( 'Send leads to a webhook', 'dev3-ai-popup-builder' ) } checked={ int.webhook.enabled } onChange={ set( 'webhook', 'enabled' ) } />
				{ int.webhook.enabled && (
					<>
						<TextField label={ __( 'Webhook URL', 'dev3-ai-popup-builder' ) } type="url" value={ int.webhook.url } onChange={ set( 'webhook', 'url' ) } placeholder="https://hooks.zapier.com/…" />
						<div>
							<Button size="sm" onClick={ test } loading={ testing } disabled={ ! int.webhook.url }>
								{ __( 'Send a test event', 'dev3-ai-popup-builder' ) }
							</Button>
						</div>
					</>
				) }
			</Card>
		</Stack>
	);
}

/* ---------- Results ---------- */

export function ResultsPane( { campaign, campaignId, setConfig, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ explain, setExplain ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const load = () => request( `campaigns/${ campaignId }/results` ).then( setData ).catch( setError );
	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ campaignId ] );
	const winner = ( id ) =>
		request( `campaigns/${ campaignId }/winner`, { method: 'POST', data: { variant: id } } )
			.then( ( c ) => {
				setConfig( () => c.config );
				toast.success( __( 'Winner declared. The test is stopped and everyone sees this variant.', 'dev3-ai-popup-builder' ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	const ask = () => {
		setBusy( true );
		request( 'ai/explain', { method: 'POST', data: { id: campaignId } } )
			.then( setExplain )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setBusy( false );
				onUsage && onUsage();
			} );
	};
	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 4 } />;
	}
	const testing = campaign.config.variants.length > 1;
	const columns = [
		{ key: 'name', label: __( 'Variant', 'dev3-ai-popup-builder' ), primary: true, render: ( r ) => <span className="dev3pu-cell-title">{ r.name }{ data.leader === r.id && r.views > 0 && <Badge tone="success">{ __( 'Leading', 'dev3-ai-popup-builder' ) }</Badge> }</span> },
		{ key: 'views', label: __( 'Views', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => formatNumber( r.views ) },
		{ key: 'conversions', label: __( 'Leads', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => formatNumber( r.conversions ) },
		{ key: 'rate', label: __( 'Conversion', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => pct( r.rate ) },
		{ key: 'uplift', label: __( 'vs control', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => ( r.uplift === null ? '-' : `${ r.uplift > 0 ? '+' : '' }${ r.uplift }%` ) },
		{
			key: 'confidence',
			label: __( 'Confidence', 'dev3-ai-popup-builder' ),
			align: 'end',
			render: ( r ) => {
				if ( r.confidence === null ) {
					return '-';
				}
				return <Badge tone={ r.significant ? 'success' : 'neutral' }>{ `${ r.confidence }%` }</Badge>;
			},
		},
	];
	return (
		<Stack gap={ 16 }>
			<Card title={ testing ? __( 'A/B results', 'dev3-ai-popup-builder' ) : __( 'Results', 'dev3-ai-popup-builder' ) } description={ __( 'All time. A result is significant at 95% confidence with at least 100 views per variant.', 'dev3-ai-popup-builder' ) } flush>
				<ResponsiveTable
					columns={ columns }
					rows={ data.variants }
					rowActions={
						testing && campaign.config.ab.enabled
							? ( r ) => (
									<Button size="sm" variant="ghost" icon="check" onClick={ ( e ) => {
										e.stopPropagation();
										winner( r.id );
									} }>
										{ __( 'Declare winner', 'dev3-ai-popup-builder' ) }
									</Button>
							  )
							: undefined
					}
				/>
			</Card>
			{ testing && (
				<Card tone="ai" title={ __( 'Explain with DEV3 AI', 'dev3-ai-popup-builder' ) }>
					<div>
						<AiButton onClick={ ask } loading={ busy } disabled={ busy }>
							{ __( 'Explain the results', 'dev3-ai-popup-builder' ) }
						</AiButton>
					</div>
					{ explain && (
						<Notice tone="ai" title={ explain.winner ? sprintf( /* translators: %s: variant */ __( 'Suggested winner: %s', 'dev3-ai-popup-builder' ), explain.winner.toUpperCase() ) : __( 'No clear winner yet', 'dev3-ai-popup-builder' ) }>
							<p>{ explain.summary }</p>
							<p>{ explain.confidence_note }</p>
							<p>
								<strong>{ __( 'Next test:', 'dev3-ai-popup-builder' ) }</strong> { explain.next_test }
							</p>
						</Notice>
					) }
				</Card>
			) }
			<Card title={ __( 'Leads per day (30 days)', 'dev3-ai-popup-builder' ) }>
				<Chart series={ data.series } measure="conversions" label={ __( 'Leads', 'dev3-ai-popup-builder' ) } />
			</Card>
		</Stack>
	);
}

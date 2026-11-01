import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Checkbox, Columns, FormGrid, NumberField, PageHeader, Stack, Textarea, Toggle, useToast } from '../core3/ui';

const KEYS = [ 'mobile_safe', 'max_per_view', 'min_gap', 'hide_roles', 'respect_dnt', 'tracking', 'store_ip', 'retention_days', 'consent_text', 'min_fill_seconds', 'brand_voice', 'delete_on_uninstall' ];

/**
 * Global settings.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( () => KEYS.reduce( ( acc, k ) => ( { ...acc, [ k ]: settings[ k ] } ), {} ) );
	const [ roles, setRoles ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		request( 'targeting/options' )
			.then( ( o ) => setRoles( o.roles || [] ) )
			.catch( () => {} );
	}, [] );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const submit = () => {
		setBusy( true );
		save( form )
			.then( () => toast.success( __( 'Settings saved.', 'dev3-ai-popup-builder' ) ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const footer = (
		<Button variant="primary" onClick={ submit } loading={ busy }>
			{ __( 'Save settings', 'dev3-ai-popup-builder' ) }
		</Button>
	);
	const hidden = form.hide_roles || [];
	return (
		<>
			<PageHeader title={ __( 'Settings', 'dev3-ai-popup-builder' ) } description={ __( 'Site-wide rules that apply to every campaign.', 'dev3-ai-popup-builder' ) } actions={ footer } />
			<Columns min={ 340 }>
				<Stack gap={ 16 }>
					<Card title={ __( 'Visitor experience', 'dev3-ai-popup-builder' ) }>
						<Toggle label={ __( 'Mobile-safe mode (recommended)', 'dev3-ai-popup-builder' ) } checked={ form.mobile_safe } onChange={ set( 'mobile_safe' ) } help={ __( 'On phones, popups and full-screen campaigns appear as a small bottom sheet that does not block the page, and never right on page load. This follows Google’s guidance on intrusive interstitials.', 'dev3-ai-popup-builder' ) } />
						<FormGrid>
							<NumberField label={ __( 'Max overlays per page view', 'dev3-ai-popup-builder' ) } value={ form.max_per_view } onChange={ set( 'max_per_view' ) } min={ 0 } max={ 5 } help={ __( '0 = no limit. Click-opened campaigns are not limited.', 'dev3-ai-popup-builder' ) } />
							<NumberField label={ __( 'Seconds between overlays', 'dev3-ai-popup-builder' ) } value={ form.min_gap } onChange={ set( 'min_gap' ) } min={ 0 } max={ 3600 } />
						</FormGrid>
						<fieldset className="dev3pu-checks">
							<legend>{ __( 'Never show campaigns to these roles', 'dev3-ai-popup-builder' ) }</legend>
							{ roles.map( ( r ) => (
								<Checkbox key={ r.value } label={ r.label } checked={ hidden.includes( r.value ) } onChange={ ( on ) => set( 'hide_roles' )( on ? hidden.concat( [ r.value ] ) : hidden.filter( ( x ) => x !== r.value ) ) } />
							) ) }
						</fieldset>
						<Toggle label={ __( 'Respect Do Not Track / Global Privacy Control', 'dev3-ai-popup-builder' ) } checked={ form.respect_dnt } onChange={ set( 'respect_dnt' ) } help={ __( 'Visitors with DNT or GPC turned on see no automatic popups (inline forms still show).', 'dev3-ai-popup-builder' ) } />
					</Card>
					<Card title={ __( 'DEV3 AI', 'dev3-ai-popup-builder' ) }>
						<Textarea label={ __( 'Brand voice', 'dev3-ai-popup-builder' ) } value={ form.brand_voice } onChange={ set( 'brand_voice' ) } rows={ 3 } maxLength={ 600 } placeholder={ __( 'Warm, witty, short sentences. We say “you”, never “customers”.', 'dev3-ai-popup-builder' ) } help={ __( 'Used for every copy and variant draft.', 'dev3-ai-popup-builder' ) } />
					</Card>
				</Stack>
				<Stack gap={ 16 }>
					<Card title={ __( 'Privacy & data', 'dev3-ai-popup-builder' ) } footer={ footer }>
						<Toggle label={ __( 'Count views, closes and conversions', 'dev3-ai-popup-builder' ) } checked={ form.tracking } onChange={ set( 'tracking' ) } help={ __( 'Anonymous daily counters, no cookies. Needed for A/B tests.', 'dev3-ai-popup-builder' ) } />
						<Toggle label={ __( 'Store a hashed IP with each lead', 'dev3-ai-popup-builder' ) } checked={ form.store_ip } onChange={ set( 'store_ip' ) } />
						<NumberField label={ __( 'Delete leads after (days)', 'dev3-ai-popup-builder' ) } value={ form.retention_days } onChange={ set( 'retention_days' ) } min={ 0 } max={ 3650 } help={ __( '0 = keep until you delete them.', 'dev3-ai-popup-builder' ) } />
						<NumberField label={ __( 'Minimum seconds before a form can be sent', 'dev3-ai-popup-builder' ) } value={ form.min_fill_seconds } onChange={ set( 'min_fill_seconds' ) } min={ 0 } max={ 30 } help={ __( 'Stops bots. A hidden honeypot field and rate limits also apply.', 'dev3-ai-popup-builder' ) } />
						<Toggle label={ __( 'Delete all data when the plugin is uninstalled', 'dev3-ai-popup-builder' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
					</Card>
				</Stack>
			</Columns>
		</>
	);
}

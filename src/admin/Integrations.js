import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, ErrorNotice, Inline, PageHeader, SkeletonCard, TextField, useToast } from '../core3/ui';

const SERVICES = [
	{
		id: 'mailchimp',
		key: 'mailchimp_key',
		title: 'Mailchimp',
		label: __( 'API key', 'dev3-ai-popup-builder' ),
		help: __( 'Mailchimp → Profile → Extras → API keys. It ends in -us1, -us21…', 'dev3-ai-popup-builder' ),
	},
	{
		id: 'brevo',
		key: 'brevo_key',
		title: 'Brevo',
		label: __( 'API key (v3)', 'dev3-ai-popup-builder' ),
		help: __( 'Brevo → SMTP & API → API keys.', 'dev3-ai-popup-builder' ),
	},
	{
		id: 'webhook',
		key: 'webhook_secret',
		title: __( 'Webhook signing', 'dev3-ai-popup-builder' ),
		label: __( 'Signing secret (optional)', 'dev3-ai-popup-builder' ),
		help: __( 'When set, every webhook carries X-DEV3PU-Signature: sha256=HMAC(body, secret).', 'dev3-ai-popup-builder' ),
	},
];

/**
 * Integration credentials (stored encrypted; the browser only sees the last 4 characters).
 *
 * @return {Element} Screen.
 */
export default function Integrations() {
	const toast = useToast();
	const [ status, setStatus ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ hookUrl, setHookUrl ] = useState( '' );

	useEffect( () => {
		request( 'integrations' ).then( setStatus ).catch( setError );
	}, [] );

	const save = ( key, value ) => {
		setBusy( `save-${ key }` );
		return request( 'integrations', { method: 'POST', data: { [ key ]: value } } )
			.then( ( next ) => {
				setStatus( next );
				setValues( ( v ) => ( { ...v, [ key ]: '' } ) );
				toast.success( value ? __( 'Saved (encrypted).', 'dev3-ai-popup-builder' ) : __( 'Removed.', 'dev3-ai-popup-builder' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const test = ( id ) => {
		setBusy( `test-${ id }` );
		request( `integrations/${ id }/test`, { method: 'POST', data: { url: hookUrl } } )
			.then( ( r ) => toast.success( r.message ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Integrations', 'dev3-ai-popup-builder' ) } description={ __( 'Connect your email marketing tools once, then pick a list in each campaign.', 'dev3-ai-popup-builder' ) } />
			<ErrorNotice error={ error } />
			{ ! status && ! error && <SkeletonCard lines={ 4 } /> }
			{ status && (
				<Columns min={ 300 }>
					{ SERVICES.map( ( s ) => {
						const st = status[ s.key ] || {};
						return (
							<Card
								key={ s.id }
								title={ s.title }
								actions={ <Badge tone={ st.set ? 'success' : 'neutral' } dot>{ st.set ? __( 'Connected', 'dev3-ai-popup-builder' ) : __( 'Not set', 'dev3-ai-popup-builder' ) }</Badge> }
								footer={
									<Inline gap={ 8 } align="end">
										{ st.set && ! st.constant && (
											<Button variant="ghost" onClick={ () => save( s.key, '' ) } loading={ busy === `save-${ s.key }` }>
												{ __( 'Remove', 'dev3-ai-popup-builder' ) }
											</Button>
										) }
										{ s.id !== 'webhook' && (
											<Button onClick={ () => test( s.id ) } loading={ busy === `test-${ s.id }` } disabled={ ! st.set }>
												{ __( 'Test connection', 'dev3-ai-popup-builder' ) }
											</Button>
										) }
										<Button variant="primary" onClick={ () => save( s.key, values[ s.key ] || '' ) } disabled={ ! values[ s.key ] || st.constant } loading={ busy === `save-${ s.key }` }>
											{ __( 'Save', 'dev3-ai-popup-builder' ) }
										</Button>
									</Inline>
								}
							>
								<TextField label={ s.label } type="password" autoComplete="off" value={ values[ s.key ] || '' } onChange={ ( v ) => setValues( ( cur ) => ( { ...cur, [ s.key ]: v } ) ) } placeholder={ st.hint || '' } help={ st.constant ? __( 'Set by a constant in wp-config.php.', 'dev3-ai-popup-builder' ) : s.help } disabled={ st.constant } />
								{ s.id === 'webhook' && (
									<>
										<TextField label={ __( 'Test URL', 'dev3-ai-popup-builder' ) } type="url" value={ hookUrl } onChange={ setHookUrl } placeholder="https://" />
										<div>
											<Button size="sm" onClick={ () => test( 'webhook' ) } disabled={ ! hookUrl } loading={ busy === 'test-webhook' }>
												{ __( 'Send a test event', 'dev3-ai-popup-builder' ) }
											</Button>
										</div>
									</>
								) }
							</Card>
						);
					} ) }
				</Columns>
			) }
		</>
	);
}

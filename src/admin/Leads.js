import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Drawer, ErrorNotice, FormGrid, Inline, Modal, PageHeader, ResponsiveTable, Select, TextField, useToast } from '../core3/ui';

const STATUS = {
	new: { tone: 'neutral', label: __( 'Stored', 'dev3-ai-popup-builder' ) },
	synced: { tone: 'success', label: __( 'Synced', 'dev3-ai-popup-builder' ) },
	partial: { tone: 'warning', label: __( 'Partly synced', 'dev3-ai-popup-builder' ) },
	failed: { tone: 'danger', label: __( 'Sync failed', 'dev3-ai-popup-builder' ) },
};

function LeadDrawer( { lead, onClose, onChanged } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	if ( ! lead ) {
		return null;
	}
	const resync = () => {
		setBusy( 'sync' );
		request( `leads/${ lead.id }/resync`, { method: 'POST' } )
			.then( ( l ) => {
				toast.success( __( 'Sync finished.', 'dev3-ai-popup-builder' ) );
				onChanged( l );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const remove = () => {
		setBusy( 'delete' );
		request( `leads/${ lead.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Lead deleted.', 'dev3-ai-popup-builder' ) );
				setConfirm( false );
				onChanged( null );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const rows = [
		[ __( 'Email', 'dev3-ai-popup-builder' ), lead.email ],
		[ __( 'Name', 'dev3-ai-popup-builder' ), lead.name ],
		[ __( 'Phone', 'dev3-ai-popup-builder' ), lead.phone ],
		...Object.keys( lead.fields || {} ).map( ( k ) => [ k, String( lead.fields[ k ] === true ? __( 'Yes', 'dev3-ai-popup-builder' ) : lead.fields[ k ] ) ] ),
		[ __( 'Campaign', 'dev3-ai-popup-builder' ), `${ lead.campaign } · ${ lead.variant.toUpperCase() }` ],
		[ __( 'Consent', 'dev3-ai-popup-builder' ), lead.consent ? lead.consent_text.replace( /<[^>]+>/g, '' ) : __( 'Not asked / not given', 'dev3-ai-popup-builder' ) ],
		[ __( 'Coupon', 'dev3-ai-popup-builder' ), lead.coupon ],
		[ __( 'Page', 'dev3-ai-popup-builder' ), lead.page_url ],
		[ __( 'Referrer', 'dev3-ai-popup-builder' ), lead.referrer ],
		[ 'UTM', Object.keys( lead.utm || {} ).map( ( k ) => `${ k }=${ lead.utm[ k ] }` ).join( ', ' ) ],
		[ __( 'Device', 'dev3-ai-popup-builder' ), lead.device ],
		[ __( 'Date', 'dev3-ai-popup-builder' ), lead.created_at ],
	].filter( ( r ) => r[ 1 ] );
	return (
		<>
			<Drawer
				open
				onClose={ onClose }
				title={ lead.email }
				description={ STATUS[ lead.status ] ? STATUS[ lead.status ].label : lead.status }
				footer={
					<>
						<Button variant="danger" icon="trash" onClick={ () => setConfirm( true ) }>
							{ __( 'Delete', 'dev3-ai-popup-builder' ) }
						</Button>
						<Button icon="refresh" onClick={ resync } loading={ busy === 'sync' }>
							{ __( 'Retry sync', 'dev3-ai-popup-builder' ) }
						</Button>
					</>
				}
			>
				<dl className="dev3pu-dl">
					{ rows.map( ( [ k, v ] ) => (
						<div key={ k }>
							<dt>{ k }</dt>
							<dd>{ v }</dd>
						</div>
					) ) }
				</dl>
				{ Object.keys( lead.sync || {} ).length > 0 && (
					<>
						<h3 className="dev3pu-h3">{ __( 'Integrations', 'dev3-ai-popup-builder' ) }</h3>
						<ul className="dev3pu-synclist">
							{ Object.keys( lead.sync ).map( ( s ) => (
								<li key={ s }>
									<Badge tone={ lead.sync[ s ].ok ? 'success' : 'danger' } dot>
										{ s }
									</Badge>{ ' ' }
									<span>{ lead.sync[ s ].message }</span>
								</li>
							) ) }
						</ul>
					</>
				) }
			</Drawer>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Delete this lead?', 'dev3-ai-popup-builder' ) }
				footer={
					<>
						<Button onClick={ () => setConfirm( false ) }>{ __( 'Cancel', 'dev3-ai-popup-builder' ) }</Button>
						<Button variant="danger" onClick={ remove } loading={ busy === 'delete' }>
							{ __( 'Delete', 'dev3-ai-popup-builder' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'It is removed from WordPress only, not from Mailchimp or Brevo.', 'dev3-ai-popup-builder' ) }</p>
			</Modal>
		</>
	);
}

/**
 * Leads screen.
 *
 * @return {Element} Screen.
 */
export default function Leads() {
	const [ filters, setFilters ] = useState( { campaign_id: '', status: '', search: '', from: '', to: '' } );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ campaigns, setCampaigns ] = useState( [] );
	const [ open, setOpen ] = useState( null );

	const load = () => {
		setError( null );
		const q = new URLSearchParams( { ...filters, page, per_page: 20 } );
		[ ...q.keys() ].forEach( ( k ) => ! q.get( k ) && q.delete( k ) );
		request( `leads?${ q.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ filters, page ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		request( 'campaigns/options' ).then( setCampaigns ).catch( () => {} );
	}, [] );
	const set = ( key ) => ( value ) => {
		setPage( 1 );
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
	};

	const columns = [
		{ key: 'email', label: __( 'Email', 'dev3-ai-popup-builder' ), primary: true },
		{ key: 'name', label: __( 'Name', 'dev3-ai-popup-builder' ), hideOnMobile: true },
		{ key: 'campaign', label: __( 'Campaign', 'dev3-ai-popup-builder' ) },
		{ key: 'status', label: __( 'Status', 'dev3-ai-popup-builder' ), render: ( r ) => ( STATUS[ r.status ] ? <Badge tone={ STATUS[ r.status ].tone } dot>{ STATUS[ r.status ].label }</Badge> : r.status ) },
		{ key: 'created_at', label: __( 'Date', 'dev3-ai-popup-builder' ), nowrap: true },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Leads', 'dev3-ai-popup-builder' ) }
				description={ __( 'Everyone who signed up through a campaign. Personal data export and erasure requests include these leads.', 'dev3-ai-popup-builder' ) }
				actions={
					<Button icon="file" href={ data ? data.export_url : undefined } disabled={ ! data || ! data.total }>
						{ __( 'Export CSV', 'dev3-ai-popup-builder' ) }
					</Button>
				}
			/>
			<Card>
				<FormGrid>
					<TextField label={ __( 'Search', 'dev3-ai-popup-builder' ) } value={ filters.search } onChange={ set( 'search' ) } placeholder={ __( 'Email, name or phone', 'dev3-ai-popup-builder' ) } type="search" />
					<Select label={ __( 'Campaign', 'dev3-ai-popup-builder' ) } value={ filters.campaign_id } onChange={ set( 'campaign_id' ) } options={ [ { value: '', label: __( 'All campaigns', 'dev3-ai-popup-builder' ) } ].concat( campaigns.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ) } />
					<Select label={ __( 'Status', 'dev3-ai-popup-builder' ) } value={ filters.status } onChange={ set( 'status' ) } options={ [ { value: '', label: __( 'Any status', 'dev3-ai-popup-builder' ) } ].concat( Object.keys( STATUS ).map( ( k ) => ( { value: k, label: STATUS[ k ].label } ) ) ) } />
					<TextField label={ __( 'From', 'dev3-ai-popup-builder' ) } type="date" value={ filters.from } onChange={ set( 'from' ) } />
					<TextField label={ __( 'To', 'dev3-ai-popup-builder' ) } type="date" value={ filters.to } onChange={ set( 'to' ) } />
				</FormGrid>
			</Card>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.items : [] }
					loading={ ! data && ! error }
					onRowClick={ ( r ) => setOpen( r ) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'users', title: __( 'No leads found', 'dev3-ai-popup-builder' ), description: __( 'Leads appear here as soon as visitors sign up.', 'dev3-ai-popup-builder' ) } }
				/>
			</Card>
			{ data && data.total > 0 && (
				<Inline align="end">
					<span className="dev3pu-muted">{ `${ data.total } ${ __( 'leads', 'dev3-ai-popup-builder' ) }` }</span>
				</Inline>
			) }
			{ open && (
				<LeadDrawer
					lead={ open }
					onClose={ () => setOpen( null ) }
					onChanged={ ( l ) => {
						setOpen( l ? { ...open, ...l } : null );
						load();
					} }
				/>
			) }
		</>
	);
}

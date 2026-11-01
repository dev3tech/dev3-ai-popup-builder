import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, Modal, PageHeader, ResponsiveTable, Tabs, formatNumber, useToast } from '../core3/ui';
import NewCampaign from './NewCampaign';
import { STATUS_LABELS, STATUS_TONES, TYPE_LABELS, pct } from './model';

/**
 * Campaign list.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Navigate.
 * @param {boolean}  props.openNew  Open the template gallery.
 * @param {Function} props.onUsage  After AI calls.
 * @return {Element} Screen.
 */
export default function Campaigns( { navigate, openNew = false, onUsage } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ filter, setFilter ] = useState( 'all' );
	const [ creating, setCreating ] = useState( openNew );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );

	const load = () => {
		setError( null );
		request( 'campaigns' ).then( setRows ).catch( setError );
	};
	useEffect( load, [] );
	useEffect( () => setCreating( openNew ), [ openNew ] );

	const act = ( row, path, data, message ) => {
		setBusy( row.id );
		return request( `campaigns/${ row.id }/${ path }`, { method: 'POST', data } )
			.then( ( res ) => {
				toast.success( message );
				load();
				return res;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( 0 ) );
	};
	const remove = () => {
		const row = confirm;
		setConfirm( null );
		request( `campaigns/${ row.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Campaign deleted.', 'dev3-ai-popup-builder' ) );
				load();
			} )
			.catch( ( err ) => toast.error( err ) );
	};

	const counts = ( rows || [] ).reduce( ( acc, r ) => ( { ...acc, [ r.status ]: ( acc[ r.status ] || 0 ) + 1 } ), {} );
	const shown = ( rows || [] ).filter( ( r ) => filter === 'all' || r.status === filter );
	const stop = ( fn ) => ( e ) => {
		e.stopPropagation();
		fn();
	};

	const columns = [
		{
			key: 'title',
			label: __( 'Campaign', 'dev3-ai-popup-builder' ),
			primary: true,
			sortable: true,
			render: ( r ) => (
				<span className="dev3pu-cell-title">
					<a href={ `#/campaigns/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>
						{ r.title }
					</a>
					{ r.ab && <Badge tone="info">{ sprintf( /* translators: %d: number of variants */ __( 'A/B · %d variants', 'dev3-ai-popup-builder' ), r.variants ) }</Badge> }
				</span>
			),
		},
		{ key: 'type', label: __( 'Type', 'dev3-ai-popup-builder' ), sortable: true, render: ( r ) => TYPE_LABELS[ r.type ] || r.type },
		{ key: 'status', label: __( 'Status', 'dev3-ai-popup-builder' ), sortable: true, render: ( r ) => <Badge tone={ STATUS_TONES[ r.status ] } dot>{ STATUS_LABELS[ r.status ] }</Badge> },
		{ key: 'views', label: __( 'Views (30d)', 'dev3-ai-popup-builder' ), align: 'end', sortable: true, render: ( r ) => formatNumber( r.views ) },
		{ key: 'leads', label: __( 'Leads (30d)', 'dev3-ai-popup-builder' ), align: 'end', sortable: true, render: ( r ) => formatNumber( r.leads ) },
		{ key: 'rate', label: __( 'Conversion', 'dev3-ai-popup-builder' ), align: 'end', sortable: true, render: ( r ) => pct( r.rate ) },
	];

	const rowActions = ( r ) => (
		<Inline gap={ 4 }>
			{ r.status === 'active' ? (
				<Button size="sm" variant="ghost" icon="pause" loading={ busy === r.id } onClick={ stop( () => act( r, 'status', { status: 'paused' }, __( 'Campaign paused.', 'dev3-ai-popup-builder' ) ) ) }>
					{ __( 'Pause', 'dev3-ai-popup-builder' ) }
				</Button>
			) : (
				<Button size="sm" variant="ghost" icon="bolt" loading={ busy === r.id } onClick={ stop( () => act( r, 'status', { status: 'active' }, __( 'Campaign is live.', 'dev3-ai-popup-builder' ) ) ) }>
					{ __( 'Activate', 'dev3-ai-popup-builder' ) }
				</Button>
			) }
			<Button size="sm" variant="ghost" icon="copy" label={ __( 'Duplicate', 'dev3-ai-popup-builder' ) } onClick={ stop( () => act( r, 'duplicate', {}, __( 'Copy created as a draft.', 'dev3-ai-popup-builder' ) ) ) } />
			<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-popup-builder' ) } onClick={ stop( () => setConfirm( r ) ) } />
		</Inline>
	);

	return (
		<>
			<PageHeader
				title={ __( 'Campaigns', 'dev3-ai-popup-builder' ) }
				description={ __( 'Popups, slide-ins, floating bars, full-screen mats and inline forms.', 'dev3-ai-popup-builder' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
						{ __( 'New campaign', 'dev3-ai-popup-builder' ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<div className="dev3pu-tabs-scroll">
				<Tabs
					variant="pills"
					label={ __( 'Filter by status', 'dev3-ai-popup-builder' ) }
					value={ filter }
					onChange={ setFilter }
					tabs={ [
						{ id: 'all', label: __( 'All', 'dev3-ai-popup-builder' ), badge: ( rows || [] ).length },
						{ id: 'active', label: STATUS_LABELS.active, badge: counts.active || 0 },
						{ id: 'draft', label: STATUS_LABELS.draft, badge: counts.draft || 0 },
						{ id: 'paused', label: STATUS_LABELS.paused, badge: counts.paused || 0 },
						{ id: 'archived', label: STATUS_LABELS.archived, badge: counts.archived || 0 },
					] }
				/>
			</div>
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ shown }
					loading={ ! rows && ! error }
					pageSize={ 20 }
					rowActions={ rowActions }
					onRowClick={ ( r ) => navigate( `campaigns/${ r.id }` ) }
					empty={ {
						icon: 'columns',
						title: filter === 'all' ? __( 'Create your first campaign', 'dev3-ai-popup-builder' ) : __( 'Nothing here', 'dev3-ai-popup-builder' ),
						description: __( 'Pick a template or describe what you want and DEV3 AI drafts it.', 'dev3-ai-popup-builder' ),
						action: (
							<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
								{ __( 'New campaign', 'dev3-ai-popup-builder' ) }
							</Button>
						),
					} }
				/>
			</Card>
			<NewCampaign
				open={ creating }
				onClose={ () => {
					setCreating( false );
					if ( openNew ) {
						navigate( 'campaigns' );
					}
				} }
				onCreated={ ( c ) => navigate( `campaigns/${ c.id }` ) }
				onUsage={ onUsage }
			/>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				title={ __( 'Delete campaign?', 'dev3-ai-popup-builder' ) }
				description={ confirm ? confirm.title : '' }
				footer={
					<>
						<Button onClick={ () => setConfirm( null ) }>{ __( 'Cancel', 'dev3-ai-popup-builder' ) }</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-popup-builder' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'Its statistics are deleted too. Leads it collected stay in the Leads list.', 'dev3-ai-popup-builder' ) }</p>
			</Modal>
		</>
	);
}

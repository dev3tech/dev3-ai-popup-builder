import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, formatNumber } from '../core3/ui';
import Chart from './Chart';
import { STATUS_LABELS, STATUS_TONES, TYPE_LABELS, pct } from './model';

function trend( cur, prev ) {
	if ( ! prev ) {
		return undefined;
	}
	const change = ( ( cur - prev ) / prev ) * 100;
	return { value: `${ Math.abs( change ).toFixed( 0 ) }%`, direction: change > 0.5 ? 'up' : change < -0.5 ? 'down' : 'flat', good: change >= 0 };
}

/**
 * Dashboard: 30-day KPIs, daily leads, top campaigns and recent leads.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Navigate.
 * @return {Element} Screen.
 */
export default function Dashboard( { settings, navigate } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'dashboard' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	const header = (
		<PageHeader
			title={ __( 'Dashboard', 'dev3-ai-popup-builder' ) }
			description={ __( 'How your popups, bars and forms performed in the last 30 days.', 'dev3-ai-popup-builder' ) }
			actions={
				<Button variant="primary" icon="plus" onClick={ () => navigate( 'campaigns/new' ) }>
					{ __( 'New campaign', 'dev3-ai-popup-builder' ) }
				</Button>
			}
		/>
	);
	if ( error ) {
		return (
			<>
				{ header }
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! data ) {
		return (
			<>
				{ header }
				<SkeletonCard lines={ 6 } />
			</>
		);
	}
	const { current, previous } = data.kpis;
	const columns = [
		{ key: 'title', label: __( 'Campaign', 'dev3-ai-popup-builder' ), primary: true, render: ( r ) => <a href={ `#/campaigns/${ r.id }` }>{ r.title }</a> },
		{ key: 'type', label: __( 'Type', 'dev3-ai-popup-builder' ), render: ( r ) => TYPE_LABELS[ r.type ] || r.type },
		{ key: 'status', label: __( 'Status', 'dev3-ai-popup-builder' ), render: ( r ) => <Badge tone={ STATUS_TONES[ r.status ] } dot>{ STATUS_LABELS[ r.status ] }</Badge> },
		{ key: 'views', label: __( 'Views', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => formatNumber( r.views ) },
		{ key: 'leads', label: __( 'Leads', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => formatNumber( r.leads ) },
		{ key: 'rate', label: __( 'Conversion', 'dev3-ai-popup-builder' ), align: 'end', render: ( r ) => pct( r.rate ) },
	];
	const leadCols = [
		{ key: 'email', label: __( 'Email', 'dev3-ai-popup-builder' ), primary: true },
		{ key: 'created_at', label: __( 'Date', 'dev3-ai-popup-builder' ), nowrap: true },
	];

	return (
		<>
			{ header }
			{ ! settings.api_key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-popup-builder' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-popup-builder' ) }</Button> }>
					{ __( 'DEV3 AI writes popup copy, offer variants for A/B tests and targeting suggestions. Everything else works without it.', 'dev3-ai-popup-builder' ) }
				</Notice>
			) }
			<StatGrid>
				<Stat label={ __( 'Views', 'dev3-ai-popup-builder' ) } value={ formatNumber( current.views ) } icon="chart" tone="brand" trend={ trend( current.views, previous.views ) } hint={ __( 'vs previous 30 days', 'dev3-ai-popup-builder' ) } />
				<Stat label={ __( 'Leads', 'dev3-ai-popup-builder' ) } value={ formatNumber( current.conversions ) } icon="users" tone="success" trend={ trend( current.conversions, previous.conversions ) } hint={ __( 'vs previous 30 days', 'dev3-ai-popup-builder' ) } />
				<Stat label={ __( 'Conversion rate', 'dev3-ai-popup-builder' ) } value={ pct( current.rate ) } icon="bolt" trend={ trend( current.rate, previous.rate ) } hint={ __( 'leads ÷ views', 'dev3-ai-popup-builder' ) } />
				<Stat label={ __( 'Active campaigns', 'dev3-ai-popup-builder' ) } value={ `${ data.active } / ${ data.campaigns }` } icon="columns" hint={ __( 'live on the site', 'dev3-ai-popup-builder' ) } />
			</StatGrid>
			<Card title={ __( 'Leads per day', 'dev3-ai-popup-builder' ) } description={ __( 'Form sign-ups and button clicks, last 30 days.', 'dev3-ai-popup-builder' ) }>
				{ data.series.some( ( s ) => s.views || s.conversions ) ? <Chart series={ data.series } measure="conversions" label={ __( 'Leads', 'dev3-ai-popup-builder' ) } /> : <EmptyState compact icon="chart" title={ __( 'No activity yet', 'dev3-ai-popup-builder' ) } description={ __( 'Publish a campaign and views and leads will show up here.', 'dev3-ai-popup-builder' ) } /> }
			</Card>
			<Columns min={ 360 }>
				<Card title={ __( 'Top campaigns', 'dev3-ai-popup-builder' ) } flush>
					<ResponsiveTable columns={ columns } rows={ data.top } onRowClick={ ( r ) => navigate( `campaigns/${ r.id }` ) } empty={ { icon: 'columns', title: __( 'No campaigns yet', 'dev3-ai-popup-builder' ), description: __( 'Start from a template in under a minute.', 'dev3-ai-popup-builder' ), action: <Button variant="primary" onClick={ () => navigate( 'campaigns/new' ) }>{ __( 'Create a campaign', 'dev3-ai-popup-builder' ) }</Button> } } />
				</Card>
				<Card title={ __( 'Recent leads', 'dev3-ai-popup-builder' ) } flush actions={ <Button size="sm" variant="ghost" href="#/leads">{ __( 'All leads', 'dev3-ai-popup-builder' ) }</Button> }>
					<ResponsiveTable columns={ leadCols } rows={ data.recent } empty={ { icon: 'users', title: __( 'No leads yet', 'dev3-ai-popup-builder' ) } } />
				</Card>
			</Columns>
		</>
	);
}

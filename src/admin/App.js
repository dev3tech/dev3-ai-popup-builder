import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Campaigns from './Campaigns';
import Editor from './editor/Editor';
import Leads from './Leads';
import Integrations from './Integrations';
import SettingsTab from './SettingsTab';
import './admin.css';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-popup-builder' ), icon: 'dashboard' },
	{ id: 'campaigns', label: __( 'Campaigns', 'dev3-ai-popup-builder' ), icon: 'columns' },
	{ id: 'leads', label: __( 'Leads', 'dev3-ai-popup-builder' ), icon: 'users' },
	{ id: 'integrations', label: __( 'Integrations', 'dev3-ai-popup-builder' ), icon: 'bolt' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-popup-builder' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-popup-builder' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-popup-builder' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute( NAV.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );
	const onUsage = () => setMeter( ( n ) => n + 1 );
	const editingId = route.id === 'campaigns' && route.params && route.params[ 0 ] ? parseInt( route.params[ 0 ], 10 ) : 0;

	const screens = {
		dashboard: () => <Dashboard settings={ settings } navigate={ navigate } onUsage={ onUsage } />,
		campaigns: () => ( editingId ? <Editor key={ editingId } id={ editingId } settings={ settings } navigate={ navigate } onUsage={ onUsage } /> : <Campaigns settings={ settings } navigate={ navigate } onUsage={ onUsage } openNew={ !! route.params && route.params[ 0 ] === 'new' } /> ),
		leads: () => <Leads />,
		integrations: () => <Integrations />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-popup-builder' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-popup-builder' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-popup-builder' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-popup-builder' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Popup & Lead Capture Builder for WordPress' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-popup-builder' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}

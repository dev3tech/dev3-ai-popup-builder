import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../../core3/api';
import {
	Badge,
	Button,
	ErrorNotice,
	Inline,
	PageHeader,
	SkeletonCard,
	TextField,
	useToast,
} from '../../core3/ui';
import Preview from './Preview';
import {
	AbPane,
	ContentPane,
	DesignPane,
	FormPane,
	IntegrationsPane,
	ResultsPane,
	TargetingPane,
	TriggersPane,
} from './panes';
import { STATUS_LABELS, STATUS_TONES, TYPE_LABELS, setIn } from '../model';

/**
 * Two-pane campaign editor with a live preview.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Campaign id.
 * @param {Object}   props.settings Plugin settings.
 * @param {Function} props.onUsage  After AI calls.
 * @return {Element} Editor.
 */
export default function Editor( { id, settings, onUsage } ) {
	const toast = useToast();
	const [ campaign, setCampaign ] = useState( null );
	const [ saved, setSaved ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ options, setOptions ] = useState( null );
	const [ pane, setPane ] = useState( 'content' );
	const [ variantId, setVariantId ] = useState( 'a' );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ success, setSuccess ] = useState( false );
	const [ saving, setSaving ] = useState( '' );

	useEffect( () => {
		request( `campaigns/${ id }` )
			.then( ( c ) => {
				setCampaign( c );
				setSaved(
					JSON.stringify( [
						c.title,
						c.type,
						c.priority,
						c.starts_at,
						c.ends_at,
						c.config,
					] )
				);
			} )
			.catch( setError );
		request( 'targeting/options' )
			.then( setOptions )
			.catch( () =>
				setOptions( {
					post_types: [],
					terms: [],
					roles: [],
					woocommerce: false,
					coupons: false,
				} )
			);
	}, [ id ] );

	const dirty = useMemo(
		() =>
			campaign &&
			JSON.stringify( [
				campaign.title,
				campaign.type,
				campaign.priority,
				campaign.starts_at,
				campaign.ends_at,
				campaign.config,
			] ) !== saved,
		[ campaign, saved ]
	);
	useEffect( () => {
		const warn = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	if ( error ) {
		return (
			<>
				<PageHeader
					title={ __( 'Campaign', 'dev3-ai-popup-builder' ) }
					back={ {
						href: '#/campaigns',
						label: __( 'Campaigns', 'dev3-ai-popup-builder' ),
					} }
				/>
				<ErrorNotice error={ error } />
			</>
		);
	}
	if ( ! campaign || ! options ) {
		return <SkeletonCard lines={ 8 } />;
	}

	const update = ( path, value ) =>
		setCampaign( ( c ) => setIn( c, path, value ) );
	const setConfig = ( fn ) =>
		setCampaign( ( c ) => ( { ...c, config: fn( c.config ) } ) );
	const vIndex = Math.max(
		0,
		campaign.config.variants.findIndex( ( v ) => v.id === variantId )
	);
	const variant = campaign.config.variants[ vIndex ];

	const save = ( status ) => {
		setSaving( status || 'save' );
		const body = {
			title: campaign.title,
			type: campaign.type,
			priority: campaign.priority,
			starts_at: campaign.starts_at,
			ends_at: campaign.ends_at,
			config: campaign.config,
		};
		if ( status ) {
			body.status = status;
		}
		return request( `campaigns/${ id }`, { method: 'PUT', data: body } )
			.then( ( c ) => {
				setCampaign( c );
				setSaved(
					JSON.stringify( [
						c.title,
						c.type,
						c.priority,
						c.starts_at,
						c.ends_at,
						c.config,
					] )
				);
				if ( ! c.config.variants.some( ( v ) => v.id === variantId ) ) {
					setVariantId( c.config.variants[ 0 ].id );
				}
				let message = __( 'Saved.', 'dev3-ai-popup-builder' );
				if ( status === 'active' ) {
					message = __(
						'Published. The campaign is live.',
						'dev3-ai-popup-builder'
					);
				} else if ( status === 'paused' ) {
					message = __( 'Paused.', 'dev3-ai-popup-builder' );
				}
				toast.success( message );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( '' ) );
	};

	const isForm = campaign.config.form.mode === 'form';
	const panes = [
		{ id: 'content', label: __( 'Content', 'dev3-ai-popup-builder' ) },
		{ id: 'form', label: __( 'Form & offer', 'dev3-ai-popup-builder' ) },
		{ id: 'design', label: __( 'Design', 'dev3-ai-popup-builder' ) },
		{
			id: 'triggers',
			label:
				campaign.type === 'inline'
					? __( 'Placement', 'dev3-ai-popup-builder' )
					: __( 'Triggers', 'dev3-ai-popup-builder' ),
		},
		{ id: 'targeting', label: __( 'Targeting', 'dev3-ai-popup-builder' ) },
		{
			id: 'ab',
			label: __( 'A/B test', 'dev3-ai-popup-builder' ),
			badge:
				campaign.config.variants.length > 1
					? campaign.config.variants.length
					: undefined,
		},
		isForm && {
			id: 'integrations',
			label: __( 'Integrations', 'dev3-ai-popup-builder' ),
		},
		{ id: 'results', label: __( 'Results', 'dev3-ai-popup-builder' ) },
	].filter( Boolean );
	const props = {
		campaign,
		variant,
		vIndex,
		update,
		setConfig,
		options,
		settings,
		onUsage,
		setVariantId,
		campaignId: id,
	};
	const PANES = {
		content: ContentPane,
		form: FormPane,
		design: DesignPane,
		triggers: TriggersPane,
		targeting: TargetingPane,
		ab: AbPane,
		integrations: IntegrationsPane,
		results: ResultsPane,
	};
	const Pane = PANES[ pane ] || ContentPane;
	const live = campaign.status === 'active';

	return (
		<>
			<PageHeader
				back={ {
					href: '#/campaigns',
					label: __( 'Campaigns', 'dev3-ai-popup-builder' ),
				} }
				eyebrow={ TYPE_LABELS[ campaign.type ] }
				title={ campaign.title }
				actions={
					<Inline gap={ 8 }>
						<Badge tone={ STATUS_TONES[ campaign.status ] } dot>
							{ STATUS_LABELS[ campaign.status ] }
						</Badge>
						{ dirty && (
							<Badge tone="warning">
								{ __(
									'Unsaved changes',
									'dev3-ai-popup-builder'
								) }
							</Badge>
						) }
						<Button
							variant="ghost"
							icon="external"
							href={ campaign.preview_url }
							target="_blank"
							rel="noopener"
						>
							{ __( 'Preview on site', 'dev3-ai-popup-builder' ) }
						</Button>
						<Button
							onClick={ () => save() }
							loading={ saving === 'save' }
							disabled={ !! saving }
						>
							{ live
								? __( 'Update', 'dev3-ai-popup-builder' )
								: __( 'Save draft', 'dev3-ai-popup-builder' ) }
						</Button>
						{ live ? (
							<Button
								variant="secondary"
								icon="pause"
								onClick={ () => save( 'paused' ) }
								loading={ saving === 'paused' }
								disabled={ !! saving }
							>
								{ __( 'Pause', 'dev3-ai-popup-builder' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								icon="bolt"
								onClick={ () => save( 'active' ) }
								loading={ saving === 'active' }
								disabled={ !! saving }
							>
								{ __( 'Publish', 'dev3-ai-popup-builder' ) }
							</Button>
						) }
					</Inline>
				}
			/>
			<div
				className={ `dev3pu-ed${
					pane === 'results' ? ' is-wide' : ''
				}` }
			>
				<div className="dev3pu-ed__side">
					<div className="dev3pu-ed__title">
						<TextField
							label={ __(
								'Campaign name (internal)',
								'dev3-ai-popup-builder'
							) }
							value={ campaign.title }
							onChange={ ( v ) => update( [ 'title' ], v ) }
							maxLength={ 190 }
						/>
					</div>
					<nav
						className="dev3pu-ed__nav"
						aria-label={ __(
							'Editor sections',
							'dev3-ai-popup-builder'
						) }
					>
						{ panes.map( ( p ) => (
							<button
								key={ p.id }
								type="button"
								className={ `dev3pu-ed__navbtn${
									pane === p.id ? ' is-on' : ''
								}` }
								aria-current={
									pane === p.id ? 'true' : undefined
								}
								onClick={ () => setPane( p.id ) }
							>
								{ p.label }
								{ p.badge && (
									<span className="dev3pu-ed__count">
										{ p.badge }
									</span>
								) }
							</button>
						) ) }
					</nav>
					<div className="dev3pu-ed__pane">
						<Pane { ...props } />
					</div>
				</div>
				{ pane !== 'results' && (
					<div className="dev3pu-ed__preview">
						<div className="dev3pu-ed__pvbar">
							<div
								className="dev3pu-seg"
								role="group"
								aria-label={ __(
									'Preview device',
									'dev3-ai-popup-builder'
								) }
							>
								<button
									type="button"
									aria-pressed={ device === 'desktop' }
									className={
										device === 'desktop' ? 'is-on' : ''
									}
									onClick={ () => setDevice( 'desktop' ) }
								>
									{ __( 'Desktop', 'dev3-ai-popup-builder' ) }
								</button>
								<button
									type="button"
									aria-pressed={ device === 'mobile' }
									className={
										device === 'mobile' ? 'is-on' : ''
									}
									onClick={ () => setDevice( 'mobile' ) }
								>
									{ __( 'Phone', 'dev3-ai-popup-builder' ) }
								</button>
							</div>
							{ campaign.config.variants.length > 1 && (
								<div
									className="dev3pu-seg"
									role="group"
									aria-label={ __(
										'Preview variant',
										'dev3-ai-popup-builder'
									) }
								>
									{ campaign.config.variants.map( ( v ) => (
										<button
											key={ v.id }
											type="button"
											aria-pressed={ variant.id === v.id }
											className={
												variant.id === v.id
													? 'is-on'
													: ''
											}
											onClick={ () =>
												setVariantId( v.id )
											}
										>
											{ v.name }
										</button>
									) ) }
								</div>
							) }
							<div
								className="dev3pu-seg"
								role="group"
								aria-label={ __(
									'Preview state',
									'dev3-ai-popup-builder'
								) }
							>
								<button
									type="button"
									aria-pressed={ ! success }
									className={ ! success ? 'is-on' : '' }
									onClick={ () => setSuccess( false ) }
								>
									{ __( 'Form', 'dev3-ai-popup-builder' ) }
								</button>
								<button
									type="button"
									aria-pressed={ success }
									className={ success ? 'is-on' : '' }
									onClick={ () => setSuccess( true ) }
								>
									{ __( 'Success', 'dev3-ai-popup-builder' ) }
								</button>
							</div>
						</div>
						<Preview
							campaign={ campaign }
							variantId={ variant.id }
							device={ device }
							mobileSafe={ settings.mobile_safe !== false }
							success={ success }
						/>
						{ device === 'mobile' &&
							settings.mobile_safe !== false &&
							( campaign.type === 'popup' ||
								campaign.type === 'fullscreen' ) && (
								<p className="dev3pu-ed__note">
									{ __(
										'Mobile-safe mode: on phones this shows as a non-blocking bottom sheet unless the visitor opens it with a click.',
										'dev3-ai-popup-builder'
									) }
								</p>
							) }
					</div>
				) }
			</div>
		</>
	);
}

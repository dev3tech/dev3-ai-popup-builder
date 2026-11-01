import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, ErrorNotice, Inline, Modal, Notice, SkeletonCard, Stack, Tabs, Textarea, useToast } from '../core3/ui';
import Preview from './editor/Preview';
import { TYPE_LABELS, triggerSummary } from './model';

const TYPES = [ 'all', 'popup', 'slidein', 'bar', 'fullscreen', 'inline' ];
const EXAMPLES = [
	__( '10% off for first-time visitors who are about to leave the shop', 'dev3-ai-popup-builder' ),
	__( 'A slide-in on blog posts offering our free SEO checklist', 'dev3-ai-popup-builder' ),
	__( 'A top bar announcing free shipping over $50 this weekend', 'dev3-ai-popup-builder' ),
];

/**
 * Template gallery + "Describe with DEV3 AI". Everything is created as a draft.
 *
 * @param {Object}   props           Props.
 * @param {boolean}  props.open      Open.
 * @param {Function} props.onClose   Close.
 * @param {Function} props.onCreated Created campaign callback.
 * @param {Function} props.onUsage   After AI calls.
 * @return {Element} Modal.
 */
export default function NewCampaign( { open, onClose, onCreated, onUsage } ) {
	const toast = useToast();
	const [ tab, setTab ] = useState( 'templates' );
	const [ type, setType ] = useState( 'all' );
	const [ templates, setTemplates ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ creating, setCreating ] = useState( '' );
	const [ brief, setBrief ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ generating, setGenerating ] = useState( false );
	const [ aiError, setAiError ] = useState( null );

	useEffect( () => {
		if ( open && ! templates ) {
			request( 'templates' ).then( setTemplates ).catch( setError );
		}
	}, [ open, templates ] );

	const create = ( data, key ) => {
		setCreating( key );
		request( 'campaigns', { method: 'POST', data } )
			.then( ( c ) => {
				toast.success( __( 'Draft created. Nothing is live until you publish it.', 'dev3-ai-popup-builder' ) );
				onCreated( c );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setCreating( '' ) );
	};
	const generate = () => {
		setGenerating( true );
		setAiError( null );
		request( 'ai/campaign', { method: 'POST', data: { brief } } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setGenerating( false );
				onUsage && onUsage();
			} );
	};

	const shown = ( templates || [] ).filter( ( t ) => type === 'all' || t.type === type );
	return (
		<Modal open={ open } onClose={ onClose } size="xl" title={ __( 'New campaign', 'dev3-ai-popup-builder' ) } description={ __( 'Start from a template or let DEV3 AI draft one. It is saved as a draft for you to review.', 'dev3-ai-popup-builder' ) }>
			<div className="dev3pu-tabs-scroll">
				<Tabs
					value={ tab }
					onChange={ setTab }
					label={ __( 'How to start', 'dev3-ai-popup-builder' ) }
					tabs={ [
						{ id: 'templates', label: __( 'Templates', 'dev3-ai-popup-builder' ) },
						{ id: 'ai', label: __( 'Describe with DEV3 AI', 'dev3-ai-popup-builder' ) },
					] }
				/>
			</div>
			{ tab === 'templates' && (
				<Stack gap={ 16 }>
					<div className="dev3pu-chips" role="group" aria-label={ __( 'Filter templates by format', 'dev3-ai-popup-builder' ) }>
						{ TYPES.map( ( t ) => (
							<button key={ t } type="button" className={ `dev3pu-chip${ type === t ? ' is-on' : '' }` } aria-pressed={ type === t } onClick={ () => setType( t ) }>
								{ t === 'all' ? __( 'All formats', 'dev3-ai-popup-builder' ) : TYPE_LABELS[ t ] }
							</button>
						) ) }
					</div>
					<ErrorNotice error={ error } />
					{ ! templates && ! error && <SkeletonCard lines={ 4 } /> }
					<div className="dev3pu-tpl-grid">
						{ shown.map( ( t ) => (
							<div key={ t.id } className="dev3pu-tpl">
								<Preview campaign={ t.campaign } thumb />
								<div className="dev3pu-tpl__body">
									<div className="dev3pu-tpl__head">
										<h3>{ t.name }</h3>
										<Badge tone="brand">{ TYPE_LABELS[ t.type ] }</Badge>
									</div>
									<p>{ t.description }</p>
									{ t.requires && <Badge tone="warning">{ t.requires }</Badge> }
									<Button size="sm" variant="secondary" loading={ creating === t.id } onClick={ () => create( { template: t.id }, t.id ) } aria-label={ `${ __( 'Use template', 'dev3-ai-popup-builder' ) }: ${ t.name }` }>
										{ __( 'Use template', 'dev3-ai-popup-builder' ) }
									</Button>
								</div>
							</div>
						) ) }
					</div>
				</Stack>
			) }
			{ tab === 'ai' && (
				<Stack gap={ 16 }>
					<Textarea label={ __( 'Describe the campaign', 'dev3-ai-popup-builder' ) } value={ brief } onChange={ setBrief } rows={ 3 } maxLength={ 600 } placeholder={ EXAMPLES[ 0 ] } help={ __( 'Mention the goal, the offer and who should see it. DEV3 AI picks the format, copy, colours and triggers.', 'dev3-ai-popup-builder' ) } />
					<Inline gap={ 8 }>
						{ EXAMPLES.map( ( ex ) => (
							<button key={ ex } type="button" className="dev3pu-chip" onClick={ () => setBrief( ex ) }>
								{ ex }
							</button>
						) ) }
					</Inline>
					<div>
						<AiButton onClick={ generate } loading={ generating } disabled={ ! brief.trim() || generating }>
							{ draft ? __( 'Regenerate with DEV3 AI', 'dev3-ai-popup-builder' ) : undefined }
						</AiButton>
					</div>
					<ErrorNotice error={ aiError } onRetry={ generate } onDismiss={ () => setAiError( null ) } />
					{ draft && (
						<div className="dev3pu-ai-draft">
							<Notice tone="ai" title={ __( 'DEV3 AI draft · not saved yet', 'dev3-ai-popup-builder' ) }>
								{ `${ draft.campaign.title } · ${ TYPE_LABELS[ draft.campaign.type ] } · ${ triggerSummary( draft.campaign.config.triggers ) }` }
							</Notice>
							<Preview campaign={ draft.campaign } thumb />
							<Inline align="end" gap={ 8 }>
								<Button variant="ghost" onClick={ () => setDraft( null ) }>
									{ __( 'Reject', 'dev3-ai-popup-builder' ) }
								</Button>
								<Button variant="primary" icon="check" loading={ creating === 'ai' } onClick={ () => create( { campaign: draft.campaign }, 'ai' ) }>
									{ __( 'Create draft and edit', 'dev3-ai-popup-builder' ) }
								</Button>
							</Inline>
						</div>
					) }
				</Stack>
			) }
		</Modal>
	);
}

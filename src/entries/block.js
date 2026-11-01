/**
 * Blocks: "Lead form" (inline campaign, rendered on the server) and "Popup button" (opens an overlay campaign).
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl, Spinner, TextControl, ExternalLink } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import config, { restUrl } from '../core3/config';

const formIcon = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" strokeWidth="1.6" />
		<path d="M7 11h10M7 14.5h6" stroke="currentColor" strokeWidth="1.6" />
	</svg>
);
const buttonIcon = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<rect x="3" y="8" width="18" height="8" rx="4" fill="none" stroke="currentColor" strokeWidth="1.6" />
		<path d="M14 3h7v7" fill="none" stroke="currentColor" strokeWidth="1.6" />
	</svg>
);

let cache = null;
function useCampaigns() {
	const [ list, setList ] = useState( cache );
	useEffect( () => {
		if ( cache ) {
			return;
		}
		apiFetch( { url: restUrl( 'campaigns/options' ) } )
			.then( ( rows ) => {
				cache = rows;
				setList( rows );
			} )
			.catch( () => setList( [] ) );
	}, [] );
	return list;
}

const adminLink = ( id ) => `${ config.adminUrl || '/wp-admin/' }admin.php?page=dev3-ai-popup-builder#/campaigns${ id ? '/' + id : '' }`;

function Picker( { list, value, onChange, filter } ) {
	if ( ! list ) {
		return <Spinner />;
	}
	const options = [ { value: 0, label: __( 'Choose a campaign…', 'dev3-ai-popup-builder' ) } ].concat(
		list.filter( filter ).map( ( c ) => ( { value: c.value, label: c.status === 'active' ? c.label : `${ c.label } (${ c.status })` } ) )
	);
	return <SelectControl label={ __( 'Campaign', 'dev3-ai-popup-builder' ) } value={ value } options={ options } onChange={ ( v ) => onChange( Number( v ) ) } __nextHasNoMarginBottom />;
}

registerBlockType( 'dev3pu/form', {
	apiVersion: 2,
	title: __( 'Lead form', 'dev3-ai-popup-builder' ),
	description: __( 'An inline lead capture form from DEV3 AI Popups.', 'dev3-ai-popup-builder' ),
	category: 'widgets',
	icon: formIcon,
	keywords: [ 'newsletter', 'signup', 'optin', 'lead', 'email' ],
	attributes: { campaignId: { type: 'number', default: 0 }, className: { type: 'string', default: '' } },
	supports: { align: [ 'wide', 'full' ], html: false },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		const list = useCampaigns();
		const inline = ( c ) => c.type === 'inline';
		const picker = <Picker list={ list } value={ attributes.campaignId } onChange={ ( v ) => setAttributes( { campaignId: v } ) } filter={ inline } />;
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Lead form', 'dev3-ai-popup-builder' ) }>
						{ picker }
						<p>
							<ExternalLink href={ adminLink( attributes.campaignId ) }>{ attributes.campaignId ? __( 'Edit in DEV3 AI Popups', 'dev3-ai-popup-builder' ) : __( 'Create an inline campaign', 'dev3-ai-popup-builder' ) }</ExternalLink>
						</p>
					</PanelBody>
				</InspectorControls>
				{ attributes.campaignId ? (
					<ServerSideRender block="dev3pu/form" attributes={ { campaignId: attributes.campaignId } } />
				) : (
					<Placeholder icon={ formIcon } label={ __( 'Lead form', 'dev3-ai-popup-builder' ) } instructions={ __( 'Pick an inline campaign. Overlay campaigns (popups, bars, slide-ins) show on their own; use the Popup button block to open one on click.', 'dev3-ai-popup-builder' ) }>
						{ picker }
					</Placeholder>
				) }
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'dev3pu/button', {
	apiVersion: 2,
	title: __( 'Popup button', 'dev3-ai-popup-builder' ),
	description: __( 'A button that opens a popup, slide-in or bar when clicked.', 'dev3-ai-popup-builder' ),
	category: 'widgets',
	icon: buttonIcon,
	keywords: [ 'popup', 'modal', 'click', 'open' ],
	attributes: { campaignId: { type: 'number', default: 0 }, label: { type: 'string', default: '' }, className: { type: 'string', default: '' } },
	supports: { html: false },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		const list = useCampaigns();
		const overlay = ( c ) => c.type !== 'inline';
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Popup button', 'dev3-ai-popup-builder' ) }>
						<Picker list={ list } value={ attributes.campaignId } onChange={ ( v ) => setAttributes( { campaignId: v } ) } filter={ overlay } />
						<TextControl label={ __( 'Button text', 'dev3-ai-popup-builder' ) } value={ attributes.label } onChange={ ( v ) => setAttributes( { label: v } ) } __nextHasNoMarginBottom />
					</PanelBody>
				</InspectorControls>
				{ attributes.campaignId ? (
					<button type="button" className="wp-element-button" disabled>
						{ attributes.label || __( 'Open', 'dev3-ai-popup-builder' ) }
					</button>
				) : (
					<Placeholder icon={ buttonIcon } label={ __( 'Popup button', 'dev3-ai-popup-builder' ) } instructions={ __( 'Choose the campaign this button opens.', 'dev3-ai-popup-builder' ) }>
						<Picker list={ list } value={ attributes.campaignId } onChange={ ( v ) => setAttributes( { campaignId: v } ) } filter={ overlay } />
					</Placeholder>
				) }
			</div>
		);
	},
	save: () => null,
} );

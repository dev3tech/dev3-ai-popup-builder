import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber, VisuallyHidden } from '../core3/ui';

/**
 * Daily bar chart for one measure (one axis), with a hover/focus tooltip and a table for screen readers.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.series [ { day, views, conversions } ].
 * @param {string} props.measure Key to plot.
 * @param {string} props.label  Measure label.
 * @return {Element} Chart.
 */
export default function Chart( { series, measure = 'conversions', label } ) {
	const [ hover, setHover ] = useState( null );
	const max = Math.max( 1, ...series.map( ( s ) => s[ measure ] ) );
	const W = 600;
	const H = 160;
	const gap = 2;
	const bw = W / series.length;
	const ticks = [ 0, Math.round( max / 2 ), max ];
	const fmtDay = ( d ) => new Date( `${ d }T12:00:00` ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	const active = hover !== null ? series[ hover ] : null;
	return (
		<div className="dev3pu-chart">
			<div className="dev3pu-chart__plot">
				<div className="dev3pu-chart__axis" aria-hidden="true">
					{ ticks
						.slice()
						.reverse()
						.map( ( t ) => (
							<span key={ t }>{ formatNumber( t ) }</span>
						) ) }
				</div>
				<svg viewBox={ `0 0 ${ W } ${ H }` } preserveAspectRatio="none" role="img" aria-label={ label } onMouseLeave={ () => setHover( null ) }>
					{ ticks.map( ( t ) => (
						<line key={ t } x1="0" x2={ W } y1={ H - ( t / max ) * H } y2={ H - ( t / max ) * H } className="dev3pu-chart__grid" />
					) ) }
					{ series.map( ( s, i ) => {
						const v = s[ measure ];
						const h = v > 0 ? Math.max( 3, ( v / max ) * H ) : 0;
						return (
							<g key={ s.day } onMouseEnter={ () => setHover( i ) }>
								<rect x={ i * bw } y="0" width={ bw } height={ H } fill="transparent" />
								{ h > 0 && <rect x={ i * bw + gap / 2 } y={ H - h } width={ Math.max( 1, bw - gap ) } height={ h } rx="3" className={ hover === i ? 'dev3pu-chart__bar is-hover' : 'dev3pu-chart__bar' } /> }
							</g>
						);
					} ) }
				</svg>
				{ active && (
					<div className="dev3pu-chart__tip" style={ { left: `${ ( ( hover + 0.5 ) / series.length ) * 100 }%` } } aria-hidden="true">
						<strong>{ fmtDay( active.day ) }</strong>
						<span>
							{ label }: { formatNumber( active[ measure ] ) }
						</span>
						<span>
							{ __( 'Views', 'dev3-ai-popup-builder' ) }: { formatNumber( active.views ) }
						</span>
					</div>
				) }
			</div>
			<div className="dev3pu-chart__x" aria-hidden="true">
				<span>{ series[ 0 ] && fmtDay( series[ 0 ].day ) }</span>
				<span>{ series.length && fmtDay( series[ series.length - 1 ].day ) }</span>
			</div>
			<VisuallyHidden>
				<table>
					<caption>{ label }</caption>
					<tbody>
						{ series.map( ( s ) => (
							<tr key={ s.day }>
								<th scope="row">{ s.day }</th>
								<td>{ s[ measure ] }</td>
								<td>{ s.views }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</VisuallyHidden>
		</div>
	);
}

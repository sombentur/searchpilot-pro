/**
 * Search Performance view: KPIs, comparison chart, queries and pages.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const PALETTE = ui.PALETTE;
	const RANGES = [ '7d', '28d', '3m', '6m', '12m' ];

	/** Path for this site's URLs; host + path for other hosts (domain properties include subdomains). */
	function pageName( url ) {
		try {
			const u = new URL( url, SPP.boot.homeUrl );
			const home = new URL( SPP.boot.homeUrl );
			const bare = ( host ) => host.replace( /^www\./, '' );
			if ( bare( u.host ) !== bare( home.host ) ) {
				return fmt.url( u.host + u.pathname + u.search );
			}
		} catch ( e ) {
			return fmt.url( url );
		}
		const path = fmt.path( url );
		return path === '/' ? __( 'Home page', 'searchpilot-pro' ) : path;
	}

	const METRICS = {
		clicks: { label: __( 'Clicks', 'searchpilot-pro' ), color: PALETTE[ 0 ], format: ( v ) => fmt.number( v ), axisFormat: ( v ) => fmt.compact( v ) },
		impressions: { label: __( 'Impressions', 'searchpilot-pro' ), color: PALETTE[ 1 ], format: ( v ) => fmt.number( v ), axisFormat: ( v ) => fmt.compact( v ) },
		ctr: { label: __( 'CTR', 'searchpilot-pro' ), color: PALETTE[ 3 ], format: ( v ) => fmt.percent( v, 2 ), axisFormat: ( v ) => fmt.percent( v, 1 ) },
		position: { label: __( 'Average position', 'searchpilot-pro' ), color: PALETTE[ 2 ], format: ( v ) => fmt.position( v ), axisFormat: ( v ) => fmt.number( v, 1 ), invert: true },
	};

	/** delta + trend options; position shows the absolute change and is good when it goes down. */
	function trendParts( metric, current, previous, title ) {
		if ( current === null || current === undefined || previous === null || previous === undefined ) {
			return null;
		}
		if ( metric === 'position' ) {
			if ( ! current || ! previous ) {
				return null;
			}
			const diff = current - previous;
			return {
				delta: { pct: diff, dir: Math.abs( diff ) < 0.05 ? 'flat' : diff > 0 ? 'up' : 'down' },
				trend: { good: 'down', absolute: fmt.number( Math.abs( diff ), 1 ), title: title || __( 'Change in average position (lower is better)', 'searchpilot-pro' ) },
			};
		}
		if ( ! current && ! previous ) {
			return null;
		}
		return { delta: fmt.delta( current, previous ), trend: { title } };
	}

	function trendChip( metric, current, previous, title ) {
		const parts = trendParts( metric, current, previous, title );
		return parts ? ui.trend( parts.delta, Object.assign( { good: parts.trend.good }, parts.trend ) ) : null;
	}

	function valueWithTrend( metric, current, previous, compare ) {
		return h(
			'span',
			{ class: 'spp-row', style: { justifyContent: 'flex-end', gap: '6px', flexWrap: 'nowrap' } },
			h( 'span', null, METRICS[ metric ].format( current ) ),
			compare ? trendChip( metric, current, previous, sprintf( /* translators: %s: previous value. */ __( 'Previous period: %s', 'searchpilot-pro' ), METRICS[ metric ].format( previous ) ) ) : null
		);
	}

	/** Bing crawl statistics (last 30 days) from the Bing module's cached summary. */
	function crawlCard( crawl ) {
		const t = crawl.totals || {};
		const series = crawl.series || {};
		const tile = ( label, value, tone ) => ui.kpi( { label, value: value === null || value === undefined ? '—' : fmt.number( value ), tone: tone || null } );
		return ui.card( {
			title: __( 'Bing crawl', 'searchpilot-pro' ),
			subtitle: crawl.from ? sprintf( /* translators: 1: first day, 2: last day. */ __( 'Bingbot activity %1$s – %2$s', 'searchpilot-pro' ), fmt.date( crawl.from ), fmt.date( crawl.to ) ) : __( 'Bingbot activity, last 30 days', 'searchpilot-pro' ),
			body: ui.stack(
				ui.grid(
					4,
					tile( __( 'Crawled pages', 'searchpilot-pro' ), t.crawled_pages ),
					tile( __( 'Crawl errors', 'searchpilot-pro' ), t.crawl_errors, t.crawl_errors ? 'critical' : null ),
					tile( __( '4xx / 5xx responses', 'searchpilot-pro' ), ( t.code_4xx || 0 ) + ( t.code_5xx || 0 ), ( t.code_5xx || 0 ) ? 'high' : null ),
					tile( __( 'Blocked by robots.txt', 'searchpilot-pro' ), t.blocked_by_robots )
				),
				series.labels && series.labels.length > 1
					? ui.lineChart( {
							labels: series.labels,
							height: 180,
							series: [
								{ name: __( 'Crawled pages', 'searchpilot-pro' ), values: series.crawled_pages, color: PALETTE[ 2 ] },
								{ name: __( 'Crawl errors', 'searchpilot-pro' ), values: series.crawl_errors, color: PALETTE[ 4 ], axis: 'right' },
							],
					  } )
					: null,
				t.in_index !== null && t.in_index !== undefined ? h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), sprintf( /* translators: %s: pages. */ __( 'Bing reports %s pages of this site in its index.', 'searchpilot-pro' ), fmt.number( t.in_index ) ) ) : null
			),
		} );
	}

	function csvExport( type, state ) {
		return api.get( '/reports/export', { type, range: state.range, engine: state.engine } ).then( ( res ) => {
			if ( ! res.rows || ! res.rows.length ) {
				ui.toast( __( 'Nothing to export yet.', 'searchpilot-pro' ), 'warning' );
				return;
			}
			SPP.util.download( res.filename, SPP.util.toCsv( res.rows, res.columns ), 'text/csv;charset=utf-8' );
			ui.toast( sprintf( /* translators: %s: number of rows. */ __( 'Exported %s rows', 'searchpilot-pro' ), fmt.number( res.rows.length ) ) );
		} );
	}

	function chartFor( series, metrics, compareLabel ) {
		const list = [];
		metrics.forEach( ( m, i ) => {
			const def = METRICS[ m ];
			const axis = m === 'position' ? 'right' : i === 0 ? 'left' : 'right';
			list.push( { name: def.label, values: series[ m ] || [], color: def.color, axis, invert: !! def.invert, format: ( v, isAxis ) => ( isAxis ? def.axisFormat( v ) : def.format( v ) ) } );
			if ( series.compare && ! series.compare.empty ) {
				list.push( { name: def.label + ' (' + compareLabel + ')', values: series.compare[ m ] || [], color: def.color, axis, invert: !! def.invert, dashed: true, format: ( v, isAxis ) => ( isAxis ? def.axisFormat( v ) : def.format( v ) ) } );
			}
		} );
		return ui.lineChart( {
			labels: series.labels || [],
			series: list,
			height: 300,
			title: __( 'Search performance trend', 'searchpilot-pro' ),
			formatLabel: ( l ) => fmt.date( l, { month: 'short', day: 'numeric' } ),
			emptyText: __( 'Connect Google Search Console (or Bing) and run the first sync to see the trend.', 'searchpilot-pro' ),
		} );
	}

	/** Page detail modal: KPIs, daily trend and the queries it ranks for. */
	function openPage( url, state ) {
		const body = SPP.region(
			() =>
				api.get( '/performance/page', { url, range: state.range, engine: state.engine } ).then( ( p ) => {
					const t = p.totals;
					const source = p.engine === 'bing' ? __( 'Bing Webmaster Tools data', 'searchpilot-pro' ) : __( 'Google Search Console data', 'searchpilot-pro' );
					const kpis = t
						? ui.grid(
								4,
								Object.keys( METRICS ).map( ( m ) =>
									ui.kpi( {
										label: METRICS[ m ].label,
										value: m === 'ctr' ? fmt.percent( t.ctr, 1 ) : m === 'position' ? fmt.position( t.position ) : fmt.compact( t[ m ] ),
										delta: null,
										hint:
											t[ 'prev_' + m ] === null || t[ 'prev_' + m ] === undefined
												? __( 'No comparison for this period', 'searchpilot-pro' )
												: sprintf( /* translators: %s: previous value. */ __( 'Previous: %s', 'searchpilot-pro' ), m === 'ctr' ? fmt.percent( t.prev_ctr, 1 ) : m === 'position' ? fmt.position( t.prev_position ) : fmt.number( t[ 'prev_' + m ] ) ),
									} )
								)
						  )
						: ui.notice( { tone: 'info', text: __( 'This page has no stored search data for the selected period.', 'searchpilot-pro' ) } );
					const chart = p.series
						? chartFor( p.series, [ 'clicks', 'impressions' ], __( 'previous period', 'searchpilot-pro' ) )
						: ui.notice( { tone: 'info', title: __( 'Daily trend unavailable', 'searchpilot-pro' ), text: p.series_error || __( 'Search Console is not connected.', 'searchpilot-pro' ) } );
					const queries = ui.table( {
						dense: true,
						rows: p.queries || [],
						empty: __( 'No query data for this page yet (queries per page are stored for the 28-day and 3-month periods).', 'searchpilot-pro' ),
						columns: [
							{ key: 'query', label: __( 'Query', 'searchpilot-pro' ), render: ( r ) => h( 'span', { class: 'spp-break' }, r.query ) },
							{ key: 'clicks', label: __( 'Clicks', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.clicks ) },
							{ key: 'impressions', label: __( 'Impressions', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.impressions ) },
							{ key: 'ctr', label: __( 'CTR', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.percent( r.ctr, 1 ) },
							{ key: 'position', label: __( 'Position', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.position( r.position ) },
						],
					} );
					const actions = [ ui.button( { label: __( 'View page', 'searchpilot-pro' ), icon: 'external', size: 'small', href: p.url, target: '_blank' } ) ];
					if ( p.edit_link ) {
						actions.push( ui.button( { label: __( 'Edit post', 'searchpilot-pro' ), size: 'small', href: p.edit_link } ) );
					}
					if ( p.object_id ) {
						actions.push( ui.button( { label: __( 'Opportunities', 'searchpilot-pro' ), icon: 'sparkle', size: 'small', variant: 'ghost', href: SPP.pageUrl( 'opportunities', { post_id: p.object_id } ) } ) );
						actions.push( ui.button( { label: __( 'Indexing', 'searchpilot-pro' ), size: 'small', variant: 'ghost', href: SPP.pageUrl( 'indexing', { post_id: p.object_id } ) } ) );
					}
					return ui.stack(
						h( 'div', null, h( 'div', { class: 'spp-strong' }, p.title || p.display_url ), h( 'a', { class: 'spp-muted spp-small spp-break', href: p.url, target: '_blank', rel: 'noopener noreferrer' }, p.display_url ), h( 'div', null, ui.badge( source, 'info' ) ) ),
						h( 'div', { class: 'spp-row' }, actions ),
						kpis,
						ui.card( { title: __( 'Trend', 'searchpilot-pro' ), body: chart } ),
						p.engine === 'bing' ? null : ui.card( { title: __( 'Queries for this page', 'searchpilot-pro' ), flush: true, body: queries } )
					);
				} ),
			{ loader: true }
		);
		ui.modal( { title: __( 'Page performance', 'searchpilot-pro' ), size: 'large', body: body.el } );
	}

	SPP.registerView( 'performance', {
		mount( el ) {
			const state = {
				range: RANGES.indexOf( SPP.query.get( 'range' ) ) !== -1 ? SPP.query.get( 'range' ) : '28d',
				engine: SPP.query.get( 'engine' ) === 'bing' ? 'bing' : 'google',
				compare: SPP.query.get( 'compare' ) === 'yoy' ? 'yoy' : 'previous',
				tab: SPP.query.get( 'tab' ) === 'pages' ? 'pages' : 'queries',
				metrics: [ 'clicks', 'impressions' ],
				search: SPP.query.get( 'q' ),
				min: SPP.query.get( 'min' ),
			};

			const status = SPP.region( () => api.get( '/performance/status' ).then( renderStatus ), { loader: false } );
			const overview = SPP.region( () => api.get( '/performance', { range: state.range, engine: state.engine, compare: state.compare } ).then( renderOverview ) );
			const tableHolder = h( 'div' );
			let table = null;
			let overviewData = null;

			function renderStatus( st ) {
				const fresh = ( st.freshness || {} )[ state.engine ] || {};
				const last = ( st.last_sync || {} )[ state.engine ];
				const manage = SPP.boot.caps && SPP.boot.caps.manage;
				const bits = [ fresh.note || '' ];
				if ( fresh.latest ) {
					bits.push( sprintf( /* translators: %s: date. */ __( 'Data through %s.', 'searchpilot-pro' ), fmt.date( fresh.latest ) ) );
				}
				bits.push( last ? sprintf( /* translators: %s: relative time. */ __( 'Last synced %s.', 'searchpilot-pro' ), fmt.relative( last ) ) : __( 'Not synced yet.', 'searchpilot-pro' ) );
				const nodes = [ h( 'span', { class: 'spp-disclaimer spp-grow' }, SPP.icon( 'info', 14 ), bits.filter( Boolean ).join( ' ' ) ) ];
				if ( st.status === 'running' ) {
					nodes.push( h( 'div', { style: { minWidth: '220px' } }, ui.progress( { value: st.progress.done, max: st.progress.total, tone: 'gold', label: st.progress.current || __( 'Syncing…', 'searchpilot-pro' ), right: st.progress.percent + '%' } ) ) );
					setTimeout( () => status.refresh(), 6000 );
				} else if ( manage && st.connected && ( st.connected.google || st.connected.bing ) ) {
					nodes.push(
						ui.button( {
							label: __( 'Sync now', 'searchpilot-pro' ),
							icon: 'refresh',
							size: 'small',
							onClick: () =>
								api.post( '/performance/sync' ).then( ( res ) => {
									ui.toast( res.status === 'running' ? __( 'Sync started — it continues in the background.', 'searchpilot-pro' ) : __( 'Search data is up to date.', 'searchpilot-pro' ) );
									status.refresh();
									overview.refresh();
									if ( table ) {
										table.reload();
									}
								} ),
						} )
					);
				}
				const out = [ h( 'div', { class: 'spp-row is-between' }, nodes ) ];
				if ( st.connected && ! st.connected[ state.engine ] ) {
					out.unshift(
						ui.notice( {
							tone: 'gold',
							icon: 'globe',
							title: state.engine === 'bing' ? __( 'Bing Webmaster Tools is not connected', 'searchpilot-pro' ) : __( 'Google Search Console is not connected', 'searchpilot-pro' ),
							text: __( 'Stored data from earlier syncs is still shown. Connect the search engine to keep it up to date.', 'searchpilot-pro' ),
							actions: manage ? [ ui.button( { label: __( 'Connect search engines', 'searchpilot-pro' ), variant: 'primary', size: 'small', href: SPP.pageUrl( 'engines' ) } ) ] : null,
						} )
					);
				}
				if ( st.errors && st.errors.length && st.status !== 'running' ) {
					out.push( ui.notice( { tone: 'high', title: __( 'Some data could not be refreshed in the last sync', 'searchpilot-pro' ), text: st.errors.map( ( e ) => ( e.label ? e.label + ': ' : '' ) + e.message ).join( ' · ' ) } ) );
				}
				return ui.stack( ...out );
			}

			function metricChips() {
				return h(
					'div',
					{ class: 'spp-row', role: 'group', 'aria-label': __( 'Metrics shown in the chart', 'searchpilot-pro' ) },
					Object.keys( METRICS ).map( ( m ) => {
						const on = state.metrics.indexOf( m ) !== -1;
						return ui.button( {
							label: METRICS[ m ].label,
							size: 'small',
							variant: on ? 'primary' : null,
							icon: on ? 'check' : null,
							disabled: m === 'position' && state.engine === 'bing',
							onClick: () => {
								if ( on ) {
									if ( state.metrics.length > 1 ) {
										state.metrics = state.metrics.filter( ( x ) => x !== m );
									}
								} else {
									state.metrics = state.metrics.concat( [ m ] ).slice( -2 );
								}
								if ( overviewData ) {
									SPP.mount( overview.el, renderOverview( overviewData ) );
								}
							},
						} );
					} )
				);
			}

			function renderOverview( data ) {
				overviewData = data;
				const t = data.totals || {};
				const prev = state.compare === 'yoy' ? t.yoy || {} : t.prev || {};
				const compareLabel = state.compare === 'yoy' ? __( 'last year', 'searchpilot-pro' ) : __( 'previous period', 'searchpilot-pro' );
				const compareTitle = state.compare === 'yoy' ? __( 'Same period last year', 'searchpilot-pro' ) : __( 'Previous period', 'searchpilot-pro' );
				const complete = prev && prev.complete !== false && ( state.compare === 'yoy' ? !! t.yoy : t.comparable !== false );
				const partial = sprintf(
					/* translators: 1: covered days, 2: days in the period. */
					__( 'No comparison — only %1$s of %2$s days are stored', 'searchpilot-pro' ),
					fmt.number( prev.covered_days || 0 ),
					fmt.number( prev.window_days || t.window_days || 0 )
				);
				const kpis = Object.keys( METRICS ).map( ( m ) => {
					const value = t.has_data ? ( m === 'ctr' ? fmt.percent( t.ctr, 2 ) : m === 'position' ? fmt.position( t.position ) : fmt.compact( t[ m ] ) ) : '—';
					const opts = {
						label: METRICS[ m ].label,
						value: m === 'position' && state.engine === 'bing' ? '—' : value,
						spark: ( data.series && data.series[ m ] ? data.series[ m ] : [] ).filter( ( v ) => v !== null && v !== undefined ),
						sparkColor: METRICS[ m ].color,
					};
					if ( ! t.has_data ) {
						opts.hint = __( 'No data yet', 'searchpilot-pro' );
					} else if ( ! complete ) {
						opts.hint = partial;
					} else {
						const parts = trendParts( m, t[ m ], prev[ m ], sprintf( /* translators: %s: comparison label. */ __( 'Compared with the %s', 'searchpilot-pro' ), compareLabel ) );
						if ( parts && ! ( m === 'position' && state.engine === 'bing' ) ) {
							opts.delta = parts.delta;
							opts.trend = parts.trend;
						}
						opts.hint = sprintf( /* translators: 1: comparison label, 2: value. */ __( '%1$s: %2$s', 'searchpilot-pro' ), compareTitle, METRICS[ m ].format( prev[ m ] ) );
					}
					return ui.kpi( opts );
				} );
				const metrics = state.engine === 'bing' ? state.metrics.filter( ( m ) => m !== 'position' ) : state.metrics;
				const compareSeries = ( data.series && data.series.compare ) || {};
				const compareNote =
					t.has_data && ! complete
						? ui.notice( {
								tone: 'info',
								text:
									state.compare === 'yoy'
										? __( 'The same period last year is not fully stored (Search Console keeps about 16 months), so no year-over-year change is shown. The dashed line covers only the stored days.', 'searchpilot-pro' )
										: __( 'The previous period is not fully stored (Search Console keeps about 16 months; a newly connected site has less), so no change is shown. Choose a shorter period for a comparison.', 'searchpilot-pro' ),
						  } )
						: null;
				return ui.stack(
					ui.grid( 4, kpis ),
					ui.card( {
						title: __( 'Trend', 'searchpilot-pro' ),
						subtitle: t.from ? sprintf( /* translators: 1: first day, 2: last day, 3: comparison label. */ __( '%1$s – %2$s, dashed lines: %3$s', 'searchpilot-pro' ), fmt.date( t.from ), fmt.date( t.to ), compareLabel ) : '',
						actions: [ metricChips() ],
						body: [ compareNote, chartFor( data.series || {}, metrics.length ? metrics : [ 'clicks' ], compareSeries.complete === false ? compareLabel + ', ' + __( 'partly stored', 'searchpilot-pro' ) : compareLabel ) ],
					} ),
					data.lists_range && data.lists_range !== state.range
						? ui.notice( {
								tone: 'info',
								text: sprintf(
									/* translators: 1: requested range, 2: range used. */
									__( 'Query and page lists are not stored for %1$s from this search engine, so the tables below show the nearest stored period (%2$s).', 'searchpilot-pro' ),
									state.range,
									data.lists_range
								),
						  } )
						: null,
					state.engine === 'bing' && data.bing_crawl ? crawlCard( data.bing_crawl ) : null
				);
			}

			function buildTable() {
				const dim = state.tab;
				const columns = [];
				const tableNote = h( 'div' );
				let compare = true;
				if ( dim === 'queries' ) {
					columns.push( { key: 'query', label: __( 'Query', 'searchpilot-pro' ), sortable: true, render: ( r ) => h( 'span', { class: 'spp-break spp-strong' }, r.query ) } );
				} else {
					columns.push( {
						key: 'page',
						label: __( 'Page', 'searchpilot-pro' ),
						render: ( r ) =>
							h(
								'div',
								null,
								h( 'button', { type: 'button', class: 'spp-btn is-link', style: { padding: 0, height: 'auto', textAlign: 'left' }, onClick: () => openPage( r.page, state ) }, r.title || pageName( r.page ) ),
								r.title ? h( 'div', { class: 'spp-cell-sub spp-truncate', title: fmt.url( r.page ) }, pageName( r.page ) ) : null
							),
					} );
				}
				columns.push(
					{ key: 'clicks', label: __( 'Clicks', 'searchpilot-pro' ), sortable: true, align: 'num', render: ( r ) => valueWithTrend( 'clicks', r.clicks, r.prev_clicks, compare ) },
					{
						key: 'delta_clicks',
						label: __( 'Change', 'searchpilot-pro' ),
						sortable: true,
						align: 'num',
						render: ( r ) =>
							! compare || r.delta_clicks === null || r.delta_clicks === undefined
								? '—'
								: h( 'span', { class: r.delta_clicks > 0 ? 'spp-trend is-good' : r.delta_clicks < 0 ? 'spp-trend is-bad' : 'spp-trend is-flat' }, ( r.delta_clicks > 0 ? '▲ +' : r.delta_clicks < 0 ? '▼ ' : '' ) + fmt.number( r.delta_clicks ) ),
					},
					{ key: 'impressions', label: __( 'Impressions', 'searchpilot-pro' ), sortable: true, align: 'num', render: ( r ) => valueWithTrend( 'impressions', r.impressions, r.prev_impressions, compare ) },
					{ key: 'ctr', label: __( 'CTR', 'searchpilot-pro' ), sortable: true, align: 'num', render: ( r ) => fmt.percent( r.ctr, 1 ) },
					{ key: 'position', label: __( 'Position', 'searchpilot-pro' ), sortable: true, align: 'num', render: ( r ) => ( state.engine === 'bing' && ! r.position ? '—' : valueWithTrend( 'position', r.position, r.prev_position, compare ) ) }
				);
				if ( dim === 'pages' ) {
					columns.push( { key: 'actions', label: '', align: 'right', render: ( r ) => ui.button( { label: __( 'Details', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => openPage( r.page, state ) } ) } );
				}
				table = ui.dataTable( {
					path: dim === 'queries' ? '/performance/queries' : '/performance/pages',
					query: { range: state.range, engine: state.engine, search: state.search, min_impressions: state.min },
					sort: { key: 'clicks', dir: 'desc' },
					perPage: 25,
					dense: true,
					columns,
					map: ( items, data ) => {
						compare = data.prev_complete !== false;
						const notes = [];
						if ( ! compare ) {
							notes.push( __( 'Changes are not shown: the previous period of this range is not fully stored by Search Console / Bing.', 'searchpilot-pro' ) );
						}
						if ( data.requested_range && data.range && data.requested_range !== data.range ) {
							notes.push( sprintf( /* translators: 1: requested range, 2: range used. */ __( 'Showing the nearest stored period (%2$s) instead of %1$s.', 'searchpilot-pro' ), data.requested_range, data.range ) );
						}
						SPP.mount( tableNote, notes.length ? h( 'p', { class: 'spp-disclaimer', style: { padding: '0 20px' } }, SPP.icon( 'info', 14 ), notes.join( ' ' ) ) : null );
						return items;
					},
					empty: dim === 'queries' ? __( 'No queries for this period. Data appears after the first sync.', 'searchpilot-pro' ) : __( 'No pages for this period. Data appears after the first sync.', 'searchpilot-pro' ),
				} );
				SPP.mount( tableHolder, tableNote, table.el );
			}

			const tabs = ui.tabs( {
				tabs: [
					{ id: 'queries', label: __( 'Queries', 'searchpilot-pro' ) },
					{ id: 'pages', label: __( 'Pages', 'searchpilot-pro' ) },
				],
				active: state.tab,
				onChange: ( id ) => {
					state.tab = id;
					SPP.query.set( { tab: id === 'queries' ? '' : id } );
					buildTable();
				},
			} );

			const refreshAll = () => {
				SPP.query.set( { range: state.range, engine: state.engine === 'google' ? '' : state.engine, compare: state.compare === 'previous' ? '' : state.compare } );
				status.refresh();
				overview.refresh();
				buildTable();
			};

			const toolbar = ui.toolbar(
				ui.search( {
					value: state.search,
					placeholder: __( 'Filter queries or pages…', 'searchpilot-pro' ),
					onSearch: ( v ) => {
						state.search = v;
						SPP.query.set( { q: v } );
						if ( table ) {
							table.setQuery( { search: v } );
						}
					},
				} ),
				ui.select( {
					label: __( 'Minimum impressions', 'searchpilot-pro' ),
					value: state.min,
					options: [
						{ value: '', label: __( 'Any impressions', 'searchpilot-pro' ) },
						{ value: '10', label: __( '10+ impressions', 'searchpilot-pro' ) },
						{ value: '100', label: __( '100+ impressions', 'searchpilot-pro' ) },
						{ value: '1000', label: __( '1,000+ impressions', 'searchpilot-pro' ) },
						{ value: '10000', label: __( '10,000+ impressions', 'searchpilot-pro' ) },
					],
					onChange: ( v ) => {
						state.min = v;
						SPP.query.set( { min: v } );
						if ( table ) {
							table.setQuery( { min_impressions: v } );
						}
					},
				} ),
				ui.button( { label: __( 'Export CSV', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => csvExport( state.tab, state ) } )
			);

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Search Performance', 'searchpilot-pro' ),
					subtitle: __( 'Clicks, impressions, CTR and position from Google Search Console and Bing, with the change against an earlier period.', 'searchpilot-pro' ),
					actions: [
						ui.buttonGroup( {
							options: [
								{ value: 'google', label: __( 'Google', 'searchpilot-pro' ) },
								{ value: 'bing', label: __( 'Bing', 'searchpilot-pro' ) },
							],
							value: state.engine,
							onChange: ( v ) => {
								state.engine = v;
								if ( v === 'bing' ) {
									state.metrics = state.metrics.filter( ( m ) => m !== 'position' );
									if ( ! state.metrics.length ) {
										state.metrics = [ 'clicks' ];
									}
								}
								refreshAll();
							},
						} ),
						ui.rangePicker( {
							value: state.range,
							onChange: ( v ) => {
								state.range = v;
								refreshAll();
							},
						} ),
						ui.select( {
							label: __( 'Compare with', 'searchpilot-pro' ),
							value: state.compare,
							options: [
								{ value: 'previous', label: __( 'vs previous period', 'searchpilot-pro' ) },
								{ value: 'yoy', label: __( 'vs same period last year', 'searchpilot-pro' ) },
							],
							onChange: ( v ) => {
								state.compare = v;
								SPP.query.set( { compare: v === 'previous' ? '' : v } );
								overview.refresh();
							},
						} ),
					],
				} ),
				ui.stack(
					status.el,
					overview.el,
					ui.card( {
						flush: true,
						body: [ h( 'div', { style: { padding: '0 20px' } }, tabs, toolbar ), tableHolder ],
						footer: h( 'span', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'Search Console data is typically 2–3 days behind; Bing reports weekly. Lists show the top 5,000 queries and pages of each period; deltas compare with the previous period of the same length.', 'searchpilot-pro' ) ),
					} )
				)
			);
			buildTable();

			const pageParam = SPP.query.get( 'page_url' );
			if ( pageParam ) {
				openPage( pageParam, state );
				SPP.query.set( { page_url: '' } );
			}
		},
	} );
} )( window.wp, window.SPP );

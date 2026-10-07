/**
 * Reports view: schedule, stored reports, print-friendly viewer and CSV exports.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const PALETTE = ui.PALETTE;
	const SITEMAP_LABELS = {
		healthy: __( 'Healthy', 'searchpilot-pro' ),
		warning: __( 'Warnings', 'searchpilot-pro' ),
		error: __( 'Errors', 'searchpilot-pro' ),
		disabled: __( 'Disabled', 'searchpilot-pro' ),
		unknown: __( 'Not checked yet', 'searchpilot-pro' ),
	};
	const TYPE_LABELS = {
		weekly: __( 'Weekly', 'searchpilot-pro' ),
		monthly: __( 'Monthly', 'searchpilot-pro' ),
		custom: __( 'Custom', 'searchpilot-pro' ),
	};

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

	const canManage = () => !! ( SPP.boot.caps && SPP.boot.caps.manage );

	/** Prints only the report modal (admin.css hides everything else under body.spp-printing). */
	function printReport() {
		document.body.classList.add( 'spp-printing' );
		const done = () => {
			document.body.classList.remove( 'spp-printing' );
			window.removeEventListener( 'afterprint', done );
		};
		window.addEventListener( 'afterprint', done );
		window.print();
		setTimeout( done, 1000 );
	}

	function sentToast( res ) {
		ui.toast( sprintf( /* translators: %d: number of recipients. */ _n( 'Report sent to %d recipient.', 'Report sent to %d recipients.', res.recipients, 'searchpilot-pro' ), res.recipients ) );
	}

	function periodLabel( r ) {
		return fmt.date( r.period_start ) + ' – ' + fmt.date( r.period_end );
	}

	function engineKpis( label, e, withPosition ) {
		if ( ! e || ! e.has_data ) {
			return ui.card( {
				title: label,
				body: h( 'p', { class: 'spp-muted' }, e && e.connected ? __( 'No data for this period yet (search engines report with a delay).', 'searchpilot-pro' ) : __( 'Not connected.', 'searchpilot-pro' ) ),
			} );
		}
		const c = e.current || {};
		const p = e.previous || {};
		const compare = e.comparable !== false;
		const tile = ( metricLabel, value, delta, prevText, trend ) =>
			ui.kpi(
				compare
					? { label: metricLabel, value, delta, trend, hint: sprintf( /* translators: %s: value. */ __( 'Previous: %s', 'searchpilot-pro' ), prevText ) }
					: { label: metricLabel, value, hint: __( 'No comparison', 'searchpilot-pro' ) }
			);
		const tiles = [
			tile( __( 'Clicks', 'searchpilot-pro' ), fmt.number( c.clicks ), fmt.delta( c.clicks, p.clicks ), fmt.number( p.clicks ) ),
			tile( __( 'Impressions', 'searchpilot-pro' ), fmt.number( c.impressions ), fmt.delta( c.impressions, p.impressions ), fmt.number( p.impressions ) ),
			tile( __( 'CTR', 'searchpilot-pro' ), fmt.percent( c.ctr, 1 ), fmt.delta( c.ctr, p.ctr ), fmt.percent( p.ctr, 1 ) ),
		];
		if ( withPosition ) {
			const diff = c.position && p.position ? c.position - p.position : null;
			tiles.push(
				tile(
					__( 'Average position', 'searchpilot-pro' ),
					fmt.position( c.position ),
					diff === null ? null : { pct: diff, dir: Math.abs( diff ) < 0.05 ? 'flat' : diff > 0 ? 'up' : 'down' },
					fmt.position( p.position ),
					{ good: 'down', absolute: diff === null ? '' : fmt.number( Math.abs( diff ), 1 ), title: __( 'Change in average position (lower is better)', 'searchpilot-pro' ) }
				)
			);
		}
		return h(
			'div',
			null,
			h( 'h3', { class: 'spp-card-title', style: { margin: '0 0 10px' } }, label ),
			ui.grid( tiles.length, tiles ),
			compare
				? null
				: h(
						'p',
						{ class: 'spp-disclaimer', style: { marginTop: '8px' } },
						SPP.icon( 'info', 14 ),
						sprintf(
							/* translators: %s: number of days. */
							__( 'No change is shown: the stored search data covers only %s days of the previous period (Search Console keeps about 16 months; newly connected sites have less).', 'searchpilot-pro' ),
							fmt.number( e.previous_covered || 0 )
						)
				  ),
			e.partial && e.latest ? h( 'p', { class: 'spp-disclaimer', style: { marginTop: '8px' } }, SPP.icon( 'info', 14 ), sprintf( /* translators: %s: date. */ __( 'Data available up to %s; the last days of the period are not reported yet.', 'searchpilot-pro' ), fmt.date( e.latest ) ) ) : null
		);
	}

	function rowsTable( rows, kind, compare ) {
		return ui.table( {
			dense: true,
			rows: rows || [],
			empty: __( 'No data for this period.', 'searchpilot-pro' ),
			columns: [
				kind === 'query'
					? { key: 'query', label: __( 'Query', 'searchpilot-pro' ), render: ( r ) => h( 'span', { class: 'spp-break' }, r.query ) }
					: { key: 'page', label: __( 'Page', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.page, r.title || pageName( r.page ) ) },
				{ key: 'clicks', label: __( 'Clicks', 'searchpilot-pro' ), align: 'num', render: ( r ) => h( 'span', { class: 'spp-row', style: { justifyContent: 'flex-end', gap: '6px', flexWrap: 'nowrap' } }, fmt.number( r.clicks ), compare && r.prev_clicks !== null && r.prev_clicks !== undefined ? ui.trend( fmt.delta( r.clicks, r.prev_clicks ) ) : null ) },
				{ key: 'impressions', label: __( 'Impressions', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.impressions ) },
				{ key: 'position', label: __( 'Position', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.position( r.position ) },
			],
		} );
	}

	function changeTable( rows, compare ) {
		return ui.table( {
			dense: true,
			rows: rows || [],
			empty: compare ? __( 'No notable changes.', 'searchpilot-pro' ) : __( 'Not available: the previous period is not fully stored.', 'searchpilot-pro' ),
			columns: [
				{ key: 'page', label: __( 'Page', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.page, r.title || pageName( r.page ) ) },
				{ key: 'delta_clicks', label: __( 'Change', 'searchpilot-pro' ), align: 'num', render: ( r ) => h( 'span', { class: r.delta_clicks >= 0 ? 'spp-trend is-good' : 'spp-trend is-bad' }, ( r.delta_clicks >= 0 ? '▲ +' : '▼ ' ) + fmt.number( r.delta_clicks ) ) },
			],
		} );
	}

	function healthList( d ) {
		const idx = d.index || {};
		const iss = d.issues || {};
		const open = iss.open || {};
		const auto = d.automation || {};
		const sub = d.submissions || {};
		const opps = d.opportunities || {};
		const pairs = [
			[ __( 'Indexed URLs', 'searchpilot-pro' ), sprintf( /* translators: 1: indexed, 2: total. */ __( '%1$s of %2$s', 'searchpilot-pro' ), fmt.number( idx.indexed || 0 ), fmt.number( idx.total || 0 ) ) ],
			[ __( 'Not indexed', 'searchpilot-pro' ), fmt.number( idx.not_indexed || 0 ) ],
			[ __( 'Crawl errors (4xx/5xx)', 'searchpilot-pro' ), fmt.number( ( idx.errors_4xx || 0 ) + ( idx.errors_5xx || 0 ) ) ],
			[ __( 'Open issues', 'searchpilot-pro' ), h( 'span', { class: 'spp-row' }, ui.badge( fmt.number( open.critical || 0 ), 'critical', { title: __( 'Critical', 'searchpilot-pro' ) } ), ui.badge( fmt.number( open.high || 0 ), 'high', { title: __( 'High', 'searchpilot-pro' ) } ), ui.badge( fmt.number( open.medium || 0 ), 'medium', { title: __( 'Medium', 'searchpilot-pro' ) } ) ) ],
			[ __( 'Issues resolved in the period', 'searchpilot-pro' ), fmt.number( iss.resolved || 0 ) ],
			[ __( 'Content opportunities', 'searchpilot-pro' ), fmt.number( opps.total || 0 ) ],
			[ __( 'Automation', 'searchpilot-pro' ), sprintf( /* translators: 1: changes, 2: automatic, 3: undone. */ __( '%1$s changes · %2$s automatic · %3$s undone', 'searchpilot-pro' ), fmt.number( auto.total || 0 ), fmt.number( auto.automatic || 0 ), fmt.number( auto.reverted || 0 ) ) ],
			[ __( 'Search-engine submissions', 'searchpilot-pro' ), sprintf( /* translators: 1: sent, 2: failed, 3: held. */ __( '%1$s sent · %2$s failed · %3$s held', 'searchpilot-pro' ), fmt.number( sub.done || 0 ), fmt.number( sub.failed || 0 ), fmt.number( sub.held || 0 ) ) ],
		];
		if ( d.sitemaps ) {
			pairs.push( [ __( 'Sitemaps', 'searchpilot-pro' ), ( SITEMAP_LABELS[ d.sitemaps.status ] || __( 'Not checked yet', 'searchpilot-pro' ) ) + ( d.sitemaps.url_count ? ' · ' + sprintf( /* translators: %s: URLs. */ __( '%s URLs', 'searchpilot-pro' ), fmt.number( d.sitemaps.url_count ) ) : '' ) ] );
		}
		return ui.dl( pairs );
	}

	/** Report viewer (modal, printable). */
	function openReport( id, onChanged ) {
		let current = null;
		const body = SPP.region(
			() =>
				api.get( '/reports/' + id ).then( ( r ) => {
					current = r;
					const d = r.data || {};
					const engines = d.engines || {};
					const g = engines.google || {};
					const lists = d.lists || {};
					const series = g.series || ( engines.bing || {} ).series;
					const newIssues = ( d.issues || {} ).new_critical || [];
					const listsShown = lists.matches !== false;
					const listsCompare = lists.prev_complete !== false;
					return ui.stack(
						h(
							'div',
							{ class: 'spp-row is-between' },
							h( 'div', null, h( 'div', { class: 'spp-strong' }, ( d.site && d.site.name ) || SPP.boot.siteName ), h( 'div', { class: 'spp-muted spp-small' }, ( TYPE_LABELS[ r.type ] || r.type ) + ' · ' + periodLabel( r ) + ' · ' + __( 'compared with the previous period', 'searchpilot-pro' ) ) ),
							r.emailed ? ui.badge( __( 'E-mailed', 'searchpilot-pro' ), 'passed', { dot: true } ) : ui.badge( __( 'Not e-mailed', 'searchpilot-pro' ), 'outline' )
						),
						engineKpis( __( 'Google Search', 'searchpilot-pro' ), g, true ),
						engines.bing && ( engines.bing.has_data || engines.bing.connected ) ? engineKpis( __( 'Bing', 'searchpilot-pro' ), engines.bing, false ) : null,
						series
							? ui.card( {
									title: __( 'Daily trend', 'searchpilot-pro' ),
									body: ui.lineChart( {
										labels: series.labels,
										series: [
											{ name: __( 'Clicks', 'searchpilot-pro' ), values: series.clicks, color: PALETTE[ 0 ] },
											{ name: __( 'Impressions', 'searchpilot-pro' ), values: series.impressions, color: PALETTE[ 1 ], axis: 'right', format: ( v ) => fmt.compact( v ) },
										],
										height: 220,
									} ),
							  } )
							: null,
						listsShown
							? ui.grid(
									2,
									ui.card( { title: __( 'Top queries', 'searchpilot-pro' ), flush: true, body: rowsTable( lists.top_queries, 'query', listsCompare ) } ),
									ui.card( { title: __( 'Top pages', 'searchpilot-pro' ), flush: true, body: rowsTable( lists.top_pages, 'page', listsCompare ) } )
							  )
							: null,
						listsShown
							? ui.grid(
									2,
									ui.card( { title: __( 'Rising content', 'searchpilot-pro' ), flush: true, body: changeTable( lists.rising, listsCompare ) } ),
									ui.card( { title: __( 'Declining content', 'searchpilot-pro' ), flush: true, body: changeTable( lists.declining, listsCompare ) } )
							  )
							: null,
						listsShown && lists.from
							? h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), sprintf( /* translators: 1: first day, 2: last day. */ __( 'Top lists use the stored snapshot for %1$s – %2$s.', 'searchpilot-pro' ), fmt.date( lists.from ), fmt.date( lists.to ) ) )
							: null,
						listsShown
							? null
							: ui.notice( { tone: 'info', text: __( 'Top queries, top pages and rising/declining content are stored for the last 7 days, 28 days and 3, 6 or 12 months only, so they are not shown for this custom period.', 'searchpilot-pro' ) } ),
						ui.card( { title: __( 'Site health', 'searchpilot-pro' ), body: healthList( d ) } ),
						newIssues.length
							? ui.card( {
									title: __( 'New critical and high issues', 'searchpilot-pro' ),
									body: h(
										'ul',
										{ class: 'spp-list' },
										newIssues.map( ( i ) => h( 'li', { class: 'spp-row' }, ui.severity( i.severity ), h( 'span', { class: 'spp-strong' }, i.label ), h( 'span', { class: 'spp-muted spp-small spp-break' }, i.title || fmt.url( i.url ) ) ) )
									),
							  } )
							: null,
						h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), d.disclaimer || '' )
					);
				} ),
			{ loader: true }
		);
		ui.modal( {
			title: __( 'Report', 'searchpilot-pro' ),
			size: 'large',
			body: body.el,
			actions: ( close ) => [
				ui.button( { label: __( 'Close', 'searchpilot-pro' ), onClick: close } ),
				canManage()
					? ui.button( {
							label: __( 'E-mail now', 'searchpilot-pro' ),
							icon: 'external',
							onClick: () =>
								api.post( '/reports/' + id + '/email' ).then( ( res ) => {
									sentToast( res );
									body.refresh();
									onChanged && onChanged();
								} ),
					  } )
					: null,
				ui.button( { label: __( 'Print', 'searchpilot-pro' ), icon: 'file', variant: 'primary', onClick: () => ( current ? printReport() : null ) } ),
			],
		} );
	}

	function openGenerate( onDone ) {
		const state = { type: 'weekly', start: '', end: '' };
		const dates = h( 'div', { class: 'spp-hidden' } );
		SPP.mount(
			dates,
			ui.grid(
				2,
				ui.field.text( { label: __( 'First day', 'searchpilot-pro' ), type: 'date', onInput: ( v ) => ( state.start = v ) } ),
				ui.field.text( { label: __( 'Last day', 'searchpilot-pro' ), type: 'date', onInput: ( v ) => ( state.end = v ) } )
			)
		);
		const m = ui.modal( {
			title: __( 'Generate a report', 'searchpilot-pro' ),
			body: ui.stack(
				ui.field.radioCards( {
					value: 'weekly',
					options: [
						{ value: 'weekly', label: __( 'Last full week', 'searchpilot-pro' ), description: __( 'The most recent completed week', 'searchpilot-pro' ), icon: 'chart' },
						{ value: 'monthly', label: __( 'Last full month', 'searchpilot-pro' ), description: __( 'The previous calendar month', 'searchpilot-pro' ), icon: 'chart' },
						{ value: 'custom', label: __( 'Custom period', 'searchpilot-pro' ), description: __( 'Up to one year', 'searchpilot-pro' ), icon: 'file' },
					],
					onChange: ( v ) => {
						state.type = v;
						dates.classList.toggle( 'spp-hidden', v !== 'custom' );
					},
				} ),
				dates,
				h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'Search data is a few days behind, so the last days of a very recent period may be incomplete.', 'searchpilot-pro' ) )
			),
			actions: ( close ) => [
				ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
				ui.button( {
					label: __( 'Generate', 'searchpilot-pro' ),
					variant: 'primary',
					icon: 'bolt',
					onClick: () => {
						const payload = { type: state.type };
						if ( state.type === 'custom' ) {
							if ( ! state.start || ! state.end ) {
								ui.toast( __( 'Choose the first and last day.', 'searchpilot-pro' ), 'error' );
								return null;
							}
							payload.start = state.start;
							payload.end = state.end;
						}
						return api.post( '/reports/generate', payload ).then( ( r ) => {
							m.close();
							ui.toast( __( 'Report generated', 'searchpilot-pro' ) );
							onDone && onDone();
							openReport( r.id, onDone );
						} );
					},
				} ),
			],
		} );
	}

	function scheduleCard( schedule, reload ) {
		if ( ! canManage() ) {
			return ui.card( {
				title: __( 'Scheduled reports', 'searchpilot-pro' ),
				body: h( 'p', null, schedule.frequency === 'off' ? __( 'Scheduled e-mail reports are turned off.', 'searchpilot-pro' ) : sprintf( /* translators: %s: frequency. */ __( 'A %s report is e-mailed to the site team.', 'searchpilot-pro' ), ( TYPE_LABELS[ schedule.frequency ] || schedule.frequency ).toLowerCase() ) ),
			} );
		}
		const draft = { report: schedule.frequency || 'off', recipients: ( schedule.recipients || [] ).join( ', ' ) };
		const lastSent = schedule.last_sent || {};
		const lastText = lastSent[ draft.report ] ? sprintf( /* translators: %s: date. */ __( 'Last sent for the period ending %s.', 'searchpilot-pro' ), fmt.date( lastSent[ draft.report ] ) ) : '';
		const alertList = ( schedule.alert_recipients || [] ).join( ', ' );
		let help = __( 'Separate addresses with commas. These addresses receive only the reports.', 'searchpilot-pro' );
		if ( ! draft.recipients ) {
			help = alertList
				? sprintf( /* translators: %s: e-mail addresses. */ __( 'Empty: reports go to the alert recipients (%s).', 'searchpilot-pro' ), alertList )
				: sprintf( /* translators: %s: e-mail addresses. */ __( 'Empty: reports go to the site administrator (%s).', 'searchpilot-pro' ), ( schedule.effective || [] ).join( ', ' ) );
		}
		const fieldError = h( 'div', { class: 'spp-field-error', role: 'alert' } );
		return ui.card( {
			title: __( 'Scheduled e-mail report', 'searchpilot-pro' ),
			subtitle: __( 'Sent once the period has ended and search data for it is available (usually 3–4 days later).', 'searchpilot-pro' ),
			body: ui.stack(
				ui.field.select( {
					label: __( 'Frequency', 'searchpilot-pro' ),
					row: true,
					value: draft.report,
					options: [
						{ value: 'off', label: __( 'Off', 'searchpilot-pro' ) },
						{ value: 'weekly', label: __( 'Weekly', 'searchpilot-pro' ) },
						{ value: 'monthly', label: __( 'Monthly', 'searchpilot-pro' ) },
					],
					onChange: ( v ) => ( draft.report = v ),
				} ),
				h(
					'div',
					null,
					ui.field.textarea( {
						label: __( 'Report recipients', 'searchpilot-pro' ),
						row: true,
						rows: 2,
						value: draft.recipients,
						placeholder: 'editor@example.com, owner@example.com',
						help,
						onInput: ( v ) => {
							draft.recipients = v;
							SPP.mount( fieldError );
						},
					} ),
					fieldError
				),
				h(
					'div',
					{ class: 'spp-row is-between' },
					h( 'span', { class: 'spp-muted spp-small' }, lastText ),
					ui.button( {
						label: __( 'Save schedule', 'searchpilot-pro' ),
						variant: 'primary',
						onClick: () =>
							api
								.post( '/settings', {
									settings: {
										alerts: {
											report: draft.report,
											report_recipients: draft.recipients
												.split( /[\s,;]+/ )
												.map( ( v ) => v.trim() )
												.filter( Boolean ),
										},
									},
								} )
								.then( ( res ) => {
									const errors = res.errors || {};
									const own = errors[ 'alerts.report_recipients' ] || errors[ 'alerts.report' ];
									if ( own ) {
										// Keep the typed addresses so they can be corrected.
										SPP.mount( fieldError, String( own ) );
										ui.toast( __( 'Some addresses are not valid — check the recipients.', 'searchpilot-pro' ), 'error' );
										return;
									}
									ui.toast( __( 'Schedule saved', 'searchpilot-pro' ) );
									reload();
								} ),
					} )
				)
			),
		} );
	}

	function exportsCard() {
		const state = { range: '28d', engine: 'google' };
		const run = ( type ) =>
			api.get( '/reports/export', { type, range: state.range, engine: state.engine } ).then( ( res ) => {
				if ( ! res.rows || ! res.rows.length ) {
					ui.toast( __( 'Nothing to export yet.', 'searchpilot-pro' ), 'warning' );
					return;
				}
				SPP.util.download( res.filename, SPP.util.toCsv( res.rows, res.columns ), 'text/csv;charset=utf-8' );
				ui.toast( sprintf( /* translators: %s: rows. */ __( 'Exported %s rows', 'searchpilot-pro' ), fmt.number( res.rows.length ) ) );
			} );
		return ui.card( {
			title: __( 'CSV exports', 'searchpilot-pro' ),
			subtitle: __( 'Spreadsheet-safe CSV files (formulas are neutralised).', 'searchpilot-pro' ),
			body: ui.stack(
				h(
					'div',
					{ class: 'spp-row' },
					ui.rangePicker( { value: state.range, onChange: ( v ) => ( state.range = v ) } ),
					ui.select( {
						label: __( 'Search engine', 'searchpilot-pro' ),
						value: state.engine,
						options: [
							{ value: 'google', label: __( 'Google', 'searchpilot-pro' ) },
							{ value: 'bing', label: __( 'Bing', 'searchpilot-pro' ) },
						],
						onChange: ( v ) => ( state.engine = v ),
					} )
				),
				h(
					'div',
					{ class: 'spp-row' },
					ui.button( { label: __( 'Queries', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => run( 'queries' ) } ),
					ui.button( { label: __( 'Pages', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => run( 'pages' ) } ),
					ui.button( { label: __( 'Opportunities', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => run( 'opportunities' ) } ),
					ui.button( { label: __( 'Issues', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => run( 'issues' ) } ),
					ui.button( { label: __( 'URLs (index status)', 'searchpilot-pro' ), icon: 'download', size: 'small', onClick: () => run( 'urls' ) } )
				)
			),
		} );
	}

	SPP.registerView( 'reports', {
		mount( el ) {
			let table = null;
			const schedule = h( 'div' );
			const reloadAll = () => {
				if ( table ) {
					table.reload();
				}
			};
			table = ui.dataTable( {
				path: '/reports',
				perPage: 15,
				dense: true,
				empty: __( 'No reports yet. Generate one now or turn on the weekly e-mail.', 'searchpilot-pro' ),
				onLoaded: ( data ) => SPP.mount( schedule, scheduleCard( data.schedule || {}, reloadAll ) ),
				columns: [
					{ key: 'period', label: __( 'Period', 'searchpilot-pro' ), render: ( r ) => h( 'button', { type: 'button', class: 'spp-btn is-link', style: { padding: 0, height: 'auto' }, onClick: () => openReport( r.id, reloadAll ) }, periodLabel( r ) ) },
					{ key: 'type', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => ui.badge( TYPE_LABELS[ r.type ] || r.type, r.type === 'custom' ? 'outline' : 'navy' ) },
					{ key: 'clicks', label: __( 'Clicks', 'searchpilot-pro' ), align: 'num', render: ( r ) => ( r.summary.has_data ? h( 'span', { class: 'spp-row', style: { justifyContent: 'flex-end', gap: '6px', flexWrap: 'nowrap' } }, fmt.number( r.summary.clicks ), ui.trend( fmt.delta( r.summary.clicks, r.summary.prev_clicks ) ) ) : '—' ) },
					{ key: 'impressions', label: __( 'Impressions', 'searchpilot-pro' ), align: 'num', render: ( r ) => ( r.summary.has_data ? h( 'span', { class: 'spp-row', style: { justifyContent: 'flex-end', gap: '6px', flexWrap: 'nowrap' } }, fmt.number( r.summary.impressions ), ui.trend( fmt.delta( r.summary.impressions, r.summary.prev_impressions ) ) ) : '—' ) },
					{ key: 'issues', label: __( 'Open issues', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.summary.issues ) },
					{ key: 'opportunities', label: __( 'Opportunities', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.summary.opportunities ) },
					{ key: 'emailed', label: __( 'E-mail', 'searchpilot-pro' ), render: ( r ) => ( r.emailed ? ui.badge( __( 'Sent', 'searchpilot-pro' ), 'passed', { dot: true } ) : ui.badge( __( 'Not sent', 'searchpilot-pro' ), 'outline' ) ) },
					{ key: 'created_at', label: __( 'Created', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => h( 'span', { title: fmt.datetime( r.created_at ) }, fmt.relative( r.created_at ) ) },
					{
						key: 'actions',
						label: '',
						align: 'right',
						render: ( r ) =>
							h(
								'div',
								{ class: 'spp-row', style: { justifyContent: 'flex-end' } },
								ui.button( { label: __( 'View', 'searchpilot-pro' ), size: 'small', onClick: () => openReport( r.id, reloadAll ) } ),
								canManage()
									? ui.button( {
											label: __( 'E-mail', 'searchpilot-pro' ),
											size: 'small',
											variant: 'ghost',
											onClick: () =>
												api.post( '/reports/' + r.id + '/email' ).then( ( res ) => {
													sentToast( res );
													reloadAll();
												} ),
									  } )
									: null
							),
					},
				],
			} );

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Reports', 'searchpilot-pro' ),
					subtitle: __( 'Weekly or monthly summaries of search performance, indexing, issues and automation — viewable, printable and sent by e-mail.', 'searchpilot-pro' ),
					actions: canManage() ? [ ui.button( { label: __( 'Generate now', 'searchpilot-pro' ), icon: 'bolt', variant: 'primary', onClick: () => openGenerate( reloadAll ) } ) ] : [],
				} ),
				ui.stack(
					h( 'div', { class: 'spp-grid' }, h( 'div', { class: 'span-7' }, schedule ), h( 'div', { class: 'span-5' }, exportsCard() ) ),
					ui.card( { title: __( 'Stored reports', 'searchpilot-pro' ), flush: true, body: table.el } ),
					h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'Search data comes from Google Search Console and Bing Webmaster Tools and is typically a few days behind. No tool can guarantee rankings.', 'searchpilot-pro' ) )
				)
			);
		},
	} );
} )( window.wp, window.SPP );

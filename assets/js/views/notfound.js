/**
 * 404 Monitor view.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;
	const field = ui.field;

	const REASONS = {
		old_slug: __( 'Old address of this post', 'searchpilot-pro' ),
		same_slug: __( 'Same slug, new location', 'searchpilot-pro' ),
		similar: __( 'Similar address', 'searchpilot-pro' ),
		term: __( 'Category or tag with this name', 'searchpilot-pro' ),
	};

	const STATUS = {
		open: { label: __( 'Open', 'searchpilot-pro' ), tone: 'high' },
		ignored: { label: __( 'Ignored', 'searchpilot-pro' ), tone: 'outline' },
		redirected: { label: __( 'Redirected', 'searchpilot-pro' ), tone: 'passed' },
	};

	/** Redirect modal with suggestions and a manual target. */
	function redirectModal( row, onDone ) {
		let target = '';
		let code = 301;
		const error = h( 'div' );
		const suggestions = h( 'div', null, ui.loading( __( 'Looking for matching pages…', 'searchpilot-pro' ) ) );
		const targetField = field.text( {
			label: __( 'Redirect to', 'searchpilot-pro' ),
			placeholder: '/correct-page/',
			help: __( 'Pick a suggestion or enter a path on this site.', 'searchpilot-pro' ),
			onInput: ( v ) => ( target = v ),
		} );
		const input = targetField.querySelector( 'input' );
		api.get( '/404/' + row.id + '/suggest' )
			.then( ( res ) => {
				const list = res.suggestions || [];
				if ( ! list.length ) {
					SPP.mount( suggestions, h( 'p', { class: 'spp-muted' }, __( 'No similar page found. Enter the right address below, or leave it as a 404 if the content never existed.', 'searchpilot-pro' ) ) );
					return;
				}
				const choose = ( s ) => {
					target = s.path;
					input.value = fmt.url( s.path );
				};
				SPP.mount(
					suggestions,
					h(
						'div',
						{ class: 'spp-radio-cards' },
						list.map( ( s ) =>
							h(
								'button',
								{
									type: 'button',
									class: 'spp-radio-card',
									onClick: ( e ) => {
										suggestions.querySelectorAll( '.spp-radio-card' ).forEach( ( b ) => b.classList.remove( 'is-selected' ) );
										e.currentTarget.classList.add( 'is-selected' );
										choose( s );
									},
								},
								h( 'span', { class: 'spp-radio-card-title' }, s.title || fmt.path( s.url ) ),
								h( 'span', { class: 'spp-radio-card-text' }, fmt.path( s.url ) ),
								h( 'span', { class: 'spp-radio-card-text' }, ( REASONS[ s.reason ] || s.reason ) + ' · ' + sprintf( /* translators: %s: percentage. */ __( '%s match', 'searchpilot-pro' ), fmt.percent( s.score, 0 ) ) )
							)
						)
					)
				);
				if ( list[ 0 ].score >= 0.9 ) {
					choose( list[ 0 ] );
					const first = suggestions.querySelector( '.spp-radio-card' );
					if ( first ) {
						first.classList.add( 'is-selected' );
					}
				}
			} )
			.catch( ( err ) => SPP.mount( suggestions, ui.errorBox( err ) ) );

		ui.modal( {
			title: __( 'Redirect this address', 'searchpilot-pro' ),
			body: h(
				'div',
				null,
				error,
				ui.dl( [
					[ __( 'Address', 'searchpilot-pro' ), h( 'code', { class: 'spp-break' }, fmt.url( row.path ) ) ],
					[ __( 'Hits', 'searchpilot-pro' ), fmt.number( row.hits ) ],
					[ __( 'Last referrer', 'searchpilot-pro' ), row.referrer ? h( 'span', { class: 'spp-break' }, fmt.url( row.referrer ) ) : '—' ],
				] ),
				h( 'h4', { style: { margin: '14px 0 8px' } }, __( 'Suggested pages', 'searchpilot-pro' ) ),
				suggestions,
				targetField,
				field.select( {
					label: __( 'Type', 'searchpilot-pro' ),
					value: code,
					options: [
						{ value: 301, label: __( '301 — Moved permanently (recommended)', 'searchpilot-pro' ) },
						{ value: 302, label: __( '302 — Temporary', 'searchpilot-pro' ) },
						{ value: 410, label: __( '410 — Gone for good (no target)', 'searchpilot-pro' ) },
					],
					onChange: ( v ) => ( code = Number( v ) ),
				} )
			),
			actions: ( close ) => [
				ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
				ui.button( {
					label: __( 'Create redirect', 'searchpilot-pro' ),
					variant: 'primary',
					onClick: () => {
						if ( 410 !== code && ! target ) {
							SPP.mount( error, ui.notice( { tone: 'high', text: __( 'Choose a suggestion or enter a target address.', 'searchpilot-pro' ) } ) );
							return null;
						}
						return api.post( '/404/' + row.id + '/redirect', { target, code } ).then(
							( res ) => {
								ui.toast(
									res.updated > 1
										? sprintf( /* translators: %d: number of logged addresses. */ __( 'Redirect created for the address without its query string; %d logged variants are now marked redirected.', 'searchpilot-pro' ), res.updated )
										: __( 'Redirect created. Manage it on the Redirects screen.', 'searchpilot-pro' )
								);
								close();
								onDone();
							},
							( err ) => {
								SPP.mount( error, ui.notice( { tone: 'critical', text: err.message } ) );
								const e = new Error( err.message );
								e.silent = true;
								throw e;
							}
						);
					},
				} ),
			],
		} );
	}

	/** Monitor settings (saved through /settings, logged and undoable). */
	function settingsCard( stats, onSaved ) {
		const draft = {};
		const saveBtn = ui.button( {
			label: __( 'Save monitor settings', 'searchpilot-pro' ),
			variant: 'primary',
			disabled: true,
			onClick: () =>
				api.post( '/settings', { settings: { redirects: draft } } ).then( ( res ) => {
					const errors = res.errors || {};
					if ( Object.keys( errors ).length ) {
						ui.toast( Object.values( errors ).join( ' ' ), 'error' );
						return;
					}
					ui.toast( __( 'Settings saved', 'searchpilot-pro' ) );
					onSaved();
				} ),
		} );
		const set = ( key, v ) => {
			draft[ key ] = v;
			saveBtn.disabled = false;
		};
		return ui.card( {
			title: __( 'Monitor settings', 'searchpilot-pro' ),
			body: [
				field.toggle( { label: __( 'Record 404 errors', 'searchpilot-pro' ), row: true, checked: stats.monitoring, help: __( 'At most 120 new records per minute, so bot floods cannot slow the site down.', 'searchpilot-pro' ), onChange: ( v ) => set( 'monitor_404', v ) } ),
				field.toggle( { label: __( 'Include bots and crawlers', 'searchpilot-pro' ), row: true, checked: stats.log_bots, help: __( 'Useful to see what Googlebot cannot find; turn off if scanners fill the log.', 'searchpilot-pro' ), onChange: ( v ) => set( 'log_bots', v ) } ),
				field.number( { label: __( 'Keep entries for', 'searchpilot-pro' ), row: true, value: stats.retention, min: 1, max: 365, suffix: __( 'days after the last hit', 'searchpilot-pro' ), onInput: ( v ) => set( 'retention_404_days', v === '' ? 60 : v ) } ),
				field.textarea( {
					label: __( 'Ignore these addresses', 'searchpilot-pro' ),
					row: true,
					wide: true,
					code: true,
					rows: 4,
					value: stats.ignore,
					help: __( 'One pattern per line; * matches anything. Example: /wp-content/*.map', 'searchpilot-pro' ),
					onInput: ( v ) => set( 'ignore_patterns', v ),
				} ),
			],
			footer: h( 'div', { class: 'spp-row is-between' }, h( 'span', { class: 'spp-muted spp-small' }, __( 'Changes are logged and can be undone.', 'searchpilot-pro' ) ), saveBtn ),
		} );
	}

	SPP.registerView( 'notfound', {
		mount( el ) {
			const query = {
				search: SPP.query.get( 'search' ),
				status: SPP.query.get( 'status', 'open' ),
				bots: SPP.query.get( 'bots', 'include' ),
				orderby: SPP.query.get( 'orderby', 'last_seen' ),
			};

			const overview = SPP.region( () =>
				api.get( '/404/stats' ).then( ( stats ) => {
					const notices = [];
					if ( ! stats.active ) {
						notices.push(
							ui.notice( {
								tone: 'high',
								title: stats.monitoring ? sprintf( /* translators: %s: plugin id. */ __( 'Another plugin (%s) records 404 errors', 'searchpilot-pro' ), stats.owner ) : __( 'The 404 monitor is switched off', 'searchpilot-pro' ),
								text: stats.monitoring ? __( 'SearchPilot does not log 404s twice. The data below is from before, if any.', 'searchpilot-pro' ) : __( 'Turn it on in the monitor settings at the bottom of this page to find broken links and missing pages.', 'searchpilot-pro' ),
							} )
						);
					}
					const bots = stats.series.bots.reduce( ( a, b ) => a + b, 0 );
					const total = stats.series.hits.reduce( ( a, b ) => a + b, 0 );
					return ui.stack(
						notices,
						ui.grid(
							4,
							ui.kpi( { label: __( '404s in the last 24 hours', 'searchpilot-pro' ), value: fmt.number( stats.last_24h ), icon: 'alert', spark: stats.series.hits.slice( -14 ) } ),
							ui.kpi( { label: __( '404s in the last 7 days', 'searchpilot-pro' ), value: fmt.number( stats.last_7d ), icon: 'chart' } ),
							ui.kpi( { label: __( 'Open addresses', 'searchpilot-pro' ), value: fmt.number( stats.open ), icon: 'link', tone: stats.open ? 'high' : 'passed', hint: sprintf( /* translators: %s: number. */ __( '%s ignored', 'searchpilot-pro' ), fmt.number( stats.ignored ) ) } ),
							ui.kpi( { label: __( 'Fixed with redirects', 'searchpilot-pro' ), value: fmt.number( stats.redirected ), icon: 'check', tone: 'passed' } )
						),
						ui.grid(
							2,
							ui.card( {
								title: __( '404 errors per day', 'searchpilot-pro' ),
								subtitle: total ? sprintf( /* translators: 1: hits, 2: share from bots. */ __( '%1$s in 30 days, %2$s from bots and crawlers.', 'searchpilot-pro' ), fmt.number( total ), fmt.percent( total ? bots / total : 0, 0 ) ) : __( 'Last 30 days (UTC).', 'searchpilot-pro' ),
								body: ui.lineChart( {
									labels: stats.series.labels,
									height: 220,
									series: [
										{ name: __( 'All 404s', 'searchpilot-pro' ), values: stats.series.hits, color: ui.PALETTE[ 4 ] },
										{ name: __( 'Bots', 'searchpilot-pro' ), values: stats.series.bots, color: ui.PALETTE[ 1 ], dashed: true },
									],
									emptyText: __( 'No 404 errors recorded yet.', 'searchpilot-pro' ),
								} ),
							} ),
							ui.card( {
								title: __( 'Where visitors came from', 'searchpilot-pro' ),
								subtitle: __( 'Pages linking to missing addresses. Links from your own pages are broken internal links — fix them at the source.', 'searchpilot-pro' ),
								body: ui.barList( {
									items: ( stats.top_referrers || [] ).map( ( r ) => ( {
										label: r.internal ? fmt.path( r.referrer ) : fmt.url( r.referrer ),
										title: r.display,
										href: r.referrer,
										external: true,
										value: r.hits,
										sub: r.internal ? __( 'your site', 'searchpilot-pro' ) : sprintf( /* translators: %d: missing addresses. */ _n( '%d address', '%d addresses', r.urls, 'searchpilot-pro' ), r.urls ),
									} ) ),
									empty: __( 'No referrers recorded yet', 'searchpilot-pro' ),
								} ),
							} )
						)
					);
				} )
			);

			const settings = SPP.region( () => api.get( '/404/stats' ).then( ( stats ) => settingsCard( stats, () => reloadAll() ) ) );

			const issues = SPP.region( () =>
				api.get( '/issues', { type: 'spike_404,frequent_404', per_page: 10 } ).then( ( res ) =>
					( res.items || [] ).length
						? ui.card( { title: __( '404 alerts', 'searchpilot-pro' ), body: ui.stack( res.items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: () => reloadAll() } ) ) ) } )
						: h( 'span' )
				)
			);

			const act = ( path, message ) => api.post( path ).then( () => {
				ui.toast( message );
				reloadAll();
			} );

			const table = ui.dataTable( {
				path: '/404',
				query,
				perPage: 25,
				dense: true,
				empty: __( 'No 404 errors match these filters. That is good news.', 'searchpilot-pro' ),
				columns: [
					{
						key: 'url',
						label: __( 'Missing address', 'searchpilot-pro' ),
						render: ( r ) => h( 'div', null, h( 'a', { class: 'spp-cell-title spp-break', href: r.url, target: '_blank', rel: 'noopener noreferrer', title: r.display }, fmt.path( r.url ) ), 'open' !== r.status ? h( 'div', { class: 'spp-cell-sub' }, ui.badge( STATUS[ r.status ].label, STATUS[ r.status ].tone ) ) : null ),
					},
					{ key: 'hits', label: __( 'Hits', 'searchpilot-pro' ), align: 'num', sortable: true, render: ( r ) => fmt.number( r.hits ) },
					{ key: 'last_seen', label: __( 'Last seen', 'searchpilot-pro' ), sortable: true, className: 'is-nowrap', render: ( r ) => h( 'span', { title: fmt.datetime( r.last_seen ) }, fmt.relative( r.last_seen ) ) },
					{
						key: 'referrer',
						label: __( 'Referrer', 'searchpilot-pro' ),
						render: ( r ) =>
							r.referrer
								? h( 'div', null, h( 'a', { class: 'spp-break spp-small', href: r.referrer, target: '_blank', rel: 'noopener noreferrer' }, r.referrer_internal ? fmt.path( r.referrer ) : fmt.url( r.referrer ) ), r.referrer_internal ? h( 'div', null, ui.badge( __( 'Broken internal link', 'searchpilot-pro' ), 'high' ) ) : null )
								: h( 'span', { class: 'spp-muted' }, __( 'Direct / unknown', 'searchpilot-pro' ) ),
					},
					{ key: 'is_bot', label: __( 'Visitor', 'searchpilot-pro' ), render: ( r ) => ( r.is_bot ? h( 'span', { title: r.user_agent }, ui.badge( __( 'Bot', 'searchpilot-pro' ), 'outline' ) ) : h( 'span', { title: r.user_agent }, ui.badge( __( 'Person', 'searchpilot-pro' ), 'info' ) ) ) },
					{
						key: 'actions',
						label: '',
						align: 'right',
						render: ( r ) =>
							ui.row(
								'redirected' !== r.status ? ui.button( { label: __( 'Redirect', 'searchpilot-pro' ), icon: 'link', size: 'small', variant: 'primary', onClick: () => redirectModal( r, reloadAll ) } ) : ui.button( { label: __( 'View rule', 'searchpilot-pro' ), size: 'small', variant: 'ghost', href: SPP.pageUrl( 'redirects', { search: fmt.path( r.url ) } ) } ),
								'ignored' === r.status
									? ui.button( { label: __( 'Reopen', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => act( '/404/' + r.id + '/reopen', __( 'Reopened', 'searchpilot-pro' ) ) } )
									: 'open' === r.status
									? ui.button( { label: __( 'Ignore', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => act( '/404/' + r.id + '/ignore', __( 'Ignored — it stays in the log but out of the open list.', 'searchpilot-pro' ) ) } )
									: null,
								ui.button( {
									icon: 'trash',
									size: 'small',
									variant: 'ghost',
									title: __( 'Delete from the log', 'searchpilot-pro' ),
									onClick: () =>
										ui
											.confirm( {
												title: __( 'Delete this entry?', 'searchpilot-pro' ),
												message: sprintf( /* translators: %s: address. */ __( '%s is removed from the 404 log permanently (it is logged again if it gets new hits). Redirects are not affected.', 'searchpilot-pro' ), fmt.path( r.url ) ),
												confirmLabel: __( 'Delete', 'searchpilot-pro' ),
												danger: true,
											} )
											.then( ( ok ) =>
												ok
													? api.del( '/404/' + r.id ).then( () => {
															ui.toast( __( 'Removed from the log', 'searchpilot-pro' ) );
															reloadAll();
													  } )
													: null
											),
								} )
							),
					},
				],
			} );

			function reloadAll() {
				table.reload();
				overview.refresh();
				issues.refresh();
				settings.refresh();
			}

			const setFilter = ( key, v ) => {
				const patch = {};
				patch[ key ] = v;
				SPP.query.set( patch );
				table.setQuery( patch );
			};

			const clear = () => {
				let days = 30;
				ui.modal( {
					title: __( 'Clear the 404 log', 'searchpilot-pro' ),
					size: 'small',
					body: h(
						'div',
						null,
						h( 'p', null, __( 'Removes logged 404 addresses. Redirects you created are not affected.', 'searchpilot-pro' ) ),
						field.select( {
							label: __( 'Remove', 'searchpilot-pro' ),
							value: days,
							options: [
								{ value: 30, label: __( 'Entries not seen for 30 days', 'searchpilot-pro' ) },
								{ value: 7, label: __( 'Entries not seen for 7 days', 'searchpilot-pro' ) },
								{ value: 0, label: __( 'Everything', 'searchpilot-pro' ) },
							],
							onChange: ( v ) => ( days = Number( v ) ),
						} )
					),
					actions: ( close ) => [
						ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
						ui.button( {
							label: __( 'Clear', 'searchpilot-pro' ),
							variant: 'danger',
							onClick: () =>
								api.post( '/404/clear', { older_than_days: days } ).then( ( r ) => {
									ui.toast( sprintf( /* translators: %s: number of entries. */ _n( 'Removed %s entry', 'Removed %s entries', r.deleted, 'searchpilot-pro' ), fmt.number( r.deleted ) ) );
									close();
									reloadAll();
								} ),
						} ),
					],
				} );
			};

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( '404 Monitor', 'searchpilot-pro' ),
					subtitle: __( 'Addresses that returned “not found”, with where visitors came from and one-click redirects. Asset probes and your ignore list are skipped.', 'searchpilot-pro' ),
					actions: [
						ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', onClick: () => reloadAll() } ),
						ui.button( { label: __( 'Clear log', 'searchpilot-pro' ), icon: 'trash', variant: 'danger', onClick: clear } ),
					],
				} ),
				ui.stack(
					overview.el,
					issues.el,
					ui.card( {
						flush: true,
						body: [
							h(
								'div',
								{ style: { padding: '0 20px' } },
								ui.toolbar(
									ui.search( { value: query.search, placeholder: __( 'Search addresses or referrers…', 'searchpilot-pro' ), onSearch: ( v ) => setFilter( 'search', v ) } ),
									ui.select( {
										label: __( 'Status', 'searchpilot-pro' ),
										value: query.status,
										options: [
											{ value: 'open', label: __( 'Open', 'searchpilot-pro' ) },
											{ value: 'redirected', label: __( 'Redirected', 'searchpilot-pro' ) },
											{ value: 'ignored', label: __( 'Ignored', 'searchpilot-pro' ) },
											{ value: 'any', label: __( 'All', 'searchpilot-pro' ) },
										],
										onChange: ( v ) => setFilter( 'status', v ),
									} ),
									ui.select( {
										label: __( 'Visitors', 'searchpilot-pro' ),
										value: query.bots,
										options: [
											{ value: 'include', label: __( 'People and bots', 'searchpilot-pro' ) },
											{ value: 'exclude', label: __( 'People only', 'searchpilot-pro' ) },
											{ value: 'only', label: __( 'Bots only', 'searchpilot-pro' ) },
										],
										onChange: ( v ) => setFilter( 'bots', v ),
									} ),
									ui.select( {
										label: __( 'Sort', 'searchpilot-pro' ),
										value: query.orderby,
										options: [
											{ value: 'last_seen', label: __( 'Most recent', 'searchpilot-pro' ) },
											{ value: 'hits', label: __( 'Most hits', 'searchpilot-pro' ) },
										],
										onChange: ( v ) => setFilter( 'orderby', v ),
									} )
								)
							),
							table.el,
						],
					} ),
					settings.el
				)
			);
		},
	} );
} )( window.wp, window.SPP );

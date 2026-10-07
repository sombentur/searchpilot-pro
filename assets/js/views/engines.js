/**
 * Search Engines view: connections, submission queue, history and quotas.
 *
 * Tabs are bookmarkable: ?tab=connections|queue|history|quotas.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const SERVICES = {
		indexnow: { label: 'IndexNow', tone: 'gold' },
		bing_url: { label: __( 'Bing URL Submission', 'searchpilot-pro' ), tone: 'info' },
		google_indexing: { label: __( 'Google Indexing API', 'searchpilot-pro' ), tone: 'navy' },
		gsc_sitemap: { label: __( 'Search Console sitemap', 'searchpilot-pro' ), tone: 'navy' },
		bing_sitemap: { label: __( 'Bing sitemap', 'searchpilot-pro' ), tone: 'info' },
	};

	const STATUSES = {
		pending: { label: __( 'Pending', 'searchpilot-pro' ), tone: 'info' },
		held: { label: __( 'Held', 'searchpilot-pro' ), tone: 'gold' },
		processing: { label: __( 'Sending', 'searchpilot-pro' ), tone: 'navy' },
		done: { label: __( 'Sent', 'searchpilot-pro' ), tone: 'passed' },
		failed: { label: __( 'Failed', 'searchpilot-pro' ), tone: 'critical' },
		skipped: { label: __( 'Skipped', 'searchpilot-pro' ), tone: 'outline' },
		cancelled: { label: __( 'Cancelled', 'searchpilot-pro' ), tone: 'outline' },
	};

	const REASONS = {
		publish: __( 'Published', 'searchpilot-pro' ),
		update: __( 'Updated', 'searchpilot-pro' ),
		delete: __( 'Deleted', 'searchpilot-pro' ),
		unpublish: __( 'Unpublished', 'searchpilot-pro' ),
		redirect: __( 'Address changed', 'searchpilot-pro' ),
		noindex: __( 'Set to noindex', 'searchpilot-pro' ),
		index: __( 'Indexable again', 'searchpilot-pro' ),
		manual: __( 'Manual', 'searchpilot-pro' ),
		fix: __( 'Fix', 'searchpilot-pro' ),
		sitemap_change: __( 'Sitemap changed', 'searchpilot-pro' ),
		backfill: __( 'Backfill', 'searchpilot-pro' ),
	};

	const HOLDS = {
		local: __( 'Held: local site', 'searchpilot-pro' ),
		setup: __( 'Held: setup not finished', 'searchpilot-pro' ),
		environment: __( 'Held: not the production site', 'searchpilot-pro' ),
		approval: __( 'Held: awaiting approval', 'searchpilot-pro' ),
		discouraged: __( 'Held: search engines discouraged', 'searchpilot-pro' ),
		moved: __( 'Held: site address changed', 'searchpilot-pro' ),
	};

	const TABS = [ 'connections', 'queue', 'history', 'quotas' ];

	function serviceBadge( service ) {
		const s = SERVICES[ service ] || { label: service, tone: 'outline' };
		return ui.badge( s.label, s.tone );
	}

	function statusBadge( row ) {
		const s = STATUSES[ row.status ] || { label: row.status, tone: 'outline' };
		return ui.badge( s.label, s.tone, { dot: true, title: row.hold_message || null } );
	}

	function actionLabel( action ) {
		return action === 'delete' ? __( 'Removal', 'searchpilot-pro' ) : __( 'Update', 'searchpilot-pro' );
	}

	function when( row ) {
		if ( row.status === 'pending' ) {
			const due = row.available_at && SPP.parseDate( row.available_at ).getTime() > Date.now();
			return h( 'span', { title: fmt.datetime( row.available_at ) }, due ? sprintf( /* translators: %s: relative time. */ __( 'next %s', 'searchpilot-pro' ), fmt.relative( row.available_at ) ) : __( 'due now', 'searchpilot-pro' ) );
		}
		if ( row.status === 'held' || row.status === 'processing' ) {
			return h( 'span', { title: fmt.datetime( row.created_at ) }, sprintf( /* translators: %s: relative time. */ __( 'queued %s', 'searchpilot-pro' ), fmt.relative( row.created_at ) ) );
		}
		return h( 'span', { title: fmt.datetime( row.completed_at || row.updated_at ) }, fmt.relative( row.completed_at || row.updated_at ) );
	}

	function details( row ) {
		ui.modal( {
			title: __( 'Submission details', 'searchpilot-pro' ),
			size: 'large',
			body: ui.stack(
				ui.dl( [
					[ __( 'URL', 'searchpilot-pro' ), h( 'a', { href: row.url, target: '_blank', rel: 'noopener noreferrer', class: 'spp-break' }, fmt.url( row.url ) ) ],
					[ __( 'Service', 'searchpilot-pro' ), serviceBadge( row.service ) ],
					[ __( 'Action', 'searchpilot-pro' ), actionLabel( row.action ) ],
					[ __( 'Reason', 'searchpilot-pro' ), REASONS[ row.reason ] || row.reason ],
					[ __( 'Status', 'searchpilot-pro' ), statusBadge( row ) ],
					row.hold_message ? [ __( 'Why it is held', 'searchpilot-pro' ), row.hold_message ] : null,
					[ __( 'Attempts', 'searchpilot-pro' ), row.attempts + ' / ' + row.max_attempts ],
					row.response_code ? [ __( 'HTTP status', 'searchpilot-pro' ), String( row.response_code ) ] : null,
					[ __( 'Queued', 'searchpilot-pro' ), fmt.datetime( row.created_at ) ],
					row.status === 'pending' ? [ __( 'Next attempt', 'searchpilot-pro' ), fmt.datetime( row.available_at ) ] : null,
					row.completed_at ? [ __( 'Completed', 'searchpilot-pro' ), fmt.datetime( row.completed_at ) ] : null,
				] ),
				row.last_error ? ui.notice( { tone: row.status === 'failed' ? 'critical' : 'high', title: __( 'Last error', 'searchpilot-pro' ), text: row.last_error } ) : null
			),
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Queue table (shared by Queue and History tabs)
	 * ------------------------------------------------------------------ */

	function queueTable( { statuses, statusOptions, history, onChanged, allowed } ) {
		const query = {
			service: SPP.query.get( 'service' ),
			status: statuses,
			search: SPP.query.get( 'q' ),
		};
		let table = null;
		const reload = () => {
			table.reload();
			if ( onChanged ) {
				onChanged();
			}
		};
		const rowAction = ( label, path, success, variant, data ) =>
			ui.button( {
				label,
				size: 'small',
				variant: variant || 'ghost',
				onClick: () =>
					api.post( path, data ).then( () => {
						ui.toast( success );
						reload();
					} ),
			} );

		table = ui.dataTable( {
			path: '/queue',
			query,
			perPage: 25,
			dense: true,
			empty: history ? __( 'No finished submissions yet.', 'searchpilot-pro' ) : __( 'Nothing is waiting. New, updated and removed pages are queued here automatically.', 'searchpilot-pro' ),
			columns: [
				{
					key: 'url',
					label: __( 'URL', 'searchpilot-pro' ),
					width: '30%',
					render: ( r ) =>
						h(
							'div',
							null,
							h( 'a', { class: 'spp-cell-title spp-break', href: r.url, target: '_blank', rel: 'noopener noreferrer', title: fmt.url( r.url ) }, fmt.path( r.url ) ),
							r.object_type === 'sitemap' ? h( 'div', { class: 'spp-cell-sub' }, __( 'Sitemap', 'searchpilot-pro' ) ) : null
						),
				},
				{ key: 'service', label: __( 'Service', 'searchpilot-pro' ), render: ( r ) => serviceBadge( r.service ) },
				{ key: 'action', label: __( 'Action', 'searchpilot-pro' ), render: ( r ) => actionLabel( r.action ) },
				{ key: 'reason', label: __( 'Reason', 'searchpilot-pro' ), render: ( r ) => REASONS[ r.reason ] || r.reason },
				{ key: 'status', label: __( 'Status', 'searchpilot-pro' ), render: ( r ) => statusBadge( r ) },
				{ key: 'attempts', label: __( 'Attempts', 'searchpilot-pro' ), align: 'num', render: ( r ) => r.attempts + '/' + r.max_attempts },
				{ key: history ? 'completed_at' : 'available_at', label: history ? __( 'Completed', 'searchpilot-pro' ) : __( 'Next attempt', 'searchpilot-pro' ), className: 'is-nowrap', render: when },
				{
					key: 'last_error',
					label: __( 'Last error', 'searchpilot-pro' ),
					width: '22%',
					render: ( r ) => {
						if ( r.status === 'held' && ! r.last_error ) {
							return h(
								'div',
								null,
								h( 'span', { class: 'spp-small spp-muted', title: r.hold_message || '' }, HOLDS[ r.hold_reason ] || HOLDS.approval ),
								' ',
								ui.button( { label: __( 'Details', 'searchpilot-pro' ), size: 'small', variant: 'link', onClick: () => details( r ) } )
							);
						}
						const text = r.last_error || '';
						if ( ! text ) {
							return '—';
						}
						return h(
							'div',
							null,
							h( 'span', { class: 'spp-small spp-muted' }, text.length > 90 ? text.slice( 0, 88 ) + '…' : text ),
							' ',
							ui.button( { label: __( 'Details', 'searchpilot-pro' ), size: 'small', variant: 'link', onClick: () => details( r ) } )
						);
					},
				},
				{
					key: 'actions',
					label: '',
					align: 'right',
					render: ( r ) =>
						h(
							'div',
							{ class: 'spp-row', style: { justifyContent: 'flex-end' } },
							r.status === 'held' && allowed() ? rowAction( __( 'Release', 'searchpilot-pro' ), '/queue/release', __( 'Released', 'searchpilot-pro' ), 'primary', { ids: [ r.id ] } ) : null,
							// Superseded or otherwise outdated rows (a newer notification exists) cannot be retried.
							[ 'failed', 'skipped', 'cancelled' ].indexOf( r.status ) !== -1 && r.can_retry !== false ? rowAction( __( 'Retry', 'searchpilot-pro' ), '/queue/' + r.id + '/retry', __( 'Queued again', 'searchpilot-pro' ) ) : null,
							[ 'pending', 'held' ].indexOf( r.status ) !== -1 ? rowAction( __( 'Cancel', 'searchpilot-pro' ), '/queue/' + r.id + '/cancel', __( 'Cancelled', 'searchpilot-pro' ) ) : null
						),
				},
			],
		} );

		// `.spp-wrap select.spp-select` outranks `.is-inline`; keep toolbar selects compact.
		const inline = ( el ) => {
			el.style.width = 'auto';
			return el;
		};
		const toolbar = ui.toolbar(
			ui.search( {
				value: query.search,
				placeholder: __( 'Search URLs…', 'searchpilot-pro' ),
				onSearch: ( v ) => {
					SPP.query.set( { q: v } );
					table.setQuery( { search: v } );
				},
			} ),
			inline(
				ui.select( {
					label: __( 'Service', 'searchpilot-pro' ),
					value: query.service,
					options: [ { value: '', label: __( 'All services', 'searchpilot-pro' ) } ].concat( Object.keys( SERVICES ).map( ( k ) => ( { value: k, label: SERVICES[ k ].label } ) ) ),
					onChange: ( v ) => {
						SPP.query.set( { service: v } );
						table.setQuery( { service: v } );
					},
				} )
			),
			inline(
				ui.select( {
					label: __( 'Status', 'searchpilot-pro' ),
					value: statuses,
					options: statusOptions,
					onChange: ( v ) => table.setQuery( { status: v } ),
				} )
			),
			ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', onClick: () => reload() } )
		);

		return { table, toolbar, reload };
	}

	/* ------------------------------------------------------------------ *
	 * Tabs
	 * ------------------------------------------------------------------ */

	function connectionsTab( refreshCounts ) {
		const onChange = () => refreshCounts();
		return h(
			'div',
			{ class: 'spp-grid' },
			h( 'div', { class: 'span-7' }, SPP.engines.google( { onChange } ) ),
			h( 'div', { class: 'span-5' }, ui.stack( SPP.engines.indexnow( { onChange } ), SPP.engines.bing( { onChange } ) ) )
		);
	}

	function queueTab( refreshCounts ) {
		let stats = null;
		const allowed = () => !! ( stats && stats.submissions && stats.submissions.allowed );

		const summary = SPP.region( () =>
			api.get( '/queue/stats' ).then( ( s ) => {
				stats = s;
				const heldNotice = s.held
					? ui.notice( {
							tone: 'gold',
							icon: 'hand',
							title: sprintf( /* translators: %s: number of held submissions. */ _n( '%s submission is held', '%s submissions are held', s.held, 'searchpilot-pro' ), fmt.number( s.held ) ),
							text: h(
								'div',
								null,
								Object.keys( s.hold_messages || {} ).map( ( k ) => h( 'div', null, s.hold_messages[ k ] ) ),
								s.submissions && s.submissions.allowed
									? h( 'div', { class: 'spp-small spp-muted', style: { marginTop: '4px' } }, __( 'In Manual mode SearchPilot asks before notifying search engines. Release sends the held items now; switch to Assisted mode to send them automatically in future.', 'searchpilot-pro' ) )
									: h( 'div', { class: 'spp-small spp-muted', style: { marginTop: '4px' } }, __( 'The environment guard protects search engines from test URLs. Held items can be released once this is the live site.', 'searchpilot-pro' ) )
							),
							actions:
								s.submissions && s.submissions.allowed
									? [
											ui.button( {
												label: __( 'Release all', 'searchpilot-pro' ),
												variant: 'primary',
												size: 'small',
												icon: 'bolt',
												onClick: () =>
													api.post( '/queue/release' ).then( ( res ) => {
														ui.toast( sprintf( /* translators: %s: number of items. */ _n( '%s submission released', '%s submissions released', res.released, 'searchpilot-pro' ), fmt.number( res.released ) ) );
														refreshAll();
													} ),
											} ),
											ui.button( { label: __( 'Automation settings', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'automation' ) } ),
									  ]
									: [ ui.button( { label: __( 'Open settings', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'settings' ) } ) ],
					  } )
					: null;
				return ui.stack(
					ui.grid(
						4,
						ui.kpi( { label: __( 'Pending', 'searchpilot-pro' ), value: fmt.number( s.pending + s.processing ), icon: 'refresh', hint: s.next_attempt_at ? sprintf( /* translators: %s: relative time. */ __( 'next %s', 'searchpilot-pro' ), fmt.relative( s.next_attempt_at ) ) : __( 'nothing due', 'searchpilot-pro' ) } ),
						ui.kpi( { label: __( 'Held', 'searchpilot-pro' ), value: fmt.number( s.held ), icon: 'hand', tone: s.held ? 'high' : null, hint: s.held ? __( 'waiting for release', 'searchpilot-pro' ) : __( 'none', 'searchpilot-pro' ) } ),
						ui.kpi( { label: __( 'Failed (24 h)', 'searchpilot-pro' ), value: fmt.number( s.failed_24h ), icon: 'alert', tone: s.failed_24h ? 'critical' : null, hint: sprintf( /* translators: %s: number. */ __( '%s failed in total', 'searchpilot-pro' ), fmt.number( s.failed ) ) } ),
						ui.kpi( { label: __( 'Sent (24 h)', 'searchpilot-pro' ), value: fmt.number( s.done_24h ), icon: 'check', tone: s.done_24h ? 'passed' : null, hint: s.next_run ? sprintf( /* translators: %s: relative time. */ __( 'worker runs %s', 'searchpilot-pro' ), fmt.relative( s.next_run ) ) : '' } )
					),
					heldNotice
				);
			} )
		);

		const q = queueTable( {
			statuses: 'pending,held,processing,failed',
			allowed,
			statusOptions: [
				{ value: 'pending,held,processing,failed', label: __( 'Open and failed', 'searchpilot-pro' ) },
				{ value: 'pending,processing', label: __( 'Pending', 'searchpilot-pro' ) },
				{ value: 'held', label: __( 'Held', 'searchpilot-pro' ) },
				{ value: 'failed', label: __( 'Failed', 'searchpilot-pro' ) },
			],
			onChanged: () => {
				summary.refresh();
				refreshCounts();
			},
		} );

		const refreshAll = () => {
			summary.refresh();
			q.table.reload();
			refreshCounts();
		};

		return ui.stack(
			summary.el,
			ui.card( {
				title: __( 'Submission queue', 'searchpilot-pro' ),
				subtitle: __( 'Every notification to IndexNow, Bing and Google passes through this queue: duplicates are merged, quotas respected and failures retried with back-off.', 'searchpilot-pro' ),
				flush: true,
				actions: [
					ui.button( {
						label: __( 'Process now', 'searchpilot-pro' ),
						icon: 'bolt',
						size: 'small',
						onClick: () =>
							api.post( '/queue/process' ).then( ( res ) => {
								const r = res.result || {};
								ui.toast(
									res.message ||
										sprintf( /* translators: 1: sent, 2: retrying, 3: failed. */ __( 'Processed: %1$s sent, %2$s retrying, %3$s failed.', 'searchpilot-pro' ), fmt.number( r.done || 0 ), fmt.number( r.retry || 0 ), fmt.number( r.failed || 0 ) ),
									res.message ? 'warning' : 'success'
								);
								refreshAll();
							} ),
					} ),
				],
				body: [ h( 'div', { style: { padding: '0 20px' } }, q.toolbar ), q.table.el ],
			} )
		);
	}

	function historyTab() {
		const q = queueTable( {
			statuses: 'done,failed,skipped,cancelled',
			history: true,
			allowed: () => false,
			statusOptions: [
				{ value: 'done,failed,skipped,cancelled', label: __( 'All finished', 'searchpilot-pro' ) },
				{ value: 'done', label: __( 'Sent', 'searchpilot-pro' ) },
				{ value: 'failed', label: __( 'Failed', 'searchpilot-pro' ) },
				{ value: 'skipped', label: __( 'Skipped', 'searchpilot-pro' ) },
				{ value: 'cancelled', label: __( 'Cancelled', 'searchpilot-pro' ) },
			],
		} );
		return ui.card( {
			title: __( 'History', 'searchpilot-pro' ),
			subtitle: __( 'Finished submissions are kept for the history retention period (Settings → General).', 'searchpilot-pro' ),
			flush: true,
			actions: [
				ui.button( {
					label: __( 'Retry failed', 'searchpilot-pro' ),
					icon: 'refresh',
					size: 'small',
					onClick: () =>
						api.post( '/queue/retry-failed' ).then( ( res ) => {
							ui.toast( sprintf( /* translators: %s: number. */ _n( '%s failed submission queued again', '%s failed submissions queued again', res.retried, 'searchpilot-pro' ), fmt.number( res.retried ) ) );
							q.reload();
						} ),
				} ),
				ui.button( {
					label: __( 'Clear history', 'searchpilot-pro' ),
					icon: 'trash',
					size: 'small',
					variant: 'danger',
					onClick: () =>
						ui
							.confirm( {
								title: __( 'Clear the submission history?', 'searchpilot-pro' ),
								message: __( 'Sent, failed, skipped and cancelled items are deleted permanently. Pending and held items stay in the queue.', 'searchpilot-pro' ),
								confirmLabel: __( 'Clear history', 'searchpilot-pro' ),
								danger: true,
							} )
							.then( ( ok ) =>
								ok
									? api.del( '/queue/history' ).then( ( res ) => {
											ui.toast( sprintf( /* translators: %s: number. */ _n( '%s item deleted', '%s items deleted', res.deleted, 'searchpilot-pro' ), fmt.number( res.deleted ) ) );
											q.reload();
									  } )
									: null
							),
				} ),
			],
			body: [ h( 'div', { style: { padding: '0 20px' } }, q.toolbar ), q.table.el ],
		} );
	}

	function quotasTab() {
		const region = SPP.region( () =>
			api.get( '/queue/stats' ).then( ( s ) =>
				ui.card( {
					title: __( 'API quotas today', 'searchpilot-pro' ),
					subtitle: __( 'SearchPilot stays below each provider’s published limits and pauses automatically when a quota is reached. Daily counters reset at midnight Pacific Time (Google’s quota day).', 'searchpilot-pro' ),
					flush: true,
					actions: [ ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', size: 'small', onClick: () => region.refresh() } ) ],
					body: ui.table( {
						columns: [
							{ key: 'label', label: __( 'API', 'searchpilot-pro' ), render: ( r ) => h( 'span', { class: 'spp-strong' }, r.label ) },
							{ key: 'used_today', label: __( 'Used today', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.used_today ) },
							{ key: 'limit_day', label: __( 'Daily limit', 'searchpilot-pro' ), align: 'num', render: ( r ) => ( r.limit_day ? fmt.number( r.limit_day ) : __( 'Unlimited', 'searchpilot-pro' ) ) },
							{
								key: 'usage',
								label: __( 'Usage', 'searchpilot-pro' ),
								width: '28%',
								render: ( r ) => {
									if ( ! r.limit_day ) {
										return '—';
									}
									const ratio = r.used_today / r.limit_day;
									return ui.progress( { value: r.used_today, max: r.limit_day, tone: ratio >= 0.9 ? 'critical' : ratio >= 0.7 ? 'high' : 'passed', right: fmt.percent( ratio, 0 ) } );
								},
							},
							{
								key: 'blocked_until',
								label: __( 'Status', 'searchpilot-pro' ),
								render: ( r ) =>
									r.blocked_until
										? ui.badge( sprintf( /* translators: %s: relative time. */ __( 'Paused until %s', 'searchpilot-pro' ), fmt.datetime( r.blocked_until ) ), 'high', { dot: true } )
										: ui.badge( __( 'Available', 'searchpilot-pro' ), 'passed', { dot: true } ),
							},
						],
						rows: s.quotas || [],
						empty: __( 'No quota information yet.', 'searchpilot-pro' ),
					} ),
					footer: h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'The Bing URL Submission limit is your account’s own daily quota, refreshed from Bing once a day. The Google Indexing API allows 200 notifications per day by default; SearchPilot keeps a small reserve.', 'searchpilot-pro' ) ),
				} )
			)
		);
		return region.el;
	}

	/* ------------------------------------------------------------------ *
	 * Manual submission
	 * ------------------------------------------------------------------ */

	function submitModal() {
		let text = '';
		ui.modal( {
			title: __( 'Submit URLs', 'searchpilot-pro' ),
			body: ui.stack(
				h( 'p', { class: 'spp-muted' }, __( 'Queue up to 100 URLs of this site for IndexNow (and Bing URL Submission when enabled). Job-posting and livestream pages also go to the Google Indexing API when it is on. Google learns about everything else from your sitemap.', 'searchpilot-pro' ) ),
				ui.field.textarea( {
					label: __( 'URLs (one per line)', 'searchpilot-pro' ),
					rows: 8,
					placeholder: ( SPP.boot.homeUrl || 'https://example.com/' ) + '…',
					onInput: ( v ) => ( text = v ),
				} )
			),
			actions: ( close ) => [
				ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
				ui.button( {
					label: __( 'Queue URLs', 'searchpilot-pro' ),
					variant: 'primary',
					icon: 'bolt',
					onClick: () => {
						const urls = text
							.split( /[\r\n]+/ )
							.map( ( u ) => u.trim() )
							.filter( Boolean );
						if ( ! urls.length ) {
							return Promise.reject( new Error( __( 'Enter at least one URL of this site.', 'searchpilot-pro' ) ) );
						}
						return api.post( '/engines/submit', { urls } ).then( ( res ) => {
							ui.toast( res.message, res.held ? 'warning' : 'success', 7000 );
							( res.rejected || [] ).slice( 0, 3 ).forEach( ( r ) => ui.toast( fmt.url( r.url ) + ' — ' + r.reason, 'error', 8000 ) );
							close();
						} );
					},
				} ),
			],
		} );
	}

	/* ------------------------------------------------------------------ *
	 * View
	 * ------------------------------------------------------------------ */

	SPP.registerView( 'engines', {
		mount( el ) {
			let active = SPP.query.get( 'tab', 'connections' );
			if ( TABS.indexOf( active ) === -1 ) {
				active = 'connections';
			}
			const navSlot = h( 'div' );
			const body = h( 'div' );
			const counts = { queue: null, history: null };

			const renderNav = () =>
				SPP.mount(
					navSlot,
					ui.tabs( {
						tabs: [
							{ id: 'connections', label: __( 'Connections', 'searchpilot-pro' ) },
							{ id: 'queue', label: __( 'Submission queue', 'searchpilot-pro' ), count: counts.queue },
							{ id: 'history', label: __( 'History', 'searchpilot-pro' ) },
							{ id: 'quotas', label: __( 'Quotas', 'searchpilot-pro' ) },
						],
						active,
						onChange: ( id ) => {
							active = id;
							SPP.query.set( { tab: id === 'connections' ? null : id, service: null, q: null } );
							show();
						},
					} )
				);

			const refreshCounts = () =>
				api
					.get( '/queue/stats' )
					.then( ( s ) => {
						counts.queue = s.pending + s.held + s.processing + s.failed_24h || null;
						renderNav();
					} )
					.catch( () => null );

			const show = () => {
				let content;
				switch ( active ) {
					case 'queue':
						content = queueTab( refreshCounts );
						break;
					case 'history':
						content = historyTab();
						break;
					case 'quotas':
						content = quotasTab();
						break;
					default:
						content = SPP.engines ? connectionsTab( refreshCounts ) : ui.errorBox( new Error( __( 'The connection panels failed to load. Reload the page.', 'searchpilot-pro' ) ) );
				}
				SPP.mount( body, content );
			};

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Search Engines', 'searchpilot-pro' ),
					subtitle: __( 'Connect Google Search Console, Bing Webmaster Tools and IndexNow, and follow every notification SearchPilot sends through their official APIs.', 'searchpilot-pro' ),
					actions: [ ui.button( { label: __( 'Submit URLs', 'searchpilot-pro' ), icon: 'plus', onClick: submitModal } ) ],
				} ),
				navSlot,
				h( 'div', { style: { marginTop: '18px' } }, body )
			);
			renderNav();
			show();
			refreshCounts();
		},
	} );
} )( window.wp, window.SPP );

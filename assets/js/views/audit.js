/**
 * SEO Audit view: health score, issues by severity/category, passed checks.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const PER_PAGE = 20;

	const TABS = [
		{ id: 'all', label: __( 'All', 'searchpilot-pro' ) },
		{ id: 'indexing', label: __( 'Indexing', 'searchpilot-pro' ) },
		{ id: 'technical', label: __( 'Technical', 'searchpilot-pro' ) },
		{ id: 'sitemap', label: __( 'Sitemap', 'searchpilot-pro' ) },
		{ id: 'content', label: __( 'Content', 'searchpilot-pro' ) },
		{ id: 'links', label: __( 'Links', 'searchpilot-pro' ) },
		{ id: 'schema', label: __( 'Schema', 'searchpilot-pro' ) },
		{ id: 'performance', label: __( 'Performance', 'searchpilot-pro' ) },
		{ id: 'security', label: __( 'Security', 'searchpilot-pro' ) },
		{ id: 'opportunity', label: __( 'Opportunities', 'searchpilot-pro' ) },
	];

	const SHOW = [
		{ value: 'open', label: __( 'Open', 'searchpilot-pro' ) },
		{ value: 'ignored', label: __( 'Ignored', 'searchpilot-pro' ) },
		{ value: 'fixed,resolved', label: __( 'Fixed & resolved', 'searchpilot-pro' ) },
	];

	const SEVERITY_HINTS = {
		critical: __( 'Can remove pages or the whole site from search', 'searchpilot-pro' ),
		high: __( 'Hurts indexing or visibility of important pages', 'searchpilot-pro' ),
		medium: __( 'Worth fixing to keep the site healthy', 'searchpilot-pro' ),
		opportunity: __( 'Ideas to win more traffic', 'searchpilot-pro' ),
		passed: __( 'Monitoring checks that found no problem', 'searchpilot-pro' ),
	};

	SPP.registerView( 'audit', {
		mount( el ) {
			const caps = SPP.boot.caps || {};
			const state = {
				tab: TABS.some( ( t ) => t.id === SPP.query.get( 'category' ) ) ? SPP.query.get( 'category' ) : 'all',
				severity: SPP.query.get( 'severity' ),
				show: SHOW.some( ( s ) => s.value === SPP.query.get( 'show' ) ) ? SPP.query.get( 'show' ) : 'open',
				page: 1,
			};
			let audit = null;

			const persist = () => SPP.query.set( { category: state.tab === 'all' ? '' : state.tab, severity: state.severity, show: state.show === 'open' ? '' : state.show } );

			/* ---------------- Hero ---------------- */

			const hero = SPP.region( () =>
				api.get( '/audit' ).then( ( data ) => {
					audit = data;
					SPP.mount( tabsHolder, tabs() );
					SPP.mount( passedHolder, passedSection() );
					return renderHero( data );
				} )
			);

			function counter( severity, count ) {
				const active = state.severity === severity;
				const btn = h(
					'button',
					{
						type: 'button',
						class: [ 'spp-status-item', active && 'is-active' ],
						'aria-pressed': active ? 'true' : 'false',
						title: SEVERITY_HINTS[ severity ],
						style: { cursor: 'pointer', textAlign: 'left', width: '100%', font: 'inherit', outline: active ? '2px solid var(--spp-gold)' : null },
						onClick: () => {
							if ( severity === 'passed' ) {
								passedHolder.scrollIntoView( { behavior: 'smooth', block: 'start' } );
								return;
							}
							// Every counter is a severity filter within the active tab.
							state.severity = state.severity === severity ? '' : severity;
							state.page = 1;
							persist();
							SPP.mount( heroCounters, counters() );
							SPP.mount( tabsHolder, tabs() );
							list.refresh();
						},
					},
					ui.dot( severity ),
					h( 'div', null, h( 'div', { class: 'spp-status-title' }, fmt.number( count ), ' ', ui.SEVERITY_LABELS[ severity ] ), h( 'div', { class: 'spp-status-text' }, SEVERITY_HINTS[ severity ] ) )
				);
				return btn;
			}

			const heroCounters = h( 'div', { class: 'spp-status-grid', style: { flex: '1 1 480px' } } );
			function counters() {
				if ( ! audit ) {
					return null;
				}
				// Severity breakdown of the active tab ("All" excludes opportunities), so the
				// four issue counters always add up to the tab's count.
				const c = scopeCounts();
				// Severity order everywhere: Critical → High → Medium → Opportunity → Passed.
				return [ counter( 'critical', c.critical || 0 ), counter( 'high', c.high || 0 ), counter( 'medium', c.medium || 0 ), counter( 'opportunity', c.opportunity || 0 ), counter( 'passed', audit.passed || 0 ) ];
			}

			/** Open issue counts per severity for the active tab. */
			function scopeCounts() {
				if ( ! audit ) {
					return {};
				}
				if ( state.tab === 'all' ) {
					return audit.counts || {};
				}
				return ( audit.categories && audit.categories[ state.tab ] ) || {};
			}

			function renderHero( data ) {
				const score = data.score || {};
				const runs = data.runs || {};
				const c = data.counts || {};
				SPP.mount( heroCounters, counters() );
				const nodes = [
					ui.card( {
						accent: true,
						body: h(
							'div',
							{ class: 'spp-hero' },
							ui.scoreRing( score.score, {
								size: 104,
								caption: __( 'Health score · internal diagnostic — not a search ranking', 'searchpilot-pro' ),
								title: __( 'Internal diagnostic weighted by open issues (critical −20, high −3, medium −0.5). It is not a search-engine ranking score.', 'searchpilot-pro' ),
							} ),
							h(
								'div',
								{ class: 'spp-hero-text' },
								h( 'h3', { class: 'spp-hero-title' }, c.critical ? sprintf( /* translators: %d: number of critical issues. */ _n( '%d critical problem needs attention', '%d critical problems need attention', c.critical, 'searchpilot-pro' ), c.critical ) : c.high ? __( 'No critical problems — a few important fixes left', 'searchpilot-pro' ) : __( 'Your site looks healthy', 'searchpilot-pro' ) ),
								h(
									'p',
									{ class: 'spp-muted', style: { margin: '0 0 12px' } },
									runs.full_at ? sprintf( /* translators: 1: time of full audit, 2: time of critical checks. */ __( 'Full audit %1$s · critical checks %2$s. SearchPilot re-checks automatically every hour and runs the full audit daily.', 'searchpilot-pro' ), fmt.relative( runs.full_at ), fmt.relative( runs.critical_at ) ) : __( 'The first full audit has not run yet. Run it now or wait for the daily background run.', 'searchpilot-pro' )
								),
								data.alerts && ! data.alerts.enabled && caps.manage
									? h( 'p', { class: 'spp-small spp-muted', style: { margin: 0 } }, __( 'E-mail alerts are off. Turn them on in Settings to hear about critical problems immediately.', 'searchpilot-pro' ) )
									: null
							),
							heroCounters
						),
					} ),
				];
				if ( data.crawl && data.crawl.paused_until ) {
					nodes.push(
						ui.notice( {
							tone: 'high',
							title: __( 'Page checks are paused', 'searchpilot-pro' ),
							text: __( 'SearchPilot could not reach your own pages, so issues found on rendered pages may be out of date. See the “SearchPilot cannot check your pages” issue below.', 'searchpilot-pro' ),
						} )
					);
				}
				return h( 'div', { class: 'spp-stack' }, nodes );
			}

			/* ---------------- Tabs ---------------- */

			const tabsHolder = h( 'div' );
			function tabs() {
				const cats = ( audit && audit.categories ) || {};
				const all = audit && audit.counts ? audit.counts.total : null;
				return ui.tabs( {
					tabs: TABS.map( ( t ) => ( {
						id: t.id,
						label: t.label,
						count: t.id === 'all' ? all : cats[ t.id ] ? cats[ t.id ].total : null,
					} ) ),
					active: state.tab,
					onChange: ( id ) => {
						state.tab = id;
						state.page = 1;
						persist();
						SPP.mount( heroCounters, counters() );
						list.refresh();
					},
				} );
			}

			/* ---------------- Issue list ---------------- */

			const baseQuery = () => {
				const query = { status: state.show, page: state.page, per_page: PER_PAGE, orderby: 'severity' };
				if ( state.tab === 'all' ) {
					query.exclude_category = 'opportunity';
				} else {
					query.category = state.tab;
				}
				if ( state.severity ) {
					query.severity = state.severity;
				}
				return query;
			};

			const list = SPP.region( () =>
				api.get( '/issues', baseQuery() ).then( ( data ) => {
					const items = data.items || [];
					if ( ! items.length ) {
						return ui.empty( {
							icon: state.show === 'open' ? 'check' : 'info',
							title: state.show === 'open' ? ( state.severity || state.tab !== 'all' ? __( 'No open issues match this filter', 'searchpilot-pro' ) : __( 'No open issues — nice work', 'searchpilot-pro' ) ) : __( 'Nothing here', 'searchpilot-pro' ),
							text: state.show === 'open' ? __( 'SearchPilot keeps monitoring and adds new findings here.', 'searchpilot-pro' ) : null,
						} );
					}
					return h(
						'div',
						{ class: 'spp-stack' },
						items.map( ( issue ) =>
							ui.issue( issue, {
								compact: items.length > 6,
								onChanged: () => {
									hero.refresh();
									list.refresh();
								},
							} )
						),
						ui.pagination( {
							page: data.page,
							pages: data.pages,
							total: data.total,
							perPage: PER_PAGE,
							onPage: ( p ) => {
								state.page = p;
								list.refresh();
								listCard.scrollIntoView( { behavior: 'smooth', block: 'start' } );
							},
						} )
					);
				} )
			);

			/* ---------------- Fixes: safe fixes, review, background runs ---------------- */

			const RUN_STATUS = {
				queued: __( 'Queued', 'searchpilot-pro' ),
				running: __( 'Applying fixes', 'searchpilot-pro' ),
				done: __( 'Finished', 'searchpilot-pro' ),
				cancelled: __( 'Cancelled', 'searchpilot-pro' ),
			};
			const ITEM_STATUS = {
				pending: [ __( 'Waiting', 'searchpilot-pro' ), 'outline' ],
				running: [ __( 'Applying', 'searchpilot-pro' ), 'gold' ],
				verified: [ __( 'Fixed and verified', 'searchpilot-pro' ), 'passed' ],
				applied: [ __( 'Applied, not confirmed', 'searchpilot-pro' ), 'high' ],
				pending_verification: [ __( 'Verification pending', 'searchpilot-pro' ), 'info' ],
				introduced: [ __( 'Undone automatically', 'searchpilot-pro' ), 'medium' ],
				failed: [ __( 'Failed', 'searchpilot-pro' ), 'critical' ],
				skipped: [ __( 'Skipped', 'searchpilot-pro' ), 'outline' ],
			};

			function runSummary( run ) {
				const c = run.counts || {};
				const parts = [ sprintf( /* translators: 1: done, 2: total. */ __( '%1$d of %2$d done', 'searchpilot-pro' ), run.done || 0, run.total || 0 ) ];
				if ( c.verified ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d verified', '%d verified', c.verified, 'searchpilot-pro' ), c.verified ) );
				}
				if ( c.pending_verification ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d pending verification', '%d pending verification', c.pending_verification, 'searchpilot-pro' ), c.pending_verification ) );
				}
				if ( c.applied ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d not confirmed', '%d not confirmed', c.applied, 'searchpilot-pro' ), c.applied ) );
				}
				if ( c.introduced ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d undone automatically', '%d undone automatically', c.introduced, 'searchpilot-pro' ), c.introduced ) );
				}
				if ( c.failed ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d failed', '%d failed', c.failed, 'searchpilot-pro' ), c.failed ) );
				}
				if ( c.skipped ) {
					parts.push( sprintf( /* translators: %d: number of fixes. */ _n( '%d skipped', '%d skipped', c.skipped, 'searchpilot-pro' ), c.skipped ) );
				}
				return parts.join( ' · ' );
			}

			function undoRun( run ) {
				return ui
					.confirm( {
						title: __( 'Undo this run?', 'searchpilot-pro' ),
						message: sprintf( /* translators: %s: run label. */ __( 'Every change made by “%s” is reverted. Items edited by someone since are left alone and listed.', 'searchpilot-pro' ), run.label || __( 'SEO fixes', 'searchpilot-pro' ) ),
						confirmLabel: __( 'Undo this run', 'searchpilot-pro' ),
						danger: true,
					} )
					.then( ( ok ) => {
						if ( ! ok ) {
							return null;
						}
						// The undo runs in the background (large runs take minutes); follow its progress.
						return api.post( '/issues/runs/' + run.id + '/undo' ).then( ( started ) => {
							loadRuns();
							const wait = ( r ) => {
								if ( r.undo && r.undo.active ) {
									return SPP.util.sleep( 2000 ).then( () => api.get( '/issues/runs/' + run.id ) ).then( wait );
								}
								const u = r.undo || {};
								ui.toast(
									u.failed
										? sprintf( /* translators: 1: undone, 2: not undone. */ __( '%1$d changes undone, %2$d could not be undone (edited since).', 'searchpilot-pro' ), u.undone || 0, u.failed )
										: sprintf( /* translators: %d: changes. */ _n( '%d change undone', '%d changes undone', u.undone || 0, 'searchpilot-pro' ), u.undone || 0 ),
									u.failed ? 'warning' : 'success'
								);
								hero.refresh();
								list.refresh();
								loadRuns();
								return r;
							};
							return wait( started );
						} );
					} );
			}

			function runItems( run ) {
				const items = ( run.items || [] ).filter( ( i ) => i.status !== 'pending' );
				if ( ! items.length ) {
					return null;
				}
				return h(
					'ul',
					{ class: 'spp-run-items' },
					items.map( ( i ) => {
						const st = ITEM_STATUS[ i.status ] || [ i.status, 'outline' ];
						return h( 'li', null, ui.badge( st[ 0 ], st[ 1 ] ), h( 'span', { class: 'spp-run-item-title' }, i.title || '#' + i.issue_id ), i.message ? h( 'span', { class: 'spp-muted spp-small' }, i.message ) : null );
					} )
				);
			}

			/** Progress card of one run (banner and review modal). */
			function runCard( run, opts ) {
				opts = opts || {};
				const details = h( 'div', { class: 'spp-run-details', hidden: ! opts.expanded }, runItems( run ) );
				const actions = [];
				if ( run.active ) {
					actions.push(
						ui.button( {
							label: __( 'Cancel', 'searchpilot-pro' ),
							size: 'small',
							variant: 'ghost',
							onClick: () => api.post( '/issues/runs/' + run.id + '/cancel' ).then( () => loadRuns() ),
						} )
					);
				}
				if ( run.undoable ) {
					actions.push(
						ui.button( {
							label: __( 'Undo this run', 'searchpilot-pro' ),
							icon: 'undo',
							size: 'small',
							onClick: () =>
								undoRun( run ).then( ( res ) => {
									if ( res && opts.onUndone ) {
										opts.onUndone();
									}
									return res;
								} ),
						} )
					);
				}
				if ( ( run.items || [] ).length || ! opts.expanded ) {
					actions.push(
						ui.button( {
							label: __( 'Details', 'searchpilot-pro' ),
							size: 'small',
							variant: 'link',
							onClick: () => {
								if ( ! run.items ) {
									return api.get( '/issues/runs/' + run.id ).then( ( full ) => {
										SPP.mount( details, runItems( full ) );
										details.hidden = false;
									} );
								}
								details.hidden = ! details.hidden;
								return null;
							},
						} )
					);
				}
				if ( ! run.active && opts.onDismiss ) {
					actions.push( ui.button( { label: __( 'Dismiss', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: opts.onDismiss } ) );
				}
				return h(
					'div',
					{ class: [ 'spp-run', run.active && 'is-active' ] },
					h(
						'div',
						{ class: 'spp-run-head' },
						h(
							'div',
							{ class: 'spp-grow' },
							h( 'div', { class: 'spp-strong' }, run.label || __( 'SEO fixes', 'searchpilot-pro' ) ),
							h( 'div', { class: 'spp-muted spp-small' }, ( RUN_STATUS[ run.status ] || run.status ) + ( run.undone_at ? ' · ' + __( 'undone', 'searchpilot-pro' ) : '' ) + ' · ' + runSummary( run ) ),
							run.undo && run.undo.active
								? h( 'div', { class: 'spp-small' }, sprintf( /* translators: 1: done, 2: total. */ __( 'Undoing in the background… %1$d of %2$d changes', 'searchpilot-pro' ), run.undo.done || 0, run.undo.total || 0 ) )
								: null
						),
						h( 'div', { class: 'spp-row' }, actions )
					),
					ui.progress( { value: run.done || 0, max: run.total || 1, tone: run.active ? 'gold' : null } ),
					run.active ? h( 'p', { class: 'spp-muted spp-small', style: { margin: 0 } }, __( 'Fixes are applied in the background — you can close this page.', 'searchpilot-pro' ) ) : null,
					details
				);
			}

			/* Background runs banner (in progress, or finished within the last hour). */
			const runsHolder = h( 'div', { class: 'spp-stack spp-runs' } );
			const dismissed = new Set();
			const knownActive = new Set();
			let runsTimer = null;
			try {
				JSON.parse( window.sessionStorage.getItem( 'spp-dismissed-runs' ) || '[]' ).forEach( ( id ) => dismissed.add( id ) );
			} catch ( e ) {}
			function loadRuns() {
				if ( ! caps.edit ) {
					return Promise.resolve();
				}
				clearTimeout( runsTimer );
				return api
					.get( '/issues/runs' )
					.then( ( res ) => {
						const now = Date.now();
						const items = ( res.items || [] ).filter( ( r ) => ! dismissed.has( r.id ) && ( r.active || ( r.undo && r.undo.active ) || ( r.finished_at && now - SPP.parseDate( r.finished_at ).getTime() < 3600 * 1000 ) ) );
						let finished = false;
						knownActive.forEach( ( id ) => {
							if ( ! items.some( ( r ) => r.id === id && r.active ) ) {
								finished = true;
								knownActive.delete( id );
							}
						} );
						items.filter( ( r ) => r.active ).forEach( ( r ) => knownActive.add( r.id ) );
						SPP.mount(
							runsHolder,
							items.map( ( run ) =>
								runCard( run, {
									onDismiss: () => {
										dismissed.add( run.id );
										try {
											window.sessionStorage.setItem( 'spp-dismissed-runs', JSON.stringify( Array.from( dismissed ) ) );
										} catch ( e ) {}
										loadRuns();
									},
								} )
							)
						);
						if ( finished ) {
							hero.refresh();
							list.refresh();
						}
						if ( items.some( ( r ) => r.active || ( r.undo && r.undo.active ) ) ) {
							runsTimer = setTimeout( loadRuns, 3000 );
						}
					} )
					.catch( () => {} );
			}

			/** Polls one run inside a container until it finishes. */
			function followRun( run, holder, onDone ) {
				let timer = null;
				let stopped = false;
				const render = ( r ) =>
					SPP.mount(
						holder,
						runCard( r, {
							expanded: true,
							onUndone: () =>
								api
									.get( '/issues/runs/' + r.id )
									.then( render )
									.catch( () => {} ),
						} )
					);
				const tick = () =>
					api
						.get( '/issues/runs/' + run.id )
						.then( ( r ) => {
							if ( stopped ) {
								return;
							}
							render( r );
							if ( r.active ) {
								timer = setTimeout( tick, 2000 );
							} else {
								onDone && onDone( r );
							}
						} )
						.catch( ( err ) => {
							if ( ! stopped ) {
								SPP.mount( holder, ui.errorBox( err ) );
							}
						} );
				render( run );
				timer = setTimeout( tick, 1500 );
				return () => {
					stopped = true;
					clearTimeout( timer );
				};
			}

			/** Scope of the fix review: the active tab. */
			const fixScope = () => ( state.tab === 'all' ? { exclude_category: 'opportunity' } : { category: state.tab } );

			/** Bulk review: choose a kind of issue → review its fixes → apply in the background. */
			function reviewFixes() {
				const stage = h( 'div' );
				const footer = h( 'div', { class: 'spp-row' } );
				let stop = null;
				const modal = ui.modal( {
					title: __( 'Review fixes', 'searchpilot-pro' ),
					size: 'large',
					body: stage,
					actions: [ footer ],
					onClose: () => {
						if ( stop ) {
							stop();
						}
						loadRuns();
						hero.refresh();
						list.refresh();
					},
				} );
				const close = () => modal.close();

				function chooseType() {
					SPP.mount( footer, ui.button( { label: __( 'Close', 'searchpilot-pro' ), onClick: close } ) );
					SPP.mount( stage, ui.loading() );
					api.get( '/issues/fixable-types', fixScope() ).then(
						( res ) => {
							const types = res.items || [];
							if ( ! types.length ) {
								SPP.mount( stage, ui.empty( { icon: 'check', title: __( 'No automatic fixes to review here', 'searchpilot-pro' ), text: __( 'The open issues in this view need a manual step — each issue says exactly what to do.', 'searchpilot-pro' ) } ) );
								return;
							}
							SPP.mount(
								stage,
								h( 'p', { class: 'spp-muted', style: { marginTop: 0 } }, __( 'Choose a kind of issue. SearchPilot prepares every fix so you can check each change before anything is applied.', 'searchpilot-pro' ) ),
								h(
									'div',
									{ class: 'spp-stack' },
									types.map( ( t ) =>
										h(
											'div',
											{ class: 'spp-fix-type' },
											h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-strong' }, t.label ), h( 'div', { class: 'spp-row', style: { marginTop: '4px' } }, ui.severity( t.severity ), ui.fixMode( t.mode ), h( 'span', { class: 'spp-muted spp-small' }, sprintf( /* translators: %d: issues. */ _n( '%d open issue', '%d open issues', t.count, 'searchpilot-pro' ), t.count ) ) ) ),
											ui.button( { label: __( 'Review', 'searchpilot-pro' ), variant: 'primary', size: 'small', onClick: () => reviewType( t ) } )
										)
									)
								)
							);
						},
						( err ) => SPP.mount( stage, ui.errorBox( err ) )
					);
				}

				function reviewType( type ) {
					const selected = new Map();
					const listEl = h( 'div', { class: 'spp-fix-list' } );
					const status = h( 'div', { class: 'spp-muted spp-small' } );
					let page = 0;
					let pages = 1;
					const applyBtn = ui.button( {
						label: __( 'Apply selected fixes', 'searchpilot-pro' ),
						icon: 'bolt',
						variant: 'primary',
						disabled: true,
						busyLabel: __( 'Starting…', 'searchpilot-pro' ),
						onClick: () =>
							api
								.post( '/issues/apply-bulk', {
									items: Array.from( selected.entries() ).map( ( [ id, fingerprint ] ) => ( { issue_id: id, fingerprint } ) ),
								} )
								.then( ( run ) => progress( run ) ),
					} );
					const moreBtn = ui.button( { label: __( 'Load more', 'searchpilot-pro' ), size: 'small', onClick: () => load() } );
					let manual = 0;
					moreBtn.style.display = 'none';
					const refreshCount = () => {
						applyBtn.disabled = ! selected.size;
						const label = applyBtn.querySelector( '.spp-btn-label' );
						if ( label ) {
							label.textContent = sprintf( /* translators: %d: number of fixes. */ _n( 'Apply %d fix', 'Apply %d fixes', selected.size, 'searchpilot-pro' ), selected.size );
						}
						moreBtn.style.display = page >= pages ? 'none' : '';
					};
					const row = ( item ) => {
						const plan = item.plan;
						const cb = h( 'input', { type: 'checkbox', checked: !! plan, disabled: ! plan, 'aria-label': item.issue.title || item.issue.url } );
						if ( plan ) {
							selected.set( item.issue.id, item.fingerprint );
						}
						cb.addEventListener( 'change', () => {
							cb.checked ? selected.set( item.issue.id, item.fingerprint ) : selected.delete( item.issue.id );
							refreshCount();
						} );
						const detail = h( 'div', { class: 'spp-fix-detail', hidden: true } );
						const toggle = plan
							? ui.button( {
									label: __( 'Show changes', 'searchpilot-pro' ),
									size: 'small',
									variant: 'link',
									onClick: () => {
										if ( ! detail.childNodes.length ) {
											SPP.mount( detail, ( plan.warnings || [] ).map( ( w ) => ui.notice( { tone: 'warning', text: w } ) ), ui.planChanges( plan ).el );
										}
										detail.hidden = ! detail.hidden;
									},
							  } )
							: item.issue.edit_link
							? ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', href: item.issue.edit_link } )
							: null;
						return h(
							'div',
							{ class: [ 'spp-fix-item', ! plan && 'is-error' ] },
							h(
								'div',
								{ class: 'spp-fix-item-head' },
								cb,
								h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-strong' }, item.issue.title || fmt.url( item.issue.url ) ), h( 'div', { class: plan ? 'spp-small' : 'spp-small spp-muted' }, plan ? plan.summary : item.error ) ),
								toggle
							),
							detail
						);
					};
					const load = () => {
						page++;
						return api.post( '/issues/plan-bulk', { type: type.type, page, per_page: 20 } ).then(
							( res ) => {
								pages = res.pages || 1;
								manual += res.manual || 0;
								if ( 1 === page ) {
									listEl.replaceChildren();
								}
								( res.items || [] ).forEach( ( item ) => listEl.appendChild( row( item ) ) );
								if ( ! listEl.childNodes.length ) {
									SPP.mount( listEl, ui.empty( { icon: 'check', title: __( 'Nothing left to fix for this issue type', 'searchpilot-pro' ) } ) );
								}
								status.textContent =
									sprintf( /* translators: 1: shown, 2: total. */ __( 'Showing %1$d of %2$d open issues. Untick anything you do not want changed.', 'searchpilot-pro' ), listEl.querySelectorAll( '.spp-fix-item' ).length, res.total || 0 ) +
									( manual ? ' ' + sprintf( /* translators: %d: number of issues. */ _n( '%d issue needs a manual step and is not listed.', '%d issues need a manual step and are not listed.', manual, 'searchpilot-pro' ), manual ) : '' ) +
									( res.partial ? ' ' + __( 'Some fixes could not be prepared in time — load more to continue.', 'searchpilot-pro' ) : '' );
								refreshCount();
							},
							( err ) => SPP.mount( listEl, ui.errorBox( err ) )
						);
					};
					SPP.mount( stage, h( 'div', { class: 'spp-stack' }, h( 'div', { class: 'spp-row is-between' }, h( 'div', null, h( 'div', { class: 'spp-strong' }, type.label ), status ), ui.fixMode( type.mode ) ), listEl ) );
					SPP.mount( footer, ui.button( { label: __( 'Back', 'searchpilot-pro' ), variant: 'ghost', onClick: chooseType } ), moreBtn, applyBtn );
					SPP.mount( listEl, ui.loading( __( 'Preparing fixes…', 'searchpilot-pro' ) ) );
					return load();
				}

				function progress( run ) {
					const holder = h( 'div' );
					SPP.mount( stage, h( 'div', { class: 'spp-stack' }, h( 'p', { class: 'spp-muted', style: { margin: 0 } }, __( 'SearchPilot applies each fix, re-checks the page and undoes a fix automatically if it causes a new problem. This continues in the background if you close this window.', 'searchpilot-pro' ) ), holder ) );
					SPP.mount( footer, ui.button( { label: __( 'Close', 'searchpilot-pro' ), onClick: close } ) );
					stop = followRun( run, holder, () => {
						hero.refresh();
						list.refresh();
					} );
				}

				chooseType();
				return null;
			}

			/**
			 * Fix every open issue of the current filter that is safe without review:
			 * legacy non-destructive fixers (applied now) and deterministic planners
			 * (applied in the background with verification).
			 */
			function fixAllSafe() {
				const query = Object.assign( baseQuery(), { status: 'open', page: 1, per_page: 200 } );
				return api.get( '/issues', query ).then( ( data ) => {
					const items = data.items || [];
					const fixable = items.filter( ( i ) => i.fixable && ! i.destructive );
					const open = fixable.filter( ( i ) => i.status === 'open' );
					const direct = open.filter( ( i ) => ! i.previewable );
					const background = open.filter( ( i ) => i.previewable && i.mode === 'deterministic' );
					const review = open.filter( ( i ) => i.previewable && i.mode !== 'deterministic' ).length;
					const held = fixable.length - open.length;
					const total = direct.length + background.length;
					if ( ! total ) {
						let text = __( 'No safe automatic fixes are available for these issues.', 'searchpilot-pro' );
						if ( review ) {
							text = sprintf( /* translators: %d: number of issues. */ _n( '%d fix needs a quick review first — use “Review fixes”.', '%d fixes need a quick review first — use “Review fixes”.', review, 'searchpilot-pro' ), review );
						} else if ( held ) {
							text = sprintf( /* translators: %d: number of issues. */ _n( 'No safe automatic fixes here. %d fix is waiting for your approval on the Automation screen.', 'No safe automatic fixes here. %d fixes are waiting for your approval on the Automation screen.', held, 'searchpilot-pro' ), held );
						}
						ui.toast( text );
						return null;
					}
					let message = sprintf( /* translators: %d: number of issues. */ _n( 'SearchPilot will apply %d safe fix. Every change is re-checked, recorded and can be undone.', 'SearchPilot will apply %d safe fixes. Every change is re-checked, recorded and can be undone.', total, 'searchpilot-pro' ), total );
					if ( background.length ) {
						message += ' ' + __( 'Fixes that change page content run in the background; progress appears above the issue list.', 'searchpilot-pro' );
					}
					if ( review ) {
						message += ' ' + sprintf( /* translators: %d: number of issues. */ _n( '%d fix that needs a review is not included — use “Review fixes”.', '%d fixes that need a review are not included — use “Review fixes”.', review, 'searchpilot-pro' ), review );
					}
					if ( held ) {
						message += ' ' + sprintf( /* translators: %d: number of issues. */ _n( '%d fix awaiting approval is not included — review it in the Approvals list on the Automation screen.', '%d fixes awaiting approval are not included — review them in the Approvals list on the Automation screen.', held, 'searchpilot-pro' ), held );
					}
					return ui
						.confirm( {
							title: __( 'Fix all safe issues?', 'searchpilot-pro' ),
							message,
							confirmLabel: __( 'Fix them', 'searchpilot-pro' ),
						} )
						.then( ( ok ) => {
							if ( ! ok ) {
								return null;
							}
							const jobs = [];
							if ( background.length ) {
								jobs.push( api.post( '/issues/apply-bulk', { ids: background.map( ( i ) => i.id ) } ).then( () => loadRuns() ) );
							}
							if ( direct.length ) {
								jobs.push(
									api.post( '/issues/bulk', { action: 'fix', ids: direct.map( ( i ) => i.id ) } ).then( ( res ) => {
										const failed = Object.keys( res.errors || {} ).length;
										const undo = res.group_id ? { action: { label: __( 'Undo', 'searchpilot-pro' ), onClick: () => SPP.undoFixGroup( res.group_id ).then( () => list.refresh() ) } } : {};
										ui.toast( failed ? sprintf( /* translators: 1: fixed, 2: failed. */ __( '%1$d fixed, %2$d could not be fixed', 'searchpilot-pro' ), res.done || 0, failed ) : sprintf( /* translators: %d: fixed. */ _n( '%d issue fixed', '%d issues fixed', res.done || 0, 'searchpilot-pro' ), res.done || 0 ), failed ? 'warning' : 'success', undo );
									} )
								);
							}
							return Promise.all( jobs ).then( () => {
								hero.refresh();
								list.refresh();
							} );
						} );
				} );
			}

			const showToggle = ui.buttonGroup( {
				options: SHOW,
				value: state.show,
				onChange: ( v ) => {
					state.show = v;
					state.page = 1;
					persist();
					list.refresh();
				},
			} );

			const listActions = [ showToggle ];
			if ( caps.edit ) {
				listActions.push( ui.button( { label: __( 'Review fixes', 'searchpilot-pro' ), icon: 'eye', size: 'small', onClick: reviewFixes } ) );
				listActions.push( ui.button( { label: __( 'Fix all safe issues', 'searchpilot-pro' ), icon: 'bolt', variant: 'gold', size: 'small', busyLabel: __( 'Fixing…', 'searchpilot-pro' ), onClick: fixAllSafe } ) );
			}

			const listCard = ui.card( {
				title: __( 'Issues', 'searchpilot-pro' ),
				subtitle: __( 'What happened → why it matters → recommended fix. Changes that affect how search engines see a page always ask first.', 'searchpilot-pro' ),
				actions: listActions,
				body: [ tabsHolder, h( 'div', { style: { marginTop: '16px' } }, list.el ) ],
			} );

			/* ---------------- Passed checks ---------------- */

			const passedHolder = h( 'div' );
			function passedSection() {
				if ( ! audit ) {
					return null;
				}
				const checks = audit.checks || [];
				const passed = checks.filter( ( c ) => c.status === 'passed' );
				const skipped = checks.filter( ( c ) => c.status === 'skipped' );
				const failing = checks.filter( ( c ) => c.status !== 'passed' && c.status !== 'skipped' );
				const body = [];
				if ( ! checks.length ) {
					body.push( ui.empty( { icon: 'shield', title: __( 'No monitoring checks have run yet', 'searchpilot-pro' ), text: __( 'Run the audit to check search visibility, robots.txt, sitemaps, canonicals, server errors and background jobs.', 'searchpilot-pro' ) } ) );
				}
				if ( failing.length ) {
					body.push(
						h( 'h4', { class: 'spp-strong', style: { margin: '0 0 8px' } }, __( 'Needs attention', 'searchpilot-pro' ) ),
						h(
							'div',
							{ class: 'spp-stack', style: { marginBottom: '16px' } },
							failing.map( ( c ) =>
								ui.checkRow( {
									label: c.label,
									message: c.message,
									status: c.status === 'critical' ? 'fail' : 'warning',
								} )
							)
						)
					);
				}
				if ( passed.length ) {
					body.push( h( 'div', { class: 'spp-stack' }, passed.map( ( c ) => ui.passed( c.label, c.message ) ) ) );
				}
				if ( skipped.length ) {
					body.push(
						h( 'h4', { class: 'spp-strong', style: { margin: '16px 0 8px' } }, __( 'Not checked', 'searchpilot-pro' ) ),
						h( 'div', { class: 'spp-stack' }, skipped.map( ( c ) => ui.checkRow( { label: c.label, message: c.message, status: 'info' } ) ) )
					);
				}
				return ui.card( {
					title: sprintf( /* translators: %d: number of passed checks. */ _n( '%d check passed', '%d checks passed', passed.length, 'searchpilot-pro' ), passed.length ),
					subtitle: __( 'Site-wide monitoring: critical checks every hour, all checks daily.', 'searchpilot-pro' ),
					body,
				} );
			}

			/* ---------------- Header ---------------- */

			const actions = [];
			if ( caps.manage ) {
				actions.push(
					ui.button( {
						label: __( 'Run audit now', 'searchpilot-pro' ),
						icon: 'refresh',
						variant: 'primary',
						busyLabel: __( 'Auditing…', 'searchpilot-pro' ),
						onClick: () =>
							api.post( '/audit/run' ).then( ( res ) => {
								// The audit re-runs the page, link and content checks behind the issue rows too.
								const crawl = res.crawl || {};
								const refresh = res.refresh || {};
								const analysis = refresh.analysis || {};
								const resolved = ( ( refresh.links && refresh.links.resolved ) || 0 ) + ( ( refresh.registry && refresh.registry.issues ) || 0 );
								const parts = [];
								if ( crawl.checked ) {
									parts.push( sprintf( /* translators: %d: pages checked. */ _n( '%d page checked', '%d pages checked', crawl.checked, 'searchpilot-pro' ), crawl.checked ) );
								}
								if ( refresh.links ) {
									parts.push( __( 'link checks refreshed', 'searchpilot-pro' ) );
								}
								if ( analysis.analyzed ) {
									parts.push( sprintf( /* translators: %d: posts analyzed. */ _n( '%d post re-analyzed', '%d posts re-analyzed', analysis.analyzed, 'searchpilot-pro' ), analysis.analyzed ) );
								}
								if ( analysis.queued ) {
									parts.push( sprintf( /* translators: %d: posts queued. */ _n( '%d more in the background', '%d more in the background', analysis.queued, 'searchpilot-pro' ), analysis.queued ) );
								}
								if ( resolved ) {
									parts.push( sprintf( /* translators: %d: issues resolved. */ _n( '%d issue resolved', '%d issues resolved', resolved, 'searchpilot-pro' ), resolved ) );
								}
								ui.toast(
									parts.length
										? sprintf( /* translators: %s: what the audit did, e.g. "12 pages checked · 3 issues resolved". */ __( 'Audit complete — %s', 'searchpilot-pro' ), parts.join( ' · ' ) )
										: __( 'Audit complete', 'searchpilot-pro' ),
									'success'
								);
								hero.refresh();
								list.refresh();
							} ),
					} ),
					ui.button( {
						label: __( 'Send test alert', 'searchpilot-pro' ),
						icon: 'external',
						variant: 'ghost',
						onClick: () =>
							api.post( '/audit/test-alert' ).then( ( res ) => {
								ui.toast( sprintf( /* translators: %s: recipients. */ __( 'Test alert sent to %s', 'searchpilot-pro' ), ( res.recipients || [] ).join( ', ' ) ) );
							} ),
					} )
				);
			}

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'SEO Audit', 'searchpilot-pro' ),
					subtitle: __( 'Everything that can keep your pages out of search, ordered by impact. SearchPilot only uses official search engine APIs and its own page checks.', 'searchpilot-pro' ),
					actions,
				} ),
				hero.el,
				runsHolder,
				listCard,
				passedHolder
			);
			loadRuns();
		},
	} );
} )( window.wp, window.SPP );

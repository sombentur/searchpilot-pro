/**
 * Settings → Import & migration tab.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { h, ui, api, fmt } = SPP;
	const field = ui.field;

	const STATUS = {
		idle: [ __( 'Not started', 'searchpilot-pro' ), 'outline' ],
		running: [ __( 'Running', 'searchpilot-pro' ), 'gold' ],
		done: [ __( 'Finished', 'searchpilot-pro' ), 'passed' ],
		cancelled: [ __( 'Cancelled', 'searchpilot-pro' ), 'outline' ],
		failed: [ __( 'Failed', 'searchpilot-pro' ), 'critical' ],
	};

	const PHASES = {
		posts: __( 'Posts and pages', 'searchpilot-pro' ),
		terms: __( 'Categories, tags and other terms', 'searchpilot-pro' ),
		settings: __( 'Settings', 'searchpilot-pro' ),
		done: __( 'Done', 'searchpilot-pro' ),
	};

	function mountImport( container ) {
		const state = {
			data: null,
			source: '',
			overwrite: false,
			include: { posts: true, terms: true, settings: false },
			timer: 0,
		};
		const root = h( 'div', { class: 'spp-stack' } );
		SPP.mount( container, root );

		const alive = () => document.body.contains( container );

		const schedule = () => {
			clearTimeout( state.timer );
			const d = state.data;
			const busy = d && ( ( d.progress && d.progress.status === 'running' ) || ( d.undo && d.undo.status === 'running' ) );
			if ( busy && alive() ) {
				state.timer = setTimeout( () => load().catch( () => schedule() ), 3000 );
			}
		};

		const load = ( fresh ) =>
			api.get( '/import', fresh ? { fresh: 1 } : undefined ).then( ( data ) => {
				if ( ! alive() ) {
					return;
				}
				state.data = data;
				if ( ! state.source ) {
					const first = ( data.sources || [] ).find( ( s ) => s.available );
					state.source = first ? first.id : '';
				}
				render();
				schedule();
			} );

		/* ---------- sections ---------- */

		const intro = () =>
			ui.card( {
				title: __( 'Import & migration', 'searchpilot-pro' ),
				subtitle: __( 'Move from Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework without losing any work.', 'searchpilot-pro' ),
				body: h(
					'div',
					{ class: 'spp-stack' },
					h(
						'p',
						null,
						__( 'SearchPilot copies SEO titles, meta descriptions, focus keywords, canonical URLs, robots settings, social titles and images, breadcrumb titles, primary categories and cornerstone flags into its own fields. Title variables are converted automatically.', 'searchpilot-pro' )
					),
					h(
						'ul',
						{ class: 'spp-list' },
						h( 'li', null, __( 'The other plugin’s data is never changed or deleted — you can keep using it until you switch.', 'searchpilot-pro' ) ),
						h( 'li', null, __( 'Runs in the background in small batches, so large sites stay fast.', 'searchpilot-pro' ) ),
						h( 'li', null, __( 'Every imported post is recorded in the activity log; the whole import can be undone in one click.', 'searchpilot-pro' ) )
					)
				),
			} );

		const sourceCards = () => {
			const sources = state.data.sources || [];
			if ( ! sources.some( ( s ) => s.available ) ) {
				return ui.card( {
					title: __( '1. Choose where to import from', 'searchpilot-pro' ),
					actions: [ ui.button( { label: __( 'Scan again', 'searchpilot-pro' ), icon: 'refresh', size: 'small', onClick: () => load( true ) } ) ],
					body: ui.empty( { icon: 'search', title: __( 'No SEO plugin data found', 'searchpilot-pro' ), text: __( 'SearchPilot looks for data of Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework — even when those plugins are deactivated.', 'searchpilot-pro' ) } ),
				} );
			}
			return ui.card( {
				title: __( '1. Choose where to import from', 'searchpilot-pro' ),
				actions: [ ui.button( { label: __( 'Scan again', 'searchpilot-pro' ), icon: 'refresh', size: 'small', onClick: () => load( true ) } ) ],
				body: field.radioCards( {
					value: state.source,
					options: sources.map( ( s ) => ( {
						value: s.id,
						label: s.name,
						description: s.available
							? sprintf( /* translators: 1: posts, 2: terms. */ __( '%1$s posts · %2$s terms', 'searchpilot-pro' ), fmt.number( s.counts.posts ), fmt.number( s.counts.terms ) ) + ( s.settings ? ' · ' + __( 'settings', 'searchpilot-pro' ) : '' )
							: __( 'No data found', 'searchpilot-pro' ),
						extra: h( 'span', { class: 'spp-row', style: { marginTop: '6px' } }, s.active ? ui.badge( __( 'Active', 'searchpilot-pro' ), 'passed' ) : ui.badge( __( 'Inactive', 'searchpilot-pro' ), 'outline' ), s.available ? null : ui.badge( __( 'Nothing to import', 'searchpilot-pro' ), 'outline' ) ),
					} ) ),
					onChange: ( v ) => {
						state.source = v;
						render();
					},
				} ),
			} );
		};

		const options = () => {
			const src = ( state.data.sources || [] ).find( ( s ) => s.id === state.source );
			const running = state.data.progress && state.data.progress.status === 'running';
			const chosen = Object.keys( state.include ).filter( ( k ) => state.include[ k ] );
			return ui.card( {
				title: __( '2. Choose what to import', 'searchpilot-pro' ),
				body: [
					field.toggle( {
						label: __( 'Posts, pages and other content', 'searchpilot-pro' ),
						help: src ? sprintf( /* translators: %s: number of posts. */ __( '%s items with SEO data.', 'searchpilot-pro' ), fmt.number( src.counts.posts ) ) : null,
						row: true,
						checked: state.include.posts,
						onChange: ( v ) => {
							state.include.posts = v;
							render();
						},
					} ),
					field.toggle( {
						label: __( 'Categories, tags and other terms', 'searchpilot-pro' ),
						help: src ? sprintf( /* translators: %s: number of terms. */ __( '%s terms with SEO data.', 'searchpilot-pro' ), fmt.number( src.counts.terms ) ) : null,
						row: true,
						checked: state.include.terms,
						onChange: ( v ) => {
							state.include.terms = v;
							render();
						},
					} ),
					field.toggle( {
						label: __( 'Settings', 'searchpilot-pro' ),
						help: __( 'Title separator and templates, home title and description, organization name, logo and social profiles, breadcrumb labels. Only settings still at their defaults are changed unless you overwrite.', 'searchpilot-pro' ),
						row: true,
						disabled: src && ! src.settings,
						checked: state.include.settings && ! ( src && ! src.settings ),
						onChange: ( v ) => {
							state.include.settings = v;
							render();
						},
					} ),
					field.toggle( {
						label: __( 'Overwrite values already set in SearchPilot', 'searchpilot-pro' ),
						help: __( 'Off (recommended): only empty SearchPilot fields are filled. On: imported values replace existing ones (still undoable).', 'searchpilot-pro' ),
						row: true,
						checked: state.overwrite,
						onChange: ( v ) => {
							state.overwrite = v;
							render();
						},
					} ),
					h(
						'div',
						{ class: 'spp-row', style: { marginTop: '12px' } },
						ui.button( {
							label: __( 'Start import', 'searchpilot-pro' ),
							icon: 'download',
							variant: 'primary',
							disabled: ! src || ! src.available || running || ! chosen.length,
							onClick: () =>
								ui
									.confirm( {
										title: sprintf( /* translators: %s: plugin name. */ __( 'Import from %s?', 'searchpilot-pro' ), src.name ),
										message: state.overwrite
											? __( 'Imported values will replace SearchPilot values that are already set. Every change is recorded and the whole import can be undone.', 'searchpilot-pro' )
											: __( 'Only empty SearchPilot fields will be filled. Every change is recorded and the whole import can be undone.', 'searchpilot-pro' ),
										confirmLabel: __( 'Start import', 'searchpilot-pro' ),
									} )
									.then( ( ok ) => {
										if ( ! ok ) {
											return null;
										}
										return api.post( '/import/start', { source: state.source, overwrite: state.overwrite, include: chosen } ).then( ( data ) => {
											state.data = data;
											ui.toast( __( 'Import started — it continues in the background.', 'searchpilot-pro' ) );
											render();
											schedule();
										} );
									} ),
						} )
					),
				],
			} );
		};

		const progressCard = () => {
			const p = state.data.progress || {};
			const u = state.data.undo || {};
			const parts = [];
			if ( p.status && p.status !== 'idle' ) {
				const status = STATUS[ p.status ] || [ p.status, 'outline' ];
				const totals = p.totals || {};
				const processed = p.processed || {};
				const imported = p.imported || {};
				const phase = p.phase || 'posts';
				const total = phase === 'terms' ? totals.terms : totals.posts;
				const done = phase === 'terms' ? processed.terms : processed.posts;
				parts.push(
					h(
						'div',
						{ class: 'spp-row is-between' },
						h( 'div', { class: 'spp-row' }, h( 'span', { class: 'spp-strong' }, sprintf( /* translators: %s: plugin name. */ __( 'Import from %s', 'searchpilot-pro' ), p.source_name || p.source ) ), ui.badge( status[ 0 ], status[ 1 ] ) ),
						p.status === 'running'
							? ui.button( {
									label: __( 'Cancel', 'searchpilot-pro' ),
									size: 'small',
									variant: 'ghost',
									onClick: () =>
										api.post( '/import/cancel' ).then( ( data ) => {
											state.data = data;
											ui.toast( __( 'Import cancelled. What was already imported stays and can be undone.', 'searchpilot-pro' ) );
											render();
										} ),
							  } )
							: null
					)
				);
				if ( p.status === 'running' ) {
					parts.push(
						ui.progress( {
							value: done || 0,
							max: Math.max( 1, total || 0 ),
							tone: 'gold',
							label: PHASES[ phase ] || phase,
							right: total ? sprintf( /* translators: 1: processed, 2: total. */ __( '%1$s of %2$s', 'searchpilot-pro' ), fmt.number( done || 0 ), fmt.number( total ) ) : '',
						} )
					);
				}
				parts.push(
					ui.dl( [
						[ __( 'Posts updated', 'searchpilot-pro' ), fmt.number( imported.posts || 0 ) ],
						[ __( 'Terms updated', 'searchpilot-pro' ), fmt.number( imported.terms || 0 ) ],
						[ __( 'Fields copied', 'searchpilot-pro' ), fmt.number( imported.fields || 0 ) ],
						[ __( 'Settings imported', 'searchpilot-pro' ), fmt.number( imported.settings || 0 ) ],
						[ __( 'Skipped (nothing new)', 'searchpilot-pro' ), fmt.number( ( ( p.skipped || {} ).posts || 0 ) + ( ( p.skipped || {} ).terms || 0 ) ) ],
						[ __( 'Started', 'searchpilot-pro' ), fmt.datetime( p.started_at ) ],
					] )
				);
				( p.errors || [] ).slice( -3 ).forEach( ( e ) => parts.push( ui.notice( { tone: 'high', text: e } ) ) );
			}
			if ( u.status === 'running' ) {
				parts.push(
					ui.progress( {
						value: ( u.undone || 0 ) + ( u.failed || 0 ),
						max: Math.max( 1, u.total || 0 ),
						tone: 'high',
						label: __( 'Undoing the import…', 'searchpilot-pro' ),
						right: sprintf( /* translators: 1: done, 2: total. */ __( '%1$s of %2$s', 'searchpilot-pro' ), fmt.number( ( u.undone || 0 ) + ( u.failed || 0 ) ), fmt.number( u.total || 0 ) ),
					} )
				);
			} else if ( u.status === 'done' ) {
				parts.push(
					ui.notice( {
						tone: u.failed ? 'medium' : 'passed',
						text: u.failed
							? sprintf( /* translators: 1: undone, 2: kept. */ __( 'Undo finished: %1$s changes reverted, %2$s kept because they were edited after the import.', 'searchpilot-pro' ), fmt.number( u.undone || 0 ), fmt.number( u.failed ) )
							: sprintf( /* translators: %s: number of changes. */ __( 'Undo finished: %s changes reverted.', 'searchpilot-pro' ), fmt.number( u.undone || 0 ) ),
					} )
				);
			}
			if ( ! parts.length ) {
				return null;
			}
			return ui.card( { title: __( 'Progress', 'searchpilot-pro' ), body: h( 'div', { class: 'spp-stack' }, parts ) } );
		};

		const switchover = () => {
			const history = state.data.history || [];
			const last = history.find( ( r ) => r.status === 'done' && ! r.undone );
			if ( ! last ) {
				return null;
			}
			const owners = state.data.owners || {};
			const labels = state.data.labels || {};
			return ui.card( {
				title: __( 'After migrating', 'searchpilot-pro' ),
				body: h(
					'div',
					{ class: 'spp-stack' },
					h(
						'p',
						null,
						sprintf(
							/* translators: %s: plugin name. */
							__( 'Check a few pages in the editor, then deactivate %s. With ownership set to “auto”, SearchPilot takes over titles, descriptions, canonicals, robots, social tags, structured data, breadcrumbs and sitemaps automatically — nothing is duplicated while both are active.', 'searchpilot-pro' ),
							last.source_name
						)
					),
					h(
						'div',
						{ class: 'spp-row', style: { flexWrap: 'wrap' } },
						Object.keys( owners ).map( ( f ) => ui.badge( ( labels[ f ] || f ) + ': ' + ( owners[ f ].name || owners[ f ].owner ), owners[ f ].owner === 'searchpilot' ? 'passed' : 'outline' ) )
					),
					h( 'div', null, ui.button( { label: __( 'Review compatibility settings', 'searchpilot-pro' ), size: 'small', onClick: () => SPP.go( 'settings', { tab: 'ownership' } ) } ) )
				),
			} );
		};

		const historyCard = () => {
			const history = state.data.history || [];
			const running = ( state.data.progress && state.data.progress.status === 'running' ) || ( state.data.undo && state.data.undo.status === 'running' );
			return ui.card( {
				title: __( 'Previous imports', 'searchpilot-pro' ),
				flush: true,
				body: ui.table( {
					dense: true,
					empty: __( 'No imports yet.', 'searchpilot-pro' ),
					columns: [
						{ key: 'finished_at', label: __( 'When', 'searchpilot-pro' ), render: ( r ) => h( 'span', { title: fmt.datetime( r.finished_at ) }, fmt.relative( r.finished_at ) ) },
						{ key: 'source_name', label: __( 'From', 'searchpilot-pro' ) },
						{
							key: 'status',
							label: __( 'Status', 'searchpilot-pro' ),
							render: ( r ) => ( r.undone ? ui.badge( __( 'Undone', 'searchpilot-pro' ), 'outline' ) : ui.badge( ( STATUS[ r.status ] || [ r.status ] )[ 0 ], ( STATUS[ r.status ] || [ '', 'outline' ] )[ 1 ] ) ),
						},
						{
							key: 'imported',
							label: __( 'Imported', 'searchpilot-pro' ),
							render: ( r ) =>
								sprintf(
									/* translators: 1: posts, 2: terms, 3: settings. */
									__( '%1$s posts · %2$s terms · %3$s settings', 'searchpilot-pro' ),
									fmt.number( ( r.imported || {} ).posts || 0 ),
									fmt.number( ( r.imported || {} ).terms || 0 ),
									fmt.number( ( r.imported || {} ).settings || 0 )
								),
						},
						{
							key: 'actions',
							label: '',
							align: 'right',
							render: ( r ) =>
								r.undone || ( ! ( r.imported || {} ).posts && ! ( r.imported || {} ).terms && ! ( r.imported || {} ).settings )
									? ''
									: ui.button( {
											label: __( 'Undo', 'searchpilot-pro' ),
											icon: 'undo',
											size: 'small',
											variant: 'ghost',
											disabled: running,
											onClick: () =>
												ui
													.confirm( {
														title: __( 'Undo this import?', 'searchpilot-pro' ),
														message: __( 'SearchPilot restores the values its fields had before the import. Fields you edited after the import are kept.', 'searchpilot-pro' ),
														confirmLabel: __( 'Undo import', 'searchpilot-pro' ),
														danger: true,
													} )
													.then( ( ok ) => {
														if ( ! ok ) {
															return null;
														}
														return api.post( '/import/undo', { group_id: r.group_id } ).then( ( data ) => {
															state.data = data;
															ui.toast( data.undo && data.undo.status === 'running' ? __( 'Undo started — it continues in the background.', 'searchpilot-pro' ) : __( 'Import undone', 'searchpilot-pro' ) );
															render();
															schedule();
														} );
													} ),
									  } ),
						},
					],
					rows: history,
				} ),
			} );
		};

		const render = () => {
			if ( ! alive() ) {
				return;
			}
			const anyData = ( state.data.sources || [] ).some( ( s ) => s.available );
			SPP.mount( root, intro(), progressCard(), sourceCards(), anyData ? options() : null, switchover(), historyCard() );
		};

		SPP.mount( root, ui.loading() );
		load().catch( ( err ) => SPP.mount( root, ui.errorBox( err ) ) );
	}

	SPP.settingsTabs = SPP.settingsTabs || [];
	SPP.settingsTabs.push( {
		id: 'import',
		label: __( 'Import & migration', 'searchpilot-pro' ),
		render( container ) {
			mountImport( container );
		},
	} );
} )( window.wp, window.SPP );

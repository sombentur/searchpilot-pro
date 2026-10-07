/**
 * Automation view: operating mode, task policies, approvals, activity log with undo.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const MODES = [
		{
			value: 'manual',
			icon: 'hand',
			label: __( 'Manual', 'searchpilot-pro' ),
			description: __( 'Recommendations only. Nothing is changed or submitted until you click. Submissions wait in the queue for one-click release.', 'searchpilot-pro' ),
		},
		{
			value: 'assisted',
			icon: 'shield',
			label: __( 'Assisted', 'searchpilot-pro' ),
			description: __( 'Safe technical and indexing work happens automatically (notifying search engines, sitemap hygiene, redirects for changed URLs). Content-related changes wait for your approval.', 'searchpilot-pro' ),
		},
		{
			value: 'autopilot',
			icon: 'rocket',
			label: __( 'Autopilot', 'searchpilot-pro' ),
			description: __( 'Maximum safe automation inside Google and Bing guidelines: also fills empty meta descriptions and ALT text and inserts internal links within your limits. Editorial text is never rewritten.', 'searchpilot-pro' ),
		},
	];

	const POLICY_LABELS = {
		auto: __( 'Automatic', 'searchpilot-pro' ),
		approval: __( 'Needs approval', 'searchpilot-pro' ),
		off: __( 'Off', 'searchpilot-pro' ),
	};
	const POLICY_TONES = { auto: 'passed', approval: 'gold', off: 'outline' };
	const SOURCE_LABELS = {
		user: __( 'You', 'searchpilot-pro' ),
		manual: __( 'Manual', 'searchpilot-pro' ),
		assisted: __( 'Assisted', 'searchpilot-pro' ),
		autopilot: __( 'Autopilot', 'searchpilot-pro' ),
		system: __( 'System', 'searchpilot-pro' ),
		import: __( 'Import', 'searchpilot-pro' ),
	};

	SPP.registerView( 'automation', {
		mount( el ) {
			let settingsData = null;

			const modeRegion = h( 'div' );
			const tasksRegion = h( 'div' );

			const saveSettings = ( changes ) =>
				api.post( '/settings', { settings: changes } ).then( ( res ) => {
					settingsData = res;
					SPP.boot.mode = res.settings.general.mode;
					renderTasks();
					return res;
				} );

			const renderMode = () => {
				SPP.mount(
					modeRegion,
					ui.card( {
						title: __( 'Operating mode', 'searchpilot-pro' ),
						subtitle: __( 'You can switch at any time. Every automatic change is logged below and can be undone.', 'searchpilot-pro' ),
						accent: true,
						body: ui.field.radioCards( {
							options: MODES,
							value: settingsData.settings.general.mode,
							label: __( 'Operating mode', 'searchpilot-pro' ),
							onChange: ( mode ) => {
								const ask =
									'autopilot' === mode
										? ui.confirm( {
												title: __( 'Switch to Autopilot?', 'searchpilot-pro' ),
												message: __( 'SearchPilot will also fill empty meta descriptions and image ALT text and — if automatic insertion is on — add internal links, on the live site, without asking first. Every change is logged and can be undone. Editorial text is never rewritten.', 'searchpilot-pro' ),
												confirmLabel: __( 'Switch to Autopilot', 'searchpilot-pro' ),
										  } )
										: Promise.resolve( true );
								return ask.then( ( ok ) => {
									if ( ! ok ) {
										return false;
									}
									return saveSettings( { 'general.mode': mode } ).then( () => {
										ui.toast( sprintf( /* translators: %s: mode name. */ __( '%s mode is on', 'searchpilot-pro' ), SPP.MODE_LABELS[ mode ] ) );
										SPP.refreshTopbar && SPP.refreshTopbar();
										approvals.refresh();
										return true;
									} );
								} );
							},
						} ),
					} )
				);
			};

			const renderTasks = () => {
				const tasks = settingsData.tasks || {};
				const overrides = ( settingsData.settings.automation && settingsData.settings.automation.tasks ) || {};
				const rows = Object.keys( tasks ).map( ( id ) => Object.assign( { id }, tasks[ id ], { policy: settingsData.policy[ id ], override: ( overrides[ id ] && overrides[ id ].policy ) || 'default' } ) );
				SPP.mount(
					tasksRegion,
					ui.card( {
						title: __( 'What SearchPilot may do', 'searchpilot-pro' ),
						subtitle: __( 'The mode sets sensible defaults; override single tasks if you prefer.', 'searchpilot-pro' ),
						flush: true,
						body: ui.table( {
							columns: [
								{ key: 'label', label: __( 'Task', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'div', { class: 'spp-strong' }, r.label ), h( 'div', { class: 'spp-muted spp-small', style: { maxWidth: '560px', whiteSpace: 'normal' } }, r.description ) ) },
								{ key: 'policy', label: __( 'Currently', 'searchpilot-pro' ), render: ( r ) => ui.badge( POLICY_LABELS[ r.policy ] || r.policy, POLICY_TONES[ r.policy ] ) },
								{
									key: 'override',
									label: __( 'Override', 'searchpilot-pro' ),
									render: ( r ) =>
										r.locked
											? h( 'span', { class: 'spp-muted spp-small' }, __( 'Always asks first', 'searchpilot-pro' ) )
											: ui.select( {
													label: sprintf( /* translators: %s: task name. */ __( 'Override for %s', 'searchpilot-pro' ), r.label ),
													value: r.override,
													options: [
														{ value: 'default', label: __( 'Follow mode', 'searchpilot-pro' ) },
														{ value: 'auto', label: POLICY_LABELS.auto },
														{ value: 'approval', label: POLICY_LABELS.approval },
														{ value: 'off', label: POLICY_LABELS.off },
													],
													onChange: ( v ) => {
														const map = Object.assign( {}, overrides, { [ r.id ]: { policy: v } } );
														saveSettings( { 'automation.tasks': map } ).then( () => ui.toast( __( 'Saved', 'searchpilot-pro' ) ) );
													},
											  } ),
								},
							],
							rows,
						} ),
						footer: settingsData.settings.links && ! settingsData.settings.links.auto_insert ? __( 'Automatic internal links also require “Automatic insertion” under Internal Links → Settings.', 'searchpilot-pro' ) : null,
					} )
				);
			};

			/* ---------- approvals & held submissions ---------- */

			const approvals = SPP.region( () =>
				Promise.all( [
					api.get( '/issues', { status: 'pending_approval', per_page: 20 } ),
					api.get( '/queue/stats' ).catch( () => null ),
				] ).then( ( [ issues, queue ] ) => {
					const parts = [];
					if ( queue && queue.held ) {
						parts.push(
							ui.notice( {
								tone: 'gold',
								title: sprintf( /* translators: %d: number of held submissions. */ __( '%d search-engine submission(s) are waiting', 'searchpilot-pro' ), queue.held ),
								text: SPP.boot.submissions
									? __( 'They were held because of the current mode or task settings. Release them to send them now.', 'searchpilot-pro' )
									: SPP.boot.heldReason || __( 'Submissions are held on purpose on this site and cannot be released here.', 'searchpilot-pro' ),
								actions: SPP.boot.submissions
									? [
											ui.button( {
												label: __( 'Release all', 'searchpilot-pro' ),
												variant: 'primary',
												size: 'small',
												onClick: () =>
													api.post( '/queue/release' ).then( () => {
														ui.toast( __( 'Submissions released', 'searchpilot-pro' ) );
														approvals.refresh();
													} ),
											} ),
											ui.button( { label: __( 'View queue', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'engines', { tab: 'queue' } ) } ),
									  ]
									: [ ui.button( { label: __( 'View queue', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'engines', { tab: 'queue' } ) } ) ],
							} )
						);
					}
					const items = issues.items || [];
					parts.push(
						ui.card( {
							title: __( 'Waiting for your approval', 'searchpilot-pro' ),
							subtitle: __( 'Changes SearchPilot prepared but will not make without you.', 'searchpilot-pro' ),
							body: items.length
								? h( 'div', null, items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: () => approvals.refresh() } ) ) )
								: ui.empty( { icon: 'check', title: __( 'Nothing to approve', 'searchpilot-pro' ), text: __( 'When a fix needs your OK it appears here.', 'searchpilot-pro' ) } ),
							footer: issues.total > items.length ? h( 'a', { href: SPP.pageUrl( 'audit' ) }, sprintf( /* translators: %d: count. */ __( 'See all %d in SEO Audit', 'searchpilot-pro' ), issues.total ) ) : null,
						} )
					);
					return ui.stack( ...parts );
				} )
			);

			/* ---------- activity log ---------- */

			const filters = { source: SPP.query.get( 'source' ), reversible: SPP.query.get( 'reversible' ) };
			const undo = ( row, force ) =>
				api
					.post( '/activity/' + row.id + '/undo', force ? { force: true } : {} )
					.then( () => {
						ui.toast( __( 'Change undone', 'searchpilot-pro' ) );
						activity.reload();
					} )
					.catch( ( err ) => {
						if ( err.code === 'searchpilot_changed_since' ) {
							return ui
								.confirm( {
									title: __( 'This was edited again since', 'searchpilot-pro' ),
									message: err.message + ' ' + __( 'Undo anyway and overwrite the newer value?', 'searchpilot-pro' ),
									confirmLabel: __( 'Undo anyway', 'searchpilot-pro' ),
									danger: true,
								} )
								.then( ( ok ) => ( ok ? undo( row, true ) : null ) );
						}
						throw err;
					} );

			const activity = ui.dataTable( {
				path: '/activity',
				query: filters,
				perPage: 25,
				empty: __( 'No changes recorded yet.', 'searchpilot-pro' ),
				columns: [
					{ key: 'created_at', label: __( 'When', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => h( 'span', { title: fmt.datetime( r.created_at ) }, fmt.relative( r.created_at ) ) },
					{ key: 'source', label: __( 'By', 'searchpilot-pro' ), render: ( r ) => ui.badge( r.user && r.source === 'user' ? r.user : SOURCE_LABELS[ r.source ] || r.source, r.source === 'autopilot' ? 'passed' : r.source === 'assisted' ? 'gold' : r.source === 'user' ? 'navy' : 'outline' ) },
					{
						key: 'description',
						label: __( 'Change', 'searchpilot-pro' ),
						render: ( r ) => h( 'div', null, h( 'div', { class: 'spp-break' }, r.description ), r.object_link ? h( 'a', { href: r.object_link, class: 'spp-small' }, __( 'Open', 'searchpilot-pro' ) ) : null ),
					},
					{
						key: 'status',
						label: __( 'Status', 'searchpilot-pro' ),
						render: ( r ) => ( r.status === 'reverted' ? ui.badge( __( 'Undone', 'searchpilot-pro' ), 'outline' ) : ui.badge( __( 'Applied', 'searchpilot-pro' ), 'passed' ) ),
					},
					{
						key: 'actions',
						label: '',
						align: 'right',
						render: ( r ) =>
							h(
								'div',
								{ class: 'spp-row', style: { justifyContent: 'flex-end' } },
								r.reversible ? ui.button( { label: __( 'Undo', 'searchpilot-pro' ), icon: 'undo', size: 'small', onClick: () => undo( r ) } ) : null,
								r.group_id && r.reversible && SPP.boot.caps.manage
									? ui.button( {
											label: __( 'Undo whole batch', 'searchpilot-pro' ),
											size: 'small',
											variant: 'ghost',
											onClick: () =>
												ui.confirm( { title: __( 'Undo the whole batch?', 'searchpilot-pro' ), message: __( 'Every change made in this batch (for example an import) is reverted.', 'searchpilot-pro' ), confirmLabel: __( 'Undo batch', 'searchpilot-pro' ), danger: true } ).then( ( ok ) =>
													ok
														? api.post( '/activity/group/' + r.group_id + '/undo' ).then( ( res ) => {
																ui.toast( sprintf( /* translators: 1: undone, 2: failed. */ __( '%1$d undone, %2$d could not be undone', 'searchpilot-pro' ), res.undone, res.failed ) );
																activity.reload();
														  } )
														: null
												),
									  } )
									: null
							),
					},
				],
			} );

			const activityCard = ui.card( {
				title: __( 'Activity log', 'searchpilot-pro' ),
				subtitle: __( 'Every change SearchPilot made to your site, and every settings change. Undo restores the previous value unless it was edited again since.', 'searchpilot-pro' ),
				flush: true,
				actions: [
					ui.select( {
						label: __( 'Source', 'searchpilot-pro' ),
						value: filters.source,
						options: [ { value: '', label: __( 'All sources', 'searchpilot-pro' ) } ].concat( Object.keys( SOURCE_LABELS ).map( ( k ) => ( { value: k, label: SOURCE_LABELS[ k ] } ) ) ),
						onChange: ( v ) => {
							SPP.query.set( { source: v } );
							activity.setQuery( { source: v } );
						},
					} ),
					ui.select( {
						label: __( 'Undoable', 'searchpilot-pro' ),
						value: filters.reversible,
						options: [
							{ value: '', label: __( 'All changes', 'searchpilot-pro' ) },
							{ value: '1', label: __( 'Undoable only', 'searchpilot-pro' ) },
						],
						onChange: ( v ) => {
							SPP.query.set( { reversible: v } );
							activity.setQuery( { reversible: v } );
						},
					} ),
				],
				body: activity.el,
			} );

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Automation', 'searchpilot-pro' ),
					subtitle: __( 'Choose how autonomous SearchPilot is. It works only through official search-engine APIs and never rewrites editorial content without your approval.', 'searchpilot-pro' ),
				} ),
				ui.stack( modeRegion, approvals.el, tasksRegion, activityCard )
			);
			SPP.mount( modeRegion, ui.loading() );

			api.get( '/settings' )
				.then( ( data ) => {
					settingsData = data;
					renderMode();
					renderTasks();
				} )
				.catch( ( err ) => SPP.mount( modeRegion, ui.errorBox( err ) ) );
		},
	} );
} )( window.wp, window.SPP );

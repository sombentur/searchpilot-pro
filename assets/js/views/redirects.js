/**
 * Redirects view.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;
	const field = ui.field;

	const CODES = [
		{ value: 301, label: __( '301 — Moved permanently', 'searchpilot-pro' ), description: __( 'The page moved for good. Search engines transfer the old address’s value to the new one. Use this in almost every case.', 'searchpilot-pro' ) },
		{ value: 302, label: __( '302 — Found (temporary)', 'searchpilot-pro' ), description: __( 'A short-term move, e.g. during maintenance. Search engines keep the old address indexed.', 'searchpilot-pro' ) },
		{ value: 307, label: __( '307 — Temporary redirect', 'searchpilot-pro' ), description: __( 'Like 302, but browsers keep the request method.', 'searchpilot-pro' ) },
		{ value: 308, label: __( '308 — Permanent redirect', 'searchpilot-pro' ), description: __( 'Like 301, but browsers keep the request method.', 'searchpilot-pro' ) },
		{ value: 410, label: __( '410 — Gone', 'searchpilot-pro' ), description: __( 'The content was removed on purpose and will not come back. Search engines drop it faster than a 404.', 'searchpilot-pro' ) },
		{ value: 451, label: __( '451 — Unavailable for legal reasons', 'searchpilot-pro' ), description: __( 'Removed because of a legal demand (court order, takedown).', 'searchpilot-pro' ) },
	];

	const MATCH = {
		exact: { label: __( 'Exact', 'searchpilot-pro' ), description: __( 'Only this address (trailing slash and letter case ignored). Query strings are passed on to the target.', 'searchpilot-pro' ) },
		prefix: { label: __( 'Starts with', 'searchpilot-pro' ), description: __( 'Every address beginning with the source, when it would otherwise be a 404. Put * in the target to keep the rest of the path: /old/* → /new/*.', 'searchpilot-pro' ) },
		regex: { label: __( 'Regular expression', 'searchpilot-pro' ), description: __( 'A PCRE pattern tested against the path of 404 requests, e.g. ^/(\\d{4})/(.*)$ → /$2. For advanced users.', 'searchpilot-pro' ) },
	};

	const ORIGINS = {
		manual: __( 'Manual', 'searchpilot-pro' ),
		slug_change: __( 'Changed URL', 'searchpilot-pro' ),
		import: __( 'Imported', 'searchpilot-pro' ),
		404: __( '404 Monitor', 'searchpilot-pro' ),
		fix: __( 'Automatic fix', 'searchpilot-pro' ),
		deleted: __( 'Removed content', 'searchpilot-pro' ),
	};

	/** Compact switch for table cells (label for screen readers only). */
	function miniToggle( checked, label, onChange ) {
		const input = h( 'input', { type: 'checkbox', checked: !! checked, role: 'switch' } );
		input.addEventListener( 'change', () => onChange( input.checked ) );
		return h( 'label', { class: 'spp-toggle' }, input, h( 'span', { class: 'spp-toggle-track', 'aria-hidden': 'true' } ), h( 'span', { class: 'screen-reader-text' }, label ) );
	}

	const codeTone = ( code ) => ( code >= 400 ? 'critical' : 301 === code || 308 === code ? 'passed' : 'medium' );

	/** Add/edit modal. Resolves with the saved rule. */
	function editModal( rule, onSaved ) {
		const data = Object.assign( { source: '', target: '', match_type: 'exact', status_code: 301, enabled: true, notes: '' }, rule || {} );
		if ( rule ) {
			data.source = rule.match_type === 'regex' ? rule.source : fmt.url( rule.source );
			data.target = fmt.url( rule.target );
		}
		const error = h( 'div' );
		const matchHelp = h( 'p', { class: 'spp-field-help' }, MATCH[ data.match_type ].description );
		const codeHelp = h( 'p', { class: 'spp-field-help' }, ( CODES.find( ( c ) => c.value === Number( data.status_code ) ) || CODES[ 0 ] ).description );
		const targetWrap = h( 'div' );
		const renderTarget = () => {
			const gone = Number( data.status_code ) >= 400;
			SPP.mount(
				targetWrap,
				gone
					? ui.notice( { tone: 'info', text: __( 'Visitors see your theme’s “not found” page with this status. No target is needed.', 'searchpilot-pro' ) } )
					: field.text( {
							label: __( 'Target (new address)', 'searchpilot-pro' ),
							value: data.target,
							placeholder: '/new-page/',
							help: __( 'A path on this site or a full https:// address. External targets are only allowed for administrators.', 'searchpilot-pro' ),
							onInput: ( v ) => ( data.target = v ),
					  } )
			);
		};
		renderTarget();
		const body = h(
			'div',
			null,
			error,
			field.text( {
				label: __( 'Source (old address)', 'searchpilot-pro' ),
				value: data.source,
				placeholder: data.match_type === 'regex' ? '^/old-section/(.*)$' : '/old-page/',
				help: __( 'A path on this site. Paste a full URL and it is shortened automatically. Non-Latin (e.g. Kannada) addresses work as typed.', 'searchpilot-pro' ),
				onInput: ( v ) => ( data.source = v ),
			} ),
			h(
				'div',
				{ class: 'spp-field' },
				h( 'label', { class: 'spp-field-label' }, __( 'Match', 'searchpilot-pro' ) ),
				ui.buttonGroup( {
					options: Object.keys( MATCH ).map( ( k ) => ( { value: k, label: MATCH[ k ].label } ) ),
					value: data.match_type,
					onChange: ( v ) => {
						data.match_type = v;
						matchHelp.textContent = MATCH[ v ].description;
					},
				} ),
				matchHelp
			),
			h(
				'div',
				{ class: 'spp-field' },
				h( 'label', { class: 'spp-field-label' }, __( 'Type', 'searchpilot-pro' ) ),
				ui.select( {
					label: __( 'Redirect type', 'searchpilot-pro' ),
					inline: false,
					value: data.status_code,
					options: CODES.map( ( c ) => ( { value: c.value, label: c.label } ) ),
					onChange: ( v ) => {
						data.status_code = Number( v );
						codeHelp.textContent = ( CODES.find( ( c ) => c.value === data.status_code ) || CODES[ 0 ] ).description;
						renderTarget();
					},
				} ),
				codeHelp
			),
			targetWrap,
			field.text( { label: __( 'Notes (optional)', 'searchpilot-pro' ), value: data.notes, maxLength: 250, onInput: ( v ) => ( data.notes = v ) } ),
			field.toggle( { label: __( 'Enabled', 'searchpilot-pro' ), checked: data.enabled, onChange: ( v ) => ( data.enabled = v ) } )
		);
		const modal = ui.modal( {
			title: rule ? __( 'Edit redirect', 'searchpilot-pro' ) : __( 'Add redirect', 'searchpilot-pro' ),
			body,
			actions: ( close ) => [
				ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
				ui.button( {
					label: rule ? __( 'Save changes', 'searchpilot-pro' ) : __( 'Add redirect', 'searchpilot-pro' ),
					variant: 'primary',
					onClick: () => {
						const payload = { source: data.source, target: data.target, match_type: data.match_type, status_code: Number( data.status_code ), enabled: !! data.enabled, notes: data.notes };
						const req = rule ? api.put( '/redirects/' + rule.id, payload ) : api.post( '/redirects', payload );
						return req.then(
							( saved ) => {
								ui.toast( rule ? __( 'Redirect saved', 'searchpilot-pro' ) : __( 'Redirect added', 'searchpilot-pro' ) );
								close();
								onSaved && onSaved( saved );
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
		return modal;
	}

	function testerCard() {
		const result = h( 'div', { style: { marginTop: '12px' } } );
		let url = '';
		const run = () =>
			api.post( '/redirects/test', { url } ).then( ( r ) => {
				if ( r.excluded ) {
					SPP.mount( result, ui.notice( { tone: 'info', text: __( 'This address is reserved (admin, login, REST API, sitemaps, robots.txt) and is never redirected.', 'searchpilot-pro' ) } ) );
					return;
				}
				if ( ! r.matched ) {
					SPP.mount( result, ui.notice( { tone: 'info', title: __( 'No redirect applies', 'searchpilot-pro' ), text: r.note || __( 'Visitors see whatever WordPress has at this address.', 'searchpilot-pro' ) } ) );
					return;
				}
				const steps = [ h( 'li', null, h( 'code', null, fmt.url( r.path ) ) ) ].concat(
					r.chain.map( ( step ) => h( 'li', null, ui.badge( String( step.status_code ), codeTone( step.status_code ) ), ' ', step.target_url ? h( 'a', { href: step.target_url, target: '_blank', rel: 'noopener noreferrer' }, fmt.url( step.target_url ) ) : __( 'Gone — shows the “not found” page', 'searchpilot-pro' ) ) )
				);
				SPP.mount(
					result,
					ui.notice( {
						tone: r.loop ? 'critical' : r.chain.length > 1 ? 'high' : 'passed',
						title: r.loop ? __( 'Redirect loop', 'searchpilot-pro' ) : r.chain.length > 1 ? sprintf( /* translators: %d: hops. */ __( 'Redirect chain with %d hops', 'searchpilot-pro' ), r.chain.length ) : __( 'Redirects in one step', 'searchpilot-pro' ),
						text: [ h( 'ol', { class: 'spp-list' }, steps ), r.active ? null : h( 'p', { class: 'spp-muted' }, __( 'SearchPilot is not applying redirects on this site (another plugin manages them).', 'searchpilot-pro' ) ) ],
						actions: r.rule ? [ ui.button( { label: __( 'Edit first rule', 'searchpilot-pro' ), size: 'small', onClick: () => editModal( r.rule, run ) } ) ] : null,
					} )
				);
			} );
		return ui.card( {
			title: __( 'Test an address', 'searchpilot-pro' ),
			subtitle: __( 'See which rule applies and where visitors end up.', 'searchpilot-pro' ),
			body: [ field.text( { label: __( 'Address', 'searchpilot-pro' ), placeholder: '/old-page/', onInput: ( v ) => ( url = v ) } ), ui.button( { label: __( 'Test', 'searchpilot-pro' ), icon: 'search', variant: 'primary', onClick: () => ( url ? run() : ui.toast( __( 'Enter an address to test.', 'searchpilot-pro' ), 'warning' ) ) } ), result ],
		} );
	}

	function importCard( onDone ) {
		const sources = SPP.region( () =>
			api.get( '/redirects/import/sources' ).then( ( res ) => {
				return h(
					'div',
					{ class: 'spp-status-grid' },
					( res.sources || [] ).map( ( s ) =>
						h(
							'div',
							{ class: 'spp-status-item' },
							ui.dot( s.available ? 'passed' : 'outline' ),
							h(
								'div',
								{ class: 'spp-grow' },
								h( 'div', { class: 'spp-status-title' }, s.name ),
								h( 'div', { class: 'spp-status-text' }, s.available ? sprintf( /* translators: %s: number. */ _n( '%s redirect found', '%s redirects found', s.count, 'searchpilot-pro' ), fmt.number( s.count ) ) : __( 'No data found', 'searchpilot-pro' ) ),
								s.available
									? ui.button( {
											label: __( 'Import', 'searchpilot-pro' ),
											size: 'small',
											onClick: () =>
												ui
													.confirm( {
														title: sprintf( /* translators: %s: plugin. */ __( 'Import from %s?', 'searchpilot-pro' ), s.name ),
														message: __( 'Existing SearchPilot rules with the same source are kept; duplicates are skipped. The import can be undone in one click.', 'searchpilot-pro' ),
														confirmLabel: __( 'Import', 'searchpilot-pro' ),
													} )
													.then( ( ok ) => ( ok ? api.post( '/redirects/import/' + s.id ).then( ( r ) => report( r ) ) : null ) ),
									  } )
									: null
							)
						)
					)
				);
			} )
		);
		const report = ( r ) => {
			ui.toast(
				sprintf(
					/* translators: 1: imported, 2: duplicates, 3: invalid. */
					__( 'Imported %1$s, skipped %2$s duplicates and %3$s invalid rows.', 'searchpilot-pro' ),
					fmt.number( r.imported ),
					fmt.number( r.duplicates ),
					fmt.number( r.invalid )
				),
				r.invalid ? 'warning' : 'success',
				7000
			);
			if ( r.errors && r.errors.length ) {
				ui.modal( { title: __( 'Rows that were not imported', 'searchpilot-pro' ), body: h( 'ul', { class: 'spp-list' }, r.errors.map( ( e ) => h( 'li', { class: 'spp-break' }, e ) ) ) } );
			}
			sources.refresh();
			onDone();
		};
		const file = h( 'input', { type: 'file', accept: '.csv,text/csv', class: 'spp-input' } );
		const importCsv = () => {
			const f = file.files && file.files[ 0 ];
			if ( ! f ) {
				ui.toast( __( 'Choose a CSV file first.', 'searchpilot-pro' ), 'warning' );
				return null;
			}
			if ( f.size > 5 * 1024 * 1024 ) {
				ui.toast( __( 'The file is larger than 5 MB. Split it into smaller files.', 'searchpilot-pro' ), 'error' );
				return null;
			}
			return f.text().then( ( csv ) => api.post( '/redirects/import', { csv } ) ).then( ( r ) => {
				file.value = '';
				report( r );
			} );
		};
		return ui.card( {
			title: __( 'Import & export', 'searchpilot-pro' ),
			subtitle: __( 'CSV columns: source,target,code,match_type. Exports are safe to open in spreadsheets.', 'searchpilot-pro' ),
			actions: [
				ui.button( {
					label: __( 'Export CSV', 'searchpilot-pro' ),
					icon: 'download',
					size: 'small',
					onClick: () =>
						api.get( '/redirects/export' ).then( ( r ) => {
							SPP.util.download( r.filename, r.csv, 'text/csv;charset=utf-8' );
							ui.toast( sprintf( /* translators: %s: number. */ _n( 'Exported %s redirect', 'Exported %s redirects', r.count, 'searchpilot-pro' ), fmt.number( r.count ) ) );
						} ),
				} ),
			],
			body: [
				h( 'div', { class: 'spp-row' }, file, ui.button( { label: __( 'Import CSV', 'searchpilot-pro' ), icon: 'plus', onClick: importCsv } ) ),
				h( 'h4', { style: { margin: '18px 0 8px' } }, __( 'Import from another plugin', 'searchpilot-pro' ) ),
				sources.el,
			],
		} );
	}

	/** Automatic redirect settings. */
	function automationCard( data, onSaved ) {
		let value = !! data.auto_slug;
		const saveBtn = ui.button( {
			label: __( 'Save', 'searchpilot-pro' ),
			variant: 'primary',
			size: 'small',
			disabled: true,
			onClick: () =>
				api.post( '/settings', { settings: { redirects: { auto_slug: value } } } ).then( () => {
					ui.toast( __( 'Settings saved', 'searchpilot-pro' ) );
					onSaved();
				} ),
		} );
		return ui.card( {
			title: __( 'Automatic redirects', 'searchpilot-pro' ),
			subtitle: __( 'When the address of a published post, page or category changes, SearchPilot adds a 301 from the old address (or asks first, depending on the automation mode). Chains are shortened and loops removed.', 'searchpilot-pro' ),
			body: [
				field.toggle( {
					label: __( 'Redirect changed addresses', 'searchpilot-pro' ),
					row: true,
					checked: value,
					help: __( 'Approval rules for this task are set on the Automation screen (“Redirect changed URLs”).', 'searchpilot-pro' ),
					onChange: ( v ) => {
						value = v;
						saveBtn.disabled = false;
					},
				} ),
			],
			footer: h( 'div', { class: 'spp-row is-between' }, ui.link( __( 'Open Automation', 'searchpilot-pro' ), SPP.pageUrl( 'automation' ) ), saveBtn ),
		} );
	}

	SPP.registerView( 'redirects', {
		mount( el ) {
			const query = {
				search: SPP.query.get( 'search' ),
				match_type: SPP.query.get( 'match_type' ),
				origin: SPP.query.get( 'origin' ),
				enabled: SPP.query.get( 'enabled' ),
				orderby: SPP.query.get( 'orderby', 'updated' ),
				order: SPP.query.get( 'order', 'desc' ),
			};
			const kpis = h( 'div' );
			const notice = h( 'div' );
			const automation = h( 'div' );
			const bulkBar = h( 'div', { class: 'spp-row', style: { display: 'none', padding: '0 20px 12px' } } );
			let table = null;

			const issues = SPP.region( () =>
				api.get( '/issues', { type: 'redirect_loop,redirect_chain,redirect_to_error,slug_redirect_needed,deleted_url_redirect,redirect_shadows_content', per_page: 20 } ).then( ( res ) =>
					( res.items || [] ).length
						? ui.card( {
								title: __( 'Redirect problems', 'searchpilot-pro' ),
								body: ui.stack( res.items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: () => reloadAll() } ) ) ),
						  } )
						: h( 'span' )
				)
			);

			const renderMeta = ( data ) => {
				const stats = data.stats || {};
				const problems = data.problems || {};
				SPP.mount(
					kpis,
					ui.grid(
						4,
						ui.kpi( { label: __( 'Active redirects', 'searchpilot-pro' ), value: fmt.number( stats.active ), icon: 'link', hint: sprintf( /* translators: %s: total. */ __( '%s in total', 'searchpilot-pro' ), fmt.number( stats.total ) ) } ),
						ui.kpi( { label: __( 'Hits (all time)', 'searchpilot-pro' ), value: fmt.compact( stats.hits_total ), icon: 'chart', hint: sprintf( /* translators: %s: number of rules. */ __( '%s rules used in the last 30 days', 'searchpilot-pro' ), fmt.number( stats.used_30d ) ) } ),
						ui.kpi( { label: __( 'Created automatically', 'searchpilot-pro' ), value: fmt.number( stats.auto ), icon: 'bolt', hint: data.auto_slug ? __( 'Changed URLs redirect automatically', 'searchpilot-pro' ) : __( 'Automatic slug redirects are off', 'searchpilot-pro' ) } ),
						ui.kpi( { label: __( 'Loops & chains', 'searchpilot-pro' ), value: fmt.number( ( problems.redirect_loop || 0 ) + ( problems.redirect_chain || 0 ) ), icon: 'alert', tone: problems.redirect_loop ? 'critical' : problems.redirect_chain ? 'high' : 'passed', hint: problems.redirect_to_error ? sprintf( /* translators: %d: number. */ _n( '%d points to an error page', '%d point to an error page', problems.redirect_to_error, 'searchpilot-pro' ), problems.redirect_to_error ) : null } )
					)
				);
				if ( ! automation.firstChild ) {
					SPP.mount( automation, automationCard( data, () => table.reload() ) );
				}
				SPP.mount(
					notice,
					data.active
						? null
						: ui.notice( {
								tone: 'high',
								title: data.owner_name ? sprintf( /* translators: %s: plugin. */ __( '%s manages redirects on this site', 'searchpilot-pro' ), data.owner_name ) : __( 'Redirects are switched off', 'searchpilot-pro' ),
								text: __( 'The rules below are stored but not applied. Hand redirects to SearchPilot in Settings → SEO plugin compatibility after importing the other plugin’s rules.', 'searchpilot-pro' ),
						  } )
				);
			};

			/** The bar always reflects the table's current selection (never a stale copy). */
			const renderBulk = ( ids ) => {
				const selected = ids || ( table ? table.selected() : [] );
				bulkBar.style.display = selected.length ? '' : 'none';
				SPP.mount(
					bulkBar,
					h( 'span', { class: 'spp-muted' }, sprintf( /* translators: %d: selected rows. */ _n( '%d selected', '%d selected', selected.length, 'searchpilot-pro' ), selected.length ) ),
					[ 'enable', 'disable', 'delete' ].map( ( action ) =>
						ui.button( {
							label: { enable: __( 'Enable', 'searchpilot-pro' ), disable: __( 'Disable', 'searchpilot-pro' ), delete: __( 'Delete', 'searchpilot-pro' ) }[ action ],
							size: 'small',
							variant: 'delete' === action ? 'danger' : null,
							onClick: () => {
								const ids = table ? table.selected() : [];
								if ( ! ids.length ) {
									renderBulk( [] );
									return null;
								}
								const run = () =>
									api.post( '/redirects/bulk', { ids, action } ).then( ( r ) => {
										ui.toast( sprintf( /* translators: %d: rules. */ _n( '%d redirect updated. You can undo it from Automation → Activity.', '%d redirects updated. You can undo it from Automation → Activity.', r.done, 'searchpilot-pro' ), r.done ) );
										renderBulk( [] );
										reloadAll();
									} );
								if ( 'delete' !== action ) {
									return run();
								}
								return ui.confirm( { title: __( 'Delete the selected redirects?', 'searchpilot-pro' ), message: __( 'Visitors to the old addresses will see “not found”. The deletion can be undone from the activity log.', 'searchpilot-pro' ), confirmLabel: __( 'Delete', 'searchpilot-pro' ), danger: true } ).then( ( ok ) => ( ok ? run() : null ) );
							},
						} )
					)
				);
			};

			table = ui.dataTable( {
				path: '/redirects',
				query,
				perPage: 25,
				selectable: true,
				// Called on every selection change and whenever reload()/setQuery() clears it.
				onSelect: ( ids ) => renderBulk( ids ),
				onLoaded: renderMeta,
				empty: __( 'No redirects yet. Changed post addresses are redirected automatically; add your own with “Add redirect”.', 'searchpilot-pro' ),
				rowClass: ( r ) => ( r.enabled ? null : 'spp-muted' ),
				columns: [
					{
						key: 'source',
						label: __( 'Source', 'searchpilot-pro' ),
						render: ( r ) =>
							h(
								'div',
								null,
								r.source_url ? h( 'a', { class: 'spp-cell-title spp-break', href: r.source_url, target: '_blank', rel: 'noopener noreferrer' }, r.source_display ) : h( 'code', { class: 'spp-break' }, r.source_display ),
								h( 'div', { class: 'spp-cell-sub' }, ui.badge( MATCH[ r.match_type ] ? MATCH[ r.match_type ].label : r.match_type, 'outline' ), r.notes ? h( 'span', { class: 'spp-muted spp-small' }, ' ', r.notes ) : null )
							),
					},
					{
						key: 'target',
						label: __( 'Target', 'searchpilot-pro' ),
						render: ( r ) =>
							r.status_code >= 400
								? h( 'span', { class: 'spp-muted' }, __( 'Gone (no target)', 'searchpilot-pro' ) )
								: h( 'div', null, h( 'a', { class: 'spp-break', href: r.target_url, target: '_blank', rel: 'noopener noreferrer' }, r.target_display ), r.is_external ? h( 'div', { class: 'spp-cell-sub' }, ui.badge( __( 'External', 'searchpilot-pro' ), 'info' ) ) : null ),
					},
					{ key: 'status_code', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => ui.badge( String( r.status_code ), codeTone( r.status_code ) ) },
					{ key: 'hits', label: __( 'Hits', 'searchpilot-pro' ), align: 'num', sortable: true, render: ( r ) => fmt.number( r.hits ) },
					{ key: 'last_hit', label: __( 'Last used', 'searchpilot-pro' ), className: 'is-nowrap', sortable: true, render: ( r ) => ( r.last_hit ? h( 'span', { title: fmt.datetime( r.last_hit ) }, fmt.relative( r.last_hit ) ) : h( 'span', { class: 'spp-muted' }, __( 'never', 'searchpilot-pro' ) ) ) },
					{
						key: 'origin',
						label: __( 'Origin', 'searchpilot-pro' ),
						render: ( r ) => h( 'div', null, ui.badge( ORIGINS[ r.origin ] || r.origin, 'slug_change' === r.origin ? 'gold' : 'outline' ), r.object_title ? h( 'div', { class: 'spp-cell-sub' }, r.object_edit ? h( 'a', { href: r.object_edit }, r.object_title ) : r.object_title ) : null ),
					},
					{
						key: 'enabled',
						label: __( 'On', 'searchpilot-pro' ),
						align: 'center',
						render: ( r ) =>
							miniToggle( r.enabled, __( 'Enabled', 'searchpilot-pro' ), ( v ) =>
								api.post( '/redirects/bulk', { ids: [ r.id ], action: v ? 'enable' : 'disable' } ).then(
									() => {
										ui.toast( v ? __( 'Redirect enabled', 'searchpilot-pro' ) : __( 'Redirect disabled', 'searchpilot-pro' ) );
										reloadAll();
									},
									( err ) => ui.toast( err.message, 'error' )
								)
							),
					},
					{
						key: 'actions',
						label: '',
						align: 'right',
						render: ( r ) =>
							ui.row(
								ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => editModal( r, reloadAll ) } ),
								ui.button( {
									icon: 'trash',
									size: 'small',
									variant: 'ghost',
									title: __( 'Delete', 'searchpilot-pro' ),
									onClick: () =>
										ui.confirm( { title: __( 'Delete this redirect?', 'searchpilot-pro' ), message: sprintf( /* translators: %s: source. */ __( 'Visitors to %s will no longer be redirected. You can undo this from the activity log.', 'searchpilot-pro' ), r.source_display ), confirmLabel: __( 'Delete', 'searchpilot-pro' ), danger: true } ).then( ( ok ) =>
											ok
												? api.del( '/redirects/' + r.id ).then( () => {
														ui.toast( __( 'Redirect deleted', 'searchpilot-pro' ) );
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
				issues.refresh();
			}

			const setFilter = ( key, v ) => {
				const patch = {};
				patch[ key ] = v;
				SPP.query.set( patch );
				table.setQuery( patch );
			};

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Redirects', 'searchpilot-pro' ),
					subtitle: __( 'Send visitors and search engines from old addresses to the right page. Every change is logged and can be undone.', 'searchpilot-pro' ),
					actions: [ ui.button( { label: __( 'Add redirect', 'searchpilot-pro' ), icon: 'plus', variant: 'primary', onClick: () => editModal( null, reloadAll ) } ) ],
				} ),
				ui.stack(
					notice,
					kpis,
					issues.el,
					ui.card( {
						flush: true,
						body: [
							h(
								'div',
								{ style: { padding: '0 20px' } },
								ui.toolbar(
									ui.search( { value: query.search, placeholder: __( 'Search source, target or notes…', 'searchpilot-pro' ), onSearch: ( v ) => setFilter( 'search', v ) } ),
									ui.select( {
										label: __( 'Match', 'searchpilot-pro' ),
										value: query.match_type,
										options: [ { value: '', label: __( 'All matches', 'searchpilot-pro' ) } ].concat( Object.keys( MATCH ).map( ( k ) => ( { value: k, label: MATCH[ k ].label } ) ) ),
										onChange: ( v ) => setFilter( 'match_type', v ),
									} ),
									ui.select( {
										label: __( 'Origin', 'searchpilot-pro' ),
										value: query.origin,
										options: [ { value: '', label: __( 'All origins', 'searchpilot-pro' ) } ].concat( Object.keys( ORIGINS ).map( ( k ) => ( { value: k, label: ORIGINS[ k ] } ) ) ),
										onChange: ( v ) => setFilter( 'origin', v ),
									} ),
									ui.select( {
										label: __( 'Status', 'searchpilot-pro' ),
										value: query.enabled,
										options: [
											{ value: '', label: __( 'Enabled & disabled', 'searchpilot-pro' ) },
											{ value: '1', label: __( 'Enabled', 'searchpilot-pro' ) },
											{ value: '0', label: __( 'Disabled', 'searchpilot-pro' ) },
										],
										onChange: ( v ) => setFilter( 'enabled', v ),
									} ),
									ui.select( {
										label: __( 'Sort', 'searchpilot-pro' ),
										value: query.orderby,
										options: [
											{ value: 'updated', label: __( 'Recently changed', 'searchpilot-pro' ) },
											{ value: 'hits', label: __( 'Most used', 'searchpilot-pro' ) },
											{ value: 'created', label: __( 'Newest', 'searchpilot-pro' ) },
											{ value: 'source', label: __( 'Source A–Z', 'searchpilot-pro' ) },
										],
										onChange: ( v ) => {
											const order = 'source' === v ? 'asc' : 'desc';
											SPP.query.set( { orderby: v, order } );
											table.setQuery( { orderby: v, order } );
										},
									} )
								)
							),
							bulkBar,
							table.el,
						],
					} ),
					ui.grid( 2, testerCard(), importCard( reloadAll ) ),
					automation
				)
			);
		},
	} );
} )( window.wp, window.SPP );

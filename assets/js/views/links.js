/**
 * Internal Links view: suggestions, orphan pages, link health, settings.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const PER_PAGE = 20;

	const STATUS_OPTIONS = [
		{ value: 'pending', label: __( 'Waiting for approval', 'searchpilot-pro' ) },
		{ value: 'applied', label: __( 'Inserted', 'searchpilot-pro' ) },
		{ value: 'dismissed', label: __( 'Dismissed', 'searchpilot-pro' ) },
		{ value: 'stale', label: __( 'Outdated', 'searchpilot-pro' ) },
		{ value: 'failed', label: __( 'Failed', 'searchpilot-pro' ) },
		{ value: 'all', label: __( 'All suggestions', 'searchpilot-pro' ) },
	];

	const STATUS_BADGES = {
		applied: [ __( 'Inserted', 'searchpilot-pro' ), 'passed' ],
		dismissed: [ __( 'Dismissed', 'searchpilot-pro' ), 'outline' ],
		stale: [ __( 'Outdated', 'searchpilot-pro' ), 'medium' ],
		failed: [ __( 'Failed', 'searchpilot-pro' ), 'critical' ],
	};

	/**
	 * Context sentence with the anchor wrapped in <mark> (built with DOM nodes, never innerHTML).
	 *
	 * @param {string} text   Context.
	 * @param {string} anchor Anchor.
	 * @return {Array} Nodes.
	 */
	function highlight( text, anchor ) {
		text = text || '';
		if ( ! anchor ) {
			return [ text ];
		}
		let index = -1;
		const lower = text.toLowerCase();
		const needle = anchor.toLowerCase();
		if ( lower.length === text.length && needle.length === anchor.length ) {
			index = lower.indexOf( needle );
		}
		if ( index < 0 ) {
			index = text.indexOf( anchor );
		}
		if ( index < 0 ) {
			return [ text ];
		}
		return [
			text.slice( 0, index ),
			h( 'mark', { class: 'spp-link-mark', style: { background: 'var(--spp-gold-soft)', color: 'inherit', padding: '0 3px', borderRadius: 'var(--spp-radius-xs)', boxShadow: 'inset 0 -2px 0 var(--spp-gold)' } }, text.slice( index, index + anchor.length ) ),
			text.slice( index + anchor.length ),
		];
	}

	function scoreBadge( score ) {
		const tone = score >= 0.6 ? 'passed' : score >= 0.4 ? 'info' : 'outline';
		return ui.badge( sprintf( /* translators: %s: relevance percentage. */ __( '%s relevant', 'searchpilot-pro' ), fmt.percent( score, 0 ) ), tone, { title: __( 'Topic similarity between the two posts (internal estimate).', 'searchpilot-pro' ) } );
	}

	/**
	 * Preview of a related-articles list entry (built with DOM nodes, never innerHTML).
	 *
	 * @param {Object} item Suggestion.
	 * @return {Element} Node.
	 */
	function relatedPreview( item ) {
		return h(
			'div',
			{ class: 'spp-break', style: { margin: '10px 0', padding: '8px 12px', borderLeft: '3px solid var(--spp-border)', background: 'var(--spp-surface-2)', borderRadius: 'var(--spp-radius-xs)', color: 'var(--spp-text-2)' } },
			h( 'p', { style: { margin: '0 0 6px' } }, item.context ),
			h(
				'div',
				{ style: { padding: '6px 10px', border: '1px dashed var(--spp-border-strong)', borderRadius: 'var(--spp-radius-xs)', background: 'var(--spp-surface)' } },
				h( 'strong', { class: 'spp-small' }, __( 'Related articles', 'searchpilot-pro' ) ),
				h( 'ul', { style: { margin: '4px 0 0 18px', listStyle: 'disc' } }, h( 'li', null, h( 'mark', { class: 'spp-link-mark', style: { background: 'var(--spp-gold-soft)', color: 'inherit', padding: '0 3px', borderRadius: 'var(--spp-radius-xs)' } }, item.anchor ) ) )
			)
		);
	}

	function kindBadge( item ) {
		return item.kind === 'related'
			? ui.badge( __( 'Related-articles list', 'searchpilot-pro' ), 'outline', {
					title: __( 'No sentence in the post names this topic, so the article is added to a short list at the end of the post, with its title as the link text.', 'searchpilot-pro' ),
			  } )
			: ui.badge( __( 'In the text', 'searchpilot-pro' ), 'outline', { title: __( 'A phrase already in a paragraph of the post becomes the link.', 'searchpilot-pro' ) } );
	}

	function directionBadge( item ) {
		const outbound = item.trigger_id ? item.source_id === item.trigger_id : item.direction === 'outbound';
		return ui.badge( outbound ? __( 'New → Existing', 'searchpilot-pro' ) : __( 'Existing → New', 'searchpilot-pro' ), outbound ? 'navy' : 'gold', {
			title: outbound ? __( 'Add a link in the new post to an older related post.', 'searchpilot-pro' ) : __( 'Add a link in an older related post to the new post.', 'searchpilot-pro' ),
		} );
	}

	SPP.registerView( 'links', {
		mount( el, ctx ) {
			const boot = ( ctx && ctx.boot ) || SPP.boot || {};
			const caps = boot.caps || {};
			const state = {
				tab: SPP.query.get( 'tab', 'suggestions' ),
				status: SPP.query.get( 'status', 'pending' ),
				postId: SPP.query.get( 'post_id', '' ),
				direction: [ 'outbound', 'inbound' ].indexOf( SPP.query.get( 'direction', '' ) ) === -1 ? '' : SPP.query.get( 'direction', '' ),
				page: 1,
				selected: new Set(),
			};
			let stats = null;
			let pollTimer = null;

			/* ---------------- KPIs, index progress, automation notice ---------------- */

			const summary = SPP.region( () =>
				api.get( '/links/stats' ).then( ( data ) => {
					stats = data;
					renderTabs();
					const nodes = [
						ui.grid(
							4,
							ui.kpi( { label: __( 'Indexed posts', 'searchpilot-pro' ), value: fmt.number( data.indexed_posts ), icon: 'file', hint: __( 'Posts in the content graph', 'searchpilot-pro' ) } ),
							ui.kpi( {
								label: __( 'Internal links', 'searchpilot-pro' ),
								value: fmt.number( data.internal_links ),
								icon: 'link',
								hint: sprintf( /* translators: %s: average links per post. */ __( '%s per post on average', 'searchpilot-pro' ), fmt.number( data.avg_per_post, 2 ) ),
							} ),
							ui.kpi( {
								label: __( 'Orphan pages', 'searchpilot-pro' ),
								value: fmt.number( data.orphans ),
								icon: 'alert',
								tone: data.orphans ? 'high' : 'passed',
								hint: __( 'No other post links to them', 'searchpilot-pro' ),
							} ),
							ui.kpi( {
								label: __( 'Pending suggestions', 'searchpilot-pro' ),
								value: fmt.number( data.pending_suggestions ),
								icon: 'sparkle',
								hint: data.pending_by_kind && data.pending_by_kind.related
									? sprintf(
											/* translators: 1: suggestions in the text, 2: related-list suggestions. */
											__( '%1$s in the text, %2$s for related-articles lists', 'searchpilot-pro' ),
											fmt.number( data.pending_by_kind.inline ),
											fmt.number( data.pending_by_kind.related )
									  )
									: sprintf( /* translators: %s: number of links inserted by SearchPilot. */ __( '%s inserted by SearchPilot', 'searchpilot-pro' ), fmt.number( data.plugin_links ) ),
							} )
						),
					];
					const index = data.index || {};
					if ( ! index.ready ) {
						nodes.push(
							ui.card( {
								title: index.phase === 'weights' ? __( 'Calculating topic relevance…', 'searchpilot-pro' ) : __( 'Building the content index…', 'searchpilot-pro' ),
								subtitle: __( 'SearchPilot reads your published posts in small background batches so the site stays fast. Suggestions for new posts start when the first build is complete.', 'searchpilot-pro' ),
								body: ui.progress( {
									value: index.phase === 'weights' ? index.total : index.processed,
									max: index.total || 1,
									tone: 'gold',
									label: index.started_at ? sprintf( /* translators: %s: relative time. */ __( 'Started %s', 'searchpilot-pro' ), fmt.relative( index.started_at ) ) : '',
									right: sprintf( /* translators: 1: processed posts, 2: total posts. */ __( '%1$s of %2$s posts', 'searchpilot-pro' ), fmt.number( index.processed ), fmt.number( index.total ) ),
								} ),
							} )
						);
						clearTimeout( pollTimer );
						pollTimer = setTimeout( () => summary.refresh(), 15000 );
					}
					nodes.push( autoNotice( data.auto || {} ) );
					return h( 'div', { class: 'spp-stack' }, nodes );
				} )
			);

			function autoNotice( auto ) {
				const limits = sprintf(
					/* translators: 1: links per post per run, 2: max links per post, 3: words per link. */
					__( 'Limits: up to %1$s automatic links per post per run, %2$s internal links per post in total, one link per %3$s words.', 'searchpilot-pro' ),
					fmt.number( auto.max_auto_per_post ),
					fmt.number( auto.max_links_per_post ),
					fmt.number( auto.words_per_link )
				);
				const related = auto.auto_related_block
					? sprintf( /* translators: %s: maximum entries. */ __( 'Related-articles lists (up to %s entries) are also added automatically when no sentence fits.', 'searchpilot-pro' ), fmt.number( auto.related_max ) )
					: __( 'Related-articles lists are only added after you approve them.', 'searchpilot-pro' );
				const mode = SPP.MODE_LABELS[ auto.mode ] || auto.mode;
				const actions = [];
				if ( boot.pages && boot.pages.automation && caps.manage ) {
					actions.push( ui.button( { label: __( 'Automation settings', 'searchpilot-pro' ), size: 'small', href: boot.pages.automation } ) );
				}
				if ( caps.manage ) {
					actions.push( ui.button( { label: __( 'Internal link settings', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => switchTab( 'settings' ) } ) );
				}
				if ( auto.policy === 'auto' ) {
					return ui.notice( {
						tone: 'passed',
						icon: 'bolt',
						title: __( 'Automatic insertion is on', 'searchpilot-pro' ),
						text: limits + ' ' + related + ' ' + __( 'Links are added only inside paragraphs, never in headings or existing links, and every change can be undone from the activity log.', 'searchpilot-pro' ),
						actions,
					} );
				}
				if ( auto.policy === 'off' ) {
					return ui.notice( {
						tone: 'info',
						title: __( 'Link insertion is switched off', 'searchpilot-pro' ),
						text: __( 'The “Insert internal links” task is set to Off on the Automation screen. Suggestions are still listed, but they cannot be applied automatically.', 'searchpilot-pro' ),
						actions,
					} );
				}
				return ui.notice( {
					tone: 'info',
					icon: 'hand',
					title: __( 'Links are inserted only after you approve them', 'searchpilot-pro' ),
					text:
						( auto.auto_insert
							? sprintf( /* translators: %s: automation mode. */ __( 'Automatic insertion is enabled, but %s mode asks for approval. Switch to Autopilot (or set the “Insert internal links” task to Automatic) to let SearchPilot insert links on its own.', 'searchpilot-pro' ), mode )
							: sprintf( /* translators: %s: automation mode. */ __( 'Current mode: %s. Review each suggestion below. To let SearchPilot insert links on its own, turn on “Automatic insertion” in the settings and use Autopilot mode.', 'searchpilot-pro' ), mode ) ) +
						' ' +
						limits,
					actions,
				} );
			}

			/* ---------------- Tabs ---------------- */

			const tabsHolder = h( 'div' );
			const body = h( 'div' );

			function tabDefs() {
				const defs = [
					{ id: 'suggestions', label: __( 'Suggestions', 'searchpilot-pro' ), count: stats ? stats.pending_suggestions : null },
					{ id: 'orphans', label: __( 'Orphan pages', 'searchpilot-pro' ), count: stats ? stats.orphans : null },
					{ id: 'health', label: __( 'Link health', 'searchpilot-pro' ) },
					{ id: 'review', label: __( 'Review automatic links', 'searchpilot-pro' ), count: stats && stats.weak_links ? stats.weak_links : null },
				];
				if ( caps.manage ) {
					defs.push( { id: 'settings', label: __( 'Settings', 'searchpilot-pro' ) } );
				}
				return defs;
			}

			function renderTabs() {
				SPP.mount( tabsHolder, ui.tabs( { tabs: tabDefs(), active: state.tab, onChange: ( id ) => switchTab( id ) } ) );
			}

			function switchTab( id ) {
				if ( ! tabDefs().some( ( t ) => t.id === id ) ) {
					id = 'suggestions';
				}
				state.tab = id;
				SPP.query.set( { tab: id === 'suggestions' ? '' : id } );
				renderTabs();
				renderBody();
			}

			function renderBody() {
				if ( state.tab === 'orphans' ) {
					SPP.mount( body, orphansTab() );
				} else if ( state.tab === 'health' ) {
					SPP.mount( body, healthTab() );
				} else if ( state.tab === 'review' ) {
					SPP.mount( body, reviewTab() );
				} else if ( state.tab === 'settings' && caps.manage ) {
					SPP.mount( body, settingsTab() );
				} else {
					state.tab = 'suggestions';
					SPP.mount( body, suggestionsTab() );
				}
			}

			/* ---------------- Suggestions ---------------- */

			function suggestionsTab() {
				const bulkBar = h( 'div', { class: 'spp-row' } );
				const list = SPP.region( () => loadSuggestions( bulkBar ) );

				const filters = ui.toolbar(
					ui.select( {
						label: __( 'Status', 'searchpilot-pro' ),
						value: state.status,
						options: STATUS_OPTIONS,
						onChange: ( v ) => {
							state.status = v;
							state.page = 1;
							SPP.query.set( { status: v === 'pending' ? '' : v } );
							list.refresh();
						},
					} ),
					ui.select( {
						label: __( 'Direction', 'searchpilot-pro' ),
						value: state.direction,
						options: state.postId
							? [
									{ value: '', label: __( 'Links from and to this post', 'searchpilot-pro' ) },
									{ value: 'outbound', label: __( 'Links from this post', 'searchpilot-pro' ) },
									{ value: 'inbound', label: __( 'Links to this post', 'searchpilot-pro' ) },
							  ]
							: [
									{ value: '', label: __( 'Both directions', 'searchpilot-pro' ) },
									{ value: 'outbound', label: __( 'New → Existing', 'searchpilot-pro' ) },
									{ value: 'inbound', label: __( 'Existing → New', 'searchpilot-pro' ) },
							  ],
						onChange: ( v ) => {
							state.direction = v;
							state.page = 1;
							SPP.query.set( { direction: v } );
							list.refresh();
						},
					} ),
					state.postId
						? h(
								'span',
								{ class: 'spp-row' },
								ui.badge( sprintf( /* translators: %s: post id. */ __( 'Post #%s only', 'searchpilot-pro' ), state.postId ), 'info' ),
								ui.button( {
									label: __( 'Show all posts', 'searchpilot-pro' ),
									size: 'small',
									variant: 'link',
									onClick: () => {
										state.postId = '';
										state.page = 1;
										SPP.query.set( { post_id: '' } );
										renderBody();
									},
								} )
						  )
						: null,
					h( 'span', { class: 'spp-grow' } ),
					bulkBar
				);
				return ui.card( {
					title: __( 'Link suggestions', 'searchpilot-pro' ),
					subtitle: __( 'Phrases in your posts that can link to related articles — or, when no sentence names the topic, an entry in a short related-articles list at the end of the post. Nothing changes until you apply a suggestion.', 'searchpilot-pro' ),
					body: [ filters, list.el ],
				} );
			}

			function renderBulk( bulkBar, items ) {
				const count = state.selected.size;
				const run = ( action ) => {
					const ids = Array.from( state.selected );
					return api.post( '/links/suggestions/bulk', { ids, action } ).then( ( res ) => {
						const failed = res.errors ? Object.keys( res.errors ).length : 0;
						ui.toast(
							action === 'apply'
								? sprintf( /* translators: %d: number of links. */ _n( '%d link inserted.', '%d links inserted.', res.done, 'searchpilot-pro' ), res.done )
								: sprintf( /* translators: %d: number of suggestions. */ _n( '%d suggestion dismissed.', '%d suggestions dismissed.', res.done, 'searchpilot-pro' ), res.done ),
							failed ? 'warning' : 'success'
						);
						if ( failed ) {
							ui.toast( Object.values( res.errors )[ 0 ], 'error' );
						}
						state.selected.clear();
						refreshAll();
					} );
				};
				const selectable = items.filter( ( i ) => i.status === 'pending' && i.can_apply && ! i.manual );
				SPP.mount(
					bulkBar,
					selectable.length
						? [
								h( 'span', { class: 'spp-muted spp-small' }, sprintf( /* translators: %d: selected suggestions. */ _n( '%d selected', '%d selected', count, 'searchpilot-pro' ), count ) ),
								ui.button( { label: __( 'Apply selected', 'searchpilot-pro' ), icon: 'link', variant: 'primary', size: 'small', disabled: ! count, onClick: () => run( 'apply' ) } ),
								ui.button( { label: __( 'Dismiss selected', 'searchpilot-pro' ), size: 'small', disabled: ! count, onClick: () => run( 'dismiss' ) } ),
						  ]
						: null
				);
			}

			function loadSuggestions( bulkBar ) {
				const query = { status: state.status, page: state.page, per_page: PER_PAGE };
				if ( state.direction ) {
					query.direction = state.direction;
				}
				if ( state.postId ) {
					query.post_id = state.postId;
				}
				return api.get( '/links/suggestions', query ).then( ( data ) => {
					const items = data.items || [];
					state.selected.clear();
					renderBulk( bulkBar, items );
					if ( ! items.length ) {
						return ui.empty( {
							icon: 'link',
							title: state.status === 'pending' ? __( 'No suggestions waiting for approval', 'searchpilot-pro' ) : __( 'Nothing in this list', 'searchpilot-pro' ),
							text: __( 'Suggestions appear a minute after you publish a post. You can also use “Find linking opportunities” on the Orphan pages tab.', 'searchpilot-pro' ),
						} );
					}
					// Group by the post that triggered them (usually a newly published post).
					const groups = [];
					const byTrigger = {};
					items.forEach( ( item ) => {
						const key = String( item.trigger_id || 0 );
						if ( ! byTrigger[ key ] ) {
							byTrigger[ key ] = { title: item.trigger_title || item.source_title, items: [] };
							groups.push( byTrigger[ key ] );
						}
						byTrigger[ key ].items.push( item );
					} );
					return h(
						'div',
						{ class: 'spp-stack' },
						groups.map( ( group ) =>
							h(
								'div',
								null,
								h( 'h4', { class: 'spp-strong', style: { margin: '6px 0 8px' } }, sprintf( /* translators: %s: post title. */ __( 'For “%s”', 'searchpilot-pro' ), group.title ) ),
								group.items.map( ( item ) => suggestionRow( item, bulkBar, items ) )
							)
						),
						ui.pagination( {
							page: data.page,
							pages: data.pages,
							total: data.total,
							perPage: PER_PAGE,
							onPage: ( p ) => {
								state.page = p;
								refreshAll();
							},
						} )
					);
				} );
			}

			function suggestionRow( item, bulkBar, items ) {
				const actions = [];
				const selectable = item.status === 'pending' && item.can_apply && ! item.manual;
				if ( item.status !== 'applied' && item.can_apply ) {
					if ( item.manual ) {
						actions.push(
							ui.button( {
								label: __( 'Insert manually', 'searchpilot-pro' ),
								size: 'small',
								href: item.source_edit || null,
								title: sprintf( /* translators: %s: page builder. */ __( 'Built with %s — open the page builder and add the link yourself.', 'searchpilot-pro' ), item.builder ),
							} )
						);
					} else {
						actions.push(
							ui.button( {
								label: __( 'Apply', 'searchpilot-pro' ),
								icon: 'link',
								variant: 'primary',
								size: 'small',
								onClick: () =>
									api.post( '/links/suggestions/' + item.id + '/apply' ).then( () => {
										ui.toast(
											item.kind === 'related'
												? __( 'Added to the related-articles list. You can undo it from Automation → Activity.', 'searchpilot-pro' )
												: __( 'Link inserted. You can undo it from Automation → Activity.', 'searchpilot-pro' )
										);
										refreshAll();
									} ),
							} )
						);
					}
					if ( item.status !== 'dismissed' ) {
						actions.push(
							ui.button( {
								label: __( 'Dismiss', 'searchpilot-pro' ),
								size: 'small',
								variant: 'ghost',
								onClick: () =>
									api.post( '/links/suggestions/' + item.id + '/dismiss' ).then( () => {
										ui.toast( __( 'Suggestion dismissed. It will not be proposed again.', 'searchpilot-pro' ) );
										refreshAll();
									} ),
							} )
						);
					}
				}
				const checkbox = selectable
					? h( 'input', {
							type: 'checkbox',
							'aria-label': __( 'Select suggestion', 'searchpilot-pro' ),
							onChange: ( e ) => {
								if ( e.target.checked ) {
									state.selected.add( item.id );
								} else {
									state.selected.delete( item.id );
								}
								renderBulk( bulkBar, items );
							},
					  } )
					: null;
				const status = STATUS_BADGES[ item.status ];
				return h(
					'article',
					{ class: 'spp-issue is-opportunity', style: { borderLeftColor: item.status === 'pending' ? 'var(--spp-gold)' : 'var(--spp-border-strong)' } },
					h(
						'div',
						{ class: 'spp-issue-head' },
						checkbox ? h( 'div', { style: { paddingTop: '2px' } }, checkbox ) : null,
						h(
							'div',
							{ class: 'spp-grow' },
							h(
								'div',
								{ class: 'spp-row' },
								directionBadge( item ),
								kindBadge( item ),
								scoreBadge( item.score ),
								status ? ui.badge( status[ 0 ], status[ 1 ] ) : null,
								item.manual ? ui.badge( item.builder || __( 'Page builder', 'searchpilot-pro' ), 'outline', { title: __( 'This post is edited in a page builder, so SearchPilot does not change it.', 'searchpilot-pro' ) } ) : null
							),
							h(
								'div',
								{ class: 'spp-issue-title', style: { marginTop: '6px' } },
								__( 'In', 'searchpilot-pro' ),
								' ',
								item.source_edit ? h( 'a', { href: item.source_edit }, item.source_title ) : item.source_title,
								' → ',
								ui.link( item.target_title, item.target_url, { external: true } )
							)
						)
					),
					item.kind === 'related'
						? relatedPreview( item )
						: h(
								'blockquote',
								{ class: 'spp-break', style: { margin: '10px 0', padding: '8px 12px', borderLeft: '3px solid var(--spp-border)', background: 'var(--spp-surface-2)', borderRadius: 'var(--spp-radius-xs)', color: 'var(--spp-text-2)' } },
								highlight( item.context, item.anchor )
						  ),
					h(
						'div',
						{ class: 'spp-issue-actions' },
						h(
							'span',
							{ class: 'spp-muted spp-small spp-grow' },
							item.kind === 'related'
								? sprintf( /* translators: %s: article title. */ __( 'Link text: the article title “%s”', 'searchpilot-pro' ), item.anchor )
								: sprintf( /* translators: %s: anchor text. */ __( 'Link text: “%s”', 'searchpilot-pro' ), item.anchor )
						),
						actions
					)
				);
			}

			/* ---------------- Orphan pages ---------------- */

			function relatedList( items ) {
				return items && items.length
					? h(
							'ul',
							{ class: 'spp-list' },
							items.map( ( rel ) =>
								h(
									'li',
									null,
									rel.edit_link ? h( 'a', { href: rel.edit_link }, rel.title ) : rel.title,
									' ',
									rel.tier === 'recent'
										? h( 'span', { class: 'spp-muted spp-small', title: __( 'No shared topic words: a recent post from the same category.', 'searchpilot-pro' ) }, __( 'same category', 'searchpilot-pro' ) )
										: h( 'span', { class: 'spp-muted spp-small' }, fmt.percent( rel.score, 0 ) )
								)
							)
					  )
					: h( 'span', { class: 'spp-muted' }, __( 'No closely related posts yet', 'searchpilot-pro' ) );
			}

			// Related posts are computed on demand per row (cached on the server).
			function relatedCell( row ) {
				if ( row.related ) {
					return relatedList( row.related );
				}
				const cell = h( 'div' );
				SPP.mount(
					cell,
					ui.button( {
						label: __( 'Show related posts', 'searchpilot-pro' ),
						size: 'small',
						variant: 'link',
						onClick: () =>
							api.get( '/links/related/' + row.post_id, { limit: 3 } ).then( ( res ) => {
								row.related = res.items || [];
								SPP.mount( cell, relatedList( row.related ) );
							} ),
					} )
				);
				return cell;
			}

			function orphansTab() {
				const table = ui.dataTable( {
					path: '/links/orphans',
					perPage: PER_PAGE,
					empty: __( 'No orphan pages: every indexed post has at least one link from another post.', 'searchpilot-pro' ),
					columns: [
						{
							key: 'title',
							label: __( 'Page', 'searchpilot-pro' ),
							render: ( r ) => ui.urlCell( r.url, r.title ),
						},
						{ key: 'post_type', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => h( 'code', null, r.post_type ) },
						{ key: 'date', label: __( 'Published', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => fmt.date( r.date ) },
						{
							key: 'related',
							label: __( 'Posts that could link here', 'searchpilot-pro' ),
							render: ( r ) => relatedCell( r ),
						},
						{
							key: 'actions',
							label: '',
							align: 'right',
							render: ( r ) =>
								h(
									'div',
									{ class: 'spp-row', style: { justifyContent: 'flex-end' } },
									caps.edit
										? ui.button( {
												label: __( 'Find linking opportunities', 'searchpilot-pro' ),
												icon: 'sparkle',
												size: 'small',
												variant: 'primary',
												onClick: () =>
													api.post( '/links/generate', { post_id: r.post_id } ).then( ( res ) => {
														ui.toast( res.message, res.items && res.items.length ? 'success' : 'warning' );
														if ( res.items && res.items.length ) {
															state.postId = String( r.post_id );
															state.status = 'pending';
															state.page = 1;
															SPP.query.set( { post_id: state.postId, status: '' } );
															summary.refresh();
															switchTab( 'suggestions' );
														}
													} ),
										  } )
										: null,
									r.edit_link ? ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', href: r.edit_link } ) : null
								),
						},
					],
				} );
				return ui.stack(
					ui.notice( {
						tone: 'info',
						title: __( 'Why orphan pages matter', 'searchpilot-pro' ),
						text: __( 'Search engines find and rank pages mostly through links. A page that no other post links to (and that is not in a menu) is crawled less often and gets little authority. Link to it from two or three related articles.', 'searchpilot-pro' ),
					} ),
					ui.card( { title: __( 'Orphan pages', 'searchpilot-pro' ), flush: true, body: table.el } )
				);
			}

			/* ---------------- Review automatic links ---------------- */

			const WEAK_REASONS = {
				weak_anchor: [ __( 'Weak link text', 'searchpilot-pro' ), 'high', __( 'The link text does not name the article it leads to.', 'searchpilot-pro' ) ],
				target_gone: [ __( 'Target unpublished', 'searchpilot-pro' ), 'critical', __( 'The linked article is no longer published.', 'searchpilot-pro' ) ],
			};

			function reviewTab() {
				const region = SPP.region( () =>
					api.get( '/links/weak' ).then( ( data ) => {
						const items = data.items || [];
						if ( stats && stats.weak_links !== items.length ) {
							stats.weak_links = items.length;
							renderTabs();
						}
						if ( ! items.length ) {
							return ui.empty( {
								icon: 'check',
								title: __( 'No weak automatic links', 'searchpilot-pro' ),
								text: __( 'Every in-text link SearchPilot inserted names the article it leads to.', 'searchpilot-pro' ),
							} );
						}
						const removeOne = ( item ) =>
							api.post( '/links/weak/remove', { keys: [ item.key ] } ).then( ( res ) => {
								ui.toast(
									res.remaining
										? sprintf( /* translators: %s: number of links left. */ __( 'Link removed — the words stay in the text. %s left to review.', 'searchpilot-pro' ), fmt.number( res.remaining ) )
										: __( 'Link removed — the words stay in the text. Nothing left to review.', 'searchpilot-pro' ),
									'success',
									res.group_id
										? {
												action: {
													label: __( 'Undo', 'searchpilot-pro' ),
													onClick: () =>
														api.post( '/activity/group/' + res.group_id + '/undo' ).then( () => {
															summary.refresh();
															region.refresh();
														} ),
												},
										  }
										: {}
								);
								summary.refresh();
								region.refresh();
							} );
						const table = ui.table( {
							rows: items,
							empty: __( 'Nothing to review.', 'searchpilot-pro' ),
							columns: [
								{
									key: 'anchor',
									label: __( 'Link text in its sentence', 'searchpilot-pro' ),
									render: ( r ) => h( 'div', { class: 'spp-break' }, highlight( r.context || r.anchor, r.anchor ) ),
								},
								{ key: 'post', label: __( 'In', 'searchpilot-pro' ), render: ( r ) => ( r.post_edit ? h( 'a', { href: r.post_edit }, r.post_title ) : r.post_title ) },
								{ key: 'target', label: __( 'Links to', 'searchpilot-pro' ), render: ( r ) => ( r.target_title ? ui.link( r.target_title, r.target_url, { external: true } ) : h( 'span', { class: 'spp-muted' }, fmt.url( r.target_url ) ) ) },
								{
									key: 'reason',
									label: __( 'Why', 'searchpilot-pro' ),
									render: ( r ) => {
										const info = WEAK_REASONS[ r.reason ] || [ r.reason, 'outline', '' ];
										return ui.badge( info[ 0 ], info[ 1 ], { title: info[ 2 ] } );
									},
								},
								{
									key: 'inserted_at',
									label: __( 'Added', 'searchpilot-pro' ),
									className: 'is-nowrap',
									render: ( r ) =>
										h(
											'span',
											{ class: 'spp-small' },
											r.source === 'user' ? __( 'Approved', 'searchpilot-pro' ) : __( 'Automatically', 'searchpilot-pro' ),
											r.inserted_at ? h( 'span', { class: 'spp-muted' }, ' · ', fmt.relative( r.inserted_at ) ) : null
										),
								},
								{
									key: 'actions',
									label: '',
									align: 'right',
									render: ( r ) =>
										r.can_edit && caps.edit
											? ui.button( { label: __( 'Remove link', 'searchpilot-pro' ), size: 'small', busyLabel: __( 'Removing…', 'searchpilot-pro' ), onClick: () => removeOne( r ) } )
											: null,
								},
							],
						} );
						return h(
							'div',
							{ class: 'spp-stack' },
							h(
								'div',
								{ class: 'spp-row' },
								h(
									'span',
									{ class: 'spp-muted spp-small spp-grow' },
									sprintf( /* translators: %s: number of links. */ _n( '%s link to review.', '%s links to review.', items.length, 'searchpilot-pro' ), fmt.number( items.length ) ) +
										( data.more ? ' ' + __( 'Only the newest links were checked; more may follow after these are removed.', 'searchpilot-pro' ) : '' )
								),
								data.issue && caps.edit
									? ui.button( {
											label: __( 'Preview and remove all', 'searchpilot-pro' ),
											icon: 'bolt',
											variant: 'primary',
											size: 'small',
											onClick: () =>
												ui.fixPreview( data.issue, {
													onApplied: () => {
														summary.refresh();
														region.refresh();
													},
												} ),
									  } )
									: null
							),
							table
						);
					} )
				);
				return ui.stack(
					ui.notice( {
						tone: 'info',
						title: __( 'Links added by earlier versions of SearchPilot', 'searchpilot-pro' ),
						text: __( 'SearchPilot 1.0 sometimes linked a common word (such as the city your site covers) together with a neighbouring word, like «across Bengaluru». These links are listed here so you can remove them. Only the link is removed — the words stay in the text. Links people added are never listed, and every removal can be undone from Automation → Activity.', 'searchpilot-pro' ),
					} ),
					ui.card( { title: __( 'Review automatic links', 'searchpilot-pro' ), flush: false, body: region.el } )
				);
			}

			/* ---------------- Link health ---------------- */

			function healthTab() {
				const region = SPP.region( () =>
					( stats ? Promise.resolve( stats ) : api.get( '/links/stats' ) ).then( ( data ) => {
						const most = ( data.most_linked || [] ).map( ( m ) => ( { label: m.title, value: m.inbound, href: m.edit_link || m.url } ) );
						const without = data.without_outbound || { count: 0, items: [] };
						const overused = data.overused_anchors || [];
						return ui.grid(
							2,
							ui.card( {
								title: __( 'Most linked posts', 'searchpilot-pro' ),
								subtitle: __( 'Number of other posts linking to each page.', 'searchpilot-pro' ),
								body: ui.barList( { items: most, empty: __( 'No internal links between posts yet', 'searchpilot-pro' ) } ),
							} ),
							ui.card( {
								title: __( 'Posts without internal links', 'searchpilot-pro' ),
								subtitle: sprintf( /* translators: %s: number of posts. */ _n( '%s post does not link to any other post.', '%s posts do not link to any other post.', without.count, 'searchpilot-pro' ), fmt.number( without.count ) ),
								flush: true,
								body: ui.table( {
									dense: true,
									rows: without.items || [],
									empty: __( 'Every post links to at least one other post.', 'searchpilot-pro' ),
									columns: [
										{ key: 'title', label: __( 'Post', 'searchpilot-pro' ), render: ( r ) => ( r.edit_link ? h( 'a', { href: r.edit_link }, r.title ) : r.title ) },
										{ key: 'words', label: __( 'Words', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.words ) },
									],
								} ),
							} ),
							ui.card( {
								title: __( 'Overused link texts', 'searchpilot-pro' ),
								subtitle: __( 'The same words used more than three times to link to one page. Vary the wording so links read naturally.', 'searchpilot-pro' ),
								flush: true,
								className: 'span-12',
								body: ui.table( {
									dense: true,
									rows: overused,
									empty: __( 'Link texts are varied — no anchor is overused.', 'searchpilot-pro' ),
									columns: [
										{ key: 'anchor', label: __( 'Link text', 'searchpilot-pro' ), render: ( r ) => h( 'strong', null, r.anchor ) },
										{ key: 'title', label: __( 'Target page', 'searchpilot-pro' ), render: ( r ) => ui.link( r.title, r.url, { external: true } ) },
										{ key: 'uses', label: __( 'Uses', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.uses ) },
									],
								} ),
							} )
						);
					} )
				);
				return region.el;
			}

			/* ---------------- Settings ---------------- */

			function settingsTab() {
				const region = SPP.region( () =>
					api.get( '/settings' ).then( ( data ) => {
						const current = Object.assign( {}, ( data.settings && data.settings.links ) || {} );
						const draft = Object.assign( {}, current );
						const postTypes = Object.keys( data.post_types || {} ).map( ( name ) => ( { value: name, label: data.post_types[ name ].label || name } ) );
						const errors = h( 'div' );
						const set = ( key ) => ( v ) => {
							draft[ key ] = v;
						};
						return ui.card( {
							title: __( 'Internal link settings', 'searchpilot-pro' ),
							subtitle: __( 'How SearchPilot suggests and inserts internal links. Links are only ever added inside paragraphs, and every inserted link can be undone.', 'searchpilot-pro' ),
							body: [
								errors,
								ui.field.toggle( {
									label: __( 'Suggest links for new posts', 'searchpilot-pro' ),
									help: __( 'When a post is published, find phrases that can link to related posts, and phrases in older posts that can link to it.', 'searchpilot-pro' ),
									checked: !! current.suggestions,
									row: true,
									onChange: set( 'suggestions' ),
								} ),
								ui.field.toggle( {
									label: __( 'Automatic insertion', 'searchpilot-pro' ),
									help: __( 'Insert the best suggestions without asking — only in Autopilot mode (or when the “Insert internal links” task is set to Automatic). Page-builder posts are never edited.', 'searchpilot-pro' ),
									checked: !! current.auto_insert,
									row: true,
									onChange: set( 'auto_insert' ),
								} ),
								ui.field.toggle( {
									label: __( 'Add related-articles lists automatically', 'searchpilot-pro' ),
									help: __( 'When automatic insertion runs and no sentence in a post names a related article, add a short “Related articles” list at the end of the post (block-editor and classic posts; page-builder posts are never edited). Off: these lists are only added after you approve them.', 'searchpilot-pro' ),
									checked: !! current.auto_related_block,
									row: true,
									onChange: set( 'auto_related_block' ),
								} ),
								ui.field.number( { label: __( 'Automatic links per post per run', 'searchpilot-pro' ), value: current.max_auto_per_post, min: 0, max: 20, row: true, onInput: set( 'max_auto_per_post' ) } ),
								ui.field.number( { label: __( 'Maximum internal links per post', 'searchpilot-pro' ), value: current.max_links_per_post, min: 1, max: 100, row: true, onInput: set( 'max_links_per_post' ) } ),
								ui.field.number( {
									label: __( 'Words per link', 'searchpilot-pro' ),
									help: __( 'At most one suggested link for this many words of text.', 'searchpilot-pro' ),
									value: current.words_per_link,
									min: 30,
									max: 1000,
									row: true,
									onInput: set( 'words_per_link' ),
								} ),
								ui.field.number( {
									label: __( 'Links from older posts to a new post', 'searchpilot-pro' ),
									help: __( 'How many related existing posts may get a suggestion to link to each new post.', 'searchpilot-pro' ),
									value: current.max_inbound_per_new_post,
									min: 0,
									max: 50,
									row: true,
									onInput: set( 'max_inbound_per_new_post' ),
								} ),
								ui.field.number( {
									label: __( 'Minimum relevance', 'searchpilot-pro' ),
									help: __( 'Only suggest links between posts at least this related (topic similarity).', 'searchpilot-pro' ),
									value: current.min_relevance,
									min: 1,
									max: 100,
									suffix: '%',
									row: true,
									onInput: set( 'min_relevance' ),
								} ),
								ui.field.checklist( {
									label: __( 'Content types', 'searchpilot-pro' ),
									help: __( 'Post types that take part in internal linking.', 'searchpilot-pro' ),
									options: postTypes,
									values: current.post_types || [],
									onChange: set( 'post_types' ),
								} ),
								ui.field.text( {
									label: __( 'Excluded posts', 'searchpilot-pro' ),
									help: __( 'Post IDs that should never receive or give suggested links, separated by commas.', 'searchpilot-pro' ),
									value: ( current.excluded_ids || [] ).join( ', ' ),
									row: true,
									onInput: ( v ) => {
										draft.excluded_ids = v
											.split( /[\s,]+/ )
											.map( ( x ) => parseInt( x, 10 ) )
											.filter( ( x ) => x > 0 );
									},
								} ),
							],
							footer: ui.button( {
								label: __( 'Save settings', 'searchpilot-pro' ),
								variant: 'primary',
								onClick: () =>
									api.post( '/settings', { settings: { links: draft } } ).then( ( res ) => {
										const errs = res.errors ? Object.values( res.errors ) : [];
										SPP.mount( errors, errs.length ? ui.notice( { tone: 'critical', title: __( 'Some values were not saved', 'searchpilot-pro' ), text: errs.join( ' ' ) } ) : null );
										ui.toast( errs.length ? __( 'Saved with errors', 'searchpilot-pro' ) : __( 'Settings saved', 'searchpilot-pro' ), errs.length ? 'warning' : 'success' );
										summary.refresh();
									} ),
							} ),
						} );
					} )
				);
				return region.el;
			}

			/* ---------------- Shell ---------------- */

			function refreshAll() {
				summary.refresh();
				renderBody();
			}

			const headerActions = [ ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', onClick: () => refreshAll() } ) ];
			if ( caps.manage ) {
				headerActions.push(
					ui.button( {
						label: __( 'Rebuild index', 'searchpilot-pro' ),
						icon: 'cog',
						onClick: () =>
							ui
								.confirm( {
									title: __( 'Rebuild the content index?', 'searchpilot-pro' ),
									message: __( 'SearchPilot re-reads every published post in the background. Existing links and suggestions are kept; relevance scores are recalculated.', 'searchpilot-pro' ),
									confirmLabel: __( 'Rebuild', 'searchpilot-pro' ),
								} )
								.then( ( ok ) =>
									ok
										? api.post( '/links/reindex', {} ).then( () => {
												ui.toast( __( 'Rebuild started', 'searchpilot-pro' ) );
												summary.refresh();
										  } )
										: null
								),
					} )
				);
			}

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Internal Links', 'searchpilot-pro' ),
					subtitle: __( 'Connect related articles with natural links, find pages nobody links to, and keep link texts varied — in every language your site publishes in.', 'searchpilot-pro' ),
					actions: headerActions,
				} ),
				h( 'div', { class: 'spp-stack' }, summary.el, tabsHolder, body )
			);
			renderTabs();
			renderBody();
		},
	} );
} )( window.wp, window.SPP );

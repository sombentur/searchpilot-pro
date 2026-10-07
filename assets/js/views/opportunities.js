/**
 * Content Opportunities view: evidence-backed recommendations from search data.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const ACTIONS = [ 'update_title', 'improve_meta_description', 'refresh_content', 'add_internal_links', 'differentiate_pages', 'merge_articles', 'create_supporting_article', 'fix_indexing', 'improve_structured_data' ];

	/** Index status labels (the same wording as the Indexing screen). */
	const INDEX_STATUS = {
		unknown: __( 'Not inspected yet', 'searchpilot-pro' ),
		indexed: __( 'Indexed', 'searchpilot-pro' ),
		not_indexed: __( 'Not indexed', 'searchpilot-pro' ),
		crawled_not_indexed: __( 'Crawled – not indexed', 'searchpilot-pro' ),
		discovered_not_indexed: __( 'Discovered – not indexed', 'searchpilot-pro' ),
		excluded_noindex: __( 'Excluded by noindex', 'searchpilot-pro' ),
		blocked_robots: __( 'Blocked by robots.txt', 'searchpilot-pro' ),
		duplicate_canonical: __( 'Duplicate – other canonical', 'searchpilot-pro' ),
		alternate_canonical: __( 'Alternate (canonical elsewhere)', 'searchpilot-pro' ),
		redirect: __( 'Page with redirect', 'searchpilot-pro' ),
		not_found: __( 'Not found (404)', 'searchpilot-pro' ),
		soft_404: __( 'Soft 404', 'searchpilot-pro' ),
		server_error: __( 'Server error (5xx)', 'searchpilot-pro' ),
		unknown_to_google: __( 'Unknown to Google', 'searchpilot-pro' ),
		removed: __( 'Removed', 'searchpilot-pro' ),
	};
	const ACTION_ICONS = {
		update_title: 'file',
		improve_meta_description: 'file',
		refresh_content: 'refresh',
		add_internal_links: 'link',
		differentiate_pages: 'link',
		merge_articles: 'copy',
		create_supporting_article: 'plus',
		fix_indexing: 'search',
		improve_structured_data: 'shield',
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

	function editUrl( postId ) {
		return ( SPP.boot.adminUrl || '' ) + 'post.php?post=' + encodeURIComponent( postId ) + '&action=edit';
	}

	function trend( current, previous, opts ) {
		if ( previous === null || previous === undefined || ( ! current && ! previous ) ) {
			return null;
		}
		return ui.trend( fmt.delta( current, previous ), opts );
	}

	function positionTrend( d ) {
		if ( d.delta_position === null || d.delta_position === undefined ) {
			return null;
		}
		const diff = Number( d.delta_position );
		return ui.trend( { pct: diff, dir: Math.abs( diff ) < 0.05 ? 'flat' : diff > 0 ? 'up' : 'down' }, { good: 'down', absolute: fmt.number( Math.abs( diff ), 1 ), title: sprintf( /* translators: %s: previous position. */ __( 'Previous period: position %s', 'searchpilot-pro' ), fmt.position( d.prev_position ) ) } );
	}

	function stat( label, value, extra ) {
		return h( 'div', { class: 'spp-status-item' }, h( 'div', null, h( 'div', { class: 'spp-status-text' }, label ), h( 'div', { class: 'spp-row', style: { gap: '6px' } }, h( 'span', { class: 'spp-status-title' }, value ), extra || null ) ) );
	}

	/** Evidence block for an opportunity. */
	function evidence( o ) {
		const d = o.data || {};
		const items = [];
		if ( d.query ) {
			items.push( stat( o.type === 'keyword_cannibalization' || o.type === 'query_without_landing_page' ? __( 'Query', 'searchpilot-pro' ) : __( 'Top query', 'searchpilot-pro' ), '“' + d.query + '”' ) );
		}
		switch ( o.type ) {
			case 'keyword_cannibalization':
				items.push( stat( __( 'Impressions (28 days)', 'searchpilot-pro' ), fmt.number( d.impressions ) ) );
				items.push( stat( __( 'Competing pages', 'searchpilot-pro' ), fmt.number( ( d.competing_pages || [] ).length ) ) );
				break;
			case 'query_without_landing_page':
				items.push( stat( __( 'Impressions (28 days)', 'searchpilot-pro' ), fmt.number( d.impressions ) ) );
				items.push( stat( __( 'Best page position', 'searchpilot-pro' ), fmt.position( d.best_position ) ) );
				items.push( stat( __( 'Best page', 'searchpilot-pro' ), d.best_title || pageName( d.best_page ) ) );
				break;
			case 'content_refresh':
				items.push( stat( __( 'Last updated', 'searchpilot-pro' ), fmt.date( d.modified ) ) );
				items.push( stat( __( 'Impressions (3 months)', 'searchpilot-pro' ), fmt.number( d.impressions ), trend( d.impressions, d.prev_impressions ) ) );
				items.push( stat( __( 'Clicks (3 months)', 'searchpilot-pro' ), fmt.number( d.clicks ), trend( d.clicks, d.prev_clicks ) ) );
				break;
			case 'fix_indexing':
				items.push( stat( __( 'Impressions before', 'searchpilot-pro' ), fmt.number( d.prev_impressions ) ) );
				items.push( stat( __( 'Impressions now', 'searchpilot-pro' ), fmt.number( d.impressions ), trend( d.impressions, d.prev_impressions ) ) );
				items.push( stat( __( 'Index status', 'searchpilot-pro' ), INDEX_STATUS[ d.index_status ] || ( d.http_status ? 'HTTP ' + d.http_status : '—' ) ) );
				break;
			case 'improve_structured_data':
				items.push( stat( __( 'Impressions (28 days)', 'searchpilot-pro' ), fmt.number( d.impressions ) ) );
				items.push( stat( __( 'Schema problems', 'searchpilot-pro' ), fmt.number( d.schema_issues || 0 ) ) );
				items.push( stat( __( 'Types on the page', 'searchpilot-pro' ), d.schema_types && d.schema_types.length ? d.schema_types.join( ', ' ) : __( 'not crawled / none', 'searchpilot-pro' ) ) );
				break;
			default:
				items.push( stat( __( 'Impressions', 'searchpilot-pro' ), fmt.number( d.impressions ), trend( d.impressions, d.prev_impressions ) ) );
				items.push( stat( __( 'Clicks', 'searchpilot-pro' ), fmt.number( d.clicks ), trend( d.clicks, d.prev_clicks ) ) );
				items.push(
					stat(
						__( 'CTR', 'searchpilot-pro' ),
						fmt.percent( d.ctr, 1 ),
						d.expected_ctr ? h( 'span', { class: 'spp-muted spp-small' }, sprintf( /* translators: %s: expected CTR. */ __( 'expected ≈ %s', 'searchpilot-pro' ), fmt.percent( d.expected_ctr, 1 ) ) ) : trend( d.ctr, d.prev_ctr )
					)
				);
				items.push( stat( __( 'Avg. position', 'searchpilot-pro' ), fmt.position( d.position ), positionTrend( d ) ) );
		}
		return h( 'div', { class: 'spp-status-grid', style: { margin: '12px 0' } }, items );
	}

	/** Toast with an Undo action for an activity entry. */
	function undoToast( message, activityId, onUndone ) {
		ui.toast(
			message,
			'success',
			activityId
				? {
						action: {
							label: __( 'Undo', 'searchpilot-pro' ),
							onClick: () =>
								api
									.post( '/activity/' + activityId + '/undo' )
									.then( () => {
										ui.toast( __( 'Change undone — the previous value is back.', 'searchpilot-pro' ) );
										onUndone && onUndone();
									} )
									.catch( ( err ) => ui.toast( err.message, 'error' ) ),
						},
				  }
				: undefined
		);
	}

	/** Title / description editor with drafts, counter and SERP preview. */
	function openEditor( o, field, onApplied ) {
		const isTitle = field === 'title';
		const body = SPP.region(
			() =>
				api.get( '/opportunities/' + o.id + '/suggestions' ).then( ( s ) => {
					const conf = isTitle ? s.title : s.description;
					let value = conf.current || '';
					const preview = h( 'div' );
					const renderPreview = () => SPP.mount( preview, ui.serpPreview( { title: isTitle ? value : s.title.current, url: s.url, description: isTitle ? s.description.current : value } ) );
					const input = isTitle
						? ui.field.text( { label: __( 'New SEO title', 'searchpilot-pro' ), value, counter: [ 30, conf.max || 60 ], onInput: ( v ) => ( ( value = v ), renderPreview() ) } )
						: ui.field.textarea( { label: __( 'New meta description', 'searchpilot-pro' ), value, rows: 3, counter: [ conf.min || 120, conf.max || 155 ], onInput: ( v ) => ( ( value = v ), renderPreview() ) } );
					const control = input.querySelector( 'input, textarea' );
					const setValue = ( v ) => {
						control.value = v;
						control.dispatchEvent( new Event( 'input' ) );
					};
					renderPreview();
					const drafts = ( conf.suggestions || [] ).map( ( d, i ) => ( {
						value: String( i ),
						label: d.text,
						description: sprintf( /* translators: 1: draft number, 2: draft label, 3: characters. */ __( 'Draft %1$s · %2$s · %3$s characters', 'searchpilot-pro' ), String( i + 1 ), d.label, fmt.number( d.length ) ),
					} ) );
					const storeNote =
						conf.store === 'searchpilot'
							? __( 'Saved as SearchPilot’s SEO field for this post.', 'searchpilot-pro' )
							: sprintf( /* translators: %s: SEO plugin name. */ __( 'Companion mode: saved to %s’s field, so your SEO plugin keeps outputting it — nothing is duplicated.', 'searchpilot-pro' ), conf.store_name );
					return ui.stack(
						h(
							'div',
							null,
							h( 'div', { class: 'spp-strong' }, s.post_title ),
							s.query ? h( 'div', { class: 'spp-muted spp-small' }, sprintf( /* translators: %s: query. */ __( 'Top query: “%s”', 'searchpilot-pro' ), s.query ) ) : null
						),
						ui.dl( [
							[ isTitle ? __( 'Current title', 'searchpilot-pro' ) : __( 'Current description', 'searchpilot-pro' ), conf.current || h( 'span', { class: 'spp-muted' }, __( '(none — search engines pick text from the page)', 'searchpilot-pro' ) ) ],
							conf.raw && conf.raw !== conf.current ? [ __( 'Stored template', 'searchpilot-pro' ), h( 'code', null, conf.raw ) ] : null,
							[ __( 'Where it is saved', 'searchpilot-pro' ), storeNote ],
							s.queries && s.queries.length ? [ __( 'Queries for this page', 'searchpilot-pro' ), s.queries.map( ( q ) => q.query + ' (' + fmt.number( q.impressions ) + ')' ).join( ' · ' ) ] : null,
						] ),
						drafts.length
							? h(
									'div',
									null,
									h( 'div', { class: 'spp-field-label' }, __( 'Drafts to start from', 'searchpilot-pro' ) ),
									ui.field.radioCards( { options: drafts, value: null, onChange: ( i ) => setValue( conf.suggestions[ Number( i ) ].text ) } )
							  )
							: null,
						input,
						h( 'div', null, h( 'div', { class: 'spp-field-label' }, __( 'Search result preview', 'searchpilot-pro' ) ), preview ),
						h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), s.note ),
						h(
							'div',
							{ class: 'spp-row is-between' },
							ui.button( { label: __( 'Edit the post instead', 'searchpilot-pro' ), variant: 'ghost', href: s.edit_link } ),
							ui.button( {
								label: isTitle ? __( 'Apply title', 'searchpilot-pro' ) : __( 'Apply description', 'searchpilot-pro' ),
								variant: 'primary',
								icon: 'check',
								onClick: () => {
									if ( ! value.trim() ) {
										ui.toast( __( 'Enter a value first.', 'searchpilot-pro' ), 'error' );
										return null;
									}
									return api.post( '/opportunities/' + o.id + '/apply', { field, value } ).then( ( res ) => {
										modal.close();
										if ( res.unchanged ) {
											ui.toast( res.message, 'warning' );
											return;
										}
										undoToast( isTitle ? __( 'Title updated. It stays logged and can be undone.', 'searchpilot-pro' ) : __( 'Description updated. It stays logged and can be undone.', 'searchpilot-pro' ), res.activity_id, onApplied );
										onApplied && onApplied( res );
									} );
								},
							} )
						)
					);
				} ),
			{ loader: true }
		);
		const modal = ui.modal( { title: isTitle ? __( 'Update title', 'searchpilot-pro' ) : __( 'Improve meta description', 'searchpilot-pro' ), size: 'large', body: body.el } );
	}

	function openCompeting( o, mode ) {
		const d = o.data || {};
		const rows = d.competing_pages || [];
		const merge = mode === 'merge';
		const steps = merge
			? [
					__( 'Pick the page that answers the query best (usually the one with the most clicks or the strongest content).', 'searchpilot-pro' ),
					__( 'These pages share most of their queries: merge the useful parts of the others into it, then redirect the old URLs to it (Redirects screen).', 'searchpilot-pro' ),
					__( 'Where near-duplicate content must stay, point its canonical URL at the main page.', 'searchpilot-pro' ),
			  ]
			: [
					__( 'Give each page a distinct angle: a title and main heading that say what is different about it.', 'searchpilot-pro' ),
					d.latest_page
						? sprintf( /* translators: %s: page. */ __( 'If the pages are updates of one story, link the older ones to the latest (%s) near the top, so readers and search engines find the current answer.', 'searchpilot-pro' ), fmt.path( d.latest_page ) )
						: __( 'If the pages are updates of one story, link the older ones to the latest near the top, so readers and search engines find the current answer.', 'searchpilot-pro' ),
					__( 'Link between pages that serve different intents, with descriptive anchor text.', 'searchpilot-pro' ),
			  ];
		ui.modal( {
			title: merge ? __( 'Merge competing articles', 'searchpilot-pro' ) : __( 'Differentiate or link to the latest', 'searchpilot-pro' ),
			size: 'large',
			body: ui.stack(
				h( 'p', null, sprintf( /* translators: 1: query, 2: impressions. */ __( 'These pages share the impressions for “%1$s” (%2$s impressions in the last 28 days).', 'searchpilot-pro' ), d.query, fmt.number( d.impressions ) ) ),
				ui.table( {
					dense: true,
					rows,
					columns: [
						{ key: 'title', label: __( 'Page', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.page, r.title || '' ) },
						{ key: 'share', label: __( 'Share', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.percent( r.share, 0 ) },
						{ key: 'impressions', label: __( 'Impressions', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.impressions ) },
						{ key: 'clicks', label: __( 'Clicks', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.clicks ) },
						{ key: 'position', label: __( 'Position', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.position( r.position ) },
						{ key: 'published', label: __( 'Published', 'searchpilot-pro' ), render: ( r ) => ( r.published ? fmt.date( r.published ) : '—' ) },
						{
							key: 'edit',
							label: '',
							align: 'right',
							render: ( r ) =>
								r.post_id
									? h(
											'div',
											{ class: 'spp-row', style: { justifyContent: 'flex-end' } },
											ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', href: editUrl( r.post_id ) } ),
											merge ? null : ui.button( { label: __( 'Links', 'searchpilot-pro' ), icon: 'link', size: 'small', variant: 'ghost', href: SPP.pageUrl( 'links', { post_id: r.post_id, direction: 'outbound' } ) } )
									  )
									: null,
						},
					],
				} ),
				ui.card( {
					title: __( 'How to resolve it', 'searchpilot-pro' ),
					body: h(
						'ol',
						null,
						steps.map( ( text ) => h( 'li', null, text ) )
					),
				} ),
				h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'SearchPilot never merges, deletes or rewrites articles automatically.', 'searchpilot-pro' ) )
			),
		} );
	}

	function openSupporting( o ) {
		const d = o.data || {};
		ui.modal( {
			title: __( 'Create a supporting article', 'searchpilot-pro' ),
			body: ui.stack(
				d.query ? ui.dl( [ [ __( 'Query', 'searchpilot-pro' ), ui.copyField( d.query ) ], [ __( 'Impressions (28 days)', 'searchpilot-pro' ), fmt.number( d.impressions ) ], d.best_page ? [ __( 'Current best page', 'searchpilot-pro' ), ui.urlCell( d.best_page, d.best_title || '' ) ] : null ] ) : null,
				h(
					'ul',
					{ class: 'spp-list' },
					h( 'li', null, __( 'Write a focused article that fully answers the query — for readers first.', 'searchpilot-pro' ) ),
					h( 'li', null, __( 'Link to it from the related page(s) above, and link back to them from the new article.', 'searchpilot-pro' ) ),
					h( 'li', null, __( 'Avoid thin or near-duplicate pages; one strong article beats several weak ones.', 'searchpilot-pro' ) )
				),
				h( 'div', { class: 'spp-row' }, ui.button( { label: __( 'Start a new post', 'searchpilot-pro' ), icon: 'plus', variant: 'primary', href: ( SPP.boot.adminUrl || '' ) + 'post-new.php' } ) )
			),
		} );
	}

	function actionButton( o, a, reload ) {
		const d = o.data || {};
		const postId = o.object_type === 'post' ? o.object_id : 0;
		const common = { label: a.label, icon: ACTION_ICONS[ a.code ], size: 'small' };
		switch ( a.code ) {
			case 'update_title':
			case 'improve_meta_description':
				if ( ! o.can_edit ) {
					return null;
				}
				return ui.button( Object.assign( common, { variant: 'primary', onClick: () => openEditor( o, a.code === 'update_title' ? 'title' : 'description', reload ) } ) );
			case 'refresh_content':
				return o.edit_link ? ui.button( Object.assign( common, { href: o.edit_link } ) ) : null;
			case 'add_internal_links':
				return postId ? ui.button( Object.assign( common, { href: SPP.pageUrl( 'links', { post_id: postId, direction: o.type === 'growing_page' ? 'outbound' : 'inbound' } ) } ) ) : null;
			case 'fix_indexing':
				return ui.button( Object.assign( common, { variant: 'primary', href: postId ? SPP.pageUrl( 'indexing', { post_id: postId } ) : SPP.pageUrl( 'indexing', { s: d.page || o.url || '' } ) } ) );
			case 'differentiate_pages':
				return ui.button( Object.assign( common, { variant: 'primary', onClick: () => openCompeting( o, 'differentiate' ) } ) );
			case 'merge_articles':
				return ui.button( Object.assign( common, { onClick: () => openCompeting( o, 'merge' ) } ) );
			case 'create_supporting_article':
				return ui.button( Object.assign( common, { onClick: () => openSupporting( o ) } ) );
			case 'improve_structured_data':
				return SPP.boot.caps && SPP.boot.caps.manage ? ui.button( Object.assign( common, { href: SPP.pageUrl( 'schema', postId ? { post_id: postId } : {} ) } ) ) : null;
		}
		return null;
	}

	function appliedBadge( d ) {
		if ( ! d.applied_at ) {
			return null;
		}
		const what = d.applied_field === 'description' ? __( 'Description updated', 'searchpilot-pro' ) : __( 'Title updated', 'searchpilot-pro' );
		return ui.badge( what + ' ' + fmt.relative( d.applied_at ) + ( d.change_measured ? '' : ' · ' + __( 'waiting for new data', 'searchpilot-pro' ) ), 'passed', { dot: true, title: __( 'The opportunity stays open until fresh search data shows the effect of the change.', 'searchpilot-pro' ) } );
	}

	function card( o, reload ) {
		const d = o.data || {};
		const actions = ( o.actions || [] ).map( ( a ) => actionButton( o, a, reload ) ).filter( Boolean );
		if ( o.url ) {
			actions.push( ui.button( { label: __( 'View', 'searchpilot-pro' ), icon: 'external', size: 'small', variant: 'ghost', href: o.url, target: '_blank' } ) );
		}
		if ( d.page ) {
			actions.push( ui.button( { label: __( 'Performance', 'searchpilot-pro' ), icon: 'chart', size: 'small', variant: 'ghost', href: SPP.pageUrl( 'performance', { tab: 'pages', page_url: d.page } ) } ) );
		}
		actions.push(
			ui.button( {
				label: __( 'Dismiss', 'searchpilot-pro' ),
				size: 'small',
				variant: 'ghost',
				onClick: () =>
					api.post( '/opportunities/' + o.id + '/dismiss' ).then( () => {
						ui.toast( __( 'Dismissed. It stays hidden even when new search data arrives.', 'searchpilot-pro' ) );
						reload();
					} ),
			} )
		);
		return h(
			'article',
			{ class: [ 'spp-issue', 'is-' + ( o.severity || 'opportunity' ) ] },
			h(
				'div',
				{ class: 'spp-issue-head' },
				h(
					'div',
					{ class: 'spp-grow' },
					h( 'div', { class: 'spp-row' }, ui.severity( o.severity ), h( 'span', { class: 'spp-issue-title' }, o.label ), appliedBadge( d ) ),
					h( 'div', { class: 'spp-issue-object' }, o.object_type === 'query' ? '“' + o.title + '”' : o.title || fmt.url( o.url ), o.url && o.object_type !== 'query' ? h( 'span', { class: 'spp-muted spp-small' }, ' · ' + pageName( o.url ) ) : null )
				),
				h( 'div', { class: 'spp-issue-meta' }, o.detected_at ? sprintf( /* translators: %s: relative time. */ __( 'Found %s', 'searchpilot-pro' ), fmt.relative( o.detected_at ) ) : null )
			),
			evidence( o ),
			h(
				'div',
				{ class: 'spp-issue-body' },
				h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'What happened', 'searchpilot-pro' ) ), h( 'p', null, o.what ) ),
				h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'Why it matters', 'searchpilot-pro' ) ), h( 'p', null, o.why ) ),
				h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'Recommended fix', 'searchpilot-pro' ) ), h( 'p', null, o.fix ) )
			),
			h( 'div', { class: 'spp-issue-actions' }, actions )
		);
	}

	SPP.registerView( 'opportunities', {
		mount( el, ctx ) {
			const params = ( ctx && ctx.params ) || new URLSearchParams();
			const state = {
				action: ACTIONS.indexOf( params.get( 'action' ) ) !== -1 ? params.get( 'action' ) : '',
				severity: params.get( 'severity' ) || '',
				type: params.get( 'type' ) || '',
				search: params.get( 's' ) || '',
				post_id: parseInt( params.get( 'post_id' ) || '0', 10 ) || 0,
				page: 1,
			};
			let summaryData = null;

			const summary = SPP.region( () =>
				api.get( '/opportunities/summary' ).then( ( s ) => {
					summaryData = s;
					return renderSummary( s );
				} )
			);
			const list = SPP.region( () =>
				api
					.get( '/opportunities', {
						action: state.action,
						severity: state.severity,
						type: state.type,
						search: state.search,
						post_id: state.post_id || '',
						page: state.page,
						per_page: 10,
					} )
					.then( renderList )
			);
			const reloadAll = () => {
				summary.refresh();
				return list.refresh();
			};

			function renderSummary( s ) {
				const labels = s.action_labels || {};
				const chips = [
					ui.button( {
						label: sprintf( /* translators: %s: count. */ __( 'All · %s', 'searchpilot-pro' ), fmt.number( s.total ) ),
						size: 'small',
						variant: state.action ? null : 'primary',
						onClick: () => setFilter( { action: '' } ),
					} ),
				].concat(
					ACTIONS.map( ( code ) =>
						ui.button( {
							label: ( labels[ code ] || code ) + ' · ' + fmt.number( ( s.by_action || {} )[ code ] || 0 ),
							icon: ACTION_ICONS[ code ],
							size: 'small',
							variant: state.action === code ? 'primary' : null,
							disabled: ! ( s.by_action || {} )[ code ] && state.action !== code,
							onClick: () => setFilter( { action: code } ),
						} )
					)
				);
				const last = s.last_run;
				return ui.card( {
					title: __( 'Recommended actions', 'searchpilot-pro' ),
					subtitle: last && last.at ? sprintf( /* translators: 1: relative time, 2: engine. */ __( 'Last analysed %1$s from %2$s data', 'searchpilot-pro' ), fmt.relative( last.at ), last.engine === 'bing' ? 'Bing' : 'Google Search Console' ) : __( 'Opportunities are found after each search-data sync', 'searchpilot-pro' ),
					body: h( 'div', { class: 'spp-row' }, chips ),
				} );
			}

			function renderList( res ) {
				const items = res.items || [];
				if ( ! items.length ) {
					const filtered = state.action || state.severity || state.type || state.search || state.post_id;
					return ui.card( {
						body: ui.empty( {
							icon: 'sparkle',
							title: filtered ? __( 'No opportunities match these filters', 'searchpilot-pro' ) : __( 'No open opportunities', 'searchpilot-pro' ),
							text: filtered
								? __( 'Try another action or clear the filters.', 'searchpilot-pro' )
								: __( 'SearchPilot analyses your Search Console data after every sync. When it finds pages close to the top, low click-through rates, declines or competing pages, they show up here with the evidence and a recommended action.', 'searchpilot-pro' ),
							action: filtered ? ui.button( { label: __( 'Clear filters', 'searchpilot-pro' ), onClick: () => setFilter( { action: '', severity: '', type: '', search: '', post_id: 0 } ) } ) : ui.button( { label: __( 'Search Performance', 'searchpilot-pro' ), href: SPP.pageUrl( 'performance' ) } ),
						} ),
					} );
				}
				return h(
					'div',
					null,
					h( 'div', null, items.map( ( o ) => card( o, reloadAll ) ) ),
					ui.pagination( {
						page: res.page,
						pages: res.pages,
						total: res.total,
						perPage: res.per_page,
						onPage: ( p ) => {
							state.page = p;
							list.refresh();
						},
					} )
				);
			}

			function setFilter( patch ) {
				Object.assign( state, patch, { page: 1 } );
				SPP.query.set( { action: state.action, severity: state.severity, type: state.type, s: state.search, post_id: state.post_id || '' } );
				if ( summaryData ) {
					SPP.mount( summary.el, renderSummary( summaryData ) );
				}
				return list.refresh();
			}

			const typeOptions = [ { value: '', label: __( 'All opportunity types', 'searchpilot-pro' ) } ];
			const toolbar = h( 'div' );
			api.get( '/opportunities/summary' )
				.then( ( s ) => {
					Object.keys( s.type_labels || {} ).forEach( ( t ) => typeOptions.push( { value: t, label: s.type_labels[ t ] + ' (' + fmt.number( ( s.by_type || {} )[ t ] || 0 ) + ')' } ) );
					SPP.mount(
						toolbar,
						ui.toolbar(
							ui.search( { value: state.search, placeholder: __( 'Search pages or queries…', 'searchpilot-pro' ), onSearch: ( v ) => setFilter( { search: v } ) } ),
							ui.select( {
								label: __( 'Severity', 'searchpilot-pro' ),
								value: state.severity,
								options: [
									{ value: '', label: __( 'All severities', 'searchpilot-pro' ) },
									{ value: 'high', label: __( 'High', 'searchpilot-pro' ) },
									{ value: 'medium', label: __( 'Medium', 'searchpilot-pro' ) },
									{ value: 'opportunity', label: __( 'Opportunity', 'searchpilot-pro' ) },
								],
								onChange: ( v ) => setFilter( { severity: v } ),
							} ),
							ui.select( { label: __( 'Type', 'searchpilot-pro' ), value: state.type, options: typeOptions, onChange: ( v ) => setFilter( { type: v } ) } ),
							state.post_id ? ui.button( { label: __( 'Showing one post — show all', 'searchpilot-pro' ), size: 'small', variant: 'ghost', icon: 'close', onClick: () => setFilter( { post_id: 0 } ) } ) : null
						)
					);
				} )
				.catch( () => SPP.mount( toolbar, null ) );

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Content Opportunities', 'searchpilot-pro' ),
					subtitle: __( 'Improvements found in your search data, ordered by severity and the clicks at stake.', 'searchpilot-pro' ),
					actions: [
						ui.button( {
							label: __( 'Export CSV', 'searchpilot-pro' ),
							icon: 'download',
							onClick: () =>
								api.get( '/reports/export', { type: 'opportunities' } ).then( ( res ) => {
									if ( ! res.rows.length ) {
										ui.toast( __( 'Nothing to export yet.', 'searchpilot-pro' ), 'warning' );
										return;
									}
									SPP.util.download( res.filename, SPP.util.toCsv( res.rows, res.columns ), 'text/csv;charset=utf-8' );
								} ),
						} ),
						ui.button( { icon: 'refresh', title: __( 'Refresh', 'searchpilot-pro' ), onClick: reloadAll } ),
					],
				} ),
				ui.stack(
					ui.notice( {
						tone: 'info',
						icon: 'shield',
						title: __( 'SearchPilot never rewrites your content automatically.', 'searchpilot-pro' ),
						text: __( 'Titles and descriptions change only when you apply them here, in the field your SEO plugin uses. Every change is logged and can be undone; the rest are recommendations for your editors.', 'searchpilot-pro' ),
					} ),
					summary.el,
					toolbar,
					list.el
				)
			);
		},
	} );
} )( window.wp, window.SPP );

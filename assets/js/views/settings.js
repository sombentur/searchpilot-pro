/**
 * Settings view.
 *
 * Other scripts can add tabs: window.SPP.settingsTabs.push( { id, label, render( container, ctx ) } ).
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, api } = SPP;
	const field = ui.field;

	SPP.settingsTabs = SPP.settingsTabs || [];

	/** Reads a dot key from a nested object. */
	function read( obj, key ) {
		return key.split( '.' ).reduce( ( o, k ) => ( o && typeof o === 'object' ? o[ k ] : undefined ), obj );
	}

	SPP.registerView( 'settings', {
		mount( el ) {
			const state = { data: null, draft: {}, tab: SPP.query.get( 'tab', 'general' ), errors: {} };
			const body = h( 'div' );
			const saveBar = h( 'div', { class: 'spp-card', style: { position: 'sticky', bottom: '0', zIndex: 5, marginTop: '18px' } } );

			const value = ( key ) => ( Object.prototype.hasOwnProperty.call( state.draft, key ) ? state.draft[ key ] : read( state.data.settings, key ) );
			const set = ( key, v ) => {
				state.draft[ key ] = v;
				renderSaveBar();
			};
			const errorFor = ( key ) => state.errors[ key ] || null;

			/* ---------- building blocks ---------- */

			const withError = ( key, node ) => {
				const message = errorFor( key );
				if ( message && node && node.querySelector ) {
					const holder = node.querySelector( '.spp-field-text' ) || node;
					holder.appendChild( h( 'div', { class: 'spp-field-error', role: 'alert' }, message ) );
				}
				return node;
			};
			const toggle = ( key, label, help ) => withError( key, field.toggle( { label, help, row: true, checked: !! value( key ), onChange: ( v ) => set( key, v ) } ) );
			const text = ( key, label, help, opts ) =>
				field.text( Object.assign( { label, help, row: true, wide: true, value: value( key ), onInput: ( v ) => set( key, v ), error: errorFor( key ) }, opts || {} ) );
			const number = ( key, label, help, opts ) => {
				const def = state.data.schema[ key ] || {};
				return withError( key, field.number( Object.assign( { label, help, row: true, value: value( key ), min: def.min, max: def.max, onInput: ( v ) => set( key, v === '' ? def.default : v ) }, opts || {} ) ) );
			};
			const select = ( key, label, options, help ) => withError( key, field.select( { label, help, row: true, value: value( key ), options, onChange: ( v ) => set( key, v ) } ) );
			const textarea = ( key, label, help, opts ) => withError( key, field.textarea( Object.assign( { label, help, row: true, wide: true, value: value( key ), onInput: ( v ) => set( key, v ) }, opts || {} ) ) );
			const listText = ( key, label, help, sep ) =>
				withError( key, field.textarea( {
					label,
					help,
					row: true,
					wide: true,
					rows: 3,
					value: ( value( key ) || [] ).join( sep || '\n' ),
					onInput: ( v ) =>
						set(
							key,
							v
								.split( sep === ', ' ? /[\s,;]+/ : /\r?\n/ )
								.map( ( s ) => s.trim() )
								.filter( Boolean )
						),
				} ) );

			/* ---------- tabs ---------- */

			const tabs = [
				{ id: 'general', label: __( 'General', 'searchpilot-pro' ) },
				{ id: 'content', label: __( 'Content types', 'searchpilot-pro' ) },
				{ id: 'meta', label: __( 'Titles & meta', 'searchpilot-pro' ) },
				{ id: 'ownership', label: __( 'SEO plugin compatibility', 'searchpilot-pro' ) },
				{ id: 'indexing', label: __( 'Indexing', 'searchpilot-pro' ) },
				{ id: 'alerts', label: __( 'Alerts & reports', 'searchpilot-pro' ) },
				{ id: 'advanced', label: __( 'Advanced', 'searchpilot-pro' ) },
			];

			const renderGeneral = () =>
				ui.stack(
					ui.card( {
						title: __( 'Automation', 'searchpilot-pro' ),
						subtitle: __( 'How much SearchPilot does on its own. Fine-tune each task on the Automation screen.', 'searchpilot-pro' ),
						body: [
							select( 'general.mode', __( 'Operating mode', 'searchpilot-pro' ), [
								{ value: 'manual', label: __( 'Manual — recommendations only', 'searchpilot-pro' ) },
								{ value: 'assisted', label: __( 'Assisted — safe technical work automatic, content changes need approval', 'searchpilot-pro' ) },
								{ value: 'autopilot', label: __( 'Autopilot — maximum safe automation', 'searchpilot-pro' ) },
							] ),
							toggle( 'general.environment_guard', __( 'Environment guard', 'searchpilot-pro' ), __( 'Hold every search-engine submission unless this is the production site (wp_get_environment_type). Local and private addresses are always held.', 'searchpilot-pro' ) ),
						],
					} ),
					ui.card( {
						title: __( 'Interface', 'searchpilot-pro' ),
						body: [ toggle( 'general.admin_bar', __( 'Admin bar menu', 'searchpilot-pro' ), __( 'Shows open critical/high issues and quick links in the admin bar.', 'searchpilot-pro' ) ) ],
					} ),
					ui.card( {
						title: __( 'Data & privacy', 'searchpilot-pro' ),
						body: [
							select( 'general.log_level', __( 'Log level', 'searchpilot-pro' ), [
								{ value: 'debug', label: __( 'Debug (verbose)', 'searchpilot-pro' ) },
								{ value: 'info', label: __( 'Info', 'searchpilot-pro' ) },
								{ value: 'warning', label: __( 'Warnings and errors', 'searchpilot-pro' ) },
								{ value: 'error', label: __( 'Errors only', 'searchpilot-pro' ) },
							] ),
							number( 'general.log_retention_days', __( 'Keep logs for', 'searchpilot-pro' ), '', { suffix: __( 'days', 'searchpilot-pro' ) } ),
							number( 'general.history_retention_days', __( 'Keep activity & submission history for', 'searchpilot-pro' ), __( 'Changes older than this can no longer be undone.', 'searchpilot-pro' ), { suffix: __( 'days', 'searchpilot-pro' ) } ),
							toggle( 'general.delete_data_on_uninstall', __( 'Delete all data on uninstall', 'searchpilot-pro' ), __( 'Removes SearchPilot tables, settings and SEO fields when the plugin is deleted. API credentials are always removed on uninstall.', 'searchpilot-pro' ) ),
						],
					} )
				);

			const renderContent = () => {
				const postTypes = state.data.post_types || {};
				const taxes = state.data.taxonomies || {};
				const ptValue = () => Object.assign( {}, read( state.data.settings, 'content.post_types' ) || {}, state.draft[ 'content.post_types' ] || {} );
				const taxValue = () => Object.assign( {}, read( state.data.settings, 'content.taxonomies' ) || {}, state.draft[ 'content.taxonomies' ] || {} );
				const schemaOptions = [ 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'none' ].map( ( v ) => ( { value: v, label: v === 'none' ? __( 'No schema', 'searchpilot-pro' ) : v } ) );

				const setPt = ( name, prop, v ) => {
					const all = ptValue();
					all[ name ] = Object.assign( { index: postTypes[ name ].index, sitemap: postTypes[ name ].sitemap, schema: postTypes[ name ].schema }, all[ name ] || {}, { [ prop ]: v } );
					set( 'content.post_types', all );
				};
				const setTax = ( name, prop, v ) => {
					const all = taxValue();
					all[ name ] = Object.assign( { index: taxes[ name ].index, sitemap: taxes[ name ].sitemap }, all[ name ] || {}, { [ prop ]: v } );
					set( 'content.taxonomies', all );
				};
				const cell = ( checked, onChange, label ) => {
					const cb = h( 'input', { type: 'checkbox', checked, 'aria-label': label || null } );
					cb.addEventListener( 'change', () => onChange( cb.checked ) );
					return h( 'label', { class: 'spp-toggle' }, cb, h( 'span', { class: 'spp-toggle-track' } ) );
				};
				const effective = ( map, name, prop, fallback ) => {
					const v = map[ name ] && map[ name ][ prop ];
					return v === undefined ? fallback : v;
				};

				return ui.stack(
					ui.card( {
						title: __( 'Post types', 'searchpilot-pro' ),
						subtitle: __( 'Which content search engines should index, list in sitemaps and describe with structured data.', 'searchpilot-pro' ),
						flush: true,
						body: ui.table( {
							columns: [
								{ key: 'label', label: __( 'Post type', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'span', { class: 'spp-cell-title' }, r.label ), h( 'span', { class: 'spp-cell-sub' }, r.name ) ) },
								/* translators: %s: post type name. */
								{ key: 'index', label: __( 'Index', 'searchpilot-pro' ), align: 'center', render: ( r ) => cell( effective( ptValue(), r.name, 'index', r.index ), ( v ) => setPt( r.name, 'index', v ), sprintf( __( 'Index %s', 'searchpilot-pro' ), r.label ) ) },
								/* translators: %s: post type name. */
								{ key: 'sitemap', label: __( 'In sitemap', 'searchpilot-pro' ), align: 'center', render: ( r ) => cell( effective( ptValue(), r.name, 'sitemap', r.sitemap ), ( v ) => setPt( r.name, 'sitemap', v ), sprintf( __( 'List %s in the sitemap', 'searchpilot-pro' ), r.label ) ) },
								{
									key: 'schema',
									label: __( 'Schema type', 'searchpilot-pro' ),
									/* translators: %s: post type name. */
									render: ( r ) => ui.select( { label: sprintf( __( 'Schema type for %s', 'searchpilot-pro' ), r.label ), value: effective( ptValue(), r.name, 'schema', r.schema ), options: schemaOptions, onChange: ( v ) => setPt( r.name, 'schema', v ) } ),
								},
							],
							rows: Object.keys( postTypes ).map( ( name ) => Object.assign( { name, id: name }, postTypes[ name ] ) ),
						} ),
					} ),
					ui.card( {
						title: __( 'Taxonomies', 'searchpilot-pro' ),
						flush: true,
						body: ui.table( {
							columns: [
								{ key: 'label', label: __( 'Taxonomy', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'span', { class: 'spp-cell-title' }, r.label ), h( 'span', { class: 'spp-cell-sub' }, r.name ) ) },
								/* translators: %s: taxonomy name. */
								{ key: 'index', label: __( 'Index', 'searchpilot-pro' ), align: 'center', render: ( r ) => cell( effective( taxValue(), r.name, 'index', r.index ), ( v ) => setTax( r.name, 'index', v ), sprintf( __( 'Index %s', 'searchpilot-pro' ), r.label ) ) },
								/* translators: %s: taxonomy name. */
								{ key: 'sitemap', label: __( 'In sitemap', 'searchpilot-pro' ), align: 'center', render: ( r ) => cell( effective( taxValue(), r.name, 'sitemap', r.sitemap ), ( v ) => setTax( r.name, 'sitemap', v ), sprintf( __( 'List %s in the sitemap', 'searchpilot-pro' ), r.label ) ) },
							],
							rows: Object.keys( taxes ).map( ( name ) => Object.assign( { name, id: name }, taxes[ name ] ) ),
						} ),
					} ),
					ui.card( {
						title: __( 'Archives & special pages', 'searchpilot-pro' ),
						body: [
							toggle( 'content.authors_index', __( 'Index author archives', 'searchpilot-pro' ), __( 'Recommended for multi-author sites. Authors without published posts are never indexed.', 'searchpilot-pro' ) ),
							toggle( 'content.authors_sitemap', __( 'Author archives in sitemap', 'searchpilot-pro' ) ),
							toggle( 'content.date_archives_noindex', __( 'Noindex date archives', 'searchpilot-pro' ), __( 'Date archives duplicate your category and post pages.', 'searchpilot-pro' ) ),
							toggle( 'content.search_noindex', __( 'Noindex internal search results', 'searchpilot-pro' ), __( 'Google asks sites not to let search result pages be indexed.', 'searchpilot-pro' ) ),
							toggle( 'content.paginated_noindex', __( 'Noindex paginated archives (page 2+)', 'searchpilot-pro' ), __( 'Usually leave off: paginated pages help crawlers discover older posts.', 'searchpilot-pro' ) ),
							toggle( 'content.attachment_redirect', __( 'Redirect attachment pages to the file', 'searchpilot-pro' ), __( 'Attachment pages are thin content. WordPress 6.4+ disables them by default.', 'searchpilot-pro' ) ),
							number( 'content.thin_words', __( 'Thin content threshold', 'searchpilot-pro' ), __( 'Pages with fewer words are flagged as thin content in audits.', 'searchpilot-pro' ), { suffix: __( 'words', 'searchpilot-pro' ) } ),
							listText( 'content.excluded_ids', __( 'Always exclude these post IDs', 'searchpilot-pro' ), __( 'Comma-separated IDs kept out of sitemaps, submissions and audits.', 'searchpilot-pro' ), ', ' ),
						],
					} )
				);
			};

			const renderMeta = () => {
				const owner = ( f ) => ( ( state.data.compat.features || {} )[ f ] || {} ).owner;
				const ownedElsewhere = [ 'title', 'description', 'social' ].filter( ( f ) => owner( f ) && owner( f ) !== 'searchpilot' && owner( f ) !== 'off' );
				const vars = h( 'p', { class: 'spp-field-help' }, __( 'Variables:', 'searchpilot-pro' ), ' ', [ '%title%', '%sitename%', '%sep%', '%tagline%', '%category%', '%excerpt%', '%author%', '%term%', '%archive%', '%search%', '%currentyear%', '%page%' ].map( ( v ) => [ h( 'code', null, v ), ' ' ] ) );
				return ui.stack(
					ownedElsewhere.length
						? ui.notice( {
								tone: 'info',
								title: __( 'Some of these settings are handled by your SEO plugin', 'searchpilot-pro' ),
								text: sprintf( /* translators: %s: plugin name. */ __( '%s currently outputs titles/meta/social tags, so SearchPilot does not print its own (no duplicates). The values below apply when SearchPilot owns these features.', 'searchpilot-pro' ), SPP.boot.companionName || __( 'Another plugin', 'searchpilot-pro' ) ),
						  } )
						: null,
					ui.card( {
						title: __( 'Title templates', 'searchpilot-pro' ),
						body: [
							vars,
							text( 'meta.separator', __( 'Separator', 'searchpilot-pro' ), '', { maxLength: 5 } ),
							text( 'meta.title_post', __( 'Posts', 'searchpilot-pro' ) ),
							text( 'meta.title_page', __( 'Pages', 'searchpilot-pro' ) ),
							text( 'meta.title_term', __( 'Category & tag archives', 'searchpilot-pro' ) ),
							text( 'meta.title_author', __( 'Author archives', 'searchpilot-pro' ) ),
							text( 'meta.title_archive', __( 'Other archives', 'searchpilot-pro' ) ),
							text( 'meta.title_search', __( 'Search results', 'searchpilot-pro' ) ),
							text( 'meta.title_404', __( '404 page', 'searchpilot-pro' ) ),
						],
					} ),
					ui.card( {
						title: __( 'Home page', 'searchpilot-pro' ),
						body: [
							text( 'meta.home_title', __( 'Home title', 'searchpilot-pro' ), __( 'Leave empty to use “Site name – Tagline”.', 'searchpilot-pro' ), { counter: [ 30, 60 ] } ),
							textarea( 'meta.home_description', __( 'Home meta description', 'searchpilot-pro' ), __( 'Leave empty to use the tagline.', 'searchpilot-pro' ), { counter: [ 120, 156 ], rows: 3 } ),
							toggle( 'meta.auto_description', __( 'Automatic descriptions', 'searchpilot-pro' ), __( 'When a post has no meta description, output one from its excerpt or opening text (not saved to the post).', 'searchpilot-pro' ) ),
						],
					} ),
					ui.card( {
						title: __( 'Social sharing', 'searchpilot-pro' ),
						body: [
							toggle( 'meta.og_enabled', __( 'Open Graph tags', 'searchpilot-pro' ), __( 'Used by Facebook, LinkedIn, WhatsApp and others.', 'searchpilot-pro' ) ),
							toggle( 'meta.twitter_enabled', __( 'X/Twitter card tags', 'searchpilot-pro' ) ),
							text( 'meta.twitter_site', __( 'X/Twitter username', 'searchpilot-pro' ), __( 'Without the @, e.g. yoursite.', 'searchpilot-pro' ) ),
							text( 'meta.facebook_app_id', __( 'Facebook App ID', 'searchpilot-pro' ), __( 'Optional.', 'searchpilot-pro' ) ),
							field.media( { label: __( 'Default sharing image', 'searchpilot-pro' ), help: __( 'Used when a page has no image. 1200 × 630 px recommended.', 'searchpilot-pro' ), row: true, value: value( 'meta.default_image' ), url: state.data.image_url, onChange: ( id ) => set( 'meta.default_image', id ) } ),
							toggle( 'meta.max_image_preview_large', __( 'Allow large image previews', 'searchpilot-pro' ), __( 'Adds max-image-preview:large, which Google Discover needs for large images.', 'searchpilot-pro' ) ),
						],
					} )
				);
			};

			const renderOwnership = () => {
				const compat = state.data.compat || {};
				const plugins = compat.plugins || {};
				const features = compat.features || {};
				const choosable = [ 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'sitemap', 'breadcrumbs', 'redirects' ];
				const ownerLabel = ( id ) => ( id === 'searchpilot' ? 'SearchPilot' : id === 'off' ? __( 'Off', 'searchpilot-pro' ) : ( plugins[ id ] && plugins[ id ].name ) || id );
				return ui.stack(
					ui.notice( {
						tone: 'gold',
						title: __( 'SearchPilot never outputs the same thing twice', 'searchpilot-pro' ),
						text: __( 'With “Automatic”, a feature another active SEO plugin already handles stays with that plugin and SearchPilot works alongside it (indexing, monitoring, analytics, internal links). Choose “SearchPilot” to take a feature over — possible only where the other plugin’s output can be switched off safely; otherwise switch it off in that plugin first.', 'searchpilot-pro' ),
					} ),
					ui.card( {
						title: __( 'Detected plugins', 'searchpilot-pro' ),
						body: Object.keys( plugins ).length
							? h(
									'div',
									{ class: 'spp-status-grid' },
									Object.keys( plugins ).map( ( id ) =>
										h( 'div', { class: 'spp-status-item' }, ui.dot( 'navy' ), h( 'div', null, h( 'div', { class: 'spp-status-title' }, plugins[ id ].name + ( plugins[ id ].version ? ' ' + plugins[ id ].version : '' ) ), h( 'div', { class: 'spp-status-text' }, ( plugins[ id ].features || [] ).map( ( f ) => ( features[ f ] || {} ).label || f ).join( ', ' ) ) ) )
									)
							  )
							: ui.empty( { icon: 'check', title: __( 'No other SEO plugin detected', 'searchpilot-pro' ), text: __( 'SearchPilot handles every feature.', 'searchpilot-pro' ) } ),
					} ),
					ui.card( {
						title: __( 'Feature ownership', 'searchpilot-pro' ),
						flush: true,
						body: ui.table( {
							columns: [
								{ key: 'label', label: __( 'Feature', 'searchpilot-pro' ), render: ( r ) => h( 'span', { class: 'spp-strong' }, r.label ) },
								{
									key: 'owner',
									label: __( 'Handled by', 'searchpilot-pro' ),
									render: ( r ) => ui.badge( ownerLabel( r.owner ), r.owner === 'searchpilot' ? 'navy' : r.owner === 'off' ? 'outline' : 'info' ),
								},
								{ key: 'providers', label: __( 'Also available in', 'searchpilot-pro' ), render: ( r ) => ( r.providers || [] ).map( ownerLabel ).join( ', ' ) || '—' },
								{
									key: 'setting',
									label: __( 'Preference', 'searchpilot-pro' ),
									render: ( r ) =>
										choosable.indexOf( r.id ) === -1
											? h( 'span', { class: 'spp-muted spp-small' }, __( 'Automatic', 'searchpilot-pro' ) )
											: ui.select( {
													/* translators: %s: feature name. */
													label: sprintf( __( 'Who handles %s', 'searchpilot-pro' ), r.label ),
													value: value( 'ownership.' + r.id ),
													options: [
														{ value: 'auto', label: __( 'Automatic', 'searchpilot-pro' ) },
														{ value: 'searchpilot', label: __( 'SearchPilot', 'searchpilot-pro' ) },
														{ value: 'off', label: __( 'Off', 'searchpilot-pro' ) },
													],
													onChange: ( v ) => set( 'ownership.' + r.id, v ),
											  } ),
								},
								{
									key: 'note',
									label: '',
									render: ( r ) => {
										const pref = value( 'ownership.' + r.id );
										if ( pref === 'searchpilot' && ( r.providers || [] ).length && ! r.suppressible ) {
											return ui.badge( __( 'Switch it off in the other plugin first', 'searchpilot-pro' ), 'high' );
										}
										return r.conflict ? ui.badge( __( 'Two plugins provide this', 'searchpilot-pro' ), 'medium' ) : h( 'span' );
									},
								},
							],
							rows: Object.keys( features ).map( ( id ) => Object.assign( { id }, features[ id ] ) ),
						} ),
					} )
				);
			};

			const renderIndexing = () =>
				ui.stack(
					ui.notice( {
						tone: 'info',
						text: __( 'Connections to Google Search Console, Bing Webmaster Tools and IndexNow are managed on the Search Engines screen.', 'searchpilot-pro' ),
						actions: [ ui.button( { label: __( 'Open Search Engines', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'engines' ) } ) ],
					} ),
					ui.card( {
						title: __( 'When to notify search engines', 'searchpilot-pro' ),
						body: [
							toggle( 'indexing.notify_publish', __( 'New content is published', 'searchpilot-pro' ) ),
							toggle( 'indexing.notify_update', __( 'Content changes significantly', 'searchpilot-pro' ) ),
							toggle( 'indexing.notify_delete', __( 'Content is deleted, unpublished, redirected or set to noindex', 'searchpilot-pro' ) ),
							toggle( 'indexing.notify_terms', __( 'Categories and tags are created or renamed', 'searchpilot-pro' ) ),
							number( 'indexing.change_threshold', __( 'Significant change sensitivity', 'searchpilot-pro' ), __( 'How much the text must change before an update is re-submitted (text-fingerprint difference, 0–64; higher = only bigger edits). Title, URL, description and indexing changes always count.', 'searchpilot-pro' ) ),
							number( 'indexing.resubmit_cooldown_minutes', __( 'Resubmit cool-down', 'searchpilot-pro' ), __( 'The same URL is not sent again within this time.', 'searchpilot-pro' ), { suffix: __( 'minutes', 'searchpilot-pro' ) } ),
						],
					} ),
					ui.card( {
						title: __( 'Monitoring', 'searchpilot-pro' ),
						body: [
							number( 'indexing.inspection_daily_budget', __( 'Search Console URL inspections per day', 'searchpilot-pro' ), __( 'Google allows 2,000 per property per day; SearchPilot spreads them over the day and prioritises new and changed pages.', 'searchpilot-pro' ) ),
							number( 'indexing.crawl_batch', __( 'Pages self-checked per run', 'searchpilot-pro' ), __( 'SearchPilot re-checks your own pages in small hourly batches so the site is never slowed down.', 'searchpilot-pro' ) ),
							number( 'indexing.recheck_days', __( 'Re-check every page at least every', 'searchpilot-pro' ), '', { suffix: __( 'days', 'searchpilot-pro' ) } ),
							toggle( 'indexing.monitor_pagination', __( 'Check paginated archives', 'searchpilot-pro' ), __( 'Verifies canonical tags on page 2 of your main archives.', 'searchpilot-pro' ) ),
							text( 'indexing.origin_ip', __( 'Origin server IP for self-checks', 'searchpilot-pro' ), __( 'Optional. Behind Cloudflare or another CDN with bot protection, enter your server’s IP so self-checks reach WordPress directly.', 'searchpilot-pro' ) ),
						],
					} )
				);

			const renderAlerts = () =>
				ui.stack(
					ui.card( {
						title: __( 'Critical alerts', 'searchpilot-pro' ),
						subtitle: __( 'Site set to noindex, sitemap down, robots.txt blocking, Search Console disconnected, traffic or indexing drops, mass errors…', 'searchpilot-pro' ),
						body: [
							toggle( 'alerts.admin_notices', __( 'Show critical problems as dashboard notices', 'searchpilot-pro' ) ),
							toggle( 'alerts.email', __( 'E-mail alerts', 'searchpilot-pro' ) ),
							listText( 'alerts.recipients', __( 'Recipients', 'searchpilot-pro' ), __( 'Comma-separated. Leave empty to use the site admin e-mail.', 'searchpilot-pro' ), ', ' ),
							select( 'alerts.min_severity', __( 'E-mail me about', 'searchpilot-pro' ), [
								{ value: 'critical', label: __( 'Critical problems only', 'searchpilot-pro' ) },
								{ value: 'high', label: __( 'Critical and high', 'searchpilot-pro' ) },
								{ value: 'medium', label: __( 'Critical, high and medium', 'searchpilot-pro' ) },
							] ),
						],
					} ),
					ui.card( {
						title: __( 'Thresholds', 'searchpilot-pro' ),
						body: [
							number( 'alerts.traffic_drop_percent', __( 'Organic traffic drop alert', 'searchpilot-pro' ), __( 'Week-over-week drop in Google clicks.', 'searchpilot-pro' ), { suffix: '%' } ),
							number( 'alerts.index_drop_percent', __( 'Indexed pages drop alert', 'searchpilot-pro' ), '', { suffix: '%' } ),
							number( 'alerts.error_spike', __( 'Error spike (404/5xx per hour)', 'searchpilot-pro' ) ),
						],
					} ),
					ui.card( {
						title: __( 'Performance reports', 'searchpilot-pro' ),
						body: [
							select( 'alerts.report', __( 'E-mail report', 'searchpilot-pro' ), [
								{ value: 'off', label: __( 'Off', 'searchpilot-pro' ) },
								{ value: 'weekly', label: __( 'Weekly', 'searchpilot-pro' ) },
								{ value: 'monthly', label: __( 'Monthly', 'searchpilot-pro' ) },
							] ),
						],
					} )
				);

			const renderAdvanced = () => {
				const system = SPP.region( () =>
					api.get( '/system' ).then( ( s ) => {
						const env = s.environment || {};
						const jobs = ( s.scheduler && s.scheduler.jobs ) || {};
						return ui.stack(
							ui.card( {
								title: __( 'System status', 'searchpilot-pro' ),
								actions: [
									ui.button( {
										label: __( 'Repair installation', 'searchpilot-pro' ),
										size: 'small',
										onClick: () => api.post( '/system/repair' ).then( () => ui.toast( __( 'Tables, schedules and rewrite rules were refreshed.', 'searchpilot-pro' ) ) ),
									} ),
								],
								body: [
									s.tables && s.tables.missing && s.tables.missing.length ? ui.notice( { tone: 'critical', title: __( 'Missing database tables', 'searchpilot-pro' ), text: s.tables.missing.join( ', ' ) } ) : null,
									s.credentials && s.credentials.error ? ui.notice( { tone: 'critical', text: s.credentials.error } ) : null,
									ui.dl( [
										[ __( 'Plugin version', 'searchpilot-pro' ), s.version + ' (DB ' + s.db_version + ')' ],
										[ 'WordPress', env.wordpress ],
										[ 'PHP', env.php + ' · ' + sprintf( /* translators: %s: memory limit. */ __( 'memory %s', 'searchpilot-pro' ), env.memory_limit ) ],
										[ __( 'Database', 'searchpilot-pro' ), env.mysql ],
										[ __( 'Environment', 'searchpilot-pro' ), env.environment + ( env.local_host ? ' · ' + __( 'local address', 'searchpilot-pro' ) : '' ) ],
										[ __( 'Submissions to search engines', 'searchpilot-pro' ), env.submissions_allowed ? ui.badge( __( 'Allowed', 'searchpilot-pro' ), 'passed' ) : ui.badge( __( 'Held', 'searchpilot-pro' ), 'high' ) ],
										[ __( 'Background jobs', 'searchpilot-pro' ), ( s.scheduler.backend === 'action_scheduler' ? 'Action Scheduler' : 'WP-Cron' ) + ( env.cron_disabled ? ' · ' + __( 'WP-Cron disabled (server cron required)', 'searchpilot-pro' ) : '' ) + ' · ' + ( s.scheduler.healthy ? __( 'healthy', 'searchpilot-pro' ) : __( 'not running recently', 'searchpilot-pro' ) ) ],
										[ __( 'Persistent object cache', 'searchpilot-pro' ), env.object_cache ? __( 'Yes', 'searchpilot-pro' ) : __( 'No', 'searchpilot-pro' ) ],
										[ __( 'Credential encryption', 'searchpilot-pro' ), env.crypto ? __( 'Available', 'searchpilot-pro' ) : __( 'Unavailable', 'searchpilot-pro' ) ],
										[ 'HTTPS', env.https ? __( 'Yes', 'searchpilot-pro' ) : __( 'No', 'searchpilot-pro' ) ],
									] ),
								],
							} ),
							ui.card( {
								title: __( 'Background jobs', 'searchpilot-pro' ),
								flush: true,
								body: ui.table( {
									columns: [
										{ key: 'job', label: __( 'Job', 'searchpilot-pro' ), render: ( r ) => h( 'code', null, r.job ) },
										{ key: 'last', label: __( 'Last run', 'searchpilot-pro' ), render: ( r ) => SPP.fmt.relative( r.last_run ) },
										{ key: 'next', label: __( 'Next run', 'searchpilot-pro' ), render: ( r ) => ( r.next_run ? SPP.fmt.relative( r.next_run ) : '—' ) },
										{
											key: 'run',
											label: '',
											align: 'right',
											render: ( r ) =>
												ui.button( {
													label: __( 'Run now', 'searchpilot-pro' ),
													size: 'small',
													onClick: () => api.post( '/system/run', { job: r.job.replace( 'searchpilot_', '' ) } ).then( ( res ) => ui.toast( sprintf( /* translators: %s: duration. */ __( 'Finished in %s', 'searchpilot-pro' ), SPP.fmt.duration( res.duration_ms ) ) ) ),
												} ),
										},
									],
									rows: Object.keys( jobs ).map( ( job ) => Object.assign( { id: job, job }, jobs[ job ] ) ),
								} ),
							} )
						);
					} )
				);
				return ui.stack(
					ui.card( {
						title: __( 'Search analytics', 'searchpilot-pro' ),
						body: [
							toggle( 'analytics.fresh_data', __( 'Include fresh (preliminary) Search Console data', 'searchpilot-pro' ), __( 'Shows the last 1–2 days sooner; numbers may still change.', 'searchpilot-pro' ) ),
							toggle( 'analytics.bing', __( 'Import Bing Webmaster data', 'searchpilot-pro' ) ),
							number( 'analytics.min_impressions', __( 'Minimum impressions for opportunities', 'searchpilot-pro' ), __( 'Pages and queries below this are ignored when looking for opportunities.', 'searchpilot-pro' ) ),
						],
					} ),
					system.el
				);
			};

			/* ---------- save bar ---------- */

			const renderSaveBar = () => {
				const count = Object.keys( state.draft ).length;
				SPP.mount(
					saveBar,
					h(
						'div',
						{ class: 'spp-card-body spp-row is-between' },
						h( 'span', { class: 'spp-muted' }, count ? sprintf( /* translators: %d: number of changed settings. */ _n( '%d unsaved change', '%d unsaved changes', count, 'searchpilot-pro' ), count ) : __( 'All changes saved. Every settings change is recorded and can be undone from Automation → Activity.', 'searchpilot-pro' ) ),
						h(
							'div',
							{ class: 'spp-row' },
							count
								? ui.button( {
										label: __( 'Discard', 'searchpilot-pro' ),
										variant: 'ghost',
										onClick: () => {
											state.draft = {};
											render();
										},
								  } )
								: null,
							ui.button( {
								label: __( 'Save changes', 'searchpilot-pro' ),
								variant: 'primary',
								disabled: ! count,
								onClick: save,
							} )
						)
					)
				);
			};

			const save = () =>
				api.post( '/settings', { settings: state.draft } ).then( ( res ) => {
					state.errors = res.errors || {};
					const failed = Object.keys( state.errors );
					state.data = res;
					state.draft = failed.reduce( ( d, k ) => Object.assign( d, { [ k ]: state.draft[ k ] } ), {} );
					if ( failed.length ) {
						ui.toast( __( 'Some values were not saved — see the highlighted fields.', 'searchpilot-pro' ), 'error' );
					} else {
						ui.toast( __( 'Settings saved', 'searchpilot-pro' ) );
					}
					if ( SPP.boot.mode !== read( res.settings, 'general.mode' ) ) {
						SPP.boot.mode = read( res.settings, 'general.mode' );
					}
					render();
				} );

			/* ---------- render ---------- */

			const allTabs = () => tabs.concat( SPP.settingsTabs.map( ( t ) => ( { id: t.id, label: t.label } ) ) );

			const render = () => {
				const extra = SPP.settingsTabs.find( ( t ) => t.id === state.tab );
				let content;
				if ( extra ) {
					content = h( 'div' );
					extra.render( content, { data: state.data } );
				} else {
					content = ( {
						general: renderGeneral,
						content: renderContent,
						meta: renderMeta,
						ownership: renderOwnership,
						indexing: renderIndexing,
						alerts: renderAlerts,
						advanced: renderAdvanced,
					}[ state.tab ] || renderGeneral )();
				}
				SPP.mount(
					body,
					ui.tabs( {
						tabs: allTabs(),
						active: state.tab,
						onChange: ( id ) => {
							state.tab = id;
							SPP.query.set( { tab: id } );
							render();
						},
					} ),
					content
				);
				saveBar.style.display = extra ? 'none' : '';
				renderSaveBar();
			};

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Settings', 'searchpilot-pro' ),
					subtitle: __( 'Content types, titles & meta, compatibility with other SEO plugins, indexing, alerts and reports.', 'searchpilot-pro' ),
				} ),
				body,
				saveBar
			);
			SPP.mount( body, ui.loading() );

			window.addEventListener( 'beforeunload', ( e ) => {
				if ( Object.keys( state.draft ).length ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );

			api.get( '/settings' )
				.then( ( data ) => {
					state.data = data;
					render();
				} )
				.catch( ( err ) => SPP.mount( body, ui.errorBox( err ) ) );
		},
	} );
} )( window.wp, window.SPP );

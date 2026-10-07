/**
 * Schema view: ownership, organization/person, breadcrumbs, rich results,
 * post type defaults, preview & validation and structured data issues.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { h, ui, api } = SPP;
	const field = ui.field;

	const ISSUE_TYPES = [ 'schema_duplicate', 'schema_invalid_json', 'schema_missing_required', 'schema_conflict_microdata', 'duplicate_meta_output', 'org_logo_missing' ];

	const SCHEMA_LABELS = {
		Article: __( 'Article', 'searchpilot-pro' ),
		BlogPosting: __( 'Blog post', 'searchpilot-pro' ),
		NewsArticle: __( 'News article', 'searchpilot-pro' ),
		WebPage: __( 'Web page', 'searchpilot-pro' ),
		AboutPage: __( 'About page', 'searchpilot-pro' ),
		ContactPage: __( 'Contact page', 'searchpilot-pro' ),
		CollectionPage: __( 'Collection page', 'searchpilot-pro' ),
		none: __( 'No page-specific schema', 'searchpilot-pro' ),
	};

	/** Plain text of a rendered title (entities decoded, nothing executed). */
	function decode( html ) {
		try {
			return new window.DOMParser().parseFromString( String( html || '' ), 'text/html' ).body.textContent || '';
		} catch ( e ) {
			return String( html || '' );
		}
	}

	/** Reads a dot key from a nested object. */
	function read( obj, key ) {
		return key.split( '.' ).reduce( ( o, k ) => ( o && typeof o === 'object' ? o[ k ] : undefined ), obj );
	}

	SPP.registerView( 'schema', {
		mount( el ) {
			const state = { overview: null, settings: null, types: null, draft: {}, errors: {}, previewId: 0 };
			const body = h( 'div' );
			const saveBar = h( 'div', { class: 'spp-card', style: { position: 'sticky', bottom: '0', zIndex: 5, marginTop: '18px' } } );

			const value = ( key ) => ( Object.prototype.hasOwnProperty.call( state.draft, key ) ? state.draft[ key ] : read( state.settings.settings, key ) );
			const set = ( key, v ) => {
				state.draft[ key ] = v;
				renderSaveBar();
			};

			/* ---------- save bar ---------- */

			const renderSaveBar = () => {
				const count = Object.keys( state.draft ).length;
				saveBar.style.display = count ? '' : 'none';
				SPP.mount(
					saveBar,
					h(
						'div',
						{ class: 'spp-card-body spp-row is-between' },
						h( 'span', { class: 'spp-muted' }, sprintf( /* translators: %d: number of changed settings. */ _n( '%d unsaved change', '%d unsaved changes', count, 'searchpilot-pro' ), count ) ),
						h(
							'div',
							{ class: 'spp-row' },
							ui.button( {
								label: __( 'Discard', 'searchpilot-pro' ),
								variant: 'ghost',
								onClick: () => {
									state.draft = {};
									render();
								},
							} ),
							ui.button( { label: __( 'Save changes', 'searchpilot-pro' ), variant: 'primary', onClick: save } )
						)
					)
				);
			};

			const save = () =>
				api.post( '/settings', { settings: state.draft } ).then( ( res ) => {
					state.errors = res.errors || {};
					const failed = Object.keys( state.errors );
					state.settings = res;
					state.draft = failed.reduce( ( d, k ) => Object.assign( d, { [ k ]: state.draft[ k ] } ), {} );
					ui.toast( failed.length ? __( 'Some values were not saved — see the highlighted fields.', 'searchpilot-pro' ) : __( 'Schema settings saved', 'searchpilot-pro' ), failed.length ? 'error' : undefined );
					return api.get( '/schema' ).then( ( overview ) => {
						state.overview = overview;
						render();
					} );
				} );

			/* ---------- sections ---------- */

			const banner = () => {
				const o = state.overview;
				if ( o.owns ) {
					return ui.notice( {
						tone: 'passed',
						title: __( 'SearchPilot outputs your structured data', 'searchpilot-pro' ),
						text: __( 'Every page gets one linked JSON-LD graph (website, organization, page, article, breadcrumbs, FAQ and video) describing only what visitors can see.', 'searchpilot-pro' ),
					} );
				}
				if ( o.owner === 'off' ) {
					return ui.notice( {
						tone: 'high',
						title: __( 'Structured data output is switched off', 'searchpilot-pro' ),
						text: __( 'Neither SearchPilot nor another plugin prints JSON-LD for your site. Turn it on in Settings → Compatibility.', 'searchpilot-pro' ),
						actions: [ ui.button( { label: __( 'Open compatibility settings', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'settings', { tab: 'ownership' } ) } ) ],
					} );
				}
				return ui.notice( {
					tone: 'info',
					title: sprintf( /* translators: %s: plugin name. */ __( '%s outputs your structured data (companion mode)', 'searchpilot-pro' ), o.owner_name ),
					text: o.suppressible
						? __( 'SearchPilot keeps its JSON-LD off so nothing is duplicated. You can hand structured data to SearchPilot in Settings → Compatibility; it then switches the other plugin’s schema off. The preview below shows what SearchPilot would output.', 'searchpilot-pro' )
						: __( 'SearchPilot keeps its JSON-LD off so nothing is duplicated. To use SearchPilot’s schema, switch structured data off in that plugin first. The preview below shows what SearchPilot would output.', 'searchpilot-pro' ),
					actions: [ ui.button( { label: __( 'Open compatibility settings', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'settings', { tab: 'ownership' } ) } ) ],
				} );
			};

			const entityCard = () => {
				const o = state.overview;
				const entity = value( 'schema.entity' ) || 'organization';
				const users = ( state.settings.users || [] ).map( ( u ) => ( { value: u.id, label: u.name } ) );
				const logo = o.logo || {};
				const parts = [
					field.radioCards( {
						value: entity,
						options: [
							{ value: 'organization', label: __( 'Organization', 'searchpilot-pro' ), description: __( 'A company, publication or newsroom.', 'searchpilot-pro' ), icon: 'globe' },
							{ value: 'person', label: __( 'Person', 'searchpilot-pro' ), description: __( 'A personal site or blog.', 'searchpilot-pro' ), icon: 'hand' },
						],
						onChange: ( v ) => {
							set( 'schema.entity', v );
							render();
						},
					} ),
				];
				if ( entity === 'person' ) {
					parts.push(
						field.select( {
							label: __( 'Person', 'searchpilot-pro' ),
							help: __( 'Their name, profile links (Users → Profile → Public profiles) and avatar describe the site.', 'searchpilot-pro' ),
							row: true,
							value: value( 'schema.person_user' ) || 0,
							options: [ { value: 0, label: __( '— Choose a user —', 'searchpilot-pro' ) } ].concat( users ),
							onChange: ( v ) => set( 'schema.person_user', parseInt( v, 10 ) || 0 ),
						} )
					);
				} else {
					parts.push(
						field.text( { label: __( 'Name', 'searchpilot-pro' ), row: true, wide: true, value: value( 'schema.org_name' ), placeholder: o.site_name, help: __( 'Leave empty to use the site title.', 'searchpilot-pro' ), onInput: ( v ) => set( 'schema.org_name', v ), error: state.errors[ 'schema.org_name' ] } ),
						field.text( { label: __( 'Legal name', 'searchpilot-pro' ), row: true, wide: true, value: value( 'schema.org_legal_name' ), help: __( 'Optional, e.g. “Example Media Pvt. Ltd.”', 'searchpilot-pro' ), onInput: ( v ) => set( 'schema.org_legal_name', v ) } ),
						field.media( {
							label: __( 'Logo', 'searchpilot-pro' ),
							help: logo.source === 'theme' ? __( 'Currently using the theme’s site logo. Choose a square image of at least 112 × 112 px (512 × 512 recommended).', 'searchpilot-pro' ) : __( 'A square image of at least 112 × 112 px (512 × 512 recommended). Leave empty to use the theme’s site logo.', 'searchpilot-pro' ),
							row: true,
							value: value( 'schema.org_logo' ),
							url: logo.url || state.settings.logo_url,
							onChange: ( id ) => set( 'schema.org_logo', id || 0 ),
						} )
					);
					if ( ! logo.url ) {
						parts.push( ui.notice( { tone: 'medium', text: __( 'No logo is set. Google uses the organization logo in search results and knowledge panels.', 'searchpilot-pro' ) } ) );
					} else if ( ! logo.ok ) {
						parts.push( ui.notice( { tone: 'high', text: sprintf( /* translators: 1: width, 2: height. */ __( 'The logo is %1$d × %2$d px. Google needs at least 112 × 112 px.', 'searchpilot-pro' ), logo.width, logo.height ) } ) );
					}
					parts.push(
						field.text( { label: __( 'Contact e-mail', 'searchpilot-pro' ), row: true, wide: true, type: 'email', value: value( 'schema.contact_email' ), onInput: ( v ) => set( 'schema.contact_email', v ) } ),
						field.text( { label: __( 'Contact phone', 'searchpilot-pro' ), row: true, wide: true, value: value( 'schema.contact_phone' ), help: __( 'Include the country code, e.g. +91 80 1234 5678.', 'searchpilot-pro' ), onInput: ( v ) => set( 'schema.contact_phone', v ) } )
					);
				}
				parts.push(
					field.textarea( {
						label: __( 'Social profiles (sameAs)', 'searchpilot-pro' ),
						help: __( 'One URL per line: Facebook, X, YouTube, Instagram, LinkedIn, Wikipedia, Wikidata…', 'searchpilot-pro' ),
						row: true,
						wide: true,
						rows: 4,
						value: ( value( 'schema.same_as' ) || [] ).join( '\n' ),
						onInput: ( v ) =>
							set(
								'schema.same_as',
								v
									.split( /\r?\n/ )
									.map( ( s ) => s.trim() )
									.filter( Boolean )
							),
					} )
				);
				return ui.card( { title: __( 'Site representation', 'searchpilot-pro' ), subtitle: __( 'Who publishes this website — used as the publisher of every article.', 'searchpilot-pro' ), body: parts } );
			};

			const richCard = () => {
				const o = state.overview;
				const categories = ( state.settings.categories || [] ).map( ( c ) => ( { value: c.id, label: c.name } ) );
				return ui.card( {
					title: __( 'Rich results', 'searchpilot-pro' ),
					subtitle: __( 'Structured data is generated only from content visible on the page.', 'searchpilot-pro' ),
					body: [
						field.select( {
							label: __( 'Breadcrumb structured data', 'searchpilot-pro' ),
							help: __( '“When shown” adds BreadcrumbList only to pages that display breadcrumbs, so it always matches what visitors see.', 'searchpilot-pro' ),
							row: true,
							value: value( 'schema.breadcrumbs' ),
							options: [
								{ value: 'displayed', label: __( 'When breadcrumbs are shown (recommended)', 'searchpilot-pro' ) },
								{ value: 'always', label: __( 'On every page', 'searchpilot-pro' ) },
								{ value: 'off', label: __( 'Off', 'searchpilot-pro' ) },
							],
							onChange: ( v ) => set( 'schema.breadcrumbs', v ),
						} ),
						field.toggle( {
							label: __( 'FAQ structured data', 'searchpilot-pro' ),
							help: __( 'From the SearchPilot FAQ block (or Details blocks when enabled per page). Note: Google shows FAQ rich results only for well-known, authoritative government and health websites.', 'searchpilot-pro' ),
							row: true,
							checked: !! value( 'schema.faq' ),
							onChange: ( v ) => set( 'schema.faq', v ),
						} ),
						field.toggle( {
							label: __( 'Video structured data', 'searchpilot-pro' ),
							help: o.video_detector ? __( 'Describes embedded YouTube, Vimeo and self-hosted videos (name, thumbnail, upload date, duration).', 'searchpilot-pro' ) : __( 'Needs the video detection of SearchPilot’s sitemap module, which is not active.', 'searchpilot-pro' ),
							row: true,
							checked: !! value( 'schema.video' ),
							onChange: ( v ) => set( 'schema.video', v ),
						} ),
						categories.length
							? field.checklist( {
									label: __( 'News categories', 'searchpilot-pro' ),
									help: __( 'Posts in these categories are described as NewsArticle (for Google News and Top stories). Other posts use their content type’s schema.', 'searchpilot-pro' ),
									options: categories,
									values: value( 'schema.news_categories' ) || [],
									onChange: ( v ) => set( 'schema.news_categories', v ),
							  } )
							: null,
					],
				} );
			};

			const breadcrumbsCard = () => {
				const o = state.overview;
				const owned = o.breadcrumbs && o.breadcrumbs.owner === 'searchpilot';
				return ui.card( {
					title: __( 'Breadcrumbs', 'searchpilot-pro' ),
					subtitle: __( 'Home → blog → category or parent pages → current page.', 'searchpilot-pro' ),
					body: [
						! owned
							? ui.notice( {
									tone: 'info',
									text: sprintf( /* translators: %s: plugin name. */ __( 'Breadcrumbs are provided by %s, so SearchPilot’s breadcrumb shortcode, block and template tag print nothing.', 'searchpilot-pro' ), ( o.breadcrumbs && o.breadcrumbs.owner_name ) || '' ),
							  } )
							: null,
						field.text( { label: __( 'Separator', 'searchpilot-pro' ), row: true, value: value( 'breadcrumbs.separator' ), maxLength: 5, onInput: ( v ) => set( 'breadcrumbs.separator', v ) } ),
						field.text( { label: __( 'Home label', 'searchpilot-pro' ), row: true, value: value( 'breadcrumbs.home_label' ), placeholder: __( 'Home', 'searchpilot-pro' ), onInput: ( v ) => set( 'breadcrumbs.home_label', v ) } ),
						field.text( { label: __( 'Prefix', 'searchpilot-pro' ), row: true, value: value( 'breadcrumbs.prefix' ), placeholder: __( 'e.g. You are here:', 'searchpilot-pro' ), onInput: ( v ) => set( 'breadcrumbs.prefix', v ) } ),
						field.toggle( { label: __( 'Show the current page', 'searchpilot-pro' ), row: true, checked: !! value( 'breadcrumbs.show_current' ), onChange: ( v ) => set( 'breadcrumbs.show_current', v ) } ),
						field.toggle( { label: __( 'Show the blog page', 'searchpilot-pro' ), help: __( 'When a static front page is set, posts get the blog page between Home and the category.', 'searchpilot-pro' ), row: true, checked: !! value( 'breadcrumbs.show_blog' ), onChange: ( v ) => set( 'breadcrumbs.show_blog', v ) } ),
						h(
							'div',
							{ class: 'spp-stack', style: { marginTop: '8px' } },
							h( 'div', { class: 'spp-strong' }, __( 'Add breadcrumbs to your site', 'searchpilot-pro' ) ),
							h( 'div', { class: 'spp-muted spp-small' }, __( 'Block themes: insert the “Breadcrumbs (SearchPilot)” block in your templates. Classic themes: use the shortcode or add the template tag to your theme.', 'searchpilot-pro' ) ),
							ui.copyField( '[searchpilot_breadcrumbs]' ),
							ui.copyField( '<?php if ( function_exists( \'searchpilot_breadcrumbs\' ) ) { searchpilot_breadcrumbs(); } ?>' )
						),
					],
				} );
			};

			const postTypesCard = () =>
				ui.card( {
					title: __( 'Content type defaults', 'searchpilot-pro' ),
					subtitle: __( 'The schema type each content type uses unless a page overrides it in the editor.', 'searchpilot-pro' ),
					actions: [ ui.button( { label: __( 'Change in Settings → Content types', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'settings', { tab: 'content' } ) } ) ],
					flush: true,
					body: ui.table( {
						dense: true,
						columns: [
							{ key: 'label', label: __( 'Content type', 'searchpilot-pro' ), render: ( r ) => h( 'span', null, r.label, ' ', h( 'code', null, r.name ) ) },
							{ key: 'schema', label: __( 'Schema', 'searchpilot-pro' ), render: ( r ) => ui.badge( SCHEMA_LABELS[ r.schema ] || r.schema, [ 'Article', 'BlogPosting', 'NewsArticle' ].indexOf( r.schema ) >= 0 ? 'navy' : 'outline' ) },
							{ key: 'index', label: __( 'Indexed', 'searchpilot-pro' ), render: ( r ) => ( r.index ? ui.badge( __( 'Yes', 'searchpilot-pro' ), 'passed' ) : ui.badge( __( 'No (noindex)', 'searchpilot-pro' ), 'high' ) ) },
						],
						rows: state.overview.post_types || [],
					} ),
				} );

			/* ---------- preview & validate ---------- */

			const previewRegion = SPP.region( () => ( state.previewId ? loadPreview( state.previewId ) : ui.empty( { icon: 'search', title: __( 'Choose a page to preview', 'searchpilot-pro' ), text: __( 'See the exact JSON-LD SearchPilot builds for it and every error or warning.', 'searchpilot-pro' ) } ) ) );

			const validationList = ( validation ) => {
				const rows = [];
				( validation.errors || [] ).forEach( ( e ) => rows.push( ui.checkRow( { status: 'fail', label: e.type + ( e.property ? ' · ' + e.property : '' ), message: e.message } ) ) );
				( validation.warnings || [] ).forEach( ( w ) => rows.push( ui.checkRow( { status: 'warning', label: w.type + ( w.property ? ' · ' + w.property : '' ), message: w.message } ) ) );
				if ( ! rows.length ) {
					rows.push( ui.checkRow( { status: 'pass', label: __( 'No errors or warnings', 'searchpilot-pro' ), message: sprintf( /* translators: %s: schema types. */ __( 'Types: %s', 'searchpilot-pro' ), ( validation.types || [] ).join( ', ' ) ) } ) );
				}
				return h( 'div', null, rows );
			};

			const loadPreview = ( postId ) =>
				api.get( '/schema/preview', { post_id: postId } ).then( ( res ) => {
					const v = res.validation || {};
					const errors = ( v.errors || [] ).length;
					const warnings = ( v.warnings || [] ).length;
					const published = res.post.status === 'publish';
					return h(
						'div',
						{ class: 'spp-stack' },
						h(
							'div',
							{ class: 'spp-row is-between' },
							h(
								'div',
								null,
								h( 'div', { class: 'spp-strong' }, res.post.title || __( '(no title)', 'searchpilot-pro' ) ),
								h(
									'div',
									{ class: 'spp-row', style: { marginTop: '4px' } },
									ui.badge( SCHEMA_LABELS[ res.post.type ] || res.post.type, 'navy' ),
									errors ? ui.badge( sprintf( /* translators: %d: errors. */ _n( '%d error', '%d errors', errors, 'searchpilot-pro' ), errors ), 'critical' ) : ui.badge( __( 'No errors', 'searchpilot-pro' ), 'passed' ),
									warnings ? ui.badge( sprintf( /* translators: %d: warnings. */ _n( '%d warning', '%d warnings', warnings, 'searchpilot-pro' ), warnings ), 'medium' ) : null
								)
							),
							h(
								'div',
								{ class: 'spp-row' },
								ui.button( { label: __( 'Copy JSON-LD', 'searchpilot-pro' ), icon: 'copy', size: 'small', onClick: () => SPP.util.copy( res.json ).then( () => ui.toast( __( 'Copied to clipboard', 'searchpilot-pro' ) ) ) } ),
								ui.button( {
									label: __( 'Validate & update issues', 'searchpilot-pro' ),
									icon: 'refresh',
									size: 'small',
									onClick: () =>
										api.post( '/schema/validate', { post_id: postId } ).then( () => {
											ui.toast( __( 'Validated', 'searchpilot-pro' ) );
											previewRegion.refresh();
											issues.refresh();
										} ),
								} ),
								published ? ui.button( { label: __( 'Rich Results Test', 'searchpilot-pro' ), icon: 'external', size: 'small', variant: 'ghost', href: 'https://search.google.com/test/rich-results?url=' + encodeURIComponent( res.post.url ), target: '_blank' } ) : null
							)
						),
						! res.owns ? ui.notice( { tone: 'info', text: sprintf( /* translators: %s: plugin name. */ __( 'Preview only: %s currently outputs structured data on your site.', 'searchpilot-pro' ), res.owner_name ) } ) : null,
						res.breadcrumbs === 'displayed' ? h( 'p', { class: 'spp-muted spp-small' }, __( 'The BreadcrumbList is included here; on the site it is printed only on pages that show breadcrumbs.', 'searchpilot-pro' ) ) : null,
						validationList( v ),
						ui.code( res.json )
					);
				} );

			const searchResults = h( 'div', { class: 'spp-list', style: { marginTop: '10px' } } );
			const runSearch = ( q ) => {
				SPP.mount( searchResults, ui.loading() );
				return wp
					.apiFetch( { path: '/wp/v2/search?type=post&per_page=8&search=' + encodeURIComponent( q || '' ) } )
					.then( ( items ) => {
						if ( ! items || ! items.length ) {
							SPP.mount( searchResults, h( 'p', { class: 'spp-muted' }, __( 'No matching content.', 'searchpilot-pro' ) ) );
							return;
						}
						SPP.mount(
							searchResults,
							h(
								'ul',
								{ class: 'spp-list' },
								items.map( ( item ) =>
									h(
										'li',
										{ class: 'spp-row is-between' },
										h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-truncate' }, decode( item.title ) ), h( 'div', { class: 'spp-muted spp-small' }, item.subtype, ' · ', SPP.fmt.path( item.url ) ) ),
										ui.button( {
											label: __( 'Preview', 'searchpilot-pro' ),
											size: 'small',
											variant: state.previewId === item.id ? 'primary' : null,
											onClick: () => {
												state.previewId = item.id;
												SPP.query.set( { post_id: item.id } );
												runSearch( q );
												return previewRegion.refresh();
											},
										} )
									)
								)
							)
						);
					} )
					.catch( ( err ) => SPP.mount( searchResults, ui.errorBox( err ) ) );
			};

			const previewCard = () =>
				ui.card( {
					title: __( 'Preview & validate', 'searchpilot-pro' ),
					subtitle: __( 'Checks required and recommended properties from Google’s structured data documentation.', 'searchpilot-pro' ),
					body: ui.grid(
						2,
						h( 'div', null, ui.search( { placeholder: __( 'Search posts and pages…', 'searchpilot-pro' ), onSearch: runSearch } ), searchResults ),
						previewRegion.el
					),
				} );

			/* ---------- issues & types ---------- */

			const issues = SPP.region( () =>
				api.get( '/issues', { type: ISSUE_TYPES.join( ',' ), per_page: 20 } ).then( ( res ) => {
					const items = res.items || [];
					if ( ! items.length ) {
						return ui.passed( __( 'No structured data problems found', 'searchpilot-pro' ), __( 'Pages are checked for broken or duplicated structured data and duplicate SEO tags whenever SearchPilot crawls them.', 'searchpilot-pro' ) );
					}
					return h( 'div', { class: 'spp-stack' }, items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: () => issues.refresh() } ) ), res.total > items.length ? h( 'p', { class: 'spp-muted' }, sprintf( /* translators: %d: number of issues. */ __( '%d more in the SEO Audit.', 'searchpilot-pro' ), res.total - items.length ) ) : null );
				} )
			);

			const typesCard = () =>
				ui.card( {
					title: __( 'What SearchPilot describes', 'searchpilot-pro' ),
					flush: true,
					body: [
						ui.table( {
							dense: true,
							columns: [
								{ key: 'label', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'div', { class: 'spp-strong' }, r.label ), h( 'code', null, r.type ) ) },
								{ key: 'where', label: __( 'Where', 'searchpilot-pro' ) },
								{ key: 'notes', label: __( 'Details', 'searchpilot-pro' ) },
							],
							rows: ( state.types && state.types.items ) || [],
						} ),
						state.types && state.types.never ? h( 'div', { style: { padding: '14px 20px' } }, ui.notice( { tone: 'info', text: state.types.never } ) ) : null,
					],
				} );

			/* ---------- render ---------- */

			const render = () => {
				SPP.mount(
					body,
					h( 'div', { style: { marginBottom: '18px' } }, banner() ),
					ui.grid( 2, entityCard(), h( 'div', { class: 'spp-stack' }, richCard(), breadcrumbsCard() ) ),
					h( 'div', { style: { marginTop: '18px' } }, previewCard() ),
					h( 'div', { style: { marginTop: '18px' } }, ui.card( { title: __( 'Structured data issues', 'searchpilot-pro' ), actions: [ ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', size: 'small', onClick: () => issues.refresh() } ) ], body: issues.el } ) ),
					h( 'div', { style: { marginTop: '18px' } }, ui.grid( 2, postTypesCard(), typesCard() ) )
				);
				renderSaveBar();
			};

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Schema', 'searchpilot-pro' ),
					subtitle: __( 'Structured data (JSON-LD) that helps search engines understand your site, articles, breadcrumbs, FAQs and videos.', 'searchpilot-pro' ),
				} ),
				body,
				saveBar
			);
			SPP.mount( body, ui.loading() );
			saveBar.style.display = 'none';

			window.addEventListener( 'beforeunload', ( e ) => {
				if ( Object.keys( state.draft ).length ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );

			Promise.all( [ api.get( '/schema' ), api.get( '/settings' ), api.get( '/schema/types' ) ] )
				.then( ( [ overview, settings, types ] ) => {
					state.overview = overview;
					state.settings = settings;
					state.types = types;
					render();
					runSearch( '' );
					// Deep links from other screens: ?post_id= previews that post.
					const linked = parseInt( SPP.query.get( 'post_id' ), 10 );
					if ( linked > 0 ) {
						state.previewId = linked;
						previewRegion.refresh();
					}
				} )
				.catch( ( err ) => SPP.mount( body, ui.errorBox( err ) ) );
		},
	} );
} )( window.wp, window.SPP );

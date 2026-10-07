/**
 * Setup wizard: Website Audit → Google Search Console → Bing Webmaster Tools → IndexNow →
 * Sitemap Configuration → Content Types → Automation Level → Notification Preferences.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, api } = SPP;
	const field = ui.field;

	function read( obj, key ) {
		return key.split( '.' ).reduce( ( o, k ) => ( o && typeof o === 'object' ? o[ k ] : undefined ), obj );
	}

	const STEPS = [
		{ id: 'audit', label: __( 'Website audit', 'searchpilot-pro' ) },
		{ id: 'google', label: __( 'Google Search Console', 'searchpilot-pro' ) },
		{ id: 'bing', label: __( 'Bing Webmaster', 'searchpilot-pro' ) },
		{ id: 'indexnow', label: 'IndexNow' },
		{ id: 'sitemap', label: __( 'Sitemaps', 'searchpilot-pro' ) },
		{ id: 'content', label: __( 'Content types', 'searchpilot-pro' ) },
		{ id: 'automation', label: __( 'Automation', 'searchpilot-pro' ) },
		{ id: 'notifications', label: __( 'Notifications', 'searchpilot-pro' ) },
		{ id: 'finish', label: __( 'Finish', 'searchpilot-pro' ) },
	];

	SPP.registerView( 'setup', {
		mount( el ) {
			const state = { step: 'audit', settings: null, draft: {}, audit: null };
			const stepper = h( 'div' );
			const content = h( 'div' );
			const footer = h( 'div', { class: 'spp-wizard-footer' } );

			const value = ( key ) => ( Object.prototype.hasOwnProperty.call( state.draft, key ) ? state.draft[ key ] : read( state.settings.settings, key ) );
			const set = ( key, v ) => {
				state.draft[ key ] = v;
			};
			const toggle = ( key, label, help ) => field.toggle( { label, help, row: true, checked: !! value( key ), onChange: ( v ) => set( key, v ) } );

			const saveDraft = () => {
				if ( ! Object.keys( state.draft ).length ) {
					return Promise.resolve();
				}
				return api.post( '/settings', { settings: state.draft } ).then( ( res ) => {
					const failed = Object.keys( res.errors || {} );
					state.settings = res;
					state.draft = failed.reduce( ( d, k ) => Object.assign( d, { [ k ]: state.draft[ k ] } ), {} );
					SPP.boot.mode = res.settings.general.mode;
					if ( failed.length ) {
						const err = new Error( failed.map( ( k ) => res.errors[ k ] ).join( ' ' ) );
						ui.toast( err.message, 'error' );
						throw Object.assign( err, { silent: true } );
					}
				} );
			};

			const go = ( id ) =>
				saveDraft().then( () => {
					state.step = id;
					SPP.query.set( { step: id } );
					api.post( '/wizard', { step: id } ).catch( () => null );
					render();
					window.scrollTo( { top: 0, behavior: 'smooth' } );
				} );

			const index = () => STEPS.findIndex( ( s ) => s.id === state.step );

			/* ---------- steps ---------- */

			const stepAudit = () => {
				const region = SPP.region( () => {
					// A re-render while the audit runs (e.g. after the connection status loads) reuses the request.
					if ( ! state.audit && ! state.auditRun ) {
						state.auditRun = api.post( '/system/audit' ).finally( () => {
							state.auditRun = null;
						} );
					}
					const run = state.audit ? Promise.resolve( state.audit ) : state.auditRun;
					return run.then( ( audit ) => {
						state.audit = audit;
						const fails = audit.checks.filter( ( c ) => c.status === 'fail' ).length;
						const warns = audit.checks.filter( ( c ) => c.status === 'warning' ).length;
						return ui.card( {
							title: __( 'Website audit', 'searchpilot-pro' ),
							subtitle: __( 'A quick check for anything that stops search engines from discovering your site.', 'searchpilot-pro' ),
							actions: [
								ui.button( {
									label: __( 'Run again', 'searchpilot-pro' ),
									icon: 'refresh',
									size: 'small',
									onClick: () => {
										state.audit = null;
										return region.refresh();
									},
								} ),
							],
							body: [
								h(
									'div',
									{ class: 'spp-hero', style: { marginBottom: '12px' } },
									ui.scoreRing( audit.score, { title: __( 'Setup readiness (internal check)', 'searchpilot-pro' ) } ),
									h(
										'div',
										{ class: 'spp-hero-text' },
										h( 'h3', { class: 'spp-hero-title' }, fails ? __( 'A few things need attention', 'searchpilot-pro' ) : warns ? __( 'Looking good — with some notes', 'searchpilot-pro' ) : __( 'Your site is ready to be discovered', 'searchpilot-pro' ) ),
										h( 'p', { class: 'spp-muted' }, __( 'SearchPilot keeps checking these continuously once setup is finished and alerts you when something breaks.', 'searchpilot-pro' ) )
									)
								),
								audit.checks.map( ( c ) => ui.checkRow( c ) ),
							],
						} );
					} );
				} );
				return region.el;
			};

			const engineStep = ( key, title, intro, optional ) => {
				const panel = SPP.engines && typeof SPP.engines[ key ] === 'function' ? SPP.engines[ key ]( { compact: true, onChange: () => null } ) : ui.notice( { tone: 'high', text: __( 'This connection screen is unavailable. You can connect later from SearchPilot → Search Engines.', 'searchpilot-pro' ) } );
				return ui.stack(
					ui.card( {
						title,
						subtitle: intro,
						body: [
							optional ? h( 'p', { class: 'spp-muted spp-small' }, __( 'Optional — you can skip this step and connect later from SearchPilot → Search Engines.', 'searchpilot-pro' ) ) : null,
							panel,
						],
					} )
				);
			};

			const stepSitemap = () => {
				const compat = state.settings.compat || {};
				const owner = ( ( compat.features || {} ).sitemap || {} ).owner;
				const ownerName = owner && owner !== 'searchpilot' && owner !== 'off' ? ( ( compat.plugins || {} )[ owner ] || {} ).name || owner : '';
				return ui.card( {
					title: __( 'Sitemap configuration', 'searchpilot-pro' ),
					subtitle: __( 'Sitemaps tell search engines which pages exist and when they changed.', 'searchpilot-pro' ),
					body: [
						ownerName
							? ui.notice( {
									tone: 'info',
									title: sprintf( /* translators: %s: plugin name. */ __( '%s already generates your XML sitemap', 'searchpilot-pro' ), ownerName ),
									text: __( 'SearchPilot will not create a second one. It monitors that sitemap, checks that it only lists indexable URLs and submits it to Google and Bing after changes.', 'searchpilot-pro' ),
							  } )
							: ui.notice( { tone: 'passed', title: __( 'SearchPilot will generate your sitemaps', 'searchpilot-pro' ), text: SPP.boot.homeUrl + 'sitemap.xml' } ),
						toggle( 'sitemap.images', __( 'Include images', 'searchpilot-pro' ), __( 'Helps Google Images find your pictures.', 'searchpilot-pro' ) ),
						toggle( 'sitemap.video', __( 'Video sitemap', 'searchpilot-pro' ), __( 'For posts with embedded YouTube, Vimeo or uploaded videos.', 'searchpilot-pro' ) ),
						toggle( 'sitemap.news', __( 'Google News sitemap', 'searchpilot-pro' ), __( 'For news publishers: lists articles from the last 48 hours.', 'searchpilot-pro' ) ),
						field.text( { label: __( 'News publication name', 'searchpilot-pro' ), row: true, wide: true, value: value( 'sitemap.news_name' ), placeholder: SPP.boot.siteName, onInput: ( v ) => set( 'sitemap.news_name', v ), help: __( 'Must match the name used in Google News.', 'searchpilot-pro' ) } ),
						field.number( { label: __( 'URLs per sitemap file', 'searchpilot-pro' ), row: true, value: value( 'sitemap.max_urls' ), min: 100, max: 50000, onInput: ( v ) => set( 'sitemap.max_urls', v ), help: __( 'Smaller files load faster on shared hosting. The maximum allowed is 50,000.', 'searchpilot-pro' ) } ),
						toggle( 'sitemap.submit_on_change', __( 'Resubmit after meaningful changes', 'searchpilot-pro' ), __( 'Through the official Search Console and Bing Webmaster APIs (throttled).', 'searchpilot-pro' ) ),
					],
				} );
			};

			const stepContent = () => {
				const postTypes = state.settings.post_types || {};
				const taxes = state.settings.taxonomies || {};
				const current = ( key ) => Object.assign( {}, read( state.settings.settings, key ) || {}, state.draft[ key ] || {} );
				const setMap = ( key, defs, name, prop, v ) => {
					const all = current( key );
					all[ name ] = Object.assign( {}, defs[ name ], all[ name ] || {}, { [ prop ]: v } );
					delete all[ name ].label;
					delete all[ name ].object_types;
					set( key, all );
				};
				const box = ( checked, onChange, label ) => {
					const cb = h( 'input', { type: 'checkbox', checked, 'aria-label': label || null } );
					cb.addEventListener( 'change', () => onChange( cb.checked ) );
					return h( 'label', { class: 'spp-toggle' }, cb, h( 'span', { class: 'spp-toggle-track' } ) );
				};
				const eff = ( key, name, prop, fallback ) => {
					const map = current( key );
					return map[ name ] && map[ name ][ prop ] !== undefined ? map[ name ][ prop ] : fallback;
				};
				return ui.stack(
					ui.card( {
						title: __( 'Content types', 'searchpilot-pro' ),
						subtitle: __( 'Choose what should appear in search results. Everything else stays out of sitemaps and submissions.', 'searchpilot-pro' ),
						flush: true,
						body: ui.table( {
							columns: [
								{ key: 'label', label: __( 'Content', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'span', { class: 'spp-cell-title' }, r.label ), h( 'span', { class: 'spp-cell-sub' }, r.kind ) ) },
								{ key: 'index', label: __( 'Index', 'searchpilot-pro' ), align: 'center', render: ( r ) => box( eff( r.key, r.name, 'index', r.index ), ( v ) => setMap( r.key, r.defs, r.name, 'index', v ) ) },
								{ key: 'sitemap', label: __( 'In sitemap', 'searchpilot-pro' ), align: 'center', render: ( r ) => box( eff( r.key, r.name, 'sitemap', r.sitemap ), ( v ) => setMap( r.key, r.defs, r.name, 'sitemap', v ) ) },
							],
							rows: Object.keys( postTypes )
								.map( ( name ) => Object.assign( { id: 'pt-' + name, name, key: 'content.post_types', defs: postTypes, kind: __( 'Post type', 'searchpilot-pro' ) }, postTypes[ name ] ) )
								.concat( Object.keys( taxes ).map( ( name ) => Object.assign( { id: 'tax-' + name, name, key: 'content.taxonomies', defs: taxes, kind: __( 'Taxonomy', 'searchpilot-pro' ) }, taxes[ name ] ) ) ),
						} ),
					} ),
					ui.card( {
						title: __( 'Who publishes this site?', 'searchpilot-pro' ),
						subtitle: __( 'Used for structured data (Organization or Person) that Google can show in knowledge panels.', 'searchpilot-pro' ),
						body: [
							field.select( {
								label: __( 'Publisher type', 'searchpilot-pro' ),
								row: true,
								value: value( 'schema.entity' ),
								options: [
									{ value: 'organization', label: __( 'Organization / publication', 'searchpilot-pro' ) },
									{ value: 'person', label: __( 'Person', 'searchpilot-pro' ) },
								],
								onChange: ( v ) => set( 'schema.entity', v ),
							} ),
							field.text( { label: __( 'Organization name', 'searchpilot-pro' ), row: true, wide: true, value: value( 'schema.org_name' ), placeholder: SPP.boot.siteName, onInput: ( v ) => set( 'schema.org_name', v ) } ),
							field.media( { label: __( 'Logo', 'searchpilot-pro' ), row: true, help: __( 'Square or rectangular, at least 112 × 112 px.', 'searchpilot-pro' ), value: value( 'schema.org_logo' ), url: state.settings.logo_url, onChange: ( id ) => set( 'schema.org_logo', id ) } ),
						],
					} )
				);
			};

			const stepAutomation = () =>
				ui.card( {
					title: __( 'Automation level', 'searchpilot-pro' ),
					subtitle: __( 'You can change this any time on the Automation screen. Every automatic change is logged and can be undone.', 'searchpilot-pro' ),
					body: field.radioCards( {
						value: value( 'general.mode' ),
						onChange: ( v ) => set( 'general.mode', v ),
						options: [
							{ value: 'manual', icon: 'hand', label: __( 'Manual', 'searchpilot-pro' ), description: __( 'Recommendations only. You approve every submission and change.', 'searchpilot-pro' ) },
							{ value: 'assisted', icon: 'shield', label: __( 'Assisted (recommended)', 'searchpilot-pro' ), description: __( 'Notifies search engines and fixes safe technical issues automatically. Asks before touching content-related fields.', 'searchpilot-pro' ) },
							{ value: 'autopilot', icon: 'rocket', label: __( 'Autopilot', 'searchpilot-pro' ), description: __( 'Also fills empty meta descriptions and ALT text and can insert internal links within your limits. Never rewrites your articles.', 'searchpilot-pro' ) },
						],
					} ),
				} );

			const stepNotifications = () =>
				ui.card( {
					title: __( 'Notification preferences', 'searchpilot-pro' ),
					body: [
						toggle( 'alerts.admin_notices', __( 'Dashboard notices for critical problems', 'searchpilot-pro' ), __( 'For example: site accidentally set to noindex, sitemap unavailable, Search Console disconnected.', 'searchpilot-pro' ) ),
						toggle( 'alerts.email', __( 'E-mail alerts', 'searchpilot-pro' ) ),
						field.text( {
							label: __( 'Send alerts to', 'searchpilot-pro' ),
							row: true,
							wide: true,
							value: ( value( 'alerts.recipients' ) || [] ).join( ', ' ),
							placeholder: __( 'Site admin e-mail', 'searchpilot-pro' ),
							onInput: ( v ) =>
								set(
									'alerts.recipients',
									v
										.split( /[\s,;]+/ )
										.map( ( s ) => s.trim() )
										.filter( Boolean )
								),
						} ),
						field.select( {
							label: __( 'Alert me about', 'searchpilot-pro' ),
							row: true,
							value: value( 'alerts.min_severity' ),
							options: [
								{ value: 'critical', label: __( 'Critical problems only', 'searchpilot-pro' ) },
								{ value: 'high', label: __( 'Critical and high', 'searchpilot-pro' ) },
								{ value: 'medium', label: __( 'Critical, high and medium', 'searchpilot-pro' ) },
							],
							onChange: ( v ) => set( 'alerts.min_severity', v ),
						} ),
						field.select( {
							label: __( 'Performance report by e-mail', 'searchpilot-pro' ),
							row: true,
							value: value( 'alerts.report' ),
							options: [
								{ value: 'off', label: __( 'Off', 'searchpilot-pro' ) },
								{ value: 'weekly', label: __( 'Weekly', 'searchpilot-pro' ) },
								{ value: 'monthly', label: __( 'Monthly', 'searchpilot-pro' ) },
							],
							onChange: ( v ) => set( 'alerts.report', v ),
						} ),
					],
				} );

			const stepFinish = () => {
				const done = SPP.region( () =>
					api.get( '/engines' ).catch( () => null ).then( ( engines ) => {
						const s = ( engines && ( engines.status || engines ) ) || {};
						const item = ( ok, title, text ) => h( 'div', { class: 'spp-status-item' }, ui.dot( ok ? 'passed' : 'medium' ), h( 'div', null, h( 'div', { class: 'spp-status-title' }, title ), h( 'div', { class: 'spp-status-text' }, text ) ) );
						return ui.card( {
							title: __( 'You’re all set', 'searchpilot-pro' ),
							subtitle: __( 'When you finish, SearchPilot builds its URL registry, checks your pages, syncs search performance and starts monitoring — all in the background, without slowing your site.', 'searchpilot-pro' ),
							body: [
								h(
									'div',
									{ class: 'spp-status-grid' },
									item( s.google && s.google.connected, 'Google Search Console', s.google && s.google.connected ? s.google.property : __( 'Not connected — performance data and index status need it', 'searchpilot-pro' ) ),
									item( s.bing && s.bing.connected, 'Bing Webmaster Tools', s.bing && s.bing.connected ? s.bing.site : __( 'Not connected (optional)', 'searchpilot-pro' ) ),
									item( s.indexnow && s.indexnow.enabled, 'IndexNow', s.indexnow && s.indexnow.enabled ? __( 'Enabled — Bing, Yandex, Seznam, Naver and Yep are notified instantly', 'searchpilot-pro' ) : __( 'Off', 'searchpilot-pro' ) ),
									item( true, __( 'Automation', 'searchpilot-pro' ), SPP.MODE_LABELS[ value( 'general.mode' ) ] || value( 'general.mode' ) )
								),
								h( 'p', { class: 'spp-disclaimer', style: { marginTop: '16px' } }, SPP.icon( 'info', 14 ), __( 'SearchPilot improves how search engines discover and understand your site using their official tools. No plugin can guarantee rankings or traffic.', 'searchpilot-pro' ) ),
							],
						} );
					} )
				);
				return done.el;
			};

			/* ---------- render ---------- */

			const renderStep = () => {
				switch ( state.step ) {
					case 'google':
						return engineStep( 'google', __( 'Google Search Console', 'searchpilot-pro' ), __( 'Unlocks clicks, impressions, queries, index status (URL Inspection) and sitemap submission — through Google’s official Search Console API. SearchPilot asks only for the Search Console permission (needed to submit sitemaps), plus the Indexing API permission if you turn that on.', 'searchpilot-pro' ), true );
					case 'bing':
						return engineStep( 'bing', __( 'Bing Webmaster Tools', 'searchpilot-pro' ), __( 'Adds Bing traffic data, crawl statistics and sitemap submission with an API key from Bing Webmaster Tools.', 'searchpilot-pro' ), true );
					case 'indexnow':
						return engineStep( 'indexnow', 'IndexNow', __( 'The open protocol Bing and other engines use to learn about new and changed pages within minutes. No account needed — SearchPilot hosts the key file for you. (Google does not use IndexNow.)', 'searchpilot-pro' ), false );
					case 'sitemap':
						return stepSitemap();
					case 'content':
						return stepContent();
					case 'automation':
						return stepAutomation();
					case 'notifications':
						return stepNotifications();
					case 'finish':
						return stepFinish();
					default:
						return stepAudit();
				}
			};

			const render = () => {
				const visited = state.visited || ( state.visited = {} );
				visited[ state.step ] = true;
				const connected = state.connected || {};
				const steps = STEPS.map( ( s, i ) => {
					let done = !! visited[ s.id ] && i < index();
					if ( 'google' === s.id || 'bing' === s.id ) {
						done = !! connected[ s.id ];
					} else if ( 'indexnow' === s.id ) {
						done = !! connected.indexnow || done;
					}
					return Object.assign( {}, s, { done } );
				} );
				SPP.mount( stepper, ui.stepper( { steps, current: state.step, onSelect: ( id ) => go( id ) } ) );
				api.get( '/engines' )
					.then( ( res ) => {
						const s = ( res && ( res.status || res ) ) || {};
						const next = {
							google: !! ( s.google && s.google.connected ),
							bing: !! ( s.bing && s.bing.connected ),
							indexnow: !! ( s.indexnow && s.indexnow.enabled ),
						};
						if ( JSON.stringify( next ) !== JSON.stringify( state.connected || {} ) ) {
							state.connected = next;
							render();
						}
					} )
					.catch( () => null );
				SPP.mount( content, renderStep() );
				const i = index();
				SPP.mount(
					footer,
					i > 0 ? ui.button( { label: __( 'Back', 'searchpilot-pro' ), onClick: () => go( STEPS[ i - 1 ].id ) } ) : ui.button( { label: __( 'Skip setup for now', 'searchpilot-pro' ), variant: 'ghost', href: SPP.pageUrl( 'dashboard' ) } ),
					state.step === 'finish'
						? ui.button( {
								label: __( 'Finish setup', 'searchpilot-pro' ),
								variant: 'gold',
								icon: 'rocket',
								onClick: () =>
									saveDraft()
										.then( () => api.post( '/wizard/complete' ) )
										.then( () => {
											SPP.boot.wizardCompleted = true;
											ui.toast( __( 'Setup complete — SearchPilot is now working in the background.', 'searchpilot-pro' ) );
											setTimeout( () => SPP.go( 'dashboard' ), 800 );
										} ),
						  } )
						: ui.button( { label: __( 'Continue', 'searchpilot-pro' ), variant: 'primary', onClick: () => go( STEPS[ i + 1 ].id ) } )
				);
			};

			SPP.mount(
				el,
				h(
					'div',
					{ class: 'spp-wizard' },
					h(
						'div',
						{ class: 'spp-topbar', style: { borderBottom: '0', marginBottom: '8px' } },
						h(
							'div',
							{ class: 'spp-brand' },
							h( 'span', { class: 'spp-brand-mark', 'aria-hidden': 'true' }, h( 'svg', { viewBox: '0 0 20 20' }, h( 'path', { fill: '#fff', d: 'M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z' } ), h( 'path', { fill: '#d9bd8c', d: 'M11.1 5.6 9.8 9.8l-4.2 1.3 1.3-4.2z' } ) ) ),
							h( 'div', null, h( 'div', { class: 'spp-brand-name' }, 'SearchPilot ', h( 'em', null, 'Pro' ) ), h( 'div', { class: 'spp-brand-sub' }, __( 'Setup wizard', 'searchpilot-pro' ) ) )
						)
					),
					ui.pageHeader( {
						title: __( 'Let’s get your site discovered', 'searchpilot-pro' ),
						subtitle: __( 'A few short steps. Nothing is sent to search engines until you finish, and you can change everything later.', 'searchpilot-pro' ),
					} ),
					stepper,
					content,
					footer
				)
			);
			SPP.mount( content, ui.loading() );

			Promise.all( [ api.get( '/settings' ), api.get( '/wizard' ) ] )
				.then( ( [ settings, wizard ] ) => {
					state.settings = settings;
					const requested = SPP.query.get( 'step' );
					const known = STEPS.map( ( s ) => s.id );
					state.step = known.indexOf( requested ) !== -1 ? requested : known.indexOf( wizard.step ) !== -1 && ! wizard.completed ? wizard.step : 'audit';
					if ( wizard.audit && wizard.audit.checks ) {
						state.audit = null; // Always re-run on open for fresh results.
					}
					render();
				} )
				.catch( ( err ) => SPP.mount( content, ui.errorBox( err ) ) );
		},
	} );
} )( window.wp, window.SPP );

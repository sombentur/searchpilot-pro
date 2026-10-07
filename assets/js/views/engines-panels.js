/**
 * Reusable search-engine connection panels (Search Engines screen and setup wizard).
 *
 * window.SPP.engines = { google( opts ), bing( opts ), indexnow( opts ), load( force ) }
 * Each panel returns a DOM node. opts.compact renders a tighter version for the
 * setup wizard (no outer card); opts.onChange( status, data ) fires after changes.
 *
 * Credentials are write-only: secret fields are never pre-filled, the API only
 * reports whether a value is set.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const LINKS = {
		cloudProject: 'https://console.cloud.google.com/projectcreate',
		cloudSearchConsoleApi: 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
		cloudIndexingApi: 'https://console.cloud.google.com/apis/library/indexing.googleapis.com',
		cloudConsent: 'https://console.cloud.google.com/apis/credentials/consent',
		cloudCredentials: 'https://console.cloud.google.com/apis/credentials',
		cloudServiceAccounts: 'https://console.cloud.google.com/iam-admin/serviceaccounts',
		searchConsole: 'https://search.google.com/search-console',
		searchConsoleUsers: 'https://search.google.com/search-console/users',
		bingWebmaster: 'https://www.bing.com/webmasters',
		indexNow: 'https://www.indexnow.org/documentation',
	};

	const OAUTH_ERRORS = {
		access_denied: __( 'Google sign-in was cancelled, so nothing was connected.', 'searchpilot-pro' ),
		invalid_state: __( 'The Google sign-in response could not be verified. Start the connection again from this screen.', 'searchpilot-pro' ),
		expired_state: __( 'The Google sign-in took too long or was already used. Start the connection again.', 'searchpilot-pro' ),
		forbidden: __( 'Only SearchPilot administrators can connect Google.', 'searchpilot-pro' ),
		missing_code: __( 'Google did not return an authorization code. Try again.', 'searchpilot-pro' ),
		token_exchange_failed: __( 'Google did not issue tokens. Check the client ID and secret, then try again. Details are in System Logs.', 'searchpilot-pro' ),
		invalid_client: __( 'Google rejected the OAuth client ID or secret. Copy both again from Google Cloud → Credentials.', 'searchpilot-pro' ),
		redirect_uri_mismatch: __( 'The redirect URI in your Google OAuth client does not match. Copy the URI shown in step 4 exactly into “Authorized redirect URIs”.', 'searchpilot-pro' ),
		missing_refresh_token: __( 'Google did not return a refresh token. Remove SearchPilot’s access at myaccount.google.com → Security → Third-party access, then connect again.', 'searchpilot-pro' ),
		storage_failed: __( 'The tokens could not be stored securely on this server. See System Logs.', 'searchpilot-pro' ),
		no_client: __( 'Save your OAuth client ID and secret first.', 'searchpilot-pro' ),
	};

	/* ------------------------------------------------------------------ *
	 * Shared data
	 * ------------------------------------------------------------------ */

	let cache = null;

	function load( force ) {
		if ( force || ! cache ) {
			cache = api.get( '/engines' ).catch( ( err ) => {
				cache = null;
				throw err;
			} );
		}
		return cache;
	}

	function invalidate() {
		cache = null;
	}

	let queryHandled = false;

	/** Shows the result of the OAuth redirect once and cleans the URL. */
	function handleOAuthQuery() {
		if ( queryHandled ) {
			return;
		}
		queryHandled = true;
		const connected = SPP.query.get( 'google' ) === 'connected';
		const error = SPP.query.get( 'google_error' );
		const noProperty = SPP.query.get( 'google_property' ) === 'missing';
		if ( connected ) {
			ui.toast( __( 'Google Search Console connected.', 'searchpilot-pro' ) );
			if ( noProperty ) {
				ui.toast( __( 'No Search Console property matches this site automatically. Choose one in the Google panel.', 'searchpilot-pro' ), 'warning', 9000 );
			}
		}
		if ( error ) {
			ui.toast( OAUTH_ERRORS[ error ] || OAUTH_ERRORS.token_exchange_failed, 'error', 10000 );
		}
		if ( connected || error || noProperty ) {
			SPP.query.set( { google: null, google_error: null, google_property: null } );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	function ext( text, href ) {
		return ui.link( text, href, { external: true } );
	}

	function steps( items ) {
		return h(
			'ol',
			{ class: 'spp-list spp-small' },
			items.map( ( item, i ) => h( 'li', null, h( 'div', { class: 'spp-row is-top', style: { flexWrap: 'nowrap' } }, h( 'span', { class: 'spp-kbd' }, String( i + 1 ) ), h( 'div', { class: 'spp-grow' }, item ) ) ) )
		);
	}

	function statusBadge( tone, text ) {
		return ui.badge( text, tone, { dot: true } );
	}

	/**
	 * Panel shell: a card normally, a plain stack in compact mode (the wizard wraps it).
	 */
	function shell( opts, { title, subtitle, icon }, render ) {
		const badgeSlot = h( 'span' );
		const region = SPP.region( () =>
			load().then( ( data ) => {
				const out = render( data, badgeSlot );
				return out;
			} )
		);
		if ( opts.compact ) {
			return { el: h( 'div', { class: 'spp-stack' }, h( 'div', { class: 'spp-row is-between' }, h( 'span', { class: 'spp-muted spp-small' }, __( 'Status', 'searchpilot-pro' ) ), badgeSlot ), region.el ), region };
		}
		const card = ui.card( {
			title: h( 'span', { class: 'spp-row' }, SPP.icon( icon, 18 ), title ),
			subtitle,
			actions: badgeSlot,
			body: region.el,
		} );
		return { el: card, region };
	}

	function changed( opts, region, key ) {
		invalidate();
		return region.refresh().then( () =>
			load().then( ( data ) => {
				if ( opts.onChange ) {
					opts.onChange( data[ key ], data );
				}
				return data;
			} )
		);
	}

	function fieldNote( cred, constantText ) {
		if ( ! cred ) {
			return '';
		}
		if ( cred.constant ) {
			return constantText;
		}
		return cred.set ? sprintf( /* translators: %s: masked value. */ __( 'Saved (%s). Leave empty to keep it.', 'searchpilot-pro' ), cred.masked ) : '';
	}

	/* ------------------------------------------------------------------ *
	 * Google
	 * ------------------------------------------------------------------ */

	function google( opts ) {
		opts = opts || {};
		handleOAuthQuery();
		const returnTo = opts.returnTo || ( opts.compact ? 'setup' : 'engines' );
		const local = { method: null, replaceClient: false, properties: null, showProperties: false };

		const connect = () =>
			api.post( '/engines/google/connect', { return: returnTo } ).then( ( res ) => {
				window.location.href = res.auth_url;
				return new Promise( () => {} ); // Keep the button busy while the browser leaves.
			} );

		const shellParts = shell(
			opts,
			{
				title: __( 'Google Search Console', 'searchpilot-pro' ),
				subtitle: __( 'Performance data, URL Inspection and sitemap submission through Google’s official API.', 'searchpilot-pro' ),
				icon: 'search',
			},
			( data, badgeSlot ) => {
				const g = data.google || {};
				const setup = data.google_setup || {};
				const cred = data.credentials || {};
				const authorized = setup.method === 'service_account' ? !! ( cred.google_sa && cred.google_sa.json.set ) : !! ( cred.google && cred.google.authorized && cred.google.authorized.set );

				let tone = 'outline';
				let label = __( 'Not connected', 'searchpilot-pro' );
				if ( g.error ) {
					tone = 'critical';
					label = __( 'Error', 'searchpilot-pro' );
				} else if ( g.connected ) {
					tone = 'passed';
					label = __( 'Connected', 'searchpilot-pro' );
				} else if ( authorized ) {
					tone = 'high';
					label = __( 'Choose a property', 'searchpilot-pro' );
				}
				SPP.mount( badgeSlot, statusBadge( tone, label ) );

				const parts = [];
				if ( g.error ) {
					parts.push(
						ui.notice( {
							tone: 'critical',
							title: __( 'Google rejected the connection', 'searchpilot-pro' ),
							text: g.error,
							actions: setup.method === 'oauth' && setup.has_client ? [ ui.button( { label: __( 'Reconnect Google', 'searchpilot-pro' ), variant: 'primary', size: 'small', icon: 'refresh', onClick: connect } ) ] : null,
						} )
					);
				}
				if ( authorized ) {
					parts.push( connectedView( data ) );
				} else {
					parts.push( setupView( data ) );
				}
				if ( ! opts.compact && ( authorized || g.connected ) ) {
					parts.push( indexingView( data ) );
				}
				return h( 'div', { class: 'spp-stack' }, parts );
			}
		);
		const region = shellParts.region;
		const refresh = () => changed( opts, region, 'google' );

		/* ---------- connected ---------- */

		function connectedView( data ) {
			const g = data.google || {};
			const setup = data.google_setup || {};
			const propertySlot = h( 'div' );

			const renderPicker = () => {
				SPP.mount( propertySlot, ui.loading( __( 'Loading your Search Console properties…', 'searchpilot-pro' ) ) );
				api.get( '/engines/google/properties' )
					.then( ( res ) => {
						const items = res.items || [];
						if ( ! items.length ) {
							SPP.mount(
								propertySlot,
								ui.notice( {
									tone: 'high',
									title: __( 'No Search Console properties found', 'searchpilot-pro' ),
									text:
										setup.method === 'service_account'
											? sprintf( /* translators: %s: service account e-mail. */ __( 'Add %s as an Owner (or Full user) of this site’s property in Search Console → Settings → Users and permissions, then reload.', 'searchpilot-pro' ), setup.service_account )
											: __( 'The Google account you connected has no verified property. Add and verify this site in Search Console, then reload.', 'searchpilot-pro' ),
									actions: [ ext( __( 'Open Search Console', 'searchpilot-pro' ), setup.method === 'service_account' ? LINKS.searchConsoleUsers : LINKS.searchConsole ) ],
								} )
							);
							return;
						}
						let choice = res.selected || res.recommended || ( items.find( ( i ) => i.usable ) || {} ).site_url || '';
						const levels = {
							siteOwner: __( 'Owner', 'searchpilot-pro' ),
							siteFullUser: __( 'Full user', 'searchpilot-pro' ),
							siteRestrictedUser: __( 'Restricted user', 'searchpilot-pro' ),
							siteUnverifiedUser: __( 'Unverified', 'searchpilot-pro' ),
						};
						SPP.mount(
							propertySlot,
							h(
								'div',
								{ class: 'spp-stack' },
								ui.field.select( {
									label: __( 'Search Console property', 'searchpilot-pro' ),
									value: choice,
									help: __( 'A Domain property (sc-domain:) covers every protocol and subdomain and is recommended. URL-prefix properties must match this site’s address exactly.', 'searchpilot-pro' ),
									options: items.map( ( i ) => ( {
										value: i.site_url,
										disabled: ! i.usable,
										label:
											i.site_url +
											' — ' +
											( levels[ i.permission_level ] || i.permission_level ) +
											( i.recommended ? ' · ' + __( 'recommended', 'searchpilot-pro' ) : '' ) +
											( ! i.matches_site ? ' · ' + __( 'does not match this site', 'searchpilot-pro' ) : '' ),
									} ) ),
									onChange: ( v ) => ( choice = v ),
								} ),
								h(
									'div',
									{ class: 'spp-row' },
									ui.button( {
										label: __( 'Use this property', 'searchpilot-pro' ),
										variant: 'primary',
										size: 'small',
										onClick: () =>
											api.post( '/engines/google/property', { property: choice } ).then( () => {
												ui.toast( __( 'Property saved.', 'searchpilot-pro' ) );
												local.showProperties = false;
												return refresh();
											} ),
									} ),
									g.property
										? ui.button( {
												label: __( 'Cancel', 'searchpilot-pro' ),
												size: 'small',
												variant: 'ghost',
												onClick: () => {
													local.showProperties = false;
													SPP.mount( propertySlot );
												},
										  } )
										: null
								)
							)
						);
					} )
					.catch( ( err ) => SPP.mount( propertySlot, ui.errorBox( err ) ) );
			};

			if ( ! g.property || local.showProperties ) {
				renderPicker();
			}

			const methodLabel = setup.method === 'service_account' ? __( 'Service account', 'searchpilot-pro' ) : __( 'OAuth (your Google account)', 'searchpilot-pro' );
			return h(
				'div',
				{ class: 'spp-stack' },
				ui.dl( [
					[ __( 'Method', 'searchpilot-pro' ), methodLabel ],
					setup.method === 'service_account' ? [ __( 'Service account', 'searchpilot-pro' ), h( 'span', { class: 'spp-mono spp-break' }, setup.service_account || g.account ) ] : null,
					[ __( 'Property', 'searchpilot-pro' ), g.property ? h( 'span', { class: 'spp-mono spp-break' }, g.property ) : ui.badge( __( 'Not selected', 'searchpilot-pro' ), 'high' ) ],
					! opts.compact ? [ __( 'Indexing API', 'searchpilot-pro' ), g.indexing_api ? ui.badge( __( 'On', 'searchpilot-pro' ), 'passed' ) : ui.badge( __( 'Off', 'searchpilot-pro' ), 'outline' ) ] : null,
					[ __( 'Last checked', 'searchpilot-pro' ), g.checked_at ? h( 'span', { title: fmt.datetime( g.checked_at ) }, fmt.relative( g.checked_at ) ) : '—' ],
				] ),
				propertySlot,
				h(
					'div',
					{ class: 'spp-row' },
					ui.button( {
						label: __( 'Test connection', 'searchpilot-pro' ),
						size: 'small',
						icon: 'check',
						onClick: () =>
							api.post( '/engines/google/test' ).then( ( res ) => {
								ui.toast( res.message, res.ok ? 'success' : 'warning', 7000 );
								return refresh();
							} ),
					} ),
					g.property
						? ui.button( {
								label: __( 'Change property', 'searchpilot-pro' ),
								size: 'small',
								variant: 'ghost',
								onClick: () => {
									local.showProperties = true;
									renderPicker();
								},
						  } )
						: null,
					setup.method === 'oauth' && setup.has_client ? ui.button( { label: __( 'Reconnect', 'searchpilot-pro' ), size: 'small', variant: 'ghost', icon: 'refresh', onClick: connect } ) : null,
					ui.button( {
						label: __( 'Disconnect', 'searchpilot-pro' ),
						size: 'small',
						variant: 'danger',
						onClick: () =>
							ui
								.confirm( {
									title: __( 'Disconnect Google?', 'searchpilot-pro' ),
									message: __( 'SearchPilot revokes its access token at Google and deletes the stored tokens (and the service-account key, if one was saved). Performance data already imported is kept.', 'searchpilot-pro' ),
									confirmLabel: __( 'Disconnect', 'searchpilot-pro' ),
									danger: true,
								} )
								.then( ( ok ) =>
									ok
										? api.post( '/engines/google/disconnect' ).then( () => {
												ui.toast( __( 'Google disconnected.', 'searchpilot-pro' ) );
												return refresh();
										  } )
										: null
								),
					} )
				)
			);
		}

		/* ---------- not connected: OAuth or service account ---------- */

		function setupView( data ) {
			const setup = data.google_setup || {};
			const cred = data.credentials || {};
			const method = local.method || ( setup.method === 'service_account' ? 'service_account' : 'oauth' );
			const body = h( 'div' );

			const renderMethod = ( m ) => {
				local.method = m;
				SPP.mount( body, m === 'service_account' ? serviceAccountForm( data ) : oauthForm( data ) );
			};
			renderMethod( method );

			return h(
				'div',
				{ class: 'spp-stack' },
				h(
					'div',
					{ class: 'spp-row is-between' },
					h( 'span', { class: 'spp-strong' }, __( 'How should SearchPilot sign in?', 'searchpilot-pro' ) ),
					ui.buttonGroup( {
						options: [
							{ value: 'oauth', label: __( 'OAuth (recommended)', 'searchpilot-pro' ) },
							{ value: 'service_account', label: __( 'Service account', 'searchpilot-pro' ) },
						],
						value: method,
						onChange: renderMethod,
					} )
				),
				body,
				cred.google && cred.google.client_id && cred.google.client_id.constant ? h( 'p', { class: 'spp-muted spp-small' }, __( 'The OAuth client is defined in wp-config.php (SEARCHPILOT_GOOGLE_CLIENT_ID / SEARCHPILOT_GOOGLE_CLIENT_SECRET).', 'searchpilot-pro' ) ) : null
			);
		}

		function oauthForm( data ) {
			const setup = data.google_setup || {};
			const cred = ( data.credentials || {} ).google || {};
			const values = { client_id: '', client_secret: '' };
			const showForm = ! setup.has_client || local.replaceClient;

			const instructions = steps( [
				[ ext( __( 'Create a Google Cloud project', 'searchpilot-pro' ), LINKS.cloudProject ), ' ', __( '(or pick an existing one).', 'searchpilot-pro' ) ],
				[ __( 'Enable the', 'searchpilot-pro' ), ' ', ext( __( 'Google Search Console API', 'searchpilot-pro' ), LINKS.cloudSearchConsoleApi ), '.' ],
				[ __( 'Configure the', 'searchpilot-pro' ), ' ', ext( __( 'OAuth consent screen', 'searchpilot-pro' ), LINKS.cloudConsent ), ' ', __( '(External, add your Google account as a test user, or publish the app).', 'searchpilot-pro' ) ],
				h(
					'div',
					null,
					h( 'div', null, __( 'Create credentials → OAuth client ID → Web application, and add this exact Authorized redirect URI:', 'searchpilot-pro' ), ' ', ext( __( 'Credentials', 'searchpilot-pro' ), LINKS.cloudCredentials ) ),
					h( 'div', { style: { marginTop: '6px' } }, ui.copyField( data.redirect_uri || '' ) )
				),
				__( 'Paste the client ID and client secret below, then sign in with the Google account that owns the site in Search Console.', 'searchpilot-pro' ),
			] );

			const form = showForm
				? h(
						'div',
						{ class: 'spp-stack' },
						ui.field.text( {
							label: __( 'Client ID', 'searchpilot-pro' ),
							placeholder: cred.client_id && cred.client_id.set ? cred.client_id.masked : '123456789012-abc….apps.googleusercontent.com',
							help: fieldNote( cred.client_id, __( 'Defined in wp-config.php.', 'searchpilot-pro' ) ),
							readOnly: !! ( cred.client_id && cred.client_id.constant ),
							onInput: ( v ) => ( values.client_id = v.trim() ),
						} ),
						ui.field.text( {
							label: __( 'Client secret', 'searchpilot-pro' ),
							type: 'password',
							placeholder: cred.client_secret && cred.client_secret.set ? cred.client_secret.masked : 'GOCSPX-…',
							help: fieldNote( cred.client_secret, __( 'Defined in wp-config.php.', 'searchpilot-pro' ) ),
							readOnly: !! ( cred.client_secret && cred.client_secret.constant ),
							onInput: ( v ) => ( values.client_secret = v.trim() ),
						} ),
						h(
							'div',
							{ class: 'spp-row' },
							ui.button( {
								label: __( 'Save and connect with Google', 'searchpilot-pro' ),
								variant: 'primary',
								icon: 'external',
								busyLabel: __( 'Opening Google…', 'searchpilot-pro' ),
								onClick: () => {
									const hasId = !! ( cred.client_id && cred.client_id.set );
									const hasSecret = !! ( cred.client_secret && cred.client_secret.set );
									if ( ! values.client_id && ! hasId ) {
										return Promise.reject( new Error( __( 'Enter the client ID.', 'searchpilot-pro' ) ) );
									}
									if ( ! values.client_secret && ! hasSecret ) {
										return Promise.reject( new Error( __( 'Enter the client secret.', 'searchpilot-pro' ) ) );
									}
									if ( ! values.client_id && ! values.client_secret ) {
										// Nothing new typed: reuse the saved client.
										return connect();
									}
									return api.post( '/engines/google/credentials', { client_id: values.client_id, client_secret: values.client_secret } ).then( () => {
										invalidate();
										return connect();
									} );
								},
							} ),
							local.replaceClient
								? ui.button( {
										label: __( 'Cancel', 'searchpilot-pro' ),
										variant: 'ghost',
										onClick: () => {
											local.replaceClient = false;
											region.refresh();
										},
								  } )
								: null
						)
				  )
				: h(
						'div',
						{ class: 'spp-row' },
						ui.button( { label: __( 'Connect with Google', 'searchpilot-pro' ), variant: 'primary', icon: 'external', busyLabel: __( 'Opening Google…', 'searchpilot-pro' ), onClick: connect } ),
						cred.client_id && cred.client_id.constant
							? null
							: ui.button( {
									label: __( 'Replace client ID / secret', 'searchpilot-pro' ),
									variant: 'ghost',
									onClick: () => {
										local.replaceClient = true;
										region.refresh();
									},
							  } )
				  );

			return h(
				'div',
				{ class: 'spp-stack' },
				opts.compact && setup.has_client ? null : instructions,
				form,
				h(
					'p',
					{ class: 'spp-disclaimer' },
					SPP.icon( 'shield', 14 ),
					__( 'SearchPilot asks only for the Search Console permission (https://www.googleapis.com/auth/webmasters). Write access is needed to submit sitemaps; SearchPilot never changes other Search Console settings. The Indexing API permission is requested only if you switch it on. Tokens are stored encrypted on your server.', 'searchpilot-pro' )
				)
			);
		}

		function serviceAccountForm( data ) {
			const cred = ( data.credentials || {} ).google_sa || { json: {} };
			let json = '';
			return h(
				'div',
				{ class: 'spp-stack' },
				steps( [
					[ ext( __( 'Create a Google Cloud project', 'searchpilot-pro' ), LINKS.cloudProject ), ' ', __( 'and enable the', 'searchpilot-pro' ), ' ', ext( __( 'Google Search Console API', 'searchpilot-pro' ), LINKS.cloudSearchConsoleApi ), '.' ],
					[ ext( __( 'Create a service account', 'searchpilot-pro' ), LINKS.cloudServiceAccounts ), ' ', __( '→ Keys → Add key → JSON. A key file downloads.', 'searchpilot-pro' ) ],
					[ __( 'In Search Console → Settings →', 'searchpilot-pro' ), ' ', ext( __( 'Users and permissions', 'searchpilot-pro' ), LINKS.searchConsoleUsers ), ', ', __( 'add the service-account e-mail (…@….iam.gserviceaccount.com) as an Owner or Full user.', 'searchpilot-pro' ) ],
					__( 'Paste the whole JSON file below. It is validated, tested against Google and stored encrypted.', 'searchpilot-pro' ),
				] ),
				cred.json && cred.json.constant
					? ui.notice( { tone: 'info', text: __( 'The service-account key is defined in wp-config.php (SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT).', 'searchpilot-pro' ) } )
					: ui.field.textarea( {
							label: __( 'Service-account JSON key', 'searchpilot-pro' ),
							rows: 6,
							code: true,
							placeholder: '{ "type": "service_account", "client_email": "…", "private_key": "…", … }',
							help: cred.json && cred.json.set ? sprintf( /* translators: %s: e-mail. */ __( 'A key for %s is saved. Paste a new key to replace it.', 'searchpilot-pro' ), cred.json.masked ) : '',
							onInput: ( v ) => ( json = v ),
					  } ),
				cred.json && cred.json.constant
					? null
					: h(
							'div',
							{ class: 'spp-row' },
							ui.button( {
								label: __( 'Save and test key', 'searchpilot-pro' ),
								variant: 'primary',
								icon: 'check',
								onClick: () => {
									if ( ! json.trim() ) {
										return Promise.reject( new Error( __( 'Paste the JSON key first.', 'searchpilot-pro' ) ) );
									}
									return api.post( '/engines/google/service-account', { json } ).then( ( res ) => {
										json = '';
										ui.toast( res.warning || __( 'Service account connected.', 'searchpilot-pro' ), res.warning ? 'warning' : 'success', res.warning ? 10000 : 3500 );
										return refresh();
									} );
								},
							} )
					  )
			);
		}

		/* ---------- Indexing API ---------- */

		function indexingView( data ) {
			const setup = data.google_setup || {};
			const state = { enabled: !! setup.indexing_setting, types: ( setup.indexing_post_types || [] ).slice() };
			const reconnectSlot = h( 'div' );

			const renderReconnect = ( needs ) => {
				SPP.mount(
					reconnectSlot,
					needs
						? ui.notice( {
								tone: 'high',
								title: __( 'Grant the Indexing API permission', 'searchpilot-pro' ),
								text: __( 'Your Google connection was made without the Indexing API permission. Reconnect Google and allow it. The “Web Search Indexing API” must also be enabled in your Google Cloud project.', 'searchpilot-pro' ),
								actions: [ ui.button( { label: __( 'Reconnect Google', 'searchpilot-pro' ), variant: 'primary', size: 'small', icon: 'external', onClick: connect } ), ext( __( 'Enable the Indexing API', 'searchpilot-pro' ), LINKS.cloudIndexingApi ) ],
						  } )
						: null
				);
			};
			renderReconnect( setup.needs_reconnect );

			return h(
				'div',
				null,
				h( 'hr', { class: 'spp-divider' } ),
				h(
					'div',
					{ class: 'spp-stack' },
					h( 'div', null, h( 'div', { class: 'spp-strong' }, __( 'Google Indexing API (job postings and livestreams only)', 'searchpilot-pro' ) ), h( 'p', { class: 'spp-muted spp-small' }, __( 'Ordinary pages and news articles are discovered through your sitemap — Google does not accept them through this API.', 'searchpilot-pro' ) ) ),
					ui.notice( {
						tone: 'medium',
						icon: 'alert',
						text: __( 'Google supports the Indexing API only for pages with JobPosting or livestream (BroadcastEvent inside VideoObject) structured data and may revoke access if it is used for anything else. Before sending, SearchPilot checks the published page itself: a page is sent only when its structured data really contains one of these types. Ticking a post type or marking a post in the SearchPilot sidebar only nominates it. SearchPilot never adds this markup itself — it must come from your job-board or event plugin or theme. Pages that fail the check are skipped, with the reason shown in the History tab.', 'searchpilot-pro' ),
					} ),
					ui.field.toggle( {
						label: __( 'Use the Indexing API for eligible pages', 'searchpilot-pro' ),
						checked: state.enabled,
						onChange: ( v ) => ( state.enabled = v ),
					} ),
					( setup.indexing_types || [] ).length
						? ui.field.checklist( {
								label: __( 'Custom post types used for job postings or livestreams', 'searchpilot-pro' ),
								options: setup.indexing_types.map( ( p ) => ( { value: p.value, label: p.label } ) ),
								values: state.types,
								help: __( 'Posts, pages and article types are never offered here. Leave all unticked on a news site.', 'searchpilot-pro' ),
								onChange: ( v ) => ( state.types = v ),
						  } )
						: h( 'p', { class: 'spp-muted spp-small' }, __( 'This site has no custom post types that could hold job postings or livestreams. Individual posts can still be nominated in the SearchPilot editor panel; they are sent only if their page carries the required structured data.', 'searchpilot-pro' ) ),
					reconnectSlot,
					h(
						'div',
						{ class: 'spp-row' },
						ui.button( {
							label: __( 'Save Indexing API settings', 'searchpilot-pro' ),
							size: 'small',
							onClick: () =>
								api.post( '/engines/google/indexing-api', { enabled: state.enabled, post_types: state.types } ).then( ( res ) => {
									ui.toast( __( 'Indexing API settings saved.', 'searchpilot-pro' ) );
									renderReconnect( res.needs_reconnect );
									invalidate();
									if ( opts.onChange ) {
										opts.onChange( res.google, res );
									}
								} ),
						} ),
						ext( __( 'Google’s Indexing API guidelines', 'searchpilot-pro' ), 'https://developers.google.com/search/apis/indexing-api/v3/quickstart' )
					)
				)
			);
		}

		return shellParts.el;
	}

	/* ------------------------------------------------------------------ *
	 * Bing
	 * ------------------------------------------------------------------ */

	function bing( opts ) {
		opts = opts || {};
		const local = { replace: false };
		const parts = shell(
			opts,
			{
				title: __( 'Bing Webmaster Tools', 'searchpilot-pro' ),
				subtitle: __( 'Bing traffic and crawl data, sitemap submission and (optional) URL submission.', 'searchpilot-pro' ),
				icon: 'globe',
			},
			( data, badgeSlot ) => {
				const b = data.bing || {};
				const cred = ( data.credentials || {} ).bing || {};
				const key = cred.api_key || {};
				const setup = data.bing_setup || {};
				const configured = !! key.set;

				let tone = 'outline';
				let label = __( 'Not connected', 'searchpilot-pro' );
				if ( b.error ) {
					tone = 'critical';
					label = __( 'Error', 'searchpilot-pro' );
				} else if ( b.connected ) {
					tone = 'passed';
					label = __( 'Connected', 'searchpilot-pro' );
				}
				SPP.mount( badgeSlot, statusBadge( tone, label ) );

				const values = { api_key: '', site: b.site || setup.default_site || '' };
				const form = h(
					'div',
					{ class: 'spp-stack' },
					configured && ! local.replace
						? null
						: steps( [
								[ __( 'Sign in to', 'searchpilot-pro' ), ' ', ext( __( 'Bing Webmaster Tools', 'searchpilot-pro' ), LINKS.bingWebmaster ), ' ', __( 'and add and verify this site (importing it from Google Search Console is fastest).', 'searchpilot-pro' ) ],
								__( 'Open Settings (gear icon) → API access → API Key, generate a key and copy it.', 'searchpilot-pro' ),
								__( 'Paste it below. SearchPilot checks that the site is verified in that account.', 'searchpilot-pro' ),
						  ] ),
					key.constant
						? ui.notice( { tone: 'info', text: __( 'The API key is defined in wp-config.php (SEARCHPILOT_BING_API_KEY).', 'searchpilot-pro' ) } )
						: ui.field.text( {
								label: __( 'API key', 'searchpilot-pro' ),
								type: 'password',
								placeholder: key.set ? key.masked : __( '32 letters and digits', 'searchpilot-pro' ),
								help: fieldNote( key, '' ),
								onInput: ( v ) => ( values.api_key = v.trim() ),
						  } ),
					ui.field.text( {
						label: __( 'Site URL in Bing', 'searchpilot-pro' ),
						value: values.site,
						help: __( 'Exactly as the site appears in Bing Webmaster Tools.', 'searchpilot-pro' ),
						onInput: ( v ) => ( values.site = v.trim() ),
					} ),
					h(
						'div',
						{ class: 'spp-row' },
						ui.button( {
							label: configured ? __( 'Validate and save', 'searchpilot-pro' ) : __( 'Connect Bing', 'searchpilot-pro' ),
							variant: 'primary',
							icon: 'check',
							onClick: () => {
								if ( ! values.api_key && ! key.set ) {
									return Promise.reject( new Error( __( 'Enter your Bing Webmaster API key.', 'searchpilot-pro' ) ) );
								}
								return api.post( '/engines/bing', values ).then( () => {
									ui.toast( __( 'Bing Webmaster Tools connected.', 'searchpilot-pro' ) );
									local.replace = false;
									return changed( opts, parts.region, 'bing' );
								} );
							},
						} ),
						local.replace
							? ui.button( {
									label: __( 'Cancel', 'searchpilot-pro' ),
									variant: 'ghost',
									onClick: () => {
										local.replace = false;
										parts.region.refresh();
									},
							  } )
							: null
					)
				);

				const out = [];
				if ( b.error ) {
					out.push( ui.notice( { tone: 'critical', title: __( 'Bing rejected the connection', 'searchpilot-pro' ), text: b.error } ) );
				}
				if ( configured && ! local.replace ) {
					const quota = b.quota;
					out.push(
						ui.dl( [
							[ __( 'Site', 'searchpilot-pro' ), h( 'span', { class: 'spp-mono spp-break' }, fmt.url( b.site ) ) ],
							[
								__( 'URL submission quota', 'searchpilot-pro' ),
								quota ? sprintf( /* translators: 1: daily remaining, 2: monthly remaining. */ __( '%1$s left today · %2$s this month', 'searchpilot-pro' ), fmt.number( quota.daily ), fmt.number( quota.monthly ) ) : '—',
							],
							[ __( 'Last checked', 'searchpilot-pro' ), setup.checked_at ? h( 'span', { title: fmt.datetime( setup.checked_at ) }, fmt.relative( setup.checked_at ) ) : '—' ],
						] )
					);
					if ( ! opts.compact ) {
						out.push( bingReports( data ) );
						out.push(
							ui.field.toggle( {
								label: __( 'Also submit changed URLs through the Bing URL Submission API', 'searchpilot-pro' ),
								checked: !! setup.url_submission,
								help: __( 'Optional. IndexNow already notifies Bing instantly; this API uses your daily quota. Useful when IndexNow is handled elsewhere or switched off.', 'searchpilot-pro' ),
								onChange: ( v ) =>
									api
										.post( '/settings', { settings: { indexing: { bing_url_submission: v } } } )
										.then( () => {
											ui.toast( v ? __( 'Bing URL submission switched on.', 'searchpilot-pro' ) : __( 'Bing URL submission switched off.', 'searchpilot-pro' ) );
											invalidate();
										} )
										.catch( ( err ) => ui.toast( err.message, 'error' ) ),
							} )
						);
					}
					out.push(
						h(
							'div',
							{ class: 'spp-row' },
							ui.button( {
								label: __( 'Test connection', 'searchpilot-pro' ),
								size: 'small',
								icon: 'check',
								onClick: () =>
									api.post( '/engines/bing/test' ).then( ( res ) => {
										ui.toast( res.message );
										return changed( opts, parts.region, 'bing' );
									} ),
							} ),
							key.constant
								? null
								: ui.button( {
										label: __( 'Replace API key', 'searchpilot-pro' ),
										size: 'small',
										variant: 'ghost',
										onClick: () => {
											local.replace = true;
											parts.region.refresh();
										},
								  } ),
							key.constant
								? null
								: ui.button( {
										label: __( 'Disconnect', 'searchpilot-pro' ),
										size: 'small',
										variant: 'danger',
										onClick: () =>
											ui
												.confirm( {
													title: __( 'Disconnect Bing?', 'searchpilot-pro' ),
													message: __( 'The stored API key is deleted. Bing data already imported is kept. IndexNow is not affected.', 'searchpilot-pro' ),
													confirmLabel: __( 'Disconnect', 'searchpilot-pro' ),
													danger: true,
												} )
												.then( ( ok ) =>
													ok
														? api.post( '/engines/bing/disconnect' ).then( () => {
																ui.toast( __( 'Bing disconnected.', 'searchpilot-pro' ) );
																return changed( opts, parts.region, 'bing' );
														  } )
														: null
												),
								  } )
						)
					);
				} else {
					out.push( form );
				}
				return h( 'div', { class: 'spp-stack' }, out );
			}
		);
		return parts.el;
	}

	/**
	 * Cached Bing crawl statistics (30 days) and the sitemaps Bing knows.
	 */
	function bingReports( data ) {
		const crawl = data.bing_crawl;
		const feeds = data.bing_feeds;
		const parts = [];
		if ( crawl && crawl.series && crawl.series.length ) {
			const t = crawl.totals || {};
			parts.push(
				h(
					'div',
					null,
					h(
						'div',
						{ class: 'spp-row is-between' },
						h( 'span', { class: 'spp-strong' }, sprintf( /* translators: %d: number of days. */ __( 'Bing crawl, last %d days', 'searchpilot-pro' ), crawl.days || 30 ) ),
						crawl.series.length > 1 ? ui.sparkline( crawl.series.map( ( r ) => r.crawled_pages ), { width: 120 } ) : null
					),
					ui.dl( [
						[ __( 'Pages crawled', 'searchpilot-pro' ), fmt.number( t.crawled_pages ) ],
						[ __( 'Crawl errors', 'searchpilot-pro' ), fmt.number( t.crawl_errors ) ],
						[ __( 'Client errors (4xx)', 'searchpilot-pro' ), fmt.number( t.code_4xx ) ],
						[ __( 'Server errors (5xx)', 'searchpilot-pro' ), fmt.number( t.code_5xx ) ],
						[ __( 'Blocked by robots.txt', 'searchpilot-pro' ), fmt.number( t.blocked_by_robots ) ],
						[ __( 'Pages in Bing’s index', 'searchpilot-pro' ), fmt.number( crawl.in_index ) ],
					] ),
					h(
						'p',
						{ class: 'spp-muted spp-small' },
						sprintf( /* translators: 1: date, 2: relative time. */ __( 'Reported by Bing up to %1$s · refreshed %2$s', 'searchpilot-pro' ), fmt.date( crawl.to ), fmt.relative( crawl.checked_at ) )
					)
				)
			);
		} else {
			parts.push( h( 'p', { class: 'spp-muted spp-small' }, __( 'Bing crawl statistics appear after the next daily sync (or press “Test connection”).', 'searchpilot-pro' ) ) );
		}
		if ( feeds && Array.isArray( feeds.items ) ) {
			parts.push(
				h(
					'div',
					null,
					h( 'div', { class: 'spp-strong' }, __( 'Sitemaps in Bing', 'searchpilot-pro' ) ),
					feeds.items.length
						? h(
								'ul',
								{ class: 'spp-list spp-small' },
								feeds.items.slice( 0, 10 ).map( ( f ) =>
									h(
										'li',
										null,
										h( 'div', { class: 'spp-row is-between' }, h( 'span', { class: 'spp-mono spp-break' }, fmt.path( f.url ) ), ui.badge( f.status || '—', /success/i.test( f.status || '' ) ? 'passed' : 'outline' ) ),
										h(
											'div',
											{ class: 'spp-muted' },
											sprintf(
												/* translators: 1: number of URLs, 2: relative time. */
												__( '%1$s URLs · last crawled %2$s', 'searchpilot-pro' ),
												fmt.number( f.url_count ),
												f.last_crawled ? fmt.relative( f.last_crawled ) : __( 'never', 'searchpilot-pro' )
											)
										)
									)
								)
						  )
						: h( 'p', { class: 'spp-muted spp-small' }, __( 'Bing lists no sitemap for this site yet. SearchPilot submits it after the next sitemap change.', 'searchpilot-pro' ) )
				)
			);
		}
		return h( 'div', null, h( 'hr', { class: 'spp-divider' } ), h( 'div', { class: 'spp-stack' }, parts ) );
	}

	/* ------------------------------------------------------------------ *
	 * IndexNow
	 * ------------------------------------------------------------------ */

	function indexnow( opts ) {
		opts = opts || {};
		const verifySlotState = { result: null };
		const parts = shell(
			opts,
			{
				title: 'IndexNow',
				subtitle: __( 'Instantly tells Bing, Yandex, Seznam, Naver and Yep about new, changed and removed pages.', 'searchpilot-pro' ),
				icon: 'bolt',
			},
			( data, badgeSlot ) => {
				const d = data.indexnow_setup || {};
				const other = d.enabled && d.owner && d.owner !== 'searchpilot' && d.owner !== 'off';
				const last = verifySlotState.result || d.last || {};

				let tone = 'outline';
				let label = __( 'Off', 'searchpilot-pro' );
				if ( other ) {
					tone = 'info';
					label = __( 'Handled by another plugin', 'searchpilot-pro' );
				} else if ( d.enabled && last.verified === false ) {
					tone = 'critical';
					label = __( 'Key not reachable', 'searchpilot-pro' );
				} else if ( d.enabled ) {
					tone = 'passed';
					label = last.verified ? __( 'Active · key verified', 'searchpilot-pro' ) : __( 'Active', 'searchpilot-pro' );
				}
				SPP.mount( badgeSlot, statusBadge( tone, label ) );

				const verifyResult = h( 'div' );
				const showVerify = ( res ) => {
					if ( ! res || res.verified === null || res.verified === undefined ) {
						SPP.mount( verifyResult );
						return;
					}
					SPP.mount(
						verifyResult,
						ui.notice( {
							tone: res.verified ? 'passed' : 'critical',
							title: res.verified ? __( 'Key file verified', 'searchpilot-pro' ) : __( 'Key file problem', 'searchpilot-pro' ),
							text: [ res.message || '', res.checked_at ? ' (' + fmt.relative( res.checked_at ) + ')' : '' ].join( '' ),
						} )
					);
				};
				showVerify( last );

				const out = [];
				if ( other ) {
					out.push(
						ui.notice( {
							tone: 'info',
							title: sprintf( /* translators: %s: plugin name. */ __( '%s already sends IndexNow notifications', 'searchpilot-pro' ), d.owner_name || d.owner ),
							text: __( 'One IndexNow submitter is enough — participating engines share submissions. SearchPilot stays quiet so no URL is sent twice. Switch IndexNow off in the other plugin if you want SearchPilot’s queue, retries and history instead.', 'searchpilot-pro' ),
						} )
					);
				}
				out.push(
					ui.field.toggle( {
						label: __( 'Notify IndexNow when content is published, updated or removed', 'searchpilot-pro' ),
						checked: !! d.enabled,
						onChange: ( v ) =>
							api
								.post( '/engines/indexnow', { enabled: v } )
								.then( () => {
									ui.toast( v ? __( 'IndexNow switched on.', 'searchpilot-pro' ) : __( 'IndexNow switched off.', 'searchpilot-pro' ) );
									return changed( opts, parts.region, 'indexnow' );
								} )
								.catch( ( err ) => ui.toast( err.message, 'error' ) ),
					} )
				);
				if ( d.key ) {
					out.push(
						h( 'div', null, h( 'div', { class: 'spp-field-label' }, __( 'Key', 'searchpilot-pro' ) ), ui.copyField( d.key ) ),
						h(
							'div',
							null,
							h( 'div', { class: 'spp-field-label' }, __( 'Key file', 'searchpilot-pro' ) ),
							ui.copyField( d.key_url ),
							h( 'p', { class: 'spp-field-help' }, __( 'Served by SearchPilot at this address (no file is written to your server). Search engines fetch it to confirm the notifications come from you.', 'searchpilot-pro' ), ' ', ext( __( 'Open key file', 'searchpilot-pro' ), d.key_url ) )
						)
					);
				}
				out.push( verifyResult );
				if ( ! opts.compact ) {
					out.push(
						ui.field.select( {
							label: __( 'Endpoint', 'searchpilot-pro' ),
							value: d.endpoint,
							help: __( 'Any endpoint works: participating engines share submissions with each other.', 'searchpilot-pro' ),
							options: ( d.endpoints || [ 'api.indexnow.org' ] ).map( ( e ) => ( { value: e, label: e } ) ),
							onChange: ( v ) =>
								api
									.post( '/engines/indexnow', { endpoint: v } )
									.then( () => {
										ui.toast( __( 'Endpoint saved.', 'searchpilot-pro' ) );
										invalidate();
									} )
									.catch( ( err ) => ui.toast( err.message, 'error' ) ),
						} )
					);
				}
				out.push(
					h(
						'div',
						{ class: 'spp-row' },
						ui.button( {
							label: __( 'Verify key file', 'searchpilot-pro' ),
							size: 'small',
							icon: 'check',
							onClick: () =>
								api.post( '/engines/indexnow/verify' ).then( ( res ) => {
									verifySlotState.result = res;
									showVerify( res );
									invalidate();
									if ( opts.onChange ) {
										opts.onChange( ( res.indexnow || {} ).verified, res );
									}
								} ),
						} ),
						ui.button( {
							label: __( 'Regenerate key', 'searchpilot-pro' ),
							size: 'small',
							variant: 'ghost',
							icon: 'refresh',
							onClick: () =>
								ui
									.confirm( {
										title: __( 'Generate a new IndexNow key?', 'searchpilot-pro' ),
										message: __( 'The old key file stops working immediately. Only do this if the key leaked or another site uses the same key.', 'searchpilot-pro' ),
										confirmLabel: __( 'Regenerate', 'searchpilot-pro' ),
									} )
									.then( ( ok ) =>
										ok
											? api.post( '/engines/indexnow/regenerate' ).then( () => {
													verifySlotState.result = null;
													ui.toast( __( 'New IndexNow key created.', 'searchpilot-pro' ) );
													return changed( opts, parts.region, 'indexnow' );
											  } )
											: null
									),
						} ),
						opts.compact ? null : ext( __( 'About IndexNow', 'searchpilot-pro' ), LINKS.indexNow )
					),
					h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'Google does not use IndexNow. Google learns about new and changed pages from your sitemap in Search Console (and, for job postings and livestreams only, from the Indexing API).', 'searchpilot-pro' ) )
				);
				return h( 'div', { class: 'spp-stack' }, out );
			}
		);
		return parts.el;
	}

	SPP.engines = { google, bing, indexnow, load, invalidate, LINKS };
} )( window.wp, window.SPP );

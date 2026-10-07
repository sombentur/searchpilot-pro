/**
 * Sitemaps & robots.txt view.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;
	const field = ui.field;

	const HEALTH = {
		healthy: { label: __( 'Healthy', 'searchpilot-pro' ), tone: 'passed' },
		warning: { label: __( 'Needs attention', 'searchpilot-pro' ), tone: 'high' },
		error: { label: __( 'Broken', 'searchpilot-pro' ), tone: 'critical' },
		disabled: { label: __( 'Off', 'searchpilot-pro' ), tone: 'outline' },
		unknown: { label: __( 'Not checked yet', 'searchpilot-pro' ), tone: 'outline' },
	};

	const CHILD_STATUS = {
		ok: { label: __( 'OK', 'searchpilot-pro' ), tone: 'passed' },
		pending: { label: __( 'Queued', 'searchpilot-pro' ), tone: 'outline' },
		error: { label: __( 'Error', 'searchpilot-pro' ), tone: 'critical' },
		invalid: { label: __( 'Invalid', 'searchpilot-pro' ), tone: 'critical' },
	};

	const KIND_LABELS = {
		pt: __( 'Content', 'searchpilot-pro' ),
		tax: __( 'Taxonomy', 'searchpilot-pro' ),
		authors: __( 'Authors', 'searchpilot-pro' ),
		video: __( 'Video', 'searchpilot-pro' ),
		news: __( 'Google News', 'searchpilot-pro' ),
		posts: __( 'Posts', 'searchpilot-pro' ),
		pages: __( 'Pages', 'searchpilot-pro' ),
		image: __( 'Images', 'searchpilot-pro' ),
		index: __( 'Index', 'searchpilot-pro' ),
		urlset: __( 'Sitemap', 'searchpilot-pro' ),
		sitemap: __( 'Sitemap', 'searchpilot-pro' ),
	};

	/** Issue cards for a query; reloads itself after an action. */
	function issueList( query, emptyText ) {
		const region = SPP.region( () =>
			api.get( '/issues', Object.assign( { per_page: 20 }, query ) ).then( ( res ) => {
				const items = res.items || [];
				if ( ! items.length ) {
					return emptyText ? ui.passed( emptyText ) : h( 'span' );
				}
				return ui.stack( items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: () => region.refresh() } ) ) );
			} )
		);
		return region;
	}

	/** Opens a sitemap page preview in a modal. */
	function previewModal( type, page, label ) {
		const body = h( 'div', null, ui.loading() );
		ui.modal( { title: sprintf( /* translators: %s: sitemap name. */ __( 'Preview: %s', 'searchpilot-pro' ), label ), size: 'large', body } );
		api.get( '/sitemaps/preview', { type, page } )
			.then( ( res ) => {
				const hasNews = ( res.items || [] ).some( ( i ) => i.news_title );
				const hasVideo = ( res.items || [] ).some( ( i ) => i.videos );
				const columns = [ { key: 'loc', label: __( 'URL', 'searchpilot-pro' ), render: ( r ) => h( 'a', { href: r.loc, target: '_blank', rel: 'noopener noreferrer', class: 'spp-break' }, fmt.url( r.loc ) ) } ];
				if ( 'index' === res.type ) {
					columns.push( { key: 'urls', label: __( 'URLs', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.urls ) } );
				} else {
					if ( hasNews ) {
						columns.push( { key: 'news_title', label: __( 'News title', 'searchpilot-pro' ) } );
					}
					if ( hasVideo ) {
						columns.push( { key: 'video_title', label: __( 'Video', 'searchpilot-pro' ) } );
					}
					columns.push( { key: 'images', label: __( 'Images', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.images ) } );
				}
				columns.push( { key: 'lastmod', label: __( 'Last modified', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => ( r.lastmod ? fmt.datetime( r.lastmod ) : '—' ) } );
				SPP.mount(
					body,
					h(
						'p',
						{ class: 'spp-muted' },
						sprintf(
							/* translators: 1: shown entries, 2: total entries. */
							__( 'Showing %1$s of %2$s entries.', 'searchpilot-pro' ),
							fmt.number( Math.min( 50, res.total ) ),
							fmt.number( res.total )
						),
						' ',
						ui.link( __( 'Open the XML', 'searchpilot-pro' ), res.url, { external: true } )
					),
					ui.table( { columns, rows: res.items || [], dense: true, empty: __( 'This sitemap is empty.', 'searchpilot-pro' ) } )
				);
			} )
			.catch( ( err ) => SPP.mount( body, ui.errorBox( err ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Sitemaps tab
	 * ------------------------------------------------------------------ */

	function ownershipBanner( data ) {
		const actions = [];
		if ( data.active_url ) {
			actions.push( ui.copyField( data.active_url ) );
			actions.push( ui.button( { label: __( 'Open', 'searchpilot-pro' ), icon: 'external', size: 'small', href: data.active_url, target: '_blank' } ) );
		}
		let notice;
		if ( data.owned ) {
			notice = ui.notice( {
				tone: 'passed',
				icon: 'map',
				title: __( 'SearchPilot generates your XML sitemaps', 'searchpilot-pro' ),
				text: __( 'The sitemap updates automatically when you publish, edit or delete content. WordPress’s built-in sitemap is switched off and /wp-sitemap.xml redirects here, so there is exactly one sitemap.', 'searchpilot-pro' ),
				actions,
			} );
		} else if ( data.companion ) {
			notice = ui.notice( {
				tone: 'info',
				icon: 'map',
				title: sprintf( /* translators: %s: plugin name. */ __( '%s generates your sitemap — SearchPilot monitors and submits it', 'searchpilot-pro' ), data.owner_name ),
				text: __( 'SearchPilot does not create a second sitemap. It checks the existing one every day, reports errors, and resubmits it to Google and Bing after meaningful changes.', 'searchpilot-pro' ),
				actions,
			} );
		} else if ( 'core' === data.owner ) {
			notice = ui.notice( {
				tone: 'info',
				icon: 'map',
				title: __( 'WordPress generates your sitemap', 'searchpilot-pro' ),
				text: __( 'SearchPilot’s sitemaps are switched off in Settings → SEO plugin compatibility, so WordPress’s basic sitemap is used. SearchPilot still monitors and submits it.', 'searchpilot-pro' ),
				actions,
			} );
		} else {
			notice = ui.notice( {
				tone: 'high',
				title: __( 'No XML sitemap is generated', 'searchpilot-pro' ),
				text: __( 'Search engines discover new pages more slowly without a sitemap. Hand sitemaps to SearchPilot in Settings → SEO plugin compatibility.', 'searchpilot-pro' ),
				actions: [ ui.button( { label: __( 'Open settings', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'settings', { tab: 'ownership' } ) } ) ],
			} );
		}
		const extra = [];
		if ( ! data.permalinks && data.owned ) {
			extra.push( ui.notice( { tone: 'medium', text: __( 'Plain permalinks are in use, so sitemap addresses use ?spp_sitemap=… Pretty permalinks (Settings → Permalinks) give cleaner sitemap URLs.', 'searchpilot-pro' ) } ) );
		}
		if ( data.submission && ! data.submission.allowed && data.submission.message ) {
			extra.push( ui.notice( { tone: 'medium', icon: 'shield', text: data.submission.message } ) );
		}
		return ui.stack( notice, extra );
	}

	function healthCard( data, reload ) {
		const health = data.health || {};
		const status = HEALTH[ health.status ] || HEALTH.unknown;
		const checks = ( health.checks || [] ).map( ( c ) => ui.checkRow( c ) );
		const errors = ( health.errors || [] ).length ? ui.notice( { tone: 'critical', title: __( 'Problems found', 'searchpilot-pro' ), text: h( 'ul', { class: 'spp-list' }, health.errors.map( ( e ) => h( 'li', { class: 'spp-break' }, e ) ) ) } ) : null;
		return ui.card( {
			title: __( 'Sitemap health', 'searchpilot-pro' ),
			subtitle: health.checked_at
				? sprintf( /* translators: %s: relative time. */ __( 'Checked %s', 'searchpilot-pro' ), fmt.relative( health.checked_at ) )
				: __( 'Checked daily in the background. Run it now to see the current state.', 'searchpilot-pro' ),
			actions: [
				ui.badge( status.label, status.tone, { dot: true } ),
				ui.button( {
					label: __( 'Run health check', 'searchpilot-pro' ),
					icon: 'refresh',
					size: 'small',
					busyLabel: __( 'Checking…', 'searchpilot-pro' ),
					onClick: () =>
						api.post( '/sitemaps/health' ).then( () => {
							ui.toast( __( 'Health check finished', 'searchpilot-pro' ) );
							reload();
						} ),
				} ),
			],
			body: [
				ui.grid(
					3,
					ui.kpi( { label: __( 'URLs listed', 'searchpilot-pro' ), value: fmt.number( health.url_count || 0 ), icon: 'link' } ),
					ui.kpi( { label: __( 'Sitemap files', 'searchpilot-pro' ), value: fmt.number( ( health.sitemaps || [] ).length ), icon: 'file', hint: health.pending ? sprintf( /* translators: %d: files. */ _n( '%d still being checked', '%d still being checked', health.pending, 'searchpilot-pro' ), health.pending ) : null } ),
					ui.kpi( { label: __( 'Newest date in sitemap', 'searchpilot-pro' ), value: health.newest_lastmod ? fmt.date( health.newest_lastmod ) : '—', icon: 'refresh', hint: health.newest_content ? sprintf( /* translators: %s: date. */ __( 'Content last changed %s', 'searchpilot-pro' ), fmt.relative( health.newest_content ) ) : null } )
				),
				errors,
				checks.length ? h( 'div', { style: { marginTop: '12px' } }, checks ) : ui.empty( { icon: 'map', title: __( 'No health data yet', 'searchpilot-pro' ), text: __( 'Run the health check to fetch and validate the sitemap as a crawler would.', 'searchpilot-pro' ) } ),
			],
		} );
	}

	function childTable( data ) {
		if ( data.owned ) {
			const rows = data.sitemaps || [];
			return ui.card( {
				title: __( 'Sitemap files', 'searchpilot-pro' ),
				subtitle: sprintf(
					/* translators: 1: number of files, 2: number of URLs. */
					__( '%1$s files listing up to %2$s URLs (noindex, password-protected, excluded and redirected content is left out automatically).', 'searchpilot-pro' ),
					fmt.number( data.totals.sitemaps ),
					fmt.number( data.totals.urls )
				),
				flush: true,
				body: ui.table( {
					columns: [
						{ key: 'label', label: __( 'Sitemap', 'searchpilot-pro' ), render: ( r ) => h( 'div', null, h( 'span', { class: 'spp-cell-title' }, r.label, r.page > 1 ? ' · ' + sprintf( /* translators: %d: page. */ __( 'page %d', 'searchpilot-pro' ), r.page ) : '' ), h( 'div', { class: 'spp-cell-sub spp-muted spp-small' }, fmt.path( r.url ) ) ) },
						{ key: 'kind', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => ui.badge( KIND_LABELS[ r.kind ] || r.kind, 'news' === r.kind ? 'gold' : 'video' === r.kind ? 'info' : 'outline' ) },
						{ key: 'urls', label: __( 'URLs', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.urls ) },
						{ key: 'lastmod', label: __( 'Last modified', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => ( r.lastmod ? h( 'span', { title: fmt.datetime( r.lastmod ) }, fmt.relative( r.lastmod ) ) : '—' ) },
						{
							key: 'actions',
							label: '',
							align: 'right',
							render: ( r ) =>
								ui.row(
									ui.button( { label: __( 'Preview', 'searchpilot-pro' ), icon: 'eye', size: 'small', variant: 'ghost', onClick: () => previewModal( r.type, r.page, r.label ) } ),
									ui.button( { label: __( 'Open', 'searchpilot-pro' ), icon: 'external', size: 'small', variant: 'ghost', href: r.url, target: '_blank' } )
								),
						},
					],
					rows,
					empty: __( 'No content is published yet, so the sitemap index is empty.', 'searchpilot-pro' ),
				} ),
			} );
		}
		const rows = ( data.health && data.health.sitemaps ) || [];
		return ui.card( {
			title: sprintf( /* translators: %s: plugin name. */ __( 'Sitemap files of %s', 'searchpilot-pro' ), data.owner_name ),
			subtitle: __( 'As found by the last health check.', 'searchpilot-pro' ),
			flush: true,
			body: ui.table( {
				columns: [
					{ key: 'url', label: __( 'Sitemap', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.url ) },
					{ key: 'type', label: __( 'Type', 'searchpilot-pro' ), render: ( r ) => ui.badge( KIND_LABELS[ r.type ] || r.type, 'outline' ) },
					{ key: 'urls', label: __( 'URLs', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.urls ) },
					{ key: 'lastmod', label: __( 'Last modified', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => ( r.lastmod ? fmt.relative( r.lastmod ) : '—' ) },
					{ key: 'status', label: __( 'Status', 'searchpilot-pro' ), render: ( r ) => h( 'span', { title: r.error || '' }, ui.badge( ( CHILD_STATUS[ r.status ] || CHILD_STATUS.pending ).label, ( CHILD_STATUS[ r.status ] || CHILD_STATUS.pending ).tone ) ) },
				],
				rows,
				empty: __( 'Run the health check to list the sitemap files.', 'searchpilot-pro' ),
			} ),
		} );
	}

	function bingCard( data ) {
		const health = data.health || {};
		const engines = ( data.submission && data.submission.engines ) || {};
		if ( ! health.bing_connected && ! engines.bing ) {
			return ui.card( {
				title: __( 'Bing Webmaster Tools', 'searchpilot-pro' ),
				body: ui.empty( {
					icon: 'globe',
					title: __( 'Bing Webmaster Tools is not connected', 'searchpilot-pro' ),
					text: __( 'Connect it to see whether Bing has read your sitemap.', 'searchpilot-pro' ),
					action: ui.button( { label: __( 'Connect search engines', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'engines' ) } ),
				} ),
			} );
		}
		return ui.card( {
			title: __( 'Bing Webmaster Tools', 'searchpilot-pro' ),
			subtitle: __( 'Bing’s report on submitted sitemaps (from the last health check).', 'searchpilot-pro' ),
			flush: true,
			body: ui.table( {
				columns: [
					{ key: 'url', label: __( 'Sitemap', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.url ) },
					{ key: 'status', label: __( 'Status', 'searchpilot-pro' ), render: ( r ) => ( r.status ? ui.badge( r.status, /success|ok/i.test( r.status ) ? 'passed' : /pending|processing/i.test( r.status ) ? 'outline' : 'medium' ) : '—' ) },
					{ key: 'submitted', label: __( 'Submitted', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => fmt.relative( r.submitted ) },
					{ key: 'last_crawled', label: __( 'Last read by Bing', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => fmt.relative( r.last_crawled ) },
					{ key: 'url_count', label: __( 'URLs', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( r.url_count ) },
				],
				rows: health.bing || [],
				empty: __( 'No sitemap status from Bing yet. Run the health check, or use “Submit now”.', 'searchpilot-pro' ),
			} ),
		} );
	}

	function gscCard( data ) {
		const health = data.health || {};
		if ( ! health.gsc_connected ) {
			return ui.card( {
				title: __( 'Google Search Console', 'searchpilot-pro' ),
				body: ui.empty( {
					icon: 'globe',
					title: __( 'Search Console is not connected', 'searchpilot-pro' ),
					text: __( 'Connect it to see when Google last read each sitemap and whether it found errors.', 'searchpilot-pro' ),
					action: ui.button( { label: __( 'Connect search engines', 'searchpilot-pro' ), variant: 'primary', size: 'small', href: SPP.pageUrl( 'engines' ) } ),
				} ),
			} );
		}
		return ui.card( {
			title: __( 'Google Search Console', 'searchpilot-pro' ),
			subtitle: __( 'Google’s own report on submitted sitemaps (from the last health check).', 'searchpilot-pro' ),
			flush: true,
			body: ui.table( {
				columns: [
					{ key: 'path', label: __( 'Sitemap', 'searchpilot-pro' ), render: ( r ) => ui.urlCell( r.path ) },
					{ key: 'last_submitted', label: __( 'Submitted', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => fmt.relative( r.last_submitted ) },
					{ key: 'last_downloaded', label: __( 'Last read by Google', 'searchpilot-pro' ), className: 'is-nowrap', render: ( r ) => ( r.is_pending ? ui.badge( __( 'Pending', 'searchpilot-pro' ), 'outline' ) : fmt.relative( r.last_downloaded ) ) },
					{ key: 'submitted_urls', label: __( 'URLs submitted', 'searchpilot-pro' ), align: 'num', render: ( r ) => fmt.number( ( r.contents || [] ).reduce( ( a, c ) => a + ( Number( c.submitted ) || 0 ), 0 ) ) },
					{ key: 'warnings', label: __( 'Warnings', 'searchpilot-pro' ), align: 'num', render: ( r ) => ( r.warnings ? ui.badge( fmt.number( r.warnings ), 'medium' ) : '0' ) },
					{ key: 'errors', label: __( 'Errors', 'searchpilot-pro' ), align: 'num', render: ( r ) => ( r.errors ? ui.badge( fmt.number( r.errors ), 'critical' ) : '0' ) },
				],
				rows: health.gsc || [],
				empty: __( 'No sitemaps are submitted in Search Console yet. Use “Submit now”.', 'searchpilot-pro' ),
			} ),
		} );
	}

	function settingsCard( data, reload ) {
		const s = Object.assign( {}, data.settings );
		const draft = {};
		const set = ( key, v ) => {
			draft[ key ] = v;
			saveBtn.disabled = false;
		};
		const saveBtn = ui.button( {
			label: __( 'Save sitemap settings', 'searchpilot-pro' ),
			variant: 'primary',
			disabled: true,
			onClick: () =>
				api.post( '/settings', { settings: { sitemap: draft } } ).then( ( res ) => {
					const errors = res.errors || {};
					if ( Object.keys( errors ).length ) {
						ui.toast( Object.values( errors ).join( ' ' ), 'error' );
						return;
					}
					ui.toast( __( 'Sitemap settings saved. Sitemaps were regenerated.', 'searchpilot-pro' ) );
					reload();
				} ),
		} );
		const newsFields = h( 'div' );
		const renderNews = ( on ) => {
			if ( ! on ) {
				SPP.mount( newsFields );
				return;
			}
			const typeOptions = ( data.post_types || [] ).filter( ( p ) => p.sitemap ).map( ( p ) => ( { value: p.name, label: p.label } ) );
			SPP.mount(
				newsFields,
				data.news && ! data.news.owned ? ui.notice( { tone: 'info', text: sprintf( /* translators: %s: plugin id. */ __( 'Another plugin (%s) provides the Google News sitemap, so SearchPilot does not create one.', 'searchpilot-pro' ), data.news.owner ) } ) : null,
				field.text( { label: __( 'Publication name', 'searchpilot-pro' ), row: true, wide: true, value: s.news_name, placeholder: data.news ? data.news.name : '', help: __( 'Must match the name Google News knows your publication by. Empty = site title.', 'searchpilot-pro' ), onInput: ( v ) => set( 'news_name', v ) } ),
				field.text( { label: __( 'Language code', 'searchpilot-pro' ), row: true, value: s.news_language, placeholder: data.news ? data.news.language : '', help: sprintf( /* translators: %s: detected code. */ __( 'ISO 639 code such as kn (Kannada), en, hi, zh-cn. Empty = detected from the site language (%s).', 'searchpilot-pro' ), data.news ? data.news.language : '' ), onInput: ( v ) => set( 'news_language', v ) } ),
				field.checklist( { label: __( 'Content types in the news sitemap', 'searchpilot-pro' ), options: typeOptions, values: s.news_post_types || [], onChange: ( v ) => set( 'news_post_types', v ) } ),
				( data.categories || [] ).length
					? field.checklist( {
							label: __( 'Only these categories (optional)', 'searchpilot-pro' ),
							help: __( 'Leave all unticked to include every category. Sub-categories are included automatically.', 'searchpilot-pro' ),
							options: data.categories.map( ( c ) => ( { value: c.id, label: c.name } ) ),
							values: s.news_categories || [],
							onChange: ( v ) => set( 'news_categories', v.map( Number ) ),
					  } )
					: null
			);
		};
		renderNews( s.news );
		return ui.card( {
			title: __( 'Sitemap settings', 'searchpilot-pro' ),
			subtitle: data.owned ? __( 'Which content types appear is set in Settings → Content types.', 'searchpilot-pro' ) : __( 'These settings apply when SearchPilot generates the sitemap. Submission settings apply in companion mode too.', 'searchpilot-pro' ),
			body: [
				field.toggle( { label: __( 'Images', 'searchpilot-pro' ), row: true, checked: s.images, help: __( 'List the featured image and images in the content (up to 50 per page) so they are found for Google Images.', 'searchpilot-pro' ), onChange: ( v ) => set( 'images', v ) } ),
				field.toggle(
					{
						label: __( 'Video sitemap', 'searchpilot-pro' ),
						row: true,
						checked: s.video,
						help: sprintf(
							/* translators: %d: posts with videos. */
							_n( 'YouTube, Vimeo and uploaded videos embedded in posts (%d post found so far). Video details are fetched in the background.', 'YouTube, Vimeo and uploaded videos embedded in posts (%d posts found so far). Video details are fetched in the background.', ( data.video && data.video.posts ) || 0, 'searchpilot-pro' ),
							( data.video && data.video.posts ) || 0
						),
						onChange: ( v ) => set( 'video', v ),
					}
				),
				field.toggle( {
					label: __( 'Google News sitemap', 'searchpilot-pro' ),
					row: true,
					checked: s.news,
					help: __( 'Articles published in the last 48 hours (max. 1,000), for sites accepted in Google News.', 'searchpilot-pro' ),
					onChange: ( v ) => {
						set( 'news', v );
						renderNews( v );
					},
				} ),
				newsFields,
				field.number( { label: __( 'URLs per sitemap file', 'searchpilot-pro' ), row: true, value: s.max_urls, min: 100, max: 50000, step: 100, help: __( 'Search engines accept up to 50,000. Smaller files load faster on shared hosting.', 'searchpilot-pro' ), onInput: ( v ) => set( 'max_urls', v === '' ? 1000 : v ) } ),
				field.toggle( { label: __( 'Readable in browsers', 'searchpilot-pro' ), row: true, checked: s.stylesheet, help: __( 'Adds a stylesheet so people see a table instead of raw XML. Search engines ignore it.', 'searchpilot-pro' ), onChange: ( v ) => set( 'stylesheet', v ) } ),
				field.toggle( { label: __( 'Resubmit after changes', 'searchpilot-pro' ), row: true, checked: s.submit_on_change, help: __( 'Submit the sitemap to Google Search Console and Bing after meaningful content changes.', 'searchpilot-pro' ), onChange: ( v ) => set( 'submit_on_change', v ) } ),
				field.number( { label: __( 'At most every', 'searchpilot-pro' ), row: true, value: s.submit_throttle_hours, min: 1, max: 168, suffix: __( 'hours', 'searchpilot-pro' ), onInput: ( v ) => set( 'submit_throttle_hours', v === '' ? 6 : v ) } ),
			],
			footer: h( 'div', { class: 'spp-row is-between' }, h( 'span', { class: 'spp-muted spp-small' }, __( 'Changes are logged and can be undone from Automation → Activity.', 'searchpilot-pro' ) ), saveBtn ),
		} );
	}

	function renderSitemaps( container, header ) {
		const reload = () => region.refresh();
		const region = SPP.region(
			() =>
				api.get( '/sitemaps' ).then( ( data ) => {
					header.update( data, reload );
					return ui.stack(
						ownershipBanner( data ),
						ui.grid( 2, healthCard( data, reload ), ui.card( { title: __( 'Sitemap issues', 'searchpilot-pro' ), body: issueList( { category: 'sitemap' }, __( 'No sitemap problems detected.', 'searchpilot-pro' ) ).el } ) ),
						childTable( data ),
						ui.grid( 2, gscCard( data ), bingCard( data ) ),
						settingsCard( data, reload )
					);
				} ),
			{ loader: false }
		);
		SPP.mount( container, region.el );
	}

	/* ------------------------------------------------------------------ *
	 * robots.txt tab
	 * ------------------------------------------------------------------ */

	function warningsBox( warnings, invalid, notes ) {
		const out = [];
		if ( ( invalid || [] ).length ) {
			out.push(
				ui.notice( {
					tone: 'high',
					title: __( 'These lines are ignored', 'searchpilot-pro' ),
					text: h(
						'ul',
						{ class: 'spp-list' },
						invalid.map( ( i ) => h( 'li', null, h( 'code', null, sprintf( /* translators: %d: line number. */ __( 'Line %d', 'searchpilot-pro' ), i.line ) ), ' ', h( 'span', { class: 'spp-mono' }, i.text ), ' — ', i.reason ) )
					),
				} )
			);
		}
		if ( ( warnings || [] ).length ) {
			out.push(
				ui.notice( {
					tone: warnings.some( ( w ) => 'critical' === w.severity ) ? 'critical' : 'high',
					title: __( 'Important sections are blocked', 'searchpilot-pro' ),
					text: h( 'ul', { class: 'spp-list' }, warnings.map( ( w ) => h( 'li', null, w.message ) ) ),
				} )
			);
		}
		( notes || [] ).forEach( ( n ) => out.push( ui.notice( { tone: 'info', text: n } ) ) );
		return out.length ? ui.stack( out ) : ui.passed( __( 'No crawl-blocking problems found', 'searchpilot-pro' ), __( 'Googlebot and Bingbot can reach your home page, content, images, CSS, JavaScript and sitemap.', 'searchpilot-pro' ) );
	}

	function testerCard() {
		const result = h( 'div', { style: { marginTop: '12px' } } );
		let url = '';
		let agent = 'Googlebot';
		const input = field.text( { label: __( 'Address on this site', 'searchpilot-pro' ), placeholder: SPP.boot.homeUrl, onInput: ( v ) => ( url = v ) } );
		const agentSel = field.select( {
			label: __( 'Crawler', 'searchpilot-pro' ),
			value: agent,
			options: [
				{ value: 'Googlebot', label: 'Googlebot' },
				{ value: 'Googlebot-Image', label: 'Googlebot-Image' },
				{ value: 'bingbot', label: 'Bingbot' },
			],
			onChange: ( v ) => ( agent = v ),
		} );
		const run = () =>
			api.get( '/robots/test', { url: url || SPP.boot.homeUrl, agent } ).then( ( r ) => {
				const sourceLabel = { live: __( 'the live robots.txt', 'searchpilot-pro' ), file: __( 'the robots.txt file', 'searchpilot-pro' ), virtual: __( 'WordPress’s virtual robots.txt', 'searchpilot-pro' ) }[ r.source ];
				SPP.mount(
					result,
					ui.notice( {
						tone: r.allowed ? 'passed' : 'critical',
						title: r.allowed
							? sprintf( /* translators: 1: crawler, 2: path. */ __( '%1$s may crawl %2$s', 'searchpilot-pro' ), r.agent, fmt.url( r.path ) )
							: sprintf( /* translators: 1: crawler, 2: path. */ __( '%1$s is blocked from %2$s', 'searchpilot-pro' ), r.agent, fmt.url( r.path ) ),
						text: [
							r.rule ? sprintf( /* translators: %s: robots.txt rule. */ __( 'Deciding rule: %s', 'searchpilot-pro' ), r.rule.line ) : __( 'No rule matches, so crawling is allowed.', 'searchpilot-pro' ),
							' ',
							sprintf( /* translators: %s: which file. */ __( '(tested against %s)', 'searchpilot-pro' ), sourceLabel ),
						],
					} )
				);
			} );
		return ui.card( {
			title: __( 'Test an address', 'searchpilot-pro' ),
			subtitle: __( 'Checks the robots.txt rules the way Google and Bing apply them (longest match wins, Allow wins ties).', 'searchpilot-pro' ),
			body: [ h( 'div', { class: 'spp-grid cols-2' }, input, agentSel ), ui.button( { label: __( 'Test', 'searchpilot-pro' ), variant: 'primary', icon: 'search', onClick: run } ), result ],
		} );
	}

	function renderRobots( container ) {
		const region = SPP.region(
			() =>
				api.get( '/robots' ).then( ( data ) => {
					const state = { rules: data.custom_rules || '', line: !! data.sitemap_line, manage: !! data.managed };
					const livePreview = h( 'div' );
					const liveWarnings = h( 'div' );
					const renderPreview = ( res ) => {
						SPP.mount( livePreview, ui.code( res.virtual ) );
						SPP.mount( liveWarnings, warningsBox( res.warnings, res.invalid, res.notes ) );
					};
					renderPreview( { virtual: data.virtual, warnings: data.warnings, invalid: data.invalid, notes: data.notes } );
					const refreshPreview = SPP.util.debounce( () => {
						api.post( '/robots/preview', { custom_rules: state.rules, sitemap_line: state.line } ).then( renderPreview, () => {} );
					}, 500 );

					const notices = [];
					if ( data.physical && data.physical.exists ) {
						notices.push(
							ui.notice( {
								tone: 'high',
								title: __( 'A robots.txt file on the server overrides WordPress', 'searchpilot-pro' ),
								text: __( 'Crawlers receive the file below, not the rules managed here. Copy what you need into the custom rules, then delete or rename the file on your server.', 'searchpilot-pro' ),
							} ),
							ui.card( { title: __( 'robots.txt file on the server', 'searchpilot-pro' ), body: ui.code( data.physical.content || '' ) } )
						);
					}
					if ( ! data.public ) {
						notices.push( ui.notice( { tone: 'critical', title: __( 'Search engines are discouraged', 'searchpilot-pro' ), text: __( 'Settings → Reading asks search engines not to index this site. Custom rules are not added while this is on.', 'searchpilot-pro' ) } ) );
					}

					const effective = data.effective || {};
					const liveCard = ui.card( {
						title: __( 'What crawlers see now', 'searchpilot-pro' ),
						subtitle: effective.fetched ? __( 'Fetched from your site just now.', 'searchpilot-pro' ) : __( 'Could not fetch robots.txt from the site itself; showing what WordPress would serve.', 'searchpilot-pro' ),
						actions: [ ui.button( { label: __( 'Open', 'searchpilot-pro' ), icon: 'external', size: 'small', href: data.url, target: '_blank' } ) ],
						body: [
							effective.fetched ? ui.code( effective.content ) : [ effective.error ? ui.notice( { tone: 'medium', text: effective.error } ) : null, ui.code( data.virtual ) ],
							h( 'div', { style: { marginTop: '10px' } }, data.lists_sitemap ? ui.badge( __( 'Lists the sitemap', 'searchpilot-pro' ), 'passed', { dot: true } ) : ui.badge( __( 'Sitemap not listed', 'searchpilot-pro' ), 'medium', { dot: true } ) ),
						],
					} );

					const saveBtn = ui.button( {
						label: __( 'Save robots.txt rules', 'searchpilot-pro' ),
						variant: 'primary',
						onClick: () =>
							api.post( '/robots', { custom_rules: state.rules, sitemap_line: state.line, manage: state.manage } ).then( ( res ) => {
								ui.toast( res.saved ? __( 'robots.txt updated. You can undo it from Automation → Activity.', 'searchpilot-pro' ) : __( 'Nothing changed.', 'searchpilot-pro' ) );
								region.refresh();
							} ),
					} );
					const editor = ui.card( {
						title: __( 'Rules managed by SearchPilot', 'searchpilot-pro' ),
						subtitle: __( 'Added after WordPress’s defaults in the virtual robots.txt. Use it for crawl rules only — to keep a page out of search results use noindex instead.', 'searchpilot-pro' ),
						body: [
							field.toggle( {
								label: __( 'Manage robots.txt', 'searchpilot-pro' ),
								row: true,
								checked: state.manage,
								help: __( 'Off: SearchPilot leaves robots.txt completely alone.', 'searchpilot-pro' ),
								onChange: ( v ) => ( state.manage = v ),
							} ),
							field.toggle( {
								label: __( 'Add the sitemap line', 'searchpilot-pro' ),
								row: true,
								checked: state.line,
								help: data.sitemap_url ? sprintf( /* translators: %s: sitemap URL. */ __( 'Adds “Sitemap: %s” unless another plugin already lists it.', 'searchpilot-pro' ), data.sitemap_url ) : __( 'No sitemap is active.', 'searchpilot-pro' ),
								onChange: ( v ) => {
									state.line = v;
									refreshPreview();
								},
							} ),
							field.textarea( {
								label: __( 'Custom rules', 'searchpilot-pro' ),
								code: true,
								rows: 8,
								value: state.rules,
								placeholder: 'User-agent: *\nDisallow: /cart/\n\nUser-agent: GPTBot\nDisallow: /',
								help: __( 'One directive per line: User-agent, Allow, Disallow, Sitemap (Crawl-delay is ignored by Google).', 'searchpilot-pro' ),
								onInput: ( v ) => {
									state.rules = v;
									refreshPreview();
								},
							} ),
							liveWarnings,
							h( 'h4', { style: { margin: '16px 0 8px' } }, __( 'Preview of the virtual robots.txt', 'searchpilot-pro' ) ),
							livePreview,
						],
						footer: h( 'div', { class: 'spp-row is-between' }, h( 'span', { class: 'spp-muted spp-small' }, __( 'Changes are logged and can be undone.', 'searchpilot-pro' ) ), saveBtn ),
					} );

					return ui.stack(
						notices,
						ui.card( { title: __( 'robots.txt issues', 'searchpilot-pro' ), body: issueList( { type: 'robots_physical_file,robots_blocks_important,blocked_by_robots' }, __( 'No robots.txt problems detected.', 'searchpilot-pro' ) ).el } ),
						ui.grid( 2, liveCard, testerCard() ),
						editor
					);
				} ),
			{ loader: false }
		);
		SPP.mount( container, region.el );
	}

	/* ------------------------------------------------------------------ *
	 * View
	 * ------------------------------------------------------------------ */

	SPP.registerView( 'sitemaps', {
		mount( el ) {
			let tab = SPP.query.get( 'tab', 'sitemaps' );
			let reloadFn = null;

			const regenerate = ui.button( {
				label: __( 'Regenerate', 'searchpilot-pro' ),
				icon: 'refresh',
				onClick: () =>
					api.post( '/sitemaps/regenerate' ).then( ( res ) => {
						ui.toast( sprintf( /* translators: %d: sitemap files. */ _n( 'Regenerated %d sitemap file and purged caches.', 'Regenerated %d sitemap files and purged caches.', ( res.result && res.result.children ) || 0, 'searchpilot-pro' ), ( res.result && res.result.children ) || 0 ) );
						if ( reloadFn ) {
							reloadFn();
						}
					} ),
			} );
			const submit = ui.button( {
				label: __( 'Submit now', 'searchpilot-pro' ),
				icon: 'rocket',
				variant: 'primary',
				onClick: () =>
					api.post( '/sitemaps/submit' ).then( ( res ) => {
						ui.toast( res.message || __( 'Submission queued.', 'searchpilot-pro' ), res.held ? 'warning' : 'success', 7000 );
					} ),
			} );
			const actions = h( 'div', { class: 'spp-page-actions' } );
			const header = {
				update( data, reload ) {
					reloadFn = reload;
					regenerate.style.display = data.owned ? '' : 'none';
					const engines = ( data.submission && data.submission.engines ) || {};
					const connected = !! ( engines.google || engines.bing );
					submit.disabled = ! ( data.submission && data.submission.available ) || ! data.active_url || ! connected;
					submit.title = ! connected
						? __( 'Connect Google Search Console or Bing Webmaster Tools to submit sitemaps.', 'searchpilot-pro' )
						: data.submission && ! data.submission.allowed
						? data.submission.message
						: '';
				},
			};
			SPP.mount( actions, regenerate, submit );

			const body = h( 'div' );
			const show = ( id ) => {
				tab = id;
				SPP.query.set( { tab: 'sitemaps' === id ? '' : id } );
				if ( 'robots' === id ) {
					renderRobots( body );
				} else {
					renderSitemaps( body, header );
				}
			};
			SPP.mount(
				el,
				h(
					'div',
					{ class: 'spp-page-header' },
					h( 'div', { class: 'spp-grow' }, h( 'h2', { class: 'spp-page-title' }, __( 'Sitemaps & robots.txt', 'searchpilot-pro' ) ), h( 'p', { class: 'spp-page-subtitle' }, __( 'XML sitemaps help Google and Bing find new and updated pages quickly; robots.txt tells crawlers where not to go.', 'searchpilot-pro' ) ) ),
					actions
				),
				ui.tabs( {
					tabs: [
						{ id: 'sitemaps', label: __( 'XML sitemaps', 'searchpilot-pro' ) },
						{ id: 'robots', label: 'robots.txt' },
					],
					active: tab,
					onChange: show,
				} ),
				body
			);
			show( tab );
		},
	} );
} )( window.wp, window.SPP );

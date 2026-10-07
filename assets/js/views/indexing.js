/**
 * Indexing view: URL registry, own page checks and Google's index status.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	/** Index status → label + badge tone. */
	const STATUS = {
		unknown: { label: __( 'Not inspected yet', 'searchpilot-pro' ), tone: 'outline', color: '--spp-border-strong' },
		indexed: { label: __( 'Indexed', 'searchpilot-pro' ), tone: 'passed', color: '--spp-passed' },
		not_indexed: { label: __( 'Not indexed', 'searchpilot-pro' ), tone: 'high', color: '--spp-high' },
		crawled_not_indexed: { label: __( 'Crawled – not indexed', 'searchpilot-pro' ), tone: 'high', color: '--spp-high' },
		discovered_not_indexed: { label: __( 'Discovered – not indexed', 'searchpilot-pro' ), tone: 'medium', color: '--spp-medium' },
		excluded_noindex: { label: __( 'Excluded by noindex', 'searchpilot-pro' ), tone: 'medium', color: '--spp-medium' },
		blocked_robots: { label: __( 'Blocked by robots.txt', 'searchpilot-pro' ), tone: 'high', color: '--spp-high' },
		duplicate_canonical: { label: __( 'Duplicate – other canonical', 'searchpilot-pro' ), tone: 'medium', color: '--spp-medium' },
		alternate_canonical: { label: __( 'Alternate (canonical elsewhere)', 'searchpilot-pro' ), tone: 'info', color: '--spp-info' },
		redirect: { label: __( 'Page with redirect', 'searchpilot-pro' ), tone: 'info', color: '--spp-info' },
		not_found: { label: __( 'Not found (404)', 'searchpilot-pro' ), tone: 'critical', color: '--spp-critical' },
		soft_404: { label: __( 'Soft 404', 'searchpilot-pro' ), tone: 'high', color: '--spp-high' },
		server_error: { label: __( 'Server error (5xx)', 'searchpilot-pro' ), tone: 'critical', color: '--spp-critical' },
		unknown_to_google: { label: __( 'Unknown to Google', 'searchpilot-pro' ), tone: 'medium', color: '--spp-medium' },
		removed: { label: __( 'Removed', 'searchpilot-pro' ), tone: 'outline', color: '--spp-muted' },
	};

	/** Crawl error codes → labels. */
	const CODES = {
		fetch_failed: __( 'Could not fetch', 'searchpilot-pro' ),
		fetch_blocked: __( 'Request refused', 'searchpilot-pro' ),
		http_4xx: __( 'Client error (4xx)', 'searchpilot-pro' ),
		http_5xx: __( 'Server error (5xx)', 'searchpilot-pro' ),
		redirect: __( 'Redirects', 'searchpilot-pro' ),
		redirect_chain: __( 'Redirect chain', 'searchpilot-pro' ),
		redirect_loop: __( 'Redirect loop', 'searchpilot-pro' ),
		too_many_redirects: __( 'Too many redirects', 'searchpilot-pro' ),
		redirect_external: __( 'Redirects off-site', 'searchpilot-pro' ),
		redirect_to_error: __( 'Redirects to an error', 'searchpilot-pro' ),
		noindex: __( 'Noindex on page', 'searchpilot-pro' ),
		noindex_missing: __( 'Expected noindex missing', 'searchpilot-pro' ),
		robots_blocked: __( 'Blocked by robots.txt', 'searchpilot-pro' ),
		multiple_canonicals: __( 'Conflicting canonicals', 'searchpilot-pro' ),
		canonical_elsewhere: __( 'Canonical points elsewhere', 'searchpilot-pro' ),
		canonical_relative: __( 'Relative canonical', 'searchpilot-pro' ),
		canonical_cross_domain: __( 'Cross-domain canonical', 'searchpilot-pro' ),
		canonical_to_non_200: __( 'Canonical target not indexable', 'searchpilot-pro' ),
		pagination_canonical: __( 'Canonical to page 1', 'searchpilot-pro' ),
		soft_404: __( 'Soft 404', 'searchpilot-pro' ),
		not_html: __( 'Not an HTML page', 'searchpilot-pro' ),
		missing_title: __( 'Missing <title>', 'searchpilot-pro' ),
		multiple_titles: __( 'Several <title> tags', 'searchpilot-pro' ),
		invalid_json_ld: __( 'Invalid JSON-LD', 'searchpilot-pro' ),
	};
	const SEVERE_CODES = [ 'fetch_failed', 'http_4xx', 'http_5xx', 'redirect_loop', 'too_many_redirects', 'noindex', 'robots_blocked', 'multiple_canonicals', 'canonical_to_non_200', 'soft_404' ];

	/** Recommended action codes → labels. */
	const ACTIONS = {
		fix_redirect: __( 'Fix the redirect loop', 'searchpilot-pro' ),
		update_redirects: __( 'Shorten the redirect chain', 'searchpilot-pro' ),
		fix_server_error: __( 'Fix the server error', 'searchpilot-pro' ),
		restore_or_redirect: __( 'Restore or redirect', 'searchpilot-pro' ),
		improve_content: __( 'Improve the content', 'searchpilot-pro' ),
		remove_noindex: __( 'Remove noindex', 'searchpilot-pro' ),
		edit_robots_txt: __( 'Edit robots.txt', 'searchpilot-pro' ),
		fix_canonical: __( 'Fix the canonical', 'searchpilot-pro' ),
		remove_from_sitemap: __( 'Remove from sitemap', 'searchpilot-pro' ),
		add_to_sitemap: __( 'Add to sitemap', 'searchpilot-pro' ),
		add_internal_links: __( 'Add internal links', 'searchpilot-pro' ),
		request_indexing: __( 'Notify search engines', 'searchpilot-pro' ),
	};

	/** Indexability reasons → labels. */
	const REASONS = {
		ok: __( 'Indexable', 'searchpilot-pro' ),
		missing: __( 'Missing', 'searchpilot-pro' ),
		not_published: __( 'Not published', 'searchpilot-pro' ),
		password: __( 'Password protected', 'searchpilot-pro' ),
		post_type: __( 'Content type set to noindex', 'searchpilot-pro' ),
		excluded: __( 'Excluded in settings', 'searchpilot-pro' ),
		noindex: __( 'Set to noindex', 'searchpilot-pro' ),
		noindex_default: __( 'Noindex by default', 'searchpilot-pro' ),
		canonicalized: __( 'Canonical points elsewhere', 'searchpilot-pro' ),
		site_noindex: __( 'Site discourages indexing', 'searchpilot-pro' ),
		taxonomy: __( 'Taxonomy set to noindex', 'searchpilot-pro' ),
		empty: __( 'No published posts', 'searchpilot-pro' ),
		author_archives: __( 'Author archives off', 'searchpilot-pro' ),
		attachment: __( 'Attachment page', 'searchpilot-pro' ),
		paginated_noindex: __( 'Paginated pages noindexed', 'searchpilot-pro' ),
		utility_page: __( 'Shop utility page (cart, checkout, account)', 'searchpilot-pro' ),
	};

	/** Submission service keys → short labels. */
	const ENGINES = {
		indexnow: 'IndexNow',
		bing_url: 'Bing',
		bing: 'Bing',
		google_indexing: 'Google',
		google: 'Google',
		gsc_sitemap: __( 'Google sitemap', 'searchpilot-pro' ),
		bing_sitemap: __( 'Bing sitemap', 'searchpilot-pro' ),
	};

	const RECHECK_MARKER = '2000-01-01';

	/** Colour from a design-system CSS variable (charts need a concrete value). */
	function token( name ) {
		const holder = document.querySelector( '.spp-wrap' ) || document.body;
		return getComputedStyle( holder ).getPropertyValue( name ).trim() || null;
	}

	/** Comparable form of a URL (decoded, lower-case host, no default port). */
	function norm( u ) {
		if ( ! u ) {
			return '';
		}
		try {
			const url = new URL( u, SPP.boot.homeUrl );
			return ( url.protocol + '//' + url.hostname.toLowerCase() + ( url.port ? ':' + url.port : '' ) + fmt.url( url.pathname ) + url.search ).replace( /#.*$/, '' );
		} catch ( e ) {
			return String( u );
		}
	}

	/** Relative time with the full date on hover. */
	function when( iso, emptyText ) {
		if ( ! iso ) {
			return h( 'span', { class: 'spp-muted' }, emptyText || '—' );
		}
		return h( 'span', { class: 'is-nowrap', title: fmt.datetime( iso ) }, fmt.relative( iso ) );
	}

	/** Toolbar select that keeps its natural width. */
	function inlineSelect( opts ) {
		const sel = ui.select( opts );
		sel.style.width = 'auto';
		return sel;
	}

	function statusBadge( status ) {
		const s = STATUS[ status ] || STATUS.unknown;
		return ui.badge( s.label, s.tone, { dot: status !== 'unknown' } );
	}

	function codeLabel( code ) {
		return CODES[ code ] || code;
	}

	function isQueued( iso ) {
		return !! iso && String( iso ).indexOf( RECHECK_MARKER ) === 0;
	}

	/** A check ran but never got an answer from the page (HTTP 0: timeout, firewall…). */
	function checkFailed( row ) {
		return !! row.last_checked && ! isQueued( row.last_checked ) && ! row.http_status;
	}

	function checkFailedBadge( row ) {
		return ui.badge( __( 'Check failed', 'searchpilot-pro' ), 'critical', { dot: true, title: ( row.errors || [] ).map( codeLabel ).join( ', ' ) || null } );
	}

	/* ------------------------------------------------------------------ *
	 * Table cells
	 * ------------------------------------------------------------------ */

	function canonicalCell( row ) {
		const self = norm( row.url );
		const found = row.canonical_url;
		const parts = [];
		if ( row.redirect_to ) {
			// The destination's canonical says nothing about this URL: the redirect is the finding.
			return ui.badge( __( 'Redirects', 'searchpilot-pro' ), 'info', { title: fmt.url( row.redirect_to ) } );
		}
		if ( checkFailed( row ) ) {
			// Only the expected canonical is known; nothing was read from the page.
			return h( 'span', { class: 'spp-muted' }, '—' );
		}
		if ( ! row.last_checked ) {
			parts.push( h( 'span', { class: 'spp-muted', title: fmt.url( found || row.url ) }, found && norm( found ) !== self ? __( 'Other (expected)', 'searchpilot-pro' ) : __( 'Self (expected)', 'searchpilot-pro' ) ) );
		} else if ( found === '' || found === null ) {
			parts.push( ui.badge( row.http_status >= 200 && row.http_status < 300 ? __( 'Missing', 'searchpilot-pro' ) : '—', 'outline' ) );
		} else if ( norm( found ) === self ) {
			parts.push( ui.badge( __( 'Self', 'searchpilot-pro' ), 'passed', { title: fmt.url( found ) } ) );
		} else {
			parts.push( ui.badge( __( 'Other URL', 'searchpilot-pro' ), row.indexable ? 'high' : 'info', { title: fmt.url( found ) } ) );
		}
		if ( row.google_canonical && norm( row.google_canonical ) !== norm( found || row.url ) ) {
			parts.push( h( 'div', { style: { marginTop: '4px' } }, ui.badge( __( 'Google chose another', 'searchpilot-pro' ), 'medium', { title: fmt.url( row.google_canonical ) } ) ) );
		}
		return h( 'div', null, parts );
	}

	function sitemapCell( row ) {
		if ( row.in_sitemap ) {
			return ui.badge( __( 'In sitemap', 'searchpilot-pro' ), row.indexable ? 'passed' : 'high', { title: row.indexable ? null : __( 'Listed although it is not indexable', 'searchpilot-pro' ) } );
		}
		return ui.badge( __( 'Not in sitemap', 'searchpilot-pro' ), 'outline' );
	}

	function indexabilityCell( row ) {
		const errors = row.errors || [];
		if ( errors.indexOf( 'noindex' ) !== -1 ) {
			return ui.badge( __( 'Noindex on page', 'searchpilot-pro' ), 'critical', { dot: true, title: row.robots_meta || '' } );
		}
		if ( row.robots_txt_blocked ) {
			return ui.badge( __( 'Blocked by robots.txt', 'searchpilot-pro' ), row.indexable ? 'high' : 'outline' );
		}
		if ( row.indexable ) {
			return ui.badge( __( 'Indexable', 'searchpilot-pro' ), 'passed' );
		}
		return ui.badge( REASONS[ row.indexability_reason ] || __( 'Not indexable', 'searchpilot-pro' ), 'outline', { title: __( 'Not indexable', 'searchpilot-pro' ) } );
	}

	function enginesCell( row ) {
		const list = ( row.engines || [] ).filter( Boolean );
		if ( ! list.length ) {
			return h( 'span', { class: 'spp-muted' }, '—' );
		}
		return h( 'div', { class: 'spp-row', style: { gap: '4px', flexWrap: 'wrap' } }, list.map( ( e ) => ui.badge( ENGINES[ e ] || e, 'navy' ) ) );
	}

	function errorsCell( row ) {
		const errors = row.errors || [];
		if ( ! row.last_checked ) {
			return h( 'span', { class: 'spp-muted' }, __( 'Not checked yet', 'searchpilot-pro' ) );
		}
		if ( checkFailed( row ) ) {
			return checkFailedBadge( row );
		}
		if ( ! errors.length ) {
			return ui.badge( __( 'None', 'searchpilot-pro' ), 'passed' );
		}
		const shown = errors.slice( 0, 2 ).map( ( c ) => ui.badge( codeLabel( c ), SEVERE_CODES.indexOf( c ) !== -1 ? 'critical' : 'medium' ) );
		return h(
			'div',
			{ class: 'spp-row', style: { gap: '4px', flexWrap: 'wrap' }, title: errors.map( codeLabel ).join( ', ' ) },
			shown,
			errors.length > 2 ? h( 'span', { class: 'spp-muted spp-small' }, '+' + ( errors.length - 2 ) ) : null
		);
	}

	function actionCell( row ) {
		if ( row.recommended_action ) {
			return h( 'span', { class: 'spp-strong' }, ACTIONS[ row.recommended_action ] || row.recommended_action );
		}
		if ( ! row.last_checked ) {
			return h( 'span', { class: 'spp-muted' }, __( 'Waiting for first check', 'searchpilot-pro' ) );
		}
		if ( checkFailed( row ) ) {
			return h( 'span', { class: 'spp-strong' }, __( 'Re-check when the site is reachable', 'searchpilot-pro' ) );
		}
		return h( 'span', { class: 'spp-muted' }, __( 'Nothing to do', 'searchpilot-pro' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Details modal
	 * ------------------------------------------------------------------ */

	function openDetails( id, onChanged ) {
		const holder = h( 'div', null, ui.loading() );
		let latest = null;
		const modal = ui.modal( {
			title: __( 'URL details', 'searchpilot-pro' ),
			size: 'large',
			body: holder,
		} );
		const render = ( data ) => {
			latest = data;
			SPP.mount( holder, detailsBody( data, render, onChanged ) );
		};
		api.get( '/indexing/urls/' + id )
			.then( render )
			.catch( ( err ) => SPP.mount( holder, ui.errorBox( err ) ) );
		return { modal, latest: () => latest };
	}

	function detailsBody( data, render, onChanged ) {
		const row = data.url;
		const crawl = data.crawl;
		const insp = data.inspection;
		const caps = SPP.boot.caps || {};
		const refresh = ( res ) => {
			render( res );
			onChanged && onChanged();
			return res;
		};

		const actions = [];
		if ( caps.edit ) {
			actions.push(
				ui.button( {
					label: __( 'Inspect now', 'searchpilot-pro' ),
					icon: 'search',
					variant: 'primary',
					size: 'small',
					disabled: ! data.can_inspect,
					title: data.can_inspect ? __( 'Ask Google’s URL Inspection API now (uses the daily budget)', 'searchpilot-pro' ) : __( 'Connect Google Search Console to inspect URLs', 'searchpilot-pro' ),
					busyLabel: __( 'Inspecting…', 'searchpilot-pro' ),
					onClick: () =>
						api.post( '/indexing/urls/' + row.id + '/inspect' ).then( ( res ) => {
							ui.toast( __( 'Inspection updated', 'searchpilot-pro' ) );
							return refresh( res );
						} ),
				} ),
				ui.button( {
					label: __( 'Re-check', 'searchpilot-pro' ),
					icon: 'refresh',
					size: 'small',
					busyLabel: __( 'Checking…', 'searchpilot-pro' ),
					onClick: () =>
						api.post( '/indexing/urls/' + row.id + '/recheck' ).then( ( res ) => {
							ui.toast( __( 'Page checked', 'searchpilot-pro' ) );
							return refresh( res );
						} ),
				} ),
				ui.button( {
					label: __( 'Notify search engines', 'searchpilot-pro' ),
					icon: 'rocket',
					size: 'small',
					disabled: ! ( data.submissions && data.submissions.available ),
					title: data.submissions && data.submissions.available ? null : __( 'The submission module is not available', 'searchpilot-pro' ),
					onClick: () =>
						api.post( '/indexing/urls/' + row.id + '/submit' ).then( ( res ) => {
							ui.toast( res.message || __( 'Queued', 'searchpilot-pro' ), res.held ? 'warning' : 'success', 6000 );
							return res;
						} ),
				} )
			);
		}
		if ( data.inspection_link ) {
			actions.push( ui.button( { label: __( 'Open in Search Console', 'searchpilot-pro' ), icon: 'external', size: 'small', variant: 'ghost', href: data.inspection_link, target: '_blank' } ) );
		}
		if ( row.edit_link ) {
			actions.push( ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', variant: 'ghost', href: row.edit_link } ) );
		}

		const head = h(
			'div',
			{ class: 'spp-stack', style: { marginBottom: '16px' } },
			h( 'div', { class: 'spp-row', style: { gap: '8px', flexWrap: 'wrap' } }, statusBadge( row.index_status ), indexabilityCell( row ), sitemapCell( row ), ui.badge( row.type_label || row.object_type, 'navy' ) ),
			h( 'div', null, row.title ? h( 'div', { class: 'spp-strong' }, row.title ) : null, ui.link( fmt.url( row.url ), row.url, { external: true, class: 'spp-break' } ) ),
			actions.length ? h( 'div', { class: 'spp-row', style: { gap: '8px', flexWrap: 'wrap' } }, actions ) : null
		);

		const statusText = ( s ) => ( s ? String( s ) : null );
		const httpText = () => {
			if ( ! row.last_checked ) {
				return __( 'Not checked yet', 'searchpilot-pro' );
			}
			if ( checkFailed( row ) ) {
				return checkFailedBadge( row );
			}
			let t = String( row.http_status );
			if ( crawl && crawl.status && crawl.status !== row.http_status ) {
				t += ' → ' + crawl.status;
			}
			return t;
		};

		const fields = ui.card( {
			title: __( 'Registry', 'searchpilot-pro' ),
			body: ui.dl( [
				[ __( 'Index status', 'searchpilot-pro' ), statusBadge( row.index_status ) ],
				[ __( 'Coverage (Google)', 'searchpilot-pro' ), statusText( row.coverage_state ) ],
				[ __( 'Last submitted', 'searchpilot-pro' ), when( row.last_submitted, __( 'never', 'searchpilot-pro' ) ) ],
				[ __( 'Last crawled by Google', 'searchpilot-pro' ), when( row.last_crawled, __( 'unknown', 'searchpilot-pro' ) ) ],
				[ __( 'Last modified', 'searchpilot-pro' ), when( row.last_modified ) ],
				[ __( 'Canonical on the page', 'searchpilot-pro' ), row.last_checked ? ( row.canonical_url ? fmt.url( row.canonical_url ) : __( 'none found', 'searchpilot-pro' ) ) : null ],
				[ __( 'Expected canonical', 'searchpilot-pro' ), fmt.url( data.expected_canonical ) ],
				[ __( 'Google-selected canonical', 'searchpilot-pro' ), row.google_canonical ? fmt.url( row.google_canonical ) : null ],
				[ __( 'Sitemap', 'searchpilot-pro' ), sitemapCell( row ) ],
				[ __( 'Indexability (WordPress)', 'searchpilot-pro' ), REASONS[ row.indexability_reason ] || row.indexability_reason ],
				[ __( 'Search engines notified', 'searchpilot-pro' ), enginesCell( row ) ],
				[ __( 'HTTP status', 'searchpilot-pro' ), httpText() ],
				[ __( 'Redirects to', 'searchpilot-pro' ), row.redirect_to ? fmt.url( row.redirect_to ) : null ],
				[ __( 'Robots directives', 'searchpilot-pro' ), row.robots_meta || null ],
				[ __( 'robots.txt', 'searchpilot-pro' ), data.robots && data.robots.blocked ? sprintf( /* translators: 1: crawlers, 2: rule. */ __( 'Blocks %1$s (%2$s)', 'searchpilot-pro' ), data.robots.agents.join( ', ' ), data.robots.rule || '—' ) : __( 'Allowed', 'searchpilot-pro' ) ],
				[ __( 'Errors', 'searchpilot-pro' ), errorsCell( row ) ],
				[ __( 'Recommended action', 'searchpilot-pro' ), actionCell( row ) ],
				[ __( 'Checked by SearchPilot', 'searchpilot-pro' ), isQueued( row.last_checked ) ? __( 'Re-check queued', 'searchpilot-pro' ) : when( row.last_checked, __( 'not yet', 'searchpilot-pro' ) ) ],
				[ __( 'Inspected via Search Console', 'searchpilot-pro' ), when( row.last_inspected, __( 'not yet', 'searchpilot-pro' ) ) ],
				[ __( 'Crawl priority', 'searchpilot-pro' ), String( row.priority ) ],
			] ),
		} );

		const sections = [ head, fields ];

		if ( crawl ) {
			if ( crawl.hops && crawl.hops.length ) {
				sections.push(
					ui.card( {
						title: __( 'Redirect chain', 'searchpilot-pro' ),
						body: h(
							'ol',
							{ class: 'spp-list' },
							crawl.hops.map( ( hop ) => h( 'li', { class: 'spp-break' }, h( 'span', { class: 'spp-mono' }, String( hop.status ) ), ' ', fmt.url( hop.url ), ' → ', fmt.url( hop.location ) ) ),
							h( 'li', { class: 'spp-break' }, h( 'span', { class: 'spp-mono' }, String( crawl.status ) ), ' ', fmt.url( crawl.final_url ) )
						),
					} )
				);
			}
			const jsonld = crawl.json_ld || {};
			sections.push(
				ui.card( {
					title: __( 'Rendered page (latest check)', 'searchpilot-pro' ),
					subtitle: crawl.checked_at ? sprintf( /* translators: %s: date. */ __( 'Checked %s', 'searchpilot-pro' ), fmt.datetime( crawl.checked_at ) ) : null,
					body: ui.dl( [
						[ __( '<title> tags', 'searchpilot-pro' ), crawl.is_html ? String( crawl.title_count ) + ( crawl.title ? ' — ' + crawl.title : '' ) : null ],
						[ __( 'Meta descriptions', 'searchpilot-pro' ), crawl.is_html ? String( crawl.description_count ) : null ],
						[ __( 'og:title tags', 'searchpilot-pro' ), crawl.is_html ? String( crawl.og_title_count ) : null ],
						[ __( 'H1 headings', 'searchpilot-pro' ), crawl.is_html ? String( crawl.h1_count ) : null ],
						[ __( 'Canonical tags', 'searchpilot-pro' ), crawl.canonicals && crawl.canonicals.length ? h( 'div', null, crawl.canonicals.map( ( c ) => h( 'div', { class: 'spp-break' }, fmt.url( c.url ), c.relative ? ' ' : null, c.relative ? ui.badge( __( 'relative', 'searchpilot-pro' ), 'medium' ) : null ) ) ) : null ],
						[ __( 'Canonical target', 'searchpilot-pro' ), crawl.canonical_target && crawl.canonical_target.checked ? sprintf( /* translators: 1: status, 2: URL. */ __( 'HTTP %1$s — %2$s', 'searchpilot-pro' ), crawl.canonical_target.status, fmt.url( crawl.canonical_target.final || crawl.canonical_target.url ) ) + ( crawl.canonical_target.noindex ? ' · noindex' : '' ) : null ],
						[ __( 'hreflang alternates', 'searchpilot-pro' ), crawl.hreflang ? String( Object.keys( crawl.hreflang ).length ) : null ],
						[ __( 'JSON-LD blocks', 'searchpilot-pro' ), jsonld.count !== undefined ? sprintf( /* translators: 1: blocks, 2: invalid blocks. */ __( '%1$d (%2$d invalid)', 'searchpilot-pro' ), jsonld.count, jsonld.invalid ) + ( jsonld.types && jsonld.types.length ? ' — ' + jsonld.types.slice( 0, 8 ).join( ', ' ) : '' ) : null ],
						[ __( 'Words in main content', 'searchpilot-pro' ), crawl.is_html ? fmt.number( crawl.word_count ) : null ],
						[ __( 'Soft 404 signal', 'searchpilot-pro' ), crawl.soft_404 ? crawl.soft_404_reason : null ],
						[ __( 'Response time', 'searchpilot-pro' ), crawl.duration_ms ? fmt.duration( crawl.duration_ms ) : null ],
					] ),
				} )
			);
		}

		if ( insp ) {
			const rich = insp.rich_results || {};
			const richItems = ( rich.items || [] ).map( ( item ) =>
				h(
					'li',
					null,
					h( 'span', { class: 'spp-strong' }, item.type ),
					( item.issues || [] ).length
						? h(
								'ul',
								{ class: 'spp-list' },
								item.issues.map( ( issue ) => h( 'li', null, ui.badge( issue.severity || '', /error/i.test( issue.severity || '' ) ? 'critical' : 'medium' ), ' ', issue.message ) )
						  )
						: h( 'span', { class: 'spp-muted' }, ' — ', __( 'no issues', 'searchpilot-pro' ) )
				)
			);
			sections.push(
				ui.card( {
					title: __( 'Google URL Inspection', 'searchpilot-pro' ),
					subtitle: insp.inspected_at ? sprintf( /* translators: %s: date. */ __( 'Inspected %s', 'searchpilot-pro' ), fmt.datetime( insp.inspected_at ) ) : null,
					body: [
						ui.dl( [
							[ __( 'Verdict', 'searchpilot-pro' ), insp.verdict ],
							[ __( 'Coverage', 'searchpilot-pro' ), insp.coverage_state ],
							[ __( 'Indexing allowed', 'searchpilot-pro' ), insp.indexing_state ],
							[ __( 'robots.txt', 'searchpilot-pro' ), insp.robots_txt_state ],
							[ __( 'Page fetch', 'searchpilot-pro' ), insp.page_fetch_state ],
							[ __( 'Crawled as', 'searchpilot-pro' ), insp.crawled_as ],
							[ __( 'Last crawl', 'searchpilot-pro' ), when( insp.last_crawl_time, __( 'never', 'searchpilot-pro' ) ) ],
							[ __( 'Google-selected canonical', 'searchpilot-pro' ), insp.google_canonical ? fmt.url( insp.google_canonical ) : null ],
							[ __( 'User-declared canonical', 'searchpilot-pro' ), insp.user_canonical ? fmt.url( insp.user_canonical ) : null ],
							[ __( 'Sitemaps', 'searchpilot-pro' ), ( insp.sitemaps || [] ).length ? h( 'div', null, insp.sitemaps.map( ( s ) => h( 'div', { class: 'spp-break' }, fmt.url( s ) ) ) ) : null ],
							[ __( 'Referring pages', 'searchpilot-pro' ), ( insp.referring_urls || [] ).length ? h( 'div', null, insp.referring_urls.slice( 0, 5 ).map( ( s ) => h( 'div', { class: 'spp-break' }, fmt.url( s ) ) ) ) : null ],
							[ __( 'Rich results', 'searchpilot-pro' ), rich.verdict ? rich.verdict : __( 'none detected', 'searchpilot-pro' ) ],
						] ),
						richItems.length ? h( 'ul', { class: 'spp-list' }, richItems ) : null,
					],
				} )
			);
		} else {
			sections.push(
				ui.notice( {
					tone: 'info',
					title: __( 'No URL Inspection result yet', 'searchpilot-pro' ),
					text: data.can_inspect ? __( 'SearchPilot inspects URLs within your daily budget. Use “Inspect now” to check this one immediately.', 'searchpilot-pro' ) : __( 'Connect Google Search Console to see Google’s own index status for this URL. SearchPilot still checks the page itself.', 'searchpilot-pro' ),
				} )
			);
		}

		const issues = data.issues || [];
		sections.push(
			ui.card( {
				title: sprintf( /* translators: %d: number of issues. */ _n( '%d open issue', '%d open issues', issues.length, 'searchpilot-pro' ), issues.length ),
				body: issues.length
					? h(
							'div',
							{ class: 'spp-stack' },
							issues.map( ( issue ) =>
								ui.issue( issue, {
									compact: true,
									onChanged: () => api.get( '/indexing/urls/' + row.id ).then( refresh ),
								} )
							)
					  )
					: ui.empty( { icon: 'check', title: __( 'No open issues for this URL', 'searchpilot-pro' ) } ),
			} )
		);
		return h( 'div', { class: 'spp-stack' }, sections );
	}

	/* ------------------------------------------------------------------ *
	 * View
	 * ------------------------------------------------------------------ */

	SPP.registerView( 'indexing', {
		mount( el, ctx ) {
			const caps = SPP.boot.caps || {};
			const q = {
				status: SPP.query.get( 'status' ),
				type: SPP.query.get( 'type' ),
				sitemap: SPP.query.get( 'sitemap' ),
				http: SPP.query.get( 'http' ),
				errors: SPP.query.get( 'errors' ),
				robots: SPP.query.get( 'robots' ),
				search: SPP.query.get( 's' ),
			};
			const CLEAR = { status: '', type: '', sitemap: '', http: '', errors: '', robots: '' };
			const toQuery = () => {
				const [ objectType, objectSubtype ] = ( q.type || '' ).split( '|' );
				return {
					index_status: q.status,
					object_type: objectType || '',
					object_subtype: objectSubtype || '',
					in_sitemap: q.sitemap,
					http: q.http,
					has_errors: q.errors === '1' ? 1 : '',
					robots_txt_blocked: q.robots,
					search: q.search,
				};
			};
			const setFilter = ( patch ) => {
				Object.assign( q, patch );
				SPP.query.set( { status: q.status, type: q.type, sitemap: q.sitemap, http: q.http, errors: q.errors, robots: q.robots, s: q.search } );
				table.setQuery( toQuery() );
			};

			let summaryData = null;
			const reloadAll = () => {
				summary.refresh();
				table.reload();
			};

			const summary = SPP.region( () => api.get( '/indexing/summary' ).then( renderSummary ), { loader: false } );

			function renderSummary( data ) {
				summaryData = data;
				const s = data.summary || {};
				const by = data.by_status || {};
				const nodes = [];

				if ( ! data.inspection.connected ) {
					nodes.push(
						ui.notice( {
							tone: 'info',
							icon: 'globe',
							title: __( 'Connect Google Search Console to see Google’s index status', 'searchpilot-pro' ),
							text: __( 'SearchPilot still checks your pages itself — status codes, noindex, canonicals, redirects and robots.txt — but only Search Console can tell whether Google indexed them.', 'searchpilot-pro' ),
							actions: SPP.boot.pages && SPP.boot.pages.engines ? [ ui.button( { label: __( 'Open Search Engines', 'searchpilot-pro' ), size: 'small', variant: 'primary', href: SPP.boot.pages.engines } ) ] : null,
						} )
					);
				}
				if ( data.crawl.paused_until ) {
					nodes.push(
						ui.notice( {
							tone: 'high',
							title: __( 'Page checks are paused', 'searchpilot-pro' ),
							text: sprintf( /* translators: 1: error, 2: time. */ __( 'SearchPilot could not reach your own pages (%1$s). It retries automatically %2$s. Firewalls, basic authentication or CDN bot protection are the usual cause; you can set the origin server IP in Settings → Indexing.', 'searchpilot-pro' ), data.crawl.last_error || __( 'no response', 'searchpilot-pro' ), fmt.relative( data.crawl.paused_until ) ),
							actions: caps.manage
								? [
										ui.button( {
											label: __( 'Retry now', 'searchpilot-pro' ),
											size: 'small',
											onClick: () => api.post( '/indexing/resume' ).then( () => api.post( '/indexing/crawl' ) ).then( reloadAll ),
										} ),
								  ]
								: null,
						} )
					);
				}
				const reg = data.registry || {};
				if ( reg.status === 'running' ) {
					nodes.push(
						ui.card( {
							body: ui.progress( {
								value: reg.done || 0,
								max: Math.max( reg.total || 1, reg.done || 1 ),
								tone: 'gold',
								label: __( 'Building the URL registry…', 'searchpilot-pro' ),
								right: sprintf( /* translators: 1: done, 2: total. */ __( '%1$s of about %2$s URLs', 'searchpilot-pro' ), fmt.number( reg.done || 0 ), fmt.number( reg.total || 0 ) ),
							} ),
						} )
					);
				} else if ( reg.status === 'pending' && ! s.total ) {
					nodes.push(
						ui.notice( {
							tone: 'gold',
							title: __( 'The URL registry has not been built yet', 'searchpilot-pro' ),
							text: __( 'It is built automatically after setup. You can start it now.', 'searchpilot-pro' ),
							actions: caps.manage ? [ rebuildButton( 'primary' ) ] : null,
						} )
					);
				}

				const errors = ( s.errors_4xx || 0 ) + ( s.errors_5xx || 0 );
				// KPI tiles filter the table below.
				const clickable = ( node, patch ) => {
					node.style.cursor = 'pointer';
					node.setAttribute( 'role', 'button' );
					node.setAttribute( 'tabindex', '0' );
					node.addEventListener( 'click', () => {
						Object.assign( q, Object.assign( {}, CLEAR, patch ) );
						SPP.mount( filterBar, filters() );
						setFilter( {} );
					} );
					node.addEventListener( 'keydown', ( e ) => {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							node.click();
						}
					} );
					return node;
				};
				nodes.push(
					ui.grid(
						6,
						clickable( ui.kpi( { label: __( 'Indexed', 'searchpilot-pro' ), value: fmt.number( s.indexed ), tone: 'passed', icon: 'check', hint: data.inspection.connected ? __( 'per URL Inspection', 'searchpilot-pro' ) : __( 'needs Search Console', 'searchpilot-pro' ) } ), { status: 'indexed' } ),
						clickable( ui.kpi( { label: __( 'Not indexed', 'searchpilot-pro' ), value: fmt.number( s.not_indexed ), tone: s.not_indexed ? 'high' : null, icon: 'alert', hint: __( 'crawled, discovered or unknown', 'searchpilot-pro' ) } ), { status: 'not_indexed,crawled_not_indexed,discovered_not_indexed,unknown_to_google' } ),
						clickable( ui.kpi( { label: __( 'Not inspected yet', 'searchpilot-pro' ), value: fmt.number( s.unknown ), icon: 'info', hint: __( 'waiting for Google’s status', 'searchpilot-pro' ) } ), { status: 'unknown' } ),
						clickable( ui.kpi( { label: __( '4xx / 5xx errors', 'searchpilot-pro' ), value: fmt.number( errors ), tone: errors ? 'critical' : 'passed', icon: 'alert', hint: sprintf( /* translators: 1: 4xx, 2: 5xx. */ __( '%1$s client · %2$s server', 'searchpilot-pro' ), fmt.number( s.errors_4xx ), fmt.number( s.errors_5xx ) ) } ), { http: 'error' } ),
						clickable( ui.kpi( { label: __( 'Blocked by robots.txt', 'searchpilot-pro' ), value: fmt.number( s.blocked ), tone: s.blocked ? 'high' : null, icon: 'shield', hint: __( 'Googlebot or Bingbot', 'searchpilot-pro' ) } ), { robots: '1' } ),
						clickable( ui.kpi( { label: __( 'In sitemap', 'searchpilot-pro' ), value: fmt.number( s.in_sitemap ), icon: 'map', hint: sprintf( /* translators: %s: total URLs. */ __( 'of %s URLs', 'searchpilot-pro' ), fmt.number( s.total ) ) } ), { sitemap: '1' } )
					)
				);

				const segments = Object.keys( STATUS )
					.filter( ( k ) => k !== 'removed' && by[ k ] )
					.map( ( k ) => ( { label: STATUS[ k ].label, value: by[ k ], color: token( STATUS[ k ].color ) } ) );
				const crawlRun = data.crawl.last_run || {};
				const inspRun = data.inspection.last_run || {};
				const quota = data.inspection.quota || {};
				const robots = data.robots || {};
				const bing = data.bing || null;

				nodes.push(
					ui.grid(
						2,
						ui.card( {
							title: __( 'Index status', 'searchpilot-pro' ),
							subtitle: __( 'Google’s view from URL Inspection; “not inspected yet” until Search Console reports on a URL.', 'searchpilot-pro' ),
							body: segments.length ? ui.donut( { segments, center: fmt.compact( s.total ), caption: __( 'active URLs', 'searchpilot-pro' ) } ) : ui.empty( { icon: 'chart', title: __( 'No URLs registered yet', 'searchpilot-pro' ) } ),
						} ),
						ui.card( {
							title: __( 'Checks & budget', 'searchpilot-pro' ),
							body: ui.dl( [
								[ __( 'Last page check run', 'searchpilot-pro' ), crawlRun.at ? sprintf( /* translators: 1: time, 2: checked, 3: failed. */ __( '%1$s — %2$d checked, %3$d failed', 'searchpilot-pro' ), fmt.relative( crawlRun.at ), crawlRun.checked || 0, crawlRun.failed || 0 ) : __( 'never', 'searchpilot-pro' ) ],
								[ __( 'Waiting for a check', 'searchpilot-pro' ), sprintf( /* translators: 1: backlog, 2: batch, 3: days. */ __( '%1$s URLs (batches of %2$d, re-checked every %3$d days)', 'searchpilot-pro' ), data.crawl.backlog >= 1000 ? '1,000+' : fmt.number( data.crawl.backlog ), data.crawl.batch, data.crawl.recheck_days ) ],
								[ __( 'URL Inspection today', 'searchpilot-pro' ), data.inspection.connected ? sprintf( /* translators: 1: used, 2: limit, 3: per run. */ __( '%1$s of %2$s used (up to %3$d per hour)', 'searchpilot-pro' ), fmt.number( quota.used ), fmt.number( quota.limit ), quota.per_run ) : __( 'Search Console not connected', 'searchpilot-pro' ) ],
								[ __( 'Last inspection run', 'searchpilot-pro' ), inspRun.at ? sprintf( /* translators: 1: time, 2: inspected. */ __( '%1$s — %2$d inspected', 'searchpilot-pro' ), fmt.relative( inspRun.at ), inspRun.inspected || 0 ) : __( 'never', 'searchpilot-pro' ) ],
								[ 'robots.txt', robots.status ? sprintf( /* translators: 1: HTTP status, 2: time. */ __( 'HTTP %1$d, fetched %2$s', 'searchpilot-pro' ), robots.status, fmt.relative( robots.fetched_at ) ) + ( robots.error ? ' — ' + robots.error : '' ) : __( 'not fetched yet', 'searchpilot-pro' ) ],
								[ __( 'Sitemap', 'searchpilot-pro' ), data.sitemap.owner === 'off' ? __( 'No sitemap', 'searchpilot-pro' ) : sprintf( /* translators: %s: plugin name. */ __( 'Generated by %s', 'searchpilot-pro' ), data.sitemap.owner_name ) ],
								[ __( 'Registry', 'searchpilot-pro' ), reg.finished_at ? sprintf( /* translators: %s: time. */ __( 'Built %s', 'searchpilot-pro' ), fmt.relative( reg.finished_at ) ) : reg.status === 'running' ? __( 'Building…', 'searchpilot-pro' ) : __( 'Not built yet', 'searchpilot-pro' ) ],
								bing
									? [
											__( 'Bing crawl errors (30 days)', 'searchpilot-pro' ),
											sprintf( /* translators: 1: 4xx, 2: 5xx, 3: blocked. */ __( '%1$s client · %2$s server · %3$s blocked by robots.txt', 'searchpilot-pro' ), fmt.number( bing.code_4xx ), fmt.number( bing.code_5xx ), fmt.number( bing.blocked_by_robots ) ) +
												( bing.recent_7 !== null && bing.recent_7 !== undefined ? ' — ' + sprintf( /* translators: %s: errors in the last 7 days. */ __( '%s in the last 7 days', 'searchpilot-pro' ), fmt.number( bing.recent_7 ) ) : '' ),
									  ]
									: null,
							] ),
						} )
					)
				);

				// Refresh content-type options once real data is known.
				SPP.mount( filterBar, filters() );
				return h( 'div', { class: 'spp-stack' }, nodes );
			}

			const statusOptions = [
				{ value: '', label: __( 'All index statuses', 'searchpilot-pro' ) },
				{ value: 'not_indexed,crawled_not_indexed,discovered_not_indexed,unknown_to_google', label: __( 'All “not indexed”', 'searchpilot-pro' ) },
			].concat( Object.keys( STATUS ).filter( ( k ) => k !== 'removed' ).map( ( k ) => ( { value: k, label: STATUS[ k ].label } ) ) );

			const filterBar = h( 'div' );
			function filters() {
				const types = ( summaryData && summaryData.by_type ) || [];
				return ui.toolbar(
					ui.search( { value: q.search, placeholder: __( 'Search URLs…', 'searchpilot-pro' ), onSearch: ( v ) => setFilter( { search: v } ) } ),
					inlineSelect( { label: __( 'Index status', 'searchpilot-pro' ), value: q.status, options: statusOptions, onChange: ( v ) => setFilter( { status: v } ) } ),
					inlineSelect( {
						label: __( 'Content type', 'searchpilot-pro' ),
						value: q.type,
						options: [ { value: '', label: __( 'All content types', 'searchpilot-pro' ) } ].concat( types.map( ( t ) => ( { value: t.object_type + '|' + t.object_subtype, label: t.label + ' (' + fmt.number( t.total ) + ')' } ) ) ),
						onChange: ( v ) => setFilter( { type: v } ),
					} ),
					inlineSelect( {
						label: __( 'Sitemap', 'searchpilot-pro' ),
						value: q.sitemap,
						options: [
							{ value: '', label: __( 'In sitemap or not', 'searchpilot-pro' ) },
							{ value: '1', label: __( 'In sitemap', 'searchpilot-pro' ) },
							{ value: '0', label: __( 'Not in sitemap', 'searchpilot-pro' ) },
						],
						onChange: ( v ) => setFilter( { sitemap: v } ),
					} ),
					inlineSelect( {
						label: __( 'HTTP status', 'searchpilot-pro' ),
						value: q.http,
						options: [
							{ value: '', label: __( 'Any HTTP status', 'searchpilot-pro' ) },
							{ value: 'error', label: __( 'Any error (4xx or 5xx)', 'searchpilot-pro' ) },
							{ value: '2xx', label: __( '2xx success', 'searchpilot-pro' ) },
							{ value: '3xx', label: __( '3xx redirect', 'searchpilot-pro' ) },
							{ value: '4xx', label: __( '4xx client error', 'searchpilot-pro' ) },
							{ value: '5xx', label: __( '5xx server error', 'searchpilot-pro' ) },
						],
						onChange: ( v ) => setFilter( { http: v } ),
					} ),
					inlineSelect( {
						label: __( 'robots.txt', 'searchpilot-pro' ),
						value: q.robots,
						options: [
							{ value: '', label: __( 'robots.txt: any', 'searchpilot-pro' ) },
							{ value: '1', label: __( 'Blocked by robots.txt', 'searchpilot-pro' ) },
							{ value: '0', label: __( 'Allowed by robots.txt', 'searchpilot-pro' ) },
						],
						onChange: ( v ) => setFilter( { robots: v } ),
					} ),
					ui.field.toggle( { label: __( 'Errors only', 'searchpilot-pro' ), toggleLabel: __( 'Errors only', 'searchpilot-pro' ), checked: q.errors === '1', onChange: ( on ) => setFilter( { errors: on ? '1' : '' } ) } )
				);
			}
			SPP.mount( filterBar, filters() );

			const table = ui.dataTable( {
				path: '/indexing/urls',
				query: toQuery(),
				perPage: 25,
				sort: { key: 'last_modified', dir: 'desc' },
				empty: __( 'No URLs match these filters.', 'searchpilot-pro' ),
				columns: [
					{
						key: 'url',
						label: __( 'URL', 'searchpilot-pro' ),
						sortable: true,
						render: ( r ) =>
							h(
								'div',
								null,
								h( 'button', { type: 'button', class: 'spp-btn is-link spp-cell-title', title: __( 'Show details', 'searchpilot-pro' ), onClick: () => openDetails( r.id, () => table.reload() ) }, r.title || fmt.path( r.url ) ),
								h( 'a', { class: 'spp-cell-sub', href: r.url, target: '_blank', rel: 'noopener noreferrer', title: fmt.url( r.url ) }, fmt.path( r.url ) ),
								h( 'span', { class: 'spp-cell-sub' }, r.type_label || r.object_type )
							),
					},
					{ key: 'index_status', label: __( 'Index Status', 'searchpilot-pro' ), sortable: true, render: ( r ) => statusBadge( r.index_status ) },
					{ key: 'last_submitted', label: __( 'Last Submitted', 'searchpilot-pro' ), sortable: true, className: 'is-nowrap', render: ( r ) => when( r.last_submitted, __( 'never', 'searchpilot-pro' ) ) },
					{ key: 'last_crawled', label: __( 'Last Crawled', 'searchpilot-pro' ), sortable: true, className: 'is-nowrap', render: ( r ) => when( r.last_crawled, __( 'unknown', 'searchpilot-pro' ) ) },
					{ key: 'last_modified', label: __( 'Last Modified', 'searchpilot-pro' ), sortable: true, className: 'is-nowrap', render: ( r ) => when( r.last_modified ) },
					{ key: 'canonical', label: __( 'Canonical', 'searchpilot-pro' ), render: canonicalCell },
					{ key: 'in_sitemap', label: __( 'Sitemap Status', 'searchpilot-pro' ), render: sitemapCell },
					{ key: 'indexable', label: __( 'Indexability', 'searchpilot-pro' ), render: indexabilityCell },
					{ key: 'engines', label: __( 'Search Engines', 'searchpilot-pro' ), render: enginesCell },
					{ key: 'errors', label: __( 'Errors', 'searchpilot-pro' ), render: errorsCell },
					{ key: 'recommended_action', label: __( 'Recommended Action', 'searchpilot-pro' ), render: actionCell },
				],
			} );

			function rebuildButton( variant ) {
				return ui.button( {
					label: __( 'Rebuild registry', 'searchpilot-pro' ),
					icon: 'refresh',
					variant: variant || null,
					busyLabel: __( 'Rebuilding…', 'searchpilot-pro' ),
					onClick: () =>
						ui
							.confirm( {
								title: __( 'Rebuild the URL registry?', 'searchpilot-pro' ),
								message: __( 'SearchPilot re-reads every published post, term and author archive in the background. Existing check and inspection results are kept.', 'searchpilot-pro' ),
								confirmLabel: __( 'Rebuild', 'searchpilot-pro' ),
							} )
							.then( ( ok ) => {
								if ( ! ok ) {
									return null;
								}
								return api.post( '/indexing/rebuild' ).then( ( res ) => {
									const reg = res.registry || {};
									ui.toast( reg.status === 'done' ? __( 'Registry rebuilt', 'searchpilot-pro' ) : __( 'Registry rebuild started — it continues in the background', 'searchpilot-pro' ) );
									reloadAll();
								} );
							} ),
				} );
			}

			const headerActions = [];
			if ( caps.manage ) {
				headerActions.push(
					ui.button( {
						label: __( 'Run crawl now', 'searchpilot-pro' ),
						icon: 'bolt',
						variant: 'primary',
						busyLabel: __( 'Checking pages…', 'searchpilot-pro' ),
						onClick: () =>
							api.post( '/indexing/crawl' ).then( ( res ) => {
								const c = res.crawl || {};
								if ( c.status === 'paused' ) {
									ui.toast( __( 'Page checks are paused because the site could not reach itself.', 'searchpilot-pro' ), 'warning' );
								} else if ( c.status === 'busy' ) {
									ui.toast( __( 'A crawl is already running in the background.', 'searchpilot-pro' ), 'warning' );
								} else {
									ui.toast( sprintf( /* translators: 1: checked, 2: failed. */ __( 'Checked %1$d URLs (%2$d failed)', 'searchpilot-pro' ), c.checked || 0, c.failed || 0 ) );
								}
								reloadAll();
							} ),
					} ),
					rebuildButton()
				);
			}
			headerActions.push( ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', variant: 'ghost', onClick: () => reloadAll() } ) );

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Indexing', 'searchpilot-pro' ),
					subtitle: __( 'Every public URL of the site with Google’s index status and SearchPilot’s own page checks. Indexing is decided by search engines and is never guaranteed.', 'searchpilot-pro' ),
					actions: headerActions,
				} ),
				summary.el,
				ui.card( {
					flush: true,
					title: __( 'URLs', 'searchpilot-pro' ),
					body: [ h( 'div', { style: { padding: '0 20px' } }, filterBar ), table.el ],
				} )
			);

			// Opened from the admin bar: ?post_id=123.
			const postId = parseInt( ( ctx && ctx.params && ctx.params.get( 'post_id' ) ) || '0', 10 );
			if ( postId > 0 ) {
				api.get( '/indexing/object', { object_type: 'post', object_id: postId } )
					.then( ( res ) => {
						if ( res.url && res.url.id ) {
							openDetails( res.url.id, () => table.reload() );
						} else {
							ui.toast( __( 'This page is not in the URL registry yet. It is added when it is published or when the registry is rebuilt.', 'searchpilot-pro' ), 'warning', 7000 );
						}
					} )
					.catch( ( err ) => ui.toast( err.message, 'error' ) );
			}
		},
	} );
} )( window.wp, window.SPP );

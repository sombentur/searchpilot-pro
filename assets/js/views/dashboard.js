/**
 * Dashboard view: search performance, indexing, opportunities and health at a glance.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const PALETTE = ui.PALETTE;
	const RANGES = [ '7d', '28d', '3m', '6m', '12m' ];
	const SOURCE_LABELS = {
		user: __( 'You', 'searchpilot-pro' ),
		manual: __( 'Manual', 'searchpilot-pro' ),
		assisted: __( 'Assisted', 'searchpilot-pro' ),
		autopilot: __( 'Autopilot', 'searchpilot-pro' ),
		system: __( 'System', 'searchpilot-pro' ),
		import: __( 'Import', 'searchpilot-pro' ),
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

	/** delta + trend options for ui.kpi; position compares the absolute change (lower is better). */
	function trendParts( metric, current, previous ) {
		if ( metric === 'position' ) {
			if ( ! current || ! previous ) {
				return null;
			}
			const diff = current - previous;
			return {
				delta: { pct: diff, dir: Math.abs( diff ) < 0.05 ? 'flat' : diff > 0 ? 'up' : 'down' },
				trend: { good: 'down', absolute: fmt.number( Math.abs( diff ), 1 ), title: __( 'Change in average position (lower is better)', 'searchpilot-pro' ) },
			};
		}
		if ( ! current && ! previous ) {
			return null;
		}
		return { delta: fmt.delta( current, previous ), trend: {} };
	}

	/** Why a change is not shown: the stored history does not cover the whole previous period. */
	function partialHint( prev ) {
		return sprintf(
			/* translators: 1: covered days, 2: days in the period. */
			__( 'No comparison — only %1$s of %2$s days of the previous period are stored', 'searchpilot-pro' ),
			fmt.number( prev.covered_days || 0 ),
			fmt.number( prev.window_days || 0 )
		);
	}

	function formatMetric( metric, value ) {
		if ( metric === 'ctr' ) {
			return fmt.percent( value, 1 );
		}
		if ( metric === 'position' ) {
			return fmt.position( value );
		}
		return fmt.compact( value );
	}

	/** KPI tile with a trend chip (vs the previous period, when fully covered) and a sparkline. */
	function perfKpi( label, metric, totals, series, icon ) {
		const prev = totals.prev || {};
		const opts = {
			label,
			icon,
			value: totals.has_data ? formatMetric( metric, totals[ metric ] ) : '—',
			spark: series && series[ metric ] ? series[ metric ].filter( ( v ) => v !== null && v !== undefined ) : null,
			sparkColor: metric === 'impressions' ? PALETTE[ 1 ] : PALETTE[ 0 ],
		};
		if ( ! totals.has_data ) {
			opts.hint = __( 'No data yet', 'searchpilot-pro' );
		} else if ( totals.comparable === false || prev.complete === false ) {
			opts.hint = partialHint( prev );
		} else {
			const parts = trendParts( metric, totals[ metric ], prev[ metric ] );
			if ( parts ) {
				opts.delta = parts.delta;
				opts.trend = parts.trend;
			}
			opts.hint = sprintf( /* translators: %s: previous value. */ __( 'Previous period: %s', 'searchpilot-pro' ), formatMetric( metric, prev[ metric ] ) );
		}
		return ui.kpi( opts );
	}

	/** Indexing / sitemap KPI row from other modules' sections. */
	function healthKpis( data ) {
		const idx = data.indexing;
		const maps = data.sitemaps;
		const sitemapTone = { healthy: 'passed', warning: 'high', error: 'critical' };
		const sitemapLabel = {
			healthy: __( 'Healthy', 'searchpilot-pro' ),
			warning: __( 'Warnings', 'searchpilot-pro' ),
			error: __( 'Errors', 'searchpilot-pro' ),
			disabled: __( 'Disabled', 'searchpilot-pro' ),
			unknown: __( 'Not checked', 'searchpilot-pro' ),
		};
		const errors = idx ? ( idx.errors_4xx || 0 ) + ( idx.errors_5xx || 0 ) : null;
		const crawl = data.bing_crawl && data.bing_crawl.totals;
		const manage = SPP.boot.caps && SPP.boot.caps.manage;
		const crawlHint = [];
		if ( idx ) {
			crawlHint.push( sprintf( /* translators: 1: 4xx count, 2: 5xx count. */ __( '%1$s client (4xx) · %2$s server (5xx)', 'searchpilot-pro' ), fmt.number( idx.errors_4xx || 0 ), fmt.number( idx.errors_5xx || 0 ) ) );
		}
		if ( crawl && crawl.crawl_errors !== null && crawl.crawl_errors !== undefined ) {
			crawlHint.push( sprintf( /* translators: %s: Bing crawl errors. */ __( 'Bing: %s crawl errors (30 days)', 'searchpilot-pro' ), fmt.number( crawl.crawl_errors ) ) );
		}
		const mapErrors = maps && Array.isArray( maps.errors ) ? maps.errors.filter( Boolean ) : [];
		let mapHint = __( 'Sitemap module not available', 'searchpilot-pro' );
		if ( maps ) {
			if ( ( maps.status === 'error' || maps.status === 'warning' ) && mapErrors.length ) {
				mapHint = h( 'span', null, String( mapErrors[ 0 ] ), manage ? [ ' · ', h( 'a', { href: SPP.pageUrl( 'sitemaps' ) }, __( 'Open Sitemaps', 'searchpilot-pro' ) ) ] : null );
			} else if ( maps.status === 'error' || maps.status === 'warning' ) {
				mapHint = manage ? h( 'a', { href: SPP.pageUrl( 'sitemaps' ) }, __( 'See the Sitemaps screen for details', 'searchpilot-pro' ) ) : __( 'See the Sitemaps screen for details', 'searchpilot-pro' );
			} else if ( maps.url_count ) {
				mapHint = sprintf( /* translators: %s: URLs. */ __( '%s URLs listed', 'searchpilot-pro' ), fmt.number( maps.url_count ) );
			} else {
				mapHint = maps.status === 'unknown' ? __( 'Checked in the background shortly', 'searchpilot-pro' ) : maps.owner_name || '';
			}
		}
		return ui.grid(
			4,
			ui.kpi( {
				label: __( 'Indexed URLs', 'searchpilot-pro' ),
				icon: 'check',
				value: idx ? fmt.compact( idx.indexed ) : '—',
				hint: idx ? sprintf( /* translators: %s: total URLs. */ __( 'of %s known URLs', 'searchpilot-pro' ), fmt.number( idx.total ) ) : __( 'Indexing monitor not available', 'searchpilot-pro' ),
				tone: idx && idx.total && idx.indexed === idx.total ? 'passed' : null,
			} ),
			ui.kpi( {
				label: __( 'Non-Indexed URLs', 'searchpilot-pro' ),
				icon: 'alert',
				value: idx ? fmt.compact( idx.not_indexed ) : '—',
				hint: idx ? sprintf( /* translators: %s: URLs not checked yet. */ __( '%s not checked yet', 'searchpilot-pro' ), fmt.number( idx.unknown || 0 ) ) : __( 'Indexing monitor not available', 'searchpilot-pro' ),
				tone: idx && idx.not_indexed ? 'high' : null,
			} ),
			ui.kpi( {
				label: __( 'Crawl Errors', 'searchpilot-pro' ),
				icon: 'shield',
				value: idx ? fmt.number( errors ) : '—',
				hint: crawlHint.length ? crawlHint.join( ' · ' ) : __( 'Indexing monitor not available', 'searchpilot-pro' ),
				tone: errors ? 'critical' : idx ? 'passed' : null,
			} ),
			ui.kpi( {
				label: __( 'Sitemap Health', 'searchpilot-pro' ),
				icon: 'map',
				value: maps ? sitemapLabel[ maps.status ] || maps.status : '—',
				hint: mapHint,
				tone: maps ? sitemapTone[ maps.status ] || null : null,
			} )
		);
	}

	/** Main trend chart with comparison and optional position axis. */
	function trendChart( series, engine, bucket ) {
		const wrap = h( 'div' );
		let showPosition = false;
		const hasPosition = engine === 'google' && ( series.position || [] ).some( ( v ) => v );
		const render = () => {
			const s = [
				{ name: __( 'Clicks', 'searchpilot-pro' ), values: series.clicks, color: PALETTE[ 0 ] },
				{ name: __( 'Impressions', 'searchpilot-pro' ), values: series.impressions, color: PALETTE[ 1 ], axis: 'right', format: ( v ) => fmt.compact( v ) },
			];
			if ( series.compare && ! series.compare.empty ) {
				s.push( {
					name: series.compare.complete === false ? __( 'Clicks (previous period, partly stored)', 'searchpilot-pro' ) : __( 'Clicks (previous period)', 'searchpilot-pro' ),
					values: series.compare.clicks,
					color: PALETTE[ 0 ],
					dashed: true,
				} );
			}
			if ( showPosition ) {
				s.splice( 1, 1 );
				s.push( { name: __( 'Average position', 'searchpilot-pro' ), values: series.position, color: PALETTE[ 2 ], axis: 'right', invert: true, format: ( v ) => fmt.position( v ) } );
			}
			SPP.mount(
				wrap,
				ui.lineChart( {
					labels: series.labels,
					series: s,
					height: 260,
					title: __( 'Clicks and impressions trend', 'searchpilot-pro' ),
					formatLabel: ( l ) => ( bucket === 'week' ? sprintf( /* translators: %s: date. */ __( 'wk %s', 'searchpilot-pro' ), fmt.date( l, { month: 'short', day: 'numeric' } ) ) : fmt.date( l, { month: 'short', day: 'numeric' } ) ),
					emptyText: __( 'Connect Google Search Console (or Bing) and run the first sync to see the trend.', 'searchpilot-pro' ),
				} )
			);
		};
		render();
		const toggle = hasPosition
			? ui.field.toggle( {
					label: __( 'Show average position (inverted axis)', 'searchpilot-pro' ),
					checked: false,
					onChange: ( v ) => {
						showPosition = v;
						render();
					},
			  } )
			: null;
		return { el: wrap, toggle };
	}

	function deltaChip( row, compare ) {
		if ( ! compare || row.prev_clicks === null || row.prev_clicks === undefined ) {
			return null;
		}
		return ui.trend( fmt.delta( row.clicks, row.prev_clicks ), { title: sprintf( /* translators: %s: previous clicks. */ __( 'Previous period: %s clicks', 'searchpilot-pro' ), fmt.number( row.prev_clicks ) ) } );
	}

	function pageLink( row, view ) {
		return SPP.pageUrl( 'performance', { tab: 'pages', page_url: row.page, range: view.range, engine: view.engine } );
	}

	function pageLabel( row ) {
		return row.title || pageName( row.page );
	}

	function topQueries( rows, view ) {
		return ui.barList( {
			items: ( rows || [] ).map( ( r ) => ( {
				label: r.query,
				title: r.query,
				value: r.clicks,
				sub: r.position ? sprintf( /* translators: %s: position. */ __( 'pos %s', 'searchpilot-pro' ), fmt.position( r.position ) ) : null,
				extra: deltaChip( r, view.compare ),
			} ) ),
			empty: __( 'No queries yet — they appear after the first Search Console sync.', 'searchpilot-pro' ),
		} );
	}

	function topPages( rows, view ) {
		return ui.barList( {
			items: ( rows || [] ).map( ( r ) => ( {
				label: pageLabel( r ),
				title: fmt.url( r.page ),
				href: pageLink( r, view ),
				value: r.clicks,
				sub: sprintf( /* translators: %s: impressions. */ __( '%s impr.', 'searchpilot-pro' ), fmt.compact( r.impressions ) ),
				extra: deltaChip( r, view.compare ),
			} ) ),
			empty: __( 'No pages yet — they appear after the first sync.', 'searchpilot-pro' ),
		} );
	}

	function changeList( rows, rising, view ) {
		if ( ! view.compare ) {
			return ui.empty( {
				icon: 'chart',
				title: __( 'No comparison for this period', 'searchpilot-pro' ),
				text: __( 'The previous period is not fully covered by stored search data (Search Console keeps about 16 months; a newly connected site has less), so gains and losses are not calculated. Choose a shorter period.', 'searchpilot-pro' ),
			} );
		}
		if ( ! rows || ! rows.length ) {
			return ui.empty( {
				icon: 'chart',
				title: rising ? __( 'No rising pages', 'searchpilot-pro' ) : __( 'No declining pages', 'searchpilot-pro' ),
				text: __( 'Pages need enough impressions or clicks in both periods to show here.', 'searchpilot-pro' ),
			} );
		}
		return ui.barList( {
			items: rows.map( ( r ) => ( {
				label: pageLabel( r ),
				title: fmt.url( r.page ),
				href: pageLink( r, view ),
				value: Math.abs( r.delta_clicks ),
				sub: sprintf( /* translators: 1: previous clicks, 2: clicks. */ __( '%1$s → %2$s clicks', 'searchpilot-pro' ), fmt.number( r.prev_clicks ), fmt.number( r.clicks ) ),
				extra: deltaChip( r, true ),
			} ) ),
			format: ( v ) => ( rising ? '+' : '−' ) + fmt.number( v ),
		} );
	}

	function opportunitiesCard( opps ) {
		const labels = opps.labels || {};
		const counts = opps.counts_by_action || {};
		const chips = Object.keys( labels )
			.filter( ( code ) => counts[ code ] )
			.map( ( code ) => h( 'a', { href: SPP.pageUrl( 'opportunities', { action: code } ), class: 'spp-badge is-opportunity', title: __( 'Show these opportunities', 'searchpilot-pro' ) }, labels[ code ], ' · ', fmt.number( counts[ code ] ) ) );
		const top = ( opps.top || [] ).map( ( o ) =>
			h(
				'li',
				null,
				h( 'div', { class: 'spp-row is-between' }, h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-strong spp-truncate', title: o.title }, o.title || fmt.url( o.url ) ), h( 'div', { class: 'spp-muted spp-small' }, o.label ) ), ui.severity( o.severity ) ),
				h( 'div', { class: 'spp-row', style: { marginTop: '6px', flexWrap: 'wrap' } }, ( o.actions || [] ).map( ( a ) => ui.badge( a.label, 'outline' ) ) )
			)
		);
		return ui.card( {
			title: __( 'Content opportunities', 'searchpilot-pro' ),
			subtitle: sprintf( /* translators: %s: number of opportunities. */ __( '%s open, found in your Search Console data', 'searchpilot-pro' ), fmt.number( opps.total || 0 ) ),
			actions: [ ui.button( { label: __( 'View all', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'opportunities' ) } ) ],
			body: opps.total
				? [ h( 'div', { class: 'spp-row', style: { flexWrap: 'wrap', marginBottom: '12px' } }, chips ), h( 'ul', { class: 'spp-list' }, top ) ]
				: ui.empty( { icon: 'sparkle', title: __( 'No opportunities yet', 'searchpilot-pro' ), text: __( 'After a Search Console sync, SearchPilot looks for pages close to the top, low click-through rates, declines and competing pages.', 'searchpilot-pro' ) } ),
			footer: h( 'span', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'SearchPilot never rewrites your content automatically.', 'searchpilot-pro' ) ),
		} );
	}

	function healthCard( data ) {
		const monitor = data.monitor;
		const issues = data.issues || {};
		let score = null;
		if ( monitor ) {
			if ( monitor.score !== undefined && monitor.score !== null ) {
				score = monitor.score;
			} else {
				const weighted = ( monitor.passed || 0 ) + 3 * ( monitor.critical || 0 ) + 1.5 * ( monitor.high || 0 ) + ( monitor.medium || 0 );
				score = weighted ? Math.round( ( 100 * ( monitor.passed || 0 ) ) / weighted ) : null;
			}
		}
		const content = data.content;
		const links = data.links;
		const nf = data.not_found;
		return ui.card( {
			title: __( 'SEO health', 'searchpilot-pro' ),
			subtitle: monitor && monitor.checked_at ? sprintf( /* translators: %s: relative time. */ __( 'Checked %s · an internal diagnostic, not a search-engine ranking', 'searchpilot-pro' ), fmt.relative( monitor.checked_at ) ) : __( 'Internal diagnostic — not a search-engine ranking score', 'searchpilot-pro' ),
			body: h(
				'div',
				{ class: 'spp-hero' },
				ui.scoreRing( score, { size: 96, caption: __( 'Internal diagnostic, not a ranking', 'searchpilot-pro' ) } ),
				h(
					'div',
					{ class: 'spp-hero-text' },
					ui.dl( [
						[ __( 'Open issues', 'searchpilot-pro' ), h( 'span', { class: 'spp-row' }, ui.badge( fmt.number( issues.critical || 0 ), 'critical', { title: __( 'Critical', 'searchpilot-pro' ) } ), ui.badge( fmt.number( issues.high || 0 ), 'high', { title: __( 'High', 'searchpilot-pro' ) } ), ui.badge( fmt.number( issues.medium || 0 ), 'medium', { title: __( 'Medium', 'searchpilot-pro' ) } ) ) ],
						content ? [ __( 'Content score (avg.)', 'searchpilot-pro' ), content.avg_score === null ? __( 'not analysed yet', 'searchpilot-pro' ) : sprintf( /* translators: 1: average score, 2: posts below 50. */ __( '%1$s · %2$s below 50', 'searchpilot-pro' ), fmt.number( content.avg_score ), fmt.number( content.below_50 ) ) ] : null,
						links ? [ __( 'Orphan pages', 'searchpilot-pro' ), sprintf( /* translators: 1: orphans, 2: pending suggestions. */ __( '%1$s · %2$s link suggestions', 'searchpilot-pro' ), fmt.number( links.orphans ), fmt.number( links.suggestions_pending ) ) ] : null,
						nf ? [ __( '404s (7 days)', 'searchpilot-pro' ), fmt.number( nf.last_7d ) ] : null,
					] )
				)
			),
			actions: [ ui.button( { label: __( 'SEO Audit', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'audit' ) } ) ],
		} );
	}

	function issuesCard( items, reload ) {
		return ui.card( {
			title: __( 'Critical and high issues', 'searchpilot-pro' ),
			actions: [ ui.button( { label: __( 'All issues', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'audit' ) } ) ],
			body: items && items.length
				? h( 'div', null, items.map( ( issue ) => ui.issue( issue, { compact: true, onChanged: reload } ) ) )
				: h( 'div', null, ui.passed( __( 'No critical or high issues', 'searchpilot-pro' ), __( 'SearchPilot keeps monitoring indexing, sitemaps, redirects and structured data in the background.', 'searchpilot-pro' ) ) ),
		} );
	}

	function activityCard( items, reload ) {
		const sourceTone = { user: 'navy', assisted: 'gold', autopilot: 'passed', system: 'info', manual: 'outline', import: 'outline' };
		const who = ( a ) => ( a.source === 'user' && a.user ? a.user : SOURCE_LABELS[ a.source ] || __( 'SearchPilot', 'searchpilot-pro' ) );
		const list = ( items || [] ).map( ( a ) =>
			h(
				'li',
				null,
				h(
					'div',
					{ class: 'spp-row is-between is-top' },
					h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-break' }, a.description ), h( 'div', { class: 'spp-muted spp-small' }, ui.badge( who( a ), sourceTone[ a.source ] || 'outline' ), ' ', h( 'span', { title: fmt.datetime( a.created_at ) }, fmt.relative( a.created_at ) ), a.status === 'reverted' ? ' · ' + __( 'undone', 'searchpilot-pro' ) : '' ) ),
					a.reversible
						? ui.button( {
								label: __( 'Undo', 'searchpilot-pro' ),
								icon: 'undo',
								size: 'small',
								variant: 'ghost',
								onClick: () =>
									api.post( '/activity/' + a.id + '/undo' ).then( () => {
										ui.toast( __( 'Change undone', 'searchpilot-pro' ) );
										reload();
									} ),
						  } )
						: null
				)
			)
		);
		return ui.card( {
			title: __( 'Automation activity', 'searchpilot-pro' ),
			subtitle: __( 'Every change SearchPilot makes is logged and can be undone', 'searchpilot-pro' ),
			actions: [ ui.button( { label: __( 'All activity', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'automation' ) } ) ],
			body: list.length ? h( 'ul', { class: 'spp-list' }, list ) : ui.empty( { icon: 'bolt', title: __( 'No changes yet', 'searchpilot-pro' ), text: __( 'Automatic and manual SEO changes will appear here with an Undo button.', 'searchpilot-pro' ) } ),
		} );
	}

	function enginesCard( data ) {
		const engines = data.engines;
		const queue = data.queue;
		const item = ( tone, title, text ) => h( 'div', { class: 'spp-status-item' }, ui.dot( tone ), h( 'div', null, h( 'div', { class: 'spp-status-title' }, title ), h( 'div', { class: 'spp-status-text' }, text ) ) );
		const connected = data.connected || {};
		const rows = [];
		if ( engines ) {
			const g = engines.google || {};
			const b = engines.bing || {};
			const n = engines.indexnow || {};
			rows.push( item( g.connected ? 'passed' : g.error ? 'critical' : 'medium', __( 'Google Search Console', 'searchpilot-pro' ), g.connected ? g.property || __( 'Connected', 'searchpilot-pro' ) : g.error || __( 'Not connected', 'searchpilot-pro' ) ) );
			rows.push( item( b.connected ? 'passed' : b.error ? 'critical' : 'medium', __( 'Bing Webmaster Tools', 'searchpilot-pro' ), b.connected ? b.site || __( 'Connected', 'searchpilot-pro' ) : b.error || __( 'Not connected', 'searchpilot-pro' ) ) );
			rows.push( item( n.enabled ? ( n.verified === false ? 'high' : 'passed' ) : 'medium', __( 'IndexNow', 'searchpilot-pro' ), n.enabled ? ( n.verified === false ? __( 'Key file not reachable', 'searchpilot-pro' ) : __( 'Enabled', 'searchpilot-pro' ) ) : __( 'Disabled', 'searchpilot-pro' ) ) );
		} else {
			rows.push( item( connected.google ? 'passed' : 'medium', __( 'Google Search Console', 'searchpilot-pro' ), connected.google ? __( 'Connected', 'searchpilot-pro' ) : __( 'Not connected', 'searchpilot-pro' ) ) );
			rows.push( item( connected.bing ? 'passed' : 'medium', __( 'Bing Webmaster Tools', 'searchpilot-pro' ), connected.bing ? __( 'Connected', 'searchpilot-pro' ) : __( 'Not connected', 'searchpilot-pro' ) ) );
		}
		if ( queue ) {
			const tone = queue.failed_24h ? 'critical' : queue.held ? 'gold' : 'passed';
			rows.push(
				item(
					tone,
					__( 'Submission queue', 'searchpilot-pro' ),
					sprintf( /* translators: 1: pending, 2: held, 3: sent in 24h, 4: failed in 24h. */ __( '%1$s pending · %2$s held · %3$s sent / %4$s failed (24 h)', 'searchpilot-pro' ), fmt.number( queue.pending ), fmt.number( queue.held ), fmt.number( queue.done_24h ), fmt.number( queue.failed_24h ) )
				)
			);
		}
		return ui.card( {
			title: __( 'Search engines and queue', 'searchpilot-pro' ),
			actions: SPP.boot.caps && SPP.boot.caps.manage ? [ ui.button( { label: __( 'Manage', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'engines' ) } ) ] : null,
			body: h( 'div', { class: 'spp-status-grid' }, rows ),
		} );
	}

	function connectionNotice( data, engine ) {
		const connected = data.connected || {};
		if ( connected[ engine ] ) {
			return null;
		}
		const hasData = data.performance && data.performance.totals && data.performance.totals.has_data;
		const manage = SPP.boot.caps && SPP.boot.caps.manage;
		const name = engine === 'bing' ? __( 'Bing Webmaster Tools', 'searchpilot-pro' ) : __( 'Google Search Console', 'searchpilot-pro' );
		return h(
			'div',
			null,
			ui.notice( {
				tone: hasData ? 'info' : 'gold',
				icon: 'globe',
				title: hasData ? sprintf( /* translators: %s: service. */ __( '%s is not connected — showing the data from the last sync', 'searchpilot-pro' ), name ) : sprintf( /* translators: %s: service. */ __( 'Connect %s to see search performance', 'searchpilot-pro' ), name ),
				text: __( 'Clicks, impressions, queries and content opportunities come from the official search-engine APIs. Everything else on this dashboard — indexing, sitemaps, redirects, 404s, structured data, internal links and the activity log — keeps working without a connection.', 'searchpilot-pro' ),
				actions: manage ? [ ui.button( { label: __( 'Connect search engines', 'searchpilot-pro' ), variant: 'primary', size: 'small', href: SPP.pageUrl( 'engines' ) } ), SPP.boot.wizardCompleted ? null : ui.button( { label: __( 'Open setup wizard', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'setup' ) } ) ] : null,
			} )
		);
	}

	function syncLine( data, engine, onSynced ) {
		const fresh = ( data.freshness || {} )[ engine ] || {};
		const last = ( data.last_sync || {} )[ engine ];
		const parts = [];
		parts.push( last ? sprintf( /* translators: %s: relative time. */ __( 'Last synced %s', 'searchpilot-pro' ), fmt.relative( last ) ) : __( 'Not synced yet', 'searchpilot-pro' ) );
		if ( fresh.latest ) {
			parts.push( sprintf( /* translators: %s: date. */ __( 'data through %s', 'searchpilot-pro' ), fmt.date( fresh.latest ) ) );
		}
		const running = data.last_sync && data.last_sync.status === 'running';
		const manage = SPP.boot.caps && SPP.boot.caps.manage;
		return h(
			'div',
			{ class: 'spp-row is-between' },
			h( 'span', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), parts.join( ' · ' ) + ( fresh.note ? ' — ' + fresh.note : '' ) ),
			running
				? ui.badge( sprintf( /* translators: %d: percent. */ __( 'Syncing… %d%%', 'searchpilot-pro' ), ( data.last_sync.progress || {} ).percent || 0 ), 'gold', { dot: true } )
				: manage && ( data.connected || {} )[ engine ]
				? ui.button( {
						label: __( 'Sync now', 'searchpilot-pro' ),
						icon: 'refresh',
						size: 'small',
						variant: 'ghost',
						onClick: () =>
							api.post( '/performance/sync' ).then( ( st ) => {
								ui.toast( st.status === 'running' ? __( 'Sync started — it continues in the background.', 'searchpilot-pro' ) : __( 'Search data is up to date.', 'searchpilot-pro' ) );
								onSynced();
							} ),
				  } )
				: null
		);
	}

	SPP.registerView( 'dashboard', {
		mount( el ) {
			const state = {
				range: RANGES.indexOf( SPP.query.get( 'range' ) ) !== -1 ? SPP.query.get( 'range' ) : '28d',
				engine: SPP.query.get( 'engine' ) === 'bing' ? 'bing' : 'google',
			};

			const content = SPP.region( () => api.get( '/dashboard', { range: state.range, engine: state.engine } ).then( render ), { loader: false } );
			const reload = () => content.refresh();

			function render( data ) {
				const perf = data.performance || {};
				const totals = perf.totals || {};
				const series = perf.series || { labels: [] };
				const chart = trendChart( series, state.engine, series.bucket );
				const listsNote = data.lists_range && data.lists_range !== state.range ? sprintf( /* translators: %s: range key. */ __( 'Lists use the nearest stored period (%s).', 'searchpilot-pro' ), data.lists_range ) : '';
				const view = { range: state.range, engine: state.engine, compare: data.lists_complete !== false };
				const listSub = view.compare ? __( 'By clicks, with change vs the previous period', 'searchpilot-pro' ) : __( 'By clicks (no comparison: the previous period is not fully stored)', 'searchpilot-pro' );
				const period = totals.from
					? totals.comparable === false
						? sprintf( /* translators: 1: first day, 2: last day. */ __( '%1$s – %2$s (the previous period is only partly stored)', 'searchpilot-pro' ), fmt.date( totals.from ), fmt.date( totals.to ) )
						: sprintf( /* translators: 1: first day, 2: last day. */ __( '%1$s – %2$s vs the previous period', 'searchpilot-pro' ), fmt.date( totals.from ), fmt.date( totals.to ) )
					: '';
				return ui.stack(
					connectionNotice( data, state.engine ),
					syncLine( data, state.engine, reload ),
					ui.grid(
						4,
						perfKpi( __( 'Organic Clicks', 'searchpilot-pro' ), 'clicks', totals, series, 'hand' ),
						perfKpi( __( 'Impressions', 'searchpilot-pro' ), 'impressions', totals, series, 'eye' ),
						perfKpi( __( 'CTR', 'searchpilot-pro' ), 'ctr', totals, series, 'bolt' ),
						state.engine === 'bing'
							? ui.kpi( { label: __( 'Average Position', 'searchpilot-pro' ), icon: 'chart', value: '—', hint: __( 'Bing does not report a site-wide average position', 'searchpilot-pro' ) } )
							: perfKpi( __( 'Average Position', 'searchpilot-pro' ), 'position', totals, series, 'chart' )
					),
					healthKpis( data ),
					ui.card( {
						title: __( 'Search performance', 'searchpilot-pro' ),
						subtitle: period,
						actions: [ chart.toggle, ui.button( { label: __( 'Details', 'searchpilot-pro' ), size: 'small', href: SPP.pageUrl( 'performance', { range: state.range, engine: state.engine } ) } ) ],
						body: chart.el,
					} ),
					ui.grid(
						2,
						ui.card( { title: __( 'Top queries', 'searchpilot-pro' ), subtitle: listsNote || listSub, body: topQueries( data.top_queries, view ), actions: [ ui.button( { label: __( 'All queries', 'searchpilot-pro' ), size: 'small', variant: 'ghost', href: SPP.pageUrl( 'performance', { tab: 'queries', range: state.range, engine: state.engine } ) } ) ] } ),
						ui.card( { title: __( 'Top pages', 'searchpilot-pro' ), subtitle: listsNote || listSub, body: topPages( data.top_pages, view ), actions: [ ui.button( { label: __( 'All pages', 'searchpilot-pro' ), size: 'small', variant: 'ghost', href: SPP.pageUrl( 'performance', { tab: 'pages', range: state.range, engine: state.engine } ) } ) ] } )
					),
					ui.grid(
						2,
						ui.card( { title: __( 'Rising content', 'searchpilot-pro' ), subtitle: __( 'Largest gains in clicks', 'searchpilot-pro' ), body: changeList( data.rising, true, view ) } ),
						ui.card( { title: __( 'Declining content', 'searchpilot-pro' ), subtitle: __( 'Largest losses in clicks', 'searchpilot-pro' ), body: changeList( data.declining, false, view ) } )
					),
					h( 'div', { class: 'spp-grid' }, h( 'div', { class: 'span-7' }, opportunitiesCard( data.opportunities || {} ) ), h( 'div', { class: 'span-5' }, healthCard( data ) ) ),
					issuesCard( data.critical_issues, reload ),
					ui.grid( 2, activityCard( data.activity, reload ), enginesCard( data ) ),
					h( 'p', { class: 'spp-disclaimer' }, SPP.icon( 'info', 14 ), __( 'Search data comes from Google Search Console and Bing Webmaster Tools. Scores are internal diagnostics; no tool can guarantee rankings.', 'searchpilot-pro' ) )
				);
			}

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'Dashboard', 'searchpilot-pro' ),
					subtitle: __( 'Search performance, indexing and SEO health at a glance.', 'searchpilot-pro' ),
					actions: [
						ui.buttonGroup( {
							options: [
								{ value: 'google', label: __( 'Google', 'searchpilot-pro' ) },
								{ value: 'bing', label: __( 'Bing', 'searchpilot-pro' ) },
							],
							value: state.engine,
							onChange: ( v ) => {
								state.engine = v;
								SPP.query.set( { engine: v === 'google' ? '' : v } );
								reload();
							},
						} ),
						ui.rangePicker( {
							value: state.range,
							onChange: ( v ) => {
								state.range = v;
								SPP.query.set( { range: v } );
								reload();
							},
						} ),
						ui.button( { icon: 'refresh', title: __( 'Refresh', 'searchpilot-pro' ), onClick: () => content.refresh() } ),
					],
				} ),
				content.el
			);
		},
	} );
} )( window.wp, window.SPP );

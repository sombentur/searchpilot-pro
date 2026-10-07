/**
 * SearchPilot Pro — classic editor meta box: live analysis, SERP preview,
 * character counters and the social image picker.
 *
 * @package SearchPilot
 */
( function ( wp, $, cfg ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! cfg ) {
		return;
	}

	const { __, sprintf } = wp.i18n;
	const NS = '/' + ( cfg.namespace || 'searchpilot/v1' );
	const root = document.getElementById( 'spp-mb' );
	if ( ! root ) {
		return;
	}

	const byId = ( id ) => document.getElementById( id );
	const api = ( path, opts ) => wp.apiFetch( Object.assign( { path: NS + path }, opts || {} ) );
	const isForbidden = ( err ) => !! err && ( err.code === 'rest_forbidden' || ( err.data && ( err.data.status === 401 || err.data.status === 403 ) ) );
	const isUnavailable = ( err ) => !! err && ( err.code === 'rest_no_route' || ( err.data && err.data.status === 404 ) || isForbidden( err ) );

	/** Element builder (text is always escaped). */
	function h( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => {
			const value = attrs[ key ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'class' ) {
				node.className = value;
			} else {
				node.setAttribute( key, value === true ? '' : String( value ) );
			}
		} );
		children.flat().forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( child instanceof window.Node ? child : document.createTextNode( String( child ) ) );
		} );
		return node;
	}

	const mount = ( target, ...nodes ) => {
		if ( target ) {
			target.replaceChildren( ...nodes.flat().filter( Boolean ) );
		}
	};

	const toText = ( html ) => {
		if ( ! html ) {
			return '';
		}
		try {
			const doc = new window.DOMParser().parseFromString( String( html ).replace( /<!--[\s\S]*?-->/g, ' ' ), 'text/html' );
			return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		} catch ( e ) {
			return String( html ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		}
	};

	/* ------------------------------------------------------------------ *
	 * Lengths in graphemes and estimated search-result width — a port of
	 * SearchPilot\Support\Text (length, truncate, serp_fit, serp_truncate);
	 * keep the tables identical to Text.php so the editor agrees with the
	 * analysis and the fixers.
	 * ------------------------------------------------------------------ */

	const SERP = { title: { font: 20, limit: 580 }, description: { font: 14, limit: 990 } };
	const ASCII_W = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584'
		.split( ',' )
		.map( Number );
	const INDIC_W = [
		[ 'devanagari', 0x0900, 0x097f, 600, 220, 420, 0x094d ],
		[ 'bengali', 0x0980, 0x09ff, 620, 250, 350, 0x09cd ],
		[ 'gurmukhi', 0x0a00, 0x0a7f, 600, 220, 200, 0x0a4d ],
		[ 'gujarati', 0x0a80, 0x0aff, 580, 220, 420, 0x0acd ],
		[ 'oriya', 0x0b00, 0x0b7f, 660, 250, 300, 0x0b4d ],
		[ 'tamil', 0x0b80, 0x0bff, 720, 400, 720, 0x0bcd ],
		[ 'telugu', 0x0c00, 0x0c7f, 680, 280, 250, 0x0c4d ],
		[ 'kannada', 0x0c80, 0x0cff, 680, 300, 250, 0x0ccd ],
		[ 'malayalam', 0x0d00, 0x0d7f, 720, 350, 400, 0x0d4d ],
		[ 'sinhala', 0x0d80, 0x0dff, 700, 300, 300, 0x0dca ],
	];
	const RANGE_W = [
		[ 0x0590, 0x05ff, 550 ], [ 0x0600, 0x06ff, 500 ], [ 0x0750, 0x077f, 500 ], [ 0x08a0, 0x08ff, 500 ],
		[ 0x0e00, 0x0eff, 550 ], [ 0x1000, 0x109f, 700 ], [ 0x1100, 0x11ff, 1000 ], [ 0x1200, 0x137f, 750 ],
		[ 0x1780, 0x17ff, 700 ], [ 0x2600, 0x27bf, 1000 ], [ 0x2e80, 0x9fff, 1000 ], [ 0xac00, 0xd7af, 1000 ],
		[ 0xf900, 0xfaff, 1000 ], [ 0xfb50, 0xfdff, 500 ], [ 0xfe70, 0xfeff, 500 ], [ 0xff01, 0xff60, 1000 ],
		[ 0xff61, 0xff9f, 500 ], [ 0xffa0, 0xffef, 1000 ], [ 0x1f1e6, 0x1f1ff, 625 ], [ 0x1f3fb, 0x1f3ff, 0 ],
		[ 0x1f000, 0x1faff, 1250 ], [ 0x20000, 0x3ffff, 1000 ],
	];
	const PUNCT_W = {
		0x00a0: 278, 0x00a3: 556, 0x00a9: 737, 0x00ab: 556, 0x00ae: 737, 0x00b0: 400, 0x00b7: 278, 0x00bb: 556, 0x00d7: 584,
		0x2010: 333, 0x2011: 333, 0x2012: 556, 0x2013: 556, 0x2014: 1000, 0x2015: 1000, 0x2018: 222, 0x2019: 222, 0x201a: 222,
		0x201c: 333, 0x201d: 333, 0x201e: 333, 0x2022: 350, 0x2026: 1000, 0x2032: 191, 0x2039: 333, 0x203a: 333, 0x20ac: 556,
		0x20b9: 556, 0x3000: 1000,
	};
	const widthClass = new Map();

	/** [ kind (fixed|letter|virama|emoji), width, script, width after a virama ] in 1/1000 em. */
	const classify = ( ch ) => {
		const cp = ch.codePointAt( 0 );
		if ( widthClass.has( cp ) ) {
			return widthClass.get( cp );
		}
		let c = null;
		if ( cp >= 0x20 && cp <= 0x7e ) {
			c = [ 'fixed', ASCII_W[ cp - 0x20 ], '', 0 ];
		} else if ( cp < 0x20 || ( cp >= 0x7f && cp < 0xa0 ) ) {
			c = [ 'fixed', cp === 0x09 || cp === 0x0a || cp === 0x0d ? 278 : 0, '', 0 ];
		} else if ( PUNCT_W[ cp ] !== undefined ) {
			c = [ 'fixed', PUNCT_W[ cp ], '', 0 ];
		}
		if ( ! c ) {
			const d = INDIC_W.find( ( r ) => cp >= r[ 1 ] && cp <= r[ 2 ] );
			if ( d ) {
				if ( cp === d[ 6 ] ) {
					c = [ 'virama', 0, d[ 0 ], 0 ];
				} else if ( /^[\p{Mn}\p{Me}]$/u.test( ch ) ) {
					c = [ 'fixed', 0, d[ 0 ], 0 ];
				} else if ( /^\p{Mc}$/u.test( ch ) ) {
					c = [ 'fixed', d[ 4 ], d[ 0 ], 0 ];
				} else if ( /^\p{Nd}$/u.test( ch ) ) {
					c = [ 'fixed', 556, d[ 0 ], 0 ];
				} else if ( /^\p{P}$/u.test( ch ) ) {
					c = [ 'fixed', 300, d[ 0 ], 0 ];
				} else {
					c = [ 'letter', d[ 3 ], d[ 0 ], d[ 5 ] ];
				}
			}
		}
		if ( ! c ) {
			if ( /^[\p{Mn}\p{Me}\p{Cf}]$/u.test( ch ) || ( cp >= 0xfe00 && cp <= 0xfe0f ) ) {
				c = [ 'fixed', 0, '', 0 ];
			} else if ( /^[\s  -   ]$/u.test( ch ) ) {
				c = [ 'fixed', 278, '', 0 ];
			}
		}
		if ( ! c ) {
			const r = RANGE_W.find( ( x ) => cp >= x[ 0 ] && cp <= x[ 1 ] );
			if ( r ) {
				c = [ cp >= 0x1f000 ? 'emoji' : 'fixed', r[ 2 ], '', 0 ];
			}
		}
		if ( ! c ) {
			let w = 600;
			if ( /^\p{Mc}$/u.test( ch ) ) {
				w = 300;
			} else if ( /^\p{Lu}$/u.test( ch ) ) {
				w = 667;
			} else if ( /^[\p{Ll}\p{Nd}]$/u.test( ch ) ) {
				w = 556;
			} else if ( /^\p{P}$/u.test( ch ) ) {
				w = 333;
			}
			c = [ 'fixed', w, '', 0 ];
		}
		if ( widthClass.size < 4096 ) {
			widthClass.set( cp, c );
		}
		return c;
	};

	const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter( undefined, { granularity: 'grapheme' } ) : null;

	/** Grapheme clusters (Intl.Segmenter; base + marks as a fallback). */
	const graphemes = ( text ) => {
		const s = String( text || '' );
		if ( ! s ) {
			return [];
		}
		if ( segmenter ) {
			return Array.from( segmenter.segment( s ), ( part ) => part.segment );
		}
		return s.match( /\P{M}\p{M}*|\p{M}+/gu ) || [];
	};

	const length = ( s ) => graphemes( s ).length;

	/** [ cluster, width in em ] per grapheme cluster. */
	const clusterWidths = ( text ) => {
		let afterVirama = '';
		let afterZwj = false;
		return graphemes( text ).map( ( cluster ) => {
			let w = 0;
			for ( const ch of cluster ) {
				const c = classify( ch );
				if ( c[ 0 ] === 'letter' ) {
					w += c[ 2 ] === afterVirama ? c[ 3 ] : c[ 1 ];
				} else if ( c[ 0 ] === 'emoji' ) {
					w += afterZwj ? 0 : c[ 1 ];
				} else {
					w += c[ 1 ];
				}
				afterVirama = c[ 0 ] === 'virama' ? c[ 2 ] : '';
				afterZwj = ch.codePointAt( 0 ) === 0x200d;
			}
			return [ cluster, w / 1000 ];
		} );
	};

	/** { fits, graphemes, width, limit, ratio } like Text::serp_fit(). */
	const serpFit = ( text, kind ) => {
		const def = SERP[ kind === 'title' ? 'title' : 'description' ];
		const items = clusterWidths( text );
		const width = Math.round( items.reduce( ( sum, item ) => sum + item[ 1 ], 0 ) * def.font * 10 ) / 10;
		return { fits: width <= def.limit, graphemes: items.length, width, limit: def.limit, ratio: width / def.limit };
	};

	const isSpace = ( cluster ) => /^[\s   -​  　]+$/u.test( cluster );

	/** At most `max` graphemes INCLUDING the ellipsis, word boundary in the last ~25 %, never inside a cluster. */
	const truncate = ( text, max, ellipsis = '…' ) => {
		const s = String( text || '' );
		const clusters = graphemes( s );
		if ( clusters.length <= max ) {
			return s;
		}
		const elen = graphemes( ellipsis ).length;
		if ( elen >= max ) {
			return clusters.slice( 0, max ).join( '' );
		}
		const keep = max - elen;
		let cut = clusters.slice( 0, keep );
		if ( ! isSpace( clusters[ keep ] ) ) {
			const floor = Math.ceil( keep * 0.75 );
			for ( let i = keep - 1; i >= floor && i > 0; i-- ) {
				if ( isSpace( cut[ i ] ) ) {
					cut = cut.slice( 0, i );
					break;
				}
			}
		}
		let out = cut.join( '' ).replace( /[\s ,.;:–—।॥-]+$/u, '' );
		if ( ! out ) {
			out = clusters.slice( 0, keep ).join( '' );
		}
		return out + ellipsis;
	};

	/** The part of a title/description Google shows (Text::serp_truncate()). */
	const serpTruncate = ( text, kind, ellipsis = '…' ) => {
		let s = String( text || '' );
		const def = SERP[ kind === 'title' ? 'title' : 'description' ];
		const budget = def.limit / def.font;
		// No cluster is narrower than ≈0.19 em: only the head of a long text can matter.
		const need = Math.ceil( def.limit / ( def.font * 0.19 ) ) + 2;
		const cps = Array.from( s );
		if ( cps.length > need * 10 ) {
			s = graphemes( cps.slice( 0, need * 10 ).join( '' ) ).slice( 0, -1 ).join( '' );
		}
		const items = clusterWidths( s );
		if ( s === String( text || '' ) && items.reduce( ( sum, item ) => sum + item[ 1 ], 0 ) <= budget ) {
			return s;
		}
		const room = budget - clusterWidths( ellipsis ).reduce( ( sum, item ) => sum + item[ 1 ], 0 );
		let n = 0;
		let sum = 0;
		for ( const item of items ) {
			if ( sum + item[ 1 ] > room ) {
				break;
			}
			sum += item[ 1 ];
			n++;
		}
		return truncate( s, n + graphemes( ellipsis ).length, ellipsis );
	};

	const SOURCE_NAMES = {
		rank_math: 'Rank Math SEO',
		yoast: 'Yoast SEO',
		aioseo: 'All in One SEO',
		seopress: 'SEOPress',
		tsf: 'The SEO Framework',
		searchpilot: 'SearchPilot',
		wordpress: 'WordPress',
	};

	/** Where a served title/description comes from, in words. */
	const sourceLabel = ( source, origin, isDescription ) => {
		if ( ! source || source === 'none' ) {
			return isDescription ? __( 'none — Google picks text from the page', 'searchpilot-pro' ) : __( 'none', 'searchpilot-pro' );
		}
		if ( source === 'rendered' ) {
			return __( 'as served on the published page', 'searchpilot-pro' );
		}
		const name = ( cfg.storeNames && cfg.storeNames[ source ] ) || SOURCE_NAMES[ source ] || source;
		if ( origin === 'custom' ) {
			/* translators: %s: plugin name. */
			return sprintf( __( '%s, written for this post', 'searchpilot-pro' ), name );
		}
		if ( origin === 'template' ) {
			/* translators: %s: plugin name. */
			return sprintf( __( '%s template', 'searchpilot-pro' ), name );
		}
		if ( origin === 'auto' ) {
			/* translators: %s: plugin name. */
			return sprintf( __( '%s, automatic from the text', 'searchpilot-pro' ), name );
		}
		return name;
	};

	/** Head of the latest live analysis: what the page will serve (companion plugin's resolution included). */
	let liveEffective = null;

	const resolveVars = ( template, vars ) => {
		let out = String( template || '' ).replace( /%%([a-z0-9_]+)%%/gi, '%$1%' );
		Object.keys( vars ).forEach( ( key ) => {
			out = out.split( '%' + key + '%' ).join( vars[ key ] || '' );
		} );
		return out.replace( /%[a-z_]+%/g, '' ).replace( /\s{2,}/g, ' ' ).trim();
	};

	const debounce = ( fn, wait ) => {
		let t = 0;
		return ( ...args ) => {
			clearTimeout( t );
			t = setTimeout( () => fn( ...args ), wait );
		};
	};

	/* ---------- Reading the classic editor ---------- */

	const val = ( id ) => {
		const node = byId( id );
		return node ? node.value : '';
	};

	const content = () => {
		const ed = window.tinymce && window.tinymce.get ? window.tinymce.get( 'content' ) : null;
		if ( ed && ! ed.isHidden() ) {
			return ed.getContent();
		}
		return val( 'content' );
	};

	const slug = () => {
		const full = byId( 'editable-post-name-full' );
		return val( 'post_name' ) || ( full ? full.textContent : '' );
	};

	const field = ( name ) => {
		const node = byId( 'spp-mb-' + name );
		return node ? node.value : '';
	};

	/** Our input's value, or null when there is no input (another plugin owns the field): the server then uses its effective value. */
	const sent = ( name ) => {
		const node = byId( 'spp-mb-' + name );
		return node ? node.value : null;
	};

	/* ---------- Counters ---------- */

	const updateCounters = () => {
		root.querySelectorAll( '.spp-mb-counter[data-for]' ).forEach( ( counter ) => {
			const input = byId( counter.getAttribute( 'data-for' ) );
			if ( ! input ) {
				return;
			}
			const kind = input.id === 'spp-mb-title' ? 'title' : 'description';
			let text = input.value;
			if ( input.id === 'spp-mb-title' ) {
				text = currentTitle().text;
			} else if ( input.id === 'spp-mb-description' ) {
				text = currentDescription().text;
			}
			// Same bands as the analysis: titles from 50 % of the width (25 % on pages), descriptions from 70 %.
			const fit = serpFit( text, kind );
			const pct = Math.round( fit.ratio * 100 );
			const min = kind === 'title' ? ( cfg.postType === 'page' ? 25 : 50 ) : 70;
			counter.textContent = sprintf( /* translators: 1: characters, 2: percentage of the width Google shows. */ __( '%1$d characters · %2$d%% of the width Google shows', 'searchpilot-pro' ), fit.graphemes, pct );
			let cls = '';
			if ( fit.graphemes ) {
				cls = fit.fits && pct >= min ? 'is-good' : 'is-warn';
			}
			counter.className = 'spp-mb-counter ' + cls;
		} );
	};

	/* ---------- SERP preview ---------- */

	const vars = () => ( {
		title: val( 'title' ),
		sitename: cfg.siteName,
		sitedesc: cfg.tagline,
		tagline: cfg.tagline,
		sep: cfg.separator,
		excerpt: serpTruncate( toText( val( 'excerpt' ) ), 'description' ),
		currentyear: String( new Date().getFullYear() ),
		current_year: String( new Date().getFullYear() ),
	} );

	/** { text, source, origin } of the title the page will show. */
	const currentTitle = () => {
		if ( byId( 'spp-mb-title' ) ) {
			// SearchPilot outputs the title: its own template and separator.
			return {
				text: resolveVars( field( 'title' ) || cfg.titleTemplate || '%title% %sep% %sitename%', vars() ),
				source: 'searchpilot',
				origin: field( 'title' ) ? 'custom' : 'template',
			};
		}
		// Another plugin owns the title: the server resolves it with that plugin's template and separator.
		if ( liveEffective && liveEffective.title_source ) {
			return { text: liveEffective.title || '', source: liveEffective.title_source, origin: liveEffective.title_origin || '' };
		}
		return { text: val( 'title' ), source: ( cfg.stores || {} ).title || '', origin: '' };
	};

	/** { text, source, origin } of the description the page will serve. */
	const currentDescription = () => {
		if ( byId( 'spp-mb-description' ) ) {
			const own = field( 'description' );
			if ( own ) {
				return { text: resolveVars( own, vars() ), source: 'searchpilot', origin: 'custom' };
			}
			if ( cfg.autoDescription ) {
				const text = serpTruncate( toText( val( 'excerpt' ) ) || toText( content() ), 'description' );
				return { text, source: text ? 'searchpilot' : 'none', origin: text ? 'auto' : 'none' };
			}
			return { text: '', source: 'none', origin: 'none' };
		}
		if ( liveEffective && liveEffective.description_source ) {
			return { text: liveEffective.description || '', source: liveEffective.description_source, origin: liveEffective.description_origin || '' };
		}
		return { text: '', source: '', origin: '' };
	};

	const renderSerp = () => {
		let host = '';
		let path = '';
		try {
			const u = new URL( cfg.permalink || cfg.homeUrl );
			host = u.host;
			let decoded = u.pathname;
			try {
				decoded = decodeURI( u.pathname );
			} catch ( e ) {}
			path = decoded.split( '/' ).filter( Boolean ).join( ' › ' );
		} catch ( e ) {
			host = cfg.homeUrl || '';
		}
		const title = currentTitle();
		const desc = currentDescription();
		const shown = desc.text ? serpTruncate( desc.text, 'description' ) : '';
		const known = !! title.source && ( !! desc.source || !! desc.text );
		mount(
			byId( 'spp-classic-serp' ),
			h(
				'div',
				{ class: 'spp-ed-serp', 'aria-label': __( 'Search result preview', 'searchpilot-pro' ) },
				h( 'div', { class: 'spp-ed-serp-site' }, cfg.siteName || host ),
				h( 'div', { class: 'spp-ed-serp-url' }, host + ( path ? ' › ' + path : '' ) ),
				h( 'div', { class: 'spp-ed-serp-title' }, title.text ? serpTruncate( title.text, 'title' ) : __( '(no title)', 'searchpilot-pro' ) ),
				desc.text ? h( 'div', { class: 'spp-ed-serp-desc' }, shown ) : h( 'div', { class: 'spp-ed-serp-desc is-empty' }, desc.source ? __( 'No meta description — Google will pick text from the page.', 'searchpilot-pro' ) : __( 'Checking what the page serves…', 'searchpilot-pro' ) )
			),
			known
				? h(
						'p',
						{ class: 'spp-ed-note' },
						sprintf(
							/* translators: 1: where the title comes from, 2: where the description comes from. */
							__( 'Title: %1$s. Description: %2$s.', 'searchpilot-pro' ),
							sourceLabel( title.source, title.origin, false ),
							sourceLabel( desc.source, desc.origin, true )
						)
				  )
				: null,
			desc.text && shown !== desc.text
				? h(
						'p',
						{ class: 'spp-ed-note' },
						sprintf(
							/* translators: %d: characters. */
							__( 'The description has %d characters; Google will likely cut it where the preview ends.', 'searchpilot-pro' ),
							length( desc.text )
						)
				  )
				: null
		);
	};

	/* ---------- Analysis ---------- */

	const GRADE = {
		good: __( 'Good', 'searchpilot-pro' ),
		ok: __( 'Needs improvement', 'searchpilot-pro' ),
		poor: __( 'Poor', 'searchpilot-pro' ),
	};
	const ICONS = { pass: '✓', warning: '!', fail: '✕', info: 'i' };
	const ICON_LABELS = {
		pass: __( 'Passed', 'searchpilot-pro' ),
		warning: __( 'Warning', 'searchpilot-pro' ),
		fail: __( 'Problem', 'searchpilot-pro' ),
		info: __( 'Information', 'searchpilot-pro' ),
	};

	const ring = ( score, grade ) => {
		const s = 64;
		const stroke = 7;
		const r = ( s - stroke ) / 2;
		const c = 2 * Math.PI * r;
		const value = Math.max( 0, Math.min( 100, Number( score ) || 0 ) );
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'width', s );
		svg.setAttribute( 'height', s );
		svg.setAttribute( 'viewBox', '0 0 ' + s + ' ' + s );
		svg.setAttribute( 'aria-hidden', 'true' );
		const track = document.createElementNS( ns, 'circle' );
		[ [ 'class', 'spp-ed-ring-track' ], [ 'cx', s / 2 ], [ 'cy', s / 2 ], [ 'r', r ], [ 'fill', 'none' ], [ 'stroke-width', stroke ] ].forEach( ( a ) => track.setAttribute( a[ 0 ], a[ 1 ] ) );
		svg.appendChild( track );
		if ( score !== null && score !== undefined ) {
			const arc = document.createElementNS( ns, 'circle' );
			[
				[ 'class', 'spp-ed-ring-value is-' + ( grade || ( value >= 80 ? 'good' : value >= 50 ? 'ok' : 'poor' ) ) ],
				[ 'cx', s / 2 ],
				[ 'cy', s / 2 ],
				[ 'r', r ],
				[ 'fill', 'none' ],
				[ 'stroke-width', stroke ],
				[ 'stroke-linecap', 'round' ],
				[ 'stroke-dasharray', ( value / 100 ) * c + ' ' + c ],
				[ 'transform', 'rotate(-90 ' + s / 2 + ' ' + s / 2 + ')' ],
			].forEach( ( a ) => arc.setAttribute( a[ 0 ], a[ 1 ] ) );
			svg.appendChild( arc );
		}
		return h( 'div', { class: 'spp-ed-ring', title: __( 'SearchPilot score: an internal diagnostic, not a Google ranking.', 'searchpilot-pro' ) }, svg, h( 'span', { class: 'spp-ed-ring-label' }, score === null || score === undefined ? '–' : String( Math.round( value ) ) ) );
	};

	const renderAnalysis = ( res ) => {
		mount(
			byId( 'spp-classic-score' ),
			h( 'div', { class: 'spp-ed-row' }, ring( res.score, res.grade ), h( 'div', null, h( 'div', { class: 'spp-ed-grade' }, GRADE[ res.grade ] || res.grade || '' ), h( 'p', { class: 'spp-ed-disclaimer' }, res.disclaimer || __( 'Internal diagnostic, not a Google ranking.', 'searchpilot-pro' ) ) ) )
		);
		const order = { fail: 0, warning: 1, info: 2, pass: 3 };
		const groups = ( res.groups || [] )
			.map( ( g ) => ( { group: g, checks: ( res.checks || [] ).filter( ( c ) => c.group === g.id ).sort( ( a, b ) => ( order[ a.status ] || 0 ) - ( order[ b.status ] || 0 ) ) } ) )
			.filter( ( g ) => g.checks.length );
		mount(
			byId( 'spp-classic-analysis' ),
			h( 'h4', { class: 'spp-mb-heading' }, __( 'Analysis', 'searchpilot-pro' ) ),
			groups.map( ( g ) =>
				h(
					'div',
					{ class: 'spp-ed-group' },
					h( 'div', { class: 'spp-ed-group-title' }, h( 'span', null, g.group.label ), g.group.score != null ? h( 'span', null, String( Math.round( g.group.score ) ) ) : null ),
					g.checks.map( ( c ) =>
						h(
							'div',
							{ class: 'spp-ed-check' },
							h( 'span', { class: 'spp-ed-icon is-' + ( c.status || 'info' ), title: ICON_LABELS[ c.status ] || '' }, h( 'span', { 'aria-hidden': 'true' }, ICONS[ c.status ] || 'i' ), h( 'span', { class: 'screen-reader-text' }, ICON_LABELS[ c.status ] || '' ) ),
							h( 'div', null, h( 'div', { class: 'spp-ed-check-label' }, c.label ), c.message ? h( 'div', { class: 'spp-ed-check-text' }, c.message ) : null, c.status !== 'pass' && c.recommendation ? h( 'div', { class: 'spp-ed-check-fix' }, c.recommendation ) : null )
						)
					)
				)
			)
		);
	};

	let unavailable = false;
	let lastKey = '';

	const analyse = () => {
		if ( unavailable || ! cfg.postId ) {
			return;
		}
		const noindex = sent( 'noindex' );
		const data = {
			post_id: cfg.postId,
			title: val( 'title' ),
			content: content(),
			excerpt: val( 'excerpt' ),
			slug: slug(),
			focus: sent( 'focus' ),
			seo_title: sent( 'title' ),
			description: sent( 'description' ),
			canonical: sent( 'canonical' ),
			noindex: noindex === null || noindex === '' ? null : noindex === '1',
			featured_media: parseInt( val( '_thumbnail_id' ), 10 ) > 0 ? parseInt( val( '_thumbnail_id' ), 10 ) : 0,
			post_type: cfg.postType,
		};
		// Skip when nothing changed since the last request.
		const key = JSON.stringify( data );
		if ( key === lastKey ) {
			return;
		}
		lastKey = key;
		api( '/analysis/live', { method: 'POST', data } )
			.then( ( res ) => {
				liveEffective = res && res.effective ? res.effective : null;
				renderAnalysis( res );
				refresh();
			} )
			.catch( ( err ) => {
				if ( isUnavailable( err ) ) {
					unavailable = true;
					mount(
						byId( 'spp-classic-analysis' ),
						h( 'p', { class: 'spp-ed-empty' }, isForbidden( err ) ? __( 'Your role can’t use the content analysis — ask an editor.', 'searchpilot-pro' ) : __( 'Content analysis is not available on this site yet.', 'searchpilot-pro' ) )
					);
					mount( byId( 'spp-classic-score' ), ring( null ) );
					return;
				}
				lastKey = '';
				mount( byId( 'spp-classic-analysis' ), h( 'p', { class: 'spp-ed-empty' }, err.message || __( 'The analysis could not run. It will retry after your next change.', 'searchpilot-pro' ) ) );
			} );
	};

	const refresh = () => {
		updateCounters();
		renderSerp();
	};
	const scheduleAnalysis = debounce( analyse, 3000 );
	const onChange = () => {
		refresh();
		scheduleAnalysis();
	};

	/* ---------- Wiring ---------- */

	[ 'title', 'excerpt', 'content', 'post_name' ].forEach( ( id ) => {
		const node = byId( id );
		if ( node ) {
			node.addEventListener( 'input', onChange );
		}
	} );
	root.addEventListener( 'input', onChange );
	root.addEventListener( 'change', onChange );

	const hookTiny = () => {
		const ed = window.tinymce && window.tinymce.get ? window.tinymce.get( 'content' ) : null;
		if ( ed && ! ed.spp_hooked ) {
			ed.spp_hooked = true;
			ed.on( 'input keyup change SetContent', onChange );
			return true;
		}
		return false;
	};
	if ( ! hookTiny() && $ ) {
		$( document ).on( 'tinymce-editor-init', hookTiny );
	}

	// Social image picker.
	const choose = byId( 'spp-mb-og_image-choose' );
	const remove = byId( 'spp-mb-og_image-remove' );
	const input = byId( 'spp-mb-og_image' );
	const preview = byId( 'spp-mb-og_image-preview' );
	if ( choose && input ) {
		choose.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			if ( ! wp.media ) {
				return;
			}
			const frame = wp.media( { title: __( 'Social image', 'searchpilot-pro' ), multiple: false, library: { type: 'image' } } );
			frame.on( 'select', () => {
				const att = frame.state().get( 'selection' ).first().toJSON();
				input.value = String( att.id );
				if ( preview ) {
					preview.src = ( att.sizes && att.sizes.medium && att.sizes.medium.url ) || att.url;
					preview.hidden = false;
				}
			} );
			frame.open();
		} );
	}
	if ( remove && input ) {
		remove.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			input.value = '';
			if ( preview ) {
				preview.hidden = true;
				preview.removeAttribute( 'src' );
			}
		} );
	}

	refresh();
	mount( byId( 'spp-classic-score' ), ring( null ) );
	analyse();
} )( window.wp, window.jQuery, window.SearchPilotEditor );

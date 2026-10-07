/**
 * SearchPilot Pro — block editor sidebar and pre-publish panel (no build step).
 *
 * Reads/writes SearchPilot's own post meta through the editor store, shows
 * another SEO plugin's values read-only when that plugin owns a field, and
 * asks the analysis, links, indexing and issues endpoints for everything
 * else. Every endpoint is optional: a missing one degrades to a short note.
 *
 * @package SearchPilot
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! cfg ) {
		return;
	}

	const el = wp.element.createElement;
	const { Fragment, useState, useEffect, useRef } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const apiFetch = wp.apiFetch;
	const C = wp.components;
	const editorPkg = wp.editor || {};
	const editPostPkg = wp.editPost || {};
	const PluginSidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	const PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	const PluginPrePublishPanel = editorPkg.PluginPrePublishPanel || editPostPkg.PluginPrePublishPanel;
	const be = wp.blockEditor || {};
	const MediaUpload = be.MediaUpload;
	const MediaUploadCheck = be.MediaUploadCheck || ( ( props ) => props.children );

	if ( ! PluginSidebar ) {
		return;
	}

	const NS = '/' + ( cfg.namespace || 'searchpilot/v1' );
	const SIDEBAR = 'searchpilot-seo-sidebar';
	const KEYS = cfg.metaKeys || {};

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	const api = ( path, opts ) => apiFetch( Object.assign( { path: NS + path }, opts || {} ) );

	/** The endpoint does not exist on this site (module not active). */
	const isMissing = ( err ) => !! err && ( err.code === 'rest_no_route' || ( err.data && err.data.status === 404 ) );

	/** The user's role may not use the endpoint. */
	const isForbidden = ( err ) => !! err && ( err.code === 'rest_forbidden' || ( err.data && ( err.data.status === 401 || err.data.status === 403 ) ) );

	const isUnavailable = ( err ) => isMissing( err ) || isForbidden( err );

	const ROLE_NOTE = __( 'Your role can’t use this panel — ask an editor.', 'searchpilot-pro' );

	/** Capabilities from the server (searchpilot_view / searchpilot_edit / searchpilot_manage). */
	const can = ( cap ) => !! ( cfg.caps && cfg.caps[ cap ] );

	/** Server datetimes without a zone are UTC. */
	const parseDate = ( value ) => {
		if ( ! value ) {
			return null;
		}
		const s = String( value );
		const d = new Date( /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/.test( s ) ? s.replace( ' ', 'T' ) + 'Z' : s );
		return isNaN( d.getTime() ) ? null : d;
	};

	const fmtDate = ( value ) => {
		const d = parseDate( value );
		return d ? d.toLocaleString( undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } ) : __( 'Never', 'searchpilot-pro' );
	};

	/** Plain text of HTML without executing anything (DOMParser does not run scripts or load images). */
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

	const resolveVars = ( template, vars ) => {
		let out = String( template || '' ).replace( /%%([a-z0-9_]+)%%/gi, '%$1%' );
		Object.keys( vars ).forEach( ( key ) => {
			out = out.split( '%' + key + '%' ).join( vars[ key ] || '' );
		} );
		return out.replace( /%[a-z_]+%/g, '' ).replace( /\s{2,}/g, ' ' ).trim();
	};

	const escapeHtml = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

	const copyText = ( text ) => {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( text );
		}
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} finally {
			ta.remove();
		}
		return Promise.resolve();
	};

	/** Fields authors/contributors may change; the rest needs an editor (searchpilot_edit). */
	const permitted = ( field ) => ( cfg.caps && cfg.caps.edit ) || ( cfg.authorFields || [] ).indexOf( field ) >= 0;

	const editable = ( field, seo ) => {
		if ( seo && seo.fields && seo.fields[ field ] ) {
			return !! seo.fields[ field ].editable;
		}
		return ( cfg.stores || {} )[ field ] === 'searchpilot' && permitted( field );
	};

	/** SearchPilot stores the field but the user may not change it. */
	const restricted = ( field, seo ) => {
		if ( seo && seo.fields && seo.fields[ field ] ) {
			return !! seo.fields[ field ].restricted && seo.fields[ field ].store === 'searchpilot';
		}
		return ( cfg.stores || {} )[ field ] === 'searchpilot' && ! permitted( field );
	};

	const SIDEBAR_NAME = 'searchpilot-seo/searchpilot-seo-sidebar';

	/** Whether the SearchPilot sidebar or the pre-publish panel is open (WordPress 6.2–7.x). */
	const panelOpen = ( select ) => {
		let open = false;
		try {
			const area = select( 'core/interface' );
			if ( area && area.getActiveComplementaryArea ) {
				// WordPress 6.6+ (PluginSidebar in wp.editor) uses the "core" scope; older versions "core/edit-post".
				open = area.getActiveComplementaryArea( wp.editor && wp.editor.PluginSidebar ? 'core' : 'core/edit-post' ) === SIDEBAR_NAME;
			}
		} catch ( e ) {}
		if ( ! open ) {
			try {
				const ep = select( 'core/edit-post' );
				open = !! ( ep && ep.isPluginSidebarOpened && ep.isPluginSidebarOpened() && ep.getActiveGeneralSidebar && ep.getActiveGeneralSidebar() === SIDEBAR_NAME );
			} catch ( e ) {}
		}
		if ( ! open ) {
			try {
				const ed = select( 'core/editor' );
				open = !! ( ed && ed.isPublishSidebarOpened && ed.isPublishSidebarOpened() );
			} catch ( e ) {}
		}
		return open;
	};

	const storeName = ( field, seo ) => {
		const store = ( seo && seo.fields && seo.fields[ field ] && seo.fields[ field ].store ) || ( cfg.stores || {} )[ field ];
		return ( seo && seo.fields && seo.fields[ field ] && seo.fields[ field ].store_name ) || ( cfg.storeNames || {} )[ store ] || store || '';
	};

	const GRADE = {
		good: __( 'Good', 'searchpilot-pro' ),
		ok: __( 'Needs improvement', 'searchpilot-pro' ),
		poor: __( 'Poor', 'searchpilot-pro' ),
	};

	const SEVERITY = {
		critical: __( 'Critical', 'searchpilot-pro' ),
		high: __( 'High', 'searchpilot-pro' ),
		medium: __( 'Medium', 'searchpilot-pro' ),
		opportunity: __( 'Opportunity', 'searchpilot-pro' ),
	};

	const STATUS_ICON = {
		pass: [ '✓', __( 'Passed', 'searchpilot-pro' ) ],
		warning: [ '!', __( 'Warning', 'searchpilot-pro' ) ],
		fail: [ '✕', __( 'Problem', 'searchpilot-pro' ) ],
		info: [ 'i', __( 'Information', 'searchpilot-pro' ) ],
	};

	/** Same wording as SearchPilot → Indexing. */
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

	/** A date, or a word when there is none. */
	const whenOr = ( value, word ) => ( parseDate( value ) ? fmtDate( value ) : word );

	/* ------------------------------------------------------------------ *
	 * Shared store (sidebar + pre-publish panel)
	 * ------------------------------------------------------------------ */

	const store = {
		seo: null,
		seoError: '',
		analysis: null,
		analysisStatus: 'idle',
		analysisError: '',
		resolvedTitle: '',
		pending: {},
		savingPending: false,
		listeners: new Set(),
		set( patch ) {
			Object.assign( store, patch );
			store.listeners.forEach( ( fn ) => fn() );
		},
	};

	function useStore() {
		const [ , force ] = useState( 0 );
		useEffect( () => {
			const fn = () => force( ( n ) => n + 1 );
			store.listeners.add( fn );
			return () => store.listeners.delete( fn );
		}, [] );
		return store;
	}

	/** Edited post data the sidebar needs (cheap selectors only). */
	function usePost() {
		return useSelect( ( select ) => {
			const ed = select( 'core/editor' );
			return {
				id: ed.getCurrentPostId(),
				type: ed.getCurrentPostType(),
				title: ed.getEditedPostAttribute( 'title' ) || '',
				excerpt: ed.getEditedPostAttribute( 'excerpt' ) || '',
				slug: ed.getEditedPostAttribute( 'slug' ) || '',
				status: ed.getEditedPostAttribute( 'status' ) || 'draft',
				link: ed.getPermalink ? ed.getPermalink() || '' : ed.getEditedPostAttribute( 'link' ) || '',
				featured: ed.getEditedPostAttribute( 'featured_media' ) || 0,
				categories: ed.getEditedPostAttribute( 'categories' ) || [],
				meta: ed.getEditedPostAttribute( 'meta' ) || {},
				published: ed.isCurrentPostPublished(),
				saving: ed.isSavingPost() && ! ed.isAutosavingPost(),
			};
		}, [] );
	}

	/**
	 * Post types without "custom-fields" support do not expose meta in the REST API:
	 * then fields are saved through SearchPilot's own endpoint when the post is saved.
	 */
	const isDirect = ( post ) => post.meta[ KEYS.title ] === undefined;

	/** Value of one of our fields: own meta, pending direct edits, or the stored value (any plugin). */
	const fieldValue = ( field, post, seo ) => {
		if ( editable( field, seo ) && ! isDirect( post ) ) {
			const v = post.meta[ KEYS[ field ] ];
			return v === undefined || v === null ? '' : v;
		}
		if ( store.pending && Object.prototype.hasOwnProperty.call( store.pending, field ) ) {
			return store.pending[ field ];
		}
		const f = seo && seo.fields && seo.fields[ field ];
		if ( ! f ) {
			return '';
		}
		if ( field === 'noindex' ) {
			return f.value === null || f.value === undefined ? '' : f.value ? '1' : '0';
		}
		if ( field === 'nofollow' ) {
			return f.value ? '1' : '';
		}
		return f.value !== null && f.value !== undefined ? f.value : '';
	};

	/** Saves pending direct-mode edits. */
	const savePending = ( postId ) => {
		const pending = store.pending || {};
		if ( ! Object.keys( pending ).length ) {
			return Promise.resolve();
		}
		store.set( { savingPending: true } );
		return api( '/seo/post/' + postId, { method: 'POST', data: { fields: pending } } )
			.then( ( res ) => {
				// Keep what the server refused (and say why); drop what was saved.
				const errors = res.errors || {};
				const rejected = {};
				Object.keys( errors ).forEach( ( f ) => {
					if ( Object.prototype.hasOwnProperty.call( pending, f ) ) {
						rejected[ f ] = pending[ f ];
					}
				} );
				const messages = Object.keys( errors ).map( ( f ) => errors[ f ] );
				store.set( { seo: res, pending: rejected, savingPending: false, seoError: messages.join( ' ' ) } );
			} )
			.catch( ( err ) => store.set( { savingPending: false, seoError: err.message || '' } ) );
	};

	/* ------------------------------------------------------------------ *
	 * Background runner: SEO data, title resolution, live analysis
	 * ------------------------------------------------------------------ */

	function Runner() {
		const post = usePost();
		// Only while the SearchPilot sidebar or the pre-publish panel is open: no background requests otherwise.
		const open = useSelect( ( select ) => panelOpen( select ), [] );
		const content = useSelect( ( select ) => ( open ? select( 'core/editor' ).getEditedPostContent() : '' ), [ open ] );
		const timer = useRef( 0 );
		const titleTimer = useRef( 0 );
		const unavailable = useRef( false );
		const wasSaving = useRef( false );
		const lastAnalysis = useRef( '' );
		const lastTitle = useRef( '' );

		const loadSeo = () =>
			api( '/seo/post/' + post.id )
				.then( ( data ) => store.set( { seo: data, seoError: '' } ) )
				.catch( ( err ) => store.set( { seoError: isUnavailable( err ) ? '' : err.message || '' } ) );

		useEffect( () => {
			if ( post.id ) {
				loadSeo();
			}
		}, [ post.id ] );

		// After each save: store direct-mode edits, then refresh stored values (other plugins' fields, effective title…).
		useEffect( () => {
			if ( wasSaving.current && ! post.saving && post.id ) {
				if ( Object.keys( store.pending || {} ).length ) {
					savePending( post.id );
				} else {
					loadSeo();
				}
			}
			wasSaving.current = post.saving;
		}, [ post.saving ] );

		const st = useStore();
		/** Our value, or null when another plugin owns the field (the server then uses its effective value). */
		const sent = ( field ) => ( editable( field, st.seo ) ? String( fieldValue( field, post, st.seo ) || '' ) : null );
		const template = String( fieldValue( 'title', post, st.seo ) || '' );

		// Exact title from the server (templates resolved like the front end, with the title being typed).
		useEffect( () => {
			clearTimeout( titleTimer.current );
			if ( ! open || ! post.id || ! editable( 'title', st.seo ) ) {
				return undefined;
			}
			const key = post.title + '|' + post.excerpt + '|' + template;
			if ( key === lastTitle.current ) {
				return undefined;
			}
			titleTimer.current = setTimeout( () => {
				lastTitle.current = key;
				api( '/seo/resolve', { method: 'POST', data: { template, post_id: post.id, title: post.title, excerpt: post.excerpt } } )
					.then( ( res ) => store.set( { resolvedTitle: res.title || '', resolvedFor: post.title + '|' + template } ) )
					.catch( () => {
						lastTitle.current = '';
					} );
			}, 800 );
			return () => clearTimeout( titleTimer.current );
		}, [ open, post.id, post.title, post.excerpt, template, !! st.seo ] );

		const noindexRaw = editable( 'noindex', st.seo ) ? fieldValue( 'noindex', post, st.seo ) : null;
		const payload = {
			post_id: post.id,
			title: post.title,
			content,
			excerpt: post.excerpt,
			slug: post.slug,
			focus: sent( 'focus' ),
			seo_title: sent( 'title' ),
			description: sent( 'description' ),
			canonical: sent( 'canonical' ),
			noindex: noindexRaw === null || noindexRaw === '' ? null : noindexRaw === true || noindexRaw === '1',
			featured_media: post.featured,
			post_type: post.type,
		};
		const payloadKey = open ? JSON.stringify( payload ) : '';

		// Live analysis: 3 s after the last change, only when something changed since the last request.
		useEffect( () => {
			clearTimeout( timer.current );
			if ( ! open || ! post.id || unavailable.current || payloadKey === lastAnalysis.current ) {
				return undefined;
			}
			timer.current = setTimeout( () => {
				lastAnalysis.current = payloadKey;
				store.set( { analysisStatus: store.analysis ? 'refreshing' : 'loading' } );
				api( '/analysis/live', { method: 'POST', data: payload } )
					.then( ( res ) => store.set( { analysis: res, analysisStatus: 'ready', analysisError: '' } ) )
					.catch( ( err ) => {
						if ( isUnavailable( err ) ) {
							unavailable.current = true;
							store.set( { analysisStatus: isForbidden( err ) ? 'forbidden' : 'unavailable' } );
						} else {
							lastAnalysis.current = '';
							store.set( { analysisStatus: 'error', analysisError: err.message || '' } );
						}
					} );
			}, store.analysis ? 3000 : 600 );
			return () => clearTimeout( timer.current );
		}, [ payloadKey ] );

		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Small components
	 * ------------------------------------------------------------------ */

	function ScoreRing( { score, grade, size } ) {
		const s = size || 64;
		const stroke = 7;
		const r = ( s - stroke ) / 2;
		const c = 2 * Math.PI * r;
		const value = Math.max( 0, Math.min( 100, Number( score ) || 0 ) );
		const tone = grade || ( value >= 80 ? 'good' : value >= 50 ? 'ok' : 'poor' );
		return el(
			'div',
			{ className: 'spp-ed-ring', style: { width: s + 'px', height: s + 'px' }, title: __( 'SearchPilot score: an internal diagnostic, not a Google ranking.', 'searchpilot-pro' ) },
			el(
				'svg',
				{ width: s, height: s, viewBox: '0 0 ' + s + ' ' + s, 'aria-hidden': 'true' },
				el( 'circle', { className: 'spp-ed-ring-track', cx: s / 2, cy: s / 2, r, fill: 'none', strokeWidth: stroke } ),
				score === null || score === undefined
					? null
					: el( 'circle', {
							className: 'spp-ed-ring-value is-' + tone,
							cx: s / 2,
							cy: s / 2,
							r,
							fill: 'none',
							strokeWidth: stroke,
							strokeLinecap: 'round',
							strokeDasharray: ( value / 100 ) * c + ' ' + c,
							transform: 'rotate(-90 ' + s / 2 + ' ' + s / 2 + ')',
					  } )
			),
			el( 'span', { className: 'spp-ed-ring-label' }, score === null || score === undefined ? '–' : Math.round( value ) )
		);
	}

	/** Characters (graphemes) and the estimated share of the width Google shows. */
	function Counter( { text, kind } ) {
		const fit = serpFit( text, kind );
		const pct = Math.round( fit.ratio * 100 );
		// Same bands as the analysis: titles from 50 % (25 % on pages), descriptions from 70 %.
		const min = kind === 'title' ? ( cfg.postType === 'page' ? 25 : 50 ) : 70;
		let cls = '';
		if ( fit.graphemes ) {
			cls = fit.fits && pct >= min ? 'is-good' : 'is-warn';
		}
		return el(
			'span',
			{ className: 'spp-ed-counter ' + cls, title: __( 'Characters, and the estimated share of the width Google shows in search results', 'searchpilot-pro' ) },
			sprintf( /* translators: 1: characters, 2: percentage of the width Google shows. */ __( '%1$d characters · %2$d%%', 'searchpilot-pro' ), fit.graphemes, pct )
		);
	}

	function ReadOnly( { label, value, field, seo } ) {
		let shown = value;
		if ( value === true || value === false ) {
			shown = value ? __( 'Yes', 'searchpilot-pro' ) : __( 'No', 'searchpilot-pro' );
		} else if ( value === null || value === undefined || value === '' ) {
			shown = __( '(empty — plugin default)', 'searchpilot-pro' );
		}
		return el(
			'div',
			{ className: 'spp-ed-readonly' },
			el( 'span', { className: 'spp-ed-readonly-label' }, label ),
			el( 'div', { className: 'spp-ed-readonly-value' }, String( shown ) ),
			el(
				'p',
				{ className: 'spp-ed-note' },
				restricted( field, seo )
					? __( 'Only editors and administrators can change this setting.', 'searchpilot-pro' )
					: sprintf( /* translators: %s: SEO plugin name. */ __( 'Edited in %s, which outputs this for your site.', 'searchpilot-pro' ), storeName( field, seo ) )
			)
		);
	}

	function Serp( { title, url, description } ) {
		let host = '';
		let path = '';
		try {
			const u = new URL( url || cfg.homeUrl );
			host = u.host;
			let decoded = u.pathname;
			try {
				decoded = decodeURI( u.pathname );
			} catch ( e ) {}
			path = decoded.split( '/' ).filter( Boolean ).join( ' › ' );
		} catch ( e ) {
			host = url || '';
		}
		return el(
			'div',
			{ className: 'spp-ed-serp', 'aria-label': __( 'Search result preview', 'searchpilot-pro' ) },
			el( 'div', { className: 'spp-ed-serp-site' }, cfg.siteName || host ),
			el( 'div', { className: 'spp-ed-serp-url' }, host + ( path ? ' › ' + path : '' ) ),
			el( 'div', { className: 'spp-ed-serp-title' }, title ? serpTruncate( title, 'title' ) : __( '(no title)', 'searchpilot-pro' ) ),
			description
				? el( 'div', { className: 'spp-ed-serp-desc' }, serpTruncate( description, 'description' ) )
				: el( 'div', { className: 'spp-ed-serp-desc is-empty' }, __( 'No meta description — Google will pick text from the page.', 'searchpilot-pro' ) )
		);
	}

	function CheckRow( { check } ) {
		const icon = STATUS_ICON[ check.status ] || STATUS_ICON.info;
		return el(
			'div',
			{ className: 'spp-ed-check' },
			el( 'span', { className: 'spp-ed-icon is-' + ( check.status || 'info' ), title: icon[ 1 ] }, el( 'span', { 'aria-hidden': 'true' }, icon[ 0 ] ), el( 'span', { className: 'screen-reader-text' }, icon[ 1 ] ) ),
			el(
				'div',
				null,
				el( 'div', { className: 'spp-ed-check-label' }, check.label ),
				check.message ? el( 'div', { className: 'spp-ed-check-text' }, check.message ) : null,
				check.status !== 'pass' && check.recommendation ? el( 'div', { className: 'spp-ed-check-fix' }, check.recommendation ) : null
			)
		);
	}

	/** Loads an optional endpoint once when mounted (panels mount when opened). */
	function useRemote( path, deps ) {
		const [ state, setState ] = useState( { status: 'loading', data: null, error: '' } );
		const load = () => {
			setState( ( s ) => Object.assign( {}, s, { status: s.data ? 'refreshing' : 'loading' } ) );
			return api( path )
				.then( ( data ) => setState( { status: 'ready', data, error: '' } ) )
				.catch( ( err ) => {
					let status = 'error';
					if ( isForbidden( err ) ) {
						status = 'forbidden';
					} else if ( isMissing( err ) ) {
						status = 'unavailable';
					}
					setState( { status, data: null, error: err.message || '' } );
				} );
		};
		useEffect( () => {
			load();
		}, deps || [] );
		return [ state, load ];
	}

	const Loading = () => el( 'div', { className: 'spp-ed-row' }, el( C.Spinner ), el( 'span', { className: 'spp-ed-empty' }, __( 'Loading…', 'searchpilot-pro' ) ) );

	/* ------------------------------------------------------------------ *
	 * Panels
	 * ------------------------------------------------------------------ */

	function Header() {
		const st = useStore();
		const a = st.analysis;
		let text;
		if ( st.analysisStatus === 'unavailable' ) {
			text = __( 'Content analysis is not available yet.', 'searchpilot-pro' );
		} else if ( st.analysisStatus === 'forbidden' ) {
			text = __( 'Your role can’t use the content analysis — ask an editor.', 'searchpilot-pro' );
		} else if ( st.analysisStatus === 'error' ) {
			text = st.analysisError || __( 'The analysis could not run. It will retry after your next change.', 'searchpilot-pro' );
		} else if ( ! a ) {
			text = __( 'Analysing…', 'searchpilot-pro' );
		} else {
			text = GRADE[ a.grade ] || a.grade;
		}
		return el(
			'div',
			{ className: 'spp-ed-header' },
			el( ScoreRing, { score: a ? a.score : null, grade: a ? a.grade : null } ),
			el(
				'div',
				null,
				el( 'div', { className: 'spp-ed-grade' }, text, st.analysisStatus === 'refreshing' || st.analysisStatus === 'loading' ? el( C.Spinner ) : null ),
				el( 'p', { className: 'spp-ed-disclaimer' }, ( a && a.disclaimer ) || __( 'SearchPilot score: internal diagnostic, not a Google ranking.', 'searchpilot-pro' ) )
			)
		);
	}

	function AppearancePanel( { post, setMeta } ) {
		const st = useStore();
		const seo = st.seo;
		// The live analysis resolves the head with the unsaved title/excerpt the way the page will
		// serve it (the companion plugin's template and separator); the SEO payload is the saved state.
		const live = st.analysis && st.analysis.effective ? st.analysis.effective : null;
		const saved = ( seo && seo.effective ) || {};
		const vars = {
			title: post.title,
			sitename: cfg.siteName,
			sitedesc: cfg.tagline,
			tagline: cfg.tagline,
			sep: cfg.separator,
			excerpt: serpTruncate( toText( post.excerpt ), 'description' ),
			currentyear: String( new Date().getFullYear() ),
			current_year: String( new Date().getFullYear() ),
		};
		const titleField = String( fieldValue( 'title', post, seo ) || '' );
		const descField = String( fieldValue( 'description', post, seo ) || '' );
		let title;
		let titleSource;
		let titleOrigin;
		if ( editable( 'title', seo ) ) {
			// SearchPilot outputs the title: its own template and separator.
			const local = resolveVars( titleField || cfg.titleTemplate || '%title% %sep% %sitename%', vars );
			title = st.resolvedTitle && st.resolvedFor === post.title + '|' + titleField ? st.resolvedTitle : local;
			titleSource = 'searchpilot';
			titleOrigin = titleField ? 'custom' : 'template';
		} else if ( live && live.title_source ) {
			title = live.title || '';
			titleSource = live.title_source;
			titleOrigin = live.title_origin || '';
		} else {
			title = saved.title || post.title;
			titleSource = saved.title_source || ( cfg.stores || {} ).title || '';
			titleOrigin = saved.title_origin || '';
		}
		let description = '';
		let descSource = 'none';
		let descOrigin = 'none';
		if ( editable( 'description', seo ) ) {
			if ( descField ) {
				description = resolveVars( descField, vars );
				descSource = 'searchpilot';
				descOrigin = 'custom';
			} else if ( cfg.autoDescription ) {
				description = live && live.description_origin === 'auto' ? live.description || '' : vars.excerpt;
				descSource = description ? 'searchpilot' : 'none';
				descOrigin = description ? 'auto' : 'none';
			}
		} else if ( live && live.description_source ) {
			description = live.description || '';
			descSource = live.description_source;
			descOrigin = live.description_origin || '';
		} else {
			description = saved.description || '';
			descSource = saved.description_source || ( description ? ( cfg.stores || {} ).description || '' : 'none' );
			descOrigin = saved.description_origin || '';
		}
		const cut = !! description && serpTruncate( description, 'description' ) !== description;
		const url = post.link || ( seo && seo.url ) || '';

		const text = ( field, label, props ) =>
			editable( field, seo )
				? el( props && props.multiline ? C.TextareaControl : C.TextControl, {
						label: props && props.counter ? el( Fragment, null, label, el( Counter, { text: props.counterText !== undefined ? props.counterText : fieldValue( field, post, seo ), kind: props.counter } ) ) : label,
						value: String( fieldValue( field, post, seo ) || '' ),
						placeholder: ( props && props.placeholder ) || '',
						help: ( props && props.help ) || undefined,
						rows: props && props.multiline ? 3 : undefined,
						onChange: ( v ) => setMeta( field, v ),
						__nextHasNoMarginBottom: true,
				  } )
				: el( ReadOnly, { label, value: fieldValue( field, post, seo ), field, seo } );

		return el(
			C.PanelBody,
			{ title: __( 'Search appearance', 'searchpilot-pro' ), initialOpen: true },
			el( Serp, { title, url, description } ),
			el(
				'p',
				{ className: 'spp-ed-note' },
				sprintf(
					/* translators: 1: where the title comes from, 2: where the description comes from. */
					__( 'Title: %1$s. Description: %2$s.', 'searchpilot-pro' ),
					sourceLabel( titleSource, titleOrigin, false ),
					sourceLabel( descSource, descOrigin, true )
				)
			),
			cut
				? el(
						'p',
						{ className: 'spp-ed-note' },
						sprintf(
							/* translators: %d: characters. */
							__( 'The description has %d characters; Google will likely cut it where the preview ends.', 'searchpilot-pro' ),
							length( description )
						)
				  )
				: null,
			text( 'title', __( 'SEO title', 'searchpilot-pro' ), {
				counter: 'title',
				counterText: title,
				placeholder: cfg.titleTemplate || '',
				help: __( 'Leave empty to use the default template. Variables: %title%, %sitename%, %sep%, %category%.', 'searchpilot-pro' ),
			} ),
			el( 'div', { style: { height: '12px' } } ),
			text( 'description', __( 'Meta description', 'searchpilot-pro' ), {
				multiline: true,
				counter: 'description',
				counterText: description,
				help: cfg.autoDescription ? __( 'Leave empty to use the excerpt or the opening text automatically.', 'searchpilot-pro' ) : undefined,
			} ),
			el( 'div', { style: { height: '12px' } } ),
			text( 'focus', __( 'Focus keyword', 'searchpilot-pro' ), { help: __( 'The main topic this page should be found for.', 'searchpilot-pro' ) } )
		);
	}

	function AnalysisPanel() {
		const st = useStore();
		const a = st.analysis;
		let body;
		if ( st.analysisStatus === 'unavailable' ) {
			body = el( 'p', { className: 'spp-ed-empty' }, __( 'The content analysis module is not available on this site yet.', 'searchpilot-pro' ) );
		} else if ( st.analysisStatus === 'forbidden' ) {
			body = el( 'p', { className: 'spp-ed-empty' }, ROLE_NOTE );
		} else if ( ! a ) {
			body = st.analysisStatus === 'error' ? el( C.Notice, { status: 'warning', isDismissible: false }, st.analysisError || __( 'The analysis could not run.', 'searchpilot-pro' ) ) : el( Loading );
		} else {
			const groups = ( a.groups || [] ).map( ( g ) => ( { group: g, checks: ( a.checks || [] ).filter( ( c ) => c.group === g.id ) } ) ).filter( ( g ) => g.checks.length );
			const order = { fail: 0, warning: 1, info: 2, pass: 3 };
			const stats = a.stats || {};
			body = el(
				Fragment,
				null,
				el(
					'div',
					{ className: 'spp-ed-stats' },
					el( 'div', { className: 'spp-ed-stat' }, el( 'b', null, stats.words !== undefined ? stats.words : '–' ), el( 'span', null, __( 'words', 'searchpilot-pro' ) ) ),
					el( 'div', { className: 'spp-ed-stat' }, el( 'b', null, stats.reading_time !== undefined ? stats.reading_time : '–' ), el( 'span', null, __( 'min read', 'searchpilot-pro' ) ) ),
					el( 'div', { className: 'spp-ed-stat' }, el( 'b', null, stats.internal_links !== undefined ? stats.internal_links : '–' ), el( 'span', null, __( 'internal links', 'searchpilot-pro' ) ) )
				),
				groups.map( ( g ) =>
					el(
						'div',
						{ className: 'spp-ed-group', key: g.group.id },
						el( 'div', { className: 'spp-ed-group-title' }, el( 'span', null, g.group.label ), g.group.score != null ? el( 'span', null, Math.round( g.group.score ) ) : null ),
						g.checks
							.slice()
							.sort( ( x, y ) => ( order[ x.status ] || 0 ) - ( order[ y.status ] || 0 ) )
							.map( ( c ) => el( CheckRow, { check: c, key: c.id } ) )
					)
				),
				a.queries && a.queries.length
					? el(
							'div',
							{ className: 'spp-ed-group' },
							el( 'div', { className: 'spp-ed-group-title' }, el( 'span', null, __( 'Search queries (last 28 days)', 'searchpilot-pro' ) ) ),
							el(
								'ul',
								{ className: 'spp-ed-list' },
								a.queries.slice( 0, 8 ).map( ( q ) =>
									el(
										'li',
										{ key: q.query },
										el( 'div', { className: 'spp-ed-item-title' }, q.query, ' ', el( 'span', { className: 'spp-ed-badge ' + ( q.covered ? 'is-passed' : 'is-opportunity' ) }, q.covered ? __( 'covered', 'searchpilot-pro' ) : __( 'not in content', 'searchpilot-pro' ) ) ),
										el( 'div', { className: 'spp-ed-item-text' }, sprintf( /* translators: 1: clicks, 2: impressions, 3: position. */ __( '%1$s clicks · %2$s impressions · position %3$s', 'searchpilot-pro' ), q.clicks, q.impressions, Number( q.position || 0 ).toFixed( 1 ) ) )
									)
								)
							)
					  )
					: null
			);
		}
		return el( C.PanelBody, { title: __( 'Analysis', 'searchpilot-pro' ), initialOpen: true }, body );
	}

	function ImageField( { value, onChange } ) {
		const id = /^\d+$/.test( String( value || '' ) ) ? parseInt( value, 10 ) : 0;
		const media = useSelect(
			( select ) => {
				if ( ! id ) {
					return null;
				}
				const core = select( 'core' );
				if ( core.getEntityRecord ) {
					return core.getEntityRecord( 'postType', 'attachment', id );
				}
				return core.getMedia ? core.getMedia( id ) : null;
			},
			[ id ]
		);
		const src = id ? media && ( ( media.media_details && media.media_details.sizes && media.media_details.sizes.medium && media.media_details.sizes.medium.source_url ) || media.source_url ) : String( value || '' );
		return el(
			'div',
			{ className: 'components-base-control' },
			el( 'span', { className: 'spp-ed-readonly-label' }, __( 'Social image', 'searchpilot-pro' ) ),
			src ? el( 'div', { className: 'spp-ed-image' }, el( 'img', { src, alt: '' } ) ) : null,
			el(
				'div',
				{ className: 'spp-ed-row' },
				MediaUpload
					? el(
							MediaUploadCheck,
							null,
							el( MediaUpload, {
								onSelect: ( m ) => onChange( m && m.id ? String( m.id ) : '' ),
								allowedTypes: [ 'image' ],
								value: id || undefined,
								render: ( { open } ) => el( C.Button, { variant: 'secondary', onClick: open }, value ? __( 'Replace image', 'searchpilot-pro' ) : __( 'Choose image', 'searchpilot-pro' ) ),
							} )
					  )
					: null,
				value ? el( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( '' ) }, __( 'Remove', 'searchpilot-pro' ) ) : null
			),
			el( 'p', { className: 'spp-ed-note' }, __( 'Defaults to the featured image. 1200 × 630 px works best.', 'searchpilot-pro' ) )
		);
	}

	function SocialPanel( { post, setMeta } ) {
		const st = useStore();
		const seo = st.seo;
		const text = ( field, label, multiline ) =>
			editable( field, seo )
				? el( multiline ? C.TextareaControl : C.TextControl, {
						label,
						value: String( fieldValue( field, post, seo ) || '' ),
						rows: multiline ? 3 : undefined,
						onChange: ( v ) => setMeta( field, v ),
				  } )
				: el( ReadOnly, { label, value: fieldValue( field, post, seo ), field, seo } );
		return el(
			C.PanelBody,
			{ title: __( 'Social sharing', 'searchpilot-pro' ), initialOpen: false },
			! cfg.ogEnabled && ! cfg.twitterEnabled ? el( C.Notice, { status: 'info', isDismissible: false }, __( 'Open Graph and X/Twitter tags are switched off in SearchPilot → Settings.', 'searchpilot-pro' ) ) : null,
			text( 'og_title', __( 'Social title', 'searchpilot-pro' ) ),
			text( 'og_description', __( 'Social description', 'searchpilot-pro' ), true ),
			editable( 'og_image', seo ) ? el( ImageField, { value: fieldValue( 'og_image', post, seo ), onChange: ( v ) => setMeta( 'og_image', v ) } ) : el( ReadOnly, { label: __( 'Social image', 'searchpilot-pro' ), value: fieldValue( 'og_image', post, seo ), field: 'og_image', seo } ),
			text( 'twitter_title', __( 'X/Twitter title', 'searchpilot-pro' ) ),
			text( 'twitter_description', __( 'X/Twitter description', 'searchpilot-pro' ), true ),
			el( 'p', { className: 'spp-ed-note' }, __( 'Leave empty to reuse the SEO title and description.', 'searchpilot-pro' ) )
		);
	}

	function PrimaryCategory( { post, setMeta } ) {
		const ids = post.categories || [];
		const terms = useSelect( ( select ) => ( ids.length ? select( 'core' ).getEntityRecords( 'taxonomy', 'category', { include: ids, per_page: 100, context: 'view' } ) : [] ), [ ids.join( ',' ) ] );
		if ( ids.length < 2 ) {
			return null;
		}
		const value = String( fieldValue( 'primary_category', post, store.seo ) || '' );
		return el( C.SelectControl, {
			label: __( 'Primary category', 'searchpilot-pro' ),
			help: __( 'Used for breadcrumbs, the article section in structured data and %category% in titles.', 'searchpilot-pro' ),
			value: value === '0' ? '' : value,
			options: [ { value: '', label: __( 'Automatic (first category)', 'searchpilot-pro' ) } ].concat( ( terms || [] ).map( ( t ) => ( { value: String( t.id ), label: toText( t.name ) } ) ) ),
			onChange: ( v ) => setMeta( 'primary_category', v ? parseInt( v, 10 ) : 0 ),
		} );
	}

	function AdvancedPanel( { post, setMeta } ) {
		const st = useStore();
		const seo = st.seo;
		const flag = ( field ) => {
			const v = fieldValue( field, post, seo );
			return v === true || v === '1' || v === 1;
		};
		const val = ( field ) => String( fieldValue( field, post, seo ) || '' );
		const children = [];

		if ( editable( 'canonical', seo ) ) {
			children.push(
				el( C.TextControl, {
					key: 'canonical',
					type: 'url',
					label: __( 'Canonical URL', 'searchpilot-pro' ),
					value: val( 'canonical' ),
					placeholder: post.link || '',
					help: cfg.caps && cfg.caps.manage ? __( 'Leave empty to use this page’s own URL (recommended).', 'searchpilot-pro' ) : __( 'Leave empty to use this page’s own URL (recommended). Only URLs of this site are accepted.', 'searchpilot-pro' ),
					onChange: ( v ) => setMeta( 'canonical', v ),
				} )
			);
		} else {
			children.push( el( ReadOnly, { key: 'canonical', label: __( 'Canonical URL', 'searchpilot-pro' ), value: fieldValue( 'canonical', post, seo ), field: 'canonical', seo } ) );
		}

		if ( editable( 'noindex', seo ) ) {
			children.push(
				el( C.SelectControl, {
					key: 'noindex',
					label: __( 'Search engine indexing', 'searchpilot-pro' ),
					value: val( 'noindex' ),
					options: [
						{ value: '', label: __( 'Default for this content type', 'searchpilot-pro' ) },
						{ value: '0', label: __( 'Index (show in search results)', 'searchpilot-pro' ) },
						{ value: '1', label: __( 'Noindex (hide from search results)', 'searchpilot-pro' ) },
					],
					onChange: ( v ) => setMeta( 'noindex', v ),
				} ),
				val( 'noindex' ) === '1' && post.published
					? el( C.Notice, { key: 'noindex-warn', status: 'warning', isDismissible: false }, __( 'This published page will disappear from search results after the next crawl.', 'searchpilot-pro' ) )
					: null,
				el( C.ToggleControl, {
					key: 'nofollow',
					label: __( 'Ask search engines not to follow links (nofollow)', 'searchpilot-pro' ),
					checked: val( 'nofollow' ) === '1',
					onChange: ( v ) => setMeta( 'nofollow', v ? '1' : '' ),
				} )
			);
		} else {
			const robots = seo && seo.robots ? ( seo.robots.directives || [] ).join( ', ' ) : '';
			children.push( el( ReadOnly, { key: 'noindex', label: __( 'Robots', 'searchpilot-pro' ), value: robots || fieldValue( 'noindex', post, seo ), field: 'noindex', seo } ) );
		}

		if ( editable( 'schema_type', seo ) ) {
			children.push(
				el( C.SelectControl, {
					key: 'schema',
					label: __( 'Schema type', 'searchpilot-pro' ),
					value: val( 'schema_type' ),
					options: cfg.schemaTypes || [],
					onChange: ( v ) => setMeta( 'schema_type', v ),
				} ),
				el( C.ToggleControl, {
					key: 'faq',
					label: __( 'Describe Details blocks as an FAQ', 'searchpilot-pro' ),
					help: __( 'The SearchPilot FAQ block is detected automatically. Google shows FAQ rich results only for authoritative government and health websites.', 'searchpilot-pro' ),
					checked: flag( 'faq' ),
					onChange: ( v ) => setMeta( 'faq', v ),
				} )
			);
		}

		children.push(
			editable( 'breadcrumb_title', seo )
				? el( C.TextControl, {
						key: 'bc',
						label: __( 'Breadcrumb title', 'searchpilot-pro' ),
						value: val( 'breadcrumb_title' ),
						placeholder: post.title,
						onChange: ( v ) => setMeta( 'breadcrumb_title', v ),
				  } )
				: el( ReadOnly, { key: 'bc', label: __( 'Breadcrumb title', 'searchpilot-pro' ), value: fieldValue( 'breadcrumb_title', post, seo ), field: 'breadcrumb_title', seo } )
		);

		if ( cfg.hasCategories && editable( 'primary_category', seo ) ) {
			children.push( el( PrimaryCategory, { key: 'primary', post, setMeta } ) );
		}

		if ( editable( 'cornerstone', seo ) ) {
			children.push( el( C.ToggleControl, { key: 'cornerstone', label: __( 'Cornerstone content', 'searchpilot-pro' ), help: __( 'A key page other posts should link to.', 'searchpilot-pro' ), checked: flag( 'cornerstone' ), onChange: ( v ) => setMeta( 'cornerstone', v ) } ) );
		}
		const sitemapOwner = ( cfg.owners || {} ).sitemap || 'searchpilot';
		if ( sitemapOwner === 'searchpilot' ) {
			if ( editable( 'sitemap_exclude', seo ) ) {
				children.push( el( C.ToggleControl, { key: 'sitemap', label: __( 'Exclude from the XML sitemap', 'searchpilot-pro' ), checked: flag( 'sitemap_exclude' ), onChange: ( v ) => setMeta( 'sitemap_exclude', v ) } ) );
			}
		} else {
			children.push(
				el(
					'p',
					{ key: 'sitemap', className: 'spp-ed-note' },
					sitemapOwner === 'off'
						? __( 'XML sitemaps are switched off in SearchPilot → Settings.', 'searchpilot-pro' )
						: sprintf( /* translators: %s: plugin name. */ __( 'Sitemap handled by %s — exclude this page there.', 'searchpilot-pro' ), ( cfg.storeNames || {} )[ sitemapOwner ] || sitemapOwner )
				)
			);
		}
		if ( editable( 'links_exclude', seo ) ) {
			children.push( el( C.ToggleControl, { key: 'links', label: __( 'Exclude from automatic internal links', 'searchpilot-pro' ), checked: flag( 'links_exclude' ), onChange: ( v ) => setMeta( 'links_exclude', v ) } ) );
		}
		if ( ! permitted( 'canonical' ) ) {
			children.unshift( el( 'p', { key: 'restricted-note', className: 'spp-ed-note' }, __( 'Indexing, canonical and structured data settings can be changed by editors and administrators.', 'searchpilot-pro' ) ) );
		}

		if ( cfg.indexingApi && editable( 'indexing_api', seo ) ) {
			children.push(
				el( C.SelectControl, {
					key: 'indexing_api',
					label: __( 'Google Indexing API', 'searchpilot-pro' ),
					help: __( 'Google allows the Indexing API only for job postings and livestream videos.', 'searchpilot-pro' ),
					value: val( 'indexing_api' ),
					options: [
						{ value: '', label: __( 'Not eligible (normal page)', 'searchpilot-pro' ) },
						{ value: 'JobPosting', label: __( 'Job posting', 'searchpilot-pro' ) },
						{ value: 'BroadcastEvent', label: __( 'Livestream (BroadcastEvent)', 'searchpilot-pro' ) },
					],
					onChange: ( v ) => setMeta( 'indexing_api', v ),
				} )
			);
		}
		return el( C.PanelBody, { title: __( 'Advanced', 'searchpilot-pro' ), initialOpen: false }, children );
	}

	function LinksBody( { post } ) {
		const [ state, reload ] = useRemote( '/links/suggestions?post_id=' + post.id + '&direction=outbound&status=pending&per_page=10', [ post.id ] );
		const [ copied, setCopied ] = useState( 0 );
		let list;
		if ( state.status === 'loading' ) {
			list = el( Loading );
		} else if ( state.status === 'forbidden' ) {
			list = el( 'p', { className: 'spp-ed-empty' }, ROLE_NOTE );
		} else if ( state.status === 'unavailable' ) {
			list = el( 'p', { className: 'spp-ed-empty' }, __( 'Link suggestions are not available on this site yet.', 'searchpilot-pro' ) );
		} else if ( state.status === 'error' ) {
			list = el( C.Notice, { status: 'warning', isDismissible: false }, state.error );
		} else {
			const items = ( state.data && state.data.items ) || [];
			list = items.length
				? el(
						'ul',
						{ className: 'spp-ed-list' },
						items.map( ( s ) =>
							el(
								'li',
								{ key: s.id },
								el( 'div', { className: 'spp-ed-item-title' }, toText( s.target_title ) ),
								el( 'div', { className: 'spp-ed-item-text' }, sprintf( /* translators: %s: anchor text. */ __( 'Anchor: “%s”', 'searchpilot-pro' ), s.anchor ) ),
								s.context ? el( 'div', { className: 'spp-ed-item-text' }, '… ' + toText( s.context ) + ' …' ) : null,
								el(
									'div',
									{ className: 'spp-ed-row' },
									el(
										C.Button,
										{
											variant: 'secondary',
											size: 'small',
											onClick: () => copyText( '<a href="' + escapeHtml( s.target_url ) + '">' + escapeHtml( s.anchor ) + '</a>' ).then( () => setCopied( s.id ) ),
										},
										copied === s.id ? __( 'Copied', 'searchpilot-pro' ) : __( 'Copy link', 'searchpilot-pro' )
									),
									s.target_url ? el( C.ExternalLink, { href: s.target_url }, __( 'Open', 'searchpilot-pro' ) ) : null
								)
							)
						)
				  )
				: el( 'p', { className: 'spp-ed-empty' }, __( 'No suggestions right now. Suggestions appear after the content is indexed by SearchPilot.', 'searchpilot-pro' ) );
		}
		return el(
			Fragment,
			null,
			list,
			el( 'p', { className: 'spp-ed-note' }, __( 'Copy a link and paste it where the anchor text appears. Links from older posts to this one (Existing → New) are applied from SearchPilot → Internal Links.', 'searchpilot-pro' ) ),
			el( 'div', { className: 'spp-ed-row' }, cfg.pages && cfg.pages.links ? el( C.ExternalLink, { href: cfg.pages.links }, __( 'SearchPilot → Internal Links', 'searchpilot-pro' ) ) : null, el( C.Button, { variant: 'tertiary', size: 'small', onClick: reload }, __( 'Refresh', 'searchpilot-pro' ) ) )
		);
	}

	function IndexingBody( { post } ) {
		const [ state, reload ] = useRemote( '/indexing/object?object_type=post&object_id=' + post.id, [ post.id ] );
		const [ notice, setNotice ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const notify = () => {
			setBusy( true );
			setNotice( null );
			api( '/engines/submit', { method: 'POST', data: { urls: [ post.link ] } } )
				.then( ( res ) => {
					const queued = res.queued || {};
					const count = Object.keys( queued ).reduce( ( n, k ) => n + ( Number( queued[ k ] ) || 0 ), 0 );
					let status = 'success';
					if ( res.held ) {
						status = 'warning';
					} else if ( ! count ) {
						status = 'info';
					}
					setNotice( { status, text: res.message || ( count ? __( 'Queued for search engines.', 'searchpilot-pro' ) : __( 'Nothing was queued.', 'searchpilot-pro' ) ) } );
					reload();
				} )
				.catch( ( err ) => {
					let text = err.message;
					if ( isForbidden( err ) ) {
						text = __( 'Your role can’t notify search engines — ask an editor.', 'searchpilot-pro' );
					} else if ( isMissing( err ) ) {
						text = __( 'Search engine submission is not available on this site yet.', 'searchpilot-pro' );
					}
					setNotice( { status: 'error', text } );
				} )
				.then( () => setBusy( false ) );
		};
		let body;
		if ( state.status === 'loading' ) {
			body = el( Loading );
		} else if ( state.status === 'forbidden' ) {
			body = el( 'p', { className: 'spp-ed-empty' }, ROLE_NOTE );
		} else if ( state.status === 'unavailable' ) {
			body = el( 'p', { className: 'spp-ed-empty' }, __( 'Indexing status is not available on this site yet.', 'searchpilot-pro' ) );
		} else if ( state.status === 'error' ) {
			body = el( C.Notice, { status: 'warning', isDismissible: false }, state.error );
		} else {
			const row = state.data && state.data.url;
			body = row
				? el(
						'dl',
						{ className: 'spp-ed-dl' },
						el( 'dt', null, __( 'Index status', 'searchpilot-pro' ) ),
						el( 'dd', null, INDEX_STATUS[ row.index_status ] || row.coverage_state || row.index_status || INDEX_STATUS.unknown ),
						row.coverage_state ? el( 'dt', null, __( 'Google says', 'searchpilot-pro' ) ) : null,
						row.coverage_state ? el( 'dd', null, row.coverage_state ) : null,
						el( 'dt', null, __( 'Last submitted', 'searchpilot-pro' ) ),
						el( 'dd', null, whenOr( row.last_submitted, __( 'never', 'searchpilot-pro' ) ) ),
						el( 'dt', null, __( 'Last crawled by Google', 'searchpilot-pro' ) ),
						el( 'dd', null, whenOr( row.last_crawled, __( 'unknown', 'searchpilot-pro' ) ) ),
						el( 'dt', null, __( 'Inspected via Search Console', 'searchpilot-pro' ) ),
						el( 'dd', null, whenOr( row.last_inspected, __( 'not yet', 'searchpilot-pro' ) ) )
				  )
				: el( 'p', { className: 'spp-ed-empty' }, post.published ? __( 'SearchPilot has not checked this URL yet.', 'searchpilot-pro' ) : __( 'Indexing is tracked once the post is published.', 'searchpilot-pro' ) );
		}
		return el(
			Fragment,
			null,
			body,
			notice ? el( C.Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.text ) : null,
			post.published && can( 'edit' ) && state.status !== 'forbidden'
				? el( C.Button, { variant: 'secondary', isBusy: busy, disabled: busy || ! post.link, onClick: notify }, __( 'Notify search engines', 'searchpilot-pro' ) )
				: el(
						'p',
						{ className: 'spp-ed-note' },
						post.published && ! can( 'edit' )
							? __( 'Editors can notify search engines about changes to this page.', 'searchpilot-pro' )
							: __( 'Search engines are notified automatically when you publish (following your automation settings).', 'searchpilot-pro' )
				  )
		);
	}

	function IssuesBody( { post } ) {
		const [ state ] = useRemote( '/issues?object_type=post&object_id=' + post.id + '&per_page=20', [ post.id ] );
		if ( state.status === 'loading' ) {
			return el( Loading );
		}
		if ( state.status === 'forbidden' ) {
			return el( 'p', { className: 'spp-ed-empty' }, ROLE_NOTE );
		}
		if ( state.status === 'unavailable' ) {
			return el( 'p', { className: 'spp-ed-empty' }, __( 'Issues are not available on this site yet.', 'searchpilot-pro' ) );
		}
		if ( state.status === 'error' ) {
			return el( C.Notice, { status: 'warning', isDismissible: false }, state.error );
		}
		const items = ( state.data && state.data.items ) || [];
		if ( ! items.length ) {
			return el( 'p', { className: 'spp-ed-empty' }, __( 'No open issues for this post.', 'searchpilot-pro' ) );
		}
		return el(
			'ul',
			{ className: 'spp-ed-list' },
			items.map( ( issue ) =>
				el(
					'li',
					{ key: issue.id },
					el( 'div', { className: 'spp-ed-item-title' }, el( 'span', { className: 'spp-ed-badge is-' + issue.severity }, SEVERITY[ issue.severity ] || issue.severity ), ' ', toText( issue.label ) ),
					issue.what ? el( 'div', { className: 'spp-ed-item-text' }, toText( issue.what ) ) : null,
					issue.fix ? el( 'div', { className: 'spp-ed-check-fix' }, toText( issue.fix ) ) : null
				)
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Sidebar & pre-publish
	 * ------------------------------------------------------------------ */

	function useSetMeta( post ) {
		const { editPost } = useDispatch( 'core/editor' );
		return ( field, value ) => {
			const key = KEYS[ field ];
			if ( ! key ) {
				return;
			}
			if ( isDirect( post ) ) {
				store.set( { pending: Object.assign( {}, store.pending, { [ field ]: value } ) } );
				return;
			}
			editPost( { meta: { [ key ]: value } } );
		};
	}

	function Sidebar() {
		const post = usePost();
		const setMeta = useSetMeta( post );
		const st = useStore();
		const companion = st.seo && st.seo.companion && st.seo.companion.name;
		const pendingCount = Object.keys( st.pending || {} ).length;
		return el(
			'div',
			{ className: 'spp-ed' },
			el( Header ),
			companion
				? el(
						'div',
						{ style: { padding: '12px 16px 0' } },
						el( C.Notice, { status: 'info', isDismissible: false }, sprintf( /* translators: %s: SEO plugin name. */ __( 'Companion mode: %s outputs the fields it owns. SearchPilot shows them read-only here so nothing is duplicated.', 'searchpilot-pro' ), companion ) )
				  )
				: null,
			st.seoError ? el( 'div', { style: { padding: '12px 16px 0' } }, el( C.Notice, { status: 'warning', isDismissible: false }, st.seoError ) ) : null,
			isDirect( post ) && pendingCount
				? el(
						'div',
						{ style: { padding: '12px 16px 0' } },
						el(
							C.Notice,
							{ status: 'info', isDismissible: false },
							__( 'SEO changes are saved with the post, or right away:', 'searchpilot-pro' ),
							' ',
							el( C.Button, { variant: 'link', isBusy: st.savingPending, onClick: () => savePending( post.id ) }, __( 'Save SEO settings', 'searchpilot-pro' ) )
						)
				  )
				: null,
			el( AppearancePanel, { post, setMeta } ),
			el( AnalysisPanel ),
			el( SocialPanel, { post, setMeta } ),
			el( AdvancedPanel, { post, setMeta } ),
			can( 'view' ) ? el( C.PanelBody, { title: __( 'Internal link suggestions', 'searchpilot-pro' ), initialOpen: false }, el( LinksBody, { post } ) ) : null,
			can( 'view' ) ? el( C.PanelBody, { title: __( 'Indexing', 'searchpilot-pro' ), initialOpen: false }, el( IndexingBody, { post } ) ) : null,
			can( 'view' ) ? el( C.PanelBody, { title: __( 'Open issues', 'searchpilot-pro' ), initialOpen: false }, el( IssuesBody, { post } ) ) : null
		);
	}

	function PrePublish() {
		const st = useStore();
		const a = st.analysis;
		if ( st.analysisStatus === 'unavailable' ) {
			return el( 'p', { className: 'spp-ed-empty' }, __( 'Content analysis is not available yet.', 'searchpilot-pro' ) );
		}
		if ( st.analysisStatus === 'forbidden' ) {
			return el( 'p', { className: 'spp-ed-empty' }, __( 'Your role can’t use the content analysis — ask an editor.', 'searchpilot-pro' ) );
		}
		if ( ! a ) {
			return el( Loading );
		}
		const rank = { fail: 0, warning: 1 };
		const problems = ( a.checks || [] )
			.filter( ( c ) => c.status === 'fail' || c.status === 'warning' )
			.sort( ( x, y ) => rank[ x.status ] - rank[ y.status ] || ( Number( y.weight ) || 0 ) - ( Number( x.weight ) || 0 ) )
			.slice( 0, 3 );
		return el(
			'div',
			{ className: 'spp-ed' },
			el(
				'div',
				{ className: 'spp-ed-row', style: { marginBottom: '8px' } },
				el( ScoreRing, { score: a.score, grade: a.grade, size: 48 } ),
				el( 'div', null, el( 'div', { className: 'spp-ed-grade' }, GRADE[ a.grade ] || a.grade ), el( 'p', { className: 'spp-ed-disclaimer' }, __( 'Internal diagnostic, not a Google ranking.', 'searchpilot-pro' ) ) )
			),
			problems.length ? problems.map( ( c ) => el( CheckRow, { check: c, key: c.id } ) ) : el( 'p', { className: 'spp-ed-empty' }, __( 'No problems found. Nice work!', 'searchpilot-pro' ) )
		);
	}

	const pluginIcon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20', width: 20, height: 20, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zm2.6 1.6-1.3 4.2-4.2 1.3 1.3-4.2 4.2-1.3z' } )
	);

	function Plugin() {
		return el(
			Fragment,
			null,
			el( Runner ),
			PluginSidebarMoreMenuItem ? el( PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: pluginIcon }, __( 'SearchPilot SEO', 'searchpilot-pro' ) ) : null,
			el( PluginSidebar, { name: SIDEBAR, title: __( 'SearchPilot SEO', 'searchpilot-pro' ), icon: pluginIcon }, el( Sidebar ) ),
			PluginPrePublishPanel ? el( PluginPrePublishPanel, { title: __( 'SearchPilot SEO', 'searchpilot-pro' ), initialOpen: true, icon: pluginIcon }, el( PrePublish ) ) : null
		);
	}

	wp.plugins.registerPlugin( 'searchpilot-seo', { icon: pluginIcon, render: Plugin } );
} )( window.wp, window.SearchPilotEditor );

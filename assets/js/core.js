/**
 * SearchPilot Pro — admin UI framework (no build step).
 *
 * window.SPP provides:
 *   SPP.h(tag, props, ...children)   DOM builder (text is always escaped; never use innerHTML with data)
 *   SPP.mount(el, ...nodes)          replace an element's children
 *   SPP.region(render)               independently refreshable part: { el, refresh() }
 *   SPP.api.get/post/put/del         REST client for /wp-json/searchpilot/v1
 *   SPP.fmt.*                        number/percent/position/date/relative/delta formatting
 *   SPP.ui.*                         components (card, kpi, table, tabs, modal, toast, issue, charts…)
 *   SPP.registerView(name, { mount(el, ctx) })
 *
 * @package SearchPilot
 */
( function ( wp, boot ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const SPP = ( window.SPP = window.SPP || {} );
	SPP.boot = boot || {};
	SPP.views = SPP.views || {};

	/* ------------------------------------------------------------------ *
	 * DOM
	 * ------------------------------------------------------------------ */

	const SVG_NS = 'http://www.w3.org/2000/svg';
	const SVG_TAGS = new Set( [ 'svg', 'path', 'g', 'line', 'polyline', 'polygon', 'rect', 'circle', 'text', 'defs', 'linearGradient', 'stop', 'title', 'tspan', 'clipPath', 'ellipse' ] );

	function append( el, children ) {
		for ( const child of children.flat( Infinity ) ) {
			if ( child === null || child === undefined || child === false || child === true ) {
				continue;
			}
			el.appendChild( child instanceof Node ? child : document.createTextNode( String( child ) ) );
		}
		return el;
	}

	/**
	 * Creates an element. Props: class (string|array), style (object), on* (listener),
	 * dataset (object), ref (fn), value/checked/disabled/selected, other attributes.
	 */
	function h( tag, props, ...children ) {
		const isSvg = SVG_TAGS.has( tag );
		const el = isSvg ? document.createElementNS( SVG_NS, tag ) : document.createElement( tag );
		if ( props && ( typeof props !== 'object' || props instanceof Node || Array.isArray( props ) ) ) {
			children.unshift( props );
			props = null;
		}
		if ( props ) {
			for ( const key of Object.keys( props ) ) {
				const value = props[ key ];
				if ( value === null || value === undefined || value === false ) {
					continue;
				}
				if ( key === 'class' || key === 'className' ) {
					const cls = Array.isArray( value ) ? value.filter( Boolean ).join( ' ' ) : value;
					if ( cls ) {
						el.setAttribute( 'class', cls );
					}
				} else if ( key === 'style' && typeof value === 'object' ) {
					Object.assign( el.style, value );
				} else if ( key.slice( 0, 2 ) === 'on' && typeof value === 'function' ) {
					el.addEventListener( key.slice( 2 ).toLowerCase(), value );
				} else if ( key === 'dataset' ) {
					Object.assign( el.dataset, value );
				} else if ( key === 'ref' && typeof value === 'function' ) {
					value( el );
				} else if ( ! isSvg && ( key === 'value' || key === 'checked' || key === 'disabled' || key === 'selected' || key === 'readOnly' || key === 'indeterminate' ) ) {
					el[ key ] = key === 'value' ? value : !! value;
				} else {
					el.setAttribute( key, value === true ? '' : String( value ) );
				}
			}
		}
		return append( el, children );
	}

	SPP.h = h;
	SPP.mount = function ( el, ...nodes ) {
		el.replaceChildren();
		return append( el, nodes );
	};
	SPP.frag = function ( ...nodes ) {
		return append( document.createDocumentFragment(), nodes );
	};

	/**
	 * A part of the page that can re-render itself. render() may return a node,
	 * an array, or a Promise of those (a loader shows meanwhile).
	 */
	SPP.region = function ( render, opts ) {
		const el = h( 'div', { class: [ 'spp-region', opts && opts.class ] } );
		const region = {
			el,
			refresh( ...args ) {
				let out;
				try {
					out = render( ...args );
				} catch ( e ) {
					SPP.mount( el, SPP.ui.errorBox( e ) );
					return Promise.resolve( region );
				}
				if ( out && typeof out.then === 'function' ) {
					if ( ! el.childNodes.length || ( opts && opts.loader ) ) {
						SPP.mount( el, SPP.ui.loading() );
					} else {
						el.classList.add( 'is-refreshing' );
					}
					return out.then(
						( node ) => {
							el.classList.remove( 'is-refreshing' );
							SPP.mount( el, node );
							return region;
						},
						( err ) => {
							el.classList.remove( 'is-refreshing' );
							SPP.mount( el, SPP.ui.errorBox( err ) );
							return region;
						}
					);
				}
				SPP.mount( el, out );
				return Promise.resolve( region );
			},
		};
		region.refresh();
		return region;
	};

	/* ------------------------------------------------------------------ *
	 * Utilities
	 * ------------------------------------------------------------------ */

	SPP.util = {
		debounce( fn, wait ) {
			let t;
			return function ( ...args ) {
				clearTimeout( t );
				t = setTimeout( () => fn.apply( this, args ), wait || 300 );
			};
		},
		sleep( ms ) {
			return new Promise( ( r ) => setTimeout( r, ms ) );
		},
		download( filename, content, mime ) {
			const blob = content instanceof Blob ? content : new Blob( [ content ], { type: mime || 'text/plain;charset=utf-8' } );
			const url = URL.createObjectURL( blob );
			const a = h( 'a', { href: url, download: filename } );
			document.body.appendChild( a );
			a.click();
			setTimeout( () => {
				URL.revokeObjectURL( url );
				a.remove();
			}, 500 );
		},
		toCsv( rows, columns ) {
			const esc = ( v ) => {
				const s = v === null || v === undefined ? '' : String( v );
				// Neutralize spreadsheet formula injection.
				const safe = /^[=+\-@\t\r]/.test( s ) ? "'" + s : s;
				return /[",\n\r]/.test( safe ) ? '"' + safe.replace( /"/g, '""' ) + '"' : safe;
			};
			const head = columns.map( ( c ) => esc( c.label ) ).join( ',' );
			const body = rows.map( ( r ) => columns.map( ( c ) => esc( typeof c.value === 'function' ? c.value( r ) : r[ c.key ] ) ).join( ',' ) );
			return '﻿' + [ head ].concat( body ).join( '\r\n' );
		},
		copy( text ) {
			if ( navigator.clipboard && window.isSecureContext ) {
				return navigator.clipboard.writeText( text );
			}
			const ta = h( 'textarea', { style: { position: 'fixed', opacity: '0' } } );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
			} finally {
				ta.remove();
			}
			return Promise.resolve();
		},
		uid( prefix ) {
			return ( prefix || 'spp' ) + '-' + Math.random().toString( 36 ).slice( 2, 9 );
		},
	};

	/** URL query-string state (bookmarkable filters/tabs). */
	SPP.query = {
		get( key, fallback ) {
			const v = new URLSearchParams( window.location.search ).get( key );
			return v === null ? ( fallback === undefined ? '' : fallback ) : v;
		},
		set( values ) {
			const url = new URL( window.location.href );
			Object.keys( values ).forEach( ( k ) => {
				const v = values[ k ];
				if ( v === null || v === undefined || v === '' ) {
					url.searchParams.delete( k );
				} else {
					url.searchParams.set( k, v );
				}
			} );
			window.history.replaceState( null, '', url.toString() );
		},
	};

	/* ------------------------------------------------------------------ *
	 * REST API
	 * ------------------------------------------------------------------ */

	function buildPath( path, query ) {
		let url = '/' + ( SPP.boot.namespace || 'searchpilot/v1' ) + ( path.charAt( 0 ) === '/' ? path : '/' + path );
		if ( query ) {
			const params = new URLSearchParams();
			Object.keys( query ).forEach( ( k ) => {
				const v = query[ k ];
				if ( v === null || v === undefined || v === '' ) {
					return;
				}
				params.set( k, Array.isArray( v ) ? v.join( ',' ) : String( v ) );
			} );
			const qs = params.toString();
			if ( qs ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs;
			}
		}
		return url;
	}

	function normalizeError( err ) {
		const e = new Error( ( err && err.message ) || __( 'The request failed. Check your connection and try again.', 'searchpilot-pro' ) );
		e.code = ( err && err.code ) || 'request_failed';
		e.status = err && err.data && err.data.status;
		e.data = err && err.data;
		return e;
	}

	SPP.api = {
		request( method, path, data, query ) {
			const opts = { path: buildPath( path, query ), method };
			if ( data !== undefined && method !== 'GET' ) {
				opts.data = data;
			}
			return apiFetch( opts ).catch( ( err ) => {
				throw normalizeError( err );
			} );
		},
		get( path, query ) {
			return SPP.api.request( 'GET', path, undefined, query );
		},
		post( path, data ) {
			return SPP.api.request( 'POST', path, data || {} );
		},
		put( path, data ) {
			return SPP.api.request( 'PUT', path, data || {} );
		},
		del( path, data ) {
			return SPP.api.request( 'DELETE', path, data );
		},
	};

	/* ------------------------------------------------------------------ *
	 * Formatting
	 * ------------------------------------------------------------------ */

	const locale = ( SPP.boot.locale || 'en_US' ).replace( '_', '-' );
	const nf = ( opts ) => {
		try {
			return new Intl.NumberFormat( locale, opts );
		} catch ( e ) {
			return new Intl.NumberFormat( 'en-US', opts );
		}
	};

	/**
	 * Parses server dates. All SearchPilot timestamps are UTC; strings without a
	 * zone ("2026-09-30T02:51:52" from mysql_to_rfc3339, or "2026-09-30 02:51:52")
	 * are read as UTC. Plain dates ("2026-09-30") are calendar days (local noon).
	 */
	function parseDate( value ) {
		if ( value instanceof Date ) {
			return value;
		}
		let str = String( value ).trim();
		if ( /^\d{4}-\d{2}-\d{2}$/.test( str ) ) {
			return new Date( str + 'T12:00:00' );
		}
		if ( /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test( str ) ) {
			str = str.replace( ' ', 'T' ) + 'Z';
		}
		return new Date( str );
	}
	SPP.parseDate = parseDate;

	SPP.fmt = {
		number( n, digits ) {
			if ( n === null || n === undefined || n === '' || isNaN( n ) ) {
				return '—';
			}
			return nf( { maximumFractionDigits: digits === undefined ? 0 : digits } ).format( Number( n ) );
		},
		compact( n ) {
			if ( n === null || n === undefined || isNaN( n ) ) {
				return '—';
			}
			return Math.abs( n ) < 10000 ? SPP.fmt.number( n ) : nf( { notation: 'compact', maximumFractionDigits: 1 } ).format( Number( n ) );
		},
		/** Fraction (0.034) → "3.4%". */
		percent( v, digits ) {
			if ( v === null || v === undefined || isNaN( v ) ) {
				return '—';
			}
			return nf( { style: 'percent', maximumFractionDigits: digits === undefined ? 1 : digits, minimumFractionDigits: 0 } ).format( Number( v ) );
		},
		position( p ) {
			if ( ! p || isNaN( p ) ) {
				return '—';
			}
			return nf( { maximumFractionDigits: 1, minimumFractionDigits: 1 } ).format( Number( p ) );
		},
		date( iso, opts ) {
			if ( ! iso ) {
				return '—';
			}
			const d = parseDate( iso );
			if ( isNaN( d ) ) {
				return '—';
			}
			return d.toLocaleDateString( locale, opts || { year: 'numeric', month: 'short', day: 'numeric' } );
		},
		datetime( iso ) {
			if ( ! iso ) {
				return '—';
			}
			const d = parseDate( iso );
			return isNaN( d ) ? '—' : d.toLocaleString( locale, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
		},
		relative( iso ) {
			if ( ! iso ) {
				return __( 'never', 'searchpilot-pro' );
			}
			const d = parseDate( iso );
			if ( isNaN( d ) ) {
				return '—';
			}
			const diff = ( d.getTime() - Date.now() ) / 1000;
			const abs = Math.abs( diff );
			const units = [
				[ 60, 'second', 1 ],
				[ 3600, 'minute', 60 ],
				[ 86400, 'hour', 3600 ],
				[ 2592000, 'day', 86400 ],
				[ 31536000, 'month', 2592000 ],
				[ Infinity, 'year', 31536000 ],
			];
			for ( const [ limit, unit, div ] of units ) {
				if ( abs < limit ) {
					try {
						return new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } ).format( Math.round( diff / div ), unit );
					} catch ( e ) {
						return SPP.fmt.datetime( iso );
					}
				}
			}
			return SPP.fmt.datetime( iso );
		},
		/** { pct, dir: up|down|flat } comparing current to previous. */
		delta( current, previous ) {
			current = Number( current ) || 0;
			previous = Number( previous ) || 0;
			if ( ! previous ) {
				return { pct: current ? null : 0, dir: current ? 'up' : 'flat' };
			}
			const pct = ( current - previous ) / Math.abs( previous );
			return { pct, dir: Math.abs( pct ) < 0.005 ? 'flat' : pct > 0 ? 'up' : 'down' };
		},
		bytes( n ) {
			if ( ! n ) {
				return '0 B';
			}
			const u = [ 'B', 'KB', 'MB', 'GB' ];
			const i = Math.min( u.length - 1, Math.floor( Math.log( n ) / Math.log( 1024 ) ) );
			return SPP.fmt.number( n / Math.pow( 1024, i ), i ? 1 : 0 ) + ' ' + u[ i ];
		},
		duration( ms ) {
			if ( ms === null || ms === undefined ) {
				return '—';
			}
			return ms < 1000 ? Math.round( ms ) + ' ms' : SPP.fmt.number( ms / 1000, 1 ) + ' s';
		},
		/** Human-readable URL (percent-decoding, e.g. Kannada slugs). */
		url( u ) {
			if ( ! u ) {
				return '';
			}
			try {
				return decodeURI( u );
			} catch ( e ) {
				return u;
			}
		},
		/** URL path only for compact tables. */
		path( u ) {
			try {
				const url = new URL( u, SPP.boot.homeUrl );
				return SPP.fmt.url( url.pathname + url.search ) || '/';
			} catch ( e ) {
				return SPP.fmt.url( u );
			}
		},
	};

	/* ------------------------------------------------------------------ *
	 * Icons (inline SVG paths, 20×20)
	 * ------------------------------------------------------------------ */

	const ICONS = {
		search: 'M8.5 3a5.5 5.5 0 0 1 4.4 8.8l3.6 3.6-1.1 1.1-3.6-3.6A5.5 5.5 0 1 1 8.5 3zm0 1.5a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
		check: 'M8 13.6 4.4 10l1.1-1.1L8 11.4l6.5-6.5 1.1 1.1z',
		alert: 'M10 2.5 18 16.5H2zm0 3.2L4.6 15h10.8zM9.2 8.5h1.6v3.7H9.2zm0 4.5h1.6v1.6H9.2z',
		info: 'M10 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zm0 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM9.2 9h1.6v5H9.2zm0-3h1.6v1.6H9.2z',
		refresh: 'M10 3.5a6.5 6.5 0 0 1 6.2 4.5H14l2.8 3.5 2.7-3.5h-1.8A8 8 0 1 0 17 14.2l-1.3-.9A6.5 6.5 0 1 1 10 3.5z',
		external: 'M11 3h6v6h-1.5V5.6l-6.2 6.2-1.1-1.1 6.2-6.2H11zM4 5h5v1.5H5.5v8h8V11H15v5H4z',
		copy: 'M7 3h9v11H7zm1.5 1.5v8h6v-8zM4 6h1.5v8.5H12V16H4z',
		undo: 'M8 4v2.3h3.5a5 5 0 0 1 0 10H6v-1.5h5.5a3.5 3.5 0 0 0 0-7H8V10L4 7z',
		bolt: 'M11 2 4 11h5l-1 7 7-9h-5z',
		link: 'M8.6 11.4a3 3 0 0 1 0-4.2l2.4-2.4a3 3 0 0 1 4.2 4.2l-1 1-1.1-1.1 1-1a1.5 1.5 0 0 0-2.1-2.1L9.7 8.2a1.5 1.5 0 0 0 0 2.1zm2.8-2.8a3 3 0 0 1 0 4.2L9 15.2A3 3 0 0 1 4.8 11l1-1 1.1 1.1-1 1a1.5 1.5 0 0 0 2.1 2.1l2.4-2.4a1.5 1.5 0 0 0 0-2.1z',
		chart: 'M3 16h14v1.5H3zM4 9h2.5v6H4zm4.5-4H11v10H8.5zM13 11h2.5v4H13z',
		globe: 'M10 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zm-1.4 1.6A6.5 6.5 0 0 0 3.6 9.2h3a12 12 0 0 1 2-5.6zm2.8 0a12 12 0 0 1 2 5.6h3a6.5 6.5 0 0 0-5-5.6zM10 3.9a10.6 10.6 0 0 0-1.9 5.3h3.8A10.6 10.6 0 0 0 10 3.9zM3.6 10.8a6.5 6.5 0 0 0 5 5.6 12 12 0 0 1-2-5.6zm4.5 0a10.6 10.6 0 0 0 1.9 5.3 10.6 10.6 0 0 0 1.9-5.3zm5.3 0a12 12 0 0 1-2 5.6 6.5 6.5 0 0 0 5-5.6z',
		map: 'M3 4.5 7.5 3l5 1.5L17 3v12.5L12.5 17l-5-1.5L3 17zm1.5 1v9.3l2.3-.8V4.7zm3.8-.8V14l3.4 1V5.7zm4.9 1v9.3l2.3-.8V4.9z',
		file: 'M5 2h7l4 4v12H5zm1.5 1.5v13h8V7h-3.5V3.5zm6 .6V5.5h1.4z',
		cog: 'M9 2h2l.4 2.1 1.4.6 1.8-1.2 1.4 1.4-1.2 1.8.6 1.4L18 9v2l-2.1.4-.6 1.4 1.2 1.8-1.4 1.4-1.8-1.2-1.4.6L11 18H9l-.4-2.1-1.4-.6-1.8 1.2L4 15.1l1.2-1.8-.6-1.4L2 11V9l2.1-.4.6-1.4L3.5 5.4 4.9 4l1.8 1.2 1.4-.6zm1 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
		rocket: 'M16.5 3.5c-3.5 0-6.3 1.8-8 4.6L5.6 8.4 3.5 11l3 .6 2 2 .6 3 2.6-2.1.3-2.9c2.8-1.7 4.5-4.5 4.5-8.1zM12.8 6.2a1.3 1.3 0 1 1 0 2.6 1.3 1.3 0 0 1 0-2.6zM5 14c-1 1-1.5 3-1.5 3s2-.5 3-1.5z',
		hand: 'M8 3.5a1 1 0 0 1 2 0V9h.5V4.5a1 1 0 0 1 2 0V9h.5V6a1 1 0 0 1 2 0v6a5.5 5.5 0 0 1-9.9 3.3L3 12.1a1 1 0 0 1 1.6-1.2L6 12.5V5.5a1 1 0 0 1 2 0V9h0z',
		eye: 'M10 5c4.2 0 7.3 3.3 8 5-.7 1.7-3.8 5-8 5s-7.3-3.3-8-5c.7-1.7 3.8-5 8-5zm0 1.5c-3 0-5.4 2.2-6.3 3.5.9 1.3 3.3 3.5 6.3 3.5s5.4-2.2 6.3-3.5c-.9-1.3-3.3-3.5-6.3-3.5zM10 8a2 2 0 1 1 0 4 2 2 0 0 1 0-4z',
		close: 'M5.1 4 10 8.9 14.9 4 16 5.1 11.1 10l4.9 4.9-1.1 1.1-4.9-4.9L5.1 16 4 14.9 8.9 10 4 5.1z',
		plus: 'M9.2 4h1.6v5.2H16v1.6h-5.2V16H9.2v-5.2H4V9.2h5.2z',
		download: 'M9.2 3h1.6v8.1l2.9-2.9 1.1 1.1L10 14.1 5.2 9.3l1.1-1.1 2.9 2.9zM4 15.5h12V17H4z',
		trash: 'M7.5 3h5l.5 1.5H16V6H4V4.5h3zM5.5 7H7v9h6V7h1.5v10.5h-9z',
		shield: 'M10 2 17 4.5V9c0 4.4-3 7.7-7 9-4-1.3-7-4.6-7-9V4.5zm0 1.6L4.5 5.6V9c0 3.5 2.3 6.2 5.5 7.4 3.2-1.2 5.5-3.9 5.5-7.4V5.6z',
		sparkle: 'M10 2l1.6 4.4L16 8l-4.4 1.6L10 14l-1.6-4.4L4 8l4.4-1.6zM15.5 13l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z',
	};

	SPP.icon = function ( name, size ) {
		const path = ICONS[ name ] || ICONS.info;
		const px = size || 16;
		return h( 'svg', { viewBox: '0 0 20 20', width: px, height: px, fill: 'currentColor', 'aria-hidden': 'true', focusable: 'false' }, h( 'path', { d: path } ) );
	};

	/* ------------------------------------------------------------------ *
	 * Components
	 * ------------------------------------------------------------------ */

	const SEVERITY_LABELS = {
		critical: __( 'Critical', 'searchpilot-pro' ),
		high: __( 'High', 'searchpilot-pro' ),
		medium: __( 'Medium', 'searchpilot-pro' ),
		opportunity: __( 'Opportunity', 'searchpilot-pro' ),
		passed: __( 'Passed', 'searchpilot-pro' ),
	};
	const SEVERITY_ORDER = [ 'critical', 'high', 'medium', 'opportunity', 'passed' ];

	const ui = ( SPP.ui = {} );
	ui.SEVERITY_LABELS = SEVERITY_LABELS;
	ui.SEVERITY_ORDER = SEVERITY_ORDER;

	ui.pageHeader = function ( { title, subtitle, actions, badge } ) {
		return h(
			'div',
			{ class: 'spp-page-header' },
			h( 'div', { class: 'spp-grow' }, h( 'h2', { class: 'spp-page-title' }, title, badge || null ), subtitle ? h( 'p', { class: 'spp-page-subtitle' }, subtitle ) : null ),
			actions && actions.length ? h( 'div', { class: 'spp-page-actions' }, actions ) : null
		);
	};

	ui.card = function ( { title, subtitle, actions, body, footer, className, flush, accent } ) {
		return h(
			'section',
			{ class: [ 'spp-card', accent && 'is-accent', className ] },
			title || actions
				? h(
						'header',
						{ class: 'spp-card-header' },
						h( 'div', { class: 'spp-grow' }, title ? h( 'h3', { class: 'spp-card-title' }, title ) : null, subtitle ? h( 'div', { class: 'spp-card-subtitle' }, subtitle ) : null ),
						actions ? h( 'div', { class: 'spp-card-actions' }, actions ) : null
				  )
				: null,
			h( 'div', { class: [ 'spp-card-body', flush && 'is-flush' ] }, body ),
			footer ? h( 'footer', { class: 'spp-card-footer' }, footer ) : null
		);
	};

	ui.grid = function ( cols, ...children ) {
		return h( 'div', { class: [ 'spp-grid', cols ? 'cols-' + cols : '' ] }, children );
	};

	ui.stack = function ( ...children ) {
		return h( 'div', { class: 'spp-stack' }, children );
	};

	ui.row = function ( ...children ) {
		return h( 'div', { class: 'spp-row' }, children );
	};

	ui.trend = function ( delta, opts ) {
		opts = opts || {};
		if ( ! delta || delta.pct === undefined ) {
			return null;
		}
		const invert = opts.good === 'down';
		let tone = 'is-flat';
		if ( delta.dir === 'up' ) {
			tone = invert ? 'is-bad' : 'is-good';
		} else if ( delta.dir === 'down' ) {
			tone = invert ? 'is-good' : 'is-bad';
		}
		const arrow = delta.dir === 'up' ? '▲' : delta.dir === 'down' ? '▼' : '■';
		let text;
		if ( opts.absolute !== undefined ) {
			text = opts.absolute;
		} else if ( delta.pct === null ) {
			text = __( 'new', 'searchpilot-pro' );
		} else {
			text = SPP.fmt.percent( Math.abs( delta.pct ), Math.abs( delta.pct ) < 0.1 ? 1 : 0 );
		}
		return h( 'span', { class: [ 'spp-trend', tone ], title: opts.title || __( 'Compared with the previous period', 'searchpilot-pro' ) }, h( 'span', { 'aria-hidden': 'true', style: { fontSize: '9px' } }, arrow ), text );
	};

	/**
	 * KPI tile. trend: extra options for ui.trend (e.g. { absolute: '−1.2' } to show an absolute change).
	 */
	ui.kpi = function ( { label, value, delta, good, hint, spark, tone, icon, sparkColor, title, trend } ) {
		return h(
			'div',
			{ class: [ 'spp-kpi', tone && 'is-' + tone ], title: title || null },
			h( 'div', { class: 'spp-kpi-label' }, icon ? SPP.icon( icon, 14 ) : null, label ),
			h( 'div', { class: 'spp-kpi-value' }, value === undefined || value === null ? '—' : value ),
			h(
				'div',
				{ class: 'spp-kpi-foot' },
				delta ? ui.trend( delta, Object.assign( { good }, trend || {} ) ) : hint ? h( 'span', { class: 'spp-kpi-hint' }, hint ) : h( 'span' ),
				spark && spark.length > 1 ? ui.sparkline( spark, { color: sparkColor } ) : null
			),
			delta && hint ? h( 'div', { class: 'spp-kpi-hint' }, hint ) : null
		);
	};

	ui.badge = function ( text, tone, opts ) {
		return h( 'span', { class: [ 'spp-badge', tone && 'is-' + tone ], title: opts && opts.title }, opts && opts.dot ? h( 'span', { class: [ 'spp-dot', 'is-' + tone ] } ) : null, text );
	};

	ui.severity = function ( severity ) {
		return ui.badge( SEVERITY_LABELS[ severity ] || severity, severity, { dot: true } );
	};

	ui.dot = function ( tone, title ) {
		return h( 'span', { class: [ 'spp-dot', tone && 'is-' + tone ], title: title || null } );
	};

	/**
	 * Button. onClick may return a Promise: the button shows a spinner until it settles.
	 */
	ui.button = function ( { label, onClick, variant, size, icon, disabled, href, title, busyLabel, type, target } ) {
		const cls = [ 'spp-btn', variant && 'is-' + variant, size && 'is-' + size ];
		const content = [ icon ? SPP.icon( icon ) : null, label ? h( 'span', { class: 'spp-btn-label' }, label ) : null ];
		if ( href ) {
			return h( 'a', { class: cls, href, title, target: target || null, rel: target === '_blank' ? 'noopener noreferrer' : null }, content );
		}
		const btn = h( 'button', { class: cls, type: type || 'button', disabled: !! disabled, title: title || null }, content );
		if ( onClick ) {
			btn.addEventListener( 'click', ( e ) => {
				if ( btn.disabled ) {
					return;
				}
				const result = onClick( e );
				if ( result && typeof result.then === 'function' ) {
					const original = Array.from( btn.childNodes );
					btn.disabled = true;
					SPP.mount( btn, h( 'span', { class: [ 'spp-spinner', variant === 'primary' || variant === 'gold' ? 'is-light' : '' ] } ), busyLabel || label || '' );
					const restore = () => {
						btn.disabled = !! disabled;
						SPP.mount( btn, original );
					};
					result.then( restore, ( err ) => {
						restore();
						if ( err && err.message && ! err.silent ) {
							ui.toast( err.message, 'error' );
						}
					} );
				}
			} );
		}
		return btn;
	};

	ui.buttonGroup = function ( { options, value, onChange } ) {
		const wrap = h( 'div', { class: 'spp-btn-group', role: 'group' } );
		const render = ( current ) => {
			SPP.mount(
				wrap,
				options.map( ( o ) =>
					h(
						'button',
						{
							type: 'button',
							class: o.value === current ? 'is-active' : '',
							'aria-pressed': o.value === current ? 'true' : 'false',
							onClick: () => {
								render( o.value );
								onChange( o.value );
							},
						},
						o.label
					)
				)
			);
		};
		render( value );
		return wrap;
	};

	ui.rangePicker = function ( { value, onChange, ranges } ) {
		const labels = {
			'7d': __( '7 days', 'searchpilot-pro' ),
			'28d': __( '28 days', 'searchpilot-pro' ),
			'3m': __( '3 months', 'searchpilot-pro' ),
			'6m': __( '6 months', 'searchpilot-pro' ),
			'12m': __( '12 months', 'searchpilot-pro' ),
		};
		return ui.buttonGroup( {
			options: ( ranges || [ '7d', '28d', '3m', '6m', '12m' ] ).map( ( r ) => ( { value: r, label: labels[ r ] || r } ) ),
			value,
			onChange,
		} );
	};

	ui.tabs = function ( { tabs, active, onChange } ) {
		const nav = h( 'nav', { class: 'spp-tabs', role: 'tablist' } );
		const render = ( current ) => {
			SPP.mount(
				nav,
				tabs.map( ( t ) =>
					h(
						'button',
						{
							type: 'button',
							role: 'tab',
							class: [ 'spp-tab', t.id === current && 'is-active' ],
							'aria-selected': t.id === current ? 'true' : 'false',
							onClick: () => {
								render( t.id );
								onChange( t.id );
							},
						},
						t.label,
						t.count !== undefined && t.count !== null ? h( 'span', { class: 'spp-count' }, SPP.fmt.compact( t.count ) ) : null
					)
				)
			);
		};
		render( active );
		return nav;
	};

	ui.toolbar = function ( ...children ) {
		return h( 'div', { class: 'spp-toolbar' }, children );
	};

	ui.search = function ( { value, onSearch, placeholder, wait } ) {
		const input = h( 'input', { type: 'search', class: 'spp-input', value: value || '', placeholder: placeholder || __( 'Search…', 'searchpilot-pro' ), 'aria-label': placeholder || __( 'Search', 'searchpilot-pro' ) } );
		const fire = SPP.util.debounce( () => onSearch( input.value.trim() ), wait || 350 );
		input.addEventListener( 'input', fire );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				onSearch( input.value.trim() );
			}
		} );
		return h( 'div', { class: 'spp-search spp-grow' }, SPP.icon( 'search' ), input );
	};

	ui.select = function ( { options, value, onChange, label, inline } ) {
		const sel = h(
			'select',
			{ class: [ 'spp-select', inline !== false && 'is-inline' ], 'aria-label': label || null, onChange: ( e ) => onChange( e.target.value ) },
			options.map( ( o ) => h( 'option', { value: o.value, selected: String( o.value ) === String( value ) }, o.label ) )
		);
		return sel;
	};

	ui.link = function ( text, href, opts ) {
		const external = opts && opts.external;
		return h( 'a', { href, target: external ? '_blank' : null, rel: external ? 'noopener noreferrer' : null, class: opts && opts.class }, text, external ? h( 'span', { style: { marginLeft: '3px', verticalAlign: '-2px', display: 'inline-flex' } }, SPP.icon( 'external', 12 ) ) : null );
	};

	ui.urlCell = function ( url, title ) {
		return h( 'div', null, title ? h( 'span', { class: 'spp-cell-title', title } , title ) : null, h( 'a', { class: title ? 'spp-cell-sub' : 'spp-cell-title', href: url, target: '_blank', rel: 'noopener noreferrer', title: SPP.fmt.url( url ) }, SPP.fmt.path( url ) ) );
	};

	/**
	 * Table. columns: [{ key, label, render(row), sortable, align: 'right'|'center'|'num', width, className }]
	 */
	ui.table = function ( { columns, rows, empty, sort, onSort, selectable, selected, onSelect, dense, rowClass } ) {
		const sel = new Set( selected || [] );
		const headCells = [];
		if ( selectable ) {
			const all = h( 'input', { type: 'checkbox', 'aria-label': __( 'Select all', 'searchpilot-pro' ), checked: rows.length && rows.every( ( r ) => sel.has( r.id ) ) } );
			all.addEventListener( 'change', () => {
				rows.forEach( ( r ) => ( all.checked ? sel.add( r.id ) : sel.delete( r.id ) ) );
				onSelect && onSelect( Array.from( sel ) );
				tbody.querySelectorAll( 'input[type=checkbox]' ).forEach( ( cb ) => ( cb.checked = all.checked ) );
			} );
			headCells.push( h( 'th', { style: { width: '32px' } }, all ) );
		}
		columns.forEach( ( c ) => {
			const sorted = sort && sort.key === c.key;
			headCells.push(
				h(
					'th',
					{
						class: [ c.sortable && 'is-sortable', sorted && 'is-sorted', c.align && 'is-' + c.align ],
						style: c.width ? { width: c.width } : null,
						onClick: c.sortable && onSort ? () => onSort( { key: c.key, dir: sorted && sort.dir === 'desc' ? 'asc' : 'desc' } ) : null,
						'aria-sort': sorted ? ( sort.dir === 'asc' ? 'ascending' : 'descending' ) : null,
					},
					c.sortable && onSort
						? h(
								'button',
								{
									type: 'button',
									class: 'spp-th-sort',
									onClick: ( e ) => {
										e.stopPropagation();
										onSort( { key: c.key, dir: sorted && sort.dir === 'desc' ? 'asc' : 'desc' } );
									},
								},
								c.label,
								h( 'span', { class: 'spp-sort', 'aria-hidden': 'true' }, sorted ? ( sort.dir === 'asc' ? '↑' : '↓' ) : '↕' )
						  )
						: [ c.label, c.sortable ? h( 'span', { class: 'spp-sort', 'aria-hidden': 'true' }, sorted ? ( sort.dir === 'asc' ? '↑' : '↓' ) : '↕' ) : null ]
				)
			);
		} );
		const tbody = h( 'tbody' );
		if ( ! rows.length ) {
			tbody.appendChild( h( 'tr', null, h( 'td', { colspan: columns.length + ( selectable ? 1 : 0 ), class: 'spp-table-empty' }, empty || __( 'Nothing to show yet.', 'searchpilot-pro' ) ) ) );
		}
		rows.forEach( ( row ) => {
			const cells = [];
			if ( selectable ) {
				const cb = h( 'input', { type: 'checkbox', checked: sel.has( row.id ), 'aria-label': __( 'Select row', 'searchpilot-pro' ) } );
				cb.addEventListener( 'change', () => {
					cb.checked ? sel.add( row.id ) : sel.delete( row.id );
					onSelect && onSelect( Array.from( sel ) );
				} );
				cells.push( h( 'td', null, cb ) );
			}
			columns.forEach( ( c ) => {
				const content = c.render ? c.render( row ) : row[ c.key ];
				cells.push( h( 'td', { class: [ c.align && 'is-' + c.align, c.className ] }, content === undefined || content === null || content === '' ? '—' : content ) );
			} );
			tbody.appendChild( h( 'tr', { class: rowClass ? rowClass( row ) : null }, cells ) );
		} );
		return h( 'div', { class: 'spp-table-wrap' }, h( 'table', { class: [ 'spp-table', dense && 'is-dense' ] }, h( 'thead', null, h( 'tr', null, headCells ) ), tbody ) );
	};

	ui.pagination = function ( { page, pages, total, onPage, perPage } ) {
		if ( ! total ) {
			return null;
		}
		const from = ( page - 1 ) * ( perPage || 25 ) + 1;
		const to = Math.min( total, page * ( perPage || 25 ) );
		return h(
			'div',
			{ class: 'spp-pagination' },
			h( 'span', null, sprintf( /* translators: 1: first row, 2: last row, 3: total. */ __( '%1$s–%2$s of %3$s', 'searchpilot-pro' ), SPP.fmt.number( from ), SPP.fmt.number( to ), SPP.fmt.number( total ) ) ),
			pages > 1
				? h(
						'div',
						{ class: 'spp-pagination-buttons' },
						ui.button( { label: __( 'Previous', 'searchpilot-pro' ), size: 'small', disabled: page <= 1, onClick: () => onPage( page - 1 ) } ),
						h( 'span', null, sprintf( /* translators: 1: page, 2: pages. */ __( 'Page %1$s of %2$s', 'searchpilot-pro' ), page, pages ) ),
						ui.button( { label: __( 'Next', 'searchpilot-pro' ), size: 'small', disabled: page >= pages, onClick: () => onPage( page + 1 ) } )
				  )
				: null
		);
	};

	/**
	 * Server-paged table bound to an endpoint.
	 * opts: { path, query (object, mutable), columns, perPage, empty, selectable, onSelect, map(items), onLoaded(data), dense, rowClass }
	 * Returns { el, reload(resetPage), setQuery(patch) }.
	 */
	ui.dataTable = function ( opts ) {
		const state = { page: 1, sort: opts.sort || null, selected: [] };
		const query = Object.assign( {}, opts.query || {} );
		const wrap = h( 'div', { class: 'spp-datatable' } );
		const load = () => {
			const q = Object.assign( {}, query, { page: state.page, per_page: opts.perPage || 25 } );
			if ( state.sort ) {
				q.orderby = state.sort.key;
				q.order = state.sort.dir;
			}
			if ( ! wrap.childNodes.length ) {
				SPP.mount( wrap, ui.loading() );
			} else {
				wrap.style.opacity = '0.6';
			}
			return SPP.api
				.get( opts.path, q )
				.then( ( data ) => {
					wrap.style.opacity = '';
					// The last row of the last page was removed: step back to the new last page.
					if ( state.page > 1 && data.pages && state.page > data.pages && ! ( data.items || [] ).length ) {
						state.page = data.pages;
						return load();
					}
					const items = opts.map ? opts.map( data.items || [], data ) : data.items || [];
					SPP.mount(
						wrap,
						ui.table( {
							columns: opts.columns,
							rows: items,
							empty: opts.empty,
							sort: state.sort,
							dense: opts.dense,
							rowClass: opts.rowClass,
							onSort: ( s ) => {
								state.sort = s;
								state.page = 1;
								load();
							},
							selectable: opts.selectable,
							selected: state.selected,
							onSelect: ( ids ) => {
								state.selected = ids;
								opts.onSelect && opts.onSelect( ids );
							},
						} ),
						ui.pagination( {
							page: data.page || state.page,
							pages: data.pages || 1,
							total: data.total || 0,
							perPage: opts.perPage || 25,
							onPage: ( p ) => {
								state.page = p;
								load();
							},
						} )
					);
					opts.onLoaded && opts.onLoaded( data );
					return data;
				} )
				.catch( ( err ) => {
					wrap.style.opacity = '';
					SPP.mount( wrap, ui.errorBox( err ) );
				} );
		};
		load();
		return {
			el: wrap,
			reload( resetPage ) {
				if ( resetPage ) {
					state.page = 1;
				}
				if ( state.selected.length ) {
					state.selected = [];
					opts.onSelect && opts.onSelect( [] );
				}
				return load();
			},
			setQuery( patch ) {
				Object.assign( query, patch );
				// An explicit sort from a toolbar replaces a column-header sort.
				if ( patch && ( 'orderby' in patch || 'order' in patch ) ) {
					state.sort = null;
				}
				state.page = 1;
				if ( state.selected.length ) {
					state.selected = [];
					opts.onSelect && opts.onSelect( [] );
				}
				return load();
			},
			query,
			selected: () => state.selected.slice(),
		};
	};

	ui.loading = function ( text ) {
		return h( 'div', { class: 'spp-loading is-center' }, h( 'span', { class: 'spp-spinner' } ), text || __( 'Loading…', 'searchpilot-pro' ) );
	};

	ui.skeleton = function ( lines ) {
		const out = [];
		for ( let i = 0; i < ( lines || 3 ); i++ ) {
			out.push( h( 'span', { class: 'spp-skeleton', style: { width: 60 + ( ( i * 37 ) % 40 ) + '%' } } ) );
		}
		return h( 'div', null, out );
	};

	ui.empty = function ( { icon, title, text, action } ) {
		return h( 'div', { class: 'spp-empty' }, h( 'div', { class: 'spp-empty-icon' }, SPP.icon( icon || 'sparkle', 24 ) ), title ? h( 'div', { class: 'spp-empty-title' }, title ) : null, text ? h( 'p', { class: 'spp-empty-text' }, text ) : null, action || null );
	};

	ui.notice = function ( { tone, title, text, actions, icon } ) {
		const iconName = icon || ( tone === 'critical' || tone === 'high' || tone === 'warning' ? 'alert' : tone === 'passed' || tone === 'success' ? 'check' : 'info' );
		return h(
			'div',
			{ class: [ 'spp-notice', tone && 'is-' + tone ], role: tone === 'critical' ? 'alert' : null },
			h( 'span', { class: 'spp-notice-icon' }, SPP.icon( iconName, 18 ) ),
			h( 'div', { class: 'spp-grow' }, title ? h( 'div', { class: 'spp-notice-title' }, title ) : null, text ? h( 'div', { class: 'spp-notice-text' }, text ) : null, actions ? h( 'div', { class: 'spp-row', style: { marginTop: '10px' } }, actions ) : null )
		);
	};

	ui.errorBox = function ( err ) {
		return ui.notice( { tone: 'critical', title: __( 'Could not load this section', 'searchpilot-pro' ), text: ( err && err.message ) || String( err ) } );
	};

	ui.progress = function ( { value, max, tone, label, right } ) {
		const pct = max ? Math.max( 0, Math.min( 100, ( value / max ) * 100 ) ) : 0;
		return h(
			'div',
			null,
			label || right ? h( 'div', { class: 'spp-progress-label' }, h( 'span', null, label || '' ), h( 'span', null, right || '' ) ) : null,
			h( 'div', { class: [ 'spp-progress', tone && 'is-' + tone ], role: 'progressbar', 'aria-valuenow': Math.round( pct ), 'aria-valuemin': 0, 'aria-valuemax': 100 }, h( 'div', { class: 'spp-progress-bar', style: { width: pct + '%' } } ) )
		);
	};

	ui.dl = function ( pairs ) {
		return h(
			'dl',
			{ class: 'spp-dl' },
			pairs.filter( Boolean ).map( ( [ term, value ] ) => [ h( 'dt', null, term ), h( 'dd', null, value === undefined || value === null || value === '' ? '—' : value ) ] )
		);
	};

	ui.code = function ( text ) {
		return h( 'pre', { class: 'spp-code' }, text );
	};

	ui.copyField = function ( text ) {
		return h(
			'div',
			{ class: 'spp-inline-code' },
			h( 'span', null, text ),
			ui.button( {
				icon: 'copy',
				size: 'small',
				variant: 'ghost',
				title: __( 'Copy', 'searchpilot-pro' ),
				onClick: () => SPP.util.copy( text ).then( () => ui.toast( __( 'Copied to clipboard', 'searchpilot-pro' ) ) ),
			} )
		);
	};

	/* ---------- Modal & toast ---------- */

	const modalStack = [];
	ui.modal = function ( { title, body, actions, size, onClose } ) {
		const previous = document.activeElement;
		let closed = false;
		const close = () => {
			if ( closed ) {
				return;
			}
			closed = true;
			root.remove();
			document.removeEventListener( 'keydown', onKey );
			const i = modalStack.indexOf( root );
			if ( i !== -1 ) {
				modalStack.splice( i, 1 );
			}
			onClose && onClose();
			if ( previous && previous.focus ) {
				previous.focus();
			}
		};
		const onKey = ( e ) => {
			// Only the top-most modal reacts (a confirm over a details modal closes alone).
			if ( modalStack[ modalStack.length - 1 ] !== root ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				e.stopPropagation();
				close();
				return;
			}
			if ( e.key === 'Tab' ) {
				const items = Array.from( dialog.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ) ).filter( ( n ) => null !== n.offsetParent );
				if ( ! items.length ) {
					e.preventDefault();
					return;
				}
				const first = items[ 0 ];
				const last = items[ items.length - 1 ];
				if ( e.shiftKey && ( document.activeElement === first || ! dialog.contains( document.activeElement ) ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && ( document.activeElement === last || ! dialog.contains( document.activeElement ) ) ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		const titleId = SPP.util.uid( 'spp-modal-title' );
		const dialog = h(
			'div',
			{ class: [ 'spp-modal', size && 'is-' + size ], role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
			h( 'div', { class: 'spp-modal-header' }, h( 'h2', { class: 'spp-modal-title', id: titleId }, title ), h( 'button', { class: 'spp-modal-close', type: 'button', 'aria-label': __( 'Close', 'searchpilot-pro' ), onClick: close }, '×' ) ),
			h( 'div', { class: 'spp-modal-body' }, typeof body === 'function' ? body( close ) : body ),
			actions ? h( 'div', { class: 'spp-modal-footer' }, typeof actions === 'function' ? actions( close ) : actions ) : null
		);
		let pressedBackdrop = false;
		const root = h(
			'div',
			{
				class: 'spp-modal-root',
				onMousedown: ( e ) => {
					pressedBackdrop = e.target === root;
				},
				// Close on a backdrop click only if the press also started there (not a text-selection drag).
				onClick: ( e ) => {
					if ( e.target === root && pressedBackdrop ) {
						close();
					}
					pressedBackdrop = false;
				},
			},
			dialog
		);
		document.body.appendChild( root );
		modalStack.push( root );
		document.addEventListener( 'keydown', onKey );
		const focusable = dialog.querySelector( 'input, textarea, select, button:not(.spp-modal-close)' );
		( focusable || dialog.querySelector( '.spp-modal-close' ) ).focus();
		return { close, el: dialog };
	};

	ui.confirm = function ( { title, message, confirmLabel, danger } ) {
		return new Promise( ( resolve ) => {
			let decided = false;
			const m = ui.modal( {
				title: title || __( 'Are you sure?', 'searchpilot-pro' ),
				size: 'small',
				body: h( 'p', null, message ),
				onClose: () => ! decided && resolve( false ),
				actions: ( close ) => [
					ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ),
					ui.button( {
						label: confirmLabel || __( 'Continue', 'searchpilot-pro' ),
						variant: danger ? 'danger' : 'primary',
						onClick: () => {
							decided = true;
							resolve( true );
							m.close();
						},
					} ),
				],
			} );
		} );
	};

	let toastRoot = null;
	/**
	 * Toast. opts: { action: { label, onClick } } adds a button (e.g. Undo); the toast then stays 8 s.
	 */
	ui.toast = function ( message, tone, timeout, opts ) {
		if ( timeout && typeof timeout === 'object' ) {
			opts = timeout;
			timeout = 0;
		}
		opts = opts || {};
		if ( ! toastRoot ) {
			toastRoot = h( 'div', { class: 'spp-toasts', 'aria-live': 'polite' } );
			document.body.appendChild( toastRoot );
		}
		const t = h( 'div', { class: [ 'spp-toast', tone && tone !== 'success' && 'is-' + tone ], role: tone === 'error' ? 'alert' : 'status' }, h( 'span', { class: 'spp-dot' } ), h( 'span', { class: 'spp-grow' }, message ) );
		if ( opts.action && opts.action.label ) {
			t.appendChild(
				h(
					'button',
					{
						type: 'button',
						class: 'spp-toast-action',
						onClick: () => {
							t.remove();
							opts.action.onClick && opts.action.onClick();
						},
					},
					opts.action.label
				)
			);
		}
		toastRoot.appendChild( t );
		setTimeout( () => t.remove(), timeout || ( opts.action ? 8000 : tone === 'error' ? 7000 : 3500 ) );
	};

	/* ---------- Form fields ---------- */

	const field = ( ui.field = {} );

	function fieldWrap( { label, help, error, row, control, wide, id } ) {
		if ( row ) {
			return h(
				'div',
				{ class: 'spp-field is-row' },
				h( 'div', { class: 'spp-field-text' }, label ? h( 'label', { class: 'spp-field-label', for: id || null }, label ) : null, help ? h( 'p', { class: 'spp-field-help' }, help ) : null, error ? h( 'div', { class: 'spp-field-error' }, error ) : null ),
				h( 'div', { class: [ 'spp-field-control', wide && 'is-wide' ] }, control )
			);
		}
		return h( 'div', { class: 'spp-field' }, label ? h( 'label', { class: 'spp-field-label', for: id || null }, label ) : null, control, help ? h( 'p', { class: 'spp-field-help' }, help ) : null, error ? h( 'div', { class: 'spp-field-error' }, error ) : null );
	}

	field.text = function ( { label, value, onInput, placeholder, help, type, row, counter, maxLength, id, readOnly, wide, error } ) {
		const fid = id || SPP.util.uid( 'spp-f' );
		const input = h( 'input', { id: fid, type: type || 'text', class: 'spp-input', value: value === undefined || value === null ? '' : value, placeholder: placeholder || '', maxlength: maxLength || null, readOnly: !! readOnly } );
		let count = null;
		if ( counter ) {
			count = h( 'span', { class: 'spp-counter' } );
			const update = () => {
				const n = [ ...input.value ].length;
				count.textContent = sprintf( /* translators: %d: characters. */ __( '%d characters', 'searchpilot-pro' ), n );
				count.className = 'spp-counter ' + ( n >= counter[ 0 ] && n <= counter[ 1 ] ? 'is-good' : n ? 'is-warn' : '' );
			};
			input.addEventListener( 'input', update );
			update();
		}
		if ( onInput ) {
			input.addEventListener( 'input', () => onInput( input.value ) );
		}
		return fieldWrap( { label: count ? h( 'span', { class: 'spp-row is-between spp-grow' }, label, count ) : label, help, row, control: input, wide, id: fid, error } );
	};

	field.textarea = function ( { label, value, onInput, placeholder, help, rows, code, row, counter, id, wide } ) {
		const fid = id || SPP.util.uid( 'spp-f' );
		const ta = h( 'textarea', { id: fid, class: [ 'spp-textarea', code && 'is-code' ], rows: rows || 4, placeholder: placeholder || '' } );
		ta.value = value || '';
		let count = null;
		if ( counter ) {
			count = h( 'span', { class: 'spp-counter' } );
			const update = () => {
				const n = [ ...ta.value ].length;
				count.textContent = sprintf( /* translators: %d: characters. */ __( '%d characters', 'searchpilot-pro' ), n );
				count.className = 'spp-counter ' + ( n >= counter[ 0 ] && n <= counter[ 1 ] ? 'is-good' : n ? 'is-warn' : '' );
			};
			ta.addEventListener( 'input', update );
			update();
		}
		if ( onInput ) {
			ta.addEventListener( 'input', () => onInput( ta.value ) );
		}
		return fieldWrap( { label: count ? h( 'span', { class: 'spp-row is-between spp-grow' }, label, count ) : label, help, row, control: ta, wide, id: fid } );
	};

	field.number = function ( { label, value, onInput, min, max, step, help, row, suffix } ) {
		const fid = SPP.util.uid( 'spp-f' );
		const input = h( 'input', { id: fid, type: 'number', class: 'spp-input is-inline', style: { width: '110px' }, value: value === undefined ? '' : value, min: min === undefined ? null : min, max: max === undefined ? null : max, step: step || 1 } );
		if ( onInput ) {
			input.addEventListener( 'input', () => onInput( input.value === '' ? '' : Number( input.value ) ) );
		}
		return fieldWrap( { label, help, row, control: suffix ? h( 'span', { class: 'spp-row' }, input, h( 'span', { class: 'spp-muted' }, suffix ) ) : input, id: fid } );
	};

	field.select = function ( { label, value, options, onChange, help, row, wide } ) {
		const fid = SPP.util.uid( 'spp-f' );
		const sel = h(
			'select',
			{ id: fid, class: 'spp-select', onChange: ( e ) => onChange && onChange( e.target.value ) },
			options.map( ( o ) => h( 'option', { value: o.value, selected: String( o.value ) === String( value ), disabled: !! o.disabled }, o.label ) )
		);
		return fieldWrap( { label, help, row, control: sel, wide, id: fid } );
	};

	field.toggle = function ( { label, checked, onChange, help, row, disabled, toggleLabel } ) {
		const input = h( 'input', { type: 'checkbox', checked: !! checked, disabled: !! disabled, role: 'switch' } );
		input.addEventListener( 'change', () => onChange && onChange( input.checked ) );
		const control = h( 'label', { class: 'spp-toggle' }, input, h( 'span', { class: 'spp-toggle-track', 'aria-hidden': 'true' } ), toggleLabel ? h( 'span', null, toggleLabel ) : row ? h( 'span', { class: 'screen-reader-text' }, label ) : h( 'span', null, label ) );
		if ( row ) {
			return fieldWrap( { label, help, row: true, control } );
		}
		return h( 'div', { class: 'spp-field' }, control, help ? h( 'p', { class: 'spp-field-help' }, help ) : null );
	};

	field.checklist = function ( { label, options, values, onChange, help } ) {
		const set = new Set( ( values || [] ).map( String ) );
		const list = h(
			'div',
			{ class: 'spp-checklist' },
			options.map( ( o ) => {
				const cb = h( 'input', { type: 'checkbox', checked: set.has( String( o.value ) ) } );
				cb.addEventListener( 'change', () => {
					cb.checked ? set.add( String( o.value ) ) : set.delete( String( o.value ) );
					onChange( options.filter( ( x ) => set.has( String( x.value ) ) ).map( ( x ) => x.value ) );
				} );
				return h( 'label', { class: 'spp-check' }, cb, o.label );
			} )
		);
		return fieldWrap( { label, help, control: list } );
	};

	/**
	 * Radio cards. onChange may return a Promise: rejected (or resolving to false) reverts the choice.
	 */
	field.radioCards = function ( { options, value, onChange, label } ) {
		const wrap = h( 'div', { class: 'spp-radio-cards', role: 'radiogroup', 'aria-label': label || null } );
		let selected = value;
		const render = ( current ) => {
			SPP.mount(
				wrap,
				options.map( ( o ) =>
					h(
						'button',
						{
							type: 'button',
							role: 'radio',
							'aria-checked': o.value === current ? 'true' : 'false',
							class: [ 'spp-radio-card', o.value === current && 'is-selected' ],
							onClick: () => {
								if ( o.value === selected ) {
									return;
								}
								const before = selected;
								selected = o.value;
								render( o.value );
								const result = onChange( o.value );
								if ( result && typeof result.then === 'function' ) {
									result.then(
										( ok ) => {
											if ( false === ok ) {
												selected = before;
												render( before );
											}
										},
										( err ) => {
											selected = before;
											render( before );
											if ( err && err.message ) {
												ui.toast( err.message, 'error' );
											}
										}
									);
								}
							},
						},
						o.icon ? h( 'span', { class: 'spp-radio-card-icon' }, SPP.icon( o.icon, 18 ) ) : null,
						h( 'span', { class: 'spp-radio-card-title' }, o.label ),
						o.description ? h( 'span', { class: 'spp-radio-card-text' }, o.description ) : null,
						o.extra || null
					)
				)
			);
		};
		render( value );
		return wrap;
	};

	/** Media library picker for an attachment id. */
	field.media = function ( { label, value, url, onChange, help, row } ) {
		const preview = h( 'div', { style: { marginBottom: '8px' } } );
		const renderPreview = ( src ) => SPP.mount( preview, src ? h( 'img', { src, alt: '', style: { maxWidth: '160px', maxHeight: '90px', borderRadius: '8px', border: '1px solid var(--spp-border)' } } ) : null );
		renderPreview( url );
		let current = value;
		const choose = ui.button( {
			label: __( 'Choose image', 'searchpilot-pro' ),
			size: 'small',
			onClick: () => {
				if ( ! window.wp || ! wp.media ) {
					ui.toast( __( 'The media library is not available on this screen.', 'searchpilot-pro' ), 'error' );
					return;
				}
				const frame = wp.media( { title: label, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', () => {
					const att = frame.state().get( 'selection' ).first().toJSON();
					current = att.id;
					renderPreview( ( att.sizes && att.sizes.medium && att.sizes.medium.url ) || att.url );
					onChange( att.id, att );
				} );
				frame.open();
			},
		} );
		const remove = ui.button( {
			label: __( 'Remove', 'searchpilot-pro' ),
			size: 'small',
			variant: 'ghost',
			onClick: () => {
				current = 0;
				renderPreview( '' );
				onChange( 0, null );
			},
		} );
		return fieldWrap( { label, help, row, control: h( 'div', null, preview, h( 'div', { class: 'spp-row' }, choose, remove ) ) } );
	};

	/* ---------- Diff ---------- */

	/**
	 * Word/tag-level diff of two strings: [[op, text], …] with op '=', '-' or '+'.
	 * Inputs above ~1500 tokens each are returned as one removal + one insertion.
	 */
	SPP.util.diffTokens = function ( a, b ) {
		const split = ( s ) => String( s || '' ).match( /<[^>]*>|\s+|[^\s<]+|</g ) || [];
		const x = split( a );
		const y = split( b );
		let pre = 0;
		while ( pre < x.length && pre < y.length && x[ pre ] === y[ pre ] ) {
			pre++;
		}
		let suf = 0;
		while ( suf < x.length - pre && suf < y.length - pre && x[ x.length - 1 - suf ] === y[ y.length - 1 - suf ] ) {
			suf++;
		}
		const out = [];
		const push = ( op, text ) => {
			if ( ! text ) {
				return;
			}
			const last = out[ out.length - 1 ];
			if ( last && last[ 0 ] === op ) {
				last[ 1 ] += text;
			} else {
				out.push( [ op, text ] );
			}
		};
		push( '=', x.slice( 0, pre ).join( '' ) );
		const mx = x.slice( pre, x.length - suf );
		const my = y.slice( pre, y.length - suf );
		if ( mx.length * my.length > 2250000 ) {
			push( '-', mx.join( '' ) );
			push( '+', my.join( '' ) );
		} else {
			const n = mx.length;
			const m = my.length;
			const lcs = [];
			for ( let i = 0; i <= n; i++ ) {
				lcs.push( new Uint16Array( m + 1 ) );
			}
			for ( let i = n - 1; i >= 0; i-- ) {
				for ( let j = m - 1; j >= 0; j-- ) {
					lcs[ i ][ j ] = mx[ i ] === my[ j ] ? lcs[ i + 1 ][ j + 1 ] + 1 : Math.max( lcs[ i + 1 ][ j ], lcs[ i ][ j + 1 ] );
				}
			}
			let i = 0;
			let j = 0;
			while ( i < n && j < m ) {
				if ( mx[ i ] === my[ j ] ) {
					push( '=', mx[ i ] );
					i++;
					j++;
				} else if ( lcs[ i + 1 ][ j ] >= lcs[ i ][ j + 1 ] ) {
					push( '-', mx[ i ] );
					i++;
				} else {
					push( '+', my[ j ] );
					j++;
				}
			}
			push( '-', mx.slice( i ).join( '' ) );
			push( '+', my.slice( j ).join( '' ) );
		}
		push( '=', x.slice( x.length - suf ).join( '' ) );
		return out;
	};

	/**
	 * Before/after diff of one change: { label, before, after, mono }. Removed text is
	 * struck through in "Before", added text highlighted in "After".
	 */
	ui.diff = function ( { label, before, after, mono, note } ) {
		const ops = SPP.util.diffTokens( before, after );
		const pane = ( kind ) =>
			h(
				'div',
				{ class: [ 'spp-diff-pane', 'is-' + kind, mono && 'is-mono' ] },
				h( 'span', { class: 'spp-diff-label' }, kind === 'before' ? __( 'Before', 'searchpilot-pro' ) : __( 'After', 'searchpilot-pro' ) ),
				( kind === 'before' ? before : after ) === ''
					? h( 'em', { class: 'spp-muted' }, __( '(empty)', 'searchpilot-pro' ) )
					: ops.map( ( [ op, text ] ) => {
							if ( op === '=' ) {
								return text;
							}
							if ( kind === 'before' && op === '-' ) {
								return h( 'del', null, text );
							}
							if ( kind === 'after' && op === '+' ) {
								return h( 'ins', null, text );
							}
							return null;
					  } )
			);
		return h(
			'div',
			{ class: 'spp-diff' },
			label || note ? h( 'div', { class: 'spp-diff-head' }, h( 'span', null, label || '' ), note ? h( 'span', { class: 'spp-muted spp-small' }, note ) : null ) : null,
			h( 'div', { class: 'spp-diff-panes' }, pane( 'before' ), pane( 'after' ) )
		);
	};

	/* ---------- Fixes: mode badge, outcome, preview ---------- */

	const MODE_INFO = {
		deterministic: { label: __( 'Safe automatic fix', 'searchpilot-pro' ), tone: 'passed', title: __( 'A precise, rule-based change. Autopilot may apply it unattended; every change is verified and can be undone.', 'searchpilot-pro' ) },
		assisted: { label: __( 'One-click fix', 'searchpilot-pro' ), tone: 'info', title: __( 'SearchPilot prepares the change; you review it and apply it with one click. Never applied unattended.', 'searchpilot-pro' ) },
		advisory: { label: __( 'Manual', 'searchpilot-pro' ), tone: 'outline', title: __( 'No safe automatic fix exists. The next step tells you exactly what to do.', 'searchpilot-pro' ) },
	};
	ui.MODE_INFO = MODE_INFO;

	ui.fixMode = function ( mode ) {
		const info = MODE_INFO[ mode ];
		return info ? ui.badge( info.label, info.tone, { title: info.title } ) : null;
	};

	/** The primary manual action when there is no automatic fix. */
	ui.manualStep = function ( text ) {
		if ( ! text ) {
			return null;
		}
		return h( 'div', { class: 'spp-issue-step' }, h( 'span', { class: 'spp-issue-step-icon' }, SPP.icon( 'hand', 16 ) ), h( 'div', null, h( 'strong', null, __( 'Next step', 'searchpilot-pro' ) ), ' ', text ) );
	};

	/** Undo of one fix (or bulk action) by its activity group. */
	SPP.undoFixGroup = function ( group ) {
		return SPP.api.post( '/issues/fix-groups/' + encodeURIComponent( group ) + '/undo' ).then( ( res ) => {
			if ( res.failed ) {
				ui.toast( ( res.errors && res.errors[ 0 ] ) || __( 'Some changes could not be undone.', 'searchpilot-pro' ), 'warning' );
			} else {
				ui.toast( sprintf( /* translators: %d: number of changes. */ _n( '%d change undone', '%d changes undone', res.undone || 0, 'searchpilot-pro' ), res.undone || 0 ) );
			}
			return res;
		} );
	};

	/**
	 * Outcome of an apply/fix call: { applied, verified (true|false|null), introduced, introduced_labels, error, group_id }.
	 * Returns { tone, title, text } and shows a toast (with Undo when something was changed) unless opts.silent.
	 */
	ui.fixOutcome = function ( res, opts ) {
		opts = opts || {};
		let out;
		if ( res.introduced && res.introduced.length ) {
			out = {
				tone: 'warning',
				title: __( 'Fix undone automatically', 'searchpilot-pro' ),
				text: res.error || sprintf( /* translators: %s: issue labels. */ __( 'The change caused a new problem (%s), so SearchPilot reverted it. Nothing was left changed.', 'searchpilot-pro' ), ( res.introduced_labels || res.introduced ).join( ', ' ) ),
			};
		} else if ( res.applied && res.verified === true ) {
			out = { tone: 'success', title: __( 'Fixed and verified', 'searchpilot-pro' ), text: __( 'A fresh check confirms the problem is gone. The change is in Automation → Activity and can be undone.', 'searchpilot-pro' ) };
		} else if ( res.applied && res.verified === null ) {
			out = { tone: 'info', title: __( 'Applied — verification pending', 'searchpilot-pro' ), text: res.error || __( 'SearchPilot could not re-check the page right now. It will be re-checked automatically.', 'searchpilot-pro' ) };
		} else if ( res.applied ) {
			out = { tone: 'warning', title: __( 'Applied, but not confirmed', 'searchpilot-pro' ), text: res.error || __( 'A fresh check still finds the problem. Review the page manually.', 'searchpilot-pro' ) };
		} else {
			out = { tone: 'error', title: __( 'Not applied', 'searchpilot-pro' ), text: res.error || __( 'The fix could not be applied.', 'searchpilot-pro' ) };
		}
		if ( ! opts.silent ) {
			const undo = res.applied && res.group_id ? { label: __( 'Undo', 'searchpilot-pro' ), onClick: () => SPP.undoFixGroup( res.group_id ).then( () => opts.onUndo && opts.onUndo() ) } : null;
			ui.toast( out.title + ' — ' + out.text, out.tone === 'success' ? 'success' : out.tone === 'error' ? 'error' : out.tone === 'info' ? 'info' : 'warning', undo ? { action: undo } : {} );
		}
		return out;
	};

	/** Changes of a plan as diffs (optionally with a checkbox per change when there are several). */
	ui.planChanges = function ( plan, opts ) {
		opts = opts || {};
		const changes = plan.changes || [];
		const chosen = new Set( changes.map( ( c ) => c.signature ) );
		const list = h(
			'div',
			{ class: 'spp-stack' },
			changes.map( ( c ) => {
				const diff = ui.diff( { label: c.label, before: c.before, after: c.after, mono: c.kind === 'post_content' || c.kind === 'option' || c.kind === 'post_meta' } );
				if ( ! opts.selectable || changes.length < 2 ) {
					return diff;
				}
				const cb = h( 'input', { type: 'checkbox', checked: true, 'aria-label': c.label } );
				cb.addEventListener( 'change', () => {
					cb.checked ? chosen.add( c.signature ) : chosen.delete( c.signature );
					opts.onSelect && opts.onSelect( Array.from( chosen ) );
				} );
				return h( 'div', { class: 'spp-fix-change' }, h( 'label', { class: 'spp-check' }, cb, __( 'Apply this change', 'searchpilot-pro' ) ), diff );
			} )
		);
		return { el: list, chosen: () => Array.from( chosen ) };
	};

	/**
	 * Fix preview: loads GET /issues/{id}/plan, shows every change as a diff with the
	 * warnings, and applies it (POST /issues/{id}/apply) on confirmation.
	 * opts: { onApplied(result) }
	 */
	ui.fixPreview = function ( issue, opts ) {
		opts = opts || {};
		let planId = '';
		let picker = null;
		let total = 0;
		const applyBtn = ui.button( {
			label: issue.status === 'pending_approval' ? __( 'Approve & apply', 'searchpilot-pro' ) : __( 'Apply fix', 'searchpilot-pro' ),
			icon: 'bolt',
			variant: 'primary',
			disabled: true,
			busyLabel: __( 'Applying and re-checking…', 'searchpilot-pro' ),
			onClick: () => {
				const body = { plan_id: planId };
				if ( picker && total > 1 ) {
					const chosen = picker.chosen();
					if ( ! chosen.length ) {
						ui.toast( __( 'Choose at least one change to apply.', 'searchpilot-pro' ), 'error' );
						return null;
					}
					if ( chosen.length < total ) {
						body.signatures = chosen;
					}
				}
				return SPP.api.post( '/issues/' + issue.id + '/apply', body ).then( ( res ) => {
					modal.close();
					ui.fixOutcome( res, { onUndo: () => opts.onApplied && opts.onApplied( res ) } );
					opts.onApplied && opts.onApplied( res );
					return res;
				} );
			},
		} );
		const body = SPP.region( () =>
			SPP.api.get( '/issues/' + issue.id + '/plan' ).then(
				( res ) => {
					const plan = res.plan || {};
					planId = res.plan_id || '';
					total = ( plan.changes || [] ).length;
					picker = ui.planChanges( plan, { selectable: true } );
					applyBtn.disabled = ! planId;
					return h(
						'div',
						{ class: 'spp-stack' },
						h( 'div', { class: 'spp-row is-between' }, h( 'div', null, h( 'div', { class: 'spp-strong' }, plan.summary || issue.label ), issue.title ? h( 'div', { class: 'spp-muted spp-small' }, issue.title ) : null ), ui.fixMode( plan.mode ) ),
						( plan.warnings || [] ).map( ( w ) => ui.notice( { tone: 'warning', text: w } ) ),
						picker.el,
						h( 'p', { class: 'spp-muted spp-small', style: { margin: 0 } }, __( 'After applying, SearchPilot re-checks the page. If the change causes a new problem it is undone automatically; otherwise it stays in Automation → Activity, where you can undo it.', 'searchpilot-pro' ) )
					);
				},
				( err ) => {
					applyBtn.disabled = true;
					return h( 'div', { class: 'spp-stack' }, ui.notice( { tone: err.status === 409 ? 'warning' : 'critical', title: __( 'This fix cannot be prepared', 'searchpilot-pro' ), text: err.message } ), ui.manualStep( issue.manual_step || issue.fix ) );
				}
			)
		);
		const modal = ui.modal( {
			title: sprintf( /* translators: %s: issue label. */ __( 'Preview fix: %s', 'searchpilot-pro' ), issue.label ),
			size: 'large',
			body: body.el,
			actions: ( close ) => [ ui.button( { label: __( 'Cancel', 'searchpilot-pro' ), onClick: close } ), applyBtn ],
		} );
		return modal;
	};

	/* ---------- Issue card: What happened → Why it matters → Recommended fix → Fix ---------- */

	/**
	 * @param {Object} issue   Issue from /issues (label, severity, what, why, fix, fixable, previewable, fix_label, mode, manual_step, status, url, title, edit_link…).
	 * @param {Object} opts    { onChanged(issue), compact, extraActions: [nodes] }
	 *
	 * The fix button appears only when the issue is `fixable`: previewable fixes open the diff
	 * preview; legacy fixes run directly. Otherwise the manual step is the primary action.
	 */
	ui.issue = function ( issue, opts ) {
		opts = opts || {};
		const expanded = ! opts.compact;
		const body = h(
			'div',
			{ class: [ 'spp-issue-body', ! expanded && 'is-collapsed' ] },
			h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'What happened', 'searchpilot-pro' ) ), h( 'p', null, issue.what || '—' ) ),
			h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'Why it matters', 'searchpilot-pro' ) ), h( 'p', null, issue.why || '—' ) ),
			h( 'div', { class: 'spp-issue-section' }, h( 'h4', null, __( 'Recommended fix', 'searchpilot-pro' ) ), h( 'p', null, issue.fix || '—' ) )
		);
		const act = ( path, successMsg ) =>
			SPP.api.post( '/issues/' + issue.id + '/' + path ).then( ( res ) => {
				if ( path === 'fix' ) {
					ui.fixOutcome( res, { onUndo: () => opts.onChanged && opts.onChanged( res.issue || issue, 'undo' ) } );
				} else {
					ui.toast( successMsg );
				}
				opts.onChanged && opts.onChanged( res.issue || issue, path );
				return res;
			} );
		const open = issue.status !== 'fixed' && issue.status !== 'ignored' && issue.status !== 'resolved';
		const canFix = issue.fixable && open && ( ! SPP.boot.caps || SPP.boot.caps.edit !== false );
		const actions = [];
		if ( canFix && issue.previewable ) {
			actions.push(
				ui.button( {
					label: issue.status === 'pending_approval' ? __( 'Review & approve', 'searchpilot-pro' ) : issue.fix_label || __( 'Preview fix', 'searchpilot-pro' ),
					icon: 'eye',
					variant: 'primary',
					size: 'small',
					onClick: () => ui.fixPreview( issue, { onApplied: ( res ) => opts.onChanged && opts.onChanged( res.issue || issue, 'fix' ) } ),
				} )
			);
		} else if ( canFix ) {
			actions.push(
				ui.button( {
					label: issue.status === 'pending_approval' ? __( 'Approve & fix', 'searchpilot-pro' ) : issue.fix_label || __( 'Fix automatically', 'searchpilot-pro' ),
					icon: 'bolt',
					variant: 'primary',
					size: 'small',
					busyLabel: __( 'Fixing and re-checking…', 'searchpilot-pro' ),
					onClick: () => {
						const run = () => act( 'fix' );
						if ( issue.destructive ) {
							return ui.confirm( { title: issue.label, message: __( 'This change affects how search engines see the page. It is recorded and can be undone.', 'searchpilot-pro' ), confirmLabel: __( 'Apply fix', 'searchpilot-pro' ) } ).then( ( ok ) => ( ok ? run() : null ) );
						}
						return run();
					},
				} )
			);
		}
		if ( issue.edit_link ) {
			actions.push( ui.button( { label: __( 'Edit', 'searchpilot-pro' ), size: 'small', variant: ! canFix && open ? 'primary' : null, href: issue.edit_link } ) );
		}
		if ( issue.url ) {
			actions.push( ui.button( { label: __( 'View', 'searchpilot-pro' ), size: 'small', variant: 'ghost', icon: 'external', href: issue.url, target: '_blank' } ) );
		}
		( opts.extraActions || [] ).forEach( ( a ) => actions.push( a ) );
		if ( issue.status === 'ignored' ) {
			actions.push( ui.button( { label: __( 'Reopen', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => act( 'reopen', __( 'Issue reopened', 'searchpilot-pro' ) ) } ) );
		} else if ( open ) {
			actions.push( ui.button( { label: __( 'Ignore', 'searchpilot-pro' ), size: 'small', variant: 'ghost', onClick: () => act( 'ignore', __( 'Issue ignored', 'searchpilot-pro' ) ) } ) );
		}
		const toggle = opts.compact
			? ui.button( {
					label: __( 'Details', 'searchpilot-pro' ),
					size: 'small',
					variant: 'link',
					onClick: () => body.classList.toggle( 'is-collapsed' ),
			  } )
			: null;
		const statusBadge =
			issue.status === 'pending_approval'
				? ui.badge( __( 'Awaiting approval', 'searchpilot-pro' ), 'gold' )
				: issue.status === 'fixed'
				? ui.badge( __( 'Fixed', 'searchpilot-pro' ), 'passed' )
				: issue.status === 'ignored'
				? ui.badge( __( 'Ignored', 'searchpilot-pro' ), 'outline' )
				: issue.status === 'resolved'
				? ui.badge( __( 'Resolved', 'searchpilot-pro' ), 'passed' )
				: null;
		return h(
			'article',
			{ class: [ 'spp-issue', 'is-' + issue.severity ] },
			h(
				'div',
				{ class: 'spp-issue-head' },
				h(
					'div',
					{ class: 'spp-grow' },
					h( 'div', { class: 'spp-row' }, ui.severity( issue.severity ), h( 'span', { class: 'spp-issue-title' }, issue.label ), statusBadge, open && issue.mode ? ui.fixMode( issue.fixable ? issue.mode : 'advisory' ) : null ),
					issue.title || issue.url ? h( 'div', { class: 'spp-issue-object' }, issue.title || SPP.fmt.url( issue.url ) ) : null,
					issue.declined && open ? h( 'div', { class: 'spp-issue-declined' }, SPP.icon( 'undo', 14 ), __( 'You undid this fix; SearchPilot won’t re-apply it automatically.', 'searchpilot-pro' ) ) : null
				),
				h( 'div', { class: 'spp-issue-meta' }, issue.last_seen_at ? sprintf( /* translators: %s: relative time. */ __( 'Seen %s', 'searchpilot-pro' ), SPP.fmt.relative( issue.last_seen_at ) ) : null )
			),
			body,
			! issue.fixable && open ? ui.manualStep( issue.manual_step || issue.fix ) : null,
			h( 'div', { class: 'spp-issue-actions' }, actions, toggle )
		);
	};

	/** Passed check row (for audits). */
	ui.passed = function ( label, text ) {
		return h( 'article', { class: 'spp-issue is-passed' }, h( 'div', { class: 'spp-row' }, ui.severity( 'passed' ), h( 'span', { class: 'spp-issue-title' }, label ) ), text ? h( 'div', { class: 'spp-issue-object' }, text ) : null );
	};

	/** Check row (status: pass|warning|fail|info). */
	ui.checkRow = function ( check ) {
		const tone = { pass: 'passed', warning: 'high', fail: 'critical', info: 'opportunity' }[ check.status ] || 'navy';
		return h( 'div', { class: 'spp-check-row' }, ui.dot( tone ), h( 'div', { class: 'spp-grow' }, h( 'div', { class: 'spp-check-label' }, check.label ), h( 'div', { class: 'spp-check-text' }, check.message ) ) );
	};

	/* ---------- Charts ---------- */

	const PALETTE = [ '#1b2a4a', '#b08d57', '#2f6fde', '#2e7d32', '#d9480f', '#7b5ea7' ];
	ui.PALETTE = PALETTE;

	ui.scoreRing = function ( score, opts ) {
		opts = opts || {};
		const size = opts.size || 88;
		const stroke = opts.stroke || 9;
		const r = ( size - stroke ) / 2;
		const c = 2 * Math.PI * r;
		const value = Math.max( 0, Math.min( 100, Number( score ) || 0 ) );
		const color = value >= 80 ? '#2e7d32' : value >= 50 ? '#e0a800' : '#c62828';
		return h(
			'div',
			{ class: 'spp-score', title: opts.title || __( 'SearchPilot score: an internal diagnostic, not a search-engine ranking score.', 'searchpilot-pro' ) },
			h(
				'svg',
				{ width: size, height: size, viewBox: `0 0 ${ size } ${ size }` },
				h( 'circle', { cx: size / 2, cy: size / 2, r, fill: 'none', stroke: '#eee9df', 'stroke-width': stroke } ),
				h( 'circle', {
					cx: size / 2,
					cy: size / 2,
					r,
					fill: 'none',
					stroke: color,
					'stroke-width': stroke,
					'stroke-linecap': 'round',
					'stroke-dasharray': `${ ( value / 100 ) * c } ${ c }`,
					transform: `rotate(-90 ${ size / 2 } ${ size / 2 })`,
				} )
			),
			h( 'div', { class: 'spp-score-value', style: { fontSize: Math.round( size / 3.6 ) + 'px' } }, score === null || score === undefined ? '—' : Math.round( value ) ),
			opts.caption ? h( 'div', { class: 'spp-score-caption' }, opts.caption ) : null
		);
	};

	ui.sparkline = function ( values, opts ) {
		opts = opts || {};
		const w = opts.width || 96;
		const hgt = opts.height || 28;
		const vals = values.map( ( v ) => Number( v ) || 0 );
		const max = Math.max( ...vals );
		const min = Math.min( ...vals );
		const range = max - min || 1;
		const step = vals.length > 1 ? w / ( vals.length - 1 ) : w;
		const pts = vals.map( ( v, i ) => [ i * step, hgt - 2 - ( ( v - min ) / range ) * ( hgt - 4 ) ] );
		const line = pts.map( ( p, i ) => ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ) ).join( ' ' );
		const color = opts.color || '#1b2a4a';
		return h(
			'svg',
			{ class: 'spp-sparkline', width: w, height: hgt, viewBox: `0 0 ${ w } ${ hgt }`, 'aria-hidden': 'true' },
			h( 'path', { d: line + ` L${ w } ${ hgt } L0 ${ hgt } Z`, fill: color, opacity: '0.08' } ),
			h( 'path', { d: line, fill: 'none', stroke: color, 'stroke-width': 1.6, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } )
		);
	};

	function niceMax( v ) {
		if ( v <= 0 ) {
			return 1;
		}
		const exp = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		const f = v / exp;
		const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return nice * exp;
	}

	/**
	 * Line chart with hover tooltip.
	 * opts: { labels: ['2026-09-01', …], series: [{ name, values, color, axis: 'left'|'right', format(v), dashed, invert }], height, formatLabel(l) }
	 * Series on the right axis get their own scale (e.g. position, which is inverted: lower is better).
	 */
	ui.lineChart = function ( opts ) {
		const container = h( 'div', { class: 'spp-chart' } );
		const tooltip = h( 'div', { class: 'spp-chart-tooltip' } );
		const hidden = new Set();
		const legend = h( 'div', { class: 'spp-legend' } );
		const svgHolder = h( 'div' );
		container.append( legend, svgHolder, tooltip );
		const labels = opts.labels || [];
		const height = opts.height || 240;
		const fmtLabel = opts.formatLabel || ( ( l ) => SPP.fmt.date( l, { month: 'short', day: 'numeric' } ) );

		const renderLegend = () => {
			SPP.mount(
				legend,
				opts.series.map( ( s, i ) => {
					const color = s.color || PALETTE[ i % PALETTE.length ];
					return h(
						'span',
						{
							class: [ 'spp-legend-item', hidden.has( i ) && 'is-off' ],
							style: { color },
							onClick: () => {
								if ( hidden.has( i ) ) {
									hidden.delete( i );
								} else if ( hidden.size < opts.series.length - 1 ) {
									hidden.add( i );
								}
								renderLegend();
								draw();
							},
						},
						h( 'span', { class: [ 'spp-swatch', s.dashed && 'is-dashed' ], style: { background: color } } ),
						h( 'span', { style: { color: 'var(--spp-text-2)' } }, s.name )
					);
				} )
			);
		};

		const draw = () => {
			const width = Math.max( 280, svgHolder.clientWidth || container.clientWidth || 640 );
			const pad = { top: 10, right: opts.series.some( ( s ) => s.axis === 'right' ) ? 46 : 12, bottom: 26, left: 46 };
			const iw = width - pad.left - pad.right;
			const ih = height - pad.top - pad.bottom;
			const visible = opts.series.map( ( s, i ) => ( { s, i } ) ).filter( ( x ) => ! hidden.has( x.i ) );
			const scaleFor = ( axis ) => {
				const vals = [];
				visible.filter( ( x ) => ( x.s.axis || 'left' ) === axis ).forEach( ( x ) => x.s.values.forEach( ( v ) => v !== null && v !== undefined && vals.push( Number( v ) ) ) );
				const max = niceMax( Math.max( 0, ...vals ) * 1.05 );
				return { max, has: vals.length > 0 };
			};
			const left = scaleFor( 'left' );
			const right = scaleFor( 'right' );
			const n = labels.length;
			const x = ( i ) => pad.left + ( n > 1 ? ( i / ( n - 1 ) ) * iw : iw / 2 );
			const y = ( v, s ) => {
				const sc = ( s.axis || 'left' ) === 'right' ? right : left;
				const ratio = sc.max ? Number( v ) / sc.max : 0;
				return s.invert ? pad.top + ratio * ih : pad.top + ih - ratio * ih;
			};
			const svg = h( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, role: 'img', 'aria-label': opts.title || __( 'Trend chart', 'searchpilot-pro' ) } );
			const ticks = 4;
			for ( let t = 0; t <= ticks; t++ ) {
				const yy = pad.top + ( ih / ticks ) * t;
				svg.appendChild( h( 'line', { class: 'spp-grid-line', x1: pad.left, x2: width - pad.right, y1: yy, y2: yy } ) );
				if ( left.has ) {
					const leftSeries = visible.find( ( v ) => ( v.s.axis || 'left' ) === 'left' );
					const val = left.max * ( 1 - t / ticks );
					svg.appendChild( h( 'text', { class: 'spp-axis-label', x: pad.left - 8, y: yy + 4, 'text-anchor': 'end' }, leftSeries && leftSeries.s.format ? leftSeries.s.format( val, true ) : SPP.fmt.compact( val ) ) );
				}
				if ( right.has ) {
					const rs = visible.find( ( v ) => v.s.axis === 'right' );
					const val = rs && rs.s.invert ? right.max * ( t / ticks ) : right.max * ( 1 - t / ticks );
					svg.appendChild( h( 'text', { class: 'spp-axis-label', x: width - pad.right + 8, y: yy + 4, 'text-anchor': 'start' }, rs && rs.s.format ? rs.s.format( val, true ) : SPP.fmt.compact( val ) ) );
				}
			}
			const every = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( iw / 80 ) ) ) );
			labels.forEach( ( l, i ) => {
				if ( i % every === 0 || i === n - 1 ) {
					svg.appendChild( h( 'text', { class: 'spp-axis-label', x: x( i ), y: height - 6, 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle' }, fmtLabel( l ) ) );
				}
			} );
			visible.forEach( ( { s, i } ) => {
				const color = s.color || PALETTE[ i % PALETTE.length ];
				let d = '';
				let started = false;
				s.values.forEach( ( v, idx ) => {
					if ( v === null || v === undefined ) {
						started = false;
						return;
					}
					d += ( started ? 'L' : 'M' ) + x( idx ).toFixed( 1 ) + ' ' + y( v, s ).toFixed( 1 ) + ' ';
					started = true;
				} );
				if ( ! s.dashed && ( s.axis || 'left' ) === 'left' && visible.length <= 2 ) {
					svg.appendChild( h( 'path', { d: d + `L${ x( n - 1 ) } ${ pad.top + ih } L${ x( 0 ) } ${ pad.top + ih } Z`, fill: color, opacity: '0.06' } ) );
				}
				svg.appendChild( h( 'path', { d, fill: 'none', stroke: color, 'stroke-width': s.dashed ? 1.5 : 2.2, 'stroke-dasharray': s.dashed ? '5 4' : null, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			} );
			const cross = h( 'line', { x1: 0, x2: 0, y1: pad.top, y2: pad.top + ih, stroke: '#b08d57', 'stroke-width': 1, opacity: 0 } );
			svg.appendChild( cross );
			const dots = visible.map( ( { s, i } ) => {
				const dot = h( 'circle', { r: 4, fill: '#fff', stroke: s.color || PALETTE[ i % PALETTE.length ], 'stroke-width': 2, opacity: 0 } );
				svg.appendChild( dot );
				return { dot, s, i };
			} );
			const overlay = h( 'rect', { x: pad.left, y: pad.top, width: iw, height: ih, fill: 'transparent' } );
			svg.appendChild( overlay );
			const onMove = ( evt ) => {
				const rect = svg.getBoundingClientRect();
				const px = ( evt.clientX - rect.left ) * ( width / rect.width );
				const idx = Math.max( 0, Math.min( n - 1, Math.round( ( ( px - pad.left ) / iw ) * ( n - 1 ) ) ) );
				cross.setAttribute( 'x1', x( idx ) );
				cross.setAttribute( 'x2', x( idx ) );
				cross.setAttribute( 'opacity', 0.8 );
				const lines = [ h( 'strong', null, fmtLabel( labels[ idx ] ) ) ];
				dots.forEach( ( { dot, s, i } ) => {
					const v = s.values[ idx ];
					if ( v === null || v === undefined ) {
						dot.setAttribute( 'opacity', 0 );
						return;
					}
					dot.setAttribute( 'cx', x( idx ) );
					dot.setAttribute( 'cy', y( v, s ) );
					dot.setAttribute( 'opacity', 1 );
					lines.push( h( 'div', null, h( 'span', { class: 'spp-swatch', style: { background: s.color || PALETTE[ i % PALETTE.length ] } } ), s.name + ': ', h( 'b', null, s.format ? s.format( v ) : SPP.fmt.number( v ) ) ) );
				} );
				SPP.mount( tooltip, lines );
				tooltip.style.display = 'block';
				tooltip.style.left = ( ( x( idx ) / width ) * rect.width ) + 'px';
				tooltip.style.top = ( legend.offsetHeight + pad.top + 4 ) + 'px';
			};
			overlay.addEventListener( 'mousemove', onMove );
			overlay.addEventListener( 'mouseleave', () => {
				tooltip.style.display = 'none';
				cross.setAttribute( 'opacity', 0 );
				dots.forEach( ( { dot } ) => dot.setAttribute( 'opacity', 0 ) );
			} );
			SPP.mount( svgHolder, svg );
		};

		renderLegend();
		if ( ! labels.length ) {
			SPP.mount( svgHolder, ui.empty( { icon: 'chart', title: __( 'No data for this period yet', 'searchpilot-pro' ), text: opts.emptyText || __( 'Data appears after the first sync with the search engine.', 'searchpilot-pro' ) } ) );
			return container;
		}
		requestAnimationFrame( draw );
		if ( window.ResizeObserver ) {
			let last = 0;
			new ResizeObserver( () => {
				const w = svgHolder.clientWidth;
				if ( Math.abs( w - last ) > 8 ) {
					last = w;
					draw();
				}
			} ).observe( svgHolder );
		}
		return container;
	};

	/** Horizontal bar list: items [{ label, value, sub, href, extra }]. */
	ui.barList = function ( { items, format, empty } ) {
		if ( ! items || ! items.length ) {
			return ui.empty( { icon: 'chart', title: empty || __( 'Nothing here yet', 'searchpilot-pro' ) } );
		}
		const max = Math.max( ...items.map( ( i ) => Number( i.value ) || 0 ), 1 );
		return h(
			'div',
			{ class: 'spp-barlist' },
			items.map( ( it ) =>
				h(
					'div',
					{ class: 'spp-barlist-row' },
					h( 'span', { class: 'spp-barlist-label', title: it.title || it.label }, it.href ? h( 'a', { href: it.href, target: it.external ? '_blank' : null, rel: it.external ? 'noopener noreferrer' : null }, it.label ) : it.label, it.sub ? h( 'span', { class: 'spp-muted spp-small' }, ' · ', it.sub ) : null ),
					h( 'span', { class: 'spp-barlist-value' }, it.extra || null, format ? format( it.value ) : SPP.fmt.number( it.value ) ),
					h( 'div', { class: 'spp-barlist-bar' }, h( 'span', { style: { width: Math.max( 2, ( ( Number( it.value ) || 0 ) / max ) * 100 ) + '%' } } ) )
				)
			)
		);
	};

	/** Donut: segments [{ label, value, color }]. */
	ui.donut = function ( { segments, size, center, caption } ) {
		const s = size || 140;
		const stroke = 16;
		const r = ( s - stroke ) / 2;
		const c = 2 * Math.PI * r;
		const total = segments.reduce( ( a, b ) => a + ( Number( b.value ) || 0 ), 0 ) || 1;
		let offset = 0;
		const arcs = segments.map( ( seg, i ) => {
			const len = ( ( Number( seg.value ) || 0 ) / total ) * c;
			const arc = h( 'circle', { cx: s / 2, cy: s / 2, r, fill: 'none', stroke: seg.color || PALETTE[ i % PALETTE.length ], 'stroke-width': stroke, 'stroke-dasharray': `${ len } ${ c - len }`, 'stroke-dashoffset': -offset, transform: `rotate(-90 ${ s / 2 } ${ s / 2 })` }, h( 'title', null, seg.label + ': ' + SPP.fmt.number( seg.value ) ) );
			offset += len;
			return arc;
		} );
		return h(
			'div',
			{ class: 'spp-row', style: { gap: '18px', alignItems: 'center' } },
			h( 'div', { class: 'spp-score' }, h( 'svg', { width: s, height: s, viewBox: `0 0 ${ s } ${ s }` }, h( 'circle', { cx: s / 2, cy: s / 2, r, fill: 'none', stroke: '#eee9df', 'stroke-width': stroke } ), arcs ), h( 'div', { class: 'spp-score-value', style: { fontSize: '20px', flexDirection: 'column' } }, center || '' ) ),
			h(
				'div',
				{ class: 'spp-legend', style: { flexDirection: 'column', gap: '6px' } },
				segments.map( ( seg, i ) => h( 'span', { class: 'spp-legend-item' }, h( 'span', { class: 'spp-swatch', style: { background: seg.color || PALETTE[ i % PALETTE.length ] } } ), seg.label, h( 'b', { style: { marginLeft: '4px' } }, SPP.fmt.number( seg.value ) ) ) )
			),
			caption ? h( 'div', { class: 'spp-muted spp-small' }, caption ) : null
		);
	};

	/** Google-style result preview. */
	ui.serpPreview = function ( { title, url, description } ) {
		let host = '';
		let path = '';
		try {
			const u = new URL( url || SPP.boot.homeUrl );
			host = u.host;
			path = SPP.fmt.url( u.pathname ).split( '/' ).filter( Boolean ).join( ' › ' );
		} catch ( e ) {
			host = url || '';
		}
		return h(
			'div',
			{ class: 'spp-serp', 'aria-label': __( 'Search result preview', 'searchpilot-pro' ) },
			h( 'div', { class: 'spp-serp-site' }, h( 'span', null, SPP.boot.siteName || host ) ),
			h( 'div', { class: 'spp-serp-url' }, host + ( path ? ' › ' + path : '' ) ),
			h( 'div', { class: 'spp-serp-title' }, title || __( '(no title)', 'searchpilot-pro' ) ),
			h( 'div', { class: 'spp-serp-desc' }, description || __( 'No meta description — Google will pick text from the page.', 'searchpilot-pro' ) )
		);
	};

	ui.stepper = function ( { steps, current, onSelect } ) {
		const idx = steps.findIndex( ( s ) => s.id === current );
		return h(
			'div',
			{ class: 'spp-stepper' },
			steps.map( ( s, i ) =>
				h(
					'button',
					{ type: 'button', class: [ 'spp-step', ( undefined === s.done ? i < idx : s.done ) && 'is-done', i === idx && 'is-current' ], onClick: onSelect ? () => onSelect( s.id ) : null, 'aria-current': i === idx ? 'step' : null },
					h( 'span', { class: 'spp-step-num' }, ( undefined === s.done ? i < idx : s.done ) && i !== idx ? '✓' : String( i + 1 ) ),
					h( 'span', null, s.label )
				)
			)
		);
	};

	/* ------------------------------------------------------------------ *
	 * Shell & views
	 * ------------------------------------------------------------------ */

	const MODE_LABELS = {
		manual: __( 'Manual', 'searchpilot-pro' ),
		assisted: __( 'Assisted', 'searchpilot-pro' ),
		autopilot: __( 'Autopilot', 'searchpilot-pro' ),
	};
	SPP.MODE_LABELS = MODE_LABELS;

	function topbar() {
		const mode = SPP.boot.mode || 'assisted';
		const modeTone = { manual: 'navy', assisted: 'gold', autopilot: 'passed' }[ mode ];
		const right = [];
		if ( SPP.boot.companionName ) {
			right.push( ui.badge( sprintf( /* translators: %s: plugin name. */ __( 'Companion mode with %s', 'searchpilot-pro' ), SPP.boot.companionName ), 'info', { title: __( 'Features your SEO plugin already outputs are left to it — nothing is duplicated.', 'searchpilot-pro' ) } ) );
		}
		if ( ! SPP.boot.submissions && SPP.boot.heldCode !== 'setup' ) {
			right.push( ui.badge( __( 'Search-engine submissions held', 'searchpilot-pro' ), 'high', { dot: true, title: SPP.boot.heldReason } ) );
		}
		right.push(
			h(
				'a',
				{ class: 'spp-mode-pill', href: SPP.boot.pages && SPP.boot.pages.automation, title: __( 'Automation mode', 'searchpilot-pro' ) },
				ui.dot( modeTone ),
				sprintf( /* translators: %s: mode. */ __( '%s mode', 'searchpilot-pro' ), MODE_LABELS[ mode ] || mode )
			)
		);
		return h(
			'div',
			{ class: 'spp-topbar' },
			h(
				'div',
				{ class: 'spp-brand' },
				h(
					'span',
					{ class: 'spp-brand-mark', 'aria-hidden': 'true' },
					h( 'svg', { viewBox: '0 0 20 20' }, h( 'path', { fill: '#fff', d: 'M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z' } ), h( 'path', { fill: '#d9bd8c', d: 'M11.1 5.6 9.8 9.8l-4.2 1.3 1.3-4.2z' } ) )
				),
				h( 'div', null, h( 'div', { class: 'spp-brand-name' }, 'SearchPilot ', h( 'em', null, 'Pro' ) ), h( 'div', { class: 'spp-brand-sub' }, SPP.boot.siteName || '' ) )
			),
			h( 'div', { class: 'spp-topbar-right' }, right )
		);
	}

	SPP.registerView = function ( name, def ) {
		SPP.views[ name ] = def;
	};

	SPP.go = function ( view, params ) {
		const base = SPP.boot.pages && SPP.boot.pages[ view ];
		if ( ! base ) {
			return;
		}
		const url = new URL( base, window.location.href );
		Object.keys( params || {} ).forEach( ( k ) => url.searchParams.set( k, params[ k ] ) );
		window.location.href = url.toString();
	};

	SPP.pageUrl = function ( view, params ) {
		const base = SPP.boot.pages && SPP.boot.pages[ view ];
		if ( ! base ) {
			return '#';
		}
		const url = new URL( base, window.location.href );
		Object.keys( params || {} ).forEach( ( k ) => url.searchParams.set( k, params[ k ] ) );
		return url.toString();
	};

	function start() {
		const root = document.getElementById( 'spp-app' );
		if ( ! root ) {
			return;
		}
		const name = root.getAttribute( 'data-view' );
		const view = SPP.views[ name ];
		const main = h( 'main', { class: 'spp-main' } );
		const children = [];
		if ( name !== 'setup' ) {
			children.push( topbar() );
			if ( ! SPP.boot.wizardCompleted && SPP.boot.caps && SPP.boot.caps.manage ) {
				children.push(
					h(
						'div',
						{ style: { marginBottom: '18px' } },
						ui.notice( {
							tone: 'gold',
							icon: 'rocket',
							title: __( 'Finish setting up SearchPilot', 'searchpilot-pro' ),
							text: __( 'Connect Google Search Console, Bing Webmaster Tools and IndexNow, and choose how much SearchPilot should automate.', 'searchpilot-pro' ),
							actions: [ ui.button( { label: __( 'Open setup wizard', 'searchpilot-pro' ), variant: 'primary', size: 'small', href: SPP.boot.pages.setup } ) ],
						} )
					)
				);
			}
		}
		children.push( main );
		SPP.mount( root, children );
		const bar = root.querySelector( '.spp-topbar' );
		SPP.refreshTopbar = () => {
			const current = root.querySelector( '.spp-topbar' );
			if ( current ) {
				current.replaceWith( topbar() );
			}
		};
		if ( ! bar ) {
			SPP.refreshTopbar = () => {};
		}
		if ( ! view ) {
			SPP.mount( main, ui.notice( { tone: 'critical', title: __( 'This screen failed to load.', 'searchpilot-pro' ), text: __( 'A plugin file is missing. Reinstall SearchPilot Pro.', 'searchpilot-pro' ) } ) );
			return;
		}
		try {
			view.mount( main, { boot: SPP.boot, params: new URLSearchParams( window.location.search ) } );
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( e );
			SPP.mount( main, ui.errorBox( e ) );
		}
	}

	wp.domReady( start );
} )( window.wp, window.SearchPilotBoot );

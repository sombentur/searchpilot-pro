<?php
/**
 * Analyses one fetched page into structured SEO facts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Pure-ish: takes a response that the Crawler already fetched (status,
 * headers, body, redirect hops) and returns facts plus problem codes.
 * It never performs HTTP requests or database queries. The fetched HTML is
 * untrusted data: it is only parsed, never executed or echoed.
 */
class PageInspector {

	/**
	 * HTTP statuses that mean "our self-check was refused" (firewall, basic
	 * auth, bot protection) rather than "the page is broken".
	 */
	const BLOCKED_STATUSES = array( 401, 403, 407, 429 );

	/**
	 * Error codes and their meaning (used by the UI and reports).
	 */
	const CODES = array(
		'fetch_failed',
		'fetch_blocked',
		'http_4xx',
		'http_5xx',
		'redirect',
		'redirect_chain',
		'redirect_loop',
		'too_many_redirects',
		'redirect_external',
		'redirect_to_error',
		'noindex',
		'noindex_missing',
		'robots_blocked',
		'multiple_canonicals',
		'canonical_elsewhere',
		'canonical_relative',
		'canonical_cross_domain',
		'canonical_to_non_200',
		'pagination_canonical',
		'soft_404',
		'not_html',
		'missing_title',
		'multiple_titles',
		'invalid_json_ld',
	);

	/**
	 * Analyses a response.
	 *
	 * @param array $response {
	 *     @type string $url          Requested URL.
	 *     @type string $final_url    URL of the last response.
	 *     @type int    $status       Status of the last response (0 = transport failure).
	 *     @type int    $first_status Status of the requested URL itself.
	 *     @type array  $headers      Lower-case headers of the last response (string or string[] values).
	 *     @type string $body         Body of the last response.
	 *     @type array  $hops         Redirect hops: [ [ url, status, location ] ].
	 *     @type bool   $loop         A redirect loop was detected.
	 *     @type bool   $too_many     More than the allowed number of hops.
	 *     @type bool   $external     The chain left the site (not followed).
	 * }
	 * @param array $context {
	 *     @type bool   $indexable          WordPress intends this URL to be indexed.
	 *     @type string $expected_canonical Canonical WordPress/the SEO plugin should print ('' = self).
	 *     @type string $pagination_root    For paginated archives: URL of page 1.
	 * }
	 * @return array Facts (see keys below) including 'codes' and 'doc' (HtmlDocument|null, not persisted).
	 */
	public function analyze( array $response, array $context = array() ) {
		$url          = (string) ( $response['url'] ?? '' );
		$final_url    = (string) ( $response['final_url'] ?? $url );
		$status       = (int) ( $response['status'] ?? 0 );
		$first_status = (int) ( $response['first_status'] ?? $status );
		$headers      = is_array( $response['headers'] ?? null ) ? array_change_key_case( $response['headers'], CASE_LOWER ) : array();
		$body         = (string) ( $response['body'] ?? '' );
		$hops         = array_values( (array) ( $response['hops'] ?? array() ) );
		$indexable    = ! isset( $context['indexable'] ) || (bool) $context['indexable'];

		$facts = array(
			'url'                => $url,
			'final_url'          => $final_url,
			'status'             => $status,
			'first_status'       => $first_status,
			'redirected'         => ! empty( $hops ),
			'hops'               => $hops,
			'hop_count'          => count( $hops ),
			'loop'               => ! empty( $response['loop'] ),
			'too_many'           => ! empty( $response['too_many'] ),
			'external'           => ! empty( $response['external'] ),
			'content_type'       => self::header_value( $headers, 'content-type' ),
			'is_html'            => false,
			'x_robots'           => self::parse_x_robots( $headers['x-robots-tag'] ?? array() ),
			'meta_robots'        => array(),
			'directives'         => array(),
			'noindex'            => false,
			'noindex_source'     => '',
			'nofollow'           => false,
			'canonicals'         => array(),
			'canonical'          => '',
			'canonical_count'    => 0,
			'canonical_distinct' => 0,
			'canonical_header'   => self::canonical_from_link_header( $headers['link'] ?? array(), $final_url ),
			'title'              => '',
			'title_count'        => 0,
			'description'        => '',
			'description_count'  => 0,
			'og_title_count'     => 0,
			'h1_count'           => 0,
			'hreflang'           => array(),
			'json_ld'            => array(
				'count'   => 0,
				'invalid' => 0,
				'types'   => array(),
			),
			'word_count'         => 0,
			'soft_404'           => false,
			'soft_404_reason'    => '',
			'codes'              => array(),
			'doc'                => null,
		);

		$facts['is_html'] = $status >= 200 && $status < 300 && self::looks_like_html( $facts['content_type'], $body );

		if ( $facts['is_html'] ) {
			$doc          = HtmlDocument::parse( $body, $final_url );
			$facts['doc'] = $doc;

			$facts['meta_robots'] = $doc->robots();

			$canonicals = self::canonicals( $doc, $final_url );
			if ( '' !== $facts['canonical_header'] && ! $canonicals ) {
				$canonicals[] = array(
					'href'         => $facts['canonical_header'],
					'url'          => $facts['canonical_header'],
					'relative'     => false,
					'cross_domain' => self::is_cross_domain( $facts['canonical_header'], $final_url ),
					'source'       => 'header',
				);
			}
			$distinct = array();
			foreach ( $canonicals as $c ) {
				$distinct[ Url::normalize( $c['url'] ) ] = true;
			}
			$facts['canonicals']         = $canonicals;
			$facts['canonical']          = $canonicals ? $canonicals[0]['url'] : '';
			$facts['canonical_count']    = count( $canonicals );
			$facts['canonical_distinct'] = count( $distinct );

			$titles                     = $doc->query( '//title' );
			$facts['title_count']       = $titles->length;
			$facts['title']             = $titles->length ? self::clip( trim( (string) preg_replace( '/\s+/u', ' ', $titles->item( 0 )->textContent ) ), 300 ) : '';
			$descriptions               = $doc->metas( 'description' );
			$facts['description_count'] = count( $descriptions );
			$facts['description']       = $descriptions ? self::clip( trim( (string) preg_replace( '/\s+/u', ' ', (string) $descriptions[0] ) ), 1000 ) : '';
			$facts['og_title_count']    = count( $doc->metas( 'og:title' ) );
			$facts['h1_count']          = $doc->query( '//h1' )->length;
			$facts['hreflang']          = $doc->hreflang();

			$blocks = $doc->json_ld();
			$bad    = 0;
			foreach ( $blocks as $block ) {
				if ( '' !== $block['error'] ) {
					++$bad;
				}
			}
			$facts['json_ld'] = array(
				'count'   => count( $blocks ),
				'invalid' => $bad,
				'types'   => array_values( array_unique( $doc->schema_types() ) ),
			);

			$error_class = $doc->query( '//body[contains(concat(" ", normalize-space(@class), " "), " error404 ")]' )->length > 0;
			// Text::strip() keeps block boundaries as spaces, so "</h1><p>" never merges two words.
			$main                = $doc->main_node();
			$facts['word_count'] = $main ? Text::word_count( Text::strip( $doc->inner_html( $main ) ) ) : 0;

			list( $facts['soft_404'], $facts['soft_404_reason'] ) = self::soft_404( $error_class, $facts['title'], $facts['word_count'] );
		}

		$facts['directives'] = array_values( array_unique( array_merge( $facts['meta_robots'], $facts['x_robots'] ) ) );
		$meta_noindex        = (bool) array_intersect( array( 'noindex', 'none' ), $facts['meta_robots'] );
		$header_noindex      = (bool) array_intersect( array( 'noindex', 'none' ), $facts['x_robots'] );
		$facts['noindex']    = $status >= 200 && $status < 300 && ( $meta_noindex || $header_noindex );
		if ( $facts['noindex'] ) {
			$facts['noindex_source'] = $meta_noindex && $header_noindex ? 'both' : ( $meta_noindex ? 'meta' : 'header' );
		}
		$facts['nofollow'] = (bool) array_intersect( array( 'nofollow', 'none' ), $facts['directives'] );

		$facts['codes'] = self::codes( $facts, $context, $indexable );
		return $facts;
	}

	/**
	 * Problem codes for the facts.
	 *
	 * @param array $facts     Facts.
	 * @param array $context   Context.
	 * @param bool  $indexable Whether WordPress intends the URL to be indexed.
	 * @return string[]
	 */
	public static function codes( array $facts, array $context, $indexable ) {
		$codes  = array();
		$status = (int) $facts['status'];

		if ( 0 === $status ) {
			return array( 'fetch_failed' );
		}
		if ( ! empty( $facts['too_many'] ) ) {
			$codes[] = 'too_many_redirects';
		}
		if ( ! empty( $facts['loop'] ) ) {
			$codes[] = 'redirect_loop';
		}
		if ( empty( $facts['loop'] ) && empty( $facts['too_many'] ) ) {
			if ( $facts['hop_count'] >= 2 ) {
				$codes[] = 'redirect_chain';
			} elseif ( 1 === $facts['hop_count'] ) {
				$codes[] = 'redirect';
			}
		}
		if ( ! empty( $facts['external'] ) ) {
			$codes[] = 'redirect_external';
		}
		if ( ! empty( $facts['loop'] ) || ! empty( $facts['too_many'] ) || ! empty( $facts['external'] ) ) {
			return array_values( array_unique( $codes ) );
		}

		if ( in_array( $status, self::BLOCKED_STATUSES, true ) ) {
			$codes[] = 'fetch_blocked';
		} elseif ( $status >= 400 && $status < 500 ) {
			$codes[] = 'http_4xx';
		} elseif ( $status >= 500 ) {
			$codes[] = 'http_5xx';
		}
		if ( $facts['redirected'] && $status >= 400 && ! in_array( $status, self::BLOCKED_STATUSES, true ) ) {
			$codes[] = 'redirect_to_error';
		}
		if ( $status < 200 || $status >= 300 ) {
			return array_values( array_unique( $codes ) );
		}
		if ( empty( $facts['is_html'] ) ) {
			$codes[] = 'not_html';
			return array_values( array_unique( $codes ) );
		}

		if ( $facts['noindex'] && $indexable ) {
			$codes[] = 'noindex';
		} elseif ( ! $facts['noindex'] && ! $indexable && ! empty( $context['expect_noindex'] ) ) {
			$codes[] = 'noindex_missing';
		}

		if ( $facts['canonical_distinct'] > 1 ) {
			$codes[] = 'multiple_canonicals';
		}
		foreach ( $facts['canonicals'] as $c ) {
			if ( ! empty( $c['relative'] ) ) {
				$codes[] = 'canonical_relative';
			}
			if ( ! empty( $c['cross_domain'] ) ) {
				$codes[] = 'canonical_cross_domain';
			}
		}
		if ( '' !== $facts['canonical'] && 1 === $facts['canonical_distinct'] ) {
			$found    = Url::normalize( $facts['canonical'] );
			$self     = Url::normalize( $facts['final_url'] );
			$root = isset( $context['pagination_root'] ) && '' !== $context['pagination_root'] ? Url::normalize( $context['pagination_root'] ) : '';
			// After a redirect the destination is judged on its own: the redirect itself is the finding.
			$expected = empty( $facts['redirected'] ) && isset( $context['expected_canonical'] ) && '' !== $context['expected_canonical'] ? Url::normalize( $context['expected_canonical'] ) : $self;
			if ( '' !== $root && $found === $root && $found !== $self ) {
				$codes[] = 'pagination_canonical';
			} elseif ( $found !== $expected && $indexable ) {
				$codes[] = 'canonical_elsewhere';
			}
		}

		if ( 0 === $facts['title_count'] ) {
			$codes[] = 'missing_title';
		} elseif ( $facts['title_count'] > 1 ) {
			$codes[] = 'multiple_titles';
		}
		if ( $facts['json_ld']['invalid'] > 0 ) {
			$codes[] = 'invalid_json_ld';
		}
		if ( $facts['soft_404'] ) {
			$codes[] = 'soft_404';
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * Parses X-Robots-Tag header values. Directives prefixed with another
	 * crawler's name ("otherbot: noindex") are ignored; unprefixed and
	 * googlebot-prefixed directives apply.
	 *
	 * @param string|string[] $values Header value(s).
	 * @param string          $agent  Crawler token.
	 * @return string[] Lower-case directives.
	 */
	public static function parse_x_robots( $values, $agent = 'googlebot' ) {
		$agent  = strtolower( $agent );
		$values = is_array( $values ) ? $values : array( $values );
		$out    = array();
		$valued = array( 'unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview' );
		foreach ( $values as $value ) {
			$current = '';
			foreach ( explode( ',', strtolower( (string) $value ) ) as $token ) {
				$token = trim( $token );
				if ( '' === $token ) {
					continue;
				}
				if ( preg_match( '/^([a-z0-9_\-]+)\s*:\s*(.*)$/', $token, $m ) && ! in_array( $m[1], $valued, true ) ) {
					$current = $m[1];
					$token   = trim( $m[2] );
					if ( '' === $token ) {
						continue;
					}
				}
				if ( '' !== $current && $current !== $agent ) {
					continue;
				}
				// "unavailable_after: 25 Jun 2010" keeps only the directive name.
				$name = trim( (string) preg_replace( '/\s*:.*$/', '', $token ) );
				if ( in_array( $name, $valued, true ) ) {
					$out[] = $token;
					continue;
				}
				if ( preg_match( '/^[a-z\-]+$/', $token ) ) {
					$out[] = $token;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Canonical from an HTTP Link header (`<url>; rel="canonical"`).
	 *
	 * @param string|string[] $values Header value(s).
	 * @param string          $base   Base URL.
	 * @return string
	 */
	public static function canonical_from_link_header( $values, $base ) {
		$values = is_array( $values ) ? $values : array( $values );
		foreach ( $values as $value ) {
			if ( preg_match_all( '/<([^>]+)>\s*;([^,]*)/', (string) $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					if ( preg_match( '/rel\s*=\s*"?([^";]*)"?/i', $m[2], $rel ) && in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $rel[1] ) ) ), true ) ) {
						return Url::absolute( trim( $m[1] ), $base );
					}
				}
			}
		}
		return '';
	}

	/**
	 * Canonical link elements with their raw form.
	 *
	 * @param HtmlDocument $doc  Document.
	 * @param string       $base Page URL.
	 * @return array<int,array{href:string,url:string,relative:bool,cross_domain:bool,source:string}>
	 */
	private static function canonicals( HtmlDocument $doc, $base ) {
		$out = array();
		foreach ( $doc->query( '//link[@rel]' ) as $node ) {
			/** @var \DOMElement $node */
			$rels = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ) );
			if ( ! in_array( 'canonical', (array) $rels, true ) ) {
				continue;
			}
			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}
			$abs   = Url::absolute( $href, $base );
			$out[] = array(
				'href'         => self::clip( $href, 500 ),
				'url'          => self::clip( $abs, 2000 ),
				'relative'     => ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $href ),
				'cross_domain' => self::is_cross_domain( $abs, $base ),
				'source'       => 'html',
			);
		}
		return $out;
	}

	/**
	 * Whether a canonical points to another domain (www/non-www treated alike).
	 *
	 * @param string $canonical Absolute canonical.
	 * @param string $page      Page URL.
	 * @return bool
	 */
	public static function is_cross_domain( $canonical, $page ) {
		$a = wp_parse_url( (string) $canonical );
		$b = wp_parse_url( (string) $page );
		if ( empty( $a['host'] ) || empty( $b['host'] ) ) {
			return false;
		}
		if ( Url::bare_host( $a['host'] ) !== Url::bare_host( $b['host'] ) ) {
			return true;
		}
		// The same host on another port is another service, i.e. another origin.
		return self::effective_port( $a ) !== self::effective_port( $b );
	}

	/**
	 * Port of parsed URL parts, 0 for the scheme's default port (http 80, https 443).
	 *
	 * @param array $parts wp_parse_url() parts.
	 * @return int
	 */
	public static function effective_port( array $parts ) {
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			return 0;
		}
		return $port;
	}

	/**
	 * Soft 404 heuristics for a 200 response.
	 *
	 * @param bool   $error_class Body has the WordPress "error404" class.
	 * @param string $title       Page title.
	 * @param int    $words       Main content word count.
	 * @return array{0:bool,1:string}
	 */
	private static function soft_404( $error_class, $title, $words ) {
		if ( $error_class ) {
			return array( true, 'error_template' );
		}
		$title = strtolower( (string) $title );
		// Only unambiguous "not found" titles on near-empty pages (a news headline can contain "404").
		if ( $words < 150 && preg_match( '/(page not found|404 not found|error 404|404 error|nothing (was )?found|page (cannot|can\'t|could not) be found)/', $title ) ) {
			return array( true, 'not_found_title' );
		}
		return array( false, '' );
	}

	/**
	 * Whether a response body is HTML.
	 *
	 * @param string $content_type Content-Type header.
	 * @param string $body         Body.
	 * @return bool
	 */
	private static function looks_like_html( $content_type, $body ) {
		$type = strtolower( (string) $content_type );
		if ( '' !== $type ) {
			return false !== strpos( $type, 'html' );
		}
		return 1 === preg_match( '/^\s*(<!doctype html|<html|<head|<\?xml[^>]*>\s*<html)/i', substr( (string) $body, 0, 512 ) );
	}

	/**
	 * Header value as one string (last value wins for arrays).
	 *
	 * @param array  $headers Headers.
	 * @param string $name    Lower-case name.
	 * @return string
	 */
	private static function header_value( array $headers, $name ) {
		$value = $headers[ $name ] ?? '';
		if ( is_array( $value ) ) {
			$value = end( $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Multibyte-safe clipping.
	 *
	 * @param string $text   Text.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function clip( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length ) : substr( (string) $text, 0, $length );
	}

	/**
	 * Facts safe to store (no HTML, no DOM).
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function storable( array $facts ) {
		unset( $facts['doc'] );
		$facts['hops']       = array_slice( (array) $facts['hops'], 0, 10 );
		$facts['canonicals'] = array_slice( (array) $facts['canonicals'], 0, 5 );
		$facts['hreflang']   = array_slice( (array) $facts['hreflang'], 0, 50, true );
		return $facts;
	}
}

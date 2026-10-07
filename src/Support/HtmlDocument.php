<?php
/**
 * Tolerant HTML document wrapper.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Parses full pages or fragments with DOMDocument (UTF-8 safe, errors
 * suppressed) and exposes the SEO-relevant bits: meta tags, canonicals,
 * headings, links, images and JSON-LD blocks.
 */
class HtmlDocument {

	/**
	 * DOM.
	 *
	 * @var \DOMDocument
	 */
	private $dom;

	/**
	 * XPath.
	 *
	 * @var \DOMXPath
	 */
	private $xpath;

	/**
	 * Base URL for resolving relative links.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param string $html HTML (page or fragment).
	 * @param string $base Base URL.
	 */
	public function __construct( $html, $base = '' ) {
		$this->base = '' !== $base ? $base : home_url( '/' );
		$this->dom  = new \DOMDocument();
		$html       = (string) $html;
		if ( '' === trim( $html ) ) {
			$html = '<html><body></body></html>';
		}
		$previous = libxml_use_internal_errors( true );
		// The XML declaration forces UTF-8 without mb_convert_encoding (deprecated for HTML-ENTITIES).
		$this->dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->xpath = new \DOMXPath( $this->dom );
	}

	/**
	 * Parses HTML.
	 *
	 * @param string $html HTML.
	 * @param string $base Base URL.
	 * @return HtmlDocument
	 */
	public static function parse( $html, $base = '' ) {
		return new self( $html, $base );
	}

	/**
	 * Raw XPath query.
	 *
	 * @param string        $expression XPath.
	 * @param \DOMNode|null $context    Context node.
	 * @return \DOMNodeList
	 */
	public function query( $expression, $context = null ) {
		$result = null === $context ? $this->xpath->query( $expression ) : $this->xpath->query( $expression, $context );
		// An invalid expression returns false; answer with an empty node list instead.
		return false === $result ? $this->xpath->query( '/..' ) : $result;
	}

	/**
	 * Underlying DOM.
	 *
	 * @return \DOMDocument
	 */
	public function dom() {
		return $this->dom;
	}

	/**
	 * <title> texts (a page should have exactly one).
	 *
	 * @return string[]
	 */
	public function titles() {
		$out = array();
		foreach ( $this->query( '//head/title|//title' ) as $node ) {
			$out[] = trim( $node->textContent );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Values of <meta name|property="…"> (case-insensitive name).
	 *
	 * @param string $name Meta name or property.
	 * @return string[]
	 */
	public function metas( $name ) {
		$name = strtolower( $name );
		$out  = array();
		foreach ( $this->query( '//meta[@name or @property or @http-equiv]' ) as $node ) {
			/** @var \DOMElement $node */
			$key = strtolower( $node->getAttribute( 'name' ) ?: ( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'http-equiv' ) ) );
			if ( $key === $name ) {
				$out[] = trim( $node->getAttribute( 'content' ) );
			}
		}
		return $out;
	}

	/**
	 * First meta value or ''.
	 *
	 * @param string $name Name/property.
	 * @return string
	 */
	public function meta( $name ) {
		$all = $this->metas( $name );
		return $all ? $all[0] : '';
	}

	/**
	 * Absolute hrefs of <link rel="…">.
	 *
	 * @param string $rel Rel value.
	 * @return string[]
	 */
	public function link_rel( $rel ) {
		$out = array();
		foreach ( $this->query( '//link[@rel]' ) as $node ) {
			/** @var \DOMElement $node */
			$rels = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ) );
			if ( in_array( strtolower( $rel ), (array) $rels, true ) ) {
				$out[] = Url::absolute( trim( $node->getAttribute( 'href' ) ), $this->base );
			}
		}
		return $out;
	}

	/**
	 * Robots directives from meta robots/googlebot (lower-cased, merged).
	 *
	 * @return string[]
	 */
	public function robots() {
		$directives = array();
		foreach ( array_merge( $this->metas( 'robots' ), $this->metas( 'googlebot' ) ) as $content ) {
			foreach ( explode( ',', strtolower( $content ) ) as $d ) {
				$d = trim( $d );
				if ( '' !== $d ) {
					$directives[] = $d;
				}
			}
		}
		return array_values( array_unique( $directives ) );
	}

	/**
	 * Headings in document order.
	 *
	 * @return array<int,array{level:int,text:string}>
	 */
	public function headings() {
		$out = array();
		foreach ( $this->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $node ) {
			$out[] = array(
				'level' => (int) substr( $node->nodeName, 1 ),
				'text'  => trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) ),
			);
		}
		return $out;
	}

	/**
	 * Links.
	 *
	 * @param \DOMNode|null $context Limit to a subtree.
	 * @return array<int,array{href:string,url:string,text:string,rel:string[],internal:bool,target:string}>
	 */
	public function links( $context = null ) {
		$out = array();
		foreach ( $this->query( null === $context ? '//a[@href]' : './/a[@href]', $context ) as $node ) {
			/** @var \DOMElement $node */
			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '#^(mailto|tel|javascript|sms|data):#i', $href ) ) {
				continue;
			}
			$url   = Url::absolute( $href, $this->base );
			$out[] = array(
				'href'     => $href,
				'url'      => $url,
				'text'     => trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) ),
				'rel'      => array_filter( (array) preg_split( '/\s+/', strtolower( $node->getAttribute( 'rel' ) ) ) ),
				'internal' => Url::is_internal( $url ),
				'target'   => $node->getAttribute( 'target' ),
			);
		}
		return $out;
	}

	/**
	 * Images.
	 *
	 * @param \DOMNode|null $context Limit to a subtree.
	 * @return array<int,array{src:string,alt:string|null,width:int,height:int,srcset:bool,loading:string}>
	 */
	public function images( $context = null ) {
		$out = array();
		foreach ( $this->query( null === $context ? '//img' : './/img', $context ) as $node ) {
			/** @var \DOMElement $node */
			$src = $node->getAttribute( 'src' ) ?: ( $node->getAttribute( 'data-src' ) ?: $node->getAttribute( 'data-lazy-src' ) );
			if ( '' === trim( $src ) ) {
				continue;
			}
			$out[] = array(
				'src'     => Url::absolute( trim( $src ), $this->base ),
				'alt'     => $node->hasAttribute( 'alt' ) ? trim( $node->getAttribute( 'alt' ) ) : null,
				'width'   => (int) $node->getAttribute( 'width' ),
				'height'  => (int) $node->getAttribute( 'height' ),
				'srcset'  => '' !== $node->getAttribute( 'srcset' ),
				'loading' => $node->getAttribute( 'loading' ),
			);
		}
		return $out;
	}

	/**
	 * JSON-LD blocks.
	 *
	 * @return array<int,array{data:mixed,error:string,raw:string}>
	 */
	public function json_ld() {
		$out = array();
		foreach ( $this->query( '//script[@type]' ) as $node ) {
			/** @var \DOMElement $node */
			if ( 'application/ld+json' !== strtolower( trim( $node->getAttribute( 'type' ) ) ) ) {
				continue;
			}
			$raw  = trim( $node->textContent );
			$data = json_decode( $raw, true );
			$out[] = array(
				'data'  => $data,
				'error' => JSON_ERROR_NONE === json_last_error() ? '' : json_last_error_msg(),
				'raw'   => $raw,
			);
		}
		return $out;
	}

	/**
	 * Schema.org @type values found in JSON-LD (flattened, includes @graph nodes).
	 *
	 * @return string[]
	 */
	public function schema_types() {
		$types = array();
		$walk  = static function ( $node ) use ( &$walk, &$types ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					if ( is_string( $type ) ) {
						$types[] = $type;
					}
				}
			}
			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) && '@context' !== $key ) {
					$walk( $value );
				}
			}
		};
		foreach ( $this->json_ld() as $block ) {
			$walk( $block['data'] );
		}
		return $types;
	}

	/**
	 * Microdata item types (itemtype attributes).
	 *
	 * @return string[]
	 */
	public function microdata_types() {
		$out = array();
		foreach ( $this->query( '//*[@itemtype]' ) as $node ) {
			/** @var \DOMElement $node */
			foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'itemtype' ) ) ) as $type ) {
				$out[] = (string) preg_replace( '#^https?://schema\.org/#i', '', $type );
			}
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Main content node (article/main/role=main/.entry-content) or body.
	 *
	 * @return \DOMNode|null
	 */
	public function main_node() {
		foreach ( array( '//main//article', '//article', '//main', '//*[@role="main"]', '//*[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]', '//body' ) as $expr ) {
			$nodes = $this->query( $expr );
			if ( $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}
		return null;
	}

	/**
	 * Visible text of a node (default: main content).
	 *
	 * @param \DOMNode|null $node Node.
	 * @return string
	 */
	public function text( $node = null ) {
		$node = $node ?: $this->main_node();
		if ( ! $node ) {
			return '';
		}
		// Text::strip() drops scripts/styles and keeps block boundaries as spaces,
		// so "<h1>A</h1><p>B</p>" becomes "A B" rather than "AB".
		return Text::strip( $this->inner_html( $node ) );
	}

	/**
	 * Inner HTML of a node.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	public function inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $this->dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * <html lang>.
	 *
	 * @return string
	 */
	public function lang() {
		$nodes = $this->query( '//html[@lang]' );
		return $nodes->length ? (string) $nodes->item( 0 )->getAttribute( 'lang' ) : '';
	}

	/**
	 * hreflang alternates.
	 *
	 * @return array<string,string> lang => url
	 */
	public function hreflang() {
		$out = array();
		foreach ( $this->query( '//link[@rel="alternate"][@hreflang]' ) as $node ) {
			/** @var \DOMElement $node */
			$out[ strtolower( $node->getAttribute( 'hreflang' ) ) ] = Url::absolute( $node->getAttribute( 'href' ), $this->base );
		}
		return $out;
	}
}

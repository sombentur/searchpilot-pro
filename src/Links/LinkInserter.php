<?php
/**
 * HTML-safe internal link insertion.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helper that wraps the first eligible occurrence of a phrase in a
 * link without breaking markup. The HTML is split into tags, comments and
 * text; the open-element stack, the block-comment stack and the shortcode
 * stack decide whether a piece of text may receive a link:
 *
 *   - only paragraph text (<p> / <li>, or top-level classic-editor text,
 *     which wpautop turns into paragraphs);
 *   - never inside <a>, headings, <code>, <pre>, <script>, <style>,
 *     <button>, <figcaption> and similar, never inside attributes, block
 *     comment delimiters or shortcodes (tags or enclosed content);
 *   - in block content only inside core paragraph/list/quote blocks, so the
 *     editor never reports an invalid block;
 *   - whole words only (Unicode-aware, combining marks included), the
 *     original casing is kept, entities are never split;
 *   - never inside SearchPilot's generated regions — between
 *     `<!-- searchpilot:… -->` markers or in elements with a `searchpilot-…`
 *     class, such as the related-articles list.
 */
final class LinkInserter {

	/**
	 * Elements whose text can receive a link.
	 */
	const PARAGRAPHS = array( 'p', 'li' );

	/**
	 * Elements inside which a link is never inserted.
	 */
	const FORBIDDEN = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'kbd', 'samp', 'var', 'script', 'style', 'button', 'figcaption', 'caption', 'textarea', 'select', 'option', 'label', 'title', 'svg', 'math', 'noscript', 'template', 'iframe', 'object', 'video', 'audio', 'summary', 'sup', 'sub', 'time', 'abbr', 'cite' );

	/**
	 * Block-level containers that are not paragraph text in classic content.
	 */
	const NON_PARAGRAPH_BLOCKS = array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'dl', 'dt', 'dd', 'figure', 'form', 'fieldset', 'legend', 'nav', 'header', 'footer', 'aside', 'address', 'details', 'ul', 'ol', 'menu' );

	/**
	 * Void elements (never pushed on the stack).
	 */
	const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen' );

	/**
	 * Raw-text elements tokenized as one opaque piece.
	 */
	const RAW = array( 'script', 'style', 'textarea', 'noscript', 'template', 'svg', 'math', 'xmp', 'iframe' );

	/**
	 * Blocks whose HTML may be edited without invalidating the block.
	 */
	const SAFE_BLOCKS = array( 'core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/freeform' );

	/**
	 * Wraps the first eligible occurrence of $anchor in a link.
	 *
	 * @param string $html      HTML (raw post content, blocks or classic).
	 * @param string $anchor    Phrase to link (matched case-insensitively on word boundaries).
	 * @param string $url       Link target.
	 * @param int|string $marker_id Value stored in data-spp-link (suggestion id, or a token such as
	 *                              "fix-12" of letters, digits and dashes; used for surgical undo).
	 * @param array  $args      Optional: rel (string) for external links.
	 * @return string|null New HTML, or null when no eligible occurrence exists.
	 */
	public static function insert( $html, $anchor, $url, $marker_id, array $args = array() ) {
		$html   = (string) $html;
		$anchor = trim( (string) preg_replace( '/\s+/u', ' ', (string) $anchor ) );
		$url    = trim( (string) $url );
		if ( '' === $html || '' === $anchor || '' === $url || ! preg_match( '/[' . Text::WORD_CHARS . ']/u', $anchor ) ) {
			return null;
		}
		$regex  = Text::phrase_regex( $anchor );
		$result = null;
		$marker = is_string( $marker_id ) && preg_match( '/^[A-Za-z0-9-]{1,40}$/', $marker_id ) ? $marker_id : (string) (int) $marker_id;
		$build  = static function ( $original ) use ( $url, $marker, $args ) {
			$attrs = ' href="' . self::escape_url( $url ) . '" data-spp-link="' . $marker . '"';
			if ( ! empty( $args['rel'] ) ) {
				$attrs .= ' rel="' . htmlspecialchars( (string) $args['rel'], ENT_QUOTES, 'UTF-8' ) . '"';
			}
			return '<a' . $attrs . '>' . $original . '</a>';
		};

		$walked = self::walk(
			$html,
			static function ( $shadow, $text, $boundaries ) use ( $regex, &$result ) {
				if ( null !== $result ) {
					return null;
				}
				if ( ! preg_match_all( $regex, $shadow, $matches, PREG_OFFSET_CAPTURE ) ) {
					return null;
				}
				foreach ( $matches[0] as $match ) {
					$start = (int) $match[1];
					$len   = strlen( $match[0] );
					if ( 0 === $start && $boundaries['word_before'] ) {
						continue; // The word continues from the previous text node.
					}
					if ( $start + $len === strlen( $shadow ) && $boundaries['word_after'] ) {
						continue; // The word continues in the next text node.
					}
					$result = array( $start, $len );
					return $result;
				}
				return null;
			},
			$build
		);
		return null === $result ? null : $walked;
	}

	/**
	 * Eligible text of a document, for finding anchor candidates.
	 *
	 * @param string $html HTML.
	 * @return array{segments:array<int,array{text:string,block:int}>,blocks:array<int,string>}
	 *         Segments are decoded text runs that can receive a link; blocks
	 *         hold the full decoded text of each paragraph (for context).
	 */
	public static function segments( $html ) {
		$segments = array();
		self::walk(
			(string) $html,
			static function ( $shadow, $text, $boundaries, $block ) use ( &$segments ) {
				$decoded = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( '' !== $decoded ) {
					$segments[] = array(
						'text'  => $decoded,
						'block' => $block,
					);
				}
				return null;
			},
			null,
			$blocks
		);
		foreach ( $blocks as $id => $text ) {
			$text          = (string) preg_replace( '/\[\/?[A-Za-z][\w\-]*[^\[\]]*\]/', ' ', $text );
			$blocks[ $id ] = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
		return array(
			'segments' => $segments,
			'blocks'   => $blocks,
		);
	}

	/**
	 * Walks the document and calls $visit for every eligible text run.
	 *
	 * @param string        $html   HTML.
	 * @param callable      $visit  fn( string $shadow, string $text, array $boundaries, int $block ): array{0:int,1:int}|null
	 *                              — return [offset, length] (byte offsets in $text) to wrap that range.
	 * @param callable|null $build  fn( string $original ): string — wraps the matched text.
	 * @param array|null    $blocks Receives paragraph texts keyed by block id.
	 * @return string|null Rebuilt HTML (with the replacement applied), or null when the HTML cannot be tokenized.
	 */
	private static function walk( $html, callable $visit, $build = null, &$blocks = null ) {
		$blocks = array();
		$tokens = self::tokenize( $html );
		if ( null === $tokens ) {
			return null;
		}
		$classic          = false === strpos( $html, '<!-- wp:' );
		$closing_codes    = self::closing_shortcodes( $html );
		$stack            = array();
		$block_stack      = array();
		$code_stack       = array();
		$block_id         = 0;
		$current_block    = -1;
		$block_depth      = -1;
		$block_is_element = false;
		$marked           = 0;
		$marked_at        = array();
		$word_before      = false;
		$done             = false;
		$count            = count( $tokens );
		$word_char        = '/[' . Text::WORD_CHARS . ']/u';

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( 'raw' === $token['type'] ) {
				$word_before = false;
				continue;
			}
			if ( 'comment' === $token['type'] ) {
				if ( preg_match( '#^<!--\s*(/)?searchpilot:[a-z0-9_-]+\s*-->$#', $token['value'], $mark ) ) {
					// SearchPilot's own generated regions (e.g. the related-articles list) never take links.
					$marked = '' === ( $mark[1] ?? '' ) ? $marked + 1 : max( 0, $marked - 1 );
					continue;
				}
				self::block_comment( $token['value'], $block_stack );
				continue;
			}
			if ( 'tag' === $token['type'] ) {
				$tag = self::parse_tag( $token['value'] );
				if ( ! $tag ) {
					continue;
				}
				$inline = self::is_inline( $tag['name'] );
				if ( $tag['close'] ) {
					$pos = array_search( $tag['name'], array_reverse( $stack, true ), true );
					if ( false !== $pos ) {
						$stack = array_slice( $stack, 0, $pos );
					}
					$marked_at = self::below( $marked_at, count( $stack ) );
					if ( $current_block >= 0 && ( $block_is_element ? count( $stack ) <= $block_depth : ! $inline ) ) {
						$current_block = -1;
					}
				} elseif ( ! $tag['void'] ) {
					if ( 'p' === $tag['name'] ) {
						// An open <p> is implicitly closed by a new <p>.
						$pos = array_search( 'p', array_reverse( $stack, true ), true );
						if ( false !== $pos && ! array_intersect( array_slice( $stack, $pos + 1 ), array( 'div', 'blockquote', 'li', 'td' ) ) ) {
							$stack     = array_slice( $stack, 0, $pos );
							$marked_at = self::below( $marked_at, count( $stack ) );
						}
					}
					$stack[] = $tag['name'];
					if ( preg_match( '/\bclass\s*=\s*(["\'])(?:[^"\']*\s)?searchpilot-[a-z0-9_-]+(?:[\s"\'])/i', $token['value'] ) ) {
						$marked_at[] = count( $stack ) - 1; // An element SearchPilot generated (class searchpilot-…).
					}
					if ( in_array( $tag['name'], self::PARAGRAPHS, true ) ) {
						$current_block    = ++$block_id;
						$block_depth      = count( $stack ) - 1;
						$block_is_element = true;
					} elseif ( $current_block >= 0 && ! $block_is_element && ! $inline ) {
						$current_block = -1;
					}
				}
				if ( ! $inline ) {
					$word_before = false;
				}
				continue;
			}

			// Text token.
			$text = $token['value'];
			if ( '' === $text ) {
				continue;
			}
			$tag_ok = ! $marked && ! $marked_at && self::tag_context_ok( $stack, $block_stack, $classic );
			if ( $tag_ok && -1 === $current_block ) {
				// Top-level classic text: paragraphs are separated by blank lines.
				$current_block    = ++$block_id;
				$block_depth      = count( $stack );
				$block_is_element = false;
			}
			$context_block = $current_block;
			if ( $context_block >= 0 && ! isset( $blocks[ $context_block ] ) ) {
				$blocks[ $context_block ] = '';
			}
			if ( $context_block >= 0 ) {
				$blocks[ $context_block ] .= $text;
			}

			$pieces = self::split_shortcodes( $text );
			$offset = 0;
			foreach ( $pieces as $piece ) {
				$len = strlen( $piece['value'] );
				if ( 'code' === $piece['type'] ) {
					self::shortcode_stack( $piece, $closing_codes, $code_stack );
					$offset     += $len;
					$word_before = false;
					continue;
				}
				if ( ! $done && $tag_ok && empty( $code_stack ) && '' !== trim( $piece['value'] ) ) {
					$shadow     = self::shadow( $piece['value'] );
					$boundaries = array(
						'word_before' => $word_before,
						'word_after'  => $offset + $len === strlen( $text ) && self::next_text_starts_with_word( $tokens, $i ),
					);
					$hit        = $visit( $shadow, $piece['value'], $boundaries, $context_block );
					if ( is_array( $hit ) && null !== $build ) {
						$absolute              = $offset + (int) $hit[0];
						$original              = substr( $text, $absolute, (int) $hit[1] );
						$text                  = substr( $text, 0, $absolute ) . $build( $original ) . substr( $text, $absolute + (int) $hit[1] );
						$tokens[ $i ]['value'] = $text;
						$done                  = true;
						break;
					}
				}
				$offset     += $len;
				$last        = '' !== $piece['value'] ? (string) mb_substr( $piece['value'], -1, 1, 'UTF-8' ) : '';
				$word_before = '' !== $last && 1 === preg_match( $word_char, $last );
			}
			if ( $done ) {
				break;
			}
			if ( $current_block >= 0 && ! $block_is_element && preg_match( '/\n\s*\n/', $text ) ) {
				// A blank line ends a classic paragraph.
				$current_block = -1;
			}
		}

		$out = '';
		foreach ( $tokens as $token ) {
			$out .= $token['value'];
		}
		return $out;
	}

	/**
	 * Stack positions that are still open.
	 *
	 * @param int[] $positions Positions.
	 * @param int   $depth     Current stack depth.
	 * @return int[]
	 */
	private static function below( array $positions, $depth ) {
		return array_values(
			array_filter(
				$positions,
				static function ( $position ) use ( $depth ) {
					return $position < $depth;
				}
			)
		);
	}

	/**
	 * Splits HTML into raw, comment, tag and text tokens.
	 *
	 * @param string $html HTML.
	 * @return array<int,array{type:string,value:string}>|null
	 */
	private static function tokenize( $html ) {
		$raw_alternatives = array();
		foreach ( self::RAW as $name ) {
			$raw_alternatives[] = '<' . $name . '\b[^>]*>.*?<\/' . $name . '\s*>';
		}
		$parts = preg_split( '/(' . implode( '|', $raw_alternatives ) . ')/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$tokens = array();
		foreach ( $parts as $index => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( 1 === $index % 2 ) {
				$tokens[] = array(
					'type'  => 'raw',
					'value' => $part,
				);
				continue;
			}
			$pieces = preg_split( '/(<!--.*?-->|<(?=[a-zA-Z\/!?])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/s', $part, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( ! is_array( $pieces ) ) {
				return null;
			}
			foreach ( $pieces as $p => $piece ) {
				if ( '' === $piece ) {
					continue;
				}
				if ( 1 === $p % 2 ) {
					$tokens[] = array(
						'type'  => 0 === strpos( $piece, '<!--' ) ? 'comment' : 'tag',
						'value' => $piece,
					);
				} else {
					$tokens[] = array(
						'type'  => 'text',
						'value' => $piece,
					);
				}
			}
		}
		return $tokens;
	}

	/**
	 * Parses a tag token.
	 *
	 * @param string $token Tag.
	 * @return array{name:string,close:bool,void:bool}|null
	 */
	private static function parse_tag( $token ) {
		if ( ! preg_match( '/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9:\-]*)/', $token, $m ) ) {
			return null; // Doctype, CDATA, processing instructions.
		}
		$name = strtolower( $m[2] );
		return array(
			'name'  => $name,
			'close' => '' !== $m[1],
			'void'  => in_array( $name, self::VOID, true ) || '/>' === substr( rtrim( $token ), -2 ),
		);
	}

	/**
	 * Updates the block stack from a comment token.
	 *
	 * @param string $comment     Comment.
	 * @param array  $block_stack Block stack (by reference).
	 */
	private static function block_comment( $comment, array &$block_stack ) {
		if ( ! preg_match( '/^<!--\s+(\/)?wp:([a-z][a-z0-9_\-]*(?:\/[a-z][a-z0-9_\-]*)?)(\s.*?)?\s*(\/)?-->$/s', $comment, $m ) ) {
			return;
		}
		$name = false === strpos( $m[2], '/' ) ? 'core/' . $m[2] : $m[2];
		if ( '' !== $m[1] ) {
			$pos = array_search( $name, array_reverse( $block_stack, true ), true );
			if ( false !== $pos ) {
				$block_stack = array_slice( $block_stack, 0, $pos );
			}
			return;
		}
		$self_closing = isset( $m[4] ) && '/' === $m[4];
		if ( ! $self_closing && ! ( isset( $m[3] ) && '/' === substr( rtrim( $m[3] ), -1 ) ) ) {
			$block_stack[] = $name;
		}
	}

	/**
	 * Whether the element/block context allows a link.
	 *
	 * @param string[] $stack       Open elements.
	 * @param string[] $block_stack Open blocks.
	 * @param bool     $classic     Classic (non-block) content.
	 * @return bool
	 */
	private static function tag_context_ok( array $stack, array $block_stack, $classic ) {
		if ( array_intersect( $stack, self::FORBIDDEN ) ) {
			return false;
		}
		if ( $block_stack ) {
			if ( ! in_array( end( $block_stack ), self::SAFE_BLOCKS, true ) ) {
				return false;
			}
			return (bool) array_intersect( $stack, self::PARAGRAPHS );
		}
		if ( array_intersect( $stack, self::PARAGRAPHS ) ) {
			return true;
		}
		if ( ! $classic ) {
			// Stray HTML between blocks without <p> is not paragraph text.
			return false;
		}
		// Classic editor: top-level text becomes a paragraph (wpautop).
		return ! array_intersect( $stack, self::NON_PARAGRAPH_BLOCKS );
	}

	/**
	 * Whether an element is inline (does not end a word).
	 *
	 * @param string $name Element.
	 * @return bool
	 */
	private static function is_inline( $name ) {
		return in_array( $name, array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'del', 'ins' ), true );
	}

	/**
	 * Names of shortcodes that have a closing tag somewhere in the document.
	 *
	 * @param string $html HTML.
	 * @return array<string,bool>
	 */
	private static function closing_shortcodes( $html ) {
		$out = array();
		if ( preg_match_all( '/\[\/([A-Za-z][\w\-]*)\]/', $html, $m ) ) {
			foreach ( $m[1] as $name ) {
				$out[ strtolower( $name ) ] = true;
			}
		}
		return $out;
	}

	/**
	 * Splits a text token into text and shortcode pieces.
	 *
	 * @param string $text Text.
	 * @return array<int,array{type:string,value:string,close?:bool,name?:string,self?:bool,escaped?:bool}>
	 */
	private static function split_shortcodes( $text ) {
		if ( false === strpos( $text, '[' ) ) {
			return array(
				array(
					'type'  => 'text',
					'value' => $text,
				),
			);
		}
		$out  = array();
		$last = 0;
		if ( preg_match_all( '/\[(\[?)(\/?)([A-Za-z][\w\-]*)([^\[\]]*?)(\/?)\](\]?)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$start = (int) $m[0][1];
				if ( $start > $last ) {
					$out[] = array(
						'type'  => 'text',
						'value' => substr( $text, $last, $start - $last ),
					);
				}
				$out[] = array(
					'type'    => 'code',
					'value'   => $m[0][0],
					'close'   => '/' === $m[2][0],
					'name'    => strtolower( $m[3][0] ),
					'self'    => '/' === $m[5][0],
					'escaped' => '[' === $m[1][0] && ']' === $m[6][0],
				);
				$last = $start + strlen( $m[0][0] );
			}
		}
		if ( $last < strlen( $text ) ) {
			$out[] = array(
				'type'  => 'text',
				'value' => substr( $text, $last ),
			);
		}
		return $out;
	}

	/**
	 * Updates the shortcode stack.
	 *
	 * @param array $piece         Shortcode piece.
	 * @param array $closing_codes Names with closing tags.
	 * @param array $code_stack    Stack (by reference).
	 */
	private static function shortcode_stack( array $piece, array $closing_codes, array &$code_stack ) {
		if ( ! empty( $piece['escaped'] ) ) {
			return;
		}
		if ( ! empty( $piece['close'] ) ) {
			$pos = array_search( $piece['name'], array_reverse( $code_stack, true ), true );
			if ( false !== $pos ) {
				$code_stack = array_slice( $code_stack, 0, $pos );
			}
			return;
		}
		if ( empty( $piece['self'] ) && isset( $closing_codes[ $piece['name'] ] ) ) {
			$code_stack[] = $piece['name'];
		}
	}

	/**
	 * Same-length copy of a text piece for matching: whitespace entities
	 * become spaces, other entities become opaque characters, so matches
	 * never start, end or split inside an entity.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function shadow( $text ) {
		$text = (string) preg_replace_callback(
			'/&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/',
			static function ( $m ) {
				$is_space = in_array( strtolower( $m[0] ), array( '&nbsp;', '&#160;', '&#xa0;', '&ensp;', '&emsp;', '&thinsp;' ), true );
				return str_repeat( $is_space ? ' ' : '#', strlen( $m[0] ) );
			},
			$text
		);
		return str_replace( "\xC2\xA0", '  ', $text );
	}

	/**
	 * Whether the next text (across inline tags) starts with a word character.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Current token index.
	 * @return bool
	 */
	private static function next_text_starts_with_word( array $tokens, $index ) {
		$count = count( $tokens );
		for ( $j = $index + 1; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( 'text' === $token['type'] ) {
				if ( '' === $token['value'] ) {
					continue;
				}
				return 1 === preg_match( '/^[' . Text::WORD_CHARS . ']/u', $token['value'] );
			}
			if ( 'tag' === $token['type'] ) {
				$tag = self::parse_tag( $token['value'] );
				if ( $tag && self::is_inline( $tag['name'] ) ) {
					continue;
				}
			}
			return false;
		}
		return false;
	}

	/**
	 * Escapes a URL for an href attribute.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function escape_url( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

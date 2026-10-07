<?php
/**
 * Block- and classic-aware edits of post content.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string operations on `post_content` used by fix planners:
 *
 * - headings() / set_heading_levels(): change heading levels while keeping
 *   Gutenberg's block comment (`<!-- wp:heading {"level":3} -->`) and the
 *   HTML tag in sync (level 2 is Gutenberg's default and has no attribute).
 *   Classic content (no blocks) only has tags. Headings inside core/html and
 *   core/shortcode blocks, enclosing shortcodes, <pre>/<code>/<script>/
 *   <style>/<textarea>, HTML comments and SearchPilot marked regions are not
 *   headings for this purpose. Headings sitting directly in the saved HTML
 *   of another block (e.g. a third-party "advanced heading" block) are
 *   traversed but reported `editable => false` and never changed: editing
 *   only their tag would invalidate the block.
 * - replace_marked_block(): idempotent insertion of SearchPilot-generated
 *   markup in a region identified by the class `searchpilot-{marker}`, so a
 *   second run replaces the region instead of duplicating it:
 *     block content:   <!-- wp:group {"className":"searchpilot-{marker}","metadata":{"name":"SearchPilot: {marker}"}} -->
 *                      <div class="wp-block-group searchpilot-{marker}">…</div><!-- /wp:group -->
 *                      (core/group's own save output, so the block editor keeps it intact)
 *     classic content: <div class="searchpilot-{marker}">…</div>
 *   Regions are found depth-aware (nested groups/divs). Legacy regions —
 *   `<!-- searchpilot:{marker} -->…<!-- /searchpilot:{marker} -->` and the form
 *   the editor turns them into (`<p><!-- searchpilot:{marker} --></p>`) — are
 *   recognised and replaced by the current form.
 * - fragments(): the changed part of a content edit, for diff previews.
 *
 * Every method returns the content byte-identical when nothing changes.
 */
class ContentEditor {

	/**
	 * Gutenberg block delimiter (same expression as WP_Block_Parser).
	 */
	const DELIMITER = '<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->';

	/**
	 * Whether content uses blocks.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function is_block_content( $content ) {
		return (bool) preg_match( '/<!--\s+wp:[a-z]/', (string) $content );
	}

	/**
	 * Headings in document order.
	 *
	 * @param string $content Content.
	 * @return array<int,array{index:int,level:int,text:string,editable:bool,block:string}>
	 */
	public static function headings( $content ) {
		$out = array();
		foreach ( self::scan( (string) $content ) as $heading ) {
			$out[] = array(
				'index'    => $heading['index'],
				'level'    => $heading['level'],
				'text'     => $heading['text'],
				'editable' => $heading['editable'],
				'block'    => $heading['block'],
			);
		}
		return $out;
	}

	/**
	 * Changes heading levels.
	 *
	 * @param string   $content Content.
	 * @param callable $map     fn( int $level, int $index, string $text ): int — called exactly once per
	 *                          heading in document order (the same traversal as headings()). Returning
	 *                          the same level (or anything outside 1–6) leaves the heading untouched.
	 * @return string
	 */
	public static function set_heading_levels( $content, callable $map ) {
		$content = (string) $content;
		$edits   = array();
		foreach ( self::scan( $content ) as $heading ) {
			$new = call_user_func( $map, $heading['level'], $heading['index'], $heading['text'] );
			$new = is_numeric( $new ) ? (int) $new : 0;
			if ( $new < 1 || $new > 6 || $new === $heading['level'] || ! $heading['editable'] ) {
				continue;
			}
			$delimiter = null;
			if ( $heading['delimiter'] ) {
				$delimiter = self::rewrite_delimiter( $heading['delimiter'], $new );
				if ( null === $delimiter ) {
					continue; // Unreadable block attributes: keep the block and its tag in sync by not touching either.
				}
				$edits[] = array( $heading['delimiter']['offset'], strlen( $heading['delimiter']['text'] ), $delimiter );
			}
			$edits[] = array( $heading['open_offset'], strlen( $heading['open_tag'] ), '<h' . $new . substr( $heading['open_tag'], 3 ) );
			$edits[] = array( $heading['close_offset'], strlen( $heading['close_tag'] ), '</h' . $new . substr( $heading['close_tag'], 4 ) );
		}
		$updated = self::apply_edits( $content, $edits );
		// Never write markup that is worse than before (mis-paired tags, block level differing from the tag).
		if ( $updated !== $content && self::heading_errors( $updated ) > self::heading_errors( $content ) ) {
			return $content;
		}
		return $updated;
	}

	/**
	 * Inserts, replaces or removes a SearchPilot marked region.
	 *
	 * @param string $content Content.
	 * @param string $marker  Marker ([a-z0-9_-], e.g. related-links).
	 * @param string $html    Region markup ('' removes the region). Block content: complete block
	 *                        markup, wrapped in the marker's core/group block (unless it already is
	 *                        that block); classic content: plain HTML, wrapped in the marker's div.
	 * @return string
	 */
	public static function replace_marked_block( $content, $marker, $html ) {
		$content = (string) $content;
		$marker  = self::marker( $marker );
		if ( '' === $marker ) {
			return $content;
		}
		$html    = trim( (string) $html, "\r\n" );
		$remove  = '' === trim( $html );
		$regions = self::regions( $content, $marker );
		$region  = '';
		if ( ! $remove ) {
			$region = self::is_block_content( $content ) || self::is_block_content( $html ) ? self::group_region( $marker, $html ) : self::div_region( $marker, $html );
		}
		if ( ! $regions ) {
			if ( $remove ) {
				return $content;
			}
			return '' === trim( $content ) ? $region : $content . "\n\n" . $region;
		}
		// Copies of the region (e.g. pasted twice): keep only the first.
		for ( $i = count( $regions ) - 1; $i >= 1; $i-- ) {
			$content = self::remove_at( $content, $regions[ $i ]['start'], $regions[ $i ]['end'] - $regions[ $i ]['start'] );
		}
		$first  = $regions[0];
		$length = $first['end'] - $first['start'];
		if ( $remove ) {
			return self::remove_at( $content, $first['start'], $length );
		}
		if ( substr( $content, $first['start'], $length ) === $region ) {
			return $content;
		}
		return substr( $content, 0, $first['start'] ) . $region . substr( $content, $first['end'] );
	}

	/**
	 * Whether a marked region exists.
	 *
	 * @param string $content Content.
	 * @param string $marker  Marker.
	 * @return bool
	 */
	public static function has_marked_block( $content, $marker ) {
		return null !== self::get_marked_block( $content, $marker );
	}

	/**
	 * Markup inside a marked region, or null.
	 *
	 * @param string $content Content.
	 * @param string $marker  Marker.
	 * @return string|null
	 */
	public static function get_marked_block( $content, $marker ) {
		$marker = self::marker( $marker );
		if ( '' === $marker ) {
			return null;
		}
		$regions = self::regions( (string) $content, $marker );
		return $regions ? trim( $regions[0]['inner'], "\r\n" ) : null;
	}

	/**
	 * CSS class identifying a marker's region.
	 *
	 * @param string $marker Marker.
	 * @return string
	 */
	public static function marker_class( $marker ) {
		return 'searchpilot-' . self::marker( $marker );
	}

	/**
	 * Changed fragments of a content edit for display: changed lines with
	 * one line of context, gaps shown as "…"; long single lines are clipped
	 * around the change.
	 *
	 * @param string $old     Old content.
	 * @param string $new     New content.
	 * @param int    $context Context lines.
	 * @return array{0:string,1:string} Before, after.
	 */
	public static function fragments( $old, $new, $context = 1 ) {
		$old = (string) $old;
		$new = (string) $new;
		if ( $old === $new ) {
			return array( '', '' );
		}
		$a = preg_split( '/\r\n|\n|\r/', $old );
		$b = preg_split( '/\r\n|\n|\r/', $new );
		$na = count( $a );
		$nb = count( $b );
		$pre = 0;
		while ( $pre < $na && $pre < $nb && $a[ $pre ] === $b[ $pre ] ) {
			++$pre;
		}
		$suf = 0;
		while ( $suf < $na - $pre && $suf < $nb - $pre && $a[ $na - 1 - $suf ] === $b[ $nb - 1 - $suf ] ) {
			++$suf;
		}
		$mid_a = array_slice( $a, $pre, $na - $pre - $suf );
		$mid_b = array_slice( $b, $pre, $nb - $pre - $suf );

		$ops = array();
		if ( $pre > $context ) {
			$ops[] = array( '…', '' );
		}
		for ( $i = max( 0, $pre - $context ); $i < $pre; $i++ ) {
			$ops[] = array( '=', $a[ $i ] );
		}
		$ops = array_merge( $ops, self::line_ops( $mid_a, $mid_b ) );
		for ( $i = 0; $i < min( $context, $suf ); $i++ ) {
			$ops[] = array( '=', $a[ $na - $suf + $i ] );
		}
		if ( $suf > $context ) {
			$ops[] = array( '…', '' );
		}

		// Keep changed lines and up to $context unchanged lines around them.
		$count = count( $ops );
		$keep  = array_fill( 0, $count, false );
		foreach ( $ops as $i => $op ) {
			if ( '-' === $op[0] || '+' === $op[0] ) {
				for ( $j = max( 0, $i - $context ); $j <= min( $count - 1, $i + $context ); $j++ ) {
					$keep[ $j ] = true;
				}
			} elseif ( '…' === $op[0] ) {
				$keep[ $i ] = true;
			}
		}
		$before = array();
		$after  = array();
		$gap    = false;
		foreach ( $ops as $i => $op ) {
			if ( ! $keep[ $i ] || '…' === $op[0] ) {
				if ( ! $gap && ( $before || $after || '…' === $op[0] ) ) {
					$before[] = '…';
					$after[]  = '…';
				}
				$gap = true;
				continue;
			}
			$gap = false;
			if ( '=' === $op[0] || '-' === $op[0] ) {
				$before[] = $op[1];
			}
			if ( '=' === $op[0] || '+' === $op[0] ) {
				$after[] = $op[1];
			}
		}
		$before = implode( "\n", $before );
		$after  = implode( "\n", $after );
		if ( strlen( $before ) > 600 || strlen( $after ) > 600 ) {
			list( $before, $after ) = self::clip_pair( $before, $after, 120 );
		}
		return array( $before, $after );
	}

	/* ------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Finds the headings with their offsets.
	 *
	 * @param string $content Content.
	 * @return array
	 */
	private static function scan( $content ) {
		if ( false === stripos( $content, '<h' ) ) {
			return array();
		}
		$skip    = self::skip_ranges( $content );
		$pattern = '/(?P<delim>' . self::DELIMITER . ')|(?P<hopen><h(?P<hl>[1-6])(?=[\s>\/])[^>]*>)|(?P<hclose><\/h(?P<cl>[1-6])\s*>)/si';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$has      = static function ( array $m, $key ) {
			return isset( $m[ $key ] ) && -1 !== $m[ $key ][1] && '' !== $m[ $key ][0];
		};
		$stack    = array();
		$used     = array();
		$headings = array();
		$open     = null;
		$index    = 0;
		// A heading that is never properly closed (or that a block boundary or another
		// heading interrupts) is traversed but never edited.
		$broken = static function ( array $open, $until ) use ( $content, &$headings, &$index ) {
			$inner_start      = $open['open_offset'] + strlen( $open['open_tag'] );
			$open['editable'] = false;
			$open['text']     = self::plain( substr( $content, $inner_start, max( 0, $until - $inner_start ) ) );
			$open['index']    = $index++;
			unset( $open['tainted'] );
			$headings[] = $open;
		};
		foreach ( $matches as $m ) {
			$offset = (int) $m[0][1];
			$text   = (string) $m[0][0];
			if ( self::in_ranges( $offset, $skip ) ) {
				if ( null !== $open && ( $has( $m, 'hclose' ) || $has( $m, 'hopen' ) ) ) {
					// A heading tag hidden in a shortcode/comment/code inside this heading: its
					// real closer may be in there, so the pairing cannot be trusted.
					$open['tainted'] = true;
				}
				continue;
			}
			if ( $has( $m, 'delim' ) ) {
				if ( null !== $open ) {
					$broken( $open, $offset ); // A block boundary inside a heading.
					$open = null;
				}
				$name     = strtolower( ( $has( $m, 'namespace' ) ? $m['namespace'][0] : 'core/' ) . $m['name'][0] );
				$raw_name = ( $has( $m, 'namespace' ) ? $m['namespace'][0] : '' ) . $m['name'][0];
				if ( $has( $m, 'closer' ) ) {
					for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
						if ( $stack[ $i ]['name'] === $name ) {
							$stack = array_slice( $stack, 0, $i );
							break;
						}
					}
				} elseif ( ! $has( $m, 'void' ) ) {
					$stack[] = array(
						'name'     => $name,
						'raw_name' => $raw_name,
						'offset'   => $offset,
						'text'     => $text,
						'attrs'    => $has( $m, 'attrs' ) ? trim( $m['attrs'][0] ) : '',
					);
				}
				continue;
			}
			if ( $has( $m, 'hopen' ) ) {
				if ( null !== $open ) {
					$broken( $open, $offset ); // Unclosed heading followed by another heading.
					$open = null;
				}
				$top      = $stack ? $stack[ count( $stack ) - 1 ] : null;
				$editable = null === $top;
				$block    = $top ? $top['name'] : '';
				$delim    = null;
				if ( $top && 'core/heading' === $top['name'] && ! isset( $used[ $top['offset'] ] ) ) {
					$editable               = true;
					$delim                  = $top;
					$used[ $top['offset'] ] = true;
				}
				$open = array(
					'level'       => (int) $m['hl'][0],
					'open_offset' => $offset,
					'open_tag'    => $text,
					'block'       => $block,
					'editable'    => $editable,
					'delimiter'   => $delim,
					'tainted'     => false,
				);
				continue;
			}
			if ( $has( $m, 'hclose' ) && null !== $open ) {
				if ( (int) $m['cl'][0] !== $open['level'] ) {
					continue; // Only a closer of the same level ends a heading.
				}
				$inner_start          = $open['open_offset'] + strlen( $open['open_tag'] );
				$open['close_offset'] = $offset;
				$open['close_tag']    = $text;
				$open['text']         = self::plain( substr( $content, $inner_start, $offset - $inner_start ) );
				$open['index']        = $index++;
				if ( $open['tainted'] || self::straddles( $skip, $open['open_offset'], $offset + strlen( $text ) ) ) {
					$open['editable'] = false;
				}
				unset( $open['tainted'] );
				$headings[] = $open;
				$open       = null;
			}
		}
		if ( null !== $open ) {
			$broken( $open, strlen( $content ) );
		}
		return $headings;
	}

	/**
	 * Whether a skip range starts inside [start, end) and ends outside it, or
	 * the other way round.
	 *
	 * @param array $ranges Ranges.
	 * @param int   $start  Start.
	 * @param int   $end    End.
	 * @return bool
	 */
	private static function straddles( array $ranges, $start, $end ) {
		foreach ( $ranges as $range ) {
			$starts_inside = $range[0] > $start && $range[0] < $end;
			$ends_inside   = $range[1] > $start && $range[1] < $end;
			if ( $starts_inside !== $ends_inside ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Number of heading markup problems: unbalanced heading tags per level
	 * (outside skipped regions) plus core/heading blocks that do not hold
	 * exactly one heading whose tag level equals the block's level
	 * attribute. Edits that would raise it are refused.
	 *
	 * @param string $content Content.
	 * @return int
	 */
	public static function heading_errors( $content ) {
		$content = (string) $content;
		if ( false === stripos( $content, '<h' ) ) {
			return 0;
		}
		$skip    = self::skip_ranges( $content );
		$pattern = '/(?P<delim>' . self::DELIMITER . ')|(?P<hopen><h(?P<hl>[1-6])(?=[\s>\/])[^>]*>)|(?P<hclose><\/h(?P<cl>[1-6])\s*>)/si';
		preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$has     = static function ( array $m, $key ) {
			return isset( $m[ $key ] ) && -1 !== $m[ $key ][1] && '' !== $m[ $key ][0];
		};
		$balance = array_fill( 1, 6, 0 );
		$errors  = 0;
		$block   = null;
		foreach ( (array) $matches as $m ) {
			if ( self::in_ranges( (int) $m[0][1], $skip ) ) {
				continue;
			}
			if ( $has( $m, 'delim' ) ) {
				$name = strtolower( ( $has( $m, 'namespace' ) ? $m['namespace'][0] : 'core/' ) . $m['name'][0] );
				if ( 'core/heading' !== $name || $has( $m, 'void' ) ) {
					continue;
				}
				if ( ! $has( $m, 'closer' ) ) {
					$attrs = $has( $m, 'attrs' ) ? json_decode( trim( $m['attrs'][0] ), true ) : array();
					$block = array(
						'level'  => is_array( $attrs ) && isset( $attrs['level'] ) ? (int) $attrs['level'] : 2,
						'opens'  => array(),
						'closes' => array(),
					);
					continue;
				}
				if ( $block ) {
					$errors += ( 1 === count( $block['opens'] ) && 1 === count( $block['closes'] ) ) ? 0 : 1;
					foreach ( array_merge( $block['opens'], $block['closes'] ) as $level ) {
						$errors += $level === $block['level'] ? 0 : 1;
					}
				}
				$block = null;
				continue;
			}
			if ( $has( $m, 'hopen' ) ) {
				$level = (int) $m['hl'][0];
				++$balance[ $level ];
				if ( $block ) {
					$block['opens'][] = $level;
				}
			} elseif ( $has( $m, 'hclose' ) ) {
				$level = (int) $m['cl'][0];
				--$balance[ $level ];
				if ( $block ) {
					$block['closes'][] = $level;
				}
			}
		}
		foreach ( $balance as $count ) {
			$errors += abs( $count );
		}
		return $errors;
	}

	/**
	 * Byte ranges whose headings do not count.
	 *
	 * @param string $content Content.
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function skip_ranges( $content ) {
		$ranges = array();
		foreach ( self::regions( $content ) as $region ) {
			$ranges[] = array( $region['start'], $region['end'] );
		}
		$patterns = array(
			'/<!--\s+wp:(?:core\/)?(html|shortcode)(?=\s).*?<!--\s+\/wp:(?:core\/)?\1\s+-->/s',
			'/<(pre|code|script|style|textarea)\b[^>]*>.*?<\/\1\s*>/is',
			'/<!--(?!\s+\/?wp:[a-z])(?!\s*\/?searchpilot:).*?-->/s',
			'/\[([a-zA-Z][\w-]*)(?=[\s\]\/])[^\]]*\].*?\[\/\1\]/s',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $match ) {
					$ranges[] = array( (int) $match[1], (int) $match[1] + strlen( $match[0] ) );
				}
			}
		}
		return $ranges;
	}

	/**
	 * Whether an offset lies inside any range.
	 *
	 * @param int   $offset Offset.
	 * @param array $ranges Ranges.
	 * @return bool
	 */
	private static function in_ranges( $offset, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rebuilds a heading block's opening comment for a new level.
	 *
	 * @param array $delimiter Delimiter (raw_name, attrs).
	 * @param int   $level     Level.
	 * @return string|null Null when the attributes cannot be read.
	 */
	private static function rewrite_delimiter( array $delimiter, $level ) {
		$attrs = new \stdClass();
		if ( '' !== $delimiter['attrs'] ) {
			$attrs = json_decode( $delimiter['attrs'] );
			if ( ! is_object( $attrs ) ) {
				return null;
			}
		}
		if ( 2 === (int) $level ) {
			unset( $attrs->level );
		} else {
			$attrs->level = (int) $level;
		}
		$json = self::serialize_attributes( $attrs );
		return '<!-- wp:' . $delimiter['raw_name'] . ' ' . ( '{}' === $json ? '' : $json . ' ' ) . '-->';
	}

	/**
	 * Serialises block attributes like WordPress/Gutenberg do.
	 *
	 * @param object $attrs Attributes.
	 * @return string
	 */
	private static function serialize_attributes( $attrs ) {
		if ( function_exists( 'serialize_block_attributes' ) ) {
			return (string) serialize_block_attributes( $attrs );
		}
		$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$json = (string) preg_replace( '/--/', '\\u002d\\u002d', $json );
		$json = (string) preg_replace( '/</', '\\u003c', $json );
		$json = (string) preg_replace( '/>/', '\\u003e', $json );
		$json = (string) preg_replace( '/&/', '\\u0026', $json );
		return (string) preg_replace( '/\\\\"/', '\\u0022', $json );
	}

	/**
	 * Applies non-overlapping edits (offset, length, replacement).
	 *
	 * @param string $content Content.
	 * @param array  $edits   Edits.
	 * @return string
	 */
	private static function apply_edits( $content, array $edits ) {
		if ( ! $edits ) {
			return $content;
		}
		usort(
			$edits,
			static function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $edits as $edit ) {
			$content = substr( $content, 0, $edit[0] ) . $edit[2] . substr( $content, $edit[0] + $edit[1] );
		}
		return $content;
	}

	/**
	 * Plain text of heading markup.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain( $html ) {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Sanitised marker.
	 *
	 * @param string $marker Marker.
	 * @return string
	 */
	private static function marker( $marker ) {
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $marker ) ), 0, 64 );
	}

	/**
	 * Marked regions in document order (outermost only).
	 *
	 * @param string      $content Content.
	 * @param string|null $marker  Sanitised marker (null: any SearchPilot marker).
	 * @return array<int,array{start:int,end:int,form:string,inner:string}> form: group|div|legacy.
	 */
	private static function regions( $content, $marker = null ) {
		$content = (string) $content;
		if ( false === strpos( $content, 'searchpilot' ) ) {
			return array();
		}
		$own   = static function ( $classes ) use ( $marker ) {
			foreach ( preg_split( '/\s+/', trim( (string) $classes ) ) as $class ) {
				if ( null === $marker ? (bool) preg_match( '/^searchpilot-[a-z0-9][a-z0-9_-]*$/', $class ) : 'searchpilot-' . $marker === $class ) {
					return true;
				}
			}
			return false;
		};
		$has   = static function ( array $m, $key ) {
			return isset( $m[ $key ] ) && -1 !== $m[ $key ][1] && '' !== $m[ $key ][0];
		};
		$found = array();

		// 1. core/group blocks whose className carries the marker class (depth-aware).
		$group = '/<!--\s+(?P<closer>\/)?wp:(?:core\/)?group\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
		if ( false !== strpos( $content, 'wp:' ) && preg_match_all( $group, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$stack = array();
			foreach ( $m as $d ) {
				if ( $has( $d, 'void' ) ) {
					continue;
				}
				if ( ! $has( $d, 'closer' ) ) {
					$attrs   = $has( $d, 'attrs' ) ? json_decode( trim( $d['attrs'][0] ), true ) : null;
					$stack[] = array(
						'start' => (int) $d[0][1],
						'end'   => (int) $d[0][1] + strlen( $d[0][0] ),
						'own'   => is_array( $attrs ) && $own( is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '' ),
					);
					continue;
				}
				$open = array_pop( $stack );
				if ( $open && $open['own'] ) {
					$found[] = array(
						'start' => $open['start'],
						'end'   => (int) $d[0][1] + strlen( $d[0][0] ),
						'form'  => 'group',
						'inner' => self::unwrap_div( substr( $content, $open['end'], (int) $d[0][1] - $open['end'] ) ),
					);
				}
			}
		}

		// 2. Classic <div class="searchpilot-…"> regions (depth-aware over nested divs).
		if ( preg_match_all( '/<div\b[^>]*?\bclass\s*=\s*(["\'])([^"\']*)\1[^>]*>/i', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $tag ) {
				if ( ! $own( $tag[2][0] ) ) {
					continue;
				}
				$start = (int) $tag[0][1];
				$after = $start + strlen( $tag[0][0] );
				$close = self::div_close( $content, $after );
				if ( null === $close ) {
					continue;
				}
				$found[] = array(
					'start' => $start,
					'end'   => $close[1],
					'form'  => 'div',
					'inner' => substr( $content, $after, $close[0] - $after ),
				);
			}
		}

		// 3. Legacy comment markers, also as the block editor rewrites them (<p><!-- … --></p>).
		$name  = null === $marker ? '([a-z0-9][a-z0-9_-]*)' : '(' . preg_quote( $marker, '/' ) . ')';
		$wrap1 = '(?:<!--\s+wp:paragraph\s+-->\s*)?(?:<p\b[^>]*>\s*)?';
		$wrap2 = '(?:\s*<\/p>)?(?:\s*<!--\s+\/wp:paragraph\s+-->)?';
		if ( preg_match_all( '/' . $wrap1 . '<!--\s*searchpilot:' . $name . '\s*-->' . $wrap2 . '(.*?)' . $wrap1 . '<!--\s*\/searchpilot:\1\s*-->' . $wrap2 . '/s', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $legacy ) {
				$found[] = array(
					'start' => (int) $legacy[0][1],
					'end'   => (int) $legacy[0][1] + strlen( $legacy[0][0] ),
					'form'  => 'legacy',
					'inner' => (string) $legacy[2][0],
				);
			}
		}

		usort(
			$found,
			static function ( $a, $b ) {
				return $a['start'] === $b['start'] ? $b['end'] - $a['end'] : $a['start'] - $b['start'];
			}
		);
		$out = array();
		$end = -1;
		foreach ( $found as $region ) {
			if ( $region['start'] < $end ) {
				continue; // Inside a region already found (e.g. the group's own wrapper div).
			}
			$out[] = $region;
			$end   = $region['end'];
		}
		return $out;
	}

	/**
	 * Offsets (start, end) of the </div> closing a div opened before $offset.
	 *
	 * @param string $content Content.
	 * @param int    $offset  Offset just after the opening tag.
	 * @return array{0:int,1:int}|null
	 */
	private static function div_close( $content, $offset ) {
		if ( ! preg_match_all( '/<div\b[^>]*>|<\/div\s*>/i', $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			return null;
		}
		$depth = 1;
		foreach ( $m[0] as $tag ) {
			if ( '/' === $tag[0][1] ) {
				--$depth;
				if ( 0 === $depth ) {
					return array( (int) $tag[1], (int) $tag[1] + strlen( $tag[0] ) );
				}
			} elseif ( '/>' !== substr( $tag[0], -2 ) ) {
				++$depth;
			}
		}
		return null;
	}

	/**
	 * Markup inside a wrapper <div>…</div> (or the markup itself).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function unwrap_div( $html ) {
		$trimmed = trim( $html );
		if ( 0 === stripos( $trimmed, '<div' ) && '</div>' === strtolower( substr( $trimmed, -6 ) ) ) {
			$open = strpos( $trimmed, '>' );
			if ( false !== $open ) {
				return substr( $trimmed, $open + 1, -6 );
			}
		}
		return $trimmed;
	}

	/**
	 * Block-content region: core/group with the marker class (core/group's save output).
	 *
	 * @param string $marker Sanitised marker.
	 * @param string $html   Inner block markup (or the complete region already).
	 * @return string
	 */
	private static function group_region( $marker, $html ) {
		$existing = self::regions( $html, $marker );
		if ( 1 === count( $existing ) && 'group' === $existing[0]['form'] && 0 === $existing[0]['start'] && strlen( $html ) === $existing[0]['end'] ) {
			return $html;
		}
		$class = 'searchpilot-' . $marker;
		return '<!-- wp:group {"className":"' . $class . '","metadata":{"name":"SearchPilot: ' . $marker . '"}} --><div class="wp-block-group ' . $class . '">' . $html . '</div><!-- /wp:group -->';
	}

	/**
	 * Classic-content region: <div class="searchpilot-{marker}">.
	 *
	 * @param string $marker Sanitised marker.
	 * @param string $html   Inner HTML (or the complete region already).
	 * @return string
	 */
	private static function div_region( $marker, $html ) {
		$existing = self::regions( $html, $marker );
		if ( 1 === count( $existing ) && 'div' === $existing[0]['form'] && 0 === $existing[0]['start'] && strlen( $html ) === $existing[0]['end'] ) {
			return $html;
		}
		return '<div class="searchpilot-' . $marker . '">' . $html . '</div>';
	}

	/**
	 * Removes a region and the blank-line separator added with it.
	 *
	 * @param string $content Content.
	 * @param int    $offset  Offset.
	 * @param int    $length  Length.
	 * @return string
	 */
	private static function remove_at( $content, $offset, $length ) {
		$start = $offset;
		$end   = $offset + $length;
		if ( $start >= 4 && "\r\n\r\n" === substr( $content, $start - 4, 4 ) ) {
			$start -= 4;
		} elseif ( $start >= 2 && "\n\n" === substr( $content, $start - 2, 2 ) ) {
			$start -= 2;
		} elseif ( 0 === $start ) {
			if ( "\r\n\r\n" === substr( $content, $end, 4 ) ) {
				$end += 4;
			} elseif ( "\n\n" === substr( $content, $end, 2 ) ) {
				$end += 2;
			}
		}
		return substr( $content, 0, $start ) . substr( $content, $end );
	}

	/**
	 * Line diff (LCS) of two short line lists; long inputs are shown as one
	 * replaced block.
	 *
	 * @param string[] $a Old lines.
	 * @param string[] $b New lines.
	 * @return array<int,array{0:string,1:string}> Ops ('=', '-', '+').
	 */
	private static function line_ops( array $a, array $b ) {
		$n = count( $a );
		$m = count( $b );
		if ( ! $n || ! $m || $n * $m > 40000 ) {
			$ops = array();
			foreach ( $a as $line ) {
				$ops[] = array( '-', $line );
			}
			foreach ( $b as $line ) {
				$ops[] = array( '+', $line );
			}
			return $ops;
		}
		$lcs = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $lcs[ $i + 1 ][ $j + 1 ] + 1 : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}
		$ops = array();
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$ops[] = array( '=', $a[ $i ] );
				++$i;
				++$j;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$ops[] = array( '-', $a[ $i ] );
				++$i;
			} else {
				$ops[] = array( '+', $b[ $j ] );
				++$j;
			}
		}
		for ( ; $i < $n; $i++ ) {
			$ops[] = array( '-', $a[ $i ] );
		}
		for ( ; $j < $m; $j++ ) {
			$ops[] = array( '+', $b[ $j ] );
		}
		return $ops;
	}

	/**
	 * Clips two long strings to their differing middle plus context
	 * (UTF-8 safe).
	 *
	 * @param string $a       A.
	 * @param string $b       B.
	 * @param int    $context Context bytes.
	 * @return array{0:string,1:string}
	 */
	private static function clip_pair( $a, $b, $context ) {
		$la  = strlen( $a );
		$lb  = strlen( $b );
		$min = min( $la, $lb );
		$pre = 0;
		while ( $pre < $min && $a[ $pre ] === $b[ $pre ] ) {
			++$pre;
		}
		$suf = 0;
		while ( $suf < $min - $pre && $a[ $la - 1 - $suf ] === $b[ $lb - 1 - $suf ] ) {
			++$suf;
		}
		$start = self::boundary( $a, max( 0, $pre - $context ), -1 );
		$out   = array();
		foreach ( array( array( $a, $la ), array( $b, $lb ) ) as $pair ) {
			list( $s, $len ) = $pair;
			$end             = self::boundary( $s, min( $len, $len - $suf + $context ), 1 );
			$out[]           = ( $start > 0 ? '…' : '' ) . substr( $s, $start, $end - $start ) . ( $end < $len ? '…' : '' );
		}
		return array( $out[0], $out[1] );
	}

	/**
	 * Moves an offset to a UTF-8 character boundary.
	 *
	 * @param string $s         String.
	 * @param int    $offset    Offset.
	 * @param int    $direction -1 left, 1 right.
	 * @return int
	 */
	private static function boundary( $s, $offset, $direction ) {
		$len = strlen( $s );
		while ( $offset > 0 && $offset < $len && 0x80 === ( ord( $s[ $offset ] ) & 0xC0 ) ) {
			$offset += $direction;
		}
		return max( 0, min( $len, $offset ) );
	}
}

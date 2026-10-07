<?php
/**
 * SearchPilot-inserted in-text links in post content (pure).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Core\Changes;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and removes the links SearchPilot inserted, by their marker
 * attribute (`data-spp-link="…"`, written with every inserted link and
 * recorded in its activity entry). Links a person added never carry a
 * marker, so they are never found or touched. Removal unwraps the <a>
 * element and keeps its text.
 */
final class AutoLinks {

	/**
	 * Valid marker attribute (as recorded by Changes::update_post_content()).
	 *
	 * @param string $marker Marker, e.g. data-spp-link="12".
	 * @return bool
	 */
	public static function is_marker( $marker ) {
		return 1 === preg_match( '/^data-spp-link="[A-Za-z0-9-]{1,40}"$/', (string) $marker );
	}

	/**
	 * The link carrying a marker: its text, href and the sentence around it.
	 *
	 * @param string $content Post content.
	 * @param string $marker  Marker attribute.
	 * @return array{anchor:string,href:string,context:string,html:string}|null Null when the link is gone.
	 */
	public static function find( $content, $marker ) {
		$content = (string) $content;
		if ( ! self::is_marker( $marker ) || false === strpos( $content, $marker ) ) {
			return null;
		}
		$pattern = '#<a\b([^>]*\s' . preg_quote( $marker, '#' ) . '(?:\s[^>]*)?)>(.*?)</a>#is';
		if ( ! preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$anchor = self::text( $m[2][0] );
		$href   = preg_match( '#\bhref\s*=\s*"([^"]*)"#i', $m[1][0], $h ) ? html_entity_decode( $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		// The paragraph (line) around the link, as plain text.
		$start = strrpos( substr( $content, 0, (int) $m[0][1] ), "\n" );
		$start = false === $start ? 0 : $start + 1;
		$end   = strpos( $content, "\n", (int) $m[0][1] + strlen( $m[0][0] ) );
		$line  = substr( $content, $start, false === $end ? null : $end - $start );
		return array(
			'anchor'  => $anchor,
			'href'    => $href,
			'context' => Suggester::context_sentence( self::text( (string) $line ), $anchor ),
			'html'    => $m[0][0],
		);
	}

	/**
	 * Content with the links carrying these markers unwrapped (text kept).
	 *
	 * @param string   $content Post content.
	 * @param string[] $markers Marker attributes.
	 * @return string
	 */
	public static function remove( $content, array $markers ) {
		$content = (string) $content;
		foreach ( $markers as $marker ) {
			if ( self::is_marker( $marker ) ) {
				$content = Changes::remove_marked_links( $content, (string) $marker );
			}
		}
		return $content;
	}

	/**
	 * In-text links written by link fixes (marker fix-{issue id}). Several links of a post may
	 * share one marker and the related list uses it too, so each link is identified by its own
	 * markup; items of the related list are left out.
	 *
	 * @param string $content Post content.
	 * @return array<int,array{anchor:string,href:string,context:string,html:string,marker:string}>
	 */
	public static function fix_links( $content ) {
		$content = (string) $content;
		$out     = array();
		if ( false === strpos( $content, 'data-spp-link="fix-' ) || ! preg_match_all( '#<a\b([^>]*\sdata-spp-link="(fix-[A-Za-z0-9-]{1,36})"[^>]*)>(.*?)</a>#is', $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$region = (string) RelatedBlock::region( $content );
		foreach ( $all as $m ) {
			if ( '' !== $region && false !== strpos( $region, $m[0][0] ) ) {
				continue; // An item of the related list.
			}
			$anchor = self::text( $m[3][0] );
			$start  = strrpos( substr( $content, 0, (int) $m[0][1] ), "\n" );
			$start  = false === $start ? 0 : $start + 1;
			$end    = strpos( $content, "\n", (int) $m[0][1] + strlen( $m[0][0] ) );
			$line   = substr( $content, $start, false === $end ? null : $end - $start );
			$out[]  = array(
				'anchor'  => $anchor,
				'href'    => preg_match( '#\bhref\s*=\s*"([^"]*)"#i', $m[1][0], $h ) ? html_entity_decode( $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
				'context' => Suggester::context_sentence( self::text( (string) $line ), $anchor ),
				'html'    => $m[0][0],
				'marker'  => 'data-spp-link="' . $m[2][0] . '"',
			);
		}
		return $out;
	}

	/**
	 * Content with one link (its exact markup, first occurrence) unwrapped; the text stays.
	 *
	 * @param string $content Post content.
	 * @param string $html    Markup of the link, as returned by fix_links().
	 * @return string
	 */
	public static function unwrap( $content, $html ) {
		$content = (string) $content;
		$html    = (string) $html;
		$pos     = '' === $html ? false : strpos( $content, $html );
		if ( false === $pos || ! preg_match( '#^<a\b[^>]*\sdata-spp-link="[^"]*"[^>]*>(.*)</a>$#is', $html, $m ) ) {
			return $content;
		}
		return substr_replace( $content, $m[1], $pos, strlen( $html ) );
	}

	/**
	 * Plain text of an HTML fragment.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text( $html ) {
		$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}

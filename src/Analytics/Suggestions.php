<?php
/**
 * Heuristic title and meta description drafts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Builds three title and three description drafts for an editor to review.
 * No AI and no invented claims: drafts only rearrange the current title,
 * the post's own opening sentences and the query people actually searched
 * for. The query phrase appears exactly once (no keyword stuffing), titles
 * stay within 60 characters and keep the current brand suffix
 * ("… | Site Name"), descriptions aim for 120–155 characters.
 *
 * Nothing here writes anything: the admin picks, edits and applies.
 */
final class Suggestions {

	const TITLE_MAX = 60;
	const DESC_MIN  = 120;
	const DESC_MAX  = 155;

	/**
	 * Title separators recognised for the brand suffix, most specific first.
	 */
	const SEPARATORS = array( ' | ', ' – ', ' — ', ' - ', ' · ', ' • ', ' :: ', ' » ', ' / ' );

	/**
	 * Splits "Core title | Brand" into its parts.
	 *
	 * @param string $title     Title.
	 * @param string $site_name Site name (a suffix matching it is the brand).
	 * @return array{core:string,separator:string,brand:string}
	 */
	public static function split_brand( $title, $site_name = '' ) {
		$title = self::clean( $title );
		$best  = array(
			'core'      => $title,
			'separator' => '',
			'brand'     => '',
		);
		$pos   = -1;
		$found = '';
		foreach ( self::SEPARATORS as $sep ) {
			$p = mb_strrpos( $title, $sep, 0, 'UTF-8' );
			if ( false !== $p && $p > $pos ) {
				$pos   = $p;
				$found = $sep;
			}
		}
		if ( $pos <= 0 ) {
			return $best;
		}
		$core  = trim( mb_substr( $title, 0, $pos, 'UTF-8' ) );
		$brand = trim( mb_substr( $title, $pos + mb_strlen( $found, 'UTF-8' ), null, 'UTF-8' ) );
		if ( '' === $core || '' === $brand ) {
			return $best;
		}
		$site = self::clean( $site_name );
		if ( '' !== $site ) {
			$is_brand = false !== mb_stripos( $brand, $site, 0, 'UTF-8' ) || false !== mb_stripos( $site, $brand, 0, 'UTF-8' );
		} else {
			$is_brand = mb_strlen( $brand, 'UTF-8' ) <= 25 && count( preg_split( '/\s+/u', $brand ) ) <= 3;
		}
		if ( ! $is_brand ) {
			return $best;
		}
		return array(
			'core'      => $core,
			'separator' => $found,
			'brand'     => $brand,
		);
	}

	/**
	 * Three title drafts.
	 *
	 * @param string $current Current effective title (with brand suffix if any).
	 * @param string $query   Top search query for the page.
	 * @param array  $args    site_name, post_title, max (default 60).
	 * @return array<int,array{text:string,length:int,label:string,fits:bool,has_query:bool}>
	 */
	public static function titles( $current, $query, array $args = array() ) {
		$max        = (int) ( $args['max'] ?? self::TITLE_MAX );
		$post_title = self::clean( $args['post_title'] ?? '' );
		$current    = self::clean( $current );
		$phrase     = self::phrase( $query );
		$parts      = self::split_brand( '' !== $current ? $current : $post_title, (string) ( $args['site_name'] ?? '' ) );
		$core       = '' !== $parts['core'] ? $parts['core'] : $post_title;
		$suffix     = '' !== $parts['brand'] ? $parts['separator'] . $parts['brand'] : '';
		$candidates = array();

		if ( '' === $phrase ) {
			$candidates[] = array( __( 'Tightened current title', 'searchpilot-pro' ), self::fit_title( '', $core, '', $suffix, $max ) );
			if ( '' !== $post_title && $post_title !== $core ) {
				$candidates[] = array( __( 'Post headline', 'searchpilot-pro' ), self::fit_title( '', $post_title, '', $suffix, $max ) );
			}
		} else {
			$lead      = self::capitalize( $phrase );
			$remainder = self::without_phrase( $core, $phrase );
			if ( '' === $remainder && '' !== $post_title ) {
				$remainder = self::without_phrase( $post_title, $phrase );
			}
			// A dash inside the title would repeat the brand separator ("A – b – Brand"); use a bar then.
			$dash = in_array( trim( $parts['separator'] ), array( '–', '—', '-' ), true ) ? ' | ' : ' – ';
			if ( Text::contains( $core, $phrase ) ) {
				// The query is already there: lead with it, or keep the title and tighten it.
				// A title that already starts with the query would only gain a colon.
				$starts = 0 === mb_stripos( $core, $phrase, 0, 'UTF-8' );
				if ( '' !== $remainder && ! $starts ) {
					$candidates[] = array( __( 'Query first', 'searchpilot-pro' ), self::fit_title( $lead . ': ', $remainder, '', $suffix, $max ) );
				}
				$candidates[] = array( __( 'Current title, tightened', 'searchpilot-pro' ), self::fit_title( '', $core, '', $suffix, $max, $phrase ) );
				if ( '' !== $remainder && ! $starts && count( preg_split( '/\s+/u', $remainder ) ) > 5 ) {
					$candidates[] = array( __( 'Query first, shorter', 'searchpilot-pro' ), self::fit_title( $lead . $dash, self::first_words( $remainder, 5 ), '', $suffix, $max ) );
				}
			} else {
				$base         = '' !== $remainder ? $remainder : $core;
				$candidates[] = array( __( 'Query first', 'searchpilot-pro' ), self::fit_title( $lead . ': ', $base, '', $suffix, $max ) );
				$candidates[] = array( __( 'Current title + query', 'searchpilot-pro' ), self::fit_title( '', $base, ': ' . ( Text::is_latin( $phrase ) ? $phrase : $lead ), $suffix, $max ) );
				if ( count( preg_split( '/\s+/u', $base ) ) > 5 ) {
					$candidates[] = array( __( 'Query first, shorter', 'searchpilot-pro' ), self::fit_title( $lead . $dash, self::first_words( $base, 5 ), '', $suffix, $max ) );
				}
			}
			$candidates[] = array( __( 'Query and brand', 'searchpilot-pro' ), self::fit_title( $lead, '', '', $suffix, $max ) );
		}

		$out  = array();
		$seen = array( mb_strtolower( $current, 'UTF-8' ) => true );
		foreach ( $candidates as $candidate ) {
			list( $label, $text ) = $candidate;
			$text                 = self::clean( $text );
			$key                  = mb_strtolower( $text, 'UTF-8' );
			if ( '' === $text || isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( '' !== $phrase && 1 !== Text::count_phrase( $text, $phrase ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'text'      => $text,
				'length'    => Text::length( $text ),
				'label'     => $label,
				'fits'      => Text::length( $text ) <= $max,
				'has_query' => '' !== $phrase && Text::contains( $text, $phrase ),
			);
			if ( 3 === count( $out ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Three meta description drafts from the post's own sentences.
	 *
	 * @param string $content Post content (HTML or text).
	 * @param string $query   Top search query.
	 * @param array  $args    current (description), excerpt, min (120), max (155).
	 * @return array<int,array{text:string,length:int,label:string,in_range:bool,has_query:bool}>
	 */
	public static function descriptions( $content, $query, array $args = array() ) {
		$min       = (int) ( $args['min'] ?? self::DESC_MIN );
		$max       = (int) ( $args['max'] ?? self::DESC_MAX );
		$phrase    = self::phrase( $query );
		$plain     = mb_substr( Text::strip( (string) $content ), 0, 4000, 'UTF-8' );
		$excerpt   = self::clean( Text::strip( (string) ( $args['excerpt'] ?? '' ) ) );
		$current   = self::clean( (string) ( $args['current'] ?? '' ) );
		$sentences = array();
		foreach ( Text::sentences( $plain ) as $sentence ) {
			$sentence = self::clean( $sentence );
			if ( Text::length( $sentence ) >= 25 ) {
				$sentences[] = $sentence;
			}
			if ( count( $sentences ) >= 12 ) {
				break;
			}
		}
		$lead       = '' !== $phrase ? self::capitalize( $phrase ) : '';
		$candidates = array();

		// The query must survive truncation to 155 characters; otherwise it leads the draft.
		$ensure = static function ( $text, $glue = ': ' ) use ( $phrase, $lead, $max ) {
			if ( '' === $phrase || Text::contains( self::fit_description( $text, $max ), $phrase ) ) {
				return $text;
			}
			return $lead . $glue . self::lower_first( $text );
		};

		// 1. The opening sentences.
		if ( $sentences ) {
			$candidates[] = array( __( 'Opening sentences', 'searchpilot-pro' ), $ensure( self::accumulate( $sentences, 0, $min, $max, $phrase ) ) );
		}

		// 2. Start from the first later sentence that mentions the query.
		if ( '' !== $phrase ) {
			foreach ( $sentences as $i => $sentence ) {
				if ( $i > 0 && Text::contains( $sentence, $phrase ) ) {
					$candidates[] = array( __( 'Starts where the query is answered', 'searchpilot-pro' ), $ensure( self::accumulate( $sentences, $i, $min, $max, $phrase ) ) );
					break;
				}
			}
		}

		// 3. Question-led (Latin-script content) or the excerpt / current description with the query.
		$base = '' !== $excerpt ? $excerpt : ( '' !== $current ? $current : ( $sentences ? self::accumulate( $sentences, min( 1, count( $sentences ) - 1 ), $min - 20, $max - 25, $phrase ) : '' ) );
		if ( '' !== $base ) {
			$label = '' !== $excerpt ? __( 'From the excerpt', 'searchpilot-pro' ) : ( '' !== $current ? __( 'Current description + query', 'searchpilot-pro' ) : __( 'Summary', 'searchpilot-pro' ) );
			if ( '' !== $phrase && ! Text::contains( self::fit_description( $base, $max ), $phrase ) && Text::is_latin( $plain . ' ' . $phrase ) ) {
				/* translators: %s: search query. */
				$base  = sprintf( __( 'Looking for %s?', 'searchpilot-pro' ), $phrase ) . ' ' . $base;
				$label = __( 'Question-led', 'searchpilot-pro' );
			}
			$candidates[] = array( $label, $ensure( $base, ' — ' ) );
		}

		// 4. Fallback: from the second sentence on.
		if ( count( $sentences ) > 2 ) {
			$candidates[] = array( __( 'Key points', 'searchpilot-pro' ), $ensure( self::accumulate( $sentences, 1, $min, $max, $phrase ) ) );
		}

		$out  = array();
		$seen = array( mb_strtolower( $current, 'UTF-8' ) => true );
		foreach ( $candidates as $candidate ) {
			list( $label, $text ) = $candidate;
			$text                 = self::fit_description( $text, $max );
			$key                  = mb_strtolower( $text, 'UTF-8' );
			if ( '' === $text || isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( '' !== $phrase && Text::count_phrase( $text, $phrase ) > 1 ) {
				continue;
			}
			$seen[ $key ] = true;
			$length       = Text::length( $text );
			$out[]        = array(
				'text'      => $text,
				'length'    => $length,
				'label'     => $label,
				'in_range'  => $length >= $min && $length <= $max,
				'has_query' => '' !== $phrase && Text::contains( $text, $phrase ),
			);
			if ( 3 === count( $out ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Cleans a query into a display phrase.
	 *
	 * @param string $query Query.
	 * @return string
	 */
	public static function phrase( $query ) {
		$query = self::clean( $query );
		$query = trim( $query, " \t\"'“”‘’?!.,;:" );
		return mb_substr( $query, 0, 70, 'UTF-8' );
	}

	/**
	 * Builds "lead + main + tail + suffix" within $max characters by shortening
	 * the main part first, then dropping the brand suffix. The lead and tail
	 * (which carry the query) are never cut.
	 *
	 * @param string $lead    Leading text (kept).
	 * @param string $main    Shortenable text.
	 * @param string $tail    Trailing text (kept).
	 * @param string $suffix  Brand suffix including the separator.
	 * @param int    $max     Max characters.
	 * @param string $protect Phrase inside $main that must survive shortening.
	 * @return string
	 */
	public static function fit_title( $lead, $main, $tail, $suffix, $max = self::TITLE_MAX, $protect = '' ) {
		$main  = self::clean( $main );
		$build = static function ( $main_text, $with_suffix ) use ( $lead, $tail, $suffix ) {
			$text = $lead . $main_text . $tail;
			$text = trim( (string) preg_replace( '/\s*[:–—-]\s*$/u', '', trim( $text ) ) );
			return $with_suffix && '' !== $suffix ? $text . $suffix : $text;
		};
		foreach ( array( true, false ) as $with_suffix ) {
			foreach ( self::shortenings( $main, $protect ) as $candidate_main ) {
				$text = $build( $candidate_main, $with_suffix );
				if ( Text::length( $text ) <= $max ) {
					return $text;
				}
			}
		}
		return $build( $main, false );
	}

	/**
	 * Progressively shorter versions of a title part: the full text, then
	 * whole clauses (cut at commas, colons, dashes), then word by word —
	 * never ending on a stop word ("… for", "… of the") and never cutting a
	 * protected phrase.
	 *
	 * @param string $main    Text.
	 * @param string $protect Phrase that must survive.
	 * @return string[]
	 */
	private static function shortenings( $main, $protect = '' ) {
		$out  = array( $main );
		$keep = static function ( $candidate ) use ( $protect ) {
			return '' !== $candidate && ( '' === $protect || Text::contains( $candidate, $protect ) );
		};
		// Clause boundaries.
		$clauses = preg_split( '/\s*[,;:–—|]\s*|\s+-\s+/u', $main, -1, PREG_SPLIT_NO_EMPTY );
		for ( $n = count( (array) $clauses ) - 1; $n >= 1; $n-- ) {
			$candidate = self::trim_stopwords( implode( ', ', array_slice( $clauses, 0, $n ) ) );
			if ( $keep( $candidate ) ) {
				$out[] = $candidate;
			}
		}
		// Word by word (not below two words).
		$words = preg_split( '/\s+/u', $main, -1, PREG_SPLIT_NO_EMPTY );
		while ( count( $words ) > 2 ) {
			array_pop( $words );
			$candidate = self::trim_stopwords( implode( ' ', $words ) );
			if ( ! $keep( $candidate ) ) {
				break;
			}
			$out[] = $candidate;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Removes trailing punctuation and stop words ("Monsoon forecast for" → "Monsoon forecast").
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function trim_stopwords( $text ) {
		$words = preg_split( '/\s+/u', self::trim_punct( $text ), -1, PREG_SPLIT_NO_EMPTY );
		while ( count( $words ) > 1 ) {
			$last = mb_strtolower( self::trim_punct( (string) end( $words ) ), 'UTF-8' );
			if ( '' !== $last && ! Text::is_stopword( $last ) && ! in_array( $last, array( 'according', 'including', 'via', 'versus', 'vs' ), true ) ) {
				break;
			}
			array_pop( $words );
		}
		return self::trim_punct( implode( ' ', (array) $words ) );
	}

	/**
	 * Truncates a description to $max characters on a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function fit_description( $text, $max = self::DESC_MAX ) {
		$text = self::clean( $text );
		if ( Text::length( $text ) <= $max ) {
			return $text;
		}
		return Text::truncate( $text, $max - 1 );
	}

	/**
	 * Joins sentences from $start until at least $min characters (stopping
	 * early rather than overshooting $max by a long sentence), skipping later
	 * sentences that repeat the query (one mention is enough).
	 *
	 * @param string[] $sentences Sentences.
	 * @param int      $start     First index.
	 * @param int      $min       Minimum length.
	 * @param int      $max       Maximum length.
	 * @param string   $phrase    Query phrase.
	 * @return string
	 */
	private static function accumulate( array $sentences, $start, $min, $max, $phrase ) {
		$text      = '';
		$has_query = false;
		$count     = count( $sentences );
		for ( $i = max( 0, (int) $start ); $i < $count; $i++ ) {
			$sentence = $sentences[ $i ];
			$mentions = '' !== $phrase && Text::contains( $sentence, $phrase );
			if ( $mentions && $has_query ) {
				continue;
			}
			$next = '' === $text ? $sentence : $text . ' ' . $sentence;
			if ( '' !== $text && Text::length( $next ) > $max && Text::length( $text ) >= $min && ! ( $mentions && ! $has_query ) ) {
				break;
			}
			$has_query = $has_query || $mentions;
			$text      = $next;
			if ( Text::length( $text ) >= $min ) {
				break;
			}
		}
		return $text;
	}

	/**
	 * Removes a phrase (whole words) and tidies leftover punctuation.
	 *
	 * @param string $text   Text.
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function without_phrase( $text, $phrase ) {
		if ( '' === trim( (string) $phrase ) ) {
			return self::clean( $text );
		}
		$out = (string) preg_replace( Text::phrase_regex( $phrase ), ' ', (string) $text );
		$out = (string) preg_replace( '/\s+([,:;.!?])/u', '$1', $out );
		$out = (string) preg_replace( '/([:–—|-])\s*([:–—|-])/u', '$1', $out );
		return self::trim_punct( self::clean( $out ) );
	}

	/**
	 * First N words.
	 *
	 * @param string $text  Text.
	 * @param int    $count Words.
	 * @return string
	 */
	private static function first_words( $text, $count ) {
		$words = preg_split( '/\s+/u', self::clean( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return self::trim_punct( implode( ' ', array_slice( (array) $words, 0, $count ) ) );
	}

	/**
	 * Upper-cases the first letter (scripts without case are unchanged).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function capitalize( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		return mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
	}

	/**
	 * Lower-cases the first letter when the text starts with a common sentence
	 * starter (names, acronyms and other scripts are left alone).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower_first( $text ) {
		$text   = (string) $text;
		$first  = (string) preg_split( '/\s+/u', $text, 2 )[0];
		$common = array( 'The', 'A', 'An', 'This', 'These', 'Those', 'Our', 'In', 'On', 'For', 'With', 'When', 'If', 'How', 'What', 'Why', 'Here', 'It', 'You', 'We', 'Your', 'Learn', 'Find', 'Get', 'Discover' );
		if ( ! in_array( $first, $common, true ) ) {
			return $text;
		}
		return mb_strtolower( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
	}

	/**
	 * Trims spaces and dangling separators.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function trim_punct( $text ) {
		return trim( (string) $text, " \t\n\r\0\x0B,;:–—-|·" );
	}

	/**
	 * Collapses whitespace and decodes entities.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}

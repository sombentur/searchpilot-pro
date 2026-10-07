<?php
/**
 * Unicode-aware text helpers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tokenization that works for every script WordPress sites publish in.
 * Word characters are letters (\p{L}), combining marks (\p{M} — required
 * for Kannada, Hindi, Tamil… vowel signs) and digits (\p{N}).
 */
final class Text {

	const WORD_CHARS = '\p{L}\p{M}\p{N}';

	/**
	 * Converts HTML to plain text (scripts, styles and shortcodes removed).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function strip( $html ) {
		$html = (string) $html;
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', ' ', $html );
		if ( function_exists( 'strip_shortcodes' ) ) {
			$html = strip_shortcodes( $html );
		}
		$html = (string) preg_replace( '/\[[^\]]{1,200}\]/', ' ', $html );
		// Keep block boundaries as spaces so words do not merge.
		$html = (string) preg_replace( '#</?(p|div|br|li|h[1-6]|tr|td|th|blockquote|section|article|figure|figcaption|dd|dt)\b[^>]*>#i', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Lower-cased words.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function words( $text ) {
		$text  = mb_strtolower( (string) $text, 'UTF-8' );
		$parts = preg_split( '/[^' . self::WORD_CHARS . '\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( $part, "'" );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Word count. CJK ideographs count as one word each.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = (string) $text;
		$cjk  = preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', $text );
		if ( $cjk ) {
			$text = (string) preg_replace( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', ' ', $text );
		}
		return count( self::words( $text ) ) + (int) $cjk;
	}

	/**
	 * Splits into sentences (Latin, Devanagari/Kannada dandas, CJK marks).
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?।॥。？！])\s+|(?<=[。？！])/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? array_values( array_filter( array_map( 'trim', $parts ) ) ) : array();
	}

	/**
	 * Whether the phrase occurs as whole words (case-insensitive).
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Phrase.
	 * @return bool
	 */
	public static function contains( $haystack, $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return false;
		}
		return 1 === preg_match( self::phrase_regex( $needle ), (string) $haystack );
	}

	/**
	 * Number of whole-word occurrences of a phrase.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Phrase.
	 * @return int
	 */
	public static function count_phrase( $haystack, $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return 0;
		}
		return (int) preg_match_all( self::phrase_regex( $needle ), (string) $haystack );
	}

	/**
	 * Regex matching a phrase on Unicode word boundaries, flexible whitespace.
	 *
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function phrase_regex( $phrase ) {
		$words = preg_split( '/\s+/u', trim( (string) $phrase ), -1, PREG_SPLIT_NO_EMPTY );
		$words = array_map(
			static function ( $w ) {
				return preg_quote( $w, '/' );
			},
			(array) $words
		);
		$wc    = self::WORD_CHARS;
		return '/(?<![' . $wc . '])' . implode( '\s+', $words ) . '(?![' . $wc . '])/iu';
	}

	/**
	 * Stop words for the site language (filterable).
	 *
	 * @param string $locale Locale (default site locale).
	 * @return array<string,bool>
	 */
	public static function stopwords( $locale = '' ) {
		static $cache = array();
		$locale = '' !== $locale ? $locale : ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}
		$english = 'a about above after again against all almost also am an and any are aren\'t as at be because been before being below between both but by can can\'t cannot could did didn\'t do does doesn\'t doing don\'t down during each even ever every few for from further get gets got had has hasn\'t have haven\'t having he her here hers herself him himself his how however i if in into is isn\'t it it\'s its itself just let\'s like made make many may me might more most much must my myself never new no nor not now of off often on once one only or other our ours ourselves out over own per quite rather really said same say says she should shouldn\'t since so some still such than that that\'s the their theirs them themselves then there there\'s these they this those though through thus to too under until up upon us use used using very via was wasn\'t way we well were weren\'t what when where whether which while who whom whose why will with within without won\'t would wouldn\'t yes yet you your yours yourself yourselves';
		$kannada = 'ಮತ್ತು ಹಾಗೂ ಅಥವಾ ಆದರೆ ಈ ಆ ಇದು ಅದು ಇವು ಅವು ಒಂದು ಎಂದು ಎಂಬ ಎಂಬುದು ಇದೆ ಇವೆ ಇತ್ತು ಆಗಿದೆ ಆಗಿ ಮೂಲಕ ಬಗ್ಗೆ ಮೇಲೆ ಕೆಳಗೆ ನಂತರ ಮುಂಚೆ ಇನ್ನು ಸಹ ಕೂಡ ತಮ್ಮ ಅವರ ಅವರು ನಾವು ನೀವು ನಾನು ಯಾವ ಯಾರು ಏನು ಹೇಗೆ ಏಕೆ ಎಲ್ಲಾ ಕೆಲವು ಹೆಚ್ಚು ಕಡಿಮೆ ಇಲ್ಲ ಹೌದು ಮಾತ್ರ ಅದೇ ಇದೇ ಅಲ್ಲಿ ಇಲ್ಲಿ';
		$hindi   = 'और का की के है हैं था थे में से को पर यह वह एक भी तो ही लिए कि जो कर किया गया इस उस ने नहीं तक साथ बाद अब';
		$list    = array_merge( explode( ' ', $english ), explode( ' ', $kannada ), explode( ' ', $hindi ) );
		/**
		 * Filters stop words used for topic extraction and link matching.
		 *
		 * @param string[] $list   Stop words.
		 * @param string   $locale Locale.
		 */
		$list             = (array) apply_filters( 'searchpilot_stopwords', $list, $locale );
		$cache[ $locale ] = array_fill_keys( array_map( 'strval', $list ), true );
		return $cache[ $locale ];
	}

	/**
	 * Whether a word is a stop word or too short to carry meaning.
	 *
	 * @param string $word Lower-cased word.
	 * @return bool
	 */
	public static function is_stopword( $word ) {
		if ( mb_strlen( $word, 'UTF-8' ) < 2 || is_numeric( $word ) ) {
			return true;
		}
		return isset( self::stopwords()[ $word ] );
	}

	/**
	 * Light English stemmer (plural/verb suffixes); other scripts unchanged.
	 *
	 * @param string $word Lower-cased word.
	 * @return string
	 */
	public static function stem( $word ) {
		if ( ! preg_match( '/^[a-z]+$/', $word ) || strlen( $word ) < 5 ) {
			return $word;
		}
		// "-es" is only a suffix after s, x, z, ch and sh (boxes, wishes); "shoes" → "shoe".
		if ( preg_match( '/(?:ss|x|z|ch|sh)es$/', $word ) ) {
			return substr( $word, 0, -2 );
		}
		foreach ( array( 'ational' => 'ate', 'ization' => 'ize', 'fulness' => 'ful', 'iveness' => 'ive', 'ingly' => '', 'edly' => '', 'ies' => 'y', 'ing' => '', 'ed' => '', 's' => '' ) as $suffix => $replacement ) {
			$len = strlen( $suffix );
			if ( strlen( $word ) - $len >= 3 && substr( $word, -$len ) === $suffix ) {
				if ( 's' === $suffix && ( 'ss' === substr( $word, -2 ) || 'us' === substr( $word, -2 ) || 'is' === substr( $word, -2 ) ) ) {
					return $word;
				}
				return substr( $word, 0, -$len ) . $replacement;
			}
		}
		return $word;
	}

	/**
	 * Meaningful keywords: words minus stop words, stemmed.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function keywords( $text ) {
		$out = array();
		foreach ( self::words( $text ) as $word ) {
			if ( ! self::is_stopword( $word ) ) {
				$out[] = self::stem( $word );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Grapheme units (contract C1): every user-visible length in the
	 * plugin is counted in grapheme clusters — what a reader perceives as
	 * one character. "ಬೆಂಗಳೂರು" is 4 (ಬೆಂ ಗ ಳೂ ರು), not 8 code points.
	 * ------------------------------------------------------------------ */

	/**
	 * Grapheme clusters of a text (extended clusters, PCRE `\X`).
	 *
	 * The same segmentation is used by length(), truncate() and serp_fit(),
	 * so a length shown to the user, the check that judges it and the
	 * fixer that shortens it always agree.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function graphemes( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array();
		}
		if ( false !== preg_match_all( '/\X/u', $text, $m ) ) {
			return $m[0];
		}
		// Invalid UTF-8: fall back to bytes so callers never fatal.
		return str_split( $text );
	}

	/**
	 * User-visible length: grapheme clusters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return 0;
		}
		$count = preg_match_all( '/\X/u', $text );
		return false === $count ? strlen( $text ) : (int) $count;
	}

	/**
	 * Cuts a text to at most $max graphemes INCLUDING the ellipsis.
	 *
	 * Never splits a grapheme cluster (a Kannada vowel sign stays on its
	 * consonant, an emoji ZWJ sequence stays whole). When a word boundary
	 * exists within the last ~25 % of the kept text, the cut moves back to
	 * it; trailing spaces and punctuation are removed before the ellipsis.
	 * Text that already fits is returned unchanged.
	 *
	 * @param string $text     Text.
	 * @param int    $max      Maximum graphemes, ellipsis included.
	 * @param string $ellipsis Suffix when cut ('' for none).
	 * @return string
	 */
	public static function truncate( $text, $max, $ellipsis = '…' ) {
		$text = (string) $text;
		$max  = (int) $max;
		if ( $max <= 0 ) {
			return '';
		}
		// A grapheme has at least one code point: short texts fit without segmenting.
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		$clusters = self::head_clusters( $text, $max + 1 );
		if ( count( $clusters ) <= $max ) {
			return $text;
		}
		$ellipsis = (string) $ellipsis;
		$elen     = self::length( $ellipsis );
		if ( $elen >= $max ) {
			return implode( '', array_slice( $clusters, 0, $max ) );
		}
		$keep = $max - $elen;
		$cut  = array_slice( $clusters, 0, $keep );
		if ( ! self::is_space( $clusters[ $keep ] ) ) {
			$floor = (int) ceil( $keep * 0.75 );
			for ( $i = $keep - 1; $i >= $floor && $i > 0; $i-- ) {
				if ( self::is_space( $cut[ $i ] ) ) {
					$cut = array_slice( $cut, 0, $i );
					break;
				}
			}
		}
		$out = (string) preg_replace( '/[\s\x{00A0},.;:\x{2013}\x{2014}\x{0964}\x{0965}\-]+$/u', '', implode( '', $cut ) );
		if ( '' === $out ) {
			$out = implode( '', array_slice( $clusters, 0, $keep ) );
		}
		return $out . $ellipsis;
	}

	/**
	 * First grapheme clusters of a long text without segmenting all of it.
	 *
	 * @param string $text Text.
	 * @param int    $need Clusters needed.
	 * @return string[] Up to $need clusters (fewer only when the text is shorter).
	 */
	private static function head_clusters( $text, $need ) {
		$need = max( 1, (int) $need );
		// Clusters rarely exceed 10 code points (emoji family sequences are 7).
		$slice = mb_substr( $text, 0, $need * 10 + 1, 'UTF-8' );
		if ( $slice === $text ) {
			return array_slice( self::graphemes( $text ), 0, $need );
		}
		$clusters = self::graphemes( $slice );
		array_pop( $clusters ); // The last cluster of a slice may be incomplete.
		if ( count( $clusters ) < $need ) {
			$clusters = self::graphemes( $text );
		}
		return array_slice( $clusters, 0, $need );
	}

	/**
	 * Whether a cluster is whitespace (a word boundary).
	 *
	 * @param string $cluster Grapheme cluster.
	 * @return bool
	 */
	private static function is_space( $cluster ) {
		return 1 === preg_match( '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+$/u', (string) $cluster );
	}

	/* ------------------------------------------------------------------
	 * Search-result width estimation (contract C1)
	 * ------------------------------------------------------------------ */

	/**
	 * Google's visible space per result element (desktop).
	 *
	 * - title: 580 px of Arial 20 px — about 55–60 average Latin characters.
	 * - description: 990 px of Arial 14 px. This is an ESTIMATE calibrated to
	 *   Google's historical "920 px" limit, which was measured when snippets
	 *   used 13 px text (920 × 14 / 13 ≈ 990); it matches the long-standing
	 *   ~155–160 Latin-character guidance at today's 14 px (two lines).
	 *
	 * Google truncates by rendered width, not by characters, so wide scripts
	 * (Kannada, Tamil, CJK) fit fewer characters than English.
	 */
	const SERP_LIMITS = array(
		'title'       => array(
			'font'  => 20,
			'limit' => 580,
		),
		'description' => array(
			'font'  => 14,
			'limit' => 990,
		),
	);

	/**
	 * Advance widths of printable ASCII (U+0020–U+007E) in 1/1000 em, in code
	 * point order. Source: the Helvetica AFM metrics that Arial (and the
	 * metric-compatible Liberation Sans) reproduce exactly — Google renders
	 * titles and snippets in Arial.
	 */
	const ASCII_WIDTHS = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584';

	/**
	 * Indic scripts: [ first code point, last code point, base letter, spacing
	 * vowel sign / anusvara (Mc), consonant after a virama (conjunct /
	 * subscript form), virama code point ], widths in 1/1000 em.
	 *
	 * Assumptions (no font metrics are shipped): averages estimated from
	 * Noto Sans / Nirmala UI glyphs, the fonts browsers fall back to for these
	 * scripts. Base letters are wider than Latin (Kannada ≈ 0.68 em); spacing
	 * vowel signs (ು ಾ ೂ) and the anusvara (ಂ) add width; non-spacing signs
	 * (ಿ ೆ) and the virama add none; a consonant after a virama is drawn as a
	 * small subscript (Kannada/Telugu ottakshara) or half form (Devanagari).
	 * The model is per code point, so it gives the same width whether or not
	 * the grapheme segmenter keeps a conjunct in one cluster.
	 */
	const INDIC_WIDTHS = array(
		'devanagari' => array( 0x0900, 0x097F, 600, 220, 420, 0x094D ),
		'bengali'    => array( 0x0980, 0x09FF, 620, 250, 350, 0x09CD ),
		'gurmukhi'   => array( 0x0A00, 0x0A7F, 600, 220, 200, 0x0A4D ),
		'gujarati'   => array( 0x0A80, 0x0AFF, 580, 220, 420, 0x0ACD ),
		'oriya'      => array( 0x0B00, 0x0B7F, 660, 250, 300, 0x0B4D ),
		'tamil'      => array( 0x0B80, 0x0BFF, 720, 400, 720, 0x0BCD ),
		'telugu'     => array( 0x0C00, 0x0C7F, 680, 280, 250, 0x0C4D ),
		'kannada'    => array( 0x0C80, 0x0CFF, 680, 300, 250, 0x0CCD ),
		'malayalam'  => array( 0x0D00, 0x0D7F, 720, 350, 400, 0x0D4D ),
		'sinhala'    => array( 0x0D80, 0x0DFF, 700, 300, 300, 0x0DCA ),
	);

	/**
	 * Other scripts and symbols: [ first, last, width in 1/1000 em ].
	 * Arabic/Hebrew/Thai/Lao/Myanmar/Khmer/Ethiopic letters: typical
	 * advances of Arial / Noto Sans; CJK ideographs, kana, Hangul and
	 * full-width forms are square (1 em); emoji render ≈1.25 em in Segoe UI
	 * Emoji / Noto Color Emoji. Skin-tone modifiers join the previous emoji.
	 */
	const RANGE_WIDTHS = array(
		array( 0x0590, 0x05FF, 550 ),
		array( 0x0600, 0x06FF, 500 ),
		array( 0x0750, 0x077F, 500 ),
		array( 0x08A0, 0x08FF, 500 ),
		array( 0x0E00, 0x0EFF, 550 ),
		array( 0x1000, 0x109F, 700 ),
		array( 0x1100, 0x11FF, 1000 ),
		array( 0x1200, 0x137F, 750 ),
		array( 0x1780, 0x17FF, 700 ),
		array( 0x2600, 0x27BF, 1000 ),
		array( 0x2E80, 0x9FFF, 1000 ),
		array( 0xAC00, 0xD7AF, 1000 ),
		array( 0xF900, 0xFAFF, 1000 ),
		array( 0xFB50, 0xFDFF, 500 ),
		array( 0xFE70, 0xFEFF, 500 ),
		array( 0xFF01, 0xFF60, 1000 ),
		array( 0xFF61, 0xFF9F, 500 ),
		array( 0xFFA0, 0xFFEF, 1000 ),
		array( 0x1F1E6, 0x1F1FF, 625 ),
		array( 0x1F3FB, 0x1F3FF, 0 ),
		array( 0x1F000, 0x1FAFF, 1250 ),
		array( 0x20000, 0x3FFFF, 1000 ),
	);

	/**
	 * Common non-ASCII punctuation (Arial widths, 1/1000 em).
	 */
	const PUNCT_WIDTHS = array(
		0x00A0 => 278,
		0x00A3 => 556,
		0x00A9 => 737,
		0x00AB => 556,
		0x00AE => 737,
		0x00B0 => 400,
		0x00B7 => 278,
		0x00BB => 556,
		0x00D7 => 584,
		0x2010 => 333,
		0x2011 => 333,
		0x2012 => 556,
		0x2013 => 556,
		0x2014 => 1000,
		0x2015 => 1000,
		0x2018 => 222,
		0x2019 => 222,
		0x201A => 222,
		0x201C => 333,
		0x201D => 333,
		0x201E => 333,
		0x2022 => 350,
		0x2026 => 1000,
		0x2032 => 191,
		0x2039 => 333,
		0x203A => 333,
		0x20AC => 556,
		0x20B9 => 556,
		0x3000 => 1000,
	);

	/**
	 * Estimated rendered width of each grapheme cluster, in em.
	 *
	 * @param string $text Text.
	 * @return array<int,array{0:string,1:float}> [ cluster, width in em ].
	 */
	public static function cluster_widths( $text ) {
		$out          = array();
		$after_virama = '';
		$after_zwj    = false;
		foreach ( self::graphemes( $text ) as $cluster ) {
			$width = 0;
			$cps   = preg_split( '//u', $cluster, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( is_array( $cps ) ? $cps : array( $cluster ) as $ch ) {
				$cp    = function_exists( 'mb_ord' ) ? (int) mb_ord( $ch, 'UTF-8' ) : 0;
				$class = self::classify( $cp, $ch );
				if ( 'letter' === $class[0] ) {
					// Indic consonant: subscript/half form right after a virama of the same script.
					$width += $class[2] === $after_virama ? $class[3] : $class[1];
				} elseif ( 'emoji' === $class[0] ) {
					// Emoji joined by a zero-width joiner draw as one picture.
					$width += $after_zwj ? 0 : $class[1];
				} else {
					$width += $class[1];
				}
				$after_virama = 'virama' === $class[0] ? $class[2] : '';
				$after_zwj    = 0x200D === $cp;
			}
			$out[] = array( $cluster, $width / 1000 );
		}
		return $out;
	}

	/**
	 * Width class of a code point (memoized; widths in 1/1000 em).
	 *
	 * @param int    $cp Code point.
	 * @param string $ch Character.
	 * @return array{0:string,1:int,2:string,3:int} [ kind (fixed|letter|virama|emoji), width, script, width after a virama ].
	 */
	private static function classify( $cp, $ch ) {
		static $cache = array();
		static $ascii = null;
		if ( isset( $cache[ $cp ] ) ) {
			return $cache[ $cp ];
		}
		if ( null === $ascii ) {
			$ascii = array_map( 'intval', explode( ',', self::ASCII_WIDTHS ) );
		}
		$class = null;
		if ( $cp >= 0x20 && $cp <= 0x7E ) {
			$class = array( 'fixed', $ascii[ $cp - 0x20 ], '', 0 );
		} elseif ( $cp < 0x20 || ( $cp >= 0x7F && $cp < 0xA0 ) ) {
			$class = array( 'fixed', 0x09 === $cp || 0x0A === $cp || 0x0D === $cp ? 278 : 0, '', 0 );
		} elseif ( isset( self::PUNCT_WIDTHS[ $cp ] ) ) {
			$class = array( 'fixed', self::PUNCT_WIDTHS[ $cp ], '', 0 );
		}
		if ( null === $class ) {
			foreach ( self::INDIC_WIDTHS as $script => $def ) {
				if ( $cp < $def[0] || $cp > $def[1] ) {
					continue;
				}
				if ( $cp === $def[5] ) {
					$class = array( 'virama', 0, $script, 0 );
				} elseif ( preg_match( '/^[\p{Mn}\p{Me}]$/u', $ch ) ) {
					$class = array( 'fixed', 0, $script, 0 );
				} elseif ( preg_match( '/^\p{Mc}$/u', $ch ) ) {
					$class = array( 'fixed', $def[3], $script, 0 );
				} elseif ( preg_match( '/^\p{Nd}$/u', $ch ) ) {
					$class = array( 'fixed', 556, $script, 0 );
				} elseif ( preg_match( '/^\p{P}$/u', $ch ) ) {
					$class = array( 'fixed', 300, $script, 0 );
				} else {
					$class = array( 'letter', $def[2], $script, $def[4] );
				}
				break;
			}
		}
		if ( null === $class ) {
			if ( preg_match( '/^[\p{Mn}\p{Me}\p{Cf}]$/u', $ch ) || ( $cp >= 0xFE00 && $cp <= 0xFE0F ) ) {
				$class = array( 'fixed', 0, '', 0 );
			} elseif ( preg_match( '/^[\s\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}]$/u', $ch ) ) {
				$class = array( 'fixed', 278, '', 0 );
			}
		}
		if ( null === $class ) {
			foreach ( self::RANGE_WIDTHS as $range ) {
				if ( $cp >= $range[0] && $cp <= $range[1] ) {
					$class = array( $cp >= 0x1F000 ? 'emoji' : 'fixed', $range[2], '', 0 );
					break;
				}
			}
		}
		if ( null === $class ) {
			if ( preg_match( '/^\p{Mc}$/u', $ch ) ) {
				$width = 300;
			} elseif ( preg_match( '/^\p{Lu}$/u', $ch ) ) {
				$width = 667;
			} elseif ( preg_match( '/^[\p{Ll}\p{Nd}]$/u', $ch ) ) {
				$width = 556;
			} elseif ( preg_match( '/^\p{P}$/u', $ch ) ) {
				$width = 333;
			} else {
				$width = 600;
			}
			$class = array( 'fixed', $width, '', 0 );
		}
		if ( count( $cache ) < 4096 ) {
			$cache[ $cp ] = $class;
		}
		return $class;
	}

	/**
	 * Estimated rendered width in pixels.
	 *
	 * @param string $text Text.
	 * @param string $kind title|description.
	 * @return float
	 */
	public static function serp_width( $text, $kind ) {
		$def = self::serp_def( $kind );
		$em  = 0.0;
		foreach ( self::cluster_widths( $text ) as $item ) {
			$em += $item[1];
		}
		return round( $em * $def['font'], 1 );
	}

	/**
	 * Whether a title or description fits the space Google shows.
	 *
	 * Checks and fixers MUST use this (and serp_truncate()) so they agree.
	 *
	 * @param string $text Text (plain, entities decoded).
	 * @param string $kind title|description.
	 * @return array{fits:bool,graphemes:int,width:float,limit:float}
	 */
	public static function serp_fit( $text, $kind ) {
		$def   = self::serp_def( $kind );
		$text  = (string) $text;
		$width = self::serp_width( $text, $kind );
		return array(
			'fits'      => $width <= $def['limit'],
			'graphemes' => self::length( $text ),
			'width'     => $width,
			'limit'     => (float) $def['limit'],
		);
	}

	/**
	 * Longest prefix (word boundary, whole clusters, ellipsis included) that
	 * fits the space Google shows. Text that fits is returned unchanged.
	 *
	 * @param string $text     Text.
	 * @param string $kind     title|description.
	 * @param string $ellipsis Suffix when cut.
	 * @return string
	 */
	public static function serp_truncate( $text, $kind, $ellipsis = '…' ) {
		$text = (string) $text;
		$def  = self::serp_def( $kind );
		// No cluster is narrower than ≈0.19 em, so this many clusters always cover the limit.
		$need     = (int) ceil( $def['limit'] / ( $def['font'] * 0.19 ) ) + 2;
		$head     = self::head_clusters( $text, $need );
		$widths   = self::cluster_widths( implode( '', $head ) );
		$ellipsis = (string) $ellipsis;
		$budget   = $def['limit'] / $def['font'];
		$total    = 0.0;
		foreach ( $widths as $item ) {
			$total += $item[1];
		}
		if ( $total <= $budget && ( count( $head ) < $need || self::serp_fit( $text, $kind )['fits'] ) ) {
			return $text; // The whole text fits.
		}
		$room = $budget;
		foreach ( self::cluster_widths( $ellipsis ) as $item ) {
			$room -= $item[1];
		}
		$n   = 0;
		$sum = 0.0;
		foreach ( $widths as $item ) {
			if ( $sum + $item[1] > $room ) {
				break;
			}
			$sum += $item[1];
			++$n;
		}
		return self::truncate( $text, $n + self::length( $ellipsis ), $ellipsis );
	}

	/**
	 * Limit definition of a kind.
	 *
	 * @param string $kind title|description.
	 * @return array{font:int,limit:int}
	 */
	private static function serp_def( $kind ) {
		return self::SERP_LIMITS[ 'title' === $kind ? 'title' : 'description' ];
	}

	/**
	 * Whether text is mostly Latin script (enables English-only checks such as readability).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_latin( $text ) {
		$letters = preg_match_all( '/\p{L}/u', (string) $text );
		if ( ! $letters ) {
			return true;
		}
		$latin = preg_match_all( '/\p{Latin}/u', (string) $text );
		return ( $latin / $letters ) > 0.8;
	}

	/**
	 * Sanitizes plain text that may contain template variables such as
	 * %category% or %date%. (sanitize_text_field() would strip them: it treats
	 * "%ca" and "%da" as percent-encoded bytes.) Tags are removed, invalid
	 * UTF-8 is rejected and whitespace collapsed; output must still be escaped.
	 *
	 * @param mixed $value     Value.
	 * @param bool  $multiline Keep line breaks.
	 * @return string
	 */
	public static function clean( $value, $multiline = false ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value );
		} elseif ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = '';
		}
		$value = wp_strip_all_tags( $value );
		if ( $multiline ) {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			$value = (string) preg_replace( '/[ \t]+/u', ' ', $value );
			$value = (string) preg_replace( "/\n{3,}/", "\n\n", $value );
		} else {
			$value = (string) preg_replace( '/\s+/u', ' ', $value );
		}
		return trim( $value );
	}

	/**
	 * Replaces %var% placeholders.
	 *
	 * @param string $template Template.
	 * @param array  $vars     name => value.
	 * @return string
	 */
	public static function replace_vars( $template, array $vars ) {
		$out = (string) $template;
		foreach ( $vars as $name => $value ) {
			$out = str_replace( '%' . $name . '%', (string) $value, $out );
		}
		// Remove unknown placeholders and tidy separators left dangling.
		$out = (string) preg_replace( '/%[a-z_]+%/', '', $out );
		return trim( (string) preg_replace( '/\s{2,}/u', ' ', $out ) );
	}
}

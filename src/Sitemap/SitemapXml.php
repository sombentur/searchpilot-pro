<?php
/**
 * Pure XML sitemap writer.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Builds sitemap and sitemap-index documents (sitemaps.org 0.9 with the
 * Google image, video and news extensions). The writer has no WordPress
 * dependencies: it only escapes, validates and serializes the entries it
 * receives, so it can be unit tested.
 *
 * Entry shape for urlset():
 *   loc      (string) absolute URL (required)
 *   lastmod  (string) W3C datetime or ''
 *   images   (array)  [ [ 'loc' => url ], … ] (max 1000 per URL by protocol; callers cap at 50)
 *   videos   (array)  [ [ 'thumbnail_loc', 'title', 'description', 'content_loc', 'player_loc', 'duration', 'publication_date' ], … ]
 *   news     (array)  [ 'name', 'language', 'publication_date', 'title' ]
 *
 * changefreq and priority are deliberately never written: Google ignores them.
 */
final class SitemapXml {

	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/1.1';
	const NS_NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';

	/**
	 * Protocol limit on the uncompressed file size (50 MB).
	 */
	const MAX_BYTES = 52428800;

	/**
	 * Protocol limit on URLs per file.
	 */
	const MAX_URLS = 50000;

	/**
	 * Escapes text for XML element content or attribute values and removes
	 * characters that are not allowed in XML 1.0.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		if ( ! preg_match( '//u', $text ) ) {
			// Invalid UTF-8: replace the broken bytes so the document stays well-formed.
			$text = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : (string) preg_replace( '/[\x80-\xFF]/', '', $text );
		}
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Prepares a URL for <loc>: percent-encodes characters outside printable
	 * ASCII (IRI → URI, e.g. Kannada slugs) and entity-escapes it.
	 *
	 * @param string $url URL.
	 * @return string Escaped URL, or '' when it is not an absolute http(s) URL.
	 */
	public static function loc( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
			return '';
		}
		$url = (string) preg_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			$url
		);
		return self::escape( $url );
	}

	/**
	 * W3C datetime (UTC) from a MySQL GMT datetime, a timestamp or an ISO string.
	 *
	 * @param string|int $value Value.
	 * @return string '' when empty/invalid.
	 */
	public static function w3c_date( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$ts = (int) $value;
		} else {
			$value = trim( (string) $value );
			if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
				return '';
			}
			// Zone-less MySQL datetimes are UTC.
			$has_zone = (bool) preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/i', $value );
			$ts       = strtotime( $has_zone ? $value : $value . ' UTC' );
		}
		if ( ! $ts || $ts < 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:s+00:00', $ts );
	}

	/**
	 * XML declaration plus optional stylesheet processing instruction.
	 *
	 * @param string $stylesheet Stylesheet href ('' = none).
	 * @return string
	 */
	public static function prolog( $stylesheet = '' ) {
		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( '' !== (string) $stylesheet ) {
			$out .= '<?xml-stylesheet type="text/xsl" href="' . self::escape( $stylesheet ) . '"?>' . "\n";
		}
		return $out;
	}

	/**
	 * Sitemap index document.
	 *
	 * @param array  $sitemaps   [ [ 'loc' => url, 'lastmod' => w3c ], … ].
	 * @param string $stylesheet Stylesheet href.
	 * @param string $comment    Optional comment (generator note).
	 * @return string
	 */
	public static function index( array $sitemaps, $stylesheet = '', $comment = '' ) {
		$out  = self::prolog( $stylesheet );
		$out .= self::comment( $comment );
		$out .= '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";
		$seen = array();
		foreach ( $sitemaps as $sitemap ) {
			$loc = self::loc( $sitemap['loc'] ?? '' );
			if ( '' === $loc || isset( $seen[ $loc ] ) || count( $seen ) >= self::MAX_URLS ) {
				continue;
			}
			$seen[ $loc ] = true;
			$out         .= "\t<sitemap>\n\t\t<loc>" . $loc . "</loc>\n";
			$lastmod      = self::w3c_date( $sitemap['lastmod'] ?? '' );
			if ( '' !== $lastmod ) {
				$out .= "\t\t<lastmod>" . $lastmod . "</lastmod>\n";
			}
			$out .= "\t</sitemap>\n";
		}
		$out .= '</sitemapindex>' . "\n";
		return $out;
	}

	/**
	 * URL set document.
	 *
	 * @param array $entries Entries (see class docblock).
	 * @param array $opts {
	 *     @type string $stylesheet Stylesheet href.
	 *     @type string $comment    Optional comment.
	 *     @type int    $max_bytes  Size cap (default 50 MB). Images/videos are dropped first, then entries.
	 *     @type int    $max_images Images per URL (default 50).
	 * }
	 * @param array $stats Filled with [ 'urls' => int, 'images' => int, 'videos' => int, 'news' => int, 'dropped' => int, 'bytes' => int ].
	 * @return string
	 */
	public static function urlset( array $entries, array $opts = array(), &$stats = null ) {
		$max_bytes  = isset( $opts['max_bytes'] ) ? (int) $opts['max_bytes'] : self::MAX_BYTES;
		$max_images = isset( $opts['max_images'] ) ? max( 0, (int) $opts['max_images'] ) : 50;
		$stats      = array(
			'urls'    => 0,
			'images'  => 0,
			'videos'  => 0,
			'news'    => 0,
			'dropped' => 0,
			'bytes'   => 0,
		);

		$chunks = array();
		$flags  = array(
			'image' => false,
			'video' => false,
			'news'  => false,
		);
		$seen   = array();
		foreach ( $entries as $entry ) {
			$loc = self::loc( $entry['loc'] ?? '' );
			if ( '' === $loc || isset( $seen[ $loc ] ) ) {
				continue;
			}
			if ( $stats['urls'] >= self::MAX_URLS ) {
				++$stats['dropped'];
				continue;
			}
			$seen[ $loc ] = true;
			$chunks[]     = array(
				'loc'   => $loc,
				'entry' => $entry,
			);
		}

		// Namespaces are declared only when an extension is used.
		foreach ( $chunks as $chunk ) {
			$entry = $chunk['entry'];
			if ( ! empty( $entry['images'] ) && $max_images > 0 ) {
				$flags['image'] = true;
			}
			if ( ! empty( $entry['videos'] ) ) {
				$flags['video'] = true;
			}
			if ( ! empty( $entry['news'] ) ) {
				$flags['news'] = true;
			}
		}

		$head  = self::prolog( $opts['stylesheet'] ?? '' );
		$head .= self::comment( $opts['comment'] ?? '' );
		$head .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( $flags['image'] ) {
			$head .= ' xmlns:image="' . self::NS_IMAGE . '"';
		}
		if ( $flags['video'] ) {
			$head .= ' xmlns:video="' . self::NS_VIDEO . '"';
		}
		if ( $flags['news'] ) {
			$head .= ' xmlns:news="' . self::NS_NEWS . '"';
		}
		$head .= ">\n";
		$foot  = "</urlset>\n";

		$body  = '';
		$bytes = strlen( $head ) + strlen( $foot );
		foreach ( $chunks as $chunk ) {
			$entry = $chunk['entry'];
			$base  = "\t<url>\n\t\t<loc>" . $chunk['loc'] . "</loc>\n";
			$date  = self::w3c_date( $entry['lastmod'] ?? '' );
			if ( '' !== $date ) {
				$base .= "\t\t<lastmod>" . $date . "</lastmod>\n";
			}
			$news_xml = ! empty( $entry['news'] ) ? self::news_xml( (array) $entry['news'] ) : '';
			$extra    = '';
			$counts   = array(
				'images' => 0,
				'videos' => 0,
			);
			if ( $flags['image'] && ! empty( $entry['images'] ) ) {
				$extra .= self::images_xml( (array) $entry['images'], $max_images, $counts['images'] );
			}
			if ( $flags['video'] && ! empty( $entry['videos'] ) ) {
				$extra .= self::videos_xml( (array) $entry['videos'], $counts['videos'] );
			}
			$full = $base . $news_xml . $extra . "\t</url>\n";
			if ( $bytes + strlen( $full ) > $max_bytes ) {
				// Keep the URL, drop its media.
				$full           = $base . $news_xml . "\t</url>\n";
				$counts         = array(
					'images' => 0,
					'videos' => 0,
				);
				if ( $bytes + strlen( $full ) > $max_bytes ) {
					++$stats['dropped'];
					continue;
				}
			}
			$bytes += strlen( $full );
			$body  .= $full;
			++$stats['urls'];
			$stats['images'] += $counts['images'];
			$stats['videos'] += $counts['videos'];
			if ( '' !== $news_xml ) {
				++$stats['news'];
			}
		}
		$stats['bytes'] = $bytes;
		return $head . $body . $foot;
	}

	/**
	 * image:image elements.
	 *
	 * @param array $images     Images.
	 * @param int   $max        Max images.
	 * @param int   $count      Filled with the number written.
	 * @return string
	 */
	private static function images_xml( array $images, $max, &$count ) {
		$out   = '';
		$count = 0;
		$seen  = array();
		foreach ( $images as $image ) {
			if ( $count >= $max ) {
				break;
			}
			$loc = self::loc( is_array( $image ) ? ( $image['loc'] ?? '' ) : $image );
			if ( '' === $loc || isset( $seen[ $loc ] ) ) {
				continue;
			}
			$seen[ $loc ] = true;
			$out         .= "\t\t<image:image>\n\t\t\t<image:loc>" . $loc . "</image:loc>\n\t\t</image:image>\n";
			++$count;
		}
		return $out;
	}

	/**
	 * video:video elements. Videos lacking a required field are skipped:
	 * thumbnail_loc, title, description and content_loc or player_loc.
	 *
	 * @param array $videos Videos.
	 * @param int   $count  Filled with the number written.
	 * @return string
	 */
	private static function videos_xml( array $videos, &$count ) {
		$out   = '';
		$count = 0;
		foreach ( $videos as $video ) {
			if ( ! self::video_is_valid( (array) $video ) ) {
				continue;
			}
			$thumb   = self::loc( $video['thumbnail_loc'] );
			$content = self::loc( $video['content_loc'] ?? '' );
			$player  = self::loc( $video['player_loc'] ?? '' );
			$out    .= "\t\t<video:video>\n";
			$out    .= "\t\t\t<video:thumbnail_loc>" . $thumb . "</video:thumbnail_loc>\n";
			$out    .= "\t\t\t<video:title>" . self::escape( self::clip( $video['title'], 100 ) ) . "</video:title>\n";
			$out    .= "\t\t\t<video:description>" . self::escape( self::clip( $video['description'], 2048 ) ) . "</video:description>\n";
			if ( '' !== $content ) {
				$out .= "\t\t\t<video:content_loc>" . $content . "</video:content_loc>\n";
			}
			if ( '' !== $player ) {
				$out .= "\t\t\t<video:player_loc>" . $player . "</video:player_loc>\n";
			}
			$duration = (int) ( $video['duration'] ?? 0 );
			if ( $duration >= 1 && $duration <= 28800 ) {
				$out .= "\t\t\t<video:duration>" . $duration . "</video:duration>\n";
			}
			$published = self::w3c_date( $video['publication_date'] ?? ( $video['upload_date'] ?? '' ) );
			if ( '' !== $published ) {
				$out .= "\t\t\t<video:publication_date>" . $published . "</video:publication_date>\n";
			}
			$out .= "\t\t</video:video>\n";
			++$count;
		}
		return $out;
	}

	/**
	 * Whether a video has every field Google requires.
	 *
	 * @param array $video Video.
	 * @return bool
	 */
	public static function video_is_valid( array $video ) {
		if ( '' === self::loc( $video['thumbnail_loc'] ?? '' ) ) {
			return false;
		}
		if ( '' === trim( (string) ( $video['title'] ?? '' ) ) || '' === trim( (string) ( $video['description'] ?? '' ) ) ) {
			return false;
		}
		return '' !== self::loc( $video['content_loc'] ?? '' ) || '' !== self::loc( $video['player_loc'] ?? '' );
	}

	/**
	 * news:news element.
	 *
	 * @param array $news [ name, language, publication_date, title ].
	 * @return string
	 */
	private static function news_xml( array $news ) {
		$name  = trim( (string) ( $news['name'] ?? '' ) );
		$lang  = strtolower( trim( (string) ( $news['language'] ?? '' ) ) );
		$date  = self::w3c_date( $news['publication_date'] ?? '' );
		$title = trim( (string) ( $news['title'] ?? '' ) );
		if ( '' === $name || '' === $lang || '' === $date || '' === $title ) {
			return '';
		}
		return "\t\t<news:news>\n"
			. "\t\t\t<news:publication>\n"
			. "\t\t\t\t<news:name>" . self::escape( $name ) . "</news:name>\n"
			. "\t\t\t\t<news:language>" . self::escape( $lang ) . "</news:language>\n"
			. "\t\t\t</news:publication>\n"
			. "\t\t\t<news:publication_date>" . $date . "</news:publication_date>\n"
			. "\t\t\t<news:title>" . self::escape( $title ) . "</news:title>\n"
			. "\t\t</news:news>\n";
	}

	/**
	 * Safe XML comment.
	 *
	 * @param string $comment Comment.
	 * @return string
	 */
	private static function comment( $comment ) {
		$comment = trim( (string) $comment );
		if ( '' === $comment ) {
			return '';
		}
		// "--" is not allowed inside comments.
		$comment = str_replace( '--', '- -', self::escape( $comment ) );
		return '<!-- ' . $comment . ' -->' . "\n";
	}

	/**
	 * Truncates text to a number of characters (multibyte safe).
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function clip( $text, $length ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $length ) {
			return rtrim( mb_substr( $text, 0, $length - 1, 'UTF-8' ) ) . '…';
		}
		return $text;
	}

	/**
	 * Google News language code for a WordPress locale (ISO 639; zh-cn / zh-tw for Chinese).
	 *
	 * @param string $locale Locale, e.g. kn, kn_IN, en_US, zh_CN, pt_BR.
	 * @return string
	 */
	public static function news_language( $locale ) {
		$locale = strtolower( str_replace( '-', '_', trim( (string) $locale ) ) );
		if ( '' === $locale ) {
			return 'en';
		}
		if ( 0 === strpos( $locale, 'zh' ) ) {
			return in_array( $locale, array( 'zh_tw', 'zh_hk', 'zh_mo', 'zh_hant' ), true ) ? 'zh-tw' : 'zh-cn';
		}
		$parts = explode( '_', $locale );
		$lang  = preg_replace( '/[^a-z]/', '', $parts[0] );
		return ( strlen( $lang ) >= 2 && strlen( $lang ) <= 3 ) ? $lang : 'en';
	}

	/**
	 * Validates a sitemap/sitemap-index document (for health checks).
	 *
	 * @param string $xml XML.
	 * @return array{valid:bool,root:string,error:string}
	 */
	public static function validate( $xml ) {
		$result = array(
			'valid' => false,
			'root'  => '',
			'error' => '',
		);
		$xml    = (string) $xml;
		if ( '' === trim( $xml ) ) {
			$result['error'] = 'empty';
			return $result;
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			// Cannot validate without ext-dom: fall back to a root sniff.
			if ( preg_match( '#<(sitemapindex|urlset)\b[^>]*xmlns=["\']' . preg_quote( self::NS_SITEMAP, '#' ) . '["\']#', $xml, $m ) ) {
				$result['valid'] = true;
				$result['root']  = $m[1];
			} else {
				$result['error'] = 'root';
			}
			return $result;
		}
		$doc = new \DOMDocument();
		error_clear_last();
		// LIBXML_NONET blocks network access; entities are never substituted. Parser
		// warnings are suppressed and read back from error_get_last().
		$ok = @$doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Untrusted XML.
		if ( ! $ok || ! $doc->documentElement ) {
			$last            = error_get_last();
			$result['error'] = $last ? trim( (string) preg_replace( '/^.*?loadXML\(\):\s*/', '', (string) $last['message'] ) ) : 'parse';
			$result['error'] = '' !== $result['error'] ? $result['error'] : 'parse';
			return $result;
		}
		$root = $doc->documentElement;
		if ( ! in_array( $root->localName, array( 'sitemapindex', 'urlset' ), true ) ) {
			$result['error'] = 'root';
			$result['root']  = (string) $root->localName;
			return $result;
		}
		$result['root'] = $root->localName;
		if ( self::NS_SITEMAP !== $root->namespaceURI ) {
			$result['error'] = 'namespace';
			return $result;
		}
		$result['valid'] = true;
		return $result;
	}
}

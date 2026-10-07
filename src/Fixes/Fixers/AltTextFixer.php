<?php
/**
 * Planner for missing_alt_text.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Fixes\ContentEditor;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Fills EMPTY ALT text. Existing ALT text is never changed, and a raw file
 * name, a camera name (IMG_2034, DSC…) or a hash is never used.
 *
 * Two kinds of change, with different sources:
 *
 *  - The media-library ALT of an attachment is SHARED by every post that
 *    uses the image (featured images, galleries, page builders, future
 *    insertions), so it is filled only from what the attachment itself
 *    owns: its caption, its title, then its file name — de-slugified, with
 *    size suffixes such as -1024x768 / -scaled removed. Text that belongs to
 *    one article (its figure caption or the alt attribute it typed) never
 *    becomes a site-wide ALT.
 *  - The alt attribute of an <img> tag in THIS post's content (the image
 *    block reads its ALT from that attribute, so the block stays valid) may
 *    also use this article's own figure caption: the attachment's ALT, its
 *    caption, the figure caption, its title, its file name. Only <img> tags
 *    in classic content or inside core blocks that read their ALT from the
 *    HTML (IMAGE_BLOCKS) are edited: blocks that keep the ALT in their
 *    block-comment attributes (Spectra/UAG, Kadence, …) would become
 *    "invalid content" in the editor, so those images are listed in the
 *    preview as a manual step.
 */
class AltTextFixer extends AbstractFixer {

	const MAX_ALT = 125;

	/**
	 * Blocks whose image ALT is sourced from the <img> alt attribute in the
	 * saved HTML (so editing that attribute keeps the block valid). Inline
	 * images in a paragraph are part of its HTML-sourced rich text.
	 */
	const IMAGE_BLOCKS = array( 'core/image', 'core/gallery', 'core/media-text', 'core/cover', 'core/paragraph' );

	/**
	 * Plans the fix.
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error
	 */
	public function plan( array $issue ) {
		$post = self::post_of( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$builder  = self::builder( $post );
		$content  = (string) $post->post_content;
		$images   = '' === $builder ? self::content_images( $content ) : array();
		$alts     = array(); // attachment id => planned media-library ALT.
		$replace  = array(); // offset => [ old tag, new tag ].
		$before   = array();
		$after    = array();
		$skipped  = array();
		$filled   = array(); // attachment ids whose <img> in the content gets an alt.
		$used     = array(); // attachment ids used in the content.
		$manual   = array(); // images inside blocks SearchPilot must not edit.
		$warnings = array();

		foreach ( $images as $image ) {
			if ( null !== $image['alt'] && '' !== trim( $image['alt'] ) ) {
				continue;
			}
			$id = $this->attachment( $image['id'] );
			if ( $id ) {
				$used[] = $id;
			}
			if ( ! self::editable_block( $image['block'] ) ) {
				$manual[] = self::file_label( $image['src'] ) . ' (' . $image['block'] . ')';
				continue;
			}
			$sources = array();
			if ( $id ) {
				$sources[] = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
				$sources[] = self::clean_caption( (string) wp_get_attachment_caption( $id ) );
			}
			$sources[] = self::clean_caption( $image['caption'] );
			if ( $id ) {
				$sources[] = self::alt_from_text( get_the_title( $id ) );
				$file      = get_attached_file( $id );
				$sources[] = self::alt_from_filename( $file ? $file : $image['src'] );
			} else {
				$sources[] = self::alt_from_filename( $image['src'] );
			}
			$alt  = self::choose( $sources );
			$name = self::file_label( $image['src'] );
			if ( '' === $alt ) {
				$skipped[] = $name;
				continue;
			}
			$replace[ $image['offset'] ] = array( $image['tag'], self::set_alt( $image['tag'], $alt ) );
			$before[]                    = $name . ': ' . __( '(no ALT text)', 'searchpilot-pro' );
			$after[]                     = $name . ': ' . self::quote( $alt );
			if ( $id ) {
				$filled[ $id ] = true;
			}
		}

		// Media-library ALT (shared by every post using the image): images in the
		// content, the featured image and images shown outside the stored content
		// (galleries, builder widgets) — from the attachment's own data only.
		$featured = (int) get_post_thumbnail_id( $post );
		$library  = array_merge( $used, array_map( 'intval', (array) ( $issue['data']['attachments'] ?? array() ) ), array( $featured ) );
		foreach ( array_unique( array_filter( $library ) ) as $id ) {
			$id = $this->attachment( $id );
			if ( ! $id || isset( $alts[ $id ] ) || '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				continue;
			}
			$file = get_attached_file( $id );
			$alt  = self::media_alt( (string) wp_get_attachment_caption( $id ), get_the_title( $id ), $file ? $file : (string) wp_get_attachment_url( $id ) );
			if ( '' === $alt ) {
				// Images in this post's content are already covered by their own alt attribute.
				if ( ! isset( $filled[ $id ] ) || $id === $featured ) {
					$skipped[] = self::file_label( $file ? $file : (string) wp_get_attachment_url( $id ) );
				}
				continue;
			}
			$alts[ $id ] = $alt;
		}

		if ( ! $replace && ! $alts ) {
			$message = __( 'None of the images without ALT text has a caption, title or descriptive file name SearchPilot could use. Describe each image in the editor (image block → ALT text) or in the media library.', 'searchpilot-pro' );
			if ( $manual ) {
				/* translators: %s: images with their block names. */
				$message .= ' ' . sprintf( __( 'These images are inside blocks that keep their ALT text in the block settings; set it in the editor: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( array_unique( $manual ), 0, 8 ) ) );
			}
			if ( $skipped ) {
				/* translators: %s: file names. */
				$message .= ' ' . sprintf( __( 'Images: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( array_unique( $skipped ), 0, 8 ) ) );
			}
			return new \WP_Error( 'searchpilot_nothing_to_fill', $message, array( 'status' => 409 ) );
		}

		$count = count( $replace ) + count( array_diff_key( $alts, $filled ) ); // Each image once.
		$plan  = self::new_plan(
			$issue,
			sprintf(
				/* translators: 1: number of images, 2: post title. */
				_n( 'Fill the empty ALT text of %1$d image in “%2$s”', 'Fill the empty ALT text of %1$d images in “%2$s”', max( 1, $count ), 'searchpilot-pro' ),
				max( 1, $count ),
				self::title_of( $post )
			),
			'post',
			$post->ID
		);
		foreach ( $alts as $id => $alt ) {
			$plan['changes'][] = self::change(
				'attachment_alt',
				$id,
				'_wp_attachment_image_alt',
				/* translators: %s: file name. */
				sprintf( __( 'Media library ALT text — every post that uses %s', 'searchpilot-pro' ), self::file_label( (string) get_attached_file( $id ) ) ),
				__( '(empty)', 'searchpilot-pro' ),
				$alt,
				$alt,
				(string) get_post_meta( $id, '_wp_attachment_image_alt', true )
			);
		}
		if ( $replace ) {
			$plan['changes'][] = self::change(
				'post_content',
				$post->ID,
				'post_content',
				sprintf(
					/* translators: %d: number of images. */
					_n( 'This article only: ALT text of %d image in the content', 'This article only: ALT text of %d images in the content', count( $replace ), 'searchpilot-pro' ),
					count( $replace )
				),
				implode( "\n", $before ),
				implode( "\n", $after ),
				self::apply_replacements( $content, $replace ),
				$content
			);
		}
		$warnings[] = __( 'Check that each ALT text describes what the image shows. Purely decorative images may keep an empty ALT text — remove those from this fix by editing them in the editor instead.', 'searchpilot-pro' );
		if ( $alts ) {
			$warnings[] = __( 'Media-library ALT text is shared: it is used wherever the image appears (featured images, galleries, future insertions), so it is taken only from the image’s own caption, title or file name — never from this article’s text.', 'searchpilot-pro' );
		}
		if ( $skipped ) {
			/* translators: %s: file names. */
			$warnings[] = sprintf( __( 'No usable source for: %s. Describe these images in the editor, otherwise this issue stays open.', 'searchpilot-pro' ), implode( ', ', array_slice( array_unique( $skipped ), 0, 8 ) ) );
		}
		if ( $manual ) {
			/* translators: %s: images with their block names. */
			$warnings[] = sprintf( __( 'Manual step: these images are inside blocks that keep their ALT text in the block settings, so SearchPilot does not edit them (the editor would report the block as invalid). Select each block in the editor and fill its ALT text: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( array_unique( $manual ), 0, 8 ) ) );
		}
		if ( '' !== $builder ) {
			/* translators: %s: page builder name. */
			$warnings[] = sprintf( __( 'This page is laid out with %s: SearchPilot fills the media-library ALT text, which its image widgets use unless an ALT text is typed into the widget itself.', 'searchpilot-pro' ), self::builder_label( $builder ) );
		}
		$plan['warnings'] = $warnings;
		return $plan;
	}

	/**
	 * A media-library image id, or 0.
	 *
	 * @param int $id Attachment id.
	 * @return int
	 */
	private function attachment( $id ) {
		$id = (int) $id;
		return $id && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/* ------------------------------------------------------------------
	 * Pure logic (unit-tested)
	 * ------------------------------------------------------------------ */

	/**
	 * <img> tags of raw post content in document order.
	 *
	 * @param string $content Content.
	 * @return array<int,array{offset:int,tag:string,src:string,id:int,alt:string|null,caption:string,block:string}> block: innermost block name ('' = classic content).
	 */
	public static function content_images( $content ) {
		$content = (string) $content;
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$ranges = self::block_ranges( $content );
		$out    = array();
		foreach ( $matches[0] as $match ) {
			$tag    = (string) $match[0];
			$offset = (int) $match[1];
			$attrs  = self::attributes( $tag );
			$src    = (string) ( $attrs['src'] ?? ( $attrs['data-src'] ?? ( $attrs['data-lazy-src'] ?? '' ) ) );
			if ( '' === trim( $src ) ) {
				continue;
			}
			$id = 0;
			if ( isset( $attrs['class'] ) && preg_match( '/\bwp-image-(\d+)\b/', $attrs['class'], $m ) ) {
				$id = (int) $m[1];
			} elseif ( isset( $attrs['data-id'] ) && ctype_digit( $attrs['data-id'] ) ) {
				$id = (int) $attrs['data-id'];
			}
			$out[] = array(
				'offset'  => $offset,
				'tag'     => $tag,
				'src'     => html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ),
				'id'      => $id,
				'alt'     => array_key_exists( 'alt', $attrs ) ? html_entity_decode( (string) $attrs['alt'], ENT_QUOTES, 'UTF-8' ) : null,
				'caption' => self::caption_after( $content, $offset + strlen( $tag ) ),
				'block'   => self::block_at( $ranges, $offset ),
			);
		}
		return $out;
	}

	/**
	 * Byte ranges of the blocks in content: [ start, end, name ] (names with
	 * their namespace, core/ added when missing). Self-closing blocks have no
	 * inner content and are left out.
	 *
	 * @param string $content Content.
	 * @return array<int,array{0:int,1:int,2:string}>
	 */
	public static function block_ranges( $content ) {
		$content = (string) $content;
		if ( false === strpos( $content, '<!-- wp:' ) || ! preg_match_all( '#' . ContentEditor::DELIMITER . '#s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$stack  = array();
		$ranges = array();
		foreach ( $matches as $m ) {
			$namespace = isset( $m['namespace'] ) && $m['namespace'][1] >= 0 && '' !== $m['namespace'][0] ? $m['namespace'][0] : 'core/';
			$name      = $namespace . $m['name'][0];
			$closer    = isset( $m['closer'] ) && $m['closer'][1] >= 0 && '' !== $m['closer'][0];
			$void      = isset( $m['void'] ) && $m['void'][1] >= 0 && '' !== $m['void'][0];
			if ( $void ) {
				continue;
			}
			if ( ! $closer ) {
				$stack[] = array( (int) $m[0][1], $name );
				continue;
			}
			// Close the innermost open block of that name (tolerates broken nesting).
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ][1] === $name ) {
					$ranges[] = array( $stack[ $i ][0], (int) $m[0][1] + strlen( $m[0][0] ), $name );
					array_splice( $stack, $i );
					break;
				}
			}
		}
		return $ranges;
	}

	/**
	 * Innermost block containing an offset ('' = none: classic content).
	 *
	 * @param array $ranges Block ranges.
	 * @param int   $offset Offset.
	 * @return string
	 */
	public static function block_at( array $ranges, $offset ) {
		$best  = '';
		$start = -1;
		foreach ( $ranges as $range ) {
			if ( $range[0] <= $offset && $offset < $range[1] && $range[0] > $start ) {
				$start = $range[0];
				$best  = $range[2];
			}
		}
		return $best;
	}

	/**
	 * Whether an <img> inside this block (or in classic content) may be edited.
	 *
	 * @param string $block Block name ('' = classic content).
	 * @return bool
	 */
	public static function editable_block( $block ) {
		return '' === (string) $block || 'core/freeform' === $block || in_array( (string) $block, self::IMAGE_BLOCKS, true );
	}

	/**
	 * Attributes of one tag (lower-case names; bare attributes → '').
	 *
	 * @param string $tag Tag.
	 * @return array<string,string>
	 */
	public static function attributes( $tag ) {
		$attrs = array();
		foreach ( self::attribute_spans( $tag ) as $name => $span ) {
			$attrs[ $name ] = $span['value'];
		}
		return $attrs;
	}

	/**
	 * Attribute tokens of an <img> tag with their byte position in the tag
	 * (quoted values are consumed whole, so text inside a value is never
	 * mistaken for an attribute).
	 *
	 * @param string $tag Tag.
	 * @return array<string,array{offset:int,length:int,value:string}>
	 */
	public static function attribute_spans( $tag ) {
		$tag   = (string) $tag;
		$spans = array();
		if ( ! preg_match( '/^<img\b/i', $tag ) ) {
			return $spans;
		}
		$inner = substr( $tag, 4 );
		if ( preg_match_all( '/([^\s=\/"\'>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $inner, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $attr ) {
				$name = strtolower( $attr[1][0] );
				if ( isset( $spans[ $name ] ) ) {
					continue;
				}
				$value = '';
				foreach ( array( 2, 3, 4 ) as $i ) {
					if ( isset( $attr[ $i ] ) && $attr[ $i ][1] >= 0 && '' !== $attr[ $i ][0] ) {
						$value = $attr[ $i ][0];
						break;
					}
				}
				$spans[ $name ] = array(
					'offset' => 4 + (int) $attr[0][1],
					'length' => strlen( $attr[0][0] ),
					'value'  => $value,
				);
			}
		}
		return $spans;
	}

	/**
	 * Text of the <figcaption> that belongs to the image ending at $from:
	 * the next figcaption before the figure closes and before another image.
	 *
	 * @param string $content Content.
	 * @param int    $from    Offset after the image tag.
	 * @return string
	 */
	public static function caption_after( $content, $from ) {
		$rest  = substr( (string) $content, (int) $from, 4000 );
		$close = stripos( $rest, '</figure' );
		if ( false === $close ) {
			return '';
		}
		$scope = substr( $rest, 0, $close );
		$next  = stripos( $scope, '<img' );
		if ( false !== $next ) {
			$scope = substr( $scope, 0, $next );
		}
		if ( ! preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $scope, $m ) ) {
			return '';
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Sets the alt attribute of an <img> tag (replacing an empty one).
	 *
	 * @param string $tag Tag.
	 * @param string $alt ALT text.
	 * @return string
	 */
	public static function set_alt( $tag, $alt ) {
		$tag     = (string) $tag;
		$escaped = 'alt="' . esc_attr( (string) $alt ) . '"';
		$spans   = self::attribute_spans( $tag );
		if ( ! $spans && ! preg_match( '/^<img\b/i', $tag ) ) {
			return $tag;
		}
		if ( isset( $spans['alt'] ) ) {
			if ( '' !== trim( html_entity_decode( $spans['alt']['value'], ENT_QUOTES, 'UTF-8' ) ) ) {
				return $tag; // Existing ALT text is never changed.
			}
			return substr_replace( $tag, $escaped, $spans['alt']['offset'], $spans['alt']['length'] );
		}
		return '<img ' . $escaped . substr( $tag, 4 );
	}

	/**
	 * Applies tag replacements (offset => [old, new]) from the end backwards.
	 *
	 * @param string $content Content.
	 * @param array  $replace Replacements.
	 * @return string
	 */
	public static function apply_replacements( $content, array $replace ) {
		krsort( $replace );
		foreach ( $replace as $offset => $pair ) {
			list( $old, $new ) = $pair;
			if ( substr( $content, (int) $offset, strlen( $old ) ) === $old ) {
				$content = substr_replace( $content, $new, (int) $offset, strlen( $old ) );
			}
		}
		return $content;
	}

	/**
	 * First usable source.
	 *
	 * @param string[] $sources Sources in priority order.
	 * @return string
	 */
	public static function choose( array $sources ) {
		foreach ( $sources as $source ) {
			$source = trim( (string) preg_replace( '/\s+/u', ' ', (string) $source ) );
			if ( '' !== $source && preg_match( '/\p{L}/u', $source ) ) {
				return Text::truncate( $source, self::MAX_ALT, '' );
			}
		}
		return '';
	}

	/**
	 * Media-library ALT from the attachment's own data only (caption, title,
	 * file name) — never from one article's text, because every post that
	 * uses the image shares it.
	 *
	 * @param string $caption Attachment caption.
	 * @param string $title   Attachment title.
	 * @param string $file    Attachment file path or URL.
	 * @return string
	 */
	public static function media_alt( $caption, $title, $file ) {
		return self::choose(
			array(
				self::clean_caption( (string) $caption ),
				self::alt_from_text( (string) $title ),
				self::alt_from_filename( (string) $file ),
			)
		);
	}

	/**
	 * A caption cleaned for use as ALT text (photo credits removed).
	 *
	 * @param string $caption Caption.
	 * @return string
	 */
	public static function clean_caption( $caption ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $caption ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $text ) {
			return '';
		}
		$credit = '(?:photo|photos|image|picture|pic|pics|file photo|credit|credits|courtesy|source|via|ಚಿತ್ರ|ಫೋಟೋ|ಕೃಪೆ|ಚಿತ್ರ ಕೃಪೆ|फोटो|चित्र)';
		// Trailing "(Photo: PTI)", "[Courtesy X]", "| Photo credit: X", "— Image: X".
		$text = (string) preg_replace( '/\s*[\(\[]\s*' . $credit . '\b[^\)\]]*[\)\]]\s*$/iu', '', $text );
		$text = (string) preg_replace( '/\s*[|\x{2013}\x{2014}\-\/]\s*' . $credit . '\b(?:\s*credit)?\s*[:\x{FF1A}\-]?.*$/iu', '', $text );
		$text = (string) preg_replace( '/^' . $credit . '\b(?:\s*credit)?\s*[:\x{FF1A}]\s*\S.*$/iu', '', $text );
		$text = trim( $text, " \t\n\r\0\x0B|-:" );
		return preg_match( '/\p{L}{2,}/u', $text ) ? $text : '';
	}

	/**
	 * ALT text from an attachment title ('' for camera/file names and hashes).
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function alt_from_text( $title ) {
		$title = trim( (string) preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|heic|bmp|tiff?)$/i', '', html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$plain = trim( (string) preg_replace( '/[\s_\-+]+/u', ' ', $title ) );
		return self::usable( $plain ) ? $plain : '';
	}

	/**
	 * ALT text from a file name or URL: de-slugified, size and edit suffixes
	 * removed ('' when the name says nothing about the image).
	 *
	 * @param string $file Path or URL.
	 * @return string
	 */
	public static function alt_from_filename( $file ) {
		$path = (string) $file;
		$url  = wp_parse_url( $path, PHP_URL_PATH );
		if ( is_string( $url ) && '' !== $url ) {
			$path = $url;
		}
		$name = rawurldecode( (string) preg_replace( '/[?#].*$/', '', basename( str_replace( '\\', '/', $path ) ) ) );
		$name = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name );
		// Repeated: photo-scaled-1024x768 → photo.
		for ( $i = 0; $i < 4; $i++ ) {
			$name = (string) preg_replace( '/(?:[-_]\d{2,5}x\d{2,5}|[-_](?:scaled|rotated|edited|thumb|thumbnail|large|medium|small|copy|final|min|compressed|optimized|webp|resized)|[-_]e\d{10,13}|@\d(?:\.\d)?x|[-_]\d{1,2})$/i', '', $name );
		}
		$plain = trim( (string) preg_replace( '/[\s_\-+.]+/u', ' ', $name ) );
		if ( ! self::usable( $plain ) ) {
			return '';
		}
		// "monsoon rain bengaluru" → "Monsoon rain bengaluru" (Latin only; other scripts have no case).
		return mb_strtoupper( mb_substr( $plain, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $plain, 1, null, 'UTF-8' );
	}

	/**
	 * Whether a de-slugified name describes something (not a camera name,
	 * screenshot label, number or hash).
	 *
	 * @param string $plain Plain words.
	 * @return bool
	 */
	public static function usable( $plain ) {
		$plain = trim( (string) $plain );
		if ( '' === $plain || ! preg_match( '/\p{L}{2,}/u', $plain ) ) {
			return false;
		}
		if ( preg_match( '/^(img|image|images|dsc|dscn|dscf|pxl|photo|pic|picture|screenshot|screen shot|whatsapp image|untitled|scan|mvimg|gopr|dji|wp|file|download|unnamed|default|placeholder|capture|snapshot|signal|telegram|fb img|insta|ig|cover|banner|featured|thumbnail|thumb)[\s\d:.,()]*(at[\s\d:.,()apm]*)?$/i', $plain ) ) {
			return false;
		}
		if ( preg_match( '/^[a-f0-9 ]{12,}$/i', $plain ) || preg_match( '/^[a-z]{0,4}\s?\d[\d\s]{5,}$/i', $plain ) ) {
			return false; // Hashes and camera-style numbers (e.g. "IMG 20240101 123456").
		}
		// Needs at least one word of three or more letters.
		return (bool) preg_match( '/[\p{L}\p{M}]{3,}/u', $plain );
	}

	/**
	 * Short file label for diffs.
	 *
	 * @param string $file Path or URL.
	 * @return string
	 */
	public static function file_label( $file ) {
		$path = wp_parse_url( (string) $file, PHP_URL_PATH );
		$name = rawurldecode( basename( str_replace( '\\', '/', is_string( $path ) && '' !== $path ? $path : (string) $file ) ) );
		return '' !== $name ? $name : __( 'image', 'searchpilot-pro' );
	}
}

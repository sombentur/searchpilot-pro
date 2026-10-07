<?php
/**
 * Planner for missing_featured_image.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

defined( 'ABSPATH' ) || exit;

/**
 * Promotes the first media-library image in the content to featured
 * image (assisted: a featured image changes how the article looks on the
 * site, in social cards and in Top stories). Images in the stored content
 * come first; images the page shows from shortcodes, galleries or a page
 * builder (recorded by the analysis) are the fallback. Without any image
 * there is nothing to promote and the planner says so.
 */
class FeaturedImageFixer extends AbstractFixer {

	const MAX_URL_LOOKUPS = 5;

	/**
	 * Fix mode for an issue: advisory when the page shows no image at all
	 * (nothing to promote), else assisted.
	 *
	 * @param array $issue Issue.
	 * @return string assisted|advisory
	 */
	public function mode( array $issue ) {
		$post = get_post( (int) ( $issue['object_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return 'advisory';
		}
		$candidates = array_filter( array_map( 'intval', (array) ( $issue['data']['candidates'] ?? array() ) ) );
		return $candidates || false !== stripos( (string) $post->post_content, '<img' ) ? 'assisted' : 'advisory';
	}

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
		$current = (int) get_post_thumbnail_id( $post );
		if ( $current && wp_attachment_is_image( $current ) ) {
			return new \WP_Error( 'searchpilot_nothing_to_fix', __( 'The post has a featured image now. Re-check the page to close this issue.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$id = $this->first_image( $post, (array) ( $issue['data']['candidates'] ?? array() ) );
		if ( ! $id ) {
			return new \WP_Error(
				'searchpilot_no_image',
				__( 'The post contains no image from the media library that could become its featured image. Upload a relevant photo (at least 1200 px wide) and set it as the featured image in the editor sidebar.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$meta       = wp_get_attachment_metadata( $id );
		$width      = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$file_label = AltTextFixer::file_label( (string) get_attached_file( $id ) );
		$label      = $width && is_array( $meta ) ? sprintf( '%s (%d × %d px)', $file_label, $width, (int) ( $meta['height'] ?? 0 ) ) : $file_label;

		$plan              = self::new_plan(
			$issue,
			/* translators: 1: file name, 2: post title. */
			sprintf( __( 'Use %1$s, the first image in the content, as the featured image of “%2$s”', 'searchpilot-pro' ), $file_label, self::title_of( $post ) ),
			'post',
			$post->ID
		);
		$plan['changes'][] = self::change(
			'featured_image',
			$post->ID,
			'_thumbnail_id',
			__( 'Featured image', 'searchpilot-pro' ),
			__( '(none)', 'searchpilot-pro' ),
			$label,
			$id,
			get_post_meta( $post->ID, '_thumbnail_id', true )
		);
		// A featured image without ALT text would open missing_alt_text (and the fix
		// engine would undo this fix): fill its EMPTY media-library ALT in the same
		// plan — from the attachment's own data only, because that ALT is shared by
		// every post using the image (this article's alt attribute or caption never
		// becomes the site-wide ALT).
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$file = get_attached_file( $id );
			$alt  = AltTextFixer::media_alt( (string) wp_get_attachment_caption( $id ), get_the_title( $id ), $file ? $file : (string) wp_get_attachment_url( $id ) );
			if ( '' !== $alt ) {
				$plan['changes'][] = self::change(
					'attachment_alt',
					$id,
					'_wp_attachment_image_alt',
					/* translators: %s: file name. */
					sprintf( __( 'Media library ALT text — every post that uses %s', 'searchpilot-pro' ), $file_label ),
					__( '(empty)', 'searchpilot-pro' ),
					$alt,
					$alt,
					''
				);
			} else {
				$plan['warnings'][] = __( 'This image has no ALT text in the media library, and its caption, title and file name do not describe it. Add an ALT text in Media → Library first — otherwise the page reports a featured image without ALT text and this fix is undone automatically.', 'searchpilot-pro' );
			}
		}
		if ( $width && $width < 1200 ) {
			/* translators: %d: width in pixels. */
			$plan['warnings'][] = sprintf( __( 'This image is %d px wide. Google recommends at least 1200 px for article rich results and Discover — a larger version would be better.', 'searchpilot-pro' ), $width );
		}
		$plan['warnings'][] = __( 'The image also stays in the article. If your theme shows the featured image above the text, the picture may appear twice — then remove it from the content.', 'searchpilot-pro' );
		return $plan;
	}

	/**
	 * First media-library image of the post.
	 *
	 * @param \WP_Post $post       Post.
	 * @param int[]    $candidates Image ids the analysis saw on the page, in order.
	 * @return int Attachment id (0 = none).
	 */
	private function first_image( \WP_Post $post, array $candidates ) {
		$lookups = 0;
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$ids     = array();
		foreach ( AltTextFixer::content_images( (string) $post->post_content ) as $image ) {
			$id = (int) $image['id'];
			if ( ! $id && '' !== $base && $lookups < self::MAX_URL_LOOKUPS && 0 === strpos( set_url_scheme( $image['src'] ), set_url_scheme( $base ) ) ) {
				++$lookups;
				$id = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', strtok( $image['src'], '?' ) ) );
			}
			if ( $id ) {
				$ids[] = $id;
			}
		}
		foreach ( $candidates as $id ) {
			$ids[] = (int) $id;
		}
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
				return (int) $id;
			}
		}
		return 0;
	}
}

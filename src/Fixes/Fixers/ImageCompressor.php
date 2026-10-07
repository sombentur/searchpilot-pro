<?php
/**
 * Fixer for large_images: re-compresses oversized media-library originals.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Logger;
use SearchPilot\Seo\Analysis\Analyzer;
use SearchPilot\Seo\Analysis\ContentRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress serves resized copies for most screens, but the original file is
 * still in every srcset (high-density screens pick it) and is the og:image.
 * Originals above the large-image threshold are re-encoded with WordPress's
 * own image editor: same format, same address, stepped quality (and, only
 * when quality alone is not enough, a smaller width). The untouched original
 * is kept next to the file, so every compression can be undone.
 *
 * PNG originals (photos and slides saved as PNG are several times larger than
 * they need to be) are converted to WebP instead: the attachment points to
 * the new .webp file with freshly generated sizes, the post that uses it is
 * updated to the new addresses, and every old .png file stays on the server,
 * so existing links, social-media previews and other posts keep working.
 *
 * GIF, SVG and AVIF are left to a person.
 */
class ImageCompressor extends AbstractFixer {

	const HANDLER     = 'image_compressed';
	const CONVERTED   = 'image_converted';
	const OLD_FILES   = '_searchpilot_image_old_files';
	const BACKUP_META = '_searchpilot_image_backup';
	const SUFFIX      = '-spp-original';
	const MIMES       = array( 'image/jpeg', 'image/webp' );
	const QUALITIES   = array( 82, 74, 66 );
	const MAX_WIDTHS  = array( 2560, 1920, 1600, 1280 );

	/**
	 * Fix mode: automatic when at least one flagged file can be re-encoded here.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function mode( array $issue ) {
		foreach ( self::ids_of( $issue ) as $id ) {
			if ( true === $this->compressible( $id ) ) {
				return 'deterministic';
			}
		}
		return 'advisory';
	}

	/**
	 * Legacy fixer entry point (runs through FixEngine::apply_legacy()).
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix( array $issue ) {
		$done   = 0;
		$errors = array();
		foreach ( self::ids_of( $issue ) as $id ) {
			$able = $this->compressible( $id );
			if ( true !== $able ) {
				$errors[] = $able;
				continue;
			}
			$result = $this->compress( $id, (array) $issue );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$done;
			}
		}
		if ( ! $done ) {
			return new \WP_Error(
				'searchpilot_nothing_to_compress',
				$errors ? implode( ' ', array_unique( $errors ) ) : __( 'None of these images can be compressed automatically.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		return true;
	}

	/**
	 * Attachment ids of an issue that are still above the threshold.
	 *
	 * @param array $issue Issue.
	 * @return int[]
	 */
	private static function ids_of( array $issue ) {
		return array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $issue['data']['attachments'] ?? array() ) ) ) ) ), 0, 20 );
	}

	/**
	 * Whether an attachment's file can be re-encoded on this server.
	 *
	 * @param int $id Attachment id.
	 * @return true|string True, or the reason it cannot.
	 */
	public function compressible( $id ) {
		$id   = (int) $id;
		$file = 'attachment' === get_post_type( $id ) ? get_attached_file( $id ) : '';
		if ( ! $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return __( 'The image file is missing.', 'searchpilot-pro' );
		}
		$name = wp_basename( $file );
		$mime = (string) get_post_mime_type( $id );
		$png  = 'image/png' === $mime;
		if ( ! $png && ! in_array( $mime, self::MIMES, true ) ) {
			/* translators: %s: file name. */
			return sprintf( __( '%s is not a JPEG, WebP or PNG file; compress it by hand.', 'searchpilot-pro' ), $name );
		}
		if ( (int) filesize( $file ) <= Analyzer::LARGE_IMAGE_BYTES ) {
			return __( 'The image is already small enough.', 'searchpilot-pro' );
		}
		if ( ! wp_is_writable( dirname( $file ) ) || ! wp_is_writable( $file ) ) {
			/* translators: %s: file name. */
			return sprintf( __( '%s cannot be written by WordPress.', 'searchpilot-pro' ), $name );
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) || ( $png && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) ) {
			/* translators: %s: file name. */
			return sprintf( __( 'This server cannot re-encode %s.', 'searchpilot-pro' ), $name );
		}
		return true;
	}

	/**
	 * The file to encode from: the attached file, or the untouched upload when the attached
	 * (scaled) file is damaged and cannot be read.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $file Attached file.
	 * @return string
	 */
	private static function source_of( $id, $file ) {
		if ( ! is_wp_error( wp_get_image_editor( $file ) ) ) {
			return $file;
		}
		$original = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( (int) $id ) : '';
		if ( '' !== $original && $original !== $file && is_file( $original ) && ! is_wp_error( wp_get_image_editor( $original ) ) ) {
			return $original;
		}
		return $file;
	}

	/**
	 * Error for a file the image library cannot open (details go to the log, without paths in the message).
	 *
	 * @param string    $file  File.
	 * @param \WP_Error $error Library error.
	 * @return \WP_Error
	 */
	private static function unreadable( $file, $error ) {
		Logger::instance()->warning( 'automation', sprintf( 'Image %s could not be opened: %s', wp_basename( $file ), $error->get_error_message() ) );
		/* translators: %s: file name. */
		return new \WP_Error( 'searchpilot_compress_failed', sprintf( __( '%s cannot be opened by the image library of this server (the file may be damaged). Upload the image again.', 'searchpilot-pro' ), wp_basename( $file ) ) );
	}

	/**
	 * Encodes a file below the threshold: stepped quality, then smaller widths.
	 *
	 * @param string $file Source file.
	 * @param string $dest Destination path.
	 * @param string $mime Output MIME type.
	 * @return array|\WP_Error { path, bytes, quality, width, height }
	 */
	private static function encode( $file, $dest, $mime ) {
		$target = (int) floor( Analyzer::LARGE_IMAGE_BYTES * 0.97 );
		$error  = null;
		foreach ( array_merge( array( 0 ), self::MAX_WIDTHS ) as $max_width ) {
			foreach ( self::QUALITIES as $quality ) {
				$editor = wp_get_image_editor( $file );
				if ( is_wp_error( $editor ) ) {
					return self::unreadable( $file, $editor );
				}
				$size = (array) $editor->get_size();
				if ( $max_width && (int) ( $size['width'] ?? 0 ) <= $max_width ) {
					continue; // Already narrower than this step.
				}
				if ( ! $max_width && (int) ( $size['width'] ?? 0 ) > self::MAX_WIDTHS[0] ) {
					continue; // An untouched upload wider than WordPress serves: start at the first width.
				}
				if ( $max_width && is_wp_error( $editor->resize( $max_width, null, false ) ) ) {
					continue;
				}
				$editor->set_quality( $quality );
				// When the format changes, WordPress resets the quality to its default while
				// saving; this filter keeps the step being tried.
				$keep = static function () use ( $quality ) {
					return $quality;
				};
				add_filter( 'wp_editor_set_quality', $keep, 9999 );
				$saved = $editor->save( $dest, $mime );
				remove_filter( 'wp_editor_set_quality', $keep, 9999 );
				if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
					$error = is_wp_error( $saved ) ? $saved : $error;
					continue;
				}
				clearstatcache( true, $saved['path'] );
				$bytes = (int) filesize( $saved['path'] );
				if ( $bytes > 0 && $bytes <= $target ) {
					return array(
						'path'    => (string) $saved['path'],
						'bytes'   => $bytes,
						'quality' => $quality,
						'width'   => (int) ( $saved['width'] ?? 0 ),
						'height'  => (int) ( $saved['height'] ?? 0 ),
					);
				}
				wp_delete_file( $saved['path'] );
			}
		}
		if ( $error ) {
			/* translators: 1: file name, 2: error message of the image library. */
			return new \WP_Error( 'searchpilot_compress_failed', sprintf( __( '%1$s could not be re-encoded on this server: %2$s', 'searchpilot-pro' ), wp_basename( $file ), str_replace( array( trailingslashit( dirname( $file ) ), A\PATH ), '', $error->get_error_message() ) ) );
		}
		/* translators: %s: file name. */
		return new \WP_Error( 'searchpilot_compress_failed', sprintf( __( '%s could not be brought under 300 KB without visible loss; replace it by hand.', 'searchpilot-pro' ), wp_basename( $file ) ) );
	}

	/**
	 * Converts a PNG attachment to WebP (new file and sizes; the PNG files stay).
	 *
	 * @param int   $id    Attachment id.
	 * @param array $issue Issue (its post gets the new addresses).
	 * @return array|\WP_Error { before, after, quality, width }
	 */
	public function convert( $id, array $issue = array() ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id       = (int) $id;
		$file     = (string) get_attached_file( $id );
		$before   = (int) filesize( $file );
		$dir      = dirname( $file );
		$old_rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$old_meta = wp_get_attachment_metadata( $id );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$old_url  = (string) wp_get_attachment_url( $id );
		$source   = self::source_of( $id, $file );
		$name     = wp_unique_filename( $dir, pathinfo( $source, PATHINFO_FILENAME ) . '.webp' );
		$best     = self::encode( $source, $dir . '/' . $name, 'image/webp' );
		if ( is_wp_error( $best ) ) {
			return $best;
		}
		$new_file = (string) $best['path'];

		update_attached_file( $id, $new_file );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/webp',
			)
		);
		$new_meta = wp_generate_attachment_metadata( $id, $new_file );
		if ( ! is_array( $new_meta ) || empty( $new_meta['file'] ) ) {
			// Put everything back: the attachment must never be left half converted.
			update_post_meta( $id, '_wp_attached_file', $old_rel );
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => 'image/png',
				)
			);
			wp_delete_file( $new_file );
			return new \WP_Error( 'searchpilot_compress_failed', __( 'WordPress could not create the image sizes for the converted file, so the image was left as it is.', 'searchpilot-pro' ) );
		}
		wp_update_attachment_metadata( $id, $new_meta );
		clean_post_cache( $id );

		// Old file name => new file name, for the addresses used inside posts.
		$map = array( wp_basename( $old_rel ) => wp_basename( $new_file ) );
		foreach ( (array) ( $old_meta['sizes'] ?? array() ) as $size => $data ) {
			if ( ! empty( $data['file'] ) ) {
				$map[ (string) $data['file'] ] = ! empty( $new_meta['sizes'][ $size ]['file'] ) ? (string) $new_meta['sizes'][ $size ]['file'] : wp_basename( $new_file );
			}
		}
		if ( ! empty( $old_meta['original_image'] ) ) {
			$map[ (string) $old_meta['original_image'] ] = wp_basename( $new_file );
		}
		update_post_meta( $id, self::OLD_FILES, array_keys( $map ) );

		$this->get( Changes::class )->record(
			self::CONVERTED,
			array(
				'action'      => 'image_converted',
				'object_type' => 'attachment',
				'object_id'   => $id,
				/* translators: 1: old file name, 2: size before, 3: new file name, 4: size after. */
				'description' => sprintf( __( 'Converted %1$s (%2$s KB) to %3$s (%4$s KB). The PNG file is kept, so existing links keep working.', 'searchpilot-pro' ), wp_basename( $old_rel ), number_format_i18n( $before / 1024 ), wp_basename( $new_file ), number_format_i18n( $best['bytes'] / 1024 ) ) . ( $source !== $file ? ' ' . __( 'The scaled file was damaged, so the original upload was used.', 'searchpilot-pro' ) : '' ),
				'before'      => array(
					'file' => $old_rel,
					'mime' => 'image/png',
					'meta' => $old_meta,
				),
				'after'       => array(
					'file'     => (string) get_post_meta( $id, '_wp_attached_file', true ),
					'filesize' => (int) $best['bytes'],
					'quality'  => (int) $best['quality'],
				),
				'source'      => (string) ( $issue['source'] ?? 'user' ),
			)
		);

		// The post of the issue: point its image addresses at the new files.
		$post_id = 'post' === ( $issue['object_type'] ?? '' ) ? (int) ( $issue['object_id'] ?? 0 ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( $post && '' !== $old_url && '' === ContentRenderer::builder_of( $post ) ) {
			$base    = trailingslashit( dirname( $old_url ) );
			$content = (string) $post->post_content;
			$updated = $content;
			foreach ( $map as $old_name => $new_name ) {
				$updated = str_replace( $base . $old_name, $base . $new_name, $updated );
			}
			if ( $updated !== $content ) {
				$this->get( Changes::class )->update_post_content(
					$post_id,
					$updated,
					array(
						'action'      => 'image_addresses_updated',
						/* translators: 1: post title, 2: file name. */
						'description' => sprintf( __( 'Updated image addresses in “%1$s” to the converted %2$s', 'searchpilot-pro' ), get_the_title( $post ), wp_basename( $new_file ) ),
						'source'      => (string) ( $issue['source'] ?? 'user' ),
					)
				);
			}
		}
		return array(
			'before'  => $before,
			'after'   => (int) $best['bytes'],
			'quality' => (int) $best['quality'],
			'width'   => (int) ( $new_meta['width'] ?? 0 ),
		);
	}

	/**
	 * Revert handler for conversions: the attachment points to its PNG again.
	 * The WebP files stay on the server (posts may still use their addresses).
	 *
	 * @param array  $before Before data.
	 * @param array  $after  After data.
	 * @param object $row    Activity row.
	 * @param bool   $force  Forced undo.
	 * @return true|\WP_Error
	 */
	public static function revert_conversion( $before, $after, $row, $force = false ) {
		unset( $after, $force );
		$id  = (int) ( $row->object_id ?? 0 );
		$rel = (string) ( $before['file'] ?? '' );
		$dir = wp_get_upload_dir();
		if ( ! $id || 'attachment' !== get_post_type( $id ) || '' === $rel || 0 !== validate_file( $rel ) || ! is_file( trailingslashit( $dir['basedir'] ) . $rel ) ) {
			return new \WP_Error( 'searchpilot_undo_failed', __( 'The original PNG file is no longer on the server, so the conversion cannot be undone.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		update_post_meta( $id, '_wp_attached_file', $rel );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/png',
			)
		);
		if ( is_array( $before['meta'] ?? null ) && $before['meta'] ) {
			wp_update_attachment_metadata( $id, $before['meta'] );
		}
		delete_post_meta( $id, self::OLD_FILES );
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Re-encodes one attachment below the threshold and keeps the original.
	 *
	 * @param int   $id    Attachment id.
	 * @param array $issue Issue (for the activity entry).
	 * @return array|\WP_Error { before, after, quality, width }
	 */
	public function compress( $id, array $issue = array() ) {
		$id     = (int) $id;
		$file   = (string) get_attached_file( $id );
		$mime   = (string) get_post_mime_type( $id );
		if ( 'image/png' === $mime ) {
			return $this->convert( $id, $issue );
		}
		$before = (int) filesize( $file );
		$info   = pathinfo( $file );
		$ext    = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$temp   = $info['dirname'] . '/' . $info['filename'] . '-spp-tmp' . $ext;
		$backup = $info['dirname'] . '/' . $info['filename'] . self::SUFFIX . $ext;
		$best   = self::encode( self::source_of( $id, $file ), $temp, $mime );
		if ( is_wp_error( $best ) ) {
			return $best;
		}

		// Keep the untouched original (never overwrite an earlier backup: it is the true original).
		if ( ! is_file( $backup ) && ! copy( $file, $backup ) ) {
			wp_delete_file( $best['path'] );
			return new \WP_Error( 'searchpilot_backup_failed', __( 'The original image could not be backed up, so it was left as it is.', 'searchpilot-pro' ) );
		}
		if ( ! copy( $best['path'], $file ) ) {
			wp_delete_file( $best['path'] );
			return new \WP_Error( 'searchpilot_compress_failed', __( 'The compressed image could not be saved.', 'searchpilot-pro' ) );
		}
		wp_delete_file( $best['path'] );
		clearstatcache( true, $file );

		$meta      = wp_get_attachment_metadata( $id );
		$meta      = is_array( $meta ) ? $meta : array();
		$old_meta  = array(
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
			'filesize' => (int) ( $meta['filesize'] ?? $before ),
		);
		$meta['filesize'] = (int) filesize( $file );
		if ( $best['width'] && $best['height'] && $old_meta['width'] && $best['width'] < $old_meta['width'] ) {
			$meta['width']  = $best['width'];
			$meta['height'] = $best['height'];
		}
		wp_update_attachment_metadata( $id, $meta );
		update_post_meta(
			$id,
			self::BACKUP_META,
			array(
				'file'   => wp_basename( $backup ),
				'bytes'  => $before,
				'meta'   => $old_meta,
				'at'     => gmdate( 'c' ),
			)
		);

		$this->get( Changes::class )->record(
			self::HANDLER,
			array(
				'action'      => 'image_compressed',
				'object_type' => 'attachment',
				'object_id'   => $id,
				/* translators: 1: file name, 2: size before, 3: size after. */
				'description' => sprintf( __( 'Compressed %1$s from %2$s KB to %3$s KB (the original is kept).', 'searchpilot-pro' ), wp_basename( $file ), number_format_i18n( $before / 1024 ), number_format_i18n( $meta['filesize'] / 1024 ) ),
				'before'      => array(
					'backup' => wp_basename( $backup ),
					'meta'   => $old_meta,
				),
				'after'       => array(
					'filesize' => (int) $meta['filesize'],
					'quality'  => (int) $best['quality'],
					'width'    => (int) ( $meta['width'] ?? 0 ),
				),
				'source'      => (string) ( $issue['source'] ?? 'user' ),
			)
		);
		clean_post_cache( $id );
		return array(
			'before'  => $before,
			'after'   => (int) $meta['filesize'],
			'quality' => (int) $best['quality'],
			'width'   => (int) ( $meta['width'] ?? 0 ),
		);
	}

	/**
	 * Registers the revert handler.
	 *
	 * @param ActivityLog $log Log.
	 */
	public static function register_handler( ActivityLog $log ) {
		$log->register_handler( self::HANDLER, array( __CLASS__, 'revert' ) );
		$log->register_handler( self::CONVERTED, array( __CLASS__, 'revert_conversion' ) );
	}

	/**
	 * Revert handler: puts the original file back.
	 *
	 * @param array  $before Before data.
	 * @param array  $after  After data.
	 * @param object $row    Activity row.
	 * @param bool   $force  Forced undo.
	 * @return true|\WP_Error
	 */
	public static function revert( $before, $after, $row, $force = false ) {
		unset( $after, $force );
		$id   = (int) ( $row->object_id ?? 0 );
		$file = $id ? (string) get_attached_file( $id ) : '';
		if ( '' === $file ) {
			return new \WP_Error( 'searchpilot_undo_failed', __( 'The image no longer exists.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		// The stored name is only ever a file name next to the attachment (no paths).
		$backup = dirname( $file ) . '/' . wp_basename( (string) ( $before['backup'] ?? '' ) );
		if ( '' === (string) ( $before['backup'] ?? '' ) || false === strpos( wp_basename( $backup ), self::SUFFIX ) || ! is_file( $backup ) ) {
			return new \WP_Error( 'searchpilot_undo_failed', __( 'The original image is no longer on the server, so the compression cannot be undone.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( ! copy( $backup, $file ) ) {
			return new \WP_Error( 'searchpilot_undo_failed', __( 'The original image could not be restored.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		clearstatcache( true, $file );
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$old  = (array) ( $before['meta'] ?? array() );
		foreach ( array( 'width', 'height' ) as $key ) {
			if ( ! empty( $old[ $key ] ) ) {
				$meta[ $key ] = (int) $old[ $key ];
			}
		}
		$meta['filesize'] = (int) filesize( $file );
		wp_update_attachment_metadata( $id, $meta );
		delete_post_meta( $id, self::BACKUP_META );
		wp_delete_file( $backup );
		clean_post_cache( $id );
		return true;
	}
}

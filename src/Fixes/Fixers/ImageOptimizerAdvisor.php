<?php
/**
 * Manual step for large_images.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

defined( 'ABSPATH' ) || exit;

/**
 * Large image files are advisory. SearchPilot never recompresses images
 * itself, and it does not drive another plugin's optimisation API it has
 * not verified (optimisers consume paid quota, replace the original files
 * and keep their own backups). Instead it detects the optimiser in use,
 * reads the setting that explains why the files are still large (for
 * example Imagify's lossless mode or a missing "resize larger images"
 * limit) and names the exact switch and the re-optimise action.
 *
 * Settings are read from the optimisers' stored options:
 *   Imagify     option `imagify_settings` (Imagify_Options defaults):
 *               `lossless` (0/1), `optimization_level` (0–2), `resize_larger`,
 *               `resize_larger_w`, `convert_to_webp`, `convert_to_avif`,
 *               `optimization_format`, `auto_optimize`.
 *   ShortPixel  option `spio_settings` (SettingsModel): `compressionType`
 *               (0 lossless, 1 lossy, 2 glossy), `resizeImages`,
 *               `resizeWidth`; older versions `wp-short-pixel-compression`,
 *               `wp-short-pixel-resize-images`, `wp-short-pixel-resize-width`.
 *   Smush       option `wp-smush-settings`: `lossy` (Super/Ultra Smush), `resize`.
 *   EWWW        option `ewww_image_optimizer_jpg_level` (10/20 lossless,
 *               30/40 lossy), `ewww_image_optimizer_maxmediawidth`.
 * Keys that are missing are simply not reported.
 */
class ImageOptimizerAdvisor extends AbstractFixer {

	/**
	 * The manual step for a large_images issue.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function manual_step( array $issue = array() ) {
		$images = array_slice( array_map( 'strval', (array) ( $issue['data']['images'] ?? array() ) ), 0, 5 );
		$list   = $images ? ' ' . sprintf(
			/* translators: %s: image files with sizes. */
			__( 'Files: %s.', 'searchpilot-pro' ),
			implode( ', ', $images )
		) : '';
		$found = $this->detect();
		if ( ! $found ) {
			return __( 'No image optimisation plugin is active. Compress these images before uploading (JPEG at about 80% quality or WebP, at most 1920 px wide) and replace them in the media library — or install an optimiser such as Imagify, ShortPixel, Smush or EWWW and run its bulk optimisation.', 'searchpilot-pro' ) . $list;
		}
		$text = '' !== $found['current']
			/* translators: 1: plugin name, 2: current settings. */
			? sprintf( __( '%1$s is active (%2$s).', 'searchpilot-pro' ), $found['name'], $found['current'] )
			/* translators: %s: plugin name. */
			: sprintf( __( '%s is active.', 'searchpilot-pro' ), $found['name'] );
		return trim( $text . ' ' . implode( ' ', $found['steps'] ) . $list );
	}

	/**
	 * Active optimiser with its relevant settings and steps.
	 *
	 * @return array{id:string,name:string,current:string,steps:string[]}|null
	 */
	public function detect() {
		if ( defined( 'IMAGIFY_VERSION' ) ) {
			return self::imagify( self::option( 'imagify_settings' ) );
		}
		if ( defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) ) {
			$settings = self::option( 'spio_settings' );
			if ( ! $settings ) {
				$settings = array_filter(
					array(
						'compressionType' => get_option( 'wp-short-pixel-compression', null ),
						'resizeImages'    => get_option( 'wp-short-pixel-resize-images', null ),
						'resizeWidth'     => get_option( 'wp-short-pixel-resize-width', null ),
					),
					static function ( $v ) {
						return null !== $v;
					}
				);
			}
			return self::shortpixel( $settings );
		}
		if ( defined( 'WP_SMUSH_VERSION' ) ) {
			return self::smush( self::option( 'wp-smush-settings' ) );
		}
		if ( defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ) {
			return self::ewww(
				array(
					'jpg_level' => get_option( 'ewww_image_optimizer_jpg_level', null ),
					'max_width' => get_option( 'ewww_image_optimizer_maxmediawidth', null ),
				)
			);
		}
		return null;
	}

	/**
	 * Option as array (site option on multisite when the blog has none).
	 *
	 * @param string $name Option.
	 * @return array
	 */
	private static function option( $name ) {
		$value = get_option( $name, array() );
		if ( ( ! is_array( $value ) || ! $value ) && is_multisite() ) {
			$value = get_site_option( $name, array() );
		}
		return is_array( $value ) ? $value : array();
	}

	/* ------------------------------------------------------------------
	 * Pure logic (unit-tested)
	 * ------------------------------------------------------------------ */

	/**
	 * Imagify advice.
	 *
	 * @param array $s imagify_settings.
	 * @return array
	 */
	public static function imagify( array $s ) {
		$current = array();
		$steps   = array();
		if ( array_key_exists( 'lossless', $s ) ) {
			if ( ! empty( $s['lossless'] ) ) {
				$current[] = __( 'compression: lossless', 'searchpilot-pro' );
				$steps[]   = __( 'In Settings → Imagify, switch off “Lossless compression”: lossless mode barely shrinks photos, while Smart Compression makes them much smaller without visible loss.', 'searchpilot-pro' );
			} else {
				$current[] = __( 'compression: Smart Compression', 'searchpilot-pro' );
			}
		} elseif ( isset( $s['optimization_level'] ) ) {
			$levels    = array(
				0 => __( 'Normal (lossless)', 'searchpilot-pro' ),
				1 => __( 'Aggressive', 'searchpilot-pro' ),
				2 => __( 'Ultra', 'searchpilot-pro' ),
			);
			$level     = (int) $s['optimization_level'];
			$current[] = sprintf(
				/* translators: %s: level name. */
				__( 'optimization level: %s', 'searchpilot-pro' ),
				$levels[ $level ] ?? (string) $level
			);
			if ( 0 === $level ) {
				$steps[] = __( 'In Settings → Imagify, choose the “Aggressive” or “Ultra” optimization level instead of Normal (lossless).', 'searchpilot-pro' );
			}
		}
		if ( array_key_exists( 'resize_larger', $s ) ) {
			if ( empty( $s['resize_larger'] ) || empty( $s['resize_larger_w'] ) ) {
				$current[] = __( '“Resize larger images” off', 'searchpilot-pro' );
				$steps[]   = __( 'Turn on “Resize larger images” with a maximum width of about 1920 px.', 'searchpilot-pro' );
			} else {
				/* translators: %d: width in pixels. */
				$current[] = sprintf( __( 'resize larger images to %d px', 'searchpilot-pro' ), (int) $s['resize_larger_w'] );
			}
		}
		$nextgen = ! empty( $s['convert_to_webp'] ) || ! empty( $s['convert_to_avif'] ) || ( isset( $s['optimization_format'] ) && in_array( (string) $s['optimization_format'], array( 'webp', 'avif' ), true ) && ! empty( $s['display_nextgen'] ) );
		if ( ! $nextgen && ( array_key_exists( 'convert_to_webp', $s ) || array_key_exists( 'optimization_format', $s ) ) ) {
			$current[] = __( 'WebP/AVIF versions not served', 'searchpilot-pro' );
			$steps[]   = __( 'Enable creating and displaying WebP or AVIF versions.', 'searchpilot-pro' );
		}
		$steps[] = __( 'Then re-optimize the files below: Media → Library in list view, Imagify column → “Re-Optimize” (or Media → Bulk Optimization).', 'searchpilot-pro' );
		return array(
			'id'      => 'imagify',
			'name'    => 'Imagify',
			'current' => implode( ', ', $current ),
			'steps'   => $steps,
		);
	}

	/**
	 * ShortPixel advice.
	 *
	 * @param array $s Settings (compressionType, resizeImages, resizeWidth).
	 * @return array
	 */
	public static function shortpixel( array $s ) {
		$current = array();
		$steps   = array();
		if ( isset( $s['compressionType'] ) ) {
			$types     = array(
				0 => __( 'Lossless', 'searchpilot-pro' ),
				1 => __( 'Lossy', 'searchpilot-pro' ),
				2 => __( 'Glossy', 'searchpilot-pro' ),
			);
			$type      = (int) $s['compressionType'];
			$current[] = sprintf(
				/* translators: %s: compression type. */
				__( 'compression: %s', 'searchpilot-pro' ),
				$types[ $type ] ?? (string) $type
			);
			if ( 0 === $type ) {
				$steps[] = __( 'In Settings → ShortPixel → General, change the compression type from Lossless to Lossy or Glossy.', 'searchpilot-pro' );
			}
		}
		if ( array_key_exists( 'resizeImages', $s ) ) {
			if ( empty( $s['resizeImages'] ) ) {
				$current[] = __( '“Resize large images” off', 'searchpilot-pro' );
				$steps[]   = __( 'Turn on “Resize large images” with a maximum width of about 1920 px.', 'searchpilot-pro' );
			} elseif ( ! empty( $s['resizeWidth'] ) ) {
				/* translators: %d: width in pixels. */
				$current[] = sprintf( __( 'resize to %d px', 'searchpilot-pro' ), (int) $s['resizeWidth'] );
			}
		}
		$steps[] = __( 'Then re-optimize the files below: Media → Library in list view, ShortPixel column → “Re-optimize”.', 'searchpilot-pro' );
		return array(
			'id'      => 'shortpixel',
			'name'    => 'ShortPixel',
			'current' => implode( ', ', $current ),
			'steps'   => $steps,
		);
	}

	/**
	 * Smush advice.
	 *
	 * @param array $s wp-smush-settings.
	 * @return array
	 */
	public static function smush( array $s ) {
		$current = array();
		$steps   = array();
		if ( array_key_exists( 'lossy', $s ) ) {
			$level     = (int) $s['lossy'];
			$names     = array(
				0 => __( 'Basic (lossless)', 'searchpilot-pro' ),
				1 => __( 'Super', 'searchpilot-pro' ),
				2 => __( 'Ultra', 'searchpilot-pro' ),
			);
			$current[] = sprintf(
				/* translators: %s: compression level. */
				__( 'compression: %s', 'searchpilot-pro' ),
				$names[ $level ] ?? (string) $level
			);
			if ( 0 === $level ) {
				$steps[] = __( 'In Smush → Bulk Smush → Settings, choose the “Super” (lossy) compression level instead of Basic.', 'searchpilot-pro' );
			}
		}
		if ( array_key_exists( 'resize', $s ) && empty( $s['resize'] ) ) {
			$current[] = __( '“Resize original images” off', 'searchpilot-pro' );
			$steps[]   = __( 'Turn on “Resize original images” with a maximum width of about 1920 px.', 'searchpilot-pro' );
		}
		$steps[] = __( 'Then re-smush the files below: Media → Library in list view, Smush column → “Re-smush” (or run Bulk Smush).', 'searchpilot-pro' );
		return array(
			'id'      => 'smush',
			'name'    => 'Smush',
			'current' => implode( ', ', $current ),
			'steps'   => $steps,
		);
	}

	/**
	 * EWWW advice.
	 *
	 * @param array $s jpg_level, max_width (null when unknown).
	 * @return array
	 */
	public static function ewww( array $s ) {
		$current = array();
		$steps   = array();
		if ( isset( $s['jpg_level'] ) && null !== $s['jpg_level'] ) {
			$level     = (int) $s['jpg_level'];
			$names     = array(
				0  => __( 'no compression', 'searchpilot-pro' ),
				10 => __( 'Pixel Perfect (lossless)', 'searchpilot-pro' ),
				20 => __( 'Pixel Perfect Plus (lossless)', 'searchpilot-pro' ),
				30 => __( 'Premium (lossy)', 'searchpilot-pro' ),
				40 => __( 'Premium Plus (lossy)', 'searchpilot-pro' ),
			);
			$current[] = sprintf(
				/* translators: %s: compression level. */
				__( 'JPG level: %s', 'searchpilot-pro' ),
				$names[ $level ] ?? (string) $level
			);
			if ( $level < 30 ) {
				$steps[] = __( 'In Settings → EWWW Image Optimizer, the JPG level is lossless, which barely shrinks photos: choose a lossy level (Premium) if you have an API key.', 'searchpilot-pro' );
			}
		}
		if ( array_key_exists( 'max_width', $s ) && null !== $s['max_width'] ) {
			if ( (int) $s['max_width'] <= 0 ) {
				$current[] = __( 'resizing off', 'searchpilot-pro' );
				$steps[]   = __( 'Set “Resize Images” to a maximum width of about 1920 px.', 'searchpilot-pro' );
			} else {
				/* translators: %d: width in pixels. */
				$current[] = sprintf( __( 'resize to %d px', 'searchpilot-pro' ), (int) $s['max_width'] );
			}
		}
		$steps[] = __( 'Then re-optimize the files below in Media → Bulk Optimize (or the “Re-optimize” link in the media library list view).', 'searchpilot-pro' );
		return array(
			'id'      => 'ewww',
			'name'    => 'EWWW Image Optimizer',
			'current' => implode( ', ', $current ),
			'steps'   => $steps,
		);
	}
}

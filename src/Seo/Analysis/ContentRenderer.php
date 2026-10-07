<?php
/**
 * Renders post content to the HTML that analysis and link parsing see.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the visible content of a post without running arbitrary code
 * paths: blocks are rendered with do_blocks() (site-structure blocks such as
 * navigation, query loops and comment forms are skipped, embeds become
 * markers and are never fetched), classic content goes through wpautop(),
 * and only safe core shortcodes are expanded ([caption], [gallery]); other
 * shortcodes are unwrapped (enclosed text kept) instead of executed.
 *
 * Page builders that keep their content outside post_content (Elementor,
 * Bricks, Oxygen) are read from their stored data.
 */
class ContentRenderer {

	/**
	 * Blocks that print site structure or other posts, not this post's content.
	 */
	const SKIP_BLOCKS = array( 'core/post-content', 'core/template-part', 'core/navigation', 'core/navigation-link', 'core/navigation-submenu', 'core/query', 'core/latest-posts', 'core/latest-comments', 'core/comments', 'core/comment-template', 'core/post-comments-form', 'core/comments-pagination', 'core/archives', 'core/categories', 'core/tag-cloud', 'core/calendar', 'core/rss', 'core/search', 'core/loginout', 'core/page-list', 'core/site-logo', 'core/site-title', 'core/site-tagline', 'core/post-navigation-link', 'core/query-pagination', 'core/home-link', 'core/social-links' );

	/**
	 * Builders whose post_content cannot be edited safely by SearchPilot.
	 */
	const BUILDERS = array(
		'elementor' => 'Elementor',
		'divi'      => 'Divi',
		'beaver'    => 'Beaver Builder',
		'bricks'    => 'Bricks',
		'oxygen'    => 'Oxygen',
		'avada'     => 'Avada',
		'wpbakery'  => 'WPBakery',
		'thrive'    => 'Thrive Architect',
		'brizy'     => 'Brizy',
	);

	/**
	 * Embeds found during the last render.
	 *
	 * @var int
	 */
	private $embeds = 0;

	/**
	 * Recursion guard.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Analysis HTML for content.
	 *
	 * @param string            $post_content Raw post content (blocks or classic).
	 * @param \WP_Post|int|null $post         Post (for page builders and dynamic blocks).
	 * @param array             $opts         builder (bool, default true): read page-builder data when present.
	 * @return string
	 */
	public function render( $post_content, $post = null, array $opts = array() ) {
		return $this->render_details( $post_content, $post, $opts )['html'];
	}

	/**
	 * Analysis HTML plus rendering facts.
	 *
	 * @param string            $post_content Raw content.
	 * @param \WP_Post|int|null $post         Post.
	 * @param array             $opts         Options (builder: bool).
	 * @return array{html:string,page_builder:string,embeds:int}
	 */
	public function render_details( $post_content, $post = null, array $opts = array() ) {
		$post         = $post ? get_post( $post ) : null;
		$builder      = $post ? self::builder_of( $post ) : '';
		$this->embeds = 0;
		$html         = '';
		$use_builder  = ! isset( $opts['builder'] ) || $opts['builder'];

		if ( $use_builder && $post && $post->ID ) {
			if ( 'elementor' === $builder ) {
				$html = $this->elementor_html( $post );
			} elseif ( 'bricks' === $builder ) {
				$html = $this->bricks_html( $post );
			} elseif ( 'oxygen' === $builder ) {
				$shortcodes = (string) get_post_meta( $post->ID, 'ct_builder_shortcodes', true );
				if ( '' === $shortcodes ) {
					$shortcodes = (string) get_post_meta( $post->ID, '_ct_builder_shortcodes', true );
				}
				$html = $this->shortcodes( $shortcodes );
			}
		}
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			$html = $this->render_content( (string) $post_content, $post );
		}
		return array(
			'html'         => $html,
			'page_builder' => $builder,
			'embeds'       => $this->embeds,
		);
	}

	/**
	 * Page builder that owns a post's layout ('' for the block/classic editor).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public static function builder_of( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $post->ID ) {
			return '';
		}
		$id = (int) $post->ID;
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $id, '_elementor_data', true ) ) {
			return 'elementor';
		}
		if ( 'on' === get_post_meta( $id, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}
		if ( get_post_meta( $id, '_fl_builder_enabled', true ) ) {
			return 'beaver';
		}
		if ( 'bricks' === get_post_meta( $id, '_bricks_editor_mode', true ) ) {
			return 'bricks';
		}
		if ( '' !== (string) get_post_meta( $id, 'ct_builder_shortcodes', true ) || '' !== (string) get_post_meta( $id, '_ct_builder_shortcodes', true ) ) {
			return 'oxygen';
		}
		if ( 'active' === get_post_meta( $id, 'fusion_builder_status', true ) || false !== strpos( (string) $post->post_content, '[fusion_' ) ) {
			return 'avada';
		}
		if ( false !== strpos( (string) $post->post_content, '[vc_row' ) ) {
			return 'wpbakery';
		}
		if ( get_post_meta( $id, 'tcb_editor_enabled', true ) ) {
			return 'thrive';
		}
		if ( get_post_meta( $id, 'brizy_post_uid', true ) ) {
			return 'brizy';
		}
		return '';
	}

	/**
	 * Human name of a builder.
	 *
	 * @param string $builder Builder id.
	 * @return string
	 */
	public static function builder_name( $builder ) {
		return self::BUILDERS[ $builder ] ?? '';
	}

	/**
	 * Whether SearchPilot may edit the post content directly (links).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public static function is_editable( $post ) {
		return '' === self::builder_of( $post );
	}

	/**
	 * Whether the theme prints the post title as a heading (for the H1 check).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public static function theme_prints_title( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $post->ID ) {
			return true;
		}
		$settings = get_post_meta( $post->ID, '_elementor_page_settings', true );
		if ( is_array( $settings ) && isset( $settings['hide_title'] ) && 'yes' === $settings['hide_title'] ) {
			return false;
		}
		$template = (string) get_page_template_slug( $post );
		if ( in_array( $template, array( 'elementor_canvas', 'elementor_header_footer' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Renders block or classic content.
	 *
	 * @param string        $content Content.
	 * @param \WP_Post|null $post    Post.
	 * @return string
	 */
	private function render_content( $content, $post ) {
		if ( '' === trim( $content ) ) {
			return '';
		}
		if ( $this->depth > 2 ) {
			return '';
		}
		++$this->depth;
		$previous = $GLOBALS['post'] ?? null;
		if ( $post instanceof \WP_Post ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		try {
			if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
				add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), 5, 2 );
				try {
					$html = do_blocks( $content );
				} finally {
					remove_filter( 'pre_render_block', array( $this, 'pre_render_block' ), 5 );
				}
			} else {
				$html = $this->mark_embed_lines( $content );
				$html = wpautop( $html );
			}
			$html = shortcode_unautop( $html );
			$html = $this->shortcodes( $html );
			if ( function_exists( 'wp_filter_content_tags' ) ) {
				$html = wp_filter_content_tags( $html, 'searchpilot_analysis' );
			}
		} finally {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			--$this->depth;
		}
		return (string) $html;
	}

	/**
	 * Skips structural blocks and replaces embeds by markers (no HTTP requests).
	 *
	 * @param string|null $pre   Short-circuit value.
	 * @param array       $block Parsed block.
	 * @return string|null
	 */
	public function pre_render_block( $pre, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( in_array( $name, self::SKIP_BLOCKS, true ) ) {
			return '';
		}
		if ( 'core/embed' === $name || 0 === strpos( $name, 'core-embed/' ) ) {
			++$this->embeds;
			$url = (string) ( $block['attrs']['url'] ?? '' );
			return '<figure class="spp-embed" data-embed="' . esc_attr( $url ) . '"></figure>';
		}
		return $pre;
	}

	/**
	 * Classic content: a URL alone on a line becomes an embed marker.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function mark_embed_lines( $content ) {
		return (string) preg_replace_callback(
			'#^(\s*)(https?://[^\s<>"]+)(\s*)$#im',
			function ( $m ) {
				++$this->embeds;
				return $m[1] . '<figure class="spp-embed" data-embed="' . esc_attr( $m[2] ) . '"></figure>' . $m[3];
			},
			$content
		);
	}

	/**
	 * Expands safe core shortcodes and unwraps the rest.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function shortcodes( $html ) {
		$html = (string) $html;
		if ( false === strpos( $html, '[' ) ) {
			return $html;
		}
		// [caption]…[/caption] → figure with figcaption.
		$html = (string) preg_replace_callback(
			'/\[caption([^\]]*)\](.*?)\[\/caption\]/is',
			static function ( $m ) {
				$attr = shortcode_parse_atts( $m[1] );
				if ( function_exists( 'img_caption_shortcode' ) ) {
					return img_caption_shortcode( is_array( $attr ) ? $attr : array(), $m[2] );
				}
				return '<figure>' . $m[2] . '</figure>';
			},
			$html
		);
		// [gallery] → the core gallery markup (attachment images).
		$html = (string) preg_replace_callback(
			'/\[gallery([^\]]*)\]/i',
			static function ( $m ) {
				$attr = shortcode_parse_atts( $m[1] );
				return function_exists( 'gallery_shortcode' ) ? (string) gallery_shortcode( is_array( $attr ) ? $attr : array() ) : '';
			},
			$html
		);
		// [embed]url[/embed] and media shortcodes → markers.
		$html = (string) preg_replace_callback(
			'/\[embed[^\]]*\](.*?)\[\/embed\]/is',
			function ( $m ) {
				++$this->embeds;
				return '<figure class="spp-embed" data-embed="' . esc_attr( trim( wp_strip_all_tags( $m[1] ) ) ) . '"></figure>';
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'/\[(audio|video|playlist)\b[^\]]*\](?:.*?\[\/\1\])?/is',
			function () {
				++$this->embeds;
				return '<figure class="spp-embed" data-embed="media"></figure>';
			},
			$html
		);
		// Escaped shortcodes [[x]] print literally.
		$html = (string) preg_replace( '/\[\[([A-Za-z][^\[\]]*)\]\]/', '&#91;$1&#93;', $html );
		// Every other shortcode tag is removed; enclosed text is kept.
		return (string) preg_replace( '/\[\/?[A-Za-z][\w\-]*(?:\s[^\[\]]*)?\/?\]/', '', $html );
	}

	/* ------------------------------------------------------------------
	 * Page builders
	 * ------------------------------------------------------------------ */

	/**
	 * Elementor: headings, text, images and links from _elementor_data.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function elementor_html( \WP_Post $post ) {
		$raw  = get_post_meta( $post->ID, '_elementor_data', true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$out = '';
		$this->walk_elementor( $data, $out, $post, 0 );
		return $out;
	}

	/**
	 * Walks Elementor elements.
	 *
	 * @param array    $elements Elements.
	 * @param string   $out      Output (by reference).
	 * @param \WP_Post $post     Post.
	 * @param int      $depth    Depth.
	 */
	private function walk_elementor( array $elements, &$out, \WP_Post $post, $depth ) {
		if ( $depth > 25 ) {
			return;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$out .= $this->elementor_widget( (string) ( $element['widgetType'] ?? '' ), $settings, $post );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor( $element['elements'], $out, $post, $depth + 1 );
			}
		}
	}

	/**
	 * HTML for one Elementor widget.
	 *
	 * @param string   $type     Widget type.
	 * @param array    $s        Settings.
	 * @param \WP_Post $post     Post.
	 * @return string
	 */
	private function elementor_widget( $type, array $s, \WP_Post $post ) {
		$text = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ] ) && is_scalar( $s[ $key ] ) ? (string) $s[ $key ] : '';
		};
		switch ( $type ) {
			case 'heading':
				$tag = in_array( $text( 'header_size' ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $text( 'header_size' ) : 'h2';
				return '<' . $tag . '>' . $this->builder_link( esc_html( wp_strip_all_tags( $text( 'title' ) ) ), $s['link'] ?? null ) . '</' . $tag . '>';
			case 'text-editor':
				return '<div>' . wpautop( wp_kses_post( $text( 'editor' ) ) ) . '</div>';
			case 'image':
				return $this->builder_image( $s['image'] ?? null, $s['link'] ?? null );
			case 'button':
				return '<p>' . $this->builder_link( esc_html( wp_strip_all_tags( $text( 'text' ) ) ), $s['link'] ?? null ) . '</p>';
			case 'icon-list':
				$items = '';
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
					if ( is_array( $item ) && ! empty( $item['text'] ) && is_scalar( $item['text'] ) ) {
						$items .= '<li>' . $this->builder_link( esc_html( wp_strip_all_tags( (string) $item['text'] ) ), $item['link'] ?? null ) . '</li>';
					}
				}
				return '' !== $items ? '<ul>' . $items . '</ul>' : '';
			case 'image-box':
			case 'icon-box':
			case 'call-to-action':
			case 'flip-box':
				$size = in_array( $text( 'title_size' ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $text( 'title_size' ) : 'h3';
				$html = 'image-box' === $type ? $this->builder_image( $s['image'] ?? null, null ) : '';
				$html .= '' !== $text( 'title_text' ) ? '<' . $size . '>' . esc_html( wp_strip_all_tags( $text( 'title_text' ) ) ) . '</' . $size . '>' : '';
				$html .= '' !== $text( 'title' ) ? '<' . $size . '>' . esc_html( wp_strip_all_tags( $text( 'title' ) ) ) . '</' . $size . '>' : '';
				$html .= '' !== $text( 'description_text' ) ? '<p>' . wp_kses_post( $text( 'description_text' ) ) . '</p>' : '';
				$html .= '' !== $text( 'description' ) ? '<p>' . wp_kses_post( $text( 'description' ) ) . '</p>' : '';
				if ( '' !== $text( 'button' ) ) {
					$html .= '<p>' . $this->builder_link( esc_html( $text( 'button' ) ), $s['link'] ?? null ) . '</p>';
				} elseif ( isset( $s['link'] ) ) {
					$html = $this->builder_link( $html, $s['link'] );
				}
				return $html;
			case 'testimonial':
				return '<blockquote><p>' . wp_kses_post( $text( 'testimonial_content' ) ) . '</p></blockquote>';
			case 'accordion':
			case 'toggle':
			case 'tabs':
				$html = '';
				foreach ( (array) ( $s['tabs'] ?? array() ) as $tab ) {
					if ( ! is_array( $tab ) ) {
						continue;
					}
					$html .= '<p><strong>' . esc_html( wp_strip_all_tags( (string) ( $tab['tab_title'] ?? '' ) ) ) . '</strong></p>';
					$html .= wpautop( wp_kses_post( (string) ( $tab['tab_content'] ?? '' ) ) );
				}
				return $html;
			case 'html':
				return wp_kses_post( $text( 'html' ) );
			case 'shortcode':
				return $this->shortcodes( $text( 'shortcode' ) );
			case 'video':
				++$this->embeds;
				return '<figure class="spp-embed" data-embed="video"></figure>';
			case 'theme-post-content':
				return $this->render_content( (string) $post->post_content, $post );
			default:
				$html = '';
				foreach ( array( 'title', 'title_text', 'heading', 'sub_heading', 'text', 'description', 'description_text', 'content', 'editor' ) as $key ) {
					if ( '' !== trim( $text( $key ) ) ) {
						$html .= '<p>' . wp_kses_post( $text( $key ) ) . '</p>';
					}
				}
				return $html;
		}
	}

	/**
	 * Bricks: elements stored in _bricks_page_content_2.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function bricks_html( \WP_Post $post ) {
		$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		if ( ! is_array( $elements ) ) {
			return '';
		}
		$html = '';
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$s    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$name = (string) ( $element['name'] ?? '' );
			$txt  = isset( $s['text'] ) && is_scalar( $s['text'] ) ? (string) $s['text'] : '';
			if ( 'heading' === $name ) {
				$tag   = isset( $s['tag'] ) && in_array( $s['tag'], array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $s['tag'] : 'h3';
				$html .= '<' . $tag . '>' . esc_html( wp_strip_all_tags( $txt ) ) . '</' . $tag . '>';
			} elseif ( in_array( $name, array( 'text', 'text-basic', 'rich-text' ), true ) ) {
				$html .= wpautop( wp_kses_post( $txt ) );
			} elseif ( 'image' === $name ) {
				$html .= $this->builder_image( $s['image'] ?? null, null );
			} elseif ( 'button' === $name ) {
				$html .= '<p>' . $this->builder_link( esc_html( wp_strip_all_tags( $txt ) ), $s['link'] ?? null ) . '</p>';
			} elseif ( '' !== trim( $txt ) ) {
				$html .= '<p>' . wp_kses_post( $txt ) . '</p>';
			}
		}
		return $html;
	}

	/**
	 * Link markup for builder link settings.
	 *
	 * @param string $inner Inner HTML.
	 * @param mixed  $link  Link settings (url, is_external, nofollow).
	 * @return string
	 */
	private function builder_link( $inner, $link ) {
		$url = '';
		if ( is_array( $link ) ) {
			$url = (string) ( $link['url'] ?? '' );
		} elseif ( is_string( $link ) ) {
			$url = $link;
		}
		if ( '' === trim( $url ) || '' === trim( (string) $inner ) ) {
			return (string) $inner;
		}
		$attrs = ' href="' . esc_url( $url ) . '"';
		if ( is_array( $link ) && ! empty( $link['nofollow'] ) ) {
			$attrs .= ' rel="nofollow"';
		}
		if ( is_array( $link ) && ! empty( $link['is_external'] ) ) {
			$attrs .= ' target="_blank"';
		}
		return '<a' . $attrs . '>' . $inner . '</a>';
	}

	/**
	 * Image markup for builder image settings (ALT from the media library).
	 *
	 * @param mixed $image Image settings (id, url).
	 * @param mixed $link  Link settings.
	 * @return string
	 */
	private function builder_image( $image, $link ) {
		if ( ! is_array( $image ) ) {
			return '';
		}
		$id  = (int) ( $image['id'] ?? 0 );
		$url = (string) ( $image['url'] ?? '' );
		if ( $id && '' === $url ) {
			$url = (string) wp_get_attachment_url( $id );
		}
		if ( '' === $url ) {
			return '';
		}
		$alt  = $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : (string) ( $image['alt'] ?? '' );
		$html = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id ? ' class="wp-image-' . $id . '"' : '' ) . '>';
		return '<figure>' . $this->builder_link( $html, $link ) . '</figure>';
	}
}

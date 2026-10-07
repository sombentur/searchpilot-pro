<?php
/**
 * Breadcrumb trail, markup, shortcode and block.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Home → blog page → post type archive → primary category hierarchy or
 * parent pages → current page. Output: template tag searchpilot_breadcrumbs(),
 * shortcode [searchpilot_breadcrumbs] and the dynamic block
 * searchpilot/breadcrumbs. Nothing is printed unless SearchPilot owns the
 * breadcrumbs feature. The schema module reads was_rendered()/last_trail()
 * to describe exactly the trail visitors see.
 */
class Breadcrumbs implements Module {

	const BLOCK_SCRIPT   = 'searchpilot-blocks';
	const FRONTEND_STYLE = 'searchpilot-frontend';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Titles/context.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Whether a trail was printed on this front-end request.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Trail printed on this request.
	 *
	 * @var array
	 */
	private $last_trail = array();

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param MetaStore        $meta      Meta store.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param TitleResolver    $titles    Titles.
	 */
	public function __construct( Settings $settings, MetaStore $meta, ConflictDetector $conflicts, TitleResolver $titles ) {
		$this->settings  = $settings;
		$this->meta      = $meta;
		$this->conflicts = $conflicts;
		$this->titles    = $titles;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_shortcode( 'searchpilot_breadcrumbs', array( $this, 'shortcode' ) );
	}

	/**
	 * Registers the shared block editor script and the tiny front-end stylesheet.
	 */
	public static function register_assets() {
		if ( ! wp_script_is( self::BLOCK_SCRIPT, 'registered' ) ) {
			wp_register_script(
				self::BLOCK_SCRIPT,
				SEARCHPILOT_URL . 'assets/js/editor/blocks.js',
				array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
				SEARCHPILOT_VERSION,
				true
			);
			wp_set_script_translations( self::BLOCK_SCRIPT, 'searchpilot-pro', SEARCHPILOT_DIR . 'languages' );
		}
		if ( ! wp_style_is( self::FRONTEND_STYLE, 'registered' ) ) {
			wp_register_style( self::FRONTEND_STYLE, SEARCHPILOT_URL . 'assets/css/frontend.css', array(), SEARCHPILOT_VERSION );
		}
	}

	/**
	 * Registers the searchpilot/breadcrumbs block.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		self::register_assets();
		register_block_type(
			'searchpilot/breadcrumbs',
			array(
				'api_version'     => 3,
				'title'           => __( 'Breadcrumbs (SearchPilot)', 'searchpilot-pro' ),
				'category'        => 'theme',
				'editor_script'   => self::BLOCK_SCRIPT,
				'editor_style'    => self::FRONTEND_STYLE,
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'showCurrent' => array(
						'type'    => 'string',
						'enum'    => array( 'default', 'show', 'hide' ),
						'default' => 'default',
					),
				),
				'supports'        => array(
					'html'       => false,
					'align'      => array( 'wide', 'full' ),
					'typography' => array( 'fontSize' => true ),
					'color'      => array(
						'text'       => true,
						'link'       => true,
						'background' => false,
					),
					'spacing'    => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public function render_block( $attributes = array() ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$context    = null;
		$in_editor  = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( $in_editor ) {
			if ( ! $this->conflicts->owns( 'breadcrumbs' ) ) {
				$owner = $this->conflicts->owner( 'breadcrumbs' );
				$name  = $this->conflicts->detect()[ $owner ]['name'] ?? '';
				return '<p class="searchpilot-breadcrumbs-note">' . esc_html(
					'' !== $name
						/* translators: %s: plugin name. */
						? sprintf( __( 'Breadcrumbs are provided by %s, so this block shows nothing. Use that plugin’s breadcrumbs or hand breadcrumbs to SearchPilot in Settings → Compatibility.', 'searchpilot-pro' ), $name )
						: __( 'Breadcrumbs are switched off in SearchPilot → Settings.', 'searchpilot-pro' )
				) . '</p>';
			}
			// The block renderer endpoint sets up the edited post.
			$post    = get_post();
			$context = $post instanceof \WP_Post ? $post : null;
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';
		return $this->render(
			array(
				'context'      => $context,
				'show_current' => 'show' === ( $attributes['showCurrent'] ?? '' ) ? true : ( 'hide' === ( $attributes['showCurrent'] ?? '' ) ? false : null ),
				'wrapper'      => $wrapper,
			)
		);
	}

	/**
	 * Shortcode [searchpilot_breadcrumbs prefix="" separator="" show_current="yes|no"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'prefix'       => null,
				'separator'    => null,
				'show_current' => '',
				'class'        => '',
			),
			is_array( $atts ) ? $atts : array(),
			'searchpilot_breadcrumbs'
		);
		return $this->render(
			array(
				'prefix'       => null === $atts['prefix'] ? null : sanitize_text_field( (string) $atts['prefix'] ),
				'separator'    => null === $atts['separator'] ? null : sanitize_text_field( (string) $atts['separator'] ),
				'show_current' => '' === $atts['show_current'] ? null : rest_sanitize_boolean( $atts['show_current'] ),
				'class'        => sanitize_html_class( (string) $atts['class'] ),
			)
		);
	}

	/**
	 * Breadcrumb markup ('' when SearchPilot does not own breadcrumbs).
	 *
	 * @param array $args {
	 *     @type mixed       $context      null (current page), WP_Post, WP_Term or a TitleResolver context.
	 *     @type string|null $prefix       Text before the trail (default setting).
	 *     @type string|null $separator    Separator (default setting).
	 *     @type bool|null   $show_current Show the current page (default setting).
	 *     @type string      $class        Extra CSS class.
	 *     @type string      $wrapper      Block wrapper attributes.
	 * }
	 * @return string
	 */
	public function render( array $args = array() ) {
		if ( ! $this->conflicts->owns( 'breadcrumbs' ) ) {
			return '';
		}
		$args  = array_merge(
			array(
				'context'      => null,
				'prefix'       => null,
				'separator'    => null,
				'show_current' => null,
				'class'        => '',
				'wrapper'      => '',
			),
			$args
		);
		$trail = $this->trail( $args['context'] );
		if ( ! $trail ) {
			return '';
		}
		$show_current = null === $args['show_current'] ? (bool) $this->settings->get( 'breadcrumbs.show_current', true ) : (bool) $args['show_current'];
		$separator    = null === $args['separator'] ? (string) $this->settings->get( 'breadcrumbs.separator', '›' ) : (string) $args['separator'];
		$prefix       = null === $args['prefix'] ? (string) $this->settings->get( 'breadcrumbs.prefix', '' ) : (string) $args['prefix'];

		$html = self::markup( $trail, $separator, $prefix, $show_current, (string) $args['class'], (string) $args['wrapper'] );
		if ( '' === $html ) {
			return '';
		}
		self::register_assets();
		wp_enqueue_style( self::FRONTEND_STYLE );
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && null === $args['context'] ) {
			$this->rendered   = true;
			$this->last_trail = $trail;
		}
		return $html;
	}

	/**
	 * Accessible markup for a trail (pure except for escaping).
	 *
	 * @param array  $trail        Items [ name, url ].
	 * @param string $separator    Separator.
	 * @param string $prefix       Prefix.
	 * @param bool   $show_current Include the last item.
	 * @param string $class        Extra class.
	 * @param string $wrapper      Pre-built wrapper attributes (blocks).
	 * @return string
	 */
	public static function markup( array $trail, $separator, $prefix = '', $show_current = true, $class = '', $wrapper = '' ) {
		$items = array_values( $trail );
		$count = count( $items );
		if ( ! $show_current && $count > 1 ) {
			array_pop( $items );
			$count = count( $items );
		}
		if ( ! $count ) {
			return '';
		}
		$lis = array();
		foreach ( $items as $i => $item ) {
			$last = $i === $count - 1;
			$name = esc_html( (string) $item['name'] );
			if ( $last && $show_current ) {
				$inner = '<span class="searchpilot-breadcrumbs-current" aria-current="page">' . $name . '</span>';
			} elseif ( '' !== (string) $item['url'] ) {
				$inner = '<a href="' . esc_url( (string) $item['url'] ) . '">' . $name . '</a>';
			} else {
				$inner = '<span>' . $name . '</span>';
			}
			if ( ! $last ) {
				$inner .= '<span class="searchpilot-breadcrumbs-sep" aria-hidden="true">' . esc_html( $separator ) . '</span>';
			}
			$lis[] = '<li>' . $inner . '</li>';
		}
		$classes = trim( 'searchpilot-breadcrumbs ' . $class );
		$attrs   = '' !== trim( $wrapper ) ? self::merge_class( $wrapper, $classes ) : 'class="' . esc_attr( $classes ) . '"';
		$out     = '<nav ' . $attrs . ' aria-label="' . esc_attr( __( 'Breadcrumb', 'searchpilot-pro' ) ) . '">';
		if ( '' !== trim( $prefix ) ) {
			$out .= '<span class="searchpilot-breadcrumbs-prefix">' . esc_html( $prefix ) . '</span> ';
		}
		return $out . '<ol>' . implode( '', $lis ) . '</ol></nav>';
	}

	/**
	 * Adds classes to block wrapper attributes.
	 *
	 * @param string $wrapper Attributes string (already escaped by core).
	 * @param string $classes Classes to add.
	 * @return string
	 */
	private static function merge_class( $wrapper, $classes ) {
		if ( preg_match( '/class="([^"]*)"/', $wrapper ) ) {
			return (string) preg_replace( '/class="([^"]*)"/', 'class="$1 ' . esc_attr( $classes ) . '"', $wrapper, 1 );
		}
		return $wrapper . ' class="' . esc_attr( $classes ) . '"';
	}

	/**
	 * Whether the trail was printed on this request.
	 *
	 * @return bool
	 */
	public function was_rendered() {
		return $this->rendered;
	}

	/**
	 * The trail printed on this request ([] when none).
	 *
	 * @return array
	 */
	public function last_trail() {
		return $this->last_trail;
	}

	/**
	 * Breadcrumb trail.
	 *
	 * @param mixed $context null (main query), WP_Post, WP_Term or a TitleResolver context array.
	 * @return array<int,array{name:string,url:string}>
	 */
	public function trail( $context = null ) {
		if ( $context instanceof \WP_Post ) {
			$context = $this->titles->post_context( $context );
		} elseif ( $context instanceof \WP_Term ) {
			$context = $this->titles->term_context( $context );
		} elseif ( ! is_array( $context ) ) {
			$context = $this->titles->current_context();
		}
		$label = trim( (string) $this->settings->get( 'breadcrumbs.home_label', '' ) );
		$items = array( self::item( '' !== $label ? $label : __( 'Home', 'searchpilot-pro' ), home_url( '/' ) ) );

		switch ( $context['type'] ) {
			case 'blog':
				if ( $context['post'] instanceof \WP_Post ) {
					$items[] = self::item( $this->post_name( $context['post'] ), (string) get_permalink( $context['post'] ) );
				}
				break;
			case 'singular':
				$post = $context['post'];
				if ( $post instanceof \WP_Post ) {
					$items = array_merge( $items, $this->post_parents( $post ) );
					$items[] = self::item( $this->post_name( $post ), (string) get_permalink( $post ) );
				}
				break;
			case 'term':
				$term = $context['term'];
				if ( $term instanceof \WP_Term ) {
					$items = array_merge( $items, $this->term_prefix( $term->taxonomy ), $this->term_ancestors( $term ) );
					$link  = get_term_link( $term );
					$items[] = self::item( $term->name, is_wp_error( $link ) ? '' : (string) $link );
				}
				break;
			case 'author':
				$user = $context['user'];
				if ( $user instanceof \WP_User ) {
					$items[] = self::item( $user->display_name, get_author_posts_url( $user->ID, $user->user_nicename ) );
				}
				break;
			case 'post_type_archive':
				$object = '' !== $context['post_type'] ? get_post_type_object( $context['post_type'] ) : null;
				if ( $object ) {
					$items[] = self::item( (string) $object->labels->name, (string) get_post_type_archive_link( $object->name ) );
				}
				break;
			case 'date':
				$date = $context['date'];
				if ( ! empty( $date['year'] ) ) {
					$items[] = self::item( (string) $date['year'], get_year_link( $date['year'] ) );
				}
				if ( ! empty( $date['month'] ) ) {
					$items[] = self::item( (string) date_i18n( 'F', (int) mktime( 12, 0, 0, (int) $date['month'], 1, (int) $date['year'] ) ), get_month_link( $date['year'], $date['month'] ) );
				}
				if ( ! empty( $date['day'] ) ) {
					$items[] = self::item( (string) (int) $date['day'], get_day_link( $date['year'], $date['month'], $date['day'] ) );
				}
				break;
			case 'search':
				/* translators: %s: search query. */
				$items[] = self::item( sprintf( __( 'Search results for “%s”', 'searchpilot-pro' ), $context['search'] ), '' );
				break;
			case '404':
				$items[] = self::item( __( 'Page not found', 'searchpilot-pro' ), '' );
				break;
		}
		if ( $context['page'] > 1 && 'front' !== $context['type'] ) {
			/* translators: %d: page number. */
			$items[] = self::item( sprintf( __( 'Page %d', 'searchpilot-pro' ), (int) $context['page'] ), '' );
		}
		/**
		 * Filters the breadcrumb trail.
		 *
		 * @param array $items   Items [ name, url ].
		 * @param array $context Request context.
		 */
		$items = (array) apply_filters( 'searchpilot_breadcrumbs', $items, $context );
		return array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return is_array( $item ) && isset( $item['name'] ) ? self::item( (string) $item['name'], (string) ( $item['url'] ?? '' ) ) : null;
					},
					$items
				),
				static function ( $item ) {
					return $item && '' !== $item['name'];
				}
			)
		);
	}

	/**
	 * Items between Home and a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function post_parents( \WP_Post $post ) {
		$items = array();
		if ( 'post' === $post->post_type ) {
			$items = array_merge( $items, $this->blog_item() );
		} else {
			$object = get_post_type_object( $post->post_type );
			if ( $object && $object->has_archive ) {
				$link = get_post_type_archive_link( $post->post_type );
				if ( $link ) {
					$items[] = self::item( (string) $object->labels->name, (string) $link );
				}
			}
		}
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );
				if ( $ancestor && 'publish' === $ancestor->post_status ) {
					$items[] = self::item( $this->post_name( $ancestor ), (string) get_permalink( $ancestor ) );
				}
			}
			return $items;
		}
		$taxonomy = $this->primary_taxonomy( $post->post_type );
		if ( '' !== $taxonomy ) {
			$term_id = $this->meta->primary_term( $post->ID, $taxonomy );
			$term    = $term_id ? get_term( $term_id, $taxonomy ) : null;
			if ( $term instanceof \WP_Term ) {
				$items = array_merge( $items, $this->term_ancestors( $term ) );
				$link  = get_term_link( $term );
				$items[] = self::item( $term->name, is_wp_error( $link ) ? '' : (string) $link );
			}
		}
		return $items;
	}

	/**
	 * Taxonomy used for a post type's trail: category, else its first public hierarchical taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private function primary_taxonomy( $post_type ) {
		if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
			return 'category';
		}
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			if ( $tax->public && $tax->hierarchical && 'post_format' !== $tax->name ) {
				return $tax->name;
			}
		}
		return '';
	}

	/**
	 * Blog page item (static front page sites).
	 *
	 * @return array
	 */
	private function blog_item() {
		if ( ! $this->settings->get( 'breadcrumbs.show_blog', true ) || 'page' !== get_option( 'show_on_front' ) ) {
			return array();
		}
		$blog = (int) get_option( 'page_for_posts' );
		$page = $blog ? get_post( $blog ) : null;
		if ( ! $page || 'publish' !== $page->post_status ) {
			return array();
		}
		return array( self::item( $this->post_name( $page ), (string) get_permalink( $page ) ) );
	}

	/**
	 * Items before a term: blog page for post taxonomies, or the post type archive.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private function term_prefix( $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return array();
		}
		$types = (array) $tax->object_type;
		if ( in_array( 'post', $types, true ) ) {
			return $this->blog_item();
		}
		if ( 1 === count( $types ) ) {
			$object = get_post_type_object( (string) reset( $types ) );
			if ( $object && $object->has_archive ) {
				$link = get_post_type_archive_link( $object->name );
				if ( $link ) {
					return array( self::item( (string) $object->labels->name, (string) $link ) );
				}
			}
		}
		return array();
	}

	/**
	 * Ancestors of a term (root first).
	 *
	 * @param \WP_Term $term Term.
	 * @return array
	 */
	private function term_ancestors( \WP_Term $term ) {
		$items = array();
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
			return $items;
		}
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$link    = get_term_link( $ancestor );
				$items[] = self::item( $ancestor->name, is_wp_error( $link ) ? '' : (string) $link );
			}
		}
		return $items;
	}

	/**
	 * Breadcrumb name of a post: breadcrumb title field or the title.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function post_name( \WP_Post $post ) {
		$custom = trim( (string) $this->meta->get_post_field( $post->ID, 'breadcrumb_title' ) );
		$name   = '' !== $custom ? $custom : get_the_title( $post );
		$name   = trim( wp_strip_all_tags( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) );
		return '' !== $name ? $name : __( '(no title)', 'searchpilot-pro' );
	}

	/**
	 * Normalized item.
	 *
	 * @param string $name Name.
	 * @param string $url  URL.
	 * @return array{name:string,url:string}
	 */
	private static function item( $name, $url ) {
		return array(
			'name' => trim( wp_strip_all_tags( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) ),
			'url'  => (string) $url,
		);
	}
}

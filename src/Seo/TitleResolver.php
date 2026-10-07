<?php
/**
 * Request context, document titles and meta descriptions for every template.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Core\Settings;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the current request shows (the "context") and the title and
 * description SearchPilot outputs for it. The context array is shared with
 * the head output, breadcrumbs and the schema graph so they always agree:
 *
 *   type      front|home|blog|singular|term|author|post_type_archive|date|search|404|other
 *   post      \WP_Post|null  (singular, front page, posts page)
 *   term      \WP_Term|null
 *   user      \WP_User|null
 *   post_type string         (post type archive)
 *   date      array{year:int,month:int,day:int}
 *   search    string         (raw search query)
 *   page      int            (1-based page number)
 *   pages     int            (number of pages, 1 when unknown)
 *
 * Only cached data is read: the queried object, post/term/user meta and options.
 */
class TitleResolver {

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
	 * Memoized context of the main query.
	 *
	 * @var array|null
	 */
	private $current = null;

	/**
	 * Memoized titles per context key.
	 *
	 * @var array<string,string>
	 */
	private $title_cache = array();

	/**
	 * Memoized descriptions per context key.
	 *
	 * @var array<string,string>
	 */
	private $description_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings Settings.
	 * @param MetaStore $meta     Meta store.
	 */
	public function __construct( Settings $settings, MetaStore $meta ) {
		$this->settings = $settings;
		$this->meta     = $meta;
	}

	/* --------------------------------------------------------------------- *
	 * Context
	 * --------------------------------------------------------------------- */

	/**
	 * Context of the main query (memoized once the query ran).
	 *
	 * @return array
	 */
	public function current_context() {
		if ( null !== $this->current ) {
			return $this->current;
		}
		$context = $this->build_current_context();
		if ( did_action( 'wp' ) ) {
			$this->current = $context;
		}
		return $context;
	}

	/**
	 * Forgets the memoized context (tests, previews).
	 */
	public function reset() {
		$this->current           = null;
		$this->title_cache       = array();
		$this->description_cache = array();
	}

	/**
	 * Context for a post, independent of the main query (admin previews).
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $page Page number of a multi-page post.
	 * @return array
	 */
	public function post_context( \WP_Post $post, $page = 1 ) {
		$type = 'singular';
		if ( 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
				$type = 'front';
			} elseif ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
				$type = 'blog';
			}
		}
		return $this->make_context(
			array(
				'type'  => $type,
				'post'  => $post,
				'page'  => max( 1, (int) $page ),
				'pages' => 'blog' === $type ? 1 : self::count_pages( (string) $post->post_content ),
			)
		);
	}

	/**
	 * Context for a term archive (page 1).
	 *
	 * @param \WP_Term $term Term.
	 * @return array
	 */
	public function term_context( \WP_Term $term ) {
		return $this->make_context(
			array(
				'type' => 'term',
				'term' => $term,
			)
		);
	}

	/**
	 * Builds the main query context.
	 *
	 * @return array
	 */
	private function build_current_context() {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return $this->make_context( array( 'type' => 'other' ) );
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$pages = max( 1, (int) $wp_query->max_num_pages );

		if ( is_404() ) {
			return $this->make_context( array( 'type' => '404' ) );
		}
		if ( is_search() ) {
			return $this->make_context(
				array(
					'type'   => 'search',
					'search' => (string) get_search_query( false ),
					'page'   => $paged,
					'pages'  => $pages,
				)
			);
		}
		if ( is_front_page() && is_home() ) {
			return $this->make_context(
				array(
					'type'  => 'home',
					'page'  => $paged,
					'pages' => $pages,
				)
			);
		}
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );
			return $this->make_context(
				array(
					'type'  => 'blog',
					'post'  => $blog ? get_post( $blog ) : null,
					'page'  => $paged,
					'pages' => $pages,
				)
			);
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return $this->make_context( array( 'type' => 'other' ) );
			}
			return $this->make_context(
				array(
					'type'  => is_front_page() ? 'front' : 'singular',
					'post'  => $post,
					'page'  => max( 1, (int) get_query_var( 'page' ) ),
					'pages' => self::count_pages( (string) $post->post_content ),
				)
			);
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				return $this->make_context(
					array(
						'type'  => 'term',
						'term'  => $term,
						'page'  => $paged,
						'pages' => $pages,
					)
				);
			}
		}
		if ( is_author() ) {
			$user = get_queried_object();
			return $this->make_context(
				array(
					'type'  => 'author',
					'user'  => $user instanceof \WP_User ? $user : null,
					'page'  => $paged,
					'pages' => $pages,
				)
			);
		}
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			return $this->make_context(
				array(
					'type'      => 'post_type_archive',
					'post_type' => $post_type,
					'page'      => $paged,
					'pages'     => $pages,
				)
			);
		}
		if ( is_date() ) {
			return $this->make_context(
				array(
					'type'  => 'date',
					'date'  => array(
						'year'  => (int) get_query_var( 'year' ),
						'month' => (int) get_query_var( 'monthnum' ),
						'day'   => (int) get_query_var( 'day' ),
					),
					'page'  => $paged,
					'pages' => $pages,
				)
			);
		}
		return $this->make_context(
			array(
				'type'  => 'other',
				'page'  => $paged,
				'pages' => $pages,
			)
		);
	}

	/**
	 * Fills context defaults.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	private function make_context( array $values ) {
		$context          = array_merge(
			array(
				'type'      => 'other',
				'post'      => null,
				'term'      => null,
				'user'      => null,
				'post_type' => '',
				'date'      => array(
					'year'  => 0,
					'month' => 0,
					'day'   => 0,
				),
				'search'    => '',
				'page'      => 1,
				'pages'     => 1,
			),
			$values
		);
		$context['page']  = max( 1, (int) $context['page'] );
		$context['pages'] = max( 1, (int) $context['pages'], $context['page'] );
		return $context;
	}

	/**
	 * Number of <!--nextpage--> pages of a post.
	 *
	 * @param string $content Raw content.
	 * @return int
	 */
	public static function count_pages( $content ) {
		return 1 + substr_count( (string) $content, '<!--nextpage-->' );
	}

	/* --------------------------------------------------------------------- *
	 * Titles
	 * --------------------------------------------------------------------- */

	/**
	 * Full document title for a context (unescaped).
	 *
	 * @param array|null $context Context (default: main query).
	 * @return string
	 */
	public function title( $context = null ) {
		$context = null === $context ? $this->current_context() : $context;
		$key     = $this->context_key( $context );
		if ( isset( $this->title_cache[ $key ] ) ) {
			return $this->title_cache[ $key ];
		}
		$title = $this->base_title( $context );
		if ( $context['page'] > 1 ) {
			$title = self::insert_page( $title, self::page_suffix( $context['page'], $context['pages'] ), $this->separator(), (string) get_bloginfo( 'name' ) );
		}
		/**
		 * Filters the document title SearchPilot outputs.
		 *
		 * @param string $title   Title.
		 * @param array  $context Request context.
		 */
		$title                     = trim( (string) apply_filters( 'searchpilot_title', $title, $context ) );
		$this->title_cache[ $key ] = $title;
		return $title;
	}

	/**
	 * Title without the pagination suffix.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function base_title( array $context ) {
		$site = (string) get_bloginfo( 'name' );
		switch ( $context['type'] ) {
			case 'front':
				$post = $context['post'];
				if ( $post instanceof \WP_Post && '' !== trim( (string) $this->meta->get_post_field( $post->ID, 'title' ) ) ) {
					return $this->meta->effective_title( $post );
				}
				return $this->home_title();
			case 'home':
				return $this->home_title();
			case 'blog':
			case 'singular':
				$post = $context['post'];
				return $post instanceof \WP_Post ? $this->meta->effective_title( $post ) : $site;
			case 'term':
				$term     = $context['term'];
				$template = $term instanceof \WP_Term ? trim( (string) $this->meta->get_term_field( $term->term_id, 'title' ) ) : '';
				if ( '' === $template ) {
					$template = (string) $this->settings->get( 'meta.title_term', '%term% %sep% %sitename%' );
				}
				return $this->resolve( $template, $context );
			case 'author':
				return $this->resolve( (string) $this->settings->get( 'meta.title_author', '%author% %sep% %sitename%' ), $context );
			case 'post_type_archive':
			case 'date':
				return $this->resolve( (string) $this->settings->get( 'meta.title_archive', '%archive% %sep% %sitename%' ), $context );
			case 'search':
				return $this->resolve( (string) $this->settings->get( 'meta.title_search', '%search% %sep% %sitename%' ), $context );
			case '404':
				return $this->resolve( (string) $this->settings->get( 'meta.title_404', '%notfound% %sep% %sitename%' ), $context );
		}
		$title = wp_strip_all_tags( (string) get_the_archive_title() );
		return '' !== $title ? $title . ' ' . $this->separator() . ' ' . $site : $site;
	}

	/**
	 * Home title: the setting, or "Site name – Tagline".
	 *
	 * @return string
	 */
	public function home_title() {
		$template = trim( (string) $this->settings->get( 'meta.home_title', '' ) );
		if ( '' !== $template ) {
			return $this->resolve( $template, $this->make_context( array( 'type' => 'home' ) ) );
		}
		$site    = (string) get_bloginfo( 'name' );
		$tagline = trim( (string) get_bloginfo( 'description' ) );
		return '' !== $tagline ? $site . ' ' . $this->separator() . ' ' . $tagline : $site;
	}

	/**
	 * Resolves a template for a non-post context.
	 *
	 * @param string $template Template.
	 * @param array  $context  Context.
	 * @return string
	 */
	public function resolve( $template, array $context ) {
		$template = self::normalize_syntax( (string) $template );
		if ( $context['post'] instanceof \WP_Post && in_array( $context['type'], array( 'singular', 'front', 'blog' ), true ) ) {
			return trim( wp_strip_all_tags( html_entity_decode( $this->meta->resolve_vars( $template, $context['post'] ), ENT_QUOTES, 'UTF-8' ) ) );
		}
		$out = Text::replace_vars( $template, $this->archive_vars( $context ) );
		return trim( wp_strip_all_tags( html_entity_decode( $out, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Template variables for archive-like contexts.
	 *
	 * @param array $context Context.
	 * @return array<string,string>
	 */
	private function archive_vars( array $context ) {
		$term    = $context['term'] instanceof \WP_Term ? $context['term'] : null;
		$user    = $context['user'] instanceof \WP_User ? $context['user'] : null;
		$tax     = $term ? get_taxonomy( $term->taxonomy ) : null;
		$archive = '';
		if ( 'post_type_archive' === $context['type'] && '' !== $context['post_type'] ) {
			$object  = get_post_type_object( $context['post_type'] );
			$archive = $object ? (string) $object->labels->name : '';
		} elseif ( 'date' === $context['type'] ) {
			$archive = self::date_label( $context['date'] );
		} elseif ( $term ) {
			$archive = $term->name;
		} elseif ( $user ) {
			$archive = $user->display_name;
		}
		$page = $context['page'] > 1 ? self::page_suffix( $context['page'], $context['pages'] ) : '';
		return array(
			'term'             => $term ? $term->name : '',
			'term_title'       => $term ? $term->name : '',
			'category'         => $term ? $term->name : '',
			'primary_category' => $term ? $term->name : '',
			'term_description' => $term ? Text::serp_truncate( Text::strip( (string) $term->description ), 'description' ) : '',
			'taxonomy'         => $tax ? (string) $tax->labels->singular_name : '',
			'author'           => $user ? $user->display_name : '',
			'name'             => $user ? $user->display_name : '',
			'archive'          => $archive,
			'date'             => 'date' === $context['type'] ? $archive : '',
			'search'           => (string) $context['search'],
			'notfound'         => __( 'Page not found', 'searchpilot-pro' ),
			'sitename'         => (string) get_bloginfo( 'name' ),
			'sitedesc'         => (string) get_bloginfo( 'description' ),
			'tagline'          => (string) get_bloginfo( 'description' ),
			'sep'              => $this->separator(),
			'page'             => $page,
			'currentyear'      => wp_date( 'Y' ),
			'current_year'     => wp_date( 'Y' ),
			'title'            => $archive,
		);
	}

	/**
	 * Human label of a date archive.
	 *
	 * @param array $date {year, month, day}.
	 * @return string
	 */
	private static function date_label( array $date ) {
		$year  = (int) ( $date['year'] ?? 0 );
		$month = (int) ( $date['month'] ?? 0 );
		$day   = (int) ( $date['day'] ?? 0 );
		if ( ! $year ) {
			return '';
		}
		if ( $day && $month ) {
			return (string) date_i18n( (string) get_option( 'date_format' ), (int) mktime( 12, 0, 0, $month, $day, $year ) );
		}
		if ( $month ) {
			return (string) date_i18n( 'F Y', (int) mktime( 12, 0, 0, $month, 1, $year ) );
		}
		return (string) $year;
	}

	/**
	 * Title separator.
	 *
	 * @return string
	 */
	public function separator() {
		$sep = trim( (string) $this->settings->get( 'meta.separator', '–' ) );
		return '' !== $sep ? $sep : '–';
	}

	/**
	 * "Page 2 of 5".
	 *
	 * @param int $page  Page.
	 * @param int $pages Pages.
	 * @return string
	 */
	public static function page_suffix( $page, $pages ) {
		$page  = max( 1, (int) $page );
		$pages = max( $page, (int) $pages );
		/* translators: 1: current page, 2: number of pages. */
		return sprintf( __( 'Page %1$d of %2$d', 'searchpilot-pro' ), $page, $pages );
	}

	/**
	 * Inserts a page suffix before a trailing " {sep} {site name}", or appends it.
	 *
	 * @param string $title    Title.
	 * @param string $suffix   Suffix, e.g. "Page 2 of 5".
	 * @param string $sep      Separator.
	 * @param string $sitename Site name.
	 * @return string
	 */
	public static function insert_page( $title, $suffix, $sep, $sitename ) {
		$title  = trim( (string) $title );
		$suffix = trim( (string) $suffix );
		if ( '' === $suffix || false !== strpos( $title, $suffix ) ) {
			return $title;
		}
		if ( '' === $title ) {
			return $suffix;
		}
		// Exact suffix match on bytes (no length is shown to anyone here).
		$tail = ' ' . $sep . ' ' . $sitename;
		$size = strlen( $tail );
		if ( '' !== trim( (string) $sitename ) && strlen( $title ) > $size && substr( $title, -$size ) === $tail ) {
			return substr( $title, 0, strlen( $title ) - $size ) . ' ' . $sep . ' ' . $suffix . $tail;
		}
		return $title . ' ' . $sep . ' ' . $suffix;
	}

	/**
	 * Converts %%var%% and AIOSEO #var syntax to SearchPilot's %var%.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function normalize_syntax( $template ) {
		$template = (string) preg_replace( '/%%([a-z0-9_]+)%%/i', '%$1%', (string) $template );
		return str_replace(
			array( '#post_title', '#site_title', '#separator_sa', '#post_excerpt', '#tagline', '#current_year', '#taxonomy_title', '#categories', '#author_name', '#search_term' ),
			array( '%title%', '%sitename%', '%sep%', '%excerpt%', '%tagline%', '%currentyear%', '%term%', '%category%', '%author%', '%search%' ),
			$template
		);
	}

	/**
	 * Resolves a template for a preview in the admin (settings or editor).
	 *
	 * @param string        $template Template.
	 * @param string        $type     Context type (singular|term|author|post_type_archive|date|search|404|home).
	 * @param \WP_Post|null $post     Post for singular previews.
	 * @return string
	 */
	public function preview( $template, $type = 'singular', $post = null ) {
		if ( $post instanceof \WP_Post ) {
			return $this->resolve( $template, $this->post_context( $post ) );
		}
		$values = array( 'type' => $type );
		if ( 'term' === $type ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'category',
					'number'     => 1,
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) && $terms ) {
				$values['term'] = $terms[0];
			}
		} elseif ( 'author' === $type ) {
			$values['user'] = wp_get_current_user();
		} elseif ( 'search' === $type ) {
			$values['search'] = __( 'example search', 'searchpilot-pro' );
		} elseif ( 'date' === $type ) {
			$values['date'] = array(
				'year'  => (int) wp_date( 'Y' ),
				'month' => (int) wp_date( 'n' ),
				'day'   => 0,
			);
		} elseif ( 'post_type_archive' === $type ) {
			$values['post_type'] = 'post';
		}
		return $this->resolve( $template, $this->make_context( $values ) );
	}

	/* --------------------------------------------------------------------- *
	 * Descriptions
	 * --------------------------------------------------------------------- */

	/**
	 * Meta description for a context ('' = print none).
	 *
	 * Descriptions written by people (per-post fields, the owner plugin's
	 * output in companion mode, settings) are returned as written — the
	 * analysis flags them when they are wider than Google shows. Text
	 * SearchPilot derives automatically (excerpt/opening text, term and
	 * author descriptions) is cut to the width Google shows
	 * (Text::serp_truncate()), on a word boundary and never inside a
	 * grapheme cluster, so it passes the length check in every script.
	 *
	 * @param array|null $context Context (default: main query).
	 * @return string
	 */
	public function description( $context = null ) {
		$context = null === $context ? $this->current_context() : $context;
		$key     = $this->context_key( $context );
		if ( isset( $this->description_cache[ $key ] ) ) {
			return $this->description_cache[ $key ];
		}
		$desc = '';
		switch ( $context['type'] ) {
			case 'front':
				$post = $context['post'];
				if ( $post instanceof \WP_Post && '' !== trim( (string) $this->meta->get_post_field( $post->ID, 'description' ) ) ) {
					$desc = $this->meta->effective_description( $post, false );
				}
				if ( '' === $desc ) {
					$desc = $this->home_description();
				}
				break;
			case 'home':
				$desc = $this->home_description();
				break;
			case 'blog':
			case 'singular':
				$post = $context['post'];
				if ( $post instanceof \WP_Post ) {
					$desc = '' !== (string) $post->post_password ? '' : $this->meta->effective_description( $post, true );
				}
				break;
			case 'term':
				$term = $context['term'];
				if ( $term instanceof \WP_Term ) {
					$template = trim( (string) $this->meta->get_term_field( $term->term_id, 'description' ) );
					$desc     = '' !== $template ? $this->resolve( $template, $context ) : Text::serp_truncate( Text::strip( (string) $term->description ), 'description' );
				}
				break;
			case 'author':
				$user = $context['user'];
				if ( $user instanceof \WP_User ) {
					$desc = Text::serp_truncate( Text::strip( (string) get_user_meta( $user->ID, 'description', true ) ), 'description' );
				}
				break;
			case 'post_type_archive':
				$object = '' !== $context['post_type'] ? get_post_type_object( $context['post_type'] ) : null;
				$desc   = $object ? Text::serp_truncate( Text::strip( (string) $object->description ), 'description' ) : '';
				break;
		}
		$desc = trim( wp_strip_all_tags( html_entity_decode( (string) $desc, ENT_QUOTES, 'UTF-8' ) ) );
		$desc = trim( (string) preg_replace( '/\s+/u', ' ', $desc ) );
		/**
		 * Filters the meta description SearchPilot outputs.
		 *
		 * @param string $desc    Description.
		 * @param array  $context Request context.
		 */
		$desc                            = trim( (string) apply_filters( 'searchpilot_description', $desc, $context ) );
		$this->description_cache[ $key ] = $desc;
		return $this->description_cache[ $key ];
	}

	/**
	 * Home description: the setting, or the tagline.
	 *
	 * @return string
	 */
	public function home_description() {
		$desc = trim( (string) $this->settings->get( 'meta.home_description', '' ) );
		if ( '' !== $desc ) {
			return $this->resolve( $desc, $this->make_context( array( 'type' => 'home' ) ) );
		}
		return trim( (string) get_bloginfo( 'description' ) );
	}

	/**
	 * Cache key of a context.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function context_key( array $context ) {
		$id = 0;
		if ( $context['post'] instanceof \WP_Post ) {
			$id = $context['post']->ID;
		} elseif ( $context['term'] instanceof \WP_Term ) {
			$id = $context['term']->term_id;
		} elseif ( $context['user'] instanceof \WP_User ) {
			$id = $context['user']->ID;
		}
		return md5( $context['type'] . '|' . $id . '|' . $context['post_type'] . '|' . implode( '-', (array) $context['date'] ) . '|' . $context['search'] . '|' . $context['page'] . '|' . $context['pages'] );
	}
}

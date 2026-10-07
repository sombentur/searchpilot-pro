<?php
/**
 * Content type configuration and indexability rules.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for "should this be indexed / in the sitemap /
 * submitted?" Every module uses it so the sitemap, the submission queue and
 * the audits never disagree.
 */
class ContentTypes {

	/**
	 * Builder/internal post types that are public but never real pages.
	 */
	const SKIP_POST_TYPES = array( 'attachment', 'elementor_library', 'e-landing-page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'fl-builder-template', 'et_pb_layout', 'ct_template', 'oxy_user_library', 'brizy_template', 'jet-theme-core', 'kadence_element', 'astra-advanced-hook', 'cartflows_step', 'tve_lead_shortcode' );

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
	 * Constructor.
	 *
	 * @param Settings  $settings Settings.
	 * @param MetaStore $meta     Meta store.
	 */
	public function __construct( Settings $settings, MetaStore $meta ) {
		$this->settings = $settings;
		$this->meta     = $meta;
	}

	/**
	 * Public post types with effective settings.
	 *
	 * @return array<string,array{label:string,index:bool,sitemap:bool,schema:string}>
	 */
	public function post_types() {
		$configured = (array) $this->settings->get( 'content.post_types', array() );
		$out        = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( in_array( $name, self::SKIP_POST_TYPES, true ) ) {
				continue;
			}
			$defaults     = array(
				'index'   => true,
				'sitemap' => true,
				'schema'  => $this->default_schema( $name ),
			);
			$conf         = isset( $configured[ $name ] ) && is_array( $configured[ $name ] ) ? array_merge( $defaults, $configured[ $name ] ) : $defaults;
			$out[ $name ] = array(
				'label'   => $object->labels->name,
				'index'   => (bool) $conf['index'],
				'sitemap' => (bool) $conf['index'] && (bool) $conf['sitemap'],
				'schema'  => (string) $conf['schema'],
			);
		}
		/**
		 * Filters content type settings.
		 *
		 * @param array $out Post types.
		 */
		return (array) apply_filters( 'searchpilot_post_types', $out );
	}

	/**
	 * Public taxonomies with effective settings.
	 *
	 * @return array<string,array{label:string,index:bool,sitemap:bool,object_types:string[]}>
	 */
	public function taxonomies() {
		$configured = (array) $this->settings->get( 'content.taxonomies', array() );
		$out        = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( in_array( $name, array( 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'product_shipping_class', 'product_visibility' ), true ) || empty( $object->publicly_queryable ) && empty( $object->query_var ) ) {
				continue;
			}
			$defaults     = array(
				'index'   => true,
				'sitemap' => true,
			);
			$conf         = isset( $configured[ $name ] ) && is_array( $configured[ $name ] ) ? array_merge( $defaults, $configured[ $name ] ) : $defaults;
			$out[ $name ] = array(
				'label'        => $object->labels->name,
				'index'        => (bool) $conf['index'],
				'sitemap'      => (bool) $conf['index'] && (bool) $conf['sitemap'],
				'object_types' => (array) $object->object_type,
			);
		}
		/**
		 * Filters taxonomy settings.
		 *
		 * @param array $out Taxonomies.
		 */
		return (array) apply_filters( 'searchpilot_taxonomies', $out );
	}

	/**
	 * Names of indexable post types.
	 *
	 * @return string[]
	 */
	public function indexable_post_types() {
		return array_keys( array_filter( $this->post_types(), static function ( $pt ) {
			return $pt['index'];
		} ) );
	}

	/**
	 * Names of post types included in sitemaps.
	 *
	 * @return string[]
	 */
	public function sitemap_post_types() {
		return array_keys( array_filter( $this->post_types(), static function ( $pt ) {
			return $pt['sitemap'];
		} ) );
	}

	/**
	 * Names of taxonomies included in sitemaps.
	 *
	 * @return string[]
	 */
	public function sitemap_taxonomies() {
		return array_keys( array_filter( $this->taxonomies(), static function ( $tax ) {
			return $tax['sitemap'];
		} ) );
	}

	/**
	 * Configured schema type of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function schema_type( $post_type ) {
		$types = $this->post_types();
		return $types[ $post_type ]['schema'] ?? $this->default_schema( $post_type );
	}

	/**
	 * Indexability of a post with a reason code.
	 *
	 * Reasons: ok, missing, not_published, password, post_type, excluded,
	 * noindex, noindex_default, canonicalized, site_noindex.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return array{indexable:bool,reason:string}
	 */
	public function post_indexability( $post ) {
		$post = get_post( $post );
		$fail = static function ( $reason ) {
			return array(
				'indexable' => false,
				'reason'    => $reason,
			);
		};
		if ( ! $post ) {
			return $fail( 'missing' );
		}
		if ( 'publish' !== $post->post_status ) {
			return $fail( 'not_published' );
		}
		if ( '' !== $post->post_password ) {
			return $fail( 'password' );
		}
		$types = $this->post_types();
		if ( ! isset( $types[ $post->post_type ] ) || ! $types[ $post->post_type ]['index'] ) {
			return $fail( 'post_type' );
		}
		if ( in_array( (int) $post->ID, (array) $this->settings->get( 'content.excluded_ids', array() ), true ) ) {
			return $fail( 'excluded' );
		}
		if ( in_array( (int) $post->ID, $this->utility_page_ids(), true ) ) {
			return $fail( 'utility_page' );
		}
		$explicit = $this->meta->explicit_noindex( $post->ID );
		if ( true === $explicit ) {
			return $fail( 'noindex' );
		}
		if ( null === $explicit && true === $this->meta->post_type_noindex_default( $post->post_type ) ) {
			return $fail( 'noindex_default' );
		}
		$canonical = $this->meta->canonical( $post->ID );
		if ( '' !== $canonical ) {
			$permalink = get_permalink( $post );
			if ( $permalink && Url::normalize( $canonical ) !== Url::normalize( $permalink ) ) {
				return $fail( 'canonicalized' );
			}
		}
		$result = array(
			'indexable' => true,
			'reason'    => 'ok',
		);
		/**
		 * Filters a post's indexability.
		 *
		 * @param array    $result {indexable, reason}.
		 * @param \WP_Post $post   Post.
		 */
		return (array) apply_filters( 'searchpilot_post_indexability', $result, $post );
	}

	/**
	 * Whether a post should be indexed (and therefore be in the sitemap and submitted).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function is_post_indexable( $post ) {
		return (bool) $this->post_indexability( $post )['indexable'];
	}

	/**
	 * Whether a post belongs in the sitemap.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function post_in_sitemap( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $this->is_post_indexable( $post ) ) {
			return false;
		}
		$types = $this->post_types();
		if ( empty( $types[ $post->post_type ]['sitemap'] ) ) {
			return false;
		}
		return ! $this->meta->get_post_field( $post->ID, 'sitemap_exclude' );
	}

	/**
	 * Indexability of a term.
	 *
	 * @param int|\WP_Term $term Term.
	 * @return array{indexable:bool,reason:string}
	 */
	public function term_indexability( $term ) {
		$term = get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'indexable' => false,
				'reason'    => 'missing',
			);
		}
		$taxes = $this->taxonomies();
		if ( empty( $taxes[ $term->taxonomy ]['index'] ) ) {
			return array(
				'indexable' => false,
				'reason'    => 'taxonomy',
			);
		}
		if ( (int) $term->count < 1 ) {
			return array(
				'indexable' => false,
				'reason'    => 'empty',
			);
		}
		$explicit = $this->meta->get_term_field( $term->term_id, 'noindex' );
		if ( true === $explicit ) {
			return array(
				'indexable' => false,
				'reason'    => 'noindex',
			);
		}
		// The SEO plugin's archive setting (e.g. Rank Math "Tag archives: noindex") is intentional.
		if ( null === $explicit && true === $this->meta->taxonomy_noindex_default( $term->taxonomy ) ) {
			return array(
				'indexable' => false,
				'reason'    => 'noindex_default',
			);
		}
		return array(
			'indexable' => true,
			'reason'    => 'ok',
		);
	}

	/**
	 * Whether a term archive should be indexed.
	 *
	 * @param int|\WP_Term $term Term.
	 * @return bool
	 */
	public function is_term_indexable( $term ) {
		return (bool) $this->term_indexability( $term )['indexable'];
	}

	/**
	 * Whether an author archive should be indexed.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function is_author_indexable( $user_id ) {
		if ( ! $this->settings->get( 'content.authors_index' ) ) {
			return false;
		}
		return count_user_posts( (int) $user_id, $this->indexable_post_types(), true ) > 0;
	}

	/**
	 * Pages that must never be indexed: WooCommerce cart, checkout and account
	 * pages (and equivalents registered through the filter).
	 *
	 * @return int[]
	 */
	public function utility_page_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		/**
		 * Filters page IDs that are never indexed (cart, checkout, account…).
		 *
		 * @param int[] $ids Page IDs.
		 */
		$ids = array_map( 'intval', (array) apply_filters( 'searchpilot_utility_page_ids', $ids ) );
		return $ids;
	}

	/**
	 * Default schema type for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private function default_schema( $post_type ) {
		if ( 'post' === $post_type ) {
			return 'Article';
		}
		if ( 'product' === $post_type ) {
			return 'none';
		}
		return 'WebPage';
	}
}

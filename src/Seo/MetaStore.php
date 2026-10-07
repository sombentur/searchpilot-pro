<?php
/**
 * Reads and writes per-post/per-term SEO fields through the owning plugin.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Core\Changes;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Settings;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * One API for SEO fields regardless of which plugin stores them. When Rank
 * Math owns titles, get_post_field( $id, 'title' ) reads rank_math_title and
 * set_post_field() writes it (logged and undoable) — SearchPilot never keeps
 * a second, conflicting copy.
 *
 * Fields: title, description, focus, canonical, noindex, nofollow, og_title,
 * og_description, og_image, twitter_title, twitter_description, schema_type,
 * breadcrumb_title. SearchPilot-only fields (always our meta): indexing_api,
 * faq, sitemap_exclude, links_exclude, cornerstone, primary_term.
 *
 * What a page actually serves (contract C2): effective_title() /
 * effective_description() resolve the head the way the outputting plugin
 * does (Rank Math's own replace_vars, separator and %excerpt%; Yoast and
 * AIOSEO through their APIs), rendered_head() returns the latest crawl of
 * the page with a freshness flag, and field_owner() says who owns a field
 * and whether SearchPilot may write it.
 */
class MetaStore {

	const PREFIX = '_searchpilot_';

	/**
	 * Field → ownership feature.
	 */
	const FIELD_FEATURE = array(
		'title'               => 'title',
		'description'         => 'description',
		'focus'               => 'title',
		'canonical'           => 'canonical',
		'noindex'             => 'robots',
		'nofollow'            => 'robots',
		'og_title'            => 'social',
		'og_description'      => 'social',
		'og_image'            => 'social',
		'twitter_title'       => 'social',
		'twitter_description' => 'social',
		'schema_type'         => 'schema',
		'breadcrumb_title'    => 'breadcrumbs',
	);

	/**
	 * SearchPilot-only fields.
	 */
	const OWN_FIELDS = array( 'indexing_api', 'faq', 'sitemap_exclude', 'links_exclude', 'cornerstone', 'primary_term' );

	/**
	 * Post meta keys per plugin.
	 */
	const POST_KEYS = array(
		'yoast'     => array(
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'focus'               => '_yoast_wpseo_focuskw',
			'canonical'           => '_yoast_wpseo_canonical',
			'noindex'             => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'            => '_yoast_wpseo_meta-robots-nofollow',
			'og_title'            => '_yoast_wpseo_opengraph-title',
			'og_description'      => '_yoast_wpseo_opengraph-description',
			'og_image'            => '_yoast_wpseo_opengraph-image',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'breadcrumb_title'    => '_yoast_wpseo_bctitle',
			'primary_term'        => '_yoast_wpseo_primary_category',
		),
		'rank_math' => array(
			'title'               => 'rank_math_title',
			'description'         => 'rank_math_description',
			'focus'               => 'rank_math_focus_keyword',
			'canonical'           => 'rank_math_canonical_url',
			'robots'              => 'rank_math_robots',
			'og_title'            => 'rank_math_facebook_title',
			'og_description'      => 'rank_math_facebook_description',
			'og_image'            => 'rank_math_facebook_image',
			'twitter_title'       => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
			'breadcrumb_title'    => 'rank_math_breadcrumb_title',
			'primary_term'        => 'rank_math_primary_category',
		),
		'seopress'  => array(
			'title'               => '_seopress_titles_title',
			'description'         => '_seopress_titles_desc',
			'focus'               => '_seopress_analysis_target_kw',
			'canonical'           => '_seopress_robots_canonical',
			'noindex'             => '_seopress_robots_index',
			'nofollow'            => '_seopress_robots_follow',
			'og_title'            => '_seopress_social_fb_title',
			'og_description'      => '_seopress_social_fb_desc',
			'og_image'            => '_seopress_social_fb_img',
			'twitter_title'       => '_seopress_social_twitter_title',
			'twitter_description' => '_seopress_social_twitter_desc',
			'primary_term'        => '_seopress_robots_primary_cat',
		),
		'tsf'       => array(
			'title'               => '_genesis_title',
			'description'         => '_genesis_description',
			'canonical'           => '_genesis_canonical_uri',
			'noindex'             => '_genesis_noindex',
			'nofollow'            => '_genesis_nofollow',
			'og_title'            => '_open_graph_title',
			'og_description'      => '_open_graph_description',
			'og_image'            => '_social_image_url',
			'twitter_title'       => '_twitter_title',
			'twitter_description' => '_twitter_description',
			'primary_term'        => '_primary_term_category',
		),
	);

	/**
	 * Term meta keys per plugin (Yoast keeps term meta in an option).
	 */
	const TERM_KEYS = array(
		'rank_math' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'focus'       => 'rank_math_focus_keyword',
			'canonical'   => 'rank_math_canonical_url',
			'robots'      => 'rank_math_robots',
		),
		'seopress'  => array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
			'canonical'   => '_seopress_robots_canonical',
			'noindex'     => '_seopress_robots_index',
		),
		'tsf'       => array(
			'title'       => 'autodescription-term-settings.doctitle',
			'description' => 'autodescription-term-settings.description',
			'canonical'   => 'autodescription-term-settings.canonical',
			'noindex'     => 'autodescription-term-settings.noindex',
		),
	);

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param Changes          $changes   Changes.
	 * @param Settings         $settings  Settings.
	 */
	public function __construct( ConflictDetector $conflicts, Changes $changes, Settings $settings ) {
		$this->conflicts = $conflicts;
		$this->changes   = $changes;
		$this->settings  = $settings;
	}

	/**
	 * Which store holds a field: searchpilot|yoast|rank_math|seopress|tsf|aioseo.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public function store_for( $field ) {
		if ( in_array( $field, self::OWN_FIELDS, true ) && 'primary_term' !== $field ) {
			return 'searchpilot';
		}
		$feature = self::FIELD_FEATURE[ $field ] ?? '';
		if ( 'primary_term' === $field ) {
			$feature = 'title';
		}
		$owner = '' !== $feature ? $this->conflicts->owner( $feature ) : 'searchpilot';
		if ( in_array( $owner, array( 'yoast', 'rank_math', 'seopress', 'tsf', 'aioseo' ), true ) ) {
			return $owner;
		}
		return 'searchpilot';
	}

	/**
	 * Meta key for a field in a store ('' when the store has none).
	 *
	 * @param string $store Store.
	 * @param string $field Field.
	 * @return string
	 */
	public static function post_key( $store, $field ) {
		if ( 'searchpilot' === $store ) {
			// The primary category is stored under the per-taxonomy key used by primary_term().
			return self::PREFIX . ( 'primary_term' === $field ? 'primary_category' : $field );
		}
		if ( 'rank_math' === $store && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			return self::POST_KEYS['rank_math']['robots'];
		}
		return self::POST_KEYS[ $store ][ $field ] ?? '';
	}

	/**
	 * Raw stored value of a post field (templates unresolved).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @return mixed '' when empty; bool for noindex/nofollow/flags; null for "use default" robots.
	 */
	public function get_post_field( $post_id, $field ) {
		$post_id = (int) $post_id;
		$store   = $this->store_for( $field );

		if ( 'aioseo' === $store ) {
			return $this->aioseo_get( $post_id, $field );
		}
		$key = self::post_key( $store, $field );
		if ( '' === $key ) {
			return $this->is_flag( $field ) ? null : '';
		}
		$raw = get_post_meta( $post_id, $key, true );

		if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			return $this->decode_robots( $store, $field, $raw );
		}
		if ( in_array( $field, array( 'faq', 'sitemap_exclude', 'links_exclude', 'cornerstone' ), true ) ) {
			return (bool) $raw;
		}
		if ( 'focus' === $field && 'rank_math' === $store ) {
			// Rank Math stores "primary, secondary, …".
			$parts = array_map( 'trim', explode( ',', (string) $raw ) );
			return (string) ( $parts[0] ?? '' );
		}
		if ( 'og_image' === $field && is_numeric( $raw ) ) {
			return (int) $raw;
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Writes a post field to the owning store (logged, undoable).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param mixed  $value   Value ('' or null clears).
	 * @param array  $opts    Changes options (source, description, group_id).
	 * @return int|\WP_Error Activity id (0 when unchanged).
	 */
	public function set_post_field( $post_id, $field, $value, array $opts = array() ) {
		$post_id = (int) $post_id;
		$store   = $this->store_for( $field );
		if ( 'aioseo' === $store ) {
			return $this->aioseo_set( $post_id, $field, $value, $opts );
		}
		$key = self::post_key( $store, $field );
		if ( '' === $key ) {
			/* translators: %s: field name. */
			return new \WP_Error( 'searchpilot_unsupported', sprintf( __( 'The active SEO plugin has no “%s” field.', 'searchpilot-pro' ), $field ) );
		}
		$stored = $this->encode_for_store( $store, $field, $value, $post_id );
		if ( ! isset( $opts['description'] ) ) {
			$labels              = self::field_labels();
			$opts['description'] = sprintf(
				/* translators: 1: field label, 2: post title. */
				__( 'Updated %1$s of “%2$s”', 'searchpilot-pro' ),
				$labels[ $field ] ?? $field,
				get_the_title( $post_id )
			);
		}
		$opts['action'] = $opts['action'] ?? 'seo_' . $field . '_updated';
		return $this->changes->update_post_meta( $post_id, $key, $stored, $opts );
	}

	/**
	 * Raw stored value of a term field.
	 *
	 * @param int    $term_id Term id.
	 * @param string $field   Field (title, description, focus, canonical, noindex).
	 * @return mixed
	 */
	public function get_term_field( $term_id, $field ) {
		$term_id = (int) $term_id;
		$store   = $this->store_for( $field );
		if ( 'yoast' === $store ) {
			$term = get_term( $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}
			$all = (array) get_option( 'wpseo_taxonomy_meta', array() );
			$map = array(
				'title'       => 'wpseo_title',
				'description' => 'wpseo_desc',
				'focus'       => 'wpseo_focuskw',
				'canonical'   => 'wpseo_canonical',
				'noindex'     => 'wpseo_noindex',
			);
			$raw = $all[ $term->taxonomy ][ $term_id ][ $map[ $field ] ?? '' ] ?? '';
			if ( 'noindex' === $field ) {
				return 'noindex' === $raw ? true : ( 'index' === $raw ? false : null );
			}
			return (string) $raw;
		}
		if ( 'tsf' === $store ) {
			$settings = (array) get_term_meta( $term_id, 'autodescription-term-settings', true );
			$map      = array(
				'title'       => 'doctitle',
				'description' => 'description',
				'canonical'   => 'canonical',
				'noindex'     => 'noindex',
			);
			$raw      = $settings[ $map[ $field ] ?? '' ] ?? '';
			return 'noindex' === $field ? ( '' === $raw ? null : (bool) $raw ) : (string) $raw;
		}
		if ( in_array( $store, array( 'rank_math', 'seopress' ), true ) ) {
			$key = 'rank_math' === $store && 'noindex' === $field ? 'rank_math_robots' : ( self::TERM_KEYS[ $store ][ $field ] ?? '' );
			if ( '' === $key ) {
				return 'noindex' === $field ? null : '';
			}
			$raw = get_term_meta( $term_id, $key, true );
			if ( 'noindex' === $field ) {
				return $this->decode_robots( $store, $field, $raw );
			}
			return is_scalar( $raw ) ? (string) $raw : '';
		}
		$raw = get_term_meta( $term_id, self::PREFIX . $field, true );
		if ( 'noindex' === $field ) {
			return '' === $raw ? null : (bool) $raw;
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Writes a term field (SearchPilot, Rank Math and SEOPress stores).
	 *
	 * @param int    $term_id Term id.
	 * @param string $field   Field.
	 * @param mixed  $value   Value.
	 * @param array  $opts    Changes options.
	 * @return int|\WP_Error
	 */
	public function set_term_field( $term_id, $field, $value, array $opts = array() ) {
		$store = $this->store_for( $field );
		if ( 'searchpilot' === $store ) {
			$stored = 'noindex' === $field ? ( null === $value || '' === $value ? null : ( $value ? '1' : '0' ) ) : ( '' === (string) $value ? null : (string) $value );
			return $this->changes->update_term_meta( $term_id, self::PREFIX . $field, $stored, $opts );
		}
		if ( in_array( $store, array( 'rank_math', 'seopress' ), true ) && 'noindex' !== $field ) {
			$key = self::TERM_KEYS[ $store ][ $field ] ?? '';
			if ( '' !== $key ) {
				return $this->changes->update_term_meta( $term_id, $key, '' === (string) $value ? null : (string) $value, $opts );
			}
		}
		return new \WP_Error( 'searchpilot_unsupported', __( 'Edit this field in your SEO plugin’s term settings.', 'searchpilot-pro' ) );
	}

	/**
	 * Explicit per-post noindex: true, false, or null (use defaults).
	 *
	 * @param int $post_id Post id.
	 * @return bool|null
	 */
	public function explicit_noindex( $post_id ) {
		$value = $this->get_post_field( $post_id, 'noindex' );
		return null === $value ? null : (bool) $value;
	}

	/**
	 * Post-type level noindex default of the owning plugin (null = unknown/index).
	 *
	 * @param string $post_type Post type.
	 * @return bool|null
	 */
	public function post_type_noindex_default( $post_type ) {
		$store = $this->store_for( 'noindex' );
		if ( 'yoast' === $store ) {
			$titles = (array) get_option( 'wpseo_titles', array() );
			return isset( $titles[ 'noindex-' . $post_type ] ) ? (bool) $titles[ 'noindex-' . $post_type ] : null;
		}
		if ( 'rank_math' === $store ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			if ( isset( $titles[ 'pt_' . $post_type . '_custom_robots' ] ) && 'on' === $titles[ 'pt_' . $post_type . '_custom_robots' ] ) {
				return in_array( 'noindex', (array) ( $titles[ 'pt_' . $post_type . '_robots' ] ?? array() ), true );
			}
			$global = (array) ( $titles['robots_global'] ?? array() );
			return $global ? in_array( 'noindex', $global, true ) : null;
		}
		return null;
	}

	/**
	 * Taxonomy-level noindex default of the owning plugin (null = unknown/index),
	 * e.g. Rank Math's or Yoast's "Tag archives: noindex".
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool|null
	 */
	public function taxonomy_noindex_default( $taxonomy ) {
		$store = $this->store_for( 'noindex' );
		if ( 'yoast' === $store ) {
			$titles = (array) get_option( 'wpseo_titles', array() );
			return isset( $titles[ 'noindex-tax-' . $taxonomy ] ) ? (bool) $titles[ 'noindex-tax-' . $taxonomy ] : null;
		}
		if ( 'rank_math' === $store ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			if ( isset( $titles[ 'tax_' . $taxonomy . '_custom_robots' ] ) && 'on' === $titles[ 'tax_' . $taxonomy . '_custom_robots' ] ) {
				return in_array( 'noindex', (array) ( $titles[ 'tax_' . $taxonomy . '_robots' ] ?? array() ), true );
			}
			$global = (array) ( $titles['robots_global'] ?? array() );
			return $global ? in_array( 'noindex', $global, true ) : null;
		}
		if ( 'seopress' === $store ) {
			$titles = (array) get_option( 'seopress_titles_option_name', array() );
			$tax    = $titles['seopress_titles_tax_titles'][ $taxonomy ] ?? array();
			return is_array( $tax ) && ! empty( $tax['noindex'] ) ? true : null;
		}
		return null;
	}

	/**
	 * Canonical override ('' = self).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function canonical( $post_id ) {
		return trim( (string) $this->get_post_field( $post_id, 'canonical' ) );
	}

	/**
	 * Focus keyword / topic.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function focus( $post_id ) {
		return trim( (string) $this->get_post_field( $post_id, 'focus' ) );
	}

	/**
	 * Primary term id for a taxonomy (falls back to the first assigned term).
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	public function primary_term( $post_id, $taxonomy = 'category' ) {
		$store = $this->store_for( 'primary_term' );
		$key   = self::post_key( $store, 'primary_term' );
		if ( 'category' !== $taxonomy && 'searchpilot' !== $store ) {
			$key = 'yoast' === $store ? '_yoast_wpseo_primary_' . $taxonomy : ( 'rank_math' === $store ? 'rank_math_primary_' . $taxonomy : '' );
		} elseif ( 'searchpilot' === $store ) {
			$key = self::PREFIX . 'primary_' . $taxonomy;
		}
		$id = '' !== $key ? (int) get_post_meta( $post_id, $key, true ) : 0;
		if ( $id && has_term( $id, $taxonomy, $post_id ) ) {
			return $id;
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_array( $terms ) && $terms ) {
			usort(
				$terms,
				static function ( $a, $b ) {
					return (int) $a->parent <=> (int) $b->parent ?: (int) $a->term_id <=> (int) $b->term_id;
				}
			);
			return (int) $terms[0]->term_id;
		}
		return 0;
	}

	/* ------------------------------------------------------------------
	 * What the page serves (contract C2)
	 * ------------------------------------------------------------------ */

	/**
	 * The title a post shows in search results, resolved the way the plugin
	 * that outputs it resolves it (Rank Math: its own variables and
	 * separator; Yoast/AIOSEO: their APIs; SearchPilot: its templates).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public function effective_title( $post ) {
		$details = $this->effective_title_details( $post );
		return $details['title'];
	}

	/**
	 * The meta description a post outputs ('' when none).
	 *
	 * @param int|\WP_Post $post          Post.
	 * @param bool         $with_fallback Include automatic text: the owner's post-type template
	 *                                    (e.g. Rank Math's %excerpt%) or SearchPilot's automatic
	 *                                    description. False returns only a description written
	 *                                    for this post.
	 * @return string
	 */
	public function effective_description( $post, $with_fallback = true ) {
		$details = $this->effective_description_details( $post, $with_fallback );
		return $details['description'];
	}

	/**
	 * Served title and where it comes from.
	 *
	 * A modified copy of a post (unsaved editor values) is resolved with
	 * those values; plugin APIs that only read the database are used only
	 * for unmodified posts.
	 *
	 * @param int|\WP_Post $post Post.
	 * @param array        $args { custom: string — use instead of the stored per-post SEO title }.
	 * @return array{title:string,source:string,origin:string} source: the outputting plugin
	 *         (rank_math|yoast|aioseo|seopress|tsf|searchpilot|wordpress or another plugin id);
	 *         origin: custom (written for this post)|template|unknown|none.
	 */
	public function effective_title_details( $post, array $args = array() ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array(
				'title'  => '',
				'source' => 'none',
				'origin' => 'none',
			);
		}
		$custom = isset( $args['custom'] ) ? (string) $args['custom'] : null;
		$owner  = $this->owner_of( 'title' );
		$title  = '';
		$origin = 'template';
		switch ( $owner ) {
			case 'rank_math':
				$template = null !== $custom ? $custom : (string) get_post_meta( $post->ID, 'rank_math_title', true );
				if ( '' !== trim( $template ) ) {
					$origin = 'custom';
				} else {
					$template = self::rank_math_option( 'pt_' . $post->post_type . '_title', '%title% %sep% %sitename%' );
				}
				$title = $this->rank_math_resolve( $template, $post );
				break;
			case 'yoast':
				$stored = null !== $custom ? $custom : (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
				$origin = '' !== trim( $stored ) ? 'custom' : 'template';
				if ( null === $custom && $this->is_stored( $post ) && function_exists( 'YoastSEO' ) ) {
					try {
						$meta  = YoastSEO()->meta->for_post( $post->ID );
						$title = $meta ? (string) $meta->title : '';
					} catch ( \Throwable $e ) {
						$title = '';
					}
				}
				if ( '' === trim( $title ) ) {
					$titles   = (array) get_option( 'wpseo_titles', array() );
					$template = '' !== trim( $stored ) ? $stored : (string) ( $titles[ 'title-' . $post->post_type ] ?? '' );
					$template = '' !== trim( $template ) ? $template : '%%title%% %%page%% %%sep%% %%sitename%%';
					$title    = $this->resolve_vars( $template, $post, array( 'sep' => self::yoast_separator() ) );
				}
				break;
			case 'aioseo':
				$stored = null !== $custom ? $custom : (string) $this->aioseo_get( $post->ID, 'title' );
				$origin = '' !== trim( $stored ) ? 'custom' : 'template';
				if ( null === $custom && $this->is_stored( $post ) && function_exists( 'aioseo' ) ) {
					try {
						$title = isset( aioseo()->meta->title ) ? (string) aioseo()->meta->title->getPostTitle( $post ) : '';
					} catch ( \Throwable $e ) {
						$title = '';
					}
				}
				if ( '' === trim( $title ) ) {
					$template = '' !== trim( $stored ) ? $stored : self::aioseo_template( $post->post_type, 'title' );
					$title    = $this->resolve_vars( $template, $post, array( 'sep' => self::aioseo_separator() ) );
				}
				break;
			case 'seopress':
			case 'tsf':
			case 'searchpilot':
				$stored   = null !== $custom ? $custom : (string) get_post_meta( $post->ID, self::post_key( $owner, 'title' ), true );
				$origin   = '' !== trim( $stored ) ? 'custom' : 'template';
				$template = '' !== trim( $stored ) ? $stored : $this->default_title_template( $post );
				$title    = $this->resolve_vars( $template, $post );
				break;
			default:
				// Output switched off (WordPress/the theme prints the title) or a plugin SearchPilot cannot read.
				$origin = 'off' === $owner ? 'template' : 'unknown';
				$title  = self::wordpress_title( $post );
		}
		return array(
			'title'  => self::plain( $title ),
			'source' => 'off' === $owner ? 'wordpress' : $owner,
			'origin' => $origin,
		);
	}

	/**
	 * Served meta description and where it comes from.
	 *
	 * @param int|\WP_Post $post          Post (a modified copy is resolved with its unsaved values).
	 * @param bool         $with_fallback Include automatic text (see effective_description()).
	 * @param array        $args          { custom: string — use instead of the stored per-post description }.
	 * @return array{description:string,source:string,origin:string} source as in
	 *         effective_title_details() or 'none'; origin: custom|template|auto|unknown|none.
	 */
	public function effective_description_details( $post, $with_fallback = true, array $args = array() ) {
		$none = array(
			'description' => '',
			'source'      => 'none',
			'origin'      => 'none',
		);
		$post = get_post( $post );
		if ( ! $post ) {
			return $none;
		}
		$custom = isset( $args['custom'] ) ? (string) $args['custom'] : null;
		$owner  = $this->owner_of( 'description' );
		$desc   = '';
		$origin = 'none';
		switch ( $owner ) {
			case 'rank_math':
				$template = null !== $custom ? $custom : (string) get_post_meta( $post->ID, 'rank_math_description', true );
				if ( '' !== trim( $template ) ) {
					$origin = 'custom';
				} elseif ( $with_fallback ) {
					// Rank Math's Singular paper: the post-type template, '%excerpt%' when empty.
					$origin   = 'template';
					$template = self::rank_math_option( 'pt_' . $post->post_type . '_description', '%excerpt%' );
				}
				$desc = '' !== trim( $template ) ? $this->rank_math_resolve( $template, $post ) : '';
				break;
			case 'yoast':
				$stored = null !== $custom ? $custom : (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
				$origin = '' !== trim( $stored ) ? 'custom' : 'template';
				if ( null === $custom && $this->is_stored( $post ) && function_exists( 'YoastSEO' ) && ( 'custom' === $origin || $with_fallback ) ) {
					try {
						$meta = YoastSEO()->meta->for_post( $post->ID );
						$desc = $meta ? (string) $meta->description : '';
					} catch ( \Throwable $e ) {
						$desc = '';
					}
				}
				if ( '' === trim( $desc ) ) {
					$titles   = (array) get_option( 'wpseo_titles', array() );
					$template = '' !== trim( $stored ) ? $stored : ( $with_fallback ? (string) ( $titles[ 'metadesc-' . $post->post_type ] ?? '' ) : '' );
					$desc     = $this->resolve_vars( $template, $post, array( 'sep' => self::yoast_separator() ) );
				}
				break;
			case 'aioseo':
				$stored = null !== $custom ? $custom : (string) $this->aioseo_get( $post->ID, 'description' );
				$origin = '' !== trim( $stored ) ? 'custom' : 'template';
				if ( null === $custom && $this->is_stored( $post ) && function_exists( 'aioseo' ) && ( 'custom' === $origin || $with_fallback ) ) {
					try {
						$desc = isset( aioseo()->meta->description ) ? (string) aioseo()->meta->description->getPostDescription( $post ) : '';
					} catch ( \Throwable $e ) {
						$desc = '';
					}
				}
				if ( '' === trim( $desc ) ) {
					$template = '' !== trim( $stored ) ? $stored : ( $with_fallback ? self::aioseo_template( $post->post_type, 'description' ) : '' );
					$desc     = $this->resolve_vars( $template, $post, array( 'sep' => self::aioseo_separator() ) );
				}
				break;
			case 'seopress':
			case 'tsf':
			case 'searchpilot':
				$stored = null !== $custom ? $custom : (string) get_post_meta( $post->ID, self::post_key( $owner, 'description' ), true );
				if ( '' !== trim( $stored ) ) {
					$origin = 'custom';
					$desc   = $this->resolve_vars( $stored, $post );
				} elseif ( $with_fallback && ( ( 'searchpilot' === $owner && $this->settings->get( 'meta.auto_description' ) ) || 'tsf' === $owner ) ) {
					// SearchPilot's (and The SEO Framework's) automatic description from the excerpt or opening text.
					$origin = 'auto';
					$desc   = $this->auto_description( $post );
				}
				break;
			case 'off':
				break;
			default:
				// Another plugin outputs descriptions; only a crawl of the page can tell what it serves.
				return array(
					'description' => '',
					'source'      => $owner,
					'origin'      => 'unknown',
				);
		}
		$desc = self::plain( $desc );
		if ( '' === $desc ) {
			return $none;
		}
		return array(
			'description' => $desc,
			'source'      => $owner,
			'origin'      => $origin,
		);
	}

	/**
	 * Head of the latest crawl of the post's URL (what the page really served).
	 *
	 * `fresh` is true when the page was crawled after the post was last
	 * modified and — when the crawl recorded it — the SEO inputs
	 * (head_inputs_hash()) are unchanged since, so meta-only edits (fixers,
	 * undo, another plugin's bulk editor) also invalidate the snapshot.
	 *
	 * @param int $post_id Post id.
	 * @return array{title:string,description:string,crawled_at:string,fresh:bool,title_count:int,description_count:int,url:string}|null
	 *         crawled_at is RFC 3339 (UTC); null when the page was never crawled.
	 */
	public function rendered_head( $post_id ) {
		$post_id = (int) $post_id;
		$crawler = '\SearchPilot\Indexer\Crawler';
		if ( $post_id <= 0 || ! class_exists( $crawler ) || ! method_exists( $crawler, 'rendered_head' ) ) {
			return null;
		}
		$head = $crawler::rendered_head( $post_id );
		$post = get_post( $post_id );
		if ( ! is_array( $head ) || empty( $head['crawled_at'] ) || ! $post ) {
			return null;
		}
		$crawled  = strtotime( (string) $head['crawled_at'] . ' UTC' );
		$modified = (string) $post->post_modified_gmt;
		$modified = '' !== $modified && '0000-00-00 00:00:00' !== $modified ? (int) strtotime( $modified . ' UTC' ) : 0;
		$fresh    = false !== $crawled && $crawled > $modified && 'publish' === $post->post_status;
		$inputs   = (string) ( $head['inputs'] ?? '' );
		if ( $fresh && '' !== $inputs ) {
			$fresh = hash_equals( $inputs, $this->head_inputs_hash( $post_id ) );
		}
		$url = (string) ( $head['url'] ?? '' );
		if ( $fresh && '' !== $url && Url::normalize( $url ) !== Url::normalize( (string) get_permalink( $post ) ) ) {
			$fresh = false; // The address changed since the crawl.
		}
		// Only a fetch past the page caches proves what the page serves now; a cached copy
		// (CDN, a purge that has not landed) may still show the previous headline.
		if ( empty( $head['cache_bypassed'] ) ) {
			$fresh = false;
		}
		return array(
			'title'             => self::plain( (string) ( $head['title'] ?? '' ) ),
			'description'       => self::plain( (string) ( $head['description'] ?? '' ) ),
			'crawled_at'        => (string) mysql_to_rfc3339( (string) $head['crawled_at'] ),
			'fresh'             => $fresh,
			'cache_bypassed'    => ! empty( $head['cache_bypassed'] ),
			'title_count'       => (int) ( $head['title_count'] ?? 0 ),
			'description_count' => (int) ( $head['description_count'] ?? 0 ),
			'url'               => $url,
		);
	}

	/**
	 * Fingerprint of everything a post's title and description are built
	 * from (owner, post text, the owner's per-post fields, templates and
	 * separator, site name). Stored with each crawl so rendered_head() can
	 * tell when a snapshot no longer matches the inputs. Cheap: cached
	 * options and post meta only.
	 *
	 * @param int $post_id Post id.
	 * @return string md5 ('' for a missing post).
	 */
	public function head_inputs_hash( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return '';
		}
		$parts = array(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			md5( (string) $post->post_content ),
			(string) $post->post_name,
			(string) $post->post_password,
			(string) get_option( 'blogname' ),
			(string) get_option( 'blogdescription' ),
			(int) $this->primary_term( $post->ID, 'category' ),
		);
		foreach ( array( 'title', 'description' ) as $field ) {
			$owner   = $this->owner_of( $field );
			$parts[] = $field . ':' . $owner;
			switch ( $owner ) {
				case 'rank_math':
					$parts[] = (string) get_post_meta( $post->ID, 'rank_math_' . $field, true );
					$parts[] = self::rank_math_option( 'pt_' . $post->post_type . '_' . $field, '' );
					$parts[] = self::rank_math_option( 'title_separator', '' );
					break;
				case 'yoast':
					$titles  = (array) get_option( 'wpseo_titles', array() );
					$parts[] = (string) get_post_meta( $post->ID, self::post_key( 'yoast', $field ), true );
					$parts[] = (string) ( $titles[ ( 'title' === $field ? 'title-' : 'metadesc-' ) . $post->post_type ] ?? '' );
					$parts[] = (string) ( $titles['separator'] ?? '' );
					break;
				case 'aioseo':
					$parts[] = (string) $this->aioseo_get( $post->ID, $field );
					$parts[] = self::aioseo_template( $post->post_type, $field );
					break;
				case 'searchpilot':
				case 'seopress':
				case 'tsf':
					$parts[] = (string) get_post_meta( $post->ID, self::post_key( $owner, $field ), true );
					$parts[] = $this->default_title_template( $post );
					$parts[] = (string) $this->settings->get( 'meta.separator', '–' );
					$parts[] = $this->settings->get( 'meta.auto_description' ) ? '1' : '0';
					break;
			}
		}
		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Who owns a field and whether SearchPilot can write it into the
	 * owner's store. Fixers must refuse (and name the owner) when
	 * `writable` is false.
	 *
	 * @param string $field Field (title, description, canonical, noindex…).
	 * @return array{owner:string,owner_name:string,writable:bool,reason:string} reason is the
	 *         message to show when not writable ('' when writable).
	 */
	public function field_owner( $field ) {
		$field  = (string) $field;
		$labels = self::field_labels();
		$label  = $labels[ $field ] ?? $field;
		if ( in_array( $field, self::OWN_FIELDS, true ) && 'primary_term' !== $field ) {
			return array(
				'owner'      => 'searchpilot',
				'owner_name' => $this->owner_name( 'searchpilot' ),
				'writable'   => true,
				'reason'     => '',
			);
		}
		$feature = 'primary_term' === $field ? 'title' : ( self::FIELD_FEATURE[ $field ] ?? '' );
		if ( '' === $feature ) {
			return array(
				'owner'      => 'wordpress',
				'owner_name' => $this->owner_name( 'wordpress' ),
				'writable'   => true,
				'reason'     => '',
			);
		}
		$owner = $this->owner_of( $feature );
		$name  = $this->owner_name( $owner );
		if ( 'off' === $owner ) {
			return array(
				'owner'      => 'off',
				'owner_name' => $name,
				'writable'   => false,
				/* translators: %s: field label, e.g. "meta description". */
				'reason'     => sprintf( __( 'Output of the %s is switched off in SearchPilot → Settings → Feature ownership, so changing it would not change the page.', 'searchpilot-pro' ), $label ),
			);
		}
		if ( ! in_array( $owner, array( 'searchpilot', 'yoast', 'rank_math', 'seopress', 'tsf', 'aioseo' ), true ) ) {
			return array(
				'owner'      => $owner,
				'owner_name' => $name,
				'writable'   => false,
				/* translators: 1: plugin name, 2: field label. */
				'reason'     => sprintf( __( '%1$s outputs the %2$s on this site, and SearchPilot cannot write %1$s’s settings. Change it in %1$s.', 'searchpilot-pro' ), $name, $label ),
			);
		}
		if ( 'aioseo' === $owner ) {
			$writable = in_array( $field, array( 'title', 'description', 'canonical' ), true ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' );
		} else {
			$writable = '' !== self::post_key( $owner, $field );
		}
		return array(
			'owner'      => $owner,
			'owner_name' => $name,
			'writable'   => $writable,
			/* translators: 1: plugin name, 2: field label. */
			'reason'     => $writable ? '' : sprintf( __( '%1$s has no per-post %2$s setting that SearchPilot can write. Change it in %1$s.', 'searchpilot-pro' ), $name, $label ),
		);
	}

	/**
	 * Display name of an owner id.
	 *
	 * @param string $owner Owner id (searchpilot, rank_math, wordpress, off, other plugin ids).
	 * @return string
	 */
	public function owner_name( $owner ) {
		$owner = (string) $owner;
		if ( 'searchpilot' === $owner ) {
			return 'SearchPilot';
		}
		if ( 'wordpress' === $owner ) {
			return 'WordPress';
		}
		if ( 'off' === $owner ) {
			return __( 'nobody (output switched off)', 'searchpilot-pro' );
		}
		$detected = $this->conflicts->detect();
		if ( ! empty( $detected[ $owner ]['name'] ) ) {
			return (string) $detected[ $owner ]['name'];
		}
		$names = array(
			'rank_math'   => 'Rank Math SEO',
			'yoast'       => 'Yoast SEO',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
			'tsf'         => 'The SEO Framework',
			'slim_seo'    => 'Slim SEO',
			'squirrly'    => 'Squirrly SEO',
			'smartcrawl'  => 'SmartCrawl',
			'wp_meta_seo' => 'WP Meta SEO',
			'jetpack'     => 'Jetpack',
		);
		return $names[ $owner ] ?? $owner;
	}

	/**
	 * Owner of an output feature.
	 *
	 * @param string $feature Feature.
	 * @return string
	 */
	private function owner_of( $feature ) {
		return (string) $this->conflicts->owner( $feature );
	}

	/**
	 * Whether a post object carries the stored values (not unsaved editor input).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function is_stored( \WP_Post $post ) {
		if ( ! $post->ID ) {
			return false;
		}
		$db = \WP_Post::get_instance( $post->ID );
		return $db instanceof \WP_Post && $db->post_title === $post->post_title && $db->post_excerpt === $post->post_excerpt && $db->post_content === $post->post_content && $db->post_name === $post->post_name;
	}

	/**
	 * Plain text as search engines read it: entities decoded, tags removed, whitespace collapsed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text  = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$clean = preg_replace( '/\s+/u', ' ', $text );
		return trim( null === $clean ? $text : (string) $clean );
	}

	/**
	 * Title WordPress itself prints (wp_get_document_title() for a post).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function wordpress_title( \WP_Post $post ) {
		$sep = trim( (string) apply_filters( 'document_title_separator', '-' ) );
		// wptexturize() turns the default " - " into an en dash.
		$sep  = '-' === $sep ? '–' : $sep;
		$site = (string) get_option( 'blogname' );
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			$tagline = (string) get_option( 'blogdescription' );
			return '' !== $tagline ? $site . ' ' . $sep . ' ' . $tagline : $site;
		}
		$title = (string) $post->post_title;
		return '' !== $site ? $title . ' ' . $sep . ' ' . $site : $title;
	}

	/* ------------------------------------------------------------------
	 * Rank Math resolution
	 * ------------------------------------------------------------------ */

	/**
	 * A value of Rank Math's Titles & Meta options ('' / missing → default).
	 *
	 * @param string $key     Option key, e.g. pt_post_title.
	 * @param string $default Default.
	 * @return string
	 */
	public static function rank_math_option( $key, $default = '' ) {
		$titles = get_option( 'rank-math-options-titles', array() );
		$value  = is_array( $titles ) && isset( $titles[ $key ] ) && is_scalar( $titles[ $key ] ) ? (string) $titles[ $key ] : '';
		return '' !== trim( $value ) ? $value : (string) $default;
	}

	/**
	 * Resolves a Rank Math template for a post exactly as Rank Math does:
	 * with \RankMath\Helper::replace_vars() when it is available and
	 * resolves every variable, else with a compatible resolver that uses
	 * Rank Math's separator and its %excerpt% (the full manual excerpt, or
	 * the first 160 characters of the content).
	 *
	 * @param string   $template Template.
	 * @param \WP_Post $post     Post.
	 * @return string
	 */
	public function rank_math_resolve( $template, \WP_Post $post ) {
		// Paper::get_from_options() maps these two before resolving.
		$template = str_replace( array( '%seo_title%', '%seo_description%' ), array( '%title%', '%excerpt%' ), (string) $template );
		if ( '' === trim( $template ) ) {
			return '';
		}
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) && self::rank_math_vars_ready() ) {
			try {
				$value = \RankMath\Helper::replace_vars( $template, $post );
				if ( is_string( $value ) && ! self::has_unresolved( $template, $value ) ) {
					return $value;
				}
			} catch ( \Throwable $e ) {
				// Use the compatible resolver below.
				unset( $e );
			}
		}
		return $this->rank_math_compat( $template, $post );
	}

	/**
	 * Whether Rank Math's variable registry exists (outside the front end it may not be set up).
	 *
	 * @return bool
	 */
	private static function rank_math_vars_ready() {
		if ( ! function_exists( 'rank_math' ) ) {
			return true;
		}
		$instance = rank_math();
		return ! is_object( $instance ) || isset( $instance->variables );
	}

	/**
	 * Whether a resolved value still contains a placeholder of its template.
	 *
	 * @param string $template Template.
	 * @param string $value    Resolved value.
	 * @return bool
	 */
	private static function has_unresolved( $template, $value ) {
		if ( ! preg_match_all( '/%[a-z0-9_]+(?:\([^)%]*\))?%/i', (string) $template, $m ) ) {
			return false;
		}
		foreach ( array_unique( $m[0] ) as $token ) {
			if ( false !== strpos( (string) $value, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rank Math-compatible resolution (Replacer::replace semantics).
	 *
	 * @param string   $template Template.
	 * @param \WP_Post $post     Post.
	 * @return string
	 */
	private function rank_math_compat( $template, \WP_Post $post ) {
		$sep      = trim( html_entity_decode( self::rank_math_option( 'title_separator', '-' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$sep      = '' !== $sep ? $sep : '-';
		$template = wp_strip_all_tags( (string) $template );
		if ( ' %sep%' === substr( $template, -6 ) ) {
			$template = substr( $template, 0, -6 );
		}
		$out = (string) preg_replace_callback(
			'/%([a-z0-9_]+)(?:\(([^)%]*)\))?%/i',
			function ( $m ) use ( $post, $sep ) {
				return $this->rank_math_var( strtolower( $m[1] ), isset( $m[2] ) ? (string) $m[2] : '', $post, $sep );
			},
			$template
		);
		$quoted = preg_quote( $sep, '`' );
		$out    = (string) preg_replace( '`' . $quoted . '(?:\s*' . $quoted . ')*`u', $sep, $out );
		return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
	}

	/**
	 * Value of one Rank Math variable for a post ('' for unknown ones, as Rank Math removes them).
	 *
	 * @param string   $name Variable name.
	 * @param string   $arg  Argument, e.g. the format of %date(Y)%.
	 * @param \WP_Post $post Post.
	 * @param string   $sep  Separator.
	 * @return string
	 */
	private function rank_math_var( $name, $arg, \WP_Post $post, $sep ) {
		switch ( $name ) {
			case 'title':
				return wp_strip_all_tags( (string) $post->post_title );
			case 'sep':
				return $sep;
			case 'sitename':
				return (string) get_option( 'blogname' );
			case 'sitedesc':
				return (string) get_option( 'blogdescription' );
			case 'excerpt':
				return self::rank_math_excerpt( $post );
			case 'excerpt_only':
				return wp_strip_all_tags( strip_shortcodes( (string) $post->post_excerpt ) );
			case 'category':
			case 'primary_category':
				$primary = $this->primary_term( $post->ID, 'category' );
				$term    = $primary ? get_term( $primary, 'category' ) : null;
				return $term instanceof \WP_Term ? $term->name : '';
			case 'categories':
			case 'tags':
				$terms = get_the_terms( $post, 'categories' === $name ? 'category' : 'post_tag' );
				return is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
			case 'date':
			case 'modified':
				return 'date' === $name ? (string) get_the_date( $arg, $post ) : (string) get_the_modified_date( $arg, $post );
			case 'currentdate':
				return (string) wp_date( '' !== $arg ? $arg : (string) get_option( 'date_format' ) );
			case 'currentday':
				return (string) wp_date( 'j' );
			case 'currentmonth':
				return (string) wp_date( 'F' );
			case 'currentyear':
				return (string) wp_date( 'Y' );
			case 'currenttime':
				return (string) wp_date( '' !== $arg ? $arg : (string) get_option( 'time_format' ) );
			case 'name':
			case 'post_author':
				return (string) get_the_author_meta( 'display_name', (int) $post->post_author );
			case 'id':
				return (string) (int) $post->ID;
			case 'focuskw':
				return $this->focus( $post->ID );
			case 'url':
				return $post->ID ? (string) get_permalink( $post ) : '';
		}
		return '';
	}

	/**
	 * Rank Math's %excerpt%: the manual excerpt in full, else the first 160
	 * characters of the content (wp_html_excerpt(), as Rank Math does);
	 * nothing for a post without content.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function rank_math_excerpt( \WP_Post $post ) {
		if ( '' === trim( (string) $post->post_content ) ) {
			return '';
		}
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( strip_shortcodes( (string) $post->post_excerpt ) );
		}
		return (string) wp_html_excerpt( strip_shortcodes( (string) $post->post_content ), 160 );
	}

	/**
	 * Yoast's title separator.
	 *
	 * @return string
	 */
	private static function yoast_separator() {
		$titles = (array) get_option( 'wpseo_titles', array() );
		$key    = (string) ( $titles['separator'] ?? 'sc-dash' );
		$map    = class_exists( '\SearchPilot\Import\Sources\YoastSource' ) ? \SearchPilot\Import\Sources\YoastSource::SEPARATORS : array();
		return $map[ $key ] ?? '-';
	}

	/**
	 * All in One SEO's options (static + dynamic post types).
	 *
	 * @return array
	 */
	private static function aioseo_options() {
		$static  = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$dynamic = json_decode( (string) get_option( 'aioseo_options_dynamic', '' ), true );
		return array(
			'static'  => is_array( $static ) ? $static : array(),
			'dynamic' => is_array( $dynamic ) ? $dynamic : array(),
		);
	}

	/**
	 * All in One SEO's separator.
	 *
	 * @return string
	 */
	private static function aioseo_separator() {
		$options = self::aioseo_options();
		$sep     = trim( html_entity_decode( (string) ( $options['static']['searchAppearance']['global']['separator'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' !== $sep ? $sep : '-';
	}

	/**
	 * All in One SEO's post-type template for a field.
	 *
	 * @param string $post_type Post type.
	 * @param string $field     title|description.
	 * @return string
	 */
	private static function aioseo_template( $post_type, $field ) {
		$options = self::aioseo_options();
		$key     = 'title' === $field ? 'title' : 'metaDescription';
		foreach ( array( 'dynamic', 'static' ) as $set ) {
			$value = $options[ $set ]['searchAppearance']['postTypes'][ $post_type ][ $key ] ?? '';
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		return 'title' === $field ? '#post_title #separator_sa #site_title' : '#post_excerpt';
	}

	/* ------------------------------------------------------------------
	 * SearchPilot's own resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Description built from the excerpt or opening text, cut on a word
	 * boundary to the width Google shows (Text::serp_truncate()), so it
	 * always passes the description length check — in every script.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function auto_description( \WP_Post $post ) {
		return Text::serp_truncate( self::auto_text( $post ), 'description' );
	}

	/**
	 * Plain excerpt or content text of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function auto_text( \WP_Post $post ) {
		$source = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$source = excerpt_remove_blocks( $source );
		}
		return Text::strip( $source );
	}

	/**
	 * Default SearchPilot title template for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function default_title_template( \WP_Post $post ) {
		return (string) $this->settings->get( 'page' === $post->post_type ? 'meta.title_page' : 'meta.title_post', '%title% %sep% %sitename%' );
	}

	/**
	 * Resolves SearchPilot (%var%), Yoast (%%var%%) and AIOSEO (#var) variables.
	 * Only the variables a template uses are computed.
	 *
	 * @param string   $template  Template.
	 * @param \WP_Post $post      Post.
	 * @param array    $overrides name => value (e.g. another plugin's separator).
	 * @return string
	 */
	public function resolve_vars( $template, \WP_Post $post, array $overrides = array() ) {
		$template = (string) $template;
		if ( '' === trim( $template ) ) {
			return '';
		}
		// Normalize other plugins' syntaxes to %var%.
		$template = (string) preg_replace( '/%%([a-z_]+)%%/', '%$1%', $template );
		$template = str_replace(
			array( '#post_title', '#site_title', '#separator_sa', '#post_excerpt', '#tagline', '#current_year', '#taxonomy_title', '#categories' ),
			array( '%title%', '%sitename%', '%sep%', '%excerpt%', '%tagline%', '%currentyear%', '%category%', '%category%' ),
			$template
		);
		if ( ! preg_match_all( '/%([a-z_]+)%/', $template, $m ) ) {
			return Text::replace_vars( $template, array() );
		}
		$category = null;
		$vars     = array();
		foreach ( array_unique( $m[1] ) as $name ) {
			if ( array_key_exists( $name, $overrides ) ) {
				$vars[ $name ] = (string) $overrides[ $name ];
				continue;
			}
			switch ( $name ) {
				case 'title':
					$vars[ $name ] = get_the_title( $post );
					break;
				case 'sitename':
					$vars[ $name ] = get_bloginfo( 'name' );
					break;
				case 'sitedesc':
				case 'tagline':
					$vars[ $name ] = get_bloginfo( 'description' );
					break;
				case 'sep':
					$vars[ $name ] = (string) $this->settings->get( 'meta.separator', '–' );
					break;
				case 'excerpt':
					$vars[ $name ] = $this->auto_description( $post );
					break;
				case 'excerpt_only':
					$vars[ $name ] = '' !== trim( (string) $post->post_excerpt ) ? Text::strip( $post->post_excerpt ) : '';
					break;
				case 'category':
				case 'primary_category':
					if ( null === $category ) {
						$primary  = $this->primary_term( $post->ID, 'category' );
						$term     = $primary ? get_term( $primary, 'category' ) : null;
						$category = $term instanceof \WP_Term ? $term->name : '';
					}
					$vars[ $name ] = $category;
					break;
				case 'author':
				case 'name':
					$vars[ $name ] = get_the_author_meta( 'display_name', (int) $post->post_author );
					break;
				case 'year':
					$vars[ $name ] = get_the_date( 'Y', $post );
					break;
				case 'currentyear':
				case 'current_year':
					$vars[ $name ] = wp_date( 'Y' );
					break;
				case 'date':
					$vars[ $name ] = get_the_date( '', $post );
					break;
				case 'modified':
					$vars[ $name ] = get_the_modified_date( '', $post );
					break;
				case 'focuskw':
					$vars[ $name ] = $this->focus( $post->ID );
					break;
			}
		}
		return Text::replace_vars( $template, $vars );
	}

	/**
	 * Field labels.
	 *
	 * @return array<string,string>
	 */
	public static function field_labels() {
		return array(
			'title'               => __( 'SEO title', 'searchpilot-pro' ),
			'description'         => __( 'meta description', 'searchpilot-pro' ),
			'focus'               => __( 'focus keyword', 'searchpilot-pro' ),
			'canonical'           => __( 'canonical URL', 'searchpilot-pro' ),
			'noindex'             => __( 'noindex', 'searchpilot-pro' ),
			'nofollow'            => __( 'nofollow', 'searchpilot-pro' ),
			'og_title'            => __( 'social title', 'searchpilot-pro' ),
			'og_description'      => __( 'social description', 'searchpilot-pro' ),
			'og_image'            => __( 'social image', 'searchpilot-pro' ),
			'twitter_title'       => __( 'X/Twitter title', 'searchpilot-pro' ),
			'twitter_description' => __( 'X/Twitter description', 'searchpilot-pro' ),
			'schema_type'         => __( 'schema type', 'searchpilot-pro' ),
			'breadcrumb_title'    => __( 'breadcrumb title', 'searchpilot-pro' ),
		);
	}

	/**
	 * Whether a field is a tri-state flag.
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	private function is_flag( $field ) {
		return in_array( $field, array( 'noindex', 'nofollow' ), true );
	}

	/**
	 * Decodes a robots flag from a plugin's storage format.
	 *
	 * @param string $store Store.
	 * @param string $field noindex|nofollow.
	 * @param mixed  $raw   Raw value.
	 * @return bool|null
	 */
	private function decode_robots( $store, $field, $raw ) {
		switch ( $store ) {
			case 'yoast':
				// '1' = noindex, '2' = index (explicit), '' / '0' = default.
				if ( 'noindex' === $field ) {
					return '1' === (string) $raw ? true : ( '2' === (string) $raw ? false : null );
				}
				return '1' === (string) $raw ? true : null;
			case 'rank_math':
				// get_post_meta() already unserialized the stored array; a string that still looks
				// serialized is user-supplied text and must never be unserialized (object injection).
				$list = is_array( $raw ) ? $raw : array();
				if ( ! is_array( $list ) || empty( $list ) ) {
					return null;
				}
				if ( in_array( $field, $list, true ) ) {
					return true;
				}
				$positive = 'noindex' === $field ? 'index' : 'follow';
				return in_array( $positive, $list, true ) ? false : null;
			case 'seopress':
				return 'yes' === (string) $raw ? true : null;
			case 'tsf':
				return '1' === (string) $raw ? true : ( '-1' === (string) $raw ? false : null );
			default:
				return '' === (string) $raw ? null : (bool) $raw;
		}
	}

	/**
	 * Encodes a value in a plugin's storage format.
	 *
	 * @param string $store   Store.
	 * @param string $field   Field.
	 * @param mixed  $value   Value.
	 * @param int    $post_id Post id.
	 * @return mixed Null deletes the meta.
	 */
	private function encode_for_store( $store, $field, $value, $post_id ) {
		if ( $this->is_flag( $field ) ) {
			$flag = null === $value || '' === $value ? null : (bool) $value;
			switch ( $store ) {
				case 'yoast':
					return null === $flag ? null : ( $flag ? '1' : ( 'noindex' === $field ? '2' : null ) );
				case 'rank_math':
					$list = get_post_meta( $post_id, 'rank_math_robots', true );
					$list = is_array( $list ) ? $list : array();
					$neg  = $field;
					$pos  = 'noindex' === $field ? 'index' : 'follow';
					$list = array_values( array_diff( $list, array( $neg, $pos ) ) );
					if ( null !== $flag ) {
						$list[] = $flag ? $neg : $pos;
					}
					return $list ? $list : null;
				case 'seopress':
					return $flag ? 'yes' : null;
				case 'tsf':
					return null === $flag ? null : ( $flag ? '1' : '-1' );
				default:
					return null === $flag ? null : ( $flag ? '1' : '0' );
			}
		}
		if ( in_array( $field, array( 'faq', 'sitemap_exclude', 'links_exclude', 'cornerstone' ), true ) ) {
			return $value ? '1' : null;
		}
		if ( 'og_image' === $field && is_numeric( $value ) ) {
			return (int) $value ? (string) (int) $value : null;
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		if ( 'canonical' === $field ) {
			return esc_url_raw( $value );
		}
		if ( 'focus' === $field && 'rank_math' === $store ) {
			// Keep Rank Math's secondary keywords.
			$existing = array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ) );
			$existing = array_values( array_filter( array_slice( $existing, 1 ) ) );
			return implode( ',', array_merge( array( Text::clean( $value ) ), $existing ) );
		}
		return Text::clean( $value, in_array( $field, array( 'description', 'og_description', 'twitter_description' ), true ) );
	}

	/**
	 * AIOSEO read (its data lives in the aioseo_posts table).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @return mixed
	 */
	private function aioseo_get( $post_id, $field ) {
		global $wpdb;
		$map = array(
			'title'               => 'title',
			'description'         => 'description',
			'canonical'           => 'canonical_url',
			'noindex'             => 'robots_noindex',
			'nofollow'            => 'robots_nofollow',
			'og_title'            => 'og_title',
			'og_description'      => 'og_description',
			'og_image'            => 'og_image_custom_url',
			'twitter_title'       => 'twitter_title',
			'twitter_description' => 'twitter_description',
			'focus'               => 'keyphrases',
		);
		if ( ! isset( $map[ $field ] ) ) {
			return $this->is_flag( $field ) ? null : '';
		}
		$table = $wpdb->prefix . 'aioseo_posts';
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", (int) $post_id ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		if ( ! $row ) {
			return $this->is_flag( $field ) ? null : '';
		}
		if ( $this->is_flag( $field ) ) {
			if ( ! empty( $row['robots_default'] ) ) {
				return null;
			}
			return (bool) ( $row[ $map[ $field ] ] ?? false );
		}
		if ( 'focus' === $field ) {
			$data = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
			return (string) ( $data['focus']['keyphrase'] ?? '' );
		}
		return (string) ( $row[ $map[ $field ] ] ?? '' );
	}

	/**
	 * AIOSEO write through its model when available.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param mixed  $value   Value.
	 * @param array  $opts    Options.
	 * @return int|\WP_Error
	 */
	private function aioseo_set( $post_id, $field, $value, array $opts ) {
		$map = array(
			'title'       => 'title',
			'description' => 'description',
			'canonical'   => 'canonical_url',
		);
		if ( ! isset( $map[ $field ] ) || ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return new \WP_Error( 'searchpilot_unsupported', __( 'Edit this field in All in One SEO.', 'searchpilot-pro' ) );
		}
		$model  = \AIOSEO\Plugin\Common\Models\Post::getPost( (int) $post_id );
		$column = $map[ $field ];
		$before = (string) ( $model->$column ?? '' );
		$new    = Text::clean( $value, 'description' === $field );
		if ( $before === $new ) {
			return 0;
		}
		$model->$column = $new;
		$model->save();
		return $this->changes->record(
			'callback',
			array(
				'action'      => 'seo_' . $field . '_updated',
				/* translators: %s: post title. */
				'description' => $opts['description'] ?? sprintf( __( 'Updated All in One SEO field on “%s”', 'searchpilot-pro' ), get_the_title( $post_id ) ),
				'object_type' => 'post',
				'object_id'   => (int) $post_id,
				'before'      => array(
					'callback' => __CLASS__ . '::revert_aioseo',
					'post_id'  => (int) $post_id,
					'column'   => $column,
					'value'    => $before,
				),
				'after'       => array( 'value' => $new ),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
	}

	/**
	 * Undo for AIOSEO writes.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_aioseo( array $before, array $after, $row, $force = false ) {
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return new \WP_Error( 'searchpilot_unsupported', __( 'All in One SEO is not active.', 'searchpilot-pro' ) );
		}
		$model  = \AIOSEO\Plugin\Common\Models\Post::getPost( (int) $before['post_id'] );
		$column = (string) $before['column'];
		if ( ! $force && (string) ( $model->$column ?? '' ) !== (string) ( $after['value'] ?? '' ) ) {
			return new \WP_Error( 'searchpilot_changed_since', __( 'This was edited again after SearchPilot’s change, so it was not undone automatically.', 'searchpilot-pro' ) );
		}
		$model->$column = (string) $before['value'];
		$model->save();
		return true;
	}
}

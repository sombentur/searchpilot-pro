<?php
/**
 * Editor integration: post meta, Gutenberg sidebar, classic meta box, term and user fields, FAQ block.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Editor;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\Breadcrumbs;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Registers SearchPilot's own `_searchpilot_*` post meta for the REST API
 * (so the block editor saves it with the post), loads the sidebar and the
 * classic meta box, and registers the searchpilot/faq block.
 *
 * When another SEO plugin owns a field, the editors show that plugin's
 * value read-only instead of a second input — no duplicate fields.
 */
class EditorModule implements Module {

	const SIDEBAR_SCRIPT = 'searchpilot-editor-sidebar';
	const CLASSIC_SCRIPT = 'searchpilot-editor-classic';
	const EDITOR_STYLE   = 'searchpilot-editor';

	const SCHEMA_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'none' );

	const INDEXING_API_TYPES = array( 'JobPosting', 'BroadcastEvent' );

	/**
	 * Fields anyone who can edit the post may change (authors, contributors).
	 * Everything else changes indexing or structured data and needs searchpilot_edit (editors+).
	 */
	const AUTHOR_FIELDS = array( 'title', 'description', 'focus', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'breadcrumb_title', 'primary_category' );

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
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Classic meta box.
	 *
	 * @var MetaBox
	 */
	private $metabox;

	/**
	 * Term fields.
	 *
	 * @var TermFields
	 */
	private $terms;

	/**
	 * User fields.
	 *
	 * @var UserFields
	 */
	private $users;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param MetaStore        $meta      Meta store.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param ContentTypes     $types     Content types.
	 * @param MetaBox          $metabox   Meta box.
	 * @param TermFields       $terms     Term fields.
	 * @param UserFields       $users     User fields.
	 */
	public function __construct( Settings $settings, MetaStore $meta, ConflictDetector $conflicts, ContentTypes $types, MetaBox $metabox, TermFields $terms, UserFields $users ) {
		$this->settings  = $settings;
		$this->meta      = $meta;
		$this->conflicts = $conflicts;
		$this->types     = $types;
		$this->metabox   = $metabox;
		$this->terms     = $terms;
		$this->users     = $users;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_meta' ), 99 );
		add_action( 'init', array( $this, 'register_faq_block' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		if ( is_admin() ) {
			$this->metabox->register();
			$this->terms->register();
			$this->users->register();
		}
	}

	/**
	 * Field definitions: field => [type, meta key].
	 *
	 * @return array<string,array{type:string,key:string}>
	 */
	public static function fields() {
		$types = array(
			'title'               => 'string',
			'description'         => 'string',
			'focus'               => 'string',
			'canonical'           => 'string',
			'noindex'             => 'string',
			'nofollow'            => 'string',
			'og_title'            => 'string',
			'og_description'      => 'string',
			'og_image'            => 'string',
			'twitter_title'       => 'string',
			'twitter_description' => 'string',
			'schema_type'         => 'string',
			'breadcrumb_title'    => 'string',
			'indexing_api'        => 'string',
			'faq'                 => 'boolean',
			'sitemap_exclude'     => 'boolean',
			'links_exclude'       => 'boolean',
			'cornerstone'         => 'boolean',
			'primary_category'    => 'integer',
		);
		$out   = array();
		foreach ( $types as $field => $type ) {
			$out[ $field ] = array(
				'type' => $type,
				'key'  => MetaStore::PREFIX . $field,
			);
		}
		return $out;
	}

	/**
	 * MetaStore field name of an editor field.
	 *
	 * @param string $field Editor field.
	 * @return string
	 */
	public static function store_field( $field ) {
		return 'primary_category' === $field ? 'primary_term' : $field;
	}

	/**
	 * Sanitizes a value for one of SearchPilot's own meta keys.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_field( $field, $value ) {
		switch ( $field ) {
			case 'title':
			case 'og_title':
			case 'twitter_title':
			case 'breadcrumb_title':
			case 'description':
			case 'og_description':
			case 'twitter_description':
			case 'focus':
				return self::clean_text( $value );
			case 'canonical':
				$value = trim( (string) $value );
				if ( '' === $value ) {
					return '';
				}
				if ( ! preg_match( '#^https?://#i', $value ) ) {
					$value = Url::absolute( $value, home_url( '/' ) );
				}
				$value = esc_url_raw( $value, array( 'http', 'https' ) );
				// Canonicals pointing to another site are for administrators only.
				return self::canonical_allowed( $value ) ? $value : '';
			case 'noindex':
				return in_array( (string) $value, array( '0', '1' ), true ) ? (string) $value : '';
			case 'nofollow':
				return in_array( (string) $value, array( '1', 'true' ), true ) || true === $value ? '1' : '';
			case 'og_image':
				$value = trim( (string) $value );
				if ( '' === $value || '0' === $value ) {
					return '';
				}
				return ctype_digit( $value ) ? (string) absint( $value ) : esc_url_raw( $value, array( 'http', 'https' ) );
			case 'schema_type':
				return in_array( (string) $value, self::SCHEMA_TYPES, true ) ? (string) $value : '';
			case 'indexing_api':
				return in_array( (string) $value, self::INDEXING_API_TYPES, true ) ? (string) $value : '';
			case 'faq':
			case 'sitemap_exclude':
			case 'links_exclude':
			case 'cornerstone':
				return (bool) rest_sanitize_boolean( $value );
			case 'primary_category':
				return absint( $value );
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Single-line plain text that keeps template variables.
	 *
	 * sanitize_text_field() removes anything that looks like a percent-encoded
	 * octet, which destroys variables such as %category% or %date% (%ca, %da).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_text( $value ) {
		return Text::clean( $value );
	}

	/**
	 * Whether the current user may change a field of a post.
	 *
	 * @param string $field   Editor field.
	 * @param int    $post_id Post id.
	 * @return bool
	 */
	public static function user_can_edit_field( $field, $post_id ) {
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return false;
		}
		return in_array( $field, self::AUTHOR_FIELDS, true ) || Capabilities::can_edit( (int) $post_id );
	}

	/**
	 * Whether a canonical URL may be stored by the current user: URLs of this
	 * site always; other hosts only for administrators (and background jobs
	 * such as imports running without a user).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function canonical_allowed( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || Url::is_internal( $url ) ) {
			return true;
		}
		return ! is_user_logged_in() || Capabilities::can_manage();
	}

	/**
	 * Post types that get SEO fields.
	 *
	 * @return string[]
	 */
	public function post_types() {
		return array_keys( $this->types->post_types() );
	}

	/**
	 * register_post_meta for every public post type.
	 */
	public function register_meta() {
		foreach ( $this->post_types() as $post_type ) {
			foreach ( self::fields() as $field => $def ) {
				$args = array(
					'type'              => $def['type'],
					'single'            => true,
					// Edit context only: focus keywords and drafts' SEO fields are not public data.
					'show_in_rest'      => array(
						'schema' => array(
							'type'    => $def['type'],
							'context' => array( 'edit' ),
						),
					),
					'auth_callback'     => static function ( $allowed, $meta_key, $object_id ) use ( $field ) {
						return self::user_can_edit_field( $field, (int) $object_id );
					},
					'sanitize_callback' => static function ( $value ) use ( $field ) {
						return self::sanitize_field( $field, $value );
					},
				);
				if ( 'string' === $def['type'] ) {
					$args['default'] = '';
				} elseif ( 'boolean' === $def['type'] ) {
					$args['default'] = false;
				} else {
					$args['default'] = 0;
				}
				register_post_meta( $post_type, $def['key'], $args );
			}
		}
	}

	/**
	 * Registers the searchpilot/faq block.
	 */
	public function register_faq_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		Breadcrumbs::register_assets();
		register_block_type(
			'searchpilot/faq',
			array(
				'api_version'     => 3,
				'title'           => __( 'FAQ (SearchPilot)', 'searchpilot-pro' ),
				'category'        => 'text',
				'editor_script'   => Breadcrumbs::BLOCK_SCRIPT,
				'editor_style'    => Breadcrumbs::FRONTEND_STYLE,
				'render_callback' => array( $this, 'render_faq' ),
				'attributes'      => array(
					'items' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
					'open'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'supports'        => array(
					'html'       => false,
					'align'      => array( 'wide', 'full' ),
					'typography' => array( 'fontSize' => true ),
					'color'      => array(
						'text'       => true,
						'background' => true,
					),
					'spacing'    => array(
						'margin'  => true,
						'padding' => true,
					),
				),
			)
		);
	}

	/**
	 * Renders the FAQ block as accessible <details>/<summary> markup.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public function render_faq( $attributes = array() ) {
		$items = self::faq_items( is_array( $attributes ) ? (array) ( $attributes['items'] ?? array() ) : array() );
		if ( ! $items ) {
			return '';
		}
		$open    = ! empty( $attributes['open'] );
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'searchpilot-faq' ) ) : 'class="searchpilot-faq"';
		$html    = '<div ' . $wrapper . '>';
		foreach ( $items as $item ) {
			$html .= '<details class="searchpilot-faq-item"' . ( $open ? ' open' : '' ) . '>';
			$html .= '<summary class="searchpilot-faq-question">' . esc_html( $item['question'] ) . '</summary>';
			$html .= '<div class="searchpilot-faq-answer">' . wp_kses_post( wpautop( $item['answer'] ) ) . '</div>';
			$html .= '</details>';
		}
		$html .= '</div>';
		Breadcrumbs::register_assets();
		wp_enqueue_style( Breadcrumbs::FRONTEND_STYLE );
		return $html;
	}

	/**
	 * Clean FAQ rows (both question and answer present).
	 *
	 * @param array $items Raw items.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function faq_items( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$answer   = trim( (string) ( $item['answer'] ?? '' ) );
			if ( '' !== $question && '' !== trim( wp_strip_all_tags( $answer ) ) ) {
				$out[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}
		return $out;
	}

	/**
	 * Sidebar assets (post editor, public post types).
	 */
	public function enqueue_block_editor() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, $this->post_types(), true ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$deps = array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-block-editor', 'wp-core-data', 'wp-editor', 'wp-url' );
		if ( wp_script_is( 'wp-edit-post', 'registered' ) ) {
			$deps[] = 'wp-edit-post';
		}
		wp_enqueue_script( self::SIDEBAR_SCRIPT, SEARCHPILOT_URL . 'assets/js/editor/sidebar.js', $deps, SEARCHPILOT_VERSION, true );
		wp_set_script_translations( self::SIDEBAR_SCRIPT, 'searchpilot-pro', SEARCHPILOT_DIR . 'languages' );
		wp_add_inline_script( self::SIDEBAR_SCRIPT, 'window.SearchPilotEditor = ' . wp_json_encode( $this->editor_data( $screen->post_type ) ) . ';', 'before' );
		wp_enqueue_style( self::EDITOR_STYLE, SEARCHPILOT_URL . 'assets/css/editor.css', array(), SEARCHPILOT_VERSION );
	}

	/**
	 * Non-secret data the editor scripts need.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function editor_data( $post_type ) {
		$stores = array();
		foreach ( array_keys( self::fields() ) as $field ) {
			$stores[ $field ] = $this->meta->store_for( self::store_field( $field ) );
		}
		$names = array();
		foreach ( $this->conflicts->detect() as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		$owners = array();
		foreach ( ConflictDetector::FEATURES as $feature ) {
			$owners[ $feature ] = $this->conflicts->owner( $feature );
		}
		$default_schema = $this->types->schema_type( $post_type );
		$labels         = self::schema_labels();
		$schema_options = array(
			array(
				'value' => '',
				/* translators: %s: default schema type. */
				'label' => sprintf( __( 'Default (%s)', 'searchpilot-pro' ), $labels[ $default_schema ] ?? $default_schema ),
			),
		);
		foreach ( self::SCHEMA_TYPES as $type ) {
			$schema_options[] = array(
				'value' => $type,
				'label' => $labels[ $type ] ?? $type,
			);
		}
		return array(
			'namespace'       => 'searchpilot/v1',
			'postType'        => $post_type,
			'siteName'        => (string) get_bloginfo( 'name' ),
			'tagline'         => (string) get_bloginfo( 'description' ),
			'homeUrl'         => home_url( '/' ),
			'separator'       => (string) $this->settings->get( 'meta.separator', '–' ),
			'titleTemplate'   => (string) $this->settings->get( 'page' === $post_type ? 'meta.title_page' : 'meta.title_post', '%title% %sep% %sitename%' ),
			'autoDescription' => (bool) $this->settings->get( 'meta.auto_description', true ),
			'ogEnabled'       => (bool) $this->settings->get( 'meta.og_enabled', true ),
			'twitterEnabled'  => (bool) $this->settings->get( 'meta.twitter_enabled', true ),
			'stores'          => $stores,
			'storeNames'      => $names,
			'owners'          => $owners,
			'authorFields'    => self::AUTHOR_FIELDS,
			'metaKeys'        => array_map(
				static function ( $def ) {
					return $def['key'];
				},
				self::fields()
			),
			'schemaTypes'     => $schema_options,
			'defaultSchema'   => $default_schema,
			'indexingApi'     => (bool) $this->settings->get( 'indexing.google_indexing_api', false ),
			'hasCategories'   => is_object_in_taxonomy( $post_type, 'category' ),
			'counters'        => array(
				'title'       => array( 30, 60 ),
				'description' => array( 120, 156 ),
			),
			'pages'           => array(
				'links'    => admin_url( 'admin.php?page=searchpilot-links' ),
				'schema'   => admin_url( 'admin.php?page=searchpilot-schema' ),
				'settings' => admin_url( 'admin.php?page=searchpilot-settings' ),
				'indexing' => admin_url( 'admin.php?page=searchpilot-indexing' ),
			),
			'caps'            => array(
				'view'   => Capabilities::can_view(),
				'edit'   => Capabilities::can_edit(),
				'manage' => Capabilities::can_manage(),
			),
		);
	}

	/**
	 * Human labels of schema types.
	 *
	 * @return array<string,string>
	 */
	public static function schema_labels() {
		return array(
			'Article'        => __( 'Article', 'searchpilot-pro' ),
			'BlogPosting'    => __( 'Blog post', 'searchpilot-pro' ),
			'NewsArticle'    => __( 'News article', 'searchpilot-pro' ),
			'WebPage'        => __( 'Web page', 'searchpilot-pro' ),
			'AboutPage'      => __( 'About page', 'searchpilot-pro' ),
			'ContactPage'    => __( 'Contact page', 'searchpilot-pro' ),
			'CollectionPage' => __( 'Collection page', 'searchpilot-pro' ),
			'none'           => __( 'No page-specific schema', 'searchpilot-pro' ),
		);
	}
}

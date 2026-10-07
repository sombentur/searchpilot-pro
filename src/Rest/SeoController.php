<?php
/**
 * Per-post and per-term SEO field endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Editor\EditorModule;
use SearchPilot\Seo\HeadOutput;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Seo\TitleResolver;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * GET|POST /seo/post/{id}, GET|POST /seo/term/{id}, POST /seo/resolve.
 *
 * Post routes are open to anyone who can edit posts (authors use the editor
 * sidebar) and always check edit_post for the object itself.
 */
class SeoController extends Controller {

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Titles.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Head output.
	 *
	 * @var HeadOutput
	 */
	private $head;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Conflicts.
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
	 * Constructor.
	 *
	 * @param MetaStore        $meta      Meta store.
	 * @param TitleResolver    $titles    Titles.
	 * @param HeadOutput       $head      Head output.
	 * @param ContentTypes     $types     Content types.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param Changes          $changes   Changes.
	 */
	public function __construct( MetaStore $meta, TitleResolver $titles, HeadOutput $head, ContentTypes $types, ConflictDetector $conflicts, Changes $changes ) {
		$this->meta      = $meta;
		$this->titles    = $titles;
		$this->head      = $head;
		$this->types     = $types;
		$this->conflicts = $conflicts;
		$this->changes   = $changes;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$fields = array(
			'fields' => array(
				'type'     => 'object',
				'required' => true,
			),
		);
		$this->route( '/seo/post/(?P<id>\d+)', 'GET', array( $this, 'get_post' ), 'author', $this->id_arg() );
		$this->route( '/seo/post/(?P<id>\d+)', 'POST', array( $this, 'update_post' ), 'author', array_merge( $this->id_arg(), $fields ) );
		$this->route( '/seo/term/(?P<id>\d+)', 'GET', array( $this, 'get_term' ), 'edit', $this->id_arg() );
		$this->route( '/seo/term/(?P<id>\d+)', 'POST', array( $this, 'update_term' ), 'edit', array_merge( $this->id_arg(), $fields ) );
		$this->route(
			'/seo/resolve',
			'POST',
			array( $this, 'resolve' ),
			'author',
			array(
				'template' => array(
					'type'     => 'string',
					'required' => true,
				),
				'post_id'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'context'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'title'    => array(
					'type' => 'string',
				),
				'excerpt'  => array(
					'type' => 'string',
				),
			)
		);
	}

	/**
	 * Adds the "author" level: anyone who can edit posts (object checks in callbacks).
	 *
	 * @param string $level Level.
	 * @return callable
	 */
	protected function permission( $level ) {
		if ( 'author' !== $level ) {
			return parent::permission( $level );
		}
		return static function () {
			return current_user_can( 'edit_posts' ) || Capabilities::can_edit()
				? true
				: new \WP_Error( 'rest_forbidden', __( 'You are not allowed to do that.', 'searchpilot-pro' ), array( 'status' => rest_authorization_required_code() ) );
		};
	}

	/**
	 * Post the user may edit.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	private function editable_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		return $post;
	}

	/**
	 * Plugin names by id.
	 *
	 * @return array<string,string>
	 */
	private function names() {
		$names = array( 'searchpilot' => __( 'SearchPilot', 'searchpilot-pro' ) );
		foreach ( $this->conflicts->detect() as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		$names += array(
			'yoast'     => 'Yoast SEO',
			'rank_math' => 'Rank Math SEO',
			'aioseo'    => 'All in One SEO',
			'seopress'  => 'SEOPress',
			'tsf'       => 'The SEO Framework',
		);
		return $names;
	}

	/**
	 * GET /seo/post/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_post( \WP_REST_Request $request ) {
		$post = $this->editable_post( (int) $request['id'] );
		return is_wp_error( $post ) ? $post : $this->post_payload( $post );
	}

	/**
	 * Full SEO state of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function post_payload( \WP_Post $post ) {
		$names  = $this->names();
		$fields = array();
		foreach ( EditorModule::fields() as $field => $def ) {
			$mfield = EditorModule::store_field( $field );
			$store  = $this->meta->store_for( $mfield );
			if ( 'primary_category' === $field ) {
				$value = is_object_in_taxonomy( $post->post_type, 'category' ) ? $this->meta->primary_term( $post->ID, 'category' ) : 0;
				$key   = 'searchpilot' === $store ? $def['key'] : MetaStore::post_key( $store, 'primary_term' );
			} else {
				$value = $this->meta->get_post_field( $post->ID, $mfield );
				$key   = 'searchpilot' === $store ? $def['key'] : ( 'aioseo' === $store ? '' : MetaStore::post_key( $store, $mfield ) );
			}
			$permitted        = EditorModule::user_can_edit_field( $field, $post->ID );
			$fields[ $field ] = array(
				'value'      => $value,
				'store'      => $store,
				'store_name' => $names[ $store ] ?? $store,
				'editable'   => 'searchpilot' === $store && $permitted,
				'restricted' => ! $permitted,
				'supported'  => 'searchpilot' === $store || 'aioseo' === $store || '' !== $key,
				'key'        => $key,
			);
		}
		$context = $this->titles->post_context( $post );
		$owners  = array();
		foreach ( array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'breadcrumbs', 'sitemap' ) as $feature ) {
			$owner              = $this->conflicts->owner( $feature );
			$owners[ $feature ] = array(
				'owner' => $owner,
				'name'  => $names[ $owner ] ?? ( 'off' === $owner ? __( 'Off', 'searchpilot-pro' ) : $owner ),
			);
		}
		$canonical = $this->head->canonical( $context );
		if ( '' === $canonical ) {
			$custom    = $this->meta->canonical( $post->ID );
			$canonical = '' !== $custom ? $custom : (string) get_permalink( $post );
		}
		$primary = $this->conflicts->primary_seo_plugin();
		// What the page serves: the companion plugin's own resolution (its template, separator
		// and excerpt rules) when it owns the field, SearchPilot's otherwise.
		$title = method_exists( $this->meta, 'effective_title_details' )
			? (array) $this->meta->effective_title_details( $post )
			: array(
				'title'  => $this->titles->title( $context ),
				'source' => 'searchpilot',
				'origin' => 'unknown',
			);
		$desc  = method_exists( $this->meta, 'effective_description_details' )
			? (array) $this->meta->effective_description_details( $post )
			: array(
				'description' => $this->titles->description( $context ),
				'source'      => 'searchpilot',
				'origin'      => 'unknown',
			);
		return array(
			'id'           => (int) $post->ID,
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'url'          => (string) get_permalink( $post ),
			'fields'       => $fields,
			'effective'    => array(
				'title'              => (string) ( $title['title'] ?? '' ),
				'title_source'       => (string) ( $title['source'] ?? '' ),
				'title_origin'       => (string) ( $title['origin'] ?? '' ),
				'description'        => (string) ( $desc['description'] ?? '' ),
				'description_source' => (string) ( $desc['source'] ?? '' ),
				'description_origin' => (string) ( $desc['origin'] ?? '' ),
				'canonical'          => $canonical,
				'url'                => (string) get_permalink( $post ),
			),
			'robots'       => $this->head->robots_for_post( $post ),
			'indexability' => $this->types->post_indexability( $post ),
			'owners'       => $owners,
			'companion'    => array(
				'id'   => $primary,
				'name' => '' !== $primary ? ( $names[ $primary ] ?? $primary ) : '',
			),
		);
	}

	/**
	 * Converts an input value for MetaStore::set_post_field().
	 *
	 * @param string $field Editor field.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function input_value( $field, $value ) {
		if ( 'noindex' === $field ) {
			if ( null === $value || '' === $value ) {
				return null;
			}
			return in_array( $value, array( true, 1, '1', 'true', 'noindex' ), true );
		}
		if ( 'nofollow' === $field ) {
			return in_array( $value, array( true, 1, '1', 'true', 'nofollow' ), true ) ? true : null;
		}
		$clean = EditorModule::sanitize_field( $field, $value );
		return true === $clean ? true : ( false === $clean ? false : $clean );
	}

	/**
	 * Stored form of a value for one of SearchPilot's own keys (null deletes).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value from input_value().
	 * @return string|null
	 */
	private static function own_stored( $field, $value ) {
		if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			return null === $value ? null : ( $value ? '1' : ( 'noindex' === $field ? '0' : null ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : null;
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value || '0' === $value ? null : $value;
	}

	/**
	 * POST /seo/post/{id} {fields}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update_post( \WP_REST_Request $request ) {
		$post = $this->editable_post( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$input = $request->get_param( 'fields' );
		if ( ! is_array( $input ) || ! $input ) {
			return $this->error( 'searchpilot_invalid', __( 'Send the fields to change.', 'searchpilot-pro' ) );
		}
		$known  = EditorModule::fields();
		$saved  = array();
		$errors = array();
		foreach ( $input as $field => $value ) {
			$field = sanitize_key( (string) $field );
			if ( ! isset( $known[ $field ] ) ) {
				$errors[ $field ] = __( 'Unknown field.', 'searchpilot-pro' );
				continue;
			}
			$store = $this->meta->store_for( EditorModule::store_field( $field ) );
			if ( 'searchpilot' !== $store ) {
				// Fields another SEO plugin owns are edited there (its role manager applies).
				$names            = $this->names();
				$errors[ $field ] = sprintf(
					/* translators: %s: SEO plugin name. */
					__( 'This field is managed by %s. Edit it there.', 'searchpilot-pro' ),
					$names[ $store ] ?? $store
				);
				continue;
			}
			if ( ! EditorModule::user_can_edit_field( $field, $post->ID ) ) {
				$errors[ $field ] = __( 'Only editors and administrators can change this setting.', 'searchpilot-pro' );
				continue;
			}
			$raw   = $value;
			$value = self::input_value( $field, $value );
			if ( 'canonical' === $field && '' === (string) $value && '' !== trim( (string) $raw ) ) {
				$errors[ $field ] = __( 'Use a URL of this site. Only administrators can point the canonical to another website.', 'searchpilot-pro' );
				continue;
			}
			if ( 'primary_category' === $field ) {
				if ( $value && ! has_term( (int) $value, 'category', $post ) ) {
					$errors[ $field ] = __( 'The primary category must be one of the post’s categories.', 'searchpilot-pro' );
					continue;
				}
				$result = $this->changes->update_post_meta( $post->ID, $known[ $field ]['key'], $value ? (string) $value : null, array( 'action' => 'seo_primary_term_updated' ) );
			} else {
				// Own keys: stored with the editor's sanitizer (keeps %variables%), logged and undoable.
				$result = $this->changes->update_post_meta(
					$post->ID,
					$known[ $field ]['key'],
					self::own_stored( $field, $value ),
					array(
						'action'      => 'seo_' . $field . '_updated',
						/* translators: 1: field label, 2: post title. */
						'description' => sprintf( __( 'Updated %1$s of “%2$s”', 'searchpilot-pro' ), MetaStore::field_labels()[ $field ] ?? $field, get_the_title( $post ) ),
						'source'      => 'user',
					)
				);
			}
			if ( is_wp_error( $result ) ) {
				$errors[ $field ] = $result->get_error_message();
			} else {
				$saved[] = $field;
			}
		}
		$payload           = $this->post_payload( get_post( $post->ID ) );
		$payload['saved']  = $saved;
		$payload['errors'] = (object) $errors;
		return $payload;
	}

	/**
	 * Term the user may edit.
	 *
	 * @param int $id Term id.
	 * @return \WP_Term|\WP_Error
	 */
	private function editable_term( $id ) {
		$term = get_term( (int) $id );
		if ( ! $term instanceof \WP_Term ) {
			return $this->error( 'searchpilot_not_found', __( 'Term not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this term.', 'searchpilot-pro' ), 403 );
		}
		return $term;
	}

	/**
	 * Term payload.
	 *
	 * @param \WP_Term $term Term.
	 * @return array
	 */
	private function term_payload( \WP_Term $term ) {
		$names  = $this->names();
		$fields = array();
		foreach ( array( 'title', 'description', 'focus', 'canonical', 'noindex' ) as $field ) {
			$store            = $this->meta->store_for( $field );
			$fields[ $field ] = array(
				'value'      => $this->meta->get_term_field( $term->term_id, $field ),
				'store'      => $store,
				'store_name' => $names[ $store ] ?? $store,
				'editable'   => 'searchpilot' === $store || ( in_array( $store, array( 'rank_math', 'seopress' ), true ) && 'noindex' !== $field ),
			);
		}
		$context = $this->titles->term_context( $term );
		$link    = get_term_link( $term );
		return array(
			'id'           => (int) $term->term_id,
			'taxonomy'     => $term->taxonomy,
			'name'         => $term->name,
			'url'          => is_wp_error( $link ) ? '' : (string) $link,
			'fields'       => $fields,
			'effective'    => array(
				'title'       => $this->titles->title( $context ),
				'description' => $this->titles->description( $context ),
				'canonical'   => $this->head->canonical( $context ),
			),
			'robots'       => $this->head->robots_for_context( $context ),
			'indexability' => $this->types->term_indexability( $term ),
		);
	}

	/**
	 * GET /seo/term/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_term( \WP_REST_Request $request ) {
		$term = $this->editable_term( (int) $request['id'] );
		return is_wp_error( $term ) ? $term : $this->term_payload( $term );
	}

	/**
	 * POST /seo/term/{id} {fields}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update_term( \WP_REST_Request $request ) {
		$term = $this->editable_term( (int) $request['id'] );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$input = $request->get_param( 'fields' );
		if ( ! is_array( $input ) || ! $input ) {
			return $this->error( 'searchpilot_invalid', __( 'Send the fields to change.', 'searchpilot-pro' ) );
		}
		$saved  = array();
		$errors = array();
		foreach ( $input as $field => $value ) {
			$field = sanitize_key( (string) $field );
			if ( ! in_array( $field, array( 'title', 'description', 'focus', 'canonical', 'noindex' ), true ) ) {
				$errors[ $field ] = __( 'Unknown field.', 'searchpilot-pro' );
				continue;
			}
			if ( 'noindex' === $field ) {
				$value = null === $value || '' === $value ? null : in_array( $value, array( true, 1, '1', 'true' ), true );
			} elseif ( 'canonical' === $field ) {
				$raw   = trim( (string) $value );
				$value = EditorModule::sanitize_field( 'canonical', $raw );
				if ( '' !== $raw && '' === $value ) {
					$errors[ $field ] = __( 'Use a URL of this site. Only administrators can point the canonical to another website.', 'searchpilot-pro' );
					continue;
				}
			} else {
				$value = EditorModule::clean_text( $value );
			}
			$result = $this->meta->set_term_field( $term->term_id, $field, $value, array( 'source' => 'user' ) );
			if ( is_wp_error( $result ) ) {
				$errors[ $field ] = $result->get_error_message();
			} else {
				$saved[] = $field;
			}
		}
		$payload           = $this->term_payload( $term );
		$payload['saved']  = $saved;
		$payload['errors'] = (object) $errors;
		return $payload;
	}

	/**
	 * POST /seo/resolve {template, post_id?, context?}: previews a title template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function resolve( \WP_REST_Request $request ) {
		$template = EditorModule::clean_text( $request->get_param( 'template' ) );
		$post_id  = absint( $request->get_param( 'post_id' ) );
		if ( $post_id ) {
			$post = $this->editable_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( '' === trim( $template ) ) {
				$template = $this->meta->default_title_template( $post );
			}
			// Resolve against the unsaved title/excerpt the editor shows (a copy; nothing is saved).
			$draft = clone $post;
			if ( null !== $request->get_param( 'title' ) ) {
				$draft->post_title = Text::clean( $request->get_param( 'title' ) );
			}
			if ( null !== $request->get_param( 'excerpt' ) ) {
				$draft->post_excerpt = Text::clean( $request->get_param( 'excerpt' ), true );
			}
			$title = $this->titles->preview( $template, 'singular', $draft );
		} else {
			if ( ! Capabilities::can_view() ) {
				return $this->error( 'rest_forbidden', __( 'You are not allowed to do that.', 'searchpilot-pro' ), 403 );
			}
			$context = (string) $request->get_param( 'context' );
			$context = in_array( $context, array( 'home', 'term', 'author', 'post_type_archive', 'date', 'search', '404' ), true ) ? $context : 'home';
			$title   = $this->titles->preview( $template, $context );
		}
		return array(
			'title'  => $title,
			'length' => function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title ),
		);
	}
}

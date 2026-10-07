<?php
/**
 * Shared helpers for issue planners.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Container;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * A planner turns an open issue into a Plan (contract C4): a plain,
 * JSON-serialisable description of the exact changes, with display
 * strings for the diff preview, the exact values to write and a hash of
 * every current value, so the fix engine can refuse to apply a plan when
 * the site changed after planning. Planners never write anything.
 */
abstract class AbstractFixer {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Service shortcut.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	protected function get( $class ) {
		return $this->container->get( $class );
	}

	/**
	 * Hash of a current value, identical to the fix engine's `expect` check
	 * (contract C4: arrays/objects → sha1 of the JSON, scalars → sha1 of the string).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		if ( class_exists( '\SearchPilot\Fixes\Plan' ) && method_exists( '\SearchPilot\Fixes\Plan', 'hash' ) ) {
			return (string) \SearchPilot\Fixes\Plan::hash( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return sha1( (string) wp_json_encode( $value ) );
		}
		return sha1( (string) $value );
	}

	/**
	 * Empty plan for an issue.
	 *
	 * @param array  $issue       Issue.
	 * @param string $summary     One-line summary.
	 * @param string $object_type post|attachment|site.
	 * @param int    $object_id   Object id.
	 * @return array
	 */
	protected static function new_plan( array $issue, $summary, $object_type = 'post', $object_id = null ) {
		return array(
			'issue_id'    => (int) ( $issue['id'] ?? 0 ),
			'type'        => (string) ( $issue['type'] ?? '' ),
			'object_type' => $object_type,
			'object_id'   => null === $object_id ? (int) ( $issue['object_id'] ?? 0 ) : (int) $object_id,
			'summary'     => (string) $summary,
			'changes'     => array(),
			'verify'      => array(
				'types'   => array( (string) ( $issue['type'] ?? '' ) ),
				'objects' => array(),
			),
			'warnings'    => array(),
		);
	}

	/**
	 * One change entry.
	 *
	 * @param string $kind      seo_field|post_content|post_meta|attachment_alt|featured_image|option.
	 * @param int    $object_id Post/attachment id (0 for options).
	 * @param string $field     Field / meta key / option name.
	 * @param string $label     Human label.
	 * @param string $before    Display string before.
	 * @param string $after     Display string after.
	 * @param mixed  $value     Exact value to write.
	 * @param mixed  $current   Current stored value (hashed into `expect`).
	 * @return array
	 */
	protected static function change( $kind, $object_id, $field, $label, $before, $after, $value, $current ) {
		return array(
			'kind'      => $kind,
			'object_id' => (int) $object_id,
			'field'     => (string) $field,
			'label'     => (string) $label,
			'before'    => (string) $before,
			'after'     => (string) $after,
			'value'     => $value,
			'expect'    => self::hash( $current ),
		);
	}

	/**
	 * The issue's post, or an error.
	 *
	 * @param array $issue Issue.
	 * @return \WP_Post|\WP_Error
	 */
	protected static function post_of( array $issue ) {
		$post = 'post' === ( $issue['object_type'] ?? '' ) ? get_post( (int) ( $issue['object_id'] ?? 0 ) ) : null;
		if ( ! $post instanceof \WP_Post || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists, so there is nothing to fix.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Page builder that owns the post layout ('' for block/classic content).
	 *
	 * @param \WP_Post|int $post Post.
	 * @return string
	 */
	protected static function builder( $post ) {
		return ContentRenderer::builder_of( $post );
	}

	/**
	 * Human name of the builder of a post.
	 *
	 * @param string $builder Builder id.
	 * @return string
	 */
	protected static function builder_label( $builder ) {
		$name = ContentRenderer::builder_name( $builder );
		return '' !== $name ? $name : __( 'a page builder', 'searchpilot-pro' );
	}

	/**
	 * Who owns a field and whether SearchPilot can write it (contract C2).
	 *
	 * @param string $field title|description|…
	 * @return array{owner:string,owner_name:string,writable:bool,reason:string}
	 */
	protected function owner_of( $field ) {
		$meta = $this->get( MetaStore::class );
		if ( method_exists( $meta, 'field_owner' ) ) {
			$owner = (array) $meta->field_owner( $field );
			return array(
				'owner'      => (string) ( $owner['owner'] ?? 'searchpilot' ),
				'owner_name' => (string) ( $owner['owner_name'] ?? 'SearchPilot' ),
				'writable'   => ! empty( $owner['writable'] ),
				'reason'     => (string) ( $owner['reason'] ?? '' ),
			);
		}
		$store = $meta->store_for( $field );
		return array(
			'owner'      => $store,
			'owner_name' => self::store_name( $store ),
			'writable'   => 'aioseo' === $store || '' !== MetaStore::post_key( $store, $field ),
			'reason'     => '',
		);
	}

	/**
	 * Error when SearchPilot cannot write a field into its owner's store.
	 *
	 * @param string $field Field.
	 * @return \WP_Error|null
	 */
	protected function unwritable( $field ) {
		$owner = $this->owner_of( $field );
		if ( $owner['writable'] ) {
			return null;
		}
		$message = '' !== $owner['reason'] ? $owner['reason'] : sprintf(
			/* translators: %s: plugin name. */
			__( '%s manages this field and SearchPilot cannot write it. Change it in that plugin’s settings for this post.', 'searchpilot-pro' ),
			$owner['owner_name']
		);
		return new \WP_Error( 'searchpilot_not_owner', $message, array( 'status' => 409 ) );
	}

	/**
	 * Display name of a meta store.
	 *
	 * @param string $store Store id.
	 * @return string
	 */
	protected static function store_name( $store ) {
		$names = array(
			'searchpilot' => 'SearchPilot',
			'rank_math'   => 'Rank Math',
			'yoast'       => 'Yoast SEO',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
			'tsf'         => 'The SEO Framework',
		);
		return $names[ $store ] ?? 'SearchPilot';
	}

	/**
	 * What the page serves for a head field right now: a crawl taken after
	 * the last edit (C2 rendered_head), else the owner's resolution.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $field title|description.
	 * @return string
	 */
	protected function served( \WP_Post $post, $field ) {
		$meta = $this->get( MetaStore::class );
		if ( method_exists( $meta, 'rendered_head' ) ) {
			$head = $meta->rendered_head( $post->ID );
			if ( is_array( $head ) && ! empty( $head['fresh'] ) && '' !== trim( (string) ( $head[ $field ] ?? '' ) ) ) {
				return trim( (string) $head[ $field ] );
			}
		}
		return 'title' === $field ? (string) $meta->effective_title( $post ) : (string) $meta->effective_description( $post );
	}

	/**
	 * Whether a post is a utility page (contact, legal, corrections…) under
	 * the per-post-type detection rules (`analysis.post_type_rules`).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	protected function is_utility( \WP_Post $post ) {
		$rule = null;
		try {
			$analyzer = '\SearchPilot\Seo\Analysis\Analyzer';
			if ( method_exists( $analyzer, 'post_type_rule' ) ) {
				$method = new \ReflectionMethod( $analyzer, 'post_type_rule' );
				$rule   = $method->isStatic() ? $analyzer::post_type_rule( $post->post_type ) : $this->get( $analyzer )->post_type_rule( $post->post_type );
			}
		} catch ( \Throwable $e ) {
			$rule = null;
		}
		if ( ! is_array( $rule ) ) {
			$rules = (array) $this->get( Settings::class )->get( 'analysis.post_type_rules', array( 'page' => array( 'utility' => true ) ) );
			$rule  = isset( $rules[ $post->post_type ] ) && is_array( $rules[ $post->post_type ] ) ? $rules[ $post->post_type ] : array( 'utility' => 'page' === $post->post_type );
		}
		return ! empty( $rule['utility'] );
	}

	/**
	 * A post title as plain text (get_the_title() returns texturized HTML
	 * entities such as &#8217;, which would show literally in the UI).
	 *
	 * @param \WP_Post|int $post Post.
	 * @return string
	 */
	protected static function title_of( $post ) {
		return self::plain( get_the_title( $post ) );
	}

	/**
	 * HTML/entities → plain text.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	protected static function plain( $text ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Quotes a text for a diff line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function quote( $text ) {
		/* translators: %s: quoted text. */
		return sprintf( __( '“%s”', 'searchpilot-pro' ), (string) $text );
	}
}

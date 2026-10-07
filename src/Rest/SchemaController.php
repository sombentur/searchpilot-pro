<?php
/**
 * Structured data endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Schema\SchemaBuilder;
use SearchPilot\Schema\SchemaConflicts;
use SearchPilot\Schema\SchemaValidator;
use SearchPilot\Seo\HeadOutput;

defined( 'ABSPATH' ) || exit;

/**
 * GET /schema, GET /schema/preview, POST /schema/validate, GET /schema/types.
 */
class SchemaController extends Controller {

	const ISSUE_TYPES = array( 'schema_duplicate', 'schema_invalid_json', 'schema_missing_required', 'schema_conflict_microdata', 'duplicate_meta_output', 'org_logo_missing' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Builder.
	 *
	 * @var SchemaBuilder
	 */
	private $builder;

	/**
	 * Validator.
	 *
	 * @var SchemaValidator
	 */
	private $validator;

	/**
	 * Checks.
	 *
	 * @var SchemaConflicts
	 */
	private $checks;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param SchemaBuilder    $builder   Builder.
	 * @param SchemaValidator  $validator Validator.
	 * @param SchemaConflicts  $checks    Checks.
	 * @param IssueRepository  $issues    Issues.
	 * @param ContentTypes     $types     Content types.
	 */
	public function __construct( Settings $settings, ConflictDetector $conflicts, SchemaBuilder $builder, SchemaValidator $validator, SchemaConflicts $checks, IssueRepository $issues, ContentTypes $types ) {
		$this->settings  = $settings;
		$this->conflicts = $conflicts;
		$this->builder   = $builder;
		$this->validator = $validator;
		$this->checks    = $checks;
		$this->issues    = $issues;
		$this->types     = $types;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$post_arg = array(
			'post_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		$this->route( '/schema', 'GET', array( $this, 'overview' ), 'view' );
		$this->route( '/schema/preview', 'GET', array( $this, 'preview' ), 'edit', $post_arg );
		$this->route( '/schema/validate', 'POST', array( $this, 'validate' ), 'edit', $post_arg );
		$this->route( '/schema/types', 'GET', array( $this, 'types' ), 'view' );
	}

	/**
	 * Plugin names.
	 *
	 * @return array<string,string>
	 */
	private function names() {
		$names = array( 'searchpilot' => __( 'SearchPilot', 'searchpilot-pro' ) );
		foreach ( $this->conflicts->detect() as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		return $names;
	}

	/**
	 * GET /schema.
	 *
	 * @return array
	 */
	public function overview() {
		$names     = $this->names();
		$owner     = $this->conflicts->owner( 'schema' );
		$report    = $this->conflicts->report();
		$providers = array();
		foreach ( $this->conflicts->providers( 'schema' ) as $id ) {
			$providers[] = array(
				'id'   => $id,
				'name' => $names[ $id ] ?? $id,
			);
		}
		$logo_id    = (int) $this->settings->get( 'schema.org_logo', 0 );
		$theme_logo = (int) get_theme_mod( 'custom_logo', 0 );
		$logo       = $this->builder->logo_image();
		$counts     = array();
		$total      = 0;
		foreach ( self::ISSUE_TYPES as $type ) {
			$result          = $this->issues->query(
				array(
					'type'     => array( $type ),
					'per_page' => 1,
				)
			);
			$counts[ $type ] = (int) $result['total'];
			$total          += (int) $result['total'];
		}
		$post_types = array();
		foreach ( $this->types->post_types() as $name => $pt ) {
			$post_types[] = array(
				'name'   => $name,
				'label'  => $pt['label'],
				'schema' => $pt['schema'],
				'index'  => $pt['index'],
			);
		}
		$primary = $this->conflicts->primary_seo_plugin();
		return array(
			'owner'          => $owner,
			'owner_name'     => $names[ $owner ] ?? ( 'off' === $owner ? __( 'Off', 'searchpilot-pro' ) : $owner ),
			'owns'           => 'searchpilot' === $owner,
			'setting'        => (string) $this->settings->get( 'ownership.schema', 'auto' ),
			'providers'      => $providers,
			'suppressible'   => (bool) ( $report['features']['schema']['suppressible'] ?? false ),
			'companion'      => '' !== $primary ? ( $names[ $primary ] ?? $primary ) : '',
			'breadcrumbs'    => array(
				'owner'      => $this->conflicts->owner( 'breadcrumbs' ),
				'owner_name' => $names[ $this->conflicts->owner( 'breadcrumbs' ) ] ?? $this->conflicts->owner( 'breadcrumbs' ),
			),
			'settings'       => array(
				'entity'          => (string) $this->settings->get( 'schema.entity', 'organization' ),
				'org_name'        => (string) $this->settings->get( 'schema.org_name', '' ),
				'org_legal_name'  => (string) $this->settings->get( 'schema.org_legal_name', '' ),
				'org_logo'        => $logo_id,
				'person_user'     => (int) $this->settings->get( 'schema.person_user', 0 ),
				'same_as'         => array_values( (array) $this->settings->get( 'schema.same_as', array() ) ),
				'contact_email'   => (string) $this->settings->get( 'schema.contact_email', '' ),
				'contact_phone'   => (string) $this->settings->get( 'schema.contact_phone', '' ),
				'breadcrumbs'     => (string) $this->settings->get( 'schema.breadcrumbs', 'displayed' ),
				'faq'             => (bool) $this->settings->get( 'schema.faq', true ),
				'video'           => (bool) $this->settings->get( 'schema.video', true ),
				'news_categories' => array_values( array_map( 'intval', (array) $this->settings->get( 'schema.news_categories', array() ) ) ),
			),
			'logo'           => array(
				'url'    => $logo ? $logo['url'] : '',
				'width'  => $logo ? (int) $logo['width'] : 0,
				'height' => $logo ? (int) $logo['height'] : 0,
				'source' => $logo_id ? 'setting' : ( $theme_logo && $logo ? 'theme' : '' ),
				'ok'     => $logo && ( ! $logo['width'] || $logo['width'] >= 112 ) && ( ! $logo['height'] || $logo['height'] >= 112 ),
			),
			'post_types'     => $post_types,
			'issues'         => array(
				'counts' => $counts,
				'total'  => $total,
			),
			'video_detector' => class_exists( '\SearchPilot\Sitemap\VideoDetector' ),
			'site_name'      => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Post for a preview (edit rights required).
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! Capabilities::can_edit( $post->ID ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		return $post;
	}

	/**
	 * GET /schema/preview?post_id=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$post = $this->post( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$graph = $this->builder->for_post( $post );
		$owner = $this->conflicts->owner( 'schema' );
		$names = $this->names();
		return array(
			'post'        => array(
				'id'     => (int) $post->ID,
				'title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'    => (string) get_permalink( $post ),
				'status' => $post->post_status,
				'type'   => $this->builder->schema_type_for_post( $post ),
			),
			'graph'       => $graph,
			'json'        => SchemaBuilder::encode( $graph, true ),
			'validation'  => $this->validator->validate( $graph ),
			'owns'        => 'searchpilot' === $owner,
			'owner_name'  => $names[ $owner ] ?? $owner,
			'breadcrumbs' => (string) $this->settings->get( 'schema.breadcrumbs', 'displayed' ),
		);
	}

	/**
	 * POST /schema/validate {post_id}: validates and updates the post's schema issue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function validate( \WP_REST_Request $request ) {
		$post = $this->post( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$graph      = $this->builder->for_post( $post );
		$validation = $this->validator->validate( $graph );
		if ( $this->conflicts->owns( 'schema' ) && 'publish' === $post->post_status ) {
			$this->checks->check_post( $post );
		}
		return array(
			'post_id'    => (int) $post->ID,
			'validation' => $validation,
		);
	}

	/**
	 * GET /schema/types.
	 *
	 * @return array
	 */
	public function types() {
		return array(
			'items' => array(
				array(
					'type'  => 'WebSite',
					'label' => __( 'Website', 'searchpilot-pro' ),
					'where' => __( 'Every page', 'searchpilot-pro' ),
					'notes' => __( 'Site name and publisher. No sitelinks search box: Google retired that feature.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'Organization',
					'label' => __( 'Organization or Person', 'searchpilot-pro' ),
					'where' => __( 'Every page', 'searchpilot-pro' ),
					'notes' => __( 'Name, logo (at least 112 × 112 px), social profiles and contact details from the Schema settings.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'WebPage',
					'label' => __( 'Web page', 'searchpilot-pro' ),
					'where' => __( 'Every page (AboutPage, ContactPage, CollectionPage for archives, ProfilePage for authors, SearchResultsPage for search)', 'searchpilot-pro' ),
					'notes' => __( 'Title, description, dates and primary image of the page.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'Article',
					'label' => __( 'Article, BlogPosting, NewsArticle', 'searchpilot-pro' ),
					'where' => __( 'Posts and other content types set to an article type (NewsArticle for the news categories you choose)', 'searchpilot-pro' ),
					'notes' => __( 'Headline, image, dates, authors, publisher, section and keywords. Use featured images at least 1200 px wide.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'BreadcrumbList',
					'label' => __( 'Breadcrumbs', 'searchpilot-pro' ),
					'where' => __( 'Pages showing breadcrumbs (or every page when set to “always”)', 'searchpilot-pro' ),
					'notes' => __( 'Matches the visible breadcrumb trail.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'FAQPage',
					'label' => __( 'FAQ', 'searchpilot-pro' ),
					'where' => __( 'Pages with the SearchPilot FAQ block (or Details blocks when the FAQ option is on for the page)', 'searchpilot-pro' ),
					'notes' => __( 'Built from the visible questions and answers. Google shows FAQ rich results only for well-known, authoritative government and health websites.', 'searchpilot-pro' ),
				),
				array(
					'type'  => 'VideoObject',
					'label' => __( 'Video', 'searchpilot-pro' ),
					'where' => __( 'Pages with embedded YouTube, Vimeo or self-hosted videos', 'searchpilot-pro' ),
					'notes' => __( 'Name, description, thumbnail, upload date, duration and embed URL detected from the page.', 'searchpilot-pro' ),
				),
			),
			'never' => __( 'SearchPilot never generates HowTo, Review/Rating, Event, Product, JobPosting or Recipe markup on its own: structured data must describe content that is visible on the page. WooCommerce describes products itself.', 'searchpilot-pro' ),
		);
	}
}

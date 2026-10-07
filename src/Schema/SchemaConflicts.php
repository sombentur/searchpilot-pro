<?php
/**
 * Structured data and head-tag conflict detection on real pages.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Schema;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\HtmlDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the indexer's `searchpilot_page_crawled` and inspects the HTML
 * search engines actually receive: broken JSON-LD, the same entity described
 * twice (two plugins or a plugin and the theme), theme microdata duplicating
 * the JSON-LD, SearchPilot's own graph failing validation, and duplicated
 * head tags (two canonicals, two descriptions, two Open Graph sets).
 */
class SchemaConflicts {

	const PAGE_TYPES = array( 'schema_invalid_json', 'schema_duplicate', 'schema_conflict_microdata', 'schema_missing_required', 'duplicate_meta_output' );

	/**
	 * Entity groups whose duplication confuses search engines.
	 */
	const GROUPS = array(
		'organization' => array( 'Organization', 'Corporation', 'NewsMediaOrganization', 'OnlineBusiness', 'OnlineStore', 'NGO', 'EducationalOrganization', 'GovernmentOrganization', 'MedicalOrganization', 'SportsOrganization', 'PerformingGroup', 'Airline', 'Consortium' ),
		'website'      => array( 'WebSite' ),
		'article'      => array( 'Article', 'NewsArticle', 'BlogPosting', 'ReportageNewsArticle', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'BackgroundNewsArticle', 'ReviewNewsArticle', 'TechArticle', 'ScholarlyArticle', 'LiveBlogPosting' ),
		'breadcrumb'   => array( 'BreadcrumbList' ),
		'webpage'      => array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage', 'SearchResultsPage', 'ItemPage', 'CheckoutPage', 'MedicalWebPage', 'QAPage' ),
		'faq'          => array( 'FAQPage' ),
	);

	/**
	 * Script markers of known JSON-LD emitters (class/id fragment => plugin id).
	 */
	const SCRIPT_SOURCES = array(
		'searchpilot-schema'         => 'searchpilot',
		'yoast-schema-graph'         => 'yoast',
		'rank-math-schema'           => 'rank_math',
		'aioseo-schema'              => 'aioseo',
		'saswp-schema-markup-output' => 'saswp',
		'schema-pro'                 => 'schema_pro',
		'slim-seo-schema'            => 'slim_seo',
		'wpsso-schema'               => 'wpsso',
	);

	/**
	 * Issue repository.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Validator.
	 *
	 * @var SchemaValidator
	 */
	private $validator;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Builder.
	 *
	 * @var SchemaBuilder
	 */
	private $builder;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param IssueRepository  $issues    Issues.
	 * @param SchemaValidator  $validator Validator.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param Settings         $settings  Settings.
	 * @param SchemaBuilder    $builder   Builder.
	 * @param ContentTypes     $types     Content types.
	 */
	public function __construct( IssueRepository $issues, SchemaValidator $validator, ConflictDetector $conflicts, Settings $settings, SchemaBuilder $builder, ContentTypes $types ) {
		$this->issues    = $issues;
		$this->validator = $validator;
		$this->conflicts = $conflicts;
		$this->settings  = $settings;
		$this->builder   = $builder;
		$this->types     = $types;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_types' ) );
		add_action( 'searchpilot_page_crawled', array( $this, 'on_page_crawled' ), 20 );
		add_action( 'searchpilot_daily', array( $this, 'daily' ), 40 );
	}

	/* --------------------------------------------------------------------- *
	 * Issue types
	 * --------------------------------------------------------------------- */

	/**
	 * Registers C's issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_types( IssueRegistry $registry ) {
		$registry->register(
			'schema_invalid_json',
			array(
				'label'    => __( 'Broken structured data (invalid JSON-LD)', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$blocks = (array) ( $issue['data']['blocks'] ?? array() );
					$first  = $blocks ? (array) $blocks[0] : array();
					return sprintf(
						/* translators: 1: number of blocks, 2: parser error, 3: source. */
						_n( '%1$d JSON-LD block on this page cannot be parsed (%2$s). Source: %3$s.', '%1$d JSON-LD blocks on this page cannot be parsed (first error: %2$s). Source: %3$s.', max( 1, count( $blocks ) ), 'searchpilot-pro' ),
						max( 1, count( $blocks ) ),
						(string) ( $first['error'] ?? __( 'syntax error', 'searchpilot-pro' ) ),
						(string) ( $first['source_name'] ?? __( 'unknown', 'searchpilot-pro' ) )
					);
				},
				'why'      => __( 'Search engines ignore structured data they cannot parse, so the page loses rich result eligibility and the knowledge information it carried.', 'searchpilot-pro' ),
				'fix'      => __( 'Find the plugin, theme or custom code that prints this script (the source above) and correct or remove it. Test the page with Google’s Rich Results Test afterwards.', 'searchpilot-pro' ),
				'action'   => 'improve_structured_data',
			)
		);
		$registry->register(
			'schema_duplicate',
			array(
				'label'    => __( 'The same entity is described twice in structured data', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$parts = array();
					foreach ( (array) ( $issue['data']['duplicates'] ?? array() ) as $dup ) {
						$parts[] = sprintf(
							/* translators: 1: schema types, 2: count, 3: sources. */
							__( '%1$s × %2$d (from %3$s)', 'searchpilot-pro' ),
							implode( '/', (array) ( $dup['types'] ?? array() ) ),
							(int) ( $dup['count'] ?? 2 ),
							implode( ', ', (array) ( $dup['sources'] ?? array() ) )
						);
					}
					/* translators: %s: list of duplicated entities. */
					return sprintf( __( 'Separate JSON-LD blocks describe the same thing: %s.', 'searchpilot-pro' ), implode( '; ', $parts ) );
				},
				'why'      => __( 'Two Organization, WebSite or Article descriptions of one page contradict each other. Google has to guess which one is right, and rich results or the site name can be shown incorrectly.', 'searchpilot-pro' ),
				'fix'      => __( 'Keep one source of structured data. Switch off the schema output of the other plugin or theme, or set SearchPilot → Settings → Compatibility → Structured data to the plugin you want to keep.', 'searchpilot-pro' ),
				'action'   => 'improve_structured_data',
			)
		);
		$registry->register(
			'schema_conflict_microdata',
			array(
				'label'    => __( 'Theme microdata duplicates the JSON-LD', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					/* translators: %s: schema types. */
					return sprintf( __( 'The page marks up %s both as microdata (itemtype attributes, usually from the theme) and in JSON-LD.', 'searchpilot-pro' ), implode( ', ', (array) ( $issue['data']['types'] ?? array() ) ) );
				},
				'why'      => __( 'Search engines may read two slightly different versions of the same article or breadcrumb trail, which can produce duplicate or wrong rich results.', 'searchpilot-pro' ),
				'fix'      => __( 'Turn off the theme’s schema/microdata option (many themes have one), or use a child theme that removes the itemscope/itemtype attributes. The JSON-LD graph already describes the page.', 'searchpilot-pro' ),
				'action'   => 'improve_structured_data',
			)
		);
		$registry->register(
			'schema_missing_required',
			array(
				'label'    => __( 'Structured data is missing required properties', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$messages = array();
					foreach ( array_slice( (array) ( $issue['data']['errors'] ?? array() ), 0, 3 ) as $error ) {
						$messages[] = (string) ( $error['type'] ?? '' ) . ': ' . (string) ( $error['message'] ?? '' );
					}
					return sprintf(
						/* translators: 1: number of errors, 2: first errors. */
						_n( 'SearchPilot’s structured data on this page has %1$d error: %2$s', 'SearchPilot’s structured data on this page has %1$d errors: %2$s', max( 1, (int) ( $issue['data']['count'] ?? count( $messages ) ) ), 'searchpilot-pro' ),
						max( 1, (int) ( $issue['data']['count'] ?? count( $messages ) ) ),
						implode( ' ', $messages )
					);
				},
				'why'      => __( 'Items with missing required properties are not eligible for rich results such as article, breadcrumb or video features.', 'searchpilot-pro' ),
				'fix'      => __( 'Fill in the missing information — usually a featured image, the organization logo or the author name. SearchPilot → Schema → Preview & validate shows every error for a page.', 'searchpilot-pro' ),
				'action'   => 'improve_structured_data',
			)
		);
		$registry->register(
			'duplicate_meta_output',
			array(
				'label'    => __( 'Duplicate SEO tags in the page head', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$parts = array();
					foreach ( (array) ( $issue['data']['tags'] ?? array() ) as $tag ) {
						$parts[] = sprintf(
							/* translators: 1: tag, 2: count, 3: likely sources. */
							__( '%1$s appears %2$d times (likely from %3$s)', 'searchpilot-pro' ),
							(string) ( $tag['label'] ?? $tag['tag'] ?? '' ),
							(int) ( $tag['count'] ?? 2 ),
							implode( ', ', (array) ( $tag['sources'] ?? array() ) )
						);
					}
					return implode( '; ', $parts ) . '.';
				},
				'why'      => __( 'With two canonical tags Google ignores both; with two descriptions or two Open Graph sets search engines and social networks pick one at random.', 'searchpilot-pro' ),
				'fix'      => __( 'Let exactly one plugin output each tag. In SearchPilot → Settings → Compatibility choose who owns titles, descriptions, canonicals and social tags, and switch the same output off in the other plugin or the theme.', 'searchpilot-pro' ),
				'action'   => 'fix_indexing',
			)
		);
		$registry->register(
			'org_logo_missing',
			array(
				'label'    => __( 'Organization logo is not set', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'medium',
				'what'     => __( 'SearchPilot describes your site as an organization in structured data, but no logo is set (neither in SearchPilot nor in the theme’s site logo).', 'searchpilot-pro' ),
				'why'      => __( 'Google uses the organization logo in search results and knowledge panels. Without it, a generic icon or nothing is shown.', 'searchpilot-pro' ),
				'fix'      => __( 'Open SearchPilot → Schema and choose a square logo of at least 112 × 112 pixels (512 × 512 recommended).', 'searchpilot-pro' ),
				'action'   => 'improve_structured_data',
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Crawled pages
	 * --------------------------------------------------------------------- */

	/**
	 * searchpilot_page_crawled.
	 *
	 * @param array $page Crawled page.
	 */
	public function on_page_crawled( $page ) {
		if ( ! is_array( $page ) || 200 !== (int) ( $page['status'] ?? 0 ) ) {
			return;
		}
		$ctype = strtolower( (string) ( $page['headers']['content-type'] ?? 'text/html' ) );
		if ( false === strpos( $ctype, 'html' ) ) {
			return;
		}
		$url = (string) ( $page['final_url'] ?? '' );
		if ( '' === $url ) {
			$url = (string) ( $page['url'] ?? '' );
		}
		$doc = $page['doc'] ?? null;
		if ( ! $doc instanceof HtmlDocument ) {
			$html = (string) ( $page['html'] ?? '' );
			if ( '' === $html ) {
				return;
			}
			$doc = HtmlDocument::parse( $html, $url );
		}
		try {
			$findings = $this->analyze( $doc );
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->warning( 'schema', 'Structured data check failed: ' . $e->getMessage(), array( 'url' => $url ) );
			return;
		}
		$this->store(
			$findings,
			(string) ( $page['object_type'] ?? '' ),
			(int) ( $page['object_id'] ?? 0 ),
			(int) ( $page['row_id'] ?? 0 ),
			$url
		);
	}

	/**
	 * Analyzes a document with the live site's ownership.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<string,array> Issue type => data.
	 */
	public function analyze( HtmlDocument $doc ) {
		$detected = $this->conflicts->detect();
		$names    = array( 'searchpilot' => __( 'SearchPilot', 'searchpilot-pro' ) );
		foreach ( $detected as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		$sources = array();
		foreach ( array(
			'canonical'   => 'canonical',
			'description' => 'description',
			'og:title'    => 'social',
		) as $tag => $feature ) {
			$list = array();
			foreach ( $this->conflicts->providers( $feature ) as $id ) {
				$list[] = $names[ $id ] ?? $id;
			}
			if ( $this->conflicts->owns( $feature ) ) {
				array_unshift( $list, $names['searchpilot'] );
			}
			$sources[ $tag ] = $list;
		}
		return self::inspect(
			$doc,
			array(
				'validator' => $this->validator,
				'names'     => $names,
				'sources'   => $sources,
			)
		);
	}

	/**
	 * Pure inspection of a document.
	 *
	 * @param HtmlDocument $doc  Document.
	 * @param array        $opts validator (SchemaValidator), names (plugin id => name), sources (tag => likely source names).
	 * @return array<string,array> Issue type => data (only types with findings).
	 */
	public static function inspect( HtmlDocument $doc, array $opts = array() ) {
		$names     = (array) ( $opts['names'] ?? array() );
		$validator = $opts['validator'] ?? null;
		$found     = array();
		$scripts   = array();

		foreach ( $doc->query( '//script[@type]' ) as $index => $node ) {
			/** @var \DOMElement $node */
			if ( 'application/ld+json' !== strtolower( trim( $node->getAttribute( 'type' ) ) ) ) {
				continue;
			}
			$raw    = trim( (string) $node->textContent );
			$marker = strtolower( $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' ) );
			$source = 'unknown';
			foreach ( self::SCRIPT_SOURCES as $fragment => $id ) {
				if ( false !== strpos( $marker, $fragment ) ) {
					$source = $id;
					break;
				}
			}
			if ( '' === $raw ) {
				continue;
			}
			$data  = json_decode( $raw, true );
			$error = JSON_ERROR_NONE === json_last_error() ? '' : json_last_error_msg();
			$scripts[] = array(
				'index'  => count( $scripts ),
				'data'   => $data,
				'error'  => $error,
				'raw'    => $raw,
				'source' => $source,
			);
		}

		// 1. Invalid JSON.
		$invalid = array();
		foreach ( $scripts as $script ) {
			if ( '' !== $script['error'] || ! is_array( $script['data'] ) ) {
				$invalid[] = array(
					'error'       => '' !== $script['error'] ? $script['error'] : __( 'not a JSON object', 'searchpilot-pro' ),
					'snippet'     => function_exists( 'mb_substr' ) ? mb_substr( $script['raw'], 0, 120 ) : substr( $script['raw'], 0, 120 ),
					'source'      => $script['source'],
					'source_name' => self::source_name( $script['source'], $names ),
				);
			}
		}
		if ( $invalid ) {
			$found['schema_invalid_json'] = array( 'blocks' => $invalid );
		}

		// 2. Duplicate entities across scripts.
		$groups = array();
		foreach ( $scripts as $script ) {
			if ( ! is_array( $script['data'] ) ) {
				continue;
			}
			foreach ( SchemaValidator::top_nodes( $script['data'] ) as $node ) {
				foreach ( SchemaValidator::types( $node ) as $type ) {
					$group = self::group_of( $type );
					if ( '' === $group ) {
						continue;
					}
					$groups[ $group ]['types'][ $type ] = true;
					$groups[ $group ]['nodes'][]        = array(
						'script' => $script['index'],
						'id'     => isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '',
						'source' => $script['source'],
					);
				}
			}
		}
		$duplicates = array();
		foreach ( $groups as $group => $info ) {
			$scripts_seen = array();
			$distinct_ids = array();
			foreach ( $info['nodes'] as $i => $n ) {
				$scripts_seen[ $n['script'] ] = $n['source'];
				// Nodes without @id are always distinct entities.
				$distinct_ids[ '' !== $n['id'] ? $n['id'] : 'anonymous-' . $i ] = true;
			}
			if ( count( $scripts_seen ) > 1 || count( $distinct_ids ) > 1 ) {
				$source_names = array();
				foreach ( array_unique( array_values( $scripts_seen ) ) as $source ) {
					$source_names[] = self::source_name( $source, $names );
				}
				$duplicates[] = array(
					'group'   => $group,
					'types'   => array_keys( $info['types'] ),
					'count'   => max( count( $scripts_seen ), count( $distinct_ids ) ),
					'sources' => $source_names,
				);
			}
		}
		if ( $duplicates ) {
			$found['schema_duplicate'] = array( 'duplicates' => $duplicates );
		}

		// 3. Microdata / RDFa duplicating JSON-LD articles or breadcrumbs.
		$jsonld_groups = array_keys( $groups );
		$micro         = array();
		$marked        = $doc->microdata_types();
		foreach ( $doc->query( '//*[@typeof]' ) as $node ) {
			/** @var \DOMElement $node */
			foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'typeof' ) ) ) as $type ) {
				$marked[] = (string) preg_replace( '#^(https?://schema\.org/|schema:)#i', '', $type );
			}
		}
		foreach ( $marked as $type ) {
			$group = false !== stripos( $type, 'breadcrumb' ) ? 'breadcrumb' : self::group_of( (string) preg_replace( '#^.*/#', '', $type ) );
			if ( in_array( $group, array( 'article', 'breadcrumb' ), true ) && in_array( $group, $jsonld_groups, true ) ) {
				$micro[ (string) preg_replace( '#^.*/#', '', $type ) ] = true;
			}
		}
		if ( $micro ) {
			$found['schema_conflict_microdata'] = array( 'types' => array_keys( $micro ) );
		}

		// 4. SearchPilot's own graph fails validation.
		if ( $validator instanceof SchemaValidator ) {
			$errors = array();
			foreach ( $scripts as $script ) {
				if ( 'searchpilot' === $script['source'] && is_array( $script['data'] ) ) {
					$result = $validator->validate( $script['data'] );
					$errors = array_merge( $errors, $result['errors'] );
				}
			}
			if ( $errors ) {
				$found['schema_missing_required'] = array(
					'errors' => array_slice( $errors, 0, 20 ),
					'count'  => count( $errors ),
				);
			}
		}

		// 5. Duplicated head tags.
		$sources = (array) ( $opts['sources'] ?? array() );
		$tags    = array();
		$counts  = array(
			'canonical'   => array( count( $doc->link_rel( 'canonical' ) ), __( 'The canonical link', 'searchpilot-pro' ) ),
			'description' => array( count( $doc->metas( 'description' ) ), __( 'The meta description', 'searchpilot-pro' ) ),
			'og:title'    => array( count( $doc->metas( 'og:title' ) ), __( 'The og:title tag (Open Graph set)', 'searchpilot-pro' ) ),
		);
		foreach ( $counts as $tag => $info ) {
			if ( $info[0] > 1 ) {
				$likely = (array) ( $sources[ $tag ] ?? array() );
				if ( count( $likely ) < 2 ) {
					$likely[] = __( 'your theme or another plugin', 'searchpilot-pro' );
				}
				$tags[] = array(
					'tag'     => $tag,
					'label'   => $info[1],
					'count'   => (int) $info[0],
					'sources' => array_values( array_unique( $likely ) ),
				);
			}
		}
		if ( $tags ) {
			$found['duplicate_meta_output'] = array( 'tags' => $tags );
		}
		return $found;
	}

	/**
	 * Entity group of a schema type ('' when not tracked).
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function group_of( $type ) {
		foreach ( self::GROUPS as $group => $types ) {
			if ( in_array( $type, $types, true ) ) {
				return $group;
			}
		}
		return '';
	}

	/**
	 * Display name of a script source.
	 *
	 * @param string $source Source id.
	 * @param array  $names  Plugin names.
	 * @return string
	 */
	private static function source_name( $source, array $names ) {
		if ( isset( $names[ $source ] ) ) {
			return (string) $names[ $source ];
		}
		$known = array(
			'searchpilot' => 'SearchPilot',
			'yoast'       => 'Yoast SEO',
			'rank_math'   => 'Rank Math SEO',
			'aioseo'      => 'All in One SEO',
			'saswp'       => 'Schema & Structured Data for WP',
			'schema_pro'  => 'Schema Pro',
			'slim_seo'    => 'Slim SEO',
			'wpsso'       => 'WPSSO',
		);
		return $known[ $source ] ?? __( 'the theme or another plugin', 'searchpilot-pro' );
	}

	/**
	 * Stores findings for one page and resolves what is fixed.
	 *
	 * @param array  $findings    Type => data.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $row_id      URL registry row id.
	 * @param string $url         URL.
	 */
	public function store( array $findings, $object_type, $object_id, $row_id, $url ) {
		if ( '' === $object_type || ! $object_id ) {
			$object_type = 'url';
			$object_id   = (int) $row_id;
		}
		$title = '';
		if ( 'post' === $object_type && $object_id ) {
			$title = (string) get_the_title( $object_id );
		}
		$keep = array();
		foreach ( $findings as $type => $data ) {
			$keep[] = $this->issues->upsert(
				$type,
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'url'         => $url,
					'title'       => '' !== $title ? $title : $url,
					'data'        => $data,
				)
			);
		}
		if ( $object_id ) {
			$this->issues->resolve_stale_for_object( $object_type, $object_id, self::PAGE_TYPES, $keep );
			return;
		}
		foreach ( array_diff( self::PAGE_TYPES, array_keys( $findings ) ) as $type ) {
			$this->issues->resolve( $type, $object_type, 0, $url );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Daily checks
	 * --------------------------------------------------------------------- */

	/**
	 * Daily: organization logo and a validation pass over recent posts.
	 */
	public function daily() {
		Lock::run(
			'schema_daily',
			600,
			function () {
				$this->check_logo();
				$this->check_recent_posts( 15 );
			}
		);
	}

	/**
	 * Raises or resolves org_logo_missing.
	 *
	 * @return bool Whether the logo is missing.
	 */
	public function check_logo() {
		$missing = $this->conflicts->owns( 'schema' )
			&& 'organization' === $this->settings->get( 'schema.entity', 'organization' )
			&& ! $this->builder->logo_image();
		if ( $missing ) {
			$this->issues->upsert(
				'org_logo_missing',
				array(
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => (string) get_bloginfo( 'name' ),
				)
			);
		} else {
			$this->issues->resolve( 'org_logo_missing', 'site', 0 );
		}
		return $missing;
	}

	/**
	 * Validates the graphs of recently changed posts.
	 *
	 * @param int $limit Posts.
	 * @return int Posts with errors.
	 */
	public function check_recent_posts( $limit = 15 ) {
		if ( ! $this->conflicts->owns( 'schema' ) ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'        => $this->types->indexable_post_types(),
				'post_status'      => 'publish',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'numberposts'      => (int) $limit,
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$bad   = 0;
		foreach ( $posts as $post ) {
			$bad += $this->check_post( $post ) ? 0 : 1;
		}
		return $bad;
	}

	/**
	 * Validates one post's graph and records the result.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool Whether the graph is valid.
	 */
	public function check_post( \WP_Post $post ) {
		$result = $this->validator->validate( $this->builder->for_post( $post ) );
		$url    = (string) get_permalink( $post );
		if ( $result['errors'] ) {
			$this->issues->upsert(
				'schema_missing_required',
				array(
					'object_type' => 'post',
					'object_id'   => (int) $post->ID,
					'url'         => $url,
					'title'       => (string) get_the_title( $post ),
					'data'        => array(
						'errors' => array_slice( $result['errors'], 0, 20 ),
						'count'  => count( $result['errors'] ),
					),
				)
			);
			return false;
		}
		$this->issues->resolve( 'schema_missing_required', 'post', (int) $post->ID, $url );
		return true;
	}
}

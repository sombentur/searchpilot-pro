<?php
/**
 * Content opportunity engine.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored Search Console (or Bing) snapshots into issues of category
 * `opportunity`, each with the evidence and a list of recommended actions.
 *
 * Detection rules are pure static functions over row arrays (unit tested);
 * thresholds are relative to the site's own size (percentiles of its page
 * impressions and clicks, never below `analytics.min_impressions`).
 *
 * Nothing is ever changed automatically: opportunities have no fixers.
 * Titles and descriptions are only written when an editor submits a value
 * through the Opportunities screen (logged and undoable).
 */
class Opportunities {

	const TYPES = array( 'low_ctr', 'striking_distance', 'page_two_rankings', 'declining_page', 'growing_page', 'keyword_cannibalization', 'losing_clicks', 'query_without_landing_page', 'content_refresh', 'fix_indexing', 'improve_structured_data' );

	const ACTIONS = array( 'update_title', 'improve_meta_description', 'refresh_content', 'add_internal_links', 'differentiate_pages', 'merge_articles', 'create_supporting_article', 'fix_indexing', 'improve_structured_data' );

	const MAX_PER_TYPE = 100;
	const MAX_QUERIES  = 50;

	const ARTICLE_TYPES = array( 'Article', 'NewsArticle', 'BlogPosting', 'ReportageNews', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'BackgroundNewsArticle', 'ReviewNewsArticle', 'TechArticle', 'ScholarlyArticle', 'LiveBlogPosting', 'SatiricalArticle', 'Report' );

	const NOT_INDEXED = array( 'not_indexed', 'crawled_not_indexed', 'discovered_not_indexed', 'excluded_noindex', 'blocked_robots', 'duplicate_canonical', 'alternate_canonical', 'redirect', 'not_found', 'soft_404', 'server_error', 'unknown_to_google', 'removed' );

	const CRAWL_META = '_searchpilot_crawl_schema';

	/**
	 * Issue repository.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Performance data.
	 *
	 * @var PerformanceRepository
	 */
	private $repo;

	/**
	 * URL resolver.
	 *
	 * @var UrlResolver
	 */
	private $resolver;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param IssueRepository       $issues   Issues.
	 * @param PerformanceRepository $repo     Performance data.
	 * @param UrlResolver           $resolver Resolver.
	 * @param ContentTypes          $types    Content types.
	 * @param MetaStore             $meta     Meta store.
	 * @param Settings              $settings Settings.
	 * @param State                 $state    State.
	 * @param Logger                $logger   Logger.
	 */
	public function __construct( IssueRepository $issues, PerformanceRepository $repo, UrlResolver $resolver, ContentTypes $types, MetaStore $meta, Settings $settings, State $state, Logger $logger ) {
		$this->issues   = $issues;
		$this->repo     = $repo;
		$this->resolver = $resolver;
		$this->types    = $types;
		$this->meta     = $meta;
		$this->settings = $settings;
		$this->state    = $state;
		$this->logger   = $logger;
	}

	/* --------------------------------------------------------------------
	 * Labels & issue types
	 * ------------------------------------------------------------------ */

	/**
	 * Action code => label.
	 *
	 * @return array<string,string>
	 */
	public static function action_labels() {
		return array(
			'update_title'              => __( 'Update Title', 'searchpilot-pro' ),
			'improve_meta_description'  => __( 'Improve Meta Description', 'searchpilot-pro' ),
			'refresh_content'           => __( 'Refresh Content', 'searchpilot-pro' ),
			'add_internal_links'        => __( 'Add Internal Links', 'searchpilot-pro' ),
			'differentiate_pages'       => __( 'Differentiate or Link to the Latest', 'searchpilot-pro' ),
			'merge_articles'            => __( 'Merge Competing Articles', 'searchpilot-pro' ),
			'create_supporting_article' => __( 'Create Supporting Article', 'searchpilot-pro' ),
			'fix_indexing'              => __( 'Fix Indexing', 'searchpilot-pro' ),
			'improve_structured_data'   => __( 'Improve Structured Data', 'searchpilot-pro' ),
		);
	}

	/**
	 * Type => label.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'low_ctr'                    => __( 'Low click-through rate for its position', 'searchpilot-pro' ),
			'striking_distance'          => __( 'Close to the top 3', 'searchpilot-pro' ),
			'page_two_rankings'          => __( 'Ranking on page two', 'searchpilot-pro' ),
			'declining_page'             => __( 'Declining page', 'searchpilot-pro' ),
			'growing_page'               => __( 'Growing page', 'searchpilot-pro' ),
			'keyword_cannibalization'    => __( 'Pages competing for the same query', 'searchpilot-pro' ),
			'losing_clicks'              => __( 'Losing clicks at a stable position', 'searchpilot-pro' ),
			'query_without_landing_page' => __( 'Search demand without a matching page', 'searchpilot-pro' ),
			'content_refresh'            => __( 'Older content losing visibility', 'searchpilot-pro' ),
			'fix_indexing'               => __( 'Lost search visibility — indexing problem', 'searchpilot-pro' ),
			'improve_structured_data'    => __( 'Popular article without valid Article schema', 'searchpilot-pro' ),
		);
	}

	/**
	 * Registers the opportunity issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_types( IssueRegistry $registry ) {
		$labels  = self::type_labels();
		$primary = array(
			'low_ctr'                    => 'update_title',
			'striking_distance'          => 'refresh_content',
			'page_two_rankings'          => 'refresh_content',
			'declining_page'             => 'refresh_content',
			'growing_page'               => 'add_internal_links',
			'keyword_cannibalization'    => 'differentiate_pages',
			'losing_clicks'              => 'update_title',
			'query_without_landing_page' => 'create_supporting_article',
			'content_refresh'            => 'refresh_content',
			'fix_indexing'               => 'fix_indexing',
			'improve_structured_data'    => 'improve_structured_data',
		);
		foreach ( self::TYPES as $type ) {
			$registry->register(
				$type,
				array(
					'label'    => $labels[ $type ],
					'category' => 'opportunity',
					'severity' => 'opportunity',
					'what'     => function ( $issue ) use ( $type ) {
						return self::what_text( $type, (array) ( $issue['data'] ?? array() ) );
					},
					'why'      => function ( $issue ) use ( $type ) {
						return self::why_text( $type, (array) ( $issue['data'] ?? array() ) );
					},
					'fix'      => function ( $issue ) use ( $type ) {
						return self::fix_text( $type, (array) ( $issue['data'] ?? array() ) );
					},
					'action'   => $primary[ $type ],
					'task'     => \SearchPilot\Core\AutomationPolicy::TASK_CONTENT,
				)
			);
		}
	}

	/* --------------------------------------------------------------------
	 * Run
	 * ------------------------------------------------------------------ */

	/**
	 * Re-evaluates every rule against the stored snapshots.
	 *
	 * @return array{engine:string,counts:array,skipped?:bool}
	 */
	public function run() {
		$engine = $this->pick_engine();
		if ( '' === $engine ) {
			return array(
				'engine'  => '',
				'counts'  => array(),
				'skipped' => true,
			);
		}
		$since    = current_time( 'mysql', true );
		$min_impr = max( 1, (int) $this->settings->get( 'analytics.min_impressions', 100 ) );
		$pages28  = $this->repo->all_rows( $engine, '28d', 'page', 5000 );
		$pages3m  = $this->repo->all_rows( $engine, '3m', 'page', 5000 );
		$queries  = $this->repo->all_rows( $engine, '28d', 'query', 5000 );
		$qp_range = '28d';
		$qp       = 'google' === $engine ? $this->repo->all_rows( 'google', '28d', 'query_page', 10000 ) : array();
		if ( ! $qp && 'google' === $engine ) {
			$qp_range = '3m';
			$qp       = $this->repo->all_rows( 'google', '3m', 'query_page', 10000 );
		}
		$model      = CtrModel::from_rows( $queries );
		$thresholds = self::thresholds( $pages28, $min_impr );
		$snapshot   = (array) $this->repo->snapshot( $engine, '28d' );

		// Context for pages that can matter (volume floor keeps this bounded on big sites).
		$floor = max( 1, (int) floor( $min_impr / 2 ) );
		$urls  = array();
		foreach ( array_merge( $pages28, $pages3m ) as $row ) {
			if ( max( $row['impressions'], $row['prev_impressions'] ) >= $floor || max( $row['clicks'], $row['prev_clicks'] ) >= 10 ) {
				$urls[ $row['page'] ] = true;
			}
		}
		foreach ( $qp as $row ) {
			$urls[ $row['page'] ] = true;
		}
		$urls = array_slice( array_keys( $urls ), 0, 4000 );
		$ctx  = $this->contexts( $urls );

		// Top query per page (from query+page rows).
		$top = array();
		foreach ( $qp as $row ) {
			$page = $row['page'];
			if ( ! isset( $top[ $page ] ) || $row['clicks'] > $top[ $page ]['clicks'] || ( $row['clicks'] === $top[ $page ]['clicks'] && $row['impressions'] > $top[ $page ]['impressions'] ) ) {
				$top[ $page ] = array(
					'query'       => $row['query'],
					'clicks'      => $row['clicks'],
					'impressions' => $row['impressions'],
					'position'    => round( $row['position'], 1 ),
				);
			}
		}
		foreach ( $ctx as $url => $c ) {
			$ctx[ $url ]['top_query'] = $top[ $url ] ?? null;
		}
		$pages28 = self::attach_context( $pages28, $ctx );
		$pages3m = self::attach_context( $pages3m, $ctx );

		// Change-based rules need a previous period the stored history fully covers
		// (a new property, or Search Console's 16-month limit, would fake growth/declines).
		$prev28   = $this->repo->prev_complete( $engine, '28d' );
		$prev3m   = $this->repo->prev_complete( $engine, '3m' );
		$fix      = $prev28 ? self::rule_fix_indexing( $pages28, $thresholds ) : array();
		$exclude  = array_fill_keys( array_column( $fix, 'page' ), true );
		$striking = self::rule_striking_distance( $pages28, $thresholds, $model );
		$changes  = $prev28 ? self::rule_declining_and_losing( $pages28, $thresholds, $exclude ) : array(
			'declining_page' => array(),
			'losing_clicks'  => array(),
		);

		$findings = array_merge(
			$fix,
			self::rule_low_ctr( $pages28, $model, $thresholds ),
			$striking,
			self::rule_page_two( $pages28, $thresholds, array_fill_keys( array_column( $striking, 'page' ), true ) ),
			$changes['declining_page'],
			$changes['losing_clicks'],
			$prev28 ? self::rule_growing( $pages28, $thresholds ) : array(),
			self::rule_structured_data( $pages28, $thresholds )
		);
		$ran = array( 'low_ctr', 'striking_distance', 'page_two_rankings', 'improve_structured_data' );
		if ( $prev28 ) {
			array_push( $ran, 'fix_indexing', 'declining_page', 'losing_clicks', 'growing_page' );
		}
		if ( $pages3m && $prev3m ) {
			$findings = array_merge( $findings, self::rule_content_refresh( $pages3m, $thresholds, time() ) );
			$ran[]    = 'content_refresh';
		}
		if ( $qp ) {
			$findings = array_merge( $findings, self::rule_cannibalization( $qp, $thresholds, $ctx, Url::normalize( home_url( '/' ) ) ) );
			$ran[]    = 'keyword_cannibalization';
			// Landing-page check needs titles/H1s of the best-ranking pages only.
			$best = self::best_pages( $qp );
			foreach ( $best as $query => $row ) {
				if ( isset( $ctx[ $row['page'] ] ) && ! empty( $ctx[ $row['page'] ]['post_id'] ) && ! isset( $ctx[ $row['page'] ]['title'] ) && $row['position'] > 20 ) {
					$post = get_post( (int) $ctx[ $row['page'] ]['post_id'] );
					if ( $post ) {
						$ctx[ $row['page'] ]['title'] = $this->meta->effective_title( $post );
					}
				}
			}
			$findings = array_merge( $findings, self::rule_query_without_landing_page( $queries, $qp, $ctx, $thresholds ) );
			$ran[]    = 'query_without_landing_page';
		}

		$findings = self::cap( $findings );
		$applied  = $this->applied_map();
		$counts   = array_fill_keys( self::TYPES, 0 );
		$base     = array(
			'engine'    => $engine,
			'range'     => '28d',
			'from'      => (string) ( $snapshot['from'] ?? '' ),
			'to'        => (string) ( $snapshot['to'] ?? '' ),
			'prev_from' => (string) ( $snapshot['prev_from'] ?? '' ),
			'prev_to'   => (string) ( $snapshot['prev_to'] ?? '' ),
		);
		foreach ( $findings as $finding ) {
			$args = $this->issue_args( $finding, $base, $ctx, $qp_range );
			$key  = IssueRepository::key( $finding['type'] . ( isset( $args['key_suffix'] ) ? '#' . $args['key_suffix'] : '' ), $args['object_type'], $args['object_id'], $args['url'] );
			if ( isset( $applied[ $key ] ) ) {
				$args['data'] = array_merge( $args['data'], $applied[ $key ] );
				// The change is reflected once the whole comparison window starts after it.
				$args['data']['change_measured'] = '' !== $base['from'] && substr( (string) $applied[ $key ]['applied_at'], 0, 10 ) < $base['from'];
			}
			$this->issues->upsert( $finding['type'], $args );
			++$counts[ $finding['type'] ];
		}
		foreach ( array_unique( $ran ) as $type ) {
			$this->issues->resolve_unseen( $type, $since );
		}
		$result = array(
			'engine'     => $engine,
			'counts'     => $counts,
			'total'      => array_sum( $counts ),
			'at'         => gmdate( 'c' ),
			'thresholds' => $thresholds,
			'ctr_curve'  => $model->to_array(),
		);
		$this->state->set( 'opportunities_last_run', $result );
		$this->logger->info( 'analytics', sprintf( 'Opportunity scan (%1$s): %2$d open opportunities.', $engine, $result['total'] ), array( 'counts' => array_filter( $counts ) ) );
		return $result;
	}

	/**
	 * Engine with snapshot data (Google preferred).
	 *
	 * @return string
	 */
	private function pick_engine() {
		foreach ( array( 'google', 'bing' ) as $engine ) {
			$snapshot = $this->repo->snapshot( $engine, '28d' );
			if ( $snapshot && ! empty( $snapshot['counts']['page'] ) ) {
				return $engine;
			}
		}
		return '';
	}

	/**
	 * Builds page context: post, indexability, registry state, schema data.
	 *
	 * @param string[] $urls Normalized URLs.
	 * @return array<string,array>
	 */
	private function contexts( array $urls ) {
		global $wpdb;
		$out = array();
		if ( ! $urls ) {
			return $out;
		}
		$map = $this->resolver->resolve_many( $urls, 1500 );
		$this->resolver->flush();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $map ) ) ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}

		// Registry rows by URL hash.
		$registry = array();
		$table    = Tables::urls();
		$hashes   = array();
		foreach ( $urls as $url ) {
			$hashes[ sha1( Url::normalize( $url ) ) ] = $url;
		}
		foreach ( array_chunk( array_keys( $hashes ), 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, index_status, coverage_state, verdict, indexable, indexability_reason, http_status, errors FROM {$table} WHERE url_hash IN ({$holders})", $chunk ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$registry[ $hashes[ $row['url_hash'] ] ] = $row;
			}
		}

		// Open structured-data issues per post.
		$schema_issues = array();
		if ( $ids ) {
			$issues = Tables::issues();
			foreach ( array_chunk( $ids, 300 ) as $chunk ) {
				$holders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, COUNT(*) AS n FROM {$issues} WHERE object_type = 'post' AND object_id IN ({$holders}) AND status IN ('open','pending_approval') AND ( category = 'schema' OR type LIKE 'schema\\_%' ) GROUP BY object_id", $chunk ) );
				foreach ( (array) $rows as $row ) {
					$schema_issues[ (int) $row->object_id ] = (int) $row->n;
				}
			}
		}

		$home = Url::normalize( home_url( '/' ) );
		foreach ( $urls as $url ) {
			$id   = (int) ( $map[ Url::normalize( $url ) ] ?? 0 );
			$post = $id ? get_post( $id ) : null;
			$reg  = $registry[ $url ] ?? null;
			$c    = array(
				'internal'      => Url::is_internal( $url ),
				'indexable'     => true,
				'is_home'       => Url::normalize( $url ) === $home,
				'post_id'       => $post ? (int) $post->ID : 0,
				'post_type'     => $post ? $post->post_type : '',
				'h1'            => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '',
				'modified'      => $post ? (string) $post->post_modified_gmt : '',
				'published'     => $post ? substr( (string) $post->post_date, 0, 10 ) : '',
				'is_news'       => $post ? $this->is_news_post( $post ) : false,
				'index_status'  => $reg ? (string) $reg['index_status'] : '',
				'coverage'      => $reg ? (string) $reg['coverage_state'] : '',
				'verdict'       => $reg ? (string) $reg['verdict'] : '',
				'http_status'   => $reg ? (int) $reg['http_status'] : 0,
				'has_errors'    => $reg && ! in_array( (string) $reg['errors'], array( '', '[]' ), true ) && null !== $reg['errors'],
				'is_article'    => false,
				'schema_types'  => null,
				'schema_issues' => $post ? (int) ( $schema_issues[ $post->ID ] ?? 0 ) : 0,
			);
			if ( $post ) {
				$indexability    = $this->types->post_indexability( $post );
				$c['indexable']  = ! empty( $indexability['indexable'] );
				$c['reason']     = (string) ( $indexability['reason'] ?? '' );
				$c['is_article'] = in_array( (string) $this->types->schema_type( $post->post_type ), self::ARTICLE_TYPES, true );
				$crawl           = get_post_meta( $post->ID, self::CRAWL_META, true );
				if ( is_array( $crawl ) && isset( $crawl['types'] ) ) {
					$c['schema_types'] = array_values( array_map( 'strval', (array) $crawl['types'] ) );
				}
			} elseif ( $reg ) {
				$c['indexable'] = ! empty( $reg['indexable'] );
				$c['reason']    = (string) $reg['indexability_reason'];
			}
			$out[ $url ] = $c;
		}
		return $out;
	}

	/**
	 * Whether a post is dated news (NewsArticle schema in SearchPilot or the
	 * owning SEO plugin, or a news-sitemap post type/category).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function is_news_post( \WP_Post $post ) {
		if ( 'NewsArticle' === (string) $this->types->schema_type( $post->post_type ) ) {
			return true;
		}
		$cats = array_filter( array_map( 'intval', array_merge( (array) $this->settings->get( 'schema.news_categories', array() ), (array) $this->settings->get( 'sitemap.news_categories', array() ) ) ) );
		if ( $cats && array_intersect( $cats, array_map( 'intval', wp_get_post_categories( $post->ID ) ) ) ) {
			return true;
		}
		if ( ! $cats && $this->settings->get( 'sitemap.news', false ) && in_array( $post->post_type, (array) $this->settings->get( 'sitemap.news_post_types', array( 'post' ) ), true ) ) {
			return true;
		}
		$rank_math = get_option( 'rank-math-options-titles' );
		if ( is_array( $rank_math ) && 'NewsArticle' === (string) ( $rank_math[ 'pt_' . $post->post_type . '_default_article_type' ] ?? '' ) ) {
			return true;
		}
		$yoast = get_option( 'wpseo_titles' );
		return is_array( $yoast ) && 'NewsArticle' === (string) ( $yoast[ 'schema-article-type-' . $post->post_type ] ?? '' );
	}

	/**
	 * Adds `ctx` to rows.
	 *
	 * @param array $rows Rows.
	 * @param array $ctx  URL => context.
	 * @return array
	 */
	private static function attach_context( array $rows, array $ctx ) {
		foreach ( $rows as $i => $row ) {
			if ( isset( $ctx[ $row['page'] ] ) ) {
				$rows[ $i ]['ctx'] = $ctx[ $row['page'] ];
			}
		}
		return $rows;
	}

	/**
	 * Existing applied markers (so a re-scan keeps "title updated on …").
	 *
	 * @return array<string,array> issue_key => applied fields.
	 */
	private function applied_map() {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT issue_key, data FROM {$table} WHERE category = 'opportunity' AND data LIKE '%applied_at%'" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row->data, true );
			if ( is_array( $data ) && ! empty( $data['applied_at'] ) ) {
				$out[ (string) $row->issue_key ] = array_intersect_key( $data, array_flip( array( 'applied_at', 'applied_field', 'applied_activity', 'applied_by', 'applied' ) ) );
			}
		}
		return $out;
	}

	/**
	 * Issue arguments for a finding.
	 *
	 * @param array  $finding  Finding.
	 * @param array  $base     Snapshot fields.
	 * @param array  $ctx      Page contexts.
	 * @param string $qp_range Range of query+page rows.
	 * @return array
	 */
	private function issue_args( array $finding, array $base, array $ctx, $qp_range ) {
		$actions = array_values( array_unique( $finding['actions'] ) );
		$data    = array_merge(
			$base,
			$finding['data'],
			array(
				'actions'     => $actions,
				'actions_key' => '|' . implode( '|', $actions ) . '|',
			)
		);
		if ( 'query' === ( $finding['object'] ?? 'page' ) ) {
			$data['query_range'] = $qp_range;
			return array(
				'object_type' => 'query',
				'object_id'   => 0,
				'url'         => '',
				'title'       => $finding['query'],
				'severity'    => $finding['severity'],
				'key_suffix'  => substr( sha1( mb_strtolower( $finding['query'], 'UTF-8' ) ), 0, 16 ),
				'data'        => $data,
			);
		}
		$page = $finding['page'];
		$c    = $ctx[ $page ] ?? array();
		$id   = (int) ( $c['post_id'] ?? 0 );

		$data['page']         = $page;
		$data['display_page'] = Url::display( $page );
		if ( $id ) {
			$data['post_id']   = $id;
			$data['post_type'] = (string) ( $c['post_type'] ?? '' );
		}
		return array(
			'object_type' => $id ? 'post' : 'url',
			'object_id'   => $id,
			'url'         => $page,
			'title'       => $id ? (string) ( $c['h1'] ?? '' ) : Url::display( $page ),
			'severity'    => $finding['severity'],
			'data'        => $data,
		);
	}

	/* --------------------------------------------------------------------
	 * Pure rules
	 * ------------------------------------------------------------------ */

	/**
	 * Site-relative thresholds.
	 *
	 * @param array $pages           Page rows (current period impressions, previous clicks).
	 * @param int   $min_impressions Setting analytics.min_impressions.
	 * @return array{min_impressions:int,high_impressions:int,meaningful_impressions:int,min_clicks:int,pages:int}
	 */
	public static function thresholds( array $pages, $min_impressions ) {
		$min_impressions = max( 1, (int) $min_impressions );
		$impressions     = array();
		$prev_clicks     = array();
		foreach ( $pages as $row ) {
			if ( (int) $row['impressions'] > 0 ) {
				$impressions[] = (int) $row['impressions'];
			}
			if ( (int) $row['prev_clicks'] > 0 ) {
				$prev_clicks[] = (int) $row['prev_clicks'];
			}
		}
		return array(
			'min_impressions'        => $min_impressions,
			'high_impressions'       => max( $min_impressions, self::percentile( $impressions, 0.8 ) ),
			'meaningful_impressions' => max( $min_impressions, self::percentile( $impressions, 0.5 ) ),
			'min_clicks'             => max( 10, min( 100, self::percentile( $prev_clicks, 0.5 ) ) ),
			'pages'                  => count( $impressions ),
		);
	}

	/**
	 * Nearest-rank percentile.
	 *
	 * @param int[] $values Values.
	 * @param float $p      0..1.
	 * @return int
	 */
	public static function percentile( array $values, $p ) {
		if ( ! $values ) {
			return 0;
		}
		sort( $values );
		$rank = (int) ceil( $p * count( $values ) ) - 1;
		return (int) $values[ max( 0, min( count( $values ) - 1, $rank ) ) ];
	}

	/**
	 * Low CTR for the position: top-20% impressions and CTR under half the expectation.
	 *
	 * @param array    $pages Page rows (with optional ctx).
	 * @param CtrModel $model CTR model.
	 * @param array    $t     Thresholds.
	 * @return array[] Findings.
	 */
	public static function rule_low_ctr( array $pages, CtrModel $model, array $t ) {
		$out = array();
		foreach ( $pages as $row ) {
			if ( ! self::eligible( $row ) || $row['impressions'] < $t['high_impressions'] || $row['position'] <= 0 || $row['position'] > 20 ) {
				continue;
			}
			$ctr      = $row['impressions'] ? $row['clicks'] / $row['impressions'] : 0.0;
			$expected = $model->expected( $row['position'] );
			$missed   = $model->missed_clicks( $row['clicks'], $row['impressions'], $row['position'] );
			if ( $ctr >= $expected * 0.5 || $missed < 5 ) {
				continue;
			}
			$out[] = self::finding(
				'low_ctr',
				$row,
				array( 'update_title', 'improve_meta_description' ),
				array(
					'expected_ctr'  => round( $expected, 4 ),
					'missed_clicks' => $missed,
					'impact'        => $missed,
				)
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Striking distance: average position 4–15 with meaningful impressions.
	 *
	 * @param array         $pages Page rows.
	 * @param array         $t     Thresholds.
	 * @param CtrModel|null $model CTR model (estimates the clicks at position 3).
	 * @return array[]
	 */
	public static function rule_striking_distance( array $pages, array $t, $model = null ) {
		$model = $model instanceof CtrModel ? $model : new CtrModel();
		$out   = array();
		foreach ( $pages as $row ) {
			if ( ! self::eligible( $row ) || $row['position'] < 4 || $row['position'] > 15 || $row['impressions'] < $t['meaningful_impressions'] ) {
				continue;
			}
			$potential = (int) max( 0, round( $model->expected( 3 ) * $row['impressions'] - $row['clicks'] ) );
			$out[]     = self::finding(
				'striking_distance',
				$row,
				array( 'refresh_content', 'add_internal_links', 'update_title' ),
				array(
					'potential_clicks' => $potential,
					'impact'           => $potential,
				)
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Page two/three: average position 11–30 (pages already in striking distance are left out).
	 *
	 * @param array $pages   Page rows.
	 * @param array $t       Thresholds.
	 * @param array $exclude URL => true.
	 * @return array[]
	 */
	public static function rule_page_two( array $pages, array $t, array $exclude = array() ) {
		$out = array();
		foreach ( $pages as $row ) {
			if ( ! self::eligible( $row ) || isset( $exclude[ $row['page'] ] ) || $row['position'] < 10.5 || $row['position'] > 30 || $row['impressions'] < $t['min_impressions'] ) {
				continue;
			}
			$out[] = self::finding(
				'page_two_rankings',
				$row,
				array( 'refresh_content', 'create_supporting_article', 'add_internal_links' ),
				array( 'impact' => (int) round( $row['impressions'] * 0.03 ) )
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Declines. A page whose impressions held while clicks fell is "losing clicks"
	 * (a SERP/CTR problem, or a small ranking slip); a page whose impressions
	 * fell too is "declining" (visibility loss).
	 *
	 * @param array $pages   Page rows.
	 * @param array $t       Thresholds.
	 * @param array $exclude URL => true (already reported as indexing problems).
	 * @return array{declining_page:array,losing_clicks:array}
	 */
	public static function rule_declining_and_losing( array $pages, array $t, array $exclude = array() ) {
		$declining = array();
		$losing    = array();
		foreach ( $pages as $row ) {
			if ( ! self::eligible( $row ) || isset( $exclude[ $row['page'] ] ) || $row['prev_clicks'] < $t['min_clicks'] || $row['clicks'] >= $row['prev_clicks'] ) {
				continue;
			}
			$lost       = $row['prev_clicks'] - $row['clicks'];
			$drop       = $lost / $row['prev_clicks'];
			$impr_ratio = $row['prev_impressions'] ? $row['impressions'] / $row['prev_impressions'] : 0.0;
			$pos_change = $row['position'] > 0 && $row['prev_position'] > 0 ? $row['position'] - $row['prev_position'] : null;
			$severity   = self::decline_severity( $lost, $drop, $t );
			$extra      = array(
				'lost_clicks' => $lost,
				'drop'        => round( $drop, 4 ),
				'impact'      => $lost,
			);
			if ( $impr_ratio >= 0.8 && $drop >= 0.2 ) {
				$ranking          = null !== $pos_change && $pos_change > 1;
				$extra['variant'] = $ranking ? 'ranking' : 'ctr';
				$losing[]         = self::finding( 'losing_clicks', $row, $ranking ? array( 'refresh_content' ) : array( 'update_title', 'improve_meta_description' ), $extra, $severity );
			} elseif ( $impr_ratio < 0.8 && $drop >= 0.3 ) {
				$ctx     = (array) ( $row['ctx'] ?? array() );
				$actions = array( 'refresh_content' );
				if ( self::registry_problem( $ctx ) ) {
					$actions[]                = 'fix_indexing';
					$extra['index_status']    = (string) ( $ctx['index_status'] ?? '' );
					$extra['indexing_signal'] = true;
				}
				$declining[] = self::finding( 'declining_page', $row, $actions, $extra, $severity );
			}
		}
		return array(
			'declining_page' => self::sort_by_impact( $declining ),
			'losing_clicks'  => self::sort_by_impact( $losing ),
		);
	}

	/**
	 * Growing pages: clicks up 30% or more (or a new page already earning clicks).
	 *
	 * @param array $pages Page rows.
	 * @param array $t     Thresholds.
	 * @return array[]
	 */
	public static function rule_growing( array $pages, array $t ) {
		$out = array();
		foreach ( $pages as $row ) {
			if ( ! self::eligible( $row ) || $row['clicks'] < $t['min_clicks'] ) {
				continue;
			}
			if ( $row['prev_clicks'] > 0 ) {
				$growth = ( $row['clicks'] - $row['prev_clicks'] ) / $row['prev_clicks'];
				if ( $growth < 0.3 ) {
					continue;
				}
			} else {
				$growth = null;
			}
			$out[] = self::finding(
				'growing_page',
				$row,
				array( 'add_internal_links', 'refresh_content' ),
				array(
					'growth'        => null === $growth ? null : round( $growth, 4 ),
					'gained_clicks' => $row['clicks'] - $row['prev_clicks'],
					'impact'        => $row['clicks'] - $row['prev_clicks'],
				)
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Keyword cannibalization: ≥ 2 of the site's pages each get ≥ 10% of a
	 * query's impressions and rank within the top 30.
	 *
	 * Dated news is expected to compete with itself (a new "gold price today"
	 * report every day), so a pair of news articles published on different
	 * days never counts. The primary action is to differentiate the pages or
	 * link the older ones to the latest; merging is recommended only for
	 * evergreen pages that share most of their queries.
	 *
	 * @param array  $query_pages Query+page rows.
	 * @param array  $t           Thresholds.
	 * @param array  $ctx         URL => context (post_id, h1, is_news, published, post_type).
	 * @param string $home        Normalized home URL (never counted as a competitor).
	 * @return array[]
	 */
	public static function rule_cannibalization( array $query_pages, array $t, array $ctx = array(), $home = '' ) {
		$groups  = array();
		$queries = array();
		foreach ( $query_pages as $row ) {
			if ( $row['impressions'] <= 0 ) {
				continue;
			}
			$key                      = mb_strtolower( $row['query'], 'UTF-8' );
			$groups[ $key ]['query']  = $row['query'];
			$groups[ $key ]['rows'][] = $row;
			// Queries per competitor (post or URL) for the overlap test.
			$id  = (int) ( $ctx[ $row['page'] ]['post_id'] ?? ( $row['object_id'] ?? 0 ) );
			$who = $id ? 'p' . $id : $row['page'];

			$queries[ $who ][ $key ] = true;
		}
		$out = array();
		foreach ( $groups as $key => $group ) {
			$total = array_sum( array_column( $group['rows'], 'impressions' ) );
			if ( $total < $t['min_impressions'] ) {
				continue;
			}
			$temporal    = self::is_temporal_query( $group['query'] );
			$competitors = array();
			foreach ( $group['rows'] as $row ) {
				$c = array_merge(
					array(
						'internal'  => true,
						'indexable' => true,
						'post_id'   => (int) ( $row['object_id'] ?? 0 ),
					),
					(array) ( $ctx[ $row['page'] ] ?? array() )
				);
				if ( empty( $c['internal'] ) || empty( $c['indexable'] ) || ( '' !== $home && $row['page'] === $home ) || ! empty( $c['is_home'] ) ) {
					continue;
				}
				if ( $row['position'] <= 0 || $row['position'] > 30 || $row['impressions'] / $total < 0.1 ) {
					continue;
				}
				// Several URLs of one post (AMP, pagination) are one competitor.
				$id = (int) ( $c['post_id'] ?? 0 );
				$k  = $id ? 'p' . $id : $row['page'];
				if ( isset( $competitors[ $k ] ) ) {
					$competitors[ $k ]['clicks']      += $row['clicks'];
					$competitors[ $k ]['impressions'] += $row['impressions'];
					$competitors[ $k ]['position']     = min( $competitors[ $k ]['position'], round( $row['position'], 1 ) );
					continue;
				}
				$competitors[ $k ] = array(
					'key'         => $k,
					'page'        => $row['page'],
					'post_id'     => $id,
					'title'       => (string) ( $c['h1'] ?? '' ),
					'clicks'      => $row['clicks'],
					'impressions' => $row['impressions'],
					'position'    => round( $row['position'], 1 ),
					'published'   => (string) ( $c['published'] ?? '' ),
					'news'        => ! empty( $c['is_news'] ) || ( $temporal && 'post' === ( $c['post_type'] ?? '' ) ),
				);
			}
			if ( count( $competitors ) < 2 ) {
				continue;
			}
			// Keep pairs that really compete: not two news articles from different days.
			$list    = array_values( $competitors );
			$keep    = array();
			$merge   = false;
			$overlap = 0.0;
			$n       = count( $list );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a = $list[ $i ];
					$b = $list[ $j ];
					if ( self::dated_news_pair( $a, $b ) ) {
						continue;
					}
					$keep[ $a['key'] ] = true;
					$keep[ $b['key'] ] = true;
					$qa                = (array) ( $queries[ $a['key'] ] ?? array() );
					$qb                = (array) ( $queries[ $b['key'] ] ?? array() );
					$shared            = count( array_intersect_key( $qa, $qb ) );
					$ratio             = min( count( $qa ), count( $qb ) ) ? $shared / min( count( $qa ), count( $qb ) ) : 0.0;
					$overlap           = max( $overlap, $ratio );
					if ( ! $a['news'] && ! $b['news'] && $shared >= 2 && $ratio >= 0.5 ) {
						$merge = true;
					}
				}
			}
			if ( ! $keep ) {
				continue;
			}
			$competitors = array_values( array_intersect_key( $competitors, $keep ) );
			foreach ( $competitors as $i => $comp ) {
				$competitors[ $i ]['share'] = round( $comp['impressions'] / $total, 3 );
				unset( $competitors[ $i ]['key'] );
			}
			usort(
				$competitors,
				static function ( $a, $b ) {
					return $b['impressions'] <=> $a['impressions'];
				}
			);
			$latest = null;
			foreach ( $competitors as $comp ) {
				if ( '' !== $comp['published'] && ( null === $latest || $comp['published'] > $latest['published'] ) ) {
					$latest = $comp;
				}
			}
			$clicks = array_sum( array_column( $group['rows'], 'clicks' ) );
			$out[]  = array(
				'type'     => 'keyword_cannibalization',
				'object'   => 'query',
				'query'    => $group['query'],
				'page'     => '',
				'severity' => 'opportunity',
				'actions'  => $merge ? array( 'differentiate_pages', 'merge_articles' ) : array( 'differentiate_pages' ),
				'data'     => array(
					'query'             => $group['query'],
					'impressions'       => $total,
					'clicks'            => $clicks,
					'ctr'               => $total ? round( $clicks / $total, 4 ) : 0,
					'competing_pages'   => array_values( array_slice( $competitors, 0, 6 ) ),
					'merge_recommended' => $merge,
					'query_overlap'     => round( $overlap, 2 ),
					'news'              => (bool) array_filter( array_column( $competitors, 'news' ) ),
					'latest_page'       => $latest ? $latest['page'] : '',
					'latest_post_id'    => $latest ? (int) $latest['post_id'] : 0,
					'impact'            => (int) round( $total * 0.02 ),
				),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['data']['impressions'] <=> $a['data']['impressions'];
			}
		);
		return array_slice( $out, 0, self::MAX_QUERIES );
	}

	/**
	 * Two news articles published on different days (a daily report series).
	 *
	 * @param array $a Competitor.
	 * @param array $b Competitor.
	 * @return bool
	 */
	public static function dated_news_pair( array $a, array $b ) {
		return ! empty( $a['news'] ) && ! empty( $b['news'] ) && '' !== (string) ( $a['published'] ?? '' ) && '' !== (string) ( $b['published'] ?? '' ) && $a['published'] !== $b['published'];
	}

	/**
	 * Whether a query asks for something time-bound ("gold price today",
	 * "election results live", "ಇಂದಿನ ಸುದ್ದಿ"), which daily news answers anew.
	 *
	 * @param string $query Query.
	 * @return bool
	 */
	public static function is_temporal_query( $query ) {
		$words    = Text::words( (string) $query );
		$temporal = array( 'today', 'todays', "today's", 'tonight', 'yesterday', 'tomorrow', 'live', 'latest', 'now', 'breaking', 'news', 'update', 'updates', 'current', 'week', 'weekly', 'daily', 'ಇಂದು', 'ಇಂದಿನ', 'ನಿನ್ನೆ', 'ನಾಳೆ', 'ಲೈವ್', 'ಇತ್ತೀಚಿನ', 'ಸುದ್ದಿ', 'आज', 'लाइव', 'ताजा', 'खबर' );
		foreach ( $words as $word ) {
			if ( in_array( $word, $temporal, true ) || preg_match( '/^(19|20)\d{2}$/', $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Best-ranking page per query.
	 *
	 * @param array $query_pages Query+page rows.
	 * @return array<string,array> Lower-cased query => row.
	 */
	public static function best_pages( array $query_pages ) {
		$best = array();
		foreach ( $query_pages as $row ) {
			if ( $row['impressions'] <= 0 || $row['position'] <= 0 ) {
				continue;
			}
			$key = mb_strtolower( $row['query'], 'UTF-8' );
			if ( ! isset( $best[ $key ] ) || $row['position'] < $best[ $key ]['position'] || ( $row['position'] === $best[ $key ]['position'] && $row['impressions'] > $best[ $key ]['impressions'] ) ) {
				$best[ $key ] = $row;
			}
		}
		return $best;
	}

	/**
	 * Queries with demand whose best page ranks weakly (position > 20) and does
	 * not mention the query in its title or main heading.
	 *
	 * @param array $queries     Query rows.
	 * @param array $query_pages Query+page rows.
	 * @param array $ctx         URL => context (title, h1).
	 * @param array $t           Thresholds.
	 * @return array[]
	 */
	public static function rule_query_without_landing_page( array $queries, array $query_pages, array $ctx, array $t ) {
		$best = self::best_pages( $query_pages );
		$out  = array();
		foreach ( $queries as $row ) {
			if ( $row['impressions'] < $t['meaningful_impressions'] ) {
				continue;
			}
			$key = mb_strtolower( $row['query'], 'UTF-8' );
			if ( ! isset( $best[ $key ] ) || $best[ $key ]['position'] <= 20 ) {
				continue;
			}
			$page = $best[ $key ]['page'];
			$c    = array_merge( array( 'internal' => true ), (array) ( $ctx[ $page ] ?? array() ) );
			if ( empty( $c['internal'] ) ) {
				continue;
			}
			$terms = array_unique( Text::keywords( $row['query'] ) );
			if ( ! $terms ) {
				continue;
			}
			$have    = array_flip( Text::keywords( trim( (string) ( $c['title'] ?? '' ) . ' ' . (string) ( $c['h1'] ?? '' ) ) ) );
			$missing = array_values( array_filter( $terms, static function ( $term ) use ( $have ) {
				return ! isset( $have[ $term ] );
			} ) );
			if ( ! $missing ) {
				continue;
			}
			$out[] = array(
				'type'     => 'query_without_landing_page',
				'object'   => 'query',
				'query'    => $row['query'],
				'page'     => '',
				'severity' => 'opportunity',
				'actions'  => array( 'create_supporting_article' ),
				'data'     => array(
					'query'         => $row['query'],
					'impressions'   => $row['impressions'],
					'clicks'        => $row['clicks'],
					'ctr'           => $row['impressions'] ? round( $row['clicks'] / $row['impressions'], 4 ) : 0,
					'position'      => round( $row['position'], 1 ),
					'best_page'     => $page,
					'best_title'    => (string) ( $c['h1'] ?? '' ),
					'best_post_id'  => (int) ( $c['post_id'] ?? 0 ),
					'best_position' => round( $best[ $key ]['position'], 1 ),
					'missing_terms' => $missing,
					'impact'        => (int) round( $row['impressions'] * 0.05 ),
				),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['data']['impressions'] <=> $a['data']['impressions'];
			}
		);
		return array_slice( $out, 0, self::MAX_QUERIES );
	}

	/**
	 * Posts not updated for 12 months whose impressions fell 20% or more (3-month snapshot).
	 *
	 * @param array $pages Page rows (3m).
	 * @param array $t     Thresholds.
	 * @param int   $now   Unix time.
	 * @return array[]
	 */
	public static function rule_content_refresh( array $pages, array $t, $now ) {
		$out    = array();
		$cutoff = (int) $now - 365 * 86400;
		foreach ( $pages as $row ) {
			$ctx = (array) ( $row['ctx'] ?? array() );
			if ( ! self::eligible( $row ) || empty( $ctx['post_id'] ) || empty( $ctx['modified'] ) ) {
				continue;
			}
			$modified = strtotime( $ctx['modified'] . ' UTC' );
			if ( ! $modified || $modified > $cutoff || $row['prev_impressions'] < $t['min_impressions'] || $row['impressions'] > $row['prev_impressions'] * 0.8 ) {
				continue;
			}
			$drop  = ( $row['prev_impressions'] - $row['impressions'] ) / $row['prev_impressions'];
			$out[] = self::finding(
				'content_refresh',
				$row,
				array( 'refresh_content' ),
				array(
					'range'            => '3m',
					'modified'         => gmdate( 'Y-m-d', $modified ),
					'months_since'     => (int) floor( ( (int) $now - $modified ) / ( 30 * 86400 ) ),
					'impressions_drop' => round( $drop, 4 ),
					'impact'           => (int) round( ( $row['prev_clicks'] - $row['clicks'] ) > 0 ? $row['prev_clicks'] - $row['clicks'] : $row['prev_impressions'] * 0.01 ),
				)
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Pages that had impressions before but ~0 now and that the registry reports as not indexed or erroring.
	 *
	 * @param array $pages Page rows (28d).
	 * @param array $t     Thresholds.
	 * @return array[]
	 */
	public static function rule_fix_indexing( array $pages, array $t ) {
		$out = array();
		foreach ( $pages as $row ) {
			$ctx = (array) ( $row['ctx'] ?? array() );
			if ( ! self::eligible( $row ) || $row['prev_impressions'] < $t['min_impressions'] || $row['impressions'] > $row['prev_impressions'] * 0.05 ) {
				continue;
			}
			if ( ! self::registry_problem( $ctx ) ) {
				continue;
			}
			$severity = 'opportunity';
			if ( $row['prev_clicks'] >= max( 50, 5 * $t['min_clicks'] ) ) {
				$severity = 'high';
			} elseif ( $row['prev_clicks'] >= $t['min_clicks'] ) {
				$severity = 'medium';
			}
			$out[] = self::finding(
				'fix_indexing',
				$row,
				array( 'fix_indexing' ),
				array(
					'index_status' => (string) ( $ctx['index_status'] ?? '' ),
					'coverage'     => (string) ( $ctx['coverage'] ?? '' ),
					'http_status'  => (int) ( $ctx['http_status'] ?? 0 ),
					'impact'       => max( $row['prev_clicks'], (int) round( $row['prev_impressions'] * 0.02 ) ),
				),
				$severity
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * High-impression articles with open schema issues or no Article structured data in the last crawl.
	 *
	 * @param array $pages Page rows.
	 * @param array $t     Thresholds.
	 * @return array[]
	 */
	public static function rule_structured_data( array $pages, array $t ) {
		$out = array();
		foreach ( $pages as $row ) {
			$ctx = (array) ( $row['ctx'] ?? array() );
			if ( ! self::eligible( $row ) || empty( $ctx['is_article'] ) || $row['impressions'] < $t['high_impressions'] ) {
				continue;
			}
			$issues  = (int) ( $ctx['schema_issues'] ?? 0 );
			$types   = isset( $ctx['schema_types'] ) && is_array( $ctx['schema_types'] ) ? $ctx['schema_types'] : null;
			$missing = null !== $types && ! array_intersect( $types, self::ARTICLE_TYPES );
			if ( ! $issues && ! $missing ) {
				continue;
			}
			$out[] = self::finding(
				'improve_structured_data',
				$row,
				array( 'improve_structured_data' ),
				array(
					'schema_issues'   => $issues,
					'schema_types'    => null === $types ? null : array_values( array_slice( $types, 0, 10 ) ),
					'missing_article' => $missing,
					'impact'          => (int) round( $row['impressions'] * 0.01 ),
				)
			);
		}
		return self::sort_by_impact( $out );
	}

	/**
	 * Severity of a decline: large relative and absolute losses escalate.
	 *
	 * @param int   $lost Clicks lost.
	 * @param float $drop Relative drop 0..1.
	 * @param array $t    Thresholds.
	 * @return string
	 */
	public static function decline_severity( $lost, $drop, array $t ) {
		if ( $drop >= 0.7 && $lost >= max( 100, 5 * $t['min_clicks'] ) ) {
			return 'high';
		}
		if ( $drop >= 0.5 && $lost >= max( 30, 2 * $t['min_clicks'] ) ) {
			return 'medium';
		}
		return 'opportunity';
	}

	/**
	 * Whether the registry reports an indexing or HTTP problem.
	 *
	 * @param array $ctx Context.
	 * @return bool
	 */
	public static function registry_problem( array $ctx ) {
		return in_array( (string) ( $ctx['index_status'] ?? '' ), self::NOT_INDEXED, true )
			|| (int) ( $ctx['http_status'] ?? 0 ) >= 400
			|| 'FAIL' === strtoupper( (string) ( $ctx['verdict'] ?? '' ) );
	}

	/**
	 * Whether a page row is this site's and meant to be indexed.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function eligible( array $row ) {
		$ctx = (array) ( $row['ctx'] ?? array() );
		if ( isset( $ctx['internal'] ) && ! $ctx['internal'] ) {
			return false;
		}
		if ( isset( $ctx['indexable'] ) && ! $ctx['indexable'] ) {
			return false;
		}
		return '' !== (string) ( $row['page'] ?? '' );
	}

	/**
	 * Standard page finding.
	 *
	 * @param string $type     Type.
	 * @param array  $row      Row.
	 * @param array  $actions  Action codes.
	 * @param array  $extra    Extra data.
	 * @param string $severity Severity.
	 * @return array
	 */
	private static function finding( $type, array $row, array $actions, array $extra = array(), $severity = 'opportunity' ) {
		$ctx         = (array) ( $row['ctx'] ?? array() );
		$top         = isset( $ctx['top_query'] ) && is_array( $ctx['top_query'] ) ? $ctx['top_query'] : null;
		$impressions = (int) $row['impressions'];
		$prev_impr   = (int) $row['prev_impressions'];
		return array(
			'type'     => $type,
			'object'   => 'page',
			'page'     => $row['page'],
			'query'    => $top ? (string) $top['query'] : '',
			'severity' => $severity,
			'actions'  => $actions,
			'data'     => array_merge(
				array(
					'query'                 => $top ? (string) $top['query'] : '',
					'query_clicks'          => $top ? (int) $top['clicks'] : null,
					'query_impressions'     => $top ? (int) $top['impressions'] : null,
					'query_position'        => $top ? (float) $top['position'] : null,
					'clicks'                => (int) $row['clicks'],
					'impressions'           => $impressions,
					'ctr'                   => $impressions ? round( $row['clicks'] / $impressions, 4 ) : 0,
					'position'              => round( (float) $row['position'], 1 ),
					'prev_clicks'           => (int) $row['prev_clicks'],
					'prev_impressions'      => $prev_impr,
					'prev_ctr'              => $prev_impr ? round( $row['prev_clicks'] / $prev_impr, 4 ) : 0,
					'prev_position'         => round( (float) $row['prev_position'], 1 ),
					'delta_clicks'          => (int) $row['clicks'] - (int) $row['prev_clicks'],
					'delta_clicks_pct'      => $row['prev_clicks'] ? round( ( $row['clicks'] - $row['prev_clicks'] ) / $row['prev_clicks'], 4 ) : null,
					'delta_impressions'     => $impressions - $prev_impr,
					'delta_impressions_pct' => $prev_impr ? round( ( $impressions - $prev_impr ) / $prev_impr, 4 ) : null,
					'delta_position'        => $row['position'] > 0 && $row['prev_position'] > 0 ? round( $row['position'] - $row['prev_position'], 1 ) : null,
					'modified'              => ! empty( $ctx['modified'] ) ? substr( (string) $ctx['modified'], 0, 10 ) : '',
				),
				$extra
			),
		);
	}

	/**
	 * Sorts findings by impact (clicks at stake), highest first.
	 *
	 * @param array $findings Findings.
	 * @return array
	 */
	private static function sort_by_impact( array $findings ) {
		usort(
			$findings,
			static function ( $a, $b ) {
				return (int) ( $b['data']['impact'] ?? 0 ) <=> (int) ( $a['data']['impact'] ?? 0 ) ?: (int) ( $b['data']['impressions'] ?? 0 ) <=> (int) ( $a['data']['impressions'] ?? 0 );
			}
		);
		return $findings;
	}

	/**
	 * Keeps the strongest findings per type.
	 *
	 * @param array $findings Findings (sorted per type).
	 * @return array
	 */
	public static function cap( array $findings ) {
		$count = array();
		$out   = array();
		foreach ( $findings as $finding ) {
			$type           = $finding['type'];
			$count[ $type ] = ( $count[ $type ] ?? 0 ) + 1;
			if ( $count[ $type ] <= self::MAX_PER_TYPE ) {
				$out[] = $finding;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Queries for the admin
	 * ------------------------------------------------------------------ */

	/**
	 * Lists opportunities (open by default), ordered by severity then impact.
	 *
	 * @param array $filters action, severity, type, search, object_id, status, page, per_page.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::issues();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$where    = array( "category = 'opportunity'" );
		$params   = array();
		$status   = $filters['status'] ?? 'open';
		if ( 'open' === $status ) {
			$where[] = "status IN ('open','pending_approval')";
		} elseif ( 'any' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $status );
		}
		if ( ! empty( $filters['action'] ) && in_array( $filters['action'], self::ACTIONS, true ) ) {
			$where[]  = 'data LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '|' . $filters['action'] . '|' ) . '%';
		}
		foreach ( array( 'severity', 'type' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$values  = array_map( 'strval', (array) $filters[ $field ] );
				$where[] = "{$field} IN (" . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$params  = array_merge( $params, $values );
			}
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = "object_type = 'post' AND object_id = %d";
			$params[] = (int) $filters['object_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(title LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = '%' . $wpdb->esc_like( Url::encode_iri( (string) $filters['search'] ) ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT id, severity_rank, data FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$light = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$rank  = array();
		foreach ( (array) $light as $row ) {
			$data              = json_decode( (string) $row->data, true );
			$rank[ $row->id ] = array( (int) $row->severity_rank, (int) ( is_array( $data ) ? ( $data['impact'] ?? 0 ) : 0 ), (int) $row->id );
		}
		uasort(
			$rank,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: $b[1] <=> $a[1] ?: $b[2] <=> $a[2];
			}
		);
		$total = count( $rank );
		$ids   = array_slice( array_keys( $rank ), ( $page - 1 ) * $per_page, $per_page );
		$items = array();
		if ( $ids ) {
			$ids = array_map( 'intval', $ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
			$byid = array();
			foreach ( (array) $rows as $row ) {
				$byid[ (int) $row['id'] ] = $this->present( $this->issues->hydrate( $row ) );
			}
			foreach ( $ids as $id ) {
				if ( isset( $byid[ $id ] ) ) {
					$items[] = $byid[ $id ];
				}
			}
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Adds action labels and links to a hydrated issue.
	 *
	 * @param array $issue Issue.
	 * @return array
	 */
	public function present( array $issue ) {
		$labels            = self::action_labels();
		$actions           = array_values( array_intersect( (array) ( $issue['data']['actions'] ?? array() ), self::ACTIONS ) );
		$issue['actions']  = array_map(
			static function ( $code ) use ( $labels ) {
				return array(
					'code'  => $code,
					'label' => $labels[ $code ] ?? $code,
				);
			},
			$actions
		);
		$issue['can_edit'] = 'post' === $issue['object_type'] && $issue['object_id'] && current_user_can( 'edit_post', (int) $issue['object_id'] );
		if ( ! $issue['can_edit'] ) {
			$issue['edit_link'] = '';
		}
		unset( $issue['data']['actions_key'] );
		return $issue;
	}

	/**
	 * Open counts per action code and per type.
	 *
	 * @return array{total:int,by_action:array,by_type:array,by_severity:array,action_labels:array,type_labels:array}
	 */
	public function summary() {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( "SELECT type, severity, data FROM {$table} WHERE category = 'opportunity' AND status IN ('open','pending_approval')" );
		$actions = array_fill_keys( self::ACTIONS, 0 );
		$types   = array_fill_keys( self::TYPES, 0 );
		$sev     = array(
			'high'        => 0,
			'medium'      => 0,
			'opportunity' => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $types[ $row->type ] ) ) {
				++$types[ $row->type ];
			}
			if ( isset( $sev[ $row->severity ] ) ) {
				++$sev[ $row->severity ];
			}
			$data = json_decode( (string) $row->data, true );
			foreach ( (array) ( is_array( $data ) ? ( $data['actions'] ?? array() ) : array() ) as $code ) {
				if ( isset( $actions[ $code ] ) ) {
					++$actions[ $code ];
				}
			}
		}
		return array(
			'total'         => count( (array) $rows ),
			'by_action'     => $actions,
			'by_type'       => $types,
			'by_severity'   => $sev,
			'action_labels' => self::action_labels(),
			'type_labels'   => self::type_labels(),
			'last_run'      => $this->state->get( 'opportunities_last_run', null ),
		);
	}

	/**
	 * Top open opportunities.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function top( $limit = 5 ) {
		return $this->query( array( 'per_page' => $limit ) )['items'];
	}

	/**
	 * Marks an opportunity as acted upon (after an editor applied a title/description).
	 * The issue stays open and is re-evaluated when new data arrives.
	 *
	 * @param int    $issue_id    Issue id.
	 * @param string $field       title|description.
	 * @param int    $activity_id Activity id of the change.
	 * @return bool
	 */
	public function mark_applied( $issue_id, $field, $activity_id ) {
		$issue = $this->issues->get( $issue_id );
		if ( ! $issue ) {
			return false;
		}
		return $this->issues->update_data(
			(int) $issue_id,
			array(
				'applied'          => array_values( array_unique( array_merge( (array) ( $issue['data']['applied'] ?? array() ), array( $field ) ) ) ),
				'applied_at'       => current_time( 'mysql', true ),
				'applied_field'    => $field,
				'applied_activity' => (int) $activity_id,
				'applied_by'       => get_current_user_id(),
				'change_measured'  => false,
			)
		);
	}

	/**
	 * Clears "applied" markers when the change is undone.
	 *
	 * @param object $row Activity row.
	 */
	public function on_activity_undone( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) || empty( $row->object_id ) || ! in_array( (string) $row->action, array( 'seo_title_updated', 'seo_description_updated' ), true ) ) {
			return;
		}
		$issues = $this->issues->query(
			array(
				'category'    => 'opportunity',
				'object_type' => 'post',
				'object_id'   => (int) $row->object_id,
				'status'      => 'any',
				'per_page'    => 100,
			)
		)['items'];
		$field  = 'seo_title_updated' === $row->action ? 'title' : 'description';
		foreach ( $issues as $issue ) {
			if ( (int) ( $issue['data']['applied_activity'] ?? 0 ) !== (int) $row->id ) {
				continue;
			}
			$this->issues->update_data(
				(int) $issue['id'],
				array(
					'applied'          => array_values( array_diff( (array) ( $issue['data']['applied'] ?? array() ), array( $field ) ) ),
					'applied_at'       => null,
					'applied_field'    => null,
					'applied_activity' => null,
					'applied_by'       => null,
					'change_measured'  => null,
				)
			);
		}
	}

	/**
	 * Remembers the structured-data types found when the indexer crawled a post.
	 *
	 * @param array $page Crawled page (see Indexer\Crawler).
	 */
	public function record_crawl( $page ) {
		if ( ! is_array( $page ) || 'post' !== ( $page['object_type'] ?? '' ) || empty( $page['object_id'] ) || (int) ( $page['status'] ?? 0 ) !== 200 ) {
			return;
		}
		$doc = $page['doc'] ?? null;
		if ( ! is_object( $doc ) || ! method_exists( $doc, 'schema_types' ) ) {
			return;
		}
		$types    = array_slice( array_map( 'sanitize_text_field', array_values( array_unique( array_merge( (array) $doc->schema_types(), method_exists( $doc, 'microdata_types' ) ? (array) $doc->microdata_types() : array() ) ) ) ), 0, 30 );
		$existing = get_post_meta( (int) $page['object_id'], self::CRAWL_META, true );
		if ( is_array( $existing ) && isset( $existing['types'], $existing['at'] ) && $existing['types'] === $types && strtotime( (string) $existing['at'] ) > time() - WEEK_IN_SECONDS ) {
			return;
		}
		update_post_meta(
			(int) $page['object_id'],
			self::CRAWL_META,
			array(
				'types' => $types,
				'at'    => gmdate( 'c' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * What / Why / Fix texts (built lazily from the evidence)
	 * ------------------------------------------------------------------ */

	/**
	 * "What happened" text.
	 *
	 * @param string $type Type.
	 * @param array  $d    Issue data.
	 * @return string
	 */
	public static function what_text( $type, array $d ) {
		$query = (string) ( $d['query'] ?? '' );
		/* translators: %s: search query. */
		$qtext = '' !== $query ? ' ' . sprintf( __( 'Top query: “%s”.', 'searchpilot-pro' ), $query ) : '';
		$range = '3m' === ( $d['range'] ?? '' ) ? __( 'the last 3 months', 'searchpilot-pro' ) : __( 'the last 28 days', 'searchpilot-pro' );
		switch ( $type ) {
			case 'low_ctr':
				return sprintf(
					/* translators: 1: impressions, 2: period, 3: position, 4: CTR, 5: clicks, 6: expected CTR, 7: missed clicks. */
					__( 'Shown %1$s times in search results in %2$s at an average position of %3$s, but only %4$s of searchers clicked (%5$s clicks). Results at this position on your site usually get about %6$s — roughly %7$s more clicks.', 'searchpilot-pro' ),
					self::num( $d['impressions'] ?? 0 ),
					$range,
					self::pos( $d['position'] ?? 0 ),
					self::pct( $d['ctr'] ?? 0 ),
					self::num( $d['clicks'] ?? 0 ),
					self::pct( $d['expected_ctr'] ?? 0 ),
					self::num( $d['missed_clicks'] ?? 0 )
				) . $qtext;
			case 'striking_distance':
				return sprintf(
					/* translators: 1: position, 2: impressions, 3: clicks, 4: period. */
					__( 'Ranks at an average position of %1$s with %2$s impressions and %3$s clicks in %4$s — just outside the top 3 results, where most clicks go.', 'searchpilot-pro' ),
					self::pos( $d['position'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 ),
					self::num( $d['clicks'] ?? 0 ),
					$range
				) . $qtext;
			case 'page_two_rankings':
				return sprintf(
					/* translators: 1: position, 2: impressions, 3: period, 4: clicks. */
					__( 'Ranks on page two or three of the results (average position %1$s) with %2$s impressions in %3$s. Few searchers scroll that far, so it earned only %4$s clicks.', 'searchpilot-pro' ),
					self::pos( $d['position'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 ),
					$range,
					self::num( $d['clicks'] ?? 0 )
				) . $qtext;
			case 'declining_page':
				$text = sprintf(
					/* translators: 1: previous clicks, 2: clicks, 3: percent drop, 4: previous impressions, 5: impressions. */
					__( 'Clicks fell from %1$s to %2$s (−%3$s) compared with the previous 28 days, and impressions dropped from %4$s to %5$s.', 'searchpilot-pro' ),
					self::num( $d['prev_clicks'] ?? 0 ),
					self::num( $d['clicks'] ?? 0 ),
					self::pct( $d['drop'] ?? 0, 0 ),
					self::num( $d['prev_impressions'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 )
				);
				if ( ! empty( $d['position'] ) && ! empty( $d['prev_position'] ) ) {
					/* translators: 1: previous position, 2: position. */
					$text .= ' ' . sprintf( __( 'Average position moved from %1$s to %2$s.', 'searchpilot-pro' ), self::pos( $d['prev_position'] ), self::pos( $d['position'] ) );
				}
				return $text . $qtext;
			case 'growing_page':
				if ( null === ( $d['growth'] ?? null ) ) {
					/* translators: %s: clicks. */
					return sprintf( __( 'New in search: %s clicks in the last 28 days after none in the 28 days before.', 'searchpilot-pro' ), self::num( $d['clicks'] ?? 0 ) ) . $qtext;
				}
				return sprintf(
					/* translators: 1: previous clicks, 2: clicks, 3: growth percent. */
					__( 'Clicks rose from %1$s to %2$s (+%3$s) compared with the previous 28 days.', 'searchpilot-pro' ),
					self::num( $d['prev_clicks'] ?? 0 ),
					self::num( $d['clicks'] ?? 0 ),
					self::pct( $d['growth'] ?? 0, 0 )
				) . $qtext;
			case 'keyword_cannibalization':
				$pages = array();
				foreach ( (array) ( $d['competing_pages'] ?? array() ) as $page ) {
					$pages[] = sprintf(
						/* translators: 1: page title or URL, 2: position, 3: share of impressions. */
						__( '%1$s (position %2$s, %3$s of impressions)', 'searchpilot-pro' ),
						'' !== (string) ( $page['title'] ?? '' ) ? $page['title'] : Url::display( (string) ( $page['page'] ?? '' ) ),
						self::pos( $page['position'] ?? 0 ),
						self::pct( $page['share'] ?? 0, 0 )
					);
				}
				return sprintf(
					/* translators: 1: query, 2: impressions, 3: number of pages, 4: list of pages. */
					__( 'For the query “%1$s” (%2$s impressions in the last 28 days), %3$d of your pages compete with each other: %4$s.', 'searchpilot-pro' ),
					$query,
					self::num( $d['impressions'] ?? 0 ),
					count( $pages ),
					implode( '; ', $pages )
				);
			case 'losing_clicks':
				if ( 'ranking' === ( $d['variant'] ?? '' ) ) {
					return sprintf(
						/* translators: 1: previous clicks, 2: clicks, 3: percent, 4: previous impressions, 5: impressions, 6: previous position, 7: position. */
						__( 'Clicks fell from %1$s to %2$s (−%3$s) although impressions held (%4$s → %5$s): the average position slipped from %6$s to %7$s, where fewer people click.', 'searchpilot-pro' ),
						self::num( $d['prev_clicks'] ?? 0 ),
						self::num( $d['clicks'] ?? 0 ),
						self::pct( $d['drop'] ?? 0, 0 ),
						self::num( $d['prev_impressions'] ?? 0 ),
						self::num( $d['impressions'] ?? 0 ),
						self::pos( $d['prev_position'] ?? 0 ),
						self::pos( $d['position'] ?? 0 )
					) . $qtext;
				}
				return sprintf(
					/* translators: 1: previous clicks, 2: clicks, 3: percent, 4: previous impressions, 5: impressions, 6: previous position, 7: position. */
					__( 'Clicks fell from %1$s to %2$s (−%3$s) while impressions held (%4$s → %5$s) and the average position stayed similar (%6$s → %7$s): searchers see the result as often but click it less.', 'searchpilot-pro' ),
					self::num( $d['prev_clicks'] ?? 0 ),
					self::num( $d['clicks'] ?? 0 ),
					self::pct( $d['drop'] ?? 0, 0 ),
					self::num( $d['prev_impressions'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 ),
					self::pos( $d['prev_position'] ?? 0 ),
					self::pos( $d['position'] ?? 0 )
				) . $qtext;
			case 'query_without_landing_page':
				return sprintf(
					/* translators: 1: query, 2: impressions, 3: best position, 4: page title or URL. */
					__( 'People searched for “%1$s” %2$s times in the last 28 days, but your best result ranks at position %3$s (%4$s) and its title and main heading do not cover the query.', 'searchpilot-pro' ),
					$query,
					self::num( $d['impressions'] ?? 0 ),
					self::pos( $d['best_position'] ?? 0 ),
					'' !== (string) ( $d['best_title'] ?? '' ) ? $d['best_title'] : Url::display( (string) ( $d['best_page'] ?? '' ) )
				);
			case 'content_refresh':
				return sprintf(
					/* translators: 1: date, 2: previous impressions, 3: impressions, 4: percent drop. */
					__( 'Last updated on %1$s. Impressions over the last 3 months fell from %2$s to %3$s (−%4$s) compared with the 3 months before.', 'searchpilot-pro' ),
					self::date( $d['modified'] ?? '' ),
					self::num( $d['prev_impressions'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 ),
					self::pct( $d['impressions_drop'] ?? 0, 0 )
				) . $qtext;
			case 'fix_indexing':
				return sprintf(
					/* translators: 1: previous impressions, 2: previous clicks, 3: impressions now, 4: registry status. */
					__( 'This page had %1$s impressions and %2$s clicks in the previous 28 days but almost none now (%3$s). SearchPilot’s URL registry reports: %4$s.', 'searchpilot-pro' ),
					self::num( $d['prev_impressions'] ?? 0 ),
					self::num( $d['prev_clicks'] ?? 0 ),
					self::num( $d['impressions'] ?? 0 ),
					self::status_label( $d )
				);
			case 'improve_structured_data':
				if ( ! empty( $d['schema_issues'] ) ) {
					return sprintf(
						/* translators: 1: impressions, 2: number of problems. */
						_n( 'One of your most visible articles (%1$s impressions in the last 28 days) has %2$d open structured-data problem.', 'One of your most visible articles (%1$s impressions in the last 28 days) has %2$d open structured-data problems.', (int) $d['schema_issues'], 'searchpilot-pro' ),
						self::num( $d['impressions'] ?? 0 ),
						(int) $d['schema_issues']
					);
				}
				$found = ! empty( $d['schema_types'] ) ? implode( ', ', array_map( 'strval', (array) $d['schema_types'] ) ) : __( 'none', 'searchpilot-pro' );
				/* translators: 1: impressions, 2: schema types found. */
				return sprintf( __( 'One of your most visible articles (%1$s impressions in the last 28 days) does not output Article structured data. Types found on the page: %2$s.', 'searchpilot-pro' ), self::num( $d['impressions'] ?? 0 ), $found );
		}
		return '';
	}

	/**
	 * "Why it matters" text.
	 *
	 * @param string $type Type.
	 * @param array  $d    Issue data.
	 * @return string
	 */
	public static function why_text( $type, array $d ) {
		switch ( $type ) {
			case 'low_ctr':
				return __( 'Searchers see this page but choose other results. At a good position, a title and description that match the query and promise a clear answer are the quickest way to win more clicks — no ranking change needed.', 'searchpilot-pro' );
			case 'striking_distance':
				return __( 'Pages just outside the top positions often need only modest improvements — fresher, more complete content and more internal links from related pages — to move up, and the top 3 results receive most clicks.', 'searchpilot-pro' );
			case 'page_two_rankings':
				return __( 'Search engines consider this page relevant but not yet the best answer. Strengthening it and supporting it with related content and internal links can lift it onto the first page.', 'searchpilot-pro' );
			case 'declining_page':
				return __( 'Falling impressions mean the page is shown less often: rankings slipped, interest in the topic changed or newer competing content appeared. Declines tend to continue when the page is left alone.', 'searchpilot-pro' );
			case 'growing_page':
				return __( 'Search engines are rewarding this page. Linking from it to related articles passes that momentum on and helps visitors discover more of your content.', 'searchpilot-pro' );
			case 'keyword_cannibalization':
				if ( ! empty( $d['news'] ) ) {
					return __( 'When several articles answer the same query at the same time, search engines have to guess which one is the current answer and may rank none of them as well as one clearly current page would.', 'searchpilot-pro' );
				}
				return __( 'When several of your pages answer the same query, search engines split relevance and links between them and may rank none of them as well as one strong page would.', 'searchpilot-pro' );
			case 'losing_clicks':
				return 'ranking' === ( $d['variant'] ?? '' )
					? __( 'The page is still shown as often but lower in the results, where fewer people click. Competing pages have likely become more complete or more current.', 'searchpilot-pro' )
					: __( 'Same visibility but fewer clicks usually means the result looks less attractive than before — for example new search features above it, stronger competing snippets or an outdated title or date.', 'searchpilot-pro' );
			case 'query_without_landing_page':
				return __( 'There is search demand you are not serving well: no page focuses on this topic, so search engines rank a loosely related page poorly.', 'searchpilot-pro' );
			case 'content_refresh':
				return __( 'Older articles lose visibility as information ages and newer content appears. A genuine update keeps them useful and competitive.', 'searchpilot-pro' );
			case 'fix_indexing':
				return __( 'A page that drops out of the index loses all its search traffic. The cause is usually technical (noindex, canonical, redirects, server errors or robots.txt) and can often be fixed quickly.', 'searchpilot-pro' );
			case 'improve_structured_data':
				return __( 'Valid Article structured data helps search engines understand the headline, author, dates and images, which can make the result eligible for richer presentation. It never guarantees rich results or rankings.', 'searchpilot-pro' );
		}
		return '';
	}

	/**
	 * "Recommended fix" text.
	 *
	 * @param string $type Type.
	 * @param array  $d    Issue data.
	 * @return string
	 */
	public static function fix_text( $type, array $d ) {
		$query = (string) ( $d['query'] ?? '' );
		$note  = ' ' . __( 'SearchPilot never rewrites your content automatically.', 'searchpilot-pro' );
		switch ( $type ) {
			case 'low_ctr':
				if ( '' !== $query ) {
					/* translators: %s: search query. */
					$lead = sprintf( __( 'Rewrite the SEO title so it leads with “%s” and makes the benefit clear, and write a 120–155 character meta description that answers that query.', 'searchpilot-pro' ), $query );
				} else {
					$lead = __( 'Rewrite the SEO title so it matches what searchers look for and makes the benefit clear, and write a 120–155 character meta description.', 'searchpilot-pro' );
				}
				return $lead . ' ' . __( 'Use “Update title” and “Improve description” to review drafts and apply your own wording — nothing changes until you apply it.', 'searchpilot-pro' );
			case 'striking_distance':
				if ( '' !== $query ) {
					/* translators: %s: search query. */
					$lead = sprintf( __( 'Refresh the content for “%s”: answer the query more completely and update facts and dates.', 'searchpilot-pro' ), $query );
				} else {
					$lead = __( 'Refresh the content: answer the main query more completely and update facts and dates.', 'searchpilot-pro' );
				}
				return $lead . ' ' . __( 'Link to this page from related articles with descriptive anchor text, and make sure the title contains the main query.', 'searchpilot-pro' ) . $note;
			case 'page_two_rankings':
				return __( 'Expand and update the content, create a supporting article on a closely related subtopic that links here, and add internal links from your strongest related pages.', 'searchpilot-pro' ) . $note;
			case 'declining_page':
				$fix = __( 'Compare the page with the queries it lost, update outdated information and cover missing subtopics.', 'searchpilot-pro' );
				if ( ! empty( $d['indexing_signal'] ) ) {
					$fix .= ' ' . __( 'The URL registry also reports an indexing problem for this URL — check the Indexing screen first.', 'searchpilot-pro' );
				}
				return $fix . $note;
			case 'growing_page':
				return __( 'Add internal links from this page to related content (the Internal Links screen suggests matching articles), keep it up to date and make sure it links to your key pages.', 'searchpilot-pro' );
			case 'keyword_cannibalization':
				if ( ! empty( $d['merge_recommended'] ) ) {
					return __( 'These evergreen pages share most of their search queries. Choose the best one and merge the others into it (then redirect them), or clearly differentiate each page’s angle and title. SearchPilot never merges articles automatically.', 'searchpilot-pro' );
				}
				return __( 'Differentiate the pages — give each a distinct angle, title and main heading — or, if one is simply newer, link the older pages to the latest one near the top so readers and search engines find the current answer. SearchPilot never merges or rewrites articles automatically.', 'searchpilot-pro' );
			case 'losing_clicks':
				if ( 'ranking' === ( $d['variant'] ?? '' ) ) {
					return __( 'Refresh the content so it is the most complete and current answer for its main queries, then review the title and description.', 'searchpilot-pro' ) . $note;
				}
				return __( 'Refresh the SEO title and meta description so they match what people search for today (current year, clear benefit, the main query), then compare clicks after the next data update.', 'searchpilot-pro' );
			case 'query_without_landing_page':
				/* translators: %s: search query. */
				return sprintf( __( 'Create a dedicated article that answers “%s” and link to it from related pages. Write it for readers first — avoid thin or near-duplicate pages.', 'searchpilot-pro' ), $query );
			case 'content_refresh':
				return __( 'Review and update the article: refresh facts, figures and dates, answer the questions readers ask today, improve headings and update internal links. Change the published date only for substantial updates.', 'searchpilot-pro' ) . $note;
			case 'fix_indexing':
				return __( 'Open the Indexing screen for this URL, fix the reported problem, then request re-indexing — SearchPilot notifies search engines through their official APIs.', 'searchpilot-pro' );
			case 'improve_structured_data':
				return __( 'Open the Schema screen, fix the reported problems or enable Article/NewsArticle schema for this post type, then re-test the page. Structured data must only describe content that is visible on the page.', 'searchpilot-pro' );
		}
		return '';
	}

	/**
	 * Registry status for texts.
	 *
	 * @param array $d Data.
	 * @return string
	 */
	private static function status_label( array $d ) {
		$labels = array(
			'not_indexed'            => __( 'not indexed', 'searchpilot-pro' ),
			'crawled_not_indexed'    => __( 'crawled – currently not indexed', 'searchpilot-pro' ),
			'discovered_not_indexed' => __( 'discovered – currently not indexed', 'searchpilot-pro' ),
			'excluded_noindex'       => __( 'excluded by a noindex tag', 'searchpilot-pro' ),
			'blocked_robots'         => __( 'blocked by robots.txt', 'searchpilot-pro' ),
			'duplicate_canonical'    => __( 'duplicate — Google chose a different canonical', 'searchpilot-pro' ),
			'alternate_canonical'    => __( 'alternate page with a canonical elsewhere', 'searchpilot-pro' ),
			'redirect'               => __( 'redirects', 'searchpilot-pro' ),
			'not_found'              => __( 'not found (404)', 'searchpilot-pro' ),
			'soft_404'               => __( 'soft 404', 'searchpilot-pro' ),
			'server_error'           => __( 'server error (5xx)', 'searchpilot-pro' ),
			'unknown_to_google'      => __( 'unknown to Google', 'searchpilot-pro' ),
			'removed'                => __( 'removed', 'searchpilot-pro' ),
		);
		$status = (string) ( $d['index_status'] ?? '' );
		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}
		if ( ! empty( $d['http_status'] ) ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'HTTP %d', 'searchpilot-pro' ), (int) $d['http_status'] );
		}
		return '' !== (string) ( $d['coverage'] ?? '' ) ? (string) $d['coverage'] : __( 'an indexing error', 'searchpilot-pro' );
	}

	/**
	 * Formats a count.
	 *
	 * @param mixed $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( (float) $n ) : number_format( (float) $n );
	}

	/**
	 * Formats a fraction as a percentage.
	 *
	 * @param mixed $fraction Fraction.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	private static function pct( $fraction, $decimals = 1 ) {
		$value = (float) $fraction * 100;
		$text  = function_exists( 'number_format_i18n' ) ? number_format_i18n( $value, $decimals ) : number_format( $value, $decimals );
		return $text . '%';
	}

	/**
	 * Formats a position.
	 *
	 * @param mixed $position Position.
	 * @return string
	 */
	private static function pos( $position ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( (float) $position, 1 ) : number_format( (float) $position, 1 );
	}

	/**
	 * Formats a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private static function date( $date ) {
		$time = strtotime( (string) $date . ' UTC' );
		if ( ! $time ) {
			return '—';
		}
		return function_exists( 'date_i18n' ) ? date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $time ) : gmdate( 'Y-m-d', $time );
	}
}

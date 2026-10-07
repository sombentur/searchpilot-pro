<?php
/**
 * Builds and reconciles the URL registry.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every public URL of the site: published posts of all public
 * post types, term archives with posts, author archives, the home page,
 * the posts page, post type archives and (optionally) a few paginated
 * archive pages. Runs incrementally in ID-range batches inside a time
 * budget; progress lives in State so a build survives many cron runs.
 */
class RegistrySync {

	const BUILD     = 'registry_build';
	const RECONCILE = 'registry_reconcile';
	const BATCH     = 200;

	/**
	 * Object types managed by sync_special().
	 */
	const SPECIAL_TYPES = array( 'home', 'archive', 'pagination' );

	/**
	 * Issue types that describe a URL that went away (e.g. "redirect the deleted
	 * post"): they are raised after the removal and must survive it.
	 */
	const KEEP_ON_REMOVAL = array( 'deleted_url_redirect', 'slug_redirect_needed' );

	/**
	 * Option set when a one-off cleanup of rows/issues of vanished objects is due.
	 */
	const CLEANUP_OPTION = 'searchpilot_pending_registry_cleanup';

	/**
	 * State key with the last cleanup result.
	 */
	const CLEANUP_STATE = 'registry_cleanup';

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

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
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Issue detector.
	 *
	 * @var IssueDetector
	 */
	private $detector;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Issue types.
	 *
	 * @var IssueRegistry
	 */
	private $issue_types;

	/**
	 * Memo for per-request lookups.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param UrlRepository    $urls        URLs.
	 * @param ContentTypes     $types       Content types.
	 * @param MetaStore        $meta        Meta store.
	 * @param Settings         $settings    Settings.
	 * @param State            $state       State.
	 * @param ConflictDetector $conflicts   Conflicts.
	 * @param IssueDetector    $detector    Issue detector.
	 * @param Logger           $logger      Logger.
	 * @param IssueRepository  $issues      Issues.
	 * @param IssueRegistry    $issue_types Issue types.
	 */
	public function __construct( UrlRepository $urls, ContentTypes $types, MetaStore $meta, Settings $settings, State $state, ConflictDetector $conflicts, IssueDetector $detector, Logger $logger, IssueRepository $issues, IssueRegistry $issue_types ) {
		$this->urls        = $urls;
		$this->types       = $types;
		$this->meta        = $meta;
		$this->settings    = $settings;
		$this->state       = $state;
		$this->conflicts   = $conflicts;
		$this->detector    = $detector;
		$this->logger      = $logger;
		$this->issues      = $issues;
		$this->issue_types = $issue_types;
	}

	/* --------------------------------------------------------------------- *
	 * Initial build
	 * --------------------------------------------------------------------- */

	/**
	 * Starts (or restarts) a full registry build.
	 *
	 * @param string $reason initial|manual|settings.
	 * @return array Progress.
	 */
	public function start_build( $reason = 'initial' ) {
		$post_types = array_keys( $this->types->post_types() );
		if ( $this->attachment_pages_live() ) {
			$post_types[] = 'attachment';
		}
		$taxonomies = array_keys( $this->types->taxonomies() );

		$progress = array(
			'status'      => 'running',
			'reason'      => sanitize_key( $reason ),
			'phase'       => 'special',
			'post_types'  => $post_types,
			'type_index'  => 0,
			'taxonomies'  => $taxonomies,
			'tax_index'   => 0,
			'last_id'     => 0,
			'counts'      => array(
				'special' => 0,
				'posts'   => 0,
				'terms'   => 0,
				'authors' => 0,
			),
			'done'        => 0,
			'total'       => $this->estimate_total( $post_types, $taxonomies ),
			'started_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
			'finished_at' => null,
		);
		$this->state->set( self::BUILD, $progress );
		$this->logger->info( 'indexer', sprintf( 'URL registry build started (%s).', $progress['reason'] ), array( 'estimate' => $progress['total'] ) );
		return $progress;
	}

	/**
	 * Build progress (with a percentage).
	 *
	 * @return array
	 */
	public function progress() {
		$p = $this->state->get( self::BUILD );
		if ( ! is_array( $p ) ) {
			return array(
				'status'  => 'pending',
				'percent' => 0,
				'done'    => 0,
				'total'   => 0,
			);
		}
		$p['percent'] = 'done' === $p['status'] ? 100 : ( $p['total'] > 0 ? min( 99, (int) floor( 100 * $p['done'] / $p['total'] ) ) : 0 );
		unset( $p['post_types'], $p['taxonomies'] );
		return $p;
	}

	/**
	 * Whether a build is in progress.
	 *
	 * @return bool
	 */
	public function is_building() {
		$p = $this->state->get( self::BUILD );
		return is_array( $p ) && 'running' === ( $p['status'] ?? '' );
	}

	/**
	 * Runs build batches until the budget is used.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array Progress.
	 */
	public function run_build( TimeBudget $budget ) {
		$p = $this->state->get( self::BUILD );
		if ( ! is_array( $p ) || 'running' !== ( $p['status'] ?? '' ) ) {
			return $this->progress();
		}
		while ( ! $budget->exhausted() && 'running' === $p['status'] ) {
			switch ( $p['phase'] ) {
				case 'special':
					$p['counts']['special'] = $this->sync_special();
					$p['done']             += $p['counts']['special'];
					$p['phase']             = 'posts';
					$p['last_id']           = 0;
					break;
				case 'posts':
					$type = $p['post_types'][ $p['type_index'] ] ?? null;
					if ( null === $type ) {
						$p['phase']   = 'terms';
						$p['last_id'] = 0;
						break;
					}
					$n = $this->build_posts_batch( $type, $p, $budget );
					if ( 0 === $n ) {
						++$p['type_index'];
						$p['last_id'] = 0;
					}
					break;
				case 'terms':
					$tax = $p['taxonomies'][ $p['tax_index'] ] ?? null;
					if ( null === $tax ) {
						$p['phase']   = 'authors';
						$p['last_id'] = 0;
						break;
					}
					$n = $this->build_terms_batch( $tax, $p, $budget );
					if ( 0 === $n ) {
						++$p['tax_index'];
						$p['last_id'] = 0;
					}
					break;
				case 'authors':
					$n = $this->build_authors_batch( $p, $budget );
					if ( 0 === $n ) {
						$p['phase'] = 'done';
					}
					break;
				default:
					$p['status']      = 'done';
					$p['phase']       = 'done';
					$p['finished_at'] = gmdate( 'c' );
					$p['total']       = max( $p['total'], $p['done'] );
					$this->logger->info( 'indexer', sprintf( 'URL registry build finished: %d URLs.', $p['done'] ), $p['counts'] );
			}
			$p['updated_at'] = gmdate( 'c' );
			$this->state->set( self::BUILD, $p );
		}
		return $this->progress();
	}

	/**
	 * One batch of posts of a type.
	 *
	 * @param string     $type   Post type.
	 * @param array      $p      Progress (by reference).
	 * @param TimeBudget $budget Budget.
	 * @return int Posts processed.
	 */
	private function build_posts_batch( $type, array &$p, TimeBudget $budget ) {
		global $wpdb;
		$status = 'attachment' === $type ? 'inherit' : 'publish';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d", $type, $status, (int) $p['last_id'], self::BATCH ) ) );
		if ( ! $ids ) {
			return 0;
		}
		_prime_post_caches( $ids, false, true );
		$count = 0;
		foreach ( $ids as $id ) {
			$p['last_id'] = $id;
			if ( $this->sync_post( $id ) ) {
				++$p['counts']['posts'];
			}
			++$p['done'];
			++$count;
			if ( $budget->exhausted() ) {
				break;
			}
		}
		return $count;
	}

	/**
	 * One batch of terms of a taxonomy.
	 *
	 * @param string     $taxonomy Taxonomy.
	 * @param array      $p        Progress (by reference).
	 * @param TimeBudget $budget   Budget.
	 * @return int Terms processed.
	 */
	private function build_terms_batch( $taxonomy, array &$p, TimeBudget $budget ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count > 0 AND term_id > %d ORDER BY term_id ASC LIMIT %d", $taxonomy, (int) $p['last_id'], self::BATCH ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$p['last_id'] = $id;
			if ( $this->sync_term( get_term( $id, $taxonomy ) ) ) {
				++$p['counts']['terms'];
			}
			++$p['done'];
			++$count;
			if ( $budget->exhausted() ) {
				break;
			}
		}
		return $count;
	}

	/**
	 * One batch of authors with published posts.
	 *
	 * @param array      $p      Progress (by reference).
	 * @param TimeBudget $budget Budget.
	 * @return int Authors processed.
	 */
	private function build_authors_batch( array &$p, TimeBudget $budget ) {
		global $wpdb;
		$types = $this->types->indexable_post_types();
		if ( ! $types ) {
			return 0;
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$holders}) AND post_author > %d ORDER BY post_author ASC LIMIT %d", array_merge( $types, array( (int) $p['last_id'], self::BATCH ) ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$p['last_id'] = $id;
			if ( $this->sync_author( $id ) ) {
				++$p['counts']['authors'];
			}
			++$p['done'];
			++$count;
			if ( $budget->exhausted() ) {
				break;
			}
		}
		return $count;
	}

	/**
	 * Estimated number of URLs of a build.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $taxonomies Taxonomies.
	 * @return int
	 */
	private function estimate_total( array $post_types, array $taxonomies ) {
		$total = 3;
		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );
			$total += (int) ( 'attachment' === $type ? ( $counts->inherit ?? 0 ) : ( $counts->publish ?? 0 ) );
		}
		foreach ( $taxonomies as $tax ) {
			$n      = wp_count_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => true,
				)
			);
			$total += is_wp_error( $n ) ? 0 : (int) $n;
		}
		return $total;
	}

	/* --------------------------------------------------------------------- *
	 * Object sync
	 * --------------------------------------------------------------------- */

	/**
	 * Registers/refreshes a post (or attachment page).
	 *
	 * @param int|\WP_Post $post  Post.
	 * @param array        $extra Extra fields to write.
	 * @return int Row id (0 when the post has no public URL).
	 */
	public function sync_post( $post, array $extra = array() ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return 0;
		}
		$attachment = 'attachment' === $post->post_type;
		if ( ( $attachment && ( 'inherit' !== $post->post_status || ! $this->attachment_pages_live() ) ) || ( ! $attachment && 'publish' !== $post->post_status ) ) {
			return 0;
		}
		if ( ! $attachment && ! isset( $this->post_types()[ $post->post_type ] ) ) {
			return 0;
		}
		$url = get_permalink( $post );
		if ( ! $url ) {
			return 0;
		}
		$id = $this->urls->upsert( $url, array_merge( $this->post_fields( $post ), $extra ) );
		if ( $id ) {
			$this->set_expected_canonical( $id, $this->expected_post_canonical( $post, $url ) );
		}
		return $id;
	}

	/**
	 * Registry fields of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function post_fields( \WP_Post $post ) {
		$attachment = 'attachment' === $post->post_type;
		$ix         = $attachment
			? array(
				'indexable' => false,
				'reason'    => 'attachment',
			)
			: $this->types->post_indexability( $post );
		$modified   = '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_date_gmt;

		$fields = array(
			'url'                 => (string) get_permalink( $post ),
			'object_type'         => $attachment ? 'attachment' : 'post',
			'object_subtype'      => $post->post_type,
			'object_id'           => (int) $post->ID,
			'status'              => 'active',
			'indexable'           => (bool) $ix['indexable'],
			'indexability_reason' => substr( (string) $ix['reason'], 0, 64 ),
			'priority'            => $this->post_priority( $post ),
			'last_modified'       => '0000-00-00 00:00:00' !== $modified ? $modified : null,
		);
		$in_sitemap = $this->sitemap_membership( $attachment ? false : $this->types->post_in_sitemap( $post ) );
		if ( null !== $in_sitemap ) {
			$fields['in_sitemap'] = $in_sitemap;
		}
		return $fields;
	}

	/**
	 * Registers/refreshes a term archive.
	 *
	 * @param int|\WP_Term $term  Term.
	 * @param array        $extra Extra fields.
	 * @return int Row id.
	 */
	public function sync_term( $term, array $extra = array() ) {
		$term = get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}
		$taxes = $this->types->taxonomies();
		if ( ! isset( $taxes[ $term->taxonomy ] ) || (int) $term->count < 1 ) {
			return 0;
		}
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) || ! $url ) {
			return 0;
		}
		$ix     = $this->types->term_indexability( $term );
		$fields = array(
			'object_type'         => 'term',
			'object_subtype'      => $term->taxonomy,
			'object_id'           => (int) $term->term_id,
			'status'              => 'active',
			'indexable'           => (bool) $ix['indexable'],
			'indexability_reason' => substr( (string) $ix['reason'], 0, 64 ),
			'priority'            => 50,
		);
		$in_sitemap = $this->sitemap_membership( ! empty( $taxes[ $term->taxonomy ]['sitemap'] ) && $ix['indexable'] );
		if ( null !== $in_sitemap ) {
			$fields['in_sitemap'] = $in_sitemap;
		}
		$id = $this->urls->upsert( $url, array_merge( $fields, $extra ) );
		if ( $id ) {
			$custom = trim( (string) $this->meta->get_term_field( $term->term_id, 'canonical' ) );
			$this->set_expected_canonical( $id, '' !== $custom ? $custom : $url );
		}
		return $id;
	}

	/**
	 * Registers/refreshes an author archive.
	 *
	 * @param int   $user_id User id.
	 * @param array $extra   Extra fields.
	 * @return int Row id.
	 */
	public function sync_author( $user_id, array $extra = array() ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return 0;
		}
		$url = get_author_posts_url( $user->ID, $user->user_nicename );
		if ( ! $url ) {
			return 0;
		}
		$enabled   = (bool) $this->settings->get( 'content.authors_index' );
		$indexable = $enabled && $this->types->is_author_indexable( $user->ID );
		$fields    = array(
			'object_type'         => 'author',
			'object_subtype'      => 'user',
			'object_id'           => (int) $user->ID,
			'status'              => 'active',
			'indexable'           => $indexable,
			'indexability_reason' => $indexable ? 'ok' : ( $enabled ? 'empty' : 'author_archives' ),
			'priority'            => 50,
		);
		$in_sitemap = $this->sitemap_membership( $indexable && (bool) $this->settings->get( 'content.authors_sitemap' ) );
		if ( null !== $in_sitemap ) {
			$fields['in_sitemap'] = $in_sitemap;
		}
		$id = $this->urls->upsert( $url, array_merge( $fields, $extra ) );
		if ( $id ) {
			$this->set_expected_canonical( $id, $url );
		}
		return $id;
	}

	/**
	 * Registers the home page, post type archives and paginated samples;
	 * marks special rows that no longer exist as removed.
	 *
	 * @return int Number of special URLs.
	 */
	public function sync_special() {
		$keep = array();
		foreach ( $this->special_urls() as $item ) {
			$fields = $item['fields'];
			$id     = $this->urls->upsert( $item['url'], $fields );
			if ( $id ) {
				$keep[ $id ] = true;
				$this->set_expected_canonical( $id, $item['url'] );
			}
		}
		global $wpdb;
		$table   = Tables::urls();
		$holders = implode( ',', array_fill( 0, count( self::SPECIAL_TYPES ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND object_type IN ({$holders})", self::SPECIAL_TYPES ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $keep[ (int) $row['id'] ] ) ) {
				$this->remove_row( $this->urls->hydrate( $row ) );
			}
		}
		return count( $keep );
	}

	/**
	 * Special URLs with their registry fields.
	 *
	 * @return array<int,array{url:string,fields:array}>
	 */
	public function special_urls() {
		$out         = array();
		$discouraged = '0' === (string) get_option( 'blog_public', '1' );
		$owns        = $this->conflicts->owns( 'sitemap' );
		$front_page  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$posts_page  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

		// A static front page is registered as its post; otherwise the home row.
		if ( ! $front_page || ! get_post( $front_page ) ) {
			$fields = array(
				'object_type'         => 'home',
				'object_subtype'      => 'home',
				'object_id'           => 0,
				'status'              => 'active',
				'indexable'           => ! $discouraged,
				'indexability_reason' => $discouraged ? 'site_noindex' : 'ok',
				'priority'            => 100,
			);
			if ( $owns ) {
				$fields['in_sitemap'] = ! $discouraged;
			}
			$out[] = array(
				'url'    => home_url( '/' ),
				'fields' => $fields,
			);
		}

		foreach ( $this->types->post_types() as $name => $conf ) {
			if ( 'post' === $name || 'page' === $name ) {
				continue;
			}
			$link = get_post_type_archive_link( $name );
			if ( ! $link ) {
				continue;
			}
			$fields = array(
				'object_type'         => 'archive',
				'object_subtype'      => $name,
				'object_id'           => 0,
				'status'              => 'active',
				'indexable'           => ! $discouraged && ! empty( $conf['index'] ),
				'indexability_reason' => $discouraged ? 'site_noindex' : ( empty( $conf['index'] ) ? 'post_type' : 'ok' ),
				'priority'            => 50,
			);
			if ( $owns ) {
				$fields['in_sitemap'] = false;
			}
			$out[] = array(
				'url'    => $link,
				'fields' => $fields,
			);
		}

		if ( $this->settings->get( 'indexing.monitor_pagination' ) ) {
			$per_page    = max( 1, (int) get_option( 'posts_per_page', 10 ) );
			$counts      = wp_count_posts( 'post' );
			$noindex     = (bool) $this->settings->get( 'content.paginated_noindex' );
			$pagination  = array();
			$published   = (int) ( $counts->publish ?? 0 );
			if ( $published > $per_page ) {
				if ( ! $front_page ) {
					$pagination[] = array( home_url( '/' ), 'home', 0 );
				} elseif ( $posts_page && get_post( $posts_page ) ) {
					$pagination[] = array( (string) get_permalink( $posts_page ), 'blog', $posts_page );
				}
			}
			$cats = get_terms(
				array(
					'taxonomy'   => 'category',
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 5,
					'hide_empty' => true,
				)
			);
			if ( is_array( $cats ) ) {
				foreach ( $cats as $cat ) {
					if ( (int) $cat->count > $per_page ) {
						$link = get_term_link( $cat );
						if ( ! is_wp_error( $link ) ) {
							$pagination[] = array( (string) $link, 'category', (int) $cat->term_id );
						}
					}
				}
			}
			foreach ( $pagination as $item ) {
				list( $base, $subtype, $object_id ) = $item;
				$fields                           = array(
					'object_type'         => 'pagination',
					'object_subtype'      => $subtype,
					'object_id'           => $object_id,
					'status'              => 'active',
					'indexable'           => ! $discouraged && ! $noindex,
					'indexability_reason' => $discouraged ? 'site_noindex' : ( $noindex ? 'paginated_noindex' : 'ok' ),
					'priority'            => 30,
				);
				if ( $owns ) {
					$fields['in_sitemap'] = false;
				}
				$out[] = array(
					'url'    => self::page_url( $base, 2 ),
					'fields' => $fields,
				);
			}
		}
		return $out;
	}

	/**
	 * URL of page N of an archive.
	 *
	 * @param string $base Archive URL.
	 * @param int    $page Page number.
	 * @return string
	 */
	public static function page_url( $base, $page ) {
		global $wp_rewrite;
		if ( '' === (string) get_option( 'permalink_structure' ) || ! $wp_rewrite ) {
			return add_query_arg( 'paged', (int) $page, $base );
		}
		$base = (string) preg_replace( '/\?.*$/', '', $base );
		return trailingslashit( $base ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . (int) $page, 'paged' );
	}

	/**
	 * URL of page 1 for a paginated URL ('' when not paginated).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function pagination_root( $url ) {
		global $wp_rewrite;
		$base = $wp_rewrite ? preg_quote( $wp_rewrite->pagination_base, '#' ) : 'page';
		if ( preg_match( '#^(.*?/)' . $base . '/\d+/?$#', (string) $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/[?&]paged=\d+/', (string) $url ) ) {
			return remove_query_arg( 'paged', (string) $url );
		}
		return '';
	}

	/**
	 * Registers an object by type.
	 *
	 * @param string $object_type post|term|author.
	 * @param int    $object_id   Id.
	 * @return int Row id.
	 */
	public function sync_object( $object_type, $object_id ) {
		switch ( $object_type ) {
			case 'post':
			case 'attachment':
				return $this->sync_post( (int) $object_id );
			case 'term':
				return $this->sync_term( (int) $object_id );
			case 'author':
				return $this->sync_author( (int) $object_id );
		}
		return 0;
	}

	/* --------------------------------------------------------------------- *
	 * Real-time changes
	 * --------------------------------------------------------------------- */

	/**
	 * Keeps the registry current after a URL event (the submission module's
	 * change detector already upserted the core fields; this adds sitemap
	 * membership, priority and the expected canonical and asks for an early re-check).
	 *
	 * @param array $change searchpilot_url_changed payload.
	 * @return int Row id of the current URL (0 when removed).
	 */
	public function handle_change( array $change ) {
		$event       = sanitize_key( (string) ( $change['event'] ?? '' ) );
		$object_type = sanitize_key( (string) ( $change['object_type'] ?? '' ) );
		$object_id   = (int) ( $change['object_id'] ?? 0 );
		$url         = (string) ( $change['url'] ?? '' );
		$old_url     = (string) ( $change['old_url'] ?? '' );

		if ( in_array( $event, array( 'delete', 'unpublish', 'term_delete' ), true ) ) {
			if ( $object_id && in_array( $object_type, array( 'post', 'attachment', 'term', 'author' ), true ) ) {
				// The change detector may already have marked the rows removed: resolve the
				// object's issues in any case (rows that are already removed are kept as they are).
				$this->forget_object( $object_type, $object_id, ! $this->object_exists( $object_type, $object_id ) );
			} elseif ( '' !== $url ) {
				$row = $this->urls->get_by_url( $url );
				if ( $row ) {
					$this->remove_row( $row );
				}
			}
			return 0;
		}

		$id = $object_id ? $this->sync_object( $object_type, $object_id ) : 0;
		if ( ! $id && '' !== $url ) {
			$row = $this->urls->get_by_url( $url );
			$id  = $row ? (int) $row['id'] : 0;
		}
		if ( $id ) {
			Crawler::request_recheck( $id );
		}
		if ( '' !== $old_url && '' !== $url && Url::normalize( $old_url ) !== Url::normalize( $url ) ) {
			$old = $this->urls->get_by_url( $old_url );
			if ( $old && 'active' === $old['status'] && (int) $old['id'] !== $id ) {
				$this->remove_row( $old );
			}
		}
		return $id;
	}

	/* --------------------------------------------------------------------- *
	 * Reconciliation (weekly / after settings changes)
	 * --------------------------------------------------------------------- */

	/**
	 * Starts a reconciliation pass.
	 *
	 * @param string $reason weekly|settings|manual.
	 * @return array
	 */
	public function start_reconcile( $reason = 'weekly' ) {
		$r = array(
			'status'      => 'running',
			'reason'      => sanitize_key( $reason ),
			'cursor'      => 0,
			'removed'     => 0,
			'refreshed'   => 0,
			'started_at'  => gmdate( 'c' ),
			'finished_at' => null,
		);
		$this->state->set( self::RECONCILE, $r );
		return $r;
	}

	/**
	 * Whether a reconciliation is running.
	 *
	 * @return bool
	 */
	public function is_reconciling() {
		$r = $this->state->get( self::RECONCILE );
		return is_array( $r ) && 'running' === ( $r['status'] ?? '' );
	}

	/**
	 * Runs reconciliation batches: rows whose object no longer exists or is
	 * unpublished become `removed`; others are refreshed (indexability,
	 * sitemap membership, priority).
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array Progress.
	 */
	public function run_reconcile( TimeBudget $budget ) {
		$r = $this->state->get( self::RECONCILE );
		if ( ! is_array( $r ) || 'running' !== ( $r['status'] ?? '' ) ) {
			return is_array( $r ) ? $r : array( 'status' => 'idle' );
		}
		global $wpdb;
		$table = Tables::urls();
		while ( ! $budget->exhausted() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND id > %d ORDER BY id ASC LIMIT %d", (int) $r['cursor'], self::BATCH ), ARRAY_A );
			if ( ! $rows ) {
				$this->sync_special();
				$r['status']      = 'done';
				$r['finished_at'] = gmdate( 'c' );
				$this->logger->info( 'indexer', 'URL registry reconciled.', array( 'removed' => $r['removed'], 'refreshed' => $r['refreshed'] ) );
				break;
			}
			$post_ids = array();
			foreach ( $rows as $row ) {
				if ( in_array( $row['object_type'], array( 'post', 'attachment' ), true ) ) {
					$post_ids[] = (int) $row['object_id'];
				}
			}
			if ( $post_ids ) {
				_prime_post_caches( $post_ids, false, true );
			}
			foreach ( $rows as $row ) {
				$r['cursor'] = (int) $row['id'];
				$result      = $this->reconcile_row( $this->urls->hydrate( $row ) );
				if ( 'removed' === $result ) {
					++$r['removed'];
				} elseif ( 'refreshed' === $result ) {
					++$r['refreshed'];
				}
				if ( $budget->exhausted() ) {
					break;
				}
			}
			$this->state->set( self::RECONCILE, $r );
		}
		$this->state->set( self::RECONCILE, $r );
		return $r;
	}

	/**
	 * Reconciles one row.
	 *
	 * @param array $row Hydrated row.
	 * @return string removed|refreshed|kept
	 */
	private function reconcile_row( array $row ) {
		$type = (string) $row['object_type'];
		$id   = (int) $row['object_id'];
		switch ( $type ) {
			case 'post':
			case 'attachment':
				$post  = $id ? get_post( $id ) : null;
				$valid = $post && ( 'attachment' === $post->post_type
					? 'inherit' === $post->post_status && $this->attachment_pages_live()
					: 'publish' === $post->post_status && isset( $this->post_types()[ $post->post_type ] ) );
				if ( ! $valid ) {
					$this->remove_row( $row );
					return 'removed';
				}
				$current = (string) get_permalink( $post );
				$new_id  = $this->sync_post( $post );
				if ( Url::normalize( $current ) !== (string) $row['url'] && $new_id !== (int) $row['id'] ) {
					$this->remove_row( $row );
					return 'removed';
				}
				$this->redetect_if_changed( $row );
				return 'refreshed';
			case 'term':
				$term  = $id ? get_term( $id ) : null;
				$valid = $term && ! is_wp_error( $term ) && isset( $this->types->taxonomies()[ $term->taxonomy ] ) && (int) $term->count > 0;
				if ( ! $valid ) {
					$this->remove_row( $row );
					return 'removed';
				}
				$new_id = $this->sync_term( $term );
				if ( $new_id !== (int) $row['id'] ) {
					$this->remove_row( $row );
					return 'removed';
				}
				$this->redetect_if_changed( $row );
				return 'refreshed';
			case 'author':
				$user  = $id ? get_userdata( $id ) : null;
				$valid = $user && count_user_posts( $id, $this->types->indexable_post_types(), true ) > 0;
				if ( ! $valid ) {
					$this->remove_row( $row );
					return 'removed';
				}
				$new_id = $this->sync_author( $id );
				if ( $new_id !== (int) $row['id'] ) {
					$this->remove_row( $row );
					return 'removed';
				}
				return 'refreshed';
		}
		return 'kept';
	}

	/**
	 * Re-evaluates a row's issues from its stored crawl data when its indexability
	 * changed (e.g. an SEO plugin setting now makes the page noindex by design),
	 * so outdated issues close without waiting for the next crawl.
	 *
	 * @param array $before Hydrated row before the refresh.
	 */
	private function redetect_if_changed( array $before ) {
		$after = $this->urls->get( (int) $before['id'] );
		if ( ! $after ) {
			return;
		}
		if ( (int) $after['indexable'] === (int) $before['indexable'] && (string) $after['indexability_reason'] === (string) $before['indexability_reason'] ) {
			return;
		}
		$this->detector->detect( $after );
	}

	/**
	 * Marks a row removed and resolves its URL issues.
	 *
	 * @param array $row Hydrated row.
	 */
	public function remove_row( array $row ) {
		$this->urls->update(
			(int) $row['id'],
			array(
				'status'     => 'removed',
				'in_sitemap' => false,
			)
		);
		$row['status'] = 'removed';
		$this->detector->resolve_all( $row );
	}

	/* --------------------------------------------------------------------- *
	 * Hygiene: deleted, trashed and unpublished objects
	 * --------------------------------------------------------------------- */

	/**
	 * Forgets a WordPress object that was deleted, trashed or unpublished: its
	 * registry rows leave every count and all its open issues are resolved —
	 * except those about the vanished URL itself (KEEP_ON_REMOVAL), which other
	 * modules raise because the page went away.
	 *
	 * Rows of permanently deleted terms and users are deleted, as are rows of
	 * deleted posts that search engines never knew. Rows of other posts stay as
	 * `removed` (excluded from every count, pruned after 180 days): the redirect
	 * manager and the Indexing API removal notice need to know that search
	 * engines had the URL. Trashed and unpublished posts keep their rows as
	 * `removed`, so their history returns if they are published again.
	 *
	 * @param string $object_type post|attachment|term|author.
	 * @param int    $object_id   Object id.
	 * @param bool   $deleted     Permanently deleted (not only trashed/unpublished).
	 * @return array{rows:int,issues:int} Rows removed or deleted, issues resolved.
	 */
	public function forget_object( $object_type, $object_id, $deleted = false ) {
		$object_type = (string) $object_type;
		$object_id   = (int) $object_id;
		$out         = array(
			'rows'   => 0,
			'issues' => 0,
		);
		if ( $object_id <= 0 || ! in_array( $object_type, array( 'post', 'attachment', 'term', 'author' ), true ) ) {
			return $out;
		}
		$delete = array();
		foreach ( $this->urls->all_for_object( $object_type, $object_id ) as $row ) {
			if ( $deleted && ( in_array( $object_type, array( 'term', 'author' ), true ) || ! self::known_to_search_engines( $row ) ) ) {
				$delete[] = (int) $row['id'];
				continue;
			}
			if ( 'active' === $row['status'] ) {
				// Only status and sitemap flag: the change detector still reads the row's
				// indexability to decide whether search engines must hear about the removal.
				$this->urls->update(
					(int) $row['id'],
					array(
						'status'     => 'removed',
						'in_sitemap' => false,
					)
				);
				++$out['rows'];
			}
		}
		if ( $delete ) {
			$out['rows'] += $this->urls->delete_rows( $delete );
		}
		$out['issues'] = $this->resolve_object_issues( $object_type, $object_id );
		return $out;
	}

	/**
	 * Resolves every open issue of an object except the KEEP_ON_REMOVAL types.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return int Issues resolved.
	 */
	public function resolve_object_issues( $object_type, $object_id ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$open = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND status IN ('open','pending_approval') LIMIT 1", (string) $object_type, (int) $object_id ) );
		if ( ! $open ) {
			return 0;
		}
		$types = array_values( array_diff( array_keys( (array) $this->issue_types->all() ), self::KEEP_ON_REMOVAL ) );
		if ( ! $types ) {
			return 0;
		}
		return (int) $this->issues->resolve_stale_for_object( (string) $object_type, (int) $object_id, $types, array() );
	}

	/**
	 * Whether search engines know a registry URL (submitted, sent to an
	 * engine, or seen by Google's URL Inspection).
	 *
	 * @param array $row Hydrated row.
	 * @return bool
	 */
	public static function known_to_search_engines( array $row ) {
		if ( ! empty( $row['last_submitted'] ) || ! empty( $row['engines'] ) ) {
			return true;
		}
		return ! in_array( (string) ( $row['index_status'] ?? 'unknown' ), array( '', 'unknown', 'unknown_to_google', 'removed' ), true );
	}

	/**
	 * Whether a WordPress object still exists.
	 *
	 * @param string $object_type post|attachment|term|author.
	 * @param int    $object_id   Id.
	 * @return bool
	 */
	private function object_exists( $object_type, $object_id ) {
		switch ( $object_type ) {
			case 'post':
			case 'attachment':
				return null !== get_post( (int) $object_id );
			case 'term':
				$term = get_term( (int) $object_id );
				return $term && ! is_wp_error( $term );
			case 'author':
				return false !== get_userdata( (int) $object_id );
		}
		return false;
	}

	/**
	 * Asks for a one-off cleanup of rows and issues whose object no longer
	 * exists (processed in the background by the every-5-minutes job). Cheap
	 * and safe to call from a database migration.
	 */
	public static function request_cleanup() {
		update_option( self::CLEANUP_OPTION, time(), false );
	}

	/**
	 * Whether a cleanup was requested and has not finished yet.
	 *
	 * @return bool
	 */
	public static function cleanup_requested() {
		return (bool) get_option( self::CLEANUP_OPTION, false );
	}

	/**
	 * Removes registry rows and resolves open issues of objects that are gone
	 * (deleted posts, terms and users; trashed or unpublished posts; empty term
	 * archives), in batches until the budget is used. Rows that were already
	 * handled no longer match, so the pass needs no cursor and resumes by
	 * itself; the request flag is cleared once nothing is left.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array{rows:int,issues:int,done:bool}
	 */
	public function cleanup_orphans( TimeBudget $budget ) {
		global $wpdb;
		$urls    = Tables::urls();
		$issues  = Tables::issues();
		// Only types this pass can resolve (registered, not kept): every match makes progress.
		$types   = array_values( array_diff( array_keys( (array) $this->issue_types->all() ), self::KEEP_ON_REMOVAL ) );
		$holders = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );
		$types   = $types ? $types : array( '' );
		$stats   = array(
			'rows'   => 0,
			'issues' => 0,
			'done'   => false,
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$queries = array(
			// Active rows of posts that were deleted, trashed or unpublished.
			array(
				'sql'    => "SELECT DISTINCT u.object_type, u.object_id, ( p.ID IS NULL ) AS gone FROM {$urls} u LEFT JOIN {$wpdb->posts} p ON p.ID = u.object_id
					WHERE u.status = 'active' AND u.object_id > 0 AND (
						( u.object_type = 'post' AND ( p.ID IS NULL OR p.post_status <> 'publish' ) )
						OR ( u.object_type = 'attachment' AND ( p.ID IS NULL OR p.post_status <> 'inherit' ) )
					) LIMIT 200",
				'params' => array(),
			),
			// Rows (active or removed) of deleted terms; active rows of empty term archives.
			array(
				'sql'    => "SELECT DISTINCT u.object_type, u.object_id, ( tt.term_taxonomy_id IS NULL ) AS gone FROM {$urls} u LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = u.object_id AND tt.taxonomy = u.object_subtype
					WHERE u.object_type = 'term' AND u.object_id > 0 AND ( tt.term_taxonomy_id IS NULL OR ( u.status = 'active' AND tt.count < 1 ) ) LIMIT 200",
				'params' => array(),
			),
			// Rows of deleted users.
			array(
				'sql'    => "SELECT DISTINCT u.object_type, u.object_id, 1 AS gone FROM {$urls} u LEFT JOIN {$wpdb->users} us ON us.ID = u.object_id
					WHERE u.object_type = 'author' AND u.object_id > 0 AND us.ID IS NULL LIMIT 200",
				'params' => array(),
			),
			// Open issues of posts that are gone or no longer published.
			array(
				'sql'    => "SELECT DISTINCT i.object_type, i.object_id, ( p.ID IS NULL ) AS gone FROM {$issues} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.object_id
					WHERE i.status IN ('open','pending_approval') AND i.object_type IN ('post','attachment') AND i.object_id > 0 AND i.type IN ({$holders})
					AND ( p.ID IS NULL OR p.post_status NOT IN ('publish','inherit') ) LIMIT 200",
				'params' => $types,
			),
			// Open issues of deleted terms.
			array(
				'sql'    => "SELECT DISTINCT i.object_type, i.object_id, 1 AS gone FROM {$issues} i LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = i.object_id
					WHERE i.status IN ('open','pending_approval') AND i.object_type = 'term' AND i.object_id > 0 AND i.type IN ({$holders}) AND tt.term_taxonomy_id IS NULL LIMIT 200",
				'params' => $types,
			),
			// Open issues of deleted users.
			array(
				'sql'    => "SELECT DISTINCT i.object_type, i.object_id, 1 AS gone FROM {$issues} i LEFT JOIN {$wpdb->users} us ON us.ID = i.object_id
					WHERE i.status IN ('open','pending_approval') AND i.object_type = 'author' AND i.object_id > 0 AND i.type IN ({$holders}) AND us.ID IS NULL LIMIT 200",
				'params' => $types,
			),
		);
		foreach ( $queries as $query ) {
			$seen = array();
			while ( true ) {
				if ( $budget->exhausted( 3 ) ) {
					// phpcs:enable
					$this->record_cleanup( $stats );
					return $stats;
				}
				$rows = $query['params'] ? $wpdb->get_results( $wpdb->prepare( $query['sql'], $query['params'] ), ARRAY_A ) : $wpdb->get_results( $query['sql'], ARRAY_A );
				$new  = 0;
				foreach ( (array) $rows as $row ) {
					$key = $row['object_type'] . ':' . (int) $row['object_id'];
					if ( isset( $seen[ $key ] ) ) {
						continue; // Already handled in this pass (e.g. only KEEP types left): never loop.
					}
					$seen[ $key ] = true;
					++$new;
					$result           = $this->forget_object( (string) $row['object_type'], (int) $row['object_id'], ! empty( $row['gone'] ) );
					$stats['rows']   += $result['rows'];
					$stats['issues'] += $result['issues'];
				}
				if ( ! $new || count( (array) $rows ) < 200 ) {
					break;
				}
			}
		}
		// phpcs:enable
		$stats['done'] = true;
		delete_option( self::CLEANUP_OPTION );
		$this->record_cleanup( $stats );
		if ( $stats['rows'] || $stats['issues'] ) {
			$this->logger->info( 'indexer', sprintf( 'Registry cleanup: %d URLs of deleted or unpublished content removed, %d issues resolved.', $stats['rows'], $stats['issues'] ), $stats );
		}
		return $stats;
	}

	/**
	 * Adds a cleanup slice to the stored totals.
	 *
	 * @param array $stats Slice result.
	 */
	private function record_cleanup( array $stats ) {
		$last = $this->state->get( self::CLEANUP_STATE );
		$last = is_array( $last ) && empty( $last['done'] ) ? $last : array(
			'rows'       => 0,
			'issues'     => 0,
			'started_at' => gmdate( 'c' ),
		);
		$last['rows']       = (int) $last['rows'] + (int) $stats['rows'];
		$last['issues']     = (int) $last['issues'] + (int) $stats['issues'];
		$last['done']       = ! empty( $stats['done'] );
		$last['updated_at'] = gmdate( 'c' );
		$this->state->set( self::CLEANUP_STATE, $last );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Whether WordPress attachment pages are publicly served.
	 *
	 * @return bool
	 */
	public function attachment_pages_live() {
		if ( isset( $this->memo['attachment_pages'] ) ) {
			return $this->memo['attachment_pages'];
		}
		$missing = new \stdClass();
		$option  = get_option( 'wp_attachment_pages_enabled', $missing );
		// WordPress < 6.4 has no option: attachment pages always exist.
		$enabled = $option === $missing ? true : (bool) (int) $option;
		if ( $enabled && $this->settings->get( 'content.attachment_redirect' ) && class_exists( '\SearchPilot\Redirects\RedirectsModule' ) && $this->conflicts->owns( 'redirects' ) ) {
			$enabled = false; // SearchPilot redirects attachment pages to the file.
		}
		$this->memo['attachment_pages'] = $enabled;
		return $enabled;
	}

	/**
	 * in_sitemap value to write: computed for SearchPilot's own sitemap, false
	 * when sitemaps are off, null (leave alone) when another plugin owns it —
	 * the sitemap module records the owner's real contents then.
	 *
	 * @param bool $computed Membership computed from content settings.
	 * @return bool|null
	 */
	private function sitemap_membership( $computed ) {
		if ( ! isset( $this->memo['sitemap_owner'] ) ) {
			$this->memo['sitemap_owner'] = $this->conflicts->owner( 'sitemap' );
			$this->memo['sitemap_owns']  = $this->conflicts->owns( 'sitemap' );
		}
		if ( $this->memo['sitemap_owns'] ) {
			return (bool) $computed;
		}
		if ( 'off' === $this->memo['sitemap_owner'] ) {
			return false;
		}
		return null;
	}

	/**
	 * Crawl priority of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return int
	 */
	private function post_priority( \WP_Post $post ) {
		if ( 'attachment' === $post->post_type ) {
			return 20;
		}
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			return 100;
		}
		if ( $this->meta->get_post_field( $post->ID, 'cornerstone' ) ) {
			return 90;
		}
		if ( 'page' === $post->post_type ) {
			return 70;
		}
		$published = strtotime( $post->post_date_gmt . ' UTC' );
		if ( $published && ( time() - $published ) < 30 * DAY_IN_SECONDS ) {
			return 60;
		}
		return 50;
	}

	/**
	 * Canonical the SEO settings of a post ask for.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Permalink.
	 * @return string
	 */
	private function expected_post_canonical( \WP_Post $post, $url ) {
		if ( 'attachment' === $post->post_type ) {
			return $url;
		}
		$custom = $this->meta->canonical( $post->ID );
		return '' !== $custom ? $custom : $url;
	}

	/**
	 * Stores the expected canonical until the crawler records the real one.
	 *
	 * @param int    $id        Row id.
	 * @param string $canonical Canonical.
	 */
	private function set_expected_canonical( $id, $canonical ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET canonical_url = %s WHERE id = %d AND last_checked IS NULL", Url::normalize( $canonical ), (int) $id ) );
	}

	/**
	 * Configured public post types (memoized).
	 *
	 * @return array
	 */
	private function post_types() {
		if ( ! isset( $this->memo['post_types'] ) ) {
			$this->memo['post_types'] = $this->types->post_types();
		}
		return $this->memo['post_types'];
	}
}

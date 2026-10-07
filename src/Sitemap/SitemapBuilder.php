<?php
/**
 * Sitemap data providers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Redirects\RedirectMatcher;
use SearchPilot\Redirects\RedirectRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the entries of every child sitemap and renders the documents.
 *
 * Child sitemaps: pt-{post_type}, tax-{taxonomy}, authors, video (paged) and
 * news (single file). Pages are cut by keyset (ID ranges) so a page is
 * cheap to rebuild: the per-type "summary" (page boundaries, counts,
 * lastmod) is computed with incremental ID-ordered queries and cached under
 * the type's version. Each page primes post/meta caches in bulk and filters
 * candidates through ContentTypes, so the sitemap, the submission queue and
 * the audits always agree.
 */
class SitemapBuilder {

	/**
	 * Post meta holding the time of the last significant change (set by SitemapModule
	 * from the change detector); trivial saves do not move <lastmod>.
	 */
	const LASTMOD_META = '_searchpilot_lastmod';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * Cache.
	 *
	 * @var SitemapCache
	 */
	private $cache;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepository
	 */
	private $redirects;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Memoized type list.
	 *
	 * @var array|null
	 */
	private $type_list = null;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param ContentTypes       $types     Content types.
	 * @param MetaStore          $meta      Meta store.
	 * @param SitemapCache       $cache     Cache.
	 * @param ConflictDetector   $conflicts Conflict detector.
	 * @param RedirectRepository $redirects Redirects.
	 * @param Logger             $logger    Logger.
	 */
	public function __construct( Settings $settings, ContentTypes $types, MetaStore $meta, SitemapCache $cache, ConflictDetector $conflicts, RedirectRepository $redirects, Logger $logger ) {
		$this->settings  = $settings;
		$this->types     = $types;
		$this->meta      = $meta;
		$this->cache     = $cache;
		$this->conflicts = $conflicts;
		$this->redirects = $redirects;
		$this->logger    = $logger;
	}

	/**
	 * Forgets memoized configuration (after settings changes).
	 */
	public function reset() {
		$this->type_list = null;
	}

	/**
	 * URLs per file.
	 *
	 * @return int
	 */
	public function max_urls() {
		return max( 100, min( SitemapXml::MAX_URLS, (int) $this->settings->get( 'sitemap.max_urls', 1000 ) ) );
	}

	/**
	 * Child sitemap types that are switched on.
	 *
	 * @return array<string,array{kind:string,name:string,label:string}>
	 */
	public function types() {
		if ( null !== $this->type_list ) {
			return $this->type_list;
		}
		$out = array();
		foreach ( $this->types->post_types() as $name => $conf ) {
			if ( ! empty( $conf['sitemap'] ) ) {
				$out[ 'pt-' . $name ] = array(
					'kind'  => 'pt',
					'name'  => $name,
					'label' => (string) $conf['label'],
				);
			}
		}
		foreach ( $this->types->taxonomies() as $name => $conf ) {
			if ( ! empty( $conf['sitemap'] ) ) {
				$out[ 'tax-' . $name ] = array(
					'kind'  => 'tax',
					'name'  => $name,
					'label' => (string) $conf['label'],
				);
			}
		}
		if ( $this->settings->get( 'content.authors_sitemap', true ) && $this->settings->get( 'content.authors_index', true ) ) {
			$out['authors'] = array(
				'kind'  => 'authors',
				'name'  => 'authors',
				'label' => __( 'Authors', 'searchpilot-pro' ),
			);
		}
		if ( $this->settings->get( 'sitemap.video', true ) ) {
			$out['video'] = array(
				'kind'  => 'video',
				'name'  => 'video',
				'label' => __( 'Videos', 'searchpilot-pro' ),
			);
		}
		if ( $this->news_enabled() ) {
			$out['news'] = array(
				'kind'  => 'news',
				'name'  => 'news',
				'label' => __( 'Google News', 'searchpilot-pro' ),
			);
		}
		$this->type_list = $out;
		return $out;
	}

	/**
	 * Whether SearchPilot generates the Google News sitemap.
	 *
	 * @return bool
	 */
	public function news_enabled() {
		return (bool) $this->settings->get( 'sitemap.news', false ) && $this->conflicts->owns( 'news_sitemap' );
	}

	/**
	 * Sitemap group a post type change affects.
	 *
	 * @param string $type Type id.
	 * @return bool
	 */
	public function is_type( $type ) {
		return isset( $this->types()[ (string) $type ] );
	}

	/* ------------------------------------------------------------------ *
	 * Summaries (page boundaries)
	 * ------------------------------------------------------------------ */

	/**
	 * Page boundaries of a type (cached under the type version).
	 *
	 * @param string $type Type id.
	 * @return array{pages:array<int,array{page:int,after:int,last:int,count:int,lastmod:string}>,total:int}
	 */
	public function summary( $type ) {
		$empty = array(
			'pages' => array(),
			'total' => 0,
		);
		if ( ! $this->is_type( $type ) ) {
			return $empty;
		}
		$cached = $this->cache->get( 'summary:' . $type );
		if ( is_array( $cached ) && isset( $cached['pages'] ) ) {
			return $cached;
		}
		$summary = $this->build_summary( $type );
		$this->cache->set( 'summary:' . $type, 1, $summary );
		return $summary;
	}

	/**
	 * The cached summary of a type, without building one.
	 *
	 * @param string $type Type id.
	 * @return array|null
	 */
	public function cached_summary( $type ) {
		$cached = $this->cache->get( 'summary:' . $type );
		return is_array( $cached ) && isset( $cached['pages'] ) ? $cached : null;
	}

	/**
	 * Computes page boundaries (not cached). With a previous summary and the
	 * ids that changed, expensive per-page lastmod queries are reused for
	 * pages whose boundaries and members did not change.
	 *
	 * @param string     $type    Type id.
	 * @param array|null $old     Previous summary.
	 * @param int[]      $changed Changed object ids.
	 * @return array
	 */
	public function build_summary( $type, $old = null, array $changed = array() ) {
		$empty = array(
			'pages' => array(),
			'total' => 0,
		);
		if ( ! $this->is_type( $type ) ) {
			return $empty;
		}
		$def = $this->types()[ $type ];
		switch ( $def['kind'] ) {
			case 'pt':
				$summary = $this->summarize_posts( $def['name'] );
				break;
			case 'tax':
				$summary = $this->summarize_terms( $def['name'], $old, $changed );
				break;
			case 'authors':
				$summary = $this->summarize_authors();
				break;
			case 'video':
				$summary = $this->summarize_videos();
				break;
			case 'news':
				$summary = $this->summarize_news();
				break;
			default:
				$summary = $empty;
		}
		return $summary;
	}

	/**
	 * Page of a type that holds an object id (from the cached summary).
	 *
	 * @param string $type Type id.
	 * @param int    $id   Object id.
	 * @return int 0 when unknown.
	 */
	public function page_for( $type, $id ) {
		$summary = $this->cached_summary( $type );
		if ( ! $summary ) {
			return 0;
		}
		foreach ( $summary['pages'] as $page ) {
			if ( (int) $id > (int) $page['after'] && (int) $id <= (int) $page['last'] ) {
				return (int) $page['page'];
			}
		}
		return 0;
	}

	/**
	 * Non-structural change: raises the lastmod of the page holding an id in
	 * the cached summary (boundaries are unchanged, so it stays valid).
	 *
	 * @param string $type    Type id.
	 * @param int    $id      Object id.
	 * @param string $lastmod New lastmod (MySQL GMT) or ''.
	 * @return int Page number, 0 when the id is not in a cached page.
	 */
	public function patch_summary( $type, $id, $lastmod = '' ) {
		$summary = $this->cached_summary( $type );
		if ( ! $summary ) {
			return 0;
		}
		foreach ( $summary['pages'] as $i => $page ) {
			if ( (int) $id > (int) $page['after'] && (int) $id <= (int) $page['last'] ) {
				if ( '' !== (string) $lastmod && (string) $lastmod > (string) $page['lastmod'] ) {
					$summary['pages'][ $i ]['lastmod'] = (string) $lastmod;
					$this->cache->set( 'summary:' . $type, 1, $summary );
				}
				return (int) $page['page'];
			}
		}
		return 0;
	}

	/**
	 * Structural change (objects added/removed): new boundaries plus the pages
	 * whose content changed. The caller bumps them, flushes and stores the summary.
	 *
	 * @param string $type    Type id.
	 * @param int[]  $changed Changed object ids.
	 * @return array{summary:array,pages:int[],had_old:bool}
	 */
	public function recompute( $type, array $changed ) {
		$old     = $this->cached_summary( $type );
		$summary = $this->build_summary( $type, $old, $changed );
		$pages   = array();
		$by_num  = array();
		foreach ( (array) ( $old['pages'] ?? array() ) as $page ) {
			$by_num[ (int) $page['page'] ] = $page;
		}
		foreach ( $summary['pages'] as $page ) {
			$num  = (int) $page['page'];
			$prev = $by_num[ $num ] ?? null;
			if ( ! $prev || (int) $prev['after'] !== (int) $page['after'] || (int) $prev['last'] !== (int) $page['last'] || (int) $prev['count'] !== (int) $page['count'] ) {
				$pages[ $num ] = true;
				continue;
			}
			foreach ( $changed as $id ) {
				if ( (int) $id > (int) $page['after'] && (int) $id <= (int) $page['last'] ) {
					$pages[ $num ] = true;
				}
			}
		}
		return array(
			'summary' => $summary,
			'pages'   => array_keys( $pages ),
			'had_old' => null !== $old,
		);
	}

	/**
	 * Walks ID-ordered rows in page-sized chunks.
	 *
	 * @param callable $fetch   fn( int $after, int $limit ) : object[] rows with ->id and ->m (lastmod).
	 * @param int      $reserve Entries reserved on page 1 (home page, archives).
	 * @param string   $extra_lastmod Lastmod of reserved entries.
	 * @return array
	 */
	private function walk( callable $fetch, $reserve = 0, $extra_lastmod = '' ) {
		$max   = $this->max_urls();
		$pages = array();
		$after = 0;
		$page  = 1;
		$total = 0;
		while ( $page <= 1000 ) {
			$limit = 1 === $page ? max( 1, $max - $reserve ) : $max;
			$rows  = (array) call_user_func( $fetch, $after, $limit );
			if ( empty( $rows ) ) {
				break;
			}
			$last    = $after;
			$lastmod = '';
			foreach ( $rows as $row ) {
				$last = max( $last, (int) $row->id );
				if ( (string) $row->m > $lastmod ) {
					$lastmod = (string) $row->m;
				}
			}
			$pages[] = array(
				'page'    => $page,
				'after'   => $after,
				'last'    => $last,
				'count'   => count( $rows ),
				'lastmod' => $lastmod,
			);
			$total  += count( $rows );
			if ( count( $rows ) < $limit ) {
				break;
			}
			$after = $last;
			++$page;
		}
		if ( $reserve > 0 ) {
			if ( empty( $pages ) ) {
				$pages[] = array(
					'page'    => 1,
					'after'   => 0,
					'last'    => 0,
					'count'   => 0,
					'lastmod' => '',
				);
			}
			$pages[0]['count'] += $reserve;
			$total             += $reserve;
			if ( $extra_lastmod > $pages[0]['lastmod'] ) {
				$pages[0]['lastmod'] = $extra_lastmod;
			}
		}
		return array(
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * SQL fragment excluding configured post ids.
	 *
	 * @param int[] $more Additional ids.
	 * @return string
	 */
	private function excluded_sql( array $more = array(), $column = 'ID' ) {
		$ids = array_filter( array_map( 'absint', array_merge( (array) $this->settings->get( 'content.excluded_ids', array() ), $more ) ) );
		return $ids ? ' AND ' . $column . ' NOT IN (' . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * LEFT JOIN bringing the significant-change time of posts aliased $alias.
	 *
	 * @param string $alias Posts table alias.
	 * @return string
	 */
	private static function lastmod_join( $alias = 'p' ) {
		global $wpdb;
		return " LEFT JOIN {$wpdb->postmeta} lm ON lm.post_id = {$alias}.ID AND lm.meta_key = '" . self::LASTMOD_META . "'";
	}

	/**
	 * SQL expression of a post's sitemap lastmod (significant change, else modified date).
	 *
	 * @param string $alias Posts table alias.
	 * @return string
	 */
	private static function lastmod_expr( $alias = 'p' ) {
		return "COALESCE(NULLIF(lm.meta_value, ''), {$alias}.post_modified_gmt)";
	}

	/**
	 * Post ids that are shown as another entry (static front page).
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	private function special_ids( $post_type ) {
		if ( 'page' === $post_type && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
			return array( (int) get_option( 'page_on_front' ) );
		}
		return array();
	}

	/**
	 * Extra entries placed first on page 1 of a post type sitemap: the home
	 * page and post type archives.
	 *
	 * @param string $post_type Post type.
	 * @return array[] Entries.
	 */
	private function extras( $post_type ) {
		global $wpdb;
		$extras     = array();
		$post_types = array_map(
			static function ( $t ) {
				return substr( $t, 3 );
			},
			array_keys(
				array_filter(
					$this->types(),
					static function ( $d ) {
						return 'pt' === $d['kind'];
					}
				)
			)
		);
		$home_type = '';
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
			$home_type = in_array( 'page', $post_types, true ) ? 'page' : '';
		} elseif ( in_array( 'post', $post_types, true ) ) {
			$home_type = 'post';
		} elseif ( $post_types ) {
			$home_type = in_array( 'page', $post_types, true ) ? 'page' : $post_types[0];
		}

		if ( $post_type === $home_type ) {
			$front = (int) get_option( 'page_on_front' );
			if ( 'page' === get_option( 'show_on_front' ) && $front ) {
				$front_post = get_post( $front );
				if ( $front_post && $this->types->post_in_sitemap( $front_post ) ) {
					$extras[] = array(
						'loc'     => home_url( '/' ),
						'lastmod' => $this->post_lastmod( $front_post ),
						'images'  => $this->images_for( array( $front_post ) )[ $front_post->ID ] ?? array(),
						'_object' => array( 'home', $front_post ),
					);
				}
			} else {
				$newest   = $this->newest_lastmod( 'post' );
				$extras[] = array(
					'loc'     => home_url( '/' ),
					'lastmod' => $newest,
					'images'  => array(),
					'_object' => array( 'home', null ),
				);
			}
		}

		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			$object = get_post_type_object( $post_type );
			if ( $object && ! empty( $object->has_archive ) ) {
				$link = get_post_type_archive_link( $post_type );
				if ( $link ) {
					$newest   = $this->newest_lastmod( $post_type );
					$extras[] = array(
						'loc'     => $link,
						'lastmod' => $newest,
						'images'  => array(),
						'_object' => array( 'post_type_archive', $post_type ),
					);
				}
			}
		}
		return $extras;
	}

	/**
	 * Newest sitemap lastmod of published posts of a type.
	 *
	 * @param string $post_type Post type.
	 * @return string MySQL GMT datetime or ''.
	 */
	public function newest_lastmod( $post_type ) {
		global $wpdb;
		$join = self::lastmod_join();
		$expr = self::lastmod_expr();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX({$expr}) FROM {$wpdb->posts} p{$join} WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''", $post_type ) );
	}

	/**
	 * Number of entries reserved on page 1 (without building them).
	 *
	 * @param string $post_type Post type.
	 * @return array{0:int,1:string} Count and newest lastmod.
	 */
	private function reserve( $post_type ) {
		$extras  = $this->extras( $post_type );
		$lastmod = '';
		foreach ( $extras as $extra ) {
			if ( (string) $extra['lastmod'] > $lastmod ) {
				$lastmod = (string) $extra['lastmod'];
			}
		}
		return array( count( $extras ), $lastmod );
	}

	/**
	 * Summary of a post type sitemap.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function summarize_posts( $post_type ) {
		global $wpdb;
		$exclude              = $this->excluded_sql( $this->special_ids( $post_type ), 'p.ID' );
		$join                 = self::lastmod_join();
		$expr                 = self::lastmod_expr();
		list( $reserve, $lm ) = $this->reserve( $post_type );
		return $this->walk(
			static function ( $after, $limit ) use ( $wpdb, $post_type, $exclude, $join, $expr ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				return $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, {$expr} AS m FROM {$wpdb->posts} p{$join} WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = '' AND p.ID > %d{$exclude} ORDER BY p.ID ASC LIMIT %d", $post_type, $after, $limit ) );
			},
			$reserve,
			$lm
		);
	}

	/**
	 * Summary of a taxonomy sitemap (lastmod = newest post in the page's terms).
	 *
	 * @param string     $taxonomy Taxonomy.
	 * @param array|null $old      Previous summary (lastmods of unchanged pages are reused).
	 * @param int[]      $changed  Changed term ids.
	 * @return array
	 */
	private function summarize_terms( $taxonomy, $old = null, array $changed = array() ) {
		global $wpdb;
		$previous = array();
		foreach ( (array) ( $old['pages'] ?? array() ) as $page ) {
			$previous[ (int) $page['after'] . ':' . (int) $page['last'] . ':' . (int) $page['count'] ] = (string) $page['lastmod'];
		}
		$join = self::lastmod_join();
		$expr = self::lastmod_expr();
		return $this->walk(
			static function ( $after, $limit ) use ( $wpdb, $taxonomy, $previous, $changed, $join, $expr ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, term_taxonomy_id AS tt FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count > 0 AND term_id > %d ORDER BY term_id ASC LIMIT %d", $taxonomy, $after, $limit ) );
				if ( empty( $rows ) ) {
					return array();
				}
				$last  = (int) end( $rows )->id;
				$key   = (int) $after . ':' . $last . ':' . count( $rows );
				$dirty = false;
				foreach ( $changed as $id ) {
					if ( (int) $id > (int) $after && (int) $id <= $last ) {
						$dirty = true;
					}
				}
				if ( ! $dirty && isset( $previous[ $key ] ) ) {
					$max = $previous[ $key ];
				} else {
					$tt = implode( ',', array_map( 'absint', wp_list_pluck( $rows, 'tt' ) ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$max = (string) $wpdb->get_var( "SELECT MAX({$expr}) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id{$join} WHERE tr.term_taxonomy_id IN ({$tt}) AND p.post_status = 'publish' AND p.post_password = ''" );
				}
				foreach ( $rows as $row ) {
					$row->m = '';
				}
				$rows[0]->m = $max;
				return $rows;
			}
		);
	}

	/**
	 * Summary of the author sitemap.
	 *
	 * @return array
	 */
	private function summarize_authors() {
		global $wpdb;
		$types = $this->types->indexable_post_types();
		if ( empty( $types ) ) {
			return array(
				'pages' => array(),
				'total' => 0,
			);
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$join    = self::lastmod_join();
		$expr    = self::lastmod_expr();
		return $this->walk(
			static function ( $after, $limit ) use ( $wpdb, $types, $holders, $join, $expr ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				return $wpdb->get_results( $wpdb->prepare( "SELECT p.post_author AS id, MAX({$expr}) AS m FROM {$wpdb->posts} p{$join} WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.post_password = '' AND p.post_author > %d GROUP BY p.post_author ORDER BY p.post_author ASC LIMIT %d", array_merge( $types, array( $after, $limit ) ) ) );
			}
		);
	}

	/**
	 * Summary of the video sitemap.
	 *
	 * @return array
	 */
	private function summarize_videos() {
		global $wpdb;
		$types = $this->post_type_names();
		if ( empty( $types ) ) {
			return array(
				'pages' => array(),
				'total' => 0,
			);
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$exclude = $this->excluded_sql( array(), 'p.ID' );
		$join    = self::lastmod_join();
		$expr    = self::lastmod_expr();
		return $this->walk(
			static function ( $after, $limit ) use ( $wpdb, $types, $holders, $exclude, $join, $expr ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				return $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID AS id, {$expr} AS m FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s{$join} WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.post_password = '' AND p.ID > %d{$exclude} ORDER BY p.ID ASC LIMIT %d", array_merge( array( VideoDetector::META ), $types, array( $after, $limit ) ) ) );
			}
		);
	}

	/**
	 * Summary of the news sitemap (single page).
	 *
	 * @return array
	 */
	private function summarize_news() {
		$ids   = $this->news_ids();
		$pages = array();
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
			$lastmod = '';
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post && $this->post_lastmod( $post ) > $lastmod ) {
					$lastmod = $this->post_lastmod( $post );
				}
			}
			$pages[] = array(
				'page'    => 1,
				'after'   => 0,
				'last'    => 0,
				'count'   => count( $ids ),
				'lastmod' => $lastmod,
			);
		}
		return array(
			'pages' => $pages,
			'total' => count( $ids ),
		);
	}

	/**
	 * Candidate ids for the Google News sitemap: news post types, optional
	 * categories (with children), published in the last 48 hours, newest first, max 1000.
	 *
	 * @return int[]
	 */
	private function news_ids() {
		global $wpdb;
		$types = array_values( array_intersect( array_map( 'sanitize_key', (array) $this->settings->get( 'sitemap.news_post_types', array( 'post' ) ) ), $this->post_type_names() ) );
		if ( empty( $types ) ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$since   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$join    = '';
		$cats    = array_filter( array_map( 'absint', (array) $this->settings->get( 'sitemap.news_categories', array() ) ) );
		if ( $cats ) {
			$all = $cats;
			foreach ( $cats as $cat ) {
				$children = get_term_children( $cat, 'category' );
				if ( is_array( $children ) ) {
					$all = array_merge( $all, array_map( 'absint', $children ) );
				}
			}
			$tt_ids = array();
			foreach ( array_unique( $all ) as $term_id ) {
				$term = get_term( $term_id, 'category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$tt_ids[] = (int) $term->term_taxonomy_id;
				}
			}
			if ( empty( $tt_ids ) ) {
				return array();
			}
			$join = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id IN (" . implode( ',', $tt_ids ) . ')';
		}
		$exclude = $this->excluded_sql( array(), 'p.ID' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p{$join} WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.post_password = '' AND p.post_date_gmt >= %s{$exclude} ORDER BY p.post_date_gmt DESC LIMIT 1000", array_merge( $types, array( $since ) ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Post type names included in sitemaps.
	 *
	 * @return string[]
	 */
	private function post_type_names() {
		$out = array();
		foreach ( $this->types() as $def ) {
			if ( 'pt' === $def['kind'] ) {
				$out[] = $def['name'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Index
	 * ------------------------------------------------------------------ */

	/**
	 * Every non-empty child sitemap with its lastmod and URL count (cached).
	 *
	 * @return array<int,array{type:string,label:string,page:int,loc:string,lastmod:string,urls:int}>
	 */
	public function index_entries() {
		$cached = $this->cache->get( 'index_data' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( $this->types() as $type => $def ) {
			$summary = $this->summary( $type );
			foreach ( $summary['pages'] as $page ) {
				if ( $page['count'] < 1 ) {
					continue;
				}
				$out[] = array(
					'type'    => $type,
					'label'   => $def['label'],
					'kind'    => $def['kind'],
					'page'    => (int) $page['page'],
					'loc'     => SitemapRouter::url( $type, (int) $page['page'] ),
					'lastmod' => (string) $page['lastmod'],
					'urls'    => (int) $page['count'],
				);
			}
		}
		$this->cache->set( 'index_data', 1, $out );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Entries
	 * ------------------------------------------------------------------ */

	/**
	 * Entries of one child sitemap page, or null when it does not exist.
	 *
	 * @param string $type Type id.
	 * @param int    $page Page (1-based).
	 * @return array[]|null
	 */
	public function entries( $type, $page ) {
		if ( ! $this->is_type( $type ) ) {
			return null;
		}
		$summary = $this->summary( $type );
		$window  = null;
		foreach ( $summary['pages'] as $p ) {
			if ( (int) $p['page'] === (int) $page ) {
				$window = $p;
				break;
			}
		}
		if ( null === $window ) {
			return null;
		}
		$def = $this->types()[ $type ];
		switch ( $def['kind'] ) {
			case 'pt':
				$entries = $this->post_entries( $def['name'], $window );
				break;
			case 'tax':
				$entries = $this->term_entries( $def['name'], $window );
				break;
			case 'authors':
				$entries = $this->author_entries( $window );
				break;
			case 'video':
				$entries = $this->video_entries( $window );
				break;
			case 'news':
				$entries = $this->news_entries();
				break;
			default:
				$entries = array();
		}
		return $entries;
	}

	/**
	 * Entries of a post type page.
	 *
	 * @param string $post_type Post type.
	 * @param array  $window    Page window.
	 * @return array[]
	 */
	private function post_entries( $post_type, array $window ) {
		global $wpdb;
		$entries = array();
		if ( 1 === (int) $window['page'] ) {
			foreach ( $this->extras( $post_type ) as $extra ) {
				$object = $extra['_object'];
				unset( $extra['_object'] );
				$entry = $this->filter_entry( $extra, $object[0], $object[1] );
				if ( $entry ) {
					$entries[] = $entry;
				}
			}
		}
		if ( (int) $window['last'] < 1 ) {
			return $entries;
		}
		$exclude = $this->excluded_sql( $this->special_ids( $post_type ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND ID > %d AND ID <= %d{$exclude} ORDER BY ID ASC", $post_type, (int) $window['after'], (int) $window['last'] ) ) );
		return array_merge( $entries, $this->entries_for_posts( $ids ) );
	}

	/**
	 * Sitemap entries for post ids (caches primed in bulk).
	 *
	 * @param int[] $ids     Post ids.
	 * @param bool  $images  Include images.
	 * @return array[]
	 */
	private function entries_for_posts( array $ids, $images = true ) {
		if ( empty( $ids ) ) {
			return array();
		}
		_prime_post_caches( $ids, true, true );
		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && $this->types->post_in_sitemap( $post ) ) {
				$posts[ $post->ID ] = $post;
			}
		}
		$links = array();
		foreach ( $posts as $id => $post ) {
			$link = get_permalink( $post );
			if ( $link ) {
				$links[ $id ] = $link;
			}
		}
		$redirected = $this->redirected( $links );
		$image_map  = $images && $this->settings->get( 'sitemap.images', true ) ? $this->images_for( array_values( $posts ) ) : array();
		$entries    = array();
		foreach ( $links as $id => $link ) {
			if ( isset( $redirected[ $id ] ) ) {
				continue;
			}
			$entry = $this->filter_entry(
				array(
					'loc'     => $link,
					'lastmod' => $this->post_lastmod( $posts[ $id ] ),
					'images'  => $image_map[ $id ] ?? array(),
				),
				'post',
				$posts[ $id ]
			);
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Entries of a taxonomy page.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $window   Page window.
	 * @return array[]
	 */
	private function term_entries( $taxonomy, array $window ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count > 0 AND term_id > %d AND term_id <= %d ORDER BY term_id ASC", $taxonomy, (int) $window['after'], (int) $window['last'] ) );
		if ( empty( $rows ) ) {
			return array();
		}
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'term_id' ) );
		_prime_term_caches( $ids );
		update_termmeta_cache( $ids );

		$tt_ids = implode( ',', array_map( 'absint', wp_list_pluck( $rows, 'term_taxonomy_id' ) ) );
		$join  = self::lastmod_join();
		$expr  = self::lastmod_expr();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$dates = $wpdb->get_results( "SELECT tr.term_taxonomy_id AS tt, MAX({$expr}) AS m FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id{$join} WHERE tr.term_taxonomy_id IN ({$tt_ids}) AND p.post_status = 'publish' AND p.post_password = '' GROUP BY tr.term_taxonomy_id" );
		$by_tt = array();
		foreach ( (array) $dates as $d ) {
			$by_tt[ (int) $d->tt ] = (string) $d->m;
		}

		$links = array();
		$terms = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || ! $this->types->is_term_indexable( $term ) ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) || ! $link ) {
				continue;
			}
			$canonical = trim( (string) $this->meta->get_term_field( $term->term_id, 'canonical' ) );
			if ( '' !== $canonical && Url::normalize( $canonical ) !== Url::normalize( $link ) ) {
				continue;
			}
			$links[ $term->term_id ] = $link;
			$terms[ $term->term_id ] = $term;
		}
		$redirected = $this->redirected( $links );
		$entries    = array();
		foreach ( $links as $id => $link ) {
			if ( isset( $redirected[ $id ] ) ) {
				continue;
			}
			$entry = $this->filter_entry(
				array(
					'loc'     => $link,
					'lastmod' => $by_tt[ (int) $terms[ $id ]->term_taxonomy_id ] ?? '',
				),
				'term',
				$terms[ $id ]
			);
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Entries of an author page (lastmod = the author's newest post).
	 *
	 * @param array $window Page window.
	 * @return array[]
	 */
	private function author_entries( array $window ) {
		global $wpdb;
		$types = $this->types->indexable_post_types();
		if ( empty( $types ) ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$join = self::lastmod_join();
		$expr = self::lastmod_expr();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_author AS id, MAX({$expr}) AS m FROM {$wpdb->posts} p{$join} WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.post_password = '' AND p.post_author > %d AND p.post_author <= %d GROUP BY p.post_author ORDER BY p.post_author ASC", array_merge( $types, array( (int) $window['after'], (int) $window['last'] ) ) ) );
		if ( empty( $rows ) ) {
			return array();
		}
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		cache_users( $ids );
		$links = array();
		$dates = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			if ( ! get_userdata( $id ) || ! $this->types->is_author_indexable( $id ) ) {
				continue;
			}
			$links[ $id ] = get_author_posts_url( $id );
			$dates[ $id ] = (string) $row->m;
		}
		$redirected = $this->redirected( $links );
		$entries    = array();
		foreach ( $links as $id => $link ) {
			if ( isset( $redirected[ $id ] ) ) {
				continue;
			}
			$entry = $this->filter_entry(
				array(
					'loc'     => $link,
					'lastmod' => $dates[ $id ],
				),
				'author',
				get_userdata( $id )
			);
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Entries of a video page.
	 *
	 * @param array $window Page window.
	 * @return array[]
	 */
	private function video_entries( array $window ) {
		global $wpdb;
		$types = $this->post_type_names();
		if ( empty( $types ) ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$exclude = $this->excluded_sql( array(), 'p.ID' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.post_password = '' AND p.ID > %d AND p.ID <= %d{$exclude} ORDER BY p.ID ASC", array_merge( array( VideoDetector::META ), $types, array( (int) $window['after'], (int) $window['last'] ) ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		_prime_post_caches( $ids, true, true );
		$posts = array();
		$links = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! $this->types->post_in_sitemap( $post ) ) {
				continue;
			}
			$link = get_permalink( $post );
			if ( $link ) {
				$posts[ $id ] = $post;
				$links[ $id ] = $link;
			}
		}
		$redirected = $this->redirected( $links );
		$entries    = array();
		foreach ( $links as $id => $link ) {
			if ( isset( $redirected[ $id ] ) ) {
				continue;
			}
			$videos = $this->videos_for( $posts[ $id ] );
			if ( empty( $videos ) ) {
				continue;
			}
			$entry = $this->filter_entry(
				array(
					'loc'     => $link,
					'lastmod' => $this->post_lastmod( $posts[ $id ] ),
					'videos'  => $videos,
				),
				'post',
				$posts[ $id ]
			);
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Valid video records for a post, with fallbacks applied.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	public function videos_for( \WP_Post $post ) {
		$out     = array();
		$title   = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$summary = null;
		foreach ( VideoDetector::get_videos( $post->ID ) as $video ) {
			if ( '' === trim( $video['title'] ) ) {
				$video['title'] = $title;
			}
			if ( '' === trim( $video['description'] ) ) {
				$summary              = null === $summary ? VideoDetector::post_summary( $post ) : $summary;
				$video['description'] = '' !== $summary ? $summary : $title;
			}
			// Only a known upload date is written; the article date is not the video's.
			$video['publication_date'] = $video['upload_date'];
			if ( SitemapXml::video_is_valid( $video ) ) {
				$out[] = $video;
			}
		}
		return $out;
	}

	/**
	 * Entries of the Google News sitemap.
	 *
	 * @return array[]
	 */
	private function news_entries() {
		$ids = $this->news_ids();
		if ( empty( $ids ) ) {
			return array();
		}
		_prime_post_caches( $ids, true, true );
		$name     = trim( (string) $this->settings->get( 'sitemap.news_name', '' ) );
		$name     = '' !== $name ? $name : wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		$language = $this->news_language();
		$posts    = array();
		$links    = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! $this->types->post_in_sitemap( $post ) ) {
				continue;
			}
			$link = get_permalink( $post );
			if ( $link ) {
				$posts[ $id ] = $post;
				$links[ $id ] = $link;
			}
		}
		$redirected = $this->redirected( $links );
		$images     = $this->settings->get( 'sitemap.images', true ) ? $this->images_for( array_values( $posts ) ) : array();
		$entries    = array();
		foreach ( $links as $id => $link ) {
			if ( isset( $redirected[ $id ] ) ) {
				continue;
			}
			$post  = $posts[ $id ];
			$entry = $this->filter_entry(
				array(
					'loc'     => $link,
					'lastmod' => $this->post_lastmod( $post ),
					'images'  => $images[ $id ] ?? array(),
					'news'    => array(
						'name'             => $name,
						'language'         => $language,
						'publication_date' => $post->post_date_gmt,
						'title'            => wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
					),
				),
				'post',
				$post
			);
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Effective Google News language code.
	 *
	 * @return string
	 */
	public function news_language() {
		$configured = strtolower( trim( (string) $this->settings->get( 'sitemap.news_language', '' ) ) );
		if ( '' !== $configured && preg_match( '/^[a-z]{2,3}(-[a-z]{2})?$/', $configured ) ) {
			return $configured;
		}
		return SitemapXml::news_language( get_locale() );
	}

	/**
	 * Effective Google News publication name.
	 *
	 * @return string
	 */
	public function news_name() {
		$name = trim( (string) $this->settings->get( 'sitemap.news_name', '' ) );
		return '' !== $name ? $name : wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Applies the searchpilot_sitemap_entry filter.
	 *
	 * @param array  $entry       Entry.
	 * @param string $object_type post|term|author|home|post_type_archive.
	 * @param mixed  $object      Object.
	 * @return array|null
	 */
	private function filter_entry( array $entry, $object_type, $object ) {
		/**
		 * Filters one sitemap entry. Return false to drop it.
		 *
		 * @param array  $entry       { loc, lastmod, images?, videos?, news? }.
		 * @param string $object_type post|term|author|home|post_type_archive.
		 * @param mixed  $object      WP_Post|WP_Term|WP_User|string|null.
		 */
		$entry = apply_filters( 'searchpilot_sitemap_entry', $entry, $object_type, $object );
		return is_array( $entry ) && ! empty( $entry['loc'] ) ? $entry : null;
	}

	/**
	 * lastmod of a post (modified, else published).
	 *
	 * @param \WP_Post $post Post.
	 * @return string MySQL GMT datetime.
	 */
	public function post_lastmod( \WP_Post $post ) {
		$significant = (string) get_post_meta( $post->ID, self::LASTMOD_META, true );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $significant ) && 0 !== strpos( $significant, '0000' ) ) {
			return $significant;
		}
		$modified = (string) $post->post_modified_gmt;
		if ( '' === $modified || 0 === strpos( $modified, '0000' ) ) {
			return (string) $post->post_date_gmt;
		}
		return $modified;
	}

	/**
	 * Keys (ids) of URLs that are the source of an enabled exact redirect.
	 *
	 * @param array<int,string> $links id => URL.
	 * @return array<int,bool>
	 */
	private function redirected( array $links ) {
		if ( empty( $links ) || ! $this->conflicts->owns( 'redirects' ) ) {
			return array();
		}
		$hashes = array();
		foreach ( $links as $id => $link ) {
			$hashes[ $id ] = RedirectMatcher::hash( Url::to_path( $link ) );
		}
		$found = $this->redirects->existing_sources( array_values( $hashes ) );
		if ( empty( $found ) ) {
			return array();
		}
		$out = array();
		foreach ( $hashes as $id => $hash ) {
			if ( isset( $found[ $hash ] ) ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Images per post: featured image first, then images in the content
	 * (wp-image-{id} classes, galleries and this site's uploads), max 50.
	 *
	 * @param \WP_Post[] $posts Posts.
	 * @return array<int,array<int,array{loc:string}>>
	 */
	public function images_for( array $posts ) {
		$refs    = array();
		$att_ids = array();
		foreach ( $posts as $post ) {
			$thumb             = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
			$parsed            = self::image_refs( (string) $post->post_content );
			$refs[ $post->ID ] = array(
				'thumb' => $thumb,
				'ids'   => $parsed['ids'],
				'srcs'  => $parsed['srcs'],
			);
			if ( $thumb ) {
				$att_ids[] = $thumb;
			}
			$att_ids = array_merge( $att_ids, $parsed['ids'] );
		}
		$att_ids = array_values( array_unique( array_filter( $att_ids ) ) );
		if ( $att_ids ) {
			_prime_post_caches( $att_ids, false, true );
		}
		$upload_host = (string) wp_parse_url( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ), PHP_URL_HOST );
		$out         = array();
		foreach ( $refs as $post_id => $ref ) {
			$images = array();
			$seen   = array();
			$add    = static function ( $url ) use ( &$images, &$seen ) {
				$url = (string) $url;
				if ( '' === $url || isset( $seen[ $url ] ) || count( $images ) >= 50 ) {
					return;
				}
				$seen[ $url ] = true;
				$images[]     = array( 'loc' => $url );
			};
			if ( $ref['thumb'] ) {
				$add( wp_get_attachment_url( $ref['thumb'] ) );
			}
			foreach ( $ref['ids'] as $id ) {
				if ( 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
					$add( wp_get_attachment_url( $id ) );
				}
			}
			foreach ( $ref['srcs'] as $src ) {
				$abs  = Url::absolute( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );
				$host = (string) wp_parse_url( $abs, PHP_URL_HOST );
				if ( Url::is_internal( $abs ) || ( '' !== $upload_host && strtolower( $host ) === strtolower( $upload_host ) ) ) {
					$add( $abs );
				}
			}
			$out[ $post_id ] = $images;
		}
		return $out;
	}

	/**
	 * Image references in content (pure): attachment ids from wp-image-{id}
	 * classes, image blocks and galleries; plain <img> sources otherwise.
	 *
	 * @param string $content Content.
	 * @return array{ids:int[],srcs:string[]}
	 */
	public static function image_refs( $content ) {
		$ids  = array();
		$srcs = array();
		if ( preg_match_all( '#<img\b[^>]*>#i', (string) $content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '#\bwp-image-(\d+)\b#', $tag, $m ) ) {
					$ids[] = (int) $m[1];
					continue;
				}
				if ( preg_match( '#\ssrc\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) && '' !== trim( $m[2] ) && 0 !== stripos( trim( $m[2] ), 'data:' ) ) {
					$srcs[] = trim( $m[2] );
				}
			}
		}
		if ( preg_match_all( '#<!--\s+wp:image\s+\{[^}]*"id"\s*:\s*(\d+)#', (string) $content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}
		if ( preg_match_all( '#\[gallery\b[^\]]*\bids\s*=\s*["\']?([\d,\s]+)#i', (string) $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, array_map( 'intval', preg_split( '/[\s,]+/', trim( $list ) ) ) );
			}
		}
		return array(
			'ids'  => array_values( array_unique( array_filter( $ids ) ) ),
			'srcs' => array_values( array_unique( $srcs ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Rendered document of a sitemap (cached), or null when it does not exist.
	 *
	 * @param string $type Type id or 'index'.
	 * @param int    $page Page.
	 * @return array{xml:string,generated:int,etag:string,count:int}|null
	 */
	public function render( $type, $page = 1 ) {
		$page   = max( 1, (int) $page );
		$cached = $this->cache->get( $type, $page );
		if ( is_array( $cached ) && isset( $cached['xml'] ) ) {
			return $cached;
		}
		$stylesheet = $this->settings->get( 'sitemap.stylesheet', true ) ? SitemapRouter::stylesheet_url() : '';
		// Not translated: the comment is read by people debugging XML, and health checks look for it.
		$comment = 'Generated by SearchPilot Pro on ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		if ( 'index' === $type ) {
			$children = $this->index_entries();
			$xml      = SitemapXml::index(
				array_map(
					static function ( $c ) {
						return array(
							'loc'     => $c['loc'],
							'lastmod' => $c['lastmod'],
						);
					},
					$children
				),
				$stylesheet,
				$comment
			);
			$count    = count( $children );
		} else {
			$entries = $this->entries( $type, $page );
			if ( null === $entries ) {
				return null;
			}
			$stats = null;
			$xml   = SitemapXml::urlset(
				$entries,
				array(
					'stylesheet' => $stylesheet,
					'comment'    => $comment,
					'max_images' => $this->settings->get( 'sitemap.images', true ) ? 50 : 0,
				),
				$stats
			);
			if ( ! empty( $stats['dropped'] ) ) {
				$this->logger->warning(
					'sitemap',
					sprintf( 'Sitemap %s page %d reached the 50 MB limit; %d entries were left out. Lower the URLs-per-sitemap setting.', $type, $page, (int) $stats['dropped'] )
				);
			}
			$count = (int) $stats['urls'];
		}
		$result = array(
			'xml'       => $xml,
			'generated' => time(),
			'etag'      => md5( $xml ),
			'count'     => $count,
		);
		$this->cache->set( $type, $page, $result );
		return $result;
	}
}

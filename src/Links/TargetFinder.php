<?php
/**
 * Ranked internal-link partners of a post.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Core\Settings;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\SimHash;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers candidate posts for a post — body-text neighbours from the TF-IDF
 * index plus the newest posts of its most specific categories and tags — and
 * ranks them with Ranker (title words, slug tokens, taxonomy, body
 * similarity; same-category-by-recency as the fallback tier).
 *
 * Only posts that take part in internal linking (ContentIndex::is_eligible:
 * published, selected post type, indexable, not excluded) are returned, never
 * the post itself, and never near-duplicates of it (those should be merged,
 * not linked). The relation is symmetric enough to serve both directions:
 * the result lists good targets for links FROM the post and good sources
 * for links TO it. Results are cached for six hours (dropped when the post
 * is re-indexed or the index is rebuilt).
 */
class TargetFinder {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Own terms used to collect taxonomy neighbours (most specific first).
	 */
	const MAX_TERMS = 6;

	/**
	 * Newest posts collected per term.
	 */
	const PER_TERM = 25;

	/**
	 * Index.
	 *
	 * @var ContentIndex
	 */
	private $index;

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
	 * Content table.
	 *
	 * @var AnalysisRepository
	 */
	private $content;

	/**
	 * Per-request results.
	 *
	 * @var array<string,array>
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param ContentIndex       $index    Index.
	 * @param Settings           $settings Settings.
	 * @param MetaStore          $meta     Meta store.
	 * @param AnalysisRepository $content  Content table.
	 */
	public function __construct( ContentIndex $index, Settings $settings, MetaStore $meta, AnalysisRepository $content ) {
		$this->index    = $index;
		$this->settings = $settings;
		$this->meta     = $meta;
		$this->content  = $content;
	}

	/**
	 * Ranked partners of a post (cached).
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Max results (3, 5, 8 or 30 are cached across requests).
	 * @return array<int,array{post_id:int,score:float,tier:string,signals:array}>
	 */
	public function find( $post_id, $limit = 30 ) {
		$post_id = (int) $post_id;
		$limit   = max( 1, (int) $limit );
		$memo    = $post_id . ':' . $limit;
		if ( isset( $this->memo[ $memo ] ) ) {
			return $this->memo[ $memo ];
		}
		$key    = $this->index->related_cache_key( $post_id, $limit, 'spp_tf_' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$this->memo[ $memo ] = $cached;
			return $cached;
		}
		$result = $this->compute( $post_id, $limit );
		set_transient( $key, $result, self::CACHE_TTL );
		if ( count( $this->memo ) > 100 ) {
			$this->memo = array();
		}
		$this->memo[ $memo ] = $result;
		return $result;
	}

	/**
	 * Cached result, or null when not computed yet (never computes).
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array|null
	 */
	public function cached( $post_id, $limit ) {
		$cached = get_transient( $this->index->related_cache_key( (int) $post_id, max( 1, (int) $limit ), 'spp_tf_' ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Drops the per-request memo (after posts changed in a long request).
	 */
	public function reset() {
		$this->memo = array();
	}

	/**
	 * Computes the ranking.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array
	 */
	private function compute( $post_id, $limit ) {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->index->is_eligible( $post ) ) {
			return array();
		}
		// Candidate pool: body-text neighbours and taxonomy neighbours.
		$topic = array();
		foreach ( $this->index->related( $post->ID, 30 ) as $rel ) {
			$topic[ (int) $rel['post_id'] ] = (float) ContentIndex::relevance( (float) $rel['similarity'] );
		}
		$own_terms = $this->terms_of( array( $post->ID ) );
		$own       = $own_terms[ $post->ID ] ?? array(
			'categories' => array(),
			'tags'       => array(),
			'sizes'      => array(),
		);
		$pool      = array_keys( $topic );
		foreach ( $this->taxonomy_neighbours( $post, $own ) as $id ) {
			$pool[] = $id;
		}
		$pool = array_values( array_unique( array_diff( array_map( 'intval', $pool ), array( (int) $post->ID ) ) ) );
		if ( ! $pool ) {
			return array();
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $pool, false, true );
		}
		$eligible = array();
		foreach ( $pool as $id ) {
			if ( $this->index->is_eligible( $id ) ) {
				$eligible[] = $id;
			}
		}
		$duplicates = $this->near_duplicates_of( $post->ID, $eligible );
		$eligible   = array_values(
			array_filter(
				$eligible,
				static function ( $id ) use ( $duplicates ) {
					return ! isset( $duplicates[ $id ] );
				}
			)
		);
		if ( ! $eligible ) {
			return array();
		}
		$terms = $this->terms_of( $eligible );
		$sizes = $own['sizes'];
		foreach ( $terms as $t ) {
			$sizes += $t['sizes'];
		}
		$source     = $this->features( $post, $own );
		$candidates = array();
		$words      = $source['title_words'];
		foreach ( $eligible as $id ) {
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			$f                = $this->features( $p, $terms[ $id ] ?? array() );
			$f['topic']       = $topic[ $id ] ?? 0.0;
			$f['cornerstone'] = (bool) $this->meta->get_post_field( $id, 'cornerstone' );
			$candidates[]     = $f;
			$words            = array_merge( $words, $f['title_words'] );
		}
		$stems = array_map( array( ContentIndex::class, 'stem' ), $words );
		$ctx   = array(
			'df'               => $this->index->document_frequencies( $stems ),
			'n'                => $this->index->indexed_count(),
			'sizes'            => $sizes,
			'default_category' => (int) get_option( 'default_category' ),
			'threshold'        => max( 1, (int) $this->settings->get( 'links.min_relevance', 25 ) ) / 100,
			'min_related'      => 3,
		);
		return Ranker::rank( $source, $candidates, $ctx, $limit );
	}

	/**
	 * Ranking features of a post.
	 *
	 * @param \WP_Post $post  Post.
	 * @param array    $terms categories, tags.
	 * @return array
	 */
	private function features( \WP_Post $post, array $terms ) {
		$title = html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return array(
			'id'          => (int) $post->ID,
			'title_words' => ContentIndex::key_words( $title ),
			'slug_tokens' => Ranker::slug_tokens( (string) $post->post_name ),
			'categories'  => array_map( 'intval', (array) ( $terms['categories'] ?? array() ) ),
			'tags'        => array_map( 'intval', (array) ( $terms['tags'] ?? array() ) ),
			'script'      => Ranker::script( $title . ' ' . wp_trim_words( (string) $post->post_content, 30, '' ) ),
			'time'        => (int) strtotime( $post->post_date_gmt . ' UTC' ),
		);
	}

	/**
	 * Categories and tags of posts, with term sizes.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,array{categories:int[],tags:int[],sizes:array<int,int>}>
	 */
	private function terms_of( array $ids ) {
		$out        = array();
		$taxonomies = array_values( array_filter( array( 'category', 'post_tag' ), 'taxonomy_exists' ) );
		if ( ! $ids || ! $taxonomies ) {
			return $out;
		}
		$terms = wp_get_object_terms( array_map( 'intval', $ids ), $taxonomies, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			$id = (int) $term->object_id;
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'categories' => array(),
					'tags'       => array(),
					'sizes'      => array(),
				);
			}
			$out[ $id ][ 'category' === $term->taxonomy ? 'categories' : 'tags' ][] = (int) $term->term_id;
			$out[ $id ]['sizes'][ (int) $term->term_id ]                           = (int) $term->count;
		}
		return $out;
	}

	/**
	 * Newest published posts sharing the post's most specific categories and tags.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $own  Own terms (categories, tags, sizes).
	 * @return int[]
	 */
	private function taxonomy_neighbours( \WP_Post $post, array $own ) {
		global $wpdb;
		$default = (int) get_option( 'default_category' );
		$ids     = array();
		foreach ( array( 'categories', 'tags' ) as $key ) {
			foreach ( (array) $own[ $key ] as $term_id ) {
				if ( 'categories' === $key && (int) $term_id === $default ) {
					continue;
				}
				if ( isset( $own['sizes'][ $term_id ] ) && (int) $own['sizes'][ $term_id ] < 2 ) {
					continue; // Only this post has the term: it has no neighbours and must not use up a slot.
				}
				$ids[ (int) $term_id ] = (int) ( $own['sizes'][ $term_id ] ?? 0 );
			}
		}
		if ( ! $ids ) {
			return array();
		}
		asort( $ids ); // Smallest (most specific) terms first.
		$ids   = array_slice( array_keys( $ids ), 0, self::MAX_TERMS );
		$types = $this->index->post_types();
		if ( ! $types ) {
			return array();
		}
		$tt = array();
		foreach ( $ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$tt[] = (int) $term->term_taxonomy_id;
			}
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$out     = array();
		foreach ( $tt as $term_taxonomy_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish' AND p.post_type IN ({$holders}) AND p.ID <> %d ORDER BY p.post_date DESC LIMIT %d", array_merge( array( $term_taxonomy_id ), $types, array( (int) $post->ID, self::PER_TERM ) ) ) );
			foreach ( (array) $rows as $id ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Candidates whose text is a near-copy of the post (SimHash distance ≤ 3).
	 *
	 * @param int   $post_id Post id.
	 * @param int[] $ids     Candidate ids.
	 * @return array<int,bool>
	 */
	private function near_duplicates_of( $post_id, array $ids ) {
		$rows = $this->content->get_many( array_merge( array( (int) $post_id ), array_map( 'intval', $ids ) ) );
		$own  = $rows[ (int) $post_id ] ?? null;
		$out  = array();
		if ( ! $own || 16 !== strlen( (string) $own['simhash'] ) || (int) $own['word_count'] < 100 ) {
			return $out;
		}
		$listed = array_map( 'intval', (array) ( $own['analysis']['dups']['content'] ?? array() ) );
		foreach ( $ids as $id ) {
			$row = $rows[ (int) $id ] ?? null;
			if ( in_array( (int) $id, $listed, true ) || ( $row && 16 === strlen( (string) $row['simhash'] ) && (int) $row['word_count'] >= 100 && SimHash::distance( $own['simhash'], $row['simhash'] ) <= 3 ) ) {
				$out[ (int) $id ] = true;
			}
		}
		return $out;
	}
}

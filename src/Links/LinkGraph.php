<?php
/**
 * Link graph: edges between posts, inbound counts, orphans, broken links.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the links of every published post (rendered content) into the
 * `links` table: source post, resolved target post, anchor, internal /
 * nofollow flags and whether SearchPilot inserted the link. Keeps
 * content.inbound_links / outbound_internal / outbound_external current and
 * reports broken internal links per source post.
 *
 * Every change of a post's edges fires `searchpilot_links_graph_changed`
 * ( int $source_id, int[] $targets ) with the targets that gained or lost a
 * link from it, so link issues (orphan pages) follow the graph immediately.
 */
class LinkGraph {

	const MENU_CACHE = 'searchpilot_menu_object_ids';

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Content table.
	 *
	 * @var AnalysisRepository
	 */
	private $repo;

	/**
	 * Renderer.
	 *
	 * @var ContentRenderer
	 */
	private $renderer;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Per-request resolution cache: url hash => result.
	 *
	 * @var array<string,array>
	 */
	private $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param ContentTypes       $types    Content types.
	 * @param UrlRepository      $urls     URL registry.
	 * @param AnalysisRepository $repo     Content table.
	 * @param ContentRenderer    $renderer Renderer.
	 * @param IssueRepository    $issues   Issues.
	 */
	public function __construct( ContentTypes $types, UrlRepository $urls, AnalysisRepository $repo, ContentRenderer $renderer, IssueRepository $issues ) {
		$this->types    = $types;
		$this->urls     = $urls;
		$this->repo     = $repo;
		$this->renderer = $renderer;
		$this->issues   = $issues;
	}

	/**
	 * Whether a post's links belong in the graph (published, public type).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function in_graph( $post ) {
		$post = get_post( $post );
		return $post && 'publish' === $post->post_status && isset( $this->types->post_types()[ $post->post_type ] );
	}

	/**
	 * Re-parses a post's links and updates edges, counts and issues.
	 *
	 * @param int         $post_id Post id.
	 * @param string|null $html    Rendered content (rendered here when null).
	 * @return array{internal:int,external:int,broken:string[]}
	 */
	public function update_post( $post_id, $html = null ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || ! $this->in_graph( $post ) ) {
			$this->remove_post( (int) $post_id );
			return array(
				'internal' => 0,
				'external' => 0,
				'broken'   => array(),
			);
		}
		if ( null === $html ) {
			$html = $this->renderer->render( $post->post_content, $post );
		}
		$base     = (string) get_permalink( $post );
		$doc      = HtmlDocument::parse( '<div>' . $html . '</div>', $base );
		$edges    = array();
		$seen     = array();
		$previous = array();
		$table    = Tables::links();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT target_hash, target_id FROM {$table} WHERE source_id = %d AND target_id > 0", (int) $post->ID ) ) as $row ) {
			$previous[ (string) $row->target_hash ] = (int) $row->target_id;
		}
		foreach ( $doc->query( '//a[@href]' ) as $node ) {
			/** @var \DOMElement $node */
			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '#^(mailto|tel|javascript|sms|data|ftp):#i', $href ) ) {
				continue;
			}
			$url      = Url::normalize( Url::absolute( $href, $base ) );
			$internal = Url::is_internal( $url );
			if ( $internal && preg_match( '#^/(wp-content|wp-admin|wp-json|wp-includes|feed)(/|$)#', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
				continue; // Files and endpoints, not pages.
			}
			$anchor = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
			if ( '' === $anchor ) {
				$img    = $node->getElementsByTagName( 'img' )->item( 0 );
				$anchor = $img ? trim( (string) $img->getAttribute( 'alt' ) ) : '';
			}
			$rel       = array_filter( (array) preg_split( '/\s+/', strtolower( $node->getAttribute( 'rel' ) ) ) );
			$target_id = 0;
			$status    = 'external';
			if ( $internal ) {
				$resolved  = $this->resolve( $url );
				$target_id = (int) $resolved['target_id'];
				$status    = (string) $resolved['status'];
				if ( $target_id === (int) $post->ID ) {
					continue; // Self link.
				}
				$hash = Url::hash( $url );
				if ( ! $target_id && 'unknown' === $status && isset( $previous[ $hash ] ) ) {
					// The address used to lead to a post that has since been deleted or unpublished.
					$old = get_post( $previous[ $hash ] );
					if ( ! $old || 'publish' !== $old->post_status ) {
						$status = 'missing';
					}
				}
			}
			$key = sha1( $url . '|' . $anchor );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$edges[]      = array(
				'target_id' => $target_id,
				'url'       => $url,
				'anchor'    => mb_substr( $anchor, 0, 250, 'UTF-8' ),
				'internal'  => $internal ? 1 : 0,
				'nofollow'  => in_array( 'nofollow', $rel, true ) ? 1 : 0,
				'plugin'    => $node->hasAttribute( 'data-spp-link' ) ? 1 : 0,
				'status'    => $status,
			);
		}

		$old_targets = array_values( array_unique( $previous ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_id = %d", (int) $post->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$internal = 0;
		$external = 0;
		$broken   = array();
		$targets  = array();
		foreach ( array_chunk( $edges, 50 ) as $chunk ) {
			$holders = array();
			$values  = array();
			foreach ( $chunk as $edge ) {
				$holders[] = '(%d, %d, %s, %s, %s, %d, %d, %d)';
				array_push( $values, (int) $post->ID, $edge['target_id'], $edge['url'], Url::hash( $edge['url'] ), $edge['anchor'], $edge['internal'], $edge['nofollow'], $edge['plugin'] );
				if ( $edge['internal'] ) {
					++$internal;
					if ( $edge['target_id'] ) {
						$targets[] = $edge['target_id'];
					}
					if ( 'missing' === $edge['status'] ) {
						$broken[] = $edge['url'];
					}
				} else {
					++$external;
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source_id, target_id, target_url, target_hash, anchor, is_internal, is_nofollow, inserted_by_plugin) VALUES " . implode( ', ', $holders ), $values ) );
		}

		$this->repo->update_fields(
			(int) $post->ID,
			array(
				'outbound_internal' => $internal,
				'outbound_external' => $external,
			)
		);
		$this->recount_inbound( array_unique( array_merge( $old_targets, $targets, array( (int) $post->ID ) ) ) );
		$this->report_broken( $post, array_values( array_unique( $broken ) ) );
		$new_targets = array_values( array_unique( array_map( 'intval', $targets ) ) );
		$this->changed( (int) $post->ID, array_merge( array_diff( $new_targets, $old_targets ), array_diff( $old_targets, $new_targets ) ) );

		return array(
			'internal' => $internal,
			'external' => $external,
			'broken'   => array_values( array_unique( $broken ) ),
		);
	}

	/**
	 * Removes a post's outgoing edges (unpublished/deleted/excluded).
	 *
	 * @param int $post_id Post id.
	 * @return int[] Source posts that link TO this post (they now point to a missing page).
	 */
	public function remove_post( $post_id ) {
		global $wpdb;
		$table   = Tables::links();
		$targets = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT target_id FROM {$table} WHERE source_id = %d AND target_id > 0", (int) $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_id = %d", (int) $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$sources = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$table} WHERE target_id = %d LIMIT 100", (int) $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $targets ) {
			$this->recount_inbound( $targets );
		}
		$this->repo->update_fields(
			(int) $post_id,
			array(
				'outbound_internal' => 0,
				'outbound_external' => 0,
			),
			false
		);
		$this->issues->resolve_stale_for_object( 'post', (int) $post_id, array( 'broken_internal_link' ), array() );
		$this->issues->resolve_stale_for_object( 'post', (int) $post_id, array( 'orphan_page' ), array() );
		$this->resolved = array();
		$this->changed( (int) $post_id, $targets );
		return $sources;
	}

	/**
	 * Announces changed edges.
	 *
	 * @param int   $source_id Source post.
	 * @param int[] $targets   Targets that gained or lost a link from it.
	 */
	private function changed( $source_id, array $targets ) {
		/**
		 * Fires after a post's internal links were re-read or removed.
		 *
		 * @param int   $source_id Source post id.
		 * @param int[] $targets   Target posts that gained or lost a link from it.
		 */
		do_action( 'searchpilot_links_graph_changed', (int) $source_id, array_values( array_filter( array_unique( array_map( 'intval', $targets ) ) ) ) );
	}

	/**
	 * Live link facts of posts, with the same definition of "orphan" as
	 * orphans() and stats() (indexed for linking, published, no link from
	 * another post, not in a menu, not the front/blog page).
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,array{known:bool,indexed:bool,published:bool,inbound:int,outbound_internal:int,exempt:bool,orphan:bool}>
	 *         known: the post has a row in the content table (its links were read at least once).
	 */
	public function link_state( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		$exempt = array_flip( $this->orphan_exempt_ids() );
		$table  = Tables::content();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = array();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( "SELECT p.ID AS id, p.post_status, c.post_id AS known, c.indexed_at, c.inbound_links, c.outbound_internal FROM {$wpdb->posts} p LEFT JOIN {$table} c ON c.post_id = p.ID WHERE p.ID IN ({$in})" ) as $row ) {
				$rows[ (int) $row->id ] = $row;
			}
			foreach ( $chunk as $id ) {
				$row       = $rows[ $id ] ?? null;
				$indexed   = $row && null !== $row->indexed_at && '' !== (string) $row->indexed_at;
				$published = $row && 'publish' === $row->post_status;
				$inbound   = $row ? (int) $row->inbound_links : 0;
				$out[ $id ] = array(
					'known'             => $row && null !== $row->known,
					'indexed'           => $indexed,
					'published'         => $published,
					'inbound'           => $inbound,
					'outbound_internal' => $row ? (int) $row->outbound_internal : 0,
					'exempt'            => isset( $exempt[ $id ] ),
					'orphan'            => $indexed && $published && 0 === $inbound && ! isset( $exempt[ $id ] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Resolves an internal URL to a post and a status.
	 *
	 * Status: ok, redirect (old slug / non-canonical address / 3xx in the registry),
	 * missing (trashed or unpublished post, 404/410 in the registry or the 404 log),
	 * unknown (not a post — archives, custom routes).
	 *
	 * @param string $url URL.
	 * @return array{target_id:int,status:string}
	 */
	public function resolve( $url ) {
		$url = Url::normalize( $url );
		$key = Url::hash( $url );
		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}
		$result = array(
			'target_id' => 0,
			'status'    => 'unknown',
		);
		$row    = $this->urls->get_by_url( $url );
		$id     = 0;
		if ( $row && 'post' === ( $row['object_type'] ?? '' ) && ! empty( $row['object_id'] ) ) {
			$id = (int) $row['object_id'];
		}
		if ( ! $id ) {
			$id = (int) url_to_postid( $url );
		}
		$status = 'ok';
		if ( ! $id ) {
			$id     = $this->old_slug_target( $url, '_wp_old_slug' );
			$status = $id ? 'redirect' : 'unknown';
		}
		if ( ! $id ) {
			// Trashed posts keep their original slug in _wp_desired_post_slug.
			$id = $this->old_slug_target( $url, '_wp_desired_post_slug' );
		}
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' === $post->post_type ) {
				$id     = 0;
				$status = $post ? 'ok' : 'unknown';
			} elseif ( 'publish' !== $post->post_status ) {
				$status = 'missing';
			} elseif ( 'ok' === $status ) {
				$permalink = Url::normalize( (string) get_permalink( $post ) );
				if ( untrailingslashit( Url::strip_query( $permalink ) ) !== untrailingslashit( Url::strip_query( $url ) ) ) {
					$status = 'redirect';
				}
			}
			$result['target_id'] = $id;
		}
		if ( $row ) {
			$http = (int) ( $row['http_status'] ?? 0 );
			if ( in_array( $http, array( 404, 410 ), true ) ) {
				$status = 'missing';
			} elseif ( $http >= 300 && $http < 400 && 'missing' !== $status ) {
				$status = 'redirect';
			}
		}
		if ( 'unknown' === $status && $this->logged_404( $url ) ) {
			$status = 'missing';
		}
		$result['status'] = $status;
		if ( count( $this->resolved ) > 2000 ) {
			$this->resolved = array();
		}
		$this->resolved[ $key ] = $result;
		return $result;
	}

	/**
	 * Post whose previous slug matches the URL's last path segment.
	 *
	 * @param string $url      URL.
	 * @param string $meta_key _wp_old_slug (renamed) or _wp_desired_post_slug (trashed).
	 * @return int
	 */
	private function old_slug_target( $url, $meta_key ) {
		global $wpdb;
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 0;
		}
		$segments = explode( '/', $path );
		$slug     = strtolower( (string) end( $segments ) );
		if ( '' === $slug ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_query_meta_key,WordPress.DB.SlowDBQuery.slow_query_meta_value
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY meta_id DESC LIMIT 1", (string) $meta_key, $slug ) );
	}

	/**
	 * Whether the 404 monitor recorded the URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function logged_404( $url ) {
		global $wpdb;
		$table    = Tables::not_found();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s AND status = 'open' LIMIT 1", Url::hash( $url ) ) );
		$wpdb->suppress_errors( $suppress );
		return (bool) $found;
	}

	/**
	 * Recomputes inbound link counts (distinct linking posts) for targets.
	 *
	 * @param int[] $ids Target post ids.
	 */
	public function recount_inbound( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$table = Tables::links();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in     = implode( ',', $chunk );
			$counts = array_fill_keys( $chunk, 0 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT target_id, COUNT(DISTINCT source_id) AS n FROM {$table} WHERE target_id IN ({$in}) AND is_internal = 1 AND source_id <> target_id GROUP BY target_id" );
			foreach ( (array) $rows as $row ) {
				$counts[ (int) $row->target_id ] = (int) $row->n;
			}
			foreach ( $counts as $id => $n ) {
				$this->repo->update_fields( $id, array( 'inbound_links' => $n ), (bool) get_post( $id ) );
			}
		}
	}

	/**
	 * Upserts or resolves the broken-link issue of a source post.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string[] $broken Broken URLs.
	 */
	private function report_broken( \WP_Post $post, array $broken ) {
		if ( ! $broken ) {
			$this->issues->resolve_stale_for_object( 'post', (int) $post->ID, array( 'broken_internal_link' ), array() );
			return;
		}
		$this->issues->upsert(
			'broken_internal_link',
			array(
				'object_type' => 'post',
				'object_id'   => (int) $post->ID,
				'url'         => (string) get_permalink( $post ),
				'title'       => Suggester::plain_title( $post ),
				'data'        => array(
					'urls'  => array_map( array( Url::class, 'display' ), array_slice( $broken, 0, 20 ) ),
					'count' => count( $broken ),
				),
			)
		);
	}

	/**
	 * Outgoing edges of a post.
	 *
	 * @param int  $post_id       Post id.
	 * @param bool $internal_only Only internal links.
	 * @return array[]
	 */
	public function edges_from( $post_id, $internal_only = false ) {
		global $wpdb;
		$table = Tables::links();
		$extra = $internal_only ? ' AND is_internal = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d{$extra} ORDER BY id ASC LIMIT 500", (int) $post_id ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Incoming internal edges of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array[]
	 */
	public function edges_to( $post_id ) {
		global $wpdb;
		$table = Tables::links();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_id = %d AND source_id <> %d AND is_internal = 1 ORDER BY id ASC LIMIT 500", (int) $post_id, (int) $post_id ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether a source already links to a target.
	 *
	 * @param int $source_id Source.
	 * @param int $target_id Target.
	 * @return bool
	 */
	public function links_to( $source_id, $target_id ) {
		global $wpdb;
		$table = Tables::links();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_id = %d AND target_id = %d LIMIT 1", (int) $source_id, (int) $target_id ) );
	}

	/**
	 * Internal link count of a source (from stored edges).
	 *
	 * @param int $source_id Source.
	 * @return int
	 */
	public function internal_count( $source_id ) {
		global $wpdb;
		$table = Tables::links();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND is_internal = 1", (int) $source_id ) );
	}

	/**
	 * How often each anchor text links to a target (lower-case anchor => count).
	 *
	 * @param int $target_id Target.
	 * @return array<string,int>
	 */
	public function anchor_uses( $target_id ) {
		global $wpdb;
		$table = Tables::links();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT anchor, COUNT(*) AS n FROM {$table} WHERE target_id = %d AND is_internal = 1 GROUP BY anchor", (int) $target_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$anchor         = mb_strtolower( trim( (string) $row->anchor ), 'UTF-8' );
			$out[ $anchor ] = ( $out[ $anchor ] ?? 0 ) + (int) $row->n;
		}
		return $out;
	}

	/**
	 * Post ids linked from navigation menus.
	 *
	 * @return int[]
	 */
	public function menu_object_ids() {
		global $wpdb;
		$cached = get_transient( self::MENU_CACHE );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_query_meta_key
		$ids = $wpdb->get_col(
			"SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			INNER JOIN {$wpdb->postmeta} t ON t.post_id = m.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type'
			WHERE p.post_type = 'nav_menu_item' AND p.post_status = 'publish' AND m.meta_key = '_menu_item_object_id'"
		);
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		set_transient( self::MENU_CACHE, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * Posts that must never count as orphans (front page, blog page, menu items).
	 *
	 * @return int[]
	 */
	public function orphan_exempt_ids() {
		$ids = $this->menu_object_ids();
		foreach ( array( 'page_on_front', 'page_for_posts' ) as $option ) {
			$id = (int) get_option( $option );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Orphan posts: indexed for linking, no links from other posts, not in a menu, not the front page.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array{ids:int[],total:int}
	 */
	public function orphans( $page = 1, $per_page = 25 ) {
		global $wpdb;
		$table  = Tables::content();
		$exempt = $this->orphan_exempt_ids();
		$extra  = $exempt ? ' AND c.post_id NOT IN (' . implode( ',', array_map( 'intval', $exempt ) ) . ')' : '';
		$where  = "c.indexed_at IS NOT NULL AND c.inbound_links = 0 AND p.post_status = 'publish'{$extra}";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$where}" );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.post_id FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$where} ORDER BY ( p.post_type = 'page' ) DESC, p.post_date DESC LIMIT %d OFFSET %d",
				(int) $per_page,
				( max( 1, (int) $page ) - 1 ) * (int) $per_page
			)
		);
		// phpcs:enable
		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}

	/**
	 * Site statistics for the Internal Links screen.
	 *
	 * @return array
	 */
	public function stats() {
		global $wpdb;
		$links   = Tables::links();
		$content = Tables::content();
		$exempt  = $this->orphan_exempt_ids();
		$extra   = $exempt ? ' AND c.post_id NOT IN (' . implode( ',', array_map( 'intval', $exempt ) ) . ')' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$indexed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$content} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.indexed_at IS NOT NULL AND p.post_status = 'publish'" );
		$internal = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} WHERE is_internal = 1 AND target_id > 0" );
		$plugin   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} WHERE inserted_by_plugin = 1" );
		$orphans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$content} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.indexed_at IS NOT NULL AND c.inbound_links = 0 AND p.post_status = 'publish'{$extra}" );
		$no_out   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$content} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.indexed_at IS NOT NULL AND c.outbound_internal = 0 AND p.post_status = 'publish'" );
		$most     = $wpdb->get_results( "SELECT c.post_id, c.inbound_links FROM {$content} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.inbound_links > 0 AND p.post_status = 'publish' ORDER BY c.inbound_links DESC, c.post_id DESC LIMIT 10" );
		$without  = $wpdb->get_results( "SELECT c.post_id, c.word_count FROM {$content} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.indexed_at IS NOT NULL AND c.outbound_internal = 0 AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT 10" );
		$overused = $wpdb->get_results( "SELECT anchor, target_id, COUNT(*) AS n FROM {$links} WHERE is_internal = 1 AND target_id > 0 AND anchor <> '' GROUP BY anchor, target_id HAVING COUNT(*) > 3 ORDER BY n DESC LIMIT 15" );
		// phpcs:enable

		$post_row = static function ( $id ) {
			return array(
				'post_id'   => (int) $id,
				'title'     => Suggester::plain_title( (int) $id ),
				'url'       => (string) get_permalink( (int) $id ),
				'edit_link' => (string) get_edit_post_link( (int) $id, 'raw' ),
			);
		};
		return array(
			'indexed_posts'    => $indexed,
			'internal_links'   => $internal,
			'plugin_links'     => $plugin,
			'avg_per_post'     => $indexed ? round( $internal / $indexed, 2 ) : 0,
			'orphans'          => $orphans,
			'most_linked'      => array_map(
				static function ( $row ) use ( $post_row ) {
					return array_merge( $post_row( $row->post_id ), array( 'inbound' => (int) $row->inbound_links ) );
				},
				(array) $most
			),
			'without_outbound' => array(
				'count' => $no_out,
				'items' => array_map(
					static function ( $row ) use ( $post_row ) {
						return array_merge( $post_row( $row->post_id ), array( 'words' => (int) $row->word_count ) );
					},
					(array) $without
				),
			),
			'overused_anchors' => array_map(
				static function ( $row ) use ( $post_row ) {
					return array_merge(
						$post_row( $row->target_id ),
						array(
							'anchor' => (string) $row->anchor,
							'uses'   => (int) $row->n,
						)
					);
				},
				(array) $overused
			),
		);
	}

	/**
	 * Formats an edge row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		return array(
			'id'           => (int) $row['id'],
			'source_id'    => (int) $row['source_id'],
			'target_id'    => (int) $row['target_id'],
			'url'          => (string) $row['target_url'],
			'display_url'  => Url::display( (string) $row['target_url'] ),
			'anchor'       => (string) $row['anchor'],
			'internal'     => (bool) $row['is_internal'],
			'nofollow'     => (bool) $row['is_nofollow'],
			'by_plugin'    => (bool) $row['inserted_by_plugin'],
			'source_title' => Suggester::plain_title( (int) $row['source_id'] ),
			'target_title' => (int) $row['target_id'] ? Suggester::plain_title( (int) $row['target_id'] ) : '',
		);
	}

	/**
	 * Forgets cached menu ids.
	 */
	public static function flush_menu_cache() {
		delete_transient( self::MENU_CACHE );
	}
}

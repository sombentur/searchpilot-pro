<?php
/**
 * Maps page URLs reported by search engines to WordPress posts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Database\Tables;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Search Console / Bing page URLs to post ids: first through the
 * URL registry (one indexed query for a whole batch, by Url::hash so
 * Kannada/Unicode slugs match), then url_to_postid() for URLs the registry
 * does not know yet. Results — including "no post" — are cached for a week
 * in one transient map, so a daily sync of thousands of rows does not
 * repeat the expensive lookups.
 */
class UrlResolver {

	const CACHE_KEY = 'searchpilot_url_postids';
	const CACHE_TTL = WEEK_IN_SECONDS;
	const CACHE_CAP = 8000;

	/**
	 * In-request cache: url hash => post id.
	 *
	 * @var array<string,int>
	 */
	private $memory = array();

	/**
	 * Persistent map loaded from the transient (hash => id).
	 *
	 * @var array<string,int>|null
	 */
	private $persisted = null;

	/**
	 * Whether the persistent map changed.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Resolves one URL.
	 *
	 * @param string $url URL.
	 * @return int Post id or 0.
	 */
	public function resolve( $url ) {
		$map = $this->resolve_many( array( $url ) );
		$this->flush();
		return (int) ( $map[ Url::normalize( $url ) ] ?? 0 );
	}

	/**
	 * Resolves many URLs.
	 *
	 * @param string[] $urls          URLs.
	 * @param int      $lookup_budget Max url_to_postid() calls (the rest stay unresolved for now).
	 * @return array<string,int> Normalized URL => post id (0 = not a post).
	 */
	public function resolve_many( array $urls, $lookup_budget = 2000 ) {
		global $wpdb;
		$out     = array();
		$pending = array();
		$this->load();
		foreach ( $urls as $url ) {
			$normalized = Url::normalize( (string) $url );
			if ( '' === $normalized ) {
				continue;
			}
			$hash = sha1( $normalized );
			if ( isset( $this->memory[ $hash ] ) ) {
				$out[ $normalized ] = $this->memory[ $hash ];
				continue;
			}
			if ( ! Url::is_internal( $normalized ) ) {
				$this->memory[ $hash ] = 0;
				$out[ $normalized ]    = 0;
				continue;
			}
			$pending[ $hash ] = $normalized;
		}
		if ( ! $pending ) {
			return $out;
		}

		// 1. URL registry (batched, indexed by url_hash).
		$table = Tables::urls();
		foreach ( array_chunk( array_keys( $pending ), 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, object_id FROM {$table} WHERE object_type = 'post' AND object_id > 0 AND url_hash IN ({$holders})", $chunk ) );
			foreach ( (array) $rows as $row ) {
				$hash = (string) $row->url_hash;
				if ( isset( $pending[ $hash ] ) ) {
					$this->memory[ $hash ]    = (int) $row->object_id;
					$out[ $pending[ $hash ] ] = (int) $row->object_id;
					unset( $pending[ $hash ] );
				}
			}
		}

		// 2. Cached url_to_postid() results, then new lookups within the budget.
		foreach ( $pending as $hash => $normalized ) {
			if ( isset( $this->persisted[ $hash ] ) ) {
				$this->memory[ $hash ] = (int) $this->persisted[ $hash ];
				$out[ $normalized ]    = (int) $this->persisted[ $hash ];
				continue;
			}
			if ( $lookup_budget <= 0 ) {
				continue;
			}
			--$lookup_budget;
			$id = $this->lookup( $normalized );

			$this->memory[ $hash ]    = $id;
			$this->persisted[ $hash ] = $id;
			$this->dirty              = true;
			$out[ $normalized ]       = $id;
		}
		return $out;
	}

	/**
	 * Saves new lookups to the transient (call after a batch).
	 */
	public function flush() {
		if ( ! $this->dirty || null === $this->persisted ) {
			return;
		}
		if ( count( $this->persisted ) > self::CACHE_CAP ) {
			$this->persisted = array_slice( $this->persisted, -self::CACHE_CAP, null, true );
		}
		set_transient( self::CACHE_KEY, $this->persisted, self::CACHE_TTL );
		$this->dirty = false;
	}

	/**
	 * Forgets cached lookups (e.g. after permalinks changed).
	 */
	public function clear() {
		delete_transient( self::CACHE_KEY );
		$this->memory    = array();
		$this->persisted = array();
		$this->dirty     = false;
	}

	/**
	 * url_to_postid() with the variants search engines report.
	 *
	 * @param string $url Normalized URL.
	 * @return int
	 */
	private function lookup( $url ) {
		$id = (int) url_to_postid( $url );
		if ( ! $id ) {
			// Search Console reports percent-encoded paths; WordPress may store the decoded slug.
			$decoded = Url::display( $url );
			if ( $decoded !== $url ) {
				$id = (int) url_to_postid( $decoded );
			}
		}
		if ( ! $id ) {
			// AMP and paginated variants: /slug/amp/, /slug/2/.
			$stripped = (string) preg_replace( '#/(amp|\d+)/?$#', '/', Url::strip_query( $url ) );
			if ( $stripped !== Url::strip_query( $url ) ) {
				$id = (int) url_to_postid( $stripped );
			}
		}
		if ( $id && 'publish' !== get_post_status( $id ) ) {
			$status = get_post_status( $id );
			// Keep ids of private/draft posts out of analytics joins; attachments inherit.
			if ( ! in_array( $status, array( 'inherit' ), true ) ) {
				$id = 0;
			}
		}
		return $id;
	}

	/**
	 * Loads the persistent map.
	 */
	private function load() {
		if ( null !== $this->persisted ) {
			return;
		}
		$map             = get_transient( self::CACHE_KEY );
		$this->persisted = is_array( $map ) ? $map : array();
	}
}

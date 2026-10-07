<?php
/**
 * Versioned sitemap cache.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Core\State;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered XML and page summaries are cached per (type, page) in the
 * persistent object cache when one exists, otherwise in transients. There
 * is exactly ONE entry per (type, page): the version it was built for is
 * stored inside the value, so a newer build overwrites the old one and
 * superseded XML never piles up in wp_options.
 *
 * Versions live in State `sitemap_versions`:
 *   {group}          whole group (summary + every page), e.g. pt-post, tax-post_tag
 *   sum:{group}      page boundaries only
 *   {group}@{page}   one page's XML
 *   _all             everything (settings changes)
 * Bumps are collected during a request and written once (flush()).
 */
class SitemapCache {

	const GROUP     = 'searchpilot_sitemaps';
	const TTL       = DAY_IN_SECONDS;
	const NEWS_TTL  = 900;
	const MAX_BYTES = 4194304;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Pending version bumps (version key => true).
	 *
	 * @var array<string,bool>
	 */
	private $pending = array();

	/**
	 * Constructor.
	 *
	 * @param State $state State.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Current versions.
	 *
	 * @return array<string,int>
	 */
	public function versions() {
		$versions = $this->state->get( 'sitemap_versions', array() );
		return is_array( $versions ) ? $versions : array();
	}

	/**
	 * Cache group of a sitemap type.
	 *
	 * @param string $type Type (pt-post, tax-category, authors, video, news, index, summary:pt-post…).
	 * @return string
	 */
	public static function group_of( $type ) {
		$type = (string) $type;
		if ( 0 === strpos( $type, 'summary:' ) ) {
			$type = substr( $type, 8 );
		}
		return $type;
	}

	/**
	 * Version string an entry must carry to be fresh.
	 *
	 * @param string $type Type.
	 * @param int    $page Page.
	 * @return string
	 */
	public function version_of( $type, $page = 1 ) {
		$v   = $this->versions();
		$all = (int) ( $v['_all'] ?? 0 );
		if ( 'index' === $type || 'index_data' === $type ) {
			unset( $v['_changed'] );
			ksort( $v );
			return md5( wp_json_encode( $v ) );
		}
		if ( 0 === strpos( (string) $type, 'summary:' ) ) {
			$group = self::group_of( $type );
			return (int) ( $v[ $group ] ?? 0 ) . '.' . (int) ( $v[ 'sum:' . $group ] ?? 0 ) . '.' . $all;
		}
		return (int) ( $v[ $type ] ?? 0 ) . '.' . (int) ( $v[ $type . '@' . (int) $page ] ?? 0 ) . '.' . $all;
	}

	/**
	 * Storage key of a type/page (one per pair; the version is inside the value).
	 *
	 * @param string $type Type.
	 * @param int    $page Page.
	 * @return string
	 */
	public function key( $type, $page = 1 ) {
		return 'searchpilot_sm_' . md5( $type . '|' . (int) $page . '|' . SEARCHPILOT_VERSION . '|' . home_url( '/' ) );
	}

	/**
	 * Reads a cached value when it matches the current version.
	 *
	 * @param string $type Type.
	 * @param int    $page Page.
	 * @return mixed|null
	 */
	public function get( $type, $page = 1 ) {
		$key = $this->key( $type, $page );
		$raw = wp_using_ext_object_cache() ? wp_cache_get( $key, self::GROUP ) : get_transient( $key );
		if ( ! is_array( $raw ) || ! array_key_exists( 'v', $raw ) || ! array_key_exists( 'd', $raw ) ) {
			return null;
		}
		return $raw['v'] === $this->version_of( $type, $page ) ? $raw['d'] : null;
	}

	/**
	 * Stores a value for the current version (replacing any older build).
	 *
	 * @param string $type  Type.
	 * @param int    $page  Page.
	 * @param mixed  $value Value (arrays with an 'xml' key are size-checked).
	 */
	public function set( $type, $page, $value ) {
		// News items expire after 48 hours and the index lists the news sitemap only while it has
		// items, so both are rebuilt often (cheap: page summaries stay cached).
		$ttl   = in_array( self::group_of( $type ), array( 'news', 'index', 'index_data' ), true ) ? self::NEWS_TTL : self::TTL;
		$key   = $this->key( $type, $page );
		$entry = array(
			'v' => $this->version_of( $type, $page ),
			'd' => $value,
		);
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $entry, self::GROUP, $ttl );
			return;
		}
		if ( is_array( $value ) && isset( $value['xml'] ) && strlen( (string) $value['xml'] ) > self::MAX_BYTES ) {
			// Too large for the options table: drop any older copy and regenerate on demand.
			delete_transient( $key );
			return;
		}
		set_transient( $key, $entry, $ttl );
	}

	/**
	 * Queues whole-group bumps (summary + every page of each group).
	 *
	 * @param string[] $groups Groups.
	 */
	public function bump( array $groups ) {
		foreach ( $groups as $group ) {
			$group = (string) $group;
			if ( '' !== $group ) {
				$this->pending[ $group ] = true;
			}
		}
	}

	/**
	 * Queues a bump of a group's page boundaries only.
	 *
	 * @param string $group Group.
	 */
	public function bump_summary( $group ) {
		$this->pending[ 'sum:' . $group ] = true;
	}

	/**
	 * Queues bumps of single pages.
	 *
	 * @param string $group Group.
	 * @param int[]  $pages Pages.
	 */
	public function bump_pages( $group, array $pages ) {
		foreach ( $pages as $page ) {
			if ( (int) $page > 0 ) {
				$this->pending[ $group . '@' . (int) $page ] = true;
			}
		}
	}

	/**
	 * Invalidates everything now.
	 */
	public function bump_all() {
		$this->pending['_all'] = true;
		$this->flush();
	}

	/**
	 * Pending version keys.
	 *
	 * @return string[]
	 */
	public function pending() {
		return array_keys( $this->pending );
	}

	/**
	 * Writes pending bumps (one State write) and fires searchpilot_sitemap_changed.
	 *
	 * @return string[] Changed groups.
	 */
	public function flush() {
		if ( empty( $this->pending ) ) {
			return array();
		}
		$keys          = array_keys( $this->pending );
		$this->pending = array();
		$versions      = $this->versions();
		$whole         = array();
		foreach ( $keys as $key ) {
			if ( false === strpos( $key, '@' ) && 0 !== strpos( $key, 'sum:' ) ) {
				$whole[ $key ] = true;
			}
		}
		foreach ( $keys as $key ) {
			$versions[ $key ] = (int) ( $versions[ $key ] ?? 0 ) + 1;
		}
		// Page and summary versions of wholly bumped groups are obsolete: drop them to keep State small.
		foreach ( array_keys( $versions ) as $key ) {
			$base = self::base_group( $key );
			if ( $base !== $key && ( isset( $whole[ $base ] ) || isset( $whole['_all'] ) ) ) {
				unset( $versions[ $key ] );
			}
		}
		$versions['_changed'] = time();
		$this->state->set( 'sitemap_versions', $versions );

		$groups = array();
		foreach ( $keys as $key ) {
			$groups[ self::base_group( $key ) ] = true;
		}
		/**
		 * Fires after sitemap content changed (cache versions bumped).
		 *
		 * @param string[] $types Changed sitemap groups (pt-post, tax-category, authors, video, news, _all).
		 */
		do_action( 'searchpilot_sitemap_changed', array_keys( $groups ) );
		return array_keys( $groups );
	}

	/**
	 * Group a version key belongs to ("sum:pt-post" and "pt-post@3" → "pt-post").
	 *
	 * @param string $key Version key.
	 * @return string
	 */
	public static function base_group( $key ) {
		$key = (string) $key;
		if ( 0 === strpos( $key, 'sum:' ) ) {
			$key = substr( $key, 4 );
		}
		$at = strpos( $key, '@' );
		return false === $at ? $key : substr( $key, 0, $at );
	}

	/**
	 * Time of the last change (0 = never).
	 *
	 * @return int
	 */
	public function last_changed() {
		return (int) ( $this->versions()['_changed'] ?? 0 );
	}
}

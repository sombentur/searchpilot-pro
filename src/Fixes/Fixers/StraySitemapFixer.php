<?php
/**
 * Fixer for sitemap_gsc_errors: removes Search Console "sitemaps" that are not sitemaps.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Core\Changes;
use SearchPilot\Core\Logger;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * People often paste an article address (or ads.txt) into Search Console's
 * "Add a new sitemap" box hoping to get it indexed. Google then reports a
 * permanent sitemap error for it. Such entries — an address of this site
 * that is a page, post, ads.txt or robots.txt, never a real sitemap or feed —
 * are removed through the official Search Console API (sitemaps.delete).
 * Real sitemaps with errors are never removed: those need a person.
 */
class StraySitemapFixer extends AbstractFixer {

	/**
	 * Fix mode: automatic when at least one listed entry is clearly not a sitemap.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function mode( array $issue ) {
		return self::stray_entries( $issue ) ? 'deterministic' : 'advisory';
	}

	/**
	 * Entries of the issue that are not sitemaps.
	 *
	 * @param array $issue Issue.
	 * @return string[] Paths.
	 */
	public static function stray_entries( array $issue ) {
		$out = array();
		foreach ( (array) ( $issue['data']['sitemaps'] ?? array() ) as $row ) {
			$path = (string) ( $row['path'] ?? '' );
			if ( (int) ( $row['errors'] ?? 0 ) > 0 && empty( $row['is_sitemaps_index'] ) && self::is_stray( $path ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Whether an address of this site can never be a sitemap (pure).
	 *
	 * @param string $url Submitted "sitemap" address.
	 * @return bool
	 */
	public static function is_stray( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! Url::is_internal( $url ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$parts = explode( '/', untrailingslashit( $path ) );
		$base  = strtolower( (string) end( $parts ) );
		if ( '' !== (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return false; // e.g. /?feed=sitemap — could be a generated sitemap.
		}
		if ( in_array( $base, array( 'ads.txt', 'app-ads.txt', 'robots.txt' ), true ) ) {
			return true;
		}
		if ( false !== strpos( $base, 'sitemap' ) || preg_match( '#(^|/)(feed|rss|rss2|atom)(/|$)#i', trailingslashit( $path ) ) ) {
			return false;
		}
		// Sitemaps and feeds are files (.xml, .xml.gz, .txt, .rss, .atom); a pretty permalink
		// (no extension) or an .html/.php page is a document.
		$ext = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
		return '' === $ext || in_array( $ext, array( 'html', 'htm', 'php' ), true ) || ( '' === $base );
	}

	/**
	 * Legacy fixer entry point.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix( array $issue ) {
		$class = '\SearchPilot\Api\Google\SearchConsole';
		$gsc   = class_exists( $class ) ? $this->get( $class ) : null;
		if ( ! $gsc || ! method_exists( $gsc, 'delete_sitemap' ) || ! $gsc->is_connected() ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Connect Google Search Console first.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$stray = self::stray_entries( $issue );
		if ( ! $stray ) {
			return new \WP_Error( 'searchpilot_nothing_to_fix', __( 'These are real sitemaps; open Search Console → Sitemaps for the error details.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$removed = array();
		$last    = null;
		foreach ( $stray as $path ) {
			$result = $gsc->delete_sitemap( $path );
			if ( is_wp_error( $result ) ) {
				$last = $result;
				continue;
			}
			$removed[] = $path;
		}
		if ( ! $removed ) {
			return $last ? $last : new \WP_Error( 'searchpilot_fix_failed', __( 'Search Console did not remove the entry.', 'searchpilot-pro' ) );
		}
		$this->get( Changes::class )->record(
			'',
			array(
				'action'      => 'gsc_sitemap_removed',
				'object_type' => 'site',
				'object_id'   => 0,
				/* translators: %s: addresses. */
				'description' => sprintf( __( 'Removed from Search Console → Sitemaps (not a sitemap): %s. The page itself is untouched.', 'searchpilot-pro' ), implode( ', ', array_map( array( Url::class, 'display' ), $removed ) ) ),
				'after'       => array( 'removed' => $removed ),
				'source'      => (string) ( $issue['source'] ?? 'user' ),
			)
		);
		Logger::instance()->info( 'google', 'Removed non-sitemap entries from Search Console: ' . implode( ', ', $removed ) );
		// Re-read Google's list so the issue and the Sitemaps screen reflect the removal.
		$health = '\SearchPilot\Sitemap\SitemapHealth';
		if ( class_exists( $health ) ) {
			try {
				$this->get( $health )->run( 15 );
			} catch ( \Throwable $e ) {
				Logger::instance()->debug( 'sitemap', 'Sitemap re-check after clean-up failed: ' . $e->getMessage() );
			}
		}
		return true;
	}
}

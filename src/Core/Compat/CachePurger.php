<?php
/**
 * Purges page caches of common caching plugins.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core\Compat;

use SearchPilot\Core\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Used after SearchPilot changes something visible (sitemaps, robots.txt,
 * inserted links) so caches never serve stale SEO output.
 *
 * Some caches purge through response headers (LiteSpeed's X-LiteSpeed-Purge).
 * Purges requested after the response headers were sent — for example from
 * `shutdown` — are queued and delivered by a background request instead.
 */
class CachePurger {

	const QUEUE_OPTION = 'searchpilot_purge_queue';
	const QUEUE_HOOK   = 'searchpilot_purge_deferred';
	const QUEUE_MAX    = 500;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Scheduler $scheduler ) {
		$this->scheduler = $scheduler;
	}

	/**
	 * Hooks the deferred-purge runner (called by CoreModule).
	 */
	public function register() {
		add_action( self::QUEUE_HOOK, array( $this, 'flush_queue' ) );
	}

	/**
	 * Purges specific URLs.
	 *
	 * @param string[] $urls Absolute URLs.
	 */
	public function purge_urls( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		if ( empty( $urls ) ) {
			return;
		}
		if ( $this->too_late() ) {
			$this->defer( $urls, array() );
			return;
		}
		$this->send_urls( $urls );
	}

	/**
	 * Purges a post's page and related URLs through each plugin's post API.
	 *
	 * @param int $post_id Post id.
	 */
	public function purge_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		clean_post_cache( $post_id );
		if ( $this->too_late() ) {
			$url = get_permalink( $post_id );
			$this->defer( $url ? array( $url ) : array(), array( $post_id ) );
			return;
		}
		$this->send_post( $post_id );
	}

	/**
	 * Delivers queued purges (background request).
	 */
	public function flush_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		delete_option( self::QUEUE_OPTION );
		if ( ! is_array( $queue ) ) {
			return;
		}
		foreach ( array_unique( array_map( 'intval', (array) ( $queue['posts'] ?? array() ) ) ) as $post_id ) {
			if ( $post_id ) {
				$this->send_post( $post_id );
			}
		}
		$urls = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $queue['urls'] ?? array() ) ) ) ) );
		if ( $urls ) {
			$this->send_urls( $urls );
		}
	}

	/**
	 * Whether header-based purges can no longer reach the server.
	 *
	 * @return bool
	 */
	private function too_late() {
		return headers_sent() || doing_action( 'shutdown' );
	}

	/**
	 * Queues purges for the next background request.
	 *
	 * @param string[] $urls     URLs.
	 * @param int[]    $post_ids Post ids.
	 */
	private function defer( array $urls, array $post_ids ) {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();

		$queue['urls']  = array_slice( array_values( array_unique( array_merge( (array) ( $queue['urls'] ?? array() ), $urls ) ) ), -self::QUEUE_MAX );
		$queue['posts'] = array_slice( array_values( array_unique( array_merge( (array) ( $queue['posts'] ?? array() ), array_map( 'intval', $post_ids ) ) ) ), -self::QUEUE_MAX );
		update_option( self::QUEUE_OPTION, $queue, false );
		$this->scheduler->enqueue_async( self::QUEUE_HOOK, array(), 5 );
	}

	/**
	 * Sends URL purges to every supported cache.
	 *
	 * @param string[] $urls URLs.
	 */
	private function send_urls( array $urls ) {
		foreach ( $urls as $url ) {
			// LiteSpeed Cache.
			do_action( 'litespeed_purge_url', $url );
			// Vayu Speed.
			do_action( 'vayu_purge_url', $url );
			// W3 Total Cache.
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url );
			}
			// WP Super Cache.
			if ( function_exists( 'wpsc_delete_url_cache' ) ) {
				wpsc_delete_url_cache( $url );
			}
			// SiteGround Optimizer.
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache( $url );
			}
			// Nginx Helper.
			global $nginx_purger;
			if ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_url' ) ) {
				$nginx_purger->purge_url( $url );
			}
		}
		// WP Rocket (also forwards to its Cloudflare add-on).
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}
		/**
		 * Fires so other caches (CDN, hosting) can purge the URLs.
		 *
		 * @param string[] $urls URLs.
		 */
		do_action( 'searchpilot_purge_urls', $urls );
	}

	/**
	 * Sends a post purge through each plugin's post API.
	 *
	 * @param int $post_id Post id.
	 */
	private function send_post( $post_id ) {
		do_action( 'litespeed_purge_post', $post_id );
		do_action( 'vayu_purge_post', $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		$url = get_permalink( $post_id );
		if ( $url ) {
			$this->send_urls( array( $url ) );
		}
	}
}

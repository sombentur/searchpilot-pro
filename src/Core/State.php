<?php
/**
 * Runtime state (not user settings).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Small key/value store for runtime state: wizard progress, last job runs,
 * connection health, sitemap version, audit summaries. Stored in one
 * non-autoloaded option. Values must be JSON-serializable.
 */
class State {

	const OPTION = 'searchpilot_state';

	/**
	 * Cached state.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Reads all state.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$value       = get_option( self::OPTION, array() );
			$this->cache = is_array( $value ) ? $value : array();
		}
		return $this->cache;
	}

	/**
	 * Reads a key.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Writes a key.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->reload();
		$this->cache[ $key ] = $value;
		update_option( self::OPTION, $this->cache, false );
	}

	/**
	 * Writes several keys at once.
	 *
	 * @param array $values Key/value pairs.
	 */
	public function set_many( array $values ) {
		$this->reload();
		foreach ( $values as $key => $value ) {
			$this->cache[ $key ] = $value;
		}
		update_option( self::OPTION, $this->cache, false );
	}

	/**
	 * Deletes a key.
	 *
	 * @param string $key Key.
	 */
	public function delete( $key ) {
		$this->reload();
		unset( $this->cache[ $key ] );
		update_option( self::OPTION, $this->cache, false );
	}

	/**
	 * Increments a numeric key and returns the new value.
	 *
	 * @param string $key  Key.
	 * @param int    $step Step.
	 * @return int
	 */
	public function increment( $key, $step = 1 ) {
		$value = (int) $this->get( $key, 0 ) + $step;
		$this->set( $key, $value );
		return $value;
	}

	/**
	 * Drops the in-memory copy so the next read comes from the database
	 * (long-running background jobs call this before reading shared progress).
	 */
	public function refresh() {
		$this->reload();
	}

	/**
	 * Re-reads the option so concurrent processes do not clobber each other's keys.
	 */
	private function reload() {
		wp_cache_delete( self::OPTION, 'options' );
		$this->cache = null;
		$this->all();
	}
}

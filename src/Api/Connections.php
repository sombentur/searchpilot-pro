<?php
/**
 * Connection status of every search engine.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api;

use SearchPilot\Api\Bing\BingWebmaster;
use SearchPilot\Api\Google\GoogleAuth;
use SearchPilot\Api\Google\IndexingApi;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\IndexNow\IndexNow;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap, non-secret status summary (no remote calls): used by the
 * dashboard, the Search Engines screen, the setup wizard and other modules.
 */
class Connections {

	/**
	 * Google auth.
	 *
	 * @var GoogleAuth
	 */
	private $google;

	/**
	 * Search Console.
	 *
	 * @var SearchConsole
	 */
	private $gsc;

	/**
	 * Indexing API.
	 *
	 * @var IndexingApi
	 */
	private $indexing;

	/**
	 * Bing.
	 *
	 * @var BingWebmaster
	 */
	private $bing;

	/**
	 * IndexNow.
	 *
	 * @var IndexNow
	 */
	private $indexnow;

	/**
	 * Constructor.
	 *
	 * @param GoogleAuth    $google   Google auth.
	 * @param SearchConsole $gsc      Search Console.
	 * @param IndexingApi   $indexing Indexing API.
	 * @param BingWebmaster $bing     Bing.
	 * @param IndexNow      $indexnow IndexNow.
	 */
	public function __construct( GoogleAuth $google, SearchConsole $gsc, IndexingApi $indexing, BingWebmaster $bing, IndexNow $indexnow ) {
		$this->google   = $google;
		$this->gsc      = $gsc;
		$this->indexing = $indexing;
		$this->bing     = $bing;
		$this->indexnow = $indexnow;
	}

	/**
	 * Status of every connection.
	 *
	 * @return array{google:array,bing:array,indexnow:array}
	 */
	public function status() {
		return array(
			'google'   => $this->google_status(),
			'bing'     => $this->bing_status(),
			'indexnow' => $this->indexnow_status(),
		);
	}

	/**
	 * Google status.
	 *
	 * @return array
	 */
	public function google_status() {
		$health     = $this->google->connection();
		$configured = $this->google->is_configured();
		$error      = 'error' === $health['status'] ? $health['error'] : '';
		return array(
			'connected'    => $configured && $this->gsc->is_connected() && '' === $error,
			'method'       => $configured ? $this->google->method() : '',
			'property'     => $this->gsc->property(),
			'account'      => $configured ? $this->google->account() : '',
			'indexing_api' => $this->indexing->is_enabled(),
			'error'        => $error,
			'checked_at'   => $health['checked_at'],
		);
	}

	/**
	 * Bing status.
	 *
	 * @return array
	 */
	public function bing_status() {
		$health = $this->bing->connection();
		return array(
			'connected' => $this->bing->is_connected(),
			'site'      => $this->bing->site(),
			'quota'     => $this->bing->has_key() ? $health['quota'] : null,
			'error'     => 'error' === $health['status'] ? $health['error'] : '',
		);
	}

	/**
	 * IndexNow status.
	 *
	 * @return array
	 */
	public function indexnow_status() {
		$last = $this->indexnow->last_verification();
		return array(
			'enabled'  => $this->indexnow->is_enabled(),
			'key'      => $this->indexnow->key(),
			'key_url'  => $this->indexnow->key_url(),
			'verified' => $last['verified'],
			'owner'    => $this->indexnow->owner(),
		);
	}
}

<?php
/**
 * Planner for indexnow_duplicate_submitter.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Api\IndexNow\IndexNow;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lets SearchPilot take over IndexNow from Rank Math (assisted, one click,
 * never automatic: it switches off another plugin's feature).
 *
 * Rank Math keeps its active modules as a list of module ids in the option
 * `rank_math_modules`; its own `Helper::update_modules( array(
 * 'instant-indexing' => 'off' ) )` removes the id from that list, and the
 * module id of "Instant Indexing" (IndexNow) is `instant-indexing`
 * (`includes/module/class-manager.php`). The plan writes exactly that
 * option change through the logged, undoable option change kind; undo puts
 * the module back. Rank Math's IndexNow key and history stay stored.
 *
 * Other submitters (the AIOSEO IndexNow addon, …) only get a manual step.
 */
class IndexNowFixer extends AbstractFixer {

	const OPTION = 'rank_math_modules';

	const MODULE = 'instant-indexing';

	/**
	 * Fix mode for an issue.
	 *
	 * @param array $issue Issue.
	 * @return string assisted|advisory
	 */
	public function mode( array $issue ) {
		return 'rank_math' === $this->owner( $issue ) && in_array( self::MODULE, self::modules(), true ) ? 'assisted' : 'advisory';
	}

	/**
	 * What a person does when there is no automatic fix.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function manual_step( array $issue ) {
		$owner = $this->owner( $issue );
		if ( 'rank_math' === $owner ) {
			return __( 'Nothing is broken. To let SearchPilot send IndexNow notifications (with its queue, retries and history), open Rank Math → Dashboard → Modules and switch off “Instant Indexing”. SearchPilot takes over automatically.', 'searchpilot-pro' );
		}
		if ( 'aioseo' === $owner ) {
			return __( 'Nothing is broken. To let SearchPilot send IndexNow notifications, deactivate the “AIOSEO – IndexNow” addon (All in One SEO → Addons, or the Plugins screen). SearchPilot takes over automatically.', 'searchpilot-pro' );
		}
		/* translators: %s: plugin name. */
		return sprintf( __( 'Nothing is broken. To let SearchPilot send IndexNow notifications, switch off IndexNow in %s. SearchPilot takes over automatically.', 'searchpilot-pro' ), (string) ( $issue['data']['owner_name'] ?? __( 'the other plugin', 'searchpilot-pro' ) ) );
	}

	/**
	 * Plans the takeover.
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error
	 */
	public function plan( array $issue ) {
		$indexnow = $this->get( IndexNow::class );
		if ( ! $indexnow->is_enabled() ) {
			return new \WP_Error( 'searchpilot_indexnow_off', __( 'SearchPilot’s own IndexNow is switched off or has no valid key. Turn it on in SearchPilot → Search Engines → IndexNow first, otherwise nothing would notify search engines after the takeover.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( 'rank_math' !== $this->owner( $issue ) ) {
			return new \WP_Error( 'searchpilot_manual_only', $this->manual_step( $issue ), array( 'status' => 409 ) );
		}
		$current = get_option( self::OPTION, array() );
		$modules = self::modules();
		if ( ! in_array( self::MODULE, $modules, true ) ) {
			return new \WP_Error( 'searchpilot_nothing_to_fix', __( 'Rank Math’s Instant Indexing module is already switched off. Re-check to close this issue.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$plan              = self::new_plan(
			$issue,
			__( 'Switch off Rank Math’s Instant Indexing module so SearchPilot sends IndexNow notifications', 'searchpilot-pro' ),
			'site',
			0
		);
		$plan['changes'][] = self::change(
			'option',
			0,
			self::OPTION,
			__( 'Rank Math modules', 'searchpilot-pro' ),
			__( 'Instant Indexing (IndexNow): on', 'searchpilot-pro' ),
			__( 'Instant Indexing (IndexNow): off — SearchPilot sends IndexNow', 'searchpilot-pro' ),
			self::without_module( $modules ),
			$current
		);
		$plan['warnings']  = array(
			__( 'This switches off a feature of another plugin. Rank Math’s IndexNow key and submission history stay stored; Undo switches the module back on.', 'searchpilot-pro' ),
			__( 'Rank Math’s “Instant Indexing” menu and its manual submit buttons disappear. SearchPilot notifies search engines through its own queue (Search Engines → IndexNow).', 'searchpilot-pro' ),
		);
		return $plan;
	}

	/**
	 * Re-evaluates the issue right after Rank Math's module list changed
	 * (the takeover, its undo, or a change in Rank Math itself), so the fix
	 * is verified without waiting for the daily health check.
	 */
	public function refresh_issue() {
		$conflicts = $this->get( ConflictDetector::class );
		$conflicts->reset();
		$indexnow = $this->get( IndexNow::class );
		$issues   = $this->get( IssueRepository::class );
		$owner    = $indexnow->owner();
		if ( $indexnow->is_enabled() && 'searchpilot' !== $owner && 'off' !== $owner ) {
			$issues->upsert(
				'indexnow_duplicate_submitter',
				array(
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => $indexnow->owner_name(),
					'data'        => array(
						'owner'      => $owner,
						'owner_name' => $indexnow->owner_name(),
					),
				)
			);
		} else {
			$issues->resolve( 'indexnow_duplicate_submitter', 'site', 0 );
		}
	}

	/**
	 * Current IndexNow owner (live; the issue data as fallback).
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	private function owner( array $issue ) {
		$owner = (string) $this->get( IndexNow::class )->owner();
		if ( '' === $owner ) {
			$owner = (string) ( $issue['data']['owner'] ?? '' );
		}
		return $owner;
	}

	/**
	 * Rank Math's active module ids.
	 *
	 * @return string[]
	 */
	public static function modules() {
		$modules = get_option( self::OPTION, array() );
		return is_array( $modules ) ? array_values( array_map( 'strval', $modules ) ) : array();
	}

	/**
	 * The module list without Instant Indexing (same result as Rank Math's
	 * Helper::update_modules( [ 'instant-indexing' => 'off' ] ), re-indexed).
	 *
	 * @param string[] $modules Module ids.
	 * @return string[]
	 */
	public static function without_module( array $modules ) {
		return array_values( array_unique( array_diff( array_map( 'strval', $modules ), array( self::MODULE ) ) ) );
	}
}

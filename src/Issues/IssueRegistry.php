<?php
/**
 * Registry of issue types.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Modules describe the problems they detect here. Registration is lazy:
 * hook `searchpilot_register_issue_types` and call register() — the action
 * fires the first time a type is needed (after `init`, so translations work).
 *
 * Definition keys:
 *   label        (string)   Short title, e.g. "Page is noindexed".
 *   category     (string)   indexing|technical|sitemap|content|links|schema|performance|opportunity|security.
 *   severity     (string)   critical|high|medium|opportunity (default severity; upsert may override).
 *   what         (string|callable(array $issue):string) What happened.
 *   why          (string|callable) Why it matters.
 *   fix          (string|callable) Recommended fix.
 *   planner      (callable(array $issue):array|\WP_Error) Returns a Fixes\Plan (previewable fix, applied
 *                and verified by Fixes\FixEngine). Preferred over `fixer`.
 *   fixer        (callable(array $issue):true|\WP_Error|null) Legacy: performs the fix directly (no preview).
 *   mode         (string|callable(array $issue):string) deterministic (safe unattended in Autopilot) |
 *                assisted (preview + one click; never unattended) | advisory (no automatic fix).
 *                Default: assisted when a planner/fixer exists, else advisory.
 *   manual_step  (string|callable(array $issue):string) The concrete thing a person does when there is
 *                no automatic fix; shown as the primary action.
 *   scope        (string)   'incoming' for cross-object types that depend on OTHER objects (e.g. orphan_page:
 *                inbound links); the fix engine does not treat them as introduced by a fix of the post itself.
 *   measurable_after (string[]) Types whose fix makes this type measurable for the first time (e.g.
 *                description_length after missing_meta_description): it appearing after such a fix is not
 *                treated as a new problem caused by it.
 *   fix_label    (string)   Button label (default "Preview fix" with a planner, "Fix automatically" with a fixer).
 *   task         (string)   AutomationPolicy task that governs automatic fixing (default safe_fixes).
 *   destructive  (bool)     Always requires explicit approval (default false).
 *   action       (string)   Recommended action code shown in lists, e.g. update_title, fix_indexing.
 *
 * Other modules add keys to a type they do not own with extend() on
 * `searchpilot_register_issue_types` at priority 20 (e.g. a planner).
 */
class IssueRegistry {

	const MODES = array( 'deterministic', 'assisted', 'advisory' );

	const SEVERITIES = array(
		'critical'    => 1,
		'high'        => 2,
		'medium'      => 3,
		'opportunity' => 4,
		'passed'      => 5,
	);

	/**
	 * Definitions.
	 *
	 * @var array<string,array>
	 */
	private $types = array();

	/**
	 * Extensions for types not registered yet.
	 *
	 * @var array<string,array>
	 */
	private $pending = array();

	/**
	 * Whether the registration action fired.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Registers a type.
	 *
	 * @param string $type Type id (snake_case).
	 * @param array  $def  Definition.
	 */
	public function register( $type, array $def ) {
		$this->types[ $type ] = wp_parse_args(
			$def,
			array(
				'label'       => $type,
				'category'    => 'technical',
				'severity'    => 'medium',
				'what'        => '',
				'why'         => '',
				'fix'         => '',
				'fixer'       => null,
				'planner'     => null,
				'mode'        => '',
				'manual_step' => '',
				'scope'       => '',
				'fix_label'   => '',
				'task'        => \SearchPilot\Core\AutomationPolicy::TASK_SAFE_FIXES,
				'destructive' => false,
				'action'      => '',
			)
		);
		if ( isset( $this->pending[ $type ] ) ) {
			$this->types[ $type ] = array_merge( $this->types[ $type ], $this->pending[ $type ] );
			unset( $this->pending[ $type ] );
		}
	}

	/**
	 * Merges keys into a type registered by another module (e.g. adds a
	 * `planner`, `mode` or `manual_step`). Extensions of a type that is not
	 * registered yet are applied when it is.
	 *
	 * @param string $type Type id.
	 * @param array  $def  Keys to set.
	 */
	public function extend( $type, array $def ) {
		if ( isset( $this->types[ $type ] ) ) {
			$this->types[ $type ] = array_merge( $this->types[ $type ], $def );
			return;
		}
		$this->pending[ $type ] = array_merge( $this->pending[ $type ] ?? array(), $def );
	}

	/**
	 * Fix mode of a type, resolved for one issue when `mode` is a callable.
	 *
	 * @param array      $def   Definition.
	 * @param array|null $issue Issue (null: type level — callables resolve to the default).
	 * @return string deterministic|assisted|advisory
	 */
	public function mode_for( array $def, $issue = null ) {
		$can  = self::has_planner( $def ) || ( ! empty( $def['fixer'] ) && is_callable( $def['fixer'] ) );
		$mode = $def['mode'] ?? '';
		if ( is_callable( $mode ) && ! is_string( $mode ) ) {
			$mode = '';
			if ( is_array( $issue ) ) {
				try {
					$mode = (string) call_user_func( $def['mode'], $issue );
				} catch ( \Throwable $e ) {
					$mode = 'advisory';
				}
			}
		}
		$mode = is_string( $mode ) ? $mode : '';
		if ( ! $can ) {
			return 'advisory';
		}
		return in_array( $mode, self::MODES, true ) ? $mode : 'assisted';
	}

	/**
	 * Whether a one-click (assisted) fix of this issue may also run unattended when the
	 * automation mode allows its task: definition key `auto_ok` (bool or callable( $issue )).
	 * Fixes that write fixed editorial text or switch off another plugin's feature never set it.
	 *
	 * @param array      $def   Definition.
	 * @param array|null $issue Issue.
	 * @return bool
	 */
	public static function auto_ok( array $def, $issue = null ) {
		$flag = $def['auto_ok'] ?? false;
		if ( is_callable( $flag ) && ! is_string( $flag ) ) {
			try {
				return is_array( $issue ) && (bool) call_user_func( $flag, $issue );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return true === $flag;
	}

	/**
	 * Whether a definition has a planner.
	 *
	 * @param array $def Definition.
	 * @return bool
	 */
	public static function has_planner( array $def ) {
		return ! empty( $def['planner'] ) && is_callable( $def['planner'] );
	}

	/**
	 * A type definition or null.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	public function get( $type ) {
		$this->load();
		return $this->types[ $type ] ?? null;
	}

	/**
	 * All definitions.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		$this->load();
		return $this->types;
	}

	/**
	 * Severity rank (1 = most severe).
	 *
	 * @param string $severity Severity.
	 * @return int
	 */
	public static function rank( $severity ) {
		return self::SEVERITIES[ $severity ] ?? 3;
	}

	/**
	 * Fires the registration action once.
	 */
	private function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		/**
		 * Register issue types.
		 *
		 * @param IssueRegistry $registry Registry.
		 */
		do_action( 'searchpilot_register_issue_types', $this );
	}
}

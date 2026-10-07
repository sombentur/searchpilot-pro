<?php
/**
 * Redirect manager.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Redirects;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Applies redirect rules (only while SearchPilot owns the `redirects`
 * feature), keeps them healthy and records every change in the activity log.
 *
 * Front end:
 *   parse_request (priority 2)     exact rules — one indexed query, skipped entirely when no exact rules exist.
 *   template_redirect (priority 1) prefix/regex rules, only for 404 responses (max 200 rules).
 *   wp (priority 1)                410/451 exact rules render the theme's 404 template with that status.
 *
 * Never redirected: wp-admin, wp-login, REST, cron, AJAX, sitemaps,
 * robots.txt and the IndexNow key file. Only GET/HEAD requests are redirected.
 */
class RedirectsModule implements Module {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Repository.
	 *
	 * @var RedirectRepository
	 */
	private $repo;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Automation policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * 404 monitor.
	 *
	 * @var NotFoundMonitor
	 */
	private $monitor;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Matched 410/451 rule for the current request.
	 *
	 * @var array|null
	 */
	private $gone = null;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param RedirectRepository $repo      Repository.
	 * @param ConflictDetector   $conflicts Conflict detector.
	 * @param Changes            $changes   Changes.
	 * @param AutomationPolicy   $policy    Policy.
	 * @param IssueRepository    $issues    Issues.
	 * @param Scheduler          $scheduler Scheduler.
	 * @param Logger             $logger    Logger.
	 * @param CachePurger        $purger    Cache purger.
	 * @param NotFoundMonitor    $monitor   404 monitor.
	 * @param MetaStore          $meta      Meta store.
	 */
	public function __construct( Settings $settings, RedirectRepository $repo, ConflictDetector $conflicts, Changes $changes, AutomationPolicy $policy, IssueRepository $issues, Scheduler $scheduler, Logger $logger, CachePurger $purger, NotFoundMonitor $monitor, MetaStore $meta ) {
		$this->settings  = $settings;
		$this->repo      = $repo;
		$this->conflicts = $conflicts;
		$this->changes   = $changes;
		$this->policy    = $policy;
		$this->issues    = $issues;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
		$this->purger    = $purger;
		$this->monitor   = $monitor;
		$this->meta      = $meta;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'exact_redirect' ), 2 );
		add_action( 'wp', array( $this, 'apply_gone' ), 1 );
		add_action( 'template_redirect', array( $this, 'pattern_redirect' ), 1 );

		add_action( 'searchpilot_url_changed', array( $this, 'on_url_changed' ), 20 );
		add_action( 'searchpilot_register_revert_handlers', array( $this, 'register_handlers' ) );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_daily', array( $this, 'schedule_daily' ), 30 );
		add_action( 'searchpilot_redirects_daily', array( $this, 'daily' ) );
		add_action( 'searchpilot_redirects_analyze', array( $this, 'analyze' ) );

		$this->monitor->register( $this );
	}

	/**
	 * Whether SearchPilot applies redirects on this site.
	 *
	 * @return bool
	 */
	public function active() {
		return $this->conflicts->owns( 'redirects' );
	}

	/**
	 * Whether the current request matched a 410/451 rule.
	 *
	 * @return bool
	 */
	public function is_gone() {
		return null !== $this->gone;
	}

	/* ------------------------------------------------------------------ *
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Raw request URI (path + query).
	 *
	 * @return string
	 */
	private function request_uri() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed/matched, never output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return '' === $uri ? '/' : $uri;
	}

	/**
	 * Whether this request may be redirected at all.
	 *
	 * @return bool
	 */
	private function redirectable_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Reserved paths beyond the built-in list (IndexNow key file).
	 *
	 * @return string[]
	 */
	public function extra_excluded() {
		$key = (string) $this->settings->get( 'indexing.indexnow_key', '' );
		$out = array();
		if ( '' !== $key ) {
			$out[] = strtolower( wp_make_link_relative( home_url( '/' . $key . '.txt' ) ) );
			$out[] = '/' . strtolower( $key ) . '.txt';
		}
		return $out;
	}

	/**
	 * Path of the home URL ('' or '/blog').
	 *
	 * @return string
	 */
	public static function home_path() {
		return rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	}

	/**
	 * parse_request: exact rules.
	 *
	 * @param \WP $wp Request.
	 */
	public function exact_redirect( $wp ) {
		if ( ! $this->redirectable_request() ) {
			return;
		}
		if ( $this->repo->counts()['exact'] < 1 || ! $this->active() ) {
			return;
		}
		$uri = $this->request_uri();
		if ( RedirectMatcher::is_excluded( RedirectMatcher::normalize_path( $uri ), $this->extra_excluded(), self::home_path() ) ) {
			return;
		}
		$hashes = RedirectMatcher::request_hashes( $uri );
		$rules  = $this->repo->find_exact( array( $hashes['full'], $hashes['path'] ) );
		if ( empty( $rules ) ) {
			return;
		}
		$match = RedirectMatcher::match( $uri, $rules );
		if ( $match ) {
			$this->execute( $match );
		}
	}

	/**
	 * template_redirect (priority 1): prefix and regex rules for 404s.
	 */
	public function pattern_redirect() {
		if ( null !== $this->gone || ! is_404() || ! $this->redirectable_request() ) {
			return;
		}
		if ( $this->repo->counts()['patterns'] < 1 || ! $this->active() ) {
			return;
		}
		$uri = $this->request_uri();
		if ( RedirectMatcher::is_excluded( RedirectMatcher::normalize_path( $uri ), $this->extra_excluded(), self::home_path() ) ) {
			return;
		}
		$match = RedirectMatcher::match( $uri, $this->repo->pattern_rules() );
		if ( ! $match ) {
			return;
		}
		$this->execute( $match );
		if ( null !== $this->gone ) {
			// Already a 404 query: just send the rule's status.
			$this->apply_gone();
		}
	}

	/**
	 * Applies a match: redirects and exits, or remembers a 410/451.
	 *
	 * @param array $match Match from RedirectMatcher::match().
	 */
	private function execute( array $match ) {
		$rule = $match['rule'];
		$code = (int) $rule['status_code'];
		if ( in_array( $code, array( 410, 451 ), true ) ) {
			$this->gone = $match;
			$this->repo->hit( (int) $rule['id'] );
			return;
		}
		$target = (string) $match['target'];
		if ( '' === $target ) {
			return;
		}
		$external = RedirectMatcher::is_external( $target );
		if ( ! $external ) {
			$request_key = RedirectMatcher::key( $match['request'] );
			$target_path = RedirectMatcher::target_path( $target );
			if ( RedirectMatcher::key( $target_path ) === $request_key ) {
				return;
			}
			// Loop guard: A → B while B → A.
			$next = $this->repo->find_exact( array( RedirectMatcher::hash( $target_path ) ) );
			foreach ( $next as $next_rule ) {
				if ( '' !== (string) $next_rule['target'] && ! RedirectMatcher::is_external( $next_rule['target'] ) && RedirectMatcher::key( RedirectMatcher::target_path( $next_rule['target'] ) ) === $request_key ) {
					if ( ! get_transient( 'searchpilot_redirect_loop_' . (int) $rule['id'] ) ) {
						set_transient( 'searchpilot_redirect_loop_' . (int) $rule['id'], 1, HOUR_IN_SECONDS );
						$this->logger->warning( 'redirects', sprintf( 'Redirect loop between rules #%d and #%d was not followed.', (int) $rule['id'], (int) $next_rule['id'] ) );
						$this->scheduler->enqueue_async( 'searchpilot_redirects_analyze', array(), 30 );
					}
					return;
				}
			}
		}
		$this->repo->hit( (int) $rule['id'] );
		$url = $external ? $target : self::absolute( $target );
		if ( $external ) {
			// External targets are only accepted from administrators at save time.
			wp_redirect( $url, $code, 'SearchPilot' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		} else {
			wp_safe_redirect( $url, $code, 'SearchPilot' );
		}
		exit;
	}

	/**
	 * wp (priority 1): renders a matched 410/451 as the theme's 404 template.
	 */
	public function apply_gone() {
		if ( null === $this->gone ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && ! $wp_query->is_404() ) {
			$wp_query->set_404();
		}
		status_header( (int) $this->gone['rule']['status_code'] );
		nocache_headers();
		add_filter( 'redirect_canonical', '__return_false', 99 );
		add_filter( 'do_redirect_guess_404_permalink', '__return_false', 99 );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );
	}

	/**
	 * Absolute URL of a stored internal target (root-relative path).
	 *
	 * @param string $target Target.
	 * @return string
	 */
	public static function absolute( $target ) {
		$target = (string) $target;
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return Url::origin( home_url( '/' ) ) . ( '' === $target ? '/' : $target );
	}

	/* ------------------------------------------------------------------ *
	 * Rule changes (logged, undoable)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the current user may create external redirects.
	 *
	 * @return bool
	 */
	private function user_may_redirect_externally() {
		return current_user_can( 'manage_options' ) || ( is_multisite() && current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Validation options.
	 *
	 * @param array $opts Options.
	 * @return array
	 */
	private function validation_opts( array $opts ) {
		return array(
			'allow_external' => $opts['allow_external'] ?? $this->user_may_redirect_externally(),
			'excluded'       => $this->extra_excluded(),
			'home_path'      => self::home_path(),
		);
	}

	/**
	 * Creates a rule.
	 *
	 * @param array $input { source, target, match_type, status_code, enabled, notes }.
	 * @param array $opts {
	 *     @type string $origin         manual|slug_change|import|404|fix|deleted.
	 *     @type int    $object_id      Post id the rule belongs to.
	 *     @type string $source         Activity source (user|assisted|autopilot|manual|system).
	 *     @type string $description    Activity description.
	 *     @type string $group_id       Activity group.
	 *     @type bool   $allow_external Override the capability check (system imports only).
	 * }
	 * @return array|\WP_Error Presented rule.
	 */
	public function create_rule( array $input, array $opts = array() ) {
		$clean = RedirectMatcher::validate( $input, $this->validation_opts( $opts ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$duplicate = $this->repo->find_duplicate( $clean['match_type'], $clean['source'], $clean['source_hash'] );
		if ( $duplicate ) {
			return new \WP_Error(
				'searchpilot_duplicate',
				/* translators: %d: rule id. */
				sprintf( __( 'A redirect for this address already exists (rule #%d). Edit that rule instead.', 'searchpilot-pro' ), (int) $duplicate['id'] ),
				array(
					'status'   => 409,
					'existing' => (int) $duplicate['id'],
				)
			);
		}
		$id = $this->repo->insert(
			array_merge(
				$clean,
				array(
					'enabled'   => array_key_exists( 'enabled', $input ) ? (int) (bool) $input['enabled'] : 1,
					'notes'     => (string) ( $input['notes'] ?? '' ),
					'origin'    => (string) ( $opts['origin'] ?? 'manual' ),
					'object_id' => (int) ( $opts['object_id'] ?? 0 ),
				)
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'searchpilot_db', __( 'The redirect could not be saved.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		$after = $this->repo->raw( $id );
		$this->changes->record(
			'redirect_created',
			array(
				'action'      => 'redirect_created',
				'description' => $opts['description'] ?? sprintf(
					/* translators: 1: source, 2: target or status. */
					__( 'Created redirect %1$s → %2$s', 'searchpilot-pro' ),
					Url::display( $clean['source'] ),
					'' !== $clean['target'] ? Url::display( $clean['target'] ) : (string) $clean['status_code']
				),
				'object_type' => 'redirect',
				'object_id'   => $id,
				'before'      => array( 'rows' => array( $id => null ) ),
				'after'       => array( 'rows' => array( $id => $after ) ),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
		$this->after_change( array( $after ) );
		return $this->present( $this->repo->get( $id ) );
	}

	/**
	 * Updates a rule.
	 *
	 * @param int   $id    Rule id.
	 * @param array $input Fields to change.
	 * @param array $opts  source, description, group_id, allow_external.
	 * @return array|\WP_Error
	 */
	public function update_rule( $id, array $input, array $opts = array() ) {
		$before = $this->repo->raw( $id );
		if ( ! $before ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'Redirect not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		$merged = array(
			'source'      => array_key_exists( 'source', $input ) ? $input['source'] : $before['source'],
			'target'      => array_key_exists( 'target', $input ) ? $input['target'] : $before['target'],
			'match_type'  => array_key_exists( 'match_type', $input ) ? $input['match_type'] : $before['match_type'],
			'status_code' => array_key_exists( 'status_code', $input ) ? $input['status_code'] : $before['status_code'],
		);
		$clean = RedirectMatcher::validate( $merged, $this->validation_opts( $opts ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$duplicate = $this->repo->find_duplicate( $clean['match_type'], $clean['source'], $clean['source_hash'] );
		if ( $duplicate && (int) $duplicate['id'] !== (int) $id ) {
			return new \WP_Error(
				'searchpilot_duplicate',
				/* translators: %d: rule id. */
				sprintf( __( 'Another redirect already uses this source (rule #%d).', 'searchpilot-pro' ), (int) $duplicate['id'] ),
				array( 'status' => 409 )
			);
		}
		$fields = $clean;
		if ( array_key_exists( 'enabled', $input ) ) {
			$fields['enabled'] = (int) (bool) $input['enabled'];
		}
		if ( array_key_exists( 'notes', $input ) ) {
			$fields['notes'] = (string) $input['notes'];
		}
		$this->repo->update( $id, $fields );
		$after = $this->repo->raw( $id );
		if ( self::same_rule( $before, $after ) && ( $before['notes'] ?? '' ) === ( $after['notes'] ?? '' ) ) {
			return $this->present( $this->repo->get( $id ) );
		}
		$this->changes->record(
			'redirect_updated',
			array(
				'action'      => 'redirect_updated',
				'description' => $opts['description'] ?? sprintf(
					/* translators: %s: source. */
					__( 'Changed redirect %s', 'searchpilot-pro' ),
					Url::display( $after['source'] )
				),
				'object_type' => 'redirect',
				'object_id'   => (int) $id,
				'before'      => array( 'rows' => array( (int) $id => $before ) ),
				'after'       => array( 'rows' => array( (int) $id => $after ) ),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
		$this->after_change( array( $before, $after ) );
		return $this->present( $this->repo->get( $id ) );
	}

	/**
	 * Enables or disables rules.
	 *
	 * @param int[] $ids     Ids.
	 * @param bool  $enabled Enabled.
	 * @param array $opts    source, description.
	 * @return int Rules changed.
	 */
	public function set_enabled( array $ids, $enabled, array $opts = array() ) {
		$before = array();
		$after  = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = $this->repo->raw( $id );
			if ( ! $row || (int) $row['enabled'] === (int) (bool) $enabled ) {
				continue;
			}
			$this->repo->update( $id, array( 'enabled' => (int) (bool) $enabled ), false );
			$before[ $id ] = $row;
			$after[ $id ]  = $this->repo->raw( $id );
		}
		if ( empty( $after ) ) {
			return 0;
		}
		$this->repo->refresh_counts();
		if ( ! $enabled ) {
			$this->resolve_rule_issues( array_keys( $after ) );
		}
		$this->changes->record(
			1 === count( $after ) ? 'redirect_updated' : 'redirects_bulk',
			array(
				'action'      => $enabled ? 'redirect_enabled' : 'redirect_disabled',
				'description' => $opts['description'] ?? ( 1 === count( $after )
					? sprintf(
						/* translators: %s: source. */
						$enabled ? __( 'Enabled redirect %s', 'searchpilot-pro' ) : __( 'Disabled redirect %s', 'searchpilot-pro' ),
						Url::display( reset( $after )['source'] )
					)
					: sprintf(
						/* translators: %d: number of redirects. */
						$enabled ? _n( 'Enabled %d redirect', 'Enabled %d redirects', count( $after ), 'searchpilot-pro' ) : _n( 'Disabled %d redirect', 'Disabled %d redirects', count( $after ), 'searchpilot-pro' ),
						count( $after )
					) ),
				'object_type' => 'redirect',
				'object_id'   => 1 === count( $after ) ? (int) key( $after ) : 0,
				'before'      => array( 'rows' => $before ),
				'after'       => array( 'rows' => $after ),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
		$this->after_change( array_values( $before ) );
		return count( $after );
	}

	/**
	 * Deletes rules.
	 *
	 * @param int[] $ids  Ids.
	 * @param array $opts source, description.
	 * @return int Rules deleted.
	 */
	public function delete_rules( array $ids, array $opts = array() ) {
		$before = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = $this->repo->raw( $id );
			if ( $row ) {
				$before[ $id ] = $row;
			}
		}
		if ( empty( $before ) ) {
			return 0;
		}
		$n = $this->repo->delete( array_keys( $before ) );
		$this->resolve_rule_issues( array_keys( $before ) );
		$this->changes->record(
			1 === count( $before ) ? 'redirect_deleted' : 'redirects_bulk',
			array(
				'action'      => 'redirect_deleted',
				'description' => $opts['description'] ?? ( 1 === count( $before )
					? sprintf(
						/* translators: %s: source. */
						__( 'Deleted redirect %s', 'searchpilot-pro' ),
						Url::display( reset( $before )['source'] )
					)
					: sprintf(
						/* translators: %d: number of redirects. */
						_n( 'Deleted %d redirect', 'Deleted %d redirects', count( $before ), 'searchpilot-pro' ),
						count( $before )
					) ),
				'object_type' => 'redirect',
				'object_id'   => 1 === count( $before ) ? (int) key( $before ) : 0,
				'before'      => array( 'rows' => $before ),
				'after'       => array( 'rows' => array_fill_keys( array_keys( $before ), null ) ),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
		$this->after_change( array_values( $before ) );
		return $n;
	}

	/**
	 * Imports many rules at once (one activity entry, undoable as a whole).
	 *
	 * @param array  $rules  Rules { source, target, match_type, status_code, enabled, notes }.
	 * @param string $origin Origin.
	 * @param array  $opts   label (import source name), source.
	 * @return array{imported:int,duplicates:int,invalid:int,total:int,errors:string[]}
	 */
	public function import_rules( array $rules, $origin = 'import', array $opts = array() ) {
		$stats = array(
			'imported'   => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'total'      => count( $rules ),
			'errors'     => array(),
		);
		$vopts   = $this->validation_opts( array( 'allow_external' => $this->user_may_redirect_externally() || ! empty( $opts['allow_external'] ) ) );
		$seen    = array();
		$created = array();
		$now     = current_time( 'mysql', true );
		foreach ( array_slice( $rules, 0, 20000 ) as $i => $rule ) {
			$clean = RedirectMatcher::validate( (array) $rule, $vopts );
			if ( is_wp_error( $clean ) ) {
				++$stats['invalid'];
				if ( count( $stats['errors'] ) < 20 ) {
					$stats['errors'][] = sprintf( '%s: %s', mb_substr( (string) ( $rule['source'] ?? '#' . ( $i + 1 ) ), 0, 120 ), $clean->get_error_message() );
				}
				continue;
			}
			$sig = $clean['match_type'] . '|' . ( '' !== $clean['source_hash'] ? $clean['source_hash'] : $clean['source'] );
			if ( isset( $seen[ $sig ] ) || $this->repo->find_duplicate( $clean['match_type'], $clean['source'], $clean['source_hash'] ) ) {
				++$stats['duplicates'];
				continue;
			}
			$seen[ $sig ] = true;
			$id           = $this->repo->insert(
				array_merge(
					$clean,
					array(
						'enabled'    => array_key_exists( 'enabled', (array) $rule ) ? (int) (bool) $rule['enabled'] : 1,
						'notes'      => (string) ( $rule['notes'] ?? '' ),
						'origin'     => $origin,
						'created_at' => $now,
						'updated_at' => $now,
					)
				),
				false
			);
			if ( $id ) {
				$created[] = $id;
				++$stats['imported'];
			}
		}
		$this->repo->refresh_counts();
		if ( $created ) {
			$this->changes->record(
				'redirects_bulk',
				array(
					'action'      => 'redirects_imported',
					'description' => sprintf(
						/* translators: 1: number of redirects, 2: source name. */
						_n( 'Imported %1$d redirect from %2$s', 'Imported %1$d redirects from %2$s', count( $created ), 'searchpilot-pro' ),
						count( $created ),
						(string) ( $opts['label'] ?? __( 'a file', 'searchpilot-pro' ) )
					),
					'object_type' => 'redirect',
					'before'      => array( 'created_ids' => array() ),
					'after'       => array(
						'created_ids' => $created,
						'created_at'  => $now,
					),
					'source'      => $opts['source'] ?? 'import',
				)
			);
			$this->scheduler->enqueue_async( 'searchpilot_redirects_analyze', array(), 60 );
		}
		return $stats;
	}

	/**
	 * Registers the revert handlers.
	 *
	 * @param ActivityLog $log Log.
	 */
	public function register_handlers( ActivityLog $log ) {
		foreach ( array( 'redirect_created', 'redirect_updated', 'redirect_deleted', 'redirects_bulk' ) as $name ) {
			$log->register_handler( $name, array( $this, 'revert' ) );
		}
	}

	/**
	 * Revert handler: restores the "before" snapshot of every affected rule.
	 *
	 * Snapshots: rows => [ id => raw row|null ]; imports store created_ids.
	 * Rules edited again after SearchPilot's change are left alone unless forced.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Activity row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public function revert( array $before, array $after, $row, $force = false ) {
		$touched = array();
		if ( isset( $after['created_ids'] ) ) {
			$ids    = array_map( 'absint', (array) $after['created_ids'] );
			$delete = array();
			$kept   = 0;
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$rows = $this->repo->query(
					array(
						'ids'      => $chunk,
						'per_page' => 500,
					)
				)['items'];
				foreach ( $rows as $current ) {
					if ( ! $force && (string) $current['updated_at'] > (string) ( $after['created_at'] ?? '' ) ) {
						++$kept;
						continue;
					}
					$delete[]  = (int) $current['id'];
					$touched[] = $current;
				}
			}
			$this->repo->delete( $delete );
			$this->after_change( $touched );
			if ( $kept && ! $force ) {
				return new \WP_Error(
					'searchpilot_changed_since',
					/* translators: %d: number of redirects. */
					sprintf( _n( 'The import was undone except %d redirect that was edited afterwards.', 'The import was undone except %d redirects that were edited afterwards.', $kept, 'searchpilot-pro' ), $kept ),
					array( 'status' => 409 )
				);
			}
			return true;
		}

		$before_rows = (array) ( $before['rows'] ?? array() );
		$after_rows  = (array) ( $after['rows'] ?? array() );
		if ( ! $force ) {
			foreach ( $after_rows as $id => $expected ) {
				$current = $this->repo->raw( (int) $id );
				if ( ! self::same_rule( $current, is_array( $expected ) ? $expected : null ) ) {
					return new \WP_Error( 'searchpilot_changed_since', __( 'This redirect was edited again after SearchPilot’s change, so it was not undone automatically. Review it on the Redirects screen.', 'searchpilot-pro' ), array( 'status' => 409 ) );
				}
			}
		}
		foreach ( $before_rows as $id => $snapshot ) {
			$id      = (int) $id;
			$current = $this->repo->raw( $id );
			if ( null === $snapshot ) {
				if ( $current ) {
					$this->repo->delete( array( $id ), false );
					$touched[] = $current;
				}
				continue;
			}
			$snapshot = (array) $snapshot;
			if ( $current ) {
				$fields = array_intersect_key( $snapshot, array_flip( array( 'source', 'source_hash', 'match_type', 'target', 'status_code', 'enabled', 'notes', 'origin', 'object_id' ) ) );
				$this->repo->update( $id, $fields, false );
			} else {
				$this->repo->restore( $snapshot );
			}
			$touched[] = $snapshot;
		}
		$this->repo->refresh_counts();
		$this->after_change( $touched );
		return true;
	}

	/**
	 * Whether two raw rows are the same rule (ignoring hit counters).
	 *
	 * @param array|null $a A.
	 * @param array|null $b B.
	 * @return bool
	 */
	private static function same_rule( $a, $b ) {
		if ( null === $a || null === $b ) {
			return null === $a && null === $b;
		}
		foreach ( array( 'source', 'match_type', 'target', 'status_code', 'enabled' ) as $field ) {
			if ( (string) ( $a[ $field ] ?? '' ) !== (string) ( $b[ $field ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves loop/chain/error issues of rules that were deleted or disabled.
	 *
	 * @param int[] $ids Rule ids.
	 */
	private function resolve_rule_issues( array $ids ) {
		foreach ( array_slice( array_map( 'intval', $ids ), 0, 500 ) as $id ) {
			$this->issues->resolve_stale_for_object( 'redirect', $id, array( 'redirect_loop', 'redirect_chain', 'redirect_to_error', 'redirect_shadows_content' ), array() );
		}
	}

	/**
	 * Side effects after rule changes: purge page caches of the sources and
	 * re-check chains in the background.
	 *
	 * @param array $rows Affected raw rows.
	 */
	private function after_change( array $rows ) {
		$urls = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && in_array( $row['match_type'] ?? 'exact', array( 'exact', 'prefix' ), true ) && '' !== (string) ( $row['source'] ?? '' ) ) {
				$urls[] = self::absolute( (string) $row['source'] );
			}
		}
		if ( $urls ) {
			$this->purger->purge_urls( array_slice( array_unique( $urls ), 0, 200 ) );
		}
		$this->scheduler->enqueue_async( 'searchpilot_redirects_analyze', array(), 60 );
	}

	/**
	 * REST/UI representation of a rule.
	 *
	 * @param array|null $rule Hydrated rule.
	 * @return array|null
	 */
	public function present( $rule ) {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$external = RedirectMatcher::is_external( (string) $rule['target'] );
		$user     = ! empty( $rule['created_by'] ) ? get_userdata( (int) $rule['created_by'] ) : null;
		$object   = ! empty( $rule['object_id'] ) && in_array( $rule['origin'], array( 'slug_change', 'deleted' ), true ) ? get_post( (int) $rule['object_id'] ) : null;
		return array(
			'id'             => (int) $rule['id'],
			'source'         => (string) $rule['source'],
			'source_display' => 'regex' === $rule['match_type'] ? (string) $rule['source'] : Url::display( $rule['source'] ),
			'source_url'     => 'regex' === $rule['match_type'] ? '' : self::absolute( $rule['source'] ),
			'match_type'     => (string) $rule['match_type'],
			'target'         => (string) $rule['target'],
			'target_display' => Url::display( (string) $rule['target'] ),
			'target_url'     => '' === (string) $rule['target'] ? '' : ( $external ? (string) $rule['target'] : self::absolute( $rule['target'] ) ),
			'is_external'    => $external,
			'status_code'    => (int) $rule['status_code'],
			'enabled'        => (bool) $rule['enabled'],
			'origin'         => (string) $rule['origin'],
			'object_id'      => (int) $rule['object_id'],
			'object_title'   => $object ? self::plain( get_the_title( $object ) ) : '',
			'object_edit'    => $object ? (string) get_edit_post_link( $object->ID, 'raw' ) : '',
			'hits'           => (int) $rule['hits'],
			'last_hit'       => ! empty( $rule['last_hit'] ) ? mysql_to_rfc3339( $rule['last_hit'] ) : null,
			'notes'          => (string) $rule['notes'],
			'created_by'     => $user ? $user->display_name : '',
			'created_at'     => ! empty( $rule['created_at'] ) ? mysql_to_rfc3339( $rule['created_at'] ) : null,
			'updated_at'     => ! empty( $rule['updated_at'] ) ? mysql_to_rfc3339( $rule['updated_at'] ) : null,
		);
	}

	/**
	 * Tests a URL against all enabled rules (for the UI tester).
	 *
	 * @param string $url URL or path.
	 * @return array { matched, rule, target, target_url, status_code, chain: [ { rule, target } ], loop, excluded, active }
	 */
	public function test( $url ) {
		$path = RedirectMatcher::normalize_path( (string) $url );
		$out  = array(
			'path'        => $path,
			'active'      => $this->active(),
			'excluded'    => RedirectMatcher::is_excluded( $path, $this->extra_excluded(), self::home_path() ),
			'matched'     => false,
			'rule'        => null,
			'target'      => '',
			'target_url'  => '',
			'status_code' => 0,
			'chain'       => array(),
			'loop'        => false,
			'note'        => '',
		);
		if ( '' === $path || $out['excluded'] ) {
			return $out;
		}
		$current = $path;
		$visited = array( RedirectMatcher::key( $path ) => true );
		for ( $hop = 0; $hop < 10; $hop++ ) {
			$hashes = RedirectMatcher::request_hashes( $current );
			$rules  = $this->repo->find_exact( array( $hashes['full'], $hashes['path'] ) );
			$match  = RedirectMatcher::match( $current, $rules );
			if ( ! $match ) {
				$match = RedirectMatcher::match( $current, $this->repo->pattern_rules() );
				if ( $match && ! $this->would_404( $current ) ) {
					if ( 0 === $hop ) {
						$out['note'] = __( 'A prefix or regex rule matches, but it only applies when the address returns 404 — this address has content.', 'searchpilot-pro' );
					}
					$match = null;
				}
			}
			if ( ! $match ) {
				break;
			}
			$step           = array(
				'rule'        => $this->present( $this->repo->get( (int) $match['rule']['id'] ) ),
				'target'      => (string) $match['target'],
				'target_url'  => '' === (string) $match['target'] ? '' : ( RedirectMatcher::is_external( $match['target'] ) ? $match['target'] : self::absolute( $match['target'] ) ),
				'status_code' => (int) $match['rule']['status_code'],
			);
			$out['chain'][] = $step;
			if ( '' === (string) $match['target'] || RedirectMatcher::is_external( $match['target'] ) ) {
				break;
			}
			$next = RedirectMatcher::target_path( $match['target'] );
			$key  = RedirectMatcher::key( $next );
			if ( isset( $visited[ $key ] ) ) {
				$out['loop'] = true;
				break;
			}
			$visited[ $key ] = true;
			$current         = $next;
		}
		if ( $out['chain'] ) {
			$first              = $out['chain'][0];
			$last               = end( $out['chain'] );
			$out['matched']     = true;
			$out['rule']        = $first['rule'];
			$out['status_code'] = $first['status_code'];
			$out['target']      = $last['target'];
			$out['target_url']  = $last['target_url'];
		}
		return $out;
	}

	/**
	 * Whether a path has no WordPress content (pattern rules only apply to 404s).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function would_404( $path ) {
		$url = self::absolute( $path );
		if ( url_to_postid( $url ) ) {
			return false;
		}
		return RedirectMatcher::key( $path ) !== RedirectMatcher::key( Url::to_path( home_url( '/' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * URL changes (slug changes, publish, delete)
	 * ------------------------------------------------------------------ */

	/**
	 * searchpilot_url_changed handler.
	 *
	 * @param array $change Change payload.
	 */
	public function on_url_changed( $change ) {
		if ( ! is_array( $change ) || ! $this->active() ) {
			return;
		}
		$event = (string) ( $change['event'] ?? '' );
		$url   = (string) ( $change['url'] ?? '' );
		$old   = (string) ( $change['old_url'] ?? '' );
		$type  = (string) ( $change['object_type'] ?? '' );
		$id    = (int) ( $change['object_id'] ?? 0 );
		try {
			if ( 'redirect' === $event && '' !== $old && '' !== $url && Url::is_internal( $old ) && Url::is_internal( $url ) && in_array( $type, array( 'post', 'term' ), true ) ) {
				$this->handle_slug_change( $type, $id, $old, $url );
			}
			if ( in_array( $event, array( 'publish', 'redirect' ), true ) && '' !== $url && Url::is_internal( $url ) ) {
				$this->unshadow( $type, $id, $url );
			}
			if ( 'post' === $type && $id && 'delete' === $event && '' !== $url ) {
				$this->flag_removed_url( $id, $url, (string) ( $change['subtype'] ?? '' ) );
			}
			if ( 'post' === $type && $id && 'publish' === $event ) {
				$this->issues->resolve_stale_for_object( 'post', $id, array( 'deleted_url_redirect' ), array() );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'redirects', 'URL change handling failed: ' . $e->getMessage() );
		}
	}

	/**
	 * A published URL changed: 301 old → new (policy permitting), clean up loops and chains.
	 *
	 * @param string $type    post|term.
	 * @param int    $id      Object id.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 */
	private function handle_slug_change( $type, $id, $old_url, $new_url ) {
		$old_path = Url::to_path( $old_url );
		$new_path = Url::to_path( $new_url );
		if ( RedirectMatcher::key( $old_path ) === RedirectMatcher::key( $new_path ) ) {
			return;
		}
		$source = $this->policy->source();

		// The object moved back to an old address: remove rules that would now loop.
		foreach ( $this->repo->by_source_hash( RedirectMatcher::hash( $new_path ) ) as $rule ) {
			$points_back = '' !== (string) $rule['target'] && ! RedirectMatcher::is_external( $rule['target'] ) && RedirectMatcher::key( RedirectMatcher::target_path( $rule['target'] ) ) === RedirectMatcher::key( $old_path );
			if ( 'slug_change' === $rule['origin'] || $points_back ) {
				$this->delete_rules(
					array( (int) $rule['id'] ),
					array(
						'source'      => $source,
						/* translators: %s: address. */
						'description' => sprintf( __( 'Removed redirect %s: the content moved back to this address', 'searchpilot-pro' ), Url::display( $rule['source'] ) ),
					)
				);
			}
		}

		// Earlier slug redirects of this post now point straight to the new address (no chains).
		if ( 'post' === $type && $id ) {
			foreach ( $this->repo->by_object( $id, 'slug_change' ) as $rule ) {
				if ( $rule['enabled'] && '' !== (string) $rule['target'] && RedirectMatcher::key( RedirectMatcher::target_path( $rule['target'] ) ) === RedirectMatcher::key( $old_path ) ) {
					$this->update_rule(
						(int) $rule['id'],
						array( 'target' => $new_path ),
						array(
							'source'         => $source,
							'allow_external' => false,
							/* translators: %s: address. */
							'description'    => sprintf( __( 'Pointed redirect %s to the new address', 'searchpilot-pro' ), Url::display( $rule['source'] ) ),
						)
					);
				}
			}
		}

		if ( ! $this->settings->get( 'redirects.auto_slug', true ) || $this->policy->disabled( AutomationPolicy::TASK_SLUG_REDIRECTS ) ) {
			return;
		}
		$existing = $this->repo->find_duplicate( 'exact', RedirectMatcher::normalize_path( $old_path ), RedirectMatcher::hash( $old_path ) );
		if ( $existing ) {
			if ( 'slug_change' === $existing['origin'] && RedirectMatcher::key( RedirectMatcher::target_path( (string) $existing['target'] ) ) !== RedirectMatcher::key( $new_path ) ) {
				$this->update_rule(
					(int) $existing['id'],
					array(
						'target'  => $new_path,
						'enabled' => 1,
					),
					array(
						'source'         => $source,
						'allow_external' => false,
					)
				);
			}
			return;
		}
		$title = '';
		if ( 'post' === $type ) {
			$title = self::plain( get_the_title( $id ) );
		} else {
			$term  = get_term( $id );
			$title = $term && ! is_wp_error( $term ) ? self::plain( $term->name ) : '';
		}
		if ( $this->policy->allows( AutomationPolicy::TASK_SLUG_REDIRECTS ) ) {
			$result = $this->create_rule(
				array(
					'source'      => $old_path,
					'target'      => $new_path,
					'status_code' => 301,
					'match_type'  => 'exact',
				),
				array(
					'origin'         => 'slug_change',
					'object_id'      => 'post' === $type ? $id : 0,
					'source'         => $source,
					'allow_external' => false,
					'description'    => sprintf(
						/* translators: 1: title, 2: old address, 3: new address. */
						__( 'Redirected the old address of “%1$s”: %2$s → %3$s', 'searchpilot-pro' ),
						$title,
						Url::display( $old_path ),
						Url::display( $new_path )
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				$this->logger->warning( 'redirects', 'Slug redirect not created: ' . $result->get_error_message(), array( 'old' => $old_path ) );
			} else {
				$this->logger->info( 'redirects', sprintf( 'Created slug-change redirect %s → %s.', $old_path, $new_path ) );
			}
			return;
		}
		$this->issues->upsert(
			'slug_redirect_needed',
			array(
				'object_type' => 'post' === $type ? 'post' : 'term',
				'object_id'   => $id,
				'url'         => $old_url,
				'title'       => $title,
				'data'        => array(
					'old_url'   => $old_url,
					'new_url'   => $new_url,
					'old_path'  => $old_path,
					'new_path'  => $new_path,
					'object'    => $type,
					'object_id' => $id,
				),
			)
		);
	}

	/**
	 * Content was published at an address that an enabled redirect sends
	 * elsewhere: disable the redirect so the content is reachable.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @param string $url  New URL.
	 */
	private function unshadow( $type, $id, $url ) {
		$path  = Url::to_path( $url );
		$rules = $this->repo->by_source_hash( RedirectMatcher::hash( $path ) );
		foreach ( $rules as $rule ) {
			if ( 'slug_change' === $rule['origin'] && (int) $rule['object_id'] === $id && $id ) {
				// Own old-slug rule for this post: handled by handle_slug_change().
				continue;
			}
			if ( $this->policy->allows( AutomationPolicy::TASK_SLUG_REDIRECTS ) ) {
				$this->set_enabled(
					array( (int) $rule['id'] ),
					false,
					array(
						'source'      => $this->policy->source(),
						/* translators: %s: address. */
						'description' => sprintf( __( 'Disabled redirect %s because content was published at this address', 'searchpilot-pro' ), Url::display( $rule['source'] ) ),
					)
				);
				$this->logger->info( 'redirects', sprintf( 'Disabled redirect #%d: content was published at %s.', (int) $rule['id'], $path ) );
			} elseif ( ! $this->policy->disabled( AutomationPolicy::TASK_SLUG_REDIRECTS ) ) {
				$this->issues->upsert(
					'redirect_shadows_content',
					array(
						'object_type' => 'redirect',
						'object_id'   => (int) $rule['id'],
						'url'         => $url,
						'title'       => Url::display( $rule['source'] ),
						'data'        => array(
							'rule_id'     => (int) $rule['id'],
							'object_type' => $type,
							'object_id'   => $id,
						),
					)
				);
			}
		}
	}

	/**
	 * A published post was deleted. A 301 is proposed ONLY when a genuinely
	 * equivalent page exists (the same article republished, or a post that
	 * carries the old slug). Redirecting removed articles to a category or the
	 * home page is treated as a soft 404 by Google, so otherwise the advice is
	 * to leave the URL as 404/410 — and that is raised only for URLs search
	 * engines are known to have (indexed or submitted).
	 *
	 * @param int    $id      Post id.
	 * @param string $url     Its old URL.
	 * @param string $subtype Post type.
	 */
	private function flag_removed_url( $id, $url, $subtype ) {
		$path = Url::to_path( $url );
		if ( $this->repo->by_source_hash( RedirectMatcher::hash( $path ) ) ) {
			return;
		}
		$post  = get_post( $id );
		$title = $post ? self::plain( get_the_title( $post ) ) : Url::display( $path );
		$match = $this->equivalent_target( $id, $url, $subtype );
		if ( $match ) {
			$data = array(
				'url'            => $url,
				'path'           => $path,
				'recommendation' => 'redirect',
				'target'         => $match['url'],
				'target_label'   => $match['label'],
				'reason'         => $match['reason'],
			);
		} else {
			if ( ! $this->known_to_search_engines( $url ) ) {
				return; // A 404 is the right answer; nothing to recommend.
			}
			$data = array(
				'url'            => $url,
				'path'           => $path,
				'recommendation' => 'gone',
				'target'         => '',
				'target_label'   => '',
				'reason'         => '',
			);
		}
		$this->issues->upsert(
			'deleted_url_redirect',
			array(
				'object_type' => 'post',
				'object_id'   => $id,
				'url'         => $url,
				'title'       => $title,
				'data'        => $data,
			)
		);
	}

	/**
	 * Whether search engines have seen a URL (URL registry: indexed or submitted).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function known_to_search_engines( $url ) {
		if ( ! class_exists( '\SearchPilot\Indexer\UrlRepository' ) ) {
			return false;
		}
		$row = \SearchPilot\Plugin::instance()->get( \SearchPilot\Indexer\UrlRepository::class )->get_by_url( $url );
		return $row && ( 'indexed' === ( $row['index_status'] ?? '' ) || ! empty( $row['last_submitted'] ) );
	}

	/**
	 * A published page that is genuinely the same content as a removed post.
	 *
	 * @param int    $id      Removed post id.
	 * @param string $url     Removed URL.
	 * @param string $subtype Post type.
	 * @return array{url:string,label:string,reason:string}|null
	 */
	private function equivalent_target( $id, $url, $subtype ) {
		global $wpdb;
		list( $path ) = RedirectMatcher::split( Url::to_path( $url ) );
		$segments     = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		$slug         = $segments ? strtolower( (string) end( $segments ) ) : '';
		$post         = get_post( $id );
		$type         = '' !== $subtype ? $subtype : ( $post ? $post->post_type : 'post' );
		$found        = null;
		$reason       = '';
		if ( '' !== $slug ) {
			// A post that remembers this slug (it was moved, then the old copy deleted).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$found  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_old_slug' AND m.meta_value = %s AND p.post_status = 'publish' AND p.post_type = %s AND p.ID <> %d ORDER BY p.post_date_gmt DESC LIMIT 1", $slug, $type, (int) $id ) );
			$reason = 'old_slug';
			if ( ! $found ) {
				// The same article republished (WordPress appends -2, -3… to a taken slug).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = %s AND ID <> %d AND ( post_name = %s OR post_name LIKE %s ) ORDER BY post_date_gmt DESC LIMIT 10", $type, (int) $id, $slug, $wpdb->esc_like( $slug ) . '-%' ) );
				foreach ( (array) $candidates as $candidate ) {
					if ( $candidate->post_name === $slug || preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d+$/', (string) $candidate->post_name ) ) {
						$found  = (int) $candidate->ID;
						$reason = 'republished';
						break;
					}
				}
			}
		}
		if ( ! $found && $post && '' !== trim( $post->post_title ) ) {
			// The same title published again (trashed original still exists).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = %s AND ID <> %d AND post_title = %s ORDER BY post_date_gmt DESC LIMIT 1", $type, (int) $id, $post->post_title ) );
			$reason = 'same_title';
		}
		if ( ! $found ) {
			return null;
		}
		$target = get_post( $found );
		if ( ! $target || ! \SearchPilot\Plugin::instance()->get( \SearchPilot\Core\ContentTypes::class )->is_post_indexable( $target ) ) {
			return null;
		}
		$link = get_permalink( $target );
		if ( ! $link || Url::normalize( $link ) === Url::normalize( $url ) ) {
			return null;
		}
		return array(
			'url'    => $link,
			'label'  => self::plain( get_the_title( $target ) ),
			'reason' => $reason,
		);
	}

	/**
	 * Plain text for titles/names (decodes entities such as &#8217;).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		return trim( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Issues
	 * ------------------------------------------------------------------ */

	/**
	 * Registers redirect issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			'redirect_loop',
			array(
				'label'    => __( 'Redirect loop', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$sources = array_map( array( Url::class, 'display' ), (array) ( $issue['data']['sources'] ?? array() ) );
					/* translators: %s: addresses joined by arrows. */
					return sprintf( __( 'These redirects send visitors in a circle: %s.', 'searchpilot-pro' ), implode( ' → ', array_merge( $sources, array_slice( $sources, 0, 1 ) ) ) );
				},
				'why'      => __( 'Browsers and search engines give up on a loop, so these addresses show an error and can drop out of search results.', 'searchpilot-pro' ),
				'fix'      => __( 'Edit or delete one of the rules so the chain ends at a working page. SearchPilot already refuses to follow the loop.', 'searchpilot-pro' ),
				'action'   => 'fix_redirects',
			)
		);
		$registry->register(
			'redirect_chain',
			array(
				'label'       => __( 'Redirect chain', 'searchpilot-pro' ),
				'category'    => 'technical',
				'severity'    => 'medium',
				'what'        => static function ( $issue ) {
					/* translators: 1: number of hops, 2: final address. */
					return sprintf( __( 'This address redirects %1$d times before reaching %2$s.', 'searchpilot-pro' ), count( (array) ( $issue['data']['ids'] ?? array() ) ), Url::display( (string) ( $issue['data']['final'] ?? '' ) ) );
				},
				'why'         => __( 'Every extra hop slows visitors down and wastes crawl budget; Google follows only a limited number of hops.', 'searchpilot-pro' ),
				'fix'         => __( 'Point the first redirect straight at the final address.', 'searchpilot-pro' ),
				'fixer'       => array( $this, 'fix_chain' ),
				'fix_label'   => __( 'Point straight to the final page', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'destructive' => false,
				'action'      => 'fix_redirects',
			)
		);
		$registry->register(
			'redirect_to_error',
			array(
				'label'    => __( 'Redirect points to an error page', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					/* translators: 1: target address, 2: HTTP status. */
					return sprintf( __( 'The redirect target %1$s answers with HTTP %2$d.', 'searchpilot-pro' ), Url::display( (string) ( $issue['data']['target'] ?? '' ) ), (int) ( $issue['data']['status'] ?? 0 ) );
				},
				'why'      => __( 'Visitors and search engines following this redirect end on an error instead of content, so the old address’s value is lost.', 'searchpilot-pro' ),
				'fix'      => __( 'Edit the redirect to point at a page that exists, or restore the target page.', 'searchpilot-pro' ),
				'action'   => 'fix_redirects',
			)
		);
		$registry->register(
			'slug_redirect_needed',
			array(
				'label'     => __( 'Changed address needs a redirect', 'searchpilot-pro' ),
				'category'  => 'technical',
				'severity'  => 'high',
				'what'      => static function ( $issue ) {
					/* translators: 1: old address, 2: new address. */
					return sprintf( __( 'The address changed from %1$s to %2$s and the old one has no redirect.', 'searchpilot-pro' ), Url::display( (string) ( $issue['data']['old_path'] ?? '' ) ), Url::display( (string) ( $issue['data']['new_path'] ?? '' ) ) );
				},
				'why'       => __( 'Links and search results still point to the old address, which now shows “not found”. A 301 redirect keeps visitors and ranking signals.', 'searchpilot-pro' ),
				'fix'       => __( 'Create a permanent (301) redirect from the old address to the new one.', 'searchpilot-pro' ),
				'fixer'     => array( $this, 'fix_slug_redirect' ),
				'fix_label' => __( 'Create 301 redirect', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_SLUG_REDIRECTS,
				'action'    => 'create_redirect',
			)
		);
		$registry->register(
			'deleted_url_redirect',
			array(
				'label'     => __( 'Deleted page', 'searchpilot-pro' ),
				'category'  => 'technical',
				'severity'  => 'opportunity',
				'what'      => static function ( $issue ) {
					$path = Url::display( (string) ( $issue['data']['path'] ?? $issue['url'] ?? '' ) );
					if ( 'redirect' === ( $issue['data']['recommendation'] ?? '' ) ) {
						/* translators: 1: address, 2: page title. */
						return sprintf( __( 'Published content at %1$s was deleted, and the same content is now published as “%2$s”.', 'searchpilot-pro' ), $path, (string) ( $issue['data']['target_label'] ?? '' ) );
					}
					/* translators: %s: address. */
					return sprintf( __( 'Published content at %s was deleted. Search engines know this address.', 'searchpilot-pro' ), $path );
				},
				'why'       => static function ( $issue ) {
					if ( 'redirect' === ( $issue['data']['recommendation'] ?? '' ) ) {
						return __( 'Links and search results to the old address can land on the same content instead of “not found”.', 'searchpilot-pro' );
					}
					return __( 'A removed page should answer “not found” (404) or “gone” (410): search engines then drop it. Redirecting it to the home page or a category is treated as a soft 404 and wastes crawl budget.', 'searchpilot-pro' );
				},
				'fix'       => static function ( $issue ) {
					if ( 'redirect' === ( $issue['data']['recommendation'] ?? '' ) ) {
						/* translators: %s: page title. */
						return sprintf( __( 'Redirect the old address (301) to “%s”.', 'searchpilot-pro' ), (string) ( $issue['data']['target_label'] ?? '' ) );
					}
					return __( 'Nothing is required — leave it as 404. Optionally mark it 410 Gone so search engines drop it sooner. If the post was deleted by mistake, restore it from the trash.', 'searchpilot-pro' );
				},
				'fixer'     => array( $this, 'fix_deleted_url' ),
				'fix_label' => __( 'Apply the recommendation', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'action'    => 'create_redirect',
			)
		);
		$registry->register(
			'redirect_shadows_content',
			array(
				'label'     => __( 'Redirect hides published content', 'searchpilot-pro' ),
				'category'  => 'technical',
				'severity'  => 'high',
				'what'      => __( 'New content was published at an address that an enabled redirect sends elsewhere.', 'searchpilot-pro' ),
				'why'       => __( 'Visitors and search engines never see the new content while the redirect is active.', 'searchpilot-pro' ),
				'fix'       => __( 'Disable the redirect (it stays on the Redirects screen and can be re-enabled).', 'searchpilot-pro' ),
				'fixer'     => array( $this, 'fix_shadow' ),
				'fix_label' => __( 'Disable redirect', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_SLUG_REDIRECTS,
				'action'    => 'fix_redirects',
			)
		);
	}

	/**
	 * Fixer: slug_redirect_needed.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_slug_redirect( array $issue ) {
		$data   = (array) $issue['data'];
		$result = $this->create_rule(
			array(
				'source'      => (string) ( $data['old_path'] ?? '' ),
				'target'      => (string) ( $data['new_path'] ?? '' ),
				'status_code' => 301,
			),
			array(
				'origin'         => 'slug_change',
				'object_id'      => 'post' === ( $data['object'] ?? '' ) ? (int) ( $data['object_id'] ?? 0 ) : 0,
				'source'         => (string) ( $issue['source'] ?? 'user' ),
				'allow_external' => false,
			)
		);
		if ( is_wp_error( $result ) && 'searchpilot_duplicate' !== $result->get_error_code() ) {
			return $result;
		}
		return true;
	}

	/**
	 * Fixer: deleted_url_redirect.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_deleted_url( array $issue ) {
		$data   = (array) $issue['data'];
		$target = (string) ( $data['target'] ?? '' );
		// Never a blanket redirect: 301 only to the equivalent page, otherwise 410 Gone.
		$redirect = 'redirect' === ( $data['recommendation'] ?? '' ) && '' !== $target;
		$result   = $this->create_rule(
			array(
				'source'      => (string) ( $data['path'] ?? Url::to_path( (string) $issue['url'] ) ),
				'target'      => $redirect ? $target : '',
				'status_code' => $redirect ? 301 : 410,
			),
			array(
				'origin'         => 'deleted',
				'object_id'      => (int) $issue['object_id'],
				'source'         => (string) ( $issue['source'] ?? 'user' ),
				'allow_external' => false,
			)
		);
		if ( is_wp_error( $result ) && 'searchpilot_duplicate' !== $result->get_error_code() ) {
			return $result;
		}
		return true;
	}

	/**
	 * Fixer: redirect_chain — first rule points straight at the final target.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_chain( array $issue ) {
		$ids   = array_map( 'intval', (array) ( $issue['data']['ids'] ?? array() ) );
		$final = (string) ( $issue['data']['final'] ?? '' );
		if ( count( $ids ) < 2 || '' === $final ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'This chain changed since it was detected. Run the check again.', 'searchpilot-pro' ) );
		}
		$result = $this->update_rule(
			$ids[0],
			array( 'target' => $final ),
			array(
				'source'      => (string) ( $issue['source'] ?? 'user' ),
				/* translators: %s: final address. */
				'description' => sprintf( __( 'Shortened a redirect chain: now points straight to %s', 'searchpilot-pro' ), Url::display( $final ) ),
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Fixer: redirect_shadows_content — disable the rule.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_shadow( array $issue ) {
		$id = (int) ( $issue['data']['rule_id'] ?? $issue['object_id'] );
		if ( ! $this->repo->raw( $id ) ) {
			return true;
		}
		$this->set_enabled( array( $id ), false, array( 'source' => (string) ( $issue['source'] ?? 'user' ) ) );
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Background checks
	 * ------------------------------------------------------------------ */

	/**
	 * searchpilot_daily: runs the checks in their own request (TimeBudget is request-relative).
	 */
	public function schedule_daily() {
		$this->scheduler->enqueue_async( 'searchpilot_redirects_daily', array(), 60 );
	}

	/**
	 * Daily (async): chain/loop analysis and target checks.
	 */
	public function daily() {
		$this->analyze();
		$this->check_targets();
	}

	/**
	 * Finds loops and chains among enabled exact internal rules.
	 */
	public function analyze() {
		Lock::run(
			'redirects_analyze',
			300,
			function () {
				$start  = current_time( 'mysql', true );
				$rules  = $this->repo->graph_rules();
				$result = RedirectMatcher::analyze( $rules );
				$by_id  = array();
				foreach ( $rules as $rule ) {
					$by_id[ (int) $rule['id'] ] = $rule;
				}
				foreach ( $result['loops'] as $ids ) {
					$first = $by_id[ $ids[0] ] ?? null;
					if ( ! $first ) {
						continue;
					}
					$this->issues->upsert(
						'redirect_loop',
						array(
							'object_type' => 'redirect',
							'object_id'   => (int) $ids[0],
							'url'         => self::absolute( (string) $first['source'] ),
							'title'       => Url::display( (string) $first['source'] ),
							'data'        => array(
								'ids'     => $ids,
								'sources' => array_map(
									static function ( $id ) use ( $by_id ) {
										return (string) ( $by_id[ $id ]['source'] ?? '' );
									},
									$ids
								),
							),
						)
					);
				}
				foreach ( array_slice( $result['chains'], 0, 500 ) as $chain ) {
					$first = $by_id[ $chain['ids'][0] ] ?? null;
					if ( ! $first ) {
						continue;
					}
					$this->issues->upsert(
						'redirect_chain',
						array(
							'object_type' => 'redirect',
							'object_id'   => (int) $chain['ids'][0],
							'url'         => self::absolute( (string) $first['source'] ),
							'title'       => Url::display( (string) $first['source'] ),
							'data'        => array(
								'ids'   => $chain['ids'],
								'final' => $chain['final'],
							),
						)
					);
				}
				$this->issues->resolve_unseen( 'redirect_loop', $start );
				$this->issues->resolve_unseen( 'redirect_chain', $start );
			}
		);
	}

	/**
	 * Checks that internal redirect targets work (registry first, then a
	 * rate-limited loopback HEAD request). Results are cached for a week.
	 */
	public function check_targets() {
		Lock::run(
			'redirect_targets',
			600,
			function () {
				$plugin  = \SearchPilot\Plugin::instance();
				$budget  = new TimeBudget( 25 );
				$limiter = $plugin->get( RateLimiter::class );
				$http    = $plugin->get( HttpClient::class );
				$urls    = class_exists( '\SearchPilot\Indexer\UrlRepository' ) ? $plugin->get( \SearchPilot\Indexer\UrlRepository::class ) : null;
				$checks  = get_transient( 'searchpilot_redirect_checks' );
				$checks  = is_array( $checks ) ? $checks : array();
				$rules   = $this->repo->query(
					array(
						'enabled'  => 1,
						'orderby'  => 'hits',
						'per_page' => 200,
					)
				)['items'];
				$live    = array();
				$fetches = 0;
				foreach ( $rules as $rule ) {
					$code = (int) $rule['status_code'];
					if ( $code < 300 || $code > 399 || '' === (string) $rule['target'] || RedirectMatcher::is_external( $rule['target'] ) || false !== strpos( (string) $rule['target'], '$' ) || false !== strpos( (string) $rule['target'], '*' ) ) {
						continue;
					}
					$id          = (int) $rule['id'];
					$live[ $id ] = true;
					$target      = self::absolute( (string) $rule['target'] );
					if ( $this->repo->find_exact( array( RedirectMatcher::hash( RedirectMatcher::target_path( (string) $rule['target'] ) ) ) ) ) {
						// The target is itself redirected: reported as a chain or loop instead.
						$this->issues->resolve( 'redirect_to_error', 'redirect', $id, self::absolute( (string) $rule['source'] ) );
						continue;
					}
					$cached      = $checks[ $id ] ?? null;
					if ( is_array( $cached ) && ( $cached['t'] ?? '' ) === $target && (int) ( $cached['at'] ?? 0 ) > time() - WEEK_IN_SECONDS ) {
						$status = (int) $cached['s'];
					} else {
						if ( $budget->exhausted() || $fetches >= 40 ) {
							continue;
						}
						$status = 0;
						$row    = $urls ? $urls->get_by_url( $target ) : null;
						if ( $row && ! empty( $row['http_status'] ) && ! empty( $row['last_checked'] ) ) {
							$status = (int) $row['http_status'];
						} elseif ( url_to_postid( $target ) && 'publish' === get_post_status( url_to_postid( $target ) ) ) {
							$status = 200;
						} elseif ( $limiter->attempt( 'self_audit' ) ) {
							++$fetches;
							$response = $http->request(
								'HEAD',
								$target,
								array(
									'timeout'     => 10,
									'redirection' => 3,
									'channel'     => 'redirects',
									'log_errors'  => false,
									'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
									'resolve_to'  => (string) $this->settings->get( 'indexing.origin_ip', '' ),
								)
							);
							$status   = (int) $response->code;
						}
						$checks[ $id ] = array(
							't'  => $target,
							's'  => $status,
							'at' => time(),
						);
					}
					if ( $status >= 400 ) {
						$this->issues->upsert(
							'redirect_to_error',
							array(
								'object_type' => 'redirect',
								'object_id'   => $id,
								'url'         => self::absolute( (string) $rule['source'] ),
								'title'       => Url::display( (string) $rule['source'] ),
								'data'        => array(
									'target' => $target,
									'status' => $status,
								),
							)
						);
					} elseif ( $status >= 200 ) {
						$this->issues->resolve( 'redirect_to_error', 'redirect', $id, self::absolute( (string) $rule['source'] ) );
					}
				}
				$checks = array_intersect_key( $checks, $live );
				set_transient( 'searchpilot_redirect_checks', $checks, 2 * WEEK_IN_SECONDS );
			}
		);
	}

	/**
	 * Repository (for controllers).
	 *
	 * @return RedirectRepository
	 */
	public function repository() {
		return $this->repo;
	}

	/**
	 * 404 monitor (for controllers).
	 *
	 * @return NotFoundMonitor
	 */
	public function monitor() {
		return $this->monitor;
	}

	/**
	 * Whether the current user can manage redirects.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return Capabilities::can_manage();
	}
}

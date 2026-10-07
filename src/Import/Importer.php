<?php
/**
 * Background import of other SEO plugins' data.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Editor\EditorModule;
use SearchPilot\Import\Sources\AioseoSource;
use SearchPilot\Import\Sources\RankMathSource;
use SearchPilot\Import\Sources\SeopressSource;
use SearchPilot\Import\Sources\Source;
use SearchPilot\Import\Sources\TsfSource;
use SearchPilot\Import\Sources\YoastSource;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Copies post fields, term fields and (optionally) settings from Yoast,
 * Rank Math, AIOSEO, SEOPress or The SEO Framework into SearchPilot's own
 * keys in background batches. Only empty SearchPilot fields are filled
 * unless "overwrite" is chosen. Every post/term gets ONE activity entry
 * (handler import_post / import_term) in a shared group, so the whole run
 * can be undone. The source plugin's data is never modified.
 *
 * Progress lives in State key `import`; finished runs in `import_history`.
 */
class Importer {

	const HOOK       = 'searchpilot_import_batch';
	const UNDO_HOOK  = 'searchpilot_import_undo_batch';
	const BATCH      = 200;
	const COUNTS_KEY = 'searchpilot_import_counts';

	const POST_FIELDS = array( 'title', 'description', 'focus', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'breadcrumb_title', 'primary_category', 'cornerstone' );

	const TERM_FIELDS = array( 'title', 'description', 'focus', 'canonical', 'noindex' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Source instances.
	 *
	 * @var Source[]|null
	 */
	private $sources = null;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings  Settings.
	 * @param State       $state     State.
	 * @param Scheduler   $scheduler Scheduler.
	 * @param Changes     $changes   Changes.
	 * @param ActivityLog $log       Activity log.
	 * @param Logger      $logger    Logger.
	 */
	public function __construct( Settings $settings, State $state, Scheduler $scheduler, Changes $changes, ActivityLog $log, Logger $logger ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->scheduler = $scheduler;
		$this->changes   = $changes;
		$this->log       = $log;
		$this->logger    = $logger;
	}

	/* --------------------------------------------------------------------- *
	 * Sources
	 * --------------------------------------------------------------------- */

	/**
	 * Sources by id.
	 *
	 * @return Source[]
	 */
	public function sources() {
		if ( null === $this->sources ) {
			$this->sources = array();
			foreach ( array( new YoastSource(), new RankMathSource(), new AioseoSource(), new SeopressSource(), new TsfSource() ) as $source ) {
				$this->sources[ $source->id() ] = $source;
			}
		}
		return $this->sources;
	}

	/**
	 * One source.
	 *
	 * @param string $id Id.
	 * @return Source|null
	 */
	public function source( $id ) {
		return $this->sources()[ (string) $id ] ?? null;
	}

	/**
	 * Availability of every source (counts cached for 10 minutes).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array[]
	 */
	public function availability( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::COUNTS_KEY );
		if ( ! is_array( $cached ) ) {
			$cached = array();
			foreach ( $this->sources() as $id => $source ) {
				try {
					$cached[ $id ] = array(
						'posts'    => $source->count_posts(),
						'terms'    => $source->count_terms(),
						'settings' => $source->has_settings(),
					);
				} catch ( \Throwable $e ) {
					$cached[ $id ] = array(
						'posts'    => 0,
						'terms'    => 0,
						'settings' => false,
					);
				}
			}
			set_transient( self::COUNTS_KEY, $cached, 10 * MINUTE_IN_SECONDS );
		}
		$out = array();
		foreach ( $this->sources() as $id => $source ) {
			$counts = $cached[ $id ] ?? array(
				'posts'    => 0,
				'terms'    => 0,
				'settings' => false,
			);
			$out[]  = array(
				'id'        => $id,
				'name'      => $source->name(),
				'active'    => $source->is_active(),
				'available' => $counts['posts'] > 0 || $counts['terms'] > 0 || $counts['settings'],
				'counts'    => array(
					'posts' => (int) $counts['posts'],
					'terms' => (int) $counts['terms'],
				),
				'settings'  => (bool) $counts['settings'],
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Run control
	 * --------------------------------------------------------------------- */

	/**
	 * Current progress.
	 *
	 * @return array
	 */
	public function progress() {
		$state = $this->state->get( 'import', array() );
		return is_array( $state ) && $state ? $state : array( 'status' => 'idle' );
	}

	/**
	 * Finished runs (newest first).
	 *
	 * @return array[]
	 */
	public function history() {
		$history = $this->state->get( 'import_history', array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Undo progress.
	 *
	 * @return array
	 */
	public function undo_progress() {
		$state = $this->state->get( 'import_undo', array() );
		return is_array( $state ) && $state ? $state : array( 'status' => 'idle' );
	}

	/**
	 * Starts an import.
	 *
	 * @param string   $source_id Source id.
	 * @param bool     $overwrite Replace existing SearchPilot values.
	 * @param string[] $include   posts|terms|settings.
	 * @return array|\WP_Error Progress.
	 */
	public function start( $source_id, $overwrite = false, array $include = array( 'posts', 'terms' ) ) {
		$source = $this->source( $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Choose a plugin to import from.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$include = array_values( array_intersect( array( 'posts', 'terms', 'settings' ), array_map( 'sanitize_key', $include ) ) );
		if ( ! $include ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Choose what to import: posts, terms or settings.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$current = $this->progress();
		if ( 'running' === ( $current['status'] ?? '' ) && time() - (int) ( $current['updated_ts'] ?? 0 ) < 10 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'searchpilot_busy', __( 'An import is already running. Wait for it to finish or cancel it first.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$counts = array(
			'posts' => in_array( 'posts', $include, true ) ? $source->count_posts() : 0,
			'terms' => in_array( 'terms', $include, true ) ? $source->count_terms() : 0,
		);
		Lock::release( 'import_cancel' );
		$state = array(
			'status'      => 'running',
			'source'      => $source->id(),
			'source_name' => $source->name(),
			'overwrite'   => (bool) $overwrite,
			'include'     => $include,
			'group_id'    => ActivityLog::new_group_id(),
			'phase'       => $this->next_phase( '', $include ),
			'cursor'      => 0,
			'totals'      => $counts,
			'processed'   => array(
				'posts' => 0,
				'terms' => 0,
			),
			'imported'    => array(
				'posts'    => 0,
				'terms'    => 0,
				'fields'   => 0,
				'settings' => 0,
			),
			'skipped'     => array(
				'posts' => 0,
				'terms' => 0,
			),
			'errors'      => array(),
			'user_id'     => get_current_user_id(),
			'started_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
			'updated_ts'  => time(),
		);
		$this->state->set( 'import', $state );
		$this->logger->info( 'import', sprintf( 'Import from %s started (%s).', $source->name(), implode( ', ', $include ) ) );
		$this->scheduler->enqueue_async( self::HOOK, array(), 5 );
		return $state;
	}

	/**
	 * Cancels the running import (already imported items stay, and can be undone).
	 *
	 * @return array Progress.
	 */
	public function cancel() {
		$state = $this->progress();
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			return $state;
		}
		Lock::acquire( 'import_cancel', HOUR_IN_SECONDS );
		$state['status']      = 'cancelled';
		$state['finished_at'] = gmdate( 'c' );
		$state['updated_at']  = gmdate( 'c' );
		$this->state->set( 'import', $state );
		$this->add_history( $state );
		return $state;
	}

	/**
	 * Runs one time-budgeted batch (Scheduler hook; also used as a fallback when cron is slow).
	 *
	 * @param int $seconds Time budget.
	 * @return array Progress.
	 */
	public function run_batch( $seconds = 20 ) {
		$result = Lock::run(
			'import',
			300,
			function () use ( $seconds ) {
				return $this->process( new TimeBudget( (int) $seconds ) );
			}
		);
		return is_array( $result ) ? $result : $this->progress();
	}

	/**
	 * Processes until done or the budget is used.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array Progress.
	 */
	private function process( TimeBudget $budget ) {
		$state = $this->progress();
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			return $state;
		}
		$source = $this->source( (string) $state['source'] );
		if ( ! $source ) {
			$state['status']   = 'failed';
			$state['errors'][] = __( 'The import source is no longer available.', 'searchpilot-pro' );
			$this->save( $state );
			return $state;
		}
		// Keep the author of the run as the acting user of activity entries.
		$previous_user = get_current_user_id();
		if ( ! $previous_user && ! empty( $state['user_id'] ) ) {
			wp_set_current_user( (int) $state['user_id'] );
		}

		while ( 'running' === $state['status'] && ! $budget->exhausted() ) {
			if ( Lock::held( 'import_cancel' ) ) {
				$state = $this->progress();
				break;
			}
			try {
				$state = $this->step( $source, $state, $budget );
			} catch ( \Throwable $e ) {
				$state['errors'][] = $e->getMessage();
				$state['status']   = 'failed';
				$this->logger->error( 'import', 'Import batch failed: ' . $e->getMessage(), array( 'line' => $e->getLine() ) );
			}
			$state['errors'] = array_slice( (array) $state['errors'], -20 );
			if ( ! Lock::held( 'import_cancel' ) ) {
				$this->save( $state );
			}
		}
		if ( 'running' === $state['status'] ) {
			$this->scheduler->enqueue_async( self::HOOK, array(), 5 );
		} elseif ( in_array( $state['status'], array( 'done', 'failed' ), true ) ) {
			$this->add_history( $state );
			delete_transient( self::COUNTS_KEY );
			$this->logger->info(
				'import',
				sprintf( 'Import from %s %s: %d posts, %d terms, %d settings imported.', $source->name(), $state['status'], (int) $state['imported']['posts'], (int) $state['imported']['terms'], (int) $state['imported']['settings'] )
			);
		}
		if ( ! $previous_user && get_current_user_id() ) {
			wp_set_current_user( 0 );
		}
		return $state;
	}

	/**
	 * One chunk of the current phase.
	 *
	 * @param Source     $source Source.
	 * @param array      $state  State.
	 * @param TimeBudget $budget Budget.
	 * @return array
	 */
	private function step( Source $source, array $state, TimeBudget $budget ) {
		$phase = (string) $state['phase'];
		if ( 'posts' === $phase || 'terms' === $phase ) {
			$ids = 'posts' === $phase ? $source->post_ids_after( (int) $state['cursor'], self::BATCH ) : $source->term_ids_after( (int) $state['cursor'], self::BATCH );
			$all = true;
			foreach ( $ids as $id ) {
				if ( $budget->exhausted() ) {
					$all = false;
					break;
				}
				$written = 'posts' === $phase
					? $this->import_post( $id, $source->read_post( $id ), (bool) $state['overwrite'], (string) $state['group_id'], $source->name() )
					: $this->import_term( $id, $source->read_term( $id ), (bool) $state['overwrite'], (string) $state['group_id'], $source->name() );
				++$state['processed'][ $phase ];
				if ( $written > 0 ) {
					++$state['imported'][ $phase ];
					$state['imported']['fields'] += $written;
				} else {
					++$state['skipped'][ $phase ];
				}
				$state['cursor'] = $id;
			}
			if ( $all && count( $ids ) < self::BATCH ) {
				$state['phase']  = $this->next_phase( $phase, (array) $state['include'] );
				$state['cursor'] = 0;
			}
		} elseif ( 'settings' === $phase ) {
			$state['imported']['settings'] = $this->import_settings( $source, (bool) $state['overwrite'], (string) $state['group_id'] );
			$state['phase']                = $this->next_phase( 'settings', (array) $state['include'] );
		}
		if ( 'done' === $state['phase'] ) {
			$state['status']      = 'done';
			$state['finished_at'] = gmdate( 'c' );
		}
		return $state;
	}

	/**
	 * Next phase.
	 *
	 * @param string   $current Current phase ('' = start).
	 * @param string[] $include Included parts.
	 * @return string
	 */
	private function next_phase( $current, array $include ) {
		$order = array( 'posts', 'terms', 'settings' );
		$start = '' === $current ? 0 : (int) array_search( $current, $order, true ) + 1;
		foreach ( array_slice( $order, $start ) as $phase ) {
			if ( in_array( $phase, $include, true ) ) {
				return $phase;
			}
		}
		return 'done';
	}

	/**
	 * Saves progress.
	 *
	 * @param array $state State.
	 */
	private function save( array $state ) {
		$state['updated_at'] = gmdate( 'c' );
		$state['updated_ts'] = time();
		$this->state->set( 'import', $state );
	}

	/**
	 * Appends a finished run to the history (max 10).
	 *
	 * @param array $state State.
	 */
	private function add_history( array $state ) {
		$history = $this->history();
		$history = array_values(
			array_filter(
				$history,
				static function ( $run ) use ( $state ) {
					return ( $run['group_id'] ?? '' ) !== $state['group_id'];
				}
			)
		);
		array_unshift(
			$history,
			array(
				'group_id'    => (string) $state['group_id'],
				'source'      => (string) $state['source'],
				'source_name' => (string) $state['source_name'],
				'status'      => (string) $state['status'],
				'overwrite'   => (bool) $state['overwrite'],
				'include'     => (array) $state['include'],
				'imported'    => (array) $state['imported'],
				'skipped'     => (array) $state['skipped'],
				'errors'      => array_slice( (array) $state['errors'], 0, 5 ),
				'started_at'  => (string) $state['started_at'],
				'finished_at' => (string) ( $state['finished_at'] ?? gmdate( 'c' ) ),
				'undone'      => false,
			)
		);
		$this->state->set( 'import_history', array_slice( $history, 0, 10 ) );
	}

	/* --------------------------------------------------------------------- *
	 * Writers
	 * --------------------------------------------------------------------- */

	/**
	 * Whether a stored value counts as empty.
	 *
	 * @param string $field  Field.
	 * @param bool   $exists Meta exists.
	 * @param mixed  $value  Stored value.
	 * @return bool
	 */
	private static function is_empty( $field, $exists, $value ) {
		if ( ! $exists || null === $value || '' === $value || false === $value ) {
			return true;
		}
		if ( in_array( $field, array( 'primary_category', 'cornerstone', 'faq', 'sitemap_exclude', 'links_exclude' ), true ) ) {
			return ! (int) $value;
		}
		return is_string( $value ) && '' === trim( $value );
	}

	/**
	 * Normalizes an imported value to the stored form ('' = nothing to import).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function stored_value( $field, $value ) {
		if ( null === $value || false === $value || '' === $value ) {
			return '';
		}
		$clean = EditorModule::sanitize_field( $field, $value );
		if ( is_bool( $clean ) ) {
			return $clean ? '1' : '';
		}
		if ( 'primary_category' === $field ) {
			return $clean ? (string) $clean : '';
		}
		return (string) $clean;
	}

	/**
	 * Imports one post (one activity entry).
	 *
	 * @param int    $post_id   Post id.
	 * @param array  $values    Field => value.
	 * @param bool   $overwrite Overwrite.
	 * @param string $group_id  Group.
	 * @param string $name      Source name.
	 * @return int Fields written.
	 */
	public function import_post( $post_id, array $values, $overwrite, $group_id, $name ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return 0;
		}
		$before = array();
		$after  = array();
		foreach ( self::POST_FIELDS as $field ) {
			$new = self::stored_value( $field, $values[ $field ] ?? '' );
			if ( '' === $new ) {
				continue;
			}
			$key     = MetaStore::PREFIX . $field;
			$exists  = metadata_exists( 'post', $post_id, $key );
			$current = $exists ? get_post_meta( $post_id, $key, true ) : null;
			if ( ! $overwrite && ! self::is_empty( $field, $exists, $current ) ) {
				continue;
			}
			if ( $exists && (string) $current === $new ) {
				continue;
			}
			$before[ $key ] = array(
				'exists' => $exists,
				'value'  => $current,
			);
			$after[ $key ]  = $new;
			update_post_meta( $post_id, $key, wp_slash( $new ) );
		}
		if ( ! $after ) {
			return 0;
		}
		$this->changes->record(
			'import_post',
			array(
				'action'      => 'seo_imported',
				/* translators: 1: plugin name, 2: post title. */
				'description' => sprintf( __( 'Imported SEO data from %1$s for “%2$s”', 'searchpilot-pro' ), $name, get_the_title( $post_id ) ),
				'object_type' => 'post',
				'object_id'   => $post_id,
				'before'      => array(
					'post_id' => $post_id,
					'keys'    => $before,
				),
				'after'       => array(
					'post_id' => $post_id,
					'keys'    => $after,
				),
				'source'      => 'import',
				'group_id'    => $group_id,
			)
		);
		return count( $after );
	}

	/**
	 * Imports one term (one activity entry).
	 *
	 * @param int    $term_id   Term id.
	 * @param array  $values    Field => value.
	 * @param bool   $overwrite Overwrite.
	 * @param string $group_id  Group.
	 * @param string $name      Source name.
	 * @return int Fields written.
	 */
	public function import_term( $term_id, array $values, $overwrite, $group_id, $name ) {
		$term_id = (int) $term_id;
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}
		$before = array();
		$after  = array();
		foreach ( self::TERM_FIELDS as $field ) {
			$new = self::stored_value( $field, $values[ $field ] ?? '' );
			if ( '' === $new ) {
				continue;
			}
			$key     = MetaStore::PREFIX . $field;
			$exists  = metadata_exists( 'term', $term_id, $key );
			$current = $exists ? get_term_meta( $term_id, $key, true ) : null;
			if ( ! $overwrite && ! self::is_empty( $field, $exists, $current ) ) {
				continue;
			}
			if ( $exists && (string) $current === $new ) {
				continue;
			}
			$before[ $key ] = array(
				'exists' => $exists,
				'value'  => $current,
			);
			$after[ $key ]  = $new;
			update_term_meta( $term_id, $key, wp_slash( $new ) );
		}
		if ( ! $after ) {
			return 0;
		}
		$this->changes->record(
			'import_term',
			array(
				'action'      => 'seo_imported',
				/* translators: 1: plugin name, 2: term name. */
				'description' => sprintf( __( 'Imported SEO data from %1$s for the term “%2$s”', 'searchpilot-pro' ), $name, $term->name ),
				'object_type' => 'term',
				'object_id'   => $term_id,
				'before'      => array(
					'term_id' => $term_id,
					'keys'    => $before,
				),
				'after'       => array(
					'term_id' => $term_id,
					'keys'    => $after,
				),
				'source'      => 'import',
				'group_id'    => $group_id,
			)
		);
		return count( $after );
	}

	/**
	 * Imports settings (one activity entry, reverted by the settings handler).
	 *
	 * @param Source $source    Source.
	 * @param bool   $overwrite Overwrite.
	 * @param string $group_id  Group.
	 * @return int Settings changed.
	 */
	public function import_settings( Source $source, $overwrite, $group_id ) {
		$schema  = Settings::schema();
		$changes = array();
		$before  = array();
		foreach ( $source->read_settings() as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$clean = Settings::sanitize_value( $value, $schema[ $key ] );
			if ( $clean instanceof \WP_Error || '' === $clean || array() === $clean ) {
				continue;
			}
			$current = $this->settings->get( $key );
			$default = $schema[ $key ]['default'];
			$empty   = null === $current || '' === $current || array() === $current || 0 === $current || $current === $default;
			if ( ! $overwrite && ! $empty ) {
				continue;
			}
			if ( wp_json_encode( $current ) === wp_json_encode( $clean ) ) {
				continue;
			}
			$changes[ $key ] = $clean;
			$before[ $key ]  = $current;
		}
		if ( ! $changes ) {
			return 0;
		}
		$result = $this->settings->update( $changes );
		foreach ( array_keys( (array) $result['errors'] ) as $failed ) {
			unset( $changes[ $failed ], $before[ $failed ] );
		}
		if ( ! $changes ) {
			return 0;
		}
		$this->changes->record(
			'settings',
			array(
				'action'      => 'settings_imported',
				/* translators: 1: plugin name, 2: setting keys. */
				'description' => sprintf( __( 'Imported settings from %1$s: %2$s', 'searchpilot-pro' ), $source->name(), implode( ', ', array_keys( $changes ) ) ),
				'object_type' => 'settings',
				'before'      => $before,
				'after'       => $changes,
				'source'      => 'import',
				'group_id'    => $group_id,
			)
		);
		return count( $changes );
	}

	/* --------------------------------------------------------------------- *
	 * Undo
	 * --------------------------------------------------------------------- */

	/**
	 * Undoes an import run (small runs immediately, large ones in the background).
	 *
	 * @param string $group_id Group id.
	 * @return array|\WP_Error
	 */
	public function undo( $group_id ) {
		$group_id = preg_replace( '/[^a-z0-9]/i', '', (string) $group_id );
		$known    = false;
		foreach ( $this->history() as $run ) {
			if ( ( $run['group_id'] ?? '' ) === $group_id ) {
				$known = true;
			}
		}
		$current = $this->progress();
		if ( ( $current['group_id'] ?? '' ) === $group_id ) {
			if ( 'running' === ( $current['status'] ?? '' ) ) {
				return new \WP_Error( 'searchpilot_busy', __( 'Cancel the running import before undoing it.', 'searchpilot-pro' ), array( 'status' => 409 ) );
			}
			$known = true;
		}
		if ( '' === $group_id || ! $known ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'That import was not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		$pending = $this->pending_undo_count( $group_id );
		if ( $pending <= 150 ) {
			$result = $this->log->undo_group( $group_id );
			$this->mark_undone( $group_id );
			$this->state->set(
				'import_undo',
				array(
					'status'   => 'done',
					'group_id' => $group_id,
					'undone'   => (int) $result['undone'],
					'failed'   => (int) $result['failed'],
					'errors'   => array_slice( (array) $result['errors'], 0, 5 ),
				)
			);
			return $this->undo_progress();
		}
		$this->state->set(
			'import_undo',
			array(
				'status'   => 'running',
				'group_id' => $group_id,
				'total'    => $pending,
				'undone'   => 0,
				'failed'   => 0,
				'cursor'   => 0,
				'errors'   => array(),
				'user_id'  => get_current_user_id(),
			)
		);
		$this->scheduler->enqueue_async( self::UNDO_HOOK, array(), 2 );
		return $this->undo_progress();
	}

	/**
	 * Background undo batch.
	 *
	 * @return array
	 */
	public function run_undo_batch() {
		$result = Lock::run(
			'import_undo',
			300,
			function () {
				$state = $this->undo_progress();
				if ( 'running' !== ( $state['status'] ?? '' ) ) {
					return $state;
				}
				$previous = get_current_user_id();
				if ( ! $previous && ! empty( $state['user_id'] ) ) {
					wp_set_current_user( (int) $state['user_id'] );
				}
				$budget = new TimeBudget( 20 );
				while ( ! $budget->exhausted() ) {
					$ids = $this->pending_undo_ids( (string) $state['group_id'], (int) $state['cursor'], 100 );
					if ( ! $ids ) {
						$state['status'] = 'done';
						$this->mark_undone( (string) $state['group_id'] );
						break;
					}
					foreach ( $ids as $id ) {
						if ( $budget->exhausted() ) {
							break;
						}
						$r = $this->log->undo( $id );
						if ( is_wp_error( $r ) ) {
							++$state['failed'];
							$state['errors'][] = $r->get_error_message();
						} else {
							++$state['undone'];
						}
						$state['cursor'] = $id;
					}
					$state['errors'] = array_slice( array_unique( (array) $state['errors'] ), -5 );
					$this->state->set( 'import_undo', $state );
				}
				$this->state->set( 'import_undo', $state );
				if ( 'running' === $state['status'] ) {
					$this->scheduler->enqueue_async( self::UNDO_HOOK, array(), 2 );
				}
				if ( ! $previous && get_current_user_id() ) {
					wp_set_current_user( 0 );
				}
				return $state;
			}
		);
		return is_array( $result ) ? $result : $this->undo_progress();
	}

	/**
	 * Reversible entries of a group not yet undone.
	 *
	 * @param string $group_id Group.
	 * @return int
	 */
	private function pending_undo_count( $group_id ) {
		global $wpdb;
		$table = Tables::activity();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE group_id = %s AND reversible = 1 AND status = 'applied'", $group_id ) );
	}

	/**
	 * Next entry ids to undo (newest first, below the cursor).
	 *
	 * @param string $group_id Group.
	 * @param int    $cursor   Last processed id (0 = start).
	 * @param int    $limit    Limit.
	 * @return int[]
	 */
	private function pending_undo_ids( $group_id, $cursor, $limit ) {
		global $wpdb;
		$table = Tables::activity();
		if ( $cursor > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE group_id = %s AND reversible = 1 AND status = 'applied' AND id < %d ORDER BY id DESC LIMIT %d", $group_id, $cursor, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE group_id = %s AND reversible = 1 AND status = 'applied' ORDER BY id DESC LIMIT %d", $group_id, $limit ) );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Flags a history entry as undone.
	 *
	 * @param string $group_id Group.
	 */
	private function mark_undone( $group_id ) {
		$history = $this->history();
		foreach ( $history as $i => $run ) {
			if ( ( $run['group_id'] ?? '' ) === $group_id ) {
				$history[ $i ]['undone']    = true;
				$history[ $i ]['undone_at'] = gmdate( 'c' );
			}
		}
		$this->state->set( 'import_history', $history );
		delete_transient( self::COUNTS_KEY );
	}

	/**
	 * Revert handler: import_post.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_post( array $before, array $after, $row, $force = false ) {
		$post_id = (int) ( $before['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		return self::revert_meta( 'post', $post_id, (array) ( $before['keys'] ?? array() ), (array) ( $after['keys'] ?? array() ), $force );
	}

	/**
	 * Revert handler: import_term.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_term( array $before, array $after, $row, $force = false ) {
		$term_id = (int) ( $before['term_id'] ?? 0 );
		if ( ! $term_id || ! get_term( $term_id ) instanceof \WP_Term ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'The term no longer exists.', 'searchpilot-pro' ) );
		}
		return self::revert_meta( 'term', $term_id, (array) ( $before['keys'] ?? array() ), (array) ( $after['keys'] ?? array() ), $force );
	}

	/**
	 * Restores the previous values of SearchPilot keys (all or nothing).
	 *
	 * @param string $type   post|term.
	 * @param int    $id     Object id.
	 * @param array  $keys   Key => {exists, value}.
	 * @param array  $new    Key => imported value.
	 * @param bool   $force  Restore even when edited since.
	 * @return true|\WP_Error
	 */
	private static function revert_meta( $type, $id, array $keys, array $new, $force ) {
		foreach ( array_keys( $keys ) as $key ) {
			if ( 0 !== strpos( (string) $key, MetaStore::PREFIX ) ) {
				return new \WP_Error( 'searchpilot_invalid', __( 'Nothing to undo.', 'searchpilot-pro' ) );
			}
		}
		if ( ! $force ) {
			foreach ( $new as $key => $value ) {
				$current = metadata_exists( $type, $id, $key ) ? get_metadata( $type, $id, $key, true ) : null;
				if ( (string) $current !== (string) $value ) {
					return new \WP_Error( 'searchpilot_changed_since', __( 'This field was edited after the import, so it was not undone automatically.', 'searchpilot-pro' ), array( 'status' => 409 ) );
				}
			}
		}
		foreach ( $keys as $key => $info ) {
			if ( empty( $info['exists'] ) ) {
				delete_metadata( $type, $id, $key );
			} else {
				$value = $info['value'] ?? '';
				update_metadata( $type, $id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
			}
		}
		return true;
	}

	/* --------------------------------------------------------------------- *
	 * Template conversion (pure)
	 * --------------------------------------------------------------------- */

	/**
	 * Converts another plugin's title/description template to SearchPilot variables.
	 *
	 * Unknown variables are dropped so they never print literally.
	 *
	 * @param string $template Template.
	 * @param string $source   yoast|rank_math|aioseo|seopress|tsf.
	 * @param string $context  post|term|author|archive|search|404|home.
	 * @return string
	 */
	public static function convert_template( $template, $source, $context = 'post' ) {
		$template = (string) $template;
		if ( '' === trim( $template ) ) {
			return '';
		}
		$archive = in_array( $context, array( 'archive', 'term', 'author', 'search', '404' ), true );
		$date    = $archive ? 'archive' : 'date';
		$maps    = array(
			'yoast'     => array(
				'title'                => 'title',
				'sitename'             => 'sitename',
				'sitedesc'             => 'tagline',
				'sep'                  => 'sep',
				'excerpt'              => 'excerpt',
				'excerpt_only'         => 'excerpt_only',
				'category'             => 'category',
				'primary_category'     => 'primary_category',
				'term_title'           => 'term',
				'term_description'     => 'term_description',
				'category_description' => 'term_description',
				'tag_description'      => 'term_description',
				'name'                 => 'author',
				'author_first_name'    => 'author',
				'date'                 => $date,
				'modified'             => 'modified',
				'currentyear'          => 'currentyear',
				'page'                 => 'page',
				'pagenumber'           => 'page',
				'searchphrase'         => 'search',
				'pt_plural'            => 'archive',
				'archive_title'        => 'archive',
				'focuskw'              => 'focuskw',
			),
			'rank_math' => array(
				'title'            => 'title',
				'sitename'         => 'sitename',
				'sitedesc'         => 'tagline',
				'sep'              => 'sep',
				'excerpt'          => 'excerpt',
				'excerpt_only'     => 'excerpt_only',
				'category'         => 'category',
				'primary_category' => 'primary_category',
				'term'             => 'term',
				'term_description' => 'term_description',
				'name'             => 'author',
				'post_author'      => 'author',
				'search_query'     => 'search',
				'date'             => $date,
				'modified'         => 'modified',
				'currentyear'      => 'currentyear',
				'page'             => 'page',
				'pt_plural'        => 'archive',
				'focuskw'          => 'focuskw',
				'org_name'         => 'sitename',
			),
			'seopress'  => array(
				'post_title'            => 'title',
				'sitetitle'             => 'sitename',
				'sitename'              => 'sitename',
				'tagline'               => 'tagline',
				'sep'                   => 'sep',
				'post_excerpt'          => 'excerpt',
				'post_category'         => 'category',
				'post_primary_category' => 'primary_category',
				'_category_title'       => 'term',
				'tag_title'             => 'term',
				'term_title'            => 'term',
				'_category_description' => 'term_description',
				'tag_description'       => 'term_description',
				'term_description'      => 'term_description',
				'post_author'           => 'author',
				'archive_title'         => 'archive',
				'archive_date'          => 'archive',
				'cpt_plural'            => 'archive',
				'search_keywords'       => 'search',
				'current_pagination'    => 'page',
				'page'                  => 'page',
				'currentyear'           => 'currentyear',
				'year'                  => 'currentyear',
				'post_date'             => 'date',
				'post_modified_date'    => 'modified',
				'target_keyword'        => 'focuskw',
			),
			'aioseo'    => array(
				'post_title'           => 'title',
				'site_title'           => 'sitename',
				'separator_sa'         => 'sep',
				'tagline'              => 'tagline',
				'post_excerpt_only'    => 'excerpt_only',
				'post_excerpt'         => 'excerpt',
				'post_content'         => 'excerpt',
				'taxonomy_title'       => 'term',
				'taxonomy_description' => 'term_description',
				'categories'           => 'category',
				'author_name'          => 'author',
				'author_first_name'    => 'author',
				'current_year'         => 'currentyear',
				'post_date'            => 'date',
				'search_term'          => 'search',
				'archive_title'        => 'archive',
				'archive_date'         => 'archive',
				'page_number'          => 'page',
			),
		);
		if ( 'tsf' === $source ) {
			return trim( (string) preg_replace( '/\s{2,}/u', ' ', $template ) );
		}
		$map = $maps[ $source ] ?? array();
		if ( 'aioseo' === $source ) {
			$out = (string) preg_replace_callback(
				'/#([a-z_]+)/',
				static function ( $m ) use ( $map ) {
					// Longest known tag that prefixes the match (tags are followed by text, e.g. "#post_title’s").
					$parts = explode( '_', $m[1] );
					for ( $n = count( $parts ); $n > 0; $n-- ) {
						$name = implode( '_', array_slice( $parts, 0, $n ) );
						if ( isset( $map[ $name ] ) ) {
							return '%' . $map[ $name ] . '%';
						}
					}
					// Unknown AIOSEO tags (#current_date…) are dropped; a plain #hashtag stays.
					return false !== strpos( $m[1], '_' ) ? '' : $m[0];
				},
				$template
			);
		} elseif ( in_array( $source, array( 'yoast', 'seopress' ), true ) ) {
			$out = (string) preg_replace_callback(
				'/%%([a-z0-9_]+)%%/i',
				static function ( $m ) use ( $map ) {
					$name = strtolower( $m[1] );
					return isset( $map[ $name ] ) ? '%' . $map[ $name ] . '%' : '';
				},
				$template
			);
		} else {
			$out = (string) preg_replace_callback(
				'/%([a-z0-9_]+)(\([^)%]*\))?%/i',
				static function ( $m ) use ( $map ) {
					$name = strtolower( $m[1] );
					return isset( $map[ $name ] ) && empty( $m[2] ) ? '%' . $map[ $name ] . '%' : '';
				},
				$template
			);
		}
		$out = (string) preg_replace( '/\s{2,}/u', ' ', $out );
		$out = trim( $out );
		// A template left with only separators carries no information.
		return '' === trim( (string) preg_replace( '/%sep%|[\s\-–—|:·•]+/u', '', $out ) ) ? '' : $out;
	}
}

<?php
/**
 * Fix plans: the reviewable description of a fix.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * A plan is a plain, JSON-serialisable array produced by a planner
 * (`IssueRegistry` definition key `planner`) and applied by FixEngine:
 *
 *     array(
 *       'issue_id'    => int,
 *       'type'        => string,
 *       'object_type' => 'post'|'attachment'|'site',
 *       'object_id'   => int,
 *       'summary'     => string,             // one line, human
 *       'changes'     => array( array(
 *           'kind'      => 'seo_field'|'post_content'|'post_meta'|'attachment_alt'|'featured_image'|'option',
 *           'object_id' => int,              // post/attachment id (0 for option)
 *           'field'     => string,           // seo_field: title|description; post_meta/option: key
 *           'label'     => string,           // human, e.g. "SEO title"
 *           'before'    => string,           // display strings for the diff
 *           'after'     => string,
 *           'value'     => mixed,            // exact value to write (post_content: full new content)
 *           'expect'    => string,           // Plan::hash() of the current value (FixEngine fills it)
 *       ) ),
 *       'verify'      => array( 'types' => string[], 'objects' => int[] ),
 *       'warnings'    => string[],
 *     )
 *
 * Optional change key `marker` (post_content): attribute marking inserted
 * markup, passed to Changes::update_post_content() for surgical undo.
 *
 * This class is pure (no database access) so it can be unit-tested.
 */
class Plan {

	const KINDS = array( 'seo_field', 'post_content', 'post_meta', 'attachment_alt', 'featured_image', 'option' );

	const OBJECT_TYPES = array( 'post', 'attachment', 'site' );

	/**
	 * Default `field` for kinds whose field is implied.
	 */
	const DEFAULT_FIELDS = array(
		'post_content'   => 'post_content',
		'attachment_alt' => '_wp_attachment_image_alt',
		'featured_image' => '_thumbnail_id',
	);

	/**
	 * Values larger than this (JSON bytes) are not sent to the browser.
	 */
	const PUBLIC_VALUE_MAX = 4096;

	/**
	 * Hash of a stored value, used for `expect`: arrays/objects hash their
	 * JSON, scalars and null their string form.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return sha1( (string) wp_json_encode( $value ) );
		}
		return sha1( (string) $value );
	}

	/**
	 * Starts a plan for an issue (planner helper).
	 *
	 * @param array  $issue    Issue row (id, type, object_type, object_id).
	 * @param string $summary  One-line summary.
	 * @param array  $changes  Changes (see Plan::change()).
	 * @param array  $warnings Warnings shown in the preview.
	 * @param array  $verify   Optional verify block: types (default the issue type), objects.
	 * @return array
	 */
	public static function create( array $issue, $summary, array $changes = array(), array $warnings = array(), array $verify = array() ) {
		$object_type = (string) ( $issue['object_type'] ?? '' );
		return array(
			'issue_id'    => (int) ( $issue['id'] ?? 0 ),
			'type'        => (string) ( $issue['type'] ?? '' ),
			'object_type' => in_array( $object_type, array( 'post', 'attachment' ), true ) ? $object_type : 'site',
			'object_id'   => (int) ( $issue['object_id'] ?? 0 ),
			'summary'     => (string) $summary,
			'changes'     => array_values( $changes ),
			'verify'      => array(
				'types'   => isset( $verify['types'] ) ? array_values( (array) $verify['types'] ) : array( (string) ( $issue['type'] ?? '' ) ),
				'objects' => array_values( array_map( 'intval', (array) ( $verify['objects'] ?? array() ) ) ),
			),
			'warnings'    => array_values( array_map( 'strval', $warnings ) ),
		);
	}

	/**
	 * Builds one change (planner helper). `expect` is filled by FixEngine
	 * from the value stored when the plan is made.
	 *
	 * @param string $kind      Kind (see KINDS).
	 * @param int    $object_id Post/attachment id (0 for options).
	 * @param string $field     Field/meta key/option name.
	 * @param string $label     Human label.
	 * @param string $before    Display text before.
	 * @param string $after     Display text after.
	 * @param mixed  $value     Exact value to write.
	 * @return array
	 */
	public static function change( $kind, $object_id, $field, $label, $before, $after, $value ) {
		return array(
			'kind'      => (string) $kind,
			'object_id' => (int) $object_id,
			'field'     => (string) $field,
			'label'     => (string) $label,
			'before'    => (string) $before,
			'after'     => (string) $after,
			'value'     => $value,
			'expect'    => '',
		);
	}

	/**
	 * Validates a plan and normalises its types/defaults.
	 *
	 * @param mixed $plan Plan.
	 * @return array|\WP_Error
	 */
	public static function normalize( $plan ) {
		if ( ! is_array( $plan ) ) {
			return self::invalid( __( 'The fix plan is not valid.', 'searchpilot-pro' ) );
		}
		$type = (string) ( $plan['type'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $type ) ) {
			return self::invalid( __( 'The fix plan does not name a valid issue type.', 'searchpilot-pro' ) );
		}
		$object_type = (string) ( $plan['object_type'] ?? 'site' );
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			$object_type = 'site';
		}
		$changes = array();
		foreach ( (array) ( $plan['changes'] ?? array() ) as $change ) {
			$change = self::normalize_change( $change );
			if ( is_wp_error( $change ) ) {
				return $change;
			}
			$changes[] = $change;
		}
		if ( ! $changes ) {
			return new \WP_Error( 'searchpilot_plan_empty', __( 'Nothing needs to change — the page may already be fixed. Re-check it to update the issue.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$verify = isset( $plan['verify'] ) && is_array( $plan['verify'] ) ? $plan['verify'] : array();
		$types  = isset( $verify['types'] ) ? (array) $verify['types'] : array( $type );
		$types  = array_values( array_unique( array_filter( array_map( 'strval', $types ), array( __CLASS__, 'is_type' ) ) ) );
		$out    = array(
			'issue_id'    => max( 0, (int) ( $plan['issue_id'] ?? 0 ) ),
			'type'        => $type,
			'object_type' => $object_type,
			'object_id'   => max( 0, (int) ( $plan['object_id'] ?? 0 ) ),
			'summary'     => self::text( $plan['summary'] ?? '' ),
			'changes'     => $changes,
			'verify'      => array(
				'types'   => $types,
				'objects' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $verify['objects'] ?? array() ) ) ) ) ),
			),
			'warnings'    => array_values( array_filter( array_map( array( __CLASS__, 'text' ), (array) ( $plan['warnings'] ?? array() ) ) ) ),
		);
		foreach ( array( 'mode', 'label', 'fingerprint' ) as $extra ) {
			if ( isset( $plan[ $extra ] ) && is_string( $plan[ $extra ] ) ) {
				$out[ $extra ] = $plan[ $extra ];
			}
		}
		return $out;
	}

	/**
	 * Validates one change.
	 *
	 * @param mixed $change Change.
	 * @return array|\WP_Error
	 */
	public static function normalize_change( $change ) {
		if ( ! is_array( $change ) ) {
			return self::invalid( __( 'A change in the fix plan is not valid.', 'searchpilot-pro' ) );
		}
		$kind = (string) ( $change['kind'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			/* translators: %s: change kind. */
			return self::invalid( sprintf( __( 'The fix plan contains an unknown kind of change (%s).', 'searchpilot-pro' ), $kind ) );
		}
		$object_id = (int) ( $change['object_id'] ?? 0 );
		$field     = (string) ( $change['field'] ?? '' );
		if ( '' === $field && isset( self::DEFAULT_FIELDS[ $kind ] ) ) {
			$field = self::DEFAULT_FIELDS[ $kind ];
		}
		if ( 'option' === $kind ) {
			$object_id = 0;
			if ( ! preg_match( '/^[A-Za-z0-9_\-.:]{1,191}$/', $field ) ) {
				return self::invalid( __( 'The fix plan names an invalid setting.', 'searchpilot-pro' ) );
			}
		} else {
			if ( $object_id <= 0 ) {
				return self::invalid( __( 'A change in the fix plan does not name the post it changes.', 'searchpilot-pro' ) );
			}
			if ( 'seo_field' === $kind && ! preg_match( '/^[a-z_]{1,40}$/', $field ) ) {
				return self::invalid( __( 'The fix plan names an invalid SEO field.', 'searchpilot-pro' ) );
			}
			if ( 'post_meta' === $kind && ( '' === $field || strlen( $field ) > 255 ) ) {
				return self::invalid( __( 'The fix plan names an invalid custom field.', 'searchpilot-pro' ) );
			}
		}
		$expect = strtolower( (string) ( $change['expect'] ?? '' ) );
		$out    = array(
			'kind'      => $kind,
			'object_id' => $object_id,
			'field'     => $field,
			'label'     => self::text( $change['label'] ?? '' ),
			'before'    => self::display( $change['before'] ?? '' ),
			'after'     => self::display( $change['after'] ?? '' ),
			'value'     => $change['value'] ?? null,
			'expect'    => preg_match( '/^[a-f0-9]{40}$/', $expect ) ? $expect : '',
		);
		if ( 'post_content' === $kind ) {
			$out['value'] = (string) $out['value'];
			if ( isset( $change['marker'] ) && is_string( $change['marker'] ) && '' !== $change['marker'] ) {
				$out['marker'] = substr( $change['marker'], 0, 200 );
			}
		} elseif ( in_array( $kind, array( 'seo_field', 'attachment_alt' ), true ) ) {
			$out['value'] = is_scalar( $out['value'] ) || null === $out['value'] ? $out['value'] : '';
		} elseif ( 'featured_image' === $kind ) {
			$out['value'] = max( 0, (int) $out['value'] );
		}
		return $out;
	}

	/**
	 * Signature of a change (what exactly it writes, and over what).
	 *
	 * @param array $change Change.
	 * @return string
	 */
	public static function signature( array $change ) {
		return sha1(
			implode(
				'|',
				array(
					(string) ( $change['kind'] ?? '' ),
					(int) ( $change['object_id'] ?? 0 ),
					(string) ( $change['field'] ?? '' ),
					self::hash( $change['value'] ?? null ),
					(string) ( $change['expect'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Fingerprint of a whole plan: identical when the same writes would be
	 * made over the same current values.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	public static function fingerprint( array $plan ) {
		$parts = array( (int) ( $plan['issue_id'] ?? 0 ), (string) ( $plan['type'] ?? '' ) );
		foreach ( (array) ( $plan['changes'] ?? array() ) as $change ) {
			$parts[] = self::signature( (array) $change );
		}
		return sha1( implode( '|', $parts ) );
	}

	/**
	 * What a change writes to (kind, object, field), independent of the value.
	 *
	 * @param array $change Change.
	 * @return string
	 */
	public static function change_key( array $change ) {
		return (string) ( $change['kind'] ?? '' ) . '|' . (int) ( $change['object_id'] ?? 0 ) . '|' . (string) ( $change['field'] ?? '' );
	}

	/**
	 * Which changes of a plan made again at apply time were reviewed. A
	 * change counts as reviewed when its signature was reviewed, or when the
	 * same (kind, object, field) was reviewed AND that object was changed
	 * earlier in the same run (so the value differs only because of that
	 * earlier, also reviewed, change). Any other change was never seen.
	 *
	 * @param array    $plan       Plan made now.
	 * @param string[] $signatures Reviewed change signatures.
	 * @param string[] $keys       Reviewed change keys (change_key()).
	 * @param int[]    $touched    Objects changed earlier in the run.
	 * @return array{ok:bool,signatures:string[],unreviewed:array[]} ok: something to apply and nothing unreviewed.
	 */
	public static function reviewed_changes( array $plan, array $signatures, array $keys, array $touched ) {
		$sigs       = array_flip( array_map( 'strval', $signatures ) );
		$key_set    = array_flip( array_map( 'strval', $keys ) );
		$touched    = array_flip( array_map( 'intval', $touched ) );
		$keep       = array();
		$unreviewed = array();
		foreach ( (array) ( $plan['changes'] ?? array() ) as $change ) {
			$change = (array) $change;
			$sig    = self::signature( $change );
			$object = (int) ( $change['object_id'] ?? 0 );
			if ( isset( $sigs[ $sig ] ) || ( $object > 0 && isset( $key_set[ self::change_key( $change ) ], $touched[ $object ] ) ) ) {
				$keep[] = $sig;
				continue;
			}
			$unreviewed[] = $change;
		}
		return array(
			'ok'         => ! $unreviewed && $keep,
			'signatures' => $keep,
			'unreviewed' => $unreviewed,
		);
	}

	/**
	 * Keeps only the changes with the given signatures.
	 *
	 * @param array    $plan       Plan.
	 * @param string[] $signatures Signatures.
	 * @return array
	 */
	public static function subset( array $plan, array $signatures ) {
		$keep            = array_flip( array_map( 'strval', $signatures ) );
		$plan['changes'] = array_values(
			array_filter(
				(array) $plan['changes'],
				static function ( $change ) use ( $keep ) {
					return isset( $keep[ self::signature( (array) $change ) ] );
				}
			)
		);
		$plan['fingerprint'] = self::fingerprint( $plan );
		return $plan;
	}

	/**
	 * Ids of the posts a plan touches or asks to verify (attachment changes
	 * map to the plan's post), for re-scans. Not filtered by post type.
	 *
	 * @param array $plan Plan.
	 * @return int[]
	 */
	public static function objects( array $plan ) {
		$ids = array();
		if ( in_array( $plan['object_type'] ?? '', array( 'post', 'attachment' ), true ) && ! empty( $plan['object_id'] ) ) {
			$ids[] = (int) $plan['object_id'];
		}
		foreach ( (array) ( $plan['changes'] ?? array() ) as $change ) {
			if ( 'option' === ( $change['kind'] ?? '' ) || 'attachment_alt' === ( $change['kind'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $change['object_id'] ) ) {
				$ids[] = (int) $change['object_id'];
			}
		}
		foreach ( (array) ( $plan['verify']['objects'] ?? array() ) as $id ) {
			$ids[] = (int) $id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * The plan as sent to the browser: large values are left out (they are
	 * never accepted back — apply re-plans on the server), and every change
	 * carries its signature.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	public static function public_view( array $plan ) {
		$fingerprint = self::fingerprint( $plan );
		foreach ( (array) $plan['changes'] as $i => $change ) {
			$plan['changes'][ $i ]['signature'] = self::signature( $change );
			$json                               = wp_json_encode( $change['value'] ?? null );
			if ( 'post_content' === $change['kind'] || strlen( (string) $json ) > self::PUBLIC_VALUE_MAX ) {
				unset( $plan['changes'][ $i ]['value'] );
				$plan['changes'][ $i ]['value_hidden'] = true;
			}
		}
		$plan['fingerprint'] = $fingerprint;
		return $plan;
	}

	/**
	 * Whether a string is an issue type id.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_type( $type ) {
		return (bool) preg_match( '/^[a-z0-9_]{1,64}$/', (string) $type );
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ) : '';
	}

	/**
	 * Display string (multi-line allowed; arrays as JSON).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}
		return (string) wp_json_encode( $value );
	}

	/**
	 * Invalid-plan error.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function invalid( $message ) {
		return new \WP_Error( 'searchpilot_plan_invalid', $message, array( 'status' => 400 ) );
	}
}

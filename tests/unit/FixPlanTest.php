<?php
/**
 * Tests for fix plans, the fix engine's pure rules and issue-type modes.
 *
 * @package SearchPilot
 */

// phpcs:disable

namespace {
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( (array) $defaults, (array) $args );
		}
	}
}

namespace SearchPilot\Tests {

use SearchPilot\Fixes\FixEngine;
use SearchPilot\Fixes\Plan;
use SearchPilot\Issues\IssueRegistry;

/**
 * Plan validation, hashing, fingerprints, subsets, public view; FixEngine
 * introduced-type detection; IssueRegistry extend() and mode resolution.
 */
class FixPlanTest extends \PHPUnit\Framework\TestCase {

	private static function issue( array $over = array() ) {
		return array_merge(
			array(
				'id'          => 12,
				'type'        => 'title_length',
				'object_type' => 'post',
				'object_id'   => 34,
			),
			$over
		);
	}

	private static function plan( array $changes = null ) {
		$changes = null === $changes ? array( Plan::change( 'seo_field', 34, 'title', 'SEO title', 'Old', 'New', 'New' ) ) : $changes;
		return Plan::create( self::issue(), 'Shorten the SEO title', $changes );
	}

	/* ---------------- hash ---------------- */

	public function test_hash_follows_the_contract() {
		$this->assertSame( sha1( 'abc' ), Plan::hash( 'abc' ) );
		$this->assertSame( sha1( '12' ), Plan::hash( 12 ) );
		$this->assertSame( sha1( '' ), Plan::hash( null ) );
		$this->assertSame( sha1( '' ), Plan::hash( false ) );
		$this->assertSame( sha1( '1' ), Plan::hash( true ) );
		$this->assertSame( sha1( json_encode( array( 'a' => 1 ) ) ), Plan::hash( array( 'a' => 1 ) ) );
		$object    = new \stdClass();
		$object->x = 'y';
		$this->assertSame( sha1( json_encode( $object ) ), Plan::hash( $object ) );
		$this->assertSame( sha1( 'ಕನ್ನಡ' ), Plan::hash( 'ಕನ್ನಡ' ) );
	}

	/* ---------------- create / change ---------------- */

	public function test_create_defaults() {
		$plan = self::plan();
		$this->assertSame( 12, $plan['issue_id'] );
		$this->assertSame( 'post', $plan['object_type'] );
		$this->assertSame( array( 'title_length' ), $plan['verify']['types'] );
		$this->assertSame( array(), $plan['verify']['objects'] );
		$site = Plan::create( self::issue( array( 'object_type' => 'url' ) ), 'x' );
		$this->assertSame( 'site', $site['object_type'] );
		$change = Plan::change( 'post_content', 34, '', 'Content', 'a', 'b', 'full' );
		$this->assertSame( '', $change['expect'] );
	}

	/* ---------------- normalize ---------------- */

	public function test_normalize_accepts_a_valid_plan_and_fills_defaults() {
		$plan = self::plan(
			array(
				Plan::change( 'post_content', 34, '', 'Content', '', '', '<p>x</p>' ) + array( 'marker' => 'data-spp-link="34"' ),
				Plan::change( 'attachment_alt', 50, '', 'ALT', '', 'A cat', 'A cat' ),
				Plan::change( 'featured_image', 34, '', 'Featured image', '', '', '77' ),
				Plan::change( 'option', 99, 'rank_math_modules', 'Rank Math modules', '', '', array( 'sitemap' ) ),
			)
		);
		$out  = Plan::normalize( $plan );
		$this->assertIsArray( $out );
		$this->assertSame( 'post_content', $out['changes'][0]['field'] );
		$this->assertSame( 'data-spp-link="34"', $out['changes'][0]['marker'] );
		$this->assertSame( '_wp_attachment_image_alt', $out['changes'][1]['field'] );
		$this->assertSame( '_thumbnail_id', $out['changes'][2]['field'] );
		$this->assertSame( 77, $out['changes'][2]['value'] );
		$this->assertSame( 0, $out['changes'][3]['object_id'] );
		$this->assertSame( array( 'sitemap' ), $out['changes'][3]['value'] );
	}

	public function test_normalize_rejects_bad_plans() {
		$this->assertTrue( is_wp_error( Plan::normalize( 'nope' ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( array_merge( self::plan(), array( 'type' => 'Bad Type' ) ) ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( self::plan( array( Plan::change( 'shell', 1, 'x', '', '', '', '' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( self::plan( array( Plan::change( 'seo_field', 0, 'title', '', '', '', 'x' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( self::plan( array( Plan::change( 'seo_field', 5, 'Title; DROP', '', '', '', 'x' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( self::plan( array( Plan::change( 'option', 0, 'bad name', '', '', '', 'x' ) ) ) ) ) );
		$this->assertTrue( is_wp_error( Plan::normalize( self::plan( array( Plan::change( 'post_meta', 5, '', '', '', '', 'x' ) ) ) ) ) );
		$empty = Plan::normalize( self::plan( array() ) );
		$this->assertTrue( is_wp_error( $empty ) );
		$this->assertSame( 'searchpilot_plan_empty', $empty->get_error_code() );
	}

	public function test_normalize_drops_invalid_expect_and_keeps_valid_one() {
		$change           = Plan::change( 'seo_field', 34, 'title', 'SEO title', 'Old', 'New', 'New' );
		$change['expect'] = 'not-a-hash';
		$out              = Plan::normalize( self::plan( array( $change ) ) );
		$this->assertSame( '', $out['changes'][0]['expect'] );
		$change['expect'] = strtoupper( sha1( 'Old' ) );
		$out              = Plan::normalize( self::plan( array( $change ) ) );
		$this->assertSame( sha1( 'Old' ), $out['changes'][0]['expect'] );
	}

	/* ---------------- signatures, fingerprints, subsets ---------------- */

	public function test_fingerprint_is_stable_and_sensitive_to_values_and_expect() {
		$a = Plan::normalize( self::plan() );
		$b = Plan::normalize( self::plan() );
		$this->assertSame( Plan::fingerprint( $a ), Plan::fingerprint( $b ) );

		$c                            = $a;
		$c['changes'][0]['value']     = 'Other';
		$this->assertNotSame( Plan::fingerprint( $a ), Plan::fingerprint( $c ) );

		$d                            = $a;
		$d['changes'][0]['expect']    = sha1( 'changed meanwhile' );
		$this->assertNotSame( Plan::fingerprint( $a ), Plan::fingerprint( $d ) );

		// Display strings do not change what is written.
		$e                            = $a;
		$e['changes'][0]['after']     = 'Shown differently';
		$this->assertSame( Plan::fingerprint( $a ), Plan::fingerprint( $e ) );
	}

	public function test_subset_keeps_only_chosen_changes() {
		$plan = Plan::normalize(
			self::plan(
				array(
					Plan::change( 'seo_field', 34, 'title', 'SEO title', 'a', 'b', 'b' ),
					Plan::change( 'seo_field', 34, 'description', 'Description', 'c', 'd', 'd' ),
				)
			)
		);
		$keep = Plan::signature( $plan['changes'][1] );
		$sub  = Plan::subset( $plan, array( $keep ) );
		$this->assertCount( 1, $sub['changes'] );
		$this->assertSame( 'description', $sub['changes'][0]['field'] );
		$this->assertSame( Plan::fingerprint( $sub ), $sub['fingerprint'] );
		$this->assertNotSame( Plan::fingerprint( $plan ), $sub['fingerprint'] );
		$this->assertCount( 0, Plan::subset( $plan, array( sha1( 'unknown' ) ) )['changes'] );
	}

	public function test_reviewed_changes_never_let_unreviewed_writes_through() {
		$link = static function ( $source, $value, $expect ) {
			$c           = Plan::change( 'post_content', $source, '', 'Link', '', '', $value );
			$c['expect'] = sha1( $expect );
			return $c;
		};
		$issue = self::issue( array( 'type' => 'orphan_page', 'object_id' => 2 ) );
		// T2 was reviewed with links from S1, S2 and S9.
		$reviewed = Plan::normalize( Plan::create( $issue, 'x', array( $link( 101, 'S1+T2', 'S1' ), $link( 102, 'S2+T2', 'S2' ), $link( 109, 'S9+T2', 'S9' ) ) ) );
		$sigs     = array_map( array( Plan::class, 'signature' ), $reviewed['changes'] );
		$keys     = array_map( array( Plan::class, 'change_key' ), $reviewed['changes'] );

		// Unchanged plan: everything reviewed.
		$same = Plan::reviewed_changes( $reviewed, $sigs, $keys, array() );
		$this->assertTrue( $same['ok'] );
		$this->assertCount( 3, $same['signatures'] );

		// Item 1 of the run changed S1 and filled S2: T2 re-plans to S1 (new value), S9, S12.
		$replanned = Plan::normalize( Plan::create( $issue, 'x', array( $link( 101, 'S1+T1+T2', 'S1+T1' ), $link( 109, 'S9+T2', 'S9' ), $link( 112, 'S12+T2', 'S12' ) ) ) );
		$check     = Plan::reviewed_changes( $replanned, $sigs, $keys, array( 101, 102 ) );
		$this->assertFalse( $check['ok'], 'S12 was never reviewed: the item must be skipped' );
		$this->assertSame( array( 112 ), array_map( 'intval', array_column( $check['unreviewed'], 'object_id' ) ) );

		// Without S12 the re-plan is acceptable: S1 differs only because this run changed S1.
		$ok = Plan::reviewed_changes( Plan::normalize( Plan::create( $issue, 'x', array( $link( 101, 'S1+T1+T2', 'S1+T1' ), $link( 109, 'S9+T2', 'S9' ) ) ) ), $sigs, $keys, array( 101, 102 ) );
		$this->assertTrue( $ok['ok'] );
		$this->assertCount( 2, $ok['signatures'] );

		// A different value on a reviewed object the run did NOT touch is not reviewed.
		$other = Plan::reviewed_changes( Plan::normalize( Plan::create( $issue, 'x', array( $link( 109, 'S9+T2 changed', 'S9 edited by a person' ) ) ) ), $sigs, $keys, array( 101 ) );
		$this->assertFalse( $other['ok'] );
		// Options are only ever accepted by exact signature.
		$opt   = Plan::normalize( Plan::create( $issue, 'x', array( Plan::change( 'option', 0, 'some_option', '', '', '', 1 ) + array( 'expect' => sha1( '' ) ) ) ) );
		$check = Plan::reviewed_changes( $opt, array(), array( 'option|0|some_option' ), array( 0 ) );
		$this->assertFalse( $check['ok'] );
		// Nothing reviewed at all (old runs): skipped.
		$this->assertFalse( Plan::reviewed_changes( $reviewed, array(), array(), array() )['ok'] );
		$this->assertSame( 'post_content|101|post_content', Plan::change_key( $reviewed['changes'][0] ) );
	}

	public function test_objects_lists_posts_to_rescan() {
		$plan                       = Plan::normalize(
			self::plan(
				array(
					Plan::change( 'post_content', 80, '', '', '', '', 'x' ),
					Plan::change( 'attachment_alt', 50, '', '', '', '', 'alt' ),
					Plan::change( 'option', 0, 'opt', '', '', '', 1 ),
					Plan::change( 'seo_field', 34, 'title', '', '', '', 't' ),
				)
			)
		);
		$plan['verify']['objects'] = array( 90, 80 );
		$this->assertSame( array( 34, 80, 90 ), Plan::objects( $plan ) );

		$site = Plan::normalize( Plan::create( self::issue( array( 'object_type' => 'site', 'object_id' => 0 ) ), 'x', array( Plan::change( 'option', 0, 'opt', '', '', '', 1 ) ) ) );
		$this->assertSame( array(), Plan::objects( $site ) );
	}

	public function test_public_view_hides_large_values_and_adds_signatures() {
		$plan = Plan::normalize(
			self::plan(
				array(
					Plan::change( 'post_content', 34, '', 'Content', '<h4>a</h4>', '<h3>a</h3>', str_repeat( 'x', 10 ) ),
					Plan::change( 'seo_field', 34, 'title', 'SEO title', 'a', 'b', 'b' ),
					Plan::change( 'option', 0, 'big', '', '', '', array_fill( 0, 2000, 'value' ) ),
				)
			)
		);
		$view = Plan::public_view( $plan );
		$this->assertFalse( array_key_exists( 'value', $view['changes'][0] ) );
		$this->assertTrue( $view['changes'][0]['value_hidden'] );
		$this->assertSame( 'b', $view['changes'][1]['value'] );
		$this->assertFalse( array_key_exists( 'value', $view['changes'][2] ) );
		$this->assertSame( Plan::signature( $plan['changes'][1] ), $view['changes'][1]['signature'] );
		$this->assertSame( Plan::fingerprint( $plan ), $view['fingerprint'] );
	}

	/* ---------------- FixEngine rules ---------------- */

	public function test_introduced_types_per_post() {
		$before = array(
			34 => array( 'title_length', 'missing_alt_text' ),
			80 => array(),
		);
		$after  = array(
			34 => array( 'missing_alt_text', 'duplicate_title' ),
			80 => array( 'orphan_page' ),
		);
		$this->assertSame( array( 'duplicate_title', 'orphan_page' ), FixEngine::introduced( $before, $after ) );
		$this->assertSame( array(), FixEngine::introduced( $before, array( 34 => array( 'missing_alt_text' ) ) ) );
		// A post without a baseline counts every open type as new.
		$this->assertSame( array( 'thin_content' ), FixEngine::introduced( array(), array( 7 => array( 'thin_content' ) ) ) );
	}

	public function test_cross_object_types_are_not_introduced_by_own_post_changes() {
		// F's live case: a heading fix on post 305; orphan_page appeared after the re-scan.
		$before = array( 305 => array( 'heading_structure' ) );
		$after  = array( 305 => array( 'orphan_page' ) );
		$heading_plan = Plan::normalize( Plan::create( self::issue( array( 'type' => 'heading_structure', 'object_id' => 305 ) ), 'x', array( Plan::change( 'post_content', 305, '', '', '', '', '<h2>x</h2>' ) ) ) );
		$this->assertFalse( FixEngine::writes_other_content( $heading_plan ) );
		$ignore = FixEngine::writes_other_content( $heading_plan ) ? array() : array( 'orphan_page', 'custom_incoming' );
		$this->assertSame( array(), FixEngine::introduced( $before, $after, $ignore ) );
		// Other types are still caught.
		$this->assertSame( array( 'duplicate_title' ), FixEngine::introduced( $before, array( 305 => array( 'orphan_page', 'duplicate_title' ) ), $ignore ) );

		// L's link plans write SOURCE posts for a TARGET issue: incoming types count again.
		$link_plan = Plan::normalize( Plan::create( self::issue( array( 'type' => 'orphan_page', 'object_id' => 305 ) ), 'x', array( Plan::change( 'post_content', 410, '', '', '', '', '<p><a href="/t/">t</a></p>' ) ) ) );
		$this->assertTrue( FixEngine::writes_other_content( $link_plan ) );
		$this->assertSame( array( 'orphan_page' ), FixEngine::introduced( array( 410 => array() ), array( 410 => array( 'orphan_page' ) ), FixEngine::writes_other_content( $link_plan ) ? array() : array( 'orphan_page' ) ) );
		// SEO-field plans never write content.
		$this->assertFalse( FixEngine::writes_other_content( self::plan() ) );
	}

	public function test_measurable_after_and_cross_object_types_are_not_blamed() {
		$registry = new IssueRegistry();
		$registry->register( 'orphan_page', array( 'label' => 'Orphan' ) );
		$registry->register( 'description_length', array( 'label' => 'Length' ) );
		$registry->register( 'missing_meta_description', array( 'label' => 'Missing' ) );
		$registry->register( 'inbound', array( 'label' => 'Inbound', 'scope' => 'incoming' ) );
		$registry->register( 'p_short', array( 'label' => 'Short', 'measurable_after' => array( 'p_missing' ) ) );
		$engine = ( new \ReflectionClass( FixEngine::class ) )->newInstanceWithoutConstructor();
		$prop   = new \ReflectionProperty( FixEngine::class, 'registry' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( $engine, $registry );

		// RV2 #6: filling an empty description makes its length measurable — not a new problem.
		$ignore = $engine->not_introduced_by( 'missing_meta_description', false );
		$this->assertContains( 'description_length', $ignore );
		$this->assertContains( 'orphan_page', $ignore );
		$this->assertContains( 'inbound', $ignore );
		$this->assertSame( array(), FixEngine::introduced( array( 7 => array( 'missing_meta_description' ) ), array( 7 => array( 'description_length' ) ), $ignore ) );
		// Definitions can declare the relation themselves.
		$this->assertContains( 'p_short', $engine->not_introduced_by( 'p_missing', false ) );
		// Other fixes are still blamed for a description that becomes too short.
		$this->assertNotContains( 'description_length', $engine->not_introduced_by( 'title_length', false ) );
		// Link plans writing other posts' content are blamed for cross-object types.
		$this->assertNotContains( 'orphan_page', $engine->not_introduced_by( 'orphan_page', true ) );
	}

	public function test_fresh_plan_must_still_contain_every_planned_change() {
		$a     = Plan::change( 'seo_field', 34, 'title', '', '', '', 'A' );
		$b     = Plan::change( 'seo_field', 34, 'description', '', '', '', 'B' );
		$a['expect'] = sha1( 'old a' );
		$b['expect'] = sha1( 'old b' );
		$full  = Plan::normalize( self::plan( array( $a, $b ) ) );
		$part  = Plan::subset( $full, array( Plan::signature( $full['changes'][1] ) ) );
		$this->assertTrue( FixEngine::is_subset( $full, $full ) );
		$this->assertTrue( FixEngine::is_subset( $part, $full ) );
		// RV3 C: the fresh scan changed what the planner proposes.
		$c           = $a;
		$c['value']  = 'A, from fresher data';
		$fresh       = Plan::normalize( self::plan( array( $c, $b ) ) );
		$this->assertFalse( FixEngine::is_subset( $full, $fresh ) );
		$this->assertTrue( FixEngine::is_subset( $part, $fresh ), 'The reviewed subset is still proposed unchanged' );
	}

	public function test_pending_verification_decisions() {
		$now    = 1000000;
		$fresh  = array( 'at' => $now - 60, 'last' => $now - 60, 'attempts' => 0 );
		$old    = array( 'at' => $now - 3600, 'last' => $now - 3600, 'attempts' => 0 );
		$tired  = array( 'at' => $now - 7200, 'last' => $now - 1800, 'attempts' => FixEngine::PENDING_ATTEMPTS - 1 );
		// Resolved by any scan since the fix: verified, whatever the age.
		$this->assertSame( 'verified', FixEngine::pending_decision( $fresh, false, false, $now ) );
		// A complete re-scan in the sweep still finds it: not confirmed.
		$this->assertSame( 'unverified', FixEngine::pending_decision( $old, true, true, $now ) );
		// Younger than the retry window: wait.
		$this->assertSame( 'wait', FixEngine::pending_decision( $fresh, true, false, $now ) );
		// Older, scan still incomplete: retry (re-queue), then give up after the last attempt.
		$this->assertSame( 'retry', FixEngine::pending_decision( $old, true, false, $now ) );
		$this->assertSame( 'give_up', FixEngine::pending_decision( $tired, true, false, $now ) );
		$this->assertSame( 'verify', 'verified' === FixEngine::pending_decision( $tired, false, false, $now ) ? 'verify' : 'x' );
		$this->assertTrue( FixEngine::PENDING_RETRY >= 15 * 60 );
	}

	/* ---------------- IssueRegistry ---------------- */

	public function test_extend_merges_before_and_after_registration() {
		$r       = new IssueRegistry();
		$planner = static function () {
			return array();
		};
		$r->extend( 'later', array( 'planner' => $planner, 'mode' => 'deterministic' ) );
		$r->register( 'early', array( 'label' => 'Early', 'fix' => 'Do it' ) );
		$r->extend( 'early', array( 'manual_step' => 'Open the editor', 'label' => 'Early (extended)' ) );
		$r->register( 'later', array( 'label' => 'Later' ) );

		$early = $r->get( 'early' );
		$this->assertSame( 'Early (extended)', $early['label'] );
		$this->assertSame( 'Open the editor', $early['manual_step'] );
		$this->assertSame( 'Do it', $early['fix'] );
		$later = $r->get( 'later' );
		$this->assertSame( 'Later', $later['label'] );
		$this->assertSame( 'deterministic', $later['mode'] );
		$this->assertTrue( IssueRegistry::has_planner( $later ) );
		$this->assertFalse( IssueRegistry::has_planner( $early ) );
	}

	public function test_mode_resolution() {
		$r       = new IssueRegistry();
		$planner = static function () {
			return array();
		};
		$fixer   = static function () {
			return true;
		};
		$this->assertSame( 'advisory', $r->mode_for( array( 'mode' => '' ) ) );
		$this->assertSame( 'advisory', $r->mode_for( array( 'mode' => 'deterministic' ) ), 'No planner or fixer: always advisory' );
		$this->assertSame( 'assisted', $r->mode_for( array( 'planner' => $planner, 'mode' => '' ) ) );
		$this->assertSame( 'assisted', $r->mode_for( array( 'fixer' => $fixer ) ) );
		$this->assertSame( 'assisted', $r->mode_for( array( 'planner' => $planner, 'mode' => 'bogus' ) ) );
		$this->assertSame( 'deterministic', $r->mode_for( array( 'planner' => $planner, 'mode' => 'deterministic' ) ) );
		$this->assertSame( 'advisory', $r->mode_for( array( 'planner' => $planner, 'mode' => 'advisory' ) ) );

		$per_issue = array(
			'planner' => $planner,
			'mode'    => static function ( $issue ) {
				return 'page' === ( $issue['data']['post_type'] ?? '' ) ? 'advisory' : 'deterministic';
			},
		);
		$this->assertSame( 'deterministic', $r->mode_for( $per_issue, array( 'data' => array( 'post_type' => 'post' ) ) ) );
		$this->assertSame( 'advisory', $r->mode_for( $per_issue, array( 'data' => array( 'post_type' => 'page' ) ) ) );
		$this->assertSame( 'assisted', $r->mode_for( $per_issue ), 'Type level: callable modes resolve to the default' );

		$throws = array(
			'planner' => $planner,
			'mode'    => static function () {
				throw new \RuntimeException( 'x' );
			},
		);
		$this->assertSame( 'advisory', $r->mode_for( $throws, array() ) );
		// A string that happens to be a function name is a mode string, not a callable.
		$this->assertSame( 'assisted', $r->mode_for( array( 'planner' => $planner, 'mode' => 'strlen' ) ) );
	}
}
}

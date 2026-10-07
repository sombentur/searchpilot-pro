<?php
/**
 * Table definitions (dbDelta format).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * CREATE TABLE statements for the current schema version. dbDelta rules:
 * one column per line, two spaces after PRIMARY KEY, KEY (not INDEX).
 * All DATETIME columns hold UTC.
 */
final class Schema {

	/**
	 * CREATE TABLE statements keyed by short table name.
	 *
	 * @return array<string,string>
	 */
	public static function statements() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		$t = array();

		$t['urls'] = 'CREATE TABLE ' . Tables::urls() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(40) NOT NULL,
  url text NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_subtype varchar(64) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  index_status varchar(40) NOT NULL DEFAULT 'unknown',
  coverage_state varchar(191) NOT NULL DEFAULT '',
  verdict varchar(20) NOT NULL DEFAULT '',
  indexable tinyint(1) NOT NULL DEFAULT 1,
  indexability_reason varchar(64) NOT NULL DEFAULT '',
  in_sitemap tinyint(1) NOT NULL DEFAULT 0,
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  redirect_to text NULL,
  canonical_url text NULL,
  google_canonical text NULL,
  robots_meta varchar(191) NOT NULL DEFAULT '',
  robots_txt_blocked tinyint(1) NOT NULL DEFAULT 0,
  engines varchar(191) NOT NULL DEFAULT '',
  errors longtext NULL,
  recommended_action varchar(64) NOT NULL DEFAULT '',
  priority tinyint(3) unsigned NOT NULL DEFAULT 50,
  content_hash char(32) NOT NULL DEFAULT '',
  last_modified datetime NULL DEFAULT NULL,
  last_submitted datetime NULL DEFAULT NULL,
  last_crawled datetime NULL DEFAULT NULL,
  last_inspected datetime NULL DEFAULT NULL,
  last_checked datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY object (object_type,object_id),
  KEY index_status (index_status),
  KEY status_checked (status,last_checked),
  KEY status_inspected (status,last_inspected),
  KEY http_status (http_status)
) $c;";

		$t['queue'] = 'CREATE TABLE ' . Tables::queue() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service varchar(32) NOT NULL,
  action varchar(20) NOT NULL DEFAULT 'update',
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  dedupe_key char(40) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  priority tinyint(3) unsigned NOT NULL DEFAULT 10,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_attempts tinyint(3) unsigned NOT NULL DEFAULT 5,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(64) NOT NULL DEFAULT '',
  response_code smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  batch_id varchar(40) NOT NULL DEFAULT '',
  available_at datetime NOT NULL,
  locked_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_available (status,available_at,priority),
  KEY dedupe (dedupe_key,status),
  KEY url_hash (url_hash),
  KEY service_status (service,status),
  KEY completed_at (completed_at)
) $c;";

		$t['issues'] = 'CREATE TABLE ' . Tables::issues() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  issue_key char(40) NOT NULL,
  type varchar(64) NOT NULL,
  category varchar(32) NOT NULL DEFAULT '',
  severity varchar(16) NOT NULL DEFAULT 'medium',
  severity_rank tinyint(3) unsigned NOT NULL DEFAULT 3,
  status varchar(20) NOT NULL DEFAULT 'open',
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url text NULL,
  title varchar(255) NOT NULL DEFAULT '',
  data longtext NULL,
  occurrences int(10) unsigned NOT NULL DEFAULT 1,
  detected_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  resolved_at datetime NULL DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY issue_key (issue_key),
  KEY status_rank (status,severity_rank),
  KEY type_status (type,status),
  KEY category (category,status),
  KEY object (object_type,object_id)
) $c;";

		$t['logs'] = 'CREATE TABLE ' . Tables::logs() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL,
  channel varchar(32) NOT NULL,
  message text NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY channel (channel,created_at),
  KEY level (level,created_at)
) $c;";

		$t['activity'] = 'CREATE TABLE ' . Tables::activity() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'user',
  action varchar(64) NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description text NOT NULL,
  handler varchar(40) NOT NULL DEFAULT '',
  before_data longtext NULL,
  after_data longtext NULL,
  reversible tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'applied',
  group_id varchar(40) NOT NULL DEFAULT '',
  reverted_at datetime NULL DEFAULT NULL,
  reverted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object (object_type,object_id),
  KEY group_id (group_id),
  KEY action (action)
) $c;";

		$t['redirects'] = 'CREATE TABLE ' . Tables::redirects() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source text NOT NULL,
  source_hash char(40) NOT NULL DEFAULT '',
  match_type varchar(10) NOT NULL DEFAULT 'exact',
  target text NULL,
  status_code smallint(5) unsigned NOT NULL DEFAULT 301,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  origin varchar(20) NOT NULL DEFAULT 'manual',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  notes varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source_hash (source_hash,enabled),
  KEY match_type (match_type,enabled),
  KEY object_id (object_id)
) $c;";

		$t['not_found'] = 'CREATE TABLE ' . Tables::not_found() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(40) NOT NULL,
  url text NOT NULL,
  referrer text NULL,
  user_agent varchar(255) NOT NULL DEFAULT '',
  is_bot tinyint(1) NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'open',
  redirect_id bigint(20) unsigned NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY last_seen (last_seen),
  KEY status_hits (status,hits)
) $c;";

		$t['links'] = 'CREATE TABLE ' . Tables::links() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  target_url text NOT NULL,
  target_hash char(40) NOT NULL,
  anchor varchar(255) NOT NULL DEFAULT '',
  is_internal tinyint(1) NOT NULL DEFAULT 1,
  is_nofollow tinyint(1) NOT NULL DEFAULT 0,
  inserted_by_plugin tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY source_id (source_id),
  KEY target_id (target_id),
  KEY target_hash (target_hash)
) $c;";

		$t['link_suggestions'] = 'CREATE TABLE ' . Tables::link_suggestions() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  anchor varchar(255) NOT NULL DEFAULT '',
  context text NULL,
  score float NOT NULL DEFAULT 0,
  direction varchar(10) NOT NULL DEFAULT 'outbound',
  kind varchar(10) NOT NULL DEFAULT 'inline',
  trigger_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY pair (source_id,target_id),
  KEY status (status,score),
  KEY target_id (target_id)
) $c;";

		$t['terms'] = 'CREATE TABLE ' . Tables::terms() . " (
  term varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  weight float NOT NULL DEFAULT 0,
  PRIMARY KEY  (term,post_id),
  KEY post_id (post_id)
) $c;";

		$t['term_stats'] = 'CREATE TABLE ' . Tables::term_stats() . " (
  term varchar(64) NOT NULL,
  df int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (term)
) $c;";

		$t['content'] = 'CREATE TABLE ' . Tables::content() . " (
  post_id bigint(20) unsigned NOT NULL,
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  content_hash char(32) NOT NULL DEFAULT '',
  simhash char(16) NOT NULL DEFAULT '',
  band1 smallint(5) unsigned NOT NULL DEFAULT 0,
  band2 smallint(5) unsigned NOT NULL DEFAULT 0,
  band3 smallint(5) unsigned NOT NULL DEFAULT 0,
  band4 smallint(5) unsigned NOT NULL DEFAULT 0,
  title_hash char(32) NOT NULL DEFAULT '',
  description_hash char(32) NOT NULL DEFAULT '',
  seo_score tinyint(3) unsigned NOT NULL DEFAULT 0,
  inbound_links int(10) unsigned NOT NULL DEFAULT 0,
  outbound_internal int(10) unsigned NOT NULL DEFAULT 0,
  outbound_external int(10) unsigned NOT NULL DEFAULT 0,
  term_count int(10) unsigned NOT NULL DEFAULT 0,
  analysis longtext NULL,
  analyzed_at datetime NULL DEFAULT NULL,
  indexed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (post_id),
  KEY band1 (band1),
  KEY band2 (band2),
  KEY band3 (band3),
  KEY band4 (band4),
  KEY title_hash (title_hash),
  KEY description_hash (description_hash),
  KEY seo_score (seo_score),
  KEY inbound_links (inbound_links)
) $c;";

		$t['perf_daily'] = 'CREATE TABLE ' . Tables::perf_daily() . " (
  engine varchar(10) NOT NULL,
  date date NOT NULL,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  ctr float NOT NULL DEFAULT 0,
  position float NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (engine,date)
) $c;";

		$t['perf_rows'] = 'CREATE TABLE ' . Tables::perf_rows() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  engine varchar(10) NOT NULL,
  range_key varchar(10) NOT NULL,
  dimension varchar(12) NOT NULL,
  row_hash char(40) NOT NULL,
  query varchar(255) NOT NULL DEFAULT '',
  page text NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  ctr float NOT NULL DEFAULT 0,
  position float NOT NULL DEFAULT 0,
  prev_clicks int(10) unsigned NOT NULL DEFAULT 0,
  prev_impressions int(10) unsigned NOT NULL DEFAULT 0,
  prev_ctr float NOT NULL DEFAULT 0,
  prev_position float NOT NULL DEFAULT 0,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY row_key (engine,range_key,dimension,row_hash),
  KEY ranking (engine,range_key,dimension,clicks),
  KEY object_id (object_id)
) $c;";

		$t['reports'] = 'CREATE TABLE ' . Tables::reports() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'weekly',
  period_start date NOT NULL,
  period_end date NOT NULL,
  data longtext NOT NULL,
  emailed tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) $c;";

		return $t;
	}

	/**
	 * Creates or updates all tables for the current site.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Drops all tables for the current site.
	 */
	public static function drop() {
		global $wpdb;
		foreach ( Tables::ALL as $short ) {
			$table = Tables::name( $short );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is internal.
		}
	}

	/**
	 * Tables that are missing on the current site.
	 *
	 * @return string[]
	 */
	public static function missing_tables() {
		global $wpdb;
		$missing = array();
		foreach ( Tables::ALL as $short ) {
			$table = Tables::name( $short );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}

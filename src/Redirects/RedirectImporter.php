<?php
/**
 * Redirect import/export.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Redirects;

use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import/export (source,target,code,match_type — formula-injection safe)
 * and one-click import from Redirection, Rank Math, Yoast SEO Premium,
 * All in One SEO and SEOPress. Tables and options are checked before
 * reading; duplicates are skipped; every import is one undoable activity
 * entry (see RedirectsModule::import_rules()).
 */
class RedirectImporter {

	const MAX_ROWS = 20000;

	/**
	 * Redirect module.
	 *
	 * @var RedirectsModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param RedirectsModule $module Redirect module.
	 */
	public function __construct( RedirectsModule $module ) {
		$this->module = $module;
	}

	/**
	 * Import sources with availability and counts.
	 *
	 * @return array<int,array{id:string,name:string,available:bool,count:int}>
	 */
	public function sources() {
		global $wpdb;
		$out = array();

		$count = 0;
		$table = $wpdb->prefix . 'redirection_items';
		if ( $this->table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE match_type = 'url' AND action_type IN ('url','error')" );
		}
		$out[] = $this->source( 'redirection', 'Redirection', $count, $this->table_exists( $table ) );

		$count = 0;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( $this->table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('active','inactive')" );
		}
		$out[] = $this->source( 'rank_math', 'Rank Math', $count, $this->table_exists( $table ) );

		$yoast = $this->yoast_rows();
		$out[] = $this->source( 'yoast', 'Yoast SEO Premium', count( $yoast ), (bool) $yoast );

		$count = 0;
		$table = $wpdb->prefix . 'aioseo_redirects';
		if ( $this->table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		$out[] = $this->source( 'aioseo', 'All in One SEO', $count, $this->table_exists( $table ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_seopress_redirections_type' WHERE p.post_type = 'seopress_404'" );
		$out[] = $this->source( 'seopress', 'SEOPress', $count, $count > 0 );

		return $out;
	}

	/**
	 * Source descriptor.
	 *
	 * @param string $id        Id.
	 * @param string $name      Name.
	 * @param int    $count     Rows.
	 * @param bool   $available Data present.
	 * @return array
	 */
	private function source( $id, $name, $count, $available ) {
		return array(
			'id'        => $id,
			'name'      => $name,
			'available' => $available && $count > 0,
			'count'     => (int) $count,
		);
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Imports from another plugin.
	 *
	 * @param string $source redirection|rank_math|yoast|aioseo|seopress.
	 * @return array|\WP_Error Stats.
	 */
	public function import_source( $source ) {
		$names = array(
			'redirection' => 'Redirection',
			'rank_math'   => 'Rank Math',
			'yoast'       => 'Yoast SEO Premium',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
		);
		if ( ! isset( $names[ $source ] ) ) {
			return new \WP_Error( 'searchpilot_invalid_source', __( 'Unknown import source.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$method = 'read_' . $source;
		$rules  = $this->$method();
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}
		if ( empty( $rules ) ) {
			return new \WP_Error( 'searchpilot_nothing', __( 'No redirects were found in that plugin’s data.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		$stats           = $this->module->import_rules( $rules, 'import', array( 'label' => $names[ $source ] ) );
		$stats['source'] = $source;
		return $stats;
	}

	/**
	 * Home path prefix for sources stored relative to the home URL.
	 *
	 * @param string $relative Path without leading slash.
	 * @return string
	 */
	private function from_home( $relative ) {
		return RedirectsModule::home_path() . '/' . ltrim( (string) $relative, '/' );
	}

	/**
	 * Regex written against the path without its leading slash → our convention.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private static function anchor_regex( $pattern ) {
		$pattern = trim( (string) $pattern );
		// Strip delimiters some plugins store (e.g. #...# or /.../i).
		if ( preg_match( '#^([/\#~@])(.*)\1([imsxu]*)$#s', $pattern, $m ) && '' !== $m[2] ) {
			$pattern = $m[2];
		}
		if ( '^' === substr( $pattern, 0, 1 ) && '/' !== substr( $pattern, 1, 1 ) ) {
			$pattern = '^/?' . substr( $pattern, 1 );
		}
		return $pattern;
	}

	/**
	 * Maps a status code to a supported one (null = skip).
	 *
	 * @param mixed $code Code.
	 * @return int|null
	 */
	private static function code( $code ) {
		$code = (int) $code;
		if ( 303 === $code ) {
			return 302;
		}
		if ( 0 === $code ) {
			return 301;
		}
		return in_array( $code, RedirectMatcher::CODES, true ) ? $code : null;
	}

	/**
	 * Redirection plugin rules.
	 *
	 * @return array[]
	 */
	private function read_redirection() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT url, action_type, action_code, action_data, regex, status, title FROM {$table} WHERE match_type = 'url' AND action_type IN ('url','error') ORDER BY id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
		$rules = array();
		foreach ( (array) $rows as $row ) {
			$code = self::code( $row['action_code'] );
			if ( 'error' === $row['action_type'] ) {
				if ( 410 !== (int) $row['action_code'] && 451 !== (int) $row['action_code'] ) {
					continue;
				}
				$code = (int) $row['action_code'];
			}
			if ( null === $code ) {
				continue;
			}
			$target = 'url' === $row['action_type'] ? maybe_unserialize( $row['action_data'] ) : '';
			if ( ! is_string( $target ) ) {
				continue;
			}
			$regex   = ! empty( $row['regex'] );
			$rules[] = array(
				'source'      => $regex ? self::anchor_regex( (string) $row['url'] ) : (string) $row['url'],
				'target'      => $target,
				'match_type'  => $regex ? 'regex' : 'exact',
				'status_code' => $code,
				'enabled'     => 'disabled' !== $row['status'],
				'notes'       => (string) $row['title'],
			);
		}
		return $rules;
	}

	/**
	 * Rank Math rules (sources serialized) plus concrete URLs from its cache table.
	 *
	 * @return array[]
	 */
	private function read_rank_math() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code, status FROM {$table} WHERE status IN ('active','inactive') ORDER BY id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
		$rules   = array();
		$pattern = array();
		foreach ( (array) $rows as $row ) {
			$code = self::code( $row['header_code'] );
			if ( null === $code ) {
				continue;
			}
			$sources = maybe_unserialize( $row['sources'] );
			if ( ! is_array( $sources ) ) {
				continue;
			}
			$enabled = 'active' === $row['status'];
			foreach ( $sources as $source ) {
				if ( ! is_array( $source ) || ! isset( $source['pattern'] ) || '' === (string) $source['pattern'] ) {
					continue;
				}
				$p          = (string) $source['pattern'];
				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				switch ( $comparison ) {
					case 'regex':
						$type = 'regex';
						$p    = self::anchor_regex( $p );
						break;
					case 'start':
						$type = 'prefix';
						$p    = $this->from_home( $p );
						break;
					case 'end':
						$type = 'regex';
						$p    = preg_quote( ltrim( $p, '/' ), '~' ) . '$';
						break;
					case 'contains':
						$type = 'regex';
						$p    = preg_quote( ltrim( $p, '/' ), '~' );
						break;
					default:
						$type = 'exact';
						$p    = $this->from_home( $p );
				}
				if ( 'exact' !== $type ) {
					$pattern[ (int) $row['id'] ] = true;
				}
				$rules[] = array(
					'source'      => $p,
					'target'      => (string) $row['url_to'],
					'match_type'  => $type,
					'status_code' => $code,
					'enabled'     => $enabled,
					'notes'       => 'Rank Math #' . (int) $row['id'],
				);
			}
		}
		// Concrete addresses Rank Math resolved for pattern rules.
		$cache = $wpdb->prefix . 'rank_math_redirections_cache';
		if ( $pattern && $this->table_exists( $cache ) ) {
			$targets = array();
			foreach ( (array) $rows as $row ) {
				$targets[ (int) $row['id'] ] = $row;
			}
			$ids = implode( ',', array_map( 'absint', array_keys( $pattern ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$cached = $wpdb->get_results( $wpdb->prepare( "SELECT from_url, redirection_id FROM {$cache} WHERE redirection_id IN ({$ids}) LIMIT %d", self::MAX_ROWS ), ARRAY_A );
			foreach ( (array) $cached as $entry ) {
				$main = $targets[ (int) $entry['redirection_id'] ] ?? null;
				if ( ! $main || '' === (string) $entry['from_url'] ) {
					continue;
				}
				$code = self::code( $main['header_code'] );
				if ( null === $code || false !== strpos( (string) $main['url_to'], '$' ) ) {
					continue;
				}
				$rules[] = array(
					'source'      => $this->from_home( (string) $entry['from_url'] ),
					'target'      => (string) $main['url_to'],
					'match_type'  => 'exact',
					'status_code' => $code,
					'enabled'     => 'active' === $main['status'],
					'notes'       => 'Rank Math #' . (int) $main['id'],
				);
			}
		}
		return $rules;
	}

	/**
	 * Yoast SEO Premium redirect rows.
	 *
	 * @return array[]
	 */
	private function yoast_rows() {
		$base = get_option( 'wpseo-premium-redirects-base', null );
		$rows = array();
		if ( is_array( $base ) && $base ) {
			foreach ( $base as $item ) {
				if ( is_array( $item ) && isset( $item['origin'] ) ) {
					$rows[] = array(
						'origin' => (string) $item['origin'],
						'url'    => (string) ( $item['url'] ?? '' ),
						'type'   => (int) ( $item['type'] ?? 301 ),
						'format' => (string) ( $item['format'] ?? 'plain' ),
					);
				}
			}
			return $rows;
		}
		foreach ( array(
			'plain' => 'wpseo-premium-redirects-export-plain',
			'regex' => 'wpseo-premium-redirects-export-regex',
		) as $format => $option ) {
			$list = get_option( $option, null );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $origin => $item ) {
				$rows[] = array(
					'origin' => (string) $origin,
					'url'    => is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '',
					'type'   => is_array( $item ) ? (int) ( $item['type'] ?? 301 ) : 301,
					'format' => $format,
				);
			}
		}
		return $rows;
	}

	/**
	 * Yoast SEO Premium rules.
	 *
	 * @return array[]
	 */
	private function read_yoast() {
		$rules = array();
		foreach ( array_slice( $this->yoast_rows(), 0, self::MAX_ROWS ) as $row ) {
			$code = self::code( $row['type'] );
			if ( null === $code || '' === $row['origin'] ) {
				continue;
			}
			$regex   = 'regex' === $row['format'];
			$rules[] = array(
				'source'      => $regex ? self::anchor_regex( $row['origin'] ) : $this->from_home( $row['origin'] ),
				'target'      => $this->target_from_home( $row['url'] ),
				'match_type'  => $regex ? 'regex' : 'exact',
				'status_code' => $code,
				'enabled'     => true,
				'notes'       => 'Yoast',
			);
		}
		return $rules;
	}

	/**
	 * All in One SEO rules.
	 *
	 * @return array[]
	 */
	private function read_aioseo() {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_redirects';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
		$rules = array();
		foreach ( (array) $rows as $row ) {
			$code = self::code( $row['type'] ?? 301 );
			if ( null === $code || empty( $row['source_url'] ) ) {
				continue;
			}
			$regex   = ! empty( $row['regex'] );
			$rules[] = array(
				'source'      => $regex ? self::anchor_regex( (string) $row['source_url'] ) : $this->from_home( (string) $row['source_url'] ),
				'target'      => $this->target_from_home( (string) ( $row['target_url'] ?? '' ) ),
				'match_type'  => $regex ? 'regex' : 'exact',
				'status_code' => $code,
				'enabled'     => ! isset( $row['enabled'] ) || ! empty( $row['enabled'] ),
				'notes'       => 'AIOSEO',
			);
		}
		return $rules;
	}

	/**
	 * SEOPress rules (post type seopress_404 with _seopress_redirections_* meta).
	 *
	 * @return array[]
	 */
	private function read_seopress() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
					MAX(CASE WHEN m.meta_key = '_seopress_redirections_value' THEN m.meta_value END) AS target,
					MAX(CASE WHEN m.meta_key = '_seopress_redirections_type' THEN m.meta_value END) AS code,
					MAX(CASE WHEN m.meta_key = '_seopress_redirections_enabled' THEN m.meta_value END) AS enabled,
					MAX(CASE WHEN m.meta_key = '_seopress_redirections_enabled_regex' THEN m.meta_value END) AS regex
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key LIKE %s
				WHERE p.post_type = 'seopress_404'
				GROUP BY p.ID, p.post_title
				ORDER BY p.ID ASC LIMIT %d",
				$wpdb->esc_like( '_seopress_redirections_' ) . '%',
				self::MAX_ROWS
			),
			ARRAY_A
		);
		$rules = array();
		foreach ( (array) $rows as $row ) {
			$code = self::code( $row['code'] ?? 301 );
			if ( null === $code || '' === trim( (string) $row['post_title'] ) ) {
				continue;
			}
			if ( '' === trim( (string) $row['target'] ) && ! in_array( $code, array( 410, 451 ), true ) ) {
				continue; // A plain 404 log entry, not a redirect.
			}
			$regex   = 'yes' === $row['regex'];
			$rules[] = array(
				'source'      => $regex ? self::anchor_regex( (string) $row['post_title'] ) : $this->from_home( (string) $row['post_title'] ),
				'target'      => $this->target_from_home( (string) $row['target'] ),
				'match_type'  => $regex ? 'regex' : 'exact',
				'status_code' => $code,
				'enabled'     => 'yes' === $row['enabled'],
				'notes'       => 'SEOPress',
			);
		}
		return $rules;
	}

	/**
	 * Targets some plugins store relative to home without a leading slash.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	private function target_from_home( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target || preg_match( '#^([a-z][a-z0-9+.\-]*:|/|\#)#i', $target ) ) {
			return $target;
		}
		return $this->from_home( $target );
	}

	/* ------------------------------------------------------------------ *
	 * CSV
	 * ------------------------------------------------------------------ */

	/**
	 * Exports every rule as CSV.
	 *
	 * @return array{filename:string,csv:string,count:int}
	 */
	public function export_csv() {
		$lines = array( self::csv_line( array( 'source', 'target', 'code', 'match_type' ) ) );
		$after = 0;
		$count = 0;
		$repo  = $this->module->repository();
		do {
			$batch = $repo->batch( $after, 1000 );
			foreach ( $batch as $rule ) {
				$after   = (int) $rule['id'];
				$lines[] = self::csv_line(
					array(
						'regex' === $rule['match_type'] ? (string) $rule['source'] : Url::display( (string) $rule['source'] ),
						Url::display( (string) $rule['target'] ),
						(string) $rule['status_code'],
						(string) $rule['match_type'],
					)
				);
				++$count;
			}
		} while ( count( $batch ) === 1000 );
		return array(
			'filename' => 'searchpilot-redirects-' . gmdate( 'Y-m-d' ) . '.csv',
			'csv'      => "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n",
			'count'    => $count,
		);
	}

	/**
	 * One CSV line (quotes when needed, neutralizes spreadsheet formulas).
	 *
	 * @param string[] $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ) {
		return implode(
			',',
			array_map(
				static function ( $value ) {
					$value = (string) $value;
					if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
						$value = "'" . $value;
					}
					if ( preg_match( '/[",\r\n]/', $value ) ) {
						$value = '"' . str_replace( '"', '""', $value ) . '"';
					}
					return $value;
				},
				$cells
			)
		);
	}

	/**
	 * Parses CSV text into rules (header optional: source,target,code,match_type).
	 *
	 * @param string $csv CSV.
	 * @return array[]|\WP_Error
	 */
	public static function parse_csv( $csv ) {
		$csv = (string) $csv;
		if ( strlen( $csv ) > 5 * MB_IN_BYTES ) {
			return new \WP_Error( 'searchpilot_too_large', __( 'The file is larger than 5 MB. Split it into smaller files.', 'searchpilot-pro' ), array( 'status' => 413 ) );
		}
		if ( 0 === strpos( $csv, "\xEF\xBB\xBF" ) ) {
			$csv = substr( $csv, 3 );
		}
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$first     = true;
		$columns   = array(
			'source'     => 0,
			'target'     => 1,
			'code'       => 2,
			'match_type' => 3,
		);
		$delimiter = ',';
		$sample    = strtok( $csv, "\n" );
		if ( false !== $sample && substr_count( $sample, ';' ) > substr_count( $sample, ',' ) ) {
			$delimiter = ';';
		}
		$rules = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( count( $rules ) >= self::MAX_ROWS ) {
				break;
			}
			if ( ! is_array( $row ) || ( 1 === count( $row ) && null === $row[0] ) ) {
				continue;
			}
			$row = array_map(
				static function ( $v ) {
					$v = trim( (string) $v );
					// Undo formula neutralization from our own export.
					if ( preg_match( "/^'[=+\\-@\t\r]/", $v ) ) {
						$v = substr( $v, 1 );
					}
					return $v;
				},
				$row
			);
			if ( $first ) {
				$first  = false;
				$header = array_map( 'strtolower', $row );
				if ( in_array( 'source', $header, true ) || in_array( 'from', $header, true ) || in_array( 'old', $header, true ) ) {
					$aliases = array(
						'source'     => array( 'source', 'from', 'old', 'source_url', 'old_url' ),
						'target'     => array( 'target', 'to', 'new', 'target_url', 'new_url', 'destination' ),
						'code'       => array( 'code', 'status', 'status_code', 'type', 'http_code' ),
						'match_type' => array( 'match_type', 'match', 'matching', 'regex' ),
					);
					foreach ( $aliases as $key => $names ) {
						$columns[ $key ] = null;
						foreach ( $names as $name ) {
							$pos = array_search( $name, $header, true );
							if ( false !== $pos ) {
								$columns[ $key ] = (int) $pos;
								break;
							}
						}
					}
					continue;
				}
			}
			$source = null !== $columns['source'] ? (string) ( $row[ $columns['source'] ] ?? '' ) : '';
			if ( '' === $source ) {
				continue;
			}
			$type = null !== $columns['match_type'] ? strtolower( (string) ( $row[ $columns['match_type'] ] ?? '' ) ) : '';
			if ( in_array( $type, array( '1', 'yes', 'true' ), true ) ) {
				$type = 'regex';
			}
			$code    = null !== $columns['code'] ? (int) ( $row[ $columns['code'] ] ?? 301 ) : 301;
			$rules[] = array(
				'source'      => $source,
				'target'      => null !== $columns['target'] ? (string) ( $row[ $columns['target'] ] ?? '' ) : '',
				'status_code' => $code ? $code : 301,
				'match_type'  => in_array( $type, RedirectMatcher::MATCH_TYPES, true ) ? $type : 'exact',
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rules;
	}

	/**
	 * Imports CSV text.
	 *
	 * @param string $csv CSV.
	 * @return array|\WP_Error Stats.
	 */
	public function import_csv( $csv ) {
		$rules = self::parse_csv( $csv );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}
		if ( empty( $rules ) ) {
			return new \WP_Error( 'searchpilot_nothing', __( 'No redirects were found in the file. Use the columns source,target,code,match_type.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		return $this->module->import_rules( $rules, 'import', array( 'label' => __( 'a CSV file', 'searchpilot-pro' ) ) );
	}
}

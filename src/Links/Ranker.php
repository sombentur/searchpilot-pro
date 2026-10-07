<?php
/**
 * Ranking of link targets (pure).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Scores how well a candidate post fits as an internal-link partner of a
 * source post, from signals that work in agglutinative scripts such as
 * Kannada, where the same concept rarely appears as the same string:
 *
 *   - shared title words (inflection-tolerant, weighted by rarity) — 35 %;
 *   - shared slug tokens (Kannada sites often use English slugs) — 10 %;
 *   - shared taxonomy (a category weighs twice a tag; large catch-all terms
 *     and the default category weigh little or nothing) — 30 %, so a shared
 *     category plus a shared tag alone reaches the default 25 % threshold;
 *   - body-text similarity from the TF-IDF index — 25 %.
 *
 * Candidates written in another script than the source are scaled down
 * (a Kannada reader is rarely served by an English article). Posts that pass
 * none of the topical signals but share a category form the "recent" tier:
 * the fallback for sites whose articles share no wording at all.
 */
final class Ranker {

	const W_TITLE = 0.35;
	const W_SLUG  = 0.10;
	const W_TAX   = 0.30;
	const W_TOPIC = 0.25;

	const CATEGORY_WEIGHT = 0.6;
	const TAG_WEIGHT      = 0.3;

	/**
	 * Factor for candidates in another script.
	 */
	const OTHER_SCRIPT = 0.6;

	/**
	 * Meaningful slug tokens (URL-decoded, lower-cased, stop words, numbers and
	 * one-letter tokens removed).
	 *
	 * @param string $slug Post slug (post_name, possibly percent-encoded).
	 * @return string[]
	 */
	public static function slug_tokens( $slug ) {
		$slug = rawurldecode( (string) $slug );
		$out  = array();
		foreach ( preg_split( '/[-_\s\.]+/u', mb_strtolower( $slug, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$token = trim( $token );
			if ( '' === $token || is_numeric( $token ) || Text::is_stopword( $token ) || ! preg_match( '/[' . Text::WORD_CHARS . ']/u', $token ) ) {
				continue;
			}
			if ( preg_match( '/^[a-z0-9]+$/', $token ) && strlen( $token ) < 3 ) {
				continue;
			}
			$out[ $token ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Dominant script of a text: latin, kannada, devanagari, … or other.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function script( $text ) {
		$text   = (string) $text;
		$counts = array();
		foreach ( array( 'latin' => '\p{Latin}', 'kannada' => '\p{Kannada}', 'devanagari' => '\p{Devanagari}', 'tamil' => '\p{Tamil}', 'telugu' => '\p{Telugu}', 'malayalam' => '\p{Malayalam}', 'bengali' => '\p{Bengali}', 'arabic' => '\p{Arabic}', 'cjk' => '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}' ) as $name => $class ) {
			$counts[ $name ] = (int) preg_match_all( '/[' . $class . ']/u', $text );
		}
		arsort( $counts );
		$top = (string) key( $counts );
		return $counts[ $top ] > 0 ? $top : 'other';
	}

	/**
	 * Inverse document frequency weight of a word.
	 *
	 * @param string $word Word.
	 * @param array  $df   Stem => document frequency.
	 * @param int    $n    Indexed documents.
	 * @return float
	 */
	public static function idf( $word, array $df, $n ) {
		$n = max( ContentIndex::MIN_CORPUS, (int) $n );
		$d = max( 1, (int) ( $df[ ContentIndex::stem( $word ) ] ?? 1 ) );
		return log( 1 + $n / $d );
	}

	/**
	 * Weighted overlap of two word lists (0..1): the mean of the share of each
	 * side's weight that the other side matches.
	 *
	 * @param string[] $a  Words.
	 * @param string[] $b  Words.
	 * @param array    $df Stem => document frequency (empty: all words weigh the same).
	 * @param int      $n  Indexed documents.
	 * @return float
	 */
	public static function overlap( array $a, array $b, array $df = array(), $n = 0 ) {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$side = static function ( array $from, array $to ) use ( $df, $n ) {
			$total   = 0.0;
			$matched = 0.0;
			foreach ( $from as $word ) {
				$w      = $df ? self::idf( $word, $df, $n ) : 1.0;
				$total += $w;
				if ( ContentIndex::matches_any( $word, $to ) ) {
					$matched += $w;
				}
			}
			return $total > 0 ? $matched / $total : 0.0;
		};
		return ( $side( $a, $b ) + $side( $b, $a ) ) / 2;
	}

	/**
	 * Weight of a term shared by two posts: 1 for focused terms, falling to
	 * 0.3 for very large catch-all terms (logarithmic in the number of posts).
	 *
	 * @param int $size Posts in the term.
	 * @return float
	 */
	public static function specificity( $size ) {
		$size = max( 1, (int) $size );
		if ( $size <= 20 ) {
			return 1.0;
		}
		return max( 0.3, 1 - log10( $size / 20 ) / 2 );
	}

	/**
	 * Shared-taxonomy score (0..1).
	 *
	 * @param array $a   Source features (categories, tags: int[]).
	 * @param array $b   Candidate features.
	 * @param array $ctx sizes (term id => posts), default_category (int).
	 * @return float
	 */
	public static function taxonomy( array $a, array $b, array $ctx = array() ) {
		$sizes   = (array) ( $ctx['sizes'] ?? array() );
		$default = (int) ( $ctx['default_category'] ?? 0 );
		$score   = 0.0;
		foreach ( array( 'categories' => self::CATEGORY_WEIGHT, 'tags' => self::TAG_WEIGHT ) as $key => $weight ) {
			$shared = array_intersect( array_map( 'intval', (array) ( $a[ $key ] ?? array() ) ), array_map( 'intval', (array) ( $b[ $key ] ?? array() ) ) );
			foreach ( $shared as $term_id ) {
				if ( 'categories' === $key && $term_id === $default ) {
					continue; // "Uncategorized" says nothing about the topic.
				}
				$score += $weight * self::specificity( (int) ( $sizes[ $term_id ] ?? 1 ) );
			}
		}
		return min( 1.0, $score );
	}

	/**
	 * Scores a candidate for a source.
	 *
	 * Features (both): title_words (string[] from ContentIndex::key_words),
	 * slug_tokens (string[]), categories (int[]), tags (int[]), script (string),
	 * time (int, publish timestamp). Candidate only: topic (0..1 relevance of
	 * the body texts), cornerstone (bool).
	 *
	 * @param array $source    Source features.
	 * @param array $candidate Candidate features.
	 * @param array $ctx       df (stem => df), n (indexed posts), sizes, default_category.
	 * @return array{score:float,title:float,slug:float,taxonomy:float,topic:float,same_script:bool,shares_category:bool}
	 */
	public static function score( array $source, array $candidate, array $ctx = array() ) {
		$df       = (array) ( $ctx['df'] ?? array() );
		$n        = (int) ( $ctx['n'] ?? 0 );
		$title    = self::overlap( (array) ( $source['title_words'] ?? array() ), (array) ( $candidate['title_words'] ?? array() ), $df, $n );
		$slug     = self::overlap( (array) ( $source['slug_tokens'] ?? array() ), (array) ( $candidate['slug_tokens'] ?? array() ) );
		$taxonomy = self::taxonomy( $source, $candidate, $ctx );
		$topic    = max( 0.0, min( 1.0, (float) ( $candidate['topic'] ?? 0 ) ) );
		$score    = self::W_TITLE * $title + self::W_SLUG * $slug + self::W_TAX * $taxonomy + self::W_TOPIC * $topic;
		$same     = (string) ( $source['script'] ?? '' ) === (string) ( $candidate['script'] ?? '' );
		if ( ! $same ) {
			$score *= self::OTHER_SCRIPT;
		}
		if ( ! empty( $candidate['cornerstone'] ) && $score > 0 ) {
			$score += 0.05;
		}
		$default = (int) ( $ctx['default_category'] ?? 0 );
		$cats    = array_diff( array_intersect( array_map( 'intval', (array) ( $source['categories'] ?? array() ) ), array_map( 'intval', (array) ( $candidate['categories'] ?? array() ) ) ), array( $default ) );
		return array(
			'score'           => round( min( 1.0, $score ), 4 ),
			'title'           => round( $title, 4 ),
			'slug'            => round( $slug, 4 ),
			'taxonomy'        => round( $taxonomy, 4 ),
			'topic'           => round( $topic, 4 ),
			'same_script'     => $same,
			'shares_category' => ! empty( $cats ),
		);
	}

	/**
	 * Ranks candidates: those at or above the threshold by score ("related"
	 * tier), then — only while fewer than $min_related qualify — candidates in
	 * the same script that share a category, newest first ("recent" tier).
	 *
	 * @param array $source      Source features (with id).
	 * @param array $candidates  Candidate features (with id).
	 * @param array $ctx         Scoring context; threshold (0..1), min_related (int).
	 * @param int   $limit       Max results.
	 * @return array<int,array{post_id:int,score:float,tier:string,signals:array}>
	 */
	public static function rank( array $source, array $candidates, array $ctx = array(), $limit = 30 ) {
		$threshold = (float) ( $ctx['threshold'] ?? 0.25 );
		$min       = (int) ( $ctx['min_related'] ?? 3 );
		$own       = (int) ( $source['id'] ?? 0 );
		$related   = array();
		$recent    = array();
		foreach ( $candidates as $candidate ) {
			$id = (int) ( $candidate['id'] ?? 0 );
			if ( ! $id || $id === $own ) {
				continue;
			}
			$signals = self::score( $source, $candidate, $ctx );
			$row     = array(
				'post_id' => $id,
				'score'   => $signals['score'],
				'tier'    => 'related',
				'signals' => $signals,
				'time'    => (int) ( $candidate['time'] ?? 0 ),
			);
			if ( $signals['score'] >= $threshold ) {
				$related[] = $row;
			} elseif ( $signals['shares_category'] && $signals['same_script'] ) {
				$row['tier'] = 'recent';
				$recent[]    = $row;
			}
		}
		usort(
			$related,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $b['time'] <=> $a['time'] ?: $a['post_id'] <=> $b['post_id'];
			}
		);
		usort(
			$recent,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'] ?: $a['post_id'] <=> $b['post_id'];
			}
		);
		$out = $related;
		if ( count( $out ) < $min ) {
			$out = array_merge( $out, array_slice( $recent, 0, $min - count( $out ) ) );
		}
		$out = array_slice( $out, 0, max( 1, (int) $limit ) );
		foreach ( $out as &$row ) {
			unset( $row['time'] );
		}
		unset( $row );
		return $out;
	}
}

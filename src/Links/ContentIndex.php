<?php
/**
 * Content graph: TF-IDF term index of published posts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Database\Tables;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps an inverted index (`terms`: term, post_id, weight) and document
 * frequencies (`term_stats`) for the posts that take part in internal
 * linking. Terms are stemmed keywords and keyword bigrams from the title
 * (×3), headings (×2) and body, at most 40 per post; weights are
 * L2-normalized TF-IDF vectors, so the dot product of two posts is their
 * cosine similarity (0..1).
 *
 * The first build runs in two phases: "build" stores raw term frequencies
 * while document frequencies accumulate, "weights" converts every post to
 * TF-IDF once the frequencies are complete. Later updates are weighted
 * immediately.
 */
class ContentIndex {

	const MAX_TERMS     = 40;
	const TITLE_BOOST   = 3;
	const HEADING_BOOST = 2;
	const STATE_KEY     = 'links_index';

	/**
	 * Smallest corpus assumed for IDF, so similarity on a young site with a
	 * handful of posts behaves like on an established one (with 4 posts a
	 * term shared by two of them would otherwise weigh little more than a
	 * word unique to one post).
	 */
	const MIN_CORPUS = 50;

	/**
	 * Source terms used to look up related posts (highest weights first).
	 */
	const RELATED_TERMS = 20;

	/**
	 * Hard cap on the document frequency of terms joined in related().
	 */
	const MAX_JOIN_DF = 2000;

	/**
	 * Cached related() results.
	 */
	const RELATED_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Content table.
	 *
	 * @var AnalysisRepository
	 */
	private $repo;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Renderer.
	 *
	 * @var ContentRenderer
	 */
	private $renderer;

	/**
	 * Cached number of indexed posts for this request.
	 *
	 * @var int|null
	 */
	private $count_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Settings.
	 * @param ContentTypes       $types    Content types.
	 * @param MetaStore          $meta     Meta store.
	 * @param AnalysisRepository $repo     Content table.
	 * @param State              $state    State.
	 * @param ContentRenderer    $renderer Renderer.
	 */
	public function __construct( Settings $settings, ContentTypes $types, MetaStore $meta, AnalysisRepository $repo, State $state, ContentRenderer $renderer ) {
		$this->settings = $settings;
		$this->types    = $types;
		$this->meta     = $meta;
		$this->repo     = $repo;
		$this->state    = $state;
		$this->renderer = $renderer;
	}

	/* ------------------------------------------------------------------
	 * Pure helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Normalizes a lower-cased word for matching: light English stemming for
	 * ASCII words, prefix truncation (first three aksharas, trailing vowel
	 * signs removed) for Indic scripts, whose inflections are suffixes —
	 * ಬೆಂಗಳೂರು / ಬೆಂಗಳೂರಿನಲ್ಲಿ, ಸರ್ಕಾರ / ಸರ್ಕಾರದ map to the same stem.
	 *
	 * @param string $word Lower-cased word.
	 * @return string
	 */
	public static function stem( $word ) {
		$word = (string) $word;
		if ( '' === $word ) {
			return '';
		}
		if ( preg_match( '/^[a-z0-9\']+$/', $word ) ) {
			return self::english_stem( $word );
		}
		if ( preg_match( '/[\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Oriya}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}]/u', $word ) && preg_match_all( '/\X/u', $word, $m ) ) {
			$core = implode( '', array_slice( $m[0], 0, 3 ) );
			$core = (string) preg_replace( '/\p{M}+$/u', '', $core );
			return '' !== $core ? $core : $word;
		}
		return $word;
	}

	/**
	 * Light English stemmer: plural and common verb suffixes, so singular and
	 * plural forms (shoe/shoes, story/stories, box/boxes) and run/runs/running
	 * share a stem.
	 *
	 * @param string $word Lower-case ASCII word.
	 * @return string
	 */
	private static function english_stem( $word ) {
		$len = strlen( $word );
		if ( $len < 4 || ! preg_match( '/^[a-z]+$/', $word ) ) {
			return $word;
		}
		foreach ( array( 'ational' => 'ate', 'ization' => 'ize', 'fulness' => 'ful', 'iveness' => 'ive' ) as $suffix => $replacement ) {
			if ( $len > strlen( $suffix ) + 2 && substr( $word, -strlen( $suffix ) ) === $suffix ) {
				return substr( $word, 0, -strlen( $suffix ) ) . $replacement;
			}
		}
		if ( $len > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( preg_match( '/(ss|x|z|ch|sh)es$/', $word ) ) {
			return substr( $word, 0, -2 );
		}
		if ( 's' === substr( $word, -1 ) && ! preg_match( '/(ss|us|is)$/', $word ) ) {
			return substr( $word, 0, -1 );
		}
		foreach ( array( 'ingly', 'edly', 'ing', 'ed' ) as $suffix ) {
			$cut = strlen( $suffix );
			if ( $len - $cut >= 3 && substr( $word, -$cut ) === $suffix ) {
				$stem = substr( $word, 0, -$cut );
				if ( 'ed' === $suffix && 'i' === substr( $stem, -1 ) ) {
					return substr( $stem, 0, -1 ) . 'y'; // Carried → carry.
				}
				if ( preg_match( '/([b-df-hj-np-tv-z])\1$/', $stem ) && ! preg_match( '/(ll|ss|zz)$/', $stem ) ) {
					$stem = substr( $stem, 0, -1 ); // Running → run, stopped → stop.
				}
				return $stem;
			}
		}
		return $word;
	}

	/**
	 * Grapheme clusters of a word (aksharas for Indic scripts).
	 *
	 * @param string $word Word.
	 * @return string[]
	 */
	public static function graphemes( $word ) {
		$word = (string) $word;
		if ( '' === $word ) {
			return array();
		}
		return preg_match_all( '/\X/u', $word, $m ) ? $m[0] : array( $word );
	}

	/**
	 * Whether a word is written in an Indic script (suffix-inflected).
	 *
	 * @param string $word Word.
	 * @return bool
	 */
	public static function is_indic( $word ) {
		return 1 === preg_match( '/[\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Oriya}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}]/u', (string) $word );
	}

	/**
	 * Whether two lower-cased words are forms of the same word.
	 *
	 * English: same light stem (shoe/shoes, run/running). Indic scripts inflect
	 * by suffix, and the root's last vowel sign often changes (ಬೆಂಗಳೂರು →
	 * ಬೆಂಗಳೂರಿನಲ್ಲಿ, ಮಳೆ → ಮಳೆಯಿಂದ, ಚುನಾವಣೆ → ಚುನಾವಣಾ), so two words match when
	 * the shorter one is a prefix of the longer one (at least two whole
	 * aksharas, suffix at most five aksharas), or when they differ only in the
	 * vowel sign of the shorter word's last akshara (roots of three or more
	 * aksharas, or two-akshara roots ending in ‑ು such as ಊರು → ಊರಿನ). A shared
	 * first akshara alone never matches (ಮಳೆ ≠ ಮಳಿಗೆ).
	 *
	 * @param string $a Word.
	 * @param string $b Word.
	 * @return bool
	 */
	public static function words_match( $a, $b ) {
		$a = (string) $a;
		$b = (string) $b;
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		// Fast path: forms of one word always share their first character.
		if ( 0 !== strncmp( $a, $b, ord( $a[0] ) < 0x80 ? 1 : 3 ) ) {
			return false;
		}
		$ascii = '/^[a-z0-9\']+$/';
		if ( preg_match( $ascii, $a ) && preg_match( $ascii, $b ) ) {
			return self::english_stem( $a ) === self::english_stem( $b );
		}
		if ( ! self::is_indic( $a ) || ! self::is_indic( $b ) ) {
			return false;
		}
		$ga = self::graphemes( $a );
		$gb = self::graphemes( $b );
		if ( count( $ga ) > count( $gb ) ) {
			list( $ga, $gb ) = array( $gb, $ga );
		}
		$short = count( $ga );
		$long  = count( $gb );
		if ( $short < 2 || $long - $short > 5 ) {
			return false;
		}
		$common = 0;
		while ( $common < $short && $ga[ $common ] === $gb[ $common ] ) {
			++$common;
		}
		if ( $common === $short ) {
			// The shorter word is the root of the longer one. In Kannada a case or plural
			// suffix starts with one of ದ ನ ಯ ವ ಗ ಕ ಲ ಳ ರ (ಸರ್ಕಾರದ, ಮಳೆಗೆ, ಮಳೆಯಿಂದ,
			// ಸರ್ಕಾರಕ್ಕೆ, ನಗರಗಳಲ್ಲಿ); anything else is usually a compound (ಮಾರ್ಗ ≠ ಮಾರ್ಗಸೂಚಿ).
			return $short === $long || ! preg_match( '/\p{Kannada}/u', $gb[0] ) || 1 === preg_match( '/^[\x{0CA6}\x{0CA8}\x{0CAF}\x{0CB5}\x{0C97}\x{0C95}\x{0CB2}\x{0CB3}\x{0CB0}]/u', $gb[ $short ] );
		}
		if ( $common !== $short - 1 ) {
			return false;
		}
		// Only the last akshara of the root differs: same consonant, different vowel sign.
		$base_a = (string) preg_replace( '/\p{M}+/u', '', $ga[ $common ] );
		$base_b = (string) preg_replace( '/\p{M}+/u', '', $gb[ $common ] );
		if ( '' === $base_a || $base_a !== $base_b ) {
			return false;
		}
		if ( $short >= 3 ) {
			return true;
		}
		// Two-akshara roots: only u-ending nouns followed by a suffix (ಊರು → ಊರಿನ), whose vowel
		// regularly changes; two different two-akshara words (ಮನೆ / ಮನು) never match.
		return $long > $short && 1 === preg_match( '/[\x{0CC1}\x{0941}\x{0BC1}\x{0C41}\x{0D41}]$/u', $ga[ $common ] );
	}

	/**
	 * Whether a word matches any of the given words.
	 *
	 * @param string   $word  Word.
	 * @param string[] $words Words.
	 * @return bool
	 */
	public static function matches_any( $word, array $words ) {
		foreach ( $words as $other ) {
			if ( self::words_match( $word, $other ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Distinct meaningful words of a text, lower-cased and unstemmed (stop
	 * words and one-letter tokens removed), in order of appearance.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function key_words( $text ) {
		$out = array();
		foreach ( Text::words( (string) $text ) as $word ) {
			if ( ! Text::is_stopword( $word ) && ! isset( $out[ $word ] ) ) {
				$out[ $word ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Stems of all words of a text (stop words included), in order.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function stems( $text ) {
		return array_map( array( __CLASS__, 'stem' ), Text::words( (string) $text ) );
	}

	/**
	 * Stems of meaningful words (stop words removed).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function keywords( $text ) {
		$out = array();
		foreach ( Text::words( (string) $text ) as $word ) {
			if ( ! Text::is_stopword( $word ) ) {
				$out[] = self::stem( $word );
			}
		}
		return $out;
	}

	/**
	 * Weighted term frequencies of a post: keywords and keyword bigrams.
	 *
	 * @param string   $title    Title.
	 * @param string[] $headings Heading texts.
	 * @param string   $body     Body text.
	 * @param int      $max      Max terms.
	 * @return array<string,float> term => frequency (boosted).
	 */
	public static function extract_terms( $title, array $headings, $body, $max = self::MAX_TERMS ) {
		$tf     = array();
		$fields = array(
			array( (string) $title, self::TITLE_BOOST ),
			array( implode( ' . ', array_map( 'strval', $headings ) ), self::HEADING_BOOST ),
			array( (string) $body, 1 ),
		);
		foreach ( $fields as $field ) {
			list( $text, $boost ) = $field;
			// Sentence and clause punctuation ends a bigram.
			foreach ( preg_split( '/[.!?।॥。;:,()\[\]"“”«»|\/]+/u', $text ) as $clause ) {
				$words = Text::words( $clause );
				$n     = count( $words );
				for ( $i = 0; $i < $n; $i++ ) {
					$word = $words[ $i ];
					if ( Text::is_stopword( $word ) ) {
						continue;
					}
					$stem = self::stem( $word );
					self::add_term( $tf, $stem, $boost );
					if ( $i + 1 < $n && ! Text::is_stopword( $words[ $i + 1 ] ) ) {
						$next = self::stem( $words[ $i + 1 ] );
						if ( $next !== $stem ) {
							self::add_term( $tf, $stem . ' ' . $next, $boost );
						}
					}
				}
			}
		}
		arsort( $tf );
		// Stable tie-break by term for deterministic output.
		$grouped = array();
		foreach ( $tf as $term => $freq ) {
			$grouped[ (string) $freq ][] = (string) $term;
		}
		$out = array();
		foreach ( $grouped as $freq => $terms ) {
			sort( $terms, SORT_STRING );
			foreach ( $terms as $term ) {
				if ( count( $out ) >= $max ) {
					break 2;
				}
				$out[ $term ] = (float) $freq;
			}
		}
		return $out;
	}

	/**
	 * Adds a term occurrence.
	 *
	 * @param array  $tf    Frequencies (by reference).
	 * @param string $term  Term.
	 * @param int    $boost Boost.
	 */
	private static function add_term( array &$tf, $term, $boost ) {
		if ( '' === $term || mb_strlen( $term, 'UTF-8' ) > 64 || mb_strlen( $term, 'UTF-8' ) < 2 ) {
			return;
		}
		$tf[ $term ] = ( $tf[ $term ] ?? 0 ) + $boost;
	}

	/**
	 * L2-normalized TF-IDF weights.
	 *
	 * @param array<string,float> $tf Term frequencies.
	 * @param array<string,int>   $df Document frequencies.
	 * @param int                 $n  Number of indexed documents (at least MIN_CORPUS is assumed).
	 * @return array<string,float>
	 */
	public static function weigh( array $tf, array $df, $n ) {
		$n       = max( self::MIN_CORPUS, (int) $n );
		$weights = array();
		$norm    = 0.0;
		foreach ( $tf as $term => $freq ) {
			$freq = max( 1.0, (float) $freq );
			$d    = max( 1, (int) ( $df[ $term ] ?? 1 ) );
			$w    = ( 1 + log( $freq ) ) * log( 1 + $n / $d );
			if ( $w <= 0 ) {
				continue;
			}
			$weights[ $term ] = $w;
			$norm            += $w * $w;
		}
		if ( $norm <= 0 ) {
			return array();
		}
		$norm = sqrt( $norm );
		foreach ( $weights as $term => $w ) {
			$weights[ $term ] = round( $w / $norm, 6 );
		}
		return $weights;
	}

	/**
	 * Whether a stored vector already holds normalized weights (raw build
	 * frequencies are ≥ 1 per term, so their norm is larger unless the post
	 * has a single term — whose normalized weight is 1 anyway).
	 *
	 * @param array<string,float> $weights Weights.
	 * @return bool
	 */
	public static function is_normalized( array $weights ) {
		$norm = 0.0;
		foreach ( $weights as $w ) {
			$norm += (float) $w * (float) $w;
		}
		return abs( sqrt( $norm ) - 1 ) < 0.01;
	}

	/**
	 * Relevance 0..1 from a cosine similarity. Cosines of related but
	 * different articles are small (0.05–0.3); the square root spreads them
	 * so links.min_relevance (percent) is intuitive: 25% ≈ cosine 0.06.
	 *
	 * @param float $cosine Cosine similarity.
	 * @return float
	 */
	public static function relevance( $cosine ) {
		return sqrt( max( 0.0, min( 1.0, (float) $cosine ) ) );
	}

	/**
	 * Cosine similarity of two weight vectors.
	 *
	 * @param array<string,float> $a Vector.
	 * @param array<string,float> $b Vector.
	 * @return float
	 */
	public static function cosine( array $a, array $b ) {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		foreach ( $a as $term => $w ) {
			$na += $w * $w;
			if ( isset( $b[ $term ] ) ) {
				$dot += $w * $b[ $term ];
			}
		}
		foreach ( $b as $w ) {
			$nb += $w * $w;
		}
		return ( $na > 0 && $nb > 0 ) ? $dot / ( sqrt( $na ) * sqrt( $nb ) ) : 0.0;
	}

	/* ------------------------------------------------------------------
	 * Eligibility
	 * ------------------------------------------------------------------ */

	/**
	 * Post types taking part in internal linking.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$configured = (array) $this->settings->get( 'links.post_types', array( 'post', 'page' ) );
		return array_values( array_intersect( $configured, array_keys( $this->types->post_types() ) ) );
	}

	/**
	 * Whether a post belongs in the content graph index.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public function is_eligible( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return false;
		}
		if ( in_array( (int) $post->ID, array_map( 'intval', (array) $this->settings->get( 'links.excluded_ids', array() ) ), true ) ) {
			return false;
		}
		if ( $this->meta->get_post_field( $post->ID, 'links_exclude' ) ) {
			return false;
		}
		return $this->types->is_post_indexable( $post );
	}

	/* ------------------------------------------------------------------
	 * Index maintenance
	 * ------------------------------------------------------------------ */

	/**
	 * Build state.
	 *
	 * @return array{phase:string,cursor:int,processed:int,total:int,started_at:string,completed_at:string,weights_started_at:string}
	 */
	public function build_state() {
		// Read fresh: a long-running worker must not act on a phase another process has since changed.
		$this->state->refresh();
		$state = (array) $this->state->get( self::STATE_KEY, array() );
		return array_merge(
			array(
				'phase'              => 'build',
				'cursor'             => 0,
				'processed'          => 0,
				'total'              => 0,
				'started_at'         => '',
				'completed_at'       => '',
				'weights_started_at' => '',
			),
			$state
		);
	}

	/**
	 * Saves build state.
	 *
	 * @param array $state State.
	 */
	public function save_build_state( array $state ) {
		$this->state->set( self::STATE_KEY, $state );
	}

	/**
	 * Whether the first build is complete (similarity scores are final).
	 *
	 * @return bool
	 */
	public function is_ready() {
		return 'done' === $this->build_state()['phase'];
	}

	/**
	 * Indexes (or removes) one post.
	 *
	 * @param int|\WP_Post $post Post.
	 * @param string|null  $html Rendered content (rendered here when null).
	 * @return int Number of stored terms.
	 */
	public function index_post( $post, $html = null ) {
		global $wpdb;
		$post = get_post( $post );
		if ( ! $post ) {
			return 0;
		}
		if ( ! $this->is_eligible( $post ) ) {
			$this->remove_post( $post->ID );
			return 0;
		}
		if ( null === $html ) {
			$html = $this->renderer->render( $post->post_content, $post );
		}
		$doc      = HtmlDocument::parse( '<div>' . $html . '</div>' );
		$headings = array();
		foreach ( $doc->headings() as $heading ) {
			$headings[] = $heading['text'];
		}
		$body = $doc->text();
		$tf   = self::extract_terms( get_the_title( $post ), $headings, $body );

		$old     = array_keys( $this->terms_of( $post->ID ) );
		$new     = array_keys( $tf );
		$added   = array_values( array_diff( $new, $old ) );
		$removed = array_values( array_diff( $old, $new ) );
		$this->df_increment( $added );
		$this->df_decrement( $removed );
		$was_indexed = ! empty( $old );

		$phase = $this->build_state()['phase'];
		if ( 'build' === $phase ) {
			$weights = $tf; // Raw frequencies until document frequencies are complete.
		} else {
			$n       = $this->indexed_count() + ( $was_indexed ? 0 : 1 );
			$weights = self::weigh( $tf, $this->df_for( $new ), $n );
		}

		$table = Tables::terms();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", (int) $post->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->insert_terms( (int) $post->ID, $weights );

		$this->repo->update_fields(
			(int) $post->ID,
			array(
				'term_count' => count( $weights ),
				'indexed_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $was_indexed && null !== $this->count_cache ) {
			++$this->count_cache;
		}
		$this->forget_related( (int) $post->ID );
		return count( $weights );
	}

	/**
	 * Recomputes TF-IDF weights from stored raw frequencies (build phase 2).
	 *
	 * @param int $post_id Post id.
	 * @return int Terms.
	 */
	public function reweight_post( $post_id ) {
		global $wpdb;
		$tf = $this->terms_of( $post_id );
		if ( ! $tf || self::is_normalized( $tf ) ) {
			return count( $tf ); // Nothing stored, or already final TF-IDF weights.
		}
		$weights = self::weigh( $tf, $this->df_for( array_keys( $tf ) ), $this->indexed_count() );
		$table   = Tables::terms();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", (int) $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->insert_terms( (int) $post_id, $weights );
		return count( $weights );
	}

	/**
	 * Removes a post from the index (document frequencies decremented).
	 *
	 * @param int $post_id Post id.
	 */
	public function remove_post( $post_id ) {
		global $wpdb;
		$old = array_keys( $this->terms_of( $post_id ) );
		if ( $old ) {
			$this->df_decrement( $old );
			$table = Tables::terms();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", (int) $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( null !== $this->count_cache ) {
				$this->count_cache = max( 0, $this->count_cache - 1 );
			}
		}
		$this->forget_related( (int) $post_id );
		$this->repo->update_fields(
			(int) $post_id,
			array(
				'term_count' => 0,
				'indexed_at' => null,
			),
			false
		);
	}

	/**
	 * Stored terms of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,float>
	 */
	public function terms_of( $post_id ) {
		global $wpdb;
		$table = Tables::terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term, weight FROM {$table} WHERE post_id = %d ORDER BY weight DESC", (int) $post_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->term ] = (float) $row->weight;
		}
		return $out;
	}

	/**
	 * Highest-weighted terms of a post.
	 *
	 * @param int  $post_id      Post id.
	 * @param int  $limit        Max terms.
	 * @param bool $bigrams_only Only two-word terms.
	 * @return string[]
	 */
	public function top_terms( $post_id, $limit = 10, $bigrams_only = false ) {
		$out = array();
		foreach ( $this->terms_of( $post_id ) as $term => $weight ) {
			if ( $bigrams_only && false === strpos( $term, ' ' ) ) {
				continue;
			}
			$out[] = $term;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Number of indexed posts.
	 *
	 * @return int
	 */
	public function indexed_count() {
		if ( null === $this->count_cache ) {
			$this->count_cache = $this->repo->count_indexed();
		}
		return $this->count_cache;
	}

	/**
	 * Related posts by content similarity, boosted by shared categories/tags
	 * and the cornerstone flag.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Max results.
	 * @return array<int,array{post_id:int,score:float,similarity:float,shared_terms:int,cornerstone:bool}>
	 */
	public function related( $post_id, $limit = 10 ) {
		$post_id = (int) $post_id;
		$limit   = max( 1, (int) $limit );
		$key     = $this->related_cache_key( $post_id, $limit );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->compute_related( $post_id, $limit );
		set_transient( $key, $result, self::RELATED_CACHE_TTL );
		return $result;
	}

	/**
	 * Cached related() result, or null when not computed yet (never computes).
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit used when it was cached.
	 * @return array|null
	 */
	public function cached_related( $post_id, $limit ) {
		$cached = get_transient( $this->related_cache_key( (int) $post_id, max( 1, (int) $limit ) ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Drops the cached related posts of a post (after it was re-indexed).
	 *
	 * @param int $post_id Post id.
	 */
	public function forget_related( $post_id ) {
		foreach ( array( 3, 5, 8, 30 ) as $limit ) {
			delete_transient( $this->related_cache_key( (int) $post_id, $limit ) );
			delete_transient( $this->related_cache_key( (int) $post_id, $limit, 'spp_tf_' ) );
		}
	}

	/**
	 * Transient key for related() results (changes when the index is rebuilt).
	 * TargetFinder caches its ranked targets under the same scheme (prefix spp_tf_),
	 * so re-indexing a post drops both.
	 *
	 * @param int    $post_id Post id.
	 * @param int    $limit   Limit.
	 * @param string $prefix  Key prefix.
	 * @return string
	 */
	public function related_cache_key( $post_id, $limit, $prefix = 'spp_rel_' ) {
		// The plugin version is part of the key: an update can change how partners are ranked.
		$generation = substr( md5( (string) $this->build_state()['started_at'] . '|' . ( defined( 'SEARCHPILOT_VERSION' ) ? SEARCHPILOT_VERSION : '' ) ), 0, 6 );
		return $prefix . $generation . '_' . (int) $post_id . '_' . (int) $limit;
	}

	/**
	 * Computes related posts: the post's strongest terms joined against the index.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Max results.
	 * @return array
	 */
	private function compute_related( $post_id, $limit ) {
		global $wpdb;
		$terms = Tables::terms();
		$stats = Tables::term_stats();
		$n     = $this->indexed_count();
		$own   = array_slice( $this->terms_of( $post_id ), 0, self::RELATED_TERMS, true );
		if ( ! $own ) {
			return array();
		}
		// Terms shared by a large part of the site carry little meaning and are expensive to join.
		$max_df  = min( self::MAX_JOIN_DF, max( 25, (int) ceil( $n * 0.2 ) ) );
		$holders = implode( ',', array_fill( 0, count( $own ), '%s' ) );
		$params  = array_merge( array( $post_id, $post_id, $max_df ), array_keys( $own ), array( $limit * 3 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t2.post_id AS post_id, SUM( t1.weight * t2.weight ) AS score
				FROM {$terms} t1
				INNER JOIN {$stats} s ON s.term = t1.term
				INNER JOIN {$terms} t2 ON t2.term = t1.term
				WHERE t1.post_id = %d AND t2.post_id <> %d AND s.df >= 2 AND s.df <= %d AND t1.term IN ({$holders})
				GROUP BY t2.post_id
				ORDER BY score DESC
				LIMIT %d",
				$params
			)
		);
		if ( ! $rows ) {
			return array();
		}
		$candidates = array();
		foreach ( $rows as $row ) {
			$candidates[ (int) $row->post_id ] = min( 1.0, max( 0.0, (float) $row->score ) );
		}
		$posts = get_posts(
			array(
				'post__in'               => array_keys( $candidates ),
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'posts_per_page'         => count( $candidates ),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);
		$published = array();
		foreach ( $posts as $p ) {
			$published[ (int) $p->ID ] = $p;
		}
		$excluded = array_map( 'intval', (array) $this->settings->get( 'links.excluded_ids', array() ) );
		$shared   = $this->shared_taxonomy_counts( $post_id, array_keys( $published ) );
		$out      = array();
		foreach ( $candidates as $id => $similarity ) {
			if ( ! isset( $published[ $id ] ) || in_array( $id, $excluded, true ) ) {
				continue;
			}
			$cornerstone = (bool) $this->meta->get_post_field( $id, 'cornerstone' );
			$common      = (int) ( $shared[ $id ] ?? 0 );
			$score       = self::relevance( $similarity );
			if ( $score > 0 ) {
				$score = min( 1.0, $score + 0.03 * min( 3, $common ) + ( $cornerstone ? 0.05 : 0 ) );
			}
			$out[] = array(
				'post_id'      => $id,
				'score'        => round( $score, 4 ),
				'similarity'   => round( $similarity, 4 ),
				'shared_terms' => $common,
				'cornerstone'  => $cornerstone,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['post_id'] <=> $b['post_id'];
			}
		);
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Shared category/tag counts between a post and candidates.
	 *
	 * @param int   $post_id Post id.
	 * @param int[] $ids     Candidate ids.
	 * @return array<int,int>
	 */
	private function shared_taxonomy_counts( $post_id, array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$taxonomies = array_values( array_filter( array( 'category', 'post_tag' ), 'taxonomy_exists' ) );
		if ( ! $taxonomies ) {
			return array();
		}
		$own = wp_get_object_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $own ) || ! $own ) {
			return array();
		}
		$own   = array_map( 'intval', $own );
		$terms = wp_get_object_terms( $ids, $taxonomies, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( in_array( (int) $term->term_id, $own, true ) ) {
				$out[ (int) $term->object_id ] = ( $out[ (int) $term->object_id ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/**
	 * Inserts weighted terms.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,float> $weights Weights.
	 */
	private function insert_terms( $post_id, array $weights ) {
		global $wpdb;
		if ( ! $weights ) {
			return;
		}
		$table  = Tables::terms();
		$holder = array();
		$values = array();
		foreach ( $weights as $term => $weight ) {
			$holder[] = '(%s, %d, %f)';
			$values[] = (string) $term;
			$values[] = (int) $post_id;
			$values[] = (float) $weight;
		}
		// IGNORE: the column collation may treat two spellings as one term.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (term, post_id, weight) VALUES " . implode( ', ', $holder ), $values ) );
	}

	/**
	 * Document frequencies of terms (stems), for weighting shared words.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,int> Only terms present in the index.
	 */
	public function document_frequencies( array $terms ) {
		return $this->df_for( array_values( array_unique( array_filter( array_map( 'strval', $terms ), 'strlen' ) ) ) );
	}

	/**
	 * Document frequencies of terms.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string,int>
	 */
	private function df_for( array $terms ) {
		global $wpdb;
		if ( ! $terms ) {
			return array();
		}
		$table = Tables::term_stats();
		$out   = array();
		foreach ( array_chunk( $terms, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term, df FROM {$table} WHERE term IN ({$holders})", $chunk ) );
			foreach ( (array) $rows as $row ) {
				$out[ (string) $row->term ] = (int) $row->df;
			}
		}
		return $out;
	}

	/**
	 * Increments document frequencies (creates missing terms).
	 *
	 * @param string[] $terms Terms.
	 */
	private function df_increment( array $terms ) {
		global $wpdb;
		if ( ! $terms ) {
			return;
		}
		$table    = Tables::term_stats();
		$existing = array_keys( $this->df_for( $terms ) );
		$missing  = array_values( array_diff( $terms, $existing ) );
		foreach ( array_chunk( $existing, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET df = df + 1 WHERE term IN ({$holders})", $chunk ) );
		}
		foreach ( array_chunk( $missing, 100 ) as $chunk ) {
			$holders = implode( ', ', array_fill( 0, count( $chunk ), '(%s, 1)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (term, df) VALUES {$holders}", $chunk ) );
		}
	}

	/**
	 * Decrements document frequencies (drops terms that reach zero).
	 *
	 * @param string[] $terms Terms.
	 */
	private function df_decrement( array $terms ) {
		global $wpdb;
		if ( ! $terms ) {
			return;
		}
		$table = Tables::term_stats();
		foreach ( array_chunk( $terms, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET df = CASE WHEN df > 1 THEN df - 1 ELSE 0 END WHERE term IN ({$holders})", $chunk ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE df = 0 AND term IN ({$holders})", $chunk ) );
			// phpcs:enable
		}
	}

	/**
	 * Clears the whole index (before a full rebuild).
	 */
	public function truncate() {
		global $wpdb;
		$terms = Tables::terms();
		$stats = Tables::term_stats();
		$wpdb->query( "DELETE FROM {$terms}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$stats}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->repo->clear_index_marks();
		$this->count_cache = null;
	}

	/**
	 * Distinct indexed post ids after a cursor (for the weighting phase).
	 *
	 * @param int $after Cursor.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function indexed_ids_after( $after, $limit ) {
		global $wpdb;
		$table = Tables::terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$table} WHERE post_id > %d ORDER BY post_id ASC LIMIT %d", (int) $after, (int) $limit ) ) );
	}

	/**
	 * Converts stray raw-frequency vectors to TF-IDF weights (self-healing
	 * after interrupted builds). Raw frequencies are > 1, normalized weights ≤ 1.
	 *
	 * @param int $limit Max posts.
	 * @return int Posts re-weighted.
	 */
	public function heal_raw_vectors( $limit = 50 ) {
		global $wpdb;
		if ( ! $this->is_ready() ) {
			return 0;
		}
		$table = Tables::terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$table} WHERE weight > 1.001 LIMIT %d", (int) $limit ) ) );
		foreach ( $ids as $id ) {
			$this->reweight_post( $id );
		}
		return count( $ids );
	}

	/**
	 * Number of distinct terms.
	 *
	 * @return int
	 */
	public function term_count() {
		global $wpdb;
		$table = Tables::term_stats();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}

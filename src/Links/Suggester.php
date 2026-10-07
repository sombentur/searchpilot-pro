<?php
/**
 * Internal link suggestions and (policy-governed) insertion.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Proposes links between related posts in two steps:
 *
 * 1. Targets — TargetFinder ranks partners on shared title words, slug
 *    tokens, taxonomy and body similarity (Kannada-safe: inflection-tolerant,
 *    no exact shared string needed), falling back to the newest posts of the
 *    same category.
 * 2. Anchors, in order:
 *    a) "inline" — a phrase already in the source's paragraphs that names the
 *       target: its focus keyword, title n-grams (2–5 words), body bigrams
 *       about its topic, or one distinctive title word; inflected forms match
 *       (ಬೆಂಗಳೂರಿನಲ್ಲಿ ↔ ಬೆಂಗಳೂರು, elections ↔ election);
 *    b) "related" — when no phrase fits, the target is offered for a short
 *       related-articles list at the end of the source (RelatedBlock), with
 *       the target's title as the link text.
 *
 * Limits: links.max_links_per_post internal links per post in total, one
 * in-text link per links.words_per_link words (related-list links do not
 * count towards the text density), at most RelatedBlock::MAX_ITEMS list
 * items, one link per target, an anchor (titles included) used at most three
 * times for the same target site-wide, never inside headings, existing links
 * or SearchPilot's own regions, never to the post itself, near-duplicates
 * excluded. Links are inserted only on approval, or automatically when the
 * automation policy allows the internal_links task — the related list only
 * when links.auto_related_block is on.
 */
class Suggester {

	const MAX_ANCHOR_USES = 3;

	const KINDS = array( 'inline', 'related' );

	/**
	 * How many of a target's strongest body terms may carry a link as a single word.
	 */
	const CENTRAL_TERMS = 12;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Index.
	 *
	 * @var ContentIndex
	 */
	private $index;

	/**
	 * Graph.
	 *
	 * @var LinkGraph
	 */
	private $graph;

	/**
	 * Suggestions.
	 *
	 * @var SuggestionRepository
	 */
	private $repo;

	/**
	 * Renderer.
	 *
	 * @var ContentRenderer
	 */
	private $renderer;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Ranked targets.
	 *
	 * @var TargetFinder
	 */
	private $finder;

	/**
	 * Per-request source documents.
	 *
	 * @var array<int,array>
	 */
	private $documents = array();

	/**
	 * Per-request anchor phrases of targets.
	 *
	 * @var array<int,array>
	 */
	private $phrase_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Settings.
	 * @param ContentIndex         $index    Index.
	 * @param LinkGraph            $graph    Graph.
	 * @param SuggestionRepository $repo     Suggestions.
	 * @param ContentRenderer      $renderer Renderer.
	 * @param MetaStore            $meta     Meta store.
	 * @param Changes              $changes  Changes.
	 * @param AutomationPolicy     $policy   Policy.
	 * @param Logger               $logger   Logger.
	 * @param TargetFinder         $finder   Ranked targets.
	 */
	public function __construct( Settings $settings, ContentIndex $index, LinkGraph $graph, SuggestionRepository $repo, ContentRenderer $renderer, MetaStore $meta, Changes $changes, AutomationPolicy $policy, Logger $logger, TargetFinder $finder ) {
		$this->settings = $settings;
		$this->index    = $index;
		$this->graph    = $graph;
		$this->repo     = $repo;
		$this->renderer = $renderer;
		$this->meta     = $meta;
		$this->changes  = $changes;
		$this->policy   = $policy;
		$this->logger   = $logger;
		$this->finder   = $finder;
	}

	/* ------------------------------------------------------------------
	 * Generation
	 * ------------------------------------------------------------------ */

	/**
	 * Suggestions for a newly published post, then automatic insertion when allowed.
	 *
	 * @param int $post_id Post id.
	 * @return array{outbound:int[],inbound:int[],applied:int}
	 */
	public function for_new_post( $post_id ) {
		$empty = array(
			'outbound' => array(),
			'inbound'  => array(),
			'applied'  => 0,
		);
		if ( ! $this->settings->get( 'links.suggestions', true ) ) {
			return $empty;
		}
		$result  = $this->generate( $post_id );
		$sources = array( (int) $post_id );
		foreach ( $result['inbound'] as $id ) {
			$row = $this->repo->get( $id );
			if ( $row ) {
				$sources[] = (int) $row['source_id'];
			}
		}
		$result['applied'] = $this->auto_apply( array_unique( $sources ) );
		if ( $result['outbound'] || $result['inbound'] ) {
			$this->logger->info(
				'links',
				sprintf( 'Link suggestions for post %d: %d outbound, %d inbound, %d inserted automatically.', (int) $post_id, count( $result['outbound'] ), count( $result['inbound'] ), $result['applied'] )
			);
		}
		return $result;
	}

	/**
	 * Generates (stores) suggestions for a post.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $direction both|outbound|inbound.
	 * @return array{outbound:int[],inbound:int[]}
	 */
	public function generate( $post_id, $direction = 'both' ) {
		$out  = array(
			'outbound' => array(),
			'inbound'  => array(),
		);
		$post = get_post( $post_id );
		if ( ! $post || ! $this->index->is_eligible( $post ) ) {
			return $out;
		}
		$ranked = $this->finder->find( $post->ID, 30 );
		if ( 'inbound' !== $direction ) {
			$out['outbound'] = $this->outbound( $post, $ranked );
		}
		if ( 'outbound' !== $direction ) {
			$out['inbound'] = $this->inbound( $post, $ranked );
		}
		return $out;
	}

	/**
	 * Links FROM a post, without storing anything (planners and previews).
	 *
	 * @param int $post_id Source post id.
	 * @param int $max     Max links.
	 * @return array<int,array{source_id:int,target_id:int,kind:string,anchor:string,context:string,score:float}>
	 */
	public function outbound_options( $post_id, $max = 3 ) {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->index->is_eligible( $post ) ) {
			return array();
		}
		$doc   = $this->document( $post );
		$room  = $this->room( $post, $doc, false );
		$total = max( 0, min( (int) $max, $room['inline'] + $room['related'] ) );
		$taken = array();
		$out   = array();
		foreach ( $this->finder->find( $post->ID, 30 ) as $rank ) {
			if ( count( $out ) >= $total ) {
				break;
			}
			$target = get_post( $rank['post_id'] );
			if ( ! $target || $this->graph->links_to( $post->ID, $target->ID ) || $this->blocked_pair( $post->ID, $target->ID ) ) {
				continue;
			}
			$option = $this->option_for( $post, $doc, $target, $taken, self::room_for_tier( $room, $rank ) );
			if ( ! $option ) {
				continue;
			}
			--$room[ $option['kind'] ];
			if ( 'inline' === $option['kind'] ) {
				$taken[ mb_strtolower( $option['anchor'], 'UTF-8' ) ] = true;
			}
			$out[] = array_merge(
				$option,
				array(
					'source_id' => (int) $post->ID,
					'target_id' => (int) $target->ID,
					'score'     => (float) $rank['score'],
				)
			);
		}
		return $out;
	}

	/**
	 * Links TO a post from related posts, without storing anything.
	 *
	 * @param int $post_id Target post id.
	 * @param int $max     Max source posts.
	 * @return array<int,array{source_id:int,target_id:int,kind:string,anchor:string,context:string,score:float}>
	 */
	public function inbound_options( $post_id, $max = 3 ) {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->index->is_eligible( $post ) ) {
			return array();
		}
		$out = array();
		foreach ( $this->finder->find( $post->ID, 30 ) as $rank ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			$source = get_post( $rank['post_id'] );
			if ( ! $source || ! ContentRenderer::is_editable( $source ) || $this->graph->links_to( $source->ID, $post->ID ) || $this->blocked_pair( $source->ID, $post->ID ) ) {
				continue;
			}
			$doc    = $this->document( $source );
			$option = $this->option_for( $source, $doc, $post, $this->pending_anchors( $source->ID, $post->ID ), self::room_for_tier( $this->room( $source, $doc, false ), $rank ) );
			if ( $option ) {
				$out[] = array_merge(
					$option,
					array(
						'source_id' => (int) $source->ID,
						'target_id' => (int) $post->ID,
						'score'     => (float) $rank['score'],
					)
				);
			}
		}
		return $out;
	}

	/**
	 * New → Existing suggestions (links from $post).
	 *
	 * @param \WP_Post $post   Source.
	 * @param array    $ranked Ranked targets.
	 * @return int[] Suggestion ids.
	 */
	private function outbound( \WP_Post $post, array $ranked ) {
		$doc     = $this->document( $post );
		$taken   = array();
		$ids     = array();
		$pending = array();
		$scores  = array();
		foreach ( $ranked as $rank ) {
			$scores[ (int) $rank['post_id'] ] = (float) $rank['score'];
		}
		foreach ( $this->repo->pending_for_source( $post->ID ) as $row ) {
			$pending[ (int) $row['target_id'] ] = $row;
			if ( 'inline' === $row['kind'] ) {
				$taken[ mb_strtolower( (string) $row['anchor'], 'UTF-8' ) ] = true;
			}
		}

		// Pass 1: refresh pending suggestions that are still valid (or mark them outdated).
		foreach ( $pending as $target_id => $row ) {
			$target = get_post( $target_id );
			$valid  = $target && $this->index->is_eligible( $target ) && ! $this->graph->links_to( $post->ID, $target_id );
			$match  = null;
			if ( $valid && 'related' === $row['kind'] ) {
				$match = $doc['editable'] ? $this->related_option( $target, (string) $row['anchor'] ) : null;
			} elseif ( $valid ) {
				$others = $taken;
				unset( $others[ mb_strtolower( (string) $row['anchor'], 'UTF-8' ) ] );
				$match = $this->match_for( $doc, $target, $others, $row['anchor'] );
			}
			if ( ! $match ) {
				$this->repo->set_status( $row['id'], 'stale' );
				unset( $pending[ $target_id ] );
				continue;
			}
			$id = $this->repo->upsert(
				array(
					'source_id'  => $post->ID,
					'target_id'  => $target_id,
					'anchor'     => $match['anchor'],
					'context'    => $match['context'],
					'kind'       => $match['kind'],
					'score'      => $scores[ $target_id ] ?? $row['score'],
					'direction'  => 'outbound',
					'trigger_id' => $post->ID,
				)
			);
			if ( $id ) {
				$ids[] = $id;
			}
		}

		// Pass 2: new targets within the remaining room (pending suggestions count).
		$room = $this->room( $post, $doc, true );
		foreach ( $ranked as $rank ) {
			if ( $room['inline'] <= 0 && $room['related'] <= 0 ) {
				break;
			}
			$target_id = (int) $rank['post_id'];
			if ( isset( $pending[ $target_id ] ) ) {
				continue;
			}
			$target = get_post( $target_id );
			if ( ! $target || $this->graph->links_to( $post->ID, $target_id ) || $this->blocked_pair( $post->ID, $target_id ) ) {
				continue;
			}
			$match = $this->option_for( $post, $doc, $target, $taken, self::room_for_tier( $room, $rank ) );
			if ( ! $match ) {
				continue;
			}
			$id = $this->repo->upsert(
				array(
					'source_id'  => $post->ID,
					'target_id'  => $target_id,
					'anchor'     => $match['anchor'],
					'context'    => $match['context'],
					'kind'       => $match['kind'],
					'score'      => $rank['score'],
					'direction'  => 'outbound',
					'trigger_id' => $post->ID,
				)
			);
			if ( $id ) {
				$ids[] = $id;
				--$room[ $match['kind'] ];
				if ( 'inline' === $match['kind'] ) {
					$taken[ mb_strtolower( $match['anchor'], 'UTF-8' ) ] = true;
				}
			}
		}
		return $ids;
	}

	/**
	 * Existing → New suggestions (links to $post from related posts).
	 *
	 * @param \WP_Post $post   Target (the new post).
	 * @param array    $ranked Ranked partners (used as sources).
	 * @return int[] Suggestion ids.
	 */
	private function inbound( \WP_Post $post, array $ranked ) {
		$max = (int) $this->settings->get( 'links.max_inbound_per_new_post', 5 );
		$ids = array();
		if ( $max <= 0 ) {
			return $ids;
		}
		foreach ( $ranked as $rank ) {
			if ( count( $ids ) >= $max ) {
				break;
			}
			$source = get_post( $rank['post_id'] );
			if ( ! $source || ! $this->index->is_eligible( $source ) || $this->graph->links_to( $source->ID, $post->ID ) ) {
				continue;
			}
			$existing = $this->repo->find_pair( $source->ID, $post->ID );
			if ( $existing && in_array( $existing['status'], array( 'applied', 'dismissed' ), true ) ) {
				continue;
			}
			$doc        = $this->document( $source );
			$is_pending = $existing && 'pending' === $existing['status'];
			$room       = $this->room( $source, $doc, true );
			if ( $is_pending ) {
				++$room[ $existing['kind'] ]; // Its own slot.
			}
			$match = $this->option_for( $source, $doc, $post, $this->pending_anchors( $source->ID, $post->ID ), self::room_for_tier( $room, $rank ), $is_pending ? (string) $existing['anchor'] : '' );
			if ( ! $match ) {
				if ( $is_pending ) {
					$this->repo->set_status( $existing['id'], 'stale' );
				}
				continue;
			}
			$id = $this->repo->upsert(
				array(
					'source_id'  => $source->ID,
					'target_id'  => $post->ID,
					'anchor'     => $match['anchor'],
					'context'    => $match['context'],
					'kind'       => $match['kind'],
					'score'      => $rank['score'],
					'direction'  => 'inbound',
					'trigger_id' => $post->ID,
				)
			);
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Best way to link a target from a source: an in-text anchor when the
	 * text has room and a fitting phrase, else a related-list item.
	 *
	 * @param \WP_Post $source     Source.
	 * @param array    $doc        Source document.
	 * @param \WP_Post $target     Target.
	 * @param array    $taken      Anchors already used in this source (lower-case => true).
	 * @param array    $room       inline / related room.
	 * @param string   $own_anchor Anchor of this pair's pending suggestion.
	 * @return array{kind:string,anchor:string,context:string}|null
	 */
	private function option_for( \WP_Post $source, array $doc, \WP_Post $target, array $taken, array $room, $own_anchor = '' ) {
		if ( (int) $source->ID === (int) $target->ID || ! $this->index->is_eligible( $target ) ) {
			return null;
		}
		if ( $room['inline'] > 0 ) {
			$match = $this->match_for( $doc, $target, $taken, $own_anchor );
			if ( $match ) {
				return $match;
			}
		}
		if ( $room['related'] > 0 && $doc['editable'] ) {
			return $this->related_option( $target, $own_anchor );
		}
		return null;
	}

	/**
	 * Room left for a partner of the given tier: partners from the fallback tier ("recent":
	 * same category, no topical signal) are only listed under the related-articles title —
	 * a phrase match with them inside the text is a coincidence, not a reason to link.
	 *
	 * @param array $room inline, related.
	 * @param array $rank Ranked partner (tier).
	 * @return array
	 */
	private static function room_for_tier( array $room, array $rank ) {
		if ( 'related' !== (string) ( $rank['tier'] ?? 'related' ) ) {
			$room['inline'] = 0;
		}
		return $room;
	}

	/**
	 * A related-list item for a target (null when its title is already used
	 * as link text for it three times).
	 *
	 * @param \WP_Post $target     Target.
	 * @param string   $own_anchor Anchor of this pair's pending suggestion (not counted as reuse).
	 * @return array{kind:string,anchor:string,context:string}|null
	 */
	private function related_option( \WP_Post $target, $own_anchor = '' ) {
		$title = self::plain_title( $target );
		if ( '' === $title ) {
			return null;
		}
		$lower = mb_strtolower( $title, 'UTF-8' );
		$uses  = (int) ( $this->anchor_uses( $target->ID )[ $lower ] ?? 0 );
		if ( '' !== $own_anchor && mb_strtolower( trim( (string) $own_anchor ), 'UTF-8' ) === $lower ) {
			--$uses;
		}
		if ( $uses >= self::MAX_ANCHOR_USES ) {
			return null;
		}
		return array(
			'kind'    => 'related',
			'anchor'  => $title,
			'context' => '',
		);
	}

	/**
	 * Whether a pair was applied or dismissed before (never proposed again).
	 *
	 * @param int $source_id Source.
	 * @param int $target_id Target.
	 * @return bool
	 */
	private function blocked_pair( $source_id, $target_id ) {
		$existing = $this->repo->find_pair( $source_id, $target_id );
		return $existing && in_array( $existing['status'], array( 'applied', 'dismissed' ), true );
	}

	/**
	 * Anchors other pending suggestions of a source already claim.
	 *
	 * @param int $source_id Source.
	 * @param int $except    Target whose own suggestion does not count.
	 * @return array<string,bool>
	 */
	private function pending_anchors( $source_id, $except ) {
		$taken = array();
		foreach ( $this->repo->pending_for_source( $source_id ) as $other ) {
			if ( (int) $other['target_id'] !== (int) $except && 'inline' === $other['kind'] ) {
				$taken[ mb_strtolower( (string) $other['anchor'], 'UTF-8' ) ] = true;
			}
		}
		return $taken;
	}

	/**
	 * How often each anchor links to a target (inserted + pending, lower-case).
	 *
	 * @param int $target_id Target.
	 * @return array<string,int>
	 */
	private function anchor_uses( $target_id ) {
		$uses = $this->graph->anchor_uses( $target_id );
		foreach ( $this->repo->anchor_uses( $target_id ) as $anchor => $n ) {
			$uses[ $anchor ] = ( $uses[ $anchor ] ?? 0 ) + $n;
		}
		return $uses;
	}

	/**
	 * Best in-text anchor in a source document for a target.
	 *
	 * @param array    $doc        Source document.
	 * @param \WP_Post $target     Target.
	 * @param array    $taken      Anchors already used in this source (lower-case => true).
	 * @param string   $own_anchor Anchor of this pair's pending suggestion (not counted as reuse).
	 * @return array{anchor:string,context:string,kind:string}|null
	 */
	private function match_for( array $doc, \WP_Post $target, array $taken, $own_anchor = '' ) {
		if ( ! $doc['segments'] ) {
			return null;
		}
		$uses = $this->anchor_uses( $target->ID );
		$own  = mb_strtolower( trim( (string) $own_anchor ), 'UTF-8' );
		if ( '' !== $own && ! empty( $uses[ $own ] ) ) {
			--$uses[ $own ];
		}
		$phrases = $this->phrases_for( $target );
		$url     = (string) get_permalink( $target );
		// Try candidates best-first until one can actually be inserted.
		foreach ( self::candidates( $doc['segments'], $phrases, $uses, $taken ) as $candidate ) {
			if ( $doc['editable'] && null === LinkInserter::insert( $doc['html'], $candidate['anchor'], $url, 0 ) ) {
				continue;
			}
			$block = $doc['blocks'][ $candidate['block'] ] ?? $candidate['segment'];
			return array(
				'anchor'  => $candidate['anchor'],
				'context' => self::context_sentence( $block, $candidate['anchor'] ),
				'kind'    => 'inline',
			);
		}
		return null;
	}

	/**
	 * Candidate phrases describing a target: focus keyword, title n-grams,
	 * body bigrams about its topic and distinctive single title words.
	 *
	 * @param \WP_Post $target Target.
	 * @return array<int,array{stems:string[],words?:string[],kind:string,priority:float}>
	 */
	public function phrases_for( \WP_Post $target ) {
		if ( isset( $this->phrase_cache[ $target->ID ] ) ) {
			return $this->phrase_cache[ $target->ID ];
		}
		$title   = self::plain_title( $target );
		$focus   = (string) $this->meta->focus( $target->ID );
		$bigrams = array();
		foreach ( $this->index->top_terms( $target->ID, 8, true ) as $term ) {
			$bigrams[] = explode( ' ', $term ); // phrases() keeps only those made of title/focus words.
		}
		// A single word makes an anchor only when it is rare on the site (names, places, events)
		// and never when it is the site's dominant term (in more than 20 % of posts, e.g. the
		// city a local news site covers); phrases made only of dominant terms are no anchor either.
		$words = ContentIndex::key_words( $title . ' ' . $focus );
		$vocab = self::anchor_vocabulary( $words, $this->index->document_frequencies( array_map( array( ContentIndex::class, 'stem' ), $words ) ), $this->index->indexed_count() );
		// Rare on the site is not enough on a small site (an adjective used in three posts is
		// "rare" too): a single word must also be what the target is about — one of its
		// strongest body terms, or part of its focus keyword.
		$central = array();
		foreach ( $this->index->top_terms( $target->ID, self::CENTRAL_TERMS ) as $term ) {
			$central[ (string) $term ] = true;
		}
		foreach ( ContentIndex::key_words( $focus ) as $word ) {
			$central[ ContentIndex::stem( $word ) ] = true;
		}
		$vocab['singles'] = array_values(
			array_filter(
				$vocab['singles'],
				static function ( $word ) use ( $central ) {
					return isset( $central[ ContentIndex::stem( $word ) ] );
				}
			)
		);
		if ( count( $this->phrase_cache ) > 100 ) {
			$this->phrase_cache = array();
		}
		$this->phrase_cache[ $target->ID ] = self::phrases( $focus, $title, $bigrams, $vocab['singles'], $vocab['generic'] );
		return $this->phrase_cache[ $target->ID ];
	}

	/**
	 * Which title/focus words may stand alone as an anchor (rare: in at most
	 * 2 % of posts, minimum 3, and long enough) and which are the site's
	 * dominant terms (in more than 20 % of posts — e.g. the city a local news
	 * site covers — which never count as distinctive). Young sites are judged
	 * like a 50-post site (pure).
	 *
	 * @param string[] $words Lower-case title/focus words.
	 * @param array    $df    Stem => document frequency.
	 * @param int      $count Indexed posts.
	 * @return array{singles:string[],generic:string[]}
	 */
	public static function anchor_vocabulary( array $words, array $df, $count ) {
		$count    = max( 1, (int) $count );
		$rare     = max( 3, (int) ceil( $count * 0.02 ) );
		$dominant = max( $count, ContentIndex::MIN_CORPUS ) * 0.2;
		$out      = array(
			'singles' => array(),
			'generic' => array(),
		);
		foreach ( $words as $word ) {
			$freq = (int) ( $df[ ContentIndex::stem( $word ) ] ?? 1 );
			if ( $freq > $dominant ) {
				$out['generic'][] = $word;
			} elseif ( $freq <= $rare && self::distinctive( $word ) ) {
				$out['singles'][] = $word;
			}
		}
		return $out;
	}

	/**
	 * Whether an in-text anchor follows the anchor rules for a target (see
	 * phrases()): the whole anchor must be one of the target's phrases.
	 *
	 * @param string   $anchor Anchor text.
	 * @param \WP_Post $target Target.
	 * @return bool
	 */
	public function anchor_fits( $anchor, \WP_Post $target ) {
		return self::anchor_matches( $anchor, $this->phrases_for( $target ) );
	}

	/**
	 * Whether a whole anchor text is one of a target's phrases (pure).
	 *
	 * @param string $anchor  Anchor text.
	 * @param array  $phrases Phrases from phrases().
	 * @return bool
	 */
	public static function anchor_matches( $anchor, array $phrases ) {
		$anchor = trim( (string) preg_replace( '/\s+/u', ' ', (string) $anchor ) );
		if ( '' === $anchor ) {
			return false;
		}
		$lower = mb_strtolower( $anchor, 'UTF-8' );
		foreach ( self::candidates( array( array( 'text' => $anchor, 'block' => 0 ) ), $phrases, array(), array(), false ) as $candidate ) {
			if ( mb_strtolower( $candidate['anchor'], 'UTF-8' ) === $lower ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a word is long enough to carry a link on its own.
	 *
	 * @param string $word Lower-cased word.
	 * @return bool
	 */
	public static function distinctive( $word ) {
		$word = (string) $word;
		if ( Text::is_stopword( $word ) || is_numeric( $word ) ) {
			return false;
		}
		if ( ContentIndex::is_indic( $word ) ) {
			return count( ContentIndex::graphemes( $word ) ) >= 3;
		}
		return mb_strlen( $word, 'UTF-8' ) >= 4;
	}

	/**
	 * Builds candidate phrases (pure).
	 *
	 * In-text anchor rules (suggestions and automatic insertion alike):
	 *   1. every content word of a phrase is a word of the target's title or
	 *      focus keyword (word forms match, see candidates()); a phrase never
	 *      starts or ends with a stop word — stop words only between them;
	 *   2. at least two content words, unless the single word is in $singles
	 *      (rare on the site and not its dominant term);
	 *   3. phrases made only of the site's dominant terms ($generic) are none.
	 * When no phrase fits, the target is offered for the related-articles list
	 * instead — an anchor is never padded with neighbouring words.
	 *
	 * @param string     $focus   Focus keyword.
	 * @param string     $title   Title.
	 * @param string[][] $bigrams Stemmed body bigrams (kept only when both stems are title/focus words).
	 * @param string[]   $singles Words allowed as a one-word anchor (lower-case; rare, not dominant).
	 * @param string[]   $generic Title/focus words dominant on the site (lower-case).
	 * @return array<int,array{stems:string[],words?:string[],kind:string,priority:float}>
	 */
	public static function phrases( $focus, $title, array $bigrams = array(), array $singles = array(), array $generic = array() ) {
		$generic = array_flip( array_map( 'strval', $generic ) );
		$singles = array_flip( array_map( 'strval', $singles ) );
		$heads   = array();
		foreach ( ContentIndex::key_words( (string) $title . ' ' . (string) $focus ) as $word ) {
			$heads[ ContentIndex::stem( $word ) ] = isset( $generic[ $word ] );
		}
		$specific = static function ( array $gram ) use ( $generic ) {
			foreach ( $gram as $word ) {
				if ( ! isset( $generic[ $word ] ) && ! Text::is_stopword( $word ) ) {
					return true;
				}
			}
			return false;
		};
		$trim = static function ( array $words ) {
			while ( $words && Text::is_stopword( $words[0] ) ) {
				array_shift( $words );
			}
			while ( $words && Text::is_stopword( $words[ count( $words ) - 1 ] ) ) {
				array_pop( $words );
			}
			return $words;
		};
		$out  = array();
		$seen = array();
		$add  = static function ( array $stems, $kind, $priority, array $words = array() ) use ( &$out, &$seen ) {
			$stems = array_values( array_filter( array_map( 'strval', $stems ), 'strlen' ) );
			$key   = implode( ' ', $stems );
			if ( ! $stems || isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$phrase       = array(
				'stems'    => $stems,
				'kind'     => $kind,
				'priority' => (float) $priority,
			);
			if ( $words && count( $words ) === count( $stems ) ) {
				$phrase['words'] = array_values( array_map( 'strval', $words ) );
			}
			$out[] = $phrase;
		};
		$words = $trim( Text::words( trim( (string) $focus ) ) );
		$n     = count( $words );
		if ( $n >= 2 && $n <= 6 && $specific( $words ) ) {
			$add( array_map( array( ContentIndex::class, 'stem' ), $words ), 'focus', 3, $words );
		} elseif ( 1 === $n && isset( $singles[ $words[0] ] ) ) {
			$add( array( ContentIndex::stem( $words[0] ) ), 'focus', 2.5, $words );
		}
		$words = Text::words( (string) $title );
		$count = count( $words );
		for ( $n = min( 5, $count ); $n >= 2; $n-- ) {
			for ( $i = 0; $i + $n <= $count; $i++ ) {
				$gram = array_slice( $words, $i, $n );
				if ( Text::is_stopword( $gram[0] ) || Text::is_stopword( $gram[ $n - 1 ] ) || ! $specific( $gram ) ) {
					continue;
				}
				$add( array_map( array( ContentIndex::class, 'stem' ), $gram ), 'title', 2 + $n / 10, $gram );
			}
		}
		foreach ( $bigrams as $bigram ) {
			// Body bigrams only when both words are title/focus words and not both dominant.
			if ( 2 === count( $bigram ) && isset( $heads[ $bigram[0] ], $heads[ $bigram[1] ] ) && ! ( $heads[ $bigram[0] ] && $heads[ $bigram[1] ] ) ) {
				$add( $bigram, 'bigram', 1 );
			}
		}
		foreach ( array_keys( $singles ) as $word ) {
			$word = mb_strtolower( (string) $word, 'UTF-8' );
			if ( '' !== $word && self::distinctive( $word ) ) {
				$add( array( ContentIndex::stem( $word ) ), 'word', 0.9, array( $word ) );
			}
		}
		return array_slice( $out, 0, 60 );
	}

	/**
	 * Ranked anchor candidates found in eligible text segments (pure).
	 *
	 * A phrase matches where every word is a form of the phrase word
	 * (ContentIndex::words_match: same English stem, or the same Indic root
	 * with a different suffix), or — for stem-only phrases — the same stem.
	 * The anchor keeps the source's own wording and casing.
	 *
	 * Ranking: fewest existing uses of that anchor for the target (anchor
	 * diversity, no exact-match stuffing), then phrase priority, then position.
	 *
	 * @param array $segments Segments from LinkInserter::segments().
	 * @param array $phrases  Phrases from phrases().
	 * @param array $uses     Anchor (lower-case) => existing uses for the target.
	 * @param array $taken    Anchors already used in this source (lower-case => true).
	 * @param bool  $names    Check that a lone English word is written as a name in the text
	 *                        (false when the segment is only the anchor itself).
	 * @return array<int,array{anchor:string,kind:string,priority:float,length:int,uses:int,block:int,segment:string}>
	 */
	public static function candidates( array $segments, array $phrases, array $uses, array $taken, $names = true ) {
		$found = array();
		$order = 0;
		foreach ( $segments as $segment ) {
			$text  = (string) $segment['text'];
			$words = Text::words( $text );
			if ( ! $words ) {
				continue;
			}
			$stems = array_map( array( ContentIndex::class, 'stem' ), $words );
			foreach ( $phrases as $phrase ) {
				$n = count( $phrase['stems'] );
				foreach ( self::positions( $words, $stems, $phrase ) as $i ) {
					$surface = implode( ' ', array_slice( $words, $i, $n ) );
					if ( false !== strpos( $surface, "'" ) || ! preg_match( Text::phrase_regex( $surface ), $text, $m, PREG_OFFSET_CAPTURE ) ) {
						continue;
					}
					$anchor = trim( (string) preg_replace( '/\s+/u', ' ', $m[0][0] ) );
					$lower  = mb_strtolower( $anchor, 'UTF-8' );
					if ( isset( $taken[ $lower ] ) || isset( $found[ $lower ] ) || ( 1 === $n && ! self::distinctive( $lower ) ) ) {
						continue;
					}
					if ( $names && 'word' === $phrase['kind'] && ! ContentIndex::is_indic( $anchor ) && ! self::is_name_at( $text, (int) $m[0][1], $anchor ) ) {
						continue; // A lone English word links only when it is a name (Chinnaswamy, BBMP), not a common word.
					}
					$used = (int) ( $uses[ $lower ] ?? 0 );
					if ( $used >= self::MAX_ANCHOR_USES ) {
						continue;
					}
					$found[ $lower ] = array(
						'anchor'   => $anchor,
						'kind'     => $phrase['kind'],
						'priority' => $phrase['priority'],
						'length'   => $n,
						'uses'     => $used,
						'block'    => (int) ( $segment['block'] ?? 0 ),
						'segment'  => $text,
						'order'    => $order++,
					);
					break;
				}
			}
		}
		$list = array_values( $found );
		usort(
			$list,
			static function ( $a, $b ) {
				if ( $a['uses'] !== $b['uses'] ) {
					return $a['uses'] <=> $b['uses'];
				}
				if ( $a['priority'] !== $b['priority'] ) {
					return $b['priority'] <=> $a['priority'];
				}
				return $a['order'] <=> $b['order'];
			}
		);
		return $list;
	}

	/**
	 * Whether a word in running text is written as a name: capitalised (or an
	 * acronym) and not merely the first word of a sentence.
	 *
	 * @param string $text   Text.
	 * @param int    $offset Byte offset of the word.
	 * @param string $word   Word as written.
	 * @return bool
	 */
	public static function is_name_at( $text, $offset, $word ) {
		if ( ! preg_match( '/^\p{Lu}/u', (string) $word ) ) {
			return false;
		}
		if ( preg_match( '/^\p{Lu}{2,}$/u', (string) $word ) ) {
			return true; // Acronym.
		}
		$before = rtrim( substr( (string) $text, 0, max( 0, (int) $offset ) ) );
		return '' !== $before && ! preg_match( '/[.!?:;"“”‘’«»(\[–—-]$/u', $before );
	}

	/**
	 * The sentence around an anchor (for the approval UI).
	 *
	 * @param string $text   Paragraph text.
	 * @param string $anchor Anchor.
	 * @return string
	 */
	public static function context_sentence( $text, $anchor ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		foreach ( Text::sentences( $text ) as $sentence ) {
			if ( Text::contains( $sentence, $anchor ) ) {
				$text = $sentence;
				break;
			}
		}
		if ( Text::length( $text ) <= 320 ) {
			return $text;
		}
		$pos = mb_stripos( $text, $anchor, 0, 'UTF-8' );
		if ( false === $pos ) {
			return Text::truncate( $text, 320 );
		}
		$start = max( 0, $pos - 140 );
		$slice = mb_substr( $text, $start, 320, 'UTF-8' );
		return ( $start > 0 ? '…' : '' ) . trim( $slice ) . ( $start + 320 < Text::length( $text ) ? '…' : '' );
	}

	/**
	 * Start positions of a phrase in a token sequence.
	 *
	 * @param string[] $words  Words.
	 * @param string[] $stems  Stems of the words.
	 * @param array    $phrase Phrase (stems, optional words).
	 * @return int[]
	 */
	private static function positions( array $words, array $stems, array $phrase ) {
		$needle = $phrase['stems'];
		$roots  = $phrase['words'] ?? null;
		$n      = count( $needle );
		$h      = count( $stems );
		$out    = array();
		if ( ! $n || $n > $h ) {
			return $out;
		}
		for ( $i = 0; $i <= $h - $n; $i++ ) {
			$ok = true;
			for ( $j = 0; $j < $n; $j++ ) {
				// Phrases with words match word forms (stricter than the index's three-akshara stems).
				$same = null === $roots ? $stems[ $i + $j ] === $needle[ $j ] : ContentIndex::words_match( $words[ $i + $j ], $roots[ $j ] );
				if ( ! $same ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * Linkable text of a source post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{html:string,segments:array,blocks:array,words:int,editable:bool}
	 */
	private function document( \WP_Post $post ) {
		if ( isset( $this->documents[ $post->ID ] ) ) {
			return $this->documents[ $post->ID ];
		}
		$editable = ContentRenderer::is_editable( $post );
		$html     = $editable ? (string) $post->post_content : $this->renderer->render( $post->post_content, $post );
		$info     = LinkInserter::segments( $html );
		$doc      = array(
			'html'     => $html,
			'segments' => $info['segments'],
			'blocks'   => $info['blocks'],
			'words'    => Text::word_count( Text::strip( $editable ? $this->renderer->render( $post->post_content, $post ) : $html ) ),
			'editable' => $editable,
		);
		if ( count( $this->documents ) > 50 ) {
			$this->documents = array();
		}
		$this->documents[ $post->ID ] = $doc;
		return $doc;
	}

	/**
	 * Maximum items in the related-articles list.
	 *
	 * @return int
	 */
	public function related_max() {
		/**
		 * Filters the maximum number of entries in SearchPilot's related-articles list.
		 *
		 * @param int $max Items (default 3).
		 */
		return max( 1, min( 10, (int) apply_filters( 'searchpilot_related_links_max', RelatedBlock::MAX_ITEMS ) ) );
	}

	/**
	 * How many more links a source can take: in the text (one per
	 * words_per_link words, related-list links not counted) and in the
	 * related list; both within max_links_per_post in total. Stubs take none.
	 *
	 * @param \WP_Post $post          Source.
	 * @param array    $doc           Source document.
	 * @param bool     $count_pending Subtract pending suggestions from this source.
	 * @return array{inline:int,related:int}
	 */
	private function room( \WP_Post $post, array $doc, $count_pending = true ) {
		// The bar is low because agglutinative scripts such as Kannada say in 35 words what English needs 80 for.
		if ( $doc['words'] < 20 ) {
			return array(
				'inline'  => 0,
				'related' => 0,
			);
		}
		$max      = max( 1, (int) $this->settings->get( 'links.max_links_per_post', 12 ) );
		$per      = max( 30, (int) $this->settings->get( 'links.words_per_link', 150 ) );
		$internal = (int) $this->graph->internal_count( $post->ID );
		$listed   = count(
			array_filter(
				RelatedBlock::items( (string) $post->post_content ),
				static function ( $item ) {
					return $item['linked'];
				}
			)
		);
		$inline   = min( $max, max( 1, (int) floor( $doc['words'] / $per ) ) ) - max( 0, $internal - $listed );
		$related  = $this->related_max() - $listed;
		$total    = $max - $internal;
		if ( $count_pending ) {
			foreach ( $this->repo->pending_for_source( $post->ID ) as $row ) {
				if ( 'related' === $row['kind'] ) {
					--$related;
				} else {
					--$inline;
				}
				--$total;
			}
		}
		$inline = max( 0, min( $inline, $total ) );
		return array(
			'inline'  => $inline,
			'related' => max( 0, min( $related, $total - $inline ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Insertion
	 * ------------------------------------------------------------------ */

	/**
	 * Inserts a suggested link.
	 *
	 * @param int    $id     Suggestion id.
	 * @param string $source Activity source (user|assisted|autopilot).
	 * @return array|\WP_Error { suggestion, activity_id }
	 */
	public function apply( $id, $source = 'user' ) {
		$s = $this->repo->get( $id );
		if ( ! $s ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'Suggestion not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		if ( 'applied' === $s['status'] ) {
			return new \WP_Error( 'searchpilot_already_applied', __( 'This link was already inserted.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$post   = get_post( $s['source_id'] );
		$target = get_post( $s['target_id'] );
		if ( ! $post || ! $target || 'publish' !== $target->post_status || 'trash' === $post->post_status ) {
			$this->repo->set_status( $s['id'], 'stale' );
			return new \WP_Error( 'searchpilot_stale', __( 'One of the posts no longer exists or is not published.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$builder = ContentRenderer::builder_of( $post );
		if ( '' !== $builder ) {
			return new \WP_Error(
				'searchpilot_manual',
				/* translators: %s: page builder name. */
				sprintf( __( 'This post is built with %s, so SearchPilot cannot edit it safely. Insert the link manually in the page builder.', 'searchpilot-pro' ), ContentRenderer::builder_name( $builder ) ),
				array( 'status' => 409 )
			);
		}
		if ( $this->graph->links_to( $post->ID, $target->ID ) ) {
			$this->repo->set_status( $s['id'], 'stale' );
			return new \WP_Error( 'searchpilot_stale', __( 'The post already links to this page.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( 'related' === $s['kind'] ) {
			$result = $this->apply_related( $post, array( $s ), $source );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'suggestion'  => $this->repo->present( $this->repo->get( $s['id'] ) ),
				'activity_id' => (int) $result['activity_id'],
			);
		}
		if ( ! $this->anchor_fits( (string) $s['anchor'], $target ) ) {
			// E.g. a suggestion stored by an earlier version that padded the anchor with a neighbouring word.
			$this->repo->set_status( $s['id'], 'stale' );
			/* translators: %s: anchor text. */
			return new \WP_Error( 'searchpilot_stale', sprintf( __( 'The link text “%s” does not name the target article closely enough, so the suggestion was marked outdated. Find linking opportunities again for a better one.', 'searchpilot-pro' ), $s['anchor'] ), array( 'status' => 409 ) );
		}
		$new = LinkInserter::insert( (string) $post->post_content, $s['anchor'], (string) get_permalink( $target ), $s['id'] );
		if ( null === $new ) {
			$this->repo->set_status( $s['id'], 'stale' );
			/* translators: %s: anchor text. */
			return new \WP_Error( 'searchpilot_stale', sprintf( __( 'The phrase “%s” is no longer in a paragraph of the post, so the suggestion was marked outdated.', 'searchpilot-pro' ), $s['anchor'] ), array( 'status' => 409 ) );
		}

		// Changes::update_post_content() keeps the post's existing markup (no kses on background writes).
		$activity = $this->changes->update_post_content(
			$post->ID,
			$new,
			array(
				'marker'            => 'data-spp-link="' . (int) $s['id'] . '"',
				'preserve_modified' => true,
				'action'            => 'link_inserted',
				/* translators: 1: anchor text, 2: source title, 3: target title. */
				'description'       => sprintf( __( 'Linked “%1$s” in “%2$s” to “%3$s”', 'searchpilot-pro' ), $s['anchor'], self::plain_title( $post ), self::plain_title( $target ) ),
				'source'            => $source,
			)
		);
		if ( is_wp_error( $activity ) ) {
			$this->repo->set_status( $s['id'], 'failed' );
			$this->logger->warning( 'links', sprintf( 'Link insertion failed for suggestion %d: %s', (int) $s['id'], $activity->get_error_message() ) );
			return $activity;
		}
		$this->repo->set_status( $s['id'], 'applied', (int) $activity );
		unset( $this->documents[ $post->ID ] );
		$this->graph->update_post( $post->ID );
		return array(
			'suggestion'  => $this->repo->present( $this->repo->get( $s['id'] ) ),
			'activity_id' => (int) $activity,
		);
	}

	/**
	 * Adds related-list suggestions of one source in a single edit.
	 *
	 * @param \WP_Post $post   Source post.
	 * @param array[]  $rows   Pending related suggestions of that source.
	 * @param string   $source Activity source.
	 * @return array{activity_id:int,applied:int[]}|\WP_Error
	 */
	public function apply_related( \WP_Post $post, array $rows, $source = 'user' ) {
		if ( ! ContentRenderer::is_editable( $post ) ) {
			return new \WP_Error( 'searchpilot_manual', __( 'This post is built with a page builder, so SearchPilot cannot edit it safely.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$valid = array();
		foreach ( $rows as $row ) {
			$target = get_post( (int) $row['target_id'] );
			if ( ! $target || 'publish' !== $target->post_status || $this->graph->links_to( $post->ID, $target->ID ) ) {
				$this->repo->set_status( $row['id'], 'stale' );
				continue;
			}
			$valid[] = array( $row, $target );
		}
		if ( ! $valid ) {
			return new \WP_Error( 'searchpilot_stale', __( 'The post already links to these pages, or they are no longer published.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$token = 'r' . (int) $valid[0][0]['id'];
		$items = array();
		foreach ( $valid as $pair ) {
			$items[] = array(
				'url'   => (string) get_permalink( $pair[1] ),
				'title' => self::plain_title( $pair[1] ),
				'link'  => $token,
			);
		}
		$result = $this->add_related( $post, (string) $post->post_content, $items );
		if ( ! $result['added'] ) {
			foreach ( $valid as $pair ) {
				$this->repo->set_status( $pair[0]['id'], 'stale' );
			}
			/* translators: %d: maximum entries. */
			return new \WP_Error( 'searchpilot_related_full', sprintf( __( 'The related-articles list of this post already has %d entries, so the suggestion was marked outdated.', 'searchpilot-pro' ), $this->related_max() ), array( 'status' => 409 ) );
		}
		$titles = array();
		foreach ( $valid as $pair ) {
			if ( in_array( (string) get_permalink( $pair[1] ), $result['added'], true ) ) {
				$titles[] = '“' . self::plain_title( $pair[1] ) . '”';
			}
		}
		$activity = $this->changes->update_post_content(
			$post->ID,
			$result['content'],
			array(
				'marker'            => 'data-spp-link="' . $token . '"',
				'preserve_modified' => true,
				'action'            => 'related_link_added',
				/* translators: 1: target titles, 2: source title. */
				'description'       => sprintf( __( 'Added %1$s to the related-articles list of “%2$s”', 'searchpilot-pro' ), implode( ', ', $titles ), self::plain_title( $post ) ),
				'source'            => $source,
			)
		);
		if ( is_wp_error( $activity ) ) {
			foreach ( $valid as $pair ) {
				$this->repo->set_status( $pair[0]['id'], 'failed' );
			}
			$this->logger->warning( 'links', sprintf( 'Related-articles list update failed for post %d: %s', (int) $post->ID, $activity->get_error_message() ) );
			return $activity;
		}
		$applied = array();
		foreach ( $valid as $pair ) {
			if ( in_array( (string) get_permalink( $pair[1] ), $result['added'], true ) ) {
				$this->repo->set_status( $pair[0]['id'], 'applied', (int) $activity );
				$applied[] = (int) $pair[0]['id'];
			} else {
				$this->repo->set_status( $pair[0]['id'], 'stale' ); // The list was full.
			}
		}
		unset( $this->documents[ $post->ID ] );
		$this->graph->update_post( $post->ID );
		return array(
			'activity_id' => (int) $activity,
			'applied'     => $applied,
		);
	}

	/**
	 * Content with targets added to the post's related-articles list (no write).
	 *
	 * @param \WP_Post $post    Source post.
	 * @param string   $content Current content.
	 * @param array    $items   url, title, link.
	 * @return array{content:string,added:string[]}
	 */
	public function add_related( \WP_Post $post, $content, array $items ) {
		return RelatedBlock::add(
			(string) $content,
			$items,
			$this->related_heading( $post ),
			array(
				'max'        => $this->related_max(),
				'list_class' => version_compare( (string) get_bloginfo( 'version' ), '6.6', '>=' ),
			)
		);
	}

	/**
	 * Title of the related-articles list for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function related_heading( \WP_Post $post ) {
		$heading = RelatedBlock::default_heading( Ranker::script( self::plain_title( $post ) . ' ' . wp_trim_words( (string) $post->post_content, 30, '' ) ), __( 'Related articles', 'searchpilot-pro' ) );
		/**
		 * Filters the title of SearchPilot's related-articles list.
		 *
		 * @param string   $heading Title (plain text).
		 * @param \WP_Post $post    Post that gets the list.
		 */
		return (string) apply_filters( 'searchpilot_related_links_heading', $heading, $post );
	}

	/**
	 * Content with in-text links and related-list items added (no write).
	 *
	 * @param \WP_Post $post    Source post.
	 * @param array[]  $options Options (kind, anchor, target_id) from outbound_options()/inbound_options().
	 * @param string   $token   data-spp-link value for the new links.
	 * @return array{content:string,applied:array[]} applied = the options that were placed.
	 */
	public function build_content( \WP_Post $post, array $options, $token ) {
		$content = (string) $post->post_content;
		$applied = array();
		$related = array();
		foreach ( $options as $option ) {
			$target = get_post( (int) $option['target_id'] );
			if ( ! $target ) {
				continue;
			}
			if ( 'inline' === $option['kind'] ) {
				$new = LinkInserter::insert( $content, (string) $option['anchor'], (string) get_permalink( $target ), (string) $token );
				if ( null !== $new ) {
					$content   = $new;
					$applied[] = $option;
				}
				continue;
			}
			$related[ (string) get_permalink( $target ) ] = $option;
		}
		if ( $related ) {
			$items = array();
			foreach ( $related as $url => $option ) {
				$items[] = array(
					'url'   => $url,
					'title' => (string) $option['anchor'],
					'link'  => $token,
				);
			}
			$result  = $this->add_related( $post, $content, $items );
			$content = $result['content'];
			foreach ( $result['added'] as $url ) {
				$applied[] = $related[ $url ];
			}
		}
		return array(
			'content' => $content,
			'applied' => $applied,
		);
	}

	/**
	 * Dismisses a suggestion (never proposed again).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function dismiss( $id ) {
		return $this->repo->set_status( $id, 'dismissed' );
	}

	/**
	 * Inserts pending suggestions automatically when the policy allows it
	 * (at most links.max_auto_per_post per source per run).
	 *
	 * @param int[] $source_ids Source posts.
	 * @return int Links inserted.
	 */
	public function auto_apply( array $source_ids ) {
		if ( ! $this->policy->allows( AutomationPolicy::TASK_INTERNAL_LINKS ) ) {
			return 0;
		}
		$per_post = (int) $this->settings->get( 'links.max_auto_per_post', 3 );
		$applied  = 0;
		foreach ( array_unique( array_map( 'intval', $source_ids ) ) as $source_id ) {
			$applied += $this->auto_apply_source( $source_id, $per_post );
		}
		return $applied;
	}

	/**
	 * Automatic insertion for existing posts: a source never receives more than
	 * links.max_auto_per_post inserted links in total, however often it is visited.
	 *
	 * @param int[] $source_ids Source post ids.
	 * @return int Links inserted.
	 */
	public function auto_apply_capped( array $source_ids ) {
		if ( ! $this->policy->allows( AutomationPolicy::TASK_INTERNAL_LINKS ) ) {
			return 0;
		}
		$per_post = (int) $this->settings->get( 'links.max_auto_per_post', 3 );
		$applied  = 0;
		foreach ( array_unique( array_map( 'intval', $source_ids ) ) as $source_id ) {
			$applied += $this->auto_apply_source( $source_id, $per_post - $this->repo->applied_count( $source_id ) );
		}
		return $applied;
	}

	/**
	 * Applies pending suggestions of one source: in-text links first, then the
	 * related-articles list (one edit) when links.auto_related_block is on.
	 *
	 * @param int $source_id Source.
	 * @param int $room      Links that may still be inserted.
	 * @return int Links inserted.
	 */
	private function auto_apply_source( $source_id, $room ) {
		if ( $room <= 0 || ! ContentRenderer::is_editable( $source_id ) ) {
			return 0;
		}
		$applied = 0;
		$related = array();
		foreach ( $this->repo->pending_for_source( $source_id ) as $s ) {
			if ( 'related' === $s['kind'] ) {
				$related[] = $s;
				continue;
			}
			if ( $room <= 0 ) {
				break;
			}
			$result = $this->apply( $s['id'], $this->policy->source() );
			if ( ! is_wp_error( $result ) ) {
				--$room;
				++$applied;
			}
		}
		if ( $room > 0 && $related && $this->settings->get( 'links.auto_related_block', false ) ) {
			$post = get_post( $source_id );
			if ( $post ) {
				$result = $this->apply_related( $post, array_slice( $related, 0, $room ), $this->policy->source() );
				if ( ! is_wp_error( $result ) ) {
					$applied += count( $result['applied'] );
				}
			}
		}
		return $applied;
	}

	/**
	 * Marks pending suggestions of a source stale when its text changed.
	 *
	 * @param int $source_id Source.
	 * @return int Suggestions marked stale.
	 */
	public function refresh_source( $source_id ) {
		$post = get_post( $source_id );
		if ( ! $post ) {
			return 0;
		}
		unset( $this->documents[ $post->ID ] );
		$pending = $this->repo->pending_for_source( $post->ID );
		if ( ! $pending ) {
			return 0;
		}
		$doc   = $this->document( $post );
		$stale = 0;
		foreach ( $pending as $s ) {
			$gone = $this->graph->links_to( $post->ID, $s['target_id'] );
			if ( ! $gone && 'related' === $s['kind'] ) {
				$gone = ! $doc['editable'];
			} elseif ( ! $gone ) {
				$target = get_post( (int) $s['target_id'] );
				if ( ! $target || ! $this->anchor_fits( (string) $s['anchor'], $target ) ) {
					$gone = true;
				} elseif ( $doc['editable'] ) {
					$gone = null === LinkInserter::insert( $doc['html'], $s['anchor'], (string) get_permalink( $s['target_id'] ), 0 );
				} else {
					$gone = ! Text::contains( implode( ' ', $doc['blocks'] ), $s['anchor'] );
				}
			}
			if ( $gone ) {
				$this->repo->set_status( $s['id'], 'stale' );
				++$stale;
			}
		}
		return $stale;
	}

	/**
	 * After an inserted link or list entry is undone: dismiss the suggestions
	 * (so automation never re-inserts them), drop list entries whose link the
	 * undo removed, and refresh the source's edges.
	 *
	 * @param object $row Activity row.
	 */
	public function on_undo( $row ) {
		if ( ! is_object( $row ) || ! in_array( (string) ( $row->action ?? '' ), array( 'link_inserted', 'related_link_added' ), true ) ) {
			return;
		}
		$source_id = 0;
		foreach ( $this->repo->all_by_activity( (int) $row->id ) as $s ) {
			$this->repo->set_status( $s['id'], 'dismissed' );
			$source_id = (int) $s['source_id'];
		}
		if ( ! $source_id ) {
			$source_id = (int) ( $row->object_id ?? 0 );
		}
		if ( ! $source_id ) {
			return;
		}
		unset( $this->documents[ $source_id ] );
		$this->tidy_related( $source_id );
		$this->graph->update_post( $source_id );
	}

	/**
	 * Removes related-list entries that no longer carry a link (left behind
	 * when an undo had to unwrap links because the post was edited since).
	 *
	 * @param int $post_id Post id.
	 * @return bool Whether the list was rewritten.
	 */
	public function tidy_related( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$content = (string) $post->post_content;
		$items   = RelatedBlock::items( $content );
		$linked  = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return $item['linked'];
				}
			)
		);
		if ( count( $linked ) === count( $items ) ) {
			return false;
		}
		$new = RelatedBlock::replace( $content, $linked, $this->related_heading( $post ), array( 'list_class' => version_compare( (string) get_bloginfo( 'version' ), '6.6', '>=' ) ) );
		if ( $new === $content ) {
			return false;
		}
		$result = $this->changes->update_post_content(
			$post->ID,
			$new,
			array(
				'preserve_modified' => true,
				'action'            => 'related_list_tidied',
				/* translators: %s: post title. */
				'description'       => sprintf( __( 'Removed unlinked entries from the related-articles list of “%s”', 'searchpilot-pro' ), self::plain_title( $post ) ),
				'source'            => 'user',
			)
		);
		return ! is_wp_error( $result );
	}

	/**
	 * Title as plain text (entities decoded).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public static function plain_title( $post ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}

<?php
/**
 * On-page content analysis and the SearchPilot Score.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo\Analysis;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\LinkGraph;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\SimHash;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Two layers:
 *
 *   context( $input ) — gathers everything about a post from WordPress
 *     (rendered content, effective title/description through the owning
 *     SEO plugin, images, link targets, duplicates, Search Console queries);
 *   evaluate( $ctx )  — pure: runs every check on that data and computes
 *     the score. Unit tests call it directly.
 *
 * All text handling is Unicode-aware (Support\Text + ContentIndex::stem):
 * Kannada, Hindi and other Indic scripts are tokenized with their combining
 * marks, inflected forms match their stems, and English-only formulas
 * (Flesch reading ease, slug stop words) are skipped for other scripts.
 */
class Analyzer {

	const GROUPS = array( 'title', 'description', 'content', 'links', 'images', 'technical', 'social', 'schema', 'freshness', 'duplicates' );

	const LARGE_IMAGE_BYTES = 307200;

	/**
	 * Built-in per-post-type detection rules (the `analysis.post_type_rules`
	 * setting is merged over these):
	 *   thin_words     -1 = use content.thin_words, 0 = never judge length, N = threshold;
	 *   featured_image whether a missing featured image is a problem;
	 *   utility        utility pages (Contact, About, legal): short titles are an
	 *                  opportunity, not a failure.
	 */
	const POST_TYPE_RULES = array(
		'page' => array(
			'thin_words'     => 0,
			'featured_image' => false,
			'utility'        => true,
		),
	);

	/**
	 * Rule for post types without an entry.
	 */
	const RULE_DEFAULTS = array(
		'thin_words'     => -1,
		'featured_image' => true,
		'utility'        => false,
	);

	/**
	 * Share of the width Google shows below which a title is short / very short.
	 */
	const TITLE_SHORT    = 0.5;
	// Widths are estimates (no font metrics for Indic scripts) and Google cuts a slightly long
	// title at a word boundary without harm: only a title well beyond the width is reported.
	const TITLE_LONG     = 1.25;
	const TITLE_TOO_SHORT = 0.25;

	/**
	 * Share of the width Google shows below which a description is short / very
	 * short, and above which it is cut off for certain.
	 */
	const DESCRIPTION_SHORT     = 0.7;
	const DESCRIPTION_TOO_SHORT = 0.3;
	const DESCRIPTION_TOO_LONG  = 1.3;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Renderer.
	 *
	 * @var ContentRenderer
	 */
	private $renderer;

	/**
	 * Content table.
	 *
	 * @var AnalysisRepository
	 */
	private $repo;

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
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Link graph (target resolution).
	 *
	 * @var LinkGraph
	 */
	private $graph;

	/**
	 * Constructor.
	 *
	 * @param MetaStore          $meta      Meta store.
	 * @param ContentRenderer    $renderer  Renderer.
	 * @param AnalysisRepository $repo      Content table.
	 * @param Settings           $settings  Settings.
	 * @param ContentTypes       $types     Content types.
	 * @param ConflictDetector   $conflicts Conflict detector.
	 * @param LinkGraph          $graph     Link graph.
	 */
	public function __construct( MetaStore $meta, ContentRenderer $renderer, AnalysisRepository $repo, Settings $settings, ContentTypes $types, ConflictDetector $conflicts, LinkGraph $graph ) {
		$this->meta      = $meta;
		$this->renderer  = $renderer;
		$this->repo      = $repo;
		$this->settings  = $settings;
		$this->types     = $types;
		$this->conflicts = $conflicts;
		$this->graph     = $graph;
	}

	/**
	 * Analyzes a post or unsaved editor content.
	 *
	 * @param array $input { post_id, title, content, excerpt, slug, focus, seo_title, description,
	 *                       canonical, noindex (bool|null), featured_media, post_type } — null/missing
	 *                       fields fall back to the stored post.
	 * @return array Contract response (score, grade, disclaimer, focus, effective, checks, groups, stats, queries) + fingerprint.
	 */
	public function analyze( array $input ) {
		return self::evaluate( $this->context( $input ) );
	}

	/**
	 * The SearchPilot Score disclaimer.
	 *
	 * @return string
	 */
	public static function disclaimer() {
		return __( 'SearchPilot Score is an internal diagnostic of on-page best practices — not a Google or Bing ranking score, and it does not guarantee rankings or traffic.', 'searchpilot-pro' );
	}

	/**
	 * Group labels.
	 *
	 * @return array<string,string>
	 */
	public static function group_labels() {
		return array(
			'title'       => __( 'Title', 'searchpilot-pro' ),
			'description' => __( 'Meta description', 'searchpilot-pro' ),
			'content'     => __( 'Content', 'searchpilot-pro' ),
			'links'       => __( 'Links', 'searchpilot-pro' ),
			'images'      => __( 'Images', 'searchpilot-pro' ),
			'technical'   => __( 'Technical', 'searchpilot-pro' ),
			'social'      => __( 'Social', 'searchpilot-pro' ),
			'schema'      => __( 'Schema', 'searchpilot-pro' ),
			'freshness'   => __( 'Freshness', 'searchpilot-pro' ),
			'duplicates'  => __( 'Duplicates', 'searchpilot-pro' ),
		);
	}

	/**
	 * Context defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_id'            => 0,
			'post_type'          => 'post',
			'status'             => 'draft',
			'title'              => '',
			'seo_title'          => '',
			'description'        => '',
			'description_source' => 'none',
			'description_origin' => '',
			'title_source'       => '',
			'title_origin'       => '',
			'rendered_at'        => null,
			'type_rule'          => null,
			'source_names'       => array(),
			'focus'              => '',
			'slug'               => '',
			'url'                => '',
			'html'               => '',
			'text'               => null,
			'doc'                => null,
			'canonical'          => '',
			'noindex'            => false,
			'featured'           => null,
			'attachments'        => array(),
			'image_ids'          => array(),
			'link_status'        => array(),
			'duplicates'         => null,
			'modified'           => 0,
			'published'          => 0,
			'now'                => time(),
			'thin_words'         => 300,
			'theme_prints_title' => true,
			'owns'               => array(
				'title'       => true,
				'description' => true,
				'social'      => true,
				'schema'      => true,
			),
			'companion'          => '',
			'social'             => array(
				'og_title'       => '',
				'og_description' => '',
				'og_image'       => false,
			),
			'default_image'      => false,
			'schema_type'        => 'Article',
			'faq'                => false,
			'queries'            => null,
			'page_builder'       => '',
			'rendered'           => array(),
			'is_news'            => false,
			'fingerprint'        => array(),
		);
	}

	/* ==================================================================
	 * Pure evaluation
	 * ================================================================== */

	/**
	 * Runs every check on a prepared context.
	 *
	 * @param array $ctx Context (see defaults()).
	 * @return array
	 */
	public static function evaluate( array $ctx ) {
		$ctx = array_merge( self::defaults(), $ctx );
		// Legacy contexts: description_source custom|fallback|none (now the outputting plugin + an origin).
		if ( '' === (string) $ctx['description_origin'] ) {
			$legacy                    = array(
				'custom'   => 'custom',
				'fallback' => 'auto',
				'none'     => 'none',
			);
			$ctx['description_origin'] = $legacy[ (string) $ctx['description_source'] ] ?? 'custom';
			if ( isset( $legacy[ (string) $ctx['description_source'] ] ) && 'none' !== $ctx['description_source'] ) {
				$ctx['description_source'] = 'searchpilot';
			}
		}
		if ( ! is_array( $ctx['type_rule'] ) ) {
			$ctx['type_rule'] = self::post_type_rule( (string) $ctx['post_type'], self::POST_TYPE_RULES );
		}
		$doc   = $ctx['doc'] instanceof HtmlDocument ? $ctx['doc'] : HtmlDocument::parse( '<div>' . (string) $ctx['html'] . '</div>', (string) $ctx['url'] );
		$facts = self::facts( $doc, $ctx );

		$checks = array();
		self::title_checks( $checks, $ctx, $facts );
		self::description_checks( $checks, $ctx, $facts );
		self::content_checks( $checks, $ctx, $facts );
		self::link_checks( $checks, $ctx, $facts );
		self::image_checks( $checks, $ctx, $facts );
		self::technical_checks( $checks, $ctx, $facts );
		self::social_checks( $checks, $ctx, $facts );
		self::schema_checks( $checks, $ctx, $facts );
		self::freshness_checks( $checks, $ctx, $facts );
		self::duplicate_checks( $checks, $ctx, $facts );

		$scored = self::score( $checks );
		return array(
			'score'       => $scored['score'],
			'grade'       => self::grade( $scored['score'] ),
			'disclaimer'  => self::disclaimer(),
			'focus'       => (string) $ctx['focus'],
			'effective'   => array(
				'title'              => (string) $ctx['seo_title'],
				'description'        => (string) $ctx['description'],
				'url'                => (string) $ctx['url'],
				'title_source'       => '' !== trim( (string) $ctx['seo_title'] ) ? ( '' !== (string) $ctx['title_source'] ? (string) $ctx['title_source'] : 'searchpilot' ) : 'none',
				'title_origin'       => (string) $ctx['title_origin'],
				'description_source' => '' !== trim( (string) $ctx['description'] ) ? (string) $ctx['description_source'] : 'none',
				'description_origin' => '' !== trim( (string) $ctx['description'] ) ? (string) $ctx['description_origin'] : 'none',
				'rendered_at'        => $ctx['rendered_at'],
			),
			'checks'      => $checks,
			'groups'      => $scored['groups'],
			'stats'       => $facts['stats'],
			'queries'     => $facts['queries'],
			'fingerprint' => array_merge(
				array(
					'content_hash' => md5( $facts['text'] ),
					'simhash'      => SimHash::of( $facts['text'] ),
				),
				(array) $ctx['fingerprint']
			),
		);
	}

	/**
	 * Grade for a score.
	 *
	 * @param int $score Score.
	 * @return string good|ok|poor
	 */
	public static function grade( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'ok' : 'poor';
	}

	/**
	 * Weighted score: pass = 1, warning = 0.5, fail = 0; info checks are not scored.
	 *
	 * @param array $checks Checks.
	 * @return array{score:int,groups:array}
	 */
	public static function score( array $checks ) {
		$factor = array(
			'pass'    => 1.0,
			'warning' => 0.5,
			'fail'    => 0.0,
		);
		$sum    = array();
		$max    = array();
		foreach ( $checks as $check ) {
			if ( ! isset( $factor[ $check['status'] ] ) || $check['weight'] <= 0 ) {
				continue;
			}
			$g         = $check['group'];
			$max[ $g ] = ( $max[ $g ] ?? 0 ) + $check['weight'];
			$sum[ $g ] = ( $sum[ $g ] ?? 0 ) + $check['weight'] * $factor[ $check['status'] ];
		}
		$labels = self::group_labels();
		$groups = array();
		foreach ( self::GROUPS as $id ) {
			$groups[] = array(
				'id'    => $id,
				'label' => $labels[ $id ],
				'score' => ! empty( $max[ $id ] ) ? (int) round( 100 * $sum[ $id ] / $max[ $id ] ) : null,
			);
		}
		$total = array_sum( $max );
		return array(
			'score'  => $total > 0 ? (int) round( 100 * array_sum( $sum ) / $total ) : 0,
			'groups' => $groups,
		);
	}

	/* ---------- Shared length rules (checks and fixers use the same) ---------- */

	/**
	 * Title length rule: estimated width against the space Google shows
	 * (Text::serp_fit()), so Kannada and other wide scripts are judged by
	 * how much fits, not by Latin character counts.
	 *
	 * @param string $title Title (plain text).
	 * @param array  $rule  Post-type rule (post_type_rule()); `utility` makes a short title an opportunity, not a failure.
	 * @return array{status:string,direction:string,graphemes:int,width:float,limit:float,ratio:float,message:string,recommendation:string,utility:bool}
	 *         status pass|warning|fail; direction ok|short|long.
	 */
	public static function title_length_status( $title, array $rule = array() ) {
		$title   = trim( (string) $title );
		$fit     = Text::serp_fit( $title, 'title' );
		$ratio   = $fit['limit'] > 0 ? round( $fit['width'] / $fit['limit'], 3 ) : 0.0;
		$percent = (int) round( $ratio * 100 );
		$count   = (int) $fit['graphemes'];
		$utility = ! empty( $rule['utility'] );
		$out     = array(
			'status'         => 'pass',
			'direction'      => 'ok',
			'graphemes'      => $count,
			'width'          => (float) $fit['width'],
			'limit'          => (float) $fit['limit'],
			'ratio'          => (float) $ratio,
			'message'        => '',
			'recommendation' => '',
			'utility'        => $utility,
		);
		$utility_tip = __( 'For a page such as Contact, About or Corrections, add the site name so searchers recognise it — for example “Contact — Site name”.', 'searchpilot-pro' );
		if ( ! $fit['fits'] && $ratio <= self::TITLE_LONG ) {
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message'] = sprintf( __( '%1$d characters, about %2$d%% of the width Google shows — the last word or two may be cut off, which is fine.', 'searchpilot-pro' ), $count, $percent );
		} elseif ( ! $fit['fits'] ) {
			$out['status']    = 'warning';
			$out['direction'] = 'long';
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message']        = sprintf( __( 'The title has %1$d characters and needs about %2$d%% of the width Google shows, so it may be cut off in search results.', 'searchpilot-pro' ), $count, $percent );
			$out['recommendation'] = __( 'Put the important words first and shorten the rest: Google shows about 580 pixels — roughly 55–60 Latin characters, fewer in wider scripts such as Kannada.', 'searchpilot-pro' );
		} elseif ( $ratio < self::TITLE_TOO_SHORT ) {
			$out['status']    = $utility ? 'warning' : 'fail';
			$out['direction'] = 'short';
			/* translators: %d: characters. */
			$out['message']        = sprintf( __( 'The title is very short (%d characters).', 'searchpilot-pro' ), $count );
			$out['recommendation'] = $utility ? $utility_tip : __( 'Write a title that says what the page is about and gives a reason to click.', 'searchpilot-pro' );
		} elseif ( $ratio < self::TITLE_SHORT && ! $utility ) {
			// Utility pages pass from TITLE_TOO_SHORT: "Contact — Site name" is the right title there.
			$out['status']    = 'warning';
			$out['direction'] = 'short';
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message']        = sprintf( __( 'The title is short (%1$d characters, about %2$d%% of the width Google shows).', 'searchpilot-pro' ), $count, $percent );
			$out['recommendation'] = __( 'Use more of the space so the title describes the page fully: the topic plus a reason to click.', 'searchpilot-pro' );
		} else {
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message'] = sprintf( __( '%1$d characters, about %2$d%% of the width Google shows — a good length.', 'searchpilot-pro' ), $count, $percent );
		}
		return $out;
	}

	/**
	 * Description length rule (see title_length_status()).
	 *
	 * @param string $desc Description (plain text).
	 * @param array  $rule Post-type rule (unused today; kept for symmetry).
	 * @return array{status:string,direction:string,graphemes:int,width:float,limit:float,ratio:float,message:string,recommendation:string}
	 */
	public static function description_length_status( $desc, array $rule = array() ) {
		unset( $rule );
		$desc    = trim( (string) $desc );
		$fit     = Text::serp_fit( $desc, 'description' );
		$ratio   = $fit['limit'] > 0 ? round( $fit['width'] / $fit['limit'], 3 ) : 0.0;
		$percent = (int) round( $ratio * 100 );
		$count   = (int) $fit['graphemes'];
		$out     = array(
			'status'         => 'pass',
			'direction'      => 'ok',
			'graphemes'      => $count,
			'width'          => (float) $fit['width'],
			'limit'          => (float) $fit['limit'],
			'ratio'          => (float) $ratio,
			'message'        => '',
			'recommendation' => '',
		);
		$long_tip  = __( 'Keep the key point first and shorten the rest: Google shows about 990 pixels — roughly 150–160 Latin characters, about 100 in Kannada.', 'searchpilot-pro' );
		$short_tip = __( 'Use more of the space to summarise the page and invite the click: about 150–160 Latin characters, about 100 in Kannada.', 'searchpilot-pro' );
		if ( $ratio > self::DESCRIPTION_TOO_LONG ) {
			$out['status']    = 'fail';
			$out['direction'] = 'long';
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message']        = sprintf( __( 'The description is very long (%1$d characters, about %2$d%% of the width Google shows) and will be cut off.', 'searchpilot-pro' ), $count, $percent );
			$out['recommendation'] = $long_tip;
		} elseif ( ! $fit['fits'] ) {
			$out['status']    = 'warning';
			$out['direction'] = 'long';
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message']        = sprintf( __( 'The description has %1$d characters and needs about %2$d%% of the width Google shows, so it may be cut off.', 'searchpilot-pro' ), $count, $percent );
			$out['recommendation'] = $long_tip;
		} elseif ( $ratio < self::DESCRIPTION_TOO_SHORT ) {
			$out['status']    = 'fail';
			$out['direction'] = 'short';
			/* translators: %d: characters. */
			$out['message']        = sprintf( __( 'The description is very short (%d characters).', 'searchpilot-pro' ), $count );
			$out['recommendation'] = $short_tip;
		} elseif ( $ratio < self::DESCRIPTION_SHORT ) {
			$out['status']    = 'warning';
			$out['direction'] = 'short';
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message']        = sprintf( __( 'The description is a little short (%1$d characters, about %2$d%% of the width Google shows).', 'searchpilot-pro' ), $count, $percent );
			$out['recommendation'] = $short_tip;
		} else {
			/* translators: 1: characters, 2: percentage of the width Google shows. */
			$out['message'] = sprintf( __( '%1$d characters, about %2$d%% of the width Google shows — a good length.', 'searchpilot-pro' ), $count, $percent );
		}
		return $out;
	}

	/**
	 * Detection rule of a post type: built-in defaults (POST_TYPE_RULES)
	 * with the `analysis.post_type_rules` setting merged over them.
	 *
	 * @param string     $post_type Post type.
	 * @param array|null $rules     Rules map (null = read the setting).
	 * @return array{thin_words:int,featured_image:bool,utility:bool}
	 */
	public static function post_type_rule( $post_type, $rules = null ) {
		if ( ! is_array( $rules ) ) {
			$rules = self::POST_TYPE_RULES;
			if ( function_exists( 'did_action' ) && class_exists( '\SearchPilot\Plugin' ) ) {
				try {
					$rules = (array) \SearchPilot\Plugin::instance()->get( Settings::class )->get( 'analysis.post_type_rules', self::POST_TYPE_RULES );
				} catch ( \Throwable $e ) {
					$rules = self::POST_TYPE_RULES;
				}
			}
		}
		$rule = self::RULE_DEFAULTS;
		foreach ( array( self::POST_TYPE_RULES, $rules ) as $set ) {
			if ( isset( $set[ $post_type ] ) && is_array( $set[ $post_type ] ) ) {
				$rule = array_merge( $rule, array_intersect_key( $set[ $post_type ], self::RULE_DEFAULTS ) );
			}
		}
		return array(
			'thin_words'     => max( -1, (int) $rule['thin_words'] ),
			'featured_image' => (bool) $rule['featured_image'],
			'utility'        => (bool) $rule['utility'],
		);
	}

	/**
	 * Display name of a title/description source.
	 *
	 * @param string $source    Source id.
	 * @param string $companion Companion plugin name (fallback).
	 * @return string
	 */
	private static function source_name( $source, $companion = '' ) {
		$names = array(
			'rank_math'   => 'Rank Math SEO',
			'yoast'       => 'Yoast SEO',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
			'tsf'         => 'The SEO Framework',
			'searchpilot' => 'SearchPilot',
			'wordpress'   => 'WordPress',
		);
		if ( isset( $names[ $source ] ) ) {
			return $names[ $source ];
		}
		return '' !== $companion ? $companion : __( 'Another plugin', 'searchpilot-pro' );
	}

	/**
	 * Parses the document into the facts the checks use.
	 *
	 * @param HtmlDocument $doc Document.
	 * @param array        $ctx Context.
	 * @return array
	 */
	private static function facts( HtmlDocument $doc, array $ctx ) {
		$base  = (string) $ctx['url'];
		$links = array();
		foreach ( $doc->query( '//a[@href]' ) as $node ) {
			/** @var \DOMElement $node */
			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '#^(mailto|tel|javascript|sms|data):#i', $href ) ) {
				continue;
			}
			$url  = Url::absolute( $href, '' !== $base ? $base : '' );
			$text = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
			if ( '' === $text ) {
				$img  = $node->getElementsByTagName( 'img' )->item( 0 );
				$text = $img ? trim( (string) $img->getAttribute( 'alt' ) ) : '';
			}
			$internal = Url::is_internal( $url );
			if ( $internal && '' !== $base && Url::normalize( Url::strip_query( $url ) ) === Url::normalize( Url::strip_query( $base ) ) ) {
				continue; // Link to the page itself.
			}
			$links[] = array(
				'url'      => $url,
				'text'     => $text,
				'rel'      => array_filter( (array) preg_split( '/\s+/', strtolower( $node->getAttribute( 'rel' ) ) ) ),
				'target'   => strtolower( trim( $node->getAttribute( 'target' ) ) ),
				'internal' => $internal,
			);
		}

		$images = array();
		foreach ( $doc->query( '//img' ) as $node ) {
			/** @var \DOMElement $node */
			$src = $node->getAttribute( 'src' ) ?: ( $node->getAttribute( 'data-src' ) ?: $node->getAttribute( 'data-lazy-src' ) );
			if ( '' === trim( $src ) ) {
				continue;
			}
			$src = Url::absolute( trim( $src ), $base );
			$id  = 0;
			if ( preg_match( '/\bwp-image-(\d+)\b/', $node->getAttribute( 'class' ), $m ) ) {
				$id = (int) $m[1];
			} elseif ( $node->getAttribute( 'data-id' ) && ctype_digit( $node->getAttribute( 'data-id' ) ) ) {
				$id = (int) $node->getAttribute( 'data-id' );
			} elseif ( isset( $ctx['image_ids'][ $src ] ) ) {
				$id = (int) $ctx['image_ids'][ $src ];
			}
			$images[] = array(
				'src'    => $src,
				'alt'    => $node->hasAttribute( 'alt' ) ? trim( $node->getAttribute( 'alt' ) ) : null,
				'srcset' => '' !== $node->getAttribute( 'srcset' ),
				'id'     => $id,
			);
		}

		$paragraphs = array();
		foreach ( $doc->query( '//p' ) as $node ) {
			$p = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
			if ( '' !== $p ) {
				$paragraphs[] = $p;
			}
		}
		$headings = $doc->headings();
		$text     = null !== $ctx['text'] ? (string) $ctx['text'] : $doc->text();
		$words    = Text::words( $text );
		$count    = Text::word_count( $text );
		$tokens   = array_map( array( ContentIndex::class, 'stem' ), $words );
		$latin    = Text::is_latin( $text );
		$focus    = trim( (string) $ctx['focus'] );
		$ftokens  = '' !== $focus ? ContentIndex::stems( $focus ) : array();

		$occurrences = $ftokens ? count( self::positions( $tokens, $ftokens ) ) : 0;
		$density     = ( $ftokens && $count ) ? round( 100 * $occurrences * count( $ftokens ) / $count, 2 ) : 0.0;
		$sentences   = Text::sentences( $text );

		$levels = array(
			'h1' => 0,
			'h2' => 0,
			'h3' => 0,
			'h4' => 0,
			'h5' => 0,
			'h6' => 0,
		);
		foreach ( $headings as $heading ) {
			++$levels[ 'h' . $heading['level'] ];
		}
		$missing_alt = 0;
		foreach ( $images as $image ) {
			if ( null === $image['alt'] || '' === $image['alt'] ) {
				++$missing_alt;
			}
		}
		$internal = 0;
		$external = 0;
		foreach ( $links as $link ) {
			if ( $link['internal'] ) {
				++$internal;
			} else {
				++$external;
			}
		}
		$flesch = null;
		if ( $latin && $count >= 100 && count( $sentences ) >= 3 ) {
			$flesch = self::flesch( $words, count( $sentences ) );
		}

		$facts = array(
			'links'       => $links,
			'images'      => $images,
			'paragraphs'  => $paragraphs,
			'headings'    => $headings,
			'text'        => $text,
			'words'       => $words,
			'count'       => $count,
			'tokens'      => $tokens,
			'latin'       => $latin,
			'focus'       => $focus,
			'ftokens'     => $ftokens,
			'occurrences' => $occurrences,
			'density'     => $density,
			'sentences'   => $sentences,
			'flesch'      => $flesch,
		);

		$facts['queries'] = self::query_coverage( $ctx, $facts );
		$facts['stats']   = array(
			'words'              => $count,
			'sentences'          => count( $sentences ),
			'paragraphs'         => count( $paragraphs ),
			'reading_time'       => $count ? max( 1, (int) ceil( $count / 200 ) ) : 0,
			'headings'           => $levels,
			'internal_links'     => $internal,
			'external_links'     => $external,
			'images'             => count( $images ),
			'images_missing_alt' => $missing_alt,
			'focus_density'      => $density,
			'focus_occurrences'  => $occurrences,
			'flesch'             => $flesch,
			'latin'              => $latin,
			'page_builder'       => (string) $ctx['page_builder'],
			// Heading sequence the heading_structure check judged (document order).
			'outline'            => array_map(
				static function ( $h ) {
					return array(
						'level' => (int) $h['level'],
						'empty' => '' === trim( (string) $h['text'] ),
					);
				},
				array_values( $headings )
			),
		);
		return $facts;
	}

	/* ---------- Title ---------- */

	/**
	 * Title checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function title_checks( array &$checks, array $ctx, array $facts ) {
		$title = trim( (string) $ctx['seo_title'] );
		if ( '' === $title ) {
			$checks[] = self::check( 'title_present', 'title', __( 'SEO title', 'searchpilot-pro' ), 'fail', __( 'The page has no title for search results.', 'searchpilot-pro' ), __( 'Write a descriptive title that says what the page is about and fits the width Google shows (about 55–60 Latin characters, fewer in wider scripts such as Kannada).', 'searchpilot-pro' ), 8 );
			return;
		}
		/* translators: %s: title. */
		$message = sprintf( __( 'Search results show: “%s”.', 'searchpilot-pro' ), $title );
		if ( 'rendered' === $ctx['title_source'] ) {
			$message .= ' ' . __( '(Read from the published page.)', 'searchpilot-pro' );
		}
		$checks[] = self::check( 'title_present', 'title', __( 'SEO title', 'searchpilot-pro' ), 'pass', $message, '', 8 );

		$length   = self::title_length_status( $title, (array) $ctx['type_rule'] );
		$checks[] = self::check( 'title_length', 'title', __( 'Title length', 'searchpilot-pro' ), $length['status'], $length['message'], $length['recommendation'], 5, array(), $length );

		if ( $facts['ftokens'] ) {
			$tokens    = ContentIndex::stems( $title );
			$positions = self::positions( $tokens, $facts['ftokens'] );
			if ( ! $positions ) {
				/* translators: %s: focus keyword. */
				$checks[] = self::check( 'title_focus', 'title', __( 'Focus keyword in title', 'searchpilot-pro' ), 'fail', __( 'The focus keyword does not appear in the title.', 'searchpilot-pro' ), sprintf( __( 'Include “%s” in the title, ideally near the start.', 'searchpilot-pro' ), $facts['focus'] ), 5 );
			} elseif ( $positions[0] <= max( 1, (int) floor( count( $tokens ) / 2 ) ) ) {
				$checks[] = self::check( 'title_focus', 'title', __( 'Focus keyword in title', 'searchpilot-pro' ), 'pass', __( 'The focus keyword appears near the start of the title.', 'searchpilot-pro' ), '', 5 );
			} else {
				$checks[] = self::check( 'title_focus', 'title', __( 'Focus keyword in title', 'searchpilot-pro' ), 'warning', __( 'The focus keyword appears late in the title.', 'searchpilot-pro' ), __( 'Move the focus keyword nearer the start of the title where it reads naturally.', 'searchpilot-pro' ), 5 );
			}
		}

		$current = (int) gmdate( 'Y', (int) $ctx['now'] );
		$years   = self::years( $title );
		$past    = array_filter(
			$years,
			static function ( $y ) use ( $current ) {
				return $y < $current && $y >= $current - 30;
			}
		);
		if ( $past && ! in_array( $current, $years, true ) ) {
			/* translators: 1: year in the title, 2: current year. */
			$checks[] = self::check( 'title_year', 'title', __( 'Year in title', 'searchpilot-pro' ), 'warning', sprintf( __( 'The title mentions %1$s, but it is now %2$d.', 'searchpilot-pro' ), implode( ', ', $past ), $current ), __( 'If the content is still current, update it and the year in the title; otherwise leave historical years as they are.', 'searchpilot-pro' ), 2 );
		}
	}

	/* ---------- Description ---------- */

	/**
	 * Description checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function description_checks( array &$checks, array $ctx, array $facts ) {
		$desc   = trim( (string) $ctx['description'] );
		$source = (string) $ctx['description_source'];
		$origin = (string) $ctx['description_origin'];
		$label  = __( 'Meta description', 'searchpilot-pro' );
		$owner  = isset( $ctx['source_names'][ $source ] ) ? (string) $ctx['source_names'][ $source ] : self::source_name( $source, (string) $ctx['companion'] );
		if ( '' === $desc && 'unknown' === $origin ) {
			/* translators: %s: plugin name. */
			$checks[] = self::check( 'description_present', 'description', $label, 'info', sprintf( __( '%s outputs the meta description on this site. SearchPilot reads it from the published page after the next crawl.', 'searchpilot-pro' ), $owner ), '', 0 );
			return;
		}
		if ( '' === $desc || 'none' === $source ) {
			$checks[] = self::check( 'description_present', 'description', $label, 'fail', __( 'The page has no meta description, so search engines choose a snippet themselves.', 'searchpilot-pro' ), __( 'Write a one- or two-sentence summary that answers the searcher’s question and invites the click (about 150 Latin characters; fewer in wider scripts such as Kannada).', 'searchpilot-pro' ), 6 );
			return;
		}
		if ( 'auto' === $origin ) {
			$checks[] = self::check( 'description_present', 'description', $label, 'pass', __( 'Automatic description from the opening text. It follows the article when you edit it.', 'searchpilot-pro' ), __( 'Optional: write your own description when you want a specific snippet (it will then no longer update with the text).', 'searchpilot-pro' ), 6 );
		} elseif ( 'template' === $origin ) {
			/* translators: %s: plugin name. */
			$checks[] = self::check( 'description_present', 'description', $label, 'pass', sprintf( __( '%s builds the description from its template for this content type (usually the excerpt), so it follows the article.', 'searchpilot-pro' ), $owner ), __( 'Optional: write a description for this post when you want a specific snippet.', 'searchpilot-pro' ), 6 );
		} elseif ( 'unknown' === $origin || 'rendered' === $source ) {
			$checks[] = self::check( 'description_present', 'description', $label, 'pass', __( 'The published page serves a meta description.', 'searchpilot-pro' ), '', 6 );
		} else {
			$checks[] = self::check( 'description_present', 'description', $label, 'pass', __( 'A meta description is set.', 'searchpilot-pro' ), '', 6 );
		}

		$length   = self::description_length_status( $desc, (array) $ctx['type_rule'] );
		$checks[] = self::check(
			'description_length',
			'description',
			__( 'Description length', 'searchpilot-pro' ),
			$length['status'],
			$length['message'],
			$length['recommendation'],
			4,
			array(),
			array_merge(
				$length,
				array(
					'source' => $source,
					'origin' => $origin,
				)
			)
		);

		if ( $facts['ftokens'] ) {
			if ( self::positions( ContentIndex::stems( $desc ), $facts['ftokens'] ) ) {
				$checks[] = self::check( 'description_focus', 'description', __( 'Focus keyword in description', 'searchpilot-pro' ), 'pass', __( 'The description mentions the focus keyword.', 'searchpilot-pro' ), '', 3 );
			} else {
				$checks[] = self::check( 'description_focus', 'description', __( 'Focus keyword in description', 'searchpilot-pro' ), 'warning', __( 'The description does not mention the focus keyword.', 'searchpilot-pro' ), __( 'Search engines bold matching words in the snippet — mention the topic naturally.', 'searchpilot-pro' ), 3 );
			}
		}
	}

	/* ---------- Content ---------- */

	/**
	 * Content checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function content_checks( array &$checks, array $ctx, array $facts ) {
		$words = (int) $facts['count'];
		$rule  = (array) $ctx['type_rule'];
		$thin  = isset( $rule['thin_words'] ) && (int) $rule['thin_words'] >= 0 ? (int) $rule['thin_words'] : max( 50, (int) $ctx['thin_words'] );
		// Page builders: the crawled page may show more text than the stored data reveals.
		if ( '' !== $ctx['page_builder'] && ! empty( $ctx['rendered']['words'] ) && (int) $ctx['rendered']['words'] > $words ) {
			$words = (int) $ctx['rendered']['words'];
		}
		$label = __( 'Content depth', 'searchpilot-pro' );
		$data  = array(
			'words'     => $words,
			'threshold' => $thin,
			'post_type' => (string) $ctx['post_type'],
		);
		if ( 0 === $thin ) {
			/* translators: %s: number of words. */
			$checks[] = self::check( 'content_length', 'content', $label, 'info', sprintf( __( '%s words. Length is not judged for this content type: pages such as Contact or Corrections are short by design.', 'searchpilot-pro' ), number_format( $words ) ), '', 0, array(), $data );
		} elseif ( $words >= $thin ) {
			/* translators: %s: number of words. */
			$checks[] = self::check( 'content_length', 'content', $label, 'pass', sprintf( __( '%s words.', 'searchpilot-pro' ), number_format( $words ) ), '', 5, array(), $data );
		} elseif ( ! empty( $ctx['is_news'] ) ) {
			/* translators: %s: number of words. */
			$checks[] = self::check( 'content_length', 'content', $label, 'info', sprintf( __( 'News report with %s words. Short updates are normal for news; what matters is that the facts are complete and clear.', 'searchpilot-pro' ), number_format( $words ) ), '', 0, array(), $data );
		} else {
			/* translators: 1: words, 2: site guideline. */
			$checks[] = self::check( 'content_length', 'content', $label, 'warning', sprintf( __( 'Short page: %1$s words (the site guideline is %2$s). Length alone is not a ranking factor.', 'searchpilot-pro' ), number_format( $words ), number_format( $thin ) ), __( 'Check that the page fully answers what visitors come for — details, examples, context. Do not pad the text to reach a word count.', 'searchpilot-pro' ), 5, array(), $data );
		}

		if ( ! $facts['ftokens'] ) {
			$checks[] = self::check( 'focus_set', 'content', __( 'Focus keyword', 'searchpilot-pro' ), 'warning', __( 'No focus keyword or topic is set, so topic checks are skipped.', 'searchpilot-pro' ), __( 'Set the main phrase people search for to find this page.', 'searchpilot-pro' ), 3 );
		} else {
			self::focus_checks( $checks, $ctx, $facts );
		}

		if ( is_array( $ctx['queries'] ) && $facts['queries'] ) {
			$top       = array_slice( $facts['queries'], 0, 5 );
			$uncovered = array();
			foreach ( $top as $q ) {
				if ( ! $q['covered'] ) {
					$uncovered[] = $q['query'];
				}
			}
			if ( $uncovered ) {
				/* translators: %s: list of search queries. */
				$checks[] = self::check( 'query_coverage', 'content', __( 'Search queries covered', 'searchpilot-pro' ), 'warning', sprintf( __( 'People find this page with searches the text does not address: %s.', 'searchpilot-pro' ), implode( ', ', $uncovered ) ), __( 'Where it fits the page, answer these searches in a section or subheading.', 'searchpilot-pro' ), 3, $uncovered );
			} else {
				$checks[] = self::check( 'query_coverage', 'content', __( 'Search queries covered', 'searchpilot-pro' ), 'pass', __( 'The top Search Console queries for this page are covered in the title, headings or text.', 'searchpilot-pro' ), '', 3 );
			}
		}

		// Paragraph length.
		if ( $facts['paragraphs'] ) {
			$long = 0;
			foreach ( $facts['paragraphs'] as $p ) {
				if ( Text::word_count( $p ) > 150 ) {
					++$long;
				}
			}
			if ( $long ) {
				/* translators: %d: number of paragraphs. */
				$checks[] = self::check( 'paragraph_length', 'content', __( 'Paragraph length', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d paragraph is longer than 150 words.', '%d paragraphs are longer than 150 words.', $long, 'searchpilot-pro' ), $long ), __( 'Split long paragraphs so the text is easier to scan, especially on phones.', 'searchpilot-pro' ), 2 );
			} else {
				$checks[] = self::check( 'paragraph_length', 'content', __( 'Paragraph length', 'searchpilot-pro' ), 'pass', __( 'Paragraphs are a comfortable length.', 'searchpilot-pro' ), '', 2 );
			}
		}

		// Sentence length.
		$sentences = $facts['sentences'];
		if ( count( $sentences ) >= 5 ) {
			$limit = $facts['latin'] ? 25 : 20;
			$long  = 0;
			foreach ( $sentences as $s ) {
				if ( Text::word_count( $s ) > $limit ) {
					++$long;
				}
			}
			$share = $long / count( $sentences );
			if ( $share > 0.25 ) {
				/* translators: 1: percentage of sentences, 2: words per sentence. */
				$checks[] = self::check( 'sentence_length', 'content', __( 'Sentence length', 'searchpilot-pro' ), 'warning', sprintf( __( '%1$d%% of sentences have more than %2$d words.', 'searchpilot-pro' ), (int) round( $share * 100 ), $limit ), __( 'Shorten or split long sentences.', 'searchpilot-pro' ), 2 );
			} else {
				$checks[] = self::check( 'sentence_length', 'content', __( 'Sentence length', 'searchpilot-pro' ), 'pass', __( 'Most sentences are easy to follow.', 'searchpilot-pro' ), '', 2 );
			}
		}

		// Readability (Latin script only).
		if ( null !== $facts['flesch'] ) {
			$f = $facts['flesch'];
			/* translators: %s: Flesch reading ease score. */
			$message = sprintf( __( 'Flesch reading ease: %s.', 'searchpilot-pro' ), number_format( $f, 1 ) );
			if ( $f >= 60 ) {
				$checks[] = self::check( 'readability', 'content', __( 'Readability', 'searchpilot-pro' ), 'pass', $message . ' ' . __( 'Easy to read.', 'searchpilot-pro' ), '', 3 );
			} else {
				$checks[] = self::check( 'readability', 'content', __( 'Readability', 'searchpilot-pro' ), 'warning', $message . ' ' . ( $f >= 30 ? __( 'Fairly difficult to read.', 'searchpilot-pro' ) : __( 'Difficult to read.', 'searchpilot-pro' ) ), __( 'Use shorter sentences and everyday words.', 'searchpilot-pro' ), 3 );
			}
		} elseif ( ! $facts['latin'] && $words >= 50 ) {
			$checks[] = self::check( 'readability', 'content', __( 'Readability', 'searchpilot-pro' ), 'info', __( 'Reading-ease formulas such as Flesch only work for English-like text, so this check is skipped for this language.', 'searchpilot-pro' ), '', 0 );
		}

		self::heading_checks( $checks, $ctx, $facts, $words );
		self::slug_check( $checks, $ctx, $facts );
	}

	/**
	 * Focus keyword checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function focus_checks( array &$checks, array $ctx, array $facts ) {
		$focus = $facts['focus'];
		$ft    = $facts['ftokens'];

		// Introduction: first paragraph or first 10% of the text.
		$intro_tokens = $facts['paragraphs'] ? ContentIndex::stems( $facts['paragraphs'][0] ) : array();
		$tenth        = array_slice( $facts['tokens'], 0, max( count( $ft ), (int) ceil( count( $facts['tokens'] ) * 0.1 ) ) );
		if ( self::positions( $intro_tokens, $ft ) || self::positions( $tenth, $ft ) ) {
			$checks[] = self::check( 'focus_intro', 'content', __( 'Focus keyword in introduction', 'searchpilot-pro' ), 'pass', __( 'The introduction mentions the focus keyword.', 'searchpilot-pro' ), '', 4 );
		} else {
			$checks[] = self::check( 'focus_intro', 'content', __( 'Focus keyword in introduction', 'searchpilot-pro' ), 'warning', __( 'The introduction does not mention the focus keyword.', 'searchpilot-pro' ), __( 'Say what the page is about in the first paragraph.', 'searchpilot-pro' ), 4 );
		}

		// Subheadings.
		$subs = array_filter(
			$facts['headings'],
			static function ( $h ) {
				return $h['level'] >= 2;
			}
		);
		if ( $subs ) {
			$keywords = ContentIndex::keywords( $focus );
			$found    = false;
			foreach ( $subs as $h ) {
				$tokens = ContentIndex::stems( $h['text'] );
				if ( self::positions( $tokens, $ft ) || ( count( $keywords ) > 1 && count( array_intersect( $keywords, $tokens ) ) >= (int) ceil( count( $keywords ) / 2 ) ) ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				$checks[] = self::check( 'focus_subheadings', 'content', __( 'Focus topic in subheadings', 'searchpilot-pro' ), 'pass', __( 'At least one subheading covers the focus topic.', 'searchpilot-pro' ), '', 3 );
			} else {
				$checks[] = self::check( 'focus_subheadings', 'content', __( 'Focus topic in subheadings', 'searchpilot-pro' ), 'warning', __( 'No subheading mentions the focus topic.', 'searchpilot-pro' ), __( 'Use the topic (or a variation) in a subheading where it helps readers.', 'searchpilot-pro' ), 3 );
			}
		}

		// Density: only excessive repetition is reported (search engines do not reward a target density).
		$density = $facts['density'];
		$label   = __( 'Keyword repetition', 'searchpilot-pro' );
		if ( 0 === $facts['occurrences'] ) {
			$checks[] = self::check( 'focus_density', 'content', $label, 'info', __( 'The exact focus phrase is not used in the text. That is fine when the text covers the topic in its own words.', 'searchpilot-pro' ), '', 0 );
		} elseif ( $density > 3 && $facts['occurrences'] >= 3 && $facts['count'] >= 100 ) {
			/* translators: %s: density percentage. */
			$checks[] = self::check( 'focus_density', 'content', $label, 'warning', sprintf( __( 'Keyword density is %s%% — possible keyword stuffing; write naturally.', 'searchpilot-pro' ), number_format( $density, 1 ) ), __( 'Replace some repetitions with synonyms or pronouns.', 'searchpilot-pro' ), 4 );
		} else {
			/* translators: 1: occurrences, 2: density percentage. */
			$checks[] = self::check( 'focus_density', 'content', $label, 'pass', sprintf( __( 'The focus keyword appears %1$d times (%2$s%%) — no sign of keyword stuffing.', 'searchpilot-pro' ), $facts['occurrences'], number_format( $density, 1 ) ), '', 4 );
		}

		// Topic coverage: the individual words of a multi-word topic.
		$keywords = array_values( array_unique( ContentIndex::keywords( $focus ) ) );
		if ( count( $keywords ) > 1 ) {
			$present = array_flip( $facts['tokens'] );
			$missing = array();
			foreach ( Text::words( $focus ) as $word ) {
				if ( ! Text::is_stopword( $word ) && ! isset( $present[ ContentIndex::stem( $word ) ] ) ) {
					$missing[] = $word;
				}
			}
			$missing = array_values( array_unique( $missing ) );
			if ( ! $missing ) {
				$checks[] = self::check( 'topic_coverage', 'content', __( 'Topic coverage', 'searchpilot-pro' ), 'pass', __( 'The text covers every part of the focus topic.', 'searchpilot-pro' ), '', 3 );
			} else {
				$status = count( $missing ) >= count( $keywords ) ? 'fail' : 'warning';
				/* translators: %s: missing words. */
				$checks[] = self::check( 'topic_coverage', 'content', __( 'Topic coverage', 'searchpilot-pro' ), $status, sprintf( __( 'Parts of the topic never appear in the text: %s.', 'searchpilot-pro' ), implode( ', ', $missing ) ), __( 'Cover each aspect of the topic the reader expects.', 'searchpilot-pro' ), 3, $missing );
			}
		}
	}

	/**
	 * Heading checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 * @param int   $words  Word count.
	 */
	private static function heading_checks( array &$checks, array $ctx, array $facts, $words ) {
		$headings = $facts['headings'];
		$problems = array();
		$previous = 1; // The page title is the H1.
		$skipped  = array();
		$empty    = 0;
		$subs     = 0;
		foreach ( $headings as $h ) {
			if ( '' === trim( $h['text'] ) ) {
				++$empty;
			}
			if ( $h['level'] >= 2 ) {
				++$subs;
			}
			if ( $h['level'] > $previous + 1 ) {
				$skipped[] = 'H' . $previous . ' → H' . $h['level'];
			}
			$previous = $h['level'];
		}
		// Utility pages (About, Contact, legal) are not judged on their outline.
		$utility = ! empty( $ctx['type_rule']['utility'] );
		if ( $skipped && ! $utility ) {
			/* translators: %s: list of skipped heading levels, e.g. H2 → H4. */
			$problems[] = sprintf( __( 'Heading levels are skipped (%s).', 'searchpilot-pro' ), implode( ', ', array_unique( $skipped ) ) );
		}
		if ( $empty ) {
			/* translators: %d: number of headings. */
			$problems[] = sprintf( _n( '%d heading is empty.', '%d headings are empty.', $empty, 'searchpilot-pro' ), $empty );
		}
		if ( ! $subs && $words > 300 && ! $utility ) {
			$problems[] = __( 'The text has no subheadings.', 'searchpilot-pro' );
		}
		if ( $problems ) {
			$checks[] = self::check( 'heading_structure', 'content', __( 'Heading structure', 'searchpilot-pro' ), 'warning', implode( ' ', $problems ), __( 'Use H2 for main sections and H3 inside them, in order, each with a short descriptive text.', 'searchpilot-pro' ), 4, $problems );
		} elseif ( $headings ) {
			$checks[] = self::check( 'heading_structure', 'content', __( 'Heading structure', 'searchpilot-pro' ), 'pass', __( 'Headings are in a logical order.', 'searchpilot-pro' ), '', 4 );
		}

		// One H1 per page: an accessibility/readability convention, not a ranking rule (not scored).
		$rendered = is_array( $ctx['rendered'] ) ? $ctx['rendered'] : array();
		$label    = __( 'Main heading (H1)', 'searchpilot-pro' );
		$tip      = __( 'Search engines handle several H1 headings fine. For screen-reader users, one H1 with the page title and H2 for sections is easiest to navigate.', 'searchpilot-pro' );
		if ( isset( $rendered['h1'] ) && null !== $rendered['h1'] ) {
			$h1 = (int) $rendered['h1'];
			if ( 1 === $h1 ) {
				$checks[] = self::check( 'single_h1', 'content', $label, 'pass', __( 'The published page has one H1 heading.', 'searchpilot-pro' ), '', 0 );
			} elseif ( 0 === $h1 ) {
				$checks[] = self::check( 'single_h1', 'content', $label, 'info', __( 'The published page has no H1 heading.', 'searchpilot-pro' ), $tip, 0 );
			} else {
				/* translators: %d: number of H1 headings. */
				$checks[] = self::check( 'single_h1', 'content', $label, 'info', sprintf( __( 'The published page has %d H1 headings.', 'searchpilot-pro' ), $h1 ), $tip, 0 );
			}
			return;
		}
		$content_h1 = 0;
		foreach ( $headings as $h ) {
			if ( 1 === $h['level'] ) {
				++$content_h1;
			}
		}
		if ( $ctx['theme_prints_title'] && $content_h1 ) {
			$checks[] = self::check( 'single_h1', 'content', $label, 'info', __( 'The content contains an H1 heading in addition to the title your theme prints.', 'searchpilot-pro' ), $tip, 0 );
		} elseif ( ! $ctx['theme_prints_title'] && 1 !== $content_h1 ) {
			$checks[] = self::check( 'single_h1', 'content', $label, 'info', __( 'This layout hides the theme title, and the content does not have exactly one H1 heading.', 'searchpilot-pro' ), $tip, 0 );
		} else {
			$checks[] = self::check( 'single_h1', 'content', $label, 'pass', __( 'The page will have one H1 heading (the title).', 'searchpilot-pro' ), '', 0 );
		}
	}

	/**
	 * URL slug check.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function slug_check( array &$checks, array $ctx, array $facts ) {
		$slug = trim( rawurldecode( (string) $ctx['slug'] ), '/' );
		if ( '' === $slug ) {
			return;
		}
		$label = __( 'URL slug', 'searchpilot-pro' );
		if ( ! preg_match( '/^[\x20-\x7E]+$/', $slug ) ) {
			$checks[] = self::check( 'slug', 'content', $label, 'pass', __( 'The address uses the page language. That is fine — search engines show it decoded.', 'searchpilot-pro' ), '', 2 );
			return;
		}
		$problems = array();
		$parts    = array_values( array_filter( preg_split( '/[-_]+/', strtolower( $slug ) ) ) );
		if ( Text::length( $slug ) > 75 || count( $parts ) > 6 ) {
			/* translators: 1: characters, 2: words. */
			$problems[] = sprintf( __( 'The slug is long (%1$d characters, %2$d words).', 'searchpilot-pro' ), Text::length( $slug ), count( $parts ) );
		}
		if ( preg_match( '/[A-Z]/', $slug ) ) {
			$problems[] = __( 'The slug contains capital letters.', 'searchpilot-pro' );
		}
		$stop = array();
		foreach ( $parts as $part ) {
			if ( ! is_numeric( $part ) && strlen( $part ) > 1 && Text::is_stopword( $part ) ) {
				$stop[] = $part;
			}
		}
		if ( count( $stop ) > 1 ) {
			/* translators: %s: stop words. */
			$problems[] = sprintf( __( 'The slug contains filler words (%s).', 'searchpilot-pro' ), implode( ', ', array_unique( $stop ) ) );
		}
		if ( $facts['ftokens'] && preg_match( '/^[\x20-\x7E]+$/', $facts['focus'] ) ) {
			$slug_stems = array_map( array( ContentIndex::class, 'stem' ), $parts );
			$missing    = array_diff( ContentIndex::keywords( $facts['focus'] ), $slug_stems );
			if ( $missing ) {
				$problems[] = __( 'The slug does not contain the focus keyword.', 'searchpilot-pro' );
			}
		}
		if ( $problems ) {
			$checks[] = self::check( 'slug', 'content', $label, 'warning', implode( ' ', $problems ), __( 'Use a short, lower-case slug with the main words of the topic. Change slugs of published pages only with a redirect (SearchPilot adds one automatically).', 'searchpilot-pro' ), 2, $problems );
		} else {
			$checks[] = self::check( 'slug', 'content', $label, 'pass', __( 'The slug is short and descriptive.', 'searchpilot-pro' ), '', 2 );
		}
	}

	/* ---------- Links ---------- */

	/**
	 * Link checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function link_checks( array &$checks, array $ctx, array $facts ) {
		$links    = $facts['links'];
		$internal = array_values(
			array_filter(
				$links,
				static function ( $l ) {
					return $l['internal'];
				}
			)
		);
		$external = count( $links ) - count( $internal );
		$words    = (int) $facts['count'];
		$need     = $words > 500 ? 2 : 1;

		$label = __( 'Internal links', 'searchpilot-pro' );
		if ( ! $internal ) {
			$checks[] = self::check( 'internal_links', 'links', $label, 'fail', __( 'The content has no links to other pages on this site.', 'searchpilot-pro' ), __( 'Link to related articles where they help the reader. SearchPilot suggests links on the Internal Links screen.', 'searchpilot-pro' ), 5 );
		} elseif ( count( $internal ) < $need ) {
			/* translators: %d: number of links. */
			$checks[] = self::check( 'internal_links', 'links', $label, 'warning', sprintf( _n( '%d internal link.', '%d internal links.', count( $internal ), 'searchpilot-pro' ), count( $internal ) ), __( 'Longer articles should link to at least two related pages.', 'searchpilot-pro' ), 5 );
		} else {
			/* translators: %d: number of links. */
			$checks[] = self::check( 'internal_links', 'links', $label, 'pass', sprintf( _n( '%d internal link.', '%d internal links.', count( $internal ), 'searchpilot-pro' ), count( $internal ) ), '', 5 );
		}

		if ( $external ) {
			/* translators: %d: number of links. */
			$checks[] = self::check( 'external_links', 'links', __( 'External links', 'searchpilot-pro' ), 'pass', sprintf( _n( '%d link to another website.', '%d links to other websites.', $external, 'searchpilot-pro' ), $external ), '', 1 );
		} elseif ( $words >= 300 ) {
			$checks[] = self::check( 'external_links', 'links', __( 'External links', 'searchpilot-pro' ), 'warning', __( 'The content does not link to any other website.', 'searchpilot-pro' ), __( 'Where you cite facts, link to the original source.', 'searchpilot-pro' ), 1 );
		}

		if ( $links ) {
			$generic = array();
			foreach ( $links as $l ) {
				$anchor = trim( (string) preg_replace( '/[^\p{L}\p{M}\p{N}\s]+/u', '', mb_strtolower( $l['text'], 'UTF-8' ) ) );
				if ( '' === $anchor || in_array( $anchor, self::generic_anchors(), true ) ) {
					$generic[] = '' === $l['text'] ? Url::display( $l['url'] ) : $l['text'];
				}
			}
			if ( $generic ) {
				/* translators: %s: anchor texts. */
				$checks[] = self::check( 'anchor_text', 'links', __( 'Descriptive link text', 'searchpilot-pro' ), 'warning', sprintf( __( 'Some links have generic or empty text: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( array_unique( $generic ), 0, 5 ) ) ), __( 'Use link text that says where the link goes, e.g. “monsoon forecast for Karnataka” instead of “click here”.', 'searchpilot-pro' ), 2, array_values( array_unique( $generic ) ) );
			} else {
				$checks[] = self::check( 'anchor_text', 'links', __( 'Descriptive link text', 'searchpilot-pro' ), 'pass', __( 'Link texts describe their targets.', 'searchpilot-pro' ), '', 2 );
			}

			$unsafe = array();
			foreach ( $links as $l ) {
				if ( '_blank' === $l['target'] && ! array_intersect( $l['rel'], array( 'noopener', 'noreferrer' ) ) ) {
					$unsafe[] = Url::display( $l['url'] );
				}
			}
			if ( $unsafe ) {
				/* translators: %d: number of links. */
				$checks[] = self::check( 'target_blank', 'links', __( 'Links opening new tabs', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d link opens a new tab without rel="noopener".', '%d links open a new tab without rel="noopener".', count( $unsafe ), 'searchpilot-pro' ), count( $unsafe ) ), __( 'Add rel="noopener" to links with target="_blank" (the block editor does this automatically).', 'searchpilot-pro' ), 1, $unsafe );
			} else {
				$checks[] = self::check( 'target_blank', 'links', __( 'Links opening new tabs', 'searchpilot-pro' ), 'pass', __( 'Links that open new tabs are safe.', 'searchpilot-pro' ), '', 1 );
			}
		}

		if ( $internal ) {
			$nofollow = array();
			$missing  = array();
			$redirect = array();
			foreach ( $internal as $l ) {
				if ( in_array( 'nofollow', $l['rel'], true ) ) {
					$nofollow[] = Url::display( $l['url'] );
				}
				$status = $ctx['link_status'][ Url::normalize( $l['url'] ) ]['status'] ?? 'unknown';
				if ( 'missing' === $status ) {
					$missing[] = Url::display( $l['url'] );
				} elseif ( 'redirect' === $status ) {
					$redirect[] = Url::display( $l['url'] );
				}
			}
			if ( $nofollow ) {
				/* translators: %d: number of links. */
				$checks[] = self::check( 'internal_nofollow', 'links', __( 'Nofollow on internal links', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d internal link is marked nofollow.', '%d internal links are marked nofollow.', count( $nofollow ), 'searchpilot-pro' ), count( $nofollow ) ), __( 'Remove rel="nofollow" from links to your own pages so search engines follow them.', 'searchpilot-pro' ), 2, $nofollow );
			} else {
				$checks[] = self::check( 'internal_nofollow', 'links', __( 'Nofollow on internal links', 'searchpilot-pro' ), 'pass', __( 'Internal links can be followed.', 'searchpilot-pro' ), '', 2 );
			}
			$label = __( 'Broken or redirected internal links', 'searchpilot-pro' );
			if ( $missing ) {
				/* translators: %s: URLs. */
				$checks[] = self::check( 'broken_internal', 'links', $label, 'fail', sprintf( __( 'Links point to pages that no longer exist: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( $missing, 0, 5 ) ) ), __( 'Update or remove these links.', 'searchpilot-pro' ), 4, array_merge( $missing, $redirect ) );
			} elseif ( $redirect ) {
				/* translators: %s: URLs. */
				$checks[] = self::check( 'broken_internal', 'links', $label, 'warning', sprintf( __( 'Links point to addresses that redirect: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( $redirect, 0, 5 ) ) ), __( 'Link directly to the final address.', 'searchpilot-pro' ), 4, $redirect );
			} else {
				$checks[] = self::check( 'broken_internal', 'links', $label, 'pass', __( 'Internal links point to live pages.', 'searchpilot-pro' ), '', 4 );
			}
		}
	}

	/**
	 * Generic anchor texts (lower-case, punctuation removed).
	 *
	 * @return string[]
	 */
	public static function generic_anchors() {
		return array( 'click here', 'click', 'here', 'read more', 'more', 'this', 'link', 'this link', 'learn more', 'continue reading', 'continue', 'go', 'this page', 'website', 'details', 'more info', 'see more', 'ಇಲ್ಲಿ', 'ಇಲ್ಲಿ ಕ್ಲಿಕ್ ಮಾಡಿ', 'ಕ್ಲಿಕ್ ಮಾಡಿ', 'ಹೆಚ್ಚು ಓದಿ', 'ಮುಂದೆ ಓದಿ', 'ಇನ್ನಷ್ಟು ಓದಿ', 'ಇದನ್ನೂ ಓದಿ', 'यहाँ', 'यहां क्लिक करें', 'यहाँ क्लिक करें', 'और पढ़ें', 'आगे पढ़ें' );
	}

	/* ---------- Images ---------- */

	/**
	 * Image checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function image_checks( array &$checks, array $ctx, array $facts ) {
		$images   = $facts['images'];
		$featured = is_array( $ctx['featured'] ) ? $ctx['featured'] : null;
		$words    = (int) $facts['count'];

		if ( $images ) {
			/* translators: %d: number of images. */
			$checks[] = self::check( 'images_present', 'images', __( 'Images', 'searchpilot-pro' ), 'pass', sprintf( _n( '%d image in the content.', '%d images in the content.', count( $images ), 'searchpilot-pro' ), count( $images ) ), '', 2 );
		} elseif ( $words > 300 ) {
			$checks[] = self::check( 'images_present', 'images', __( 'Images', 'searchpilot-pro' ), 'warning', __( 'The content has no images.', 'searchpilot-pro' ), __( 'Add a relevant photo, chart or illustration with descriptive ALT text.', 'searchpilot-pro' ), 2 );
		}

		$article = in_array( (string) $ctx['schema_type'], array( 'Article', 'BlogPosting', 'NewsArticle' ), true );
		$label   = __( 'Featured image', 'searchpilot-pro' );
		$expect  = ! isset( $ctx['type_rule']['featured_image'] ) || ! empty( $ctx['type_rule']['featured_image'] );
		if ( ! $expect ) {
			if ( $featured ) {
				$checks[] = self::check( 'featured_image', 'images', $label, 'pass', __( 'A featured image is set.', 'searchpilot-pro' ), '', 0 );
			} else {
				$checks[] = self::check( 'featured_image', 'images', $label, 'info', __( 'No featured image is set. That is fine for this content type (pages such as Contact or legal pages do not need one).', 'searchpilot-pro' ), '', 0 );
			}
		} elseif ( ! $featured ) {
			$status   = ( $article && 'post' === $ctx['post_type'] ) ? 'fail' : 'warning';
			$checks[] = self::check( 'featured_image', 'images', $label, $status, __( 'No featured image is set.', 'searchpilot-pro' ), __( 'Set a featured image of at least 1200 px width — it is used for article rich results and social cards.', 'searchpilot-pro' ), 4 );
		} elseif ( ! empty( $featured['width'] ) && (int) $featured['width'] < 1200 ) {
			/* translators: %d: width in pixels. */
			$checks[] = self::check( 'featured_image', 'images', $label, 'warning', sprintf( __( 'The featured image is %dpx wide.', 'searchpilot-pro' ), (int) $featured['width'] ), __( 'Use an image at least 1200 px wide for article rich results and large social cards.', 'searchpilot-pro' ), 4 );
		} else {
			$checks[] = self::check( 'featured_image', 'images', $label, 'pass', __( 'A featured image is set.', 'searchpilot-pro' ), '', 4 );
		}

		$missing = array();
		foreach ( $images as $image ) {
			if ( null === $image['alt'] || '' === $image['alt'] ) {
				$missing[] = basename( (string) wp_parse_url( $image['src'], PHP_URL_PATH ) );
			}
		}
		if ( $featured && '' === trim( (string) ( $featured['alt'] ?? '' ) ) ) {
			$missing[] = __( 'featured image', 'searchpilot-pro' );
		}
		$total = count( $images ) + ( $featured ? 1 : 0 );
		if ( $total ) {
			if ( $missing ) {
				/* translators: %d: number of images. */
				$checks[] = self::check( 'image_alt', 'images', __( 'Image ALT text', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d image has no ALT text.', '%d images have no ALT text.', count( $missing ), 'searchpilot-pro' ), count( $missing ) ), __( 'Describe what each image shows in its ALT text (skip purely decorative images).', 'searchpilot-pro' ), 4, $missing );
			} else {
				$checks[] = self::check( 'image_alt', 'images', __( 'Image ALT text', 'searchpilot-pro' ), 'pass', __( 'Every image has ALT text.', 'searchpilot-pro' ), '', 4 );
			}
		}

		if ( $facts['ftokens'] && $images ) {
			$found = false;
			foreach ( $images as $image ) {
				if ( null !== $image['alt'] && self::positions( ContentIndex::stems( $image['alt'] ), $facts['ftokens'] ) ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				$checks[] = self::check( 'image_alt_focus', 'images', __( 'Focus keyword in image ALT', 'searchpilot-pro' ), 'pass', __( 'An image ALT text mentions the focus topic.', 'searchpilot-pro' ), '', 1 );
			} else {
				$checks[] = self::check( 'image_alt_focus', 'images', __( 'Focus keyword in image ALT', 'searchpilot-pro' ), 'warning', __( 'No image ALT text mentions the focus topic.', 'searchpilot-pro' ), __( 'If an image shows the topic, say so in its ALT text.', 'searchpilot-pro' ), 1 );
			}
		}

		// File size and formats (media library images only).
		$known  = array();
		$large  = array();
		$modern = false;
		$ids    = array();
		foreach ( $images as $image ) {
			if ( $image['id'] ) {
				$ids[] = (int) $image['id'];
			}
		}
		if ( $featured && ! empty( $featured['id'] ) ) {
			$ids[] = (int) $featured['id'];
		}
		foreach ( array_unique( $ids ) as $id ) {
			$info = ( $featured && (int) ( $featured['id'] ?? 0 ) === $id ) ? $featured : ( $ctx['attachments'][ $id ] ?? null );
			if ( ! is_array( $info ) ) {
				continue;
			}
			$known[] = $id;
			if ( in_array( (string) ( $info['mime'] ?? '' ), array( 'image/webp', 'image/avif' ), true ) ) {
				$modern = true;
			}
			if ( (int) ( $info['filesize'] ?? 0 ) > self::LARGE_IMAGE_BYTES ) {
				$large[] = sprintf( '%s (%s KB)', (string) ( $info['file'] ?? ( '#' . $id ) ), number_format( (int) $info['filesize'] / 1024 ) );
			}
		}
		if ( $known ) {
			$format_note = $modern ? '' : ' ' . __( 'None of the images use WebP or AVIF, which are usually much smaller.', 'searchpilot-pro' );
			if ( $large ) {
				/* translators: %d: number of images. */
				$checks[] = self::check( 'image_size', 'images', __( 'Image file size', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d image is larger than 300 KB.', '%d images are larger than 300 KB.', count( $large ), 'searchpilot-pro' ), count( $large ) ) . $format_note, __( 'Compress large images or serve them as WebP/AVIF so the page loads faster.', 'searchpilot-pro' ), 2, $large );
			} else {
				$checks[] = self::check( 'image_size', 'images', __( 'Image file size', 'searchpilot-pro' ), 'pass', __( 'Image files are a reasonable size.', 'searchpilot-pro' ) . $format_note, '', 2 );
			}
		}

		$no_srcset = 0;
		$library   = 0;
		foreach ( $images as $image ) {
			if ( $image['id'] ) {
				++$library;
				if ( ! $image['srcset'] ) {
					++$no_srcset;
				}
			}
		}
		if ( $library ) {
			if ( $no_srcset ) {
				/* translators: %d: number of images. */
				$checks[] = self::check( 'image_srcset', 'images', __( 'Responsive images', 'searchpilot-pro' ), 'warning', sprintf( _n( '%d image has no responsive sizes (srcset).', '%d images have no responsive sizes (srcset).', $no_srcset, 'searchpilot-pro' ), $no_srcset ), __( 'Insert images from the media library with a size that WordPress can scale, so phones download smaller files.', 'searchpilot-pro' ), 1 );
			} else {
				$checks[] = self::check( 'image_srcset', 'images', __( 'Responsive images', 'searchpilot-pro' ), 'pass', __( 'Images offer responsive sizes.', 'searchpilot-pro' ), '', 1 );
			}
		}
	}

	/* ---------- Technical ---------- */

	/**
	 * Technical checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function technical_checks( array &$checks, array $ctx, array $facts ) {
		$url       = (string) $ctx['url'];
		$canonical = trim( (string) $ctx['canonical'] );
		$label     = __( 'Canonical URL', 'searchpilot-pro' );
		if ( '' !== $canonical && '' !== $url && Url::normalize( $canonical ) !== Url::normalize( $url ) ) {
			/* translators: %s: canonical URL. */
			$checks[] = self::check( 'canonical', 'technical', $label, 'warning', sprintf( __( 'The canonical URL points to another page (%s), so search engines are asked to index that page instead.', 'searchpilot-pro' ), Url::display( $canonical ) ), __( 'Leave the canonical empty unless this page duplicates another one.', 'searchpilot-pro' ), 4 );
		} else {
			$checks[] = self::check( 'canonical', 'technical', $label, 'pass', __( 'The canonical URL points to this page.', 'searchpilot-pro' ), '', 4 );
		}

		if ( $ctx['noindex'] ) {
			$checks[] = self::check( 'noindex', 'technical', __( 'Search visibility', 'searchpilot-pro' ), 'warning', __( 'This page is set to noindex, so it won’t appear in search results.', 'searchpilot-pro' ), __( 'Remove noindex if people should find this page in search.', 'searchpilot-pro' ), 6 );
		} else {
			$checks[] = self::check( 'noindex', 'technical', __( 'Search visibility', 'searchpilot-pro' ), 'pass', __( 'Search engines may index this page.', 'searchpilot-pro' ), '', 6 );
		}

		if ( '' !== $url ) {
			$len = Text::length( Url::display( $url ) );
			if ( $len > 115 ) {
				/* translators: %d: characters. */
				$checks[] = self::check( 'url_length', 'technical', __( 'URL length', 'searchpilot-pro' ), 'warning', sprintf( __( 'The address is long (%d characters).', 'searchpilot-pro' ), $len ), __( 'Shorter addresses are easier to share and read in search results.', 'searchpilot-pro' ), 1 );
			} else {
				$checks[] = self::check( 'url_length', 'technical', __( 'URL length', 'searchpilot-pro' ), 'pass', __( 'The address has a reasonable length.', 'searchpilot-pro' ), '', 1 );
			}
		}

		$rendered = is_array( $ctx['rendered'] ) ? $ctx['rendered'] : array();
		if ( isset( $rendered['titles'] ) && null !== $rendered['titles'] ) {
			$titles = (int) $rendered['titles'];
			if ( 1 === $titles ) {
				$checks[] = self::check( 'title_tag', 'technical', __( 'Title tag', 'searchpilot-pro' ), 'pass', __( 'The published page has one <title> tag.', 'searchpilot-pro' ), '', 3 );
			} elseif ( 0 === $titles ) {
				$checks[] = self::check( 'title_tag', 'technical', __( 'Title tag', 'searchpilot-pro' ), 'fail', __( 'The published page has no <title> tag.', 'searchpilot-pro' ), __( 'Your theme must support the “title-tag” feature, or your SEO plugin must print the title.', 'searchpilot-pro' ), 3 );
			} else {
				/* translators: %d: number of title tags. */
				$checks[] = self::check( 'title_tag', 'technical', __( 'Title tag', 'searchpilot-pro' ), 'warning', sprintf( __( 'The published page has %d <title> tags.', 'searchpilot-pro' ), $titles ), __( 'Only one plugin or the theme should print the title.', 'searchpilot-pro' ), 3 );
			}
		}
	}

	/* ---------- Social & schema ---------- */

	/**
	 * Social preview checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function social_checks( array &$checks, array $ctx, array $facts ) {
		$label = __( 'Social previews', 'searchpilot-pro' );
		if ( empty( $ctx['owns']['social'] ) ) {
			$owner = '' !== $ctx['companion'] ? $ctx['companion'] : __( 'Another plugin', 'searchpilot-pro' );
			/* translators: %s: plugin name. */
			$checks[] = self::check( 'social_preview', 'social', $label, 'info', sprintf( __( '%s handles Open Graph and X/Twitter tags for this site; check its social settings for this page.', 'searchpilot-pro' ), $owner ), '', 0 );
			return;
		}
		$social = (array) $ctx['social'];
		$title  = '' !== trim( (string) ( $social['og_title'] ?? '' ) ) || '' !== trim( (string) $ctx['seo_title'] );
		$desc   = '' !== trim( (string) ( $social['og_description'] ?? '' ) ) || '' !== trim( (string) $ctx['description'] );
		$image  = ! empty( $social['og_image'] ) || is_array( $ctx['featured'] ) || ! empty( $ctx['default_image'] );
		if ( $title && $desc && $image ) {
			$checks[] = self::check( 'social_preview', 'social', $label, 'pass', __( 'Shares on Facebook, WhatsApp, LinkedIn and X will show a title, description and image.', 'searchpilot-pro' ), '', 2 );
		} else {
			$missing = array();
			if ( ! $title ) {
				$missing[] = __( 'title', 'searchpilot-pro' );
			}
			if ( ! $desc ) {
				$missing[] = __( 'description', 'searchpilot-pro' );
			}
			if ( ! $image ) {
				$missing[] = __( 'image', 'searchpilot-pro' );
			}
			/* translators: %s: missing parts. */
			$checks[] = self::check( 'social_preview', 'social', $label, 'warning', sprintf( __( 'Social previews are missing: %s.', 'searchpilot-pro' ), implode( ', ', $missing ) ), __( 'Set a featured image (or a default social image in Settings) and a description.', 'searchpilot-pro' ), 2, $missing );
		}
	}

	/**
	 * Schema checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function schema_checks( array &$checks, array $ctx, array $facts ) {
		$label = __( 'Structured data', 'searchpilot-pro' );
		if ( empty( $ctx['owns']['schema'] ) ) {
			$owner = '' !== $ctx['companion'] ? $ctx['companion'] : __( 'Another plugin', 'searchpilot-pro' );
			/* translators: %s: plugin name. */
			$checks[] = self::check( 'schema_article', 'schema', $label, 'info', sprintf( __( '%s outputs the structured data (schema) for this site.', 'searchpilot-pro' ), $owner ), '', 0 );
		} else {
			$type = (string) $ctx['schema_type'];
			if ( '' === $type || 'none' === $type ) {
				$checks[] = self::check( 'schema_article', 'schema', $label, 'info', __( 'No schema type is configured for this content type.', 'searchpilot-pro' ), __( 'Choose Article, NewsArticle or WebPage in Settings → Content types.', 'searchpilot-pro' ), 0 );
			} elseif ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) && ! is_array( $ctx['featured'] ) ) {
				/* translators: %s: schema type. */
				$checks[] = self::check( 'schema_article', 'schema', $label, 'warning', sprintf( __( '%s schema is output, but without a featured image the page is not eligible for article rich results.', 'searchpilot-pro' ), $type ), __( 'Set a featured image.', 'searchpilot-pro' ), 2 );
			} else {
				/* translators: %s: schema type. */
				$checks[] = self::check( 'schema_article', 'schema', $label, 'pass', sprintf( __( '%s schema is output for this page.', 'searchpilot-pro' ), $type ), '', 2 );
			}
		}
		if ( ! empty( $ctx['faq'] ) ) {
			$checks[] = self::check( 'schema_faq', 'schema', __( 'FAQ content', 'searchpilot-pro' ), 'info', __( 'The page contains FAQ content. FAQ rich results are shown only for well-known government and health sites, but the markup still describes the content.', 'searchpilot-pro' ), '', 0 );
		}
	}

	/* ---------- Freshness ---------- */

	/**
	 * Freshness checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function freshness_checks( array &$checks, array $ctx, array $facts ) {
		$now      = (int) $ctx['now'];
		$modified = (int) $ctx['modified'];
		if ( $modified > 0 && 'publish' === $ctx['status'] ) {
			$days = (int) floor( max( 0, $now - $modified ) / DAY_IN_SECONDS );
			if ( $days > 365 ) {
				/* translators: %d: months. */
				$checks[] = self::check( 'last_modified', 'freshness', __( 'Last updated', 'searchpilot-pro' ), 'warning', sprintf( __( 'Last updated %d months ago.', 'searchpilot-pro' ), (int) floor( $days / 30 ) ), __( 'If this is evergreen content, review it: update facts, prices, screenshots and dates.', 'searchpilot-pro' ), 2 );
			} else {
				/* translators: %d: days. */
				$checks[] = self::check( 'last_modified', 'freshness', __( 'Last updated', 'searchpilot-pro' ), 'pass', sprintf( _n( 'Updated %d day ago.', 'Updated %d days ago.', $days, 'searchpilot-pro' ), $days ), '', 2 );
			}
		}

		$current = (int) gmdate( 'Y', $now );
		$heading = array();
		foreach ( $facts['headings'] as $h ) {
			foreach ( self::years( $h['text'] ) as $y ) {
				if ( $y < $current && $y >= $current - 30 ) {
					$heading[] = $y;
				}
			}
		}
		if ( $heading ) {
			/* translators: %s: years. */
			$checks[] = self::check( 'stale_years', 'freshness', __( 'Dates in headings', 'searchpilot-pro' ), 'warning', sprintf( __( 'Headings mention past years (%s).', 'searchpilot-pro' ), implode( ', ', array_unique( $heading ) ) ), __( 'Check whether these sections are still current.', 'searchpilot-pro' ), 1 );
			return;
		}
		// Past years in the opening text: informational (reports and news legitimately cite them).
		$body = array_filter(
			self::years( mb_substr( (string) $facts['text'], 0, 3000, 'UTF-8' ) ),
			static function ( $y ) use ( $current ) {
				return $y < $current && $y >= $current - 30;
			}
		);
		if ( $body && $modified > 0 && ( $now - $modified ) > 180 * DAY_IN_SECONDS ) {
			sort( $body );
			/* translators: %s: years. */
			$checks[] = self::check( 'stale_years', 'freshness', __( 'Dates in the text', 'searchpilot-pro' ), 'info', sprintf( __( 'The text mentions %s. If the page is meant to stay current, check that these dates and facts are still right.', 'searchpilot-pro' ), implode( ', ', array_slice( $body, -3 ) ) ), '', 0 );
		}
	}

	/* ---------- Duplicates ---------- */

	/**
	 * Duplicate checks.
	 *
	 * @param array $checks Checks (by reference).
	 * @param array $ctx    Context.
	 * @param array $facts  Facts.
	 */
	private static function duplicate_checks( array &$checks, array $ctx, array $facts ) {
		if ( ! is_array( $ctx['duplicates'] ) ) {
			return;
		}
		$dups   = $ctx['duplicates'];
		$titles = static function ( array $rows ) {
			return array_values(
				array_map(
					static function ( $r ) {
						return (string) ( $r['title'] ?? '' );
					},
					$rows
				)
			);
		};

		if ( '' !== trim( (string) $ctx['seo_title'] ) ) {
			if ( ! empty( $dups['title'] ) ) {
				/* translators: %d: number of pages. */
				$checks[] = self::check( 'duplicate_title', 'duplicates', __( 'Unique title', 'searchpilot-pro' ), 'fail', sprintf( _n( '%d other published page uses the same title.', '%d other published pages use the same title.', count( $dups['title'] ), 'searchpilot-pro' ), count( $dups['title'] ) ), __( 'Give every page its own title so search engines know which one to show.', 'searchpilot-pro' ), 4, $titles( $dups['title'] ) );
			} else {
				$checks[] = self::check( 'duplicate_title', 'duplicates', __( 'Unique title', 'searchpilot-pro' ), 'pass', __( 'No other page uses this title.', 'searchpilot-pro' ), '', 4 );
			}
		}
		if ( '' !== trim( (string) $ctx['description'] ) ) {
			if ( ! empty( $dups['description'] ) ) {
				/* translators: %d: number of pages. */
				$checks[] = self::check( 'duplicate_description', 'duplicates', __( 'Unique description', 'searchpilot-pro' ), 'fail', sprintf( _n( '%d other published page uses the same description.', '%d other published pages use the same description.', count( $dups['description'] ), 'searchpilot-pro' ), count( $dups['description'] ) ), __( 'Write a description for this page specifically.', 'searchpilot-pro' ), 3, $titles( $dups['description'] ) );
			} else {
				$checks[] = self::check( 'duplicate_description', 'duplicates', __( 'Unique description', 'searchpilot-pro' ), 'pass', __( 'No other page uses this description.', 'searchpilot-pro' ), '', 3 );
			}
		}
		if ( $facts['count'] >= 100 ) {
			if ( ! empty( $dups['content'] ) ) {
				/* translators: %s: page titles. */
				$checks[] = self::check( 'near_duplicate', 'duplicates', __( 'Original content', 'searchpilot-pro' ), 'fail', sprintf( __( 'The text is nearly identical to: %s.', 'searchpilot-pro' ), implode( ', ', array_slice( $titles( $dups['content'] ), 0, 3 ) ) ), __( 'Merge the pages (and redirect one to the other), or rewrite one so each has a distinct purpose.', 'searchpilot-pro' ), 5, $titles( $dups['content'] ) );
			} else {
				$checks[] = self::check( 'near_duplicate', 'duplicates', __( 'Original content', 'searchpilot-pro' ), 'pass', __( 'The text is not a near-copy of another page.', 'searchpilot-pro' ), '', 5 );
			}
		}
	}

	/* ---------- Queries ---------- */

	/**
	 * Search Console queries with coverage flags.
	 *
	 * @param array $ctx   Context.
	 * @param array $facts Facts (headings, tokens).
	 * @return array<int,array{query:string,clicks:int,impressions:int,position:float,covered:bool,where:string}>
	 */
	private static function query_coverage( array $ctx, array $facts ) {
		if ( ! is_array( $ctx['queries'] ) || ! $ctx['queries'] ) {
			return array();
		}
		$title_tokens   = ContentIndex::stems( (string) $ctx['seo_title'] . ' . ' . (string) $ctx['title'] );
		$heading_tokens = array();
		foreach ( $facts['headings'] as $h ) {
			$heading_tokens[] = ContentIndex::stems( $h['text'] );
		}
		$body_set = array_flip( $facts['tokens'] );
		$rows     = $ctx['queries'];
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) ( $b['impressions'] ?? 0 ) <=> (int) ( $a['impressions'] ?? 0 );
			}
		);
		$out = array();
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			$query  = trim( (string) ( $row['query'] ?? '' ) );
			$qt     = ContentIndex::stems( $query );
			$where  = '';
			if ( $qt ) {
				if ( self::positions( $title_tokens, $qt ) ) {
					$where = 'title';
				} else {
					foreach ( $heading_tokens as $ht ) {
						if ( self::positions( $ht, $qt ) ) {
							$where = 'headings';
							break;
						}
					}
				}
				if ( '' === $where && self::positions( $facts['tokens'], $qt ) ) {
					$where = 'body';
				}
				if ( '' === $where ) {
					$keywords = ContentIndex::keywords( $query );
					$all      = (bool) $keywords;
					foreach ( $keywords as $k ) {
						if ( ! isset( $body_set[ $k ] ) ) {
							$all = false;
							break;
						}
					}
					if ( $all ) {
						$where = 'body';
					}
				}
			}
			$out[] = array(
				'query'       => $query,
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				'covered'     => '' !== $where,
				'where'       => $where,
			);
		}
		return $out;
	}

	/* ---------- Helpers ---------- */

	/**
	 * Builds a check.
	 *
	 * @param string $id             Id.
	 * @param string $group          Group.
	 * @param string $label          Label.
	 * @param string $status         pass|warning|fail|info.
	 * @param string $message        What was found.
	 * @param string $recommendation What to do.
	 * @param int    $weight         Weight in the score (0 = not scored).
	 * @param array  $items          Details (URLs, titles…).
	 * @param array  $data           Machine-readable measurement (e.g. *_length_status()) for issues and fixers.
	 * @return array
	 */
	private static function check( $id, $group, $label, $status, $message, $recommendation = '', $weight = 1, array $items = array(), array $data = array() ) {
		$check = array(
			'id'             => $id,
			'group'          => $group,
			'label'          => $label,
			'status'         => $status,
			'message'        => $message,
			'recommendation' => $recommendation,
			'weight'         => 'info' === $status ? 0 : (int) $weight,
		);
		if ( $items ) {
			$check['items'] = array_values( array_slice( $items, 0, 20 ) );
		}
		if ( $data ) {
			$check['data'] = $data;
		}
		return $check;
	}

	/**
	 * Start positions of a token sequence inside another.
	 *
	 * @param string[] $haystack Tokens.
	 * @param string[] $needle   Tokens.
	 * @return int[]
	 */
	public static function positions( array $haystack, array $needle ) {
		$n = count( $needle );
		$h = count( $haystack );
		if ( ! $n || $n > $h ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i <= $h - $n; $i++ ) {
			if ( $haystack[ $i ] !== $needle[0] ) {
				continue;
			}
			$match = true;
			for ( $j = 1; $j < $n; $j++ ) {
				if ( $haystack[ $i + $j ] !== $needle[ $j ] ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				$out[] = $i;
				$i    += $n - 1;
			}
		}
		return $out;
	}

	/**
	 * User-perceived characters (grapheme clusters).
	 *
	 * @deprecated 1.1.0 Use Text::length() (the plugin-wide grapheme unit).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function chars( $text ) {
		return Text::length( $text );
	}

	/**
	 * Four-digit years (1900–2099) in a text.
	 *
	 * @param string $text Text.
	 * @return int[]
	 */
	private static function years( $text ) {
		if ( ! preg_match_all( '/(?<![0-9])(19[0-9]{2}|20[0-9]{2})(?![0-9])/', (string) $text, $m ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $m[1] ) ) );
	}

	/**
	 * Flesch reading ease (English formula).
	 *
	 * @param string[] $words     Words.
	 * @param int      $sentences Sentence count.
	 * @return float
	 */
	private static function flesch( array $words, $sentences ) {
		$syllables = 0;
		$count     = 0;
		foreach ( $words as $word ) {
			if ( ! preg_match( '/[a-z]/', $word ) ) {
				continue;
			}
			++$count;
			$syllables += self::syllables( $word );
		}
		if ( ! $count || ! $sentences ) {
			return 0.0;
		}
		$score = 206.835 - 1.015 * ( $count / $sentences ) - 84.6 * ( $syllables / $count );
		return round( max( 0.0, min( 100.0, $score ) ), 1 );
	}

	/**
	 * Estimated English syllables of a word.
	 *
	 * @param string $word Lower-case word.
	 * @return int
	 */
	private static function syllables( $word ) {
		$word = (string) preg_replace( '/[^a-z]/', '', strtolower( $word ) );
		if ( '' === $word ) {
			return 0;
		}
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word  = (string) preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word  = (string) preg_replace( '/^y/', '', $word );
		$count = preg_match_all( '/[aeiouy]{1,2}/', $word );
		return max( 1, (int) $count );
	}

	/* ==================================================================
	 * Context from WordPress
	 * ================================================================== */

	/**
	 * Builds the analysis context for a post or editor input.
	 *
	 * @param array $input Input (see analyze()).
	 * @return array
	 */
	public function context( array $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$stored  = $post_id ? get_post( $post_id ) : null;
		if ( ! $stored ) {
			$post_id = 0;
		}
		$live = isset( $input['content'] ) && null !== $input['content'];
		$post = $this->working_post( $stored, $input );

		$render = $this->renderer->render_details( (string) $post->post_content, $post, array( 'builder' => ! $live ) );
		$html   = (string) $render['html'];
		$url    = $this->url_for( $post, $stored );
		$doc    = HtmlDocument::parse( '<div>' . $html . '</div>', $url );

		// Internal link targets.
		$link_status = array();
		foreach ( $doc->links() as $link ) {
			if ( ! $link['internal'] || count( $link_status ) >= 60 ) {
				continue;
			}
			$key = Url::normalize( $link['url'] );
			if ( ! isset( $link_status[ $key ] ) ) {
				$link_status[ $key ] = $this->graph->resolve( $link['url'] );
			}
		}
		list( $attachments, $image_ids ) = $this->attachments_in( $doc, $url );
		$text                            = $doc->text();
		$title_info                      = $this->effective_title( $post, $input );
		$desc_info                       = $this->effective_description( $post, $input );
		$rendered_at                     = null;
		$source_names                    = array(
			(string) $title_info['source'] => $this->meta->owner_name( (string) $title_info['source'] ),
			(string) $desc_info['source']  => $this->meta->owner_name( (string) $desc_info['source'] ),
			// A head read from the published page is still built by the owner plugin.
			'rendered'                     => $this->meta->owner_name( 'none' !== $desc_info['source'] ? (string) $desc_info['source'] : (string) $title_info['source'] ),
		);
		// Stored posts: measure what the published page served when a crawl after the last change exists.
		$unsaved = $live;
		foreach ( array( 'title', 'excerpt', 'slug', 'seo_title', 'description' ) as $key ) {
			$unsaved = $unsaved || ( isset( $input[ $key ] ) && null !== $input[ $key ] );
		}
		if ( $post_id && ! $unsaved ) {
			$head = $this->meta->rendered_head( $post_id );
			if ( is_array( $head ) && ! empty( $head['fresh'] ) ) {
				$rendered_at = (string) $head['crawled_at'];
				$title_info  = array(
					'title'  => (string) $head['title'],
					'source' => 'rendered',
					'origin' => self::same_text( $head['title'], $title_info['title'] ) ? $title_info['origin'] : 'unknown',
				);
				$served      = (string) $head['description'];
				$desc_info   = array(
					'description' => $served,
					'source'      => '' !== $served ? 'rendered' : 'none',
					'origin'      => '' === $served ? 'none' : ( self::same_text( $served, $desc_info['description'] ) ? $desc_info['origin'] : 'unknown' ),
				);
			}
		}
		$seo_title   = (string) $title_info['title'];
		$description = (string) $desc_info['description'];

		$focus     = isset( $input['focus'] ) && null !== $input['focus'] ? sanitize_text_field( (string) $input['focus'] ) : ( $post_id ? $this->meta->focus( $post_id ) : '' );
		$canonical = isset( $input['canonical'] ) && null !== $input['canonical'] ? trim( (string) $input['canonical'] ) : ( $post_id ? $this->meta->canonical( $post_id ) : '' );
		if ( array_key_exists( 'noindex', $input ) && null !== $input['noindex'] ) {
			$noindex = (bool) $input['noindex'];
		} else {
			$noindex = $post_id ? $this->is_noindex( $stored ) : false;
		}
		$featured_id = isset( $input['featured_media'] ) && null !== $input['featured_media'] ? absint( $input['featured_media'] ) : ( $post_id ? (int) get_post_thumbnail_id( $post_id ) : 0 );
		$featured    = $featured_id ? $this->attachment_info( $featured_id ) : null;

		$words   = Text::word_count( $text );
		$simhash = SimHash::of( $text );
		$dups    = array(
			'title'       => $this->titles_for( $this->repo->duplicates_of( 'title_hash', AnalysisRepository::text_hash( $seo_title ), $post_id ) ),
			'description' => '' !== $description ? $this->titles_for( $this->repo->duplicates_of( 'description_hash', AnalysisRepository::text_hash( $description ), $post_id ) ) : array(),
			'content'     => array(),
		);
		if ( $words >= 100 ) {
			foreach ( $this->repo->near_duplicates( $simhash, $post_id ) as $id => $distance ) {
				$dups['content'][] = array(
					'id'       => (int) $id,
					'title'    => get_the_title( $id ),
					'distance' => (int) $distance,
				);
			}
		}

		$rendered = array();
		if ( $post_id ) {
			$row = $this->repo->get( $post_id );
			if ( $row && ! empty( $row['analysis']['rendered'] ) && is_array( $row['analysis']['rendered'] ) ) {
				$r = $row['analysis']['rendered'];
				// Only trust crawl facts gathered after the last edit.
				if ( ! empty( $r['checked_at'] ) && strtotime( (string) $r['checked_at'] ) >= strtotime( $stored->post_modified_gmt . ' UTC' ) ) {
					$rendered = $r;
				}
			}
		}

		$owns = array(
			'title'       => $this->conflicts->owns( 'title' ),
			'description' => $this->conflicts->owns( 'description' ),
			'social'      => $this->conflicts->owns( 'social' ),
			'schema'      => $this->conflicts->owns( 'schema' ),
		);
		$primary   = $this->conflicts->primary_seo_plugin();
		$detected  = $this->conflicts->detect();
		$companion = '' !== $primary && isset( $detected[ $primary ]['name'] ) ? (string) $detected[ $primary ]['name'] : '';

		$schema_type = '';
		if ( $owns['schema'] ) {
			$schema_type = $post_id ? (string) $this->meta->get_post_field( $post_id, 'schema_type' ) : '';
			if ( '' === $schema_type ) {
				$schema_type = $this->types->schema_type( $post->post_type );
			}
		}
		$raw_content = (string) $post->post_content;
		$faq         = ( $post_id && $this->meta->get_post_field( $post_id, 'faq' ) ) || false !== strpos( $raw_content, 'wp:yoast/faq-block' ) || false !== strpos( $raw_content, 'wp:rank-math/faq-block' ) || false !== strpos( $raw_content, 'wp:searchpilot/faq' );

		$social = array(
			'og_title'       => $post_id ? (string) $this->meta->get_post_field( $post_id, 'og_title' ) : '',
			'og_description' => $post_id ? (string) $this->meta->get_post_field( $post_id, 'og_description' ) : '',
			'og_image'       => $post_id ? (bool) $this->meta->get_post_field( $post_id, 'og_image' ) : false,
		);

		return array(
			'post_id'            => $post_id,
			'post_type'          => (string) $post->post_type,
			'status'             => $stored ? (string) $stored->post_status : 'draft',
			'title'              => (string) $post->post_title,
			'seo_title'          => $seo_title,
			'description'        => $description,
			'description_source' => (string) $desc_info['source'],
			'description_origin' => (string) $desc_info['origin'],
			'title_source'       => (string) $title_info['source'],
			'title_origin'       => (string) $title_info['origin'],
			'rendered_at'        => $rendered_at,
			'type_rule'          => self::post_type_rule( (string) $post->post_type, (array) $this->settings->get( 'analysis.post_type_rules', self::POST_TYPE_RULES ) ),
			'source_names'       => $source_names,
			'focus'              => $focus,
			'slug'               => (string) $post->post_name,
			'url'                => $url,
			'html'               => $html,
			'text'               => $text,
			'doc'                => $doc,
			'canonical'          => $canonical,
			'noindex'            => $noindex,
			'featured'           => $featured,
			'attachments'        => $attachments,
			'image_ids'          => $image_ids,
			'link_status'        => $link_status,
			'duplicates'         => $dups,
			'modified'           => $stored ? (int) strtotime( $stored->post_modified_gmt . ' UTC' ) : 0,
			'published'          => $stored && 'publish' === $stored->post_status ? (int) strtotime( $stored->post_date_gmt . ' UTC' ) : 0,
			'now'                => time(),
			'thin_words'         => (int) $this->settings->get( 'content.thin_words', 300 ),
			'theme_prints_title' => ContentRenderer::theme_prints_title( $post ),
			'owns'               => $owns,
			'companion'          => $companion,
			'social'             => $social,
			'default_image'      => (bool) $this->settings->get( 'meta.default_image', 0 ),
			'schema_type'        => $schema_type,
			'faq'                => $faq,
			'queries'            => $stored && 'publish' === $stored->post_status ? $this->queries_for( $url ) : null,
			'page_builder'       => (string) $render['page_builder'],
			'rendered'           => $rendered,
			'is_news'            => $this->is_news( $post ),
			'fingerprint'        => array(
				'simhash'          => $simhash,
				'content_hash'     => md5( $text ),
				'title_hash'       => AnalysisRepository::text_hash( $seo_title ),
				'description_hash' => AnalysisRepository::text_hash( $description ),
				'duplicate_ids'    => array(
					'title'       => wp_list_pluck( $dups['title'], 'id' ),
					'description' => wp_list_pluck( $dups['description'], 'id' ),
					'content'     => wp_list_pluck( $dups['content'], 'id' ),
				),
				'attachments'      => array_keys( $attachments ),
				'featured_id'      => $featured_id,
			),
		);
	}

	/**
	 * Post object carrying the editor's unsaved values.
	 *
	 * @param \WP_Post|null $stored Stored post.
	 * @param array         $input  Input.
	 * @return \WP_Post
	 */
	private function working_post( $stored, array $input ) {
		if ( $stored ) {
			$post = clone $stored;
		} else {
			$now  = current_time( 'mysql' );
			$gmt  = current_time( 'mysql', true );
			$type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
			$post = new \WP_Post(
				(object) array(
					'ID'                => 0,
					'post_type'         => post_type_exists( $type ) ? $type : 'post',
					'post_status'       => 'draft',
					'post_author'       => get_current_user_id(),
					'post_date'         => $now,
					'post_date_gmt'     => $gmt,
					'post_modified'     => $now,
					'post_modified_gmt' => $gmt,
					'post_title'        => '',
					'post_content'      => '',
					'post_excerpt'      => '',
					'post_name'         => '',
					'post_password'     => '',
					'post_parent'       => 0,
					'comment_status'    => 'closed',
					'filter'            => 'raw',
				)
			);
		}
		$map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);
		foreach ( $map as $key => $property ) {
			if ( isset( $input[ $key ] ) && null !== $input[ $key ] ) {
				$post->$property = (string) $input[ $key ];
			}
		}
		if ( ! $stored && '' === $post->post_name && '' !== $post->post_title ) {
			$post->post_name = sanitize_title( $post->post_title );
		}
		return $post;
	}

	/**
	 * Public (or expected) URL of the post.
	 *
	 * @param \WP_Post      $post   Working post.
	 * @param \WP_Post|null $stored Stored post.
	 * @return string
	 */
	private function url_for( \WP_Post $post, $stored ) {
		if ( $stored && 'publish' === $stored->post_status ) {
			if ( '' !== $post->post_name && $post->post_name !== $stored->post_name ) {
				$clone            = clone $stored;
				$clone->post_name = $post->post_name;
				return (string) get_permalink( $clone );
			}
			return (string) get_permalink( $stored );
		}
		if ( $stored ) {
			if ( ! function_exists( 'get_sample_permalink' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			if ( function_exists( 'get_sample_permalink' ) ) {
				$sample = get_sample_permalink( $stored->ID, $post->post_title, $post->post_name );
				if ( is_array( $sample ) && isset( $sample[0], $sample[1] ) && '' !== (string) $sample[1] ) {
					return str_replace( array( '%postname%', '%pagename%' ), (string) $sample[1], (string) $sample[0] );
				}
			}
			return (string) get_permalink( $stored );
		}
		$slug = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		return '' !== $slug ? home_url( user_trailingslashit( $slug ) ) : home_url( '/' );
	}

	/**
	 * Served SEO title of the working post (MetaStore C2 resolution; unsaved
	 * editor values — the post title and SearchPilot's own SEO title field —
	 * are applied).
	 *
	 * @param \WP_Post $post  Working post.
	 * @param array    $input Input.
	 * @return array{title:string,source:string,origin:string}
	 */
	private function effective_title( \WP_Post $post, array $input ) {
		$args = array();
		if ( isset( $input['seo_title'] ) && null !== $input['seo_title'] ) {
			$args['custom'] = (string) $input['seo_title'];
		}
		return $this->meta->effective_title_details( $post, $args );
	}

	/**
	 * Served meta description of the working post and its source.
	 *
	 * @param \WP_Post $post  Working post.
	 * @param array    $input Input.
	 * @return array{description:string,source:string,origin:string}
	 */
	private function effective_description( \WP_Post $post, array $input ) {
		$args = array();
		if ( isset( $input['description'] ) && null !== $input['description'] ) {
			$args['custom'] = (string) $input['description'];
		}
		return $this->meta->effective_description_details( $post, true, $args );
	}

	/**
	 * Whether two head texts are the same once whitespace and case are normalized.
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return bool
	 */
	private static function same_text( $a, $b ) {
		return AnalysisRepository::text_hash( (string) $a ) === AnalysisRepository::text_hash( (string) $b );
	}

	/**
	 * Whether a post is a news report (NewsArticle schema, a news category,
	 * or covered by the Google News sitemap): short updates are normal there.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function is_news( \WP_Post $post ) {
		$type = $post->ID ? (string) $this->meta->get_post_field( $post->ID, 'schema_type' ) : '';
		if ( '' === $type ) {
			$type = $this->types->schema_type( $post->post_type );
		}
		if ( 'NewsArticle' === $type ) {
			return true;
		}
		if ( ! $post->ID ) {
			return false;
		}
		$news_categories = array_map( 'intval', (array) $this->settings->get( 'schema.news_categories', array() ) );
		if ( $news_categories && has_category( $news_categories, $post ) ) {
			return true;
		}
		if ( $this->settings->get( 'sitemap.news', false ) && in_array( $post->post_type, (array) $this->settings->get( 'sitemap.news_post_types', array( 'post' ) ), true ) ) {
			$sitemap_categories = array_map( 'intval', (array) $this->settings->get( 'sitemap.news_categories', array() ) );
			return ! $sitemap_categories || has_category( $sitemap_categories, $post );
		}
		return false;
	}

	/**
	 * Whether a post is noindexed (explicitly or by its post-type default).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function is_noindex( \WP_Post $post ) {
		$explicit = $this->meta->explicit_noindex( $post->ID );
		if ( null !== $explicit ) {
			return (bool) $explicit;
		}
		return true === $this->meta->post_type_noindex_default( $post->post_type );
	}

	/**
	 * Media-library facts for an attachment.
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public function attachment_info( $id ) {
		$id = (int) $id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		// The file on disk is the truth: image optimisers (Imagify, ShortPixel, …) shrink it
		// without updating the "filesize" WordPress stored at upload. The stored value is only
		// a fallback for files that are not on this server (offloaded media).
		$file = get_attached_file( $id );
		$size = $file && is_readable( $file ) ? (int) filesize( $file ) : 0;
		if ( ! $size ) {
			$size = (int) ( $meta['filesize'] ?? 0 );
		}
		return array(
			'id'       => $id,
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
			'filesize' => $size,
			'mime'     => (string) get_post_mime_type( $id ),
			'alt'      => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'file'     => $file ? wp_basename( $file ) : '',
		);
	}

	/**
	 * Media-library images used in the content.
	 *
	 * @param HtmlDocument $doc Document.
	 * @param string       $base Base URL.
	 * @return array{0:array<int,array>,1:array<string,int>} [id => info, src => id]
	 */
	private function attachments_in( HtmlDocument $doc, $base ) {
		$infos   = array();
		$by_src  = array();
		$lookups = 0;
		$uploads = wp_get_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		foreach ( $doc->query( '//img' ) as $node ) {
			/** @var \DOMElement $node */
			$src = trim( (string) ( $node->getAttribute( 'src' ) ?: $node->getAttribute( 'data-src' ) ) );
			if ( '' === $src ) {
				continue;
			}
			$abs = Url::absolute( $src, $base );
			$id  = 0;
			if ( preg_match( '/\bwp-image-(\d+)\b/', $node->getAttribute( 'class' ), $m ) ) {
				$id = (int) $m[1];
			} elseif ( ctype_digit( (string) $node->getAttribute( 'data-id' ) ) ) {
				$id = (int) $node->getAttribute( 'data-id' );
			} elseif ( '' !== $baseurl && 0 === strpos( $abs, $baseurl ) && $lookups < 15 ) {
				++$lookups;
				$id = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', Url::strip_query( $abs ) ) );
			}
			if ( $id ) {
				$by_src[ $abs ] = $id;
				if ( ! isset( $infos[ $id ] ) && count( $infos ) < 40 ) {
					$info = $this->attachment_info( $id );
					if ( $info ) {
						$infos[ $id ] = $info;
					}
				}
			}
		}
		return array( $infos, $by_src );
	}

	/**
	 * Ids → [ id, title, edit ] rows.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,array{id:int,title:string,edit:string}>
	 */
	private function titles_for( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * Stored Search Console queries for a URL (never calls an API).
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private function queries_for( $url ) {
		$class = '\\SearchPilot\\Analytics\\PerformanceRepository';
		if ( '' === $url || ! class_exists( $class ) || ! method_exists( $class, 'queries_for_page' ) ) {
			return null;
		}
		try {
			$rows = \SearchPilot\Plugin::instance()->get( $class )->queries_for_page( $url, '28d', 10 );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $rows ) ? $rows : null;
	}
}

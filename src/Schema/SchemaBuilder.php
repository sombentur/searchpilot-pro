<?php
/**
 * JSON-LD graph builder.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Schema;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\Breadcrumbs;
use SearchPilot\Seo\HeadOutput;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Seo\TitleResolver;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one linked @graph per page: WebSite, Organization or Person,
 * WebPage (typed by template), Article/BlogPosting/NewsArticle, authors,
 * primary image, BreadcrumbList, FAQPage (from visible FAQ blocks) and
 * VideoObject (from detected embeds). Only content visible on the page is
 * described; nothing is invented (no HowTo, Review, Product, Event…).
 *
 * Works outside the main query: every method takes an explicit context.
 */
class SchemaBuilder {

	const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle' );

	const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Titles/context.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Head output (canonical, images).
	 *
	 * @var HeadOutput
	 */
	private $head;

	/**
	 * Breadcrumbs.
	 *
	 * @var Breadcrumbs
	 */
	private $breadcrumbs;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings    Settings.
	 * @param MetaStore        $meta        Meta store.
	 * @param ContentTypes     $types       Content types.
	 * @param ConflictDetector $conflicts   Conflicts.
	 * @param TitleResolver    $titles      Titles.
	 * @param HeadOutput       $head        Head output.
	 * @param Breadcrumbs      $breadcrumbs Breadcrumbs.
	 */
	public function __construct( Settings $settings, MetaStore $meta, ContentTypes $types, ConflictDetector $conflicts, TitleResolver $titles, HeadOutput $head, Breadcrumbs $breadcrumbs ) {
		$this->settings    = $settings;
		$this->meta        = $meta;
		$this->types       = $types;
		$this->conflicts   = $conflicts;
		$this->titles      = $titles;
		$this->head        = $head;
		$this->breadcrumbs = $breadcrumbs;
	}

	/* --------------------------------------------------------------------- *
	 * Entry points
	 * --------------------------------------------------------------------- */

	/**
	 * Graph of a post, independent of the main query (previews, validation).
	 *
	 * @param \WP_Post $post Post.
	 * @return array {@context, @graph}
	 */
	public function for_post( \WP_Post $post ) {
		$context = $this->titles->post_context( $post );
		$mode    = (string) $this->settings->get( 'schema.breadcrumbs', 'displayed' );
		return $this->for_context(
			$context,
			array(
				'breadcrumbs' => 'off' !== $mode ? $this->breadcrumbs->trail( $post ) : array(),
				'url'         => $this->url_for( $context, true ),
			)
		);
	}

	/**
	 * Graph of the current front-end request.
	 *
	 * @return array {@context, @graph}
	 */
	public function for_current() {
		$context = $this->titles->current_context();
		$mode    = (string) $this->settings->get( 'schema.breadcrumbs', 'displayed' );
		$trail   = array();
		if ( 'always' === $mode ) {
			$trail = $this->breadcrumbs->trail( $context );
		} elseif ( 'displayed' === $mode && $this->breadcrumbs->was_rendered() ) {
			// Block themes render the template before wp_head.
			$trail = $this->breadcrumbs->last_trail();
		}
		return $this->for_context( $context, array( 'breadcrumbs' => $trail ) );
	}

	/**
	 * Builds the graph for a context.
	 *
	 * @param array $context Context (TitleResolver).
	 * @param array $opts    breadcrumbs (trail items to describe, [] = none), url (override).
	 * @return array {@context, @graph}
	 */
	public function for_context( array $context, array $opts = array() ) {
		$home      = home_url( '/' );
		$url       = isset( $opts['url'] ) && '' !== (string) $opts['url'] ? (string) $opts['url'] : $this->url_for( $context );
		$lang      = (string) get_bloginfo( 'language' );
		$publisher = $this->publisher_node();
		$nodes     = array( $this->website_node( $publisher['@id'], $lang ), $publisher );
		$people    = array();
		$article   = null;

		if ( '404' !== $context['type'] && '' !== $url ) {
			$page_id = $url . '#webpage';
			$post    = $context['post'] instanceof \WP_Post ? $context['post'] : null;
			$page    = $this->webpage_node( $context, $url, $lang, $publisher['@id'] );
			$type    = $post && in_array( $context['type'], array( 'singular', 'front' ), true ) ? $this->schema_type_for_post( $post ) : '';
			$image   = $post && in_array( $context['type'], array( 'singular', 'front' ), true ) ? $this->primary_image( $post, $url ) : null;
			if ( $image ) {
				$page['primaryImageOfPage'] = array( '@id' => $image['@id'] );
				$page['image']              = array( '@id' => $image['@id'] );
				$page['thumbnailUrl']       = $image['url'];
				$nodes[]                    = $image;
			}
			if ( in_array( $type, self::PAGE_TYPES, true ) && 'WebPage' !== $type ) {
				$page['@type'] = $type;
			}
			$trail = isset( $opts['breadcrumbs'] ) ? (array) $opts['breadcrumbs'] : array();
			if ( count( $trail ) >= 2 ) {
				$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			}
			if ( $post && in_array( $type, self::ARTICLE_TYPES, true ) ) {
				$article = $this->article_node( $post, $type, $context, $url, $lang, $publisher['@id'], $image, $people );
				$nodes[] = $article;
			}
			// Nothing derived from the content of password-protected posts (it is not public).
			if ( $post && in_array( $context['type'], array( 'singular', 'front' ), true ) && '' === (string) $post->post_password ) {
				$faq = $this->faq_questions( $post, $url );
				if ( $faq ) {
					$page['@type']      = array_values( array_unique( array_merge( (array) $page['@type'], array( 'FAQPage' ) ) ) );
					$page['mainEntity'] = $faq;
				}
				foreach ( $this->video_nodes( $post, $url, $article ? $article['@id'] : $page_id, (string) ( $page['description'] ?? '' ) ) as $video ) {
					$nodes[] = $video;
				}
			}
			if ( 'author' === $context['type'] && $context['user'] instanceof \WP_User ) {
				$person_id          = $this->person_id( $context['user'] );
				$page['mainEntity'] = array( '@id' => $person_id );
				$people[ $context['user']->ID ] = array( $context['user'], 'archive' );
			}
			array_splice( $nodes, 2, 0, array( $page ) );
			if ( count( $trail ) >= 2 ) {
				$nodes[] = $this->breadcrumb_node( $trail, $url );
			}
		}
		foreach ( $people as $person ) {
			if ( $this->person_id( $person[0] ) !== $publisher['@id'] ) {
				$nodes[] = $this->person_node( $person[0], $this->person_id( $person[0] ), $person[1] );
			}
		}
		$nodes = array_values( array_filter( array_map( array( __CLASS__, 'clean' ), $nodes ) ) );
		/**
		 * Filters the JSON-LD graph nodes before output.
		 *
		 * @param array $nodes   Graph nodes.
		 * @param array $context Request context.
		 */
		$nodes = (array) apply_filters( 'searchpilot_schema_graph', $nodes, $context );
		return self::wrap( $nodes );
	}

	/**
	 * Standalone BreadcrumbList graph (for the footer in "displayed" mode).
	 *
	 * @param array  $trail Trail.
	 * @param string $url   Page URL.
	 * @return array
	 */
	public function breadcrumb_graph( array $trail, $url ) {
		return self::wrap( array( self::clean( $this->breadcrumb_node( $trail, $url ) ) ) );
	}

	/**
	 * {@context, @graph} wrapper.
	 *
	 * @param array $nodes Nodes.
	 * @return array
	 */
	public static function wrap( array $nodes ) {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);
	}

	/**
	 * Encodes a graph for a <script> element.
	 *
	 * @param array $graph  Graph.
	 * @param bool  $pretty Pretty print.
	 * @return string
	 */
	public static function encode( array $graph, $pretty = false ) {
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ( $pretty ? JSON_PRETTY_PRINT : 0 ) );
		if ( ! is_string( $json ) ) {
			return '';
		}
		return str_replace( array( '</', '<!--' ), array( '<\/', '\\u003C!--' ), $json );
	}

	/**
	 * <script> element for a graph.
	 *
	 * @param array  $graph Graph.
	 * @param string $class Extra class.
	 * @return string
	 */
	public static function script( array $graph, $class = '' ) {
		$json = self::encode( $graph );
		if ( '' === $json ) {
			return '';
		}
		return '<script type="application/ld+json" class="' . esc_attr( trim( 'searchpilot-schema ' . $class ) ) . '">' . $json . '</script>' . "\n";
	}

	/* --------------------------------------------------------------------- *
	 * Nodes
	 * --------------------------------------------------------------------- */

	/**
	 * WebSite node (no SearchAction: Google retired the sitelinks search box).
	 *
	 * @param string $publisher_id Publisher @id.
	 * @param string $lang         Language.
	 * @return array
	 */
	private function website_node( $publisher_id, $lang ) {
		$home = home_url( '/' );
		return array(
			'@type'       => 'WebSite',
			'@id'         => $home . '#website',
			'url'         => $home,
			'name'        => (string) get_bloginfo( 'name' ),
			'description' => (string) get_bloginfo( 'description' ),
			'publisher'   => array( '@id' => $publisher_id ),
			'inLanguage'  => $lang,
		);
	}

	/**
	 * Organization or Person representing the site.
	 *
	 * @return array
	 */
	public function publisher_node() {
		$home = home_url( '/' );
		if ( 'person' === $this->settings->get( 'schema.entity', 'organization' ) ) {
			$user = get_userdata( (int) $this->settings->get( 'schema.person_user', 0 ) );
			if ( $user instanceof \WP_User ) {
				$node        = $this->person_node( $user, $home . '#person', 'site' );
				$node['url'] = $home;
				return $node;
			}
			$name = trim( (string) $this->settings->get( 'schema.org_name', '' ) );
			return array(
				'@type'  => 'Person',
				'@id'    => $home . '#person',
				'name'   => '' !== $name ? $name : (string) get_bloginfo( 'name' ),
				'url'    => $home,
				'sameAs' => $this->same_as(),
			);
		}
		$name = trim( (string) $this->settings->get( 'schema.org_name', '' ) );
		$name = '' !== $name ? $name : (string) get_bloginfo( 'name' );
		$node = array(
			'@type'     => 'Organization',
			'@id'       => $home . '#organization',
			'name'      => $name,
			'legalName' => trim( (string) $this->settings->get( 'schema.org_legal_name', '' ) ),
			'url'       => $home,
			'sameAs'    => $this->same_as(),
		);
		$logo = $this->logo_image();
		if ( $logo ) {
			$node['logo']  = array(
				'@type'      => 'ImageObject',
				'@id'        => $home . '#/schema/logo/image/',
				'url'        => $logo['url'],
				'contentUrl' => $logo['url'],
				'width'      => $logo['width'] ? $logo['width'] : null,
				'height'     => $logo['height'] ? $logo['height'] : null,
				'caption'    => $name,
				'inLanguage' => (string) get_bloginfo( 'language' ),
			);
			$node['image'] = array( '@id' => $home . '#/schema/logo/image/' );
		}
		$email = sanitize_email( (string) $this->settings->get( 'schema.contact_email', '' ) );
		$phone = trim( (string) $this->settings->get( 'schema.contact_phone', '' ) );
		if ( '' !== $email || '' !== $phone ) {
			$node['contactPoint'] = array(
				array(
					'@type'       => 'ContactPoint',
					'contactType' => 'customer support',
					'email'       => $email,
					'telephone'   => $phone,
				),
			);
		}
		return $node;
	}

	/**
	 * Logo: the schema setting, else the theme's custom logo.
	 *
	 * @return array|null
	 */
	public function logo_image() {
		$id = (int) $this->settings->get( 'schema.org_logo', 0 );
		if ( ! $id ) {
			$id = (int) get_theme_mod( 'custom_logo', 0 );
		}
		return $id ? HeadOutput::attachment_image( $id ) : null;
	}

	/**
	 * sameAs profile URLs.
	 *
	 * @return string[]
	 */
	private function same_as() {
		return array_values( array_filter( array_map( 'esc_url_raw', (array) $this->settings->get( 'schema.same_as', array() ) ) ) );
	}

	/**
	 * WebPage node typed by template.
	 *
	 * @param array  $context      Context.
	 * @param string $url          URL.
	 * @param string $lang         Language.
	 * @param string $publisher_id Publisher @id.
	 * @return array
	 */
	private function webpage_node( array $context, $url, $lang, $publisher_id ) {
		$types = array(
			'home'              => 'CollectionPage',
			'blog'              => 'CollectionPage',
			'term'              => 'CollectionPage',
			'post_type_archive' => 'CollectionPage',
			'date'              => 'CollectionPage',
			'author'            => 'ProfilePage',
			'search'            => 'SearchResultsPage',
		);
		$node  = array(
			'@type'       => $types[ $context['type'] ] ?? 'WebPage',
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => $this->titles->title( $context ),
			'description' => $this->titles->description( $context ),
			'isPartOf'    => array( '@id' => home_url( '/' ) . '#website' ),
			'inLanguage'  => $lang,
		);
		$post  = $context['post'] instanceof \WP_Post ? $context['post'] : null;
		if ( $post && in_array( $context['type'], array( 'singular', 'front', 'blog' ), true ) ) {
			$node['datePublished'] = (string) get_post_time( 'c', true, $post );
			$node['dateModified']  = (string) get_post_modified_time( 'c', true, $post );
		}
		if ( in_array( $context['type'], array( 'front', 'home' ), true ) ) {
			$node['about'] = array( '@id' => $publisher_id );
		}
		return $node;
	}

	/**
	 * Article / BlogPosting / NewsArticle node.
	 *
	 * @param \WP_Post   $post         Post.
	 * @param string     $type         Article type.
	 * @param array      $context      Context.
	 * @param string     $url          URL.
	 * @param string     $lang         Language.
	 * @param string     $publisher_id Publisher @id.
	 * @param array|null $image        Primary image node.
	 * @param array      $people       Authors to add (by reference).
	 * @return array
	 */
	private function article_node( \WP_Post $post, $type, array $context, $url, $lang, $publisher_id, $image, array &$people ) {
		$headline = trim( wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) );
		$node     = array(
			'@type'            => $type,
			'@id'              => $url . '#article',
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'headline'         => Text::truncate( '' !== $headline ? $headline : $this->titles->title( $context ), 110 ),
			'description'      => $this->titles->description( $context ),
			'datePublished'    => (string) get_post_time( 'c', true, $post ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
			'publisher'        => array( '@id' => $publisher_id ),
			'inLanguage'       => $lang,
			'wordCount'        => '' === (string) $post->post_password ? Text::word_count( Text::strip( (string) $post->post_content ) ) : null,
		);
		if ( $image ) {
			$node['image']        = array( '@id' => $image['@id'] );
			$node['thumbnailUrl'] = $image['url'];
		}
		$authors = array();
		foreach ( $this->authors( $post ) as $user ) {
			$authors[]           = array( '@id' => $this->person_id( $user ) );
			$people[ $user->ID ] = array( $user, 'author' );
		}
		if ( $authors ) {
			$node['author'] = 1 === count( $authors ) ? $authors[0] : $authors;
		}
		if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$primary = $this->meta->primary_term( $post->ID, 'category' );
			$term    = $primary ? get_term( $primary, 'category' ) : null;
			if ( $term instanceof \WP_Term ) {
				$node['articleSection'] = array( $term->name );
			}
		}
		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			$tags = get_the_terms( $post, 'post_tag' );
			if ( is_array( $tags ) && $tags ) {
				$node['keywords'] = array_values( wp_list_pluck( $tags, 'name' ) );
			}
		}
		return $node;
	}

	/**
	 * Authors of a post (Co-Authors Plus aware).
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_User[]
	 */
	private function authors( \WP_Post $post ) {
		$users = array();
		if ( function_exists( 'get_coauthors' ) ) {
			foreach ( (array) get_coauthors( $post->ID ) as $coauthor ) {
				if ( $coauthor instanceof \WP_User ) {
					$users[] = $coauthor;
				}
			}
		}
		if ( ! $users ) {
			$user = get_userdata( (int) $post->post_author );
			if ( $user instanceof \WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	/**
	 * @id of a person.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	public function person_id( \WP_User $user ) {
		$home = home_url( '/' );
		if ( 'person' === $this->settings->get( 'schema.entity', 'organization' ) && (int) $this->settings->get( 'schema.person_user', 0 ) === (int) $user->ID ) {
			return $home . '#person';
		}
		return $home . '#/schema/person/' . md5( 'searchpilot-person-' . $user->ID );
	}

	/**
	 * Person node.
	 *
	 * @param \WP_User $user User.
	 * @param string   $id   @id.
	 * @param string   $role author (wrote the published post: has posts), archive, site.
	 * @return array
	 */
	private function person_node( \WP_User $user, $id, $role = 'author' ) {
		$social = HeadOutput::user_social( $user->ID );
		$same   = array();
		foreach ( $social as $network => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'x' === $network && ! preg_match( '#^https?://#i', $value ) ) {
				$handle = HeadOutput::twitter_handle( $value );
				$value  = '' !== $handle ? 'https://x.com/' . ltrim( $handle, '@' ) : '';
			}
			if ( preg_match( '#^https?://#i', $value ) ) {
				$same[] = esc_url_raw( $value );
			}
		}
		$node = array(
			'@type'       => 'Person',
			'@id'         => $id,
			'name'        => $user->display_name,
			'description' => Text::truncate( Text::strip( (string) get_user_meta( $user->ID, 'description', true ) ), 300 ),
			'sameAs'      => array_values( array_unique( $same ) ),
		);
		// Authors of a published post have posts, so only the setting matters (no count query).
		$indexable = 'author' === $role ? (bool) $this->settings->get( 'content.authors_index', true ) : ( 'archive' === $role && $this->types->is_author_indexable( $user->ID ) );
		if ( $indexable ) {
			$node['url'] = get_author_posts_url( $user->ID, $user->user_nicename );
		} elseif ( '' !== (string) $user->user_url ) {
			$node['url'] = esc_url_raw( $user->user_url );
		}
		if ( get_option( 'show_avatars' ) ) {
			$avatar = get_avatar_url( $user->ID, array( 'size' => 96 ) );
			if ( $avatar ) {
				$node['image'] = array(
					'@type'      => 'ImageObject',
					'@id'        => home_url( '/' ) . '#/schema/person/image/' . md5( 'searchpilot-person-' . $user->ID ),
					'url'        => $avatar,
					'contentUrl' => $avatar,
					'caption'    => $user->display_name,
				);
			}
		}
		return $node;
	}

	/**
	 * Primary image (featured image) node.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Page URL.
	 * @return array|null
	 */
	private function primary_image( \WP_Post $post, $url ) {
		$thumb = (int) get_post_thumbnail_id( $post );
		$image = $thumb ? HeadOutput::attachment_image( $thumb ) : null;
		if ( ! $image && '' === (string) $post->post_password ) {
			// No featured image: the first image visible in the content (as for og:image).
			$image = HeadOutput::content_image( (string) $post->post_content );
		}
		if ( ! $image ) {
			return null;
		}
		return array(
			'@type'      => 'ImageObject',
			'@id'        => $url . '#primaryimage',
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
			'width'      => $image['width'] ? $image['width'] : null,
			'height'     => $image['height'] ? $image['height'] : null,
			'caption'    => $image['alt'],
			'inLanguage' => (string) get_bloginfo( 'language' ),
		);
	}

	/**
	 * BreadcrumbList node.
	 *
	 * @param array  $trail Trail items.
	 * @param string $url   Page URL (used for the last item when it has none).
	 * @return array
	 */
	public function breadcrumb_node( array $trail, $url ) {
		$items = array();
		$trail = array_values( $trail );
		$count = count( $trail );
		foreach ( $trail as $i => $crumb ) {
			$item_url = (string) ( $crumb['url'] ?? '' );
			if ( '' === $item_url && $i === $count - 1 ) {
				$item_url = (string) $url;
			}
			if ( '' === $item_url && $i < $count - 1 ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => count( $items ) + 1,
				'name'     => (string) $crumb['name'],
				'item'     => $item_url,
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * FAQ questions from the searchpilot/faq block, or from core Details blocks when the FAQ field is on.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Page URL.
	 * @return array
	 */
	public function faq_questions( \WP_Post $post, $url ) {
		if ( ! $this->settings->get( 'schema.faq', true ) ) {
			return array();
		}
		$content = (string) $post->post_content;
		$pairs   = array();
		if ( false !== strpos( $content, '<!-- wp:searchpilot/faq' ) ) {
			foreach ( self::find_blocks( parse_blocks( $content ), 'searchpilot/faq' ) as $block ) {
				foreach ( (array) ( $block['attrs']['items'] ?? array() ) as $item ) {
					if ( is_array( $item ) ) {
						$pairs[] = array( (string) ( $item['question'] ?? '' ), (string) ( $item['answer'] ?? '' ) );
					}
				}
			}
		} elseif ( false !== strpos( $content, '<!-- wp:details' ) && $this->meta->get_post_field( $post->ID, 'faq' ) ) {
			foreach ( self::find_blocks( parse_blocks( $content ), 'core/details' ) as $block ) {
				$question = preg_match( '#<summary[^>]*>(.*?)</summary>#is', (string) $block['innerHTML'], $m ) ? $m[1] : '';
				$answer   = '';
				foreach ( (array) $block['innerBlocks'] as $inner ) {
					$answer .= (string) render_block( $inner );
				}
				$pairs[] = array( $question, $answer );
			}
		}
		$questions = array();
		foreach ( $pairs as $i => $pair ) {
			$question = trim( Text::strip( $pair[0] ) );
			$answer   = self::answer_html( $pair[1] );
			if ( '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}
			$questions[] = array(
				'@type'          => 'Question',
				'@id'            => $url . '#faq-question-' . ( $i + 1 ),
				'position'       => count( $questions ) + 1,
				'name'           => $question,
				'answerCount'    => 1,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}
		return $questions;
	}

	/**
	 * Answer HTML limited to the tags Google accepts in FAQ answers.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function answer_html( $html ) {
		$allowed = array(
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'h5'     => array(),
			'h6'     => array(),
			'br'     => array(),
			'ol'     => array(),
			'ul'     => array(),
			'li'     => array(),
			'a'      => array( 'href' => true ),
			'p'      => array(),
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
		);
		$html    = wp_kses( (string) $html, $allowed );
		return trim( (string) preg_replace( '/\s+/u', ' ', $html ) );
	}

	/**
	 * Blocks of a name anywhere in a block tree.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $name   Block name.
	 * @return array
	 */
	public static function find_blocks( array $blocks, $name ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === $name ) {
				$out[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::find_blocks( (array) $block['innerBlocks'], $name ) );
			}
		}
		return $out;
	}

	/**
	 * VideoObject nodes from the video detector's cache.
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $url         Page URL.
	 * @param string   $parent_id   @id the videos are part of.
	 * @param string   $description Fallback description.
	 * @return array
	 */
	private function video_nodes( \WP_Post $post, $url, $parent_id, $description ) {
		if ( ! $this->settings->get( 'schema.video', true ) || ! class_exists( '\SearchPilot\Sitemap\VideoDetector' ) ) {
			return array();
		}
		$class = '\SearchPilot\Sitemap\VideoDetector';
		try {
			if ( ! method_exists( $class, 'get_videos' ) ) {
				return array();
			}
			$method = new \ReflectionMethod( $class, 'get_videos' );
			$videos = $method->isStatic()
				? (array) call_user_func( array( $class, 'get_videos' ), $post->ID )
				: (array) \SearchPilot\Plugin::instance()->get( $class )->get_videos( $post->ID );
		} catch ( \Throwable $e ) {
			return array();
		}
		$nodes = array();
		foreach ( array_slice( $videos, 0, 10 ) as $i => $video ) {
			// Without the provider's upload date the item would be inaccurate (the video sitemap omits such videos too).
			if ( ! is_array( $video ) || empty( $video['thumbnail_loc'] ) || empty( $video['upload_date'] ) ) {
				continue;
			}
			$name    = trim( (string) ( $video['title'] ?? '' ) );
			$name    = '' !== $name ? $name : trim( wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) );
			$desc    = trim( (string) ( $video['description'] ?? '' ) );
			$nodes[] = array(
				'@type'        => 'VideoObject',
				'@id'          => $url . '#video-' . ( $i + 1 ),
				'name'         => $name,
				'description'  => Text::truncate( '' !== $desc ? $desc : ( '' !== $description ? $description : $name ), 300 ),
				'thumbnailUrl' => esc_url_raw( (string) $video['thumbnail_loc'] ),
				'uploadDate'   => (string) $video['upload_date'],
				'embedUrl'     => ! empty( $video['player_loc'] ) ? esc_url_raw( (string) $video['player_loc'] ) : '',
				'contentUrl'   => ! empty( $video['content_loc'] ) ? esc_url_raw( (string) $video['content_loc'] ) : '',
				'duration'     => ! empty( $video['duration'] ) ? self::iso_duration( (int) $video['duration'] ) : '',
				'isPartOf'     => array( '@id' => $parent_id ),
			);
		}
		return $nodes;
	}

	/**
	 * ISO 8601 duration from seconds.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function iso_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( ! $seconds ) {
			return '';
		}
		$h   = intdiv( $seconds, 3600 );
		$m   = intdiv( $seconds % 3600, 60 );
		$s   = $seconds % 60;
		$out = 'PT' . ( $h ? $h . 'H' : '' ) . ( $m ? $m . 'M' : '' ) . ( $s ? $s . 'S' : '' );
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Effective schema type of a post: field → news category → post type setting.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Article|BlogPosting|NewsArticle|WebPage|AboutPage|ContactPage|CollectionPage|none
	 */
	public function schema_type_for_post( \WP_Post $post ) {
		if ( 'product' === $post->post_type && ( class_exists( 'WooCommerce' ) || ! $this->conflicts->owns( 'product_schema' ) ) ) {
			return 'WebPage';
		}
		$valid = array_merge( self::ARTICLE_TYPES, self::PAGE_TYPES, array( 'none' ) );
		$field = trim( (string) $this->meta->get_post_field( $post->ID, 'schema_type' ) );
		if ( in_array( $field, $valid, true ) ) {
			return $field;
		}
		$type = (string) $this->types->schema_type( $post->post_type );
		$type = in_array( $type, $valid, true ) ? $type : 'WebPage';
		if ( in_array( $type, self::ARTICLE_TYPES, true ) && 'NewsArticle' !== $type ) {
			$news = array_filter( array_map( 'intval', (array) $this->settings->get( 'schema.news_categories', array() ) ) );
			if ( $news && is_object_in_taxonomy( $post->post_type, 'category' ) && has_term( $news, 'category', $post ) ) {
				$type = 'NewsArticle';
			}
		}
		return $type;
	}

	/**
	 * URL a graph describes.
	 *
	 * @param array $context Context.
	 * @param bool  $preview Allow unpublished posts (admin previews).
	 * @return string
	 */
	private function url_for( array $context, $preview = false ) {
		$url = $this->head->canonical( $context );
		if ( '' !== $url ) {
			return $url;
		}
		if ( 'search' === $context['type'] ) {
			return HeadOutput::paged_url( get_search_link( $context['search'] ), (int) $context['page'] );
		}
		if ( $preview && $context['post'] instanceof \WP_Post ) {
			return (string) get_permalink( $context['post'] );
		}
		return '';
	}

	/**
	 * Removes empty values recursively (keeps 0 and false).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$item = self::clean( $item );
			if ( null === $item || '' === $item || array() === $item ) {
				continue;
			}
			$out[ $key ] = $item;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) ? array_values( $out ) : $out;
	}
}

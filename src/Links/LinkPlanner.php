<?php
/**
 * Fix plans for link issues.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Fixes\ContentEditor;
use SearchPilot\Fixes\Plan;
use SearchPilot\Seo\Analysis\ContentRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Planners (contract C4, mode "assisted": preview + one click) for:
 *
 *   no_internal_links  links FROM the post to up to three related articles —
 *                      in-text anchors where a sentence names the topic,
 *                      otherwise a related-articles list at the end;
 *   orphan_page        links TO the post from up to three related articles
 *                      (one `post_content` change per source post), each an
 *                      in-text anchor or an entry in that article's list;
 *   weak_auto_links    removal of links earlier versions inserted with weak
 *                      link text (one change per post; the <a> is unwrapped,
 *                      the words stay).
 *
 * Plans never edit page-builder layouts, never link a post to itself and use
 * the same ranking, limits and anchor rules as the Internal Links
 * suggestions. Every inserted link carries data-spp-link="fix-{issue id}" for
 * surgical undo.
 */
class LinkPlanner {

	/**
	 * Links per plan.
	 */
	const MAX_LINKS = 3;

	/**
	 * Suggester.
	 *
	 * @var Suggester
	 */
	private $suggester;

	/**
	 * Index.
	 *
	 * @var ContentIndex
	 */
	private $index;

	/**
	 * Links module.
	 *
	 * @var LinksModule
	 */
	private $links;

	/**
	 * Constructor.
	 *
	 * @param Suggester    $suggester Suggester.
	 * @param ContentIndex $index     Index.
	 * @param LinksModule  $links     Links module.
	 */
	public function __construct( Suggester $suggester, ContentIndex $index, LinksModule $links ) {
		$this->suggester = $suggester;
		$this->index     = $index;
		$this->links     = $links;
	}

	/**
	 * Plan for "Automatic links with weak link text": unwraps every listed
	 * link (or only `$issue['only']` — link keys from the review panel), one
	 * `post_content` change per post.
	 *
	 * @param array $issue Issue (optionally with only: string[] link keys).
	 * @return array|\WP_Error Plan.
	 */
	public function plan_weak_auto_links( array $issue ) {
		$items = $this->links->weak_auto_links( 500 );
		if ( isset( $issue['only'] ) ) {
			$only  = array_flip( array_map( 'strval', (array) $issue['only'] ) );
			$items = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $only ) {
						return isset( $only[ $item['key'] ] );
					}
				)
			);
		}
		if ( ! $items ) {
			return new \WP_Error( 'searchpilot_plan_empty', __( 'No automatic links with weak link text are left — nothing needs to change.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$by_post = array();
		foreach ( $items as $item ) {
			$by_post[ $item['post_id'] ][] = $item;
		}
		$changes = array();
		$gone    = 0;
		foreach ( $by_post as $post_id => $links ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$markers = array();
			$single  = array();
			foreach ( $links as $link ) {
				if ( ! empty( $link['html'] ) ) {
					$single[] = (string) $link['html']; // A link fix: its marker is shared, remove this link only.
				} else {
					$markers[] = $link['marker'];
				}
				if ( 'target_gone' === $link['reason'] ) {
					++$gone;
				}
			}
			$content = AutoLinks::remove( (string) $post->post_content, $markers );
			foreach ( $single as $html ) {
				$content = AutoLinks::unwrap( $content, $html );
			}
			list( $before, $after ) = ContentEditor::fragments( (string) $post->post_content, $content );
			$changes[]              = Plan::change(
				'post_content',
				(int) $post->ID,
				'post_content',
				/* translators: 1: number of links, 2: post title. */
				sprintf( _n( '%1$d link in “%2$s”', '%1$d links in “%2$s”', count( $links ), 'searchpilot-pro' ), count( $links ), Suggester::plain_title( $post ) ),
				$before,
				$after,
				$content
			);
		}
		$count    = count( $items );
		$summary  = sprintf(
			/* translators: 1: number of links, 2: number of posts. */
			_n( 'Remove %1$d automatic link with weak link text from %2$d post', 'Remove %1$d automatic links with weak link text from %2$d posts', $count, 'searchpilot-pro' ),
			$count,
			count( $changes )
		);
		$warnings = array( __( 'Only the links are removed — the words stay in the text. Links people added are never touched.', 'searchpilot-pro' ) );
		if ( $gone ) {
			/* translators: %d: number of links. */
			$warnings[] = sprintf( _n( '%d of them points to a page that is no longer published.', '%d of them point to pages that are no longer published.', $gone, 'searchpilot-pro' ), $gone );
		}
		return Plan::create( $issue, $summary, $changes, $warnings, array( 'types' => array( 'weak_auto_links' ) ) );
	}

	/**
	 * Plan for "No internal links in the content".
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error Plan.
	 */
	public function plan_no_internal_links( array $issue ) {
		$post = $this->post_for( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! ContentRenderer::is_editable( $post ) ) {
			return $this->builder_error( $post );
		}
		$options = $this->suggester->outbound_options( $post->ID, self::MAX_LINKS );
		if ( ! $options ) {
			return new \WP_Error(
				'searchpilot_nothing_to_link',
				__( 'SearchPilot found no related published article to link to from this post (or the post is too short to take a link). Link to related pages by hand when you next edit it.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$token = 'fix-' . (int) ( $issue['id'] ?? 0 );
		$built = $this->suggester->build_content( $post, $options, $token );
		if ( ! $built['applied'] ) {
			return new \WP_Error( 'searchpilot_nothing_to_link', __( 'The suggested links could not be placed in this post any more. Re-check the page and try again.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$titles = array();
		foreach ( $built['applied'] as $option ) {
			$titles[] = '“' . Suggester::plain_title( (int) $option['target_id'] ) . '”';
		}
		$summary = sprintf(
			/* translators: 1: number of links, 2: target titles. */
			_n( 'Add %1$d internal link: %2$s', 'Add %1$d internal links: %2$s', count( $titles ), 'searchpilot-pro' ),
			count( $titles ),
			implode( ', ', $titles )
		);
		return Plan::create(
			$issue,
			$summary,
			array( $this->change( $post, $built['content'], $token ) ),
			$this->warnings( $built['applied'] ),
			array(
				'types'   => array( 'no_internal_links' ),
				'objects' => array( (int) $post->ID ),
			)
		);
	}

	/**
	 * Plan for "No other article links to this page".
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error Plan.
	 */
	public function plan_orphan_page( array $issue ) {
		$post = $this->post_for( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$options = $this->suggester->inbound_options( $post->ID, self::MAX_LINKS );
		if ( ! $options ) {
			return new \WP_Error(
				'searchpilot_nothing_to_link',
				__( 'No related published article can take a link to this page right now (none found, or they already have their maximum number of links). Link to it by hand from an article on the same topic.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$token   = 'fix-' . (int) ( $issue['id'] ?? 0 );
		$changes = array();
		$placed  = array();
		$titles  = array();
		foreach ( $options as $option ) {
			$source = get_post( (int) $option['source_id'] );
			if ( ! $source ) {
				continue;
			}
			$built = $this->suggester->build_content( $source, array( $option ), $token );
			if ( ! $built['applied'] ) {
				continue;
			}
			$changes[] = $this->change( $source, $built['content'], $token );
			$placed    = array_merge( $placed, $built['applied'] );
			$titles[]  = '“' . Suggester::plain_title( $source ) . '”';
		}
		if ( ! $changes ) {
			return new \WP_Error( 'searchpilot_nothing_to_link', __( 'The suggested links could not be placed any more. Re-check the page and try again.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$summary = sprintf(
			/* translators: 1: number of articles, 2: article titles. */
			_n( 'Link to this page from %1$d related article: %2$s', 'Link to this page from %1$d related articles: %2$s', count( $titles ), 'searchpilot-pro' ),
			count( $titles ),
			implode( ', ', $titles )
		);
		return Plan::create(
			$issue,
			$summary,
			$changes,
			$this->warnings( $placed ),
			array(
				'types'   => array( 'orphan_page' ),
				'objects' => array( (int) $post->ID ),
			)
		);
	}

	/**
	 * The issue's post, when it takes part in internal linking.
	 *
	 * @param array $issue Issue.
	 * @return \WP_Post|\WP_Error
	 */
	private function post_for( array $issue ) {
		$post = 'post' === ( $issue['object_type'] ?? '' ) ? get_post( (int) ( $issue['object_id'] ?? 0 ) ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists, so there is nothing to fix.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		if ( ! $this->index->is_eligible( $post ) ) {
			return new \WP_Error( 'searchpilot_not_eligible', __( 'This post is not part of internal linking: it must be published, indexable, of a post type selected in Internal Links → Settings, and not excluded.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		return $post;
	}

	/**
	 * Page-builder refusal.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Error
	 */
	private function builder_error( \WP_Post $post ) {
		return new \WP_Error(
			'searchpilot_fix_builder',
			/* translators: %s: page builder name. */
			sprintf( __( 'This post is built with %s, so SearchPilot does not edit its content. Add the links in the page builder.', 'searchpilot-pro' ), ContentRenderer::builder_name( ContentRenderer::builder_of( $post ) ) ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A post_content change with a diff of the changed fragments.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $content New content.
	 * @param string   $token   Link marker value.
	 * @return array
	 */
	private function change( \WP_Post $post, $content, $token ) {
		list( $before, $after ) = ContentEditor::fragments( (string) $post->post_content, (string) $content );
		$change                 = Plan::change(
			'post_content',
			(int) $post->ID,
			'post_content',
			/* translators: %s: post title. */
			sprintf( __( 'Content of “%s”', 'searchpilot-pro' ), Suggester::plain_title( $post ) ),
			$before,
			$after,
			(string) $content
		);
		$change['marker']       = 'data-spp-link="' . $token . '"';
		return $change;
	}

	/**
	 * Preview notes.
	 *
	 * @param array[] $placed Placed options.
	 * @return string[]
	 */
	private function warnings( array $placed ) {
		$related = 0;
		foreach ( $placed as $option ) {
			if ( 'related' === $option['kind'] ) {
				++$related;
			}
		}
		if ( ! $related ) {
			return array();
		}
		return array(
			sprintf(
				/* translators: %d: number of links. */
				_n( '%d link goes into a short “Related articles” list at the end of the post, because no sentence in it names that topic. The list uses the article title as link text.', '%d links go into a short “Related articles” list at the end of the post, because no sentence in it names those topics. The list uses the article titles as link text.', $related, 'searchpilot-pro' ),
				$related
			),
		);
	}
}

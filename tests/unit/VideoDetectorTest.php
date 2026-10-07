<?php
/**
 * Video URL parsing tests.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Sitemap\VideoDetector;

// phpcs:disable

class VideoDetectorTest extends \PHPUnit\Framework\TestCase {

	public function test_youtube_url_forms() {
		$forms = array(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
			'https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=10s',
			'https://youtu.be/dQw4w9WgXcQ',
			'https://youtu.be/dQw4w9WgXcQ?si=abc',
			'https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0',
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
			'https://www.youtube.com/shorts/dQw4w9WgXcQ',
			'https://www.youtube.com/live/dQw4w9WgXcQ?feature=share',
			'//www.youtube.com/embed/dQw4w9WgXcQ',
		);
		foreach ( $forms as $url ) {
			$parsed = VideoDetector::parse_video_url( $url );
			$this->assertNotNull( $parsed, $url );
			$this->assertSame( 'youtube', $parsed['provider'], $url );
			$this->assertSame( 'dQw4w9WgXcQ', $parsed['id'], $url );
			$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $parsed['url'] );
		}
	}

	public function test_vimeo_url_forms() {
		foreach ( array( 'https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871?h=abc', 'https://vimeo.com/channels/staffpicks/76979871', 'https://vimeo.com/76979871/2a3b4c5d6e', 'https://vimeo.com/showcase/123/video/76979871', 'https://vimeo.com/groups/name/videos/76979871' ) as $url ) {
			$parsed = VideoDetector::parse_video_url( $url );
			$this->assertNotNull( $parsed, $url );
			$this->assertSame( 'vimeo', $parsed['provider'], $url );
			$this->assertSame( '76979871', $parsed['id'], $url );
		}
	}

	public function test_self_hosted_and_rejections() {
		$self = VideoDetector::parse_video_url( 'https://example.com/wp-content/uploads/2026/09/clip.MP4?ver=2' );
		$this->assertSame( 'self', $self['provider'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/09/clip.MP4', $self['url'] );
		$this->assertSame( 16, strlen( $self['id'] ) );

		foreach ( array( 'https://www.youtube.com/watch?v=short', 'https://www.youtube.com/channel/UC123', 'https://vimeo.com/about', 'https://example.com/page/', 'ftp://example.com/a.mp4', 'not a url', 'https://evil.com/youtube.com/watch?v=dQw4w9WgXcQ' ) as $url ) {
			$this->assertNull( VideoDetector::parse_video_url( $url ), $url );
		}
	}

	public function test_extract_from_blocks_iframes_shortcodes_and_lines() {
		$content = <<<'HTML'
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube"} -->
<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=dQw4w9WgXcQ
</div></figure>
<!-- /wp:embed -->
<p>Intro text</p>
<iframe width="560" src="https://player.vimeo.com/video/76979871?h=1" frameborder="0"></iframe>
[video mp4="https://example.com/wp-content/uploads/a.mp4" poster="https://example.com/wp-content/uploads/a.jpg"][/video]
<video controls src="https://example.com/wp-content/uploads/b.webm" poster="/wp-content/uploads/b.jpg"></video>
<p>https://youtu.be/aaaaaaaaaaa</p>
Some text with https://www.youtube.com/watch?v=bbbbbbbbbbb inline is not an embed.
[embed]https://vimeo.com/12345678[/embed]
HTML;
		$found = VideoDetector::extract( $content );
		$keys  = array_map(
			static function ( $v ) {
				return $v['provider'] . ':' . $v['id'];
			},
			$found
		);
		$this->assertContains( 'youtube:dQw4w9WgXcQ', $keys );
		$this->assertContains( 'vimeo:76979871', $keys );
		$this->assertContains( 'youtube:aaaaaaaaaaa', $keys );
		$this->assertContains( 'vimeo:12345678', $keys );
		$this->assertNotContains( 'youtube:bbbbbbbbbbb', $keys );
		$this->assertCount( 6, $found, 'Duplicates are merged: ' . implode( ', ', $keys ) );

		$posters = array();
		foreach ( $found as $v ) {
			if ( 'self' === $v['provider'] ) {
				$posters[ basename( $v['url'] ) ] = $v['poster'];
			}
		}
		$this->assertSame( 'https://example.com/wp-content/uploads/a.jpg', $posters['a.mp4'] );
		$this->assertSame( '/wp-content/uploads/b.jpg', $posters['b.webm'] );
	}

	public function test_extract_legacy_embed_block_and_empty_content() {
		$this->assertSame( array(), VideoDetector::extract( '' ) );
		$found = VideoDetector::extract( '<!-- wp:core-embed/youtube {"url":"https://youtu.be/dQw4w9WgXcQ"} --><!-- /wp:core-embed/youtube -->' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'dQw4w9WgXcQ', $found[0]['id'] );
	}
}

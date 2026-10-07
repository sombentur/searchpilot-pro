<?php
/**
 * Tests for MetaStore's companion resolution helpers (Rank Math compatible
 * resolver, plain text normalization).
 *
 * @package SearchPilot
 */

namespace {
	// phpcs:disable
	if ( ! class_exists( 'WP_Post' ) ) {
		/** Minimal stand-in for WordPress' post object. */
		class WP_Post {
			public $ID           = 0;
			public $post_type    = 'post';
			public $post_title   = '';
			public $post_content = '';
			public $post_excerpt = '';
			public $post_author  = 0;
			public function __construct( $values = array() ) {
				foreach ( (array) $values as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}
	$GLOBALS['spp_test_options'] = array();
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['spp_test_options'] ) ? $GLOBALS['spp_test_options'][ $name ] : $default;
		}
	}
	if ( ! function_exists( 'strip_shortcodes' ) ) {
		function strip_shortcodes( $content ) {
			return preg_replace( '/\[[^\]]+\]/', '', (string) $content );
		}
	}
	if ( ! function_exists( 'wp_html_excerpt' ) ) {
		// WordPress' implementation (formatting.php).
		function wp_html_excerpt( $str, $count, $more = null ) {
			$str     = wp_strip_all_tags( $str, true );
			$excerpt = mb_substr( $str, 0, $count );
			$excerpt = preg_replace( '/&[^;\s]{0,6}$/', '', $excerpt );
			if ( $str !== $excerpt && null !== $more ) {
				$excerpt .= $more;
			}
			return $excerpt;
		}
	}
}

namespace SearchPilot\Tests {

	use SearchPilot\Seo\MetaStore;
	use SearchPilot\Support\Text;

	/**
	 * Rank Math's separator and %excerpt% semantics, without Rank Math installed.
	 */
	class MetaStoreResolveTest extends \PHPUnit\Framework\TestCase {

		private function store() {
			return ( new \ReflectionClass( MetaStore::class ) )->newInstanceWithoutConstructor();
		}

		private function post( array $values ) {
			return new \WP_Post( array_merge( array( 'ID' => 0, 'post_title' => 'Post', 'post_content' => '<p>Body</p>' ), $values ) );
		}

		protected function setUp(): void {
			$GLOBALS['spp_test_options'] = array(
				'blogname'                 => 'The Voice of Human',
				'blogdescription'          => 'News',
				'rank-math-options-titles' => array( 'title_separator' => '-' ),
			);
		}

		public function test_rank_math_title_uses_its_own_separator_and_template() {
			$meta = $this->store();
			$post = $this->post( array( 'post_title' => 'Can Poverty Cost You Your Children?' ) );
			$this->assertSame( 'Can Poverty Cost You Your Children?', $meta->rank_math_resolve( '%title%', $post ), 'Template %title%: no invented site suffix.' );
			$this->assertSame( 'Can Poverty Cost You Your Children? - The Voice of Human', $meta->rank_math_resolve( '%title% %sep% %sitename%', $post ) );
			$GLOBALS['spp_test_options']['rank-math-options-titles']['title_separator'] = '&ndash;';
			$this->assertSame( 'Can Poverty Cost You Your Children? – The Voice of Human', MetaStore::plain( $meta->rank_math_resolve( '%title% %sep% %sitename%', $post ) ) );
		}

		public function test_rank_math_tidies_separators_like_its_replacer() {
			$meta = $this->store();
			$post = $this->post( array( 'post_title' => 'ಬೆಂಗಳೂರು ಮಳೆ' ) );
			$this->assertSame( 'ಬೆಂಗಳೂರು ಮಳೆ', $meta->rank_math_resolve( '%title% %sep%', $post ), 'A trailing " %sep%" is dropped.' );
			$this->assertSame( 'ಬೆಂಗಳೂರು ಮಳೆ - The Voice of Human', $meta->rank_math_resolve( '%title% %sep% %page% %sep% %sitename%', $post ), 'Empty variables do not leave doubled separators.' );
			$this->assertSame( 'ಬೆಂಗಳೂರು ಮಳೆ', $meta->rank_math_resolve( '%seo_title%', $post ), '%seo_title% in option templates means %title%.' );
			$this->assertSame( 'ಬೆಂಗಳೂರು ಮಳೆ', $meta->rank_math_resolve( '%title% %unknown_var%', $post ) );
		}

		public function test_rank_math_excerpt_is_the_full_manual_excerpt_or_160_characters() {
			$manual = str_repeat( 'A manual excerpt that is longer than any description limit. ', 5 );
			$post   = $this->post( array( 'post_excerpt' => $manual, 'post_content' => '<p>Body text</p>' ) );
			// Rank Math serves manual excerpts in full (finding 4); its replacer trims the result.
			$this->assertSame( trim( $manual ), trim( MetaStore::rank_math_excerpt( $post ) ) );
			$this->assertSame( Text::length( trim( $manual ) ), Text::length( MetaStore::plain( MetaStore::rank_math_excerpt( $post ) ) ), 'Not shortened.' );
			$this->assertSame( trim( $manual ), MetaStore::plain( $this->store()->rank_math_resolve( '%excerpt%', $post ) ) );

			$content = '<!-- wp:paragraph --><p>' . str_repeat( 'ಬೆಂಗಳೂರು ನಗರದಲ್ಲಿ ಶುಕ್ರವಾರ ಭಾರಿ ಮಳೆಯಾಗಿದ್ದು ', 10 ) . '</p><!-- /wp:paragraph -->';
			$auto    = MetaStore::rank_math_excerpt( $this->post( array( 'post_content' => $content ) ) );
			$this->assertSame( 160, mb_strlen( $auto ), 'Rank Math cuts automatic excerpts at 160 code points.' );
			$this->assertLessThan( 160, Text::length( $auto ), 'That is far fewer Kannada graphemes.' );
			$this->assertSame( '', MetaStore::rank_math_excerpt( $this->post( array( 'post_excerpt' => 'x', 'post_content' => '' ) ) ), 'No content: Rank Math bails out.' );
		}

		public function test_plain_decodes_entities_and_collapses_whitespace() {
			$this->assertSame( 'Rain & floods – city’s roads', MetaStore::plain( "  Rain &amp; floods &#8211;\n <b>city&#8217;s</b>   roads " ) );
			$this->assertSame( '', MetaStore::plain( '' ) );
		}
	}
}

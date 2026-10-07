<?php
/**
 * Meta description, canonical, robots, Open Graph and X/Twitter tags.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Computes and prints the head tags for the features SearchPilot owns.
 * Every public method that prints checks ConflictDetector::owns() itself,
 * so nothing is ever duplicated when another SEO plugin keeps a feature.
 */
class HeadOutput {

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
	 * Titles and context.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Whether robots directives from other plugins are replaced (forced takeover).
	 *
	 * @var bool
	 */
	private $replace_robots = false;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param MetaStore        $meta      Meta store.
	 * @param ContentTypes     $types     Content types.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param TitleResolver    $titles    Titles.
	 */
	public function __construct( Settings $settings, MetaStore $meta, ContentTypes $types, ConflictDetector $conflicts, TitleResolver $titles ) {
		$this->settings  = $settings;
		$this->meta      = $meta;
		$this->types     = $types;
		$this->conflicts = $conflicts;
		$this->titles    = $titles;
	}

	/**
	 * Makes the robots filter replace other plugins' index/follow values.
	 *
	 * @param bool $replace Replace.
	 */
	public function set_replace_robots( $replace ) {
		$this->replace_robots = (bool) $replace;
	}

	/* --------------------------------------------------------------------- *
	 * Output
	 * --------------------------------------------------------------------- */

	/**
	 * wp_head (priority 1): description, canonical and social tags.
	 */
	public function render() {
		$context = $this->titles->current_context();
		$lines   = array();

		if ( $this->conflicts->owns( 'description' ) ) {
			$desc = $this->titles->description( $context );
			if ( '' !== $desc ) {
				$lines[] = '<meta name="description" content="' . esc_attr( $desc ) . '" />';
			}
		}
		if ( $this->conflicts->owns( 'canonical' ) ) {
			$canonical = $this->canonical( $context );
			if ( '' !== $canonical ) {
				$lines[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '" />';
			}
		}
		if ( $this->conflicts->owns( 'social' ) ) {
			foreach ( $this->social_tags( $context ) as $tag ) {
				$lines[] = '<meta ' . $tag[0] . '="' . esc_attr( $tag[1] ) . '" content="' . esc_attr( $tag[2] ) . '" />';
			}
		}
		if ( ! $lines ) {
			return;
		}
		echo "\n<!-- SearchPilot Pro -->\n" . implode( "\n", $lines ) . "\n<!-- / SearchPilot Pro -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped above.
	}

	/* --------------------------------------------------------------------- *
	 * Canonical
	 * --------------------------------------------------------------------- */

	/**
	 * Canonical URL for a context ('' = none: search results, 404, previews).
	 *
	 * @param array $context Context.
	 * @return string
	 */
	public function canonical( array $context ) {
		$page = (int) $context['page'];
		switch ( $context['type'] ) {
			case 'front':
			case 'singular':
				$post = $context['post'];
				if ( ! $post instanceof \WP_Post ) {
					return '';
				}
				$url = wp_get_canonical_url( $post );
				return $url ? (string) $url : '';
			case 'home':
				return self::paged_url( home_url( '/' ), $page );
			case 'blog':
				$post = $context['post'];
				$base = $post instanceof \WP_Post ? get_permalink( $post ) : home_url( '/' );
				return self::paged_url( (string) $base, $page );
			case 'term':
				$term = $context['term'];
				if ( ! $term instanceof \WP_Term ) {
					return '';
				}
				if ( 1 === $page ) {
					$custom = trim( (string) $this->meta->get_term_field( $term->term_id, 'canonical' ) );
					if ( '' !== $custom ) {
						return $custom;
					}
				}
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : self::paged_url( (string) $link, $page );
			case 'author':
				$user = $context['user'];
				return $user instanceof \WP_User ? self::paged_url( get_author_posts_url( $user->ID, $user->user_nicename ), $page ) : '';
			case 'post_type_archive':
				$link = '' !== $context['post_type'] ? get_post_type_archive_link( $context['post_type'] ) : '';
				return $link ? self::paged_url( (string) $link, $page ) : '';
			case 'date':
				$date = $context['date'];
				if ( ! empty( $date['day'] ) ) {
					$link = get_day_link( $date['year'], $date['month'], $date['day'] );
				} elseif ( ! empty( $date['month'] ) ) {
					$link = get_month_link( $date['year'], $date['month'] );
				} elseif ( ! empty( $date['year'] ) ) {
					$link = get_year_link( $date['year'] );
				} else {
					return '';
				}
				return self::paged_url( (string) $link, $page );
		}
		return '';
	}

	/**
	 * Adds /page/N/ (or ?paged=N) to an archive URL; page 1 returns the base.
	 *
	 * @param string $base Base URL.
	 * @param int    $page Page.
	 * @return string
	 */
	public static function paged_url( $base, $page ) {
		global $wp_rewrite;
		$page = (int) $page;
		if ( $page < 2 ) {
			return (string) $base;
		}
		$rewrite  = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite : null;
		$pretty   = $rewrite && $rewrite->using_permalinks() && false === strpos( (string) $base, '?' );
		$pbase    = $rewrite ? (string) $rewrite->pagination_base : 'page';
		$trailing = $rewrite ? (bool) $rewrite->use_trailing_slashes : true;
		return self::build_paged_url( (string) $base, $page, $pretty, $pbase, $trailing );
	}

	/**
	 * Pure pagination URL builder.
	 *
	 * @param string $base     Base URL.
	 * @param int    $page     Page.
	 * @param bool   $pretty   Pretty permalinks.
	 * @param string $pbase    Pagination base (default "page").
	 * @param bool   $trailing Permalinks end with a slash.
	 * @return string
	 */
	public static function build_paged_url( $base, $page, $pretty, $pbase = 'page', $trailing = true ) {
		$base = (string) $base;
		if ( (int) $page < 2 ) {
			return $base;
		}
		if ( $pretty ) {
			return rtrim( $base, '/' ) . '/' . $pbase . '/' . (int) $page . ( $trailing ? '/' : '' );
		}
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'paged=' . (int) $page;
	}

	/**
	 * get_canonical_url filter: custom canonical of a post.
	 *
	 * @param string|false $url  Canonical URL.
	 * @param \WP_Post     $post Post.
	 * @return string|false
	 */
	public function filter_canonical_url( $url, $post ) {
		if ( ! $post instanceof \WP_Post || ! $this->conflicts->owns( 'canonical' ) ) {
			return $url;
		}
		$custom = $this->meta->canonical( $post->ID );
		return '' !== $custom ? $custom : $url;
	}

	/* --------------------------------------------------------------------- *
	 * Robots
	 * --------------------------------------------------------------------- */

	/**
	 * wp_robots filter.
	 *
	 * @param array $robots Directives (name => true|string).
	 * @return array
	 */
	public function filter_robots( $robots ) {
		$robots = is_array( $robots ) ? $robots : array();
		if ( ! $this->conflicts->owns( 'robots' ) ) {
			return $robots;
		}
		$wanted = $this->robots_for_context( $this->titles->current_context() );
		return self::apply_directives( $robots, $wanted, (bool) $this->settings->get( 'meta.max_image_preview_large', true ), (bool) get_option( 'blog_public' ), $this->replace_robots );
	}

	/**
	 * Pure merge of SearchPilot's decision into a wp_robots array.
	 *
	 * @param array $robots      Existing directives.
	 * @param array $wanted      {noindex: bool, nofollow: bool}.
	 * @param bool  $large_image Allow max-image-preview:large.
	 * @param bool  $public      Site is public (blog_public).
	 * @param bool  $replace     Drop index/follow values set by others first.
	 * @return array
	 */
	public static function apply_directives( array $robots, array $wanted, $large_image, $public, $replace = false ) {
		if ( $replace ) {
			unset( $robots['index'], $robots['noindex'], $robots['follow'], $robots['nofollow'] );
			if ( ! $public ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
			}
		}
		$noindex = ! empty( $wanted['noindex'] ) || ! empty( $robots['noindex'] );
		if ( ! empty( $wanted['noindex'] ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( ! empty( $wanted['nofollow'] ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}
		if ( $noindex && empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
		if ( $noindex || ! $large_image ) {
			unset( $robots['max-image-preview'] );
		} elseif ( $public && ! isset( $robots['max-image-preview'] ) ) {
			$robots['max-image-preview'] = 'large';
		}
		return $robots;
	}

	/**
	 * SearchPilot's robots decision for a context.
	 *
	 * @param array $context Context.
	 * @return array{noindex:bool,nofollow:bool,reason:string}
	 */
	public function robots_for_context( array $context ) {
		$out  = array(
			'noindex'  => false,
			'nofollow' => false,
			'reason'   => 'ok',
		);
		$type = $context['type'];

		if ( in_array( $type, array( 'singular', 'front', 'blog' ), true ) && $context['post'] instanceof \WP_Post ) {
			$post = $context['post'];
			if ( 'attachment' === $post->post_type ) {
				$out['noindex'] = true;
				$out['reason']  = 'attachment';
			} elseif ( 'publish' === $post->post_status ) {
				$state = $this->types->post_indexability( $post );
				if ( in_array( $state['reason'], array( 'post_type', 'noindex', 'noindex_default' ), true ) && ! ( 'post_type' === $state['reason'] && in_array( $type, array( 'front', 'blog' ), true ) ) ) {
					$out['noindex'] = true;
					$out['reason']  = (string) $state['reason'];
				}
			}
			$out['nofollow'] = true === $this->meta->get_post_field( $post->ID, 'nofollow' );
		} elseif ( 'term' === $type && $context['term'] instanceof \WP_Term ) {
			$state = $this->types->term_indexability( $context['term'] );
			if ( ! $state['indexable'] ) {
				$out['noindex'] = true;
				$out['reason']  = 'term_' . $state['reason'];
			}
		} elseif ( 'author' === $type ) {
			$user = $context['user'];
			if ( ! $user instanceof \WP_User || ! $this->types->is_author_indexable( $user->ID ) ) {
				$out['noindex'] = true;
				$out['reason']  = 'author';
			}
		} elseif ( 'date' === $type && $this->settings->get( 'content.date_archives_noindex' ) ) {
			$out['noindex'] = true;
			$out['reason']  = 'date_archive';
		} elseif ( 'search' === $type && $this->settings->get( 'content.search_noindex' ) ) {
			$out['noindex'] = true;
			$out['reason']  = 'search';
		} elseif ( 'post_type_archive' === $type && '' !== $context['post_type'] ) {
			$types = $this->types->post_types();
			if ( isset( $types[ $context['post_type'] ] ) && ! $types[ $context['post_type'] ]['index'] ) {
				$out['noindex'] = true;
				$out['reason']  = 'post_type';
			}
		}
		if ( ! $out['noindex'] && $context['page'] > 1 && in_array( $type, array( 'home', 'blog', 'term', 'author', 'post_type_archive', 'date' ), true ) && $this->settings->get( 'content.paginated_noindex' ) ) {
			$out['noindex'] = true;
			$out['reason']  = 'paginated';
		}
		return $out;
	}

	/**
	 * Robots directives a post's page outputs (for the editor and REST).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{noindex:bool,nofollow:bool,reason:string,directives:string[]}
	 */
	public function robots_for_post( \WP_Post $post ) {
		$decision = $this->robots_for_context( $this->titles->post_context( $post ) );
		$robots   = self::apply_directives( array(), $decision, (bool) $this->settings->get( 'meta.max_image_preview_large', true ), (bool) get_option( 'blog_public' ) );
		if ( ! get_option( 'blog_public' ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		$list = array();
		foreach ( $robots as $key => $value ) {
			$list[] = true === $value ? $key : $key . ':' . $value;
		}
		$decision['directives'] = $list ? $list : array( 'index', 'follow' );
		return $decision;
	}

	/* --------------------------------------------------------------------- *
	 * Social
	 * --------------------------------------------------------------------- */

	/**
	 * Open Graph and X/Twitter tags as [attribute, name, content] triples.
	 *
	 * @param array $context Context.
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public function social_tags( array $context ) {
		$og      = (bool) $this->settings->get( 'meta.og_enabled', true );
		$twitter = (bool) $this->settings->get( 'meta.twitter_enabled', true );
		if ( ( ! $og && ! $twitter ) || in_array( $context['type'], array( '404' ), true ) ) {
			return array();
		}
		$post     = $context['post'] instanceof \WP_Post && in_array( $context['type'], array( 'singular', 'front', 'blog' ), true ) ? $context['post'] : null;
		$title    = $this->titles->title( $context );
		$desc     = $this->titles->description( $context );
		$og_title = $title;
		$og_desc  = $desc;
		$tw_title = '';
		$tw_desc  = '';
		if ( $post ) {
			$field = trim( (string) $this->meta->get_post_field( $post->ID, 'og_title' ) );
			if ( '' !== $field ) {
				$og_title = $this->titles->resolve( $field, $context );
			}
			$field = trim( (string) $this->meta->get_post_field( $post->ID, 'og_description' ) );
			if ( '' !== $field ) {
				$og_desc = $this->titles->resolve( $field, $context );
			}
			$tw_title = trim( (string) $this->meta->get_post_field( $post->ID, 'twitter_title' ) );
			$tw_title = '' !== $tw_title ? $this->titles->resolve( $tw_title, $context ) : '';
			$tw_desc  = trim( (string) $this->meta->get_post_field( $post->ID, 'twitter_description' ) );
			$tw_desc  = '' !== $tw_desc ? $this->titles->resolve( $tw_desc, $context ) : '';
		}
		$url   = $this->canonical( $context );
		$image = $post ? $this->image_for_post( $post ) : $this->default_image();
		if ( 'front' === $context['type'] && ! $image ) {
			$image = $this->default_image();
		}

		$tags = array();
		if ( $og ) {
			$type   = 'author' === $context['type'] ? 'profile' : ( 'singular' === $context['type'] ? 'article' : 'website' );
			$tags[] = array( 'property', 'og:locale', self::og_locale( get_locale() ) );
			$tags[] = array( 'property', 'og:type', $type );
			$tags[] = array( 'property', 'og:title', $og_title );
			if ( '' !== $og_desc ) {
				$tags[] = array( 'property', 'og:description', $og_desc );
			}
			if ( '' !== $url ) {
				$tags[] = array( 'property', 'og:url', $url );
			}
			$tags[] = array( 'property', 'og:site_name', (string) get_bloginfo( 'name' ) );
			if ( $image ) {
				$tags[] = array( 'property', 'og:image', $image['url'] );
				if ( $image['width'] && $image['height'] ) {
					$tags[] = array( 'property', 'og:image:width', (string) $image['width'] );
					$tags[] = array( 'property', 'og:image:height', (string) $image['height'] );
				}
				if ( '' !== $image['alt'] ) {
					$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
				}
				if ( '' !== $image['type'] ) {
					$tags[] = array( 'property', 'og:image:type', $image['type'] );
				}
			}
			if ( $post && 'singular' === $context['type'] ) {
				$tags[] = array( 'property', 'article:published_time', (string) get_post_time( 'c', true, $post ) );
				$tags[] = array( 'property', 'article:modified_time', (string) get_post_modified_time( 'c', true, $post ) );
				if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
					$primary = $this->meta->primary_term( $post->ID, 'category' );
					$term    = $primary ? get_term( $primary, 'category' ) : null;
					if ( $term instanceof \WP_Term ) {
						$tags[] = array( 'property', 'article:section', $term->name );
					}
				}
				if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
					$post_tags = get_the_terms( $post, 'post_tag' );
					foreach ( is_array( $post_tags ) ? array_slice( $post_tags, 0, 10 ) : array() as $tag ) {
						$tags[] = array( 'property', 'article:tag', $tag->name );
					}
				}
				$social = self::user_social( (int) $post->post_author );
				if ( ! empty( $social['facebook'] ) ) {
					$tags[] = array( 'property', 'article:author', $social['facebook'] );
				}
			}
			$app_id = trim( (string) $this->settings->get( 'meta.facebook_app_id', '' ) );
			if ( '' !== $app_id ) {
				$tags[] = array( 'property', 'fb:app_id', $app_id );
			}
		}
		if ( $twitter ) {
			$tags[] = array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' );
			$tags[] = array( 'name', 'twitter:title', '' !== $tw_title ? $tw_title : $og_title );
			$tw_desc = '' !== $tw_desc ? $tw_desc : $og_desc;
			if ( '' !== $tw_desc ) {
				$tags[] = array( 'name', 'twitter:description', $tw_desc );
			}
			if ( $image ) {
				$tags[] = array( 'name', 'twitter:image', $image['url'] );
				if ( '' !== $image['alt'] ) {
					$tags[] = array( 'name', 'twitter:image:alt', $image['alt'] );
				}
			}
			$site = self::twitter_handle( (string) $this->settings->get( 'meta.twitter_site', '' ) );
			if ( '' !== $site ) {
				$tags[] = array( 'name', 'twitter:site', $site );
			}
			if ( $post ) {
				$social  = self::user_social( (int) $post->post_author );
				$creator = self::twitter_handle( (string) ( $social['x'] ?? '' ) );
				if ( '' !== $creator ) {
					$tags[] = array( 'name', 'twitter:creator', $creator );
				}
			}
		}
		/**
		 * Filters the social tags ([attribute, name, content] triples).
		 *
		 * @param array $tags    Tags.
		 * @param array $context Request context.
		 */
		return (array) apply_filters( 'searchpilot_social_tags', $tags, $context );
	}

	/**
	 * Sharing image for a post: social image → featured → first content image → default.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{url:string,width:int,height:int,alt:string,type:string,id:int}|null
	 */
	public function image_for_post( \WP_Post $post ) {
		$raw = $this->meta->get_post_field( $post->ID, 'og_image' );
		if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
			$image = self::attachment_image( (int) $raw );
			if ( $image ) {
				return $image;
			}
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) && preg_match( '#^https?://#i', trim( $raw ) ) ) {
			return array(
				'url'    => esc_url_raw( trim( $raw ) ),
				'width'  => 0,
				'height' => 0,
				'alt'    => '',
				'type'   => self::mime_from_url( trim( $raw ) ),
				'id'     => 0,
			);
		}
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$image = self::attachment_image( $thumb );
			if ( $image ) {
				return $image;
			}
		}
		// Content of password-protected posts is not public: never take an image from it.
		$image = '' === (string) $post->post_password ? self::content_image( (string) $post->post_content ) : null;
		return $image ? $image : $this->default_image();
	}

	/**
	 * Default sharing image from settings.
	 *
	 * @return array|null
	 */
	public function default_image() {
		$id = (int) $this->settings->get( 'meta.default_image', 0 );
		return $id ? self::attachment_image( $id ) : null;
	}

	/**
	 * First image in post content.
	 *
	 * @param string $content Raw content.
	 * @return array|null
	 */
	public static function content_image( $content ) {
		$content = (string) $content;
		if ( false === stripos( $content, '<img' ) ) {
			return null;
		}
		if ( ! preg_match( '/<img\b[^>]*>/i', $content, $tag ) ) {
			return null;
		}
		if ( preg_match( '/\bwp-image-(\d+)\b/', $tag[0], $m ) ) {
			$image = self::attachment_image( (int) $m[1] );
			if ( $image ) {
				return $image;
			}
		}
		if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag[0], $m ) && preg_match( '#^(https?:)?//#i', $m[1] ) ) {
			$alt = preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag[0], $a ) ? html_entity_decode( $a[1], ENT_QUOTES, 'UTF-8' ) : '';
			$url = 0 === strpos( $m[1], '//' ) ? set_url_scheme( $m[1] ) : $m[1];
			return array(
				'url'    => esc_url_raw( $url ),
				'width'  => preg_match( '/\bwidth=["\']?(\d+)/i', $tag[0], $w ) ? (int) $w[1] : 0,
				'height' => preg_match( '/\bheight=["\']?(\d+)/i', $tag[0], $hh ) ? (int) $hh[1] : 0,
				'alt'    => trim( $alt ),
				'type'   => self::mime_from_url( $url ),
				'id'     => 0,
			);
		}
		return null;
	}

	/**
	 * Image data of an attachment (full size).
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public static function attachment_image( $id ) {
		$id = (int) $id;
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src || empty( $src[0] ) ) {
			return null;
		}
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
			'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
			'type'   => (string) get_post_mime_type( $id ),
			'id'     => $id,
		);
	}

	/**
	 * MIME type guessed from an image URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function mime_from_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map  = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);
		return $map[ $ext ] ?? '';
	}

	/**
	 * Open Graph locale (ll_CC) from a WordPress locale.
	 *
	 * @param string $locale WordPress locale, e.g. en_US, kn, de_DE_formal.
	 * @return string
	 */
	public static function og_locale( $locale ) {
		$locale = (string) $locale;
		if ( preg_match( '/^([a-z]{2,3})_([A-Z]{2})/', $locale, $m ) ) {
			return $m[1] . '_' . $m[2];
		}
		$map  = array(
			'kn'  => 'kn_IN',
			'te'  => 'te_IN',
			'mr'  => 'mr_IN',
			'gu'  => 'gu_IN',
			'ml'  => 'ml_IN',
			'hi'  => 'hi_IN',
			'ta'  => 'ta_IN',
			'bn'  => 'bn_IN',
			'pa'  => 'pa_IN',
			'or'  => 'or_IN',
			'as'  => 'as_IN',
			'ur'  => 'ur_PK',
			'ne'  => 'ne_NP',
			'si'  => 'si_LK',
			'ja'  => 'ja_JP',
			'ko'  => 'ko_KR',
			'zh'  => 'zh_CN',
			'th'  => 'th_TH',
			'vi'  => 'vi_VN',
			'ar'  => 'ar_AR',
			'he'  => 'he_IL',
			'fa'  => 'fa_IR',
			'el'  => 'el_GR',
			'en'  => 'en_US',
			'es'  => 'es_ES',
			'fr'  => 'fr_FR',
			'de'  => 'de_DE',
			'it'  => 'it_IT',
			'pt'  => 'pt_PT',
			'nl'  => 'nl_NL',
			'sv'  => 'sv_SE',
			'da'  => 'da_DK',
			'nb'  => 'nb_NO',
			'fi'  => 'fi_FI',
			'pl'  => 'pl_PL',
			'cs'  => 'cs_CZ',
			'uk'  => 'uk_UA',
			'ru'  => 'ru_RU',
			'tr'  => 'tr_TR',
			'id'  => 'id_ID',
			'ms'  => 'ms_MY',
			'sq'  => 'sq_AL',
			'hy'  => 'hy_AM',
			'az'  => 'az_AZ',
			'eu'  => 'eu_ES',
			'ca'  => 'ca_ES',
			'gl'  => 'gl_ES',
			'et'  => 'et_EE',
			'lv'  => 'lv_LV',
			'lt'  => 'lt_LT',
			'sk'  => 'sk_SK',
			'sl'  => 'sl_SI',
			'sr'  => 'sr_RS',
			'hr'  => 'hr_HR',
			'bg'  => 'bg_BG',
			'ro'  => 'ro_RO',
			'hu'  => 'hu_HU',
			'ka'  => 'ka_GE',
			'km'  => 'km_KH',
			'lo'  => 'lo_LA',
			'my'  => 'my_MM',
			'sw'  => 'sw_KE',
			'af'  => 'af_ZA',
			'cy'  => 'cy_GB',
			'ga'  => 'ga_IE',
			'is'  => 'is_IS',
			'mk'  => 'mk_MK',
			'mn'  => 'mn_MN',
			'kk'  => 'kk_KZ',
			'uz'  => 'uz_UZ',
			'tl'  => 'tl_PH',
		);
		$lang = strtolower( substr( $locale, 0, 3 ) );
		$lang = isset( $map[ $lang ] ) ? $lang : strtolower( substr( $locale, 0, 2 ) );
		return $map[ $lang ] ?? 'en_US';
	}

	/**
	 * "@handle" from a handle or an x.com/twitter.com URL ('' when invalid).
	 *
	 * @param string $value Handle or URL.
	 * @return string
	 */
	public static function twitter_handle( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^(?:https?://)?(?:www\.|mobile\.)?(?:twitter|x)\.com/@?([A-Za-z0-9_]{1,15})#i', $value, $m ) ) {
			return '@' . $m[1];
		}
		$value = ltrim( $value, '@' );
		return preg_match( '/^[A-Za-z0-9_]{1,15}$/', $value ) ? '@' . $value : '';
	}

	/**
	 * A user's social profiles (user meta searchpilot_social).
	 *
	 * @param int $user_id User id.
	 * @return array<string,string>
	 */
	public static function user_social( $user_id ) {
		$data = get_user_meta( (int) $user_id, 'searchpilot_social', true );
		return is_array( $data ) ? array_map( 'strval', $data ) : array();
	}

	/**
	 * Plain text cut to at most $max graphemes (ellipsis included, word
	 * boundary, never inside a cluster — Text::truncate()).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max graphemes.
	 * @return string
	 */
	public static function clip( $text, $max ) {
		return Text::truncate( Text::strip( (string) $text ), (int) $max );
	}
}

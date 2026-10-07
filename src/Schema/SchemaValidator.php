<?php
/**
 * Structured data validator (required and recommended properties).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a JSON-LD graph against the properties Google documents for the
 * rich results SearchPilot generates. Pure: no WordPress state is read.
 *
 * Errors make an item ineligible for rich results; warnings are recommended
 * properties that improve how the result can be shown.
 */
class SchemaValidator {

	const ARTICLE_TYPES = array( 'Article', 'NewsArticle', 'BlogPosting', 'ReportageNewsArticle', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'BackgroundNewsArticle', 'ReviewNewsArticle', 'TechArticle', 'LiveBlogPosting', 'SocialMediaPosting' );

	const ORGANIZATION_TYPES = array( 'Organization', 'Corporation', 'NewsMediaOrganization', 'OnlineBusiness', 'OnlineStore', 'NGO', 'EducationalOrganization', 'GovernmentOrganization', 'MedicalOrganization', 'SportsOrganization', 'PerformingGroup', 'Airline', 'Consortium' );

	/**
	 * Errors found in the current run.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Warnings found in the current run.
	 *
	 * @var array
	 */
	private $warnings = array();

	/**
	 * Nodes by @id.
	 *
	 * @var array<string,array>
	 */
	private $index = array();

	/**
	 * Validates a graph ({@context, @graph}, a list of nodes or one node).
	 *
	 * @param array $graph Graph.
	 * @return array{errors:array,warnings:array,valid:bool,types:string[]}
	 */
	public function validate( array $graph ) {
		$this->errors   = array();
		$this->warnings = array();
		$this->index    = array();

		$top   = self::top_nodes( $graph );
		$all   = array();
		$refs  = array();
		$types = array();
		foreach ( $top as $node ) {
			$this->collect( $node, $all, $refs );
		}
		foreach ( $all as $node ) {
			if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && count( $node ) > 1 ) {
				$this->index[ $node['@id'] ] = isset( $this->index[ $node['@id'] ] ) ? array_merge( $this->index[ $node['@id'] ], $node ) : $node;
			}
		}
		foreach ( $all as $node ) {
			foreach ( self::types( $node ) as $type ) {
				$types[] = $type;
				$this->check_node( $type, $node );
			}
		}
		foreach ( array_unique( $refs ) as $ref ) {
			if ( ! isset( $this->index[ $ref ] ) ) {
				$this->warn(
					'Thing',
					'@id',
					/* translators: %s: node id. */
					sprintf( __( 'The graph references %s, but no item with that @id is defined.', 'searchpilot-pro' ), $ref ),
					$ref
				);
			}
		}
		if ( ! $top ) {
			$this->error( 'Thing', '@type', __( 'No structured data items were found.', 'searchpilot-pro' ) );
		}
		$this->errors   = self::unique( $this->errors );
		$this->warnings = self::unique( $this->warnings );
		return array(
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
			'valid'    => empty( $this->errors ),
			'types'    => array_values( array_unique( $types ) ),
		);
	}

	/**
	 * Removes repeated entries (a node can be both nested and top-level).
	 *
	 * @param array $entries Entries.
	 * @return array
	 */
	private static function unique( array $entries ) {
		$seen = array();
		$out  = array();
		foreach ( $entries as $entry ) {
			$key = $entry['type'] . '|' . $entry['property'] . '|' . $entry['message'] . '|' . $entry['id'];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $entry;
			}
		}
		return $out;
	}

	/**
	 * Top-level nodes of a JSON-LD value.
	 *
	 * @param mixed $data Decoded JSON-LD.
	 * @return array[]
	 */
	public static function top_nodes( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			return array_values( array_filter( $data['@graph'], 'is_array' ) );
		}
		if ( self::is_list( $data ) ) {
			$out = array();
			foreach ( $data as $item ) {
				$out = array_merge( $out, self::top_nodes( $item ) );
			}
			return $out;
		}
		return isset( $data['@type'] ) || isset( $data['@id'] ) ? array( $data ) : array();
	}

	/**
	 * @type values of a node.
	 *
	 * @param array $node Node.
	 * @return string[]
	 */
	public static function types( array $node ) {
		if ( ! isset( $node['@type'] ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) $node['@type'] ) ) );
	}

	/**
	 * Collects typed nodes (recursively) and bare references.
	 *
	 * @param array $node Node.
	 * @param array $all  Typed nodes (by reference).
	 * @param array $refs Referenced ids (by reference).
	 */
	private function collect( array $node, array &$all, array &$refs ) {
		if ( isset( $node['@id'] ) && 1 === count( $node ) && is_string( $node['@id'] ) ) {
			$refs[] = $node['@id'];
			return;
		}
		if ( isset( $node['@type'] ) ) {
			$all[] = $node;
		}
		foreach ( $node as $key => $value ) {
			if ( '@context' === $key || ! is_array( $value ) ) {
				continue;
			}
			if ( self::is_list( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						$this->collect( $item, $all, $refs );
					}
				}
			} else {
				$this->collect( $value, $all, $refs );
			}
		}
	}

	/**
	 * Dispatches the checks for one type of a node.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_node( $type, array $node ) {
		if ( in_array( $type, self::ARTICLE_TYPES, true ) ) {
			$this->check_article( $type, $node );
		} elseif ( in_array( $type, self::ORGANIZATION_TYPES, true ) ) {
			$this->check_organization( $type, $node );
		} elseif ( 'Person' === $type ) {
			if ( ! self::filled( $node, 'name' ) ) {
				$this->error( $type, 'name', __( 'A Person needs a name.', 'searchpilot-pro' ), $node );
			}
		} elseif ( 'BreadcrumbList' === $type ) {
			$this->check_breadcrumbs( $type, $node );
		} elseif ( 'FAQPage' === $type ) {
			$this->check_faq( $type, $node );
		} elseif ( 'VideoObject' === $type ) {
			$this->check_video( $type, $node );
		} elseif ( 'WebSite' === $type ) {
			foreach ( array( 'url', 'name' ) as $prop ) {
				if ( ! self::filled( $node, $prop ) ) {
					/* translators: %s: property name. */
					$this->error( $type, $prop, sprintf( __( 'WebSite is missing “%s”, which Google needs for site names.', 'searchpilot-pro' ), $prop ), $node );
				}
			}
		} elseif ( 'ImageObject' === $type ) {
			if ( ! self::filled( $node, 'url' ) && ! self::filled( $node, 'contentUrl' ) ) {
				$this->error( $type, 'url', __( 'An ImageObject needs a url (or contentUrl).', 'searchpilot-pro' ), $node );
			}
		}
	}

	/**
	 * Article, NewsArticle, BlogPosting.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_article( $type, array $node ) {
		if ( ! self::filled( $node, 'headline' ) ) {
			$this->error( $type, 'headline', __( 'Add a headline (the article title).', 'searchpilot-pro' ), $node );
		} elseif ( self::length( (string) $node['headline'] ) > 110 ) {
			$this->warn( $type, 'headline', __( 'The headline is longer than 110 characters; Google may shorten it.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'image' ) ) {
			$this->warn( $type, 'image', __( 'Add an image (a featured image at least 1200 px wide works best for Google Discover and Top stories).', 'searchpilot-pro' ), $node );
		} else {
			$image = $this->resolve( is_array( $node['image'] ) && self::is_list( $node['image'] ) ? $node['image'][0] : $node['image'] );
			if ( is_array( $image ) && isset( $image['width'] ) && is_numeric( $image['width'] ) && (int) $image['width'] > 0 && (int) $image['width'] < 1200 ) {
				$this->warn( $type, 'image', __( 'The image is narrower than 1200 px. Google recommends at least 1200 px wide images for articles.', 'searchpilot-pro' ), $node );
			}
		}
		if ( ! self::filled( $node, 'datePublished' ) ) {
			$this->warn( $type, 'datePublished', __( 'Add datePublished so search engines know when the article first appeared.', 'searchpilot-pro' ), $node );
		} elseif ( ! self::is_date( (string) $node['datePublished'] ) ) {
			$this->error( $type, 'datePublished', __( 'datePublished must be an ISO 8601 date (e.g. 2026-09-30T08:00:00+05:30).', 'searchpilot-pro' ), $node );
		}
		if ( self::filled( $node, 'dateModified' ) && ! self::is_date( (string) $node['dateModified'] ) ) {
			$this->error( $type, 'dateModified', __( 'dateModified must be an ISO 8601 date.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'author' ) ) {
			$this->warn( $type, 'author', __( 'Add the author so Google can show who wrote the article.', 'searchpilot-pro' ), $node );
			return;
		}
		$authors = is_array( $node['author'] ) && self::is_list( $node['author'] ) ? $node['author'] : array( $node['author'] );
		foreach ( $authors as $author ) {
			if ( is_string( $author ) ) {
				continue;
			}
			$author = $this->resolve( $author );
			if ( ! is_array( $author ) || ! self::filled( $author, 'name' ) ) {
				$this->error( $type, 'author.name', __( 'Every author needs a name.', 'searchpilot-pro' ), $node );
				return;
			}
		}
	}

	/**
	 * Organization.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_organization( $type, array $node ) {
		if ( ! self::filled( $node, 'name' ) ) {
			$this->error( $type, 'name', __( 'The organization needs a name.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'url' ) ) {
			$this->warn( $type, 'url', __( 'Add the organization’s website URL.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'logo' ) ) {
			$this->warn( $type, 'logo', __( 'Add a logo so Google can show it in search results and the knowledge panel.', 'searchpilot-pro' ), $node );
			return;
		}
		$logo = $this->resolve( $node['logo'] );
		if ( is_string( $logo ) ) {
			return;
		}
		if ( ! is_array( $logo ) || ( ! self::filled( $logo, 'url' ) && ! self::filled( $logo, 'contentUrl' ) ) ) {
			$this->error( $type, 'logo', __( 'The logo needs an image URL.', 'searchpilot-pro' ), $node );
			return;
		}
		$width  = isset( $logo['width'] ) && is_numeric( $logo['width'] ) ? (int) $logo['width'] : 0;
		$height = isset( $logo['height'] ) && is_numeric( $logo['height'] ) ? (int) $logo['height'] : 0;
		if ( ( $width && $width < 112 ) || ( $height && $height < 112 ) ) {
			$this->error( $type, 'logo', __( 'The logo must be at least 112 × 112 pixels.', 'searchpilot-pro' ), $node );
		}
	}

	/**
	 * BreadcrumbList.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_breadcrumbs( $type, array $node ) {
		$items = $node['itemListElement'] ?? null;
		if ( ! is_array( $items ) || ! $items ) {
			$this->error( $type, 'itemListElement', __( 'A breadcrumb list needs at least one item.', 'searchpilot-pro' ), $node );
			return;
		}
		$items = self::is_list( $items ) ? $items : array( $items );
		$count = count( $items );
		foreach ( array_values( $items ) as $i => $item ) {
			$item = $this->resolve( $item );
			if ( ! is_array( $item ) ) {
				$this->error( $type, 'itemListElement', __( 'Breadcrumb items must be ListItem objects.', 'searchpilot-pro' ), $node );
				continue;
			}
			/* translators: %d: position in the list. */
			$label = sprintf( __( 'item %d', 'searchpilot-pro' ), $i + 1 );
			if ( ! isset( $item['position'] ) || ! is_numeric( $item['position'] ) || (int) $item['position'] != $item['position'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- "2" and 2 are both valid.
				/* translators: %s: item label. */
				$this->error( $type, 'position', sprintf( __( 'Breadcrumb %s needs an integer position.', 'searchpilot-pro' ), $label ), $node );
			} elseif ( (int) $item['position'] !== $i + 1 ) {
				/* translators: %s: item label. */
				$this->warn( $type, 'position', sprintf( __( 'Breadcrumb %s is out of order; positions should count up from 1.', 'searchpilot-pro' ), $label ), $node );
			}
			$target = isset( $item['item'] ) ? $this->resolve( $item['item'] ) : null;
			$name   = self::filled( $item, 'name' ) || ( is_array( $target ) && self::filled( $target, 'name' ) );
			if ( ! $name ) {
				/* translators: %s: item label. */
				$this->error( $type, 'name', sprintf( __( 'Breadcrumb %s needs a name.', 'searchpilot-pro' ), $label ), $node );
			}
			$has_url = is_string( $target ) ? '' !== trim( $target ) : ( is_array( $target ) && ( self::filled( $target, '@id' ) || self::filled( $target, 'url' ) ) );
			if ( ! $has_url && $i < $count - 1 ) {
				/* translators: %s: item label. */
				$this->error( $type, 'item', sprintf( __( 'Breadcrumb %s needs a URL (only the last item may omit it).', 'searchpilot-pro' ), $label ), $node );
			}
		}
	}

	/**
	 * FAQPage.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_faq( $type, array $node ) {
		$questions = $node['mainEntity'] ?? null;
		if ( ! is_array( $questions ) || ! $questions ) {
			$this->error( $type, 'mainEntity', __( 'An FAQ page needs at least one question.', 'searchpilot-pro' ), $node );
			return;
		}
		$questions = self::is_list( $questions ) ? $questions : array( $questions );
		foreach ( array_values( $questions ) as $i => $question ) {
			$question = $this->resolve( $question );
			if ( ! is_array( $question ) || ! self::filled( $question, 'name' ) ) {
				/* translators: %d: question number. */
				$this->error( 'Question', 'name', sprintf( __( 'Question %d needs its question text (name).', 'searchpilot-pro' ), $i + 1 ), $node );
				continue;
			}
			$answer = isset( $question['acceptedAnswer'] ) ? $this->resolve( is_array( $question['acceptedAnswer'] ) && self::is_list( $question['acceptedAnswer'] ) ? $question['acceptedAnswer'][0] : $question['acceptedAnswer'] ) : null;
			if ( ! is_array( $answer ) || ! self::filled( $answer, 'text' ) ) {
				/* translators: %d: question number. */
				$this->error( 'Question', 'acceptedAnswer.text', sprintf( __( 'Question %d needs an answer (acceptedAnswer.text).', 'searchpilot-pro' ), $i + 1 ), $node );
			}
		}
	}

	/**
	 * VideoObject.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 */
	private function check_video( $type, array $node ) {
		$required = array(
			'name'         => __( 'Add the video title (name).', 'searchpilot-pro' ),
			'thumbnailUrl' => __( 'Add a thumbnail image URL (thumbnailUrl).', 'searchpilot-pro' ),
			'uploadDate'   => __( 'Add the upload date (uploadDate).', 'searchpilot-pro' ),
		);
		foreach ( $required as $prop => $message ) {
			if ( ! self::filled( $node, $prop ) ) {
				$this->error( $type, $prop, $message, $node );
			}
		}
		if ( self::filled( $node, 'uploadDate' ) && ! self::is_date( (string) $node['uploadDate'] ) ) {
			$this->error( $type, 'uploadDate', __( 'uploadDate must be an ISO 8601 date.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'description' ) ) {
			$this->warn( $type, 'description', __( 'Add a video description.', 'searchpilot-pro' ), $node );
		}
		if ( ! self::filled( $node, 'contentUrl' ) && ! self::filled( $node, 'embedUrl' ) ) {
			$this->warn( $type, 'contentUrl', __( 'Add contentUrl or embedUrl so Google can fetch the video.', 'searchpilot-pro' ), $node );
		}
		if ( self::filled( $node, 'duration' ) && ! self::is_duration( (string) $node['duration'] ) ) {
			$this->warn( $type, 'duration', __( 'duration should be an ISO 8601 duration such as PT4M13S.', 'searchpilot-pro' ), $node );
		}
	}

	/**
	 * Follows a bare {"@id"} reference to its node.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function resolve( $value ) {
		if ( is_array( $value ) && isset( $value['@id'] ) && 1 === count( $value ) && is_string( $value['@id'] ) && isset( $this->index[ $value['@id'] ] ) ) {
			return $this->index[ $value['@id'] ];
		}
		return $value;
	}

	/**
	 * Whether a property has a non-empty value.
	 *
	 * @param array  $node Node.
	 * @param string $prop Property.
	 * @return bool
	 */
	private static function filled( array $node, $prop ) {
		if ( ! array_key_exists( $prop, $node ) ) {
			return false;
		}
		$value = $node[ $prop ];
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		return null !== $value && false !== $value;
	}

	/**
	 * ISO 8601 date or date-time.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_date( $value ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', trim( (string) $value ) );
	}

	/**
	 * ISO 8601 duration.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_duration( $value ) {
		$value = trim( (string) $value );
		return 'P' !== $value && 'PT' !== $value && 1 === preg_match( '/^P(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$/', $value ) && 'T' !== substr( $value, -1 );
	}

	/**
	 * UTF-8 length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Whether an array is a list.
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		return array() === $arr || array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Adds an error.
	 *
	 * @param string       $type    Schema type.
	 * @param string       $prop    Property.
	 * @param string       $message Message.
	 * @param array|string $node    Node (for its @id) or an id.
	 */
	private function error( $type, $prop, $message, $node = array() ) {
		$this->errors[] = self::entry( $type, $prop, $message, $node );
	}

	/**
	 * Adds a warning.
	 *
	 * @param string       $type    Schema type.
	 * @param string       $prop    Property.
	 * @param string       $message Message.
	 * @param array|string $node    Node or id.
	 */
	private function warn( $type, $prop, $message, $node = array() ) {
		$this->warnings[] = self::entry( $type, $prop, $message, $node );
	}

	/**
	 * Result entry.
	 *
	 * @param string       $type    Type.
	 * @param string       $prop    Property.
	 * @param string       $message Message.
	 * @param array|string $node    Node or id.
	 * @return array{type:string,property:string,message:string,id:string}
	 */
	private static function entry( $type, $prop, $message, $node ) {
		$id = is_string( $node ) ? $node : ( is_array( $node ) && isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '' );
		return array(
			'type'     => (string) $type,
			'property' => (string) $prop,
			'message'  => (string) $message,
			'id'       => $id,
		);
	}
}

<?php
/**
 * Public template tags and helper functions.
 *
 * @package SearchPilot
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'searchpilot' ) ) {
	/**
	 * Returns the SearchPilot plugin instance, or a service when an id is given.
	 *
	 * @param string $service Optional service class name.
	 * @return \SearchPilot\Plugin|object
	 */
	function searchpilot( $service = '' ) {
		$plugin = \SearchPilot\Plugin::instance();
		return '' === $service ? $plugin : $plugin->get( $service );
	}
}

if ( ! function_exists( 'searchpilot_breadcrumbs' ) ) {
	/**
	 * Prints (or returns) the breadcrumb trail for the current page.
	 *
	 * @param array $args { Optional. @type bool $echo Print instead of return. Default true. }
	 * @return string
	 */
	function searchpilot_breadcrumbs( $args = array() ) {
		$args = wp_parse_args( $args, array( 'echo' => true ) );
		$html = '';
		if ( class_exists( '\SearchPilot\Seo\Breadcrumbs' ) ) {
			$html = searchpilot( \SearchPilot\Seo\Breadcrumbs::class )->render( $args );
		}
		if ( $args['echo'] ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
		}
		return $html;
	}
}

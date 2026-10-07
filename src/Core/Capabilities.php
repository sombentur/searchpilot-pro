<?php
/**
 * Custom capabilities.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * searchpilot_manage — settings, connections, automation, redirects (administrators).
 * searchpilot_view   — dashboards and reports (administrators, editors).
 * searchpilot_edit   — apply SEO fixes to content the user can edit (administrators, editors).
 */
final class Capabilities {

	const MANAGE = 'searchpilot_manage';
	const VIEW   = 'searchpilot_view';
	const EDIT   = 'searchpilot_edit';

	/**
	 * Role => capabilities granted on activation.
	 *
	 * @return array<string,string[]>
	 */
	public static function map() {
		return array(
			'administrator' => array( self::MANAGE, self::VIEW, self::EDIT ),
			'editor'        => array( self::VIEW, self::EDIT ),
		);
	}

	/**
	 * Adds capabilities to roles.
	 */
	public static function install() {
		foreach ( self::map() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes capabilities from every role.
	 */
	public static function uninstall() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( $wp_roles->role_objects as $role ) {
			foreach ( array( self::MANAGE, self::VIEW, self::EDIT ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user can manage SearchPilot.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user can view SearchPilot data.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( self::VIEW ) || self::can_manage();
	}

	/**
	 * Whether the current user can apply SEO fixes (optionally to a post).
	 *
	 * @param int $post_id Post id (0 = general).
	 * @return bool
	 */
	public static function can_edit( $post_id = 0 ) {
		$general = current_user_can( self::EDIT ) || self::can_manage();
		if ( ! $general ) {
			return false;
		}
		return $post_id ? current_user_can( 'edit_post', (int) $post_id ) : true;
	}
}

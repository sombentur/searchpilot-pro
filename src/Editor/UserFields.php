<?php
/**
 * Social profile fields on user profiles (Person sameAs).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Authors' public profiles (Facebook, X, LinkedIn, Instagram, YouTube,
 * Wikipedia, website) are stored in user meta `searchpilot_social` and
 * describe the author in structured data (Person.sameAs) and twitter:creator.
 */
class UserFields {

	const META  = 'searchpilot_social';
	const NONCE = 'searchpilot_user_social';

	/**
	 * Networks => labels (built lazily for translations).
	 *
	 * @return array<string,string>
	 */
	public static function networks() {
		return array(
			'facebook'  => __( 'Facebook profile URL', 'searchpilot-pro' ),
			'x'         => __( 'X (Twitter) username or URL', 'searchpilot-pro' ),
			'linkedin'  => __( 'LinkedIn profile URL', 'searchpilot-pro' ),
			'instagram' => __( 'Instagram profile URL', 'searchpilot-pro' ),
			'youtube'   => __( 'YouTube channel URL', 'searchpilot-pro' ),
			'wikipedia' => __( 'Wikipedia page URL', 'searchpilot-pro' ),
			'website'   => __( 'Personal website', 'searchpilot-pro' ),
		);
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Profile section.
	 *
	 * @param \WP_User $user User.
	 */
	public function render( $user ) {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$values = get_user_meta( $user->ID, self::META, true );
		$values = is_array( $values ) ? $values : array();
		?>
		<h2><?php esc_html_e( 'Public profiles (SearchPilot)', 'searchpilot-pro' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Used in structured data so search engines can connect your articles to your public profiles. Leave empty what you do not want to share.', 'searchpilot-pro' ); ?></p>
		<?php wp_nonce_field( self::NONCE, self::NONCE . '_nonce' ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( self::networks() as $key => $label ) : ?>
				<tr>
					<th><label for="searchpilot-social-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="<?php echo 'x' === $key ? 'text' : 'url'; ?>" class="regular-text" id="searchpilot-social-<?php echo esc_attr( $key ); ?>" name="searchpilot_social[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $values[ $key ] ?? '' ) ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Saves the profiles.
	 *
	 * @param int $user_id User id.
	 */
	public function save( $user_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', (int) $user_id ) ) {
			return;
		}
		$input = isset( $_POST['searchpilot_social'] ) && is_array( $_POST['searchpilot_social'] ) ? wp_unslash( $_POST['searchpilot_social'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize().
		$clean = self::sanitize( $input );
		if ( $clean ) {
			update_user_meta( (int) $user_id, self::META, $clean );
		} else {
			delete_user_meta( (int) $user_id, self::META );
		}
	}

	/**
	 * Sanitizes profile input.
	 *
	 * @param array $input Raw input.
	 * @return array<string,string>
	 */
	public static function sanitize( array $input ) {
		$out = array();
		foreach ( array_keys( self::networks() ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'x' === $key && preg_match( '/^@?([A-Za-z0-9_]{1,15})$/', $value, $m ) ) {
				$out[ $key ] = '@' . $m[1];
				continue;
			}
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out[ $key ] = $url;
			}
		}
		return $out;
	}
}

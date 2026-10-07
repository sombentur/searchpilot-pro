<?php
/**
 * SEO fields on taxonomy term screens.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Editor;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * SEO title, meta description, noindex and canonical for category, tag and
 * custom taxonomy archives — shown only for the features SearchPilot owns
 * (otherwise the term is edited in the SEO plugin that outputs it).
 */
class TermFields {

	const NONCE = 'searchpilot_term_fields';

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param ContentTypes     $types     Content types.
	 */
	public function __construct( ConflictDetector $conflicts, ContentTypes $types ) {
		$this->conflicts = $conflicts;
		$this->types     = $types;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
	}

	/**
	 * Attaches the form hooks for every public taxonomy.
	 */
	public function hook_taxonomies() {
		foreach ( array_keys( $this->types->taxonomies() ) as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'add_form' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'edit_form' ), 10, 2 );
			add_action( "created_{$taxonomy}", array( $this, 'save' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save' ) );
		}
	}

	/**
	 * Fields SearchPilot owns: field => feature.
	 *
	 * @return array<string,string>
	 */
	private function owned() {
		$out = array();
		foreach ( array(
			'title'       => 'title',
			'description' => 'description',
			'noindex'     => 'robots',
			'canonical'   => 'canonical',
		) as $field => $feature ) {
			if ( $this->conflicts->owns( $feature ) ) {
				$out[ $field ] = $feature;
			}
		}
		return $out;
	}

	/**
	 * Add-term form.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function add_form( $taxonomy ) {
		$owned = $this->owned();
		if ( ! $owned ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		foreach ( $owned as $field => $feature ) {
			echo '<div class="form-field term-searchpilot-' . esc_attr( $field ) . '-wrap">';
			$this->control( $field, '', 'add' );
			echo '</div>';
		}
	}

	/**
	 * Edit-term form.
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public function edit_form( $term, $taxonomy = '' ) {
		$owned = $this->owned();
		if ( ! $owned || ! $term instanceof \WP_Term ) {
			return;
		}
		echo '<tr class="form-field"><th colspan="2"><h2>' . esc_html__( 'SearchPilot SEO', 'searchpilot-pro' ) . '</h2>';
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '</th></tr>';
		foreach ( $owned as $field => $feature ) {
			$value = get_term_meta( $term->term_id, MetaStore::PREFIX . $field, true );
			echo '<tr class="form-field term-searchpilot-' . esc_attr( $field ) . '-wrap"><th scope="row">';
			echo '<label for="searchpilot-term-' . esc_attr( $field ) . '">' . esc_html( $this->label( $field ) ) . '</label></th><td>';
			$this->control( $field, is_scalar( $value ) ? (string) $value : '', 'edit' );
			echo '</td></tr>';
		}
	}

	/**
	 * Label of a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private function label( $field ) {
		$labels = array(
			'title'       => __( 'SEO title', 'searchpilot-pro' ),
			'description' => __( 'Meta description', 'searchpilot-pro' ),
			'noindex'     => __( 'Search engine indexing', 'searchpilot-pro' ),
			'canonical'   => __( 'Canonical URL', 'searchpilot-pro' ),
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * One control.
	 *
	 * @param string $field Field.
	 * @param string $value Value.
	 * @param string $mode  add|edit.
	 */
	private function control( $field, $value, $mode ) {
		$id   = 'searchpilot-term-' . $field;
		$name = 'searchpilot_term[' . $field . ']';
		if ( 'add' === $mode ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $this->label( $field ) ) . '</label>';
		}
		switch ( $field ) {
			case 'description':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
				echo '<p class="description">' . esc_html__( 'Shown in search results. Leave empty to use the term description. 120–156 characters work best.', 'searchpilot-pro' ) . '</p>';
				break;
			case 'noindex':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				echo '<option value=""' . selected( $value, '', false ) . '>' . esc_html__( 'Default (index when the archive has posts)', 'searchpilot-pro' ) . '</option>';
				echo '<option value="0"' . selected( $value, '0', false ) . '>' . esc_html__( 'Index', 'searchpilot-pro' ) . '</option>';
				echo '<option value="1"' . selected( $value, '1', false ) . '>' . esc_html__( 'Noindex (hide from search results)', 'searchpilot-pro' ) . '</option>';
				echo '</select>';
				break;
			case 'canonical':
				echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				echo '<p class="description">' . esc_html__( 'Leave empty to use the archive’s own URL (recommended).', 'searchpilot-pro' ) . '</p>';
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				echo '<p class="description">' . esc_html__( 'Leave empty to use the title template. Variables: %term%, %sitename%, %sep%.', 'searchpilot-pro' ) . '</p>';
		}
	}

	/**
	 * Saves the fields.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}
		$input = isset( $_POST['searchpilot_term'] ) && is_array( $_POST['searchpilot_term'] ) ? wp_unslash( $_POST['searchpilot_term'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		foreach ( array_keys( $this->owned() ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$raw = (string) $input[ $field ];
			if ( 'canonical' === $field && trim( $raw ) === (string) get_term_meta( (int) $term_id, MetaStore::PREFIX . $field, true ) ) {
				continue; // Unchanged (keeps a canonical an administrator pointed elsewhere).
			}
			switch ( $field ) {
				case 'description':
					$clean = EditorModule::clean_text( $raw );
					break;
				case 'noindex':
					$clean = in_array( $raw, array( '0', '1' ), true ) ? $raw : '';
					break;
				case 'canonical':
					// Only URLs of this site, unless an administrator sets it.
					$clean = EditorModule::sanitize_field( 'canonical', $raw );
					break;
				default:
					$clean = EditorModule::clean_text( $raw );
			}
			if ( '' === $clean ) {
				delete_term_meta( (int) $term_id, MetaStore::PREFIX . $field );
			} else {
				update_term_meta( (int) $term_id, MetaStore::PREFIX . $field, wp_slash( $clean ) );
			}
		}
	}
}

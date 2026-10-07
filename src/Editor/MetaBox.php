<?php
/**
 * Classic editor meta box.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Editor;

use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * SEO fields for the classic editor (the block editor uses the sidebar).
 * Saving is the author's own edit, so values are written straight to
 * SearchPilot's meta keys after a nonce and edit_post check.
 */
class MetaBox {

	const NONCE = 'searchpilot_meta_box';

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
	 * @param Settings         $settings  Settings.
	 * @param MetaStore        $meta      Meta store.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param ContentTypes     $types     Content types.
	 */
	public function __construct( Settings $settings, MetaStore $meta, ConflictDetector $conflicts, ContentTypes $types ) {
		$this->settings  = $settings;
		$this->meta      = $meta;
		$this->conflicts = $conflicts;
		$this->types     = $types;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Adds the box (classic editor only).
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 */
	public function add( $post_type, $post = null ) {
		if ( ! isset( $this->types->post_types()[ $post_type ] ) ) {
			return;
		}
		add_meta_box(
			'searchpilot-seo',
			__( 'SearchPilot SEO', 'searchpilot-pro' ),
			array( $this, 'render' ),
			$post_type,
			'normal',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Scripts for the classic editor screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) || ! isset( $this->types->post_types()[ $screen->post_type ] ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( EditorModule::EDITOR_STYLE, SEARCHPILOT_URL . 'assets/css/editor.css', array(), SEARCHPILOT_VERSION );
		wp_enqueue_script( EditorModule::CLASSIC_SCRIPT, SEARCHPILOT_URL . 'assets/js/editor/classic.js', array( 'wp-api-fetch', 'wp-i18n', 'jquery' ), SEARCHPILOT_VERSION, true );
		wp_set_script_translations( EditorModule::CLASSIC_SCRIPT, 'searchpilot-pro', SEARCHPILOT_DIR . 'languages' );
		$post = get_post();
		$data = \SearchPilot\Plugin::instance()->get( EditorModule::class )->editor_data( $screen->post_type );
		$data['postId']    = $post ? (int) $post->ID : 0;
		$data['permalink'] = $post ? (string) get_permalink( $post ) : '';
		wp_add_inline_script( EditorModule::CLASSIC_SCRIPT, 'window.SearchPilotEditor = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		$names = array();
		foreach ( $this->conflicts->detect() as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		$value = function ( $field ) use ( $post ) {
			return get_post_meta( $post->ID, MetaStore::PREFIX . $field, true );
		};
		$own   = function ( $field ) use ( $post ) {
			return 'searchpilot' === $this->meta->store_for( EditorModule::store_field( $field ) ) && EditorModule::user_can_edit_field( $field, $post->ID );
		};
		// Indexing, canonical and structured data settings are for editors and administrators.
		$advanced = Capabilities::can_edit( $post->ID );
		?>
		<div class="spp-mb" id="spp-mb">
			<div class="spp-mb-top">
				<div class="spp-mb-score" id="spp-classic-score" aria-live="polite"></div>
				<div class="spp-mb-serp" id="spp-classic-serp"></div>
			</div>
			<p class="spp-mb-disclaimer"><?php esc_html_e( 'The SearchPilot score is an internal diagnostic, not a Google ranking.', 'searchpilot-pro' ); ?></p>

			<h4 class="spp-mb-heading"><?php esc_html_e( 'Search appearance', 'searchpilot-pro' ); ?></h4>
			<?php
			$this->text_field( $post, 'title', __( 'SEO title', 'searchpilot-pro' ), $own( 'title' ), $names, array( 'counter' => '30,60', 'placeholder' => (string) $this->settings->get( 'page' === $post->post_type ? 'meta.title_page' : 'meta.title_post', '%title% %sep% %sitename%' ), 'help' => __( 'Variables: %title%, %sitename%, %sep%, %category%, %excerpt%.', 'searchpilot-pro' ) ) );
			$this->text_field( $post, 'description', __( 'Meta description', 'searchpilot-pro' ), $own( 'description' ), $names, array( 'counter' => '120,156', 'textarea' => true ) );
			$this->text_field( $post, 'focus', __( 'Focus keyword', 'searchpilot-pro' ), $own( 'focus' ), $names, array( 'help' => __( 'The main topic this page should be found for.', 'searchpilot-pro' ) ) );
			?>
			<div class="spp-mb-analysis" id="spp-classic-analysis" aria-live="polite"></div>

			<details class="spp-mb-section">
				<summary><?php esc_html_e( 'Social sharing', 'searchpilot-pro' ); ?></summary>
				<?php
				$this->text_field( $post, 'og_title', __( 'Social title', 'searchpilot-pro' ), $own( 'og_title' ), $names );
				$this->text_field( $post, 'og_description', __( 'Social description', 'searchpilot-pro' ), $own( 'og_description' ), $names, array( 'textarea' => true ) );
				if ( $own( 'og_image' ) ) {
					$image = (string) $value( 'og_image' );
					$src   = ctype_digit( $image ) ? (string) wp_get_attachment_image_url( (int) $image, 'medium' ) : $image;
					?>
					<div class="spp-mb-field">
						<label for="spp-mb-og_image"><?php esc_html_e( 'Social image', 'searchpilot-pro' ); ?></label>
						<div class="spp-mb-media">
							<img id="spp-mb-og_image-preview" src="<?php echo esc_url( $src ); ?>" alt="" <?php echo '' === $src ? 'hidden' : ''; ?> />
							<input type="hidden" id="spp-mb-og_image" name="searchpilot[og_image]" value="<?php echo esc_attr( $image ); ?>" />
							<button type="button" class="button" id="spp-mb-og_image-choose"><?php esc_html_e( 'Choose image', 'searchpilot-pro' ); ?></button>
							<button type="button" class="button-link" id="spp-mb-og_image-remove"><?php esc_html_e( 'Remove', 'searchpilot-pro' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Defaults to the featured image. 1200 × 630 px works best.', 'searchpilot-pro' ); ?></p>
					</div>
					<?php
				} else {
					$this->readonly( 'og_image', __( 'Social image', 'searchpilot-pro' ), $post, $names );
				}
				$this->text_field( $post, 'twitter_title', __( 'X/Twitter title', 'searchpilot-pro' ), $own( 'twitter_title' ), $names );
				$this->text_field( $post, 'twitter_description', __( 'X/Twitter description', 'searchpilot-pro' ), $own( 'twitter_description' ), $names, array( 'textarea' => true ) );
				?>
			</details>

			<details class="spp-mb-section">
				<summary><?php esc_html_e( 'Advanced', 'searchpilot-pro' ); ?></summary>
				<?php
				if ( ! $advanced ) {
					echo '<p class="description">' . esc_html__( 'Indexing, canonical and structured data settings can be changed by editors and administrators.', 'searchpilot-pro' ) . '</p>';
				}
				$this->text_field( $post, 'canonical', __( 'Canonical URL', 'searchpilot-pro' ), $own( 'canonical' ), $names, array( 'type' => 'url', 'help' => Capabilities::can_manage() ? __( 'Leave empty to use this page’s own URL (recommended).', 'searchpilot-pro' ) : __( 'Leave empty to use this page’s own URL (recommended). Only URLs of this site are accepted.', 'searchpilot-pro' ) ) );
				if ( $own( 'noindex' ) ) {
					$noindex = (string) $value( 'noindex' );
					?>
					<div class="spp-mb-field">
						<label for="spp-mb-noindex"><?php esc_html_e( 'Search engine indexing', 'searchpilot-pro' ); ?></label>
						<select id="spp-mb-noindex" name="searchpilot[noindex]">
							<option value="" <?php selected( $noindex, '' ); ?>><?php esc_html_e( 'Default for this content type', 'searchpilot-pro' ); ?></option>
							<option value="0" <?php selected( $noindex, '0' ); ?>><?php esc_html_e( 'Index (show in search results)', 'searchpilot-pro' ); ?></option>
							<option value="1" <?php selected( $noindex, '1' ); ?>><?php esc_html_e( 'Noindex (hide from search results)', 'searchpilot-pro' ); ?></option>
						</select>
					</div>
					<?php
					$this->checkbox( 'nofollow', __( 'Ask search engines not to follow links on this page (nofollow)', 'searchpilot-pro' ), '1' === (string) $value( 'nofollow' ) );
				} else {
					$this->readonly( 'noindex', __( 'Search engine indexing', 'searchpilot-pro' ), $post, $names );
				}
				if ( $own( 'schema_type' ) ) {
					$labels  = EditorModule::schema_labels();
					$current = (string) $value( 'schema_type' );
					$default = $this->types->schema_type( $post->post_type );
					?>
					<div class="spp-mb-field">
						<label for="spp-mb-schema_type"><?php esc_html_e( 'Schema type', 'searchpilot-pro' ); ?></label>
						<select id="spp-mb-schema_type" name="searchpilot[schema_type]">
							<?php /* translators: %s: default schema type. */ ?>
							<option value=""><?php echo esc_html( sprintf( __( 'Default (%s)', 'searchpilot-pro' ), $labels[ $default ] ?? $default ) ); ?></option>
							<?php foreach ( EditorModule::SCHEMA_TYPES as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $current, $type ); ?>><?php echo esc_html( $labels[ $type ] ?? $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php
					$this->checkbox( 'faq', __( 'Describe the Details blocks on this page as an FAQ (structured data)', 'searchpilot-pro' ), (bool) $value( 'faq' ), __( 'Google shows FAQ rich results only for well-known, authoritative government and health websites.', 'searchpilot-pro' ) );
				}
				$this->text_field( $post, 'breadcrumb_title', __( 'Breadcrumb title', 'searchpilot-pro' ), $own( 'breadcrumb_title' ), $names, array( 'help' => __( 'Shorter name for breadcrumbs. Leave empty to use the title.', 'searchpilot-pro' ) ) );
				if ( $advanced ) {
					$this->checkbox( 'cornerstone', __( 'Cornerstone content (a key page other posts should link to)', 'searchpilot-pro' ), (bool) $value( 'cornerstone' ) );
					if ( $this->conflicts->owns( 'sitemap' ) ) {
						$this->checkbox( 'sitemap_exclude', __( 'Leave this page out of the XML sitemap', 'searchpilot-pro' ), (bool) $value( 'sitemap_exclude' ) );
					} else {
						$owner = $this->conflicts->owner( 'sitemap' );
						echo '<p class="description">' . esc_html(
							'off' === $owner
								? __( 'XML sitemaps are switched off in SearchPilot → Settings.', 'searchpilot-pro' )
								/* translators: %s: plugin name. */
								: sprintf( __( 'The XML sitemap is handled by %s; exclude this page there.', 'searchpilot-pro' ), $names[ $owner ] ?? $owner )
						) . '</p>';
					}
					$this->checkbox( 'links_exclude', __( 'Never add automatic internal links to or from this page', 'searchpilot-pro' ), (bool) $value( 'links_exclude' ) );
				}
				if ( $advanced && $this->settings->get( 'indexing.google_indexing_api', false ) ) {
					$api = (string) $value( 'indexing_api' );
					?>
					<div class="spp-mb-field">
						<label for="spp-mb-indexing_api"><?php esc_html_e( 'Google Indexing API', 'searchpilot-pro' ); ?></label>
						<select id="spp-mb-indexing_api" name="searchpilot[indexing_api]">
							<option value="" <?php selected( $api, '' ); ?>><?php esc_html_e( 'Not eligible (normal page)', 'searchpilot-pro' ); ?></option>
							<option value="JobPosting" <?php selected( $api, 'JobPosting' ); ?>><?php esc_html_e( 'Job posting', 'searchpilot-pro' ); ?></option>
							<option value="BroadcastEvent" <?php selected( $api, 'BroadcastEvent' ); ?>><?php esc_html_e( 'Livestream (BroadcastEvent)', 'searchpilot-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Google allows the Indexing API only for job postings and livestream videos.', 'searchpilot-pro' ); ?></p>
					</div>
					<?php
				}
				?>
			</details>
		</div>
		<?php
	}

	/**
	 * Text/textarea field, or the other plugin's value read-only.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $field    Field.
	 * @param string   $label    Label.
	 * @param bool     $editable SearchPilot stores this field.
	 * @param array    $names    Plugin names.
	 * @param array    $opts     textarea, counter ("min,max"), placeholder, help, type.
	 */
	private function text_field( $post, $field, $label, $editable, array $names, array $opts = array() ) {
		if ( ! $editable ) {
			$this->readonly( $field, $label, $post, $names );
			return;
		}
		$id    = 'spp-mb-' . $field;
		$value = (string) get_post_meta( $post->ID, MetaStore::PREFIX . $field, true );
		echo '<div class="spp-mb-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( ! empty( $opts['counter'] ) ) {
			echo ' <span class="spp-mb-counter" data-for="' . esc_attr( $id ) . '" data-range="' . esc_attr( (string) $opts['counter'] ) . '"></span>';
		}
		if ( ! empty( $opts['textarea'] ) ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="searchpilot[' . esc_attr( $field ) . ']" rows="3" class="widefat">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $opts['type'] ?? 'text' ) . '" id="' . esc_attr( $id ) . '" name="searchpilot[' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '" class="widefat" placeholder="' . esc_attr( (string) ( $opts['placeholder'] ?? '' ) ) . '" />';
		}
		if ( ! empty( $opts['help'] ) ) {
			echo '<p class="description">' . esc_html( (string) $opts['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Checkbox for a SearchPilot-only flag.
	 *
	 * @param string $field   Field.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $help    Help.
	 */
	private function checkbox( $field, $label, $checked, $help = '' ) {
		echo '<div class="spp-mb-field is-check">';
		echo '<input type="hidden" name="searchpilot[' . esc_attr( $field ) . ']" value="0" />';
		echo '<label><input type="checkbox" name="searchpilot[' . esc_attr( $field ) . ']" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Another plugin's value, read-only.
	 *
	 * @param string   $field Field.
	 * @param string   $label Label.
	 * @param \WP_Post $post  Post.
	 * @param array    $names Plugin names.
	 */
	private function readonly( $field, $label, $post, array $names ) {
		$store = $this->meta->store_for( EditorModule::store_field( $field ) );
		$raw   = $this->meta->get_post_field( $post->ID, EditorModule::store_field( $field ) );
		if ( is_bool( $raw ) ) {
			$shown = $raw ? __( 'Yes', 'searchpilot-pro' ) : __( 'No', 'searchpilot-pro' );
		} elseif ( null === $raw ) {
			$shown = __( 'Default', 'searchpilot-pro' );
		} else {
			$shown = '' !== (string) $raw ? (string) $raw : __( '(empty)', 'searchpilot-pro' );
		}
		$name = $names[ $store ] ?? $store;
		echo '<div class="spp-mb-field is-readonly">';
		echo '<span class="spp-mb-label">' . esc_html( $label ) . '</span>';
		echo '<div class="spp-mb-readonly">' . esc_html( $shown ) . '</div>';
		if ( 'searchpilot' === $store ) {
			echo '<p class="description">' . esc_html__( 'Only editors and administrators can change this setting.', 'searchpilot-pro' ) . '</p>';
		} else {
			/* translators: %s: SEO plugin name. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Edited in %s, which outputs this for your site.', 'searchpilot-pro' ), $name ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Saves the box.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post = null ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['searchpilot'] ) && is_array( $_POST['searchpilot'] ) ? wp_unslash( $_POST['searchpilot'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		foreach ( EditorModule::fields() as $field => $def ) {
			if ( ! array_key_exists( $field, $input ) || 'primary_category' === $field ) {
				continue;
			}
			if ( 'searchpilot' !== $this->meta->store_for( EditorModule::store_field( $field ) ) || ! EditorModule::user_can_edit_field( $field, $post_id ) ) {
				continue;
			}
			if ( 'sitemap_exclude' === $field && ! $this->conflicts->owns( 'sitemap' ) ) {
				continue;
			}
			if ( 'canonical' === $field && trim( (string) $input[ $field ] ) === (string) get_post_meta( $post_id, $def['key'], true ) ) {
				continue; // Unchanged (keeps a canonical an administrator pointed elsewhere).
			}
			$clean = EditorModule::sanitize_field( $field, $input[ $field ] );
			if ( '' === $clean || false === $clean || 0 === $clean ) {
				delete_post_meta( $post_id, $def['key'] );
			} else {
				update_post_meta( $post_id, $def['key'], true === $clean ? '1' : wp_slash( (string) $clean ) );
			}
		}
	}
}

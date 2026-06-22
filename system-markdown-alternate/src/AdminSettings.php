<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pannello di configurazione in wp-admin (Impostazioni → Markdown Alternate).
 *
 * Le opzioni salvate sovrascrivono i default definiti nel codice tramite i filtri
 * `sma_markdown_*`. Se un campo viene lasciato vuoto, il codice mantiene il default.
 */
class AdminSettings {

	const PAGE         = 'sma-settings';
	const OPTION_GROUP = 'sma_options';

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		$this->hook_filters();
	}

	public function add_menu(): void {
		add_options_page(
			'Markdown Alternate',
			'Markdown Alternate',
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'sma_cache_ttl',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_excluded_shortcodes',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_excluded_block_names',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_excluded_classes',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_supported_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_robots_header',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		add_settings_section( 'sma_cache', 'Cache', '__return_false', self::PAGE );
		add_settings_section( 'sma_exclusions', 'Exclusions', array( $this, 'render_exclusions_intro' ), self::PAGE );
		add_settings_section( 'sma_advanced', 'Advanced', '__return_false', self::PAGE );

		add_settings_field( 'sma_cache_ttl', 'Cache TTL (seconds)', array( $this, 'field_cache_ttl' ), self::PAGE, 'sma_cache' );
		add_settings_field( 'sma_excluded_shortcodes', 'Excluded shortcodes', array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sma_exclusions' );
		add_settings_field( 'sma_excluded_block_names', 'Excluded block names', array( $this, 'field_excluded_block_names' ), self::PAGE, 'sma_exclusions' );
		add_settings_field( 'sma_excluded_classes', 'Excluded CSS classes', array( $this, 'field_excluded_classes' ), self::PAGE, 'sma_exclusions' );
		add_settings_field( 'sma_supported_post_types', 'Supported post types', array( $this, 'field_post_types' ), self::PAGE, 'sma_advanced' );
		add_settings_field( 'sma_robots_header', 'X-Robots-Tag', array( $this, 'field_robots_header' ), self::PAGE, 'sma_advanced' );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	public function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}

	/**
	 * Aggancia le opzioni salvate sui filtri corrispondenti (priorità 20, dopo i default).
	 *
	 * Convenzione: get_option() ritorna false se l'opzione non è mai stata salvata,
	 * '' se è stata salvata vuota. In entrambi i casi si usano i default del codice.
	 */
	private function hook_filters(): void {
		add_filter(
			'sma_markdown_cache_ttl',
			function ( $default, $post ) {
				$v = get_option( 'sma_cache_ttl' );
				return false !== $v ? (int) $v : $default;
			},
			20,
			2
		);

		add_filter(
			'sma_markdown_excluded_shortcodes',
			function ( $defaults ) {
				return $this->option_to_list( 'sma_excluded_shortcodes', $defaults );
			},
			20
		);

		add_filter(
			'sma_markdown_excluded_block_names',
			function ( $defaults ) {
				return $this->option_to_list( 'sma_excluded_block_names', $defaults );
			},
			20
		);

		add_filter(
			'sma_markdown_excluded_classes',
			function ( $defaults ) {
				return $this->option_to_list( 'sma_excluded_classes', $defaults );
			},
			20
		);

		add_filter(
			'sma_markdown_supported_post_types',
			function ( $defaults ) {
				$v = get_option( 'sma_supported_post_types' );
				if ( false === $v ) {
					return $defaults;
				}
				$list = (array) $v;
				return ! empty( $list ) ? $list : $defaults;
			},
			20
		);

		add_filter(
			'sma_markdown_robots_header',
			function ( $default, $post ) {
				$v = get_option( 'sma_robots_header' );
				return false !== $v ? $v : $default;
			},
			20,
			2
		);
	}

	/**
	 * Converte un'opzione textarea (una voce per riga) in array.
	 * Se vuota o non impostata ritorna i $defaults.
	 *
	 * @param string[] $defaults
	 * @return string[]
	 */
	private function option_to_list( string $option, array $defaults ): array {
		$v = get_option( $option );
		if ( false === $v || '' === $v ) {
			return $defaults;
		}
		$items = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $v ) ) ) );
		return ! empty( $items ) ? $items : $defaults;
	}

	// ─── Rendering ────────────────────────────────────────────────────────────

	public function render_exclusions_intro(): void {
		echo '<p>One per line. Leave empty to use the built-in defaults.</p>';
	}

	public function field_cache_ttl(): void {
		$v = get_option( 'sma_cache_ttl' );
		$v = false !== $v ? (int) $v : DAY_IN_SECONDS;
		echo '<input type="number" min="0" step="1" name="sma_cache_ttl" value="' . esc_attr( $v ) . '" class="small-text" />';
		echo '<p class="description">0 = cache disabled. Default: 86400 (24 h).</p>';
	}

	public function field_excluded_shortcodes(): void {
		$v = (string) get_option( 'sma_excluded_shortcodes', '' );
		echo '<textarea name="sma_excluded_shortcodes" rows="5" class="large-text code">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">Default: contact-form-7, gravityform, wpforms, mailerlite_form, lwptoc</p>';
	}

	public function field_excluded_block_names(): void {
		$v = (string) get_option( 'sma_excluded_block_names', '' );
		echo '<textarea name="sma_excluded_block_names" rows="5" class="large-text code">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">Default: gravityforms/form, contact-form-7/contact-form-selector, wpforms/form-selector, mailerlite/form, luckywp/toc</p>';
	}

	public function field_excluded_classes(): void {
		$v = (string) get_option( 'sma_excluded_classes', '' );
		echo '<textarea name="sma_excluded_classes" rows="3" class="large-text code">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">Default: no-md, md-exclude, exclude-from-markdown</p>';
	}

	public function field_post_types(): void {
		$saved     = (array) get_option( 'sma_supported_post_types', array() );
		$all_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $all_types as $pt ) {
			$checked = in_array( $pt->name, $saved, true ) ? ' checked' : '';
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="sma_supported_post_types[]" value="%s"%s /> %s <code>(%s)</code></label>',
				esc_attr( $pt->name ),
				$checked,
				esc_html( $pt->labels->singular_name ),
				esc_html( $pt->name )
			);
		}
		echo '<p class="description">Default: Posts only. Check additional types to enable .md endpoints and llms.txt listing.</p>';
	}

	public function field_robots_header(): void {
		$v = get_option( 'sma_robots_header' );
		$v = false !== $v ? (string) $v : 'noindex, follow';
		echo '<input type="text" name="sma_robots_header" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Default: "noindex, follow". Empty = header not sent.</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

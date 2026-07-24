<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration panel in wp-admin (Settings => Markdown Alternate).
 *
 * A single page using the native Settings API, with one option per setting.
 * Sections are grouped by scope (General, Markdown output, llms.txt,
 * Integrations, Advanced), but remain in one form: saving writes every option
 * in the group, so settings from other sections cannot be lost.
 *
 * Saved options override code defaults through the `sysmda_markdown_*` filters.
 * An empty field means the default is used.
 */
class AdminSettings {

	const PAGE         = 'sysmda-settings';
	const OPTION_GROUP = 'sysmda_options';

	/** Exclusion defaults (displayed in the panel for reference only). */
	const DEFAULT_SHORTCODES  = array( 'contact-form-7', 'gravityform', 'wpforms', 'mailerlite_form', 'lwptoc' );
	const DEFAULT_BLOCK_NAMES = array( 'gravityforms/form', 'contact-form-7/contact-form-selector', 'wpforms/form-selector', 'mailerlite/form', 'luckywp/toc' );
	const DEFAULT_CSS_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/** @var string Settings page hook (used to load assets only on that page). */
	private $hook = '';

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// "Settings" action link on the plugin row in the Plugins list.
		add_filter( 'plugin_action_links_' . plugin_basename( SYSMDA_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );

		// Invalidate the Markdown cache when a plugin option changes.
		add_action( 'added_option', array( $this, 'maybe_bump_cache_salt' ) );
		add_action( 'updated_option', array( $this, 'maybe_bump_cache_salt' ) );

		$this->hook_filters();
	}

	/**
	 * Bumps the cache salt when a plugin option is saved, so all cached Markdown
	 * is regenerated on the next request.
	 *
	 * The hit-counter buckets are excluded: they are written on every counted
	 * `.md` request and do not affect the Markdown output, so bumping the salt
	 * for them would invalidate the whole cache (and change every ETag) on
	 * each hit.
	 *
	 * @param string $option Name of the option that was just saved.
	 */
	public function maybe_bump_cache_salt( $option ): void {
		if ( ! is_string( $option ) || 0 !== strpos( $option, 'sysmda_' )
			|| 'sysmda_cache_salt' === $option || HitCounter::OPTION === $option ) {
			return;
		}

		static $bumped = false;
		if ( $bumped ) {
			return; // Only one bump per request, even when multiple options change.
		}
		$bumped = true;

		update_option( 'sysmda_cache_salt', (string) time() );
	}

	/**
	 * Prepends a "Settings" link to the plugin's action links in the Plugins list.
	 *
	 * @param array $links Existing action links (Deactivate, ...).
	 * @return array Action links with the Settings link first.
	 */
	public function add_settings_link( $links ): array {
		$links = (array) $links;

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'system-markdown-alternate' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function add_menu(): void {
		$this->hook = (string) add_options_page(
			__( 'Markdown Alternate', 'system-markdown-alternate' ),
			__( 'Markdown Alternate', 'system-markdown-alternate' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		// Align .htaccess with the LiteSpeed option every time the settings page
		// loads. options.php redirects back here after saving, so a toggle is
		// applied right away, and a manually restored .htaccess gets repaired.
		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'sync_litespeed_htaccess' ) );
		}
	}

	/**
	 * Writes or removes the LiteSpeed compatibility block in .htaccess so it
	 * matches the `sysmda_litespeed_htaccess` option (see LiteSpeedCompat).
	 */
	public function sync_litespeed_htaccess(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		LiteSpeedCompat::sync( '1' === get_option( 'sysmda_litespeed_htaccess', '0' ) );
	}

	/**
	 * Loads the panel CSS only on this plugin's settings page.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 */
	public function enqueue_assets( $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'sysmda-admin-settings',
			SYSMDA_PLUGIN_URL . 'assets/admin-settings.css',
			array(),
			SYSMDA_VERSION
		);

		// Client-side tabs (progressive enhancement): vanilla JS, no dependencies.
		// Without JS, every panel remains visible and all fields remain in the form.
		wp_enqueue_script(
			'sysmda-admin-settings',
			SYSMDA_PLUGIN_URL . 'assets/admin-settings.js',
			array(),
			SYSMDA_VERSION,
			true
		);
	}

	public function register_settings(): void {
		// ── Opzioni sempre registrate ──────────────────────────────────────────
		register_setting(
			self::OPTION_GROUP,
			'sysmda_cache_ttl',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_excluded_shortcodes',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_lines' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_excluded_block_names',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_lines' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_excluded_classes',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_class_lines' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_supported_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_robots_header',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_llms_txt_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_llms_txt_enriched',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_llms_txt_lastmod',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_llms_txt_summary',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_llms_txt_key_content',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_lines' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_litespeed_htaccess',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_md_hits_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);

		// ACF options are registered ONLY when ACF is active. This prevents saving
		// the form from clearing them when ACF is inactive and its fields are absent
		// (options.php writes only options registered in the group).
		if ( $this->acf_active() ) {
			register_setting(
				self::OPTION_GROUP,
				'sysmda_acf_subtitle_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			register_setting(
				self::OPTION_GROUP,
				'sysmda_acf_tldr_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// ── Generale ───────────────────────────────────────────────────────────
		add_settings_section( 'sysmda_general', __( 'General', 'system-markdown-alternate' ), array( $this, 'render_general_intro' ), self::PAGE );
		add_settings_field( 'sysmda_supported_post_types', __( 'Enabled content types', 'system-markdown-alternate' ), array( $this, 'field_post_types' ), self::PAGE, 'sysmda_general' );
		add_settings_field( 'sysmda_cache_ttl', __( 'Cache TTL (seconds)', 'system-markdown-alternate' ), array( $this, 'field_cache_ttl' ), self::PAGE, 'sysmda_general' );

		// ── Output Markdown ──────────────────────────────────────────────────────
		add_settings_section( 'sysmda_markdown', __( 'Markdown output', 'system-markdown-alternate' ), array( $this, 'render_markdown_intro' ), self::PAGE );
		add_settings_field( 'sysmda_excluded_shortcodes', __( 'Excluded shortcodes', 'system-markdown-alternate' ), array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_excluded_block_names', __( 'Excluded blocks', 'system-markdown-alternate' ), array( $this, 'field_excluded_block_names' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_excluded_classes', __( 'Excluded CSS classes', 'system-markdown-alternate' ), array( $this, 'field_excluded_classes' ), self::PAGE, 'sysmda_markdown' );

		if ( $this->acf_active() ) {
			add_settings_field( 'sysmda_acf_subtitle_key', __( 'ACF subtitle field', 'system-markdown-alternate' ), array( $this, 'field_acf_subtitle_key' ), self::PAGE, 'sysmda_markdown' );
			add_settings_field( 'sysmda_acf_tldr_key', __( 'ACF TL;DR field', 'system-markdown-alternate' ), array( $this, 'field_acf_tldr_key' ), self::PAGE, 'sysmda_markdown' );
		} else {
			add_settings_field( 'sysmda_acf_notice', __( 'ACF fields', 'system-markdown-alternate' ), array( $this, 'field_acf_notice' ), self::PAGE, 'sysmda_markdown' );
		}

		// ── llms.txt ─────────────────────────────────────────────────────────────
		add_settings_section( 'sysmda_llmstxt', 'llms.txt', array( $this, 'render_llmstxt_intro' ), self::PAGE );
		add_settings_field( 'sysmda_llms_txt_enabled', __( 'Enable /llms.txt', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_enabled' ), self::PAGE, 'sysmda_llmstxt' );
		add_settings_field( 'sysmda_llms_txt_enriched', __( 'Enriched output', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_enriched' ), self::PAGE, 'sysmda_llmstxt' );
		add_settings_field( 'sysmda_llms_txt_lastmod', __( 'Last modified dates', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_lastmod' ), self::PAGE, 'sysmda_llmstxt' );
		add_settings_field( 'sysmda_llms_txt_summary', __( 'Site summary', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_summary' ), self::PAGE, 'sysmda_llmstxt' );
		add_settings_field( 'sysmda_llms_txt_key_content', __( 'Key content', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_key_content' ), self::PAGE, 'sysmda_llmstxt' );

		// ── Integrations (informational only) ──────────────────────────────────────
		add_settings_section( 'sysmda_integrations', __( 'Integrations', 'system-markdown-alternate' ), array( $this, 'render_integrations_intro' ), self::PAGE );

		// ── Avanzate ─────────────────────────────────────────────────────────────
		add_settings_section( 'sysmda_advanced', __( 'Advanced', 'system-markdown-alternate' ), array( $this, 'render_advanced_intro' ), self::PAGE );
		add_settings_field( 'sysmda_robots_header', 'X-Robots-Tag', array( $this, 'field_robots_header' ), self::PAGE, 'sysmda_advanced' );
		add_settings_field( 'sysmda_litespeed_htaccess', __( 'LiteSpeed cache compatibility', 'system-markdown-alternate' ), array( $this, 'field_litespeed_htaccess' ), self::PAGE, 'sysmda_advanced' );
		add_settings_field( 'sysmda_md_hits_enabled', __( 'Hit counter', 'system-markdown-alternate' ), array( $this, 'field_md_hits_enabled' ), self::PAGE, 'sysmda_advanced' );
	}

	/**
	 * Is ACF active (and therefore defining get_field())?
	 */
	private function acf_active(): bool {
		return function_exists( 'get_field' );
	}

	/**
	 * Is GenerateBlocks 2.x (with Dynamic Tags) active?
	 */
	private function generateblocks_active(): bool {
		return class_exists( 'GenerateBlocks_Register_Dynamic_Tag' );
	}

	// ─── Sanitizzazione ─────────────────────────────────────────────────────────

	/**
	 * Post type allowlist: keeps only registered public types (excluding Media).
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = get_post_types( array( 'public' => true ), 'names' );
		unset( $allowed['attachment'] );

		$clean = array();
		foreach ( $value as $item ) {
			$item = sanitize_key( $item );
			if ( '' !== $item && isset( $allowed[ $item ] ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Normalizes a "one entry per line" textarea: trims entries, removes empty
	 * lines, applies sanitize_text_field, and deduplicates. Preserves a multiline string.
	 *
	 * @param mixed $value
	 */
	public function sanitize_lines( $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = sanitize_text_field( trim( $line ) );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Normalizes CSS-class tokens with WordPress's class-specific sanitizer,
	 * removes empty entries, and deduplicates them (first-seen order preserved).
	 *
	 * Whitespace-separated: although the UI asks for one class per line, spaces
	 * and tabs are accepted so pasted class lists are handled — sanitizing a
	 * whole line such as "foo bar" would otherwise produce the unintended class
	 * "foobar". Note this NORMALIZES rather than rejects: `sanitize_html_class()`
	 * reduces each token to the ASCII letter/digit/hyphen/underscore subset
	 * (`.notice` → `notice`, `<script>` → `script`, punctuation-only → dropped).
	 *
	 * @param mixed $value
	 */
	public function sanitize_class_lines( $value ): string {
		$tokens = preg_split( '/\s+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		$out    = array();

		foreach ( (array) $tokens as $token ) {
			$class = sanitize_html_class( $token );
			if ( '' !== $class && ! in_array( $class, $out, true ) ) {
				$out[] = $class;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * @param mixed $value
	 */
	public function sanitize_checkbox( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Hooks saved options into the corresponding filters (priority 20, after defaults).
	 *
	 * Convention: get_option() returns false when the option has never been saved,
	 * and '' when it was saved empty. Code defaults are used in both cases.
	 */
	private function hook_filters(): void {
		add_filter(
			'sysmda_markdown_cache_ttl',
			function ( $default, $post ) {
				$v = get_option( 'sysmda_cache_ttl' );
				return false !== $v ? (int) $v : $default;
			},
			20,
			2
		);

		add_filter(
			'sysmda_llms_txt_cache_ttl',
			function ( $default ) {
				$v = get_option( 'sysmda_cache_ttl' );
				return false !== $v ? (int) $v : $default;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_enriched',
			function ( $default ) {
				$v = get_option( 'sysmda_llms_txt_enriched' );
				return false !== $v ? '1' === $v : $default;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_lastmod',
			function ( $default ) {
				$v = get_option( 'sysmda_llms_txt_lastmod' );
				return false !== $v ? '1' === $v : $default;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_summary',
			function ( $default ) {
				$v = get_option( 'sysmda_llms_txt_summary' );
				return ( false !== $v && '' !== trim( (string) $v ) ) ? (string) $v : $default;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_key_content',
			function ( $defaults ) {
				return $this->option_to_list( 'sysmda_llms_txt_key_content', (array) $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_shortcodes',
			function ( $defaults ) {
				return $this->option_to_list( 'sysmda_excluded_shortcodes', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_block_names',
			function ( $defaults ) {
				return $this->option_to_list( 'sysmda_excluded_block_names', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_classes',
			function ( $defaults ) {
				return $this->option_to_list( 'sysmda_excluded_classes', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_supported_post_types',
			function ( $defaults ) {
				$v = get_option( 'sysmda_supported_post_types' );
				if ( false === $v ) {
					return $defaults;
				}
				$list = (array) $v;
				return ! empty( $list ) ? $list : $defaults;
			},
			20
		);

		add_filter(
			'sysmda_markdown_robots_header',
			function ( $default, $post ) {
				$v = get_option( 'sysmda_robots_header' );
				return false !== $v ? $v : $default;
			},
			20,
			2
		);

		add_filter(
			'sysmda_acf_subtitle_key',
			function ( $default, $post ) {
				$v = get_option( 'sysmda_acf_subtitle_key' );
				return ( false !== $v && '' !== $v ) ? $v : $default;
			},
			20,
			2
		);

		add_filter(
			'sysmda_acf_tldr_key',
			function ( $default, $post ) {
				$v = get_option( 'sysmda_acf_tldr_key' );
				return ( false !== $v && '' !== $v ) ? $v : $default;
			},
			20,
			2
		);
	}

	/**
	 * Converts a textarea option (one entry per line) to an array.
	 * Returns $defaults when the option is empty or unset.
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

	// ─── Intro sezioni ──────────────────────────────────────────────────────────

	public function render_general_intro(): void {
		echo '<p class="sysmda-help">' . esc_html__( 'Main settings. Without at least one selected content type, the plugin stays inactive.', 'system-markdown-alternate' ) . '</p>';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="sysmda-status">';
			echo wp_kses_post( __( 'Your site uses <strong>plain permalinks</strong>: the <code>.md</code> suffix is not available, so Markdown URLs fall back to <code>?format=markdown</code>. For clean <code>.md</code> URLs, choose a pretty permalink structure in Settings → Permalinks.', 'system-markdown-alternate' ) );
			echo '</div>';
		}
	}

	public function render_markdown_intro(): void {
		echo '<p class="sysmda-help">' . wp_kses_post( __( 'Controls what goes into or stays out of the <code>.md</code> file. For exclusions: one entry per line, leave empty to use the built-in defaults.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function render_advanced_intro(): void {
		echo '<p class="sysmda-help">' . esc_html__( 'Settings for advanced users.', 'system-markdown-alternate' ) . '</p>';
	}

	public function render_llmstxt_intro(): void {
		echo '<p class="sysmda-help">' . wp_kses_post( __( 'The <code>/llms.txt</code> file exposes selected site resources in a format readable by LLMs and AI agents. It currently lists the enabled Markdown content.', 'system-markdown-alternate' ) ) . '</p>';
	}

	/**
	 * Quick info nell'aside: stato dell'endpoint /llms.txt, URL e conflitti.
	 * Presentation only: uses the same data already calculated by the plugin.
	 */
	public function render_llmstxt_aside(): void {
		$enabled = '1' === get_option( 'sysmda_llms_txt_enabled', '1' );
		$url     = home_url( '/llms.txt' );

		echo '<section class="sysmda-card sysmda-aside-card">';
		echo '<header class="sysmda-card__header"><h2>' . esc_html__( 'llms.txt status', 'system-markdown-alternate' ) . '</h2></header>';
		echo '<div class="sysmda-card__body">';

		echo '<p class="sysmda-endpoint-state ' . ( $enabled ? 'is-on' : 'is-off' ) . '">';
		echo '<span class="sysmda-dot" aria-hidden="true"></span>';
		echo esc_html( $enabled ? __( 'Enabled', 'system-markdown-alternate' ) : __( 'Disabled', 'system-markdown-alternate' ) );
		echo '</p>';

		echo '<p class="sysmda-endpoint-url"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $url ) . '</code></a></p>';

		$this->render_conflict_warning();

		echo '</div></section>';
	}

	public function render_integrations_intro(): void {
		echo '<p class="sysmda-help">' . wp_kses_post( __( 'Informational section: how to use the <code>.md</code> URL in content and templates.', 'system-markdown-alternate' ) ) . '</p>';

		echo '<div class="sysmda-integration-card">';
		echo '<h3>' . esc_html__( 'Shortcode', 'system-markdown-alternate' ) . '</h3>';
		echo '<p>' . wp_kses_post( __( '<code>[sysmda_md_url]</code> — <code>.md</code> URL of the current post.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sysmda_md_url id="123"]</code> — <code>.md</code> URL of a specific post.', 'system-markdown-alternate' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Returns empty if the post does not expose a .md (type not enabled, draft, or password-protected).', 'system-markdown-alternate' ) . '</p>';
		echo '</div>';

		echo '<div class="sysmda-integration-card">';
		echo '<h3>GenerateBlocks</h3>';
		if ( $this->generateblocks_active() ) {
			echo '<p>' . esc_html__( 'GenerateBlocks detected. The dynamic tag is available automatically.', 'system-markdown-alternate' ) . '</p>';
			echo '<p><code>{{sysmda_md_url}}</code></p>';
			echo '<p class="description">' . wp_kses_post( __( 'Insert <code>{{sysmda_md_url}}</code> in GenerateBlocks/GeneratePress fields that accept a dynamic tag, e.g. a button URL. If the post has no <code>.md</code>, the tag resolves to empty and the element is hidden (required to render).', 'system-markdown-alternate' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'GenerateBlocks not detected. The dynamic tag is not available.', 'system-markdown-alternate' ) . '</p>';
		}
		echo '</div>';

		echo '<div class="sysmda-integration-card">';
		echo '<h3>ACF</h3>';
		echo $this->acf_active()
			? '<p>' . wp_kses_post( __( 'ACF detected. The Subtitle and TL;DR fields are configured in the <strong>Markdown output</strong> section.', 'system-markdown-alternate' ) ) . '</p>'
			: '<p>' . esc_html__( 'ACF not detected. The Subtitle and TL;DR fields are not available.', 'system-markdown-alternate' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Warns when another /llms.txt handler is active (SEO plugin or physical file),
	 * or when the endpoint responds even though it should not.
	 */
	private function render_conflict_warning(): void {
		$detector = new ConflictDetector();

		$alerts = array(); // Likely conflicts (red).
		$notes  = array(); // Informational notes (description).

		if ( $detector->physical_file_exists() ) {
			$alerts[] = __( 'A physical <code>llms.txt</code> file exists in the site root: the web server serves it <strong>before</strong> WordPress, so this endpoint (and any other plugin\'s) is ignored.', 'system-markdown-alternate' );
		}

		$providers = $detector->detected_providers();
		if ( $providers ) {
			$notes[] = sprintf(
				/* translators: %s is a comma-separated list of active SEO plugin names. */
				__( 'Active SEO plugins that <em>might</em> handle <code>/llms.txt</code>: <strong>%s</strong>. If one of them already generates it, keep only one handler active (disable this one below, or the llms.txt feature in the other plugin).', 'system-markdown-alternate' ),
				esc_html( implode( ', ', $providers ) )
			);
		}

		if ( $alerts ) {
			echo '<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px"><p style="margin-top:0"><strong>' . esc_html__( 'Possible /llms.txt conflict:', 'system-markdown-alternate' ) . '</strong></p><ul style="list-style:disc;margin:0 0 0 20px">';
			foreach ( $alerts as $a ) {
				echo '<li>' . wp_kses_post( $a ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $notes ) {
			echo '<p class="description">' . wp_kses_post( implode( '<br>', $notes ) ) . '</p>';
		}
	}

	// ─── Campi ──────────────────────────────────────────────────────────────────

	public function field_post_types(): void {
		$raw   = get_option( 'sysmda_supported_post_types' ); // false = never saved.
		$saved = false !== $raw ? (array) $raw : array();

		$all_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $all_types['attachment'] ); // Media is always excluded.

		foreach ( $all_types as $pt ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="sysmda_supported_post_types[]" value="%s"%s /> %s <code>(%s)</code></label>',
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $saved, true ), true, false ),
				esc_html( $pt->labels->singular_name ),
				esc_html( $pt->name )
			);
		}
		echo '<p class="description">' . wp_kses_post( __( 'Content types exposed as <code>.md</code> and in <code>/llms.txt</code>. No selection = plugin inactive.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_cache_ttl(): void {
		$v = get_option( 'sysmda_cache_ttl' );
		$v = false !== $v ? (int) $v : DAY_IN_SECONDS;
		echo '<input type="number" min="0" step="1" name="sysmda_cache_ttl" value="' . esc_attr( $v ) . '" class="small-text" /> ' . esc_html__( 'seconds', 'system-markdown-alternate' );
		echo '<p class="description">' . esc_html__( '0 = cache disabled. Default: 86400 (24 hours).', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_excluded_shortcodes(): void {
		$this->render_exclusion_field( 'sysmda_excluded_shortcodes', self::DEFAULT_SHORTCODES );
	}

	public function field_excluded_block_names(): void {
		$this->render_exclusion_field( 'sysmda_excluded_block_names', self::DEFAULT_BLOCK_NAMES );
	}

	public function field_excluded_classes(): void {
		$this->render_exclusion_field( 'sysmda_excluded_classes', self::DEFAULT_CSS_CLASSES );
	}

	/**
	 * Compact "one per line" textarea plus a list of defaults.
	 *
	 * @param string[] $defaults
	 */
	private function render_exclusion_field( string $option, array $defaults ): void {
		$v = (string) get_option( $option, '' );
		echo '<textarea name="' . esc_attr( $option ) . '" rows="4" class="code sysmda-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sysmda-help">' . esc_html__( 'One per line. Leave empty to use the built-in defaults.', 'system-markdown-alternate' ) . '</p>';
		echo '<details class="sysmda-defaults-toggle"><summary>' . esc_html__( 'View built-in defaults', 'system-markdown-alternate' ) . '</summary>';
		echo '<pre class="sysmda-defaults">' . esc_html( implode( "\n", $defaults ) ) . '</pre>';
		echo '</details>';
	}

	public function field_acf_subtitle_key(): void {
		$v = (string) get_option( 'sysmda_acf_subtitle_key', '' );
		echo '<input type="text" name="sysmda_acf_subtitle_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'ACF field name for the subtitle (type: text). Inserted in italics right after the H1 title.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_acf_tldr_key(): void {
		$v = (string) get_option( 'sysmda_acf_tldr_key', '' );
		echo '<input type="text" name="sysmda_acf_tldr_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . wp_kses_post( __( 'ACF field name for the TL;DR (type: WYSIWYG editor). Inserted as a <code>**TL;DR**</code> section with <code>---</code> separators.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_acf_notice(): void {
		echo '<p class="description">' . esc_html__( 'ACF not detected: the Subtitle and TL;DR fields will appear here when ACF is active. Any previously saved settings are preserved.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_llms_txt_enabled(): void {
		$v = get_option( 'sysmda_llms_txt_enabled', '1' ); // Enabled by default.
		echo '<label><input type="checkbox" name="sysmda_llms_txt_enabled" value="1"' . checked( '1', $v, false ) . ' /> ' . wp_kses_post( __( 'Enable the <code>/llms.txt</code> endpoint', 'system-markdown-alternate' ) ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Disable if another plugin already handles <code>/llms.txt</code>.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_llms_txt_enriched(): void {
		$v = get_option( 'sysmda_llms_txt_enriched', '0' ); // Disabled by default.
		echo '<label><input type="checkbox" name="sysmda_llms_txt_enriched" value="1"' . checked( '1', $v, false ) . ' /> ' . esc_html__( 'Enable the enriched output', 'system-markdown-alternate' ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Adds the site summary, the key content section, a description for each entry (Rank Math meta → excerpt → trimmed text) and moves the overflow beyond the most recent posts into an <code>Optional</code> section. Off = the basic index only.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_llms_txt_lastmod(): void {
		$v = get_option( 'sysmda_llms_txt_lastmod', '0' ); // Disabled by default.
		echo '<label><input type="checkbox" name="sysmda_llms_txt_lastmod" value="1"' . checked( '1', $v, false ) . ' /> ' . esc_html__( 'Append the last modified date to each entry', 'system-markdown-alternate' ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Adds <code>(updated: YYYY-MM-DD)</code> after every entry, so crawlers can spot changed content without re-fetching each URL. Works with both the basic and the enriched output.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_llms_txt_summary(): void {
		$v = (string) get_option( 'sysmda_llms_txt_summary', '' );
		echo '<textarea name="sysmda_llms_txt_summary" rows="3" class="large-text sysmda-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sysmda-help">' . esc_html__( 'One short paragraph describing the site, shown after the tagline. Used only when the enriched output is enabled.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_llms_txt_key_content(): void {
		$v = (string) get_option( 'sysmda_llms_txt_key_content', '' );
		echo '<textarea name="sysmda_llms_txt_key_content" rows="4" class="code sysmda-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sysmda-help">' . esc_html__( 'Featured content: one post ID or URL per line. Listed first, before the automatic sections. Used only when the enriched output is enabled.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_robots_header(): void {
		$v = get_option( 'sysmda_robots_header' );
		$v = false !== $v ? (string) $v : 'noindex, follow';
		echo '<input type="text" name="sysmda_robots_header" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . wp_kses_post( __( 'Default: <code>noindex, follow</code>. Leave empty to not send the header.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_litespeed_htaccess(): void {
		$v = get_option( 'sysmda_litespeed_htaccess', '0' ); // Disabled by default.
		echo '<label><input type="checkbox" name="sysmda_litespeed_htaccess" value="1"' . checked( '1', $v, false ) . ' /> ' . wp_kses_post( __( 'Add LiteSpeed cache bypass rules to <code>.htaccess</code>', 'system-markdown-alternate' ) ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Some LiteSpeed servers cache pages by URL only and ignore <code>Vary: Accept</code>, breaking content negotiation on the permalink (a cached variant is served regardless of the <code>Accept</code> header). These rules make requests that negotiate Markdown bypass the LiteSpeed page cache, so PHP always decides the representation. Normal browser traffic stays fully cached; on servers other than LiteSpeed the rules are inert (<code>&lt;IfModule LiteSpeed&gt;</code>). After enabling, purge the LiteSpeed cache if entries look stale.', 'system-markdown-alternate' ) ) . '</p>';

		$detected = LiteSpeedCompat::is_litespeed();
		$present  = LiteSpeedCompat::rules_present();
		$enabled  = '1' === $v;

		$status   = array();
		$status[] = $detected
			? __( 'LiteSpeed detected on this server.', 'system-markdown-alternate' )
			: __( 'LiteSpeed not detected on this server (a proxy may hide it; enabling is harmless anyway).', 'system-markdown-alternate' );
		$status[] = $present
			? __( 'The rules are currently present in .htaccess.', 'system-markdown-alternate' )
			: __( 'The rules are currently not present in .htaccess.', 'system-markdown-alternate' );

		echo '<p class="description">' . esc_html( implode( ' ', $status ) ) . '</p>';

		// Explicit recommendation when it matters: LiteSpeed is detected and the
		// option is off. Whether the host honours Vary: Accept cannot be detected
		// reliably (loopback checks are unreliable behind WAF/CDN — rejected), so
		// the safe default for an unsure user is to enable the rules.
		if ( $detected && ! $enabled ) {
			echo '<div class="notice notice-info inline" style="margin:8px 0;padding:8px 12px"><p style="margin:0">';
			echo wp_kses_post( __( '<strong>Recommended on LiteSpeed:</strong> whether a LiteSpeed server honours <code>Vary: Accept</code> depends on the host, and it cannot be detected automatically. If you are unsure how your host behaves, enabling these rules is the safe choice: normal browser traffic stays fully cached, and on hosts that already honour <code>Vary</code> the rules are simply redundant. See the plugin FAQ for a quick manual test.', 'system-markdown-alternate' ) );
			echo '</p></div>';
		}

		if ( $enabled && ! $present && ! LiteSpeedCompat::htaccess_writable() ) {
			echo '<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px"><p style="margin:0">';
			echo wp_kses_post( __( '<strong>.htaccess is not writable</strong>: add this block manually to the site root .htaccess:', 'system-markdown-alternate' ) );
			echo '</p><pre class="sysmda-defaults">' . esc_html( '# BEGIN ' . LiteSpeedCompat::MARKER . "\n" . implode( "\n", LiteSpeedCompat::htaccess_rules() ) . "\n# END " . LiteSpeedCompat::MARKER ) . '</pre></div>';
		}
	}

	public function field_md_hits_enabled(): void {
		$v = get_option( 'sysmda_md_hits_enabled', '0' ); // Disabled by default (opt-in).
		echo '<label><input type="checkbox" name="sysmda_md_hits_enabled" value="1"' . checked( '1', $v, false ) . ' /> ' . wp_kses_post( __( 'Count <code>.md</code> requests', 'system-markdown-alternate' ) ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Stores only aggregate daily totals, split bot vs human — no IP addresses, no user-agent strings, no per-visitor data (the user agent is read once to classify the request, then discarded). Requests served by a page cache or CDN without reaching PHP are not counted: treat the numbers as an indicator, not analytics.', 'system-markdown-alternate' ) ) . '</p>';

		$this->render_md_hits_totals();
	}

	/**
	 * Read-only bot/human totals (today / last 7 / last 30 days) for the `.md`
	 * hit counter. Shown whenever data exists, so the numbers stay visible
	 * after the counter is switched off.
	 */
	private function render_md_hits_totals(): void {
		$hits = get_option( HitCounter::OPTION, array() );

		if ( ! is_array( $hits ) || empty( $hits ) ) {
			return;
		}

		$today   = gmdate( 'Y-m-d' );
		$windows = array(
			__( 'Today', 'system-markdown-alternate' ) => HitCounter::totals( $hits, $today, 1 ),
			__( 'Last 7 days', 'system-markdown-alternate' ) => HitCounter::totals( $hits, $today, 7 ),
			__( 'Last 30 days', 'system-markdown-alternate' ) => HitCounter::totals( $hits, $today, 30 ),
		);

		echo '<table class="widefat striped" style="max-width:420px;margin-top:8px">';
		echo '<thead><tr><th></th><th>' . esc_html__( 'Bots', 'system-markdown-alternate' ) . '</th><th>' . esc_html__( 'Humans', 'system-markdown-alternate' ) . '</th><th>' . esc_html__( 'Total', 'system-markdown-alternate' ) . '</th></tr></thead><tbody>';

		foreach ( $windows as $label => $totals ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				esc_html( $label ),
				(int) $totals['bot'],
				(int) $totals['human'],
				(int) $totals['bot'] + (int) $totals['human']
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Days are counted in UTC.', 'system-markdown-alternate' ) . '</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp_settings_sections, $wp_settings_fields;
		$sections = isset( $wp_settings_sections[ self::PAGE ] ) ? (array) $wp_settings_sections[ self::PAGE ] : array();
		?>
		<div class="wrap sysmda-settings-page">
			<form method="post" action="options.php" class="sysmda-settings-page__form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<header class="sysmda-settings-page__header">
					<div class="sysmda-settings-page__titles">
						<h1>
							<?php echo esc_html( get_admin_page_title() ); ?>
							<span class="sysmda-version">v<?php echo esc_html( SYSMDA_VERSION ); ?></span>
						</h1>
						<p class="sysmda-settings-page__desc"><?php esc_html_e( 'Serve a clean Markdown version of your content at the .md URL, for LLMs and AI agents.', 'system-markdown-alternate' ); ?></p>
					</div>
					<div class="sysmda-settings-page__actions">
						<?php submit_button( '', 'primary', 'submit', false ); ?>
					</div>
				</header>
				<hr class="wp-header-end">

				<?php if ( count( $sections ) > 1 ) : ?>
					<nav class="nav-tab-wrapper sysmda-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'system-markdown-alternate' ); ?>">
						<?php
						$i = 0;
						foreach ( $sections as $sid => $section ) {
							printf(
								'<a href="#sysmda-panel-%1$s" class="nav-tab%2$s" data-tab="%1$s">%3$s</a>',
								esc_attr( (string) $sid ),
								0 === $i ? ' nav-tab-active' : '',
								esc_html( (string) $section['title'] )
							);
							++$i;
						}
						?>
					</nav>
				<?php endif; ?>

				<div class="sysmda-settings-page__layout">
					<main class="sysmda-settings-page__main">
						<?php
						$i = 0;
						foreach ( $sections as $sid => $section ) {
							$sid = (string) $sid;
							printf(
								'<div class="sysmda-tab-panel%1$s" id="sysmda-panel-%2$s" data-tab="%2$s" role="tabpanel">',
								0 === $i ? ' is-active' : '',
								esc_attr( $sid )
							);
							echo '<section class="sysmda-card">';
							if ( ! empty( $section['title'] ) ) {
								echo '<header class="sysmda-card__header"><h2>' . esc_html( (string) $section['title'] ) . '</h2></header>';
							}
							echo '<div class="sysmda-card__body">';
							if ( ! empty( $section['callback'] ) ) {
								call_user_func( $section['callback'], $section );
							}
							if ( isset( $wp_settings_fields[ self::PAGE ][ $sid ] ) ) {
								echo '<table class="form-table" role="presentation">';
								do_settings_fields( self::PAGE, $sid );
								echo '</table>';
							}
							echo '</div></section></div>';
							++$i;
						}
						?>
					</main>
					<aside class="sysmda-settings-page__aside">
						<?php $this->render_llmstxt_aside(); ?>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}
}

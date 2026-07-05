<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pannello di configurazione in wp-admin (Impostazioni → Markdown Alternate).
 *
 * Pagina unica, Settings API nativa, una option per impostazione. Le sezioni
 * sono raggruppate per ambito (Generale, Output Markdown, llms.txt, Integrazioni,
 * Avanzate) ma restano un solo form: salvando si scrivono tutte le opzioni del
 * gruppo, quindi nessun rischio di perdere impostazioni di altre sezioni.
 *
 * Le opzioni salvate sovrascrivono i default del codice tramite i filtri
 * `sma_markdown_*`. Campo vuoto = si usa il default.
 */
class AdminSettings {

	const PAGE         = 'sma-settings';
	const OPTION_GROUP = 'sma_options';

	/** Default di esclusione (solo a scopo visivo nel pannello). */
	const DEFAULT_SHORTCODES   = array( 'contact-form-7', 'gravityform', 'wpforms', 'mailerlite_form', 'lwptoc' );
	const DEFAULT_BLOCK_NAMES  = array( 'gravityforms/form', 'contact-form-7/contact-form-selector', 'wpforms/form-selector', 'mailerlite/form', 'luckywp/toc' );
	const DEFAULT_CSS_CLASSES  = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/** @var string Hook della pagina settings (per caricare gli asset solo lì). */
	private $hook = '';

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Invalida la cache Markdown quando un'opzione del plugin cambia.
		add_action( 'added_option', array( $this, 'maybe_bump_cache_salt' ) );
		add_action( 'updated_option', array( $this, 'maybe_bump_cache_salt' ) );

		$this->hook_filters();
	}

	/**
	 * Bumpa il salt di cache quando viene salvata un'opzione del plugin, così
	 * tutto il Markdown in cache viene rigenerato al prossimo accesso.
	 *
	 * @param string $option Nome dell'opzione appena salvata.
	 */
	public function maybe_bump_cache_salt( $option ): void {
		if ( ! is_string( $option ) || 0 !== strpos( $option, 'sma_' ) || 'sma_cache_salt' === $option ) {
			return;
		}

		static $bumped = false;
		if ( $bumped ) {
			return; // Un solo bump per richiesta, anche se cambiano più opzioni.
		}
		$bumped = true;

		update_option( 'sma_cache_salt', (string) time() );
	}

	public function add_menu(): void {
		$this->hook = (string) add_options_page(
			__( 'Markdown Alternate', 'system-markdown-alternate' ),
			__( 'Markdown Alternate', 'system-markdown-alternate' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Carica il CSS del pannello solo nella nostra pagina settings.
	 *
	 * @param string $hook Hook suffix della pagina admin corrente.
	 */
	public function enqueue_assets( $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'sma-admin-settings',
			SMA_PLUGIN_URL . 'assets/admin-settings.css',
			array(),
			SMA_VERSION
		);

		// Tab client-side (progressive enhancement): vanilla JS, nessuna dipendenza.
		// Senza JS tutti i pannelli restano visibili e i campi restano nel form.
		wp_enqueue_script(
			'sma-admin-settings',
			SMA_PLUGIN_URL . 'assets/admin-settings.js',
			array(),
			SMA_VERSION,
			true
		);
	}

	public function register_settings(): void {
		// ── Opzioni sempre registrate ──────────────────────────────────────────
		register_setting( self::OPTION_GROUP, 'sma_cache_ttl', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::OPTION_GROUP, 'sma_excluded_shortcodes', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_lines' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_excluded_block_names', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_lines' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_excluded_classes', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_lines' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_supported_post_types', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_post_types' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_robots_header', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'sma_llms_txt_enabled', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_llms_txt_enriched', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, 'sma_llms_txt_summary', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( self::OPTION_GROUP, 'sma_llms_txt_key_content', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_lines' ) ) );

		// Opzioni ACF: registrate SOLO se ACF è attivo. Così, quando ACF è spento e
		// i suoi campi non sono nel form, il salvataggio NON le azzera (options.php
		// scrive solo le opzioni registrate nel gruppo).
		if ( $this->acf_active() ) {
			register_setting( self::OPTION_GROUP, 'sma_acf_subtitle_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
			register_setting( self::OPTION_GROUP, 'sma_acf_tldr_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		}

		// ── Generale ───────────────────────────────────────────────────────────
		add_settings_section( 'sma_general', __( 'General', 'system-markdown-alternate' ), array( $this, 'render_general_intro' ), self::PAGE );
		add_settings_field( 'sma_supported_post_types', __( 'Enabled content types', 'system-markdown-alternate' ), array( $this, 'field_post_types' ), self::PAGE, 'sma_general' );
		add_settings_field( 'sma_cache_ttl', __( 'Cache TTL (seconds)', 'system-markdown-alternate' ), array( $this, 'field_cache_ttl' ), self::PAGE, 'sma_general' );

		// ── Output Markdown ──────────────────────────────────────────────────────
		add_settings_section( 'sma_markdown', __( 'Markdown output', 'system-markdown-alternate' ), array( $this, 'render_markdown_intro' ), self::PAGE );
		add_settings_field( 'sma_excluded_shortcodes', __( 'Excluded shortcodes', 'system-markdown-alternate' ), array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sma_markdown' );
		add_settings_field( 'sma_excluded_block_names', __( 'Excluded blocks', 'system-markdown-alternate' ), array( $this, 'field_excluded_block_names' ), self::PAGE, 'sma_markdown' );
		add_settings_field( 'sma_excluded_classes', __( 'Excluded CSS classes', 'system-markdown-alternate' ), array( $this, 'field_excluded_classes' ), self::PAGE, 'sma_markdown' );

		if ( $this->acf_active() ) {
			add_settings_field( 'sma_acf_subtitle_key', __( 'ACF subtitle field', 'system-markdown-alternate' ), array( $this, 'field_acf_subtitle_key' ), self::PAGE, 'sma_markdown' );
			add_settings_field( 'sma_acf_tldr_key', __( 'ACF TL;DR field', 'system-markdown-alternate' ), array( $this, 'field_acf_tldr_key' ), self::PAGE, 'sma_markdown' );
		} else {
			add_settings_field( 'sma_acf_notice', __( 'ACF fields', 'system-markdown-alternate' ), array( $this, 'field_acf_notice' ), self::PAGE, 'sma_markdown' );
		}

		// ── llms.txt ─────────────────────────────────────────────────────────────
		add_settings_section( 'sma_llmstxt', 'llms.txt', array( $this, 'render_llmstxt_intro' ), self::PAGE );
		add_settings_field( 'sma_llms_txt_enabled', __( 'Enable /llms.txt', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_enabled' ), self::PAGE, 'sma_llmstxt' );
		add_settings_field( 'sma_llms_txt_enriched', __( 'Enriched output', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_enriched' ), self::PAGE, 'sma_llmstxt' );
		add_settings_field( 'sma_llms_txt_summary', __( 'Site summary', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_summary' ), self::PAGE, 'sma_llmstxt' );
		add_settings_field( 'sma_llms_txt_key_content', __( 'Key content', 'system-markdown-alternate' ), array( $this, 'field_llms_txt_key_content' ), self::PAGE, 'sma_llmstxt' );

		// ── Integrazioni (solo informativa) ──────────────────────────────────────
		add_settings_section( 'sma_integrations', __( 'Integrations', 'system-markdown-alternate' ), array( $this, 'render_integrations_intro' ), self::PAGE );

		// ── Avanzate ─────────────────────────────────────────────────────────────
		add_settings_section( 'sma_advanced', __( 'Advanced', 'system-markdown-alternate' ), array( $this, 'render_advanced_intro' ), self::PAGE );
		add_settings_field( 'sma_robots_header', 'X-Robots-Tag', array( $this, 'field_robots_header' ), self::PAGE, 'sma_advanced' );
	}

	/**
	 * ACF è attivo? (definisce la funzione get_field()).
	 */
	private function acf_active(): bool {
		return function_exists( 'get_field' );
	}

	/**
	 * GenerateBlocks 2.x (con Dynamic Tags) è attivo?
	 */
	private function generateblocks_active(): bool {
		return class_exists( 'GenerateBlocks_Register_Dynamic_Tag' );
	}

	// ─── Sanitizzazione ─────────────────────────────────────────────────────────

	/**
	 * Whitelist dei post type: tiene solo i tipi pubblici registrati (Media escluso).
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
	 * Normalizza una textarea "una voce per riga": trim, niente righe vuote,
	 * sanitize_text_field, niente duplicati. Mantiene il formato stringa multilinea.
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
	 * @param mixed $value
	 */
	public function sanitize_checkbox( $value ): string {
		return '1' === (string) $value ? '1' : '0';
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
			'sma_llms_txt_cache_ttl',
			function ( $default ) {
				$v = get_option( 'sma_cache_ttl' );
				return false !== $v ? (int) $v : $default;
			},
			20
		);

		add_filter(
			'sma_llms_txt_enriched',
			function ( $default ) {
				$v = get_option( 'sma_llms_txt_enriched' );
				return false !== $v ? '1' === $v : $default;
			},
			20
		);

		add_filter(
			'sma_llms_txt_summary',
			function ( $default ) {
				$v = get_option( 'sma_llms_txt_summary' );
				return ( false !== $v && '' !== trim( (string) $v ) ) ? (string) $v : $default;
			},
			20
		);

		add_filter(
			'sma_llms_txt_key_content',
			function ( $defaults ) {
				return $this->option_to_list( 'sma_llms_txt_key_content', (array) $defaults );
			},
			20
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

		add_filter(
			'sma_acf_subtitle_key',
			function ( $default, $post ) {
				$v = get_option( 'sma_acf_subtitle_key' );
				return ( false !== $v && '' !== $v ) ? $v : $default;
			},
			20,
			2
		);

		add_filter(
			'sma_acf_tldr_key',
			function ( $default, $post ) {
				$v = get_option( 'sma_acf_tldr_key' );
				return ( false !== $v && '' !== $v ) ? $v : $default;
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

	// ─── Intro sezioni ──────────────────────────────────────────────────────────

	public function render_general_intro(): void {
		echo '<p class="sma-help">' . esc_html__( 'Main settings. Without at least one selected content type, the plugin stays inactive.', 'system-markdown-alternate' ) . '</p>';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="sma-status">';
			echo wp_kses_post( __( 'Your site uses <strong>plain permalinks</strong>: the <code>.md</code> suffix is not available, so Markdown URLs fall back to <code>?format=markdown</code>. For clean <code>.md</code> URLs, choose a pretty permalink structure in Settings → Permalinks.', 'system-markdown-alternate' ) );
			echo '</div>';
		}
	}

	public function render_markdown_intro(): void {
		echo '<p class="sma-help">' . wp_kses_post( __( 'Controls what goes into or stays out of the <code>.md</code> file. For exclusions: one entry per line, leave empty to use the built-in defaults.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function render_advanced_intro(): void {
		echo '<p class="sma-help">' . esc_html__( 'Settings for advanced users.', 'system-markdown-alternate' ) . '</p>';
	}

	public function render_llmstxt_intro(): void {
		echo '<p class="sma-help">' . wp_kses_post( __( 'The <code>/llms.txt</code> file exposes selected site resources in a format readable by LLMs and AI agents. It currently lists the enabled Markdown content.', 'system-markdown-alternate' ) ) . '</p>';
	}

	/**
	 * Quick info nell'aside: stato dell'endpoint /llms.txt, URL e conflitti.
	 * Solo presentazione: usa gli stessi dati già calcolati dal plugin.
	 */
	public function render_llmstxt_aside(): void {
		$enabled = '1' === get_option( 'sma_llms_txt_enabled', '1' );
		$url     = home_url( '/llms.txt' );

		echo '<section class="sma-card sma-aside-card">';
		echo '<header class="sma-card__header"><h2>' . esc_html__( 'llms.txt status', 'system-markdown-alternate' ) . '</h2></header>';
		echo '<div class="sma-card__body">';

		echo '<p class="sma-endpoint-state ' . ( $enabled ? 'is-on' : 'is-off' ) . '">';
		echo '<span class="sma-dot" aria-hidden="true"></span>';
		echo esc_html( $enabled ? __( 'Enabled', 'system-markdown-alternate' ) : __( 'Disabled', 'system-markdown-alternate' ) );
		echo '</p>';

		echo '<p class="sma-endpoint-url"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $url ) . '</code></a></p>';

		$this->render_conflict_warning();

		echo '</div></section>';
	}

	public function render_integrations_intro(): void {
		echo '<p class="sma-help">' . wp_kses_post( __( 'Informational section: how to use the <code>.md</code> URL in content and templates.', 'system-markdown-alternate' ) ) . '</p>';

		echo '<div class="sma-integration-card">';
		echo '<h3>' . esc_html__( 'Shortcode', 'system-markdown-alternate' ) . '</h3>';
		echo '<p>' . wp_kses_post( __( '<code>[sma_md_url]</code> — <code>.md</code> URL of the current post.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sma_md_url id="123"]</code> — <code>.md</code> URL of a specific post.', 'system-markdown-alternate' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Returns empty if the post does not expose a .md (type not enabled, draft, or password-protected).', 'system-markdown-alternate' ) . '</p>';
		echo '</div>';

		echo '<div class="sma-integration-card">';
		echo '<h3>GenerateBlocks</h3>';
		if ( $this->generateblocks_active() ) {
			echo '<p>' . esc_html__( 'GenerateBlocks detected. The dynamic tag is available automatically.', 'system-markdown-alternate' ) . '</p>';
			echo '<p><code>{{sma_md_url}}</code></p>';
			echo '<p class="description">' . wp_kses_post( __( 'Insert <code>{{sma_md_url}}</code> in GenerateBlocks/GeneratePress fields that accept a dynamic tag, e.g. a button URL. If the post has no <code>.md</code>, the tag resolves to empty and the element is hidden (required to render).', 'system-markdown-alternate' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'GenerateBlocks not detected. The dynamic tag is not available.', 'system-markdown-alternate' ) . '</p>';
		}
		echo '</div>';

		echo '<div class="sma-integration-card">';
		echo '<h3>ACF</h3>';
		echo $this->acf_active()
			? '<p>' . wp_kses_post( __( 'ACF detected. The Subtitle and TL;DR fields are configured in the <strong>Markdown output</strong> section.', 'system-markdown-alternate' ) ) . '</p>'
			: '<p>' . esc_html__( 'ACF not detected. The Subtitle and TL;DR fields are not available.', 'system-markdown-alternate' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Avviso se un altro gestore di /llms.txt è attivo (plugin SEO, file fisico)
	 * o se l'endpoint risponde quando non dovrebbe.
	 */
	private function render_conflict_warning(): void {
		$detector = new ConflictDetector();

		$alerts = array(); // Conflitti probabili (rosso).
		$notes  = array(); // Note informative (descrizione).

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
		$raw   = get_option( 'sma_supported_post_types' ); // false = mai salvato
		$saved = false !== $raw ? (array) $raw : array();

		$all_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $all_types['attachment'] ); // Media: sempre escluso.

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
		echo '<p class="description">' . wp_kses_post( __( 'Content types exposed as <code>.md</code> and in <code>/llms.txt</code>. No selection = plugin inactive.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_cache_ttl(): void {
		$v = get_option( 'sma_cache_ttl' );
		$v = false !== $v ? (int) $v : DAY_IN_SECONDS;
		echo '<input type="number" min="0" step="1" name="sma_cache_ttl" value="' . esc_attr( $v ) . '" class="small-text" /> ' . esc_html__( 'seconds', 'system-markdown-alternate' );
		echo '<p class="description">' . esc_html__( '0 = cache disabled. Default: 86400 (24 hours).', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_excluded_shortcodes(): void {
		$this->render_exclusion_field( 'sma_excluded_shortcodes', self::DEFAULT_SHORTCODES );
	}

	public function field_excluded_block_names(): void {
		$this->render_exclusion_field( 'sma_excluded_block_names', self::DEFAULT_BLOCK_NAMES );
	}

	public function field_excluded_classes(): void {
		$this->render_exclusion_field( 'sma_excluded_classes', self::DEFAULT_CSS_CLASSES );
	}

	/**
	 * Textarea compatta "una per riga" + lista dei default.
	 *
	 * @param string[] $defaults
	 */
	private function render_exclusion_field( string $option, array $defaults ): void {
		$v = (string) get_option( $option, '' );
		echo '<textarea name="' . esc_attr( $option ) . '" rows="4" class="code sma-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sma-help">' . esc_html__( 'One per line. Leave empty to use the built-in defaults.', 'system-markdown-alternate' ) . '</p>';
		echo '<details class="sma-defaults-toggle"><summary>' . esc_html__( 'View built-in defaults', 'system-markdown-alternate' ) . '</summary>';
		echo '<pre class="sma-defaults">' . esc_html( implode( "\n", $defaults ) ) . '</pre>';
		echo '</details>';
	}

	public function field_acf_subtitle_key(): void {
		$v = (string) get_option( 'sma_acf_subtitle_key', '' );
		echo '<input type="text" name="sma_acf_subtitle_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'ACF field name for the subtitle (type: text). Inserted in italics right after the H1 title.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_acf_tldr_key(): void {
		$v = (string) get_option( 'sma_acf_tldr_key', '' );
		echo '<input type="text" name="sma_acf_tldr_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . wp_kses_post( __( 'ACF field name for the TL;DR (type: WYSIWYG editor). Inserted as a <code>**TL;DR**</code> section with <code>---</code> separators.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_acf_notice(): void {
		echo '<p class="description">' . esc_html__( 'ACF not detected: the Subtitle and TL;DR fields will appear here when ACF is active. Any previously saved settings are preserved.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_llms_txt_enabled(): void {
		$v = get_option( 'sma_llms_txt_enabled', '1' ); // abilitato per default
		echo '<label><input type="checkbox" name="sma_llms_txt_enabled" value="1"' . checked( '1', $v, false ) . ' /> ' . wp_kses_post( __( 'Enable the <code>/llms.txt</code> endpoint', 'system-markdown-alternate' ) ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Disable if another plugin already handles <code>/llms.txt</code>.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_llms_txt_enriched(): void {
		$v = get_option( 'sma_llms_txt_enriched', '0' ); // disattivato per default
		echo '<label><input type="checkbox" name="sma_llms_txt_enriched" value="1"' . checked( '1', $v, false ) . ' /> ' . esc_html__( 'Enable the enriched output', 'system-markdown-alternate' ) . '</label>';
		echo '<p class="description">' . wp_kses_post( __( 'Adds the site summary, the key content section, a description for each entry (Rank Math meta → excerpt → trimmed text) and moves the overflow beyond the most recent posts into an <code>Optional</code> section. Off = the basic index only.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function field_llms_txt_summary(): void {
		$v = (string) get_option( 'sma_llms_txt_summary', '' );
		echo '<textarea name="sma_llms_txt_summary" rows="3" class="large-text sma-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sma-help">' . esc_html__( 'One short paragraph describing the site, shown after the tagline. Used only when the enriched output is enabled.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_llms_txt_key_content(): void {
		$v = (string) get_option( 'sma_llms_txt_key_content', '' );
		echo '<textarea name="sma_llms_txt_key_content" rows="4" class="code sma-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sma-help">' . esc_html__( 'Featured content: one post ID or URL per line. Listed first, before the automatic sections. Used only when the enriched output is enabled.', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_robots_header(): void {
		$v = get_option( 'sma_robots_header' );
		$v = false !== $v ? (string) $v : 'noindex, follow';
		echo '<input type="text" name="sma_robots_header" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">' . wp_kses_post( __( 'Default: <code>noindex, follow</code>. Leave empty to not send the header.', 'system-markdown-alternate' ) ) . '</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp_settings_sections, $wp_settings_fields;
		$sections = isset( $wp_settings_sections[ self::PAGE ] ) ? (array) $wp_settings_sections[ self::PAGE ] : array();
		?>
		<div class="wrap sma-settings-page">
			<form method="post" action="options.php" class="sma-settings-page__form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<header class="sma-settings-page__header">
					<div class="sma-settings-page__titles">
						<h1>
							<?php echo esc_html( get_admin_page_title() ); ?>
							<span class="sma-version">v<?php echo esc_html( SMA_VERSION ); ?></span>
						</h1>
						<p class="sma-settings-page__desc"><?php esc_html_e( 'Serve a clean Markdown version of your content at the .md URL, for LLMs and AI agents.', 'system-markdown-alternate' ); ?></p>
					</div>
					<div class="sma-settings-page__actions">
						<?php submit_button( '', 'primary', 'submit', false ); ?>
					</div>
				</header>
				<hr class="wp-header-end">

				<?php settings_errors(); ?>

				<?php if ( count( $sections ) > 1 ) : ?>
					<nav class="nav-tab-wrapper sma-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'system-markdown-alternate' ); ?>">
						<?php
						$i = 0;
						foreach ( $sections as $sid => $section ) {
							printf(
								'<a href="#sma-panel-%1$s" class="nav-tab%2$s" data-tab="%1$s">%3$s</a>',
								esc_attr( (string) $sid ),
								0 === $i ? ' nav-tab-active' : '',
								esc_html( (string) $section['title'] )
							);
							++$i;
						}
						?>
					</nav>
				<?php endif; ?>

				<div class="sma-settings-page__layout">
					<main class="sma-settings-page__main">
						<?php
						$i = 0;
						foreach ( $sections as $sid => $section ) {
							$sid = (string) $sid;
							printf(
								'<div class="sma-tab-panel%1$s" id="sma-panel-%2$s" data-tab="%2$s" role="tabpanel">',
								0 === $i ? ' is-active' : '',
								esc_attr( $sid )
							);
							echo '<section class="sma-card">';
							if ( ! empty( $section['title'] ) ) {
								echo '<header class="sma-card__header"><h2>' . esc_html( (string) $section['title'] ) . '</h2></header>';
							}
							echo '<div class="sma-card__body">';
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
					<aside class="sma-settings-page__aside">
						<?php $this->render_llmstxt_aside(); ?>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}
}

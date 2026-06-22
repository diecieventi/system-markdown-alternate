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
			'Markdown Alternate',
			'Markdown Alternate',
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

		// Opzioni ACF: registrate SOLO se ACF è attivo. Così, quando ACF è spento e
		// i suoi campi non sono nel form, il salvataggio NON le azzera (options.php
		// scrive solo le opzioni registrate nel gruppo).
		if ( $this->acf_active() ) {
			register_setting( self::OPTION_GROUP, 'sma_acf_subtitle_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
			register_setting( self::OPTION_GROUP, 'sma_acf_tldr_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		}

		// ── Generale ───────────────────────────────────────────────────────────
		add_settings_section( 'sma_general', 'Generale', array( $this, 'render_general_intro' ), self::PAGE );
		add_settings_field( 'sma_supported_post_types', 'Tipi di contenuto abilitati', array( $this, 'field_post_types' ), self::PAGE, 'sma_general' );
		add_settings_field( 'sma_cache_ttl', 'Cache TTL (secondi)', array( $this, 'field_cache_ttl' ), self::PAGE, 'sma_general' );

		// ── Output Markdown ──────────────────────────────────────────────────────
		add_settings_section( 'sma_markdown', 'Output Markdown', array( $this, 'render_markdown_intro' ), self::PAGE );
		add_settings_field( 'sma_excluded_shortcodes', 'Shortcode esclusi', array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sma_markdown' );
		add_settings_field( 'sma_excluded_block_names', 'Blocchi esclusi', array( $this, 'field_excluded_block_names' ), self::PAGE, 'sma_markdown' );
		add_settings_field( 'sma_excluded_classes', 'Classi CSS escluse', array( $this, 'field_excluded_classes' ), self::PAGE, 'sma_markdown' );

		if ( $this->acf_active() ) {
			add_settings_field( 'sma_acf_subtitle_key', 'Campo ACF sottotitolo', array( $this, 'field_acf_subtitle_key' ), self::PAGE, 'sma_markdown' );
			add_settings_field( 'sma_acf_tldr_key', 'Campo ACF TL;DR', array( $this, 'field_acf_tldr_key' ), self::PAGE, 'sma_markdown' );
		} else {
			add_settings_field( 'sma_acf_notice', 'Campi ACF', array( $this, 'field_acf_notice' ), self::PAGE, 'sma_markdown' );
		}

		// ── llms.txt ─────────────────────────────────────────────────────────────
		add_settings_section( 'sma_llmstxt', 'llms.txt', array( $this, 'render_llmstxt_intro' ), self::PAGE );
		add_settings_field( 'sma_llms_txt_enabled', 'Abilita /llms.txt', array( $this, 'field_llms_txt_enabled' ), self::PAGE, 'sma_llmstxt' );

		// ── Integrazioni (solo informativa) ──────────────────────────────────────
		add_settings_section( 'sma_integrations', 'Integrazioni', array( $this, 'render_integrations_intro' ), self::PAGE );

		// ── Avanzate ─────────────────────────────────────────────────────────────
		add_settings_section( 'sma_advanced', 'Avanzate', array( $this, 'render_advanced_intro' ), self::PAGE );
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
		echo '<p class="sma-help">Impostazioni principali. Senza almeno un tipo di contenuto selezionato, il plugin resta inattivo.</p>';
	}

	public function render_markdown_intro(): void {
		echo '<p class="sma-help">Decide cosa entra o resta fuori dal file <code>.md</code>. Per le esclusioni: una voce per riga, lascia vuoto per usare i default interni.</p>';
	}

	public function render_advanced_intro(): void {
		echo '<p class="sma-help">Impostazioni per utenti esperti.</p>';
	}

	public function render_llmstxt_intro(): void {
		echo '<p class="sma-help">Il file <code>/llms.txt</code> espone risorse selezionate del sito in un formato leggibile da LLM e agenti AI. Attualmente elenca i contenuti Markdown abilitati.</p>';

		$enabled = '1' === get_option( 'sma_llms_txt_enabled', '1' );
		$url     = home_url( '/llms.txt' );
		echo '<div class="sma-status">';
		echo 'Abilitato nelle impostazioni: <strong>' . ( $enabled ? 'sì' : 'no' ) . '</strong><br>';
		echo 'URL: <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $url ) . '</code></a>';
		echo '</div>';

		$this->render_conflict_warning();
	}

	public function render_integrations_intro(): void {
		echo '<p class="sma-help">Sezione informativa: come usare l\'URL del <code>.md</code> nei contenuti e nei template.</p>';

		echo '<div class="sma-integration-card">';
		echo '<h3>Shortcode</h3>';
		echo '<p><code>[sma_md_url]</code> — URL del <code>.md</code> del post corrente.<br>';
		echo '<code>[sma_md_url id="123"]</code> — URL del <code>.md</code> di un post specifico.</p>';
		echo '<p class="description">Restituisce vuoto se il post non espone un .md (tipo non abilitato, bozza o protetto da password).</p>';
		echo '</div>';

		echo '<div class="sma-integration-card">';
		echo '<h3>GenerateBlocks</h3>';
		if ( $this->generateblocks_active() ) {
			echo '<p>GenerateBlocks rilevato. Il dynamic tag è disponibile automaticamente.</p>';
			echo '<p><code>{{sma_md_url}}</code></p>';
			echo '<p class="description">Inserisci <code>{{sma_md_url}}</code> nei campi GenerateBlocks/GeneratePress che accettano un dynamic tag, ad esempio l\'URL di un bottone. Se il post non ha un <code>.md</code>, il tag si risolve a vuoto e l\'elemento viene nascosto (required to render).</p>';
		} else {
			echo '<p>GenerateBlocks non rilevato. Il dynamic tag non è disponibile.</p>';
		}
		echo '</div>';

		echo '<div class="sma-integration-card">';
		echo '<h3>ACF</h3>';
		echo $this->acf_active()
			? '<p>ACF rilevato. I campi Sottotitolo e TL;DR si configurano nella sezione <strong>Output Markdown</strong>.</p>'
			: '<p>ACF non rilevato. I campi Sottotitolo e TL;DR non sono disponibili.</p>';
		echo '</div>';
	}

	/**
	 * Avviso se un altro gestore di /llms.txt è attivo (plugin SEO, file fisico)
	 * o se l'endpoint risponde quando non dovrebbe.
	 */
	private function render_conflict_warning(): void {
		$detector     = new ConflictDetector();
		$ours_enabled = '1' === get_option( 'sma_llms_txt_enabled', '1' );
		$force        = isset( $_GET['sma_recheck'] ); // phpcs:ignore WordPress.Security.NonceVerification

		$alerts = array(); // Conflitti probabili (rosso).
		$notes  = array(); // Note informative (descrizione).

		if ( $detector->physical_file_exists() ) {
			$alerts[] = 'Esiste un file fisico <code>llms.txt</code> nella root del sito: il web server lo serve <strong>prima</strong> di WordPress, quindi questo endpoint (e quello di altri plugin) viene ignorato.';
		}

		$providers = $detector->detected_providers();
		if ( $providers ) {
			$notes[] = sprintf(
				'Plugin SEO attivi che <em>potrebbero</em> gestire <code>/llms.txt</code>: <strong>%s</strong>. Se uno di loro lo genera già, tieni attivo un solo gestore (disattiva questo qui sotto, oppure la funzione llms.txt nell\'altro plugin).',
				esc_html( implode( ', ', $providers ) )
			);
		}

		$endpoint = $detector->endpoint_status( $force );
		if ( null === $endpoint ) {
			$notes[] = 'Controllo HTTP di <code>/llms.txt</code> non ancora eseguito.';
		} elseif ( ! empty( $endpoint['reachable'] ) ) {
			$ct         = (string) ( $endpoint['content_type'] ?? '' );
			$is_textual = ( false !== stripos( $ct, 'text/plain' ) || false !== stripos( $ct, 'markdown' ) );
			$ct_txt     = '' !== $ct ? ', content-type ' . esc_html( $ct ) : '';

			if ( $ours_enabled ) {
				$notes[] = sprintf( '<code>/llms.txt</code> risponde HTTP %d%s (verosimilmente servito da questo plugin).', (int) $endpoint['status'], $ct_txt );
			} elseif ( $is_textual ) {
				$alerts[] = sprintf( 'Questo endpoint è <strong>disattivato</strong> ma <code>/llms.txt</code> risponde con un file di testo (HTTP %d%s): qualcos\'altro lo sta servendo.', (int) $endpoint['status'], $ct_txt );
			} else {
				$notes[] = sprintf( 'Questo endpoint è disattivato e <code>/llms.txt</code> risponde HTTP %d%s: sembra HTML, forse una pagina di blocco/soft-404 più che un vero llms.txt. Verifica manualmente.', (int) $endpoint['status'], $ct_txt );
			}
		} else {
			$status_txt = ! empty( $endpoint['status'] ) ? ' (HTTP ' . (int) $endpoint['status'] . ')' : '';
			$notes[]    = 'Il controllo di <code>/llms.txt</code> non ha ricevuto una risposta valida' . $status_txt . '. Può essere un blocco del WAF sul controllo automatico (le richieste reali dei browser potrebbero comunque funzionare).';
		}

		if ( $alerts ) {
			echo '<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px"><p style="margin-top:0"><strong>Possibile conflitto su /llms.txt:</strong></p><ul style="list-style:disc;margin:0 0 0 20px">';
			foreach ( $alerts as $a ) {
				echo '<li>' . $a . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></div>';
		}

		if ( $notes ) {
			echo '<p class="description">' . implode( '<br>', $notes ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$recheck = esc_url( add_query_arg( 'sma_recheck', time() ) );
		echo '<p><a href="' . $recheck . '" class="button button-secondary">Controlla /llms.txt ora</a></p>';
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
		echo '<p class="description">Tipi di contenuto esposti come <code>.md</code> e in <code>/llms.txt</code>. Nessuna selezione = plugin inattivo.</p>';
	}

	public function field_cache_ttl(): void {
		$v = get_option( 'sma_cache_ttl' );
		$v = false !== $v ? (int) $v : DAY_IN_SECONDS;
		echo '<input type="number" min="0" step="1" name="sma_cache_ttl" value="' . esc_attr( $v ) . '" class="small-text" /> secondi';
		echo '<p class="description">0 = cache disabilitata. Default: 86400 (24 ore).</p>';
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
		echo '<p class="description sma-help">Uno per riga. Lascia vuoto per usare i default interni.</p>';
		echo '<pre class="sma-defaults">' . esc_html( "Default:\n" . implode( "\n", $defaults ) ) . '</pre>';
	}

	public function field_acf_subtitle_key(): void {
		$v = (string) get_option( 'sma_acf_subtitle_key', '' );
		echo '<input type="text" name="sma_acf_subtitle_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Nome del campo ACF per il sottotitolo (tipo: testo). Inserito in corsivo subito dopo il titolo H1.</p>';
	}

	public function field_acf_tldr_key(): void {
		$v = (string) get_option( 'sma_acf_tldr_key', '' );
		echo '<input type="text" name="sma_acf_tldr_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Nome del campo ACF per il TL;DR (tipo: editor WYSIWYG). Inserito come sezione <code>**TL;DR**</code> con separatori <code>---</code>.</p>';
	}

	public function field_acf_notice(): void {
		echo '<p class="description">ACF non rilevato: i campi Sottotitolo e TL;DR appariranno qui quando ACF sarà attivo. Le eventuali impostazioni già salvate restano conservate.</p>';
	}

	public function field_llms_txt_enabled(): void {
		$v = get_option( 'sma_llms_txt_enabled', '1' ); // abilitato per default
		echo '<label><input type="checkbox" name="sma_llms_txt_enabled" value="1"' . checked( '1', $v, false ) . ' /> Abilita l\'endpoint <code>/llms.txt</code></label>';
		echo '<p class="description">Disattiva se un altro plugin gestisce già <code>/llms.txt</code>.</p>';
	}

	public function field_robots_header(): void {
		$v = get_option( 'sma_robots_header' );
		$v = false !== $v ? (string) $v : 'noindex, follow';
		echo '<input type="text" name="sma_robots_header" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Default: <code>noindex, follow</code>. Lascia vuoto per non inviare l\'header.</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap sma-settings-page">
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

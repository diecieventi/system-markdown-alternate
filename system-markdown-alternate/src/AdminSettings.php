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
		register_setting(
			self::OPTION_GROUP,
			'sma_acf_subtitle_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_acf_tldr_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sma_llms_txt_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);

		// ── Sezioni sempre presenti ───────────────────────────────────────────
		add_settings_section( 'sma_cache', 'Cache', '__return_false', self::PAGE );
		add_settings_field( 'sma_cache_ttl', 'Cache TTL (seconds)', array( $this, 'field_cache_ttl' ), self::PAGE, 'sma_cache' );

		add_settings_section( 'sma_exclusions', 'Exclusions', array( $this, 'render_exclusions_intro' ), self::PAGE );
		add_settings_field( 'sma_excluded_shortcodes', 'Excluded shortcodes', array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sma_exclusions' );
		add_settings_field( 'sma_excluded_block_names', 'Excluded block names', array( $this, 'field_excluded_block_names' ), self::PAGE, 'sma_exclusions' );
		add_settings_field( 'sma_excluded_classes', 'Excluded CSS classes', array( $this, 'field_excluded_classes' ), self::PAGE, 'sma_exclusions' );

		add_settings_section( 'sma_shortcode', 'Shortcode', array( $this, 'render_shortcode_intro' ), self::PAGE );

		// ── Integrazione GenerateBlocks: solo se il plugin è attivo ────────────
		// Nessun toggle: il Dynamic Tag si auto-registra quando GB è attivo.
		// La sezione fa solo da riepilogo d'uso.
		if ( $this->generateblocks_active() ) {
			add_settings_section( 'sma_generateblocks', 'GenerateBlocks Integration', array( $this, 'render_generateblocks_intro' ), self::PAGE );
		}

		// ── Integrazione ACF: solo se il plugin è attivo ───────────────────────
		if ( $this->acf_active() ) {
			add_settings_section( 'sma_acf', 'ACF Integration', array( $this, 'render_acf_intro' ), self::PAGE );
			add_settings_field( 'sma_acf_subtitle_key', 'Subtitle field', array( $this, 'field_acf_subtitle_key' ), self::PAGE, 'sma_acf' );
			add_settings_field( 'sma_acf_tldr_key', 'TL;DR field', array( $this, 'field_acf_tldr_key' ), self::PAGE, 'sma_acf' );
		}

		add_settings_section( 'sma_llmstxt', 'llms.txt', array( $this, 'render_llmstxt_intro' ), self::PAGE );
		add_settings_field( 'sma_llms_txt_enabled', 'Attiva /llms.txt', array( $this, 'field_llms_txt_enabled' ), self::PAGE, 'sma_llmstxt' );

		add_settings_section( 'sma_advanced', 'Advanced', '__return_false', self::PAGE );
		add_settings_field( 'sma_supported_post_types', 'Supported post types', array( $this, 'field_post_types' ), self::PAGE, 'sma_advanced' );
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

	// ─── Rendering ────────────────────────────────────────────────────────────

	public function render_shortcode_intro(): void {
		echo '<p>Shortcode per inserire dati del Markdown nei contenuti, bottoni e template. Sempre disponibili.</p>';
		echo '<table class="widefat striped" style="max-width:780px"><thead><tr><th>Shortcode</th><th>Descrizione</th></tr></thead><tbody>';
		echo '<tr><td><code>[sma_md_url]</code></td><td>URL del <code>.md</code> del post corrente. Per un post specifico: <code>[sma_md_url id="123"]</code>. Restituisce vuoto se il post non espone un .md (tipo non abilitato, bozza o protetto da password).</td></tr>';
		echo '</tbody></table>';
	}

	public function render_generateblocks_intro(): void {
		echo '<p>Rilevato GenerateBlocks: il Dynamic Tag <code>{{sma_md_url}}</code> è <strong>attivo automaticamente</strong>, nessuna configurazione necessaria.</p>';
		echo '<p><strong>Uso:</strong> inserisci <code>{{sma_md_url}}</code> nei campi degli elementi GenerateBlocks/GeneratePress che accettano un Dynamic Tag (es. il campo URL di un Button). Restituisce l\'URL del <code>.md</code> del post corrente.</p>';
		echo '<p class="description">Se il post non espone un <code>.md</code> (tipo non abilitato, bozza o protetto), il tag si risolve a vuoto e l\'opzione "required to render" di GenerateBlocks nasconde l\'elemento — così non resta mai un link rotto. <em>Nota:</em> il tag viene risolto da GenerateBlocks: se disattivi GenerateBlocks o questo plugin, eventuali <code>{{sma_md_url}}</code> già inseriti restano come testo (vale per qualsiasi dynamic tag).</p>';
	}

	public function render_acf_intro(): void {
		echo '<p>Rilevato ACF. Campi inclusi nel Markdown come preambolo (tra titolo H1 e corpo). Lascia vuoto per disabilitare.</p>';
	}

	public function render_exclusions_intro(): void {
		echo '<p>One per line. Leave empty to use the built-in defaults.</p>';
	}

	public function render_llmstxt_intro(): void {
		echo '<p>Il file <code>/llms.txt</code> elenca i contenuti del sito in formato leggibile da LLM e agenti AI.</p>';
		$this->render_conflict_warning();
	}

	/**
	 * Avviso automatico se un altro gestore di /llms.txt è attivo (altri plugin SEO,
	 * file fisico) o se l'endpoint risponde quando non dovrebbe.
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

		foreach ( $detector->active_providers() as $p ) {
			$alerts[] = sprintf(
				'<strong>%1$s</strong> ha la funzione llms.txt <strong>attiva</strong>: gestisce già <code>/llms.txt</code>. Tieni attivo un solo gestore (disattiva questo qui sotto, oppure quello di %1$s).',
				esc_html( $p['name'] )
			);
		}

		foreach ( $detector->unknown_providers() as $p ) {
			$notes[] = sprintf(
				'<strong>%s</strong> è attivo e potrebbe gestire llms.txt: verifica nelle sue impostazioni.',
				esc_html( $p['name'] )
			);
		}

		$endpoint = $detector->endpoint_status( $force );
		if ( null === $endpoint ) {
			$notes[] = 'Controllo HTTP di <code>/llms.txt</code> non ancora eseguito.';
		} elseif ( ! empty( $endpoint['reachable'] ) ) {
			$ct        = (string) ( $endpoint['content_type'] ?? '' );
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

	public function field_acf_subtitle_key(): void {
		$v = (string) get_option( 'sma_acf_subtitle_key', '' );
		echo '<input type="text" name="sma_acf_subtitle_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Nome del campo ACF per il sottotitolo (tipo: testo). Viene inserito in corsivo subito dopo il titolo H1.</p>';
	}

	public function field_acf_tldr_key(): void {
		$v = (string) get_option( 'sma_acf_tldr_key', '' );
		echo '<input type="text" name="sma_acf_tldr_key" value="' . esc_attr( $v ) . '" class="regular-text" />';
		echo '<p class="description">Nome del campo ACF per il TL;DR (tipo: editor WYSIWYG). Viene inserito come sezione <code>**TL;DR**</code> con separatori <code>---</code>.</p>';
	}

	public function field_llms_txt_enabled(): void {
		$v = get_option( 'sma_llms_txt_enabled', '1' ); // abilitato per default
		echo '<label><input type="checkbox" name="sma_llms_txt_enabled" value="1"' . checked( '1', $v, false ) . ' /> Abilita l\'endpoint <code>/llms.txt</code></label>';
		echo '<p class="description">Disattiva se un altro plugin gestisce già <code>/llms.txt</code>.</p>';
		echo '<p class="description" style="margin-top:8px;color:#888"><strong>Sviluppi futuri:</strong> integrazione con Rank Math / Yoast SEO per meta e descrizioni; possibilità di configurare il file con contenuti non limitati ai soli .md (da verificare con la specifica Cloudflare e i LLM Signals).</p>';
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
		echo '<p class="description">Seleziona i tipi da esporre come .md e in llms.txt. Nessuna selezione = plugin inattivo. <em>Nota: in futuro si potrà filtrare i CPT mostrando solo quelli realmente pubblici (esclusi quelli ad uso interno).</em></p>';
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

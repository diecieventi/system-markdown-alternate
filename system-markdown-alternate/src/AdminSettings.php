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

	/**
	 * Taxonomies selected for the front matter (array of slugs, empty = none).
	 *
	 * Named after the filter it feeds (`sysmda_front_matter_taxonomy_slugs`):
	 * the option is the default value of that filter, not a separate concept.
	 */
	const OPTION_TAXONOMIES = 'sysmda_front_matter_taxonomy_slugs';

	/**
	 * The 0.24.x on/off checkbox, replaced by the explicit selection above in
	 * 0.25.0. Read once by the migration, then deleted.
	 */
	const LEGACY_OPTION_TAXONOMIES = 'sysmda_front_matter_taxonomies';

	/** Exclusion defaults (displayed in the panel for reference only). */
	/*
	 * The built-in exclusion lists shown in the panel are read from the classes
	 * that apply them, never copied. They used to be duplicated here, which is a
	 * drift the user sees: a tag added to the cleaner and forgotten here made
	 * the "View built-in defaults" disclosure describe a plugin that no longer
	 * existed.
	 */

	/** @var string Settings page hook (used to load assets only on that page). */
	private $hook = '';

	/** @var bool Whether this request still owes the cache salt a bump. */
	private $salt_bump_pending = false;

	/** @var string[]|null Saved extra meta keys, memoized (null = not read yet). */
	private $extra_meta_keys = null;

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// "Settings" action link on the plugin row in the Plugins list.
		add_filter( 'plugin_action_links_' . plugin_basename( SYSMDA_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );

		// Invalidate the Markdown cache when a plugin option changes.
		add_action( 'added_option', array( $this, 'maybe_bump_cache_salt' ) );
		add_action( 'updated_option', array( $this, 'maybe_bump_cache_salt' ) );

		// …and when a site-wide input of the output changes. These are read by
		// every post's Markdown but belong to no post, so nothing else moves the
		// validator: without them a client holding the old ETag is told `304`
		// for good, which no TTL ever bounds. All four are rare events, so
		// bumping the global salt (and rebuilding everything once) is the cheap
		// trade against reading them on every request — that would both
		// invalidate every post on upgrade and permanently disable the
		// `If-Modified-Since` path, which is switched off for any post with
		// out-of-post dependencies.
		//
		// All four fire AFTER the write, which is the half that matters and is
		// easy to get wrong: `update_option_{$option}` is a post-write hook
		// despite its name (the pre-write one is the suffix-less
		// `update_option`), and `profile_update` / `deleted_user` run once the
		// user rows are saved. Bumping *before* the write would let a concurrent
		// request cache the old output under the new salt, with nothing left to
		// invalidate it afterwards. Do not move these to a pre-write hook.
		add_action( 'update_option_permalink_structure', array( $this, 'bump_cache_salt' ) );
		add_action( 'update_option_home', array( $this, 'bump_cache_salt' ) );
		add_action( 'profile_update', array( $this, 'maybe_bump_for_author' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'bump_cache_salt' ) );

		// The site timezone formats `date_published` and `date_modified`: both
		// are printed with get_post_time()/get_post_modified_time() in LOCAL
		// time, so their ISO offset — and the wall-clock reading itself —
		// changes across the whole site the moment Settings => General is
		// saved, with no post row touched.
		add_action( 'update_option_timezone_string', array( $this, 'bump_cache_salt' ) );
		add_action( 'update_option_gmt_offset', array( $this, 'bump_cache_salt' ) );

		// WooCommerce's cart/checkout/my-account pages are excluded by ID
		// (WooCommerceCompat), and assigning one is set from WooCommerce's own
		// settings screen, never from a post editor save — so, like the three
		// options above, nothing else here moves the validator. Rare enough
		// that a site-wide bump is the cheap trade; the same reasoning that
		// keeps a post's *format* out of this list does not apply, because a
		// format change is saved from the post editor, which already clears
		// the cache through save_post.
		//
		// All three write hooks per key, not just update_option_*: WordPress's
		// update_option() delegates to add_option() when the row does not yet
		// exist — which is exactly the FIRST time WooCommerce assigns one of
		// these pages — and fires add_option_{$option} instead, which
		// update_option_{$option} never sees. The generic added_option handler
		// above does not help either: it only bumps for sysmda_* options.
		// delete_option_{$option} closes the same gap on the removal side.
		// Caught by Codex on PR #122, before it shipped.
		foreach ( WooCommerceCompat::DEFAULT_PAGES as $sysmda_wc_page_key ) {
			$sysmda_wc_option = 'woocommerce_' . $sysmda_wc_page_key . '_page_id';
			add_action( "add_option_{$sysmda_wc_option}", array( $this, 'bump_cache_salt' ) );
			add_action( "update_option_{$sysmda_wc_option}", array( $this, 'bump_cache_salt' ) );
			add_action( "delete_option_{$sysmda_wc_option}", array( $this, 'bump_cache_salt' ) );
		}
		unset( $sysmda_wc_page_key, $sysmda_wc_option );

		// `categories:` and `tags:` are ALWAYS emitted, and unlike the optional
		// custom taxonomies they are excluded from taxonomies_fingerprint() —
		// they have their own front-matter keys. Renaming or deleting a term
		// therefore rewrote the front matter of every post carrying it while
		// cache_version() stayed put, so a client holding the old ETag was told
		// `304` for good. Term edits are rare, so the site-wide salt shape is
		// the right one here: reading the terms of every post on every request
		// would make dependencies_fingerprint() non-empty site-wide, which
		// invalidates everything on upgrade AND permanently disables the
		// `If-Modified-Since` path.
		//
		// Deliberately NOT hooked: `set_object_terms`, which fires on every
		// single post save. Assigning terms from the editor already moves
		// `post_modified_gmt`; the residue is a purely programmatic
		// wp_set_object_terms() that touches no post row — the same bounded
		// residue already accepted for post formats, and not worth a hook on
		// the write path of every save.
		add_action( 'edited_term', array( $this, 'maybe_bump_for_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'maybe_bump_for_term' ), 10, 3 );

		// Removing the last featured-image or Rank Math dependency makes the
		// dependency fingerprint empty. The post row may remain untouched, so
		// preserve the invalidation history in the global salt; otherwise an
		// If-Modified-Since-only client could start receiving stale 304s again.
		add_action( 'deleted_post_meta', array( $this, 'bump_for_deleted_dependency_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'maybe_bump_for_empty_dependency_meta' ), 10, 4 );

		// The write itself happens once, at the very end of the request: see
		// flush_cache_salt().
		add_action( 'shutdown', array( $this, 'flush_cache_salt' ) );

		// After init, so taxonomies registered by themes/plugins are all visible.
		add_action( 'wp_loaded', array( $this, 'maybe_migrate_legacy_taxonomies' ) );

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

		$this->bump_cache_salt();
	}

	/**
	 * Marks the cache salt as needing a bump: every cached Markdown body is
	 * rebuilt on the next request and every `ETag` changes once.
	 *
	 * Hooked directly to the site-wide changes listed in boot(); the option
	 * handler above filters first and then calls this. Recording the intent
	 * rather than writing straight away is what makes a settings save safe —
	 * see flush_cache_salt().
	 */
	public function bump_cache_salt(): void {
		$this->salt_bump_pending = true;
	}

	/**
	 * Hook: shutdown. Performs the pending bump, once, after everything else
	 * this request was going to write.
	 *
	 * Deferring is the point, and it is the same argument that already keeps
	 * the triggers in boot() on post-write hooks, applied one level up. A
	 * Settings API save writes the group's options one at a time, and the first
	 * changed `sysmda_*` option used to bump the salt immediately: a front-end
	 * request landing between that write and the last one would build its
	 * Markdown from a half-old set of settings and cache it under the NEW salt,
	 * where nothing would ever invalidate it again. Bumping at shutdown means
	 * such a request caches under the old salt instead, and the bump that
	 * follows throws it away.
	 *
	 * The value is a timestamp plus random bytes, not a bare `time()`. Two
	 * genuine invalidations inside the same second used to produce the same
	 * string, and `update_option()` short-circuits on an unchanged value: the
	 * second one silently did nothing, leaving stale bodies and ETags valid.
	 * The leading timestamp is not decoration either — MarkdownController reads
	 * it to decide whether `post_modified_gmt` is still a trustworthy
	 * validator, so keep the `<unix ts>-<random>` shape.
	 */
	public function flush_cache_salt(): void {
		if ( ! $this->salt_bump_pending ) {
			return;
		}

		$this->salt_bump_pending = false;

		update_option( 'sysmda_cache_salt', time() . '-' . bin2hex( random_bytes( 4 ) ) );
	}

	/**
	 * Hook: edited_term / delete_term. Bumps the salt when a term of a taxonomy
	 * printed in the front matter changes name or disappears.
	 *
	 * Limited to `category` and `post_tag` on purpose: those two are always
	 * emitted, under their own `categories:`/`tags:` keys, and are the ones
	 * MetadataBuilder::taxonomies_fingerprint() explicitly leaves out. The
	 * optional custom taxonomies need no hook — that fingerprint hashes their
	 * term NAMES, so a rename already moves the validator by itself.
	 *
	 * @param int    $term_id  Term being edited or deleted (unused).
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy the term belongs to.
	 */
	public function maybe_bump_for_term( $term_id, $tt_id, $taxonomy ): void {
		if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			$this->bump_cache_salt();
		}
	}

	/**
	 * Hook: deleted_post_meta. Preserves invalidation when a dependency vanishes.
	 *
	 * @param mixed  $meta_ids   Deleted metadata IDs (unused).
	 * @param int    $post_id    Post whose metadata changed (unused).
	 * @param string $meta_key   Deleted metadata key.
	 * @param mixed  $_meta_value Previous metadata value (unused).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress hook signature requires all four arguments.
	public function bump_for_deleted_dependency_meta( $meta_ids, $post_id, $meta_key, $_meta_value ): void {
		if ( in_array( $meta_key, array( '_thumbnail_id', 'rank_math_description' ), true ) ) {
			$this->bump_cache_salt();
			return;
		}

		// A configured extra field, deleted. Unlike the two above — whose
		// fingerprint parts are emitted unconditionally — an extra field
		// contributes a part only while the post HAS the key
		// (`MetadataBuilder::collect_meta_dependencies()`), so deleting the last
		// one can return the whole dependencies fingerprint to empty. That makes
		// `date_is_strong_validator()` trust `post_modified_gmt` again, which a
		// meta deletion never moves: a client sending only `If-Modified-Since`
		// would be told `304` while holding a body that has lost a section.
		//
		// Emptying a value needs nothing, and that asymmetry is the reason this
		// lives here and not in maybe_bump_for_empty_dependency_meta(): an empty
		// value leaves the row in place, so `metadata_exists()` stays true, the
		// part survives as the hash of an empty value, and the ETag moves on its
		// own.
		if ( in_array( (string) $meta_key, $this->extra_meta_keys(), true ) ) {
			$this->bump_cache_salt();
		}
	}

	/**
	 * The saved extra meta keys, memoized for the request.
	 *
	 * Reads the OPTION rather than applying `sysmda_markdown_extra_meta_keys`,
	 * on purpose. This runs inside a metadata-write hook that fires for every
	 * meta deletion on the site, and calling an arbitrary site filter from there
	 * — with the `WP_Post` it would first have to fetch — invites both cost and
	 * re-entrancy in somebody else's callback for a check that has to stay
	 * trivial.
	 *
	 * The documented consequence: a key added through the filter ALONE, with no
	 * panel entry, is not covered by this bump. `docs/filters.md` says so, and
	 * points at `sysmda_markdown_cache_dependencies`, which is the standing
	 * answer for "my output changes and the `.md` does not".
	 *
	 * @return string[]
	 */
	private function extra_meta_keys(): array {
		if ( null === $this->extra_meta_keys ) {
			$this->extra_meta_keys = $this->option_lines( 'sysmda_extra_meta_keys' );
		}

		return $this->extra_meta_keys;
	}

	/**
	 * Hook: updated_post_meta. Handles dependencies stored as an empty value.
	 *
	 * WordPress normally deletes an empty featured-image relation, but plugins
	 * may update either dependency to an empty scalar instead. Positive IDs and
	 * non-empty descriptions remain covered by the per-post fingerprint and do
	 * not need a site-wide invalidation.
	 *
	 * @param int    $meta_id    Updated metadata ID (unused).
	 * @param int    $post_id    Post whose metadata changed (unused).
	 * @param string $meta_key   Updated metadata key.
	 * @param mixed  $meta_value New metadata value.
	 */
	public function maybe_bump_for_empty_dependency_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( '_thumbnail_id' === $meta_key && ( ! is_scalar( $meta_value ) || (int) $meta_value <= 0 ) ) {
			$this->bump_cache_salt();
		}

		if ( 'rank_math_description' === $meta_key
			&& ( ! is_scalar( $meta_value ) || '' === (string) $meta_value ) ) {
			$this->bump_cache_salt();
		}
	}

	/**
	 * Hook: profile_update. Bumps the salt only when a user's **display name**
	 * changed, because that is what the `author:` front-matter key prints.
	 *
	 * The guard is the whole point: `profile_update` fires on every user save,
	 * and on a store with customer accounts that is often — bumping the salt
	 * each time would flush the site's Markdown cache and reissue every `ETag`
	 * for a change nothing in the output can see. An actual rename is rare, and
	 * it moves no post's modification date, so it needs the bump.
	 *
	 * @param int   $user_id       Updated user.
	 * @param mixed $old_user_data The user object as it was before the save.
	 */
	public function maybe_bump_for_author( $user_id, $old_user_data = null ): void {
		if ( ! is_object( $old_user_data ) || ! isset( $old_user_data->display_name ) ) {
			return;
		}

		$user = get_userdata( (int) $user_id );

		if ( ! $user || (string) $old_user_data->display_name === (string) $user->display_name ) {
			return;
		}

		$this->bump_cache_salt();
	}

	/**
	 * One-time migration from the 0.24.x "Custom taxonomies" checkbox to the
	 * explicit selection.
	 *
	 * A site that had the checkbox on keeps the feature, seeded with the
	 * taxonomies that are public **and** publicly queryable — so the output
	 * loses exactly the editorial-internal taxonomies that should never have
	 * been published (the reason for the change). A site that had it off gets
	 * nothing selected, i.e. no output change at all.
	 *
	 * The legacy option is deleted either way, which makes this idempotent: the
	 * option is autoloaded, so the check costs nothing once it is gone.
	 */
	public function maybe_migrate_legacy_taxonomies(): void {
		$legacy = get_option( self::LEGACY_OPTION_TAXONOMIES );

		if ( false === $legacy ) {
			return;
		}

		$was_enabled = '1' === (string) $legacy;
		delete_option( self::LEGACY_OPTION_TAXONOMIES );

		if ( ! $was_enabled ) {
			return;
		}

		if ( false === get_option( self::OPTION_TAXONOMIES ) ) {
			$seed = array();

			// The EFFECTIVE list, not the raw option: a site can enable its types
			// through `sysmda_markdown_supported_post_types` alone, and seeding
			// from the option would find no candidate and silently drop the
			// taxonomies such a site was already emitting.
			$candidates = MetadataBuilder::candidate_taxonomies( PostSupport::supported_post_types() );

			foreach ( $candidates as $slug => $taxonomy ) {
				if ( MetadataBuilder::is_public_taxonomy( $taxonomy ) ) {
					$seed[] = $slug;
				}
			}

			if ( ! empty( $seed ) ) {
				update_option( self::OPTION_TAXONOMIES, $seed );
			}
		}

		// The emitted front matter changes for every post that has terms in an
		// affected taxonomy, and a term change never touches post_modified_gmt:
		// the salt has to move so cached bodies and ETags are not reused. Writing
		// the seed above already triggers this through added_option; calling it
		// explicitly also covers the "nothing left to select" case (the site was
		// only emitting internal taxonomies). The static guard inside makes the
		// second call a no-op.
		$this->maybe_bump_cache_salt( self::OPTION_TAXONOMIES );
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
	 *
	 * Runs on a plain GET of the settings page and carries no nonce on purpose:
	 * it changes no state of its own. The target content is derived entirely from
	 * the stored option, so the operation is idempotent — loading this page can
	 * only bring .htaccess back in line with what was already saved, never to a
	 * state an attacker could choose. It is capability-gated all the same, and
	 * the write itself is locked and atomic (see LiteSpeedCompat::write()).
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
		// ── Always-registered options ──────────────────────────────────────────
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
			'sysmda_excluded_builder_elements',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_class_lines' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sysmda_extra_meta_keys',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_meta_key_lines' ),
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
			self::OPTION_TAXONOMIES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_taxonomy_slugs' ),
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

		// ── General ───────────────────────────────────────────────────────────
		add_settings_section( 'sysmda_general', __( 'General', 'system-markdown-alternate' ), array( $this, 'render_general_intro' ), self::PAGE );
		add_settings_field( 'sysmda_supported_post_types', __( 'Enabled content types', 'system-markdown-alternate' ), array( $this, 'field_post_types' ), self::PAGE, 'sysmda_general' );
		add_settings_field( 'sysmda_cache_ttl', __( 'Cache TTL (seconds)', 'system-markdown-alternate' ), array( $this, 'field_cache_ttl' ), self::PAGE, 'sysmda_general' );

		// ── Output Markdown ──────────────────────────────────────────────────────
		add_settings_section( 'sysmda_markdown', __( 'Markdown output', 'system-markdown-alternate' ), array( $this, 'render_markdown_intro' ), self::PAGE );
		add_settings_field( 'sysmda_excluded_shortcodes', __( 'Excluded shortcodes', 'system-markdown-alternate' ), array( $this, 'field_excluded_shortcodes' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_excluded_block_names', __( 'Excluded blocks', 'system-markdown-alternate' ), array( $this, 'field_excluded_block_names' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_excluded_classes', __( 'Excluded CSS classes', 'system-markdown-alternate' ), array( $this, 'field_excluded_classes' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_excluded_builder_elements', __( 'Excluded builder elements', 'system-markdown-alternate' ), array( $this, 'field_excluded_builder_elements' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_extra_meta_keys', __( 'Extra custom fields', 'system-markdown-alternate' ), array( $this, 'field_extra_meta_keys' ), self::PAGE, 'sysmda_markdown' );
		add_settings_field( 'sysmda_front_matter_taxonomies', __( 'Custom taxonomies', 'system-markdown-alternate' ), array( $this, 'field_front_matter_taxonomies' ), self::PAGE, 'sysmda_markdown' );

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

		// ── Advanced ─────────────────────────────────────────────────────────────
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

	// ─── Sanitization ─────────────────────────────────────────────────────────

	/**
	 * Post type allowlist: registered public types (excluding Media), plus any
	 * previously saved type that is not registered right now.
	 *
	 * The survival rule matters: a saved type whose plugin is temporarily
	 * inactive would otherwise be dropped by the next save of this page, silently
	 * turning the `.md` endpoint off for it when the plugin comes back. Same
	 * reasoning as sanitize_taxonomy_slugs(). The slug survives here and is
	 * inert at runtime until the type is registered publicly again:
	 * PostSupport::type_is_public() enforces that, and until 0.36.0 nothing
	 * did — this comment claimed a validation that was not happening.
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

		$saved = (array) get_option( 'sysmda_supported_post_types', array() );

		$clean = array();
		foreach ( $value as $item ) {
			$item = sanitize_key( $item );

			if ( '' === $item || 'attachment' === $item ) {
				continue;
			}

			if ( isset( $allowed[ $item ] ) || in_array( $item, $saved, true ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Taxonomy selection: valid slugs only, minus the always-excluded ones.
	 *
	 * Deliberately does NOT check `taxonomy_exists()`: a saved slug whose plugin
	 * is temporarily inactive must survive the next save instead of silently
	 * dropping out of the selection. The emission path validates the slug shape
	 * again and skips taxonomies with no terms, so an unknown slug is inert.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public function sanitize_taxonomy_slugs( $value ): array {
		if ( ! is_array( $value ) ) {
			return array(); // Nothing ticked: options.php passes null.
		}

		$clean = array();

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = sanitize_key( $item );

			if ( '' === $item || in_array( $item, MetadataBuilder::EXCLUDED_TAXONOMIES, true ) ) {
				continue;
			}

			$clean[] = $item;
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean, SORT_STRING ); // Stored order is irrelevant; a stable one keeps diffs readable.

		return $clean;
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
	 * One post meta key per line, normalized rather than rejected.
	 *
	 * Deliberately NOT `sanitize_key()`, which lowercases: meta keys are
	 * case-sensitive and plugins do ship camelCase ones, so lowercasing would
	 * silently point the setting at a key that does not exist. The charset is
	 * the same ASCII letter/digit/underscore/hyphen subset the class sanitizer
	 * uses, with a leading underscore preserved — protected keys are exactly
	 * what a page builder or a plugin stores its content in, and naming one is a
	 * deliberate choice the owner is entitled to make.
	 *
	 * Splitting on any whitespace, like the class lists, so a pasted list on one
	 * line still yields one key per entry.
	 *
	 * @param mixed $value
	 */
	public function sanitize_meta_key_lines( $value ): string {
		$tokens = preg_split( '/\s+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		$out    = array();

		foreach ( (array) $tokens as $token ) {
			$key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $token );

			if ( is_string( $key ) && '' !== $key && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
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
			function ( $fallback ) {
				$v = get_option( 'sysmda_cache_ttl' );
				return false !== $v ? (int) $v : $fallback;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_cache_ttl',
			function ( $fallback ) {
				$v = get_option( 'sysmda_cache_ttl' );
				return false !== $v ? (int) $v : $fallback;
			},
			20
		);

		// Priority 5, before the default 10: the saved selection is the *default*
		// value of the filter, so site code hooking at 10 can still narrow it and
		// extend it. The `sysmda_front_matter_taxonomies` gate needs no closure:
		// its default is derived from this list (see MetadataBuilder).
		add_filter(
			'sysmda_front_matter_taxonomy_slugs', // Same string as the option: the option IS this filter's default.
			function ( $slugs ) {
				$saved = get_option( self::OPTION_TAXONOMIES );
				return false !== $saved ? (array) $saved : $slugs;
			},
			5
		);

		add_filter(
			'sysmda_llms_txt_enriched',
			function ( $fallback ) {
				$v = get_option( 'sysmda_llms_txt_enriched' );
				return false !== $v ? '1' === $v : $fallback;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_lastmod',
			function ( $fallback ) {
				$v = get_option( 'sysmda_llms_txt_lastmod' );
				return false !== $v ? '1' === $v : $fallback;
			},
			20
		);

		add_filter(
			'sysmda_llms_txt_summary',
			function ( $fallback ) {
				$v = get_option( 'sysmda_llms_txt_summary' );
				return ( false !== $v && '' !== trim( (string) $v ) ) ? (string) $v : $fallback;
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
				return $this->option_to_merged_list( 'sysmda_excluded_shortcodes', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_block_names',
			function ( $defaults ) {
				return $this->option_to_merged_list( 'sysmda_excluded_block_names', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_classes',
			function ( $defaults ) {
				return $this->option_to_merged_list( 'sysmda_excluded_classes', $defaults );
			},
			20
		);

		add_filter(
			'sysmda_markdown_excluded_builder_elements',
			function ( $defaults ) {
				return $this->option_to_merged_list( 'sysmda_excluded_builder_elements', $defaults );
			},
			20
		);

		// Priority 5, before the default 10, for the same reason as the taxonomy
		// slugs above: this callback REPLACES rather than merges, so at 20 it
		// would run after site code and throw its additions away — the saved
		// list has to arrive as the filter's *default* for anyone hooking at 10
		// to narrow or extend it. The three exclusion filters can sit at 20
		// precisely because they merge, which preserves whatever ran earlier.
		add_filter(
			'sysmda_markdown_extra_meta_keys',
			function ( $defaults ) {
				// REPLACE, not merge: this is a curated inclusion list with no
				// built-in defaults, so the saved value is the whole answer.
				// The 0.40.0 accumulate rule covers the EXCLUSION lists, where
				// losing an entry means publishing something that should not be.
				return $this->option_to_list( 'sysmda_extra_meta_keys', $defaults );
			},
			5
		);

		add_filter(
			'sysmda_markdown_supported_post_types',
			function ( $defaults ) {
				$v = get_option( 'sysmda_supported_post_types' );
				if ( false === $v ) {
					return $defaults;
				}

				// The saved slugs are the ONLY place the public policy is
				// enforced, and deliberately so. sanitize_post_types() keeps a
				// slug whose provider is temporarily inactive, so an afternoon
				// of deactivation does not turn the endpoint off for its
				// content — but a type re-registered as `public => false`, or
				// replaced by an internal one of the same name, must not stay
				// servable on the strength of a stale option. The slug survives
				// in the settings and comes back by itself when the type is
				// public again.
				//
				// Applying the same rule to the filter's RESULT would be a
				// different thing entirely: site code adding a non-public CPT
				// here is an explicit request, and this filter's documented job
				// is to widen what is served. It runs at 20, so anything hooked
				// later passes through untouched.
				$list = array_values( array_filter( (array) $v, array( PostSupport::class, 'type_is_public' ) ) );

				return ! empty( $list ) ? $list : $defaults;
			},
			20
		);

		add_filter(
			'sysmda_markdown_robots_header',
			function ( $fallback ) {
				$v = get_option( 'sysmda_robots_header' );
				return false !== $v ? $v : $fallback;
			},
			20
		);

		add_filter(
			'sysmda_acf_subtitle_key',
			function ( $fallback ) {
				$v = get_option( 'sysmda_acf_subtitle_key' );
				return ( false !== $v && '' !== $v ) ? $v : $fallback;
			},
			20
		);

		add_filter(
			'sysmda_acf_tldr_key',
			function ( $fallback ) {
				$v = get_option( 'sysmda_acf_tldr_key' );
				return ( false !== $v && '' !== $v ) ? $v : $fallback;
			},
			20
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
		$items = $this->option_lines( $option );

		return ! empty( $items ) ? $items : $defaults;
	}

	/**
	 * Same textarea, but the saved lines are **added to** the defaults instead
	 * of replacing them.
	 *
	 * Used by the three exclusion lists, and the difference is not cosmetic.
	 * Under the old replace semantics, typing a single tag into "Excluded
	 * shortcodes" silently dropped every built-in default with it: a site adding
	 * one newsletter tag lost `contact-form-7`, `gravityform`, `wpforms`,
	 * `mailerlite_form` and `lwptoc` in the same save, and the only hint was a
	 * help text saying the defaults apply when the box is empty — true, and
	 * useless once it is not. Exclusions are a safety list; the failure mode of
	 * getting them wrong is publishing a form into every `.md`, so they add up
	 * rather than trade off.
	 *
	 * Removing a default is therefore no longer possible from the panel, and
	 * that is the accepted cost: it stays available through
	 * `sysmda_markdown_excluded_*`, which runs at priority 10, before the
	 * closure feeding this in at 20 — so site code can still hand a narrowed
	 * list through, and only the textarea is additive.
	 *
	 * @param string[] $defaults Incoming filter value (the built-in list).
	 * @return string[]
	 */
	private function option_to_merged_list( string $option, array $defaults ): array {
		return array_values( array_unique( array_merge( $defaults, $this->option_lines( $option ) ) ) );
	}

	/**
	 * Non-empty trimmed lines of a textarea option, or an empty array.
	 *
	 * @return string[]
	 */
	private function option_lines( string $option ): array {
		$v = get_option( $option );

		if ( false === $v || '' === $v ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $v ) ) ) );
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
	 * Quick info in the aside: /llms.txt endpoint status, URL and conflicts.
	 * Presentation only: uses the same data already calculated by the plugin.
	 */
	public function render_llmstxt_aside(): void {
		$enabled = '1' === get_option( 'sysmda_llms_txt_enabled', '1' );
		$url     = home_url( '/llms.txt' );

		// The option being on is not the same as the endpoint answering. With no
		// content type selected LlmsTxtController deliberately stays silent —
		// there is nothing to index, and it must not take the URL over from
		// whatever else may be handling it while the rest of the plugin is
		// inactive. Reporting that as a flat "Enabled" sent the reader to a URL
		// that does not respond, with nothing on the page explaining why.
		$waiting = $enabled && empty( PostSupport::supported_post_types() );

		echo '<section class="sysmda-card sysmda-aside-card">';
		echo '<header class="sysmda-card__header"><h2>' . esc_html__( 'llms.txt status', 'system-markdown-alternate' ) . '</h2></header>';
		echo '<div class="sysmda-card__body">';

		echo '<p class="sysmda-endpoint-state ' . ( $enabled && ! $waiting ? 'is-on' : 'is-off' ) . '">';
		echo '<span class="sysmda-dot" aria-hidden="true"></span>';
		if ( ! $enabled ) {
			echo esc_html__( 'Disabled', 'system-markdown-alternate' );
		} elseif ( $waiting ) {
			echo esc_html__( 'Enabled, waiting for a content type', 'system-markdown-alternate' );
		} else {
			echo esc_html__( 'Enabled', 'system-markdown-alternate' );
		}
		echo '</p>';

		if ( $waiting ) {
			echo '<p class="description">' . esc_html__( 'Nothing is indexed yet, so the endpoint does not respond. Select at least one content type under General.', 'system-markdown-alternate' ) . '</p>';
		}

		echo '<p class="sysmda-endpoint-url"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $url ) . '</code></a></p>';

		$this->render_conflict_warning();

		echo '</div></section>';
	}

	public function render_integrations_intro(): void {
		echo '<p class="sysmda-help">' . wp_kses_post( __( 'Informational section: how to use the <code>.md</code> URL in content and templates.', 'system-markdown-alternate' ) ) . '</p>';

		echo '<div class="sysmda-integration-card">';
		echo '<h3>' . esc_html__( 'Shortcodes', 'system-markdown-alternate' ) . '</h3>';
		echo '<p>' . wp_kses_post( __( '<code>[sysmda_md_url]</code> — <code>.md</code> URL of the current post.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sysmda_md_url id="123"]</code> — <code>.md</code> URL of a specific post.', 'system-markdown-alternate' ) ) . '</p>';
		echo '<p>' . wp_kses_post( __( '<code>[sysmda_md_download]</code> — a link that saves the <code>.md</code> as a file instead of opening it.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sysmda_md_download text="Save it"]</code> — same link with your own label.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sysmda_md_download id="123"]</code> — the download link of a specific post.', 'system-markdown-alternate' ) ) . '</p>';
		echo '<p>' . wp_kses_post( __( '<code>[sysmda_md_actions]</code> — a Copy as Markdown split button with copy, new-tab view and download actions.', 'system-markdown-alternate' ) ) . '<br>';
		echo wp_kses_post( __( '<code>[sysmda_md_actions id="123"]</code> — the same actions for a specific post.', 'system-markdown-alternate' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'All three return empty if the post does not expose a .md (type not enabled, draft, password-protected, or a non-standard post format), so they never link to a 404. The actions stylesheet and script load only on pages that render that shortcode.', 'system-markdown-alternate' ) . '</p>';
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

		$orphans = array_values( array_diff( $saved, array_keys( $all_types ) ) );

		// What each type is actually built with. Advisory only — it never
		// filters anything — but it is what makes the page-builder veto visible
		// before it surprises anybody: a type showing "8 Divi" is a type whose
		// posts have no Markdown version at all. Computed here, on the settings
		// screen, and never on the front end.
		$census = BuilderCensus::breakdown( array_merge( array_keys( $all_types ), $orphans ) );

		foreach ( $all_types as $pt ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="sysmda_supported_post_types[]" value="%1$s"%2$s /> %3$s <code>(%4$s)</code>%5$s</label>',
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $saved, true ), true, false ),
				esc_html( $pt->labels->singular_name ),
				esc_html( $pt->name ),
				wp_kses_post( self::breakdown_html( isset( $census[ $pt->name ] ) ? $census[ $pt->name ] : array() ) )
			);
		}

		// Types saved earlier that are not registered right now (plugin
		// deactivated). Rendered checked so saving the page cannot silently
		// discard the choice: the field would otherwise be absent from the POST.
		foreach ( $orphans as $type ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="sysmda_supported_post_types[]" value="%1$s" checked="checked" /> <code>%1$s</code> <span class="description">%2$s</span>%3$s</label>',
				esc_attr( $type ),
				esc_html__( '— not registered right now', 'system-markdown-alternate' ),
				wp_kses_post( self::breakdown_html( isset( $census[ $type ] ) ? $census[ $type ] : array() ) )
			);
		}

		echo '<p class="description">' . wp_kses_post( __( 'Content types exposed as <code>.md</code> and in <code>/llms.txt</code>. No selection = plugin inactive.', 'system-markdown-alternate' ) ) . '</p>';
	}

	/**
	 * The "— 12 Bricks, 3 Gutenberg" note beside a content type, plus a warning
	 * naming any page builder whose posts are not served.
	 *
	 * A breakdown rather than one label, because mixed types are the normal
	 * case: sites routinely build their pages with a builder while the articles
	 * stay in the ordinary editor, and a single label would be wrong for both
	 * halves.
	 *
	 * It describes the **built-in** veto list and deliberately does not consult
	 * `sysmda_markdown_unsupported_builders`: that filter is evaluated per post
	 * and there is no post here. A site that has opted a builder back in through
	 * it knows it did; the panel would have to invent a post to ask.
	 *
	 * @param array<string,int> $counts kind => number of published posts.
	 */
	private static function breakdown_html( array $counts ): string {
		if ( empty( $counts ) ) {
			return '';
		}

		$vetoed  = array_merge( BuilderDetector::NEVER_SUPPORTED, BuilderDetector::AWAITING_ADAPTER );
		$parts   = array();
		$flagged = array();

		foreach ( $counts as $kind => $n ) {
			if ( 'gutenberg' === $kind ) {
				$label = 'Gutenberg';
			} elseif ( 'classic' === $kind ) {
				$label = __( 'classic', 'system-markdown-alternate' );
			} else {
				$label = BuilderDetector::label( $kind );

				if ( in_array( $kind, $vetoed, true ) ) {
					$flagged[] = $label;
				}
			}

			/* translators: 1: number of posts, 2: what they are built with (a page builder name, "Gutenberg" or "classic"). */
			$parts[] = sprintf( _x( '%1$d %2$s', 'content breakdown entry', 'system-markdown-alternate' ), (int) $n, $label );
		}

		$html = ' <span class="description">' . esc_html( '— ' . implode( ', ', $parts ) ) . '</span>';

		if ( ! empty( $flagged ) ) {
			$html .= ' <span class="description sysmda-builder-warning"><strong>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated page builder names. */
					__( '⚠ %s content has no Markdown version and is never served.', 'system-markdown-alternate' ),
					implode( ', ', array_unique( $flagged ) )
				)
			) . '</strong></span>';
		}

		return $html;
	}

	public function field_cache_ttl(): void {
		$v = get_option( 'sysmda_cache_ttl' );
		$v = false !== $v ? (int) $v : DAY_IN_SECONDS;
		echo '<input type="number" min="0" step="1" name="sysmda_cache_ttl" value="' . esc_attr( $v ) . '" class="small-text" /> ' . esc_html__( 'seconds', 'system-markdown-alternate' );
		echo '<p class="description">' . esc_html__( '0 = cache disabled. Default: 86400 (24 hours).', 'system-markdown-alternate' ) . '</p>';
	}

	public function field_excluded_shortcodes(): void {
		$this->render_exclusion_field( 'sysmda_excluded_shortcodes', ShortcodeCleaner::DEFAULT_EXCLUDED );
	}

	public function field_excluded_block_names(): void {
		$this->render_exclusion_field( 'sysmda_excluded_block_names', BlockCleaner::DEFAULT_EXCLUDED );
	}

	public function field_excluded_classes(): void {
		$this->render_exclusion_field( 'sysmda_excluded_classes', ContentRenderer::EXCLUDED_CLASSES );
	}

	/**
	 * Page-builder chrome (a form, a nav menu, a share bar, a table of
	 * contents, a breadcrumb trail) removed the same way an excluded CSS class
	 * is. Defaults come from the active builder adapters
	 * (`BuilderAdapter::element_selectors()`) rather than a class constant here,
	 * since which classes are even meaningful depends on which builder is
	 * active — currently just `BricksAdapter::DEFAULT_EXCLUDED_ELEMENTS`.
	 */
	public function field_excluded_builder_elements(): void {
		$this->render_exclusion_field( 'sysmda_excluded_builder_elements', BricksAdapter::DEFAULT_EXCLUDED_ELEMENTS );
	}

	/**
	 * Meta keys whose values are pulled into the document.
	 *
	 * No "built-in defaults" disclosure here, unlike the exclusion fields: there
	 * are none, and there never will be. Which meta keys hold content is a
	 * property of the site, not of WordPress — post meta is mostly internal
	 * plumbing — so a default would be a guess with a published document as its
	 * cost. Same discipline as the taxonomy selection: the plugin offers the
	 * box, the owner fills it in.
	 */
	public function field_extra_meta_keys(): void {
		$v = (string) get_option( 'sysmda_extra_meta_keys', '' );
		echo '<textarea name="sysmda_extra_meta_keys" rows="4" class="code sysmda-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sysmda-help">' . wp_kses_post(
			__( 'One post meta key per line, empty by default. Their values are appended to the end of the Markdown body, in the order listed. Works with any plugin that stores post meta — ACF, JetEngine, Meta Box — and with the native Custom Fields box. Values that are not text (a repeater, an image, anything stored as an array) are skipped.', 'system-markdown-alternate' )
		) . '</p>';
	}

	/**
	 * Compact "one per line" textarea plus a list of defaults.
	 *
	 * @param string[] $defaults
	 */
	private function render_exclusion_field( string $option, array $defaults ): void {
		$v = (string) get_option( $option, '' );
		echo '<textarea name="' . esc_attr( $option ) . '" rows="4" class="code sysmda-textarea">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description sysmda-help">' . esc_html__( 'One per line. These are added to the built-in defaults below, which always apply — removing one of them requires a filter.', 'system-markdown-alternate' ) . '</p>';
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

	/**
	 * Checkbox list of the taxonomies to emit under `taxonomies:`.
	 *
	 * Nothing is selected by default and nothing is ever added implicitly: a
	 * taxonomy registered by a plugin installed later shows up here unticked.
	 * Rows that are not publicly queryable (typically editorial-internal
	 * classifications with no term archive) are labelled as such but stay
	 * selectable — including one is a deliberate choice, not an accident.
	 */
	public function field_front_matter_taxonomies(): void {
		$raw      = get_option( self::OPTION_TAXONOMIES ); // false = never saved.
		$selected = false !== $raw ? (array) $raw : array();

		// Effective supported types (option, or the filter on a code-driven site),
		// so the list matches what actually gets served.
		$post_types = PostSupport::supported_post_types();
		$candidates = MetadataBuilder::candidate_taxonomies( $post_types );

		foreach ( $candidates as $slug => $taxonomy ) {
			$label = isset( $taxonomy->labels->singular_name ) ? (string) $taxonomy->labels->singular_name : $slug;

			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s <code>(%2$s)</code>%5$s</label>',
				esc_attr( self::OPTION_TAXONOMIES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label ),
				MetadataBuilder::is_public_taxonomy( $taxonomy )
					? ''
					: ' <span class="description">' . esc_html__( '— internal: not publicly queryable', 'system-markdown-alternate' ) . '</span>'
			);
		}

		// Slugs saved earlier that are not among the candidates right now (plugin
		// deactivated, taxonomy detached, content type disabled). Rendered checked
		// so saving the page cannot silently discard the choice: the field would
		// otherwise be absent from the POST and sanitize to an empty selection.
		foreach ( array_diff( $selected, array_keys( $candidates ) ) as $slug ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%1$s[]" value="%2$s" checked="checked" /> <code>%2$s</code> <span class="description">%3$s</span></label>',
				esc_attr( self::OPTION_TAXONOMIES ),
				esc_attr( $slug ),
				esc_html__( '— not available for the enabled content types', 'system-markdown-alternate' )
			);
		}

		if ( empty( $post_types ) ) {
			echo '<p class="description">' . esc_html__( 'Select at least one content type under General first: the taxonomies available for it will be listed here.', 'system-markdown-alternate' ) . '</p>';
		} elseif ( empty( $candidates ) ) {
			echo '<p class="description">' . esc_html__( 'No custom taxonomy is registered for the enabled content types.', 'system-markdown-alternate' ) . '</p>';
		}

		echo '<p class="description">' . wp_kses_post( __( 'Adds a <code>taxonomies:</code> block with the terms of the taxonomies ticked above, in alphabetical order. Nothing is added until you tick one. Categories and tags already have their own keys and are never repeated here.', 'system-markdown-alternate' ) ) . '</p>';
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

		$this->render_md_hits_named_totals( $hits, $today );
	}

	/**
	 * Read-only breakdown of the bot total by known crawler name, for the
	 * same three windows as render_md_hits_totals().
	 *
	 * Shows only names with at least one hit in the last 30 days — a fixed
	 * table of always-zero rows for crawlers a site never sees would be noise,
	 * not signal. Named bots are a curated subset of the bot total (see
	 * HitCounter::named_bot()): most bot traffic stays unnamed by design.
	 *
	 * @param array  $hits  Daily buckets, already fetched by the caller.
	 * @param string $today Current UTC day ('YYYY-MM-DD').
	 */
	private function render_md_hits_named_totals( array $hits, string $today ): void {
		$named_30 = array_filter(
			HitCounter::named_totals( $hits, $today, 30 ),
			static function ( $n ) {
				return $n > 0;
			}
		);

		if ( empty( $named_30 ) ) {
			return;
		}

		arsort( $named_30 );

		$named_1 = HitCounter::named_totals( $hits, $today, 1 );
		$named_7 = HitCounter::named_totals( $hits, $today, 7 );

		echo '<p class="description" style="margin-top:12px">' . esc_html__( 'Known crawlers seen among the bot hits above (a subset — most bot traffic has no dedicated row):', 'system-markdown-alternate' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:420px">';
		echo '<thead><tr><th>' . esc_html__( 'Bot', 'system-markdown-alternate' ) . '</th><th>' . esc_html__( 'Today', 'system-markdown-alternate' ) . '</th><th>' . esc_html__( 'Last 7 days', 'system-markdown-alternate' ) . '</th><th>' . esc_html__( 'Last 30 days', 'system-markdown-alternate' ) . '</th></tr></thead><tbody>';

		foreach ( array_keys( $named_30 ) as $name ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				esc_html( $name ),
				isset( $named_1[ $name ] ) ? (int) $named_1[ $name ] : 0,
				isset( $named_7[ $name ] ) ? (int) $named_7[ $name ] : 0,
				(int) $named_30[ $name ]
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the settings page: header, tabs, one card per registered section.
	 *
	 * The section list is read from `$wp_settings_sections`, a core global with no
	 * public accessor — the only way to wrap each section in its own card and tab
	 * panel while keeping every field inside the single form (so saving,
	 * sanitization and nonces stay exactly as the Settings API expects). If a
	 * future core change makes that global unavailable or reshapes it, the page
	 * falls back to the standard do_settings_sections() output: plain, but
	 * complete and saveable.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp_settings_sections, $wp_settings_fields;
		$sections = isset( $wp_settings_sections[ self::PAGE ] ) ? (array) $wp_settings_sections[ self::PAGE ] : array();

		if ( empty( $sections ) ) {
			$this->render_page_fallback();
			return;
		}
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
					<nav class="nav-tab-wrapper sysmda-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'system-markdown-alternate' ); ?>">
						<?php
						$i = 0;
						foreach ( $sections as $sid => $section ) {
							printf(
								'<a href="#sysmda-panel-%1$s" id="sysmda-tab-%1$s" class="nav-tab%2$s" data-tab="%1$s" aria-controls="sysmda-panel-%1$s">%3$s</a>',
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
								'<div class="sysmda-tab-panel%1$s" id="sysmda-panel-%2$s" data-tab="%2$s" role="tabpanel" aria-labelledby="sysmda-tab-%2$s">',
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

	/**
	 * Plain Settings API rendering, used when the section list cannot be read
	 * (see render_page()). No tabs and no cards, but every field is present and
	 * the form saves normally.
	 */
	private function render_page_fallback(): void {
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

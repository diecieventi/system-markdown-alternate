<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Per-post page-builder detection, and the veto on builders with no adapter.
 *
 * A page builder breaks the assumption the whole pipeline rests on — that the
 * content lives in `post_content`. Some builders keep their tree in post meta,
 * leaving `post_content` empty, and the `.md` comes out as front matter plus a
 * bare `# Title`. Others (Divi, WPBakery) fill `post_content` with their own
 * layout shortcodes, and the `.md` comes out full of chrome converted as prose.
 * The second is the worse of the two: an empty document is useless, a wrong one
 * is misleading to the exact audience this plugin exists for, and nothing about
 * it looks broken from the admin side.
 *
 * Until a builder has an adapter that can render it, the honest answer is that
 * such a post has **no Markdown representation at all**. That is expressed as a
 * veto inside `PostSupport::is_servable()`, so it reaches every consumer at
 * once: the `.md` URL 404s, no `rel="alternate"` link or `Link:` header is
 * advertised, the post is absent from `/llms.txt`, and the shortcodes and the
 * dynamic tag render nothing — so nothing on the site ever points at a Markdown
 * URL that does not exist.
 *
 * Three rules shape the detection, and each one is easy to get backwards.
 *
 * 1. **Per post, never per post type and never per site.** Sites routinely
 *    build their pages with a builder while the articles stay in the ordinary
 *    editor; mixed types are the normal case. Activating Bricks on a site of
 *    150 Gutenberg posts must therefore change nothing at all, and it does not:
 *    none of those posts carries builder render data of its own.
 *
 * 2. **The render mode decides, not the presence of builder data.** Bricks and
 *    Elementor both document a per-post switch back to the WordPress editor
 *    that leaves the builder tree stored while the front end serves
 *    `post_content`. Keying on the blob would deny a Markdown representation to
 *    a post that renders perfectly ordinary content — the same class of error
 *    as the old `post_password_required()` check, where the question asked was
 *    not the question that mattered.
 *
 * 3. **The veto applies whether the builder plugin is active or not.** This is
 *    a deliberate asymmetry with the adapters that will follow: an adapter
 *    needs the vendor present, because with no renderer there is nothing to
 *    render and `post_content` is then the correct answer. A veto is the
 *    opposite — with Divi deactivated its `[et_pb_*]` shortcodes stay in
 *    `post_content` unregistered and would be published as literal text, which
 *    is the worst outcome of all. So detection reads meta and never calls into
 *    a vendor API.
 */
class BuilderDetector {

	/**
	 * Builders that will never be supported, whatever happens.
	 *
	 * Decided with the maintainer in August 2026: a post built with one of
	 * these has no Markdown representation, full stop. They are not waiting for
	 * anything.
	 */
	const NEVER_SUPPORTED = array( 'divi', 'wpbakery', 'oxygen', 'beaver-builder', 'breakdance' );

	/**
	 * Builders that are vetoed only until their adapter exists.
	 *
	 * The list is how the work is phased: one mechanism, incremental coverage,
	 * and no window in which an empty or wrong `.md` is published. When the
	 * Bricks adapter lands, `bricks` moves out of here and nothing else has to
	 * change. Elementor is parked behind it and may never move.
	 */
	const AWAITING_ADAPTER = array( 'bricks', 'elementor' );

	/**
	 * How each builder declares, per post, that it renders the front end.
	 *
	 * `key => array( meta key, accepted values )`. A non-empty value list is an
	 * **exact** match against the stored scalar, which is what rule 2 above
	 * requires: `_bricks_editor_mode` reads `wordpress` and
	 * `_elementor_edit_mode` is deleted outright when a post is switched back to
	 * the WordPress editor, and `_wpb_vc_js_status` stores the string `false`,
	 * which is perfectly truthy and would claim every post that ever had the
	 * WPBakery editor opened on it.
	 *
	 * An **empty** value list means "this builder ships no documented mode flag,
	 * so the presence of its render payload is the closest available proxy".
	 * That applies to Oxygen and Breakdance only, neither of which offers a
	 * per-post switch back to the WordPress editor for the payload to outlive.
	 * If one ever grows a mode flag, it moves to an exact match here and nothing
	 * else changes.
	 *
	 * The order is the order the post is tested in, and it is deterministic on
	 * purpose: a post migrated between builders can hold two payloads, and which
	 * one is reported must not depend on how PHP happens to walk an array.
	 */
	const RENDER_MODE_META = array(
		'bricks'         => array( '_bricks_editor_mode', array( 'bricks' ) ),
		'elementor'      => array( '_elementor_edit_mode', array( 'builder' ) ),
		'divi'           => array( '_et_pb_use_builder', array( 'on' ) ),
		'wpbakery'       => array( '_wpb_vc_js_status', array( 'true' ) ),
		'beaver-builder' => array( '_fl_builder_enabled', array( '1' ) ),
		'oxygen'         => array( 'ct_builder_shortcodes', array() ),
		'breakdance'     => array( '_breakdance_data', array() ),
	);

	/**
	 * Display names, for the settings panel and for nothing else.
	 *
	 * Deliberately not translated: they are product names.
	 */
	const LABELS = array(
		'bricks'         => 'Bricks',
		'elementor'      => 'Elementor',
		'divi'           => 'Divi',
		'wpbakery'       => 'WPBakery Page Builder',
		'oxygen'         => 'Oxygen',
		'beaver-builder' => 'Beaver Builder',
		'breakdance'     => 'Breakdance',
	);

	/**
	 * The page builder that renders this post, or '' when none does.
	 *
	 * Reads meta only, so the answer is the same whether the builder plugin is
	 * loaded or not (rule 3). It never sniffs `post_content`: an article
	 * documenting Divi and quoting `[et_pb_section]` in a code sample would
	 * otherwise be made unservable by its own example — the same defect
	 * `CodeRegions` exists to prevent, one level up.
	 *
	 * The first `get_post_meta()` call primes WordPress's meta cache for the
	 * post, so the whole loop costs one query at most, and none at all on the
	 * `.md` route where the post's meta is already primed.
	 *
	 * @return string A key of self::LABELS, or '' when the post is ordinary.
	 */
	public static function detect( \WP_Post $post ): string {
		foreach ( self::RENDER_MODE_META as $builder => $spec ) {
			list( $meta_key, $accepted ) = $spec;

			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( array() === $accepted ) {
				// No mode flag: a stored payload is what renders.
				if ( ! empty( $value ) ) {
					return $builder;
				}

				continue;
			}

			if ( is_scalar( $value ) && in_array( (string) $value, $accepted, true ) ) {
				return $builder;
			}
		}

		return '';
	}

	/**
	 * Whether this post is rendered by a builder the plugin cannot represent.
	 *
	 * Consulted by `PostSupport::is_servable()`. A post rendered by a builder
	 * that has left the list — because its adapter shipped — is not vetoed and
	 * takes the ordinary path.
	 */
	public static function is_unsupported( \WP_Post $post ): bool {
		$builder = self::detect( $post );

		if ( '' === $builder ) {
			return false;
		}

		return in_array( $builder, self::unsupported_builders( $post ), true );
	}

	/**
	 * The builder keys currently without a Markdown representation.
	 *
	 * @return string[]
	 */
	public static function unsupported_builders( \WP_Post $post ): array {
		$builders = array_merge( self::NEVER_SUPPORTED, self::AWAITING_ADAPTER );

		/**
		 * Filters the page builders whose posts have no Markdown representation.
		 *
		 * Defaults to every builder the plugin can detect, because none of them
		 * has an adapter yet. Remove a key to serve that builder's posts again —
		 * which means accepting whatever the ordinary pipeline makes of them, an
		 * empty document for the meta-based builders and layout chrome converted
		 * as prose for the shortcode-based ones. Return an empty array to switch
		 * the veto off entirely.
		 *
		 * Recognized keys: `bricks`, `elementor`, `divi`, `wpbakery`, `oxygen`,
		 * `beaver-builder`, `breakdance`. Adding a key the plugin cannot detect
		 * has no effect; to deny posts built with something else, use
		 * `sysmda_post_is_servable`, which is the general per-post veto.
		 *
		 * The rule lives in `is_servable()`, so it applies to the `.md` route,
		 * negotiation, `rel="alternate"`, `/llms.txt`, the shortcodes and the
		 * dynamic tag at once. On the every-request path, `304` responses
		 * included: keep it to values already in memory.
		 *
		 * @param string[] $builders Builder keys with no Markdown representation.
		 * @param \WP_Post $post     Post being evaluated.
		 */
		return (array) apply_filters( 'sysmda_markdown_unsupported_builders', $builders, $post );
	}

	/**
	 * Display name for a builder key, falling back to the key itself.
	 */
	public static function label( string $builder ): string {
		return isset( self::LABELS[ $builder ] ) ? self::LABELS[ $builder ] : $builder;
	}
}

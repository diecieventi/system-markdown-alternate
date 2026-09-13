<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a Bricks-built post through Bricks' own API.
 *
 * Ground rules verified against a real Bricks 2.0 install
 * (`sma-bricks-instawp-co`, see docs/page-builders-plan.md §6):
 *
 * - `\Bricks\Frontend::render_data( $elements, $area = 'content' )` is
 *   `public static`, has no dependency on the main query, and echoes/enqueues
 *   nothing this route would print (§6.2.1, §6.2.4, §6.2.6).
 * - Every rendered element wraps in `class="brxe-{name}"`, and a custom class
 *   set on the element (`settings._cssClasses`) is emitted verbatim alongside
 *   it — confirmed live with an `md-exclude` class, which needs no code of its
 *   own here: ContentRenderer's existing class-removal pass already reaches it.
 * - Bricks' own image lazy-loading replaces `src` with an inline SVG
 *   placeholder unless `\Bricks\Database::$page_settings['disableLazyLoad']`
 *   is set for the duration of the render (§6.2.5) — the one step that is not
 *   optional cleanup.
 */
class BricksAdapter implements BuilderAdapter {

	/** The key BuilderDetector reports for this builder. */
	const BUILDER_KEY = 'bricks';

	/** Meta key holding the serialized element tree. */
	const META_CONTENT = '_bricks_page_content_2';

	/**
	 * Default `sysmda_markdown_excluded_builder_elements` entries for Bricks:
	 * the class Bricks emits (`brxe-{element name}`) for its built-in form,
	 * navigation, share, table-of-contents and breadcrumbs elements — verified
	 * element names against the installed Bricks 2.0 (`\Bricks\Elements::$elements`).
	 * Conservative on purpose (see AGENTS.md, the 0.40.0 exclusion-list rule):
	 * chrome and interface, never a list of posts.
	 */
	const DEFAULT_EXCLUDED_ELEMENTS = array(
		'brxe-form',
		'brxe-nav-menu',
		'brxe-nav-nested',
		'brxe-post-sharing',
		'brxe-post-toc',
		'brxe-breadcrumbs',
	);

	/**
	 * The Bricks element name whose render calls WordPress's full `the_content`
	 * filter chain (confirmed in docs/page-builders-plan.md §6.2.7).
	 */
	const CONTENT_ELEMENT = 'post-content';

	/**
	 * How far `lineage_classes()` walks up before giving up.
	 *
	 * A backstop, not a limit on real layouts: Bricks nesting is a handful of
	 * levels, and this only has to stop a malformed `parent` chain the `$seen`
	 * set cannot describe. Truncating costs one leaf its outermost ancestors,
	 * which is the pre-0.51.0 behaviour for that leaf and nothing worse.
	 */
	const MAX_ANCESTOR_DEPTH = 50;

	/**
	 * How far the referenced-template walk follows nested `template` elements.
	 *
	 * A backstop behind the `$seen` set, not a limit on real layouts: a Bricks
	 * page nests a template inside a template a handful of levels at most. The
	 * set already ends every cycle it can describe; this ends the ones it
	 * cannot (a tree rewritten mid-walk, a `get_post_meta()` filter handing back
	 * a fresh reference each call). Truncating loses one branch of the
	 * dependency graph, which is the pre-fix behaviour and nothing worse.
	 */
	const MAX_TEMPLATE_DEPTH = 10;

	public function is_active(): bool {
		return class_exists( '\Bricks\Frontend' ) && class_exists( '\Bricks\Database' );
	}

	/**
	 * The render mode, not the presence of data (BuilderDetector rule 2): reads
	 * the same meta key and accepted value BuilderDetector itself tests, so the
	 * two can never disagree about a post switched to "Render with WordPress".
	 */
	public function handles( \WP_Post $post ): bool {
		list( $meta_key, $accepted ) = BuilderDetector::RENDER_MODE_META[ self::BUILDER_KEY ];

		$value = get_post_meta( $post->ID, $meta_key, true );

		return is_scalar( $value ) && in_array( (string) $value, $accepted, true );
	}

	/**
	 * Renders through Bricks' own API. The lazy-load flag is bracketed exactly
	 * as docs/page-builders-plan.md §7.2 specifies — save/restore, never a bare
	 * assignment — because skipping the restore would leave every OTHER Bricks
	 * render on the same request (a preview, an admin-ajax call sharing the
	 * process) with lazy-loading silently disabled.
	 */
	public function render( \WP_Post $post ): string {
		$tree = $this->tree( $post );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Bricks' own API.
		$previous = \Bricks\Database::$page_settings['disableLazyLoad'] ?? null;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		\Bricks\Database::$page_settings['disableLazyLoad'] = true;

		$suppressed = $this->maybe_suppress_content_filters( $tree, $post );

		try {
			$html = (string) \Bricks\Frontend::render_data( $tree, 'content' );
		} finally {
			$this->restore_content_filters( $suppressed );

			if ( null === $previous ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				unset( \Bricks\Database::$page_settings['disableLazyLoad'] );
			} else {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				\Bricks\Database::$page_settings['disableLazyLoad'] = $previous;
			}
		}

		return $html;
	}

	/**
	 * Cache-validator inputs (folded into MetadataBuilder::dependencies_fingerprint()):
	 * the render mode (so a flip to/from "Render with WordPress" moves the
	 * validator even though the tree itself is untouched), a hash of the whole
	 * tree, and the modification date of every `template` element's referenced
	 * post, **followed transitively** (edge case 11 — the template's own content
	 * lives outside this post's row and editing it does not touch
	 * post_modified_gmt here; a template nested inside a template is the same
	 * dependency one level down, see referenced_template_fingerprint()).
	 *
	 * Deliberately narrower than "every out-of-post dependency": a Bricks
	 * "component" instance carries a `cid` reference whose own definition was
	 * not confirmed to live anywhere resolvable on the reconnaissance install
	 * (no `bricks_component` post type, no populated components option) — see
	 * AGENTS.md. The `cid` value itself is still covered by the tree hash, so
	 * a *reassigned* component reference invalidates; a component's own
	 * definition changing elsewhere does not. Documented, not silently assumed.
	 *
	 * @return array<string,scalar>
	 */
	public function fingerprint( \WP_Post $post ): array {
		if ( ! $this->handles( $post ) ) {
			return array();
		}

		$tree = $this->tree( $post );

		list( $mode_key ) = BuilderDetector::RENDER_MODE_META[ self::BUILDER_KEY ];

		$parts = array(
			'mode' => (string) get_post_meta( $post->ID, $mode_key, true ),
			'blob' => md5( (string) wp_json_encode( $tree ) ),
		);

		$templates = self::referenced_template_fingerprint( $tree );

		if ( '' !== $templates ) {
			$parts['templates'] = $templates;
		}

		return $parts;
	}

	/**
	 * A cheap, unrendered approximation of the post's text: walks the stored
	 * tree instead of calling into Bricks, reading each element's own `text`
	 * setting (confirmed for heading/text-basic/button/text-link elements) and
	 * wrapping it in a span carrying the same `brxe-{name}` class Bricks itself
	 * emits, the element's own custom class if set, **and the same two for every
	 * ancestor** — see leaves_markup(), where the ancestors are the part that
	 * was missing. That is what lets the
	 * caller run this through the SAME exclusion pass
	 * (ContentRenderer::strip_excluded_content()) the rendered body goes
	 * through, rather than reimplementing exclusion for raw text.
	 *
	 * Only used as a last resort — after Rank Math and the excerpt — and only
	 * for the front-matter description fallback and `/llms.txt` entries, both
	 * contexts where rendering every listed post through Bricks would be
	 * prohibitive. Crude by design: no nested-item extraction (list, tabs,
	 * accordion item text-field names were not confirmed — see
	 * docs/page-builders-plan.md §6.2.5's deferred note on the `list` element),
	 * so those elements contribute nothing here even though they may hold text.
	 */
	public function source_text( \WP_Post $post ): string {
		if ( ! $this->handles( $post ) ) {
			return '';
		}

		return $this->leaves_markup( $this->tree( $post ) );
	}

	public function element_selectors(): array {
		return self::DEFAULT_EXCLUDED_ELEMENTS;
	}

	/**
	 * The stored element tree, or an empty array when there is none.
	 */
	private function tree( \WP_Post $post ): array {
		return self::stored_tree( $post->ID );
	}

	/**
	 * The same read by post ID, for the referenced-template walk: it follows
	 * references into posts it never receives a `WP_Post` for, and asking for
	 * one would be a query this does not need.
	 */
	private static function stored_tree( int $post_id ): array {
		$tree = get_post_meta( $post_id, self::META_CONTENT, true );

		return is_array( $tree ) ? $tree : array();
	}

	/**
	 * Fingerprint of every `template` element's referenced post, **transitively**.
	 *
	 * It has to follow a template into the templates *it* references, and until
	 * 0.52.0 it did not — it read the page's own elements and stopped. Measured
	 * on a real Bricks 2.3.12 install (B1 of the 0.50.0 external review, see
	 * docs/review-followup-plan.md): on a `page -> outer template -> inner
	 * template` chain, editing **only the inner template** changed the rendered
	 * document while this value stayed byte-identical, so the `.md` went on
	 * serving the old body for the full cache TTL and a conditional request was
	 * answered `304`. Nothing else invalidates it: saving the inner template
	 * clears that template's own cache entry, not the page's, and the page's
	 * `post_modified_gmt` never moves.
	 *
	 * The recursion is Bricks' own, not a precaution: `Element_Template::render()`
	 * loads the referenced template's `_bricks_page_content_2` and renders it
	 * through the `[bricks_template]` shortcode, which walks a nested `template`
	 * element exactly the same way.
	 *
	 * Same shape, and the same two jobs for `$seen`, as
	 * `MetadataBuilder::collect_pattern_refs()`: a template that references
	 * itself — directly or around a ring — would otherwise recurse forever, and
	 * a template used twice on one page is one dependency, not two.
	 *
	 * Deliberately unchanged in scope: a Bricks "component" (`cid`) reference is
	 * still not resolved. Its value moves the tree hash, so a *reassigned*
	 * component invalidates; a component's own definition changing elsewhere
	 * does not. That is the documented, narrow residual — it is NOT what this
	 * walk is about, and closing B1 does not close it.
	 */
	private static function referenced_template_fingerprint( array $tree ): string {
		$parts = array();
		$seen  = array();

		self::collect_template_refs( $tree, $seen, $parts, 0 );

		return empty( $parts ) ? '' : md5( implode( '|', $parts ) );
	}

	/**
	 * Appends a fingerprint part for every template this tree reaches, then
	 * walks into each one's own stored tree.
	 *
	 * A Bricks tree is a FLAT array of elements linked by `parent`/`children`,
	 * so one pass over `$tree` already sees every element of that post — the
	 * recursion here crosses posts, never nesting inside the array.
	 *
	 * A missing template records `missing` rather than being skipped: creating
	 * the referenced post later changes the rendered body, and a skipped
	 * reference would leave the validator where it was.
	 *
	 * @param array<int|string, mixed> $tree  Stored element tree.
	 * @param array<int, bool>         $seen  Template IDs already visited, by ID.
	 * @param array<int, string>       $parts Fingerprint parts, appended to.
	 * @param int                      $depth Current recursion depth.
	 */
	private static function collect_template_refs( array $tree, array &$seen, array &$parts, int $depth ): void {
		if ( $depth >= self::MAX_TEMPLATE_DEPTH ) {
			return;
		}

		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'template' !== $element['name'] ) {
				continue;
			}

			$template_id = isset( $element['settings']['template'] ) ? (int) $element['settings']['template'] : 0;

			if ( $template_id <= 0 || isset( $seen[ $template_id ] ) ) {
				continue;
			}

			$seen[ $template_id ] = true;

			$template = get_post( $template_id );

			if ( ! $template instanceof \WP_Post ) {
				$parts[] = $template_id . ':missing';
				continue;
			}

			$parts[] = $template_id . ':' . (string) $template->post_modified_gmt;

			self::collect_template_refs( self::stored_tree( $template_id ), $seen, $parts, $depth + 1 );
		}
	}

	/**
	 * Text-bearing leaves of the tree, each wrapped in a span carrying its
	 * element's class(es) **and its ancestors'**, joined with a single space.
	 *
	 * The ancestors are the whole point, and leaving them out was a real defect
	 * (R2 of the 0.50.0 external review). Bricks stores a flat element array
	 * with `parent`/`children` links, so a container marked `md-exclude` is a
	 * separate entry from the text element inside it. Wrapping each leaf in its
	 * OWN classes alone therefore produced a span the exclusion pass had nothing
	 * to match on: the body correctly dropped the excluded subtree while the
	 * front-matter `description` and the enriched `/llms.txt` entry kept its
	 * text. What the body excludes is excluded everywhere — the same rule the
	 * `description` fallback already owes `post_content`, one builder over.
	 *
	 * Concatenating the ancestors' tokens onto the leaf's own span is enough
	 * because `strip_excluded_content()` matches any element carrying an
	 * excluded class; rebuilding the real nesting with wrapper elements would
	 * cost more and decide nothing extra.
	 */
	private function leaves_markup( array $tree ): string {
		// Built once for the whole tree: resolving each leaf's ancestry by
		// re-scanning the array would be quadratic, and this runs once per
		// listed post while /llms.txt is assembled.
		$by_id = array();

		foreach ( $tree as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_scalar( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}

		$out = array();

		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || ! is_string( $element['name'] ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$text     = isset( $settings['text'] ) && is_string( $settings['text'] ) ? trim( wp_strip_all_tags( $settings['text'] ) ) : '';

			if ( '' === $text ) {
				continue;
			}

			$out[] = '<span class="' . esc_attr( self::lineage_classes( $element, $by_id ) ) . '">' . esc_html( $text ) . '</span>';
		}

		return implode( ' ', $out );
	}

	/**
	 * An element's own class tokens followed by every ancestor's, nearest first.
	 *
	 * Malformed ancestry is a stored-data question, not an invariant: a
	 * `parent` may name an element that no longer exists, and a hand-edited or
	 * partially migrated tree can contain a cycle. Both end the walk instead of
	 * looping — a `$seen` set for the cycle, and MAX_ANCESTOR_DEPTH as the
	 * backstop for anything the set cannot describe. A truncated lineage
	 * degrades to the previous behaviour for that one leaf; it never hangs.
	 *
	 * @param array<string, mixed>                $element The leaf element.
	 * @param array<string, array<string, mixed>> $by_id   Every element of the tree, keyed by id.
	 */
	private static function lineage_classes( array $element, array $by_id ): string {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$own      = self::element_class( $element['name'], $settings );
		$classes  = '' !== $own ? array( $own ) : array();

		$seen    = array();
		$current = $element;

		for ( $depth = 0; $depth < self::MAX_ANCESTOR_DEPTH; $depth++ ) {
			$parent_id = isset( $current['parent'] ) && is_scalar( $current['parent'] ) ? (string) $current['parent'] : '';

			// Bricks writes `0` for a root-level element.
			if ( '' === $parent_id || '0' === $parent_id
				|| ! isset( $by_id[ $parent_id ] ) || isset( $seen[ $parent_id ] ) ) {
				break;
			}

			$seen[ $parent_id ] = true;
			$current            = $by_id[ $parent_id ];

			$parent_settings = isset( $current['settings'] ) && is_array( $current['settings'] ) ? $current['settings'] : array();
			$parent_name     = isset( $current['name'] ) && is_string( $current['name'] ) ? $current['name'] : '';

			$ancestor = self::element_class( $parent_name, $parent_settings );

			if ( '' !== $ancestor ) {
				$classes[] = $ancestor;
			}
		}

		return implode( ' ', $classes );
	}

	/**
	 * The class token(s) this element would carry in a real Bricks render:
	 * `brxe-{name}` plus any custom class set on it (`settings._cssClasses`),
	 * which is where an author-applied `md-exclude` lives.
	 */
	private static function element_class( string $name, array $settings ): string {
		$slug = (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $name ) );

		// An ancestor read out of the tree may carry no usable name; emitting a
		// bare `brxe-` would be a token that matches nothing and reads as a bug.
		$class = '' !== $slug ? 'brxe-' . $slug : '';

		if ( isset( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] ) ) {
			$custom = trim( (string) preg_replace( '/[^A-Za-z0-9_ -]/', ' ', $settings['_cssClasses'] ) );

			if ( '' !== $custom ) {
				$class = '' !== $class ? $class . ' ' . $custom : $custom;
			}
		}

		return $class;
	}

	/**
	 * Whether the tree contains a `post-content` element — the only element
	 * that reaches into the `the_content` filter chain, so suppression is
	 * skipped entirely on the (more common) tree that has none.
	 */
	private static function tree_has_post_content_element( array $tree ): bool {
		foreach ( $tree as $element ) {
			if ( is_array( $element ) && isset( $element['name'] ) && self::CONTENT_ELEMENT === $element['name'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes every callback foreign to WordPress core from `the_content`
	 * for the duration of the render, and returns what to restore afterwards
	 * (null when nothing was changed).
	 *
	 * This is the recommendation from docs/page-builders-plan.md §6.2.7 /
	 * §10 open question 2, implemented rather than left open — but flagged
	 * there, and again here, as a maintainer-reversible design choice, not a
	 * settled one: it trades adapter complexity for consistency with
	 * "Technical notes" §4 in AGENTS.md (render_block() is used instead of
	 * the_content() everywhere else in this pipeline specifically to avoid
	 * injected related/CTA content). Verified live on `sma-bricks-instawp-co`:
	 * a foreign `the_content` callback appending a "SUBSCRIBE NOW" block is
	 * present in the Post Content element's output without this, and absent
	 * with it, while wpautop/do_shortcode still run either way.
	 *
	 * The snapshot MUST be a clone, not a bare property read: `$wp_filter`
	 * entries are WP_Hook objects, so `$previous = $wp_filter['the_content'];`
	 * copies the object handle, not its state — remove_all_filters() then
	 * empties the "snapshot" too, and the restore silently does nothing.
	 * Caught by testing this exact sequence before writing it here, not by
	 * reasoning about it (see AGENTS.md, "a guard is not done until it has
	 * been seen to fire").
	 *
	 * @return \WP_Hook|null
	 */
	private function maybe_suppress_content_filters( array $tree, \WP_Post $post ) {
		/**
		 * Filter: whether foreign `the_content` callbacks are suppressed while
		 * a builder adapter's "Post Content" style element renders (Bricks:
		 * the `post-content` element). Default on. Return false to accept
		 * whatever the_content produces for a real visitor, related/CTA
		 * content included — the maintainer's call, not a settled default.
		 *
		 * @param bool     $suppress Whether to suppress. Default true.
		 * @param \WP_Post $post     Post being rendered.
		 */
		if ( ! apply_filters( 'sysmda_markdown_builder_suppress_content_filters', true, $post ) ) {
			return null;
		}

		if ( ! self::tree_has_post_content_element( $tree ) ) {
			return null;
		}

		global $wp_filter;

		if ( ! isset( $wp_filter['the_content'] ) || ! $wp_filter['the_content'] instanceof \WP_Hook ) {
			return null;
		}

		$previous = clone $wp_filter['the_content'];

		remove_all_filters( 'the_content' );

		global $wp_embed;

		if ( is_object( $wp_embed ) ) {
			add_filter( 'the_content', array( $wp_embed, 'run_shortcode' ), 8 );
			add_filter( 'the_content', array( $wp_embed, 'autoembed' ), 8 );
		}

		add_filter( 'the_content', 'do_blocks', 9 );
		add_filter( 'the_content', 'wptexturize' );
		add_filter( 'the_content', 'convert_smilies', 20 );
		add_filter( 'the_content', 'wpautop' );
		add_filter( 'the_content', 'shortcode_unautop' );
		add_filter( 'the_content', 'prepend_attachment' );
		add_filter( 'the_content', 'wp_filter_content_tags', 12 );
		add_filter( 'the_content', 'do_shortcode', 11 );

		if ( function_exists( 'wp_replace_insecure_home_url' ) ) {
			add_filter( 'the_content', 'wp_replace_insecure_home_url' );
		}

		return $previous;
	}

	/**
	 * Restores whatever maybe_suppress_content_filters() saved, if anything.
	 *
	 * @param \WP_Hook|null $previous
	 */
	private function restore_content_filters( $previous ): void {
		if ( ! $previous instanceof \WP_Hook ) {
			return;
		}

		global $wp_filter;

		remove_all_filters( 'the_content' );
		$wp_filter['the_content'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the exact snapshot maybe_suppress_content_filters() cloned; there is no core API for reinstating a whole WP_Hook.
	}
}

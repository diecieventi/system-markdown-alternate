<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * A page builder that can render its own content: the plugin reads the
 * builder's tree only to decide, never to reimplement its rendering.
 *
 * Same shape as `MetadataBuilder::candidate_taxonomies()`, which builds a list
 * for the panel and never gates output: an adapter's `handles()`/`fingerprint()`/
 * `source_text()` all read the stored tree to answer a question, and only
 * `render()` calls into the vendor. The rejected alternative — mapping element
 * types to semantic HTML — is the "block-native Markdown engine" already
 * evaluated and rejected in AGENTS.md, with the added risk that an unmapped
 * element type disappears silently.
 *
 * `is_active()` and `handles()` are deliberately separate questions (see
 * BuilderDetector's rule 3, "the veto applies whether the plugin is active or
 * not"): an adapter additionally requires the vendor plugin to be active,
 * because with no renderer there is nothing to render and `post_content` is
 * then the correct answer — unlike the veto, which must hold even with the
 * builder deactivated.
 */
interface BuilderAdapter {

	/**
	 * Whether the vendor plugin/theme is loaded, i.e. whether render() can be
	 * called at all. Checked before handles(): with no renderer present,
	 * post_content is the correct answer regardless of the stored render mode.
	 */
	public function is_active(): bool;

	/**
	 * Whether this post's CURRENT render mode is this builder — never whether
	 * builder data merely exists on the post. A post switched back to "Render
	 * with WordPress" keeps its stored tree but must not be claimed: the
	 * ordinary pipeline is the correct answer for what a visitor actually sees.
	 */
	public function handles( \WP_Post $post ): bool;

	/**
	 * Renders the post through the vendor's own API and returns HTML ready for
	 * the same DOM pass (process_dom()) every other branch of
	 * ContentRenderer::render() goes through.
	 *
	 * Only called after is_active() && handles() both hold.
	 */
	public function render( \WP_Post $post ): string;

	/**
	 * Cache-validator inputs for this post's builder content: scalars only,
	 * folded into MetadataBuilder::dependencies_fingerprint() the same way a
	 * synced pattern or an ACF field already is. Must cover everything that can
	 * change the rendered Markdown without moving post_modified_gmt — a
	 * referenced global element or template chief among them.
	 *
	 * Empty when handles() is false: a post this adapter does not render
	 * contributes nothing to its own fingerprint.
	 *
	 * @return array<string,scalar>
	 */
	public function fingerprint( \WP_Post $post ): array;

	/**
	 * A cheap, unrendered approximation of this post's text, for the
	 * front-matter `description` fallback and for `/llms.txt` entries — both
	 * contexts where actually rendering through the vendor (potentially once
	 * per listed post) would be prohibitive.
	 *
	 * Deliberately crude: no semantic mapping, just the text-bearing settings
	 * of each element. May pick up a button label; that is an accepted
	 * trade-off for staying cheap. The excluded-class and excluded-builder-
	 * element rules still apply to whatever this returns — see
	 * ContentRenderer::strip_excluded_content().
	 */
	public function source_text( \WP_Post $post ): string;

	/**
	 * This builder's own suggested defaults for `sysmda_markdown_excluded_builder_elements`,
	 * expressed as the CSS class tokens the builder emits per element type
	 * (e.g. `brxe-form` for Bricks). Additive to whatever else contributes to
	 * that list, per the 0.40.0 rule: exclusions accumulate, they never replace.
	 *
	 * @return string[]
	 */
	public function element_selectors(): array;
}

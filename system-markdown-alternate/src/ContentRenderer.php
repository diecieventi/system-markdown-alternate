<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Produces clean HTML ready for Markdown conversion.
 */
class ContentRenderer {

	/** CSS classes that mark content for exclusion from Markdown. */
	const EXCLUDED_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/**
	 * Wrapper element used to parse the fragment in process_dom().
	 *
	 * Deliberately NOT a `div`: content carrying an unbalanced `</div>` (custom
	 * HTML blocks, migrated content, legacy column shortcodes) would close the
	 * wrapper early, and everything after it — being a sibling of the wrapper
	 * rather than a child — was silently dropped from the output. An element
	 * name no real content closes cannot be ended by accident.
	 */
	const ROOT_TAG = 'sysmda-root';

	/**
	 * Tags that may not be nested inside a `<p>`, so a `<figure>` containing one
	 * is never rewritten into a paragraph (see unwrap_figures()).
	 */
	const BLOCK_TAGS = array( 'table', 'pre', 'ul', 'ol', 'dl', 'blockquote', 'div', 'figure', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/** @var BlockCleaner */
	private $blocks;

	/** @var ShortcodeCleaner */
	private $shortcodes;

	/** @var BuilderAdapter[] */
	private $builder_adapters;

	/**
	 * @param BuilderAdapter[] $builder_adapters Page-builder adapters consulted
	 *                         before the has_blocks() branch (see render()).
	 *                         Empty by default: a site with no builder content
	 *                         pays nothing for this.
	 */
	public function __construct( BlockCleaner $blocks, ShortcodeCleaner $shortcodes, array $builder_adapters = array() ) {
		$this->blocks           = $blocks;
		$this->shortcodes       = $shortcodes;
		$this->builder_adapters = $builder_adapters;
	}

	/**
	 * @param \WP_Post $post Post to render.
	 * @return string HTML ready for conversion.
	 */
	public function render( \WP_Post $post ): string {
		/** Filters source content (extension point for ACF/custom content). */
		$content = (string) apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );

		// 1. Remove excluded shortcodes from the raw source (including inside blocks).
		$content = $this->shortcodes->strip( $content );

		$adapter = $this->matching_builder_adapter( $post );

		// 2. A page builder that claims this post renders through its own API;
		// otherwise the ordinary block/classic branches, exactly as before.
		// Deliberately NOT hung off sysmda_markdown_source_content: that hook
		// already ran above, and already-rendered builder HTML falling into the
		// classic branch would pick up wpautop() plus a second do_shortcode()
		// pass over content the builder already expanded.
		if ( null !== $adapter ) {
			$html = $adapter->render( $post );
		} elseif ( has_blocks( $content ) ) {
			$blocks = $this->blocks->clean( parse_blocks( $content ) );

			$html = '';
			foreach ( $blocks as $block ) {
				$html .= render_block( $block );
			}

			$html = $this->expand_shortcodes( $html );
		} else {
			// Classic content: skip the_content to avoid injected related content/CTAs.
			$html = wpautop( $this->expand_shortcodes( $content ) );
		}

		/**
		 * Filters HTML appended after the main content, on every render branch.
		 *
		 * This exists because appending is not the same operation as replacing
		 * the source, and only one of the two survives the adapter branch above.
		 * Content added through `sysmda_markdown_source_content` is discarded
		 * for a post a page-builder adapter claims — the adapter renders from
		 * the builder's own tree and never looks at `$content` — so anything
		 * that means "add this to the document" belongs here instead.
		 */
		$appended = (string) apply_filters( 'sysmda_markdown_appended_html', '', $post );

		if ( '' !== $appended ) {
			$html .= $this->render_appended( $appended );
		}

		// 3-5. DOM pass: normalize code blocks, remove excluded classes, absolutize URLs.
		// Deliberately after the append, so appended content is class-excluded and
		// absolutized by the same single pass rather than a second one of its own.
		$html = $this->process_dom( $html, (string) get_permalink( $post ) );

		/** Filters rendered clean HTML before conversion. */
		return (string) apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
	}

	/**
	 * Renders appended content with the same two branches the main path uses.
	 *
	 * Mirroring them is what makes moving a producer onto the appended seam
	 * free: block markup in an ACF field is still cleaned by `BlockCleaner` and
	 * still has its synced patterns expanded, which is the behaviour
	 * `collect_acf_dependencies()` walks the pattern references for. A helper
	 * that only ever ran `wpautop()` would silently drop that.
	 *
	 * **Freeform blocks are paragraph-wrapped individually.** `has_blocks()` is a
	 * substring test over the whole fragment, so ONE value carrying block markup
	 * sends every plain-text sibling down this branch too — where `parse_blocks()`
	 * wraps them in a freeform block (`blockName === null`) that `render_block()`
	 * returns verbatim. Core gets its paragraphs back through `do_blocks()`'s
	 * `wpautop` hook dance, which this pipeline skips by design, so without this
	 * the converter collapses the blank line between two text values and publishes
	 * them as one run-on line — the very merging `MetaFields::append()`'s
	 * separator exists to prevent, arriving through another door. Verified against
	 * real `parse_blocks()`: the text portion comes back as `blockName => null`
	 * with the newlines intact, and `wpautop()` on it yields the two paragraphs.
	 *
	 * No `process_dom()` here: the caller runs it once over the whole document.
	 */
	private function render_appended( string $html ): string {
		$html = $this->shortcodes->strip( $html );

		if ( has_blocks( $html ) ) {
			$blocks = $this->blocks->clean( parse_blocks( $html ) );

			$out = '';
			foreach ( $blocks as $block ) {
				$rendered = render_block( $block );
				$name     = isset( $block['blockName'] ) ? $block['blockName'] : null;

				$out .= ( null === $name ) ? wpautop( $rendered ) : $rendered;
			}

			return $this->expand_shortcodes( $out );
		}

		return wpautop( $this->expand_shortcodes( $html ) );
	}

	/**
	 * The page-builder adapters actually in effect: the constructor's list,
	 * passed through the same filter every consumer of the adapter list must
	 * go through, so none of them can drift from what render() itself uses.
	 *
	 * $post is optional because one caller — excluded_builder_elements(),
	 * computing the default exclusion selectors rather than deciding who
	 * renders a specific post — has no post in view. A site filtering the
	 * list by post is expected to treat a null $post as "no post-specific
	 * narrowing", the same way it would treat any other context-free caller.
	 *
	 * @return BuilderAdapter[]
	 */
	private function effective_builder_adapters( ?\WP_Post $post = null ): array {
		/**
		 * Filters the page-builder adapters consulted before the block/classic
		 * branches of the render pipeline, and consulted for the default
		 * page-builder exclusion selectors (see excluded_builder_elements()).
		 * Anchored to a stage of the current implementation — a future engine
		 * may not have "adapters" at all.
		 *
		 * @param BuilderAdapter[] $adapters Adapters, tried in order.
		 * @param \WP_Post|null    $post     Post being rendered, or null when
		 *                                    the caller has no specific post in
		 *                                    view (computing defaults).
		 */
		return (array) apply_filters( 'sysmda_markdown_builder_adapters', $this->builder_adapters, $post );
	}

	/**
	 * The page-builder adapter that renders this post, or null when none does.
	 *
	 * Both conditions matter and neither substitutes for the other (see
	 * BuilderAdapter): is_active() is required because with no renderer
	 * present post_content is the correct answer regardless of what handles()
	 * says, and handles() is required because a post merely storing a
	 * builder's data while rendering ordinary content (e.g. "Render with
	 * WordPress") must take the normal branches below.
	 */
	private function matching_builder_adapter( \WP_Post $post ): ?BuilderAdapter {
		foreach ( $this->effective_builder_adapters( $post ) as $adapter ) {
			if ( $adapter instanceof BuilderAdapter && $adapter->is_active() && $adapter->handles( $post ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Cache-validator inputs contributed by the page-builder adapter that
	 * renders this post, or an empty array when none does. Consumed by
	 * MetadataBuilder::dependencies_fingerprint(), the same way a synced
	 * pattern or an ACF field already is: this class holds the adapter list,
	 * MetadataBuilder holds the fingerprint scheme, and this is the seam
	 * between the two so neither has to duplicate the adapter lookup.
	 *
	 * @return string[]
	 */
	public function builder_dependency_parts( \WP_Post $post ): array {
		$adapter = $this->matching_builder_adapter( $post );

		if ( null === $adapter ) {
			return array();
		}

		$parts = array();

		foreach ( $adapter->fingerprint( $post ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = 'builder:' . $key . ':' . (string) $value;
			}
		}

		return $parts;
	}

	/**
	 * The matching adapter's cheap, unrendered text approximation, or '' when
	 * no adapter claims this post. See BuilderAdapter::source_text() and
	 * MetadataBuilder::description(), which calls this as the last-resort
	 * fallback, after Rank Math and the excerpt.
	 */
	public function builder_source_text( \WP_Post $post ): string {
		$adapter = $this->matching_builder_adapter( $post );

		return null !== $adapter ? $adapter->source_text( $post ) : '';
	}

	/**
	 * Whether a page-builder adapter renders this post. Lets a caller that
	 * derives text from stored data (MetadataBuilder::description()'s last
	 * fallback) know when `post_content` must NOT be trusted at all — a
	 * builder-rendered post can hold stale prose left over from before it was
	 * rebuilt, and summarising that would reproduce, in the description field,
	 * the exact "confidently wrong" failure the page-builder veto exists to
	 * prevent in the body (see AGENTS.md, "Product decisions").
	 */
	public function builder_handles( \WP_Post $post ): bool {
		return null !== $this->matching_builder_adapter( $post );
	}

	/**
	 * Processes an HTML fragment (for example an ACF WYSIWYG field) through the
	 * same pipeline as classic content: excluded shortcode removal,
	 * shortcode/wpautop processing, and a DOM pass (class exclusions, code
	 * normalization, and absolute URLs resolved against the post permalink).
	 */
	public function render_fragment( string $html, \WP_Post $post ): string {
		$html = $this->shortcodes->strip( $html );
		$html = wpautop( $this->expand_shortcodes( $html ) );

		return $this->process_dom( $html, (string) get_permalink( $post ) );
	}

	/**
	 * Expands shortcodes, leaving the inside of `<pre>` and `<code>` untouched.
	 *
	 * Both source branches go through here, and each had one half of the
	 * problem:
	 *
	 * - **Blocks were never expanded at all.** `render_block()` does not expand
	 *   shortcodes; on the front end that job belongs to `the_content`, which
	 *   this pipeline skips by design (see render()). So a shortcode typed into
	 *   a paragraph, a Custom HTML block or the core Shortcode block reached the
	 *   converter as literal text and was published as an escaped `\[tag\]`.
	 * - **Classic content expanded too much.** It has always called
	 *   `do_shortcode()`, which is a plain regex over the whole string with no
	 *   notion of markup: a code sample *showing* `[gallery]` was expanded like
	 *   the real thing, silently rewriting the sample into whatever the
	 *   shortcode renders.
	 *
	 * Masking the code regions before the expansion fixes the second for both
	 * branches at once — see CodeRegions, which owns that masking and is shared
	 * with the removal pass in `ShortcodeCleaner::strip()`, so the rule cannot
	 * be applied to one side of the pipeline and forgotten on the other.
	 */
	private function expand_shortcodes( string $html ): string {
		// No bracket, no shortcode: do_shortcode() short-circuits on the same
		// test, so this only skips the masking work.
		if ( false === strpos( $html, '[' ) ) {
			return $html;
		}

		return CodeRegions::protect( $html, 'do_shortcode' );
	}

	/**
	 * Removes the regions marked for exclusion from a fragment, running none of
	 * the rest of the pipeline.
	 *
	 * Exists for the `description` front-matter fallback, which derives its text
	 * from the post content directly rather than from the rendered body — so
	 * without this a section the body promises never to publish came straight
	 * back out in the front matter. Excluded shortcodes are already gone by the
	 * time this runs; the two exclusion rules left are applied here, and BOTH of
	 * them are needed:
	 *
	 * - **Block-level** (`BlockCleaner`), for block content. The first version
	 *   of this method skipped it, on the reasoning that a block excluded by
	 *   name is dynamic and contributes no text to the source anyway. That is
	 *   true of the names the plugin ships and false in general: "Excluded
	 *   blocks" is a settings-page field, so a site can exclude a *static*
	 *   block — a pullquote, a quote — whose text sits right there in the saved
	 *   markup. The same gap swallowed blocks excluded through
	 *   `attrs.className` when the saved inner HTML does not repeat the class
	 *   attribute. The body dropped them and the description published them.
	 * - **Element-level** (the DOM pass below), for a class on markup *inside* a
	 *   block, and for classic content, which has no blocks to walk.
	 *
	 * The block pass runs whenever the source has blocks, with no cheap
	 * pre-filter, and that is deliberate: any substring guard would have to be
	 * evaluated against this post's markup, and a synced pattern keeps its
	 * content in another post entirely — so a guard would go blind exactly where
	 * `BlockCleaner` follows the reference. Expanding those patterns also makes
	 * the description follow the body, which renders them. The cost is one
	 * `parse_blocks()` per fallback description, `/llms.txt` entries included;
	 * it is paid only when a post has neither an SEO description nor an excerpt.
	 *
	 * The DOM pass returns its input untouched when nothing matched, so content
	 * carrying no excluded class is never round-tripped through the DOM.
	 *
	 * Also carries the excluded-builder-element list (`brxe-form` and
	 * friends): `BuilderAdapter::source_text()` wraps each extracted text leaf
	 * in a span bearing that same class, precisely so this one pass — already
	 * the description fallback's exclusion mechanism — reaches builder chrome
	 * too, with no separate exclusion logic to keep in step.
	 */
	public function strip_excluded_content( string $html ): string {
		if ( has_blocks( $html ) ) {
			$html = serialize_blocks( $this->blocks->clean( parse_blocks( $html ) ) );
		}

		$classes = array_merge( $this->excluded_classes(), $this->excluded_builder_elements() );

		if ( ! $this->mentions_class( $html, $classes ) ) {
			return $html;
		}

		$dom = $this->load_fragment( $html );

		if ( null === $dom ) {
			return $html;
		}

		if ( 0 === $this->remove_excluded_nodes( $dom, $classes ) ) {
			return $html;
		}

		return $this->serialize_fragment( $dom );
	}

	/**
	 * Whether any excluded class name occurs anywhere in the string.
	 *
	 * A cheap substring test whose only job is to decide whether the DOM pass is
	 * worth running. A false positive costs one parse and nothing else: the
	 * class attribute itself is matched properly in remove_excluded_nodes().
	 *
	 * @param array $classes Excluded class names, already resolved.
	 */
	private function mentions_class( string $html, array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && '' !== $class && false !== strpos( $html, $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Single DOM pass for code blocks, excluded classes, and absolute URLs.
	 *
	 * @param string $base Base URL (post permalink) for resolving relative URLs.
	 */
	private function process_dom( string $html, string $base ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$dom = $this->load_fragment( $html );

		if ( null === $dom ) {
			return $html;
		}

		$excluded = array_merge( $this->excluded_classes(), $this->excluded_builder_elements() );
		$removed  = $this->remove_excluded_nodes( $dom, $excluded );
		$this->flatten_definition_lists( $dom );
		$this->flatten_disclosures( $dom );
		$this->promote_figcaptions( $dom );
		$this->link_embeds( $dom, $base );
		$this->name_empty_links( $dom );
		$this->unwrap_figures( $dom );
		$this->normalize_code_blocks( $dom );
		$this->absolutize_urls( $dom, $base );

		$out = $this->serialize_fragment( $dom );

		// Non-empty input that comes back empty means the parse went wrong, and
		// the unprocessed HTML is a better answer than nothing. Skipped when an
		// exclusion rule actually removed something: emptying the body is then
		// the intended outcome, and falling back would republish excluded content.
		if ( 0 === $removed && '' === trim( $out ) ) {
			return $html;
		}

		return $out;
	}

	/**
	 * Parses an HTML fragment into a document wrapped in ROOT_TAG.
	 *
	 * @return \DOMDocument|null Null when the wrapper did not survive the parse.
	 */
	private function load_fragment( string $html ): ?\DOMDocument {
		$previous = libxml_use_internal_errors( true );

		$dom     = new \DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<?xml encoding="UTF-8"?><' . self::ROOT_TAG . '>' . $html . '</' . self::ROOT_TAG . '>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom->getElementsByTagName( self::ROOT_TAG )->item( 0 ) instanceof \DOMElement ? $dom : null;
	}

	/**
	 * Serializes the children of the ROOT_TAG wrapper back to HTML.
	 */
	private function serialize_fragment( \DOMDocument $dom ): string {
		$root = $dom->getElementsByTagName( self::ROOT_TAG )->item( 0 );

		if ( ! $root instanceof \DOMElement ) {
			return '';
		}

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Filterable list of CSS classes whose content is excluded from Markdown.
	 *
	 * Resolved by the caller and passed down, so a pass that needs the list
	 * before deciding whether to parse at all does not run the filter twice.
	 *
	 * @return array
	 */
	private function excluded_classes(): array {
		/** Filters CSS classes whose elements are removed from Markdown output. */
		return (array) apply_filters( 'sysmda_markdown_excluded_classes', self::EXCLUDED_CLASSES );
	}

	/**
	 * Filterable list of page-builder chrome removed the same way an excluded
	 * CSS class is: a form, a nav menu, a share bar, a table of contents, a
	 * breadcrumb trail — the builder equivalent of `sysmda_markdown_excluded_classes`,
	 * kept as a separate filter because its defaults come from the builder
	 * adapters themselves (`BuilderAdapter::element_selectors()`) rather than a
	 * class constant, and because it applies to markup no shortcode or block
	 * name can identify: a builder renders its own form element as ordinary
	 * HTML, not a `[contact-form-7]` shortcode this pipeline could otherwise
	 * strip before rendering.
	 *
	 * Additive, like the other three exclusion lists (the 0.40.0 rule): a
	 * site's own entries join what the active adapters suggest, they do not
	 * replace it.
	 *
	 * Reads the SAME filtered adapter list matching_builder_adapter() does
	 * (via effective_builder_adapters()), not the constructor's raw list: a
	 * site adding an adapter through `sysmda_markdown_builder_adapters` gets
	 * that adapter's default selectors for free, and one removed through the
	 * same filter stops contributing its (now unreachable) defaults. Reading
	 * the raw list here would silently diverge from what actually renders.
	 *
	 * @return string[]
	 */
	private function excluded_builder_elements(): array {
		$defaults = array();

		foreach ( $this->effective_builder_adapters() as $adapter ) {
			if ( $adapter instanceof BuilderAdapter ) {
				$defaults = array_merge( $defaults, $adapter->element_selectors() );
			}
		}

		$defaults = array_values( array_unique( $defaults ) );

		/**
		 * Filters CSS class tokens identifying page-builder chrome (forms, nav
		 * menus, share bars, tables of contents, breadcrumbs) removed from
		 * Markdown output, the same way `sysmda_markdown_excluded_classes`
		 * removes any other excluded class. Defaults to what the active builder
		 * adapters suggest via `element_selectors()`.
		 *
		 * @param string[] $selectors CSS class tokens (e.g. `brxe-form`).
		 */
		return (array) apply_filters( 'sysmda_markdown_excluded_builder_elements', $defaults );
	}

	/**
	 * Removes DOM elements carrying an excluded class (including nested elements).
	 *
	 * @param array $excluded_classes Excluded class names, already resolved.
	 * @return int Number of removed elements.
	 */
	private function remove_excluded_nodes( \DOMDocument $dom, array $excluded_classes ): int {
		$xpath = new \DOMXPath( $dom );

		$removed = 0;

		foreach ( $excluded_classes as $class ) {
			$class = is_string( $class ) ? trim( $class ) : '';

			// The class is interpolated into an XPath expression, so anything but
			// a plain CSS token is rejected: a quote would make query() return
			// false and take the whole response down with a TypeError.
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $class ) ) {
				continue;
			}

			$query = sprintf(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
				$class
			);

			$nodes = $xpath->query( $query );

			if ( ! $nodes instanceof \DOMNodeList ) {
				continue;
			}

			foreach ( iterator_to_array( $nodes ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Flattens `<dl>` into paragraphs: the term in bold, each definition as its
	 * own paragraph.
	 *
	 * The converter has no definition-list support and `strip_tags` is on, so an
	 * untouched `<dl>` came out as its terms and definitions concatenated with no
	 * separator at all ("TermDefinition").
	 */
	private function flatten_definition_lists( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'dl' ) ) as $list ) {
			if ( ! $list->parentNode ) {
				continue;
			}

			$fragment = $dom->createDocumentFragment();

			foreach ( iterator_to_array( $list->childNodes ) as $child ) {
				if ( ! $child instanceof \DOMElement ) {
					continue;
				}

				$name = strtolower( $child->nodeName );

				if ( 'dt' !== $name && 'dd' !== $name ) {
					continue;
				}

				$paragraph = $dom->createElement( 'p' );
				$target    = $paragraph;

				if ( 'dt' === $name ) {
					$target = $dom->createElement( 'strong' );
					$paragraph->appendChild( $target );
				}

				foreach ( iterator_to_array( $child->childNodes ) as $inner ) {
					$target->appendChild( $inner );
				}

				$fragment->appendChild( $paragraph );
			}

			if ( ! $fragment->hasChildNodes() ) {
				$list->parentNode->removeChild( $list );
				continue;
			}

			$list->parentNode->replaceChild( $fragment, $list );
		}
	}

	/**
	 * Flattens `<details>`/`<summary>` into a bold lead-in paragraph followed by
	 * the disclosure body.
	 *
	 * The converter knows neither tag, and `strip_tags` is on, so `core/details`
	 * came out as its summary and body concatenated with nothing between them
	 * ("MoreHidden body"). The `<dt>` treatment in flatten_definition_lists() is
	 * the precedent: a label that introduces what follows becomes a bold
	 * paragraph, and the content it hid becomes ordinary content — a Markdown
	 * document has no collapsed state to represent, and hiding it from an agent
	 * would defeat the point of the representation.
	 */
	private function flatten_disclosures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'summary' ) ) as $summary ) {
			if ( ! $summary->parentNode ) {
				continue;
			}

			$paragraph = $dom->createElement( 'p' );
			$strong    = $dom->createElement( 'strong' );
			$paragraph->appendChild( $strong );

			foreach ( iterator_to_array( $summary->childNodes ) as $child ) {
				$strong->appendChild( $child );
			}

			if ( $strong->hasChildNodes() ) {
				$summary->parentNode->replaceChild( $paragraph, $summary );
				continue;
			}

			$summary->parentNode->removeChild( $summary );
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'details' ) ) as $details ) {
			if ( ! $details->parentNode ) {
				continue;
			}

			$fragment = $dom->createDocumentFragment();

			foreach ( iterator_to_array( $details->childNodes ) as $child ) {
				$fragment->appendChild( $child );
			}

			if ( ! $fragment->hasChildNodes() ) {
				$details->parentNode->removeChild( $details );
				continue;
			}

			$details->parentNode->replaceChild( $fragment, $details );
		}
	}

	/**
	 * Moves a `<figcaption>` out of its `<figure>` and turns it into the
	 * paragraph that follows it.
	 *
	 * `<figcaption>` is another tag the converter does not know, so with
	 * `strip_tags` on its text was emitted flush against whatever it captioned:
	 * a captioned image came out as `![Alt](url)My caption`, on one line, with
	 * the caption indistinguishable from the alt text. Promoting it to a sibling
	 * paragraph works for every captioned construct at once — images, tables and
	 * embeds all use the same element — and leaves the figure holding only the
	 * media, which is what unwrap_figures() below expects.
	 *
	 * Only direct children are promoted: a caption belonging to a nested figure
	 * stays with that figure and is handled when its own turn comes.
	 */
	private function promote_figcaptions( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure->parentNode ) {
				continue;
			}

			$anchor = $figure;

			foreach ( iterator_to_array( $figure->childNodes ) as $caption ) {
				if ( ! $caption instanceof \DOMElement || 'figcaption' !== strtolower( $caption->nodeName ) ) {
					continue;
				}

				$paragraph = $dom->createElement( 'p' );

				foreach ( iterator_to_array( $caption->childNodes ) as $child ) {
					$paragraph->appendChild( $child );
				}

				$figure->removeChild( $caption );

				if ( ! $paragraph->hasChildNodes() ) {
					continue;
				}

				$figure->parentNode->insertBefore( $paragraph, $anchor->nextSibling );
				$anchor = $paragraph;
			}
		}
	}

	/**
	 * Makes sure an embed leaves a usable address behind.
	 *
	 * `render_block()` returns the saved markup of a `core/embed` block, and
	 * what that markup holds depends on whether anything resolved the embed:
	 *
	 * - **The bare source URL**, which is what the block stores. Nothing
	 *   resolves it on this route — `$wp_embed->autoembed()` runs inside
	 *   `the_content`, which the pipeline skips by design (see render()) — so
	 *   the address reached the converter as loose text in a wrapper `<div>`.
	 * - **The provider's player**, when something did resolve it (a cached
	 *   oEmbed result, a plugin filtering `render_block`, an embed block from
	 *   another plugin). That shape used to disappear without a trace: `iframe`
	 *   is in the converter's `remove_nodes`, so where a video had been the
	 *   reader was left nothing at all — not even the address to fetch.
	 *
	 * The rule is not "replace the embed" but "keep the text and keep the
	 * address", which are only in tension if the pass insists on replacing the
	 * whole element:
	 *
	 * - the element says nothing but its URL (the stored address, an
	 *   iframe-only player, a fallback link with no text) → it becomes a
	 *   paragraph holding that link, which the library emits as an autolink
	 *   because the text equals the target;
	 * - it carries real text **and** a link that already names the resource (a
	 *   quoted tweet, a provider's fallback markup) → left alone; the converter
	 *   keeps links, so the address is in the document either way;
	 * - it carries real text and the address lives **only in the frame** → the
	 *   frame alone is replaced by the link, in place. Bailing out here would
	 *   lose the address to `remove_nodes` a step later, which is the defect
	 *   this pass exists to fix; replacing the whole element would discard the
	 *   text. Neither is necessary.
	 *
	 * The caption keeps its own paragraph — promote_figcaptions() has already
	 * moved it out of the figure, which is why this pass runs after it.
	 *
	 * Only `wp-block-embed` elements are rewritten. A bare `<iframe>` elsewhere
	 * in the content is not an embed block and is left to the converter, which
	 * still removes it: this pass resolves a known construct, it does not
	 * salvage arbitrary framed markup.
	 *
	 * @param string $base Base URL (post permalink) for resolving relative URLs.
	 */
	private function link_embeds( \DOMDocument $dom, string $base ): void {
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-embed ")]' );

		if ( ! $nodes instanceof \DOMNodeList ) {
			return;
		}

		foreach ( iterator_to_array( $nodes ) as $embed ) {
			if ( ! $embed instanceof \DOMElement || ! $embed->parentNode ) {
				continue;
			}

			$texts = $this->text_nodes( $xpath, $embed );
			$link  = $this->embed_candidate( $embed, 'a', 'href', $base );
			$frame = $this->embed_candidate( $embed, 'iframe', 'src', $base );

			// The stored address is the element's only text. Read per text node
			// rather than from `textContent`, which flattens the subtree and
			// glues neighbours together: a wrapper holding the URL followed by a
			// sibling paragraph reads as "https://example.com/v/1Note", a string
			// that passes for a URL and is not one.
			$stored = 1 === count( $texts ) ? $texts[0] : '';

			// Ordered by how close each candidate is to the resource itself: the
			// stored source URL, then a link in the provider's fallback markup
			// (which points at the original tweet, post or track), then the
			// player frame — its `src` is an embed endpoint, not the address a
			// reader would visit.
			if ( $this->is_http_url( $stored ) ) {
				$url        = $stored;
				$from_frame = false;
			} elseif ( null !== $link ) {
				$url        = $link['url'];
				$from_frame = false;
			} elseif ( null !== $frame ) {
				$url        = $frame['url'];
				$from_frame = true;
			} else {
				continue;
			}

			if ( array() === $texts || array( $url ) === $texts ) {
				$embed->parentNode->replaceChild( $this->url_paragraph( $dom, $url ), $embed );
				continue;
			}

			// Real text, and the address is not the frame's to lose: it is
			// already written into the document as a link or as the text itself.
			if ( null === $frame || ! $from_frame || ! $frame['node']->parentNode ) {
				continue;
			}

			$frame['node']->parentNode->replaceChild( $this->url_paragraph( $dom, $url ), $frame['node'] );
		}
	}

	/**
	 * The element's own text, one trimmed entry per non-empty text node.
	 *
	 * @return string[]
	 */
	private function text_nodes( \DOMXPath $xpath, \DOMElement $element ): array {
		$nodes = $xpath->query( './/text()', $element );

		if ( ! $nodes instanceof \DOMNodeList ) {
			return array();
		}

		$texts = array();

		foreach ( $nodes as $node ) {
			$text = trim( $node->textContent );

			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * First descendant of $embed whose $attribute resolves to an http(s) URL.
	 *
	 * Resolved against the permalink here rather than left to absolutize_urls():
	 * that pass runs later and only covers `a` and `img`, so a frame carrying a
	 * root-relative or protocol-relative `src` — ordinary output from a plugin
	 * building its own embed markup — would be rejected as a candidate and then
	 * removed with the rest of the frames, address and all.
	 *
	 * @return array{node: \DOMElement, url: string}|null
	 */
	private function embed_candidate( \DOMElement $embed, string $tag, string $attribute, string $base ): ?array {
		foreach ( $embed->getElementsByTagName( $tag ) as $node ) {
			$url = $this->embed_reference( $node->getAttribute( $attribute ), $base );

			if ( '' !== $url ) {
				return array(
					'node' => $node,
					'url'  => $url,
				);
			}
		}

		return null;
	}

	/**
	 * Resolves one embed reference, or an empty string when it is not usable.
	 *
	 * A protocol-relative reference survives absolutize() unchanged, which is
	 * right for a link a browser resolves against the page it sits in and
	 * useless in a document read anywhere else, so the permalink's scheme
	 * completes it here. Nothing else about absolutize() changes: the general
	 * rule for links in the body is a stable part of the output format.
	 */
	private function embed_reference( string $value, string $base ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = $this->absolutize( $value, $base );

		if ( 0 === strpos( $value, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			$value  = ( is_string( $scheme ) && '' !== $scheme ? $scheme : 'https' ) . ':' . $value;
		}

		return $this->is_http_url( $value ) ? $value : '';
	}

	/**
	 * A paragraph holding one link whose text is its target, which the library
	 * emits as an autolink.
	 *
	 * A paragraph rather than a bare anchor because it also replaces a frame
	 * *inside* an element, among ordinary inline siblings: emitted flush against
	 * them the autolink came out as "<https://…>Watch the video" on one line —
	 * the defect promote_figcaptions() exists to prevent for captions.
	 */
	private function url_paragraph( \DOMDocument $dom, string $url ): \DOMElement {
		$link = $dom->createElement( 'a' );
		$link->setAttribute( 'href', $url );
		$link->appendChild( $dom->createTextNode( $url ) );

		$paragraph = $dom->createElement( 'p' );
		$paragraph->appendChild( $link );

		return $paragraph;
	}

	/**
	 * Whether the value is a single absolute http(s) URL and nothing else.
	 */
	private function is_http_url( string $value ): bool {
		return 1 === preg_match( '#^https?://[^\s<>"\']+$#i', $value );
	}

	/**
	 * Gives an anchor that renders nothing the accessible name its markup declares.
	 *
	 * A card whose whole surface is clickable is usually built as an empty
	 * anchor positioned over it, with the title, image and summary as siblings
	 * rather than children — the "stretched link" idiom, which CSS frameworks
	 * document as a utility and link-preview and related-posts plugins emit by
	 * default. Nothing is lost in the conversion, but the link comes out as
	 * `[](url "Name")`: a link with no text at all, while the name the markup
	 * does carry ends up in a paragraph of its own further down. For a document
	 * read by an agent that severs the one association that matters — what the
	 * resource is, and where it lives.
	 *
	 * An anchor that renders nothing still has to name itself for anyone not
	 * looking at the layout, and HTML has exactly two places for that name, so
	 * the fix reads what the markup already declares instead of guessing from
	 * the surrounding structure. `aria-label` first: it is the mechanism meant
	 * for this, while `title` is a tooltip that merely happens to be readable.
	 *
	 * Deliberately narrow on three points:
	 *
	 * - **A declared name or nothing.** With neither attribute the markup says
	 *   nothing about the link, and synthesising one from the href would turn
	 *   decorative anchors — "#top", JS hooks, skip links — into visible URLs
	 *   in documents that read cleanly today. The degenerate `[](url)` stays.
	 * - **Emptiness is what the anchor renders, not whether it holds text.** An
	 *   anchor wrapping an image is named by that image's alt and already
	 *   converts correctly; one holding an empty `<span>` may be an icon drawn
	 *   in CSS. Only an anchor with no element children at all is claimed.
	 * - **Nothing is rewritten inside code.** A sample quoting this markup is
	 *   an author documenting it, and the name would land in the sample.
	 *
	 * The sibling title is left where it is: reattaching it to the anchor would
	 * mean guessing which of a card's elements is the title, which is exactly
	 * the structural guesswork this pass avoids. The name is duplicated as a
	 * result — once as the link text, once as the card's own heading — which is
	 * honest, and cheaper than being wrong.
	 *
	 * Runs after link_embeds(): that pass reads an embed's text nodes to decide
	 * what an embed says, and naming a fallback anchor first would answer that
	 * question for it.
	 */
	private function name_empty_links( \DOMDocument $dom ): void {
		$xpath = new \DOMXPath( $dom );

		// Leaf anchors outside code are selected here; whether one renders
		// anything is decided in PHP. XPath 1.0's normalize-space() knows only
		// XML whitespace, so an anchor holding `&nbsp;` — the placeholder a card
		// generator reaches for when a link needs a body it does not have — was
		// not selected at all, and came back out as the `[](url "Name")` this
		// pass exists to prevent.
		$nodes = $xpath->query( '//a[@href][not(*)][not(ancestor::pre)][not(ancestor::code)]' );

		if ( ! $nodes instanceof \DOMNodeList ) {
			return;
		}

		foreach ( iterator_to_array( $nodes ) as $link ) {
			if ( ! $link instanceof \DOMElement ) {
				continue;
			}

			if ( ! self::renders_nothing( $link->textContent ) ) {
				continue;
			}

			$name       = trim( $link->getAttribute( 'aria-label' ) );
			$from_title = false;

			if ( '' === $name ) {
				$name       = trim( $link->getAttribute( 'title' ) );
				$from_title = true;
			}

			if ( '' === $name ) {
				continue;
			}

			// The library emits `title` as a Markdown link title, so consuming it
			// as the text and leaving it in place would print it twice over:
			// `[The Name](url "The Name")`. An aria-label it never emits, so that
			// one stays; a title alongside it is genuinely something else.
			if ( $from_title ) {
				$link->removeAttribute( 'title' );
			}

			// Whitespace-only children are what "renders nothing" allows, and they
			// would otherwise be kept in front of the name.
			while ( $link->firstChild ) {
				$link->removeChild( $link->firstChild );
			}

			$link->appendChild( $dom->createTextNode( $name ) );
		}
	}

	/**
	 * Whether a text value puts nothing on the page.
	 *
	 * Wider than trim(), and deliberately: what makes an anchor "empty" is that
	 * a reader sees no link text, and the characters used to fill such an anchor
	 * are the invisible ones — a non-breaking space above all, then the
	 * zero-width family a templating layer leaves behind. Unicode separators are
	 * matched as a class (\p{Z} covers U+00A0 and the typographic spaces); the
	 * zero-width characters are listed one by one rather than swept up with
	 * \p{C}, which would also claim private-use code points — an icon font's
	 * glyph is invisible to this function but not to the reader.
	 */
	private static function renders_nothing( string $text ): bool {
		$stripped = preg_replace( '~[\s\p{Z}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]+~u', '', $text );

		// A malformed sequence makes preg_replace() return null; the text is then
		// not something this pass can reason about, so it is not claimed.
		return is_string( $stripped ) && '' === $stripped;
	}

	/**
	 * Replaces <figure> with <p>, which the library treats as standalone blocks,
	 * ensuring blank-line separation around images and captions.
	 *
	 * Figures holding a block-level element are left alone: a `<table>` (the
	 * core table block wraps one in a figure) or a `<pre>` inside a `<p>` is
	 * invalid nesting, and the paragraph brought no benefit there anyway.
	 */
	private function unwrap_figures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure->parentNode || $this->contains_block_element( $figure ) ) {
				continue;
			}

			$paragraph = $dom->createElement( 'p' );
			foreach ( iterator_to_array( $figure->childNodes ) as $child ) {
				$paragraph->appendChild( $child );
			}

			$figure->parentNode->replaceChild( $paragraph, $figure );
		}
	}

	/**
	 * Whether the element contains a descendant that cannot live inside a `<p>`.
	 */
	private function contains_block_element( \DOMElement $element ): bool {
		foreach ( $element->getElementsByTagName( '*' ) as $descendant ) {
			if ( in_array( strtolower( $descendant->nodeName ), self::BLOCK_TAGS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes code blocks: removes syntax-highlighting <span> elements while
	 * preserving text and setting the `language-*` class on <code>, so the library
	 * produces a fenced code block. Covers Code Block Pro and other highlighters.
	 */
	private function normalize_code_blocks( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'pre' ) ) as $pre ) {
			$language  = $this->detect_code_language( $pre );
			$code_text = $this->code_text( $pre );

			while ( $pre->firstChild ) {
				$pre->removeChild( $pre->firstChild );
			}

			$code = $dom->createElement( 'code' );
			if ( '' !== $language ) {
				$code->setAttribute( 'class', 'language-' . $language );
			}
			$code->appendChild( $dom->createTextNode( $code_text ) );
			$pre->appendChild( $code );
		}
	}

	/**
	 * Text content of a <pre>, restoring the line breaks when the markup carries
	 * none of its own.
	 *
	 * Highlighters that wrap every line in its own element and rely on CSS for
	 * the breaks (Shiki — and therefore Code Block Pro — emit
	 * `<span class="line">…</span><span class="line">…</span>`) have no newline
	 * anywhere in their text, so reading textContent flat collapsed the whole
	 * block onto a single line. Highlighters that do keep literal newlines
	 * (Prism, highlight.js) take the first branch and are untouched.
	 */
	private function code_text( \DOMElement $pre ): string {
		$text = $pre->textContent;

		if ( false !== strpos( $text, "\n" ) ) {
			return $text;
		}

		$lines = $this->line_elements( $pre );

		if ( count( $lines ) < 2 ) {
			return $text;
		}

		$out = array();
		foreach ( $lines as $line ) {
			$out[] = $line->textContent;
		}

		return implode( "\n", $out );
	}

	/**
	 * Per-line wrapper elements of a <pre>, or an empty array when its children
	 * are not a clean one-element-per-line structure.
	 *
	 * Conservative on purpose: anything unexpected (a stray text node, an element
	 * that does not look like a line wrapper) returns nothing, so code_text()
	 * keeps the previous flat behaviour rather than guessing at line boundaries.
	 *
	 * @return \DOMElement[]
	 */
	private function line_elements( \DOMElement $pre ): array {
		$container = $pre;

		foreach ( $pre->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'code' === strtolower( $child->nodeName ) ) {
				$container = $child;
				break;
			}
		}

		$lines = array();

		foreach ( $container->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				if ( '' !== trim( $child->textContent ) ) {
					return array(); // Mixed content: not a line-per-element block.
				}
				continue;
			}

			$is_line = self::has_line_class( $child )
				|| in_array( strtolower( $child->nodeName ), array( 'div', 'p' ), true );

			if ( ! $is_line ) {
				return array();
			}

			$lines[] = $child;
		}

		return $lines;
	}

	/**
	 * Whether an element carries a class that marks it as one rendered line.
	 *
	 * Matched per class token, with `line` as a whole hyphen/underscore-delimited
	 * word: `line`, `code-line`, `token-line`, `line-number` all qualify, while
	 * `inline-token`, `underline`, `baseline` and `outline` do not. A plain
	 * substring test accepted all of those, and since this decides where
	 * code_text() inserts newlines, an `inline-*` class on adjacent spans split
	 * one source line into several — silently rewriting the code.
	 */
	private static function has_line_class( \DOMElement $element ): bool {
		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $classes as $class ) {
			if ( 1 === preg_match( '/(?:^|[-_])line(?:[-_]|$)/i', $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detects the code language from classes (`language-*`, `lang-*`, `brush:`)
	 * and data attributes (`data-language`, `data-lang`) on <pre> or descendants.
	 */
	private function detect_code_language( \DOMElement $pre ): string {
		$elements = array_merge( array( $pre ), iterator_to_array( $pre->getElementsByTagName( '*' ) ) );

		foreach ( $elements as $el ) {
			$class = $el->getAttribute( 'class' );
			if ( $class && preg_match( '/(?:language|lang|brush:?)[-\s:]([a-z0-9#+]+)/i', $class, $m ) ) {
				return strtolower( $m[1] );
			}

			foreach ( array( 'data-language', 'data-lang' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$value = trim( $el->getAttribute( $attr ) );
					if ( '' !== $value ) {
						return strtolower( $value );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Converts link href and image src values to absolute URLs, resolving
	 * relative values against the post permalink ($base).
	 */
	private function absolutize_urls( \DOMDocument $dom, string $base ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( $href ) {
				$a->setAttribute( 'href', $this->absolutize( $href, $base ) );
			}
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( $src ) {
				$img->setAttribute( 'src', $this->absolutize( $src, $base ) );
			}
		}
	}

	/**
	 * Makes a URL absolute by resolving it against $base (the source permalink):
	 * - any absolute reference (a scheme is present) / protocol-relative /
	 *   fragment-only → unchanged;
	 * - query-only (?x) → against the base path itself (RFC 3986 §5.3);
	 * - root-relative (/x) → against the origin (scheme://host);
	 * - document-relative (x, ../x) → against the permalink directory.
	 */
	private function absolutize( string $url, string $base ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return $url;
		}

		// Fragment-only reference.
		if ( '#' === $url[0] ) {
			return $url;
		}

		// Protocol-relative.
		if ( 0 === strpos( $url, '//' ) ) {
			return $url;
		}

		// Any absolute reference. RFC 3986 §3.1 defines a scheme as
		// ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ) followed by ":", and scheme
		// names are case-insensitive. Matching the grammar rather than a list of
		// known prefixes covers http(s), mailto:, tel:, data: and everything else
		// real content links to — ftp:, sms:, whatsapp:, callto:, webcal: — none
		// of which may be resolved as a path.
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			// Unparseable base: fall back to the site origin.
			return home_url( '/' . ltrim( $url, '/' ) );
		}

		$port      = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$origin    = $parts['scheme'] . '://' . $parts['host'] . $port;
		$base_path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		// Query-only reference: the base path is kept as it is, not treated as a
		// directory (RFC 3986 §5.3). Resolving "?page=2" against "/blog/my-post"
		// as a directory would otherwise land on "/blog/?page=2".
		if ( '?' === $url[0] ) {
			return $origin . $base_path . $url;
		}

		// Root-relative: resolve against the origin.
		if ( '/' === $url[0] ) {
			return $origin . $this->resolve_dot_segments( $url );
		}

		// Document-relative: resolve against the permalink directory.
		$dir = ( '/' === substr( $base_path, -1 ) )
			? $base_path
			: (string) preg_replace( '#/[^/]*$#', '/', $base_path );

		return $origin . $this->resolve_dot_segments( $dir . $url );
	}

	/**
	 * Normalizes "." and ".." path segments while preserving the leading slash.
	 */
	private function resolve_dot_segments( string $path ): string {
		$out = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! empty( $out ) && '' !== end( $out ) ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $segment;
		}

		$result = implode( '/', $out );

		return '' === $result || '/' !== $result[0] ? '/' . ltrim( $result, '/' ) : $result;
	}
}

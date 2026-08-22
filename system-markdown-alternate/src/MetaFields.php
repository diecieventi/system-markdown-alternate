<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls values from configured post meta keys into the Markdown source.
 *
 * A page built from a template routinely mixes `post_content` with pieces held
 * elsewhere — an ACF field, a JetEngine dynamic field, a value typed into
 * WordPress's own Custom Fields box. Those are content the visitor sees, and
 * without this they reach no `.md` at all: the document carries `post_content`
 * and nothing else, so the article is published incomplete with nothing marking
 * the gap.
 *
 * **One generic mechanism, not one integration per plugin.** ACF, JetEngine,
 * Meta Box and the native Custom Fields UI all store ordinary post meta, so a
 * list of meta *keys* covers every one of them at once and costs nothing to a
 * site that never fills it in (empty by default, like the taxonomy selection).
 *
 * Explicit and opt-in, never auto-detected — the same discipline as the
 * custom-taxonomy selection: post meta is full of internal plumbing (cache
 * markers, internal IDs, serialized UI state) and no rule can tell which keys
 * are content. The owner decides.
 *
 * Values are appended to the END of the source, in the order the keys are
 * listed, and then travel the whole pipeline (exclusions, shortcodes, blocks,
 * DOM pass, absolute URLs) exactly like `post_content`. Appending is the honest
 * answer rather than a limitation to apologise for: the plugin cannot know
 * where in a template's layout a field is rendered, so a predictable position
 * beats a guessed one.
 */
class MetaFields {

	/**
	 * The configured meta values, as HTML appended to the document.
	 *
	 * Hook: sysmda_markdown_appended_html (priority 20, after AcfIntegration so
	 * ACF's own fields keep their existing position).
	 *
	 * Deliberately NOT `sysmda_markdown_source_content`, which is where this
	 * started and where it did not work: a post claimed by a page-builder
	 * adapter is rendered from the builder's own tree, so the filtered source is
	 * discarded and every configured value silently vanished from the document
	 * while still moving the cache validator. A Bricks page is precisely the
	 * "the template holds the content" case this feature exists for, so that was
	 * the motivating scenario failing. Appending is not replacing the source.
	 *
	 * @param string   $appended Current appended HTML.
	 * @param \WP_Post $post     Reference post.
	 * @return string Appended HTML with the configured meta values.
	 */
	public function appended_html( string $appended, \WP_Post $post ): string {
		$keys = self::keys( $post );

		if ( empty( $keys ) ) {
			return $appended;
		}

		$values = array();
		foreach ( $keys as $key ) {
			$values[] = $this->value( $key, $post );
		}

		return self::append( $appended, $values );
	}

	/**
	 * The configured meta keys, as a list of non-empty strings.
	 *
	 * @return string[]
	 */
	public static function keys( \WP_Post $post ): array {
		/**
		 * Filters the post meta keys whose values are pulled into the Markdown.
		 *
		 * Fed by the "Extra custom fields" panel field at priority 20. The list
		 * REPLACES rather than accumulates, unlike the three exclusion filters:
		 * this is a curated inclusion list with no built-in defaults, so the
		 * value supplied is the whole answer (same semantics as
		 * `sysmda_llms_txt_key_content`).
		 *
		 * @param string[] $keys Meta keys (default: none).
		 * @param \WP_Post $post Reference post.
		 */
		$keys = (array) apply_filters( 'sysmda_markdown_extra_meta_keys', array(), $post );

		$out = array();
		foreach ( $keys as $key ) {
			$key = trim( (string) $key );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Reads one meta value, preferring ACF's own formatting where it applies.
	 *
	 * With ACF active `get_field()` returns what ACF would render for a field it
	 * knows, which is the point of asking it first. For a key ACF has no field
	 * definition for it falls through to the stored value, so a JetEngine or
	 * native Custom Fields key is unaffected by whether ACF happens to be
	 * installed — verified against ACF 6.8.8, where the two functions returned
	 * identical values for an unregistered key, a protected (`_`-prefixed) key
	 * and a serialized one alike.
	 *
	 * They diverge on an ABSENT key (`''` from `get_post_meta()`, `null` from
	 * `get_field()`), which is why presence is never inferred from the value
	 * here or in the fingerprint — see `MetadataBuilder::collect_meta_dependencies()`.
	 *
	 * @return mixed The stored value, of whatever type.
	 */
	public function value( string $key, \WP_Post $post ) {
		if ( ! $this->acf_available() ) {
			return get_post_meta( $post->ID, $key, true );
		}

		$value = get_field( $key, $post->ID );

		// Measured on ACF 6.8.8: for a key ACF has no definition for, get_field()
		// returns the stored value, so this fallback does not fire. `null` is
		// what it returns for a key that is ABSENT — and what it would return for
		// an unregistered one were a future ACF to start refusing those. Reading
		// the row directly answers both and costs nothing.
		//
		// Keyed on strict null, never on falsiness: a registered true/false field
		// legitimately returns false, and falling back there would publish the
		// raw "0" that ACF meant to suppress.
		if ( null === $value ) {
			$value = get_post_meta( $post->ID, $key, true );
		}

		return $value;
	}

	/**
	 * Whether ACF's value API is available.
	 *
	 * A method rather than an inline `function_exists()` so the fallback branch
	 * is reachable from the test suite: a defined function cannot be undefined,
	 * so without this seam the `get_post_meta()` path could never be executed
	 * under test — and an untested branch in a two-way fork is where the wrong
	 * value silently comes from.
	 */
	protected function acf_available(): bool {
		return function_exists( 'get_field' );
	}

	/**
	 * Adds values to whatever is already appended, keeping them apart.
	 *
	 * The separator lives here rather than in each caller because there are two
	 * of them — this class and `AcfIntegration` — and with the `<div>` wrapper
	 * gone (see `emit()`) nothing else keeps one producer's last value from
	 * being glued to the next producer's first. One rule, one place: two copies
	 * of it would drift, and the symptom would be two fields silently merged
	 * into one paragraph.
	 *
	 * @param mixed[] $values Raw values, of any type.
	 */
	public static function append( string $appended, array $values ): string {
		$new = self::emit( $values );

		if ( '' === $new ) {
			return $appended;
		}

		return '' === $appended ? $new : $appended . "\n\n" . $new;
	}

	/**
	 * Wraps values for the source content, applying the two skip rules.
	 *
	 * Shared with `AcfIntegration::append_fields()` rather than written twice.
	 * The wrapping is trivial; the skip rules are not, and one of them is a bug
	 * waiting to be reintroduced — the emptiness test is an explicit
	 * `'' === trim( $value )` precisely because a falsy test would also drop the
	 * string `"0"`, a perfectly valid field value. Two copies of that reasoning
	 * would drift; one cannot. (Same argument as `CodeRegions`, which exists
	 * because a rule applied on one side of a pipeline and forgotten on the
	 * other shipped exactly that defect.)
	 *
	 * Non-strings are skipped: an array from a serialized value or a repeater
	 * has a structure this plugin has no brief to invent a rendering for, and
	 * guessing one would publish something confidently wrong.
	 *
	 * **Values are separated by a blank line and NOT wrapped in an element**
	 * (0.47.1). They used to be wrapped in a `<div>`, and that wrapper silently
	 * disabled Markdown escaping for every plain-text value: the conversion
	 * library escapes text nodes only when their parent is not a `div`, so a
	 * field containing `A *literal* marker` was published with the asterisks
	 * live and the reader saw one word in italics. Measured on staging, and the
	 * same defect the ACF subtitle had in 0.46.1 — a text value must reach the
	 * document as text.
	 *
	 * A blank line is what fixes it and what keeps the values apart: the caller
	 * hands the result to `wpautop()`, which wraps a bare value in a paragraph —
	 * where the library does escape — and leaves block markup from a WYSIWYG
	 * field alone. The `div` contributed nothing to the Markdown either way; the
	 * converter discards unknown wrappers, so removing it changes no output
	 * except the escaping this exists to restore.
	 *
	 * @param mixed[] $values Raw values, of any type.
	 * @return string HTML fragment, empty when nothing survived.
	 */
	public static function emit( array $values ): string {
		$parts = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			$parts[] = $value;
		}

		return implode( "\n\n", $parts );
	}
}

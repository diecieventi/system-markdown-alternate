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
	 * Appends configured meta values to the source content.
	 *
	 * Hook: sysmda_markdown_source_content (priority 20, after AcfIntegration so
	 * ACF's own fields keep their existing position).
	 *
	 * @param string   $content Current source content.
	 * @param \WP_Post $post    Reference post.
	 * @return string Content with the configured meta values appended.
	 */
	public function append_fields( string $content, \WP_Post $post ): string {
		$keys = self::keys( $post );

		if ( empty( $keys ) ) {
			return $content;
		}

		$values = array();
		foreach ( $keys as $key ) {
			$values[] = $this->value( $key, $post );
		}

		return $content . self::emit( $values );
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
		return $this->acf_available()
			? get_field( $key, $post->ID )
			: get_post_meta( $post->ID, $key, true );
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
	 * @param mixed[] $values Raw values, of any type.
	 * @return string HTML fragment, empty when nothing survived.
	 */
	public static function emit( array $values ): string {
		$out = '';

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			$out .= '<div>' . $value . '</div>';
		}

		return $out;
	}
}

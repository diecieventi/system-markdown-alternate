<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Shared eligibility rules for posts that expose a Markdown version.
 *
 * Single source of truth for the .md endpoint, content negotiation, the alternate
 * link, /llms.txt, the [sysmda_md_url], [sysmda_md_download] and
 * [sysmda_md_actions] shortcodes, and the {{sysmda_md_url}} dynamic tag.
 */
class PostSupport {

	/**
	 * Post types that are never servable, whatever the settings or the filter say.
	 *
	 * Media is always excluded (durable product decision): an attachment page is
	 * not editorial content and has no Markdown representation to offer.
	 */
	const NEVER_SERVABLE = array( 'attachment' );

	/**
	 * Post formats whose posts never expose a Markdown version.
	 *
	 * Every non-standard format: an aside, status, link or quote is a short
	 * snippet, usually untitled, that carries no editorial body worth serving as
	 * a document. Posts with the standard format (get_post_format() === false)
	 * are unaffected, which is the overwhelming majority of content.
	 */
	const EXCLUDED_POST_FORMATS = array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' );

	/**
	 * Supported post types (filterable). An empty list means the plugin is inactive.
	 *
	 * Memoized per blog: the value is keyed on the current blog ID so a
	 * switch_to_blog() loop (WP-CLI, network cron, a multisite aggregator) does
	 * not evaluate one site's posts against another site's settings.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		static $types = array();

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( ! isset( $types[ $blog_id ] ) ) {
			/** Filters post types that expose the .md endpoint and alternate link. */
			$types[ $blog_id ] = self::sanitize_types( (array) apply_filters( 'sysmda_markdown_supported_post_types', array() ) );
		}

		return $types[ $blog_id ];
	}

	/**
	 * Normalizes a supported-types list and drops the never-servable ones.
	 *
	 * The settings page already keeps `attachment` out of the saved option, but
	 * the filter is a public extension point: enforcing the rule here keeps it
	 * true for every consumer (.md endpoint, negotiation, alternate link,
	 * /llms.txt), not just for values coming from the panel.
	 *
	 * @param array $types Raw list, as returned by the filter.
	 * @return string[] Normalized list, without duplicates or excluded types.
	 */
	public static function sanitize_types( array $types ): array {
		$clean = array();

		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}

			$type = trim( $type );

			if ( '' === $type
				|| in_array( $type, self::NEVER_SERVABLE, true )
				|| in_array( $type, $clean, true ) ) {
				continue;
			}

			$clean[] = $type;
		}

		return $clean;
	}

	/**
	 * Whether the post exposes a .md representation: supported type, published,
	 * not password-protected, and not carrying an excluded post format.
	 *
	 * The password test reads `post_password` directly and deliberately NOT
	 * `post_password_required()`, which answers a different question: "does this
	 * visitor still have to supply it?". That returns false as soon as a valid
	 * `wp-postpass_*` cookie exists, so a reader who had entered the password
	 * once also unlocked the `.md`, the `rel="alternate"` link, the shortcode and
	 * the dynamic tag. The rule is "protected content has no Markdown
	 * representation at all" — see the decision in AGENTS.md — so having the
	 * password is irrelevant. This also makes the endpoint agree with
	 * `/llms.txt`, which has always filtered on `has_password => false`.
	 */
	public static function is_servable( \WP_Post $post ): bool {
		$servable = in_array( $post->post_type, self::supported_post_types(), true )
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& ! self::has_excluded_post_format( $post );

		if ( ! $servable ) {
			return false;
		}

		/**
		 * Filter: final veto on whether a post has a Markdown representation.
		 *
		 * The built-in rules understand WordPress's own notion of access —
		 * post status and the core password field — and nothing else. A
		 * membership, paywall or editorial plugin typically protects an
		 * otherwise published post from a `template_redirect` callback or a
		 * `the_content` filter, and neither reaches this endpoint: it runs at
		 * `template_redirect` priority 0 and exits, so later callbacks never
		 * run, and it renders cleaned blocks instead of `the_content` by
		 * design. This filter is how such a plugin denies a single post, and
		 * it is honoured by every consumer at once — the `.md` route,
		 * negotiation, the `rel="alternate"` link, `/llms.txt`, both
		 * shortcodes and the dynamic tag.
		 *
		 * **Veto only.** It is consulted just when the built-in rules already
		 * said yes, so returning `true` can never publish a draft, a
		 * password-protected post or a type the site has not enabled. Widening
		 * what is served is what `sysmda_markdown_supported_post_types` and
		 * `sysmda_markdown_excluded_post_formats` are for.
		 *
		 * On the every-request path, `304` responses included: keep it to
		 * values already in memory or cheap to read, and never do I/O here.
		 */
		return (bool) apply_filters( 'sysmda_post_is_servable', true, $post );
	}

	/**
	 * Whether a post type is currently registered as public.
	 *
	 * Applied to the **saved selection** only, by the AdminSettings callback that
	 * feeds it into `sysmda_markdown_supported_post_types` — never here, and
	 * never to the filter's result. The distinction is the whole point:
	 *
	 * - the saved option deliberately KEEPS a slug whose provider is temporarily
	 *   inactive, so that deactivating a plugin for an afternoon does not
	 *   silently turn the endpoint off for its content. That survival rule
	 *   shipped with a comment promising "the emission path validates the type
	 *   again", and nothing did: a type re-registered as `public => false`, or
	 *   replaced by an internal one of the same name, stayed fully servable and
	 *   `/llms.txt` kept advertising it. A stale saved slug is not a request;
	 * - site code adding a type through the filter IS a request, and an explicit
	 *   one. Enforcing the policy after the filter would silently overrule it
	 *   and contradict the filter's documented job of widening what is served.
	 *
	 * So the check sits between the two, where only the option passes.
	 */
	public static function type_is_public( string $type ): bool {
		$object = get_post_type_object( $type );

		return is_object( $object ) && ! empty( $object->public );
	}

	/**
	 * Whether the post carries a post format that is excluded from Markdown.
	 *
	 * Checked last in is_servable(): it is the only rule that may need a term
	 * lookup, and the cheaper checks usually settle the question first.
	 */
	public static function has_excluded_post_format( \WP_Post $post ): bool {
		/**
		 * Filters the post formats excluded from the Markdown representation.
		 *
		 * Defaults to every non-standard format. Return an empty array to serve
		 * them all again, or a shorter list to exclude only some. The standard
		 * format is never affected: it is the absence of a format, not a value.
		 *
		 * @param string[] $formats Excluded format slugs (without the `post-format-` prefix).
		 * @param \WP_Post $post    Post being evaluated.
		 */
		$excluded = (array) apply_filters( 'sysmda_markdown_excluded_post_formats', self::EXCLUDED_POST_FORMATS, $post );

		if ( empty( $excluded ) ) {
			return false;
		}

		$format = get_post_format( $post );

		// false (standard format) or a post type without format support.
		if ( ! is_string( $format ) || '' === $format ) {
			return false;
		}

		return in_array( $format, $excluded, true );
	}
}

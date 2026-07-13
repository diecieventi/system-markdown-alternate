<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility layer for LiteSpeed's page cache (and URL-keyed page caches
 * in general) on the negotiated permalink.
 *
 * Some LiteSpeed cache configurations key the cache by URL only and do not
 * honour `Vary: Accept`: once a variant is stored, it is served to every
 * client regardless of its Accept header. Observed in production: an
 * `Accept: text/markdown` request populated the cache with Markdown and the
 * cached Markdown was then served to HTML clients (and vice versa).
 *
 * Two complementary mitigations:
 * 1. mark_nocache(): the negotiated Markdown and 406 responses tell the page
 *    cache not to store them (`X-LiteSpeed-Cache-Control: no-cache`, the
 *    generic DONOTCACHEPAGE constant, and the LiteSpeed Cache plugin API), so
 *    a shared-URL cache can never be poisoned with the Markdown variant.
 * 2. Opt-in `.htaccess` rules (Advanced settings, `sysmda_litespeed_htaccess`
 *    option): requests that negotiate Markdown — or accept neither HTML nor a
 *    wildcard (the 406 case) — bypass the LiteSpeed cache entirely, so PHP
 *    performs the negotiation even when the HTML variant is already cached.
 *    The block is wrapped in `<IfModule LiteSpeed>`, so it is inert on Apache
 *    and ignored by nginx. Explicit `.md` URLs stay fully cacheable: they are
 *    their own cache key and always identify the Markdown representation.
 */
class LiteSpeedCompat {

	/** Marker used for the .htaccess block (BEGIN/END comments). */
	const MARKER = 'System Markdown Alternate';

	/**
	 * Whether the site runs on LiteSpeed (server signature or the LiteSpeed
	 * Cache plugin). Informational only: the .htaccess rules can be enabled
	 * regardless, because a proxy may hide the real server signature.
	 *
	 * @param string|null $server_software Value to test (for tests); null reads $_SERVER.
	 */
	public static function is_litespeed( ?string $server_software = null ): bool {
		if ( null === $server_software ) {
			if ( defined( 'LSCWP_V' ) ) {
				return true; // LiteSpeed Cache plugin active.
			}
			$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		return false !== stripos( $server_software, 'litespeed' );
	}

	/**
	 * Marks the current response as non-cacheable for page caches.
	 *
	 * Used on the negotiated Markdown and 406 responses (NOT on `.md` URLs,
	 * which are safe to cache per URL). Sent unconditionally: the LiteSpeed
	 * header is ignored by other servers, and DONOTCACHEPAGE protects any
	 * page-cache plugin that keys by URL only.
	 */
	public static function mark_nocache(): void {
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed Cache plugin API (no-op when the plugin is not active).
		do_action( 'litespeed_control_set_nocache', 'system-markdown-alternate: negotiated representation' );
	}

	/**
	 * The .htaccess rules (one line per entry, without BEGIN/END markers).
	 *
	 * `E=Cache-Control:no-cache` is the documented LiteSpeed directive to
	 * exclude a request from the page cache. The conditions only depend on the
	 * Accept header, so they keep working in every rewrite pass.
	 *
	 * Two separate rules: (1) any Accept mentioning Markdown reaches PHP,
	 * which evaluates the q-values; (2) an Accept allowing neither HTML nor a
	 * wildcard reaches PHP for the 406. A missing/empty Accept and wildcard
	 * accepts (`text/*` and the full wildcard) deliberately stay on the
	 * cached HTML: PHP would serve HTML for them anyway.
	 *
	 * @return string[]
	 */
	public static function htaccess_rules(): array {
		return array(
			'<IfModule LiteSpeed>',
			'RewriteEngine On',
			'# Requests that mention Markdown must reach WordPress,',
			'# which evaluates the q-values.',
			'RewriteCond %{HTTP:Accept} text/markdown [NC]',
			'RewriteRule ^ - [E=Cache-Control:no-cache]',
			'# Requests whose Accept allows neither HTML nor a wildcard',
			'# must reach WordPress so it can answer 406.',
			'RewriteCond %{HTTP:Accept} !^$',
			'RewriteCond %{HTTP:Accept} !text/html [NC]',
			'RewriteCond %{HTTP:Accept} !text/\* [NC]',
			'RewriteCond %{HTTP:Accept} !\*/\* [NC]',
			'RewriteRule ^ - [E=Cache-Control:no-cache]',
			'</IfModule>',
		);
	}

	/**
	 * Absolute path of the site .htaccess ('' when it cannot be determined).
	 */
	public static function htaccess_path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			if ( ! defined( 'ABSPATH' ) || ! file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				return '';
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = get_home_path();

		return is_string( $home ) && '' !== $home ? trailingslashit( $home ) . '.htaccess' : '';
	}

	/**
	 * Whether the marker block currently in .htaccess matches htaccess_rules()
	 * AND sits before the WordPress rewrite block.
	 *
	 * Position matters: the WordPress block ends every rewrite pass with [L]
	 * rules, so a block appended after it is never evaluated. Comment lines are
	 * ignored in the comparison (WordPress adds its own instruction comment
	 * inside marker blocks).
	 */
	public static function rules_present(): bool {
		if ( self::directives( self::current_rules() ) !== self::directives( self::htaccess_rules() ) ) {
			return false;
		}

		$path = self::htaccess_path();

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		return self::block_is_before_wordpress( (string) file_get_contents( $path ) );
	}

	/**
	 * Whether the marker block occurs before the `# BEGIN WordPress` block
	 * (or WordPress has no block at all) in an .htaccess contents string.
	 *
	 * Pure string logic (public so it can be tested in isolation).
	 */
	public static function block_is_before_wordpress( string $contents ): bool {
		$ours = strpos( $contents, '# BEGIN ' . self::MARKER );

		if ( false === $ours ) {
			return false;
		}

		$wp = strpos( $contents, '# BEGIN WordPress' );

		return false === $wp || $ours < $wp;
	}

	/**
	 * Directive lines only: comments and blank lines removed.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function directives( array $lines ): array {
		$out = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && '#' !== $line[0] ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Whether .htaccess can be written (or created) by PHP.
	 */
	public static function htaccess_writable(): bool {
		$path = self::htaccess_path();

		if ( '' === $path ) {
			return false;
		}

		return file_exists( $path ) ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) );
	}

	/**
	 * Aligns .htaccess with the option: writes the block when enabled (and
	 * missing or outdated), removes it when disabled. Purges the LiteSpeed
	 * cache after a change so stale mixed variants disappear immediately.
	 *
	 * @param bool $enabled Desired state (the `sysmda_litespeed_htaccess` option).
	 * @return bool Whether .htaccess now matches the desired state.
	 */
	public static function sync( bool $enabled ): bool {
		$path = self::htaccess_path();

		if ( '' === $path ) {
			return false;
		}

		if ( $enabled ) {
			if ( self::rules_present() ) {
				return true;
			}

			if ( ! self::htaccess_writable() || ( file_exists( $path ) && ! is_readable( $path ) ) ) {
				return false;
			}

			$contents = file_exists( $path ) ? (string) file_get_contents( $path ) : '';
			$written  = false !== file_put_contents( $path, self::prepend_rules( $contents ) );

			if ( $written ) {
				self::purge_litespeed_cache();
			}
			return $written;
		}

		if ( ! self::markers_exist( $path ) ) {
			return true;
		}

		$removed = self::remove_rules();
		if ( $removed ) {
			self::purge_litespeed_cache();
		}
		return $removed;
	}

	/**
	 * Removes the marker block from .htaccess (markers included, unlike
	 * insert_with_markers with an empty list, which leaves empty markers behind).
	 */
	public static function remove_rules(): bool {
		$path = self::htaccess_path();

		if ( '' === $path || ! file_exists( $path ) ) {
			return true; // Nothing to remove.
		}

		if ( ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
			return false;
		}

		$contents = (string) file_get_contents( $path );
		$stripped = self::strip_rules( $contents );

		if ( $stripped === $contents ) {
			return true;
		}

		return false !== file_put_contents( $path, $stripped );
	}

	/**
	 * Returns the .htaccess contents with the marker block at the TOP of the
	 * file (any previous copy removed first).
	 *
	 * The block must precede `# BEGIN WordPress`: the WordPress block ends
	 * every rewrite pass with an [L] rule (`RewriteRule . /index.php [L]` in
	 * the first pass, `RewriteRule ^index\.php$ - [L]` in the second), so
	 * anything appended after it is never evaluated. Verified live: the block
	 * written at the bottom by insert_with_markers had no effect.
	 *
	 * Pure string logic (public so it can be tested in isolation).
	 */
	public static function prepend_rules( string $contents ): string {
		$block = '# BEGIN ' . self::MARKER . "\n"
			. implode( "\n", self::htaccess_rules() ) . "\n"
			. '# END ' . self::MARKER . "\n";

		$rest = ltrim( self::strip_rules( $contents ), "\n" );

		return $block . ( '' === $rest ? '' : "\n" . $rest );
	}

	/**
	 * Removes the marker block from an .htaccess contents string.
	 *
	 * Pure string logic (public so it can be tested in isolation).
	 */
	public static function strip_rules( string $contents ): string {
		$marker  = preg_quote( self::MARKER, '/' );
		$pattern = '/\n?# BEGIN ' . $marker . '.*?# END ' . $marker . '[^\n]*\n?/s';

		$stripped = preg_replace( $pattern, "\n", $contents, -1, $count );

		if ( null === $stripped || 0 === $count ) {
			return $contents; // Block not found: leave the file byte-for-byte intact.
		}

		// When the block sat at the very top of the file the "\n" replacement,
		// plus any blank line that already followed the block, leaves blank
		// lines at the start. Leading newlines are never meaningful in
		// .htaccess, so drop them (a mid-file removal never starts with one).
		return ltrim( $stripped, "\n" );
	}

	/**
	 * Lines currently inside the marker block (empty array when absent).
	 *
	 * @return string[]
	 */
	private static function current_rules(): array {
		$path = self::htaccess_path();

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		if ( ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return array_values( (array) extract_from_markers( $path, self::MARKER ) );
	}

	/**
	 * Whether the BEGIN marker exists at all (even with an empty block).
	 */
	private static function markers_exist( string $path ): bool {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		return false !== strpos( (string) file_get_contents( $path ), '# BEGIN ' . self::MARKER );
	}

	/**
	 * Purge-all through the LiteSpeed Cache plugin API (no-op when inactive).
	 */
	private static function purge_litespeed_cache(): void {
		do_action( 'litespeed_purge_all' );
	}
}

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
 *    These LiteSpeed-specific signals complement the standard, server-agnostic
 *    `Cache-Control` no-cache header that MarkdownController sends on the same
 *    responses (see MarkdownController::send_no_cache_headers()).
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
	 * Marks the current response as non-cacheable for page caches
	 * (LiteSpeed-specific signals).
	 *
	 * Used on the negotiated Markdown and 406 responses (NOT on `.md` URLs,
	 * which are safe to cache per URL). Sent unconditionally: the LiteSpeed
	 * header is ignored by other servers, and DONOTCACHEPAGE protects any
	 * page-cache plugin that keys by URL only. The standard `Cache-Control`
	 * no-cache header is sent by MarkdownController::send_no_cache_headers(),
	 * which calls this method.
	 */
	public static function mark_nocache(): void {
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- De-facto standard constant read by page-cache plugins; prefixing it would defeat its purpose.
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed Cache plugin API (no-op when the plugin is not active).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by the LiteSpeed Cache plugin; we invoke its API, we do not define it.
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

		return self::block_is_before_wordpress( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; see write().
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

			$contents = file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; WP_Filesystem would need credentials this page load does not have.
			$written  = self::write( $path, self::prepend_rules( $contents ) );

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

		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; see write().
		$stripped = self::strip_rules( $contents );

		if ( $stripped === $contents ) {
			return true;
		}

		return self::write( $path, $stripped );
	}

	/**
	 * Replaces .htaccess atomically: the new content goes to a private temporary
	 * file in the same directory, which is then renamed over the target.
	 *
	 * This is the one file whose corruption takes the whole site down with a 500,
	 * and sync() runs on every load of the settings page, so a concurrent load must
	 * never be able to observe — or produce — a half-written .htaccess. rename()
	 * within a directory is atomic, so any reader (Apache included) sees either the
	 * old file or the new one, complete.
	 *
	 * The temporary name is unique per attempt on purpose: a fixed one would let two
	 * concurrent writers truncate each other's buffer before either renamed, which
	 * is precisely the corruption this exists to prevent. What this does NOT
	 * serialize is the surrounding read-modify-write — two writers can still race
	 * and the last rename wins — but both candidate contents are complete and
	 * derived from the same stored option, so the result is always a valid file. A
	 * lock file would close that last gap at the cost of leaving a stray file in the
	 * site root: not worth it for an idempotent write.
	 *
	 * WP_Filesystem is deliberately not used: it may require FTP/SSH credentials the
	 * user has not supplied, which would make the sync fail silently on exactly the
	 * hosts that need it. Direct writes are guarded by htaccess_writable() instead.
	 *
	 * A one-time `.htaccess.sysmda-bak` snapshot is kept the first time the file is
	 * touched, so a bad state is recoverable without FTP access.
	 */
	private static function write( string $path, string $contents ): bool {
		$backup = $path . '.sysmda-bak';

		if ( file_exists( $path ) && ! file_exists( $backup ) ) {
			@copy( $path, $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort: a missing backup must not block the write.
		}

		$temp = $path . '.sysmda-tmp-' . wp_generate_password( 8, false );

		// 'x' fails instead of clobbering, so a name collision can never make two
		// writers share one buffer.
		$handle = fopen( $temp, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- See the docblock: WP_Filesystem may need credentials.

		if ( false === $handle ) {
			return false;
		}

		$written = false !== fwrite( $handle, $contents ) && fflush( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- The atomic replace is the point: WP_Filesystem::move() cannot guarantee it and may need credentials.
		if ( ! $written || ! rename( $temp, $path ) ) {
			wp_delete_file( $temp );
			return false;
		}

		return true;
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

		return false !== strpos( (string) file_get_contents( $path ), '# BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; see write().
	}

	/**
	 * Purge-all through the LiteSpeed Cache plugin API (no-op when inactive).
	 *
	 * Public: also fired on plugin activation/deactivation (see the bootstrap
	 * file), because entries cached before activation carry no `Vary` and can
	 * produce ghost mixed-representation behaviour that is hard to diagnose.
	 */
	public static function purge_litespeed_cache(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by the LiteSpeed Cache plugin; we invoke its API, we do not define it.
		do_action( 'litespeed_purge_all' );
	}
}

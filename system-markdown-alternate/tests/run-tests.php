<?php
/**
 * Local tests for pure plugin logic, without WordPress or PHPUnit.
 *
 * Usage:  php tests/run-tests.php
 *
 * Covers independently testable classes (AcceptNegotiator, BlockCleaner,
 * MetadataBuilder::markdown_url/description) through minimal stubs of the used WordPress functions.
 * Exits with code 1 when at least one assertion fails.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

// ─── Environment ────────────────────────────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// ─── WordPress stubs (only what the tested classes need) ─────────────

$GLOBALS['sysmda_test_posts']   = array(); // id → WP_Post
$GLOBALS['sysmda_test_parsed']  = array(); // content → blocks
$GLOBALS['sysmda_test_options'] = array(); // option → value
$GLOBALS['sysmda_test_meta']    = array(); // post ID => meta key => value

/** Stub: filters return the default value. */
function apply_filters( $tag, $value ) {
	return $value;
}

function get_post( $id = null ) {
	return isset( $GLOBALS['sysmda_test_posts'][ $id ] ) ? $GLOBALS['sysmda_test_posts'][ $id ] : null;
}

function parse_blocks( $content ) {
	return isset( $GLOBALS['sysmda_test_parsed'][ $content ] ) ? $GLOBALS['sysmda_test_parsed'][ $content ] : array();
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sysmda_test_options'] ) ? $GLOBALS['sysmda_test_options'][ $name ] : $default;
}

function get_permalink( $post ) {
	return $post->permalink;
}

function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['sysmda_test_meta'][ $post_id ][ $key ] )
		? $GLOBALS['sysmda_test_meta'][ $post_id ][ $key ]
		: ( $single ? '' : array() );
}

function has_excerpt( $post ) {
	return '' !== trim( (string) $post->post_excerpt );
}

function get_the_excerpt( $post ) {
	return $post->post_excerpt;
}

function strip_shortcodes( $content ) {
	return $content;
}

function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	return strip_tags( $text );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/' );
}

function add_query_arg( $key, $value, $url ) {
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $sep . $key . '=' . $value;
}

function get_shortcode_regex( $tags = null ) {
	// Simplified core regex, sufficient for the tested tags.
	$tagregexp = implode( '|', array_map( 'preg_quote', (array) $tags ) );
	return '(\\[)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}

/** Minimal WP_Post stub (in the global namespace, as in WordPress). */
class WP_Post {
	public $ID           = 0;
	public $post_type    = 'post';
	public $post_status  = 'publish';
	public $post_content = '';
	public $post_excerpt = '';
	public $permalink    = '';

	public function __construct( array $props = array() ) {
		foreach ( $props as $k => $v ) {
			$this->$k = $v;
		}
	}
}

// ─── Classes under test ───────────────────────────────────────────────────────

require __DIR__ . '/../src/AcceptNegotiator.php';
require __DIR__ . '/../src/ShortcodeCleaner.php';
require __DIR__ . '/../src/BlockCleaner.php';
require __DIR__ . '/../src/MetadataBuilder.php';
require __DIR__ . '/../src/LlmsTxtController.php';
require __DIR__ . '/../src/MarkdownController.php';
require __DIR__ . '/../src/LiteSpeedCompat.php';
require __DIR__ . '/../src/HitCounter.php';

use Diecieventi\SystemMarkdownAlternate\AcceptNegotiator;
use Diecieventi\SystemMarkdownAlternate\BlockCleaner;
use Diecieventi\SystemMarkdownAlternate\HitCounter;
use Diecieventi\SystemMarkdownAlternate\LiteSpeedCompat;
use Diecieventi\SystemMarkdownAlternate\LlmsTxtController;
use Diecieventi\SystemMarkdownAlternate\MarkdownController;
use Diecieventi\SystemMarkdownAlternate\MetadataBuilder;
use Diecieventi\SystemMarkdownAlternate\ShortcodeCleaner;

// ─── Micro-framework ─────────────────────────────────────────────────────────

$GLOBALS['sysmda_failures'] = 0;
$GLOBALS['sysmda_asserts']  = 0;

function check( $label, $expected, $actual ) {
	++$GLOBALS['sysmda_asserts'];
	if ( $expected === $actual ) {
		return;
	}
	++$GLOBALS['sysmda_failures'];
	echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

// ─── AcceptNegotiator ────────────────────────────────────────────────────────

// parse: default q, clamping, duplicates at maximum q, malformed ranges ignored.
check( 'parse: q default 1.0', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html' ) );
check( 'parse: explicit q', array( 'text/html' => 0.5 ), AcceptNegotiator::parse( 'text/html;q=0.5' ) );
check( 'parse: clamp to [0,1]', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html;q=7' ) );
check( 'parse: non-numeric q => 1.0', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html;q=abc' ) );
check( 'parse: duplicate => maximum q', array( 'text/html' => 0.9 ), AcceptNegotiator::parse( 'text/html;q=0.2, text/html;q=0.9' ) );
check( 'parse: missing slash ignored', array(), AcceptNegotiator::parse( 'html, json' ) );
check( 'parse: empty', array(), AcceptNegotiator::parse( '' ) );
check( 'parse: case-insensitive', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'TEXT/HTML' ) );

// quality: specificity: exact match > type/* > */*.
$accept = 'text/markdown;q=0.9, text/*;q=0.5, */*;q=0.1';
check( 'quality: exact match', 0.9, AcceptNegotiator::quality( $accept, 'text/markdown' ) );
check( 'quality: subtype wildcard', 0.5, AcceptNegotiator::quality( $accept, 'text/html' ) );
check( 'quality: full wildcard', 0.1, AcceptNegotiator::quality( $accept, 'image/png' ) );
check( 'quality: unaccepted type', 0.0, AcceptNegotiator::quality( 'text/html', 'application/json' ) );
check( 'quality: explicit q=0', 0.0, AcceptNegotiator::quality( 'text/html;q=0', 'text/html' ) );

// explicit_quality: no wildcard fallback.
check( 'explicit: listed', 0.9, AcceptNegotiator::explicit_quality( $accept, 'text/markdown' ) );
check( 'explicit: covered only by wildcard => null', null, AcceptNegotiator::explicit_quality( '*/*', 'text/markdown' ) );
check( 'explicit: absent => null', null, AcceptNegotiator::explicit_quality( 'text/html', 'text/markdown' ) );

// Key negotiation scenario: Accept "*/*" (curl) does NOT prefer Markdown.
check( 'negotiation: curl */* remains HTML', null, AcceptNegotiator::explicit_quality( '*/*', 'text/markdown' ) );
// Scenario: explicit Markdown with the same q as HTML → served (md >= html).
check(
	'negotiation: equal q => Markdown',
	true,
	AcceptNegotiator::explicit_quality( 'text/markdown, text/html', 'text/markdown' )
		>= AcceptNegotiator::quality( 'text/markdown, text/html', 'text/html' )
);

// ─── BlockCleaner ────────────────────────────────────────────────────────────

/** ShortcodeCleaner that changes nothing: expansion is tested separately. */
class PassthroughShortcodeCleaner extends ShortcodeCleaner {
	public function strip( string $content ): string {
		return $content;
	}
}

function make_block( $name, $inner_blocks = array(), $attrs = array() ) {
	$inner_content = array();
	foreach ( $inner_blocks as $ib ) {
		$inner_content[] = null;
	}
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner_blocks,
		'innerContent' => $inner_content,
		'innerHTML'    => '',
	);
}

$cleaner = new BlockCleaner( new PassthroughShortcodeCleaner() );

// Exclusion by blockName.
$out = $cleaner->clean( array( make_block( 'core/paragraph' ), make_block( 'gravityforms/form' ) ) );
check( 'blocks: form excluded', 1, count( $out ) );
check( 'blocks: paragraph preserved', 'core/paragraph', $out[0]['blockName'] );

// Exclusion by className, including nested blocks, with innerContent realignment.
$group = make_block(
	'core/group',
	array(
		make_block( 'core/paragraph' ),
		make_block( 'core/paragraph', array(), array( 'className' => 'x md-exclude y' ) ),
		make_block( 'core/paragraph' ),
	)
);
$out = $cleaner->clean( array( $group ) );
check( 'blocks: inner block excluded by class', 2, count( $out[0]['innerBlocks'] ) );
check( 'blocks: innerContent realigned', 2, count( array_filter( $out[0]['innerContent'], 'is_null' ) ) );

// Preserve freeform content (null blockName).
$out = $cleaner->clean( array( array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerContent' => array( '<p>x</p>' ), 'innerHTML' => '<p>x</p>' ) ) );
check( 'blocks: freeform preserved', 1, count( $out ) );

// core/block expansion: referenced content is cleaned.
$GLOBALS['sysmda_test_posts'][10] = new WP_Post(
	array(
		'ID'           => 10,
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_content' => 'PATTERN_A',
	)
);
$GLOBALS['sysmda_test_parsed']['PATTERN_A'] = array( make_block( 'core/paragraph' ), make_block( 'wpforms/form-selector' ) );

$out = $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 10 ) ) ) );
check( 'reusable: expanded and cleaned', 1, count( $out ) );
check( 'reusable: paragraph remains', 'core/paragraph', $out[0]['blockName'] );

// core/block pointing to a draft or missing post → discarded.
$GLOBALS['sysmda_test_posts'][11] = new WP_Post( array( 'ID' => 11, 'post_type' => 'wp_block', 'post_status' => 'draft', 'post_content' => 'X' ) );
check( 'reusable: draft discarded', array(), $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 11 ) ) ) ) );
check( 'reusable: nonexistent ref discarded', array(), $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 999 ) ) ) ) );

// Recursion guard: a pattern that references itself.
$GLOBALS['sysmda_test_posts'][12] = new WP_Post( array( 'ID' => 12, 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => 'PATTERN_SELF' ) );
$GLOBALS['sysmda_test_parsed']['PATTERN_SELF'] = array( make_block( 'core/paragraph' ), make_block( 'core/block', array(), array( 'ref' => 12 ) ) );
$out = $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 12 ) ) ) );
check( 'reusable: cycle stopped', 1, count( $out ) );

// Nested expansion inside a wrapper: placeholders are multiplied.
$GLOBALS['sysmda_test_parsed']['PATTERN_A2'] = array( make_block( 'core/paragraph' ), make_block( 'core/paragraph' ) );
$GLOBALS['sysmda_test_posts'][13] = new WP_Post( array( 'ID' => 13, 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => 'PATTERN_A2' ) );
$out = $cleaner->clean( array( make_block( 'core/group', array( make_block( 'core/block', array(), array( 'ref' => 13 ) ) ) ) ) );
check( 'nested reusable: 2 innerBlocks', 2, count( $out[0]['innerBlocks'] ) );
check( 'nested reusable: 2 placeholders', 2, count( $out[0]['innerContent'] ) );

// ─── MetadataBuilder::markdown_url ───────────────────────────────────────────

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';

$p = new WP_Post( array( 'permalink' => 'https://example.com/my-post/' ) );
check( 'url: pretty with trailing slash', 'https://example.com/my-post.md', MetadataBuilder::markdown_url( $p ) );

$p = new WP_Post( array( 'permalink' => 'https://example.com/blog/my-post' ) );
check( 'url: pretty without trailing slash', 'https://example.com/blog/my-post.md', MetadataBuilder::markdown_url( $p ) );

$p = new WP_Post( array( 'permalink' => 'https://example.com:8080/my-post/' ) );
check( 'url: port preserved', 'https://example.com:8080/my-post.md', MetadataBuilder::markdown_url( $p ) );

// Plain permalink: fall back to ?format=markdown.
$GLOBALS['sysmda_test_options']['permalink_structure'] = '';
$p = new WP_Post( array( 'permalink' => 'https://example.com/?p=123' ) );
check( 'url: plain → format=markdown', 'https://example.com/?p=123&format=markdown', MetadataBuilder::markdown_url( $p ) );

// Pretty structure but permalink with query (for example ?page_id): same fallback.
$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';
$p = new WP_Post( array( 'permalink' => 'https://example.com/?page_id=2' ) );
check( 'url: query string → format=markdown', 'https://example.com/?page_id=2&format=markdown', MetadataBuilder::markdown_url( $p ) );

// Homepage (path "/") → fallback, no /index.md.
$p = new WP_Post( array( 'permalink' => 'https://example.com/' ) );
check( 'url: homepage → format=markdown', 'https://example.com/?format=markdown', MetadataBuilder::markdown_url( $p ) );

// ─── MetadataBuilder::description ─────────────────────────────────────

$metadata = new MetadataBuilder( new ShortcodeCleaner() );

$p = new WP_Post(
	array(
		'ID'           => 20,
		'post_content' => '<p>Cookie Policy</p><SCRIPT type="text/javascript">(function (w,d) { var loader = d.createElement("script"); })(window, document);</SCRIPT><p>Final text.</p>',
	)
);
check( 'description: script content removed', 'Cookie Policy Final text.', $metadata->description( $p ) );

$p = new WP_Post(
	array(
		'ID'           => 21,
		'post_content' => '<p>Introduction</p><style media="screen">.banner { display: none; }</style><iframe src="https://example.com/embed">Embedded fallback content</iframe><p>Conclusion</p>',
	)
);
check( 'description: style and iframe content removed', 'Introduction Conclusion', $metadata->description( $p ) );

// ─── LlmsTxtController: line escaping ─────────────────────────────────

// escape_link_text: escape characters that would break [text](url).
check( 'llms: simple link text', 'Hello world', LlmsTxtController::escape_link_text( 'Hello world' ) );
check( 'llms: square brackets', 'Title \\[draft\\]', LlmsTxtController::escape_link_text( 'Title [draft]' ) );
check( 'llms: parentheses', 'Guide \\(2024\\)', LlmsTxtController::escape_link_text( 'Guide (2024)' ) );
check( 'llms: backslash escaped once', 'a\\\\b', LlmsTxtController::escape_link_text( 'a\\b' ) );
check( 'llms: newline => single line', 'Line one Line two', LlmsTxtController::escape_link_text( "Line one\nLine two" ) );
check( 'llms: control characters removed', 'A B', LlmsTxtController::escape_link_text( "A\t\x00B" ) );
check( 'llms: whitespace collapsed and trimmed', 'X Y', LlmsTxtController::escape_link_text( "  X   Y  " ) );

// normalize_inline: single line only, no bracket escaping (description).
check( 'llms: multiline description => single line', 'One two three', LlmsTxtController::normalize_inline( "One\ntwo\r\nthree" ) );
check( 'llms: description brackets preserved', 'see [1] and (2)', LlmsTxtController::normalize_inline( 'see [1] and (2)' ) );

// lastmod_suffix: `(updated: YYYY-MM-DD)` suffix for index entries.
check( 'llms: lastmod valid date', '(updated: 2026-07-01)', LlmsTxtController::lastmod_suffix( '2026-07-01 08:30:00' ) );
check( 'llms: lastmod date only', '(updated: 2024-12-31)', LlmsTxtController::lastmod_suffix( '2024-12-31' ) );
check( 'llms: lastmod empty date', '', LlmsTxtController::lastmod_suffix( '' ) );
check( 'llms: lastmod zero date', '', LlmsTxtController::lastmod_suffix( '0000-00-00 00:00:00' ) );
check( 'llms: lastmod invalid string', '', LlmsTxtController::lastmod_suffix( 'not-a-date' ) );

// ─── MarkdownController::etag_matches ────────────────────────────────────────

check( 'etag: wildcard *', true, MarkdownController::etag_matches( '*', '"abc"' ) );
check( 'etag: exact match', true, MarkdownController::etag_matches( '"abc"', '"abc"' ) );
check( 'etag: no match', false, MarkdownController::etag_matches( '"xyz"', '"abc"' ) );
check( 'etag: list containing match', true, MarkdownController::etag_matches( '"xyz", "abc"', '"abc"' ) );
check( 'etag: weak W/ prefix', true, MarkdownController::etag_matches( 'W/"abc"', '"abc"' ) );
check( 'etag: empty header', false, MarkdownController::etag_matches( '', '"abc"' ) );

// ─── LiteSpeedCompat ─────────────────────────────────────────────────────────

// is_litespeed: case-insensitive signature match on the given string.
check( 'litespeed: LiteSpeed signature', true, LiteSpeedCompat::is_litespeed( 'LiteSpeed' ) );
check( 'litespeed: lowercase signature', true, LiteSpeedCompat::is_litespeed( 'litespeed/6.3 (Enterprise)' ) );
check( 'litespeed: Apache is not LiteSpeed', false, LiteSpeedCompat::is_litespeed( 'Apache/2.4.62' ) );
check( 'litespeed: nginx is not LiteSpeed', false, LiteSpeedCompat::is_litespeed( 'nginx/1.27.0' ) );
check( 'litespeed: empty signature', false, LiteSpeedCompat::is_litespeed( '' ) );

// htaccess_rules: guarded by <IfModule LiteSpeed>, bypasses on Markdown
// negotiation and on Accept headers without HTML or a wildcard.
$sysmda_ls_rules = LiteSpeedCompat::htaccess_rules();
check( 'litespeed rules: IfModule guard opens', '<IfModule LiteSpeed>', $sysmda_ls_rules[0] );
check( 'litespeed rules: IfModule guard closes', '</IfModule>', $sysmda_ls_rules[ count( $sysmda_ls_rules ) - 1 ] );
check( 'litespeed rules: markdown condition', true, in_array( 'RewriteCond %{HTTP:Accept} text/markdown [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: empty Accept stays cached', true, in_array( 'RewriteCond %{HTTP:Accept} !^$', $sysmda_ls_rules, true ) );
check( 'litespeed rules: no text/html condition', true, in_array( 'RewriteCond %{HTTP:Accept} !text/html [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: no text/* condition', true, in_array( 'RewriteCond %{HTTP:Accept} !text/\* [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: no */* condition', true, in_array( 'RewriteCond %{HTTP:Accept} !\*/\* [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: no-cache env', 2, count( array_keys( $sysmda_ls_rules, 'RewriteRule ^ - [E=Cache-Control:no-cache]', true ) ) );

// A manual block with the SAME directives but different comments/indentation
// must be recognized as equivalent (directive-only comparison in sync).
$sysmda_ls_manual = array(
	'<IfModule LiteSpeed>',
	'    RewriteEngine On',
	'',
	'    # Le richieste che citano Markdown devono arrivare a WordPress.',
	'    RewriteCond %{HTTP:Accept} text/markdown [NC]',
	'    RewriteRule ^ - [E=Cache-Control:no-cache]',
	'',
	'    RewriteCond %{HTTP:Accept} !^$',
	'    RewriteCond %{HTTP:Accept} !text/html [NC]',
	'    RewriteCond %{HTTP:Accept} !text/\* [NC]',
	'    RewriteCond %{HTTP:Accept} !\*/\* [NC]',
	'    RewriteRule ^ - [E=Cache-Control:no-cache]',
	'</IfModule>',
);
$sysmda_directives = function ( array $lines ): array {
	$out = array();
	foreach ( $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' !== $line && '#' !== $line[0] ) {
			$out[] = $line;
		}
	}
	return $out;
};
check( 'litespeed rules: manual block with same directives is equivalent', $sysmda_directives( LiteSpeedCompat::htaccess_rules() ), $sysmda_directives( $sysmda_ls_manual ) );

// strip_rules: removes the whole marker block, leaves the rest untouched.
$sysmda_ls_block = "# BEGIN System Markdown Alternate\n<IfModule LiteSpeed>\nRewriteRule .* - [E=Cache-Control:no-cache]\n</IfModule>\n# END System Markdown Alternate";
check(
	'litespeed strip: block removed, neighbours preserved',
	"# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n",
	LiteSpeedCompat::strip_rules( "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n" . $sysmda_ls_block . "\n" )
);
check( 'litespeed strip: no block => unchanged', "# BEGIN WordPress\n# END WordPress\n", LiteSpeedCompat::strip_rules( "# BEGIN WordPress\n# END WordPress\n" ) );
check( 'litespeed strip: block-only file => empty, no leading blank', '', LiteSpeedCompat::strip_rules( $sysmda_ls_block ) );

// Block at the top followed by a blank line and other content: removal must
// not leave leading blank lines (regression: two blank lines at the top).
check(
	'litespeed strip: top block leaves no leading blank lines',
	"<IfModule mod_headers.c>\nHeader set X 1\n</IfModule>\n",
	LiteSpeedCompat::strip_rules( $sysmda_ls_block . "\n\n<IfModule mod_headers.c>\nHeader set X 1\n</IfModule>\n" )
);
check( 'litespeed strip: other markers untouched', "# BEGIN Other Plugin\nfoo\n# END Other Plugin\n", LiteSpeedCompat::strip_rules( "# BEGIN Other Plugin\nfoo\n# END Other Plugin\n" ) );

// prepend_rules: the block must land at the TOP (before # BEGIN WordPress,
// whose [L] rules would otherwise stop rewrite processing before our rules).
$sysmda_ls_expected_block = "# BEGIN System Markdown Alternate\n" . implode( "\n", LiteSpeedCompat::htaccess_rules() ) . "\n# END System Markdown Alternate\n";
$sysmda_wp_block          = "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";

check( 'litespeed prepend: empty file', $sysmda_ls_expected_block, LiteSpeedCompat::prepend_rules( '' ) );
check(
	'litespeed prepend: block goes before WordPress',
	$sysmda_ls_expected_block . "\n" . $sysmda_wp_block,
	LiteSpeedCompat::prepend_rules( $sysmda_wp_block )
);
check(
	'litespeed prepend: bottom copy moved to top, single copy',
	$sysmda_ls_expected_block . "\n" . $sysmda_wp_block,
	LiteSpeedCompat::prepend_rules( $sysmda_wp_block . $sysmda_ls_block . "\n" )
);
check(
	'litespeed prepend: idempotent',
	LiteSpeedCompat::prepend_rules( $sysmda_wp_block ),
	LiteSpeedCompat::prepend_rules( LiteSpeedCompat::prepend_rules( $sysmda_wp_block ) )
);

// block_is_before_wordpress: position check used by rules_present().
check( 'litespeed position: before WordPress', true, LiteSpeedCompat::block_is_before_wordpress( $sysmda_ls_expected_block . "\n" . $sysmda_wp_block ) );
check( 'litespeed position: after WordPress', false, LiteSpeedCompat::block_is_before_wordpress( $sysmda_wp_block . $sysmda_ls_block . "\n" ) );
check( 'litespeed position: no WordPress block', true, LiteSpeedCompat::block_is_before_wordpress( $sysmda_ls_expected_block ) );
check( 'litespeed position: block absent', false, LiteSpeedCompat::block_is_before_wordpress( $sysmda_wp_block ) );

// ─── HitCounter ──────────────────────────────────────────────────────────────

// is_bot: an empty/missing UA is a bot (every browser sends one).
check( 'hits is_bot: null UA', true, HitCounter::is_bot( null ) );
check( 'hits is_bot: empty UA', true, HitCounter::is_bot( '' ) );
check( 'hits is_bot: whitespace UA', true, HitCounter::is_bot( '   ' ) );

// is_bot: real browser UAs are human.
check( 'hits is_bot: Chrome', false, HitCounter::is_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' ) );
check( 'hits is_bot: Firefox', false, HitCounter::is_bot( 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0' ) );
check( 'hits is_bot: Safari iPhone', false, HitCounter::is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' ) );
check( 'hits is_bot: Edge', false, HitCounter::is_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0' ) );

// is_bot: crawlers, HTTP clients and AI agents (case-insensitive substring).
check( 'hits is_bot: Googlebot', true, HitCounter::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
check( 'hits is_bot: curl', true, HitCounter::is_bot( 'curl/8.5.0' ) );
check( 'hits is_bot: wget', true, HitCounter::is_bot( 'Wget/1.21.4' ) );
check( 'hits is_bot: python-requests', true, HitCounter::is_bot( 'python-requests/2.32.0' ) );
check( 'hits is_bot: Go http client', true, HitCounter::is_bot( 'Go-http-client/2.0' ) );
check( 'hits is_bot: headless Chrome', true, HitCounter::is_bot( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36' ) );
check( 'hits is_bot: GPTBot', true, HitCounter::is_bot( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot' ) );
check( 'hits is_bot: ClaudeBot', true, HitCounter::is_bot( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' ) );
check( 'hits is_bot: PerplexityBot case-insensitive', true, HitCounter::is_bot( 'MOZILLA/5.0 (COMPATIBLE; PERPLEXITYBOT/1.0)' ) );

// prune: buckets older than the retention window (90 days) are dropped.
$sysmda_hits = array(
	'2026-07-16' => array( 'bot' => 1, 'human' => 2 ),           // Today: kept.
	'2026-04-17' => array( 'bot' => 3, 'human' => 0 ),           // 90 days old: kept (cutoff is exclusive).
	'2026-04-16' => array( 'bot' => 4, 'human' => 4 ),           // 91 days old: dropped.
	'2025-01-01' => array( 'bot' => 9, 'human' => 9 ),           // Ancient: dropped.
	'not-a-date' => array( 'bot' => 1, 'human' => 1 ),           // Malformed key: dropped.
);
$sysmda_pruned = HitCounter::prune( $sysmda_hits, '2026-07-16' );
check( 'hits prune: surviving buckets', array( '2026-07-16', '2026-04-17' ), array_keys( $sysmda_pruned ) );
check( 'hits prune: counters untouched', array( 'bot' => 1, 'human' => 2 ), $sysmda_pruned['2026-07-16'] );
check( 'hits prune: empty input', array(), HitCounter::prune( array(), '2026-07-16' ) );

// totals: window includes today, excludes older buckets and future/malformed keys.
$sysmda_hits = array(
	'2026-07-16' => array( 'bot' => 1, 'human' => 2 ),  // Today.
	'2026-07-10' => array( 'bot' => 10, 'human' => 20 ), // 6 days ago: inside "last 7".
	'2026-07-09' => array( 'bot' => 100, 'human' => 200 ), // 7 days ago: outside "last 7", inside "last 30".
	'2026-06-17' => array( 'bot' => 1000, 'human' => 2000 ), // 29 days ago: inside "last 30".
	'2026-06-16' => array( 'bot' => 5000, 'human' => 5000 ), // 30 days ago: outside "last 30".
	'2026-08-01' => array( 'bot' => 7, 'human' => 7 ),   // Future (clock skew): ignored.
);
check( 'hits totals: today only', array( 'bot' => 1, 'human' => 2 ), HitCounter::totals( $sysmda_hits, '2026-07-16', 1 ) );
check( 'hits totals: last 7 days', array( 'bot' => 11, 'human' => 22 ), HitCounter::totals( $sysmda_hits, '2026-07-16', 7 ) );
check( 'hits totals: last 30 days', array( 'bot' => 1111, 'human' => 2222 ), HitCounter::totals( $sysmda_hits, '2026-07-16', 30 ) );
check( 'hits totals: zero-day window', array( 'bot' => 0, 'human' => 0 ), HitCounter::totals( $sysmda_hits, '2026-07-16', 0 ) );

// ─── Result ───────────────────────────────────────────────────────────────────

echo "\n{$GLOBALS['sysmda_asserts']} assertions, {$GLOBALS['sysmda_failures']} failed.\n";
exit( $GLOBALS['sysmda_failures'] > 0 ? 1 : 0 );

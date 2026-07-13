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

use Diecieventi\SystemMarkdownAlternate\AcceptNegotiator;
use Diecieventi\SystemMarkdownAlternate\BlockCleaner;
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
check( 'litespeed rules: markdown condition', true, in_array( 'RewriteCond %{HTTP:Accept} text/markdown [NC,OR]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: non-HTML condition', true, in_array( 'RewriteCond %{HTTP:Accept} !(text/html|\*/\*) [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: no-cache env', true, in_array( 'RewriteRule .* - [E=Cache-Control:no-cache]', $sysmda_ls_rules, true ) );

// strip_rules: removes the whole marker block, leaves the rest untouched.
$sysmda_ls_block = "# BEGIN System Markdown Alternate\n<IfModule LiteSpeed>\nRewriteRule .* - [E=Cache-Control:no-cache]\n</IfModule>\n# END System Markdown Alternate";
check(
	'litespeed strip: block removed, neighbours preserved',
	"# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n",
	LiteSpeedCompat::strip_rules( "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n" . $sysmda_ls_block . "\n" )
);
check( 'litespeed strip: no block => unchanged', "# BEGIN WordPress\n# END WordPress\n", LiteSpeedCompat::strip_rules( "# BEGIN WordPress\n# END WordPress\n" ) );
check( 'litespeed strip: block without trailing newline', "\n", LiteSpeedCompat::strip_rules( $sysmda_ls_block ) );
check( 'litespeed strip: other markers untouched', "# BEGIN Other Plugin\nfoo\n# END Other Plugin\n", LiteSpeedCompat::strip_rules( "# BEGIN Other Plugin\nfoo\n# END Other Plugin\n" ) );

// ─── Result ───────────────────────────────────────────────────────────────────

echo "\n{$GLOBALS['sysmda_asserts']} assertions, {$GLOBALS['sysmda_failures']} failed.\n";
exit( $GLOBALS['sysmda_failures'] > 0 ? 1 : 0 );

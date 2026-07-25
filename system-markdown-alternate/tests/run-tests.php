<?php
/**
 * Local tests for pure plugin logic, without WordPress or PHPUnit.
 *
 * Usage:  php tests/run-tests.php
 *
 * Covers independently testable classes (AcceptNegotiator, BlockCleaner,
 * MetadataBuilder::markdown_url/description/build_front_matter) through minimal
 * stubs of the used WordPress functions.
 * Exits with code 1 when at least one assertion fails.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

// ─── Environment ────────────────────────────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SYSMDA_VERSION', '0.0.0-test' ); // Only used by the cache-validator tests.

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// ─── WordPress stubs (only what the tested classes need) ─────────────

$GLOBALS['sysmda_test_posts']       = array(); // id → WP_Post
$GLOBALS['sysmda_test_parsed']      = array(); // content → blocks
$GLOBALS['sysmda_test_options']     = array(); // option → value
$GLOBALS['sysmda_test_meta']        = array(); // post ID => meta key => value
$GLOBALS['sysmda_test_authors']     = array(); // user ID => display name
$GLOBALS['sysmda_test_attachments'] = array(); // attachment ID => image URL
$GLOBALS['sysmda_test_terms']       = array(); // post ID => taxonomy => term objects
$GLOBALS['sysmda_test_taxonomies']  = array(); // post type => taxonomy slug => object
$GLOBALS['sysmda_test_filters']     = array(); // filter tag => forced return value
$GLOBALS['sysmda_test_status']      = array(); // status codes sent by status_header()

/**
 * Stub: filters return the default value, unless a test forced a return value
 * for that tag in $GLOBALS['sysmda_test_filters'] (used to drive opt-in
 * features, which read their toggle through a filter).
 */
function apply_filters( $tag, $value ) {
	return array_key_exists( $tag, $GLOBALS['sysmda_test_filters'] )
		? $GLOBALS['sysmda_test_filters'][ $tag ]
		: $value;
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

/** Stub: site origin used as the fallback base for unparseable permalinks. */
function home_url( $path = '' ) {
	return 'https://example.com' . $path;
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

/**
 * Stub matching WordPress core `sanitize_html_class()` for the relevant subset:
 * strip %-octets, then keep only A-Z a-z 0-9 _ - (normalizes, does not reject).
 */
function sanitize_html_class( $class, $fallback = '' ) {
	$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class );
	$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
	if ( '' === $sanitized && '' !== (string) $fallback ) {
		return sanitize_html_class( $fallback );
	}
	return $sanitized;
}

/**
 * Stub matching WordPress core `sanitize_key()`: lowercase, then keep only
 * a-z 0-9 _ - (used for the taxonomy-selection slugs).
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/** Stub: strip tags, collapse whitespace, trim (keeps slashes/colons/URLs). */
function sanitize_text_field( $str ) {
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}

/** Stub: title from the post object. */
function get_the_title( $post ) {
	return $post->post_title;
}

/** Stub: published/modified time; the tests preset ISO strings on the post. */
function get_post_time( $format, $gmt, $post ) {
	return $post->sysmda_published;
}

function get_post_modified_time( $format, $gmt, $post ) {
	return $post->sysmda_modified;
}

/** Stub: author display name from the authors map (missing => empty string). */
function get_the_author_meta( $field, $user_id ) {
	return isset( $GLOBALS['sysmda_test_authors'][ $user_id ] ) ? $GLOBALS['sysmda_test_authors'][ $user_id ] : '';
}

/** Stub: featured-image attachment ID from the post (0 => none). */
function get_post_thumbnail_id( $post ) {
	return $post->sysmda_thumb_id;
}

/** Stub: attachment URL from the attachments map (missing => false). */
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
	return isset( $GLOBALS['sysmda_test_attachments'][ $id ] ) ? $GLOBALS['sysmda_test_attachments'][ $id ] : false;
}

/** Stub: post terms from the terms map (missing => false, like core). */
function get_the_terms( $post, $taxonomy ) {
	return isset( $GLOBALS['sysmda_test_terms'][ $post->ID ][ $taxonomy ] )
		? $GLOBALS['sysmda_test_terms'][ $post->ID ][ $taxonomy ]
		: false;
}

/** Stub: no magic quotes to strip in the tests. */
function post_password_required( $post ) {
	return ! empty( $post->post_password );
}

/** Stub: post format, driven by a test-only property (false = standard format). */
function get_post_format( $post = null ) {
	return isset( $post->post_format ) ? $post->post_format : false;
}

function esc_url_raw( $url ) {
	return $url;
}

function wp_unslash( $value ) {
	return $value;
}

/**
 * Stub: records the status codes the conditional-request logic would send, so a
 * 304 can be asserted without a real HTTP response ($GLOBALS reset per test).
 */
function status_header( $code ) {
	$GLOBALS['sysmda_test_status'][] = (int) $code;
}

/** Stub: taxonomies registered for a post type (always called with 'objects'). */
function get_object_taxonomies( $post_type, $output = 'names' ) {
	return isset( $GLOBALS['sysmda_test_taxonomies'][ $post_type ] )
		? $GLOBALS['sysmda_test_taxonomies'][ $post_type ]
		: array();
}

/** Stub: JSON encoding used to fingerprint the taxonomy data. */
function wp_json_encode( $data ) {
	return json_encode( $data );
}

/** Minimal WP_Error stub: get_the_terms returns one for unknown taxonomies. */
class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
}

/** Stub: pluck a field from a list of objects/arrays. */
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		if ( is_object( $item ) ) {
			$out[] = $item->$field;
		} elseif ( is_array( $item ) ) {
			$out[] = $item[ $field ];
		}
	}
	return $out;
}

/** Minimal WP_Post stub (in the global namespace, as in WordPress). */
class WP_Post {
	public $ID           = 0;
	public $post_type    = 'post';
	public $post_status  = 'publish';
	public $post_title   = '';
	public $post_author  = 0;
	public $post_content = '';
	public $post_excerpt   = '';
	public $post_password  = '';
	public $permalink      = '';
	/** GMT modification time: part of the cache validity hash / ETag. */
	public $post_modified_gmt = '';
	/** Test-only preset read by the get_post_format() stub (false = standard). */
	public $post_format = false;
	/** Test-only presets read by the get_post_time/thumbnail stubs above. */
	public $sysmda_published = '';
	public $sysmda_modified  = '';
	public $sysmda_thumb_id  = 0;

	public function __construct( array $props = array() ) {
		foreach ( $props as $k => $v ) {
			$this->$k = $v;
		}
	}
}

// ─── Classes under test ───────────────────────────────────────────────────────

/*
 * The Markdown conversion tests need league/html-to-markdown, which lives in
 * vendor/. Loaded when present; the few tests that need it are skipped with an
 * explicit notice otherwise, so `php tests/run-tests.php` still works in a bare
 * checkout. CI installs the dependencies, so they always run there.
 */
$GLOBALS['sysmda_has_vendor'] = is_readable( __DIR__ . '/../vendor/autoload.php' );

if ( $GLOBALS['sysmda_has_vendor'] ) {
	require __DIR__ . '/../vendor/autoload.php';
}

require __DIR__ . '/../src/AcceptNegotiator.php';
require __DIR__ . '/../src/ShortcodeCleaner.php';
require __DIR__ . '/../src/BlockCleaner.php';
require __DIR__ . '/../src/ContentRenderer.php';
require __DIR__ . '/../src/MarkdownConverter.php';
require __DIR__ . '/../src/PostSupport.php';
require __DIR__ . '/../src/MetadataBuilder.php';
require __DIR__ . '/../src/LlmsTxtController.php';
require __DIR__ . '/../src/MarkdownController.php';
require __DIR__ . '/../src/LiteSpeedCompat.php';
require __DIR__ . '/../src/HitCounter.php';
require __DIR__ . '/../src/AdminSettings.php';

use Diecieventi\SystemMarkdownAlternate\AcceptNegotiator;
use Diecieventi\SystemMarkdownAlternate\AdminSettings;
use Diecieventi\SystemMarkdownAlternate\BlockCleaner;
use Diecieventi\SystemMarkdownAlternate\ContentRenderer;
use Diecieventi\SystemMarkdownAlternate\PostSupport;
use Diecieventi\SystemMarkdownAlternate\HitCounter;
use Diecieventi\SystemMarkdownAlternate\LiteSpeedCompat;
use Diecieventi\SystemMarkdownAlternate\LlmsTxtController;
use Diecieventi\SystemMarkdownAlternate\MarkdownController;
use Diecieventi\SystemMarkdownAlternate\MarkdownConverter;
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

// ─── ContentRenderer::absolutize ─────────────────────────────────────────────
//
// absolutize() is private (an internal step of the render pipeline); it is
// exercised through reflection rather than widening the public API for tests.

$sysmda_renderer   = new ContentRenderer( new BlockCleaner( new ShortcodeCleaner() ), new ShortcodeCleaner() );
$sysmda_abs_method = new ReflectionMethod( ContentRenderer::class, 'absolutize' );
$sysmda_abs_method->setAccessible( true );

$sysmda_abs = function ( $url ) use ( $sysmda_abs_method, $sysmda_renderer ) {
	return $sysmda_abs_method->invoke( $sysmda_renderer, $url, 'https://example.com/blog/my-post/' );
};

// Relative URLs are resolved against the permalink. The base ends with a
// slash (pretty permalinks do), so the permalink itself is the directory.
check( 'absolutize: document-relative', 'https://example.com/blog/my-post/other', $sysmda_abs( 'other' ) );
check( 'absolutize: root-relative', 'https://example.com/about', $sysmda_abs( '/about' ) );
check( 'absolutize: parent segment', 'https://example.com/blog/other', $sysmda_abs( '../other' ) );

// Absolute and protocol-relative URLs are left untouched (any case).
check( 'absolutize: absolute https', 'https://other.test/x', $sysmda_abs( 'https://other.test/x' ) );
check( 'absolutize: uppercase scheme', 'HTTPS://other.test/x', $sysmda_abs( 'HTTPS://other.test/x' ) );
check( 'absolutize: protocol-relative', '//cdn.test/x.png', $sysmda_abs( '//cdn.test/x.png' ) );

// Non-hierarchical schemes and fragments must survive verbatim. Scheme names
// are case-insensitive (RFC 3986), so the uppercase spellings must not be
// mistaken for relative paths and rewritten.
check( 'absolutize: mailto', 'mailto:info@example.com', $sysmda_abs( 'mailto:info@example.com' ) );
check( 'absolutize: MAILTO uppercase', 'MAILTO:info@example.com', $sysmda_abs( 'MAILTO:info@example.com' ) );
check( 'absolutize: Mailto mixed case', 'Mailto:info@example.com', $sysmda_abs( 'Mailto:info@example.com' ) );
check( 'absolutize: tel', 'tel:+390212345', $sysmda_abs( 'tel:+390212345' ) );
check( 'absolutize: TEL uppercase', 'TEL:+390212345', $sysmda_abs( 'TEL:+390212345' ) );
check( 'absolutize: data', 'data:image/png;base64,AAA', $sysmda_abs( 'data:image/png;base64,AAA' ) );
check( 'absolutize: DATA uppercase', 'DATA:image/png;base64,AAA', $sysmda_abs( 'DATA:image/png;base64,AAA' ) );
check( 'absolutize: fragment', '#section-2', $sysmda_abs( '#section-2' ) );

// ─── PostSupport::sanitize_types ─────────────────────────────────────────────
//
// `attachment` must never be servable, whatever the filter returns — the
// settings page is not the only way into the supported-types list.

check( 'types: attachment removed', array( 'post', 'page' ), PostSupport::sanitize_types( array( 'post', 'attachment', 'page' ) ) );
check( 'types: attachment only => empty', array(), PostSupport::sanitize_types( array( 'attachment' ) ) );
check( 'types: normal list untouched', array( 'post', 'page', 'product' ), PostSupport::sanitize_types( array( 'post', 'page', 'product' ) ) );
check( 'types: empty input', array(), PostSupport::sanitize_types( array() ) );
check( 'types: duplicates dropped', array( 'post' ), PostSupport::sanitize_types( array( 'post', 'post' ) ) );
check( 'types: surrounding whitespace trimmed', array( 'post' ), PostSupport::sanitize_types( array( '  post  ' ) ) );
check( 'types: empty and non-string entries skipped', array( 'post' ), PostSupport::sanitize_types( array( 'post', '', '   ', 42, null, array( 'x' ) ) ) );
// The exclusion is exact: a CPT whose name merely contains "attachment" stays.
check( 'types: lookalike CPT preserved', array( 'attachment_note' ), PostSupport::sanitize_types( array( 'attachment_note' ) ) );
// Keys are not preserved: consumers use in_array(), a list is expected.
check( 'types: reindexed list', array( 0, 1 ), array_keys( PostSupport::sanitize_types( array( 5 => 'post', 9 => 'page' ) ) ) );

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

// ─── MetadataBuilder::build_front_matter (F1 golden conformance) ─────
//
// These golden strings pin the documented output format (docs/output-format.md):
// the exact front-matter keys, their order, which keys are conditional, and the
// YAML scalar escaping rules. An accidental reorder/removal/format change breaks
// them on purpose, so the contract cannot drift silently.

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';

// (1) Full fixture: every conditional key present.
$GLOBALS['sysmda_test_authors'][7]      = 'Jane Doe';
$GLOBALS['sysmda_test_attachments'][55] = 'https://example.com/img.jpg';
$GLOBALS['sysmda_test_meta'][55]['_wp_attachment_image_alt'] = 'Cover alt';
$GLOBALS['sysmda_test_meta'][30]['rank_math_description']    = 'A concise summary.';
$GLOBALS['sysmda_test_terms'][30]['category'] = array( (object) array( 'name' => 'News' ), (object) array( 'name' => 'Updates' ) );
$GLOBALS['sysmda_test_terms'][30]['post_tag'] = array( (object) array( 'name' => 'alpha' ), (object) array( 'name' => 'beta' ) );

$sysmda_full_post = new WP_Post(
	array(
		'ID'              => 30,
		'post_title'      => 'Hello World',
		'post_author'     => 7,
		'permalink'       => 'https://example.com/hello-world/',
		'sysmda_published' => '2026-07-01T08:30:00+00:00',
		'sysmda_modified'  => '2026-07-10T12:00:00+00:00',
		'sysmda_thumb_id'  => 55,
	)
);
$sysmda_full_expected = implode(
	"\n",
	array(
		'---',
		'title: "Hello World"',
		'url: "https://example.com/hello-world/"',
		'markdown_url: "https://example.com/hello-world.md"',
		'date_published: "2026-07-01T08:30:00+00:00"',
		'date_modified: "2026-07-10T12:00:00+00:00"',
		'author: "Jane Doe"',
		'featured_image: "https://example.com/img.jpg"',
		'featured_image_alt: "Cover alt"',
		'categories:',
		'  - "News"',
		'  - "Updates"',
		'tags:',
		'  - "alpha"',
		'  - "beta"',
		'description: "A concise summary."',
		'---',
	)
) . "\n";
check( 'front matter: full fixture, keys and order', $sysmda_full_expected, $metadata->build_front_matter( $sysmda_full_post ) );

// (2) Minimal fixture: every conditional key absent (no author, thumbnail,
// terms or description) — proves the conditional presence of those keys.
$sysmda_min_post = new WP_Post(
	array(
		'ID'              => 31,
		'post_title'      => 'Bare',
		'post_author'     => 0,
		'permalink'       => 'https://example.com/bare/',
		'sysmda_published' => '2026-01-01T00:00:00+00:00',
		'sysmda_modified'  => '2026-01-01T00:00:00+00:00',
		'sysmda_thumb_id'  => 0,
	)
);
$sysmda_min_expected = implode(
	"\n",
	array(
		'---',
		'title: "Bare"',
		'url: "https://example.com/bare/"',
		'markdown_url: "https://example.com/bare.md"',
		'date_published: "2026-01-01T00:00:00+00:00"',
		'date_modified: "2026-01-01T00:00:00+00:00"',
		'---',
	)
) . "\n";
check( 'front matter: minimal fixture, conditional keys absent', $sysmda_min_expected, $metadata->build_front_matter( $sysmda_min_post ) );

// (3) Scalar escaping: the title line exercises MetadataBuilder::scalar()
// (entity-decode → strip tags → collapse whitespace → escape \ then ").
$sysmda_title_line = function ( $title ) use ( $metadata ) {
	$p = new WP_Post(
		array(
			'ID'              => 40,
			'post_title'      => $title,
			'post_author'     => 0,
			'permalink'       => 'https://example.com/x/',
			'sysmda_published' => '2026-01-01T00:00:00+00:00',
			'sysmda_modified'  => '2026-01-01T00:00:00+00:00',
		)
	);
	return explode( "\n", $metadata->build_front_matter( $p ) )[1]; // The `title:` line.
};
check( 'scalar: double quotes escaped', 'title: "He said \\"hi\\""', $sysmda_title_line( 'He said "hi"' ) );
check( 'scalar: backslash doubled', 'title: "a\\\\b"', $sysmda_title_line( 'a\\b' ) );
check( 'scalar: entities decoded', 'title: "Tom & Jerry"', $sysmda_title_line( 'Tom &amp; Jerry' ) );
check( 'scalar: entity quote decoded then escaped', 'title: "AT&T \\"deal\\""', $sysmda_title_line( 'AT&amp;T &quot;deal&quot;' ) );
check( 'scalar: embedded tags stripped', 'title: "Bold move"', $sysmda_title_line( '<strong>Bold</strong> move' ) );
check( 'scalar: whitespace collapsed and trimmed', 'title: "Line one Line two"', $sysmda_title_line( "  Line one\n\n\tLine   two  " ) );

// ─── MetadataBuilder: custom taxonomies (F3.1) ───────────────────────
//
// normalize_taxonomies() is pure: filtering and ordering are tested directly.

// Ordering: taxonomy slugs and term names both come out alphabetical.
check(
	'taxonomies: slugs and names sorted',
	array(
		'genre' => array( 'Ambient', 'Techno' ),
		'topic' => array( 'Privacy' ),
	),
	MetadataBuilder::normalize_taxonomies(
		array(
			'topic' => array( 'Privacy' ),
			'genre' => array( 'Techno', 'Ambient' ),
		)
	)
);

// Core and presentational taxonomies never appear here.
check( 'taxonomies: category excluded', array(), MetadataBuilder::normalize_taxonomies( array( 'category' => array( 'News' ) ) ) );
check( 'taxonomies: post_tag excluded', array(), MetadataBuilder::normalize_taxonomies( array( 'post_tag' => array( 'alpha' ) ) ) );
check( 'taxonomies: post_format excluded', array(), MetadataBuilder::normalize_taxonomies( array( 'post_format' => array( 'post-format-aside' ) ) ) );

// A slug a filter could inject must never become a YAML key.
check( 'taxonomies: invalid slug dropped', array(), MetadataBuilder::normalize_taxonomies( array( 'bad slug!' => array( 'x' ) ) ) );
check( 'taxonomies: slug with colon dropped', array(), MetadataBuilder::normalize_taxonomies( array( 'a:b' => array( 'x' ) ) ) );
check( 'taxonomies: lookalike slug kept', array( 'category_extra' => array( 'x' ) ), MetadataBuilder::normalize_taxonomies( array( 'category_extra' => array( 'x' ) ) ) );

// Empty / malformed input.
check( 'taxonomies: empty input', array(), MetadataBuilder::normalize_taxonomies( array() ) );
check( 'taxonomies: taxonomy with no terms dropped', array(), MetadataBuilder::normalize_taxonomies( array( 'genre' => array() ) ) );
check( 'taxonomies: non-array terms dropped', array(), MetadataBuilder::normalize_taxonomies( array( 'genre' => 'Techno' ) ) );
check( 'taxonomies: non-string names skipped', array( 'genre' => array( 'Techno' ) ), MetadataBuilder::normalize_taxonomies( array( 'genre' => array( 'Techno', 42, null, array( 'x' ) ) ) ) );
check( 'taxonomies: empty names dropped', array( 'genre' => array( 'Techno' ) ), MetadataBuilder::normalize_taxonomies( array( 'genre' => array( '', '   ', 'Techno' ) ) ) );
check( 'taxonomies: names trimmed', array( 'genre' => array( 'Techno' ) ), MetadataBuilder::normalize_taxonomies( array( 'genre' => array( '  Techno  ' ) ) ) );
check( 'taxonomies: duplicate names dropped', array( 'genre' => array( 'Techno' ) ), MetadataBuilder::normalize_taxonomies( array( 'genre' => array( 'Techno', 'Techno' ) ) ) );

// ─── candidate_taxonomies / is_public_taxonomy (F3.2) ────────────────
//
// These two are pure and decide what the settings page OFFERS and how each row
// is labelled — never what is emitted, which is the saved selection alone.

$sysmda_tax_objects = array(
	'genre'    => (object) array(
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
	),
	'internal' => (object) array( // Editorial-internal: public, no term archive.
		'public'             => true,
		'publicly_queryable' => false,
		'show_ui'            => true,
	),
	'hidden'   => (object) array( // Not public but editable: offerable, unticked.
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
	),
	'plumbing' => (object) array( // Invisible everywhere: never offered.
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => false,
	),
	'category' => (object) array( // Public, but always excluded from this block.
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
	),
);

check(
	'candidates: core and plumbing dropped, sorted by slug',
	array( 'genre', 'hidden', 'internal' ),
	array_keys( MetadataBuilder::filter_candidates( $sysmda_tax_objects ) )
);
check( 'candidates: empty input', array(), MetadataBuilder::filter_candidates( array() ) );
check( 'candidates: non-object dropped', array(), MetadataBuilder::filter_candidates( array( 'genre' => 'nope' ) ) );
check( 'candidates: invalid slug dropped', array(), MetadataBuilder::filter_candidates( array( 'bad slug!' => (object) array( 'public' => true ) ) ) );

// is_public_taxonomy: the advisory predicate behind the panel label and the
// migration seed. `publicly_queryable` is what the 0.24.x check was missing.
check( 'is_public_taxonomy: public and queryable', true, MetadataBuilder::is_public_taxonomy( $sysmda_tax_objects['genre'] ) );
check( 'is_public_taxonomy: public but not queryable', false, MetadataBuilder::is_public_taxonomy( $sysmda_tax_objects['internal'] ) );
check( 'is_public_taxonomy: queryable but not public', false, MetadataBuilder::is_public_taxonomy( (object) array( 'public' => false, 'publicly_queryable' => true ) ) );
check( 'is_public_taxonomy: neither', false, MetadataBuilder::is_public_taxonomy( $sysmda_tax_objects['plumbing'] ) );
check( 'is_public_taxonomy: properties missing', false, MetadataBuilder::is_public_taxonomy( (object) array() ) );
check( 'is_public_taxonomy: not an object', false, MetadataBuilder::is_public_taxonomy( 'genre' ) );

// candidate_taxonomies(): the union of the taxonomies of the given post types.
$GLOBALS['sysmda_test_taxonomies']['post'] = $sysmda_tax_objects;
$GLOBALS['sysmda_test_taxonomies']['page'] = array(
	'department' => (object) array(
		'public'             => true,
		'publicly_queryable' => true,
	),
);
check( 'candidates: union across post types', array( 'department', 'genre', 'hidden', 'internal' ), array_keys( MetadataBuilder::candidate_taxonomies( array( 'post', 'page' ) ) ) );
check( 'candidates: unknown post type', array(), MetadataBuilder::candidate_taxonomies( array( 'nope' ) ) );
check( 'candidates: no post types', array(), MetadataBuilder::candidate_taxonomies( array() ) );
check( 'candidates: non-string post type ignored', array(), MetadataBuilder::candidate_taxonomies( array( 0, '', null ) ) );

// ─── taxonomy_terms / fingerprint: explicit selection only (F3.2) ────
//
// The emitted list is the selection fed into `sysmda_front_matter_taxonomy_slugs`
// (by AdminSettings from the saved option; here by the filter stub). There is no
// auto-detection to test any more: registration alone must never publish.

$sysmda_tax_post = new WP_Post(
	array(
		'ID'               => 60,
		'post_type'        => 'post',
		'post_title'       => 'Tagged',
		'post_author'      => 0,
		'permalink'        => 'https://example.com/tagged/',
		'sysmda_published' => '2026-01-01T00:00:00+00:00',
		'sysmda_modified'  => '2026-01-01T00:00:00+00:00',
	)
);
$GLOBALS['sysmda_test_terms'][60]['genre']    = array( (object) array( 'name' => 'Techno' ), (object) array( 'name' => 'Ambient' ) );
$GLOBALS['sysmda_test_terms'][60]['internal'] = array( (object) array( 'name' => 'Hidden' ) );
$GLOBALS['sysmda_test_terms'][60]['category'] = array( (object) array( 'name' => 'News' ) );

// Nothing selected (the default): no data and no fingerprint, so the front
// matter and the cache validator stay as on a site without the feature — even
// though all three taxonomies are registered and have terms.
check( 'taxonomies: nothing selected => no data', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );
check( 'taxonomies: nothing selected => empty fingerprint', '', MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );

// A selection is all it takes: the gate's default is "something is selected",
// so there is no separate toggle to set.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre' );

check(
	'taxonomies: selection alone enables the block',
	array( 'genre' => array( 'Ambient', 'Techno' ) ),
	MetadataBuilder::taxonomy_terms( $sysmda_tax_post )
);

// The reported defect: `internal` is registered `public => true,
// publicly_queryable => false` and has a term, and 0.24.x published it on its
// own. Not selected now => it never reaches the output.
check( 'taxonomies: unselected internal taxonomy stays out', false, array_key_exists( 'internal', MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) ) );

// Selecting it is still allowed: the panel labels such a taxonomy, it does not
// veto it, so an explicit choice is honoured.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'internal' );
check(
	'taxonomies: internal taxonomy emitted when deliberately selected',
	array( 'internal' => array( 'Hidden' ) ),
	MetadataBuilder::taxonomy_terms( $sysmda_tax_post )
);

// The always-excluded set and invalid slugs are stripped AFTER the selection, so
// neither the option nor a filter can duplicate categories/tags or break the YAML.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre', 'category', 'post_tag', 'post_format', 'bad slug!' );
check(
	'taxonomies: excluded and invalid slugs stripped after the selection',
	array( 'genre' => array( 'Ambient', 'Techno' ) ),
	MetadataBuilder::taxonomy_terms( $sysmda_tax_post )
);

// Kill switch: the boolean filter still wins over a non-empty selection.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomies'] = false;
check( 'taxonomies: kill switch beats the selection', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );
check( 'taxonomies: kill switch => empty fingerprint', '', MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );
unset( $GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomies'] );

$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre' );

$sysmda_fp = MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post );
check( 'taxonomies: selected => fingerprint present', true, '' !== $sysmda_fp );
check( 'taxonomies: fingerprint stable for same terms', $sysmda_fp, MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );

// Changing the selection changes the emitted body, so it must change the
// validator too (the settings-save salt bump is a second, independent layer).
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre', 'internal' );
check( 'taxonomies: fingerprint changes with the selection', true, $sysmda_fp !== MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre' );

// The fingerprint must move when the terms move: this is what makes the ETag
// change on a term reassignment or rename (post_modified_gmt does not).
$GLOBALS['sysmda_test_terms'][60]['genre'] = array( (object) array( 'name' => 'Techno' ), (object) array( 'name' => 'Dub' ) );
check( 'taxonomies: fingerprint changes on term change', true, $sysmda_fp !== MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );

// A taxonomy the post has no terms in (false) or an unregistered one (WP_Error).
$GLOBALS['sysmda_test_terms'][60]['genre'] = false;
check( 'taxonomies: no terms => omitted', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );
$GLOBALS['sysmda_test_terms'][60]['genre'] = new WP_Error( 'invalid_taxonomy' );
check( 'taxonomies: WP_Error => omitted', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );

// ─── Front matter with the taxonomies block ──────────────────────────

$GLOBALS['sysmda_test_terms'][60]['genre'] = array( (object) array( 'name' => 'Techno' ), (object) array( 'name' => 'Ambient' ) );

check(
	'front matter: taxonomies appended after description',
	implode(
		"\n",
		array(
			'---',
			'title: "Tagged"',
			'url: "https://example.com/tagged/"',
			'markdown_url: "https://example.com/tagged.md"',
			'date_published: "2026-01-01T00:00:00+00:00"',
			'date_modified: "2026-01-01T00:00:00+00:00"',
			'categories:',
			'  - "News"',
			'taxonomies:',
			'  genre:',
			'    - "Ambient"',
			'    - "Techno"',
			'---',
		)
	) . "\n",
	$metadata->build_front_matter( $sysmda_tax_post )
);

// Term names go through the same YAML escaping as every other scalar.
$GLOBALS['sysmda_test_terms'][60]['genre'] = array( (object) array( 'name' => 'He said "hi"' ) );
check(
	'front matter: taxonomy term escaped',
	true,
	false !== strpos( $metadata->build_front_matter( $sysmda_tax_post ), '    - "He said \\"hi\\""' )
);

// ─── cache_version: the taxonomy fingerprint reaches the ETag ────────
//
// cache_version() is private and is both the cache-validity hash AND the strong
// ETag, so it is checked through reflection: this is the one place where an
// error would either invalidate every cached .md on upgrade (toggle off) or
// keep serving 304 with stale terms (toggle on).

$sysmda_controller  = new MarkdownController(
	new ContentRenderer( new BlockCleaner( new ShortcodeCleaner() ), new ShortcodeCleaner() ),
	new MarkdownConverter(),
	$metadata
);
$sysmda_cv_method = new ReflectionMethod( MarkdownController::class, 'cache_version' );
$sysmda_cv_method->setAccessible( true );
$sysmda_cv        = function ( $post ) use ( $sysmda_cv_method, $sysmda_controller ) {
	return $sysmda_cv_method->invoke( $sysmda_controller, $post );
};

$sysmda_cv_post = new WP_Post(
	array(
		'ID'                => 61,
		'post_type'         => 'post',
		'permalink'         => 'https://example.com/cv/',
		'post_modified_gmt' => '2026-07-01 08:30:00',
	)
);
$GLOBALS['sysmda_test_taxonomies']['post'] = array(
	'genre' => (object) array(
		'public'             => true,
		'publicly_queryable' => true,
	),
);
$GLOBALS['sysmda_test_terms'][61]['genre'] = array( (object) array( 'name' => 'Techno' ) );

// Nothing selected: byte-identical to the pre-feature formula, so upgrading does
// not invalidate a single cached response or ETag.
$GLOBALS['sysmda_test_filters'] = array();
check(
	'cache_version: unchanged while nothing is selected',
	md5( '2026-07-01 08:30:00|' . SYSMDA_VERSION . '|0' ),
	$sysmda_cv( $sysmda_cv_post )
);

// Taxonomy selected: the validator now depends on the terms.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre' );
$sysmda_cv_on = $sysmda_cv( $sysmda_cv_post );
check( 'cache_version: changes once a taxonomy is selected', true, $sysmda_cv_on !== md5( '2026-07-01 08:30:00|' . SYSMDA_VERSION . '|0' ) );
check( 'cache_version: stable for unchanged terms', $sysmda_cv_on, $sysmda_cv( $sysmda_cv_post ) );

// The whole point: a term change moves the ETag even though post_modified_gmt
// is untouched, so a conditional request cannot answer 304 with stale terms.
$GLOBALS['sysmda_test_terms'][61]['genre'] = array( (object) array( 'name' => 'Ambient' ) );
check( 'cache_version: term rename changes the ETag', true, $sysmda_cv_on !== $sysmda_cv( $sysmda_cv_post ) );

// ─── handle_conditional: If-Modified-Since must not go stale ─────────
//
// The ETag carries the taxonomy fingerprint, but Last-Modified is derived from
// post_modified_gmt, which a term change does NOT touch. A client sending only
// If-Modified-Since would therefore be told "304 Not Modified" while its copy
// has outdated terms, so the date is only honoured while it is a strong
// validator for the representation.

$sysmda_hc_method = new ReflectionMethod( MarkdownController::class, 'handle_conditional' );
$sysmda_hc_method->setAccessible( true );

/** Runs handle_conditional() with only an If-Modified-Since header set. */
$sysmda_ims = function ( $post, $since ) use ( $sysmda_hc_method, $sysmda_controller, $sysmda_cv ) {
	$GLOBALS['sysmda_test_status'] = array();
	unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
	$_SERVER['HTTP_IF_MODIFIED_SINCE'] = $since;
	$result = $sysmda_hc_method->invoke( $sysmda_controller, $post, $sysmda_cv( $post ) );
	unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	return $result;
};

// The client already holds a copy newer than the post's modification date.
$sysmda_fresh_since = gmdate( 'D, d M Y H:i:s', strtotime( '2026-07-01 08:30:00 GMT' ) ) . ' GMT';

// Nothing selected: the date fully determines the body, so a 304 is correct and
// the existing behaviour is preserved.
$GLOBALS['sysmda_test_filters'] = array();
check( 'conditional: IMS honoured while no taxonomy is selected', true, $sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since ) );
check( 'conditional: 304 actually sent', array( 304 ), $GLOBALS['sysmda_test_status'] );

// Taxonomy selected: the date can no longer prove the body is unchanged, so the
// full response must be served instead of a stale 304.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_taxonomy_slugs'] = array( 'genre' );
check( 'conditional: IMS ignored when taxonomies are emitted', false, $sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since ) );
check( 'conditional: no 304 sent', array(), $GLOBALS['sysmda_test_status'] );

// If-None-Match still works with the block on: the ETag is taxonomy-aware, so
// it remains a reliable validator (this is the common browser/crawler case).
$GLOBALS['sysmda_test_status'] = array();
unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
$_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $sysmda_cv( $sysmda_cv_post ) . '"';
check( 'conditional: matching ETag still yields 304', true, $sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) ) );
$_SERVER['HTTP_IF_NONE_MATCH'] = '"stale-validator"';
check( 'conditional: stale ETag yields the full body', false, $sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) ) );
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

// Back to the default state so later assertions are unaffected.
$GLOBALS['sysmda_test_filters'] = array();
$GLOBALS['sysmda_test_taxonomies'] = array();
$GLOBALS['sysmda_test_status'] = array();
unset( $GLOBALS['sysmda_test_terms'][60], $GLOBALS['sysmda_test_terms'][61] );

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

// ─── AdminSettings sanitizers ──────────────────────────────────────────────────

$sysmda_admin = new AdminSettings(); // No boot(): sanitizers are pure, no hooks needed.

// sanitize_class_lines: normalizes CSS-class tokens (does NOT reject/validate).
check(
	'class_lines: valid defaults unchanged',
	"no-md\nmd-exclude\nexclude-from-markdown",
	$sysmda_admin->sanitize_class_lines( "no-md\nmd-exclude\nexclude-from-markdown" )
);
check(
	'class_lines: whitespace-separated tokens split',
	"foo\nbar\nbaz",
	$sysmda_admin->sanitize_class_lines( "foo bar\tbaz" )
);
check(
	'class_lines: dedupe across lines/spaces',
	"foo\nbar",
	$sysmda_admin->sanitize_class_lines( "foo\r\nfoo\nbar" )
);
check(
	'class_lines: punctuation normalized, not rejected',
	"notice\ncustom",
	$sysmda_admin->sanitize_class_lines( ".notice\n<custom>" )
);
check(
	'class_lines: punctuation-only dropped, hyphen/underscore kept',
	"---\n___",
	$sysmda_admin->sanitize_class_lines( "...\n---\n___" )
);
check( 'class_lines: empty input', '', $sysmda_admin->sanitize_class_lines( '' ) );
check( 'class_lines: whitespace-only input', '', $sysmda_admin->sanitize_class_lines( "  \t\n " ) );

// sanitize_taxonomy_slugs: the taxonomy selection saved by the panel.
check(
	'taxonomy_slugs: valid slugs kept and sorted',
	array( 'department', 'genre' ),
	$sysmda_admin->sanitize_taxonomy_slugs( array( 'genre', 'department' ) )
);
check( 'taxonomy_slugs: nothing ticked (null) => empty', array(), $sysmda_admin->sanitize_taxonomy_slugs( null ) );
check( 'taxonomy_slugs: empty array', array(), $sysmda_admin->sanitize_taxonomy_slugs( array() ) );
check( 'taxonomy_slugs: non-array => empty', array(), $sysmda_admin->sanitize_taxonomy_slugs( 'genre' ) );
check( 'taxonomy_slugs: duplicates dropped', array( 'genre' ), $sysmda_admin->sanitize_taxonomy_slugs( array( 'genre', 'genre' ) ) );
check( 'taxonomy_slugs: non-string entries skipped', array( 'genre' ), $sysmda_admin->sanitize_taxonomy_slugs( array( 'genre', 42, null, array( 'x' ) ) ) );
check( 'taxonomy_slugs: always-excluded taxonomies rejected', array(), $sysmda_admin->sanitize_taxonomy_slugs( array( 'category', 'post_tag', 'post_format' ) ) );
check( 'taxonomy_slugs: punctuation normalized away', array( 'genre' ), $sysmda_admin->sanitize_taxonomy_slugs( array( 'gen re!' ) ) );
check( 'taxonomy_slugs: punctuation-only dropped', array(), $sysmda_admin->sanitize_taxonomy_slugs( array( '///' ) ) );
// An unregistered slug is deliberately KEPT: a temporarily inactive plugin must
// not silently erase the choice on the next save.
check( 'taxonomy_slugs: unknown slug preserved', array( 'not_registered_yet' ), $sysmda_admin->sanitize_taxonomy_slugs( array( 'not_registered_yet' ) ) );

// Regression: the generic multiline sanitizer was NOT replaced globally — it must
// still preserve values with slashes/colons/URLs (block names, key content, …).
check(
	'lines: slashes/colons/URL preserved',
	"gravityforms/form\nhttps://example.com/a:b",
	$sysmda_admin->sanitize_lines( "gravityforms/form\nhttps://example.com/a:b" )
);

// ─── ContentRenderer::process_dom (DOM pipeline) ──────────────────────────────

// The DOM pass is where the body is assembled, so it gets golden coverage: the
// bugs it used to hide (silent truncation, glued tables, collapsed code) were all
// invisible to the pure-logic tests that only reached absolutize().
$sysmda_dom_method = new ReflectionMethod( ContentRenderer::class, 'process_dom' );
$sysmda_dom_method->setAccessible( true );

$sysmda_dom = static function ( $html, $base = 'https://example.com/blog/my-post/' ) use ( $sysmda_renderer, $sysmda_dom_method ) {
	return $sysmda_dom_method->invoke( $sysmda_renderer, $html, $base );
};

// The wrapper element must not be closeable by the content itself. A stray
// </div> — custom HTML blocks, migrated content, legacy column shortcodes —
// used to close the wrapper early and silently drop everything after it.
check( 'dom: stray </div> mid-content keeps the rest', '<p>a</p><p>b</p><p>c</p>', $sysmda_dom( '<p>a</p></div><p>b</p><p>c</p>' ) );
check( 'dom: leading </div> keeps everything', '<p>a</p>', $sysmda_dom( '</div><p>a</p>' ) );
check( 'dom: stray </section> harmless', '<p>a</p><p>b</p>', $sysmda_dom( '<p>a</p></section><p>b</p>' ) );
check( 'dom: legitimate div preserved', '<div class="k"><p>a</p></div><p>b</p>', $sysmda_dom( '<div class="k"><p>a</p></div><p>b</p>' ) );

// Class exclusion still empties the body when that is the point: the
// "parse came back empty" fallback must never republish excluded content.
check( 'dom: excluded wrapper empties the body', '', trim( $sysmda_dom( '<div class="md-exclude"><p>secret</p></div>' ) ) );
check( 'dom: excluded node removed, sibling kept', '<p>keep</p>', $sysmda_dom( '<p>keep</p><div class="no-md"><p>drop</p></div>' ) );

// A class that cannot be interpolated into XPath is skipped instead of taking
// the response down: query() returns false and iterator_to_array() fatals on it.
$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_classes'] = array( "it's-bad", 'no-md' );
check( 'dom: unsafe class skipped, safe one still applied', '<p>keep</p>', $sysmda_dom( '<p>keep</p><span class="no-md">x</span>' ) );
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_classes'] );

// Figures become paragraphs (blank-line separation around images), EXCEPT when
// they hold a block element: a <table> inside a <p> is invalid nesting.
check( 'dom: image figure unwrapped to p', '<p><img src="https://example.com/a.png" alt="x"></p>', $sysmda_dom( '<figure class="wp-block-image"><img src="/a.png" alt="x"/></figure>' ) );
check(
	'dom: table figure left alone',
	'<figure class="wp-block-table"><table><tr><td>c</td></tr></table></figure>',
	$sysmda_dom( '<figure class="wp-block-table"><table><tr><td>c</td></tr></table></figure>' )
);

// Definition lists: the converter has no dl support and strip_tags is on, so an
// untouched <dl> came out as "TermDefinition".
check(
	'dom: dl flattened to bold term + paragraphs',
	'<p><strong>Term</strong></p><p>Def</p>',
	$sysmda_dom( '<dl><dt>Term</dt><dd>Def</dd></dl>' )
);

// Code blocks: a highlighter that wraps each line in its own element and relies
// on CSS for the breaks has no newline in its text at all.
check(
	'dom: per-line spans regain their newlines',
	"<pre class=\"shiki\"><code>echo 1;\necho 2;</code></pre>",
	$sysmda_dom( '<pre class="shiki"><code><span class="line">echo 1;</span><span class="line">echo 2;</span></code></pre>' )
);
check(
	'dom: markup with real newlines untouched',
	"<pre><code class=\"language-php\">echo 1;\necho 2;</code></pre>",
	$sysmda_dom( "<pre><code class=\"language-php\">echo 1;\necho 2;</code></pre>" )
);
// Not a clean line-per-element structure: keep the flat text rather than guess.
check(
	'dom: mixed inline spans stay on one line',
	'<pre><code class="language-js">let a = 1;</code></pre>',
	$sysmda_dom( '<pre><code class="language-js">let <span>a</span> = 1;</code></pre>' )
);

// ─── ContentRenderer::absolutize (schemes and query-only references) ─────────

// Any scheme, not just http(s), is an absolute reference: resolving one as a
// path produced URLs like "https://example.com/blog/my-post/ftp://host/file".
check( 'absolutize: ftp scheme preserved', 'ftp://host/file', $sysmda_abs( 'ftp://host/file' ) );
check( 'absolutize: sms scheme preserved', 'sms:+390212345', $sysmda_abs( 'sms:+390212345' ) );
check( 'absolutize: whatsapp scheme preserved', 'whatsapp://send?text=x', $sysmda_abs( 'whatsapp://send?text=x' ) );
check( 'absolutize: callto scheme preserved', 'callto:123', $sysmda_abs( 'callto:123' ) );
check( 'absolutize: webcal scheme preserved', 'webcal://example.com/c.ics', $sysmda_abs( 'webcal://example.com/c.ics' ) );

// A query-only reference keeps the base path (RFC 3986 §5.3) instead of being
// resolved against the base *directory*.
check( 'absolutize: query-only, trailing-slash base', 'https://example.com/blog/my-post/?page=2', $sysmda_abs( '?page=2' ) );
$sysmda_abs_flat = static function ( $url ) use ( $sysmda_renderer, $sysmda_abs_method ) {
	return $sysmda_abs_method->invoke( $sysmda_renderer, $url, 'https://example.com/blog/my-post' );
};
check( 'absolutize: query-only, no trailing slash', 'https://example.com/blog/my-post?page=2', $sysmda_abs_flat( '?page=2' ) );
check( 'absolutize: document-relative, no trailing slash', 'https://example.com/blog/other', $sysmda_abs_flat( 'other' ) );

// ─── PostSupport::is_servable (post formats) ──────────────────────────────────

$GLOBALS['sysmda_test_options']['sysmda_supported_post_types'] = array( 'post', 'page' );
$GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] = array( 'post', 'page' );

$sysmda_mk_post = static function ( $overrides = array() ) {
	$post = new WP_Post(
		array_merge(
			array(
				'ID'          => 900,
				'post_type'   => 'post',
				'post_status' => 'publish',
			),
			$overrides
		)
	);
	return $post;
};

check( 'servable: standard format post', true, PostSupport::is_servable( $sysmda_mk_post() ) );
check( 'servable: unsupported type', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_type' => 'product' ) ) ) );
check( 'servable: draft', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_status' => 'draft' ) ) ) );
check( 'servable: password protected', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_password' => 'x' ) ) ) );

// Non-standard post formats are snippets, not documents: excluded everywhere
// is_servable() is consulted (.md, alternate link, /llms.txt, shortcode, tag).
foreach ( array( 'aside', 'status', 'quote', 'link', 'gallery', 'image', 'video', 'audio', 'chat' ) as $sysmda_format ) {
	check( "servable: {$sysmda_format} format excluded", false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => $sysmda_format ) ) ) );
}
// The standard format is the ABSENCE of a format, so it must never be excluded.
check( 'servable: standard format not excluded', false, PostSupport::has_excluded_post_format( $sysmda_mk_post() ) );
check( 'servable: unknown format value ignored', true, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => 'something-else' ) ) ) );

// The exclusion list is filterable: an empty list serves every format again.
$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_post_formats'] = array();
check( 'servable: filter can opt formats back in', true, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => 'aside' ) ) ) );
$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_post_formats'] = array( 'status' );
check( 'servable: filter can shorten the list (aside)', true, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => 'aside' ) ) ) );
check( 'servable: filter can shorten the list (status)', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => 'status' ) ) ) );
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_post_formats'] );
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] );
unset( $GLOBALS['sysmda_test_options']['sysmda_supported_post_types'] );

// ─── MarkdownConverter (needs league/html-to-markdown) ───────────────────────

if ( ! $GLOBALS['sysmda_has_vendor'] ) {
	echo "NOTE: skipping the Markdown conversion tests (vendor/ absent — run `composer install`).\n";
} else {
	$sysmda_conv = new MarkdownConverter();

	// Tables. Without the library's TableConverter registered, strip_tags glued
	// every cell together ("NamePriceCoffee2") — worse than useless to an LLM.
	check(
		'convert: table becomes a GFM pipe table',
		"| Name | Price |\n|---|---|\n| Coffee | 2 |\n| Tea | 3 |\n",
		$sysmda_conv->convert( '<table><thead><tr><th>Name</th><th>Price</th></tr></thead><tbody><tr><td>Coffee</td><td>2</td></tr><tr><td>Tea</td><td>3</td></tr></tbody></table>' )
	);
	check(
		'convert: headerless table still tabular',
		"| a | b |\n|---|---|\n| c | d |\n",
		$sysmda_conv->convert( '<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>' )
	);
	check(
		'convert: pipe inside a cell escaped',
		"| H |\n|---|\n| a\\|b |\n",
		$sysmda_conv->convert( '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>a|b</td></tr></tbody></table>' )
	);

	// Fenced code must survive byte-for-byte: trailing spaces are meaningful in a
	// Markdown sample and blank-line runs matter in transcripts, diffs and patches.
	check(
		'convert: blank lines inside a fence preserved',
		"```python\na = 1\n\n\n\nb = 2\n```\n",
		$sysmda_conv->convert( "<pre><code class=\"language-python\">a = 1\n\n\n\nb = 2</code></pre>" )
	);
	check(
		'convert: trailing spaces inside a fence preserved',
		"```\na   \nb\n```\n",
		$sysmda_conv->convert( "<pre>a   \nb</pre>" )
	);
	// Outside a fence both rules still apply.
	check(
		'convert: blank lines collapsed outside a fence',
		"a\n\nb\n",
		$sysmda_conv->convert( '<p>a</p><p></p><p></p><p>b</p>' )
	);

	// Ordinary conversions, pinned so the converter config cannot drift silently.
	check( 'convert: atx heading', "## Title\n", $sysmda_conv->convert( '<h2>Title</h2>' ) );
	check( 'convert: dash list items', "- a\n- b\n", $sysmda_conv->convert( '<ul><li>a</li><li>b</li></ul>' ) );
	check( 'convert: script node removed', "text\n", $sysmda_conv->convert( '<p>text</p><script>evil()</script>' ) );
	check( 'convert: empty input', '', $sysmda_conv->convert( '   ' ) );
}

// ─── LiteSpeedCompat::update (read-modify-write on a real file) ──────────────

// The pure string helpers above are the interesting logic, but the file path has
// its own failure modes (wrong fopen mode truncating before the read, a lock that
// is never released, a backup that clobbers itself). Exercised on a temp file:
// no WordPress needed, and it catches the kind of typo that only shows up on a
// live site's .htaccess — the one file whose breakage takes a site down.
$sysmda_update = new ReflectionMethod( LiteSpeedCompat::class, 'update' );
$sysmda_update->setAccessible( true );

$sysmda_tmp_dir  = sys_get_temp_dir() . '/sysmda-tests-' . getmypid();
@mkdir( $sysmda_tmp_dir, 0777, true );
$sysmda_htaccess = $sysmda_tmp_dir . '/.htaccess';
$sysmda_bak      = $sysmda_htaccess . '.sysmda-bak';

$sysmda_reset_htaccess = static function ( $contents ) use ( $sysmda_htaccess, $sysmda_bak ) {
	file_put_contents( $sysmda_htaccess, $contents );
	if ( file_exists( $sysmda_bak ) ) {
		unlink( $sysmda_bak );
	}
};

// Existing content must survive the open: 'w' modes would truncate before the
// transform ever sees it, silently wiping every other plugin's rules.
$sysmda_wp_rules = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
$sysmda_reset_htaccess( $sysmda_wp_rules );
$sysmda_update->invoke( null, $sysmda_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) );
$sysmda_after_add = (string) file_get_contents( $sysmda_htaccess );

check( 'update: existing content preserved', true, false !== strpos( $sysmda_after_add, '# BEGIN WordPress' ) );
check( 'update: block written', true, LiteSpeedCompat::block_is_before_wordpress( $sysmda_after_add ) );
check( 'update: matches prepend_rules exactly', LiteSpeedCompat::prepend_rules( $sysmda_wp_rules ), $sysmda_after_add );
check( 'update: backup taken', $sysmda_wp_rules, (string) file_get_contents( $sysmda_bak ) );

// Idempotent: a second run changes nothing and still reports success.
check( 'update: second run is a no-op', true, (bool) $sysmda_update->invoke( null, $sysmda_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: contents unchanged by the no-op', $sysmda_after_add, (string) file_get_contents( $sysmda_htaccess ) );

// Removal leaves the rest of the file intact.
$sysmda_update->invoke( null, $sysmda_htaccess, array( LiteSpeedCompat::class, 'strip_rules' ) );
check( 'update: removal restores the original', $sysmda_wp_rules, (string) file_get_contents( $sysmda_htaccess ) );

// The backup is a one-time snapshot of the pre-plugin state, never overwritten
// by a later write (which would replace it with our own block).
check( 'update: backup not overwritten', $sysmda_wp_rules, (string) file_get_contents( $sysmda_bak ) );

// A missing file is created rather than failing.
unlink( $sysmda_htaccess );
unlink( $sysmda_bak );
check( 'update: creates a missing file', true, (bool) $sysmda_update->invoke( null, $sysmda_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: created file holds the block', true, LiteSpeedCompat::block_is_before_wordpress( (string) file_get_contents( $sysmda_htaccess ) ) );
check( 'update: no backup for a file that did not exist', false, file_exists( $sysmda_bak ) );

// Cleanup, doubling as a weak check that nothing holds the file open. Weak on
// purpose: PHP frees the handle when it goes out of scope, so a missing
// release() would still pass here — the reason release() is called explicitly is
// to unlock deterministically rather than at the mercy of refcounting. The two
// consecutive update() calls above are the real guard: a lock left held would
// make the second one block on flock and hang the suite.
check( 'update: file removable after the write', true, unlink( $sysmda_htaccess ) );
@rmdir( $sysmda_tmp_dir );

// ─── Result ───────────────────────────────────────────────────────────────────

echo "\n{$GLOBALS['sysmda_asserts']} assertions, {$GLOBALS['sysmda_failures']} failed.\n";
exit( $GLOBALS['sysmda_failures'] > 0 ? 1 : 0 );

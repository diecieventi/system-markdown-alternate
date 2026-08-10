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
define( 'SYSMDA_PLUGIN_URL', 'https://example.com/wp-content/plugins/system-markdown-alternate/' );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// Buffer everything this runner prints, flushed automatically on exit.
// Under the CLI SAPI `headers_sent()` becomes true as soon as any output
// reaches the SAPI, and MarkdownController::send_not_modified() returns early
// when it does. So a single failing assertion — or one deprecation notice from
// a newer PHP — printed above the conditional-request tests silently stopped
// the 304 from being recorded and produced a phantom extra failure, which is
// exactly the "CLI header observation" flakiness reported against PHP 8.5.
// Buffering keeps the status observable no matter what ran before.
ob_start();

// ─── WordPress stubs (only what the tested classes need) ─────────────

$GLOBALS['sysmda_test_posts']       = array(); // id → WP_Post
$GLOBALS['sysmda_test_parsed']      = array(); // content → blocks
$GLOBALS['sysmda_test_options']     = array(); // option → value
$GLOBALS['sysmda_test_meta']        = array(); // post ID => meta key => value
$GLOBALS['sysmda_test_fields']      = array(); // post ID => ACF field key => value
$GLOBALS['sysmda_test_authors']     = array(); // user ID => display name
$GLOBALS['sysmda_test_attachments'] = array(); // attachment ID => image URL
$GLOBALS['sysmda_test_terms']       = array(); // post ID => taxonomy => term objects
$GLOBALS['sysmda_test_taxonomies']  = array(); // post type => taxonomy slug => object
$GLOBALS['sysmda_test_filters']     = array(); // filter tag => forced return value
$GLOBALS['sysmda_test_status']      = array(); // status codes sent by status_header()
$GLOBALS['sysmda_test_users']       = array(); // user ID => user object (display_name)
$GLOBALS['sysmda_test_logged_in']   = false;   // whether the current visitor is authenticated
$GLOBALS['sysmda_test_post_types']  = array(); // post type => registered object (overrides the public default)
$GLOBALS['sysmda_test_query_posts'] = array(); // post type => WP_Post list served by the get_posts() stub
$GLOBALS['sysmda_test_query_pages'] = array(); // pages the get_posts() stub was asked for

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

/** Stub matching core: block content is content carrying a block delimiter. */
function has_blocks( $content ) {
	return false !== strpos( (string) $content, '<!-- wp:' );
}

/**
 * Stub: reassembles the markup of a block list.
 *
 * Core re-emits the delimiter comments as well; here the inner HTML is enough,
 * because every caller of this in the plugin feeds the result to a pass that
 * strips comments anyway. What the tests need from it is precisely what core
 * guarantees: the blocks that survived cleaning keep their markup, and the ones
 * that were removed contribute nothing.
 */
function serialize_blocks( $blocks ) {
	$out = '';

	foreach ( (array) $blocks as $block ) {
		$out .= isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		if ( ! empty( $block['innerBlocks'] ) ) {
			$out .= serialize_blocks( $block['innerBlocks'] );
		}
	}

	return $out;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sysmda_test_options'] ) ? $GLOBALS['sysmda_test_options'][ $name ] : $default;
}

/** Stub: option writes land in the map the get_option() stub reads. */
function update_option( $name, $value ) {
	$GLOBALS['sysmda_test_options'][ $name ] = $value;

	return true;
}

/**
 * Stub: the registered post type. Types are public unless a test registers them
 * otherwise, which is what an inactive-then-changed provider looks like.
 */
function get_post_type_object( $type ) {
	if ( array_key_exists( $type, $GLOBALS['sysmda_test_post_types'] ) ) {
		return $GLOBALS['sysmda_test_post_types'][ $type ];
	}

	return (object) array(
		'name'   => $type,
		'public' => true,
	);
}

/**
 * Stub: whether a visitor is authenticated. Drives the split between the shared
 * public representation and a request that may render in a visitor's context.
 */
function is_user_logged_in() {
	return ! empty( $GLOBALS['sysmda_test_logged_in'] );
}

/** Stub: user objects, read when a display-name change invalidates the cache. */
function get_userdata( $user_id ) {
	return isset( $GLOBALS['sysmda_test_users'][ $user_id ] ) ? $GLOBALS['sysmda_test_users'][ $user_id ] : false;
}

function get_permalink( $post ) {
	return $post->permalink;
}

function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['sysmda_test_meta'][ $post_id ][ $key ] )
		? $GLOBALS['sysmda_test_meta'][ $post_id ][ $key ]
		: ( $single ? '' : array() );
}

/** Stub: ACF field values, available only where a fixture sets one. */
function get_field( $key, $post_id = false ) {
	return isset( $GLOBALS['sysmda_test_fields'][ $post_id ][ $key ] )
		? $GLOBALS['sysmda_test_fields'][ $post_id ][ $key ]
		: false;
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

/**
 * Stub: expands the single tag the pipeline tests use. Core short-circuits on
 * content carrying no bracket at all, and so does the caller, so the stub only
 * has to be faithful about *what* it rewrites — deliberately with no notion of
 * markup, which is the property the code-masking pass exists to compensate for.
 */
function do_shortcode( $content ) {
	return str_replace( '[demo]', 'EXPANDED', $content );
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

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

/**
 * Stub: transliteration of accented Latin characters, used to build the download
 * file name. Only the accents the tests exercise; anything else is left as-is,
 * exactly like core, so the ASCII filter in download_filename() is what has to
 * drop the rest.
 */
function remove_accents( $text ) {
	return strtr(
		$text,
		array(
			'à' => 'a',
			'á' => 'a',
			'è' => 'e',
			'é' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'ò' => 'o',
			'ó' => 'o',
			'ù' => 'u',
			'ú' => 'u',
			'ç' => 'c',
			'ñ' => 'n',
		)
	);
}

/**
 * Stub: core's file-name sanitizer, reduced to the characters that matter here —
 * the ones that must not survive into the `download` attribute.
 */
function sanitize_file_name( $name ) {
	$name = str_replace( array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) ), '', $name );
	$name = preg_replace( '/[\r\n\t -]+/', '-', $name );
	return trim( $name, '.-_' );
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
	// Real WordPress semantics, which this stub used to get wrong: the answer is
	// "does THIS VISITOR still have to supply the password", not "is the post
	// protected". A valid wp-postpass_* cookie makes it false while the post is
	// still protected. Modelling it correctly is what exposes the 0.26.3 defect;
	// the previous version returned `! empty( $post->post_password )` and so
	// quietly encoded the assumption the code was making.
	if ( ! empty( $GLOBALS['sysmda_test_password_cookie'] ) ) {
		return false;
	}

	return ! empty( $post->post_password );
}

/** Stub: site identity, part of the /llms.txt cache validity hash. */
function get_bloginfo( $show = '', $filter = 'raw' ) {
	return isset( $GLOBALS['sysmda_test_bloginfo'][ $show ] ) ? $GLOBALS['sysmda_test_bloginfo'][ $show ] : '';
}

/**
 * Stub: paged post query. Serves slices of a per-type fixture list so the
 * /llms.txt paging can be exercised, and records the pages actually requested.
 */
function get_posts( $args ) {
	$type = isset( $args['post_type'] ) ? $args['post_type'] : '';
	$all  = isset( $GLOBALS['sysmda_test_query_posts'][ $type ] ) ? $GLOBALS['sysmda_test_query_posts'][ $type ] : array();

	$per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
	$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

	$GLOBALS['sysmda_test_query_pages'][] = $paged;

	return array_slice( $all, ( $paged - 1 ) * $per_page, $per_page );
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

/*
 * Escaping stubs. Modelled on the real functions closely enough for the markup
 * assertions to mean something: esc_attr/esc_html encode the characters that
 * would break out of an attribute or a text node, and esc_url additionally drops
 * anything that is not an http(s) URL — so a test feeding `javascript:` a URL
 * still sees it rejected.
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	$url = (string) $url;

	if ( 1 !== preg_match( '#^(https?:)?//#i', $url ) ) {
		return '';
	}

	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/** Stub: no translation catalogs in the harness, the source string is returned. */
function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

/** Stub: unique IDs for multiple shortcode instances on one page. */
function wp_unique_id( $prefix = '' ) {
	static $id = 0;
	++$id;
	return $prefix . $id;
}

/** Asset stubs used by the shortcode's lazy registration path. */
function wp_style_is( $handle, $status = 'enqueued' ) {
	return ! empty( $GLOBALS['sysmda_test_assets']['styles'][ $handle ][ $status ] );
}

function wp_script_is( $handle, $status = 'enqueued' ) {
	return ! empty( $GLOBALS['sysmda_test_assets']['scripts'][ $handle ][ $status ] );
}

function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['sysmda_test_assets']['styles'][ $handle ] = array(
		'registered' => true,
		'enqueued'   => false,
		'src'        => $src,
	);
	return true;
}

function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
	$GLOBALS['sysmda_test_assets']['scripts'][ $handle ] = array(
		'registered' => true,
		'enqueued'   => false,
		'src'        => $src,
	);
	return true;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	return true;
}

function wp_enqueue_style( $handle ) {
	$GLOBALS['sysmda_test_assets']['styles'][ $handle ]['enqueued'] = true;
	return true;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['sysmda_test_assets']['scripts'][ $handle ]['enqueued'] = true;
	return true;
}

function did_action( $hook_name ) {
	return isset( $GLOBALS['sysmda_test_did_actions'][ $hook_name ] )
		? $GLOBALS['sysmda_test_did_actions'][ $hook_name ]
		: 0;
}

function doing_action( $hook_name = null ) {
	return isset( $GLOBALS['sysmda_test_doing_action'] ) && $hook_name === $GLOBALS['sysmda_test_doing_action'];
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sysmda_test_actions'][ $hook_name ][ $priority ][] = $callback;
	return true;
}

function wp_print_styles( $handles = false ) {
	$handles = is_array( $handles ) ? $handles : array( $handles );

	foreach ( $handles as $handle ) {
		if ( isset( $GLOBALS['sysmda_test_assets']['styles'][ $handle ] ) ) {
			$GLOBALS['sysmda_test_assets']['styles'][ $handle ]['done'] = true;
			$GLOBALS['sysmda_test_assets']['styles'][ $handle ]['print_count'] =
				isset( $GLOBALS['sysmda_test_assets']['styles'][ $handle ]['print_count'] )
					? $GLOBALS['sysmda_test_assets']['styles'][ $handle ]['print_count'] + 1
					: 1;
		}
	}

	return array();
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
	public $post_name      = '';
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
require __DIR__ . '/../src/CodeRegions.php';
require __DIR__ . '/../src/ShortcodeCleaner.php';
require __DIR__ . '/../src/BlockCleaner.php';
require __DIR__ . '/../src/ContentRenderer.php';
require __DIR__ . '/../src/CodeFence.php';
require __DIR__ . '/../src/MarkdownConverter.php';

// These implement a library interface, so they can only be loaded with vendor/
// present. MarkdownConverter references them inside a method body, which PHP
// resolves only when that method runs.
if ( $GLOBALS['sysmda_has_vendor'] ) {
	require __DIR__ . '/../src/CodeElementConverter.php';
	require __DIR__ . '/../src/SafeParagraphConverter.php';
}
require __DIR__ . '/../src/PostSupport.php';
require __DIR__ . '/../src/MetadataBuilder.php';
require __DIR__ . '/../src/LlmsTxtController.php';
require __DIR__ . '/../src/MarkdownController.php';
require __DIR__ . '/../src/LiteSpeedCompat.php';
require __DIR__ . '/../src/HitCounter.php';
require __DIR__ . '/../src/AdminSettings.php';
require __DIR__ . '/../src/Shortcodes.php';
require __DIR__ . '/../src/MarkdownActions.php';

use Diecieventi\SystemMarkdownAlternate\AcceptNegotiator;
use Diecieventi\SystemMarkdownAlternate\AdminSettings;
use Diecieventi\SystemMarkdownAlternate\BlockCleaner;
use Diecieventi\SystemMarkdownAlternate\CodeElementConverter;
use Diecieventi\SystemMarkdownAlternate\CodeFence;
use Diecieventi\SystemMarkdownAlternate\CodeRegions;
use Diecieventi\SystemMarkdownAlternate\ContentRenderer;
use Diecieventi\SystemMarkdownAlternate\PostSupport;
use Diecieventi\SystemMarkdownAlternate\HitCounter;
use Diecieventi\SystemMarkdownAlternate\LiteSpeedCompat;
use Diecieventi\SystemMarkdownAlternate\LlmsTxtController;
use Diecieventi\SystemMarkdownAlternate\MarkdownController;
use Diecieventi\SystemMarkdownAlternate\MarkdownConverter;
use Diecieventi\SystemMarkdownAlternate\MarkdownActions;
use Diecieventi\SystemMarkdownAlternate\MetadataBuilder;
use Diecieventi\SystemMarkdownAlternate\ShortcodeCleaner;
use Diecieventi\SystemMarkdownAlternate\Shortcodes;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\PreConverterInterface;

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

/**
 * Creates an invokable reflection method across the supported PHP range.
 *
 * Private methods require setAccessible() through PHP 8.0. Since PHP 8.1 they
 * are invokable without it, and PHP 8.5 deprecates the now-no-op call.
 */
function sysmda_reflection_method( $class, $method ) {
	$reflection = new ReflectionMethod( $class, $method );

	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}

	return $reflection;
}

// ─── AcceptNegotiator ────────────────────────────────────────────────────────

// parse: default q, clamping, duplicates at maximum q, malformed ranges ignored.
check( 'parse: q default 1.0', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html' ) );
check( 'parse: explicit q', array( 'text/html' => 0.5 ), AcceptNegotiator::parse( 'text/html;q=0.5' ) );
check( 'parse: clamp to [0,1]', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html;q=7' ) );
// A non-numeric weight used to default to 1.0, i.e. the STRONGEST preference:
// `text/markdown;q=banana` then outranked `text/html` and served Markdown to a
// client that never asked for it. The range is dropped instead. A numeric
// weight is still kept and clamped, out of range included.
check( 'parse: non-numeric q drops the range', array(), AcceptNegotiator::parse( 'text/html;q=abc' ) );
check( 'parse: empty q drops the range', array(), AcceptNegotiator::parse( 'text/html;q=' ) );
check(
	'parse: malformed q cannot outrank a valid range',
	array( 'text/html' => 1.0 ),
	AcceptNegotiator::parse( 'text/html,text/markdown;q=banana' )
);
check(
	'quality: malformed markdown weight loses to html',
	0.0,
	AcceptNegotiator::quality( 'text/html,text/markdown;q=banana', 'text/markdown' )
);
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

/**
 * A parsed block that carries saved markup, as post content does.
 *
 * make_block() below builds structure only (empty innerHTML): it exists for the
 * cleaning tests, which care about which blocks survive. The source-content
 * passes care about the text the survivors carry, so they need the markup too.
 */
function sysmda_source_block( $name, $inner_html, $attrs = array() ) {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerContent' => array( $inner_html ),
		'innerHTML'    => $inner_html,
	);
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
$sysmda_abs_method = sysmda_reflection_method( ContentRenderer::class, 'absolutize' );

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

// ─── ContentRenderer::expand_shortcodes ──────────────────────────────────────
//
// Shortcodes have to be expanded on both source branches — render_block() does
// not do it, and the pipeline skips the_content, which is what does it on the
// front end — while never being expanded inside a code region, where the text
// is the content rather than an instruction. Private, so exercised through
// reflection like absolutize() above.

$sysmda_expand_method = sysmda_reflection_method( ContentRenderer::class, 'expand_shortcodes' );

$sysmda_expand = function ( $html ) use ( $sysmda_expand_method, $sysmda_renderer ) {
	return $sysmda_expand_method->invoke( $sysmda_renderer, $html );
};

check( 'shortcodes: expanded in prose', '<p>EXPANDED</p>', $sysmda_expand( '<p>[demo]</p>' ) );
check( 'shortcodes: content without a bracket is returned as it is', '<p>plain</p>', $sysmda_expand( '<p>plain</p>' ) );

// The three shapes a code sample arrives in: a bare <pre>, an inline <code>,
// and the <pre><code> pair every core code block and highlighter produces.
check( 'shortcodes: protected inside pre', '<pre>[demo]</pre>', $sysmda_expand( '<pre>[demo]</pre>' ) );
check( 'shortcodes: protected inside inline code', '<p>see <code>[demo]</code></p>', $sysmda_expand( '<p>see <code>[demo]</code></p>' ) );
check(
	'shortcodes: protected inside pre > code',
	'<pre class="wp-block-code"><code class="language-php">[demo]</code></pre>',
	$sysmda_expand( '<pre class="wp-block-code"><code class="language-php">[demo]</code></pre>' )
);

// Tag names are matched case-insensitively, and the closing tag has to be the
// matching one: a backreference, not "the next closing tag of any kind".
check( 'shortcodes: protected inside uppercase PRE', '<PRE>[demo]</PRE>', $sysmda_expand( '<PRE>[demo]</PRE>' ) );

// Every region is restored, in its own place, with the prose around it expanded.
check(
	'shortcodes: prose expanded around protected regions',
	'<p>EXPANDED</p><pre>A [demo]</pre><p>EXPANDED</p><pre>B [demo]</pre>',
	$sysmda_expand( '<p>[demo]</p><pre>A [demo]</pre><p>[demo]</p><pre>B [demo]</pre>' )
);

// A `pre`-prefixed tag name is not a code region: \b must not match inside a word.
check( 'shortcodes: preview element is not a code region', '<preview>EXPANDED</preview>', $sysmda_expand( '<preview>[demo]</preview>' ) );

// ─── ContentRenderer::strip_excluded_content ─────────────────────────────────
//
// The exclusion rule applied outside the render pipeline, for the front-matter
// description fallback. Content that carries no excluded class must come back
// byte-identical: the description of an ordinary post may not change shape just
// because this pass exists.

check(
	'strip_excluded: region with the class removed',
	'<p>Keep</p><p>Keep too</p>',
	$sysmda_renderer->strip_excluded_content( '<p>Keep</p><div class="md-exclude"><p>Drop</p></div><p>Keep too</p>' )
);
check(
	'strip_excluded: nested element with the class removed',
	'<div class="wrapper"><p>Keep</p></div>',
	$sysmda_renderer->strip_excluded_content( '<div class="wrapper"><p>Keep</p><span class="no-md">Drop</span></div>' )
);
check(
	'strip_excluded: content without the class is untouched',
	'<p>Nothing to strip &amp; nothing to parse</p>',
	$sysmda_renderer->strip_excluded_content( '<p>Nothing to strip &amp; nothing to parse</p>' )
);

// The cheap substring guard says "maybe" for a class name that is only prose;
// the DOM pass then removes nothing, and the input must survive unchanged
// rather than come back through a serialization round trip.
check(
	'strip_excluded: class named in prose only is untouched',
	'<p>Mark a section with md-exclude &amp; it disappears</p>',
	$sysmda_renderer->strip_excluded_content( '<p>Mark a section with md-exclude &amp; it disappears</p>' )
);

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

// ─── MetadataBuilder::download_filename ──────────────────────────────────────

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';

check( 'filename: from the slug', 'my-post.md', MetadataBuilder::download_filename( new WP_Post( array( 'post_name' => 'my-post' ) ) ) );

// Accents are transliterated rather than dropped, so the name stays readable.
check( 'filename: accents transliterated', 'perche-no.md', MetadataBuilder::download_filename( new WP_Post( array( 'post_name' => 'perché-no' ) ) ) );

// WordPress stores non-Latin slugs percent-encoded. Without the rawurldecode()
// the hex would survive as text and produce `d0bfd180d0b8.md`.
check(
	'filename: percent-encoded non-Latin slug → ID fallback',
	'post-42.md',
	MetadataBuilder::download_filename(
		new WP_Post(
			array(
				'ID'        => 42,
				'post_name' => '%d0%bf%d1%80%d0%b8',
			)
		)
	)
);

check( 'filename: empty slug → ID fallback', 'post-7.md', MetadataBuilder::download_filename( new WP_Post( array( 'ID' => 7 ) ) ) );

// What matters is the invariant, not the exact spelling: whatever a hostile slug
// contains, the result stays within the safe set, so it cannot break out of the
// attribute it is interpolated into. Asserted as a property so the test
// exercises download_filename()'s own filter rather than the
// sanitize_file_name() stub.
foreach (
	array(
		'quote'     => 'evil"; rm -rf /".md',
		'backslash' => 'back\\slash',
		'crlf'      => "line\r\nInjected: header",
		'non-latin' => 'привет-мир',
		'spaces'    => '  padded name  ',
	) as $sysmda_case => $sysmda_slug
) {
	$sysmda_name = MetadataBuilder::download_filename( new WP_Post( array( 'ID' => 3, 'post_name' => $sysmda_slug ) ) );
	check( 'filename: safe charset (' . $sysmda_case . ')', 1, preg_match( '/^[A-Za-z0-9._-]+\.md$/', $sysmda_name ) );
}

// ─── MetadataBuilder::description ─────────────────────────────────────

$metadata = new MetadataBuilder( new ShortcodeCleaner(), $sysmda_renderer );

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

// The fallback reads the post content, not the rendered body, so the exclusion
// rules have to be applied to it as well: whatever the body refuses to publish
// may not be summarised into the front matter either.
$p = new WP_Post(
	array(
		'ID'           => 22,
		'post_content' => '<p>Visible.</p><div class="md-exclude"><p>Confidential.</p></div><p>Also visible.</p>',
	)
);
check( 'description: md-exclude region omitted', 'Visible. Also visible.', $metadata->description( $p ) );

// Block markup takes the same path: the class is on the rendered element inside
// the block, and the block delimiters are stripped as the comments they are.
$sysmda_desc_src = '<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"md-exclude"} --><p class="md-exclude">Confidential.</p><!-- /wp:paragraph -->';

$GLOBALS['sysmda_test_parsed'][ $sysmda_desc_src ] = array(
	sysmda_source_block( 'core/paragraph', '<p>Intro.</p>' ),
	sysmda_source_block( 'core/paragraph', '<p class="md-exclude">Confidential.</p>', array( 'className' => 'md-exclude' ) ),
);

$p = new WP_Post( array( 'ID' => 23, 'post_content' => $sysmda_desc_src ) );
check( 'description: md-exclude block omitted', 'Intro.', $metadata->description( $p ) );

// A block excluded by NAME. The shipped defaults are all dynamic blocks with no
// text of their own, which is what made this look safe to skip; but "Excluded
// blocks" is a settings-page field, so a site can exclude a static block whose
// text sits in the saved markup. The body drops it, and so must the fallback.
$sysmda_desc_src = '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph --><!-- wp:pullquote --><blockquote>Excluded by name.</blockquote><!-- /wp:pullquote -->';

$GLOBALS['sysmda_test_parsed'][ $sysmda_desc_src ] = array(
	sysmda_source_block( 'core/paragraph', '<p>Kept.</p>' ),
	sysmda_source_block( 'core/pullquote', '<blockquote>Excluded by name.</blockquote>' ),
);
$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_block_names'] = array( 'core/pullquote' );

$p = new WP_Post( array( 'ID' => 24, 'post_content' => $sysmda_desc_src ) );
check( 'description: block excluded by name omitted', 'Kept.', $metadata->description( $p ) );

unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_block_names'] );

// A block excluded through attrs.className whose saved inner HTML does NOT
// repeat the class attribute. The element-level pass cannot see it — there is no
// class to match on — so only the block-level pass removes it.
$sysmda_desc_src = '<!-- wp:acme/panel {"className":"md-exclude"} --><div>Hidden panel copy.</div><!-- /wp:acme/panel --><!-- wp:paragraph --><p>Shown.</p><!-- /wp:paragraph -->';

$GLOBALS['sysmda_test_parsed'][ $sysmda_desc_src ] = array(
	sysmda_source_block( 'acme/panel', '<div>Hidden panel copy.</div>', array( 'className' => 'md-exclude' ) ),
	sysmda_source_block( 'core/paragraph', '<p>Shown.</p>' ),
);

$p = new WP_Post( array( 'ID' => 25, 'post_content' => $sysmda_desc_src ) );
check( 'description: className block without a class attribute omitted', 'Shown.', $metadata->description( $p ) );

// Ordinary block content keeps the description it had before the block pass
// existed: nothing is excluded, so nothing is dropped.
$sysmda_desc_src = '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->';

$GLOBALS['sysmda_test_parsed'][ $sysmda_desc_src ] = array(
	sysmda_source_block( 'core/paragraph', '<p>First.</p>' ),
	sysmda_source_block( 'core/paragraph', '<p>Second.</p>' ),
);

$p = new WP_Post( array( 'ID' => 26, 'post_content' => $sysmda_desc_src ) );
check( 'description: ordinary block content unaffected', 'First. Second.', $metadata->description( $p ) );

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

// (2b) Opt-out: `sysmda_front_matter_enabled` suppresses the whole block. The
// filter must not leave a stray `---` behind, and the default must stay on.
$GLOBALS['sysmda_test_filters']['sysmda_front_matter_enabled'] = false;
check( 'front matter: filter off returns an empty block', '', $metadata->build_front_matter( $sysmda_min_post ) );
unset( $GLOBALS['sysmda_test_filters']['sysmda_front_matter_enabled'] );
check( 'front matter: on by default', $sysmda_min_expected, $metadata->build_front_matter( $sysmda_min_post ) );

// assemble_document: the blank line after the front matter belongs to the block,
// so suppressing it must start the document at `# `, not at an empty line. With
// the block present the layout is byte-identical to the pre-0.30.0 formula.
check(
	'assemble: front matter present, layout unchanged',
	"---\ntitle: \"X\"\n---\n\n# Title\n\nBody\n",
	MarkdownController::assemble_document( "---\ntitle: \"X\"\n---\n", 'Title', '', "Body\n" )
);
check(
	'assemble: no front matter, document starts with the H1',
	"# Title\n\nBody\n",
	MarkdownController::assemble_document( '', 'Title', '', "Body\n" )
);
check(
	'assemble: preamble sits between the H1 and the body',
	"# Title\n\n*Subtitle*\n\nBody\n",
	MarkdownController::assemble_document( '', 'Title', "*Subtitle*\n\n", "Body\n" )
);

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
// Control characters may not appear raw inside a YAML double-quoted scalar. Not
// reachable from wp-admin, but a title can arrive from an import, a REST write
// or one of this plugin's own filters, and the contract here is that the result
// parses whatever the source was.
check( 'scalar: NUL and BEL dropped', 'title: "ab"', $sysmda_title_line( "a\x00\x07b" ) );
check( 'scalar: ESC dropped', 'title: "ab"', $sysmda_title_line( "a\x1Bb" ) );
check( 'scalar: DEL dropped', 'title: "ab"', $sysmda_title_line( "a\x7Fb" ) );
check( 'scalar: C1 controls dropped', 'title: "ab"', $sysmda_title_line( "a\xC2\x85b" ) );
// …while multibyte characters, whose bytes are all >= 0x80, are untouched.
check( 'scalar: accented text preserved', 'title: "città è così"', $sysmda_title_line( 'città è così' ) );
check( 'scalar: emoji preserved', 'title: "ok 🎉"', $sysmda_title_line( 'ok 🎉' ) );

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
$sysmda_terms_fp = MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post );

// A taxonomy the post has no terms in (false) or an unregistered one (WP_Error).
$GLOBALS['sysmda_test_terms'][60]['genre'] = false;
check( 'taxonomies: no terms => omitted', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );
check( 'taxonomies: selected empty state keeps a fingerprint', true, '' !== MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );
check( 'taxonomies: removing the last term moves the fingerprint', true, $sysmda_terms_fp !== MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );
$GLOBALS['sysmda_test_terms'][60]['genre'] = new WP_Error( 'invalid_taxonomy' );
check( 'taxonomies: WP_Error => omitted', array(), MetadataBuilder::taxonomy_terms( $sysmda_tax_post ) );
check( 'taxonomies: selected unavailable state keeps a fingerprint', true, '' !== MetadataBuilder::taxonomies_fingerprint( $sysmda_tax_post ) );

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
// cache_version() is private and is both the cache-validity hash and the input
// to the weak ETag, so it is checked through reflection: this is the one place
// where an error would either invalidate every cached .md on upgrade (toggle
// off) or keep serving 304 with stale terms (toggle on).

$sysmda_controller  = new MarkdownController(
	new ContentRenderer( new BlockCleaner( new ShortcodeCleaner() ), new ShortcodeCleaner() ),
	new MarkdownConverter(),
	$metadata
);
$sysmda_cv_method = sysmda_reflection_method( MarkdownController::class, 'cache_version' );
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

// ─── cache_version: dependencies outside the post row ────────────────
//
// Same failure mode as the taxonomies above, found by the 0.26.3 review (H1):
// a synced pattern, the featured image, the Rank Math description and ACF
// fields all change the emitted Markdown while `post_modified_gmt` stays put.
// Without them in the validator a conditional request answers 304 with stale
// content — body cache or not. Each assertion below fails against 0.26.3.

$GLOBALS['sysmda_test_filters'] = array();

// A post whose only content is a reference to a synced pattern.
$sysmda_dep_post = new WP_Post(
	array(
		'ID'                => 62,
		'post_type'         => 'post',
		'post_content'      => 'DEP',
		'permalink'         => 'https://example.com/dep/',
		'post_modified_gmt' => '2026-07-01 08:30:00',
	)
);
$GLOBALS['sysmda_test_parsed']['DEP'] = array(
	array(
		'blockName'   => 'core/block',
		'attrs'       => array( 'ref' => 99 ),
		'innerHTML'   => '',
		'innerBlocks' => array(),
	),
);
$GLOBALS['sysmda_test_posts'][99] = new WP_Post(
	array(
		'ID'                => 99,
		'post_type'         => 'wp_block',
		'post_content'      => 'PATTERN V1',
		'post_modified_gmt' => '2026-07-01 09:00:00',
	)
);

$sysmda_dep_before = $sysmda_cv( $sysmda_dep_post );

// The editor saves the synced pattern. The article is NOT touched.
$GLOBALS['sysmda_test_posts'][99]->post_modified_gmt = '2026-07-02 11:00:00';
check(
	'cache_version: editing a synced pattern moves the ETag',
	true,
	$sysmda_dep_before !== $sysmda_cv( $sysmda_dep_post )
);

// Transitive references: the article points at pattern 99, which itself embeds
// pattern 98. BlockCleaner expands both, so editing only 98 changes the body
// while the article AND pattern 99 stay untouched.
$GLOBALS['sysmda_test_posts'][99]->post_content = 'PATTERN V1';
$GLOBALS['sysmda_test_parsed']['PATTERN V1']    = array(
	array(
		'blockName'   => 'core/block',
		'attrs'       => array( 'ref' => 98 ),
		'innerHTML'   => '',
		'innerBlocks' => array(),
	),
);
$GLOBALS['sysmda_test_posts'][98] = new WP_Post(
	array(
		'ID'                => 98,
		'post_type'         => 'wp_block',
		'post_content'      => 'NESTED',
		'post_modified_gmt' => '2026-07-01 09:00:00',
	)
);

$sysmda_nested_before = $sysmda_cv( $sysmda_dep_post );
$GLOBALS['sysmda_test_posts'][98]->post_modified_gmt = '2026-07-03 12:00:00';
check(
	'cache_version: editing a nested synced pattern moves the ETag',
	true,
	$sysmda_nested_before !== $sysmda_cv( $sysmda_dep_post )
);

// A reference cycle must not recurse forever: 98 points back at 99.
$GLOBALS['sysmda_test_posts'][98]->post_content = 'CYCLE';
$GLOBALS['sysmda_test_parsed']['CYCLE']         = array(
	array(
		'blockName'   => 'core/block',
		'attrs'       => array( 'ref' => 99 ),
		'innerHTML'   => '',
		'innerBlocks' => array(),
	),
);
check( 'cache_version: reference cycle terminates', true, '' !== $sysmda_cv( $sysmda_dep_post ) );

// A post with a featured image: swapping the image or rewriting its alt text
// changes the front matter without touching the post row.
$sysmda_img_post = new WP_Post(
	array(
		'ID'                => 63,
		'post_type'         => 'post',
		'permalink'         => 'https://example.com/img/',
		'post_modified_gmt' => '2026-07-01 08:30:00',
		'sysmda_thumb_id'   => 77,
	)
);
$GLOBALS['sysmda_test_posts'][77] = new WP_Post(
	array(
		'ID'                => 77,
		'post_type'         => 'attachment',
		'post_modified_gmt' => '2026-06-01 07:00:00',
	)
);
$GLOBALS['sysmda_test_meta'][77]['_wp_attachment_image_alt'] = 'Before';

$GLOBALS['sysmda_test_meta'][77]['_wp_attached_file']        = '2026/06/before.jpg';

$sysmda_img_before = $sysmda_cv( $sysmda_img_post );
$GLOBALS['sysmda_test_meta'][77]['_wp_attachment_image_alt'] = 'After';
check(
	'cache_version: featured-image alt change moves the ETag',
	true,
	$sysmda_img_before !== $sysmda_cv( $sysmda_img_post )
);

// What the front matter prints is the resolved URL, not the attachment ID, so a
// plugin swapping the file behind an existing attachment rewrites
// `featured_image` while leaving the attachment row and its alt text alone.
$sysmda_img_file_before                               = $sysmda_cv( $sysmda_img_post );
$GLOBALS['sysmda_test_meta'][77]['_wp_attached_file'] = '2026/06/after.jpg';
check(
	'cache_version: replacing the attached file moves the ETag',
	true,
	$sysmda_img_file_before !== $sysmda_cv( $sysmda_img_post )
);

// The description comes from post meta, which update_post_meta() writes without
// moving post_modified_gmt.
$sysmda_desc_before = $sysmda_cv( $sysmda_cv_post );
$GLOBALS['sysmda_test_meta'][61]['rank_math_description'] = 'A brand new description';
check(
	'cache_version: description meta change moves the ETag',
	true,
	$sysmda_desc_before !== $sysmda_cv( $sysmda_cv_post )
);
// Leave post 61 dependency-free: the conditional-request tests below use it as
// the post whose date IS a strong validator, which a lingering description
// would silently turn into the opposite case.
unset( $GLOBALS['sysmda_test_meta'][61]['rank_math_description'] );

// Generic ACF source fields join post_content before the block branch runs. A
// synced pattern referenced there is therefore rendered exactly like one in
// the post row, and must share the same transitive dependency fingerprint.
$sysmda_acf_pattern_markup = '<!-- wp:block {"ref":97} /-->';
$sysmda_acf_post           = new WP_Post(
	array(
		'ID'                => 64,
		'post_type'         => 'post',
		'post_content'      => '',
		'permalink'         => 'https://example.com/acf-pattern/',
		'post_modified_gmt' => '2026-07-01 08:30:00',
	)
);
$GLOBALS['sysmda_test_fields'][64]['layout']            = $sysmda_acf_pattern_markup;
$GLOBALS['sysmda_test_parsed'][ $sysmda_acf_pattern_markup ] = array(
	array(
		'blockName'   => 'core/block',
		'attrs'       => array( 'ref' => 97 ),
		'innerHTML'   => '',
		'innerBlocks' => array(),
	),
);
$GLOBALS['sysmda_test_posts'][97] = new WP_Post(
	array(
		'ID'                => 97,
		'post_type'         => 'wp_block',
		'post_content'      => 'ACF PATTERN',
		'post_modified_gmt' => '2026-07-01 09:00:00',
	)
);
$GLOBALS['sysmda_test_filters']['sysmda_acf_field_keys'] = array( 'layout' );

$sysmda_acf_pattern_before = $sysmda_cv( $sysmda_acf_post );
$GLOBALS['sysmda_test_posts'][97]->post_modified_gmt = '2026-07-04 10:00:00';
check(
	'cache_version: editing a pattern referenced by ACF moves the ETag',
	true,
	$sysmda_acf_pattern_before !== $sysmda_cv( $sysmda_acf_post )
);

unset(
	$GLOBALS['sysmda_test_filters']['sysmda_acf_field_keys'],
	$GLOBALS['sysmda_test_fields'][64],
	$sysmda_acf_pattern_before,
	$sysmda_acf_pattern_markup,
	$sysmda_acf_post
);

// Escape hatch for output this plugin cannot fingerprint (dynamic blocks,
// shortcodes, site filters reading options or remote data).
$sysmda_extra_before = $sysmda_cv( $sysmda_img_post );
$GLOBALS['sysmda_test_filters']['sysmda_markdown_cache_dependencies'] = array( 'stock:42' );
check(
	'cache_version: sysmda_markdown_cache_dependencies reaches the ETag',
	true,
	$sysmda_extra_before !== $sysmda_cv( $sysmda_img_post )
);
$GLOBALS['sysmda_test_filters'] = array();

// ─── should_reject_unacceptable: a broken Accept is not a 406 ────────
//
// Dropping a malformed range can leave nothing parseable. That is a broken
// client, not one refusing HTML, so it must keep getting the HTML page rather
// than an error page.
$sysmda_406_method = sysmda_reflection_method( MarkdownController::class, 'should_reject_unacceptable' );
$sysmda_406 = function ( $accept ) use ( $sysmda_406_method, $sysmda_controller ) {
	$_SERVER['HTTP_ACCEPT'] = $accept;
	$result                 = $sysmda_406_method->invoke( $sysmda_controller );
	unset( $_SERVER['HTTP_ACCEPT'] );
	return $result;
};

check( '406: unparseable Accept is not rejected', false, $sysmda_406( 'text/html;q=abc' ) );
check( '406: Accept refusing both is still rejected', true, $sysmda_406( 'application/json' ) );
check( '406: normal browser Accept is not rejected', false, $sysmda_406( 'text/html,*/*;q=0.8' ) );

// The `format` override switches the 406 off only for the value that actually
// names a representation. Testing for the parameter's mere PRESENCE meant
// `?format=banana` — or any stray parameter of that name — silently disabled it.
$_GET['format'] = 'markdown';
check( '406: ?format=markdown suppresses the rejection', false, $sysmda_406( 'application/json' ) );
$_GET['format'] = 'banana';
check( '406: an unrecognized format does not suppress it', true, $sysmda_406( 'application/json' ) );
$_GET['format'] = array( 'markdown' );
check( '406: an array format does not suppress it', true, $sysmda_406( 'application/json' ) );
unset( $_GET['format'] );

// ─── vary_covers_accept: field names, not substrings ─────────────────
//
// `Vary: Accept` is what stops a cache from handing the HTML of a permalink to
// a Markdown-preferring request (and the reverse). The check that decides
// whether it still has to be sent used to look for the substring "accept"
// anywhere in an existing Vary header, so `Accept-Encoding` — which practically
// every compressing stack emits — read as "already covered" and the header was
// never added at all.
check( 'vary: nothing sent yet', false, MarkdownController::vary_covers_accept( array() ) );
check( 'vary: Accept-Encoding does not cover Accept', false, MarkdownController::vary_covers_accept( array( 'Vary: Accept-Encoding' ) ) );
check( 'vary: Accept-Language does not cover Accept', false, MarkdownController::vary_covers_accept( array( 'Vary: Accept-Language' ) ) );
check( 'vary: a comma list of neighbours does not cover it', false, MarkdownController::vary_covers_accept( array( 'Vary: Accept-Encoding, Accept-Language' ) ) );
check( 'vary: exact Accept covers it', true, MarkdownController::vary_covers_accept( array( 'Vary: Accept' ) ) );
check( 'vary: Accept inside a comma list covers it', true, MarkdownController::vary_covers_accept( array( 'Vary: Accept-Encoding, Accept' ) ) );
check( 'vary: matching is case-insensitive', true, MarkdownController::vary_covers_accept( array( 'vary: accept' ) ) );
check( 'vary: a second Vary header is inspected too', true, MarkdownController::vary_covers_accept( array( 'Vary: User-Agent', 'Vary: Accept' ) ) );
check( 'vary: the * wildcard covers everything', true, MarkdownController::vary_covers_accept( array( 'Vary: *' ) ) );
check( 'vary: other headers are ignored', false, MarkdownController::vary_covers_accept( array( 'X-Accept: yes', 'Content-Type: text/html' ) ) );

// ─── Link alternate: append without duplicating discovery metadata ──
//
// WordPress, a theme or another plugin may already have emitted one or more
// Link fields. The Markdown alternate is appended, never allowed to overwrite
// them, but the exact relation/target must not be repeated. Link's grammar also
// permits commas inside a URI or quoted parameter, so a plain explode(',') is
// not sufficient for the duplicate check.
$sysmda_alternate = 'https://example.com/article.md';
check( 'Link alternate: nothing sent yet', false, MarkdownController::link_header_has_alternate( array(), $sysmda_alternate ) );
check( 'Link alternate: X-Link is not a Link field', false, MarkdownController::link_header_has_alternate( array( 'X-Link: <https://example.com/article.md>; rel="alternate"' ), $sysmda_alternate ) );
check( 'Link alternate: canonical is not alternate', false, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md>; rel="canonical"' ), $sysmda_alternate ) );
check( 'Link alternate: a different target is not a duplicate', false, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/other.md>; rel="alternate"' ), $sysmda_alternate ) );
check( 'Link alternate: typed alternate is detected', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md>; rel="alternate"; type="text/markdown"' ), $sysmda_alternate ) );
check( 'Link alternate: field and relation matching is case-insensitive', true, MarkdownController::link_header_has_alternate( array( 'lInK: <https://example.com/article.md>; ReL="AlTeRnAtE"' ), $sysmda_alternate ) );
check( 'Link alternate: relation token list is detected', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md>; rel="next alternate"' ), $sysmda_alternate ) );
check( 'Link alternate: an untyped alternate still deduplicates', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md>; rel=alternate' ), $sysmda_alternate ) );
check( 'Link alternate: repeated Link fields are inspected', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/>; rel="canonical"', 'Link: <https://example.com/article.md>; rel="alternate"' ), $sysmda_alternate ) );
check( 'Link alternate: comma-separated link-values are inspected', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/>; rel="canonical", <https://example.com/article.md>; rel="alternate"' ), $sysmda_alternate ) );
check( 'Link alternate: comma inside the URI is not a separator', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md?parts=one,two>; rel="alternate"' ), 'https://example.com/article.md?parts=one,two' ) );
check( 'Link alternate: comma inside a quoted parameter is not a separator', true, MarkdownController::link_header_has_alternate( array( 'Link: <https://example.com/article.md>; title="One, two"; rel="alternate"' ), $sysmda_alternate ) );

// ─── handle_conditional: If-Modified-Since must not go stale ─────────
//
// The ETag carries the taxonomy fingerprint, but Last-Modified is derived from
// post_modified_gmt, which a term change does NOT touch. A client sending only
// If-Modified-Since would therefore be told "304 Not Modified" while its copy
// has outdated terms, so the date is only honoured while it is a strong
// validator for the representation.

$sysmda_hc_method = sysmda_reflection_method( MarkdownController::class, 'handle_conditional' );

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

// Removing the last selected term must not make the date strong again: the
// removal can happen outside the post row, so its timestamp may still describe
// the old body. The selected-but-empty fingerprint preserves that history.
$sysmda_saved_genre_terms = $GLOBALS['sysmda_test_terms'][61]['genre'];
unset( $GLOBALS['sysmda_test_terms'][61]['genre'] );
check( 'conditional: IMS remains ignored after the last selected term is removed', false, $sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since ) );
check( 'conditional: no stale 304 after the last selected term is removed', array(), $GLOBALS['sysmda_test_status'] );
$GLOBALS['sysmda_test_terms'][61]['genre'] = $sysmda_saved_genre_terms;
unset( $sysmda_saved_genre_terms );

// If-None-Match still works with the block on: the ETag is taxonomy-aware, so
// it remains a reliable validator (this is the common browser/crawler case).
$GLOBALS['sysmda_test_status'] = array();
unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
$_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $sysmda_cv( $sysmda_cv_post ) . '"';
check( 'conditional: matching ETag still yields 304', true, $sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) ) );
// The tag this version issues comes back in its weak form, and revalidates too.
$_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"' . $sysmda_cv( $sysmda_cv_post ) . '"';
check( 'conditional: the weak tag we issue yields 304', true, $sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) ) );
$_SERVER['HTTP_IF_NONE_MATCH'] = '"stale-validator"';
check( 'conditional: stale ETag yields the full body', false, $sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) ) );
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

// The same rule for the out-of-post dependencies, and it is NOT covered by the
// taxonomy check above: a client sending only If-Modified-Since never presents
// the ETag, so folding synced patterns, the featured image, the description and
// ACF into the ETag alone still answered 304 with a stale body. Reported as a P1
// on the PR that introduced the fingerprint. Fails when date_is_strong_validator()
// looks at the taxonomy fingerprint only.
$GLOBALS['sysmda_test_filters'] = array();
$sysmda_dep_since               = gmdate( 'D, d M Y H:i:s', strtotime( '2026-07-05 08:30:00 GMT' ) ) . ' GMT';
check(
	'conditional: IMS ignored while the post has out-of-post dependencies',
	false,
	$sysmda_ims( $sysmda_dep_post, $sysmda_dep_since )
);
check( 'conditional: no 304 for a post with dependencies', array(), $GLOBALS['sysmda_test_status'] );
check(
	'conditional: IMS still honoured for a post with none',
	true,
	$sysmda_ims( $sysmda_cv_post, $sysmda_dep_since )
);

// The third input the two fingerprints cannot describe: the salt. It moves for
// reasons that belong to no post — a settings save, the permalink structure,
// the home URL, the site timezone, an author rename, a category or tag rename —
// and each of those rewrites the output of posts whose post_modified_gmt has
// not moved. A client sending only If-Modified-Since presents no ETag, so
// without this the date answered 304 with a body the salt had already
// invalidated, for every post older than the change.
$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = (string) ( strtotime( '2026-07-02 00:00:00 GMT' ) . '-a1b2c3d4' );
check(
	'conditional: IMS ignored once the salt is newer than the post',
	false,
	$sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since )
);
check( 'conditional: no stale 304 after a salt bump', array(), $GLOBALS['sysmda_test_status'] );

// Equality is ambiguous at one-second resolution: a post save and a site-wide
// invalidation landing in the same second are indistinguishable, and if the
// salt came second the date is already lying. Ambiguity resolves against the
// date.
$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = (string) ( strtotime( '2026-07-01 08:30:00 GMT' ) . '-a1b2c3d4' );
check(
	'conditional: IMS ignored when salt and post share a second',
	false,
	$sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since )
);

// A salt older than the post's own modification date says nothing about it: the
// post has been rebuilt since, so the date is trustworthy again. This is what
// keeps a single settings save from disabling the IMS path for good.
$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = (string) ( strtotime( '2026-06-01 00:00:00 GMT' ) . '-a1b2c3d4' );
check(
	'conditional: IMS honoured again once the post is newer than the salt',
	true,
	$sysmda_ims( $sysmda_cv_post, $sysmda_fresh_since )
);

// An authenticated request is rebuilt rather than served from the shared cache,
// so it must not be answered 304 on a validator that describes that shared
// body: the browser would reuse a copy built for everyone, off an If-None-Match
// kept from an earlier anonymous fetch.
$GLOBALS['sysmda_test_status'] = array();
$GLOBALS['sysmda_test_logged_in'] = true;
$_SERVER['HTTP_IF_NONE_MATCH']    = 'W/"' . $sysmda_cv( $sysmda_cv_post ) . '"';
check(
	'conditional: an authenticated request is never answered 304',
	false,
	$sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) )
);
check( 'conditional: no 304 sent to an authenticated visitor', array(), $GLOBALS['sysmda_test_status'] );
// The very same request, anonymous, still revalidates — the split is the only
// thing deciding it.
$GLOBALS['sysmda_test_logged_in'] = false;
check(
	'conditional: the same request anonymous still yields 304',
	true,
	$sysmda_hc_method->invoke( $sysmda_controller, $sysmda_cv_post, $sysmda_cv( $sysmda_cv_post ) )
);
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
$GLOBALS['sysmda_test_status'] = array();

// Back to the default state so later assertions are unaffected.
$GLOBALS['sysmda_test_filters'] = array();
$GLOBALS['sysmda_test_taxonomies'] = array();
$GLOBALS['sysmda_test_status'] = array();
unset( $GLOBALS['sysmda_test_terms'][60], $GLOBALS['sysmda_test_terms'][61] );
unset( $GLOBALS['sysmda_test_options']['sysmda_cache_salt'] );

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

// ─── LlmsTxtController::servable_posts (the limit counts ELIGIBLE posts) ──────
//
// Entries are filtered through is_servable() after the query, so asking for
// exactly $limit rows and filtering afterwards returns fewer than $limit as
// soon as the newest batch holds an ineligible post — and the older eligible
// posts behind it are never reached. In the extreme a whole section vanishes
// while the site still has servable content of that type.

$sysmda_sp_method = sysmda_reflection_method( LlmsTxtController::class, 'servable_posts' );
$sysmda_sp_ctrl = new LlmsTxtController( new MetadataBuilder( new ShortcodeCleaner(), $sysmda_renderer ) );

/** Builds a fixture list: $formats entries, '' meaning a standard (servable) format. */
$sysmda_sp_fixture = static function ( array $formats ) {
	$posts = array();
	foreach ( $formats as $i => $format ) {
		$args = array(
			'ID'          => 900 + $i,
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		if ( '' !== $format ) {
			$args['post_format'] = $format;
		}
		$posts[] = new WP_Post( $args );
	}
	return $posts;
};

$sysmda_sp_run = static function ( array $formats, $limit ) use ( $sysmda_sp_method, $sysmda_sp_ctrl, $sysmda_sp_fixture ) {
	$GLOBALS['sysmda_test_query_posts']['post'] = $sysmda_sp_fixture( $formats );
	$GLOBALS['sysmda_test_query_pages']         = array();
	return $sysmda_sp_method->invoke( $sysmda_sp_ctrl, 'post', $limit, false );
};

$GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] = array( 'post' );

// Three of the newest four are asides: a single-page query would have returned
// one entry out of the three requested, and stopped there.
check(
	'llms: the limit counts servable posts, not rows',
	3,
	count( $sysmda_sp_run( array( 'aside', 'aside', '', 'aside', '', '', '' ), 3 ) )
);
check( 'llms: it paged to find them', array( 1, 2 ), $GLOBALS['sysmda_test_query_pages'] );

// The oldest eligible posts are reached, in date order, and none is duplicated.
check(
	'llms: the entries are the eligible ones in order',
	array( 902, 904, 905 ),
	array_map( static function ( $p ) {
		return $p->ID;
	}, $sysmda_sp_run( array( 'aside', 'aside', '', 'aside', '', '', '' ), 3 ) )
);

// A type with fewer eligible posts than requested stops at the last page rather
// than paging to the cap: no later page can add anything.
$sysmda_sp_short = $sysmda_sp_run( array( '', 'aside' ), 5 );
check( 'llms: a short type yields what it has', 1, count( $sysmda_sp_short ) );
check( 'llms: and stops after one page', array( 1 ), $GLOBALS['sysmda_test_query_pages'] );

// Enough ineligible content to exhaust the page cap: shorter than requested,
// which is the pre-existing outcome, but bounded rather than unbounded.
check(
	'llms: the page cap bounds the work',
	LlmsTxtController::MAX_QUERY_PAGES,
	count( ( static function () use ( $sysmda_sp_run ) {
		$sysmda_sp_run( array_fill( 0, 60, 'aside' ), 2 );
		return $GLOBALS['sysmda_test_query_pages'];
	} )() )
);

check( 'llms: a zero limit queries nothing', array(), $sysmda_sp_run( array( '', '' ), 0 ) );

unset(
	$GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'],
	$GLOBALS['sysmda_test_query_posts']['post']
);
$GLOBALS['sysmda_test_query_pages'] = array();

// ─── LlmsTxtController: the cached index follows the site identity ────
//
// The site name is the `# ` heading of /llms.txt and the tagline the blockquote
// under it, but both are edited in Settings → General, which never fires
// save_post — so renaming the site used to leave the old name in the index for
// a full TTL. Both assertions fail against 0.26.3.

$sysmda_llms_cv_method = sysmda_reflection_method( LlmsTxtController::class, 'cache_version' );
$sysmda_llms_controller = new LlmsTxtController( $metadata );
$sysmda_llms_cv         = function () use ( $sysmda_llms_cv_method, $sysmda_llms_controller ) {
	return $sysmda_llms_cv_method->invoke( $sysmda_llms_controller );
};

$GLOBALS['sysmda_test_bloginfo'] = array(
	'name'        => 'Old Site Name',
	'description' => 'Old tagline',
);
$sysmda_llms_cv_before = $sysmda_llms_cv();

$GLOBALS['sysmda_test_bloginfo']['name'] = 'New Site Name';
check( 'llms: renaming the site invalidates the cached index', true, $sysmda_llms_cv_before !== $sysmda_llms_cv() );

$sysmda_llms_cv_named                           = $sysmda_llms_cv();
$GLOBALS['sysmda_test_bloginfo']['description'] = 'New tagline';
check( 'llms: changing the tagline invalidates the cached index', true, $sysmda_llms_cv_named !== $sysmda_llms_cv() );
check( 'llms: unchanged identity keeps the same version', $sysmda_llms_cv(), $sysmda_llms_cv() );

// ─── LlmsTxtController: validators on the index ───────────────────────
//
// The ETag hashes the BYTES about to be sent, not cache_version(): the version
// does not cover the posts listed in the file (a new post is picked up by
// deleting the cache entry, not by moving the version), so using it here would
// answer 304 with an index missing that post.

check( 'llms: body etag is the md5 of the body', '"' . md5( "# Site\n" ) . '"', LlmsTxtController::body_etag( "# Site\n" ) );
check( 'llms: a different body is a different etag', true, LlmsTxtController::body_etag( 'a' ) !== LlmsTxtController::body_etag( 'b' ) );
check( 'llms: the same body is the same etag', LlmsTxtController::body_etag( 'x' ), LlmsTxtController::body_etag( 'x' ) );

$sysmda_llms_hc_method = sysmda_reflection_method( LlmsTxtController::class, 'handle_conditional' );

/** Runs the index's conditional check with a given If-None-Match header. */
$sysmda_llms_hc = function ( $header, $etag ) use ( $sysmda_llms_hc_method, $sysmda_llms_controller ) {
	$GLOBALS['sysmda_test_status'] = array();
	if ( null === $header ) {
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
	} else {
		$_SERVER['HTTP_IF_NONE_MATCH'] = $header;
	}
	$result = $sysmda_llms_hc_method->invoke( $sysmda_llms_controller, $etag );
	unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
	return $result;
};

$sysmda_llms_etag = LlmsTxtController::body_etag( "# Site\n\n> Tagline\n" );

check( 'llms: no If-None-Match => full body', false, $sysmda_llms_hc( null, $sysmda_llms_etag ) );
check( 'llms: no 304 without the header', array(), $GLOBALS['sysmda_test_status'] );
check( 'llms: matching validator => 304', true, $sysmda_llms_hc( $sysmda_llms_etag, $sysmda_llms_etag ) );
check( 'llms: 304 actually sent', array( 304 ), $GLOBALS['sysmda_test_status'] );
check( 'llms: stale validator => full body', false, $sysmda_llms_hc( '"outdated"', $sysmda_llms_etag ) );
check( 'llms: no 304 for a stale validator', array(), $GLOBALS['sysmda_test_status'] );
// Same weak comparison as the .md endpoint: the index reuses etag_matches().
check( 'llms: weakened validator still revalidates', true, $sysmda_llms_hc( 'W/' . $sysmda_llms_etag, $sysmda_llms_etag ) );
$GLOBALS['sysmda_test_status'] = array();

// ─── Cache-Control on the URLs the plugin owns ────────────────────────
//
// Sending nothing was never "always revalidate": RFC 9111 §4.2.2 lets a cache
// invent a lifetime, and on this route WordPress had already sent its own
// no-store set before the plugin ran. The default grants storage and refuses
// reuse, which is the only combination that cannot outlive an edit.

check(
	'cache-control: default grants storage and forbids reuse',
	'public, max-age=0, must-revalidate',
	MarkdownController::cache_control_value()
);

$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = 'public, s-maxage=600';
check( 'cache-control: a site may impose its own freshness', 'public, s-maxage=600', MarkdownController::cache_control_value() );

$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = '';
check( 'cache-control: empty means no header at all', '', MarkdownController::cache_control_value() );

// Hostile filter values are sanitized before reaching header(): a line break
// would take the response down with a fatal error.
$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = "public\r\nX-Injected: 1";
check( 'cache-control: header injection stripped', true, false === strpos( MarkdownController::cache_control_value(), "\n" ) );

$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = array( 'not', 'a', 'string' );
check( 'cache-control: a non-string filter value sends nothing', '', MarkdownController::cache_control_value() );

// A request that is not the shared public representation is never publicly
// cacheable, and the site filter must not be able to make it so — the body may
// have been rendered in that visitor's context by a dynamic block or shortcode.
$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = 'public, s-maxage=600';
check(
	'cache-control: an authenticated request is private and unstorable',
	'private, no-store, must-revalidate',
	MarkdownController::cache_control_value( false )
);
$GLOBALS['sysmda_test_filters']['sysmda_cache_control'] = '';
check(
	'cache-control: the filter cannot publish a personalized response',
	'private, no-store, must-revalidate',
	MarkdownController::cache_control_value( false )
);
unset( $GLOBALS['sysmda_test_filters']['sysmda_cache_control'] );

// The split itself: anonymous traffic — the audience this endpoint exists for —
// keeps the full shared-cache behaviour, and only an authenticated request
// leaves it.
check( 'shared: an anonymous request is the public representation', true, MarkdownController::representation_is_shared() );
$sysmda_llms_cache_method = sysmda_reflection_method( LlmsTxtController::class, 'uses_shared_body_cache' );
check( 'llms cache: anonymous requests use the shared body cache', true, $sysmda_llms_cache_method->invoke( null, DAY_IN_SECONDS ) );
check( 'llms cache: zero TTL disables the shared body cache', false, $sysmda_llms_cache_method->invoke( null, 0 ) );
$GLOBALS['sysmda_test_logged_in'] = true;
check( 'shared: an authenticated request is not', false, MarkdownController::representation_is_shared() );
check( 'llms cache: authenticated requests bypass the shared body cache', false, $sysmda_llms_cache_method->invoke( null, DAY_IN_SECONDS ) );
check(
	'cache-control: the default follows the visitor',
	'private, no-store, must-revalidate',
	MarkdownController::cache_control_value( MarkdownController::representation_is_shared() )
);
$GLOBALS['sysmda_test_logged_in'] = false;
$GLOBALS['sysmda_test_filters'] = array();

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

// The response tag is weak (the validator is computed from metadata, never from
// the bytes, so byte-for-byte identity cannot be promised — see etag()).
$sysmda_etag_method = sysmda_reflection_method( MarkdownController::class, 'etag' );
check( 'etag: the emitted tag is weak', 'W/"abc"', $sysmda_etag_method->invoke( null, 'abc' ) );

// Weak comparison ignores the flag on BOTH sides (RFC 9110 §8.8.3.2). The first
// case is the upgrade path: a client still holding the strong tag issued before
// this version must keep revalidating instead of re-downloading forever.
check( 'etag: strong client tag vs weak resource tag', true, MarkdownController::etag_matches( '"abc"', 'W/"abc"' ) );
check( 'etag: weak on both sides', true, MarkdownController::etag_matches( 'W/"abc"', 'W/"abc"' ) );
check( 'etag: weak tags still compare by value', false, MarkdownController::etag_matches( 'W/"xyz"', 'W/"abc"' ) );

// Apache rewrites the tag of a compressed response by appending the coding
// inside the quotes (`DeflateAlterETag AddSuffix` / `BrotliAlterETag`, both the
// default). The client echoes back what it received, so ignoring the suffix is
// what keeps 304 working at all on a stock Apache serving gzip.
check( 'etag: mod_deflate -gzip suffix', true, MarkdownController::etag_matches( '"abc-gzip"', 'W/"abc"' ) );
check( 'etag: mod_brotli -br suffix', true, MarkdownController::etag_matches( 'W/"abc-br"', 'W/"abc"' ) );
check( 'etag: suffix in a list', true, MarkdownController::etag_matches( '"xyz", W/"abc-gzip"', 'W/"abc"' ) );
// Only a trailing suffix, and only those two: nothing else may be trimmed off a
// validator before comparing it.
check( 'etag: -gzip not stripped mid-value', false, MarkdownController::etag_matches( '"abc-gzip-x"', 'W/"abc"' ) );
check( 'etag: unknown suffix is not stripped', false, MarkdownController::etag_matches( '"abc-zstd"', 'W/"abc"' ) );

// ─── LiteSpeedCompat ─────────────────────────────────────────────────────────

// is_litespeed: case-insensitive signature match on the given string.
check( 'litespeed: LiteSpeed signature', true, LiteSpeedCompat::is_litespeed( 'LiteSpeed' ) );
check( 'litespeed: lowercase signature', true, LiteSpeedCompat::is_litespeed( 'litespeed/6.3 (Enterprise)' ) );
check( 'litespeed: Apache is not LiteSpeed', false, LiteSpeedCompat::is_litespeed( 'Apache/2.4.62' ) );
check( 'litespeed: nginx is not LiteSpeed', false, LiteSpeedCompat::is_litespeed( 'nginx/1.27.0' ) );
check( 'litespeed: empty signature', false, LiteSpeedCompat::is_litespeed( '' ) );

// htaccess_rules: guarded by <IfModule LiteSpeed>, bypasses the page cache on
// Markdown negotiation and on nothing else.
$sysmda_ls_rules = LiteSpeedCompat::htaccess_rules();
check( 'litespeed rules: IfModule guard opens', '<IfModule LiteSpeed>', $sysmda_ls_rules[0] );
check( 'litespeed rules: IfModule guard closes', '</IfModule>', $sysmda_ls_rules[ count( $sysmda_ls_rules ) - 1 ] );
check( 'litespeed rules: markdown condition', true, in_array( 'RewriteCond %{HTTP:Accept} text/markdown [NC]', $sysmda_ls_rules, true ) );
check( 'litespeed rules: single no-cache env', 1, count( array_keys( $sysmda_ls_rules, 'RewriteRule ^ - [E=Cache-Control:no-cache]', true ) ) );

// Regression (0.30.0): the 406 bypass is gone. `RewriteRule ^` matches every
// URL, so those conditions let any request with an arbitrary media type skip the
// page cache site-wide — the cache-busting vector of keying on a raw Accept —
// to serve a 406 to clients that do not exist in practice. Must not come back.
$sysmda_ls_406_conds = array(
	'RewriteCond %{HTTP:Accept} !^$',
	'RewriteCond %{HTTP:Accept} !text/html [NC]',
	'RewriteCond %{HTTP:Accept} !text/\* [NC]',
	'RewriteCond %{HTTP:Accept} !\*/\* [NC]',
);
check( 'litespeed rules: no 406 cache bypass', array(), array_values( array_intersect( $sysmda_ls_406_conds, $sysmda_ls_rules ) ) );

// A manual block with the SAME directives but different comments/indentation
// must be recognized as equivalent (directive-only comparison in sync).
$sysmda_ls_manual = array(
	'<IfModule LiteSpeed>',
	'    RewriteEngine On',
	'',
	'    # Le richieste che citano Markdown devono arrivare a WordPress.',
	'    RewriteCond %{HTTP:Accept} text/markdown [NC]',
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

// ─── AdminSettings: the cache salt follows site-wide output inputs ────────────
//
// The `author:` front-matter key prints a user's display name, and `url:` /
// `markdown_url:` (plus every absolute link in the body) are built from the
// permalink structure and the home URL. None of those live in a post row, so
// nothing moves `post_modified_gmt` when they change: without a salt bump a
// client holding the old ETag is told `304` for good — staleness no TTL bounds.
//
// The bump is recorded by bump_cache_salt() and written by flush_cache_salt()
// on `shutdown`, so "did it bump" is always asked after an explicit flush: a
// settings save writes its options one at a time, and a salt written before the
// last of them lets a concurrent front-end request cache half-old output under
// the final salt, where nothing would ever invalidate it.

$sysmda_now                                          = time();
$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = '0';
$GLOBALS['sysmda_test_users'][7]                     = (object) array( 'display_name' => 'Jamie Rivers' );

// A profile save that leaves the display name alone: the output cannot change,
// and on a store with customer accounts this fires constantly.
$sysmda_admin->maybe_bump_for_author( 7, (object) array( 'display_name' => 'Jamie Rivers' ) );
$sysmda_admin->flush_cache_salt();
check( 'salt: unchanged display name does not bump', '0', get_option( 'sysmda_cache_salt' ) );

// Hooks that pass no user object at all (or an unknown user) must be inert too.
$sysmda_admin->maybe_bump_for_author( 7, null );
$sysmda_admin->maybe_bump_for_author( 999, (object) array( 'display_name' => 'Ghost' ) );
$sysmda_admin->flush_cache_salt();
check( 'salt: a profile update without usable data does not bump', '0', get_option( 'sysmda_cache_salt' ) );

// Options that are not ours, and the two of ours that must never bump.
$sysmda_admin->maybe_bump_cache_salt( 'blogname' );
$sysmda_admin->maybe_bump_cache_salt( 'sysmda_cache_salt' );
$sysmda_admin->maybe_bump_cache_salt( HitCounter::OPTION );
$sysmda_admin->flush_cache_salt();
check( 'salt: unrelated and excluded options do not bump', '0', get_option( 'sysmda_cache_salt' ) );

// Terms of a taxonomy that is NOT printed under its own front-matter key: the
// optional custom taxonomies are hashed by name in taxonomies_fingerprint(), so
// a rename already moves the validator and a salt bump would flush the whole
// site for nothing.
$sysmda_admin->maybe_bump_for_term( 11, 11, 'genre' );
$sysmda_admin->flush_cache_salt();
check( 'salt: a custom-taxonomy term edit does not bump', '0', get_option( 'sysmda_cache_salt' ) );

// Non-empty featured-image and Rank Math values remain represented by the
// per-post dependency fingerprint, so their ordinary updates need no global
// invalidation. When the last value vanishes, however, the fingerprint becomes
// empty and the salt must preserve that invalidation history for IMS-only
// clients whose post timestamp did not move.
$sysmda_admin->maybe_bump_for_empty_dependency_meta( 1, 61, '_thumbnail_id', 77 );
$sysmda_admin->maybe_bump_for_empty_dependency_meta( 2, 61, 'rank_math_description', 'Fresh summary' );
$sysmda_admin->flush_cache_salt();
check( 'salt: non-empty dependency meta updates do not bump', '0', get_option( 'sysmda_cache_salt' ) );

$sysmda_admin->bump_for_deleted_dependency_meta( array( 2 ), 61, 'rank_math_description', 'Old summary' );
$sysmda_admin->flush_cache_salt();
check( 'salt: deleting the last Rank Math dependency bumps', true, '0' !== get_option( 'sysmda_cache_salt' ) );

$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = '0';
$sysmda_admin->maybe_bump_for_empty_dependency_meta( 1, 61, '_thumbnail_id', 0 );
$sysmda_admin->flush_cache_salt();
check( 'salt: emptying the featured-image dependency bumps', true, '0' !== get_option( 'sysmda_cache_salt' ) );

$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = '0';

// The rename itself: the author line of every post by that user changes.
$GLOBALS['sysmda_test_users'][7]->display_name = 'Jamie R.';
$sysmda_admin->maybe_bump_for_author( 7, (object) array( 'display_name' => 'Jamie Rivers' ) );
$sysmda_admin->flush_cache_salt();
check( 'salt: a display-name change bumps the salt', true, '0' !== get_option( 'sysmda_cache_salt' ) );

// `categories:`/`tags:` are always emitted and are the two taxonomies
// taxonomies_fingerprint() leaves out, so a term rename or deletion reaches the
// validator through the salt or not at all.
foreach ( array( 'category', 'post_tag' ) as $sysmda_tax ) {
	$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = '0';
	$sysmda_admin->maybe_bump_for_term( 5, 5, $sysmda_tax );
	$sysmda_admin->flush_cache_salt();
	check( "salt: a {$sysmda_tax} term edit bumps the salt", true, '0' !== get_option( 'sysmda_cache_salt' ) );
}

// One bump per request, whatever else fires afterwards (a settings save can
// write a dozen options; each would otherwise reissue every ETag again).
$sysmda_admin->flush_cache_salt();
$GLOBALS['sysmda_test_options']['sysmda_cache_salt'] = 'already-bumped';
$sysmda_admin->flush_cache_salt();
check( 'salt: nothing is written without a pending bump', 'already-bumped', get_option( 'sysmda_cache_salt' ) );

// Two invalidations in the same second must still produce different salts. A
// bare `time()` did not: update_option() short-circuits on an unchanged value,
// so the second bump silently left stale bodies and ETags valid.
$sysmda_admin->bump_cache_salt();
$sysmda_admin->flush_cache_salt();
$sysmda_salt_a = get_option( 'sysmda_cache_salt' );
$sysmda_admin->bump_cache_salt();
$sysmda_admin->flush_cache_salt();
check( 'salt: two bumps in the same second differ', true, $sysmda_salt_a !== get_option( 'sysmda_cache_salt' ) );

// The leading field stays a Unix timestamp: MarkdownController reads it to
// decide whether `post_modified_gmt` is still a trustworthy validator.
check(
	'salt: the value starts with a Unix timestamp',
	true,
	(int) get_option( 'sysmda_cache_salt' ) >= $sysmda_now - 60
);

unset(
	$GLOBALS['sysmda_test_options']['sysmda_cache_salt'],
	$GLOBALS['sysmda_test_users'][7],
	$sysmda_tax,
	$sysmda_salt_a
);

// ─── ContentRenderer::process_dom (DOM pipeline) ──────────────────────────────

// The DOM pass is where the body is assembled, so it gets golden coverage: the
// bugs it used to hide (silent truncation, glued tables, collapsed code) were all
// invisible to the pure-logic tests that only reached absolutize().
$sysmda_dom_method = sysmda_reflection_method( ContentRenderer::class, 'process_dom' );

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

// Captions (0.38.0): <figcaption> is another tag the converter does not know,
// so with strip_tags on its text was emitted flush against the media it
// captioned — "![Alt](url)My caption" on one line. Promoted to a sibling
// paragraph it separates, and the same fix covers every captioned construct.
check(
	'dom: image caption promoted to its own paragraph',
	'<p><img src="https://example.com/a.png" alt="x"></p><p>Cap</p>',
	$sysmda_dom( '<figure class="wp-block-image"><img src="/a.png" alt="x"/><figcaption>Cap</figcaption></figure>' )
);
check(
	'dom: table caption promoted out of the figure',
	'<figure class="wp-block-table"><table><tr><td>c</td></tr></table></figure><p>Cap</p>',
	$sysmda_dom( '<figure class="wp-block-table"><table><tr><td>c</td></tr></table><figcaption>Cap</figcaption></figure>' )
);
check(
	'dom: caption inline markup survives promotion',
	'<p><img src="https://example.com/a.png" alt="x"></p><p>See <a href="https://example.com/blog/my-post/x">this</a></p>',
	$sysmda_dom( '<figure><img src="/a.png" alt="x"/><figcaption>See <a href="x">this</a></figcaption></figure>' )
);
check(
	'dom: empty caption leaves nothing behind',
	'<p><img src="https://example.com/a.png" alt="x"></p>',
	$sysmda_dom( '<figure><img src="/a.png" alt="x"/><figcaption></figcaption></figure>' )
);

// Disclosures (0.38.0): core/details came out as "MoreHidden body" — summary
// and body concatenated with nothing between them.
check(
	'dom: details flattened to bold summary + body',
	'<p><strong>More</strong></p><p>Hidden body</p>',
	$sysmda_dom( '<details class="wp-block-details"><summary>More</summary><p>Hidden body</p></details>' )
);
check(
	'dom: details without a summary keeps its body',
	'<p>Body only</p>',
	$sysmda_dom( '<details><p>Body only</p></details>' )
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
// A class merely CONTAINING "line" is not a line wrapper. The substring test
// this replaced accepted `inline-token`, `underline`, `baseline` and `outline`,
// so adjacent token spans — which have no text node between them to bail out on
// — were each treated as a rendered line and one source line was silently split
// into several.
foreach ( array( 'inline-token', 'underline', 'baseline', 'outline' ) as $sysmda_not_line ) {
	check(
		"dom: class \"{$sysmda_not_line}\" is not a code line",
		'<pre><code class="language-js">let a = 1;</code></pre>',
		$sysmda_dom(
			'<pre><code class="language-js"><span class="' . $sysmda_not_line . '">let </span>'
			. '<span class="' . $sysmda_not_line . '">a = 1;</span></code></pre>'
		)
	);
}
// …while the shapes highlighters actually use still are, whatever they prefix.
foreach ( array( 'line', 'code-line', 'token-line', 'line-number highlighted' ) as $sysmda_is_line ) {
	check(
		"dom: class \"{$sysmda_is_line}\" is a code line",
		"<pre><code class=\"language-js\">echo 1;\necho 2;</code></pre>",
		$sysmda_dom(
			'<pre><code class="language-js"><span class="' . $sysmda_is_line . '">echo 1;</span>'
			. '<span class="' . $sysmda_is_line . '">echo 2;</span></code></pre>'
		)
	);
}
unset( $sysmda_not_line, $sysmda_is_line );

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

// The whole point of reading post_password directly: a reader who has already
// entered the password holds a valid wp-postpass_* cookie, which makes
// post_password_required() return false. Protected content still has no
// Markdown representation — not through .md, not through the alternate link,
// the shortcode or the dynamic tag. Fails against 0.26.3.
$GLOBALS['sysmda_test_password_cookie'] = true;
check(
	'servable: password protected, cookie supplied',
	false,
	PostSupport::is_servable( $sysmda_mk_post( array( 'post_password' => 'x' ) ) )
);
check(
	'servable: unprotected post unaffected by the cookie',
	true,
	PostSupport::is_servable( $sysmda_mk_post() )
);
$GLOBALS['sysmda_test_password_cookie'] = false;

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

// The public policy applies to the SAVED SELECTION, which AdminSettings filters
// through here before feeding it to sysmda_markdown_supported_post_types.
// sanitize_post_types() keeps a slug whose provider is temporarily inactive, so
// an afternoon of deactivation does not turn the endpoint off — but a type
// re-registered as public => false, or replaced by an internal one of the same
// name, must not stay servable on the strength of a stale option.
$GLOBALS['sysmda_test_post_types']['secret_records'] = (object) array(
	'name'               => 'secret_records',
	'public'             => false,
	'publicly_queryable' => false,
);
check( 'public policy: a non-public type is rejected', false, PostSupport::type_is_public( 'secret_records' ) );

$GLOBALS['sysmda_test_post_types']['gone'] = null;
check( 'public policy: an unregistered type is rejected', false, PostSupport::type_is_public( 'gone' ) );
check( 'public policy: a public type passes', true, PostSupport::type_is_public( 'post' ) );

// …and it is NOT applied to the filter's result. Site code adding a non-public
// CPT through sysmda_markdown_supported_post_types is making an explicit
// request, and widening what is served is that filter's documented job;
// enforcing the policy afterwards would silently overrule it. A stale saved
// slug is not a request — that is the whole distinction.
// Whatever reached supported_post_types() got there either from the saved
// option — already filtered by the callback above — or from an explicit
// site-code opt-in through the filter, and both have been decided by then.
// Re-applying the policy here would silently overrule the second one, so a type
// that is in the list is servable even when it is not public.
$GLOBALS['sysmda_test_post_types']['post'] = (object) array(
	'name'   => 'post',
	'public' => false,
);
check(
	'servable: the emission path does not re-apply the public policy',
	true,
	PostSupport::is_servable( $sysmda_mk_post() )
);
unset(
	$GLOBALS['sysmda_test_post_types']['post'],
	$GLOBALS['sysmda_test_post_types']['secret_records'],
	$GLOBALS['sysmda_test_post_types']['gone']
);

// A membership or paywall plugin protects a PUBLISHED post from a later
// template_redirect callback or a the_content filter, and neither reaches this
// endpoint: it runs at template_redirect priority 0 and exits, and it renders
// cleaned blocks instead of the_content by design. sysmda_post_is_servable is
// how such a plugin denies one post, everywhere at once.
$GLOBALS['sysmda_test_filters']['sysmda_post_is_servable'] = false;
check( 'servable: a site filter can veto a single post', false, PostSupport::is_servable( $sysmda_mk_post() ) );

// Veto ONLY. It is consulted just when the built-in rules already said yes, so
// returning true can never publish a draft, protected content, or a type the
// site has not enabled.
$GLOBALS['sysmda_test_filters']['sysmda_post_is_servable'] = true;
check( 'servable: the veto filter cannot publish a draft', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_status' => 'draft' ) ) ) );
check( 'servable: the veto filter cannot publish protected content', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_password' => 'x' ) ) ) );
check( 'servable: the veto filter cannot publish an unsupported type', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_type' => 'product' ) ) ) );
check( 'servable: the veto filter cannot publish an excluded format', false, PostSupport::is_servable( $sysmda_mk_post( array( 'post_format' => 'aside' ) ) ) );
check( 'servable: an allowed post is unaffected', true, PostSupport::is_servable( $sysmda_mk_post() ) );
unset( $GLOBALS['sysmda_test_filters']['sysmda_post_is_servable'] );

// ─── Shortcodes::render_download ──────────────────────────────────────────────

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';

$sysmda_dl_post = $sysmda_mk_post(
	array(
		'post_name' => 'my-post',
		'permalink' => 'https://example.com/my-post/',
	)
);

$GLOBALS['sysmda_test_posts'][ $sysmda_dl_post->ID ] = $sysmda_dl_post;

$sysmda_shortcodes = new Shortcodes();

check(
	'download shortcode: default markup',
	'<a class="sysmda-md-download" href="https://example.com/my-post.md" download="my-post.md">Download MD</a>',
	$sysmda_shortcodes->render_download( array( 'id' => $sysmda_dl_post->ID ) )
);

check(
	'download shortcode: custom text',
	'<a class="sysmda-md-download" href="https://example.com/my-post.md" download="my-post.md">Save it</a>',
	$sysmda_shortcodes->render_download(
		array(
			'id'   => $sysmda_dl_post->ID,
			'text' => 'Save it',
		)
	)
);

// A blank text= falls back to the default rather than producing an empty link.
check(
	'download shortcode: blank text falls back to the default',
	'<a class="sysmda-md-download" href="https://example.com/my-post.md" download="my-post.md">Download MD</a>',
	$sysmda_shortcodes->render_download(
		array(
			'id'   => $sysmda_dl_post->ID,
			'text' => '   ',
		)
	)
);

// The label is user input: it must not be able to inject markup.
check(
	'download shortcode: text is escaped',
	true,
	false !== strpos(
		$sysmda_shortcodes->render_download(
			array(
				'id'   => $sysmda_dl_post->ID,
				'text' => '<script>alert(1)</script>',
			)
		),
		'&lt;script&gt;'
	)
);

// Same contract as [sysmda_md_url]: never link to something that would 404.
$GLOBALS['sysmda_test_posts'][901] = $sysmda_mk_post(
	array(
		'ID'          => 901,
		'post_status' => 'draft',
		'post_name'   => 'hidden',
		'permalink'   => 'https://example.com/hidden/',
	)
);
check( 'download shortcode: not servable → empty', '', $sysmda_shortcodes->render_download( array( 'id' => 901 ) ) );
check( 'download shortcode: unknown ID → empty', '', $sysmda_shortcodes->render_download( array( 'id' => 999999 ) ) );

// The markup stays a bare anchor: one class, no inline style, no data-* hooks,
// nothing a stylesheet or a script would need. The front-end button removed in
// 0.34.0 began at exactly this size, so the shape is asserted, not assumed.
$sysmda_dl_markup = $sysmda_shortcodes->render_download( array( 'id' => $sysmda_dl_post->ID ) );
check( 'download shortcode: no inline style', false, strpos( $sysmda_dl_markup, 'style=' ) );
check( 'download shortcode: no data attributes', false, strpos( $sysmda_dl_markup, 'data-' ) );
check( 'download shortcode: exactly one class attribute', 1, substr_count( $sysmda_dl_markup, 'class=' ) );
check( 'download shortcode: single class value', 1, preg_match( '/class="sysmda-md-download"/', $sysmda_dl_markup ) );

unset( $GLOBALS['sysmda_test_posts'][901], $GLOBALS['sysmda_test_posts'][ $sysmda_dl_post->ID ] );
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] );
unset( $GLOBALS['sysmda_test_options']['sysmda_supported_post_types'] );

// ─── MarkdownActions ────────────────────────────────────────────────────────

$sysmda_actions_html = MarkdownActions::build_html(
	'https://example.com/my-post.md',
	'my-post.md',
	'sysmda-md-actions-menu-test'
);

check( 'actions: valid primitives produce markup', true, '' !== $sysmda_actions_html );
check( 'actions: root starts hidden until JavaScript setup', true, false !== strpos( $sysmda_actions_html, 'class="sysmda-md-actions" data-sysmda-md-url="https://example.com/my-post.md" hidden' ) );
check( 'actions: copy is available as primary and menu action', 2, substr_count( $sysmda_actions_html, 'data-sysmda-action="copy"' ) );
check( 'actions: menu id is connected to the toggle', true, false !== strpos( $sysmda_actions_html, 'aria-controls="sysmda-md-actions-menu-test"' ) );
check( 'actions: menu carries the same unique id', true, false !== strpos( $sysmda_actions_html, 'id="sysmda-md-actions-menu-test"' ) );
check( 'actions: view opens in a new tab safely', true, false !== strpos( $sysmda_actions_html, 'target="_blank" rel="noopener noreferrer"' ) );
check( 'actions: view announces the new tab', true, false !== strpos( $sysmda_actions_html, '(opens in new tab)' ) );
check( 'actions: download is a native same-origin download', true, false !== strpos( $sysmda_actions_html, 'download="my-post.md"' ) );
check( 'actions: exactly three menu rows', 3, substr_count( $sysmda_actions_html, 'class="sysmda-md-actions__row"' ) );
check( 'actions: markup has no inline style', false, strpos( $sysmda_actions_html, 'style=' ) );
check( 'actions: invalid URL suppresses the component', '', MarkdownActions::build_html( 'javascript:alert(1)', 'x.md', 'menu' ) );
check( 'actions: missing filename suppresses the component', '', MarkdownActions::build_html( 'https://example.com/x.md', '', 'menu' ) );

// Query-string Markdown fallbacks must be escaped once, not once per attribute.
$sysmda_actions_plain = MarkdownActions::build_html(
	'https://example.com/?p=123&format=markdown',
	'post-123.md',
	'menu-plain'
);
check( 'actions: query URL is escaped', true, false !== strpos( $sysmda_actions_plain, '?p=123&amp;format=markdown' ) );
check( 'actions: query URL is not double-escaped', false, strpos( $sysmda_actions_plain, '&amp;amp;' ) );

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] = array( 'post' );

$sysmda_actions_post = $sysmda_mk_post(
	array(
		'ID'        => 777,
		'post_name' => 'actions-post',
		'permalink' => 'https://example.com/actions-post/',
	)
);
$GLOBALS['sysmda_test_posts'][777] = $sysmda_actions_post;

$sysmda_actions = new MarkdownActions();
$GLOBALS['sysmda_test_did_actions']['wp_print_styles'] = 1;
$sysmda_rendered_actions = $sysmda_actions->render_shortcode( array( 'id' => 777 ) );
check( 'actions shortcode: explicit servable post renders', true, false !== strpos( $sysmda_rendered_actions, 'https://example.com/actions-post.md' ) );
check( 'actions shortcode: download uses the shared filename builder', true, false !== strpos( $sysmda_rendered_actions, 'download="actions-post.md"' ) );
check( 'actions shortcode: late render registers its stylesheet', true, wp_style_is( MarkdownActions::HANDLE, 'registered' ) );
check( 'actions shortcode: late render registers its script', true, wp_script_is( MarkdownActions::HANDLE, 'registered' ) );
check( 'actions shortcode: late render enqueues its stylesheet', true, wp_style_is( MarkdownActions::HANDLE, 'enqueued' ) );
check( 'actions shortcode: late render enqueues its script', true, wp_script_is( MarkdownActions::HANDLE, 'enqueued' ) );
check( 'actions shortcode: late render schedules a footer style printer', 1, count( $GLOBALS['sysmda_test_actions']['wp_footer'][0] ) );

call_user_func( $GLOBALS['sysmda_test_actions']['wp_footer'][0][0] );
call_user_func( $GLOBALS['sysmda_test_actions']['wp_footer'][0][0] );
check( 'actions shortcode: late stylesheet is marked done', true, wp_style_is( MarkdownActions::HANDLE, 'done' ) );
check( 'actions shortcode: late stylesheet prints once', 1, $GLOBALS['sysmda_test_assets']['styles'][ MarkdownActions::HANDLE ]['print_count'] );

$GLOBALS['sysmda_test_posts'][778] = $sysmda_mk_post(
	array(
		'ID'          => 778,
		'post_status' => 'draft',
		'post_name'   => 'draft-actions',
		'permalink'   => 'https://example.com/draft-actions/',
	)
);
check( 'actions shortcode: draft produces no control', '', $sysmda_actions->render_shortcode( array( 'id' => 778 ) ) );
check( 'actions shortcode: unknown ID produces no control', '', $sysmda_actions->render_shortcode( array( 'id' => 999999 ) ) );

unset( $GLOBALS['sysmda_test_posts'][777], $GLOBALS['sysmda_test_posts'][778] );
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_supported_post_types'] );
unset( $GLOBALS['sysmda_test_did_actions']['wp_print_styles'] );

// ─── CodeFence (pure logic, no library needed) ───────────────────────────────

check( 'fence: plain code gets the minimum', '```', CodeFence::block_delimiter( "a\nb" ) );
check( 'fence: widens past an inner fence', '````', CodeFence::block_delimiter( "a\n```\nb" ) );
check( 'fence: widens past the longest run', '`````', CodeFence::block_delimiter( "a\n````\nb" ) );
check( 'fence: a mid-line run counts too', '````', CodeFence::block_delimiter( 'x ``` y' ) );
check( 'fence: inline delimiter is one by default', '`', CodeFence::inline_delimiter( 'x' ) );
check( 'fence: inline delimiter clears a backtick', '``', CodeFence::inline_delimiter( 'a ` b' ) );
check( 'fence: padding only when it touches a backtick', true, CodeFence::needs_padding( '`x' ) );
check( 'fence: symmetric boundary spaces need compensation', true, CodeFence::needs_padding( ' x ' ) );
check( 'fence: all-space content needs no compensation', false, CodeFence::needs_padding( '   ' ) );
check( 'fence: no padding otherwise', false, CodeFence::needs_padding( 'x`y' ) );
check( 'fence: info string strips a backtick', 'php', CodeFence::info_string( 'p`hp' ) );

// is_safely_fenced() decides whether an already-converted block may be passed
// through untouched. Getting it wrong in either direction is a real defect:
// too strict double-fences ordinary code, too loose lets an interior fence
// escape and swallow the rest of the document.
check( 'safely fenced: a well-formed block', true, CodeFence::is_safely_fenced( "```\na\n```" ) );
check( 'safely fenced: with an info string', true, CodeFence::is_safely_fenced( "```php\na\n```" ) );
check( 'safely fenced: empty block', true, CodeFence::is_safely_fenced( "```\n```" ) );
check( 'safely fenced: wide fence over an inner one', true, CodeFence::is_safely_fenced( "````\na\n```\nb\n````" ) );
check( 'safely fenced: closing run may be longer', true, CodeFence::is_safely_fenced( "```\na\n````" ) );
// The case the library's heuristic accepted and should not have: text that
// merely begins and ends with a backtick, with a bare fence in the middle.
check( 'safely fenced: NOT bare text bounded by backticks', false, CodeFence::is_safely_fenced( "`a\n```\nb`" ) );
check( 'safely fenced: NOT an interior line that closes it', false, CodeFence::is_safely_fenced( "```\na\n```\nb\n```" ) );
// A backtick run with text after it is content, not a delimiter: a closing
// fence carries nothing but whitespace. So this really is one safe block, and
// treating it as unsafe would double-fence ordinary code.
check( 'safely fenced: a run followed by text is content', true, CodeFence::is_safely_fenced( "```\na\n``` and ```\nb\n```" ) );
// Two genuine blocks in a row, though: the third line closes the first.
check( 'safely fenced: NOT two separate blocks', false, CodeFence::is_safely_fenced( "```\na\n```\n```\nb\n```" ) );
// …and a closing run shorter than the opening one never closes it. This is the
// shape a <pre> with two <code> children actually produces.
check( 'safely fenced: NOT a short closing run', false, CodeFence::is_safely_fenced( "````\na\n```\nb\n```` and ```\nc\n```" ) );
check( 'safely fenced: NOT an unclosed block', false, CodeFence::is_safely_fenced( "```\na\nb" ) );
check( 'safely fenced: NOT a single line', false, CodeFence::is_safely_fenced( '```' ) );
check( 'safely fenced: NOT plain text', false, CodeFence::is_safely_fenced( "hello\nworld" ) );
// An info string may not contain a backtick, so this never opened a fence.
check( 'safely fenced: NOT a first line with a later backtick', false, CodeFence::is_safely_fenced( "```a`b\nx\n```" ) );

// ─── MarkdownConverter (needs league/html-to-markdown) ───────────────────────

if ( ! $GLOBALS['sysmda_has_vendor'] ) {
	echo "NOTE: skipping the Markdown conversion tests (vendor/ absent — run `composer install`).\n";
} else {
	$sysmda_conv = new MarkdownConverter();

	// Public-interface characterization for the independently rewritten code
	// converter. This deliberately observes only ElementInterface::getValue()
	// through the real HtmlConverter traversal: it is the API contract the new
	// implementation relies on, and it prevents a library upgrade from silently
	// restoring wrapper-markup extraction.
	$sysmda_characterization = new class() implements ConverterInterface, PreConverterInterface {
		/** @var array<int,array{tag:string,value:string}> */
		public $seen = array();
		/** @var array<int,array{tag:string,children:string[]}> */
		public $before = array();

		public function preConvert( ElementInterface $element ): void {
			if ( 'pre' !== strtolower( $element->getTagName() ) ) {
				return;
			}

			$children = array();
			foreach ( $element->getChildren() as $child ) {
				$children[] = $child->getTagName();
			}

			$this->before[] = array(
				'tag'      => 'pre',
				'children' => $children,
			);
		}

		public function convert( ElementInterface $element ): string {
			$tag          = strtolower( $element->getTagName() );
			$this->seen[] = array(
				'tag'   => $tag,
				'value' => $element->getValue(),
			);

			return 'code' === $tag ? 'CHILD{' . $element->getValue() . '}' : 'PARENT{' . $element->getValue() . '}';
		}

		public function getSupportedTags(): array {
			return array( 'code', 'pre' );
		}
	};
	$sysmda_characterization_html = new HtmlConverter( array( 'strip_tags' => true ) );
	$sysmda_characterization_html->getEnvironment()->addConverter( $sysmda_characterization );

	$sysmda_characterization->seen = array();
	$sysmda_characterization_html->convert( '<code>&lt;x&gt; &amp; "quoted"</code>' );
	check(
		'convert API: code value is decoded text without its wrapper',
		array( array( 'tag' => 'code', 'value' => '<x> & "quoted"' ) ),
		$sysmda_characterization->seen
	);

	$sysmda_characterization->seen = array();
	$sysmda_characterization_html->convert( '<pre>&lt;x&gt; &amp; "quoted"</pre>' );
	check(
		'convert API: bare pre value is decoded text',
		array( array( 'tag' => 'pre', 'value' => '<x> & "quoted"' ) ),
		$sysmda_characterization->seen
	);

	$sysmda_characterization->seen = array();
	$sysmda_characterization->before = array();
	$sysmda_characterization_html->convert( '<pre><code>&lt;x&gt;</code></pre>' );
	check(
		'convert API: one converter sees code before pre and pre receives child Markdown',
		array(
			array( 'tag' => 'code', 'value' => '<x>' ),
			array( 'tag' => 'pre', 'value' => 'CHILD{<x>}' ),
		),
		$sysmda_characterization->seen
	);
	check(
		'convert API: pre-conversion sees the original code child before replacement',
		array( array( 'tag' => 'pre', 'children' => array( 'code' ) ) ),
		$sysmda_characterization->before
	);

	$sysmda_characterization->seen = array();
	$sysmda_characterization_html->convert( '<kbd>x</kbd>' );
	check( 'convert API: registration cannot route an unsupported tag', array(), $sysmda_characterization->seen );

	$sysmda_code_converter = new CodeElementConverter();
	check( 'convert API: one production converter owns code and pre', array( 'code', 'pre' ), $sysmda_code_converter->getSupportedTags() );

	$sysmda_unexpected_element = new class() implements ElementInterface {
		public function isBlock(): bool {
			return false;
		}
		public function isText(): bool {
			return false;
		}
		public function isWhitespace(): bool {
			return false;
		}
		public function getTagName(): string {
			return 'kbd';
		}
		public function getValue(): string {
			return '<literal>';
		}
		public function hasParent(): bool {
			return false;
		}
		public function getParent(): ?ElementInterface {
			return null;
		}
		public function getNextSibling(): ?ElementInterface {
			return null;
		}
		public function getPreviousSibling(): ?ElementInterface {
			return null;
		}
		public function isDescendantOf( $tagNames ): bool {
			return false;
		}
		public function hasChildren(): bool {
			return false;
		}
		public function getChildren(): array {
			return array();
		}
		public function getNext(): ?ElementInterface {
			return null;
		}
		public function getSiblingPosition(): int {
			return 0;
		}
		public function getChildrenAsString(): string {
			return '';
		}
		public function setFinalMarkdown( string $markdown ): void {
		}
		public function getListItemLevel(): int {
			return 0;
		}
		public function getAttribute( string $name ): string {
			return '';
		}
	};
	check( 'convert API: defensive unexpected-tag dispatch preserves the value', '<literal>', $sysmda_code_converter->convert( $sysmda_unexpected_element ) );

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

	// …and a fence is not always at the left margin. The converter emits
	// `<blockquote><pre>` as "> ```", `<li><pre>` as "- ```" with a four-space
	// body, and the two nest. Matching only `^ {0,3}` missed all of them, so
	// exactly the code that had to survive byte-for-byte was normalized as
	// prose: hard-break spaces trimmed, blank-line runs collapsed.
	$sysmda_nested_code = "x = 1  \n\n\ny = 2  ";

	check(
		'convert: code inside a blockquote preserved',
		"> ```php\n> x = 1  \n> \n> \n> y = 2  \n> ```\n",
		$sysmda_conv->convert( "<blockquote><pre><code class=\"language-php\">{$sysmda_nested_code}</code></pre></blockquote>" )
	);
	check(
		'convert: code inside a list item preserved',
		"- ```php\n    x = 1  \n    \n    \n    y = 2  \n    ```\n",
		$sysmda_conv->convert( "<ul><li><pre><code class=\"language-php\">{$sysmda_nested_code}</code></pre></li></ul>" )
	);
	// Nesting positions the delimiter without indenting it: a second-level list
	// emits "    - ```php", where the four spaces belong to the nested list, not
	// to the fence. Counting them as indentation pushed it past the three-space
	// cap and the whole block fell through to the prose rules.
	check(
		'convert: code inside a nested list preserved',
		"- Outer\n    - ```php\n        x = 1  \n        \n        \n        y = 2  \n        ```\n",
		$sysmda_conv->convert( "<ul><li>Outer<ul><li><pre><code class=\"language-php\">{$sysmda_nested_code}</code></pre></li></ul></li></ul>" )
	);

	check(
		'convert: code inside a nested blockquote preserved',
		"> > ```\n> > x = 1  \n> > \n> > \n> > y = 2  \n> > ```\n",
		$sysmda_conv->convert( "<blockquote><blockquote><pre><code>{$sysmda_nested_code}</code></pre></blockquote></blockquote>" )
	);

	// Accepting a container prefix must not turn ARBITRARY indentation into a
	// delimiter. A sample that itself shows fenced code puts ``` four spaces in,
	// and CommonMark caps a delimiter at three: reading that line as the close
	// ends the fence early and hands the rest of the block to the prose rules.
	//
	// Since 0.38.0 the opening delimiter is also sized to the content, so this
	// block opens with FOUR backticks: the indentation is no longer the only
	// thing keeping the inner ``` from closing it.
	check(
		'convert: indented backticks inside a fence are content',
		"````\nExample:  \n    ```\n    x  \n\n\n    ```\ndone  \n````\n",
		$sysmda_conv->convert( "<pre><code>Example:  \n    ```\n    x  \n\n\n    ```\ndone  </code></pre>" )
	);

	// The fence must still CLOSE, or everything after it would be preserved
	// verbatim and the rest of the document would stop being normalized.
	check(
		'convert: prose after a quoted fence is normalized again',
		"> ```\n> x = 1  \n> ```\n\na\n\nb\n",
		$sysmda_conv->convert( "<blockquote><pre><code>x = 1  </code></pre></blockquote><p>a</p><p></p><p></p><p>b</p>" )
	);

	// Ordinary conversions, pinned so the converter config cannot drift silently.
	check( 'convert: atx heading', "## Title\n", $sysmda_conv->convert( '<h2>Title</h2>' ) );
	check( 'convert: dash list items', "- a\n- b\n", $sysmda_conv->convert( '<ul><li>a</li><li>b</li></ul>' ) );
	check( 'convert: script node removed', "text\n", $sysmda_conv->convert( '<p>text</p><script>evil()</script>' ) );
	check( 'convert: empty input', '', $sysmda_conv->convert( '   ' ) );

	// ── Delimiter safety (0.38.0) ───────────────────────────────────────────
	//
	// The library picks a delimiter without looking at what it wraps, so content
	// carrying that delimiter escaped its own construct. These pin the fix at
	// the point where it matters: the text AFTER the construct must still be
	// prose, which is what a breakout destroys.

	// A code block whose body contains a bare fence. Before the fix the fence
	// closed on the inner ``` and everything from "still code" to the end of
	// the document — the following paragraph and heading included — was
	// re-read as prose and then swallowed by a stray reopening fence.
	check(
		'convert: code containing a bare fence gets a longer delimiter',
		"````\na\n```\nb\n````\n\nAfter.\n\n## Heading\n",
		$sysmda_conv->convert( "<pre><code>a\n```\nb</code></pre><p>After.</p><h2>Heading</h2>" )
	);

	// Escalates: four backticks inside means five outside.
	check(
		'convert: fence grows past the longest run inside',
		"`````\na\n````\nb\n`````\n",
		$sysmda_conv->convert( "<pre><code>a\n````\nb</code></pre>" )
	);

	// The language info string survives the longer fence.
	check(
		'convert: language preserved on a widened fence',
		"````php\n```\n````\n",
		$sysmda_conv->convert( '<pre><code class="language-php">```</code></pre>' )
	);

	// Inline code containing a backtick: one delimiter backtick used to end the
	// span in the middle of the content.
	check(
		'convert: inline code containing a backtick',
		"Run `` git log --format=`%h` `` then stop.\n",
		$sysmda_conv->convert( '<p>Run <code>git log --format=`%h`</code> then stop.</p>' )
	);

	// Content that starts and ends with a backtick needs padding, or the
	// delimiters merge with it.
	check(
		'convert: inline code starting and ending with a backtick',
		"a `` `x` `` b\n",
		$sysmda_conv->convert( '<p>a <code>`x`</code> b</p>' )
	);

	check(
		'convert: ordinary inline code keeps a single backtick',
		"a `x` b\n",
		$sysmda_conv->convert( '<p>a <code>x</code> b</p>' )
	);

	// CommonMark §6.1 turns line endings inside code spans into spaces. It also
	// removes one symmetric boundary space unless the value is all spaces. The
	// renderer must compensate so parsing the Markdown reproduces the intended
	// text instead of concatenating or trimming it.
	check(
		'convert: inline LF becomes one space',
		"a `x y` b\n",
		$sysmda_conv->convert( "<p>a <code>x\ny</code> b</p>" )
	);
	check(
		'convert: inline CRLF becomes one space',
		"a `x y` b\n",
		$sysmda_conv->convert( "<p>a <code>x\r\ny</code> b</p>" )
	);
	check(
		'convert: inline CR becomes one space',
		"a `x y` b\n",
		$sysmda_conv->convert( "<p>a <code>x\ry</code> b</p>" )
	);
	check(
		'convert: symmetric inline spaces survive CommonMark normalization',
		"a `  x  ` b\n",
		$sysmda_conv->convert( '<p>a <code> x </code> b</p>' )
	);
	check(
		'convert: leading-only inline space stays byte-identical',
		"a ` x` b\n",
		$sysmda_conv->convert( '<p>a <code> x</code> b</p>' )
	);
	check(
		'convert: trailing-only inline space stays byte-identical',
		"a `x ` b\n",
		$sysmda_conv->convert( '<p>a <code>x </code> b</p>' )
	);
	check(
		'convert: all-space inline content needs no compensation',
		"a `   ` b\n",
		$sysmda_conv->convert( '<p>a <code>   </code> b</p>' )
	);
	check(
		'convert: empty inline code emits no invalid delimiter pair',
		"a b\n",
		$sysmda_conv->convert( '<p>a <code></code>b</p>' )
	);
	check(
		'convert: inline structure is not changed by the old content trigger',
		"a ``x` y`` b\n",
		$sysmda_conv->convert( '<p>a <code>x` y</code> b</p>' )
	);
	check(
		'convert: nested highlighting contributes decoded text, not markup',
		"`x<y & z`\n",
		$sysmda_conv->convert( '<p><code><span>x</span>&lt;y &amp; z</code></p>' )
	);
	check(
		'convert: inline entities, quotes and non-ASCII decode exactly once',
		"`<>&\"' café`\n",
		$sysmda_conv->convert( '<p><code>&lt;&gt;&amp;&quot;&#039; café</code></p>' )
	);

	// Property-style delimiter corpus: every run length and position gets a
	// delimiter that is strictly longer, without introducing block newlines.
	foreach ( range( 0, 12 ) as $sysmda_run_length ) {
		$sysmda_run = str_repeat( '`', $sysmda_run_length );
		$sysmda_inline_values = 0 === $sysmda_run_length
			? array( 'plain' => 'plain' )
			: array(
				'start'  => $sysmda_run . 'a',
				'middle' => 'a' . $sysmda_run . 'b',
				'end'    => 'a' . $sysmda_run,
			);

		foreach ( $sysmda_inline_values as $sysmda_position => $sysmda_value ) {
			$sysmda_delimiter = str_repeat( '`', $sysmda_run_length + 1 );
			$sysmda_padding   = '`' === $sysmda_value[0] || '`' === substr( $sysmda_value, -1 ) ? ' ' : '';
			$sysmda_expected  = $sysmda_delimiter . $sysmda_padding . $sysmda_value . $sysmda_padding . $sysmda_delimiter . " sentinel\n";

			check(
				"convert property: inline run {$sysmda_run_length} at {$sysmda_position}",
				$sysmda_expected,
				$sysmda_conv->convert( '<p><code>' . $sysmda_value . '</code> sentinel</p>' )
			);
		}
	}

	// A fence typed as prose. Nothing here is code at all, and the paragraph
	// used to open a fence that ran to the end of the document.
	check(
		'convert: a bare fence written as prose is escaped',
		"\\```\n\nRegular paragraph.\n",
		$sysmda_conv->convert( '<p>```</p><p>Regular paragraph.</p>' )
	);

	check(
		'convert: a fence with an info string written as prose is escaped',
		"\\```php\n\nRegular paragraph.\n",
		$sysmda_conv->convert( '<p>```php</p><p>Regular paragraph.</p>' )
	);

	// …but an inline code span whose delimiter happens to be three backticks
	// must NOT be escaped: its closing run puts a backtick later on the line,
	// which is exactly what tells the two cases apart.
	check(
		'convert: an inline span with a long delimiter is left alone',
		"```a``b```\n",
		$sysmda_conv->convert( '<p><code>a``b</code></p>' )
	);

	// ── DOM pass + converter, end to end ────────────────────────────────────
	//
	// The separation fixes live in the DOM pass and only pay off once the
	// converter has run, so the two are pinned together: asserting the HTML
	// alone would not have caught a converter that glues the pieces back.
	$sysmda_e2e = static function ( $html ) use ( $sysmda_dom, $sysmda_conv ) {
		return $sysmda_conv->convert( $sysmda_dom( $html ) );
	};

	check(
		'e2e: captioned image separates from its caption',
		"![Alt](https://example.com/a.png)\n\nMy caption\n",
		$sysmda_e2e( '<figure class="wp-block-image"><img src="/a.png" alt="Alt"/><figcaption>My caption</figcaption></figure>' )
	);

	check(
		'e2e: details renders as a bold lead-in plus its body',
		"**More**\n\nHidden body\n",
		$sysmda_e2e( '<details class="wp-block-details"><summary>More</summary><p>Hidden body</p></details>' )
	);

	// The whole point of the fence fix, stated as the property that matters:
	// text that followed the code block is still text.
	check(
		'e2e: a code sample showing a fence does not swallow the article',
		"````\nSee:\n```\nx\n```\n````\n\nThe article continues here.\n",
		$sysmda_e2e( "<pre><code>See:\n```\nx\n```</code></pre><p>The article continues here.</p>" )
	);

	// ── Unnormalized <pre> reaching the converter ───────────────────────────
	//
	// process_dom() gives every <pre> a <code> child, so CodeElementConverter
	// normally builds the child fence and the parent passes it through. A bare
	// <pre> still gets here two ways: the documented
	// `sysmda_markdown_rendered_html` filter runs after process_dom(), and
	// process_dom() returns its input unchanged on a parse failure. These use
	// convert() directly, which is exactly that situation.

	// Reported on PR #65. Text that merely begins and ends with a backtick is
	// not an already-converted block, and passing it through unfenced let its
	// interior ``` swallow the rest of the document.
	check(
		'convert: bare <pre> bounded by backticks is fenced, not passed through',
		"````\n`a\n```\nb`\n````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre>`a\n```\nb`</pre><p>After.</p>" )
	);
	check(
		'convert: bare pre text that is a valid fence remains literal content',
		"````\n```\nx\n```\n````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre>```\nx\n```</pre><p>After.</p>" )
	);
	check(
		'convert: pre-conversion provenance is consumed per element',
		"```\na\n```\n\n\n````\n```\nb\n```\n````\n",
		$sysmda_conv->convert( "<pre><code>a</code></pre><pre>```\nb\n```</pre>" )
	);

	// A <pre> holding two <code> children has a code child but is still not one
	// self-contained block, so the child-presence test would not have caught it.
	check(
		'convert: <pre> with two code children is fenced as a whole',
		"`````\n````\na\n```\nb\n```` and ```\nc\n```\n`````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre><code>a\n```\nb</code> and <code>c</code></pre><p>After.</p>" )
	);

	// …and the ordinary shape must NOT pick up a second fence.
	check(
		'convert: an already-fenced block is not double-fenced',
		"```php\necho 1;\n```\n",
		$sysmda_conv->convert( '<pre><code class="language-php">echo 1;</code></pre>' )
	);
	check(
		'convert: a widened fence passes through untouched',
		"````\na\n```\nb\n````\n",
		$sysmda_conv->convert( "<pre><code>a\n```\nb</code></pre>" )
	);

	check(
		'convert: unclosed fenced text is wrapped by a wider fence',
		"````\n```\na\n````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre>```\na</pre><p>After.</p>" )
	);
	check(
		'convert: an invalid backtick info string is wrapped, not passed through',
		"````\n```a`b\nx\n```\n````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre>```a`b\nx\n```</pre><p>After.</p>" )
	);
	check(
		'convert: a short closing run is wrapped as literal preformatted text',
		"`````\n````\na\n```\n`````\n\nAfter.\n",
		$sysmda_conv->convert( "<pre>````\na\n```</pre><p>After.</p>" )
	);
	check( 'convert: bare empty pre is structurally empty', "```\n```\n", $sysmda_conv->convert( '<pre></pre>' ) );
	check(
		'convert: block highlighting contributes decoded text, not markup',
		"```\nx<y & z\n```\n",
		$sysmda_conv->convert( '<pre><span>x</span>&lt;y &amp; z</pre>' )
	);

	// Block boundaries preserve meaningful newlines but do not manufacture a
	// blank line before the closing fence when the source already ends in LF.
	check( 'convert: empty code block is structurally empty', "```\n```\n", $sysmda_conv->convert( '<pre><code></code></pre>' ) );
	check( 'convert: block without final LF gets one separator', "```\nx\n```\n", $sysmda_conv->convert( '<pre><code>x</code></pre>' ) );
	check( 'convert: one final LF gets no extra blank line', "```\nx\n```\n", $sysmda_conv->convert( "<pre><code>x\n</code></pre>" ) );
	check( 'convert: two final LFs preserve one intentional blank line', "```\nx\n\n```\n", $sysmda_conv->convert( "<pre><code>x\n\n</code></pre>" ) );
	check( 'convert: block CRLF normalizes to LF', "```\nx\ny\n```\n", $sysmda_conv->convert( "<pre><code>x\r\ny</code></pre>" ) );

	// Fallback language detection is deliberately conservative and ordered:
	// anchored language-* tokens, then code data attributes, then the parent pre.
	check(
		'convert: anchored language token wins among multiple classes',
		"```php\nx\n```\n",
		$sysmda_conv->convert( '<pre><code class="foo language-php language-js">x</code></pre>' )
	);
	check(
		'convert: code language class precedes its data attributes',
		"```php\nx\n```\n",
		$sysmda_conv->convert( '<pre><code class="language-php" data-language="js">x</code></pre>' )
	);
	check(
		'convert: misleading class is not a language token',
		"```\nx\n```\n",
		$sysmda_conv->convert( '<pre><code class="notlanguage-php">x</code></pre>' )
	);
	check(
		'convert: empty language token emits no info string',
		"```\nx\n```\n",
		$sysmda_conv->convert( '<pre><code class="language-">x</code></pre>' )
	);
	check(
		'convert: code data-language precedes code data-lang',
		"```php\nx\n```\n",
		$sysmda_conv->convert( '<pre><code data-language="php" data-lang="js">x</code></pre>' )
	);
	check(
		'convert: code data-lang is accepted when data-language is absent',
		"```js\nx\n```\n",
		$sysmda_conv->convert( '<pre><code data-lang="js">x</code></pre>' )
	);
	check(
		'convert: code data language precedes the parent pre language',
		"```php\nx\n```\n",
		$sysmda_conv->convert( '<pre class="language-ruby"><code data-language="php">x</code></pre>' )
	);
	check(
		'convert: parent pre language is used on the fallback path',
		"```ruby\nx\n```\n",
		$sysmda_conv->convert( '<pre class="foo language-ruby"><code>x</code></pre>' )
	);
	check(
		'convert: bare pre data language is sanitized',
		"```cpp\nx\n```\n",
		$sysmda_conv->convert( '<pre data-language="c`pp"><span>x</span></pre>' )
	);

	// Property-style block corpus: the outer delimiter always clears the longest
	// internal run and a sentinel paragraph remains outside the construct.
	foreach ( range( 0, 12 ) as $sysmda_run_length ) {
		$sysmda_run = str_repeat( '`', $sysmda_run_length );
		$sysmda_block_values = 0 === $sysmda_run_length
			? array( 'plain' => 'plain' )
			: array(
				'start'    => $sysmda_run . 'a',
				'middle'   => 'a' . $sysmda_run . 'b',
				'end'      => 'a' . $sysmda_run,
				'own-line' => "a\n" . $sysmda_run . "\nb",
			);

		foreach ( $sysmda_block_values as $sysmda_position => $sysmda_value ) {
			$sysmda_delimiter = str_repeat( '`', max( 3, $sysmda_run_length + 1 ) );
			$sysmda_expected  = $sysmda_delimiter . "\n" . $sysmda_value . "\n" . $sysmda_delimiter . "\n\nSentinel.\n";

			check(
				"convert property: block run {$sysmda_run_length} at {$sysmda_position}",
				$sysmda_expected,
				$sysmda_conv->convert( '<pre><code>' . $sysmda_value . '</code></pre><p>Sentinel.</p>' )
			);
		}
	}
}

// ─── LiteSpeedCompat::update (read-modify-write on a real file) ──────────────

// The pure string helpers above are the interesting logic, but the file path has
// its own failure modes (wrong fopen mode truncating before the read, a lock that
// is never released, a backup that clobbers itself). Exercised on a temp file:
// no WordPress needed, and it catches the kind of typo that only shows up on a
// live site's .htaccess — the one file whose breakage takes a site down.
$sysmda_update = sysmda_reflection_method( LiteSpeedCompat::class, 'update' );

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

// ─── LiteSpeedCompat::update (rollback when the write fails) ─────────────────

// The write is truncate-then-rewrite, so the file is empty for an instant. If
// the write then fails (a full disk, an I/O error) or falls short, the site is
// left with a broken .htaccess: dead permalinks or a 500 from a rule cut in
// half. Simulated with a stream wrapper whose writes can be made to fail, since
// a real ENOSPC is not reproducible in a test suite.
class Sysmda_Test_Stream {
	/** @var array<string,string> Contents of every path opened through the wrapper. */
	public static $data = array();
	/** @var int How many of the next write calls must fail. */
	public static $fail_writes = 0;
	/** @var bool Whether the first failing call writes half the payload first. */
	public static $partial = false;
	/** @var int Reads to serve before failing; negative never fails. */
	public static $fail_read_after = -1;

	/** @var resource|null Set by PHP on the wrapper instance. */
	public $context;
	private $path = '';
	private $pos  = 0;

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->path = $path;
		if ( ! isset( self::$data[ $path ] ) || false !== strpos( $mode, 'w' ) ) {
			self::$data[ $path ] = '';
		}
		$this->pos = 0;
		return true;
	}

	public function stream_read( $count ) {
		if ( self::$fail_read_after >= 0 && false === strpos( $this->path, '.sysmda-bak' ) ) {
			if ( 0 === self::$fail_read_after ) {
				return false;
			}
			--self::$fail_read_after;
		}

		$chunk      = substr( self::$data[ $this->path ], $this->pos, $count );
		$this->pos += strlen( $chunk );
		return $chunk;
	}

	public function stream_write( $data ) {
		// Only the file under test fails: the .sysmda-bak snapshot is written
		// through the same wrapper and would otherwise absorb the failure.
		if ( self::$fail_writes > 0 && false === strpos( $this->path, '.sysmda-bak' ) ) {
			--self::$fail_writes;
			if ( ! self::$partial ) {
				return 0; // Nothing could be written at all.
			}
			self::$partial = false;
			$data          = substr( $data, 0, (int) ( strlen( $data ) / 2 ) );
		}

		self::$data[ $this->path ] = substr_replace( self::$data[ $this->path ], $data, $this->pos, strlen( $data ) );
		$this->pos                += strlen( $data );
		return strlen( $data );
	}

	public function stream_truncate( $size ) {
		self::$data[ $this->path ] = substr( str_pad( self::$data[ $this->path ], $size, "\0" ), 0, $size );
		return true;
	}

	public function stream_seek( $offset, $whence = SEEK_SET ) {
		$base = SEEK_CUR === $whence ? $this->pos : ( SEEK_END === $whence ? strlen( self::$data[ $this->path ] ) : 0 );
		if ( $base + $offset < 0 ) {
			return false;
		}
		$this->pos = $base + $offset;
		return true;
	}

	public function stream_tell() {
		return $this->pos;
	}

	public function stream_eof() {
		return $this->pos >= strlen( self::$data[ $this->path ] );
	}

	public function stream_lock( $operation ) {
		return true;
	}

	public function stream_flush() {
		return true;
	}

	public function stream_stat() {
		return array( 'size' => strlen( self::$data[ $this->path ] ) );
	}

	public function url_stat( $path, $flags ) {
		return isset( self::$data[ $path ] ) ? array( 'size' => strlen( self::$data[ $path ] ) ) : false;
	}

	public function stream_close() {
		return true;
	}
}

stream_wrapper_register( 'sysmdatest', 'Sysmda_Test_Stream' );

$sysmda_fake_htaccess = 'sysmdatest://htaccess';

// Total write failure: the transform is lost, but the previous rules come back.
Sysmda_Test_Stream::$data        = array( $sysmda_fake_htaccess => $sysmda_wp_rules );
Sysmda_Test_Stream::$fail_writes = 1;
Sysmda_Test_Stream::$partial     = false;

check( 'update: failed write reports failure', false, (bool) $sysmda_update->invoke( null, $sysmda_fake_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: failed write restores the file', $sysmda_wp_rules, Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess ] );

// Short write: fwrite returns a byte count instead of false, so the count has to
// be compared with the payload or half a rule stays on disk reported as success.
Sysmda_Test_Stream::$data        = array( $sysmda_fake_htaccess => $sysmda_wp_rules );
Sysmda_Test_Stream::$fail_writes = 2;
Sysmda_Test_Stream::$partial     = true;

check( 'update: short write reports failure', false, (bool) $sysmda_update->invoke( null, $sysmda_fake_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: short write restores the file', $sysmda_wp_rules, Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess ] );

// Short write on a file that was empty (or had just been created): the rollback
// must still fire. "Empty is already the prior state" only holds when nothing
// was written — half a directive on disk is a broken .htaccess like any other,
// and truncating back to zero bytes is what undoes it.
Sysmda_Test_Stream::$data        = array( $sysmda_fake_htaccess => '' );
Sysmda_Test_Stream::$fail_writes = 2;
Sysmda_Test_Stream::$partial     = true;

check( 'update: short write on an empty file reports failure', false, (bool) $sysmda_update->invoke( null, $sysmda_fake_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: short write on an empty file leaves nothing behind', '', Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess ] );

// A read that fails PART WAY THROUGH must abort the whole update. Breaking out
// of the read loop and continuing treats the bytes gathered so far as the whole
// file: the transform runs on a truncation, the backup snapshots it, and the
// overwrite discards everything that was never read. The write-side rollback
// cannot help — the lost remainder never reached the buffer. The payload is
// deliberately larger than one 8 KiB fread() chunk, so the first read succeeds
// and the file is genuinely half-consumed when the second one fails.
$sysmda_big_htaccess              = $sysmda_wp_rules . str_repeat( "# padding\n", 1200 );
Sysmda_Test_Stream::$data         = array( $sysmda_fake_htaccess => $sysmda_big_htaccess );
Sysmda_Test_Stream::$fail_writes  = 0;
Sysmda_Test_Stream::$fail_read_after = 1;

check(
	'update: a read failure reports failure',
	false,
	(bool) $sysmda_update->invoke( null, $sysmda_fake_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) )
);
check(
	'update: a read failure leaves the file untouched',
	$sysmda_big_htaccess,
	Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess ]
);
check(
	'update: a read failure writes no backup',
	false,
	isset( Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess . '.sysmda-bak' ] )
);

Sysmda_Test_Stream::$fail_read_after = -1;

// The successful path must still work through the same wrapper: the rollback
// must not fire when the write went through.
Sysmda_Test_Stream::$data        = array( $sysmda_fake_htaccess => $sysmda_wp_rules );
Sysmda_Test_Stream::$fail_writes = 0;

check( 'update: healthy write reports success', true, (bool) $sysmda_update->invoke( null, $sysmda_fake_htaccess, array( LiteSpeedCompat::class, 'prepend_rules' ) ) );
check( 'update: healthy write applies the transform', LiteSpeedCompat::prepend_rules( $sysmda_wp_rules ), Sysmda_Test_Stream::$data[ $sysmda_fake_htaccess ] );

stream_wrapper_unregister( 'sysmdatest' );

// ─── ShortcodeCleaner: front-end controls never reach the Markdown ─────────

$sysmda_btn_cleaner = new ShortcodeCleaner();
$sysmda_btn_source  = "Intro\n\n[sysmda_md_button]\n\nBody";

check(
	'button exclusion: stripped with the default list',
	"Intro\n\n\n\nBody",
	$sysmda_btn_cleaner->strip( $sysmda_btn_source )
);

// The regression that matters: AdminSettings bridges a saved option that
// REPLACES the defaults, so a site owner who edited the "Excluded shortcodes"
// textarea would otherwise publish the button's HTML inside their .md.
$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_shortcodes'] = array( 'lwptoc' );
check(
	'button exclusion: stripped even when the filter drops it',
	"Intro\n\n\n\nBody",
	$sysmda_btn_cleaner->strip( $sysmda_btn_source )
);
check(
	'button exclusion: the filtered list still applies',
	'a  b',
	$sysmda_btn_cleaner->strip( 'a [lwptoc] b' )
);
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_shortcodes'] );

$sysmda_actions_source = "Intro\n\n[sysmda_md_actions]\n\nBody";
check(
	'actions exclusion: stripped with the default list',
	"Intro\n\n\n\nBody",
	$sysmda_btn_cleaner->strip( $sysmda_actions_source )
);

$GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_shortcodes'] = array( 'lwptoc' );
check(
	'actions exclusion: stripped even when the filter drops it',
	"Intro\n\n\n\nBody",
	$sysmda_btn_cleaner->strip( $sysmda_actions_source )
);
unset( $GLOBALS['sysmda_test_filters']['sysmda_markdown_excluded_shortcodes'] );

// ─── CodeRegions: a code sample is shown, not executed ─────────────────────

/*
 * A transform shaped like the real ones: it rewrites `[tag]` syntax and leaves
 * everything else — HTML comments included — alone. That last part is what
 * makes the masking work, and the fallback test below is what happens when a
 * transform does not honour it.
 */
$sysmda_detag = static function ( string $s ): string {
	return (string) preg_replace( '/\[[a-z0-9_-]+\]/i', 'GONE', $s );
};

check(
	'code regions: the transform runs on ordinary text',
	'before GONE after',
	CodeRegions::protect( 'before [tag] after', $sysmda_detag )
);
check(
	'code regions: a <pre> body is hidden from the transform',
	'GONE <pre>[tag]</pre>',
	CodeRegions::protect( '[tag] <pre>[tag]</pre>', $sysmda_detag )
);
check(
	'code regions: an inline <code> body is hidden too',
	'A <code>[tag]</code> GONE',
	CodeRegions::protect( 'A <code>[tag]</code> [tag]', $sysmda_detag )
);
check(
	'code regions: every region is restored, not just the first',
	'<pre>[one]</pre> GONE <code>[two]</code>',
	CodeRegions::protect( '<pre>[one]</pre> [x] <code>[two]</code>', $sysmda_detag )
);
check(
	'code regions: attributes on the opening tag stay with the hidden region',
	'<pre class="wp-block-code">[tag]</pre>',
	CodeRegions::protect( '<pre class="wp-block-code">[tag]</pre>', $sysmda_detag )
);
check(
	'code regions: content with no code region is transformed normally',
	'GONE and GONE',
	CodeRegions::protect( '[a] and [b]', $sysmda_detag )
);

/*
 * An enclosing shortcode may rewrite, escape or discard the body it is handed,
 * so a placeholder can legitimately fail to come back. The rule is that the
 * transform still runs exactly once: re-running it on the unmasked string to
 * "recover" would expand shortcodes inside the very code sample this class
 * protects, and would repeat every wrapper side effect. Raised by Codex on
 * PR #72 against the first version, which did exactly that.
 */
$sysmda_cr_calls = 0;
$sysmda_eater    = static function ( string $s ) use ( &$sysmda_cr_calls ): string {
	++$sysmda_cr_calls;

	// Stands in for a wrapper that consumed its body.
	return (string) preg_replace( '/sysmda_code_[0-9a-f]+_\d+/', 'EATEN', $s );
};

check(
	'code regions: a consumed placeholder is not recovered by re-running',
	'EATEN',
	CodeRegions::protect( '<pre>[gallery]</pre>', $sysmda_eater )
);
check(
	'code regions: the transform ran exactly once',
	1,
	$sysmda_cr_calls
);

// The realistic way a placeholder gets mangled is a wrapper escaping its body.
// A word-character token survives that, so the region is restored normally
// instead of being lost — which is why the token is not comment-shaped.
check(
	'code regions: an escaping transform still restores the region',
	'&lt;b&gt;x&lt;/b&gt; <pre>[gallery]</pre>',
	CodeRegions::protect(
		'<b>x</b> <pre>[gallery]</pre>',
		static function ( string $s ): string {
			return htmlspecialchars( $s, ENT_NOQUOTES );
		}
	)
);

// ─── ShortcodeCleaner: excluded tags survive inside code samples ───────────

/*
 * The regression this pair exists for. `strip()` runs on the raw source, before
 * anything is rendered, and had no notion of code: an article documenting an
 * excluded shortcode had it deleted from its own example, leaving
 * `echo do_shortcode('');`. Expansion had been protected since 0.38.1; removal
 * had not, so the same rule was applied to one half of the pipeline only.
 * Reproduced on staging before the fix.
 */
$sysmda_code_cleaner = new ShortcodeCleaner();

check(
	'strip: an excluded tag inside <pre><code> is preserved',
	"<pre><code>echo do_shortcode('[contact-form-7 id=\"42\"]');</code></pre>",
	$sysmda_code_cleaner->strip( "<pre><code>echo do_shortcode('[contact-form-7 id=\"42\"]');</code></pre>" )
);
check(
	'strip: an excluded tag inside inline <code> is preserved',
	'Write <code>[lwptoc]</code> to add an index.',
	$sysmda_code_cleaner->strip( 'Write <code>[lwptoc]</code> to add an index.' )
);
check(
	'strip: the same tag outside code is still removed',
	'Before  after',
	$sysmda_code_cleaner->strip( 'Before [contact-form-7 id="42"] after' )
);
check(
	'strip: documented and live occurrences are treated differently in one pass',
	'<code>[lwptoc]</code> shows the index; this one  is real.',
	$sysmda_code_cleaner->strip( '<code>[lwptoc]</code> shows the index; this one [lwptoc] is real.' )
);
check(
	'strip: ez-toc is excluded by default',
	'Index:  end',
	$sysmda_code_cleaner->strip( 'Index: [ez-toc] end' )
);

/*
 * Code protection reaches ALWAYS_EXCLUDED too, and that is a deliberate
 * narrowing of the 0.34.0 rule rather than a hole in it. That rule exists so a
 * bare `[sysmda_md_button]` left in old content after the feature was removed
 * does not surface as literal text — which still holds, first assertion below.
 * A tag written *inside a code span* is not a leftover, it is an author
 * documenting the shortcode, and this plugin's own settings page presents both
 * tags exactly that way. Stripping it would gut an article about this plugin
 * for the same reason it used to gut one about Contact Form 7.
 *
 * What the rule actually protects is unchanged either way: the shortcode never
 * *renders* into the Markdown, because a masked region is never expanded.
 */
check(
	'strip: a bare interface tag is still removed, wherever it was left',
	'old post x  y',
	$sysmda_code_cleaner->strip( 'old post x [sysmda_md_button] y' )
);
check(
	'strip: an interface tag shown as documentation inside code survives',
	'Add <code>[sysmda_md_actions]</code> to your template.',
	$sysmda_code_cleaner->strip( 'Add <code>[sysmda_md_actions]</code> to your template.' )
);

// ─── AdminSettings: the exclusion textarea adds to the defaults ────────────

/*
 * Replacing them was a trap with no visible symptom: typing one tag into
 * "Excluded shortcodes" silently dropped all five built-in ones, and the only
 * hint was a help text about the empty case. The defaults are a safety list, so
 * they accumulate.
 */
$sysmda_merge = sysmda_reflection_method( AdminSettings::class, 'option_to_merged_list' );
$sysmda_admin = new AdminSettings();

$GLOBALS['sysmda_test_options']['sysmda_excluded_shortcodes'] = "acme_form\nacme_optin";
check(
	'exclusion option: saved lines are added to the defaults, in order',
	array_merge( ShortcodeCleaner::DEFAULT_EXCLUDED, array( 'acme_form', 'acme_optin' ) ),
	$sysmda_merge->invoke( $sysmda_admin, 'sysmda_excluded_shortcodes', ShortcodeCleaner::DEFAULT_EXCLUDED )
);

$GLOBALS['sysmda_test_options']['sysmda_excluded_shortcodes'] = "  \n\nacme_form\n  \n";
check(
	'exclusion option: blank lines and padding are ignored',
	array_merge( ShortcodeCleaner::DEFAULT_EXCLUDED, array( 'acme_form' ) ),
	$sysmda_merge->invoke( $sysmda_admin, 'sysmda_excluded_shortcodes', ShortcodeCleaner::DEFAULT_EXCLUDED )
);

// Re-typing a default is harmless rather than a duplicate entry, which matters
// because the panel shows the defaults right under the box.
$GLOBALS['sysmda_test_options']['sysmda_excluded_shortcodes'] = "lwptoc\nacme_form";
check(
	'exclusion option: a default re-typed by hand is not duplicated',
	array_merge( ShortcodeCleaner::DEFAULT_EXCLUDED, array( 'acme_form' ) ),
	$sysmda_merge->invoke( $sysmda_admin, 'sysmda_excluded_shortcodes', ShortcodeCleaner::DEFAULT_EXCLUDED )
);

$GLOBALS['sysmda_test_options']['sysmda_excluded_shortcodes'] = '';
check(
	'exclusion option: an empty box leaves the defaults exactly as they are',
	ShortcodeCleaner::DEFAULT_EXCLUDED,
	$sysmda_merge->invoke( $sysmda_admin, 'sysmda_excluded_shortcodes', ShortcodeCleaner::DEFAULT_EXCLUDED )
);

// Site code hooking at priority 10 still decides what reaches the closure at
// 20, so a narrowed list stays narrowed: only the textarea is additive.
unset( $GLOBALS['sysmda_test_options']['sysmda_excluded_shortcodes'] );
check(
	'exclusion option: a filter-narrowed list is respected, the box adds to it',
	array( 'lwptoc' ),
	$sysmda_merge->invoke( $sysmda_admin, 'sysmda_excluded_shortcodes', array( 'lwptoc' ) )
);

// The panel must display the very lists that are applied, not a second copy.
check(
	'panel defaults: shortcodes come from the class that applies them',
	true,
	in_array( 'ez-toc', ShortcodeCleaner::DEFAULT_EXCLUDED, true )
);
check(
	'panel defaults: block names come from the class that applies them',
	true,
	in_array( 'luckywp/toc', BlockCleaner::DEFAULT_EXCLUDED, true )
);

// ─── Filter API: stability contract ──────────────────────────────────

/*
 * These do not exercise behaviour: they guard the *promises* docs/filters.md
 * makes, which nothing else in this suite can catch. The apply_filters() stub
 * above returns the default for any tag, so a hook that was renamed or deleted
 * changes no assertion anywhere — the suite stays green while the documented
 * API breaks. Two properties, checked against the source itself:
 *
 * 1. every Stable hook is still applied, with at least its documented arity;
 * 2. every hook applied in src/ is listed with a level in docs/filters.md.
 *
 * (2) is the AGENTS.md rule "update docs/filters.md in the same commit", made
 * mechanical: a new filter that nobody classified fails here rather than
 * silently joining the public surface at an undeclared level.
 */

/**
 * Hook => documented minimum `accepted_args`, i.e. how many arguments reach a
 * callback: the filtered value plus any context after it, NOT counting the tag.
 */
$sysmda_stable_hooks = array(
	'sysmda_markdown_supported_post_types'  => 1,
	'sysmda_markdown_excluded_post_formats' => 2,
	'sysmda_post_is_servable'               => 2,
	'sysmda_markdown_robots_header'         => 2,
	'sysmda_markdown_strict_406'            => 1,
	'sysmda_markdown_canonical_url'         => 2,
	'sysmda_cache_control'                  => 1,
	'sysmda_markdown_cache_ttl'             => 2,
	'sysmda_markdown_cache_dependencies'    => 2,
	'sysmda_markdown_output'                => 2,
	'sysmda_markdown_excluded_shortcodes'   => 1,
	'sysmda_markdown_excluded_block_names'  => 1,
	'sysmda_markdown_excluded_classes'      => 1,
	'sysmda_front_matter_enabled'           => 2,
	'sysmda_front_matter_taxonomy_slugs'    => 2,
	'sysmda_acf_subtitle_key'               => 2,
	'sysmda_acf_tldr_key'                   => 2,
	'sysmda_llms_txt_cache_ttl'             => 1,
	'sysmda_llms_txt_enriched'              => 1,
	'sysmda_llms_txt_lastmod'               => 1,
	'sysmda_llms_txt_summary'               => 1,
	'sysmda_llms_txt_key_content'           => 1,
);

/**
 * Every apply_filters() call in src/, as hook => list of per-call-site
 * `accepted_args`.
 *
 * Every call site is kept, not the highest: five hooks are applied from two
 * places, and a callback registered once fires at all of them. Collapsing them
 * to the maximum would let a complete call site mask one that dropped `$post`,
 * which is precisely the regression these checks exist to catch.
 *
 * Counted as the depth-0 commas following the tag, so each number is what a
 * callback receives — the tag itself is not one of them. Nested calls and array
 * literals in an argument do not inflate it. Good enough for a source guard,
 * and it never has to parse PHP it did not write.
 *
 * @return array<string,int[]>
 */
function sysmda_applied_filters(): array {
	$found = array();

	foreach ( glob( __DIR__ . '/../src/*.php' ) as $file ) {
		$source = (string) file_get_contents( $file );

		if ( ! preg_match_all( "/apply_filters(?:_deprecated)?\s*\(\s*'([a-z0-9_]+)'/", $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches[1] as $i => $match ) {
			$hook = $match[0];
			// Walk from the hook name to the closing parenthesis, counting the
			// commas that sit at depth 0 of this call.
			$pos   = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$depth = 0;
			$args  = 0;
			$len   = strlen( $source );

			for ( ; $pos < $len; $pos++ ) {
				$char = $source[ $pos ];

				if ( '(' === $char || '[' === $char ) {
					++$depth;
				} elseif ( ')' === $char || ']' === $char ) {
					if ( ')' === $char && 0 === $depth ) {
						break;
					}
					--$depth;
				} elseif ( ',' === $char && 0 === $depth ) {
					++$args;
				}
			}

			$found[ $hook ][] = $args;
		}
	}

	return $found;
}

$sysmda_applied = sysmda_applied_filters();

check( 'filter contract: the source scan finds the filters at all', true, count( $sysmda_applied ) > 25 );

foreach ( $sysmda_stable_hooks as $sysmda_hook => $sysmda_arity ) {
	// Renaming or removing a Stable hook is a breaking change (AGENTS.md):
	// it has to go through apply_filters_deprecated(), not just disappear.
	check( "filter contract: Stable hook {$sysmda_hook} is still applied", true, isset( $sysmda_applied[ $sysmda_hook ] ) );

	if ( isset( $sysmda_applied[ $sysmda_hook ] ) ) {
		// Dropping a documented parameter is breaking too: callbacks registered
		// with the documented accepted_args would start receiving null. Checked
		// against the WEAKEST call site, since one callback serves them all —
		// the documented arity has to hold everywhere, not on average.
		check(
			"filter contract: every call site of {$sysmda_hook} passes {$sysmda_arity} argument(s)",
			true,
			min( $sysmda_applied[ $sysmda_hook ] ) >= $sysmda_arity
		);
	}
}

/*
 * The docs live at the repository root, outside the plugin folder, and are
 * excluded from the distributed package. Skipped with a notice when absent, so
 * the suite still runs from a bare package; CI checks out the whole repo.
 */
$sysmda_filters_doc = __DIR__ . '/../../docs/filters.md';

if ( ! is_readable( $sysmda_filters_doc ) ) {
	echo "NOTICE: docs/filters.md not readable, skipping the documentation-sync checks.\n";
} else {
	$sysmda_doc = (string) file_get_contents( $sysmda_filters_doc );

	// The stability table is the canonical index: `| `hook` | Level |`.
	preg_match_all( '/^\|\s*`(sysmda_[a-z0-9_]+)`\s*\|\s*(Stable|Advanced)\s*\|/m', $sysmda_doc, $sysmda_rows );
	$sysmda_classified = array_combine( $sysmda_rows[1], $sysmda_rows[2] );

	check( 'filter contract: the stability table parses', true, count( $sysmda_classified ) > 25 );

	foreach ( array_keys( $sysmda_applied ) as $sysmda_hook ) {
		check( "filter contract: {$sysmda_hook} has a documented stability level", true, isset( $sysmda_classified[ $sysmda_hook ] ) );
	}

	// The other direction: a hook removed from the code but left in the table
	// would keep promising something that no longer exists.
	foreach ( array_keys( $sysmda_classified ) as $sysmda_hook ) {
		check( "filter contract: documented hook {$sysmda_hook} still exists in src/", true, isset( $sysmda_applied[ $sysmda_hook ] ) );
	}

	// And the two views of "Stable" must agree **in both directions**, or this
	// file and the docs drift apart while each stays internally consistent.
	foreach ( $sysmda_stable_hooks as $sysmda_hook => $sysmda_unused ) {
		check(
			"filter contract: {$sysmda_hook} is documented as Stable",
			'Stable',
			isset( $sysmda_classified[ $sysmda_hook ] ) ? $sysmda_classified[ $sysmda_hook ] : 'MISSING'
		);
	}

	// The reverse leg is the one that bites in practice: promoting a hook to
	// Stable in the canonical table alone would leave it with none of the arity
	// checks above, while the suite reported agreement it had never tested.
	foreach ( $sysmda_classified as $sysmda_hook => $sysmda_level ) {
		if ( 'Stable' !== $sysmda_level ) {
			continue;
		}

		check(
			"filter contract: documented-Stable {$sysmda_hook} is covered by the arity checks",
			true,
			isset( $sysmda_stable_hooks[ $sysmda_hook ] )
		);
	}
}

// ─── Result ───────────────────────────────────────────────────────────────────

echo "\n{$GLOBALS['sysmda_asserts']} assertions, {$GLOBALS['sysmda_failures']} failed.\n";
exit( $GLOBALS['sysmda_failures'] > 0 ? 1 : 0 );

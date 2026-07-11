<?php
/**
 * Test locali della logica pura del plugin, senza WordPress né PHPUnit.
 *
 * Uso:  php tests/run-tests.php
 *
 * Copre le classi testabili in isolamento (AcceptNegotiator, BlockCleaner,
 * MetadataBuilder::markdown_url) tramite stub minimi delle funzioni WP usate.
 * Esce con codice 1 se almeno un assert fallisce.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

// ─── Ambiente ────────────────────────────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// ─── Stub WordPress (solo ciò che serve alle classi sotto test) ─────────────

$GLOBALS['sysmda_test_posts']   = array(); // id → WP_Post
$GLOBALS['sysmda_test_parsed']  = array(); // content → blocks
$GLOBALS['sysmda_test_options'] = array(); // option → value

/** Stub: i filtri restituiscono il valore di default. */
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
	// Versione semplificata del regex core, sufficiente per i tag testati.
	$tagregexp = implode( '|', array_map( 'preg_quote', (array) $tags ) );
	return '(\\[)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}

/** Stub minimale di WP_Post (nel namespace globale, come in WordPress). */
class WP_Post {
	public $ID           = 0;
	public $post_type    = 'post';
	public $post_status  = 'publish';
	public $post_content = '';
	public $permalink    = '';

	public function __construct( array $props = array() ) {
		foreach ( $props as $k => $v ) {
			$this->$k = $v;
		}
	}
}

// ─── Classi sotto test ───────────────────────────────────────────────────────

require __DIR__ . '/../src/AcceptNegotiator.php';
require __DIR__ . '/../src/ShortcodeCleaner.php';
require __DIR__ . '/../src/BlockCleaner.php';
require __DIR__ . '/../src/MetadataBuilder.php';
require __DIR__ . '/../src/LlmsTxtController.php';
require __DIR__ . '/../src/MarkdownController.php';

use Diecieventi\SystemMarkdownAlternate\AcceptNegotiator;
use Diecieventi\SystemMarkdownAlternate\BlockCleaner;
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

// parse: q di default, clamping, duplicati sul q massimo, range malformati ignorati.
check( 'parse: q default 1.0', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html' ) );
check( 'parse: q esplicito', array( 'text/html' => 0.5 ), AcceptNegotiator::parse( 'text/html;q=0.5' ) );
check( 'parse: clamp a [0,1]', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html;q=7' ) );
check( 'parse: q non numerico → 1.0', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'text/html;q=abc' ) );
check( 'parse: duplicato → q max', array( 'text/html' => 0.9 ), AcceptNegotiator::parse( 'text/html;q=0.2, text/html;q=0.9' ) );
check( 'parse: senza slash ignorato', array(), AcceptNegotiator::parse( 'html, json' ) );
check( 'parse: vuoto', array(), AcceptNegotiator::parse( '' ) );
check( 'parse: case-insensitive', array( 'text/html' => 1.0 ), AcceptNegotiator::parse( 'TEXT/HTML' ) );

// quality: specificità match esatto > tipo/* > */*.
$accept = 'text/markdown;q=0.9, text/*;q=0.5, */*;q=0.1';
check( 'quality: match esatto', 0.9, AcceptNegotiator::quality( $accept, 'text/markdown' ) );
check( 'quality: wildcard sottotipo', 0.5, AcceptNegotiator::quality( $accept, 'text/html' ) );
check( 'quality: wildcard totale', 0.1, AcceptNegotiator::quality( $accept, 'image/png' ) );
check( 'quality: tipo non accettato', 0.0, AcceptNegotiator::quality( 'text/html', 'application/json' ) );
check( 'quality: q=0 esplicito', 0.0, AcceptNegotiator::quality( 'text/html;q=0', 'text/html' ) );

// explicit_quality: nessun fallback su wildcard.
check( 'explicit: elencato', 0.9, AcceptNegotiator::explicit_quality( $accept, 'text/markdown' ) );
check( 'explicit: coperto solo da wildcard → null', null, AcceptNegotiator::explicit_quality( '*/*', 'text/markdown' ) );
check( 'explicit: assente → null', null, AcceptNegotiator::explicit_quality( 'text/html', 'text/markdown' ) );

// Scenario chiave della negotiation: Accept "*/*" (curl) NON preferisce markdown.
check( 'negotiation: curl */* resta HTML', null, AcceptNegotiator::explicit_quality( '*/*', 'text/markdown' ) );
// Scenario: markdown esplicito con q pari all'HTML → servito (md >= html).
check(
	'negotiation: q pari → markdown',
	true,
	AcceptNegotiator::explicit_quality( 'text/markdown, text/html', 'text/markdown' )
		>= AcceptNegotiator::quality( 'text/markdown, text/html', 'text/html' )
);

// ─── BlockCleaner ────────────────────────────────────────────────────────────

/** ShortcodeCleaner che non tocca nulla: l'espansione è testata a parte. */
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

// Esclusione per blockName.
$out = $cleaner->clean( array( make_block( 'core/paragraph' ), make_block( 'gravityforms/form' ) ) );
check( 'blocks: form escluso', 1, count( $out ) );
check( 'blocks: paragraph conservato', 'core/paragraph', $out[0]['blockName'] );

// Esclusione per className, anche annidata, con riallineamento innerContent.
$group = make_block(
	'core/group',
	array(
		make_block( 'core/paragraph' ),
		make_block( 'core/paragraph', array(), array( 'className' => 'x md-exclude y' ) ),
		make_block( 'core/paragraph' ),
	)
);
$out = $cleaner->clean( array( $group ) );
check( 'blocks: inner escluso per classe', 2, count( $out[0]['innerBlocks'] ) );
check( 'blocks: innerContent riallineato', 2, count( array_filter( $out[0]['innerContent'], 'is_null' ) ) );

// Freeform (blockName null) conservato.
$out = $cleaner->clean( array( array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerContent' => array( '<p>x</p>' ), 'innerHTML' => '<p>x</p>' ) ) );
check( 'blocks: freeform conservato', 1, count( $out ) );

// Espansione core/block: il contenuto referenziato viene ripulito.
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
check( 'reusable: espanso e ripulito', 1, count( $out ) );
check( 'reusable: resta il paragraph', 'core/paragraph', $out[0]['blockName'] );

// core/block verso bozza o post inesistente → scartato.
$GLOBALS['sysmda_test_posts'][11] = new WP_Post( array( 'ID' => 11, 'post_type' => 'wp_block', 'post_status' => 'draft', 'post_content' => 'X' ) );
check( 'reusable: bozza scartata', array(), $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 11 ) ) ) ) );
check( 'reusable: ref inesistente scartato', array(), $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 999 ) ) ) ) );

// Guardia anti-ricorsione: pattern che referenzia sé stesso.
$GLOBALS['sysmda_test_posts'][12] = new WP_Post( array( 'ID' => 12, 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => 'PATTERN_SELF' ) );
$GLOBALS['sysmda_test_parsed']['PATTERN_SELF'] = array( make_block( 'core/paragraph' ), make_block( 'core/block', array(), array( 'ref' => 12 ) ) );
$out = $cleaner->clean( array( make_block( 'core/block', array(), array( 'ref' => 12 ) ) ) );
check( 'reusable: ciclo interrotto', 1, count( $out ) );

// Espansione annidata dentro un wrapper: i segnaposto si moltiplicano.
$GLOBALS['sysmda_test_parsed']['PATTERN_A2'] = array( make_block( 'core/paragraph' ), make_block( 'core/paragraph' ) );
$GLOBALS['sysmda_test_posts'][13] = new WP_Post( array( 'ID' => 13, 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => 'PATTERN_A2' ) );
$out = $cleaner->clean( array( make_block( 'core/group', array( make_block( 'core/block', array(), array( 'ref' => 13 ) ) ) ) ) );
check( 'reusable annidato: 2 innerBlocks', 2, count( $out[0]['innerBlocks'] ) );
check( 'reusable annidato: 2 segnaposto', 2, count( $out[0]['innerContent'] ) );

// ─── MetadataBuilder::markdown_url ───────────────────────────────────────────

$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';

$p = new WP_Post( array( 'permalink' => 'https://example.com/my-post/' ) );
check( 'url: pretty con trailing slash', 'https://example.com/my-post.md', MetadataBuilder::markdown_url( $p ) );

$p = new WP_Post( array( 'permalink' => 'https://example.com/blog/my-post' ) );
check( 'url: pretty senza trailing slash', 'https://example.com/blog/my-post.md', MetadataBuilder::markdown_url( $p ) );

$p = new WP_Post( array( 'permalink' => 'https://example.com:8080/my-post/' ) );
check( 'url: porta preservata', 'https://example.com:8080/my-post.md', MetadataBuilder::markdown_url( $p ) );

// Permalink Plain: fallback ?format=markdown.
$GLOBALS['sysmda_test_options']['permalink_structure'] = '';
$p = new WP_Post( array( 'permalink' => 'https://example.com/?p=123' ) );
check( 'url: plain → format=markdown', 'https://example.com/?p=123&format=markdown', MetadataBuilder::markdown_url( $p ) );

// Struttura pretty ma permalink con query (es. ?page_id): stesso fallback.
$GLOBALS['sysmda_test_options']['permalink_structure'] = '/%postname%/';
$p = new WP_Post( array( 'permalink' => 'https://example.com/?page_id=2' ) );
check( 'url: query string → format=markdown', 'https://example.com/?page_id=2&format=markdown', MetadataBuilder::markdown_url( $p ) );

// Homepage (path "/") → fallback, niente /index.md.
$p = new WP_Post( array( 'permalink' => 'https://example.com/' ) );
check( 'url: homepage → format=markdown', 'https://example.com/?format=markdown', MetadataBuilder::markdown_url( $p ) );

// ─── LlmsTxtController: escaping delle righe ─────────────────────────────────

// escape_link_text: escape dei caratteri che romperebbero [testo](url).
check( 'llms: link text semplice', 'Ciao mondo', LlmsTxtController::escape_link_text( 'Ciao mondo' ) );
check( 'llms: parentesi quadre', 'Titolo \\[bozza\\]', LlmsTxtController::escape_link_text( 'Titolo [bozza]' ) );
check( 'llms: parentesi tonde', 'Guida \\(2024\\)', LlmsTxtController::escape_link_text( 'Guida (2024)' ) );
check( 'llms: backslash escapato una volta', 'a\\\\b', LlmsTxtController::escape_link_text( 'a\\b' ) );
check( 'llms: newline → riga singola', 'Riga uno Riga due', LlmsTxtController::escape_link_text( "Riga uno\nRiga due" ) );
check( 'llms: caratteri di controllo rimossi', 'A B', LlmsTxtController::escape_link_text( "A\t\x00B" ) );
check( 'llms: whitespace collassato e trimmato', 'X Y', LlmsTxtController::escape_link_text( "  X   Y  " ) );

// normalize_inline: solo riga singola, nessun escape dei bracket (description).
check( 'llms: description multi-riga → singola', 'Uno due tre', LlmsTxtController::normalize_inline( "Uno\ndue\r\ntre" ) );
check( 'llms: description bracket intatti', 'vedi [1] e (2)', LlmsTxtController::normalize_inline( 'vedi [1] e (2)' ) );

// lastmod_suffix: suffisso `(updated: YYYY-MM-DD)` per le voci dell'indice.
check( 'llms: lastmod data valida', '(updated: 2026-07-01)', LlmsTxtController::lastmod_suffix( '2026-07-01 08:30:00' ) );
check( 'llms: lastmod solo data', '(updated: 2024-12-31)', LlmsTxtController::lastmod_suffix( '2024-12-31' ) );
check( 'llms: lastmod data vuota', '', LlmsTxtController::lastmod_suffix( '' ) );
check( 'llms: lastmod data azzerata', '', LlmsTxtController::lastmod_suffix( '0000-00-00 00:00:00' ) );
check( 'llms: lastmod stringa non valida', '', LlmsTxtController::lastmod_suffix( 'non-una-data' ) );

// ─── MarkdownController::etag_matches ────────────────────────────────────────

check( 'etag: jolly *', true, MarkdownController::etag_matches( '*', '"abc"' ) );
check( 'etag: match esatto', true, MarkdownController::etag_matches( '"abc"', '"abc"' ) );
check( 'etag: nessun match', false, MarkdownController::etag_matches( '"xyz"', '"abc"' ) );
check( 'etag: lista con match', true, MarkdownController::etag_matches( '"xyz", "abc"', '"abc"' ) );
check( 'etag: prefisso weak W/', true, MarkdownController::etag_matches( 'W/"abc"', '"abc"' ) );
check( 'etag: header vuoto', false, MarkdownController::etag_matches( '', '"abc"' ) );

// ─── Esito ───────────────────────────────────────────────────────────────────

echo "\n{$GLOBALS['sysmda_asserts']} assert, {$GLOBALS['sysmda_failures']} falliti.\n";
exit( $GLOBALS['sysmda_failures'] > 0 ? 1 : 0 );

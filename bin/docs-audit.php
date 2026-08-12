<?php
/**
 * Reports where the documentation has fallen behind the plugin.
 *
 * Run on demand — `php bin/docs-audit.php` — typically before a release or when
 * picking documentation work back up. It reads only local files: no network, no
 * tokens, nothing scheduled.
 *
 * It informs and never writes. Every finding is a place for a human (or an
 * agent asked to) to decide what the documentation should say; nothing here
 * knows that.
 *
 * STEP ZERO, NOT THE CHECK. It answers one narrow question — is there a filter,
 * panel field or shortcode named nowhere at all — because that is the gap a
 * human reading diffs can miss entirely. Judging whether a change altered how
 * the plugin is used needs the diff read, and that is what the "check the
 * documentation" procedure in AGENTS.md is for.
 *
 * So a clean run does NOT mean the documentation is current. `0.40.0` turned
 * the exclusion lists from "replace the defaults" into "add to the defaults" —
 * same filter, same field, same names — and this script would have said
 * nothing at all.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

declare( strict_types = 1 );

$root      = dirname( __DIR__ );
$src       = $root . '/system-markdown-alternate/src';
$user_docs = $root . '/documentation/src/content/docs';
$filters   = $root . '/docs/filters.md';
$output    = $root . '/docs/output-format.md';

foreach ( array( $src, $user_docs, $filters, $output ) as $path ) {
	if ( ! file_exists( $path ) ) {
		fwrite( STDERR, "Missing: {$path}\n" );
		exit( 2 );
	}
}

$plugin_code   = read_all( $src, 'php' );
$user_doc_text = read_all( $user_docs, 'md' );
$contract_text = file_get_contents( $filters ) . file_get_contents( $output );
$registered    = registrations( $src );

/*
 * The two audiences are checked separately, because they are documented in
 * different places on purpose. A filter belongs in docs/filters.md — AGENTS.md
 * puts it plainly: a filter that is not there does not exist as far as the
 * public API is concerned. Panel fields and shortcodes belong in
 * documentation/, which is what a site owner reads. Demanding that all 32
 * filters also have a user-facing article would report a gap that is not one.
 */
$findings = 0;

/*
 * Searched in filters.md ALONE, not in the combined contract text. The two
 * files overlap — output-format.md names filters where they affect the document
 * — so searching both would count a filter as contracted because the output
 * format happens to mention it in passing. The check says "missing from
 * docs/filters.md" and now means it.
 */
$findings += report(
	'Filters missing from docs/filters.md',
	array_diff( $registered['filters'], collect( '/`?(sysmda_[a-z0-9_]+)`?/', file_get_contents( $filters ) ) ),
	'The public API contract. Add each one there, with its default and stability level.'
);

/*
 * Panel fields are probed by LABEL, not by option name.
 *
 * The first version of this script matched the field id — `sysmda_cache_ttl` —
 * and reported all sixteen fields as undocumented, every one a false positive:
 * user documentation speaks in the words on the screen ("Cache TTL"), not in
 * option keys, and rightly so. Sixteen findings that are all noise is worse
 * than no tool, because nobody reads the seventeenth run.
 *
 * A field counts as covered when either its id or its label appears. The
 * trailing parenthetical of a label is dropped first: "Cache TTL (seconds)"
 * is written up as "Cache TTL".
 */
$uncovered = array();

foreach ( $registered['fields'] as $id => $label ) {
	// `*_notice` rows render an explanation, not a control — there is nothing
	// for a reader to set, so nothing to document as a setting. A convention
	// rather than a list of exceptions, so a future notice is covered too.
	// (Written without str_ends_with(): the repository's PHP baseline is 7.4.)
	if ( '_notice' === substr( $id, -7 ) ) {
		continue;
	}

	$documented = false !== stripos( $user_doc_text, $id )
		|| ( '' !== $label && false !== stripos( $user_doc_text, $label ) );

	if ( ! $documented ) {
		$uncovered[] = '' === $label ? $id : "{$id}  (“{$label}”)";
	}
}

$findings += report(
	'Panel fields not mentioned in documentation/',
	$uncovered,
	'Every field the user can change should have an article under settings/.'
);

$findings += report(
	'Shortcodes not mentioned in documentation/',
	array_diff( $registered['shortcodes'], collect( '/\[?(sysmda_[a-z0-9_]+)/', $user_doc_text ) ),
	'Each shortcode should have an article under shortcodes/.'
);

/*
 * The reverse direction. A name the documentation still explains but the code
 * no longer contains is worse than a gap: the reader follows instructions that
 * cannot work. Checked against every `sysmda_*` string in the source rather
 * than against the extracted lists, so renames and internal helpers do not
 * produce noise.
 */
$findings += report(
	'Named in the documentation but absent from the source',
	array_diff( collect( '/(sysmda_[a-z0-9_]+)/', $user_doc_text . $contract_text ), collect( '/(sysmda_[a-z0-9_]+)/', $plugin_code ) ),
	'Removed, renamed, or a typo. Each one sends a reader somewhere that does not exist.'
);

echo "\n";

if ( 0 === $findings ) {
	echo "No symbol is missing or stale.\n";
	echo "This is step zero only — see \"check the documentation\" in AGENTS.md for the rest.\n";
}

exit( $findings > 0 ? 1 : 0 );

/**
 * Every filter, panel field and shortcode the plugin registers.
 *
 * A hook name is not always a literal: `MarkdownActions` registers its
 * shortcode as `add_shortcode( self::TAG, … )`. An earlier version of this
 * script matched quoted arguments only, so `sysmda_md_actions` was absent from
 * the list — and deleting its entire article left the audit reporting nothing,
 * verified by doing exactly that. A check with a silent blind spot is the
 * failure this script exists to prevent, one level up.
 *
 * Constants are therefore resolved, and resolved **per file** rather than from
 * one flat table: `TAG` means `sysmda_md_url` in `DynamicTags` and
 * `sysmda_md_actions` in `MarkdownActions`, so a global map would answer with
 * whichever was read last. A qualified reference to another class falls back to
 * the union, which is the only thing that can be said without parsing PHP
 * properly — this stays a regex, deliberately.
 *
 * @return array{filters: string[], fields: array<string, string>, shortcodes: string[]}
 */
function registrations( string $dir ): array {
	$files  = php_files( $dir );
	$global = array();
	$source = array();

	foreach ( $files as $file ) {
		$code            = file_get_contents( $file );
		$source[ $file ] = $code;

		preg_match_all( "/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'/", $code, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$global[ $match[1] ][ $match[2] ] = true;
		}
	}

	$found = array(
		'filters'    => array(),
		'fields'     => array(),
		'shortcodes' => array(),
	);

	// A quoted string, or a class-constant reference.
	$argument = "(?:'([^']+)'|(?:self|static|parent|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Z][A-Z0-9_]*))";

	foreach ( $source as $file => $code ) {
		$local = array();

		preg_match_all( "/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'/", $code, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$local[ $match[1] ] = $match[2];
		}

		foreach ( resolve_all( "/apply_filters(?:_deprecated)?\(\s*{$argument}/", $code, $local, $global ) as $name ) {
			$found['filters'][ $name ] = true;
		}

		foreach ( resolve_all( "/add_shortcode\(\s*{$argument}/", $code, $local, $global ) as $name ) {
			$found['shortcodes'][ $name ] = true;
		}

		preg_match_all( "/add_settings_field\(\s*{$argument}\s*,\s*(?:__\(\s*)?(?:'([^']+)')?/", $code, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			foreach ( resolve( $match[1] ?? '', $match[2] ?? '', $local, $global ) as $id ) {
				$label = trim( preg_replace( '/\s*\([^)]*\)\s*$/', '', $match[3] ?? '' ) );

				$found['fields'][ $id ] = $label;
			}
		}
	}

	$filters    = array_keys( $found['filters'] );
	$shortcodes = array_keys( $found['shortcodes'] );

	sort( $filters );
	sort( $shortcodes );
	ksort( $found['fields'] );

	return array(
		'filters'    => $filters,
		'fields'     => $found['fields'],
		'shortcodes' => $shortcodes,
	);
}

/**
 * All names a pattern's first argument resolves to, across every match.
 *
 * @param array<string, string>             $local  Constants declared in this file.
 * @param array<string, array<string, bool>> $global Constants declared anywhere.
 * @return string[]
 */
function resolve_all( string $pattern, string $code, array $local, array $global ): array {
	preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER );

	$names = array();

	foreach ( $matches as $match ) {
		foreach ( resolve( $match[1] ?? '', $match[2] ?? '', $local, $global ) as $name ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * A literal, or the value(s) a constant reference stands for.
 *
 * @param array<string, string>              $local  Constants declared in this file.
 * @param array<string, array<string, bool>> $global Constants declared anywhere.
 * @return string[]
 */
function resolve( string $literal, string $constant, array $local, array $global ): array {
	if ( '' !== $literal ) {
		return array( $literal );
	}

	if ( '' === $constant ) {
		return array();
	}

	if ( isset( $local[ $constant ] ) ) {
		return array( $local[ $constant ] );
	}

	return isset( $global[ $constant ] ) ? array_keys( $global[ $constant ] ) : array();
}

/**
 * @return string[]
 */
function php_files( string $dir ): array {
	$files = array();
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $items as $item ) {
		if ( $item->isFile() && 'php' === $item->getExtension() ) {
			$files[] = $item->getPathname();
		}
	}

	sort( $files );

	return $files;
}

/**
 * Concatenates every file of an extension under a directory.
 */
function read_all( string $dir, string $extension ): string {
	$text  = '';
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $items as $item ) {
		if ( $item->isFile() && $extension === $item->getExtension() ) {
			$text .= file_get_contents( $item->getPathname() ) . "\n";
		}
	}

	return $text;
}

/**
 * Unique capture-group matches, sorted.
 *
 * @return string[]
 */
function collect( string $pattern, string $subject ): array {
	preg_match_all( $pattern, $subject, $matches );

	$found = array_values( array_unique( $matches[1] ) );
	sort( $found );

	return $found;
}

/**
 * Prints a section, and returns how many items it held.
 *
 * @param string[] $items Symbols.
 */
function report( string $title, array $items, string $advice ): int {
	if ( empty( $items ) ) {
		return 0;
	}

	echo "\n{$title} (" . count( $items ) . ")\n";
	echo str_repeat( '-', strlen( $title ) + 8 ) . "\n";

	foreach ( $items as $item ) {
		echo "  {$item}\n";
	}

	echo "  → {$advice}\n";

	return count( $items );
}

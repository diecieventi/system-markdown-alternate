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
 * WHAT IT CANNOT SEE, and this is the important limit: a behaviour change that
 * moves no symbol. `0.40.0` turned the exclusion lists from "replace the
 * defaults" into "add to the defaults" — same filter, same field, same names,
 * and the article about them became false in silence. No symbol diff finds
 * that, which is why the last section prints recent changelog entries: they are
 * the only record of intent, and they have to be read.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

declare( strict_types = 1 );

$root      = dirname( __DIR__ );
$src       = $root . '/system-markdown-alternate/src';
$user_docs = $root . '/documentation/src/content/docs';
$filters   = $root . '/docs/filters.md';
$output    = $root . '/docs/output-format.md';
$changelog = $root . '/CHANGELOG.md';

$entries = 3;
foreach ( array_slice( $argv, 1 ) as $i => $arg ) {
	if ( '--changelog' === $arg && isset( $argv[ $i + 2 ] ) ) {
		$entries = max( 0, (int) $argv[ $i + 2 ] );
	}
}

foreach ( array( $src, $user_docs, $filters, $output, $changelog ) as $path ) {
	if ( ! file_exists( $path ) ) {
		fwrite( STDERR, "Missing: {$path}\n" );
		exit( 2 );
	}
}

$plugin_code   = read_all( $src, 'php' );
$user_doc_text = read_all( $user_docs, 'md' );
$contract_text = file_get_contents( $filters ) . file_get_contents( $output );

/*
 * The two audiences are checked separately, because they are documented in
 * different places on purpose. A filter belongs in docs/filters.md — AGENTS.md
 * puts it plainly: a filter that is not there does not exist as far as the
 * public API is concerned. Panel fields and shortcodes belong in
 * documentation/, which is what a site owner reads. Demanding that all 32
 * filters also have a user-facing article would report a gap that is not one.
 */
$findings = 0;

$findings += report(
	'Filters missing from docs/filters.md',
	array_diff( collect( "/apply_filters(?:_deprecated)?\(\s*'([a-z_]+)'/", $plugin_code ), collect( '/`?(sysmda_[a-z_]+)`?/', $contract_text ) ),
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
$fields    = array();
$uncovered = array();

preg_match_all( "/add_settings_field\(\s*'([a-z_]+)',\s*(?:__\(\s*)?'([^']+)'/", $plugin_code, $matches, PREG_SET_ORDER );

foreach ( $matches as $match ) {
	$fields[ $match[1] ] = trim( preg_replace( '/\s*\([^)]*\)\s*$/', '', $match[2] ) );
}

foreach ( $fields as $id => $label ) {
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
		$uncovered[] = "{$id}  (“{$label}”)";
	}
}

$findings += report(
	'Panel fields not mentioned in documentation/',
	$uncovered,
	'Every field the user can change should have an article under settings/.'
);

$findings += report(
	'Shortcodes not mentioned in documentation/',
	array_diff( collect( "/add_shortcode\(\s*'([a-z_]+)'/", $plugin_code ), collect( '/\[?(sysmda_[a-z_]+)/', $user_doc_text ) ),
	'Each shortcode should have an article under shortcodes/.'
);

/*
 * The reverse direction. A name the documentation still explains but the code
 * no longer contains is worse than a gap: the reader follows instructions that
 * cannot work. Checked against every `sysmda_*` string in the source rather
 * than against the extracted lists, so renames and internal helpers do not
 * produce noise.
 */
$known = collect( '/(sysmda_[a-z_]+)/', $plugin_code );

$findings += report(
	'Named in the documentation but absent from the source',
	array_diff( collect( '/(sysmda_[a-z_]+)/', $user_doc_text . $contract_text ), $known ),
	'Removed, renamed, or a typo. Each one sends a reader somewhere that does not exist.'
);

echo "\n";

if ( 0 === $findings ) {
	echo "No gaps found in what can be checked mechanically.\n";
}

if ( $entries > 0 ) {
	echo "\n";
	echo "Recent changelog entries — read these for behaviour changes that moved no symbol,\n";
	echo "which is the class of drift nothing above can detect:\n\n";

	echo trim( recent_changelog( file_get_contents( $changelog ), $entries ) ) . "\n";
}

exit( $findings > 0 ? 1 : 0 );

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

/**
 * The most recent `## X.Y.Z` sections of the changelog.
 */
function recent_changelog( string $changelog, int $count ): string {
	$parts = preg_split( '/^(## \d+\.\d+\.\d+.*)$/m', $changelog, -1, PREG_SPLIT_DELIM_CAPTURE );

	if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
		return '(could not parse CHANGELOG.md)';
	}

	$text = '';

	for ( $i = 1; $i < count( $parts ) && $count > 0; $i += 2, $count-- ) {
		$text .= $parts[ $i ] . "\n" . rtrim( $parts[ $i + 1 ] ?? '' ) . "\n\n";
	}

	return $text;
}

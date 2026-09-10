<?php
/**
 * Stubs that must live INSIDE the plugin's namespace, which the main runner
 * (global namespace, `use` statements) cannot declare itself.
 *
 * PHP resolves an unqualified function call to the current namespace first and
 * only then to the global one, so a function declared here shadows the built-in
 * for every unqualified call made by the plugin's classes. That is the whole
 * mechanism, and it is what makes emitted headers assertable: without it
 * `header()` is a silent no-op under the CLI SAPI and a test can only infer
 * what the response would have carried, which is exactly the kind of inference
 * that let `Last-Modified` go out on responses whose date the plugin had
 * already judged unusable.
 *
 * Tests only: `tests/` is excluded from every distributable package.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

/**
 * Stub: records the headers the response would send, in order.
 *
 * Reset with `$GLOBALS['sysmda_test_headers'] = array();` per test.
 *
 * @param string $header         Full header line.
 * @param bool   $replace        Unused: no test depends on replace semantics.
 * @param int    $response_code  Unused.
 */
function header( string $header, bool $replace = true, int $response_code = 0 ): void {
	$GLOBALS['sysmda_test_headers'][] = $header;
}

/**
 * Returns the value of a captured header, or '' when it was not sent.
 *
 * @param string $name Header name, case-insensitive, without the colon.
 */
function sysmda_test_header( string $name ): string {
	$prefix = strtolower( $name ) . ':';

	foreach ( (array) $GLOBALS['sysmda_test_headers'] as $line ) {
		if ( 0 === strpos( strtolower( $line ), $prefix ) ) {
			return trim( substr( $line, strlen( $prefix ) ) );
		}
	}

	return '';
}

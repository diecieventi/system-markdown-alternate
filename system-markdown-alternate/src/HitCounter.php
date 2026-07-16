<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Counts how many times the `.md` endpoint is served, split bot vs human,
 * and nothing else.
 *
 * Privacy by design (count-only durable decision): the option stores ONLY
 * aggregate daily counters — never IP addresses, raw user-agent strings,
 * timestamps finer than the day, or any per-visitor identifier. The user
 * agent is read from the request only to classify bot vs human and is
 * immediately discarded. No external calls, no cookies. This keeps the data
 * anonymous (outside the GDPR scope, no consent needed) and within the
 * wordpress.org "no tracking without consent" guideline.
 *
 * Accepted limits (it is an indicator, not analytics): a page cache/CDN
 * serving `.md` without reaching PHP undercounts, and the read-modify-write
 * on the option may lose an increment under heavy concurrency.
 */
class HitCounter {

	/** Option holding the daily buckets: [ 'YYYY-MM-DD' => [ 'bot' => n, 'human' => n ] ]. */
	const OPTION = 'sysmda_md_hits';

	/** Default retention of the daily buckets, in days. */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Whether a user agent looks like a bot / automated client.
	 *
	 * An empty or missing user agent counts as bot: every browser sends one,
	 * so its absence means an automated client. Otherwise the UA is matched
	 * (case-insensitive substring) against a token list covering generic
	 * crawlers, HTTP libraries/CLIs, headless browsers and AI/LLM agents.
	 *
	 * @param string|null $ua Raw User-Agent header (used only for this check).
	 */
	public static function is_bot( ?string $ua ): bool {
		$ua = trim( (string) $ua );

		if ( '' === $ua ) {
			return true;
		}

		/**
		 * Filter: case-insensitive substrings that classify a user agent as a
		 * bot in the `.md` hit counter (e.g. 'bot', 'curl', 'gpt').
		 *
		 * @param string[] $patterns Default token list.
		 */
		$patterns = apply_filters( 'sysmda_md_hits_bot_patterns', self::default_bot_patterns() );

		foreach ( (array) $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Records one served `.md` response in today's (UTC) daily bucket and
	 * prunes buckets older than the retention window.
	 *
	 * The option is stored with autoload off: it is only read when recording
	 * a hit or rendering the settings page.
	 *
	 * @param string|null $ua Raw User-Agent header, only classified via is_bot(), never stored.
	 */
	public static function record( ?string $ua ): void {
		$key   = self::is_bot( $ua ) ? 'bot' : 'human';
		$today = gmdate( 'Y-m-d' );

		$hits = get_option( self::OPTION, array() );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}

		if ( ! isset( $hits[ $today ] ) || ! is_array( $hits[ $today ] ) ) {
			$hits[ $today ] = array(
				'bot'   => 0,
				'human' => 0,
			);
		}

		$hits[ $today ][ $key ] = ( isset( $hits[ $today ][ $key ] ) ? (int) $hits[ $today ][ $key ] : 0 ) + 1;

		update_option( self::OPTION, self::prune( $hits, $today ), false );
	}

	/**
	 * Removes buckets older than the retention window (and malformed keys).
	 *
	 * Public and free of I/O so the pruning logic can be tested in isolation.
	 *
	 * @param array  $hits  Daily buckets ('YYYY-MM-DD' => counters).
	 * @param string $today Current UTC day ('YYYY-MM-DD').
	 * @return array Pruned buckets.
	 */
	public static function prune( array $hits, string $today ): array {
		/**
		 * Filter: retention of the daily `.md` hit buckets, in days.
		 *
		 * @param int $days Default 90. Values below 1 are clamped to 1.
		 */
		$days = (int) apply_filters( 'sysmda_md_hits_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $days < 1 ) {
			$days = 1;
		}

		$today_ts = strtotime( $today . ' 00:00:00 GMT' );
		if ( false === $today_ts ) {
			return $hits; // Unusable reference date: prune nothing.
		}

		$cutoff_ts = $today_ts - ( $days * DAY_IN_SECONDS );

		foreach ( array_keys( $hits ) as $day ) {
			$ts = is_string( $day ) ? strtotime( $day . ' 00:00:00 GMT' ) : false;
			if ( false === $ts || $ts < $cutoff_ts ) {
				unset( $hits[ $day ] );
			}
		}

		return $hits;
	}

	/**
	 * Aggregate bot/human totals over the last N days (today included).
	 *
	 * Public and free of I/O so it can be tested in isolation.
	 *
	 * @param array  $hits  Daily buckets ('YYYY-MM-DD' => counters).
	 * @param string $today Current UTC day ('YYYY-MM-DD').
	 * @param int    $days  Window size in days (1 = today only).
	 * @return array{bot:int,human:int}
	 */
	public static function totals( array $hits, string $today, int $days ): array {
		$totals = array(
			'bot'   => 0,
			'human' => 0,
		);

		$today_ts = strtotime( $today . ' 00:00:00 GMT' );
		if ( false === $today_ts || $days < 1 ) {
			return $totals;
		}

		// ISO dates compare correctly as strings.
		$from = gmdate( 'Y-m-d', $today_ts - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		foreach ( $hits as $day => $bucket ) {
			if ( ! is_string( $day ) || ! is_array( $bucket ) || $day < $from || $day > $today ) {
				continue;
			}
			$totals['bot']   += isset( $bucket['bot'] ) ? (int) $bucket['bot'] : 0;
			$totals['human'] += isset( $bucket['human'] ) ? (int) $bucket['human'] : 0;
		}

		return $totals;
	}

	/**
	 * Default bot tokens: generic crawler words, HTTP clients/libraries,
	 * headless/automation stacks and known AI/LLM agents. Matched as
	 * case-insensitive substrings of the User-Agent.
	 *
	 * 'http' alone covers most library defaults (Go-http-client, okhttp,
	 * GuzzleHttp, aiohttp) and crawler UAs embedding a "+http(s)://" URL.
	 *
	 * @return string[]
	 */
	private static function default_bot_patterns(): array {
		return array(
			// Generic crawler vocabulary.
			'bot',
			'crawl',
			'spider',
			'slurp',
			'scrapy',
			'ia_archiver',
			'facebookexternalhit',
			// HTTP clients, CLIs and language runtimes.
			'curl',
			'wget',
			'python',
			'java',
			'php',
			'perl',
			'ruby',
			'node',
			'http',
			'axios',
			'fetch',
			'libwww',
			// Headless browsers / automation.
			'headless',
			'phantom',
			'selenium',
			'playwright',
			'puppeteer',
			// AI / LLM agents.
			'gpt',
			'claude',
			'anthropic',
			'openai',
			'perplexity',
			'gemini',
			'mistral',
			'cohere',
		);
	}
}

<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Counts how many times the `.md` endpoint is served, split bot vs human,
 * plus an optional per-known-bot-name breakdown of that bot total, and
 * nothing else.
 *
 * Privacy by design (count-only durable decision): the option stores ONLY
 * aggregate daily counters — never IP addresses, raw user-agent strings,
 * timestamps finer than the day, or any per-visitor identifier. The user
 * agent is read from the request only to classify bot vs human (and, for a
 * short curated list of documented crawlers, to name which one) and is
 * immediately discarded. No external calls, no cookies. This keeps the data
 * anonymous (outside the GDPR scope, no consent needed) and within the
 * wordpress.org "no tracking without consent" guideline. The named breakdown
 * stays inside the same boundary on purpose: a fixed, code-defined list of
 * canonical names, never a bucket keyed on request-derived text.
 *
 * Accepted limits (it is an indicator, not analytics): a page cache/CDN
 * serving `.md` without reaching PHP undercounts, and the read-modify-write
 * on the option may lose an increment under heavy concurrency.
 */
class HitCounter {

	/**
	 * Option holding the daily buckets:
	 * [ 'YYYY-MM-DD' => [ 'bot' => n, 'human' => n, 'named' => [ name => n ] ] ].
	 * 'named' is present only on a day that had at least one named match, and
	 * absent entirely on any bucket written before this breakdown existed.
	 */
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
	 * @param string|null $ua Raw User-Agent header, only classified via is_bot()/named_bot(), never stored.
	 */
	public static function record( ?string $ua ): void {
		$is_bot = self::is_bot( $ua );
		$key    = $is_bot ? 'bot' : 'human';
		// A refinement of the bot bucket only: a UA that a site's own
		// sysmda_md_hits_bot_patterns filter has decided is NOT a bot never
		// reaches the named breakdown either, whatever it matches here.
		$named = $is_bot ? self::named_bot( $ua ) : null;
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

		if ( null !== $named ) {
			if ( ! isset( $hits[ $today ]['named'] ) || ! is_array( $hits[ $today ]['named'] ) ) {
				$hits[ $today ]['named'] = array();
			}

			$hits[ $today ]['named'][ $named ] = ( isset( $hits[ $today ]['named'][ $named ] ) ? (int) $hits[ $today ]['named'][ $named ] : 0 ) + 1;
		}

		update_option( self::OPTION, self::prune( $hits, $today ), false );
	}

	/**
	 * The canonical name of a known AI crawler/agent, or null.
	 *
	 * One level more specific than is_bot(): a small, curated map of
	 * documented product identifiers (never a guess at an undocumented UA
	 * token), checked only for a request already classified as a bot. Kept
	 * deliberately short — this names a few crawlers among the bot total, it
	 * does not attempt to name all of it.
	 *
	 * @param string|null $ua Raw User-Agent header (used only for this check).
	 */
	public static function named_bot( ?string $ua ): ?string {
		$ua = trim( (string) $ua );

		if ( '' === $ua ) {
			return null;
		}

		/**
		 * Filter: named-bot => list of case-insensitive User-Agent substrings,
		 * for the `.md` hit counter's per-bot breakdown.
		 *
		 * Independent of sysmda_md_hits_bot_patterns (which only decides
		 * bot vs human): this one names a match already counted as a bot, it
		 * does not itself decide whether a request is a bot.
		 *
		 * @param string[][] $patterns Default map (canonical name => substrings).
		 */
		$map = apply_filters( 'sysmda_md_hits_named_bot_patterns', self::default_named_bot_patterns() );

		foreach ( (array) $map as $name => $patterns ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}

			foreach ( (array) $patterns as $pattern ) {
				$pattern = (string) $pattern;
				if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
					return $name;
				}
			}
		}

		return null;
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
	 * Aggregate per-named-bot totals over the last N days (today included).
	 *
	 * A bucket predating this breakdown (or a day with no named match) simply
	 * has no 'named' key, which sums as zero here rather than as a warning:
	 * the shape is additive, not a migration.
	 *
	 * Public and free of I/O so it can be tested in isolation.
	 *
	 * @param array  $hits  Daily buckets ('YYYY-MM-DD' => counters).
	 * @param string $today Current UTC day ('YYYY-MM-DD').
	 * @param int    $days  Window size in days (1 = today only).
	 * @return array<string,int> Canonical bot name => count. Only names seen at least once appear.
	 */
	public static function named_totals( array $hits, string $today, int $days ): array {
		$totals = array();

		$today_ts = strtotime( $today . ' 00:00:00 GMT' );
		if ( false === $today_ts || $days < 1 ) {
			return $totals;
		}

		$from = gmdate( 'Y-m-d', $today_ts - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		foreach ( $hits as $day => $bucket ) {
			if ( ! is_string( $day ) || ! is_array( $bucket ) || $day < $from || $day > $today ) {
				continue;
			}

			if ( empty( $bucket['named'] ) || ! is_array( $bucket['named'] ) ) {
				continue;
			}

			foreach ( $bucket['named'] as $name => $n ) {
				$name = (string) $name;
				if ( '' === $name ) {
					continue;
				}
				$totals[ $name ] = ( isset( $totals[ $name ] ) ? $totals[ $name ] : 0 ) + (int) $n;
			}
		}

		return $totals;
	}

	/**
	 * Default named-bot map: a short, curated list of AI/LLM crawlers and
	 * agents with documented, stable User-Agent identifiers — never a guess
	 * at an undocumented token. Deliberately smaller than the generic
	 * is_bot() vocabulary: this names a few crawlers within the bot total,
	 * it does not attempt to name all of it.
	 *
	 * @return array<string,string[]> Canonical name => case-insensitive substrings.
	 */
	private static function default_named_bot_patterns(): array {
		return array(
			'ClaudeBot'     => array( 'claudebot', 'claude-user', 'claude-searchbot' ),
			'GPTBot'        => array( 'gptbot', 'chatgpt-user', 'oai-searchbot' ),
			'PerplexityBot' => array( 'perplexitybot', 'perplexity-user' ),
			'CCBot'         => array( 'ccbot' ),
		);
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

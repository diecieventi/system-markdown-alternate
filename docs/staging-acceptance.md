# Real-WordPress staging acceptance

This is the compact release check for behavior that the pure PHP harness cannot
prove: real WordPress routing and hook order, emitted HTTP headers, browser-side
actions and interaction with the active staging stack. It complements
`tests/run-tests.php`; it does not replace that suite or CI.

Use the connected `instawp_sma` site and its safe plugin-update workflow. Never
leave update packages, rollback archives, credentials or diagnostic output on
the server.

## Before changing the site

1. Confirm the staging URL, WordPress/PHP versions, active plugin version and
   active integrations.
2. Check the homepage, one canonical post, its `.md` URL and `/llms.txt`.
3. Create a rollback archive outside the plugin directory and record its size
   and SHA-256.
4. Verify the local distributable's size and SHA-256 before transfer.

## Acceptance matrix

- Install the exact distributable, confirm the expected version and verify that
  the plugin remains active.
- Canonical HTML stays HTML; explicit Markdown negotiation returns Markdown;
  an unacceptable `Accept` value returns `406`; feeds and embeds never
  negotiate or advertise a Markdown alternate.
- Dedicated `.md` returns `text/markdown`, the canonical link, validators and
  `public, max-age=0, must-revalidate`. Negotiated Markdown remains private and
  non-storable. An exact `If-None-Match` request returns `304` when the host
  forwards the validator.
- Password-protected and non-standard-format posts remain unavailable as
  Markdown.
- Excluded shortcodes are absent from prose but survive literally inside inline
  and fenced code examples.
- Embed blocks leave a usable address: a video embed becomes a link to it,
  a captioned one keeps its caption as the following paragraph, and an embed
  showing text of its own (a quoted post) keeps that text as well as its link.
  Worth doing here specifically: whether the player is already resolved when
  the pipeline sees it varies per provider and per caching setup, and only one
  of the two shapes can be reproduced offline.
- `/llms.txt` is healthy and excludes ineligible content.
- Render `[sysmda_md_actions]` through the real `wp_footer` both before and
  after WordPress's footer-script printer (representative priorities 10 and 25).
  In both cases markup, localization and exactly one script must be emitted.
- In a real browser, verify actions are visible, the menu opens, Escape closes
  it, copy reports success and the console has no plugin JavaScript errors.
- Search WordPress debug and PHP error logs for new plugin warnings or fatals.

## Cleanup and record

Always delete the transferred package and rollback archive when testing
finishes. After a failed run, keep them only until rollback and any required
diagnostics are complete, then remove them as well.
Record only the release version, date, platform versions and pass/fail outcome
in this file; keep transient URLs, hashes and verbose diagnostics out of the
repository.

## Latest full pass

- **2026-08-10 — System Markdown Alternate 0.41.1**
- WordPress 7.0.3, PHP 8.4.20
- HTTP, conditional request, eligibility, shortcode-code protection, real
  footer-hook timing, browser interactions and error-log checks: **passed**
- Transferred update and rollback artifacts: **removed**

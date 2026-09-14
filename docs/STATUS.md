# Status — what is open, and what unblocks it

**This file is the backlog, and it is the only place a status lives.** Plans,
reviews and `AGENTS.md` carry the reasoning; the state of the work is here.
A plan that disagrees with this file is stale — fix the plan, not this table.

**Only actionable work is listed.** Anything that has been decided against —
Elementor, `/llms.txt`, the homepage `.md`, escaping the `# Title`, a front-end
button, a crawler log — is *not* in this file, and must not be added back to it:
durable decisions live in `AGENTS.md` under *Product decisions*, and closed
measurements in [`evaluations.md`](evaluations.md). Read those before proposing
anything; a backlog that lists what nobody intends to do is worse than no
backlog, because it has to be re-read and re-dismissed every time.

> **A "what's outstanding" review is not complete without the private companion
> repository.** Some plans live only in
> [`diecieventi/system-markdown-alternate-dev`](https://github.com/diecieventi/system-markdown-alternate-dev)
> under `private-plans/`, whose own `README.md` is the private half of this
> index. They are deliberately not named here (see "This repository is public"
> in `AGENTS.md`): a pointer that listed them would defeat the point of keeping
> them out of the public repository. List that folder — do not infer its
> contents from this file.

Released: **0.53.0**, live on wordpress.org since 13 September 2026, and
accepted on both staging sites on 14 September. That run found one defect, and
the listing still carries the pre-`0.53.0` screenshots.

## Open work

| # | Item | State | What unblocks it | Detail |
|---|---|---|---|---|
| 1 | **Stale `304` on the first request after an upgrade** | Open, reproduced on both staging sites | Nothing — the cause is identified in the code | below |
| 2 | **The wordpress.org listing still shows the pre-`0.53.0` screenshots** | Retaken in the repository, not published | The next tagged release being deployed — item 1's fix is the natural carrier | below |
| 3 | **Italian translation** on translate.wordpress.org | Ready, half done | Nothing — 51/116 strings are approved, 65 remain; a language pack is built at 90% | below |
| 4 | **R3** — synced-pattern instance overrides | Parked, measured but inconclusive | **One SQL query** on the production reference site | [review-followup-plan.md](review-followup-plan.md) |
| 5 | **Exclusion scanner** | Parked, designed, not started | A real content corpus to point it at | [exclusion-scanner-plan.md](exclusion-scanner-plan.md) |

**1. Stale `304` after an upgrade.** The `0.53.0` acceptance run upgraded both
staging sites from `0.51.0` and sent the pre-upgrade `Last-Modified` back as
`If-Modified-Since`: the **first** request after the upgrade was answered `304`
with no body, the second `200`. `AdminSettings::maybe_bump_for_plugin_version()`
only marks the salt bump, which `flush_cache_salt()` writes at `shutdown`,
while `MarkdownController::date_is_strong_validator()` reads the stored salt —
so the request that detects the upgrade, and any request running before it
finishes, still trusts the date. Bounded (the affected client revalidates on
its next request, `max-age=0`), but it contradicts the `0.51.0` decision that
an upgrade stops the date path. The fix belongs in the validator (refuse the
date while the stored `sysmda_version` differs from `SYSMDA_VERSION`), not in
moving the deferred write, and it owes a test seen to fail first.

**2. Screenshots on the listing.** PR #147 retook `screenshot-1` …
`screenshot-4` and closed the backlog row, but it merged on 14 September, the
morning **after** `0.53.0` was deployed — and the deploy workflow checks out the
release tag and stages `.wordpress-org/` from it. So the listing still serves
the shots with the removed `llms.txt` tab and aside (checked on
`ps.w.org`: byte sizes identical to the tag's files, not to `main`'s), and the
readme's caption for `screenshot-2` is the pre-#147 one too. Re-running the
deploy for `v0.53.0` would republish exactly those files. What closes this is
the next tagged release reaching wordpress.org, then a look at the listing —
not at the repository. Publishing the assets on their own, without a release,
would need a manual SVN commit or a new asset-only workflow; neither exists.

**3. Italian translation.** The plugin is live on wordpress.org, so the old
blocker is gone. On translate.wordpress.org the `dev` project holds 116 strings:
51 translated and approved, 65 untranslated, none waiting for review, with a
locale editor already approving (`piermario`). A language pack is generated at
90%, so ~105 of the 116 have to land. No translation files belong in this
repository — see the i18n note in `AGENTS.md`.

**4. R3 — pattern overrides.** `BlockCleaner` drops a `core/block` instance's
own `content` attribute, so the plugin publishes a synced pattern's default text
where the page shows the per-instance override. Real, and the most invasive fix
of the `0.50.0` review. Three connected installs scanned clean — but none of
them holds a single synced pattern, so the denominator is zero and the result
carries no information. The SQL is in the plan; run it on the production
reference site before spending anything.

**5. Exclusion scanner.** An admin page inventorying the shortcode tags and
block names actually present in the servable corpus, so the three exclusion
lists can be filled from evidence. The *damage* half shipped in `0.40.0` (lists
accumulate, code samples are safe); *discovery* is what remains, and it waits on
a corpus worth scanning.

## Closed — do not redo

Measurements and evaluations that answered a question for good live in
[`evaluations.md`](evaluations.md): the caching and `304` host measurement, the
`acceptmarkdown.com` guides, the block-native Markdown engine, server-side
diagnostics, the homepage `.md`, escaping the `# Title`, and the ideas surfaced
by reading comparable plugins. Read that file before proposing any of them
again.

**`/llms.txt` is gone**, generated by this plugin from `0.2.0` until `0.53.0`
removed it. It is not an open item, a parked one or a candidate for a later
release: the reasoning is a durable decision in `AGENTS.md`, and a hypothetical
future for llms.txt is not a reason to reopen it.

Durable product decisions — the ones that must not be reopened at all — stay in
`AGENTS.md` under *Product decisions*.

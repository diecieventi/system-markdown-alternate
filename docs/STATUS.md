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

Released: **0.53.1**, live on wordpress.org since 14 September 2026 and
verified the same day: the first request after an upgrade on staging, and the
refreshed screenshots on the listing. The defect `0.53.0`'s acceptance run found
is fixed; the items below remain, R3 among them — a real content-accuracy
defect waiting on one measurement.

## Open work

| # | Item | State | What unblocks it | Detail |
|---|---|---|---|---|
| 1 | **Italian translation** on translate.wordpress.org | Ready, half done | Nothing — 51/116 strings are approved, 65 remain; a language pack is built at 90% | below |
| 2 | **R3** — synced-pattern instance overrides | Parked, measured but inconclusive | **One SQL query** on the production reference site | [review-followup-plan.md](review-followup-plan.md) |
| 3 | **Exclusion scanner** | Parked, designed, not started | A real content corpus to point it at | [exclusion-scanner-plan.md](exclusion-scanner-plan.md) |
| 4 | **Shared slugs on Polylang Pro** — does the `.md` route follow the language when translations share a slug | Unmeasured | A Polylang Pro (or WPML) install; neither staging site has one | [evaluations.md](evaluations.md) |

**1. Italian translation.** The plugin is live on wordpress.org, so the old
blocker is gone. On translate.wordpress.org the `dev` project holds 116 strings:
51 translated and approved, 65 untranslated, none waiting for review, with a
locale editor already approving (`piermario`). A language pack is generated at
90%, so ~105 of the 116 have to land. No translation files belong in this
repository — see the i18n note in `AGENTS.md`.

**2. R3 — pattern overrides.** `BlockCleaner` drops a `core/block` instance's
own `content` attribute, so the plugin publishes a synced pattern's default text
where the page shows the per-instance override. Real, and the most invasive fix
of the `0.50.0` review. Three connected installs scanned clean — but none of
them holds a single synced pattern, so the denominator is zero and the result
carries no information. The SQL is in the plan; run it on the production
reference site before spending anything.

**3. Exclusion scanner.** An admin page inventorying the shortcode tags and
block names actually present in the servable corpus, so the three exclusion
lists can be filled from evidence. The *damage* half shipped in `0.40.0` (lists
accumulate, code samples are safe); *discovery* is what remains, and it waits on
a corpus worth scanning.

**4. Shared slugs on Polylang Pro.** The `.md` suffix route resolves the post
through `url_to_postid()`, which knows nothing about languages. On Polylang
**free** that was measured and is not a defect — the free version refuses
shared slugs, and a slug forced into the database is mis-resolved by WordPress
itself before this plugin is involved ([evaluations.md](evaluations.md)). That
says nothing about **Pro**, whose shared-slug feature routes the HTML by
language: whether the `.md` follows it, or serves the other translation, is
unknown. What unblocks it is a Pro install with two translations sharing a
slug, and the same probe — `.md` suffix, `?format=markdown` and
`Accept: text/markdown` on both language URLs, compared with the HTML. WPML
raises the same question.

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

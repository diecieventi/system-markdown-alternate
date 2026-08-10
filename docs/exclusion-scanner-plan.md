# Exclusion scanner — discovering what the `.md` is actually publishing

> Implementation plan. Status: **not started**, greenlit by the D1 measurement of
> 10 August 2026 (§2). Written against `main @ 0.38.2`.
>
> This plan does not reopen the design: the shape below was fixed while the idea
> was parked, and every constraint in §3 is blocking. If the work is picked up,
> start from §5 — do not redesign from §1.

## 1. The problem

The three exclusion lists ship with small, static defaults: five shortcodes,
five block names, three CSS classes (`ShortcodeCleaner::excluded_shortcodes()`,
`BlockCleaner::excluded_block_names()`, `ContentRenderer::EXCLUDED_CLASSES`).
They cover the form and table-of-contents plugins that were known when they were
written, and nothing else. A site owner has no way to find out what *their*
corpus contains — the settings page offers three empty textareas and no answer
to the only question that matters when filling them in: **what is in my content,
and how much of it is site chrome rather than article?**

That was a cosmetic gap until `0.38.1`. Before it, `render_block()` never
expanded shortcodes and the pipeline skips `the_content` by design, so a
registered shortcode sitting in block content reached the converter as literal
text and was published as an escaped `\[tag\]` — ugly, easy to miss, and inert.
`0.38.1` closed that hole for the right reasons, and in doing so **changed what
an incomplete exclusion list costs**: every shortcode that was silently dormant
in block content now renders in full into every `.md` that contains it, across
the whole archive, from the moment the plugin is updated.

The exclusion mechanism is fine. What is missing is knowing what to put in it.

## 2. What D1 established, and what it did not

Measured on the InstaWP staging site (`sma.instawp.co`), 10 August 2026, after
bringing it from `0.38.0` to `0.38.2`.

A shortcode registered by an mu-plugin (`[wdq_newsletter_form list="blog"]`,
shaped like a typical newsletter-form plugin) was written inline in a paragraph
of a block post. In the `.md` body it now expands in full — field label, submit
button, and the entire GDPR consent paragraph — dropped into the middle of the
prose between two article paragraphs. That is exactly the failure the parked
note predicted, reproduced end to end.

Everything else behaved correctly, which is what makes the result trustworthy
rather than alarming:

- the same tag inside an inline `<code>` span stayed literal;
- the same tag inside a fenced code block stayed literal (the `<pre>`/`<code>`
  masking in `expand_shortcodes()` does its job);
- the front-matter `description` was unaffected — it uses `strip_shortcodes()`,
  not `do_shortcode()`, so the tag is dropped rather than expanded.

**What D1 did not establish**: that any production corpus actually contains such
a shortcode. It proves the mechanism produces the feared output, not that the
input exists. That distinction is the strongest argument for building the
scanner rather than against it — the scanner is the instrument that answers the
remaining question, and there is no cheaper one. Reading 812 posts by hand is
not a plan.

## 3. Constraints (all blocking)

Collected while the idea was parked. Each one is a decision already taken; none
is a preference.

1. **Read `post_content`; never instrument the exclusion filters.**
   `docs/filters.md` guarantees the *shape of the callback*, not the call sites:
   they are documented as illustrative, span four classes and two endpoints, and
   "have been wrong every time they were written down as a count". Callbacks must
   be pure, cheap, stateless, and must not count their invocations. A scanner
   that learned by observing the filters would be measuring the pipeline's
   internals, which are explicitly free to change.
2. **A separate admin page.** The settings panel is a single `options.php` form
   (`AdminSettings::render_page()` wraps the registered sections in tabs but
   keeps every field in that one form). A results table with per-row checkboxes
   and actions is a second form and cannot live inside it. Already stated in
   `docs/strategy-review-2026-07.md`.
3. **Static scan, never rendering.** Since `0.36.0` the `.md` is by definition
   the *anonymous* representation. A scan launched from wp-admin runs
   authenticated, so anything it rendered would be a logged-in rendering — the
   one thing the plugin now goes out of its way not to cache or serve. The
   static layer has no such problem, and this is precisely what makes running
   the scan from the admin legitimate rather than a compromise.
4. **Follow synced patterns transitively.** Content hidden inside a `core/block`
   lives in another post; `BlockCleaner::expand_reusable()` and
   `MetadataBuilder::collect_pattern_refs()` both already walk them recursively
   with a `$seen` cycle guard. A scanner that stops at the article is blind
   exactly where the plugin is not.
5. **Applying writes "current effective list + the new tag".** Confirmed against
   `AdminSettings::option_to_list()`: an empty option yields the hardcoded
   defaults, a non-empty one **replaces them entirely**. Writing only the newly
   ticked tag would silently drop `contact-form-7`, `gravityform`, `wpforms`,
   `mailerlite_form` and `lwptoc`. `ShortcodeCleaner::ALWAYS_EXCLUDED` is merged
   *after* the filter and must never be written into the option.
6. **The signal is frequency over source content, not inspection of output.**
   `strip_tags` is on, so no raw tag survives into the Markdown, and
   `url_to_postid() === 0` does not prove a link is broken — both were rejected
   as brittle in the strategy review. Something appearing on 812 of 820 posts is
   site chrome; something appearing on 2 is probably deliberate. The count is the
   signal; the preview (§4.5) is what turns it into a decision.
7. **Aggregates only.** Counts per tag plus a handful of example post IDs. Never
   store content, never call anything external — the same rule the hit counter
   already follows.

## 4. Target design

### 4.1 Scope of the MVP

Two inventories: **shortcode tags** and **block names** — the two lists whose
contents are discoverable and whose defaults are demonstrably incomplete.

**CSS class discovery is out of scope**, deliberately. Every theme class in the
corpus would appear in it, drowning the signal, and the three excluded classes
are not something a site *has* — they are a convention the owner applies on
purpose. What is in scope is the cheap inverse: report how many scanned posts
match each **currently configured** excluded class, which answers "is this rule
of mine doing anything at all?" without enumerating anything.

### 4.2 What is scanned

Published posts of the **enabled** post types, filtered through
`PostSupport::is_servable()` so the inventory describes what actually reaches a
`.md`. Same shape as the `/llms.txt` listing query, including
`update_post_term_cache => true`, so post formats are primed in one query rather
than one per post.

`wp_block` posts are not scanned as entries of their own: they have no `.md`.
Their content is reached only by following a reference from a scanned post.

### 4.3 What is recorded, per tag

| Field | Meaning |
|---|---|
| `tag` | Shortcode tag or block name |
| `posts` | Number of scanned posts containing it (deduplicated within a post) |
| `registered` | Observed in wp-admin at scan time — see the caveat in §8 |
| `dynamic` | Blocks only: the block type has a `render_callback` |
| `excluded` | Already covered by the current effective list |
| `via_pattern` | Found only inside a referenced synced pattern |
| `examples` | Up to three post IDs, for the preview |

A tag found inside a referenced pattern is attributed to the **referencing
post** — that is where it surfaces in the `.md` — following references
transitively with a per-post `$seen` cycle guard, exactly as
`collect_pattern_refs()` does. Extract a shared walker only if it is a clean
fit; two short correct walks beat one forced abstraction.

### 4.4 The classification that carries the value

Crossing `registered` with `excluded` produces three states, and they are three
genuinely different problems:

- **registered, not excluded** → *expands in full into the `.md`*. The `0.38.1`
  case, and the one D1 reproduced. Highest priority.
- **not registered, not excluded** → *published as literal `[tag]`*. The
  `sysmda_md_button` case that `ALWAYS_EXCLUDED` exists for. Cosmetic, but it is
  noise in a machine-readable document.
- **excluded** → already covered. Shown with its count anyway, so a rule
  matching zero posts is visible as dead weight.

### 4.5 Preview — "what would ticking this remove?"

`ContentRenderer::strip_excluded_content()` (public since `0.38.2`) applies the
block-level and class-level rules to raw content with no rendering at all;
`ShortcodeCleaner::strip()` covers the shortcode list. For a candidate tag, run
the relevant one over the recorded example posts with the candidate list
injected through its filter by a temporary closure, added and removed inside the
same call, then diff the plain-text extraction before and after and show what
disappeared.

Adding a filter to *configure* a preview run is ordinary usage and does not
violate constraint 1: the closure returns a constant list and is pure. What
constraint 1 forbids is instrumenting those filters to observe or count the
pipeline. Do not let a later reader mistake one for the other.

The preview is **not optional**, and `0.38.2` is why: exclusions now reach the
front-matter `description` and enriched `/llms.txt` entries, not just the body,
so a wrong tick no longer dirties one place. A number cannot justify a decision
with that reach; seeing the removed text can.

### 4.6 Inform, do not apply

The scanner never changes the exclusion lists on its own. Applying is an
explicit, per-row, confirmed action, and there is deliberately **no bulk
"exclude everything above N" control** — that is the same line already held by
"never auto-detect which taxonomies to emit": the plugin informs, the owner
decides.

### 4.7 Storage

| Option | Autoload | Role |
|---|---|---|
| `sysmda_content_scan` | off | Latest scan aggregate: totals, per-tag rows, timestamp, scanned-post count |

**It must be added to the exclusion list in
`AdminSettings::maybe_bump_cache_salt()`, alongside `HitCounter::OPTION`.**
That method bumps the salt for *any* `sysmda_*` option; without the exclusion,
every scan would invalidate the entire body cache and change every `ETag` on the
site. This is the single easiest thing in the plan to get wrong.

The exclusion options themselves must stay *out* of that list, so applying a
suggestion keeps invalidating the cache as it does today.

### 4.8 Execution

Batched over AJAX (nonce + `manage_options`), default 50 posts per request, with
progress and resumption; the partial aggregate accumulates in the option.
`parse_blocks()` runs only when `has_blocks()` is true — shortcode discovery is
a regex over the raw string and needs no parse.

### 4.9 The page

Registered with `add_submenu_page()` under `options-general.php`, then removed
from the menu with `remove_submenu_page()`, and reached from a button in the
**Markdown output** tab, next to the three exclusion fields. One menu entry, a
real capability-checked URL, and the entry point sits exactly where the owner is
already asking themselves what to type into those boxes.

### 4.10 No new filters

None in the MVP. Per `AGENTS.md`, a filter is added when something is anchored
to a setting or a domain concept, not because a value could be configurable; a
batch size is anchored to the implementation and would have to be marked
Advanced on day one. If a real need appears later, it can be added then.

## 5. Work breakdown

Single PR, atomic commits, on a dedicated branch.

1. **`src/ContentScanner.php`** (new) — the walk and the aggregation. The
   tag-extraction and merge logic must be pure and callable without WordPress,
   so it is testable in `tests/run-tests.php`.
2. **`src/ScanPage.php`** (new) — page registration, AJAX handlers (scan batch,
   preview, apply), rendering. Small and single-responsibility, per the code
   conventions; do not grow `AdminSettings` for this.
3. **`src/AdminSettings.php`** — add `sysmda_content_scan` to the salt-bump
   exclusion (§4.7); add the button in the Markdown output section; call
   `remove_submenu_page()`.
4. **`uninstall.php`** — add `sysmda_content_scan`.
5. **Assets** — reuse `assets/admin-settings.{css,js}` if it fits; a separate
   small file otherwise. No framework, no dependency, usable without JS for the
   read-only parts.
6. **Tests** — §6.
7. **Docs** — `AGENTS.md` (*Current state* bullet; a *Product decisions* entry
   for "informs, never applies" and for the aggregates-only storage), `README.md`
   and `readme.txt` (feature bullet, changelog, one FAQ entry). No
   `docs/filters.md` change: no new filters.
8. **Release** — `0.39.0` (new feature): bump the plugin header and
   `SYSMDA_VERSION`, update `Stable tag` and the changelog in both `readme.txt`
   and `CHANGELOG.md`, drop the now-fourth-oldest entry from `readme.txt`.

## 6. Tests

Pure-logic, in `tests/run-tests.php`, no WordPress and no PHPUnit.

1. **Shortcode extraction**: tags found in a paragraph, in a Custom HTML block,
   in the core Shortcode block; a tag appearing three times in one post counts
   as one post; `[[escaped]]` is not a tag.
2. **Code regions**: a tag inside `<pre>`/`<code>` is reported separately or not
   at all — pick one and assert it. It never expands in the `.md`, so counting it
   as a live occurrence would produce a false positive on exactly the content
   that is safe.
3. **Block extraction**: nested blocks, and a block name found only in
   `innerBlocks`.
4. **Synced patterns**: a tag living only inside a referenced pattern is
   attributed to the referencing post and flagged `via_pattern`; a two-level
   chain is followed; a reference cycle terminates.
5. **Merge across batches**: two partial aggregates combine to the same result
   as one pass over the union.
6. **Apply semantics** (the §3.5 trap): applying a tag to an *empty* option
   writes the hardcoded defaults plus the new tag, not the tag alone; applying
   to a non-empty option appends without duplicating; a member of
   `ALWAYS_EXCLUDED` is never written.
7. **Classification truth table**: registered/unregistered × excluded/not.

Then `php -l` on the touched files, the full test run, and
`composer --working-dir=system-markdown-alternate phpcs` with zero errors.

## 7. Manual acceptance (staging)

The fixtures already exist: `sysmda-test-newsletter-shortcode` (post 65) and the
mu-plugin registering `[wdq_newsletter_form]`, both created for D1.

1. Scan → `wdq_newsletter_form` appears as **registered, not excluded**, with
   post 65 among the examples.
2. Preview on that row shows the label, button and GDPR paragraph as the text
   that would be removed.
3. Apply → `sysmda_excluded_shortcodes` contains the five defaults **plus**
   `wdq_newsletter_form`; the `.md` of post 65 loses the form and keeps both
   code samples; the `ETag` changes once.
4. Deactivate the mu-plugin and rescan → the tag is now **not registered**, and
   the `.md` would show it literally if it were not already excluded.
5. Put the same shortcode inside a synced pattern referenced by an article →
   attributed to the article, flagged `via_pattern`.
6. Run two scans back to back with an unrelated post open in another tab →
   confirm no cache invalidation between them (`ETag` unchanged), which is the
   §4.7 exclusion working.

## 8. Risks

- **The salt-bump trap** (§4.7). Mitigated by the exclusion and by acceptance
  test 6, which exists specifically to catch it.
- **`registered` is an observation, not a fact.** A shortcode registered
  conditionally — front end only, or on a hook wp-admin never reaches — reads as
  unregistered during a scan. The UI must label the column as *observed in
  wp-admin* rather than assert registration, and the three-state classification
  must degrade gracefully when it is wrong: the count and the preview are both
  still correct, only the explanatory label is off.
- **False confidence in a low count.** A tag on two posts may still be chrome,
  and one on 800 may be deliberate. The preview is the decision, the number is
  only the ordering.
- **Scan cost on large corpora.** Bounded by batching and by scoping the query
  to servable posts. It is admin-initiated and never scheduled.
- **Scope creep back toward diagnostics.** Size and token estimates, link
  checking, `.md` previews of whole posts and servability dashboards were all
  removed from the active plan in July 2026 and stay out. This page inventories
  tags so the exclusion lists can be filled in — nothing more.

## 9. Explicitly out

- CSS class discovery (§4.1); match counts for configured classes are in.
- Anything that renders: no `render_block()`, no `do_shortcode()`, no
  `the_content`, no loopback HTTP.
- Automatic or scheduled scanning, and any automatic change to the exclusion
  lists.
- Storing content, per-post detail beyond the example IDs, or anything a
  visitor could be identified by.

# F3.2 — Explicit taxonomy selection for the front matter

> Implementation plan. Status: **implemented in `0.25.0`** — kept as the record
> of why auto-detection was removed (the *Product decisions* entry in `AGENTS.md`
> is the binding summary; retire this file once it stops being useful).
> Supersedes the auto-detection introduced with F3.1 in `0.24.0`.
>
> Deviations from the plan as written: the emission path reads no options
> directly — the selection reaches it through `sysmda_front_matter_taxonomy_slugs`
> at priority 5 — so the `sysmda_front_matter_taxonomies` gate needed no
> option-backed closure at all; and `eligible_slugs()` shipped as
> `candidate_taxonomies()` + the pure `filter_candidates()`, in
> `MetadataBuilder`, since the migration needs it outside the panel.

## 1. The problem

`MetadataBuilder::taxonomy_terms()` builds its default list by auto-detecting
the post type's taxonomies:

```php
foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $slug => $taxonomy ) {
    if ( ! empty( $taxonomy->public ) ) {
        $slugs[] = (string) $slug;
    }
}
```

Two defects follow from that single `public` check:

1. **A taxonomy registered `public => true, publicly_queryable => false` is
   emitted.** That combination — "Public" ticked, *"Publicly queryable"*
   unticked, which is how CPT UI and hand-rolled `register_taxonomy()` calls
   express *editorial-internal classification with no archive URL* — is exactly
   the case that must stay out of the `.md` output. This is the reported bug.
2. **Any taxonomy the site registers later is published automatically**, with no
   action by the site owner. Every new plugin (WooCommerce, a multilingual
   plugin, a page builder) can silently add keys to the front matter of every
   post. That is the part the owner objects to on principle, and it is not fixed
   by tightening the predicate.

## 2. How WordPress actually exposes this (the "how do you detect it?" part)

`get_taxonomy( $slug )` / `get_object_taxonomies( $type, 'objects' )` return
`WP_Taxonomy` objects. The relevant properties (all available on WP ≥ 4.7, well
under the plugin's 6.1 floor):

| Property | Default | Meaning |
|---|---|---|
| `public` | `true` | Intended for public use — front end **or** admin UI. |
| `publicly_queryable` | `= public` | Term archives / `?taxonomy=term` queries work. |
| `show_ui` | `= public` | An editing UI is shown in wp-admin. |
| `show_in_nav_menus` | `= public` | Selectable in menus. |
| `show_in_rest` | `false` | Exposed in the REST API / block editor. |
| `_builtin` | `false` | Core taxonomy (`category`, `post_tag`, `post_format`, …). |

So a strict predicate is available and cheap:

```php
! empty( $tax->public ) && ! empty( $tax->publicly_queryable )
```

But it is **not a reliable statement of intent**, in either direction:

- `publicly_queryable => false` does **not** mean "secret": the terms may still
  be printed on the page by the theme; only the archive URL is missing.
- `public => true` does **not** mean "useful to a machine reader". Plumbing
  taxonomies attached to public post types are common — WooCommerce's
  `product_type` / `product_visibility` / `product_shipping_class`, multilingual
  plugins' translation-group taxonomies (verify the exact args on staging before
  quoting them anywhere). Some are already excluded by the `public` check today;
  the point is that the registry cannot be trusted to answer "should this be
  published?".

Conclusion: **detection can inform a UI label, it cannot be the policy.**

## 3. The two options, and the recommendation

### Option A — keep auto-detection, tighten the predicate

Add `&& ! empty( $taxonomy->publicly_queryable )`.

- **+** One line, no new option, no migration, no UI work.
- **−** Fixes the symptom, not the objection: the plugin still decides on its
  own, and still starts publishing whatever the next plugin registers.
- **−** No way to exclude a *public* taxonomy that is simply noise, other than
  writing filter code.
- **−** `publicly_queryable` is a weak proxy for intent (see §2).

### Option B — explicit selection in the settings, like "Enabled content types" ✅ recommended

A checkbox list of the eligible taxonomies. **Empty = nothing emitted.** Nothing
is ever published unless the owner ticked it (or wrote a filter).

- **+** Answers the actual objection: no automatic publication, ever, and new
  taxonomies arrive unticked.
- **+** Mirrors the plugin's own established pattern and durable decision —
  `sysmda_markdown_supported_post_types` defaults to empty, "inactive until the
  user picks something". Same mental model for the user, no new concept.
- **+** Fixes the reported bug *by construction*: an internal taxonomy is off
  because everything is off by default, not because a heuristic guessed right.
- **+** Gives the owner per-taxonomy control both ways (drop a noisy public one,
  deliberately include an internal one).
- **−** One new option, a settings field, a small migration, more tests/docs.

**Recommendation: Option B**, with the strict predicate of Option A retained in
two non-authoritative roles — the badge next to each row in the panel, and the
seed value for the one-time migration. Detection informs, the user decides.

Rejected variants, for the record:

- *Strict auto-detection + an exclusion list*: still publishes by default; an
  exclusion list can only ever be reactive.
- *A second "include internal taxonomies too" checkbox*: two toggles expressing
  one decision, and it re-creates automatic publication inside its own branch.

## 4. Target design

### Options

| Option | Type | Default | Role |
|---|---|---|---|
| `sysmda_front_matter_taxonomy_slugs` | `array` of slugs | `array()` (never saved = empty) | The taxonomies to emit. Deliberately named after the filter it feeds. |
| `sysmda_front_matter_taxonomies` | `string` `'1'`/`'0'` | — | **Legacy** (0.24.x checkbox). Migrated, then deleted. |

### Filter contract (unchanged names, restated defaults)

```php
// Kill switch. Default is now "is anything selected?" instead of the checkbox.
apply_filters( 'sysmda_front_matter_taxonomies', ! empty( $selected ) );

// Default value is now the SELECTED list, not an auto-detected one.
// May still narrow and extend it; the always-excluded set and invalid slugs
// are stripped afterwards, as today.
apply_filters( 'sysmda_front_matter_taxonomy_slugs', $selected, $post );
```

Code-only usage (no options touched) keeps working but must state both: return
`true` from the gate and supply slugs. This is a documented consequence of "no
implicit list anywhere", and goes in the filter docblocks and in `AGENTS.md`.

`EXCLUDED_TAXONOMIES` (`category`, `post_tag`, `post_format`) stays as the final
strip, so the panel and the filters can never duplicate `categories`/`tags` or
inject a presentational taxonomy.

### Panel

Same section (**Markdown output**), same field id and label (*Custom
taxonomies*), new body: a checkbox list replacing the single checkbox.

- **Candidates**: taxonomies registered for at least one **enabled** post type —
  the *effective* list, `PostSupport::supported_post_types()`, not the raw option,
  since a site may enable its types through the filter alone — with `show_ui` or
  `public` true so the user
  recognises them, minus `EXCLUDED_TAXONOMIES`. Alphabetical by slug.
- **Row**: `Label (slug)` + a badge from the strict predicate — nothing for a
  fully public taxonomy, `internal — no public archive` when
  `publicly_queryable` is false. The badge is advisory: the row is still
  tickable, because "internal but I want it in the machine-readable output" is
  a legitimate, deliberate choice (`AGENTS.md` already says so for the filter).
- **Empty states**: no enabled post types → point at the General tab; enabled
  types but no eligible taxonomy → say so plainly.
- Description: nothing is added to the front matter until at least one box is
  ticked; categories and tags are already emitted under their own keys.
- A **stale-selection note**: a saved slug whose taxonomy is no longer
  registered is kept in the option (so deactivating a plugin temporarily does
  not silently lose the choice) and shown as `slug — not currently registered`.

### Cache / ETag

No new mechanism needed, but three things must hold and be asserted:

1. `MetadataBuilder::taxonomies_fingerprint()` already covers *which* terms are
   emitted; because the emitted set now derives from the selection, a selection
   change changes the fingerprint for every post that has terms in the affected
   taxonomy.
2. Saving the settings bumps `sysmda_cache_salt` via
   `AdminSettings::maybe_bump_cache_salt()` (`added_option` / `updated_option`,
   any `sysmda_*` except the salt and the hit buckets) — the new option is
   covered automatically, so every `ETag` changes once on save. Do **not** add
   it to the exclusion list.
3. `MarkdownController::date_is_strong_validator()` keys off
   `taxonomies_fingerprint() === ''`, so it follows the selection with no
   change: with nothing selected the block is absent and `If-Modified-Since`
   becomes usable again.

### Migration (one-time, from 0.24.x)

Trigger: presence of the legacy option (it is autoloaded, so the check is free).
Hook `wp_loaded` (after `init`, so late-registering taxonomies are visible),
run once, idempotent:

1. If `get_option( 'sysmda_front_matter_taxonomies' ) === false` → nothing to do.
2. If it is `'1'`: seed `sysmda_front_matter_taxonomy_slugs` with the taxonomies
   of the **effective** supported post types (`PostSupport::supported_post_types()`
   — the raw option would seed nothing on a site whose types come from the
   filter, silently dropping taxonomies it was already emitting) that pass the
   **strict** predicate (`public && publicly_queryable`), minus
   `EXCLUDED_TAXONOMIES`. Write only if the seed is non-empty.
3. `delete_option( 'sysmda_front_matter_taxonomies' )`.

Net effect for a site that had the feature on: the same output **minus** the
internal taxonomies — i.e. the bug fixed, the feature kept, no reconfiguration.
For a site that had it off: nothing appears, as before.

## 5. Work breakdown

Single PR on `claude/custom-taxonomies-visibility-8snhvl`, atomic commits.

1. **`src/MetadataBuilder.php`**
   - `taxonomy_terms()`: drop the `get_object_taxonomies()` auto-detection; take
     the selected list as the default for both filters (per §4). Update the
     docblock — it currently documents the public-default behaviour.
   - Add `public static function is_public_taxonomy( $taxonomy ): bool` —
     pure, accepts a `WP_Taxonomy`-shaped object, `public && publicly_queryable`.
     Shared by the panel badge and the migration seed, and unit-testable.
   - Add `public static function eligible_slugs( array $post_types ): array` (or
     keep it in `AdminSettings` if it stays admin-only) for the candidate list.
     Wherever it lands, the pure part must be testable without WP.
2. **`src/AdminSettings.php`**
   - `register_setting( 'sysmda_front_matter_taxonomy_slugs', … )` with a new
     `sanitize_taxonomy_slugs()`: array in, `sanitize_key()` each, drop empties
     and `EXCLUDED_TAXONOMIES`, keep unknown-but-valid slugs (stale selection),
     dedupe, `array_values()`. Modelled on `sanitize_post_types()`.
   - Retire the `sysmda_front_matter_taxonomies` registration; rewrite
     `field_front_matter_taxonomies()` as the checkbox list per §4.
   - `hook_filters()`: gate closure returns `! empty( (array) get_option( … ) )`;
     add a priority-5 filter feeding the option into
     `sysmda_front_matter_taxonomy_slugs` so user code at priority 10 can still
     narrow and extend it.
   - `maybe_migrate_legacy_taxonomy_option()` on `wp_loaded`.
3. **`uninstall.php`** — add `sysmda_front_matter_taxonomy_slugs`; keep the
   legacy `sysmda_front_matter_taxonomies` in the list (same treatment as
   `sysmda_dynamic_tag_enabled`, with the same "legacy" comment).
4. **`tests/run-tests.php`** — see §6.
5. **Docs** — `docs/output-format.md` (*Custom taxonomies*: default list becomes
   "the taxonomies selected in the panel"; note the 0.25.0 behaviour change
   under the compatibility policy — the block can now be *absent* where 0.24.x
   emitted it, which is a settings-driven narrowing, not a format break),
   `AGENTS.md` (*Current state* bullet, *Product decisions* — amend the July 2026
   decision to "opt-in **and** explicitly selected; no auto-detection, because
   the registry cannot express intent", *Filters* list), `README.md`,
   `readme.txt` (feature bullet + changelog + a FAQ line on the internal-taxonomy
   case), `docs/HANDOFF.md`.
6. **Release** — bump `Version:` + `SYSMDA_VERSION` to `0.25.0`, `Stable tag` +
   changelog in `readme.txt`, `bash bin/build.sh`, then push and open the PR
   (the user merges with squash; the tag is created by the workflow).

## 6. Tests

Pure-logic tests in `tests/run-tests.php` (no WP, no PHPUnit). The harness
already stubs `get_object_taxonomies`, `get_the_terms`, `apply_filters` (forced
return values) and `wp_json_encode`; the taxonomy stub objects must gain a
`publicly_queryable` property.

1. **Regression, the reported bug**: a taxonomy with
   `public => true, publicly_queryable => false` that has terms is **not**
   emitted when it is not selected.
2. **Nothing selected ⇒ nothing emitted**: front matter byte-identical to the
   no-taxonomy golden fixture, and `taxonomies_fingerprint()` is `''` (so the
   `If-Modified-Since` path stays strong).
3. **Only the selection is emitted**, even when other public taxonomies on the
   same post type have terms.
4. **Deliberate opt-in works**: an internal taxonomy that *is* selected is
   emitted (the badge is advisory, not a veto).
5. **Filter still narrows and extends** the selected list, and
   `category`/`post_tag`/`post_format` plus invalid slugs are stripped after it.
6. **Kill switch**: `sysmda_front_matter_taxonomies => false` suppresses the
   block even with a non-empty selection.
7. **Fingerprint tracks the selection**: two different selections over the same
   post produce different fingerprints (⇒ different `ETag`).
8. `is_public_taxonomy()` truth table: `public` only, `publicly_queryable` only,
   both, neither, missing properties.
9. Existing golden front-matter fixtures updated to drive the selection
   explicitly, and the `normalize_taxonomies()` / ordering tests kept green.

Then: `php -l` on touched files, `php system-markdown-alternate/tests/run-tests.php`,
`composer --working-dir=system-markdown-alternate phpcs` (0 errors).

## 7. Manual acceptance (staging)

1. Register a taxonomy `public => true, publicly_queryable => false` on `post`,
   assign a term → panel shows it with the `internal` badge, unticked; the `.md`
   front matter has no `taxonomies:` key.
2. Tick it → the key appears; untick → it disappears; `ETag` changes each time,
   and a conditional request with the old `ETag` returns the full body (not
   `304`).
3. Upgrade path: on a copy with 0.24.x settings and the checkbox on, the
   migration ticks the public taxonomies only, drops the legacy option, and the
   output loses exactly the internal taxonomies.
4. WooCommerce active with `product` enabled: only the taxonomies actually
   ticked show up — no `product_type` / `product_visibility` plumbing.
5. Rename a term with the block on → next `.md` reflects it (fingerprint), and
   `If-Modified-Since` alone does not yield a stale `304`.

## 8. Risks

- **Behaviour change for early adopters** (anyone who enabled F3.1 from the
  GitHub zip): output narrows. Mitigated by the migration and called out in the
  changelog; acceptable, since the narrowing *is* the fix.
- **Late-registered taxonomies**: a taxonomy registered after `wp_loaded` never
  appears in the panel list. Mitigated by keeping unknown-but-valid saved slugs
  and by the filter escape hatch.
- **Option/filter same name** (`sysmda_front_matter_taxonomy_slugs`): no
  technical conflict, but the docblock must state which is which so a future
  reader does not mistake one for the other.

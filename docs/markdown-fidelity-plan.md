# Table fidelity and label escaping in the Markdown body (closed)

> **Closed. Kept as the record of measured work, not as a plan.** Phase 1
> (§1.3/§1.4, labels and destinations) shipped in `0.49.4`; Phase 2 (§1.1/§1.2,
> table grid and header row) in `0.51.0`, as
> `ContentRenderer::normalize_tables()`. Nothing here is outstanding — the
> backlog is [`STATUS.md`](STATUS.md).
>
> Written and kept in the private companion repository while it was an
> unannounced intention, and **moved here in September 2026** once both phases
> had shipped: a record of work that is in the product belongs where it helps
> people understand the plugin. Its value now is §3 — why this had to be a DOM
> pass rather than a converter override, and the two mistakes the design
> produces on a first pass.
>
> **Phase 2 implementation notes** (added 10 September 2026, do not re-derive):
> the design in §3 was followed as written and needed no change — a DOM pass in
> `process_dom()`, not a converter override, for the bottom-up reason §3.1
> gives. Every defect in §1.1/§1.2 was re-measured against the pinned
> `league/html-to-markdown` 5.1.1 before any code was written, and all of them
> still reproduced. Both mistakes §3.2 records were made again and caught the
> same way — by running the fixtures, not by re-reading the rule:
> the first version computed a row's width as `$column + count($occupied)`,
> which double-counts the positions a rowspan already stepped over and produced
> a three-column table for the two-column `rowspan` fixture. All three are now
> negative-controlled in the suite: disabling the pass flips 8 assertions, the
> naive any-`<th>` header test flips exactly the row-label fixture, and
> appending the placeholder instead of inserting it flips exactly the `rowspan`
> one. One addition not in §3: a table nested inside another table's cell has
> its grid filled but gets **no** header row — GFM cannot express a nested
> table, so the inner one is flattened into the cell either way and an empty
> header there is pure noise (measured both ways against the current output).
> **Two P2 findings on the implementation PR (#140), both reproduced before
> being fixed, and both worth adding to §3's list of things this design gets
> wrong on the first pass:**
> - **A header row carrying a `colspan` was emitted as a data row.** §3.1(2)
>   says a header exists when the first row is entirely `<th>` — but §3.3 puts
>   the grid fill first, and it inserted `<td>` placeholders, so that row
>   stopped being all-`<th>` before the test ran. Two remedies are in: the
>   placeholder now mirrors the spanning cell's tag, and the header test moved
>   ahead of the expansion. The negative controls say the **tag mirroring** is
>   the operative one — reverting either alone leaves the fixture passing.
>   Whoever revisits §3.3's ordering should know that its own rule ("this pass
>   only moves and inserts empty cells, it never reads or rewrites content")
>   was not quite true: inserting a `<td>` into a header row *is* a change of
>   meaning.
> - **`rowspan="0"` is valid HTML** — it covers every remaining row of the row
>   group — and §3.3's bounding rule ("treat a non-numeric or negative value as
>   `1`") swept it in with the broken values, reproducing the exact column
>   shift §1.2 exists to fix. It now expands to the rest of the group, resolved
>   as "same `parentNode`". `colspan="0"` deliberately keeps the `1` reading:
>   the standard requires `colspan` above zero, so the zero-means-the-rest
>   semantics are `rowspan`-only. Both have fixtures, so the asymmetry is not
>   later mistaken for an oversight.
>
> The public contract is documented in `docs/output-format.md` under
> *Table grids*.** Opened 30 August 2026 against `0.49.3` (`main`
> at `941f94d04728bc5f56e27f1d33b2844794b03b69`), with
> `league/html-to-markdown` `5.1.1` as pinned in `composer.lock`. Every defect
> below was **reproduced against this plugin's own converter** before being
> written down; every fix below was **prototyped and seen to produce the
> corrected output** before being proposed. Neither the reproductions nor the
> prototypes touched the plugin: both ran in a scratch harness loading
> `vendor/autoload.php` plus the plugin's `src/` classes with
> `wp_strip_all_tags()` stubbed, calling `MarkdownConverter::convert()`
> directly. The harness is not kept in the repository — rebuilding it is ten
> lines.
>
> **Phase 1 implementation notes** (added 1 September 2026, do not re-derive):
> shipped as two new converters, `SafeImageConverter`/`SafeLinkConverter`,
> registered the same way `CodeElementConverter`/`SafeParagraphConverter`
> already are, plus two new static helpers on `MarkdownConverter` —
> `escape_link_title()` (backslash first, then quote, so the backslash the
> method itself adds before a quote is never re-escaped by the second pass)
> and `wrap_destination()` (whitespace or a parenthesis triggers the `<...>`
> wrapper; a lone `<`/`>` does not, since CommonMark's unbracketed destination
> form already allows one, but either is percent-encoded once a wrapper is
> needed for another reason). `SafeLinkConverter` replicates the library's
> `LinkConverter` branch by branch rather than reimplementing it from
> scratch — the autolink and mailto-autolink branches are untouched, since an
> href reaching either has already been proven, by the pattern/equality test
> that selects it, not to carry the characters the destination wrapper guards
> against. No documentation surface changed: broken output becomes correct
> output, and nothing a user does or configures changes. Before writing the
> fix, the unpatched library was probed directly with these fixtures and
> confirmed to reproduce the exact corruption described below, so the new
> tests in `tests/run-tests.php` are a real regression guard rather than
> vacuously true.

## 1. Three defects in the body pipeline, all measured

### 1.1 A table with no header row loses its first data row

`TableConverter` emits the GFM delimiter row after the **first `<tr>` it
sees**, whatever that row is: `columnAlignments` starts as an array and the
first `tr` conversion flushes it and sets it to `null`. The class contains no
`<thead>` test at all.

WordPress core's `core/table` block emits `<thead>` **only when the header
section is populated** — its `save.jsx` renders each of `head`/`body`/`foot`
through a `Section` component that returns `null` for an empty row list, and
the editor's "Header section" toggle is off by default. So an ordinary table
reaches the pipeline as `<figure class="wp-block-table"><table><tbody>…`, with
no header at all, and:

```
IN   <table><tbody><tr><td>Rome</td><td>3</td></tr><tr><td>Milan</td><td>5</td></tr></tbody></table>
OUT  | Rome | 3 |
     |---|---|
     | Milan | 5 |
```

Rome/3 is data in the source and column names in the `.md`. Nothing about the
output looks broken; it simply asserts something the article does not. That is
the same failure mode — not empty, not obviously wrong, just false — that the
page-builder veto exists to prevent one level up, and it is worse than a
missing table because a consumer cannot detect it.

### 1.2 `colspan` and `rowspan` produce ragged rows

Neither attribute is read anywhere in the conversion. Measured:

```
IN   <tr><td colspan="2">Total</td></tr><tr><td>Rome</td><td>3</td></tr>
OUT  | Total |
     |---|
     | Rome | 3 |        ← the table is one column wide, so a GFM parser drops the 3

IN   <tr><td rowspan="2">Rome</td><td>3</td></tr><tr><td>5</td></tr>
OUT  | Rome | 3 |
     |---|---|
     | 5 |               ← 5 is published under "Rome" instead of under "3"
```

The `rowspan` case is the more damaging one for the same reason as §1.1:
nothing is missing, so nothing looks wrong, and a value is silently attributed
to the wrong column.

This is **not** a third-party-markup-only concern. Core's own `core/table`
block carries `colspan` and `rowspan` as cell attributes and emits them as
`colSpan`/`rowSpan` (same `save.jsx`), so merged cells survive a paste from
Word or Google Docs into the block editor. Classic content, Custom HTML blocks
and table plugins rendering their own markup through a shortcode all reach the
pipeline with them too.

### 1.3 `alt` and `title` are not escaped, while link text is

Attribute values never pass through the library's text converter, which is what
escapes `*`, `_`, `[`, `]`, `\` and a leading `#` for everything else in the
document. The image converter interpolates `alt`, `src` and `title` raw; the
link converter interpolates `title` raw. Measured, in one document, through one
converter:

```
alt="Note] end"        → ![Note] end](url)             ← the label closes early
title='He said "hi"'   → [text](url "He said "hi"")   ← the link stops parsing
text ] with bracket    → [text \] with bracket](url)   ← correctly escaped
```

The asymmetry inside a single document is the whole finding: the rule exists
and is applied to one of the two places a label can come from.

**This is the third occurrence of one defect family.** `0.46.1` interpolated an
ACF subtitle raw between `*` and published the reader's own asterisks as
formatting. `0.47.1` wrapped meta-field values in a `<div>`, which suppressed
the library's escaping invisibly. Both were fixed where they surfaced. The
general rule they produced — *a value placed into the document by hand is text,
and text is escaped by the same converter the body uses* — was never applied to
the two labels the converter itself builds from attributes.

### 1.4 Minor, same seam: URL destinations

```
<img src="https://e.com/my file.png">  → ![x](https://e.com/my file.png)
<a href="https://e.com/a)b">           → [text](https://e.com/a)b)
```

The link converter wraps a destination in `<…>` only when it contains a space;
the image converter never wraps at all; neither accounts for parentheses. Rare
on WordPress-sanitised internal URLs, reachable through any externally authored
link, and free to fix alongside §1.3 since it is the same two converters.

## 2. What must NOT change

- The front-matter contract in `docs/output-format.md`: keys, order, YAML
  escaping. This plan touches the **body** only.
- `ContentRenderer`'s existing passes and their order:
  `promote_figcaptions()` → `link_embeds()` → `name_empty_links()` →
  class exclusion → `absolutize_urls()`. Each order dependency is documented
  and load-bearing; a new pass takes its place in that sequence deliberately
  (§3.3), it does not reshuffle them.
- `CodeFence`, `CodeElementConverter`, `SafeParagraphConverter` and the
  masking in `CodeRegions`: a table or a label inside `<pre>`/`<code>` is a
  code sample and is published verbatim, unchanged by any of this.
- `MarkdownConverter::escape_inline()`'s contract and its reason for existing:
  the escaping rule is **the library's own**, obtained by handing it a text
  node, so there is exactly one copy of it. Nothing here adds a second.
- The route, headers, validators and caching. `SYSMDA_VERSION` is part of
  `cache_version()`, so the release carrying this invalidates every cached body
  and every `ETag` by construction — no salt bump, no migration, no special
  case.

## 3. Design — tables

### 3.1 A DOM pre-pass, not a converter override

The library converts **bottom-up**: by the time the `table` element is
converted, its rows and cells are already converted strings, and
`ElementInterface::getValue()` returns that text. A `table` converter therefore
cannot see which cells carried a `colspan`, how wide the widest row was, or
whether a header section existed — the information is gone before it is
called. Overriding `TableConverter` would mean re-parsing its own output, which
is the shape of fix that goes wrong quietly.

The library's `PreConverterInterface` hook does run before children are
converted, so it can *read* the raw element — but not repair it: the `Element`
wrapper exposes `getAttribute()`, `getChildren()` and `setFinalMarkdown()` and
**no node-insertion API at all**, with the underlying `DOMNode` protected
(checked against the installed `5.1.1`, not assumed). Blank cells cannot be
inserted from there.

The right seam is the one the plugin already uses five times: a **pass over the
DOM in `ContentRenderer::process_dom()`**, before conversion, that normalises
the table markup so that the existing `TableConverter` — unmodified — produces
correct Markdown from it. Two normalisations:

1. **Fill the grid.** Walk the rows, tracking occupied positions. For each cell
   read `colspan`/`rowspan`, mark every position it covers, then *remove both
   attributes*. Insert an empty `<td>` at each covered position, and pad every
   short row to the table's full width.
2. **Give a headerless table an empty header — but detect "headerless"
   correctly.** GFM requires a delimiter row, but nothing requires inventing
   column names. The naive test is "no `<thead>` and no `<th>` anywhere in the
   table", and it is wrong: a `<th>` can appear as a **row label inside a data
   row** (`<tbody><tr><th>Rome</th><td>3</td></tr>…`, a legitimate and common
   pattern — a table plugin, a hand-authored Custom HTML block, or a paste
   from an external editor), and that table has no real header row at all. The
   naive test sees a `<th>` and stays silent, so the unmodified
   `TableConverter` still promotes that first row — row label and value alike
   — to Markdown column headers, which is exactly the defect this
   normalisation exists to close, reopened by the fix meant to close it. A
   real header exists only when **(a)** a non-empty `<thead>` is present, or
   **(b)** the table's *own* first row (a direct `<tr>` child, or the first
   `<tr>` of its first direct `<tbody>`/`<thead>` child — never a nested
   table's row) consists **entirely** of `<th>` cells with no `<td>` among
   them, which is the classic thead-less header row still worth recognising as
   one. Anything else — including a `<th>` used as a row label anywhere in the
   body — gets the empty `<thead>` prepended.

### 3.2 Prototyped, including two mistakes

```
no header    BEFORE            AFTER
             | Rome | 3 |      |  |  |
             |---|---|         |---|---|
             | Milan | 5 |     | Rome | 3 |
                               | Milan | 5 |

colspan=2    BEFORE            AFTER
             | Total |         |  |  |
             |---|             |---|---|
             | Rome | 3 |      | Total |  |
                               | Rome | 3 |

rowspan=2    BEFORE            AFTER
             | Rome | 3 |      |  |  |
             |---|---|         |---|---|
             | 5 |             | Rome | 3 |
                               |  | 5 |

real header  BEFORE            AFTER            ← byte-identical
             | City | N |      | City | N |
             |---|---|         |---|---|
             | Rome | 3 |      | Rome | 3 |

row-header th, no real header      BEFORE            AFTER
(the case a naive "any <th>"       | Rome | 3 |      |  |  |
test misses — §3.1(2))             |---|---|         |---|---|
                                    | Milan | 5 |     | Rome | 3 |
                                                       | Milan | 5 |

genuine th-first-row header,   BEFORE            AFTER            ← byte-identical
no <thead> wrapper             | City | N |      | City | N |
                                |---|---|         |---|---|
                                | Rome | 3 |      | Rome | 3 |
```

The fourth and sixth fixtures are the ones that matter most: a table that
already states its header correctly — with a real `<thead>`, or with a
genuine all-`<th>` first row and no spans — must come out **byte-identical to
today**, and does. The fifth is the one a naive header test gets wrong (see
§3.1(2)): row-label `<th>` cells inside the body must not be read as a header,
or the defect this normalisation exists to close stays open for exactly this
shape.

Worth recording because it is exactly the kind of thing that ships silently:
**two separate mistakes were made and caught only by running the fixtures,
not by reading the rule.** The first version of the grid fill appended the
blank cells at the end of each row instead of inserting them at the covered
index, so the `rowspan` case produced `| 5 |  |` — the value still in the
wrong column, with the table now merely *looking* well-formed. The first
version of the header test (any `<th>` anywhere in the table means "has a
header") passed all four of the original fixtures cleanly, because none of
them happened to put a `<th>` inside a data row — the test was under-specified
and the fixture set was too narrow to expose it, each hiding the other. A
reviewer's fresh eyes caught it; the author's own re-reading had not. The
visible output caught both mistakes once the missing fixture was added; a
reading of the code would not have. Whoever implements this owes the
`rowspan` fixture and the row-header-`<th>` fixture specifically, and owes
checking *which column* the value lands in, not
only that the row has the right width.

### 3.3 Where the pass goes, and its guards

- **Before** the class-exclusion pass and `absolutize_urls()`, so an excluded
  cell is still excluded and links inside cells are still absolutised: this
  pass only moves and inserts empty cells, it never reads or rewrites content.
- **Cheap when there is nothing to do.** No `<table>` in the fragment → the
  pass must not cost a DOM traversal of its own; it rides the existing
  `DOMXPath` instance in `process_dom()` and exits on an empty node list.
- **Bounded.** A hostile or broken `colspan="99999"` must not be allowed to
  synthesise a table thousands of columns wide. Clamp both attributes to a
  small maximum (a value in the low hundreds is already far past any real
  editorial table) and treat a non-numeric or negative value as `1`. This is a
  guard, so per the standing rule it is not done until a fixture with an absurd
  span has been run and *seen* to be clamped.
- **Nested tables**: the walk keys cells to their own table, never a
  descendant's. A `.//tr` query from a table element reaches a nested table's
  rows too; scope by nearest ancestor or walk explicitly. The header-detection
  test in §3.1(2) needs the identical care and for the identical reason: it
  reads the table's *own* first row, through direct-child queries
  (`./tr[1]`, `./tbody[1]/tr[1]`, `./thead[1]/tr[1]`), never `.//tr[1]` —
  which would find a nested table's first row instead whenever the outer
  table's own first row is itself the cell that contains the nested table.

## 4. Design — labels and destinations

Two converter overrides registered in `MarkdownConverter::converter()`, exactly
as `CodeElementConverter` and `SafeParagraphConverter` already are (the
environment keys converters by tag, so the last registration for a tag wins):

- `alt` is escaped with **`escape_inline()`**, which is already the plugin's
  answer to "this value is text": it hands the value to the library as a text
  node, so an image alt escapes precisely what a paragraph escapes, and no
  second copy of the rule exists to drift.
- A Markdown link *title* is a quoted string, not inline text, so it is not
  `escape_inline()`'s job: escape `\` and `"` within it. (Prototyped:
  `"He said \"hi\""`.)
- A destination containing whitespace or a parenthesis is wrapped in `<…>`;
  `<` and `>` inside it are percent-encoded first, so the wrapper cannot be
  closed from within.

Prototyped against the current converter:

```
BEFORE  ![Note] end](https://e.com/a.png)
AFTER   ![Note\] end](https://e.com/a.png)

BEFORE  ![file_name *v2*](https://e.com/a.png "He said "hi"")
AFTER   ![file\_name \*v2\*](https://e.com/a.png "He said \"hi\"")

BEFORE  ![x](https://e.com/my file.png)
AFTER   ![x](<https://e.com/my file.png>)
```

Two interactions to check rather than assume:

- **`name_empty_links()` consumes an anchor's `title`** and removes the
  attribute precisely so the name is not printed twice. That pass runs on the
  DOM, before conversion, so it is unaffected — but the fixture that proves it
  (an empty anchor with a `title` carrying a quote) belongs in the suite.
- **Image alt inside a table cell**: the cell escaping the converter applies to
  `|` runs over the already-converted cell value, so an escaped alt is escaped
  first and piped second. Verify the composition on a fixture; do not reason
  about the order.

## 5. Contract and documentation impact

- `docs/output-format.md` describes the body pipeline and the table rendering.
  Both change: a table without a header row gains an empty header row, and a
  spanned cell gains blank companions. That is a **visible output change for
  existing content**, so it is a minor version, and the document must state the
  new rule rather than have it discovered.
- Ask the standing question — *would a user who read the documentation
  yesterday do something different today?* For §1.3/§1.4 the answer is no
  (broken output becomes correct output; nothing a user does changes). For the
  table work it is **yes**: what lands in a `.md` moves for content the user
  already has, and the *Excluding content* / output articles under
  `documentation/` describe what tables become. Walk the full surface list —
  `documentation/`, `readme.txt` (Key features, FAQ), `README.md`,
  `docs/output-format.md`, `docs/staging-acceptance.md`, `AGENTS.md` — and
  state in the PR body which were updated and which were deliberately skipped.
- **No new filter.** Neither fix is a choice a site could reasonably want made
  differently: an unescaped label is a bug, and a promoted data row is a false
  statement. Adding `sysmda_markdown_table_*` knobs would be adding a filter
  because something *could* be configurable, which the standing rule refuses.

## 6. Tests

All in the pure suite (no WordPress needed for any of them):

1. Table with `<thead>` and no spans → **byte-identical to the pre-change
   output**. This is the regression gate for every existing table on every
   site, and it goes in first.
2. Table with no `<thead>` and no `<th>` at all → empty header row, first data
   row still data.
3. **A `<th>` used as a row label inside a data row, no real header
   anywhere** (`<tbody><tr><th>Rome</th><td>3</td></tr>…`) → still gets the
   empty header row; the row label is not mistaken for a header section. This
   is the fixture the first version of the header test missed (§3.2) — it
   goes in specifically because a fixture set that omits it once already let
   the defect through.
4. A genuine header row using `<th>` cells with **no `<thead>` wrapper**
   (`<tr><th>City</th>…</tr>`, no spans) → **byte-identical to the pre-change
   output**. The companion to test 3: proof that the corrected header test
   does not overcorrect and start inserting a redundant header above a table
   that already states one correctly.
5. `colspan` in the first row → correct width, following rows aligned.
6. `rowspan` → the covered position is blank and the value lands in the
   **right column** (assert the cell contents, not just the row width).
7. Absurd `colspan`/`rowspan` → clamped, output bounded.
8. Nested table, including one whose **outer** table has no header but whose
   **inner** table has a genuine `<th>`-first-row header (or vice versa) →
   each table's header is detected against its own first row only, never a
   descendant's or an ancestor's.
9. A table inside `<pre>`/`<code>` → published verbatim, untouched.
10. `alt` with `]`, `[`, `\`, `*`, `_`, a leading `#`; `alt` of exactly `0`
    (the falsy-value trap that already bit `escape_inline()` once).
11. `title` with `"` and `\`, on both `a` and `img`.
12. Destination with a space, with parentheses, with `<`/`>`.
13. An empty anchor carrying a `title` with a quote → `name_empty_links()`
    still consumes it, no double name, no broken link.
14. Image with an escaped alt **inside a table cell** → escaping and `|`
    escaping compose.
15. Golden conformance fixtures re-run: the full and minimal documents must
    move only where §5 says they move.

## 7. Phasing

**Phase 1 — labels and destinations (§1.3, §1.4).** Small, closed, no visible
change to any correct document, and it closes the third instance of a defect
family that has now cost two patch releases. Ship first. **Implemented, PR
#133, not yet merged** — see the status note at the top of this file.

**Phase 2 — table grid and header (§1.1, §1.2).** Larger, changes existing
output, needs the byte-identical regression gate from test 1 and a real staging
pass on tables from the block editor, from classic content and from whatever
table plugin the acceptance site has. Ship second, in its own release, with the
documentation surfaces from §5.

The two are independent: Phase 2 can be deferred indefinitely without Phase 1
becoming wrong.

## 8. Optional Phase 3 — a deterministic mutation smoke test

The pure suite asserts on outputs it expects. It has never asserted that a
*malformed* fragment cannot take the pipeline down, and the one time that
mattered — an unbalanced `</div>` silently truncating the body until `ROOT_TAG`
was introduced — the suite had nothing to say.

A cheap addition: take the existing HTML fixtures, apply byte mutations at
pseudo-random positions with a **fixed-seed** generator (so a failure is
reproducible and CI is not flaky), plus occasional truncation, and assert only
that `ContentRenderer::render_fragment()` and `MarkdownConverter::convert()`
return a string rather than raising. Malformed input producing poor Markdown is
fine; malformed input producing a fatal is not. No WordPress, no new
dependency, runs on both CI PHP versions.

Independent of Phases 1 and 2; worth doing whether or not they happen.

## 9. Open decisions for the maintainer

1. **Empty header row, or keep promoting the first row?** The plan takes the
   empty header row because it is the only option that does not assert
   something false. The alternatives are worse but should be named: promoting
   row 1 (today's behaviour, false); repeating the first row as both header and
   data (duplicated content); or emitting the table as a list (loses the shape).
2. **Should a spanned cell repeat its value instead of leaving blanks?**
   Repeating makes each row independently readable, which arguably suits an
   LLM consumer; leaving blanks reproduces the source's own structure and never
   invents content. The plan takes blanks; repetition would be a defensible
   product decision, and should be decided once rather than per fixture.
3. **Is the `title` attribute worth emitting at all?** It is the source of half
   of §1.3, most themes never render it, and dropping it from images would be
   simpler than escaping it. Escaping is proposed because it is
   non-destructive; dropping is a legitimate alternative if the maintainer
   prefers the smaller surface.
4. **Phase 2 timing.** It changes the output of existing content on every
   install with a headerless or merged-cell table. It should not ride the same
   release as anything else that touches the body.

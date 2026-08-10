# Plan: independent rewrite of the code-element converters

> **Status: implemented for 0.41.0 after all prerequisites merged.**
> The parallel code review was reconciled on 10 August 2026. It did not change
> the converter design. The cache/security prerequisites landed in PR #75; the
> independent implementation then followed the test-first and restricted-source
> protocol recorded below.

## Executive decision

Replace `SafeCodeConverter` and `SafePreformattedConverter` with one independently
designed converter that owns both `<code>` and `<pre>` behavior. The new
implementation will use only:

- the public `league/html-to-markdown` converter API (`ConverterInterface`,
  `PreConverterInterface` and `ElementInterface`);
- this plugin's existing `CodeFence` behavior;
- the CommonMark rules for code spans and fenced code blocks;
- behavior and regression tests written from the plugin's output contract.

The implementation must not translate, rearrange or cosmetically rewrite the
upstream League converter source. Its central design difference is to consume
`ElementInterface::getValue()` instead of reading serialized children and then
removing `<code>` / `<pre>` tags with regular expressions.

Default architecture:

```text
HTML fragment
    |
    v
league/html-to-markdown traversal
    |
    +-- <code> outside <pre> --> inline code-span renderer
    |
    +-- <code> inside <pre>  --> fenced-block renderer
    |
    +-- <pre> receives child result
            |
            +-- exactly one safe fenced block --> pass through
            |
            +-- anything else --> wrap in a new, wider fence
```

The recommended new class name is `CodeElementConverter`, because it describes
what the class owns without claiming that the source element is necessarily a
block. One instance will register both supported tags: `code` and `pre`.

## Why this plan exists

The current overrides fixed a real output-corruption defect: a fixed Markdown
delimiter can be closed by the content it wraps. They are functionally valuable
and must not be removed without an equivalent replacement. However:

- `SafeCodeConverter` deliberately mirrors most of the League converter's
  structure and decisions;
- both current classes consume serialized child markup and remove wrapper tags;
- inline `<code>` has a non-structural fallback heuristic that can turn an inline
  element into a block;
- the two classes split one protocol across two implementations, even though the
  `<pre>` converter exists largely to decide whether the `<code>` converter has
  already produced a complete fenced block;
- language extraction is permissive enough to accept `language-` as a substring
  of an unrelated class;
- block rendering always inserts a newline before the closing fence, even if the
  value already ends in one;
- line-ending and boundary-space behavior for inline code is not stated as an
  explicit compatibility contract.

This rewrite is an engineering/provenance improvement. It is **not** a removal
of the MIT dependency: `HtmlConverter`, `TableConverter`, `ParagraphConverter`,
`ElementInterface`, Composer's autoloader and the rest of
`league/html-to-markdown` remain. Their bundled license files must remain in the
distribution. No root `THIRD-PARTY-NOTICES.md` is proposed by this plan.

## Goals

1. Replace the two adapted converter implementations with one independently
   designed implementation based on public interfaces and behavior contracts.
2. Preserve every valid output guarantee shipped in `0.38.0`:
   - a code block cannot close its own fence;
   - an inline code span cannot close its own delimiter;
   - language info strings survive widened fences safely;
   - bare/unprocessed `<pre>` content is always fenced;
   - an already safe fenced child is not double-fenced;
   - multiple or malformed child constructs cannot escape into the document;
   - following prose remains outside the code construct;
   - whitespace normalization never rewrites fenced content.
3. Make inline/block classification structural and predictable.
4. Remove tag-stripping regular expressions from the converter implementation.
5. Make language detection token-based and explicitly sanitized.
6. Define newline and boundary-space behavior with executable tests.
7. Keep PHP 7.4 and WordPress 6.1 compatibility.
8. Keep the public Markdown output byte-identical unless an existing output is
   proven invalid or lossy and an intentional compatibility change is approved.
9. Produce a reviewable provenance trail showing that the implementation was
   written from this specification and tests, not from upstream source.

## Non-goals

- Do not replace `league/html-to-markdown` as the general conversion engine.
- Do not remove Composer or any bundled MIT license.
- Do not build the previously rejected block-native Markdown engine.
- Do not rewrite `SafeParagraphConverter`; it composes the public
  `ParagraphConverter` and solves the separate prose-fence problem.
- Do not change block cleaning, shortcode expansion, DOM exclusions, URL
  absolutization or metadata generation.
- Do not add a settings toggle or public filter for converter selection.
- Do not keep old and new converters as permanent parallel pipelines.
- Do not claim that the process provides a legal opinion or retroactively
  changes the provenance of already released versions.

## Current pipeline and constraints

### Normal path

`ContentRenderer::normalize_code_blocks()` rewrites every `<pre>` in a
successfully parsed fragment into exactly one `<code>` child containing a text
node. It preserves/detects a language and reconstructs line breaks for
highlighters whose markup represents one source line per element.

The League traversal converts the child before its parent:

1. the `<code>` converter sees that its parent is `<pre>` and emits a complete
   fenced block;
2. the `<pre>` converter receives that child Markdown and passes it through if
   it is exactly one safe fenced block.

### Required fallback path

Bare or unusual `<pre>` markup still reaches the converter when:

- the DOM parse fails and `process_dom()` deliberately returns the unprocessed
  HTML; or
- `sysmda_markdown_rendered_html` injects/replaces markup after the DOM pass.

The replacement must therefore continue to support:

- a bare `<pre>`;
- `<pre>` with no `<code>` child;
- `<pre>` with several `<code>` children;
- `<pre>` containing prose that merely resembles a fenced block;
- `<pre>` containing one already converted, structurally safe fenced block.

### Public API characterization

A read-only characterization against League 5.1.1 established the behavior the
new design may rely on:

- for `<code>`, `ElementInterface::getValue()` returns decoded text rather than
  the serialized `<code>` wrapper;
- for bare `<pre>`, it returns decoded preformatted text;
- for `<pre>` with converted children, it returns the children's final Markdown;
- `getChildrenAsString()` includes serialized wrapper markup and is therefore
  the API that forced the current regex removal.

The implementation must add an integration test pinning the relied-upon
`getValue()` behavior through `HtmlConverter`; it must not depend solely on this
one-time observation.

## Proposed component

### `CodeElementConverter`

New file: `system-markdown-alternate/src/CodeElementConverter.php`.

Implements `League\HTMLToMarkdown\Converter\ConverterInterface` and
`League\HTMLToMarkdown\PreConverterInterface`, and supports:

```php
array( 'code', 'pre' )
```

Suggested internal methods (names may change during implementation, behavior
may not):

- `convert( ElementInterface $element ): string`
- `preConvert( ElementInterface $element ): void`
- `convert_code( ElementInterface $element ): string`
- `convert_pre( ElementInterface $element ): string`
- `render_inline( string $value ): string`
- `render_block( string $value, string $language = '' ): string`
- `language( ElementInterface $element ): string`
- `language_from_classes( string $classes ): string`
- `normalize_line_endings( string $value ): string`

The class keeps only ephemeral traversal state: `preConvert()` records whether
each `<pre>` originally had exactly one `<code>` child, and `convert_pre()`
consumes and deletes that flag. This is necessary because the real library
replaces converted children with text nodes before it calls the parent
converter; inspecting `getChildren()` at parent-conversion time cannot recover
their provenance. No WordPress functions, options, filters or globals belong in
the class.

### Dispatch contract

`convert()` branches only on `strtolower( $element->getTagName() )`:

- `code` -> `convert_code()`;
- `pre` -> `convert_pre()`;
- unexpected tag -> return the element value unchanged defensively.

Although the environment should call the converter only for its registered
tags, the defensive branch makes misuse non-destructive and easy to test.

### `<code>` classification

A `<code>` element is a block **only** when its immediate parent tag is `pre`.
Every other `<code>` element is inline.

Remove the current content heuristic based on a backtick-space pattern. Content
cannot change an element's structural role. Add a regression showing that an
inline code span containing the old trigger remains inline and does not gain
block newlines.

### Text source

Use `(string) $element->getValue()` as the source value. Do not:

- consume `getChildrenAsString()` for ordinary text extraction;
- regex-remove opening or closing HTML tags;
- call `strip_tags()`;
- decode entities a second time.

This preserves literal `<`, `>`, `&` and quotes as text after the DOM/library
layer has decoded them, while avoiding accidental removal of code that merely
contains tag-like strings.

### Inline renderer

The inline renderer must:

1. normalize CRLF and CR to LF;
2. decide, using a documented CommonMark rule, how an actual line ending inside
   inline code maps to Markdown (proposed: one space per line ending, never
   silent concatenation);
3. choose a delimiter longer than the longest backtick run using
   `CodeFence::inline_delimiter()`;
4. add symmetric padding when required so content cannot merge with the
   delimiter and CommonMark parsing reproduces the intended boundary bytes;
5. define the empty-value output explicitly;
6. return no block-level leading/trailing newline.

The current `CodeFence::needs_padding()` covers backticks at a boundary. During
the test-first phase, decide whether it must also cover values that begin **and**
end with spaces, because CommonMark removes one symmetric space in a non-all-space
code span. If this helper changes, preserve the old name only if its contract
remains accurate; otherwise introduce a clearer helper and deprecate/remove the
old private-use behavior in the same release.

Any change to inline newline, empty-code or boundary-space bytes is an explicit
output-format decision, not an incidental cleanup.

### Block renderer

The block renderer must:

1. normalize CRLF/CR to LF without trimming spaces or blank lines;
2. sanitize the language through `CodeFence::info_string()`;
3. choose `CodeFence::block_delimiter( $value )` after normalization;
4. put the opening fence and optional info string on one line;
5. ensure a line break separates content from the closing fence;
6. avoid manufacturing an extra blank line when content already ends in `\n`;
7. preserve multiple intentional trailing newlines inside the block;
8. produce a valid empty fenced block;
9. leave final block separation to the parent converter/normalization contract.

Proposed boundary algorithm:

```text
opening fence + info + "\n"
content
if content does not end in "\n": add exactly one "\n"
closing fence
```

This algorithm must be approved against the byte-compatibility baseline before
implementation because the current nested-`<code>` branch always adds a newline
even when one is already present.

### Language detection

Resolve the first valid language from the following ordered sources:

1. the `<code>` element's `class` tokens;
2. `data-language` / `data-lang` on `<code>`;
3. for block code only, the parent `<pre>` class tokens;
4. `data-language` / `data-lang` on the parent `<pre>`.

Class matching must be token-based and anchored, for example:

```text
language-php       -> php
foo language-js   -> js
notlanguage-php   -> no match
language-          -> no match
```

Do not reproduce the broad `lang-*` / `brush:*` reconnaissance already owned by
`ContentRenderer::detect_code_language()` unless the parallel review shows a
real post-DOM use case. The normal path has already canonicalized the result to
`language-*`; the converter fallback should be conservative.

Every candidate still passes through `CodeFence::info_string()`. Invalid or
empty candidates result in no info string, never an invalid fence opener.

### `<pre>` renderer

Read the pre value via `getValue()` after child conversion.

- If `preConvert()` recorded exactly one structural `<code>` child **and**
  `CodeFence::is_safely_fenced( $value )` is true, return that one block with the
  exact separator expected by the League environment. Do not alter its
  delimiter, language or body. The child check proves provenance: a bare
  `<pre>` may contain literal text that happens to be a valid fence and must
  still be wrapped.
- Otherwise render the entire value as a new fenced block. This includes plain
  bare text, several converted child code spans/blocks, pseudo-fences and
  malformed combinations.
- When wrapping converted children, the new outer delimiter must be longer than
  every backtick run in the combined value, so all child Markdown remains
  literal code.
- A bare `<pre class="language-x">` may supply an info string through the same
  conservative language resolver. A `<pre>` whose value is already one fenced
  block keeps the child's existing info string instead.

`CodeFence::is_safely_fenced()` remains the single syntax predicate. Combine it
with the pre-conversion structure above; do not add a second weaker fence
heuristic or a marker inside the converted text.

## Compatibility policy

### Baseline first

Before deleting either current class, run the complete existing conversion
suite and capture a local old/new differential corpus covering every fixture
listed below. The temporary comparison harness does not need to be committed;
every approved output must end up as a normal regression expectation.

Classify every difference:

- **Required equivalence:** valid current output; new bytes must match.
- **Approved correction:** current bytes are invalid, ambiguous or lossy;
  document the reason and expected new bytes.
- **Unexplained drift:** blocks the rewrite.

No broad snapshot update is permitted. Each changed expectation needs its own
named test and explanation.

### Stable output contract

The Markdown output format is documented as stable. Therefore:

- delimiter widening behavior remains byte-stable;
- ordinary inline code and ordinary fenced blocks remain byte-stable;
- intentional corrections to trailing-newline/space handling require an entry
  in `docs/output-format.md`, `CHANGELOG.md` and `readme.txt`;
- if review concludes that a correction could break realistic consumers, defer
  it and perform only the independent structural rewrite in this release.

### Cache/validator consequence

Changing emitted bytes requires a plugin version bump, which already participates
in `MarkdownController::cache_version()`. Verify that the release bump happens
before staging tests so an old cached body cannot be paired with the new output.
No new cache key, salt or invalidation hook is expected.

## Independent implementation protocol

This is an engineering provenance protocol, not a legal certification.

For the strongest practical separation:

1. Finish and freeze this plan after the parallel review reconciliation.
2. Write/approve the behavior tests before production implementation.
3. Implement in a fresh task/worktree whose instructions explicitly forbid
   opening:
   - League's `CodeConverter.php` and `PreformattedConverter.php`;
   - historical versions of this plugin's two `Safe*` files;
   - the ProgressPlanner converter implementation.
4. Allow that implementer to read only:
   - this plan;
   - the public `ConverterInterface` / `ElementInterface` signatures;
   - `CodeFence.php`;
   - the approved tests and output-format documentation;
   - the relevant CommonMark sections.
5. Record this boundary in the PR description.
6. After implementation, a different review pass may compare behavior and scan
   for suspicious source similarity; it must not feed upstream source back into
   the implementation unless a defect cannot otherwise be specified.

Because the upstream library remains bundled, its MIT `LICENSE` still ships.
The new class itself should carry only this plugin's normal package DocBlock;
do not add a claim that upstream copyright has disappeared from the dependency.

## Review reconciliation gate (mandatory before coding)

The parallel review completed against `0.40.0`. The maintainer closed this gate
on 10 August 2026 with the outcome **Split prerequisites**.

| Review finding | Converter relevance | Plan impact | Decision |
|---|---|---|---|
| Authenticated `/llms.txt` can populate the shared anonymous body cache | none | prerequisite | accepted; fix before converter Phase 1 |
| Synced patterns reached through generic ACF source fields are absent from the dependency fingerprint | none | prerequisite | accepted; fix before converter Phase 1 |
| Removing the last external dependency can make `If-Modified-Since` strong again without moving the post date | none | prerequisite | accepted; fix before converter Phase 1 |
| Non-canonical singular aliases may negotiate before WordPress redirects them | none | none | accepted; separate routing-hardening PR |
| A Markdown actions shortcode rendered after footer scripts were printed can remain hidden | none | none | accepted; separate UI-hardening PR |
| The one-time `.htaccess` backup is stored under the public document root | none | none | accepted; separate filesystem-hardening PR |
| No real-WordPress routing/header integration suite | indirect | none | accepted coverage gap; track separately, not a converter prerequisite |
| No browser execution of `md-actions.js` | none | none | accepted coverage gap; track with the UI-hardening work |
| HTTP/output/filter documentation drift and two non-English comments | none | none | accepted cleanup; separate documentation change |

Gate answers:

1. **No.** The review found no additional correctness or security defect in
   `<code>` / `<pre>` conversion.
2. **No.** It did not challenge `ElementInterface::getValue()` as the public
   input. The real-`HtmlConverter` characterization test remains mandatory.
3. **No.** It found no converter output drift and did not change the existing
   byte-compatibility budget.
4. **No.** It did not recommend a broader converter or block-native engine.
5. **Yes, outside conversion.** Three cache/security defects must land first so
   the converter PR is not built on known validator or representation-isolation
   faults. The WordPress and browser coverage gaps remain important but do not
   block the converter's pure and real-library characterization tests.
6. **No.** The converter release classification remains conditional: a
   byte-identical independent rewrite is a patch; an approved output correction
   is a minor release under this pre-1.0 policy.

Outcomes:

- **Proceed unchanged** — no relevant findings.
- **Revise plan** — findings are compatible but add behavior/tests.
- **Split prerequisites** — another fix must land first.
- **Cancel/supersede** — review supports a different architecture.

Closing decision: **Split prerequisites.** The converter architecture and test
plan proceed unchanged after the three cache/security fixes above are merged.
The routing, UI, filesystem and documentation work remains intentionally split
so it cannot blur the converter's provenance or compatibility review.

## Test plan

### A. Public-interface characterization

Through the real `HtmlConverter` environment, not a hand-written mock:

- `<code>` value is decoded text and excludes the serialized wrapper;
- bare `<pre>` value is decoded text;
- `<pre><code>…</code></pre>` receives the child's final Markdown;
- `PreConverterInterface::preConvert()` sees the original `<code>` child before
  traversal replaces it with a text node;
- one converter registered for both tags is invoked in the expected child-first
  order;
- unexpected/unhandled tags cannot be routed to it by registration.

### B. Inline code

- ordinary text keeps one-backtick delimiters;
- one, two, three and longer internal backtick runs grow the delimiter;
- content beginning with a backtick is preserved;
- content ending with a backtick is preserved;
- content beginning and ending with backticks is preserved;
- leading-only, trailing-only and symmetric spaces are preserved as approved;
- all-space content is defined;
- empty content is defined;
- CRLF, CR and LF behavior is defined;
- a newline does not silently concatenate two words;
- `&lt;`, `&gt;`, `&amp;`, quotes and non-ASCII text are preserved once;
- nested highlighting spans contribute text, not markup;
- the old backtick-space heuristic no longer turns inline code into a block;
- following prose remains following prose.

### C. Fenced blocks

- empty block;
- ordinary one-line and multi-line blocks;
- no final newline / one final newline / several final blank lines;
- trailing spaces on code lines remain byte-for-byte;
- internal three-, four- and longer-backtick runs widen the fence;
- an info string survives a widened fence;
- invalid info-string characters are removed safely;
- multiple class tokens select only an anchored `language-*` token;
- a misleading class such as `notlanguage-php` does not match;
- language from parent `<pre>` works on the fallback path;
- data attributes follow the approved precedence;
- literal entity text is decoded exactly once;
- text after the block remains prose.

### D. `<pre>` pass-through and fallback

- exactly one ordinary fenced child is not double-fenced;
- exactly one widened fenced child is not double-fenced;
- pseudo-fenced text bounded by backticks is wrapped;
- an interior close/reopen sequence is wrapped;
- two fenced child blocks are wrapped together by a wider outer fence;
- a closing run shorter than its opener is not accepted;
- an info string containing a backtick is not accepted;
- unclosed fenced text is wrapped;
- bare preformatted text is fenced;
- bare empty `<pre>` is valid;
- post-DOM-filter injection of a bare `<pre>` stays safe.

### E. Container and normalization regressions

Retain the existing end-to-end expectations for:

- blockquotes;
- nested blockquotes;
- lists;
- nested lists;
- code containing indented inner fences;
- trailing spaces and multiple blank lines inside every container;
- prose normalization resuming after the closing fence.

These tests exercise the interaction with `MarkdownConverter::fence_parts()`;
passing isolated converter tests is not enough.

### F. Property-style corpus

Without adding a testing dependency, loop over generated fixtures:

- longest internal backtick runs from 0 through at least 12;
- content with the run at start, middle, end and on its own line;
- CRLF/LF variants;
- boundary backtick/space combinations.

For every block fixture assert that the outer delimiter is strictly longer than
the longest internal run and that a sentinel paragraph after it survives. For
every inline fixture assert that its delimiter is strictly longer than the
longest internal run and no newline is introduced around the span.

### G. Full regression suite

- `php system-markdown-alternate/tests/run-tests.php`
- `composer --working-dir=system-markdown-alternate phpcs`
- `php -l` on every touched PHP file
- CI on PHP 7.4 and PHP 8.4
- `bash bin/build.sh`
- inspect the built ZIP: old converter files absent, new converter present,
  Composer and League license files still present.

## Staging validation

Use a dedicated post containing all of the following sources because the defect
must be fixed independently of where code originates:

1. core Code block;
2. core Custom HTML with `<pre><code>`;
3. Classic content;
4. core Shortcode block producing code markup;
5. ACF WYSIWYG code;
6. Code Block Pro / Shiki line wrappers;
7. a blockquote and nested list containing code;
8. internal backtick runs of lengths 1, 3, 4 and 8;
9. literal entities and non-ASCII text;
10. leading/trailing spaces, blank lines and CRLF-origin content;
11. a normal paragraph and heading after every dangerous fixture.

Validate:

- direct `.md` suffix response;
- negotiated `Accept: text/markdown` response;
- copy action from `[sysmda_md_actions]`;
- view and download actions return the same bytes;
- first `200` and subsequent conditional request use validators for the new
  version correctly;
- no PHP warnings/notices in staging logs;
- HTML pages remain unchanged.

Run the existing corpus before and after on the same staging content and retain
the diff as PR evidence. Only approved differences may remain.

## Suggested implementation sequence

### Phase 0 — reconcile the parallel review

1. Receive the final review report and its target commit.
2. Rebase this plan branch if `main` moved.
3. Fill the review matrix and resolve every direct/indirect finding.
4. Revise behavior, tests, affected files and release scope.
5. Obtain explicit maintainer approval to begin implementation.

Deliverable: frozen plan revision; still no runtime code.

### Phase 1 — tests and compatibility baseline

1. Add named tests for every unpinned behavior in sections A–F.
2. Run them against the current implementation.
3. Mark tests that describe approved new behavior so they initially fail for a
   precise reason.
4. Build the temporary old/new differential corpus.
5. Obtain approval for every intentional byte change.

Deliverable: reviewed behavior contract.

### Phase 2 — independent converter implementation

1. Add `CodeElementConverter.php` in the restricted implementation context.
2. Implement tag dispatch and structural inline/block classification.
3. Implement `getValue()`-based text handling.
4. Implement inline rendering and approved padding/newline rules.
5. Implement block rendering and language precedence.
6. Implement `<pre>` safe-pass-through/wider-wrap behavior.
7. Make the focused tests green before changing registration.

Deliverable: new class, not yet active in production wiring.

### Phase 3 — integration and removal

1. Register one `CodeElementConverter` in `MarkdownConverter`.
2. Remove registrations for both old converters.
3. Delete `SafeCodeConverter.php` and `SafePreformattedConverter.php`.
4. Update test bootstrap/autoload assumptions if any explicit includes exist.
5. Run focused, full and property-style tests.
6. Run a source-similarity/provenance review of the new class.

Deliverable: no adapted converter implementation remains under `src/`.

### Phase 4 — documentation, release and staging

1. Update `AGENTS.md` architecture/current-state entries.
2. Update `docs/output-format.md` only for approved observable changes.
3. Update `CHANGELOG.md` and the trimmed `readme.txt` changelog.
4. Choose patch vs minor version from actual behavior:
   - byte-identical internal rewrite -> patch candidate;
   - intentional output corrections/new behavior -> minor candidate under the
     repository's `0.x.y` convention.
5. Bump both plugin version declarations and stable tag.
6. Build and inspect the distributable.
7. Execute staging validation and attach the before/after evidence to the PR.

Deliverable: release PR, never a direct push to `main`.

## Files expected to change during implementation

### Add

- `system-markdown-alternate/src/CodeElementConverter.php`

### Delete

- `system-markdown-alternate/src/SafeCodeConverter.php`
- `system-markdown-alternate/src/SafePreformattedConverter.php`

### Modify

- `system-markdown-alternate/src/MarkdownConverter.php`
- `system-markdown-alternate/src/CodeFence.php` — only if approved inline
  padding behavior requires it
- `system-markdown-alternate/tests/run-tests.php`
- `docs/output-format.md` — only for observable corrections
- `AGENTS.md`
- `CHANGELOG.md`
- `system-markdown-alternate/readme.txt`
- `system-markdown-alternate/system-markdown-alternate.php` — release only

### Explicitly unchanged

- `composer.json` / `composer.lock`
- bundled League and Composer licenses
- `SafeParagraphConverter.php`
- `ContentRenderer::normalize_code_blocks()` unless the parallel review finds a
  direct defect that must be handled in the same scope
- public filters and settings

## Risks and mitigations

| Risk | Consequence | Mitigation |
|---|---|---|
| Library traversal/value semantics change | malformed or double-fenced output | real-library characterization tests; Composer lock; CI |
| One converter registered for two tags behaves differently than expected | child/parent ordering regression | focused integration test before deletion of old classes |
| Byte drift hidden in mass snapshot updates | undocumented output break | named diffs only; unexplained drift blocks merge |
| Inline whitespace correction breaks a consumer | compatibility regression | separate decision; defer correction if evidence is weak |
| Language detection becomes too broad | invalid info string or false language | anchored class tokens plus final sanitization |
| Language detection becomes too narrow | missing syntax hint | normal DOM path already canonicalizes; stage third-party blocks |
| Pre pass-through predicate accepts multiple blocks | fence breakout | retain one structural predicate and adversarial fixtures |
| New implementation still resembles upstream source | provenance objective fails | restricted implementation context and post-build similarity review |
| Parallel review changes the architecture | duplicated/throwaway work | mandatory review gate before any runtime edit |
| Old cache serves old bytes | validator/body mismatch | release version bump before staging and production |

## Rollback strategy

- Keep the rewrite in one focused implementation branch/PR.
- Do not combine unrelated review findings unless they are explicit
  prerequisites.
- Before release, rollback is a normal PR revert.
- After release, ship a versioned revert restoring the two previous converters;
  never move a tag or silently replace a wordpress.org package.
- Retain the new regression fixtures even if the architecture is reverted; they
  describe required output safety, not one implementation.

## Acceptance criteria

The rewrite is complete only when all of the following are true:

- the parallel review is finished and the reconciliation gate is explicitly
  closed by the maintainer;
- one independently designed converter owns both `code` and `pre`;
- neither old `Safe*` converter file or registration remains;
- the new converter uses `getValue()` and contains no wrapper-tag-stripping
  regex;
- inline/block classification is structural;
- every existing valid output fixture is byte-identical;
- every changed byte has a named test, rationale and documentation;
- delimiter, whitespace, language and fallback adversarial tests pass;
- PHP 7.4, PHP 8.4 and PHPCS CI are green;
- the built ZIP contains the new class and required dependency licenses;
- staging validates core, Classic, ACF and Code Block Pro/Shiki content;
- copy, view and download actions return the same safe Markdown;
- no unrelated runtime or public API change is bundled into the PR.

## Resolved implementation decisions

1. Inline CRLF, CR and LF each become one space, matching CommonMark §6.1 and
   preventing silent word concatenation.
2. An empty inline `<code>` emits no bytes: CommonMark has no valid empty code
   span delimiter pair, so emitting adjacent backticks would add invalid syntax.
3. Symmetric leading/trailing ASCII spaces are preserved with one compensating
   padding pair; all-space content is not padded because CommonMark does not
   strip it.
4. Fallback language detection accepts anchored `language-*` class tokens plus
   `data-language` / `data-lang`. Broader pre-DOM forms remain the responsibility
   of `ContentRenderer::detect_code_language()`.
5. One `CodeElementConverter` owns both tags; the review found no reason to keep
   the split protocol.
6. The acknowledged real-WordPress coverage gap does not block this pure and
   real-library rewrite. Staging validation remains part of release acceptance.
7. The approved newline, whitespace, empty-value and decoded-text corrections
   make this `0.41.0`, a minor release under the repository's pre-1.0 policy.

# Changelog

All notable changes to `Muon_DevProfilerBoard` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is
[SemVer](https://semver.org/spec/v2.0.0.html).

## [1.2.0] — 2026-08-28

Closes the Medium findings from the 2026-08-28 release-readiness audit.

### Security

- **`RequestUrl::openable()` accepted `/\host`.** The guard blocked protocol-relative `//` and
  nothing else, but in the WHATWG URL parser's relative-slash state a backslash routes into
  special-authority-ignore-slashes exactly as a slash does — so browsers resolved `/\evil.example/`
  to another origin, and `escapeUrl()` left the backslash untouched. The dangerous form was
  `/\evil.example\@muon.localhost/`, which renders with the real host at the end and reads as a
  local path. A backslash anywhere in the first segment is now refused.

### Fixed

- **`RunDiff` reimplemented the cache verdict instead of delegating to `CacheVerdict`.** It derived
  the verdict from `generated` and `cacheable` alone, so it ignored `request.kind` — a static run
  and a cache-hit run both compared equal while the ledger beside them showed `n/a` against `hit` —
  and its cause list skipped `constructor_optouts` entirely, so a page made uncacheable only by a
  constructor opt-out showed a named cause on the run page and an empty list on compare.

- **The ledger was scanned twice per page and per poll.** `feed()` and `matching()` each read the
  whole ring independently whenever a filter was active, re-listing the directory and re-decoding
  every run file. One memoized pass now answers both.

- **Two unguarded array keys** emitted PHP warnings for any run missing an optional field:
  `FallbackPanel` on `module`, `VerdictBanner` on `store_id`. Both were found by the new tests.

- **`RunFilter::VERDICTS` hardcoded `CacheVerdict`'s values** as string literals while naming it as
  the source of truth. It now references the constants.

### Added

- **The run document's `schema` is checked.** The board displayed it and never read it, so a run
  captured by a newer collector rendered as empty panels and zeroed counters — a capped answer
  presenting itself as a complete one. An unrecognised schema now says so.

- **`Test\Unit\Controller\ExcludedActionsTest`.** The registry that keeps the board from recording
  its own requests was checked "in review". Its failure is silent and destructive: a tenth
  controller without an entry gets recorded, and an open board polls every four seconds, so it
  evicts the runs the reader is looking at. Both sides are now read from disk and diffed.

- **`Test/Unit/Stub/generated.php`.** `RawFactory` has no source file — the framework ships `Raw.php`
  and generates the factory into `generated/code` on demand — so a test that doubles it passes on a
  full install and errors in CI. Declared only when the real class is absent, so the tests run
  everywhere instead of being skipped in CI.

- **Tests for the classes that had none**: `BoardResponse` (the no-store and noindex headers are a
  stated privacy control), `Widgets` and `UrlBuilder` (shared by every panel), `RunView` (the one
  place a raw `?panel=` decides what renders), `BoardPage`, and the panel branching that
  `XssRegressionTest` never reached. 274 tests, up from 208.

### Accessibility

- **A page heading.** Every panel heading was `h3` with no `h1` or `h2` above it.
- **Seven filter controls had no accessible name** — `FilterPanel` was the one panel not wiring
  `for`/`id`. Range pairs carry their own `aria-label`, since one label cannot name two inputs.
- **Data tables** gained `scope="col"` and a visually-hidden caption.
- **Contrast**: `--ink-faint` measured 2.83–3.30:1 and colours table headers, field labels and every
  eyebrow — the text that says what a value *is*. It and three verdict chips now clear 4.5:1 in both
  schemes.
- **The compare picker announced nothing.** The first pick was signalled by a border colour alone;
  it now sets `aria-pressed` and writes to a live region.
- **Tabs are links.** Panel switching lives in JavaScript and inactive panels carry the real
  `hidden` attribute, so with JavaScript off there was no control that could reach Layout or Raw at
  all. `board.js` intercepts the click, so behaviour is unchanged when it runs.

### Changed

- **`excludedActions` is contributed globally, not per-area.** Array arguments merge item-wise
  within a scope, but across scopes `Config::extend()` `array_replace()`s the whole `arguments`
  entry — so a third module contributing from global `di.xml`, where `RunFinalizer` is also
  constructed, would have been discarded silently.

- **`BoardPage` no longer reads the ring.** It injected `RunSelector` and `StoreManagerInterface`
  and fetched its own data, putting data access in `Model\Html` and making it the only renderer
  without a test. A `LedgerResolver` resolves rows, counts and store code for the two callers that
  render a board page.

- **`magento/module-store` is declared and sequenced.** It was used by `BoardPage` and satisfied
  only transitively through the collector.

- **`muon/module-dev-profiler` raised to `^1.4`**, the release that marks its read surface `@api`.
  Six concrete classes were being consumed across ten files with no BC promise behind them.

- **The theme-independence claim is scoped to what actually holds.** The rendered document is
  theme-independent and asserted so; the *request* is not — `Magento_Theme` registers
  `LoadDesignPlugin` on `ActionInterface`, so `DesignLoader::load()` runs before every board
  action. That is bounded, because `excludedActions` keeps those resolutions out of stored evidence.

- **Documentation uses `bin/magento`, not `make`** — those targets are monorepo wrappers that exist
  in no Magento install.

### Known trade-off, accepted

- **Deployment mode remains the only access control.** There is no ACL, admin-session check or IP
  allowlist behind `BoardGate`. The gate is not request-controllable and fails closed, but the
  failure mode is binary: an FPM pool reporting `MAGE_MODE=developer` on a public host exposes the
  ring. A second factor was considered and deliberately not added, because it would change who can
  open the board on a developer's own machine.

## [1.1.0] — 2026-08-28

### Changed

- **Relicensed from proprietary to OSL-3.0**, matching `Muon_DevProfiler`, which this module depends
  on and cannot be used without.

  The previous pairing did not hold together. OSL-3.0 is reciprocal and has no linking exception,
  and its §5 treats External Deployment — serving over a network, which is exactly what this module
  does — as distribution. This module imports six of the collector's classes across ten files,
  reproduces its analysis semantics and writes into its DI graph, so whether it constituted a
  Derivative Work was a live question rather than a settled one. A proprietary license forbidding
  copying and derivative works sat on the wrong side of it.

  `LICENSE.txt` now carries the full verbatim OSL-3.0 text rather than an abridged excerpt with a
  link. The excerpt omitted operative sections — including Termination for Patent Action and
  Jurisdiction — and is not recognized by license detectors, so a relicense resting on it would
  have been neither complete nor visible to compliance tooling.

  No source file changed: every header already read "See LICENSE.txt for license details", which is
  the same notice the collector uses.

## [1.0.1] — 2026-08-28

### Added

- **`Test/Unit/Controller/GateEnforcementTest`.** None of the nine controllers had a test, and the
  board's only access control is one hand-copied `isOpen()` line per controller with no base class
  enforcing it. The test discovers controllers by walking `Controller/` rather than listing them, so
  a tenth added without the check fails here instead of quietly serving profiler data on a
  storefront route. It asserts both that a closed gate returns `notFound()` and that no collaborator
  is reached — the second matters because a controller with its own not-found branch can satisfy the
  first without ever consulting the gate.

- **`Test/Unit/Controller/Runs/ClearTest`.** The board's only state-changing request, and the only
  place a cross-site POST could destroy the evidence someone is reading. Pins that the form key is
  validated rather than waived, that `validateForCsrf()` never returns `null` (which would let the
  framework fall back to its "not a POST, so allow it" shortcut), and that a closed gate clears
  nothing even with a valid key.

### Fixed

- **A filtered ledger no longer repopulates itself four seconds after the page loads.** The live
  feed URL was built without the active filter, and `board.js` compensated by rebuilding the query
  from `window.location` against its own hand-maintained list of parameter names. That list omitted
  `url`, so a page filtered by URL text polled an unfiltered feed and replaced the reader's five
  matching rows with the whole 25-row ring — then rebuilt the list on every tick, flashing the
  arrival highlight as new runs arrived.

  The filter now travels on `data-feed`, built from `RunFilter::toQuery()` — the same source the
  ledger links already use — and the JavaScript reassembles nothing. There is one list of parameter
  names again, so it cannot drift a second time.

### Changed

- **CI runs the unit tests.** The `unit-tests` job was present but commented out, so 181 tests were
  gated by nothing. It now runs on every push and pull request, and needs no secrets:
  `magento/*` comes from the public Mage-OS mirror and `muon/module-dev-profiler` from its own
  public repository. The job is deliberately unconditional — one made conditional on a secret skips
  its steps and still reports success.

- **`composer.json` declares where its own dependency lives.** A `repositories` entry for
  `muon/module-dev-profiler`, without which a standalone `composer install` could not resolve it.
  Composer ignores `repositories` in a dependency, so consuming installs are unaffected.

## [1.0.0] — 2026-08-14

### Added

- **A web board for `Muon_DevProfiler`.** Reads the runs the profiler already captures and renders
  them in a browser: cache verdict with its named cause, the shadow ladder for every theme fallback,
  SQL findings with their basis, layout evidence, and the stored document verbatim. Five panels, a
  live ledger of recent runs, and a side-by-side comparison of two runs.

- **Theme independence, structurally.** Every controller returns a `Controller\Result\Raw`; the
  module ships no layout XML, no `.phtml`, no `view/` directory and no theme dependency. Nothing
  resolves through the theme fallback chain — which matters beyond styling, because a board rendered
  *through* that chain would add its own resolutions to the evidence it exists to display. The
  closed-gate 404 is a Raw result too, rather than a `forward('noroute')` that would have rendered a
  themed page on the one path nobody tests.

- **Assets outside the static pipeline.** `assets/board.css` and `assets/board.js` live at the module
  root and are served by their own routes, so `setup:static-content:deploy` is never involved and an
  edit shows up on the next reload.

- **Read-time thresholds as a control.** N+1, duplicate and slow thresholds are query parameters, so
  one capture can be re-examined at a different sensitivity without reloading the page it describes.
  All analysis state lives in the URL, making every view a shareable link.

- **Copy as JSON or Markdown**, so a run travels into an issue with its evidence attached.

- **The open panel survives every round trip.** Switching a tab writes `panel` into the URL, and each
  panel's own form states which panel it is rather than carrying the value through from the query
  string. Without both, submitting Apply or Re-analyse — or clicking another run in the ledger —
  returned the reader to Overview with their filter applied to a panel they were no longer looking
  at. The forms work with JavaScript disabled.

- **The run counter reports what is shown and what is held.** It printed the ledger's row count and
  labelled it "in ring" — but the ledger is capped at `feedLimit` (25) while the ring holds
  `ringSize` (50), so once 25 runs accumulated it read "25 in ring" permanently: a cap wearing the
  costume of a total. It now reads "25 of 50 in ring", and "no runs yet" when the ring is empty.

- **Clear runs.** The board's only mutation, and the only reason it has a POST action: the collector's
  own console output tells a reader to run `bin/magento muon:profile:clear` before capturing a cold request, and
  the board could not perform the workflow it was telling them to run.

  POST with a form key, a confirm, and a redirect afterwards so a refresh cannot clear a second time.
  It deletes this module's own data and nothing else — flushing Magento's page cache is deliberately
  left to the operator, because it mutates state this module does not own from a page with no
  authentication. The board says what to run instead: clearing without flushing leaves the next load
  served from cache, resolving no files, and the board would look empty for a reason that has nothing
  to do with clearing.

- **Ledger filters.** URL, verdict, method, status, execution time and statement count, in a collapsible
  panel above the ledger. URL is a case-insensitive substring of the whole recorded URI, query string
  included — the same rule the Fallback panel's "Path contains" uses, so the board has one behaviour
  to learn rather than two. The toggle carries the number of active criteria, and a filtered ledger
  states "11 of 33 runs match" — a short list has two very different causes, and saying which is the
  point.

  **Filtering runs over the whole ring, not over the rows the ledger already fetched.** The ledger is
  capped at `feedLimit`; filtering the fetched page would let "show me the uncacheable runs" come
  back empty while uncacheable runs sat below the cut. The live poll applies the same filter, so a
  refresh cannot repopulate the list with rows just filtered out.

  Criteria live in the query string, so a filtered ledger is a link. Reversed bounds are read as the
  range between them; anything unusable — a verdict that is not one, a negative bound — is discarded
  rather than compared against, so a typo shows the whole ring instead of silently matching nothing.

### Notes

- Developer mode only, gated by `Muon_DevProfiler`'s own `Gate`, which fails closed. There is
  deliberately no enable/disable field.
- One mutation, and only one: **Clear runs** (POST, form-key validated). Every other route is GET
  and writes nothing. Flushing the page cache is deliberately not offered — that stays
  `bin/magento cache:flush`.
- The board excludes its own nine actions from the profiler's ring via
  `RunFinalizer::excludedActions`. Without it, browsing the board would evict the runs being
  inspected within seconds.
- Requires `muon/module-dev-profiler ^1.2`.

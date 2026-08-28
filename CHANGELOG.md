# Changelog

All notable changes to `Muon_DevProfilerBoard` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is
[SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Changed

- **CI runs the unit tests.** The `unit-tests` job was present but commented out, so 181 tests were
  gated by nothing. It now runs on every push and pull request. `magento/*` comes from the public
  Mage-OS mirror; the one unavoidable secret is `MUON_COMPOSER_TOKEN`, needed to read the private
  `muon/module-dev-profiler` repository, and its absence fails the job rather than skipping it.

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
  own console output tells a reader to run `make profile-clear` before capturing a cold request, and
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
  `make flush`.
- The board excludes its own nine actions from the profiler's ring via
  `RunFinalizer::excludedActions`. Without it, browsing the board would evict the runs being
  inspected within seconds.
- Requires `muon/module-dev-profiler ^1.2`.

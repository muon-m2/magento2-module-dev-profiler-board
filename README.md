# Muon_DevProfilerBoard

A web board for [`Muon_DevProfiler`](https://github.com/muon-m2/magento2-module-dev-profiler). It
reads the runs the profiler already captures and renders them in a browser: the cache verdict with
its named cause, which physical file won every theme fallback, and the statement shapes behind an
N+1 — at whatever sensitivity you ask for.

Developer mode only. It reads; the single exception is a **Clear runs** button that empties the
profiler's own ring, behind a form key and a confirm.

```
https://muon.localhost/en-us/muon_profiler/
```

## Why it exists

The profiler is headless by design and reads back through `make profile`. That is the right shape
for the capture side and an awkward one for the reading side: choosing a token by hand, re-running
the command to change one threshold, and holding two captures in your head to compare them. The
evidence was already complete; this is a second way to read it.

The board does **not** replace the CLI, and the two can never disagree — every classification comes
from the same read-time classes (`ShadowResolver`, `CacheVerdict`, `QueryAnalyzer`,
`ResolutionSet`), so `make profile t=abc123` and `/muon_profiler/run/view?token=abc123` are the same
analysis in two presentations.

## Install

```bash
bin/magento module:enable Muon_DevProfilerBoard
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Requires `muon/module-dev-profiler` **^1.2** — the version that added `RunFinalizer::excludedActions`,
which is how the board keeps its own page loads out of the ring it is displaying.

`setup:static-content:deploy` is **not** required and changes nothing here. See "No theme, on
purpose" below.

## Use

| URL | What it shows |
|---|---|
| `/muon_profiler/` | The most recent full-document run |
| `/muon_profiler/run/view?token=…` | One run — permalink |
| `/muon_profiler/compare/index?a=…&b=…` | Two runs, side by side |
| `/muon_profiler/run/json?token=…` | The stored document, verbatim |
| `/muon_profiler/run/markdown?token=…` | The run as Markdown, for pasting into an issue |
| `POST /muon_profiler/runs/clear` | Empty the ring — the board's only mutation, form-key protected |

Five panels per run — **Overview**, **Fallback**, **SQL**, **Layout**, **Raw** — plus a live ledger
of recent runs down the left, filterable by URL, verdict, method, status, execution time and
statement count. Filtering runs over the whole ring rather than the visible list, so a filter never
comes back empty while matches sit below the ledger's cap.

Analysis state lives in the query string, so **every view of the board is a shareable link**:

```
/muon_profiler/run/view?token=7f3a9c2e1b4d&panel=sql&nplus1=3&slow=10
/muon_profiler/run/view?token=7f3a9c2e1b4d&panel=fallback&shadowed=1
/muon_profiler/?url=/gear&verdict=uncacheable&min_ms=500
```

Thresholds are applied when you **read**, not when the page ran, so the same capture can be
re-examined at a different sensitivity without reloading anything.

## No theme, on purpose

Every controller returns a `Controller\Result\Raw`. The module ships **no layout XML, no `.phtml`,
no `view/` directory and no theme dependency**, and never constructs `View\Element\Template`.

That is not tidiness. The profiler exists to report which physical file won each theme fallback; a
board rendered *through* the fallback chain would add its own resolutions to the evidence it is
displaying, and a reader would have no way to tell the page apart from the tool inspecting it. It
also means Luma, Breeze, Cosmic or any future theme cannot reach the board — and that the board
looks nothing like your storefront, so you always know which surface you are on.

The stylesheet and script live in `assets/` at the module root, outside anything Magento's static
pipeline scans, and are served by two routes of this module. Editing them shows up on the next
reload with nothing to deploy.

## It does not profile itself

A board that polls for new runs is itself a frontend request, so its own page loads would be
recorded and would evict the runs being inspected within seconds — the tool destroying its own
evidence. `etc/frontend/di.xml` contributes all **nine** of its action names to
`RunFinalizer::excludedActions`, so none of them is ever written.

## One mutation, and only one

**Clear runs** in the ledger footer empties the ring. It is POST, form-key validated, confirmed, and
redirects afterwards so a refresh cannot clear twice.

It deletes this module's own data and nothing else. Flushing Magento's page cache — the other half of
the cold-capture workflow — is deliberately left to the operator, because it mutates state this
module does not own from a page with no authentication. The board prints the command instead.

## Configuration

None in the admin. One `di.xml` argument:

| Argument | Type | On | Default | Purpose |
|---|---|---|---|---|
| `feedLimit` | int | `Model\Run\RunSelector` | 25 | Ledger rows per request; the profiler's ring size caps it further |

There is deliberately no enable/disable field. Activation is
`State::getMode() === MODE_DEVELOPER`, evaluated by the profiler's own `Gate`, which fails closed —
an installation that cannot report its own mode is treated as production. In any other mode every
route returns a bodyless 404.

## Documentation

- [Technical reference](docs/technical-reference.md) — architecture, routes, classes, invariants
- [Developer guide](docs/developer-guide.md) — extending the board, the escaping contract
- [User guide](docs/user-guide.md) — reading each panel, and what the words mean
- [Changelog](CHANGELOG.md)

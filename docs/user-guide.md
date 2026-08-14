# Muon_DevProfilerBoard — User Guide

Reading the board, and what its words mean.

## Opening it

```
https://muon.localhost/en-us/muon_profiler/
```

Any store view works; the board opens on the most recent **full-document** run. That default is
deliberate — a storefront page fires customer-section XHRs immediately behind it, so the newest
entry in the ring is almost never the page you just loaded.

If you get a 404, the web request is not running in developer mode. That is set by `MAGE_MODE` in
the FastCGI params and can differ from what `bin/magento deploy:mode:show` reports for the CLI.

If it says **"No runs recorded yet"**, load a storefront page and come back.

## The layout

- **Top right — `25 of 50 in ring`.** How many runs the ledger is listing, and how many the profiler
  is holding. They differ because the ledger is capped (`feedLimit`, 25) while the ring keeps more
  (`ringSize`, 50) — so the second number is the one that tells you whether older runs are still
  there. When they match it says just `50 in ring`.
- **Left — the ledger.** Recent runs, newest first, refreshing every few seconds. Each row carries a
  verdict-coloured spine, so severity is scannable down the column without reading a word.
- **Top — the verdict.** What happened and why, in one sentence.
- **Under it — the evidence strip.** Duration · peak memory · statements · fallbacks · theme · store.
- **Below — five panels.** Overview, Fallback, SQL, Layout, Raw.

Colour means one thing only: **teal = cached, amber = built, coral = a problem, slate = not
applicable**. Violet is interaction — a link is never a finding.

## Filtering the ledger

**Filter** above the ledger opens six criteria: URL, verdict, method, status, time and statements.
The toggle shows how many are active, so a ledger that is hiding rows always says so.

```
/muon_profiler/?url=/gear
/muon_profiler/?url=sections=cart
/muon_profiler/?verdict=uncacheable&min_ms=500
/muon_profiler/?url=trade&verdict=miss&min_stmt=100
```

**URL contains** is a case-insensitive substring of the whole recorded URI, query string included —
so `sections=cart` finds the customer-section XHRs, and `/de-de/` finds one store view's traffic. It
is a plain substring, not a pattern: `*` and `.*` match themselves.

Two more things worth knowing:

- **It filters the whole ring, not the visible list.** The ledger shows at most 25 rows but the ring
  holds 50, so filtering only what is on screen would let "show me the uncacheable runs" come back
  empty while uncacheable runs sat below the cut. The summary — *11 of 33 runs match* — is counted
  across everything stored.
- **Ranges are inclusive and forgiving.** Leave either end blank for an open bound. Type them the
  wrong way round and you get the range between them, not an empty list.

Anything unusable is ignored rather than obeyed: a verdict that is not a verdict, or a negative
bound, shows the whole ring rather than silently matching nothing.

## The verdict

| Verdict | Means |
|---|---|
| **Cache hit** | Layout never generated — the page came out of the full-page cache |
| **Cache miss** | The page was built, and it is cacheable. Normal for a cold page |
| **Uncacheable** | The page was built and asked not to be cached — with the cause named |
| **Unknown** | Layout could not report whether the page is cacheable |
| **Not applicable** | A static asset — no layout, no page cache |

When the board says **"cause unknown"**, it means no generated block and no layout construction
accounts for the page being uncacheable. That is an honest answer, not a gap: an invented cause
sends you to edit the wrong file.

## Overview

Everything recorded about the request, plus two things worth reading carefully.

**Theme, `observed` vs `configured`.** `observed` means this request actually resolved files against
that theme. `configured` means the request resolved nothing — a cache hit loads no design — so the
board recovered the theme the store is *set to* and is telling you it is the weaker claim.

**Truncation notices.** If a list hit its cap, the panel says "N recorded and M more refused". A
capped list that reads as a complete one is the failure this whole tool is built to avoid.

## Fallback — which file actually won

The question the profiler exists to answer. Static analysis cannot tell you which copy of a file is
live, because the answer depends on the theme the request resolved.

Each file renders as a **ladder**: the winner marked `WON`, and every later copy struck through and
marked `SHADOWED`.

```
css/theme/abstracts/_tokens-generated.less   ×4
  WON       app/design/frontend/Muon/cosmic-custom/web/css/…/_tokens-generated.less
  SHADOWED  vendor/muon/theme-frontend-cosmic/web/css/…/_tokens-generated.less
```

The first directory searched wins. **Every later copy is dead** — it behaves exactly as if it had
never been written, which is why an override in the wrong theme is invisible rather than broken.

- `×4` means the same file was resolved four times in the request; repeats are collapsed into one
  ladder with the count kept.
- **Shadowed only** narrows to files found in more than one place — usually what you want.
- **Path contains** matches the file key *or* any path it resolved to, so searching
  `breeze-evolution` finds what the page pulled out of that theme.
- **Anomalies** are counted at the bottom rather than hidden. `probe-miss` is normal (Magento is
  allowed to look for files that do not exist). `replay-diverged` and `winner-mismatch` mean that
  entry could not be trusted, and it says so rather than quietly dropping it.

## SQL — findings first

Statement shapes, worst first, at whatever sensitivity you ask for.

| Finding | Means |
|---|---|
| **n+1** | One shape executed many times with varying arguments |
| **duplicate** | The identical statement repeated |
| **slow** | A single execution over the threshold |

Every finding states its **basis**, and the difference matters:

- *"statement text differed between executions — variation observed"* is an **observation**.
- *"bound arguments present — variation inferred, not proven"* is an **inference**.

Acting on the second as though it were the first is how a wrong cache gets written.

Thresholds — **N+1 at ≥**, **Duplicate at ≥**, **Slow over (ms)** — are applied when you read, not
when the page ran. Changing one re-examines a capture from an hour ago; nothing is reloaded. The
values live in the URL, so the view you are looking at is a link you can paste into an issue.

Bind values are masked at capture time and never unmasked. Numeric ids survive on purpose — the
bound id is the evidence that separates an N+1 from a plain duplicate.

## Layout — the evidence behind the verdict

The `cacheable="false"` block table has one column that carries the weight: **Generated?**

- `in play` — the block was generated and *can* be the cause.
- `not generated` — the declaration exists in merged XML but never produced an element, so it
  cannot be why the page was uncacheable.

Both are shown. Hiding the second would look like the panel had missed them; treating it as a cause
would contradict the verdict printed above it.

## Raw — the source

The stored document, exactly as the collector wrote it. Every other panel is an interpretation; this
is where you check one.

- **Copy JSON** — the whole document to the clipboard.
- **Copy Markdown** — verdict, evidence, shadowed files and SQL findings as a Markdown report, ready
  to paste into an issue or a Claude session.

## Comparing two runs

**Compare two** in the ledger footer, then click two rows. Or link straight to it:
`/muon_profiler/compare/index?a=<token>&b=<token>`.

The row worth looking for is **winner moved** — a file that resolved to a different physical copy
between the two runs, meaning the theme resolved differently. It is the hardest thing to notice by
reading either run alone, because the site behaves normally in both.

When nothing moved, the board says so explicitly rather than showing an empty section: you can tell
"nothing moved" from "the diff did not look".

Comparing two *different* URLs is allowed and flagged — a cached render against an uncached one is
one of the most useful comparisons there is.

## Clearing the ring

**Clear runs** in the ledger footer deletes every recorded run, after a confirm. It is the board's
only destructive action.

It clears the runs and **nothing else** — in particular it does not flush Magento's page cache. That
matters: with the cache still warm your next page load is served from it, resolves no files and loads
no theme, so the board looks empty for a reason that has nothing to do with clearing. For a genuine
cold capture:

```bash
bin/magento cache:flush     # then reload the page you want to profile
```

The board says as much on the page straight after clearing, so you do not have to remember it.

## What it will not do

- **It will not flush any Magento cache.** Clearing removes the profiler's own runs; anything beyond
  that is yours to run.
- **It will not appear in production.** Developer mode only, failing closed, with no flag to change
  that.
- **It will not profile itself.** Browsing the board writes no runs, so the ring keeps the pages you
  were actually investigating.

## Screenshots to capture

| Navigation | Suggested filename |
|---|---|
| Board home, Overview panel | `docs/screenshots/board-overview.png` |
| Fallback panel with "Shadowed only" ticked | `docs/screenshots/board-fallback.png` |
| SQL panel showing N+1 findings | `docs/screenshots/board-sql.png` |
| Compare view with a winner change | `docs/screenshots/board-compare.png` |

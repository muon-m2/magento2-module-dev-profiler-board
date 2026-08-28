# Muon_DevProfilerBoard — Developer Guide

For working *on* the board. For working *with* it, see the [user guide](user-guide.md).

## The one rule

**Never build markup outside `Model/Html/Tag.php`.**

A stored run holds the request URL, and the request URL is attacker-controlled — anyone who can
reach the storefront can put a payload in a path, and the profiler records it faithfully. Every
value read from a run therefore reaches the page through one of four methods:

```php
$this->tag->tag('div', ['class' => 'x'], $inner);  // element; attributes escaped
$this->tag->text($run['request']['url']);          // text node
$this->tag->attr($value);                          // attribute value
$this->tag->url($value);                           // href/src, when you need it standalone
```

`tag()`'s third argument is the only input trusted to be markup, and callers only ever pass it the
output of these same methods.

### Do not escape a URL before handing it to `tag()`

`Tag` special-cases `href`, `src` and `action`: they get `escapeUrl()` and nothing else, because
Magento's `escapeUrl()` already applies `escapeHtmlAttr()` internally. Escaping twice produces
`&amp;amp;` between query parameters, which a browser decodes to a literal `&amp;` — so
`?slow=10&nplus1=3` arrives as one parameter named `slow` with the rest glued to its value, and
every control on the board silently stops working while the page still renders correctly.

```php
// right
$this->tag->tag('a', ['href' => $this->urls->link(UrlBuilder::ROUTE_RUN, $query)], 'open');

// wrong — double-escaped
$this->tag->tag('a', ['href' => $this->tag->url($this->urls->link(...))], 'open');
```

`Test/Unit/Model/Html/TagTest::testAUrlAttributeIsEscapedExactlyOnce` guards this.

## Adding a panel

1. Write `Model/Html/YourPanel.php` taking `Tag` and `Widgets`, returning a markup string.
2. Add its key to `RunView::PANELS` — this is also the whitelist for the `panel` query parameter,
   so a value not in the list can never be rendered.
3. Add its body to the `$bodies` map in `RunView::render()`.
   **If your panel has a form, give it a `PANEL` constant and submit that as the `panel` field**, the
   way `FallbackPanel` and `SqlPanel` do. Do not carry `panel` through from request state: tab
   switching is client-side and does not put `panel` in the URL, so on the board's default view the
   value is absent and the submit drops the reader back to Overview. `PanelFormStateTest` holds this.
4. Style it in `assets/board.css` using the existing tokens. Do not introduce a colour: `--v-hit`,
   `--v-miss`, `--v-bad` and `--v-none` mean verdict, `--accent` means interaction, and a fifth hue
   would make the page stop encoding anything.
5. Extend `XssRegressionTest` with your panel, feeding `PAYLOAD` into every field it reads.

`Widgets` covers the shapes you are likely to need — `chip()`, `eyebrow()`, `eyebrowVerbatim()`,
`heading()`, `facts()`, `factsHtml()`, `table()`, `note()`, `lede()` — and escapes everything it is
given.

## Adding a route

Four things, or the board starts profiling itself:

1. `Controller/{Group}/{Action}.php` implementing `HttpGetActionInterface`, returning a
   `BoardResponse` result.
2. A `UrlBuilder::ROUTE_*` constant.
3. **An `excludedActions` item in `etc/frontend/di.xml`** naming
   `muon_profiler_{group}_{action}` in lowercase.
4. `setup:di:compile`.

Miss step 3 and the board records its own page loads, evicting the runs you were inspecting — with
no error anywhere. Review diffs the route list against the DI list item-by-item for this reason.

## Adding an asset

`assets/` holds exactly two files, mapped by logical name in `AssetReader::FILES`. If you add a
third, add it to that map and give it its own controller. **Do not** accept a filename from the
request: the traversal defence here is that no path exists to traverse.

Assets deliberately live outside `view/`, so `setup:static-content:deploy` neither picks them up nor
is needed. Editing `board.css` shows up on the next reload.

## The rules that are not style preferences

**Never add a plugin, preference or interceptor to anything in `Muon_DevProfiler`.** Its constructor
graph is built inside `___callPlugins()` on the static-resource path; generating an interceptor
there re-enters the object manager and resets `PluginList::$_data`, and every page fails with
`Undefined array key "Magento\Framework\App\Http"`. With DI compiled nothing is generated and the
bug is invisible — it only appears after a routine `generated/` clear. Extend by **constructor
argument** instead, as `excludedActions` does.

**Never reimplement an analysis.** `ShadowResolver`, `CacheVerdict`, `QueryAnalyzer` and
`ResolutionSet` live in `Muon_DevProfiler` and are shared with the CLI. A local reimplementation
means the board and `bin/magento muon:profile:show` can report different answers for the same token, and a reader has
no way to tell which is lying. If you need a presentation rule the CLI also needs, put it in the
profiler and consume it from both — that is exactly why `ResolutionSet` exists.

**Never render through a template.** No `.phtml`, no layout XML, no `View\Element\Template`. A
template resolves through the theme fallback chain, which would both couple the board to the active
theme and add the board's own resolutions to the fallback evidence it exists to display.
`DocumentTest` asserts this.

## Working on the JavaScript

`assets/board.js` is plain ES5-compatible JavaScript in an IIFE — no framework, no build step, no
RequireJS. Four behaviours: tab switching, ledger polling, clipboard copy, compare picker.

The ledger markup in `board.js` mirrors `Model/Html/LedgerRail.php`, because the script rebuilds the
same rows from the JSON feed. **A change to a class name or data attribute has to be made in both
places.** `LedgerRailTest` asserts the attributes the script depends on (`data-ledger`, `data-token`,
`data-spine`) are present.

Anything that changes *analysis* belongs on the server: submit a form, change the URL, reload. That
is what keeps every view of the board a link somebody can paste into an issue.

Tab switching is the one exception, and it pays for itself by writing the open panel into the URL
with `history.replaceState` and rewriting the ledger's hrefs to match. Without that, switching a tab
leaves the address bar, the ledger links and every form describing a panel the reader is no longer
looking at. The forms defend themselves too — each states its own panel — so the two layers fail
independently rather than together.

## Running the checks

```bash
vendor/bin/phpunit Test/Unit
docker exec muon-php vendor/bin/phpcs --standard=Magento2 app/code/Muon/DevProfilerBoard
docker exec muon-php vendor/bin/phpmd app/code/Muon/DevProfilerBoard text app/code/Muon/DevProfilerBoard/phpmd.xml
docker exec muon-php vendor/bin/phpstan analyse --level=8 app/code/Muon/DevProfilerBoard
```

PHPStan level 8 must stay clean. PHPCS must stay at **zero errors**; the warning count is the
`Method annotation structure` sniff on private-helper docblocks, accepted deliberately.

One note on docblocks: write generics **without a space** — `array<string,mixed>`, not
`array<string, mixed>`. The Magento2 sniff cannot parse the spaced form and reports every parameter
as missing; the compact form is identical to PHPStan and costs no type information.

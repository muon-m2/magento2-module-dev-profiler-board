<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * The page frame.
 *
 * This class is the reason the board is theme-independent, and the independence is structural
 * rather than stylistic: there is no layout XML to merge, no .phtml to resolve, no block to
 * instantiate and no asset in the static pipeline. Luma, Breeze or Cosmic cannot reach this
 * document because nothing here consults a theme.
 *
 * That is not tidiness. The profiler exists to report which physical file won each theme fallback;
 * a board rendered *through* the fallback chain would add its own resolutions to the evidence it is
 * displaying, and a reader would have no way to tell the page apart from the tool inspecting it.
 *
 * The stylesheet and script come from two routes of this same module rather than from pub/static,
 * so nothing has to be deployed for the board to work — and editing the CSS shows up on reload.
 */
class Document
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\UrlBuilder $urls
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly UrlBuilder $urls
    ) {
    }

    /**
     * A complete HTML document.
     *
     * @param string $title
     * @param string $rail Markup for the ledger column; empty renders a single-column page.
     * @param string $main Markup for the detail column.
     * @param array<string,mixed> $meta runs, store — shown in the top bar.
     * @param array<string,string|int|float> $feedQuery The active filter, from RunFilter::toQuery().
     * @return string
     */
    public function render(
        string $title,
        string $rail,
        string $main,
        array $meta = [],
        array $feedQuery = [],
        string $heading = ''
    ): string {
        return '<!doctype html>'
            . $this->element(
                'html',
                ['lang' => 'en'],
                $this->head($title) . $this->body($rail, $main, $meta, $feedQuery, $heading === '' ? $title : $heading)
            );
    }

    /**
     * A single-column document for when there is nothing to show.
     *
     * @param string $title
     * @param string $message
     * @param string $hint
     * @param array<string,mixed> $meta
     * @param string|null $onward Label for a link back to the board, so the page is never a dead end.
     * @return string
     */
    public function notice(
        string $title,
        string $message,
        string $hint = '',
        array $meta = [],
        ?string $onward = null
    ): string {
        $body = $this->element('h1', [], $this->tag->text($title))
            . $this->element('p', ['class' => 'lede'], $this->tag->text($message));

        if ($hint !== '') {
            $body .= $this->element('p', ['class' => 'note'], $this->tag->text($hint));
        }

        if ($onward !== null) {
            $body .= $this->element('div', ['class' => 'notice-actions'], $this->element(
                'a',
                ['class' => 'btn primary', 'href' => $this->urls->link(UrlBuilder::ROUTE_INDEX)],
                $this->tag->text($onward)
            ));
        }

        return $this->render($title, '', $this->element('div', ['class' => 'panel notice-page'], $body), $meta);
    }

    /**
     * @param string $title
     * @return string
     */
    private function head(string $title): string
    {
        // charset first, ahead of anything that could carry a non-ASCII byte.
        return $this->element(
            'head',
            [],
            $this->element('meta', ['charset' => 'utf-8'])
            . $this->element('meta', ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1'])
            . $this->element('meta', ['name' => 'robots', 'content' => 'noindex, nofollow'])
            . $this->element('title', [], $this->tag->text($title))
            . $this->element('link', [
                'rel' => 'stylesheet',
                'href' => $this->urls->link(UrlBuilder::ROUTE_ASSET_CSS),
            ])
            . $this->element('script', [
                'defer' => true,
                'src' => $this->urls->link(UrlBuilder::ROUTE_ASSET_JS),
            ], '')
        );
    }

    /**
     * @param string $rail
     * @param string $main
     * @param array<string,mixed> $meta
     * @param array<string,string|int|float> $feedQuery
     * @param string $heading Rendered as the page's h1; defaults to the document title.
     * @return string
     */
    private function body(
        string $rail,
        string $main,
        array $meta,
        array $feedQuery = [],
        string $heading = ''
    ): string {
        // Every panel heading is an h2 under this one. Without it the outline started at h2 with
        // nothing above it, so a screen-reader user navigating by heading had no page-level anchor
        // — on a document that is otherwise a long list of sibling sections.
        $h1 = $heading === ''
            ? ''
            : $this->element('h1', ['class' => 'sr-only'], $this->tag->text($heading));

        $columns = $this->element('main', ['class' => 'main'], $h1 . $main);

        if ($rail !== '') {
            $columns = $this->element('nav', ['class' => 'rail', 'aria-label' => 'Recorded runs'], $rail) . $columns;
        }

        return $this->element(
            'body',
            [
                // The filter travels with the feed URL, so the poll asks for the slice the page
                // was rendered with. Reassembling it in JavaScript instead means two lists of
                // parameter names that must agree; the one that drifted omitted `url`, and the
                // ledger repopulated itself with the rows the reader had just filtered out.
                'data-feed' => $this->urls->link(UrlBuilder::ROUTE_FEED, $feedQuery),
                'class' => $rail === '' ? 'single' : null,
            ],
            $this->topbar($meta) . $this->element('div', ['class' => 'shell'], $columns)
        );
    }

    /**
     * @param array<string,mixed> $meta
     * @return string
     */
    private function topbar(array $meta): string
    {
        $store = (string)($meta['store'] ?? '');
        $eyebrow = $store === '' ? 'developer mode' : 'developer mode · ' . $store;

        $count = $this->tag->text($this->ringLabel($meta));

        $live = $this->element(
            'span',
            ['class' => 'live'],
            $this->element('span', ['class' => 'dot', 'data-live' => 'idle'])
            . $this->element('span', ['data-run-count' => 'true'], $count)
        );

        return $this->element(
            'header',
            ['class' => 'topbar'],
            $this->element(
                'a',
                ['class' => 'brand', 'href' => $this->urls->link(UrlBuilder::ROUTE_INDEX)],
                'MUON ' . $this->element('span', [], 'PROFILER')
            )
            . $this->element('span', ['class' => 'eyebrow'], $this->tag->text($eyebrow))
            . $live
        );
    }

    /**
     * How many runs are listed, and how many the ring actually holds.
     *
     * These are different numbers whenever the ledger's feedLimit is below the profiler's ring size
     * — 25 against 50 by default — and this counter used to print only the first while labelling it
     * "in ring". Once 25 runs had accumulated it read "25 in ring" forever: a constant wearing the
     * costume of a measurement, on a page whose other panels go out of their way to say "N of M"
     * rather than let a capped list read as a complete one.
     *
     * @param array<string,mixed> $meta
     * @return string
     */
    private function ringLabel(array $meta): string
    {
        $total = (int)($meta['runs'] ?? 0);
        $shown = (int)($meta['shown'] ?? $total);

        if ($total === 0) {
            return 'no runs yet';
        }

        // With a filter on, "of 50 in ring" would answer a question nobody asked. What matters is
        // how many matched, and that the ring is bigger than the match — otherwise a short ledger
        // reads as a quiet ring.
        if (!empty($meta['filtered'])) {
            $matching = (int)($meta['matching'] ?? $shown);

            return $shown < $matching
                ? sprintf('%d of %d matching · %d in ring', $shown, $matching, $total)
                : sprintf('%d matching · %d in ring', $matching, $total);
        }

        return $shown < $total
            ? sprintf('%d of %d in ring', $shown, $total)
            : sprintf('%d in ring', $total);
    }

    /**
     * Shorthand for the tag builder, so this class reads as structure rather than plumbing.
     *
     * @param string $name
     * @param array<string,string|int|float|bool|null> $attributes
     * @param string|null $innerHtml
     * @return string
     */
    private function element(string $name, array $attributes = [], ?string $innerHtml = null): string
    {
        return $this->tag->tag($name, $attributes, $innerHtml);
    }
}

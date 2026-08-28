<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * Assembles one run page: verdict, evidence strip, tab strip, panels.
 *
 * Every panel is rendered into the document, and the tab strip switches between them in the
 * browser. That is a deliberate trade — a slightly larger page in exchange for instant switching
 * and, more importantly, a page whose whole contents are in the source. Anything that changes the
 * *analysis* (a threshold, a filter) goes back to the server and changes the URL instead, so a link
 * always describes what is on screen.
 */
class RunView
{
    /**
     * Panels, in tab order. The keys are the only values `panel` may take.
     */
    private const PANELS = ['overview', 'fallback', 'sql', 'layout', 'raw'];

    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\UrlBuilder $urls
     * @param \Muon\DevProfilerBoard\Model\Html\VerdictBanner $banner
     * @param \Muon\DevProfilerBoard\Model\Html\OverviewPanel $overview
     * @param \Muon\DevProfilerBoard\Model\Html\FallbackPanel $fallback
     * @param \Muon\DevProfilerBoard\Model\Html\SqlPanel $sql
     * @param \Muon\DevProfilerBoard\Model\Html\LayoutPanel $layout
     * @param \Muon\DevProfilerBoard\Model\Html\RawPanel $raw
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly UrlBuilder $urls,
        private readonly VerdictBanner $banner,
        private readonly OverviewPanel $overview,
        private readonly FallbackPanel $fallback,
        private readonly SqlPanel $sql,
        private readonly LayoutPanel $layout,
        private readonly RawPanel $raw
    ) {
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $analysis From RunAnalysis::analyse().
     * @param array<string,mixed> $state Query state: token, panel, thresholds, filters.
     * @return string
     */
    public function render(array $run, array $analysis, array $state): string
    {
        $token = (string)($run['token'] ?? '');
        $action = $this->urls->link(UrlBuilder::ROUTE_RUN);
        $active = $this->activePanel($state);

        $bodies = [
            'overview' => $this->overview->render($run),
            'fallback' => $this->fallback->render($this->fallbackEntries($analysis), $state, $action),
            'sql' => $this->sql->render($this->section($analysis, 'sql'), $state, $action),
            'layout' => $this->layout->render($run, $this->section($analysis, 'verdict')),
            'raw' => $this->raw->render(
                $run,
                $this->urls->link(UrlBuilder::ROUTE_RUN_JSON, ['token' => $token]),
                $this->urls->link(UrlBuilder::ROUTE_RUN_MARKDOWN, ['token' => $token])
            ),
        ];

        return $this->banner->render($run, $this->section($analysis, 'verdict'), $this->totals($analysis))
            . $this->tabs($analysis, $active, $token, $state)
            . $this->panels($bodies, $active);
    }

    /**
     * @param array<string,mixed> $analysis
     * @param string $active
     * @param string $token
     * @param array<string,string|int|float|null> $state Analysis state carried into each tab link.
     * @return string
     */
    private function tabs(array $analysis, string $active, string $token, array $state = []): string
    {
        $totals = $this->totals($analysis);

        $counts = [
            'fallback' => (int)($totals['fallbacks'] ?? 0),
            'sql' => (int)($this->section($analysis, 'sql')['findings'] ?? 0),
        ];

        $markup = '';

        foreach (self::PANELS as $panel) {
            $label = $panel === 'sql' ? 'SQL' : ucfirst($panel);
            $count = $counts[$panel] ?? null;

            $inner = $this->tag->text($label);

            if ($count !== null && $count > 0) {
                $inner .= ' ' . $this->tag->tag('span', ['class' => 'count'], $this->tag->text($count));
            }

            // A link, not a button. Panel switching lives in board.js, and the inactive panels
            // carry the real `hidden` attribute — so with JavaScript off there was no in-page
            // control that could reach Layout or Raw at all; only hand-editing ?panel= worked.
            // board.js intercepts the click and keeps the tab behaviour, exactly as the Compare
            // tab-link below already worked.
            $markup .= $this->tag->tag('a', [
                'class' => 'tab',
                'id' => 't-' . $panel,
                'role' => 'tab',
                'href' => $this->urls->run($token, ['panel' => $panel] + $state),
                'aria-selected' => $panel === $active ? 'true' : 'false',
                'aria-controls' => 'p-' . $panel,
            ], $inner);
        }

        // Compare is a page, not a panel — it needs a second run — so it is a link that carries this
        // run in as side A.
        $markup .= $this->tag->tag('a', [
            'class' => 'tab tab-link',
            'href' => $this->urls->link(UrlBuilder::ROUTE_COMPARE, ['a' => $token]),
        ], 'Compare →');

        return $this->tag->tag('div', ['class' => 'tabs', 'role' => 'tablist'], $markup);
    }

    /**
     * @param array<string,string> $bodies
     * @param string $active
     * @return string
     */
    private function panels(array $bodies, string $active): string
    {
        $markup = '';

        foreach (self::PANELS as $panel) {
            $markup .= $this->tag->tag('section', [
                'class' => 'panel',
                'id' => 'p-' . $panel,
                'role' => 'tabpanel',
                'aria-labelledby' => 't-' . $panel,
                'hidden' => $panel !== $active,
            ], $bodies[$panel] ?? '');
        }

        return $markup;
    }

    /**
     * The requested panel, or the first one. Never a value from the query string unchecked.
     *
     * @param array<string,mixed> $state
     * @return string
     */
    private function activePanel(array $state): string
    {
        $panel = $state['panel'] ?? null;

        return is_string($panel) && in_array($panel, self::PANELS, true) ? $panel : self::PANELS[0];
    }

    /**
     * @param array<string,mixed> $analysis
     * @return list<array<string,mixed>>
     */
    private function fallbackEntries(array $analysis): array
    {
        $entries = $analysis['fallback'] ?? [];

        if (!is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,int>
     */
    private function totals(array $analysis): array
    {
        $totals = $analysis['totals'] ?? [];

        if (!is_array($totals)) {
            return [];
        }

        return array_map('intval', array_filter($totals, 'is_scalar'));
    }

    /**
     * @param array<string,mixed> $analysis
     * @param string $key
     * @return array<string,mixed>
     */
    private function section(array $analysis, string $key): array
    {
        $section = $analysis[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}

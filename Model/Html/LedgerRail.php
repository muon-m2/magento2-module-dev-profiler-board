<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfilerBoard\Model\Run\RunFilter;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfiler\Model\Analysis\CacheVerdict;

/**
 * The run ledger down the left edge.
 *
 * Rows are ordered newest first and carry a verdict-coloured spine, so severity is scannable down
 * the column without reading a word. That is the one visual device the board repeats — on rows, on
 * findings, on panels — and it encodes the same fact twice, by position and by colour.
 *
 * The markup here is also the contract the auto-refresh script renders against: the script rebuilds
 * these rows from the JSON feed, so any change to a class name or data attribute has to be made in
 * both places.
 */
class LedgerRail
{
    /**
     * Verdict status to the spine/chip modifier that carries its colour.
     */
    private const VERDICT_CLASS = [
        CacheVerdict::HIT => 'v-hit',
        CacheVerdict::MISS => 'v-miss',
        CacheVerdict::UNCACHEABLE => 'v-bad',
        CacheVerdict::UNKNOWN => 'v-none',
        CacheVerdict::NOT_APPLICABLE => 'v-none',
    ];

    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\Widgets $ui
     * @param \Muon\DevProfilerBoard\Model\Url\UrlBuilder $urls
     * @param \Muon\DevProfilerBoard\Model\Html\FilterPanel $filters
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly Widgets $ui,
        private readonly UrlBuilder $urls,
        private readonly FilterPanel $filters
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows From RunSelector::feed().
     * @param string|null $selected Token of the run currently open.
     * @param array<string,string|int|float|null> $state Analysis state to carry into each link.
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter|null $filter Active filter, for the chip row.
     * @param int $matching How many runs match across the whole ring, not just this page.
     * @param int $total How many runs the ring holds.
     * @param string $formKey For the Clear form. Passed in rather than injected: FormKey is
     *        session-backed, and materialising it — which starts a session — is a side effect a
     *        markup builder has no business causing.
     * @return string
     */
    public function render(
        array $rows,
        ?string $selected = null,
        array $state = [],
        ?RunFilter $filter = null,
        int $matching = 0,
        int $total = 0,
        string $formKey = ''
    ): string {
        $head = $this->tag->tag(
            'div',
            ['class' => 'rail-head'],
            $this->tag->tag('span', ['class' => 'eyebrow'], 'Ledger')
            . $this->tag->tag('span', ['class' => 'eyebrow rail-head-note'], 'newest first')
        );

        $items = '';

        foreach ($rows as $row) {
            $items .= $this->tag->tag('li', [], $this->row($row, $selected, $state));
        }

        if ($items === '') {
            $items = $this->tag->tag(
                'li',
                ['class' => 'rail-empty'],
                $this->tag->tag('p', ['class' => 'note'], $this->tag->text(
                    $filter !== null && $filter->isActive()
                        // Two very different situations that look identical: an empty ring, and a
                        // full ring none of which matches. Saying the wrong one sends the reader to
                        // reload a page when they should be widening a filter.
                        ? 'No run matches this filter. Widen it, or clear it to see the whole ring.'
                        : 'No runs recorded yet. Load a storefront page, then refresh.'
                ))
            );
        }

        return $head
            . ($filter === null ? '' : $this->filters->render($filter, $matching, $total))
            . $this->tag->tag('ul', ['class' => 'rail-list', 'data-ledger' => 'true'], $items)
            . $this->foot($formKey);
    }

    /**
     * One ledger row.
     *
     * @param array<string,mixed> $row
     * @param string|null $selected
     * @param array<string,string|int|float|null> $state
     * @return string
     */
    private function row(array $row, ?string $selected, array $state): string
    {
        $token = (string)($row['token'] ?? '');
        $verdict = (string)($row['verdict'] ?? CacheVerdict::UNKNOWN);
        $isSelected = $selected !== null && $token === $selected;

        return $this->tag->tag(
            'a',
            [
                'class' => 'run',
                'href' => $this->urls->run($token, $state),
                'data-token' => $token,
                'data-spine' => $this->verdictClass($verdict),
                'aria-current' => $isSelected ? 'true' : null,
            ],
            $this->chips($row, $verdict)
            . $this->tag->tag('span', ['class' => 'run-path'], $this->tag->text($row['url'] ?? ''))
            . $this->tag->tag('span', ['class' => 'run-foot num'], $this->tag->text($this->summary($row)))
        );
    }

    /**
     * Method, status, verdict and kind, as chips.
     *
     * @param array<string,mixed> $row
     * @param string $verdict
     * @return string
     */
    private function chips(array $row, string $verdict): string
    {
        $chips = $this->ui->chip($row['method'] ?? '')
            . $this->ui->chip($row['status'] ?? '—')
            . $this->ui->chip($verdict, $this->verdictClass($verdict));

        // "page" is the default and adds nothing; the exceptions are what a reader is scanning for.
        $kind = (string)($row['kind'] ?? 'page');

        if (!empty($row['is_ajax'])) {
            $chips .= $this->ui->chip('ajax');
        } elseif ($kind !== 'page') {
            $chips .= $this->ui->chip($kind);
        }

        return $this->tag->tag('span', ['class' => 'run-top'], $chips);
    }

    /**
     * The numeric line under each row.
     *
     * @param array<string,mixed> $row
     * @return string
     */
    private function summary(array $row): string
    {
        $parts = [
            sprintf('%.1f ms', (float)($row['duration_ms'] ?? 0)),
            sprintf('%d stmt', (int)($row['statements'] ?? 0)),
            (string)($row['token'] ?? ''),
        ];

        return implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return string
     */
    private function foot(string $formKey): string
    {
        return $this->tag->tag(
            'div',
            ['class' => 'rail-foot'],
            $this->tag->tag('button', [
                'class' => 'btn',
                'type' => 'button',
                'data-compare-toggle' => 'true',
                // Shipped from the server, like the Pause live button beside it. board.js flips it,
                // but a toggle that only gains its state once JavaScript has run announces nothing
                // to a reader who arrives before that.
                'aria-pressed' => 'false',
            ], 'Compare two')
            . $this->tag->tag('button', [
                'class' => 'btn',
                'type' => 'button',
                'data-live-toggle' => 'true',
                'aria-pressed' => 'false',
            ], 'Pause live')
            . $this->clearForm($formKey)
        );
    }

    /**
     * The board's only mutation, and the only form on it that is not a GET.
     *
     * A real form rather than a scripted fetch, so it carries the form key Magento validates and
     * still works with JavaScript disabled. The confirm is attached by the script; without it the
     * button simply submits, which is the correct fallback for an action whose worst outcome is
     * losing throwaway profiling data.
     *
     * @return string
     */
    private function clearForm(string $formKey): string
    {
        return $this->tag->tag(
            'form',
            [
                'class' => 'rail-clear',
                'method' => 'post',
                'action' => $this->urls->link(UrlBuilder::ROUTE_CLEAR),
                'data-confirm' => 'Delete every recorded run? This cannot be undone.',
            ],
            $this->tag->tag('input', [
                'type' => 'hidden',
                'name' => 'form_key',
                'value' => $formKey,
            ])
            . $this->tag->tag('button', ['class' => 'btn btn-danger', 'type' => 'submit'], 'Clear runs')
        );
    }

    /**
     * @param string $verdict
     * @return string
     */
    private function verdictClass(string $verdict): string
    {
        return self::VERDICT_CLASS[$verdict] ?? 'v-none';
    }
}

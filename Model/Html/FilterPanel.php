<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfilerBoard\Model\Run\RunFilter;

/**
 * The ledger's filter controls.
 *
 * Collapsed by default — the rail is 288px and its job is a glanceable list, not a search form — but
 * the toggle carries a count when anything is set. A ledger that is quietly hiding rows is worse
 * than one that is obviously doing so, and "why is my run not here" is a question this panel exists
 * to stop a reader ever having to ask.
 *
 * A GET form, so a filtered ledger is a link. Everything else on the board works that way and the
 * ledger should not be the exception.
 */
class FilterPanel
{
    /**
     * Verdicts offered as toggles, in the order the ledger's spine colours run.
     */
    private const VERDICTS = [
        CacheVerdict::HIT => 'v-hit',
        CacheVerdict::MISS => 'v-miss',
        CacheVerdict::UNCACHEABLE => 'v-bad',
        CacheVerdict::UNKNOWN => 'v-none',
        CacheVerdict::NOT_APPLICABLE => 'v-none',
    ];

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
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter $filter
     * @param int $matching How many runs in the ring match.
     * @param int $total How many the ring holds.
     * @return string
     */
    public function render(RunFilter $filter, int $matching, int $total): string
    {
        $active = $filter->count();

        return $this->tag->tag(
            'div',
            ['class' => 'rail-filter', 'data-filter-panel' => 'true'],
            $this->toggle($active)
            . $this->tag->tag(
                'form',
                [
                    'class' => 'filter-form',
                    'method' => 'get',
                    'action' => $this->urls->link(UrlBuilder::ROUTE_INDEX),
                    'hidden' => $active === 0,
                    'data-filter-form' => 'true',
                ],
                $this->urlNeedle($filter)
                . $this->verdictToggles($filter)
                . $this->methodAndStatus($filter)
                . $this->range('Time (ms)', 'min_ms', 'max_ms', $filter->minMs, $filter->maxMs)
                . $this->range('Statements', 'min_stmt', 'max_stmt', $filter->minStatements, $filter->maxStatements)
                . $this->actions($active)
            )
            . ($active > 0 ? $this->summary($matching, $total) : '')
        );
    }

    /**
     * @param int $active
     * @return string
     */
    private function toggle(int $active): string
    {
        $label = 'Filter';

        if ($active > 0) {
            $label .= ' · ' . $active;
        }

        return $this->tag->tag('button', [
            'class' => $active > 0 ? 'btn filter-toggle is-active' : 'btn filter-toggle',
            'type' => 'button',
            'data-filter-toggle' => 'true',
            'aria-expanded' => $active > 0 ? 'true' : 'false',
        ], $this->tag->text($label));
    }

    /**
     * The URL needle, first and full width — it is what identifies a run, so it is what a reader
     * reaches for first.
     *
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter $filter
     * @return string
     */
    private function urlNeedle(RunFilter $filter): string
    {
        return $this->field('URL contains', $this->tag->tag('input', [
            'id' => 'filter-url',
            'type' => 'text',
            'name' => 'url',
            'class' => 'filter-url',
            'placeholder' => '/en-us/gear',
            'value' => $filter->url,
            'autocomplete' => 'off',
        ]), 'filter-url');
    }

    /**
     * Verdicts as checkboxes styled like the chips they filter, so the control looks like the thing
     * it acts on.
     *
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter $filter
     * @return string
     */
    private function verdictToggles(RunFilter $filter): string
    {
        $boxes = '';

        foreach (self::VERDICTS as $verdict => $modifier) {
            $checked = in_array($verdict, $filter->verdicts, true);

            $boxes .= $this->tag->tag(
                'label',
                ['class' => $checked ? 'verdict-pick is-on ' . $modifier : 'verdict-pick ' . $modifier],
                $this->tag->tag('input', [
                    'type' => 'checkbox',
                    'name' => 'verdict[]',
                    'value' => $verdict,
                    'checked' => $checked,
                ]) . $this->tag->text($verdict)
            );
        }

        return $this->field('Verdict', $this->tag->tag('div', ['class' => 'verdict-picks'], $boxes));
    }

    /**
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter $filter
     * @return string
     */
    private function methodAndStatus(RunFilter $filter): string
    {
        $methods = $this->tag->tag('option', ['value' => ''], 'any');

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            $methods .= $this->tag->tag(
                'option',
                ['value' => $method, 'selected' => $filter->method === $method],
                $this->tag->text($method)
            );
        }

        return $this->tag->tag(
            'div',
            ['class' => 'filter-row'],
            $this->field(
                'Method',
                $this->tag->tag('select', ['id' => 'filter-method', 'name' => 'method'], $methods),
                'filter-method'
            )
            . $this->field('Status', $this->tag->tag('input', [
                'id' => 'filter-status',
                'type' => 'number',
                'name' => 'status',
                'class' => 'num',
                'placeholder' => 'any',
                'min' => '100',
                'max' => '599',
                'value' => $filter->status,
            ]), 'filter-status')
        );
    }

    /**
     * @param string $label
     * @param string $minName
     * @param string $maxName
     * @param float|int|null $min
     * @param float|int|null $max
     * @return string
     */
    private function range(string $label, string $minName, string $maxName, float|int|null $min, float|int|null $max): string
    {
        // Two controls under one visible label. `for` can only point at one of them, so each
        // carries its own accessible name — otherwise a screen reader reads both as the same field
        // and the reader cannot tell which end of the range they are in.
        $input = static fn (string $name, string $placeholder, float|int|null $value, string $aria): array => [
            'id' => 'filter-' . $name,
            'type' => 'number',
            'name' => $name,
            'class' => 'num',
            'min' => '0',
            'step' => 'any',
            'placeholder' => $placeholder,
            'aria-label' => $aria,
            'value' => $value,
        ];

        return $this->field($label, $this->tag->tag(
            'div',
            ['class' => 'filter-range'],
            $this->tag->tag('input', $input($minName, 'min', $min, 'Minimum ' . $label))
            . $this->tag->tag('span', ['class' => 'filter-dash'], '–')
            . $this->tag->tag('input', $input($maxName, 'max', $max, 'Maximum ' . $label))
        ), 'filter-' . $minName);
    }

    /**
     * @param int $active
     * @return string
     */
    private function actions(int $active): string
    {
        $buttons = $this->tag->tag('button', ['class' => 'btn primary', 'type' => 'submit'], 'Apply');

        if ($active > 0) {
            // A plain link, not a reset button: clearing a filter is navigation back to the
            // unfiltered ledger, and it must survive JavaScript being off.
            $buttons .= $this->tag->tag(
                'a',
                ['class' => 'btn', 'href' => $this->urls->link(UrlBuilder::ROUTE_INDEX)],
                'Clear'
            );
        }

        return $this->tag->tag('div', ['class' => 'filter-actions'], $buttons);
    }

    /**
     * What the filter is hiding, stated rather than left to be inferred from a short list.
     *
     * @param int $matching
     * @param int $total
     * @return string
     */
    private function summary(int $matching, int $total): string
    {
        return $this->tag->tag(
            'p',
            ['class' => 'filter-summary'],
            $this->tag->text(sprintf(
                $matching === 0
                    ? 'No run in the ring matches — %d of %d.'
                    : '%d of %d runs match.',
                $matching,
                $total
            ))
        );
    }

    /**
     * @param string $label
     * @param string $control
     * @return string
     */
    private function field(string $label, string $control, ?string $for = null): string
    {
        // Without `for`, seven controls in this form had no accessible name at all — a screen
        // reader announced "edit text, blank". SqlPanel and ComparePanel already wire for/id;
        // this panel was the one that did not.
        $attributes = $for === null ? [] : ['for' => $for];

        return $this->tag->tag(
            'div',
            ['class' => 'field'],
            $this->tag->tag('label', $attributes, $this->tag->text($label)) . $control
        );
    }
}

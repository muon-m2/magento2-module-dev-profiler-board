<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;

/**
 * Statement shapes, findings first.
 *
 * Thresholds are a control rather than a setting: the collector stored groups, and nothing decided
 * "this is an N+1" until this page asked. Moving a threshold re-examines a capture taken an hour
 * ago without reloading the page it describes — and because the thresholds live in the query
 * string, the resulting view is a link somebody can paste into an issue.
 *
 * Each finding shows its **basis**, not just its verdict. "Statement text differed between
 * executions" is an observation; "bound arguments present" is an inference. A reader deciding
 * whether to add a cache needs to know which one they are looking at, because acting on the second
 * as though it were the first is how a wrong cache gets written.
 */
class SqlPanel
{
    /**
     * This panel's key in RunView::PANELS — the value its own form submits back.
     */
    private const PANEL = 'sql';

    /**
     * Finding kind to its severity colour and label.
     */
    private const FINDING = [
        QueryAnalyzer::N_PLUS_ONE => ['v-bad', 'n+1'],
        QueryAnalyzer::DUPLICATE => ['v-miss', 'duplicate'],
        QueryAnalyzer::SLOW => ['v-miss', 'slow'],
    ];

    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\Widgets $ui
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly Widgets $ui
    ) {
    }

    /**
     * @param array<string,mixed> $analysis From QueryAnalyzer::classify().
     * @param array<string,mixed> $state Carried query state, including the thresholds in force.
     * @param string $formAction
     * @return string
     */
    public function render(array $analysis, array $state, string $formAction): string
    {
        $groups = $this->groups($analysis);

        if ($groups === []) {
            return $this->controls($state, $formAction)
                . $this->ui->note(
                    'No statements were recorded. A full-page-cache hit runs none, and a static '
                    . 'asset request runs none either.'
                );
        }

        return $this->controls($state, $formAction)
            . $this->ui->lede(sprintf(
                '%d statements in %d shapes, %.1f ms total. %d findings at the thresholds below.',
                (int)($analysis['statements'] ?? 0),
                (int)($analysis['shapes'] ?? 0),
                (float)($analysis['total_ms'] ?? 0),
                (int)($analysis['findings'] ?? 0)
            ))
            . $this->findings($groups)
            . $this->ui->heading('All shapes', sprintf('%d', count($groups)))
            . $this->table($groups);
    }

    /**
     * @param array<string,mixed> $state
     * @param string $formAction
     * @return string
     */
    private function controls(array $state, string $formAction): string
    {
        $duplicate = $state['duplicate'] ?? QueryAnalyzer::DEFAULT_DUPLICATE;

        $fields = $this->field('t-n', 'nplus1', 'N+1 at ≥', $state['nplus1'] ?? QueryAnalyzer::DEFAULT_NPLUS1)
            . $this->field('t-d', 'duplicate', 'Duplicate at ≥', $duplicate)
            . $this->field('t-s', 'slow', 'Slow over (ms)', $state['slow'] ?? QueryAnalyzer::DEFAULT_SLOW_MS);

        return $this->tag->tag(
            'form',
            ['class' => 'controls', 'method' => 'get', 'action' => $formAction],
            $this->hidden($state)
            . $fields
            . $this->tag->tag('button', ['class' => 'btn primary', 'type' => 'submit'], 'Re-analyse')
        );
    }

    /**
     * @param string $id
     * @param string $name
     * @param string $label
     * @param mixed $value
     * @return string
     */
    private function field(string $id, string $name, string $label, mixed $value): string
    {
        return $this->tag->tag(
            'div',
            ['class' => 'field'],
            $this->tag->tag('label', ['for' => $id], $this->tag->text($label))
            . $this->tag->tag('input', [
                'id' => $id,
                'class' => 'num',
                'type' => 'number',
                'name' => $name,
                'min' => '1',
                'step' => 'any',
                'value' => is_scalar($value) ? (string)$value : null,
            ])
        );
    }

    /**
     * Carry the run and the fallback filters through a threshold submit.
     *
     * @param array<string,mixed> $state
     * @return string
     */
    private function hidden(array $state): string
    {
        // Stated as a constant, not carried from the query string — see FallbackPanel::hidden().
        $markup = $this->tag->tag('input', ['type' => 'hidden', 'name' => 'panel', 'value' => self::PANEL]);

        foreach (['token', 'fallback', 'shadowed'] as $key) {
            if (($state[$key] ?? null) === null || $state[$key] === '') {
                continue;
            }

            $markup .= $this->tag->tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $state[$key]]);
        }

        return $markup;
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return string
     */
    private function findings(array $groups): string
    {
        $markup = '';

        foreach ($groups as $group) {
            $finding = $group['finding'] ?? null;

            if (!is_array($finding)) {
                continue;
            }

            $markup .= $this->finding($group, $finding);
        }

        return $markup === ''
            ? $this->ui->note('No shape crossed a threshold. Lower them to look harder.')
            : $markup;
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $finding
     * @return string
     */
    private function finding(array $group, array $finding): string
    {
        $kind = (string)($finding['kind'] ?? '');
        [$modifier, $label] = self::FINDING[$kind] ?? ['v-none', $kind];

        $head = $this->tag->tag(
            'div',
            ['class' => 'finding-head'],
            $this->ui->chip($label, $modifier)
            . $this->tag->tag('b', ['class' => 'num'], $this->tag->text('×' . (int)($group['count'] ?? 0)))
            . $this->tag->tag('span', ['class' => 'why'], $this->tag->text($finding['basis'] ?? ''))
        );

        return $this->tag->tag(
            'div',
            ['class' => 'finding', 'data-spine' => $modifier],
            $head
            . $this->tag->tag('div', ['class' => 'sql'], $this->tag->text($group['sample'] ?? ''))
            . $this->foot($group, $finding)
        );
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $finding
     * @return string
     */
    private function foot(array $group, array $finding): string
    {
        $parts = [
            (string)($group['origin'] ?? 'origin not captured'),
            sprintf('%.1f ms total', (float)($group['total_ms'] ?? 0)),
            sprintf('%.1f ms max', (float)($group['max_ms'] ?? 0)),
        ];

        $saving = (float)($finding['saving_ms'] ?? 0);

        if ($saving > 0) {
            $parts[] = sprintf('up to %.1f ms recoverable', $saving);
        }

        $binds = $this->binds($group);

        if ($binds !== '') {
            $parts[] = 'binds ' . $binds;
        }

        $markup = '';

        foreach ($parts as $part) {
            $markup .= $this->tag->tag('span', [], $this->tag->text($part));
        }

        return $this->tag->tag('div', ['class' => 'finding-foot'], $markup);
    }

    /**
     * Sample bind values, exactly as stored.
     *
     * They were masked at capture time by the collector's ValueMasker and are never unmasked here.
     * Numeric ids survive masking on purpose: the bound id is the evidence that separates an N+1
     * from a plain duplicate, so hiding it would make the finding undiagnosable.
     *
     * @param array<string,mixed> $group
     * @return string
     */
    private function binds(array $group): string
    {
        $binds = $group['binds'] ?? null;

        if (!is_array($binds) || $binds === []) {
            return '';
        }

        $pairs = [];

        foreach ($binds as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $pairs[] = is_string($key) ? $key . '=' . $value : (string)$value;
        }

        return implode(', ', array_slice($pairs, 0, 6));
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return string
     */
    private function table(array $groups): string
    {
        $rows = [];

        foreach ($groups as $group) {
            $finding = $group['finding'] ?? null;
            $kind = is_array($finding) ? (string)($finding['kind'] ?? '') : '';
            [$modifier, $label] = self::FINDING[$kind] ?? [null, ''];

            $rows[] = [
                $label === '' ? '' : $this->ui->chip($label, $modifier),
                $this->tag->text($group['sample'] ?? ''),
                $this->tag->text((int)($group['count'] ?? 0)),
                $this->tag->text(sprintf('%.1f', (float)($group['total_ms'] ?? 0))),
                $this->tag->text(sprintf('%.1f', (float)($group['max_ms'] ?? 0))),
                $this->tag->text($group['origin'] ?? '—'),
            ];
        }

        return $this->ui->table(
            ['', 'Statement shape', '×', 'Total ms', 'Max ms', 'Origin'],
            $rows,
            [2, 3, 4],
            'Recorded statement shapes'
        );
    }

    /**
     * @param array<string,mixed> $analysis
     * @return list<array<string,mixed>>
     */
    private function groups(array $analysis): array
    {
        $groups = $analysis['groups'] ?? [];

        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, 'is_array'));
    }
}

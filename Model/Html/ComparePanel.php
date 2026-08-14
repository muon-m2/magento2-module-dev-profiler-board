<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * Two runs, side by side, with what moved between them.
 *
 * Comparing two different URLs is allowed and flagged rather than refused: the most useful
 * comparison on this board is a cached render against an uncached one, and a strict same-URL rule
 * would also block comparing a category page against the product page it links to. The mismatch is
 * stated so a reader cannot mistake one for the other.
 */
class ComparePanel
{
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
     * @param array<string,mixed> $diff From RunDiff::compare().
     * @param string $formAction
     * @return string
     */
    public function render(array $diff, string $formAction): string
    {
        $sides = $this->section($diff, 'sides');

        return $this->controls($sides, $formAction)
            . ($diff['same_url'] ?? true ? '' : $this->ui->note(
                'These runs are of different URLs. The comparison is still valid, but a difference '
                . 'below may be the difference between two pages rather than between two loads.'
            ))
            . $this->summary($sides)
            . $this->ui->heading('What changed')
            . $this->changes($diff);
    }

    /**
     * @param array<string,mixed> $sides
     * @param string $formAction
     * @return string
     */
    private function controls(array $sides, string $formAction): string
    {
        $a = $this->section($sides, 'a');
        $b = $this->section($sides, 'b');

        return $this->tag->tag(
            'form',
            ['class' => 'controls', 'method' => 'get', 'action' => $formAction],
            $this->field('c-a', 'a', 'Run A', $a['token'] ?? '')
            . $this->field('c-b', 'b', 'Run B', $b['token'] ?? '')
            . $this->tag->tag('button', ['class' => 'btn primary', 'type' => 'submit'], 'Compare')
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
                'type' => 'text',
                'name' => $name,
                'value' => is_scalar($value) ? (string)$value : null,
            ])
        );
    }

    /**
     * @param array<string,mixed> $sides
     * @return string
     */
    private function summary(array $sides): string
    {
        return $this->tag->tag(
            'div',
            ['class' => 'cmp'],
            $this->column('A', $this->section($sides, 'a'))
            . $this->column('B', $this->section($sides, 'b'))
        );
    }

    /**
     * @param string $letter
     * @param array<string,mixed> $side
     * @return string
     */
    private function column(string $letter, array $side): string
    {
        $head = $this->tag->tag(
            'div',
            ['class' => 'cmp-head'],
            $this->ui->eyebrow($letter . ' · ' . (string)($side['token'] ?? '—'))
            . $this->tag->tag('div', [], $this->tag->text(
                trim((string)($side['method'] ?? '') . ' ' . (string)($side['url'] ?? '')) ?: '—'
            ))
        );

        return $this->tag->tag('div', [], $head . $this->ui->factsHtml([
            'Captured' => $this->tag->text($side['captured_at'] ?? '—'),
            'Duration' => $this->tag->text(sprintf('%.1f ms', (float)($side['duration_ms'] ?? 0))),
            'Statements' => $this->tag->text((int)($side['statements'] ?? 0)),
            'Fallbacks' => $this->tag->text((int)($side['fallbacks'] ?? 0)),
            'Theme' => $this->theme($side),
        ]));
    }

    /**
     * @param array<string,mixed> $side
     * @return string
     */
    private function theme(array $side): string
    {
        $path = (string)($side['theme_path'] ?? '');

        if ($path === '') {
            return $this->tag->text('—');
        }

        $source = (string)($side['theme_source'] ?? '');

        return $this->tag->text($path) . ' ' . $this->ui->chip(
            $source === '' ? 'unknown' : $source,
            $source === 'observed' ? 'v-hit' : 'v-none'
        );
    }

    /**
     * @param array<string,mixed> $diff
     * @return string
     */
    private function changes(array $diff): string
    {
        $winners = $this->winnerRows($diff);

        $rows = array_merge(
            $this->verdictRow($diff),
            $this->metricRows($diff),
            $winners,
            $this->handleRows($diff),
            $this->queryRows($diff)
        );

        if ($rows === []) {
            return $this->ui->note(
                'Nothing changed between these two runs — same verdict, same timings, same handles, '
                . 'same statement shapes, and every file resolved to the same physical copy.'
            );
        }

        // The "no winner moved" statement is worth making explicitly rather than leaving as an
        // absence, because a reader chasing a theme override cannot tell "nothing moved" from
        // "the diff did not look" — but it belongs beside the table, not as a row in it, or a
        // comparison of two identical runs would never report itself as unchanged.
        return $this->ui->table(['Change', 'Detail'], $rows)
            . ($winners === [] ? $this->ui->note(
                'No fallback winner moved — every file resolved to the same physical copy in both runs.'
            ) : '');
    }

    /**
     * @param array<string,mixed> $diff
     * @return list<list<string>>
     */
    private function verdictRow(array $diff): array
    {
        $verdict = $diff['verdict'] ?? null;

        if (!is_array($verdict)) {
            return [];
        }

        $causes = is_array($verdict['causes'] ?? null) ? $verdict['causes'] : [];
        $detail = sprintf(
            'cacheable %s → %s',
            $this->flag($this->section($verdict, 'a')['cacheable'] ?? null),
            $this->flag($this->section($verdict, 'b')['cacheable'] ?? null)
        );

        if ($causes !== []) {
            $detail .= ', caused by ' . implode(', ', array_map('strval', $causes));
        }

        return [[$this->ui->chip('verdict', 'v-bad'), $this->tag->text($detail)]];
    }

    /**
     * @param array<string,mixed> $diff
     * @return list<list<string>>
     */
    private function metricRows(array $diff): array
    {
        $rows = [];

        foreach ($this->listOf($diff, 'metrics') as $metric) {
            if (empty($metric['changed'])) {
                continue;
            }

            $delta = (float)($metric['delta'] ?? 0);

            $rows[] = [
                $this->ui->chip((string)($metric['label'] ?? ''), $delta > 0 ? 'v-bad' : 'v-hit'),
                $this->tag->text(sprintf(
                    '%.1f → %.1f (%s%.1f)',
                    (float)($metric['a'] ?? 0),
                    (float)($metric['b'] ?? 0),
                    $delta > 0 ? '+' : '',
                    $delta
                )),
            ];
        }

        return $rows;
    }

    /**
     * The row this whole view exists for.
     *
     * @param array<string,mixed> $diff
     * @return list<list<string>>
     */
    private function winnerRows(array $diff): array
    {
        $winners = $this->listOf($diff, 'winners');
        $rows = [];

        foreach ($winners as $moved) {
            $rows[] = [
                $this->ui->chip('winner moved', 'v-bad'),
                $this->tag->text(sprintf(
                    '%s: %s → %s',
                    (string)($moved['file'] ?? '?'),
                    (string)($moved['a'] ?? '?'),
                    (string)($moved['b'] ?? '?')
                )),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $diff
     * @return list<list<string>>
     */
    private function handleRows(array $diff): array
    {
        $handles = $this->section($diff, 'handles');
        $rows = [];

        foreach (['added' => 'v-miss', 'removed' => 'v-none'] as $key => $modifier) {
            $list = is_array($handles[$key] ?? null) ? $handles[$key] : [];

            if ($list === []) {
                continue;
            }

            $rows[] = [
                $this->ui->chip('handles ' . $key, $modifier),
                $this->tag->text(implode(', ', array_map('strval', $list))),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $diff
     * @return list<list<string>>
     */
    private function queryRows(array $diff): array
    {
        $rows = [];

        foreach (array_slice($this->listOf($diff, 'queries'), 0, 15) as $change) {
            $delta = (int)($change['delta'] ?? 0);

            $rows[] = [
                $this->ui->chip(($delta > 0 ? '+' : '') . $delta, $delta > 0 ? 'v-bad' : 'v-hit'),
                $this->tag->text(sprintf(
                    '%d → %d · %s%s',
                    (int)($change['a'] ?? 0),
                    (int)($change['b'] ?? 0),
                    (string)($change['sample'] ?? ''),
                    ($change['origin'] ?? null) ? ' · ' . (string)$change['origin'] : ''
                )),
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function flag(mixed $value): string
    {
        return $value === null ? 'not reported' : ($value ? 'true' : 'false');
    }

    /**
     * @param array<string,mixed> $source
     * @param string $key
     * @return list<array<string,mixed>>
     */
    private function listOf(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param array<string,mixed> $source
     * @param string $key
     * @return array<string,mixed>
     */
    private function section(array $source, string $key): array
    {
        $section = $source[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}

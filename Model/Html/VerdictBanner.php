<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;

/**
 * The first thing on a run page: what happened, and why.
 *
 * The verdict leads because it is the answer; the numbers underneath are the evidence for it. That
 * ordering is deliberate and is the opposite of a metrics dashboard, which opens with figures and
 * leaves the reader to infer a conclusion.
 *
 * The wording comes from CacheVerdict, unmodified. When the analyser says the cause is unknown, this
 * says so too — an invented cause is worse than none, because it sends somebody to edit the right
 * file for the wrong reason, or the wrong file entirely.
 */
class VerdictBanner
{
    /**
     * Verdict status to its spine colour and headline word.
     */
    private const PRESENTATION = [
        CacheVerdict::HIT => ['v-hit', 'Cache hit'],
        CacheVerdict::MISS => ['v-miss', 'Cache miss'],
        CacheVerdict::UNCACHEABLE => ['v-bad', 'Uncacheable'],
        CacheVerdict::UNKNOWN => ['v-none', 'Unknown'],
        CacheVerdict::NOT_APPLICABLE => ['v-none', 'Not applicable'],
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
     * @param array<string,mixed> $run
     * @param array<string,mixed> $verdict From CacheVerdict::verdict().
     * @param array<string,mixed> $totals shadowed, statements, shapes — computed by the caller.
     * @return string
     */
    public function render(array $run, array $verdict, array $totals = []): string
    {
        $status = (string)($verdict['status'] ?? CacheVerdict::UNKNOWN);
        [$modifier, $headline] = self::PRESENTATION[$status] ?? self::PRESENTATION[CacheVerdict::UNKNOWN];

        $line = $this->tag->tag('span', ['class' => 'verdict-state'], $this->tag->text($headline))
            . ' — '
            . $this->tag->text($verdict['summary'] ?? '')
            . $this->causes($verdict);

        return $this->tag->tag(
            'div',
            ['class' => 'verdict', 'data-spine' => $modifier],
            $this->identity($run)
            . $this->tag->tag('div', ['class' => 'verdict-line'], $line)
        ) . $this->evidence($run, $totals);
    }

    /**
     * Which run this is, in one line above the verdict.
     *
     * @param array<string,mixed> $run
     * @return string
     */
    private function identity(array $run): string
    {
        $request = $this->section($run, 'request');

        $parts = array_filter([
            'Run ' . (string)($run['token'] ?? '?'),
            trim((string)($request['method'] ?? '') . ' ' . (string)($request['url'] ?? '')),
            (string)($run['captured_at'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '');

        // Verbatim: this line carries the run token, and the reader's next move is often to copy it
        // into `make profile t=…`. Uppercasing it for looks would hand them a token that does not
        // exist.
        return $this->ui->eyebrowVerbatim(implode(' · ', $parts));
    }

    /**
     * The named cause, or an explicit statement that there is none.
     *
     * @param array<string,mixed> $verdict
     * @return string
     */
    private function causes(array $verdict): string
    {
        $causes = $verdict['causes'] ?? [];

        if (!is_array($causes) || $causes === []) {
            return '';
        }

        $first = is_array($causes[0] ?? null) ? $causes[0] : [];
        $detail = (string)($first['detail'] ?? '');

        if ($detail === '') {
            return '';
        }

        $extra = count($causes) > 1
            ? sprintf(' (and %d more)', count($causes) - 1)
            : '';

        return $this->tag->tag(
            'span',
            ['class' => 'verdict-because'],
            'because ' . $this->tag->tag('code', [], $this->tag->text($detail)) . $this->tag->text($extra)
        );
    }

    /**
     * One hairline row of measurements. Deliberately not six cards.
     *
     * @param array<string,mixed> $run
     * @param array<string,mixed> $totals
     * @return string
     */
    private function evidence(array $run, array $totals): string
    {
        $request = $this->section($run, 'request');
        $context = $this->section($run, 'context');

        $items = [
            ['Duration', sprintf('%.1f ms', (float)($request['duration_ms'] ?? 0)), null],
            ['Peak', sprintf('%.1f MB', (float)($request['memory_peak_kb'] ?? 0) / 1024), null],
            [
                'Statements',
                (string)(int)($totals['statements'] ?? 0),
                ($totals['shapes'] ?? null) !== null ? sprintf('%d shapes', (int)$totals['shapes']) : null,
            ],
            [
                'Fallbacks',
                (string)(int)($totals['fallbacks'] ?? 0),
                ($totals['shadowed'] ?? null) ? sprintf('%d shadowed', (int)$totals['shadowed']) : null,
            ],
            // theme_source is carried, not dropped: "the store is configured to use this" is a
            // weaker claim than "this request resolved files against it", and the difference is
            // exactly what a reader chasing a fallback needs to know.
            ['Theme', (string)($context['theme_path'] ?? '—'), $context['theme_source'] ?? null],
            [
                'Store',
                (string)($context['store_code'] ?? '—'),
                $context['store_id'] === null ? null : sprintf('id %d', (int)$context['store_id']),
            ],
        ];

        $markup = '';

        foreach ($items as [$label, $value, $weak]) {
            $markup .= $this->tag->tag(
                'span',
                ['class' => 'ev'],
                $this->ui->eyebrow($label)
                . $this->tag->tag('b', ['class' => 'num'], $this->tag->text($value))
                . ($weak === null ? '' : $this->tag->tag('span', ['class' => 'weak'], $this->tag->text($weak)))
            );
        }

        return $this->tag->tag('div', ['class' => 'evidence'], $markup);
    }

    /**
     * @param array<string,mixed> $run
     * @param string $key
     * @return array<string,mixed>
     */
    private function section(array $run, string $key): array
    {
        $section = $run[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}

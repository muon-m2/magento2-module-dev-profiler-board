<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * Which physical file won each theme fallback, and which copies lost.
 *
 * This is the question the profiler exists to answer, so the panel renders it the way the CLI does:
 * a ladder per file, the winner marked, every later copy struck through. The board should read as
 * the command grown up rather than as a different product — somebody who has been using
 * `bin/magento muon:profile:show --shadowed-only` should not have to learn a second vocabulary.
 *
 * Filtering follows the CLI exactly, including the parts that look like details: probe misses are
 * counted rather than listed, because Magento is allowed not to find a file and listing those
 * buried the real signal under noise; and the substring filter matches the file key or any path it
 * resolved to, so searching for "tokens" finds a file whose key does not contain the word.
 */
class FallbackPanel
{
    /**
     * This panel's key in RunView::PANELS — the value its own form submits back.
     */
    private const PANEL = 'fallback';

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
     * @param list<array<string,mixed>> $classified From ShadowResolver::classify().
     * @param array<string,mixed> $state shadowed, fallback (substring), plus carried thresholds.
     * @param string $formAction
     * @return string
     */
    public function render(array $classified, array $state, string $formAction): string
    {
        $shadowedOnly = !empty($state['shadowed']);
        $filter = $this->filterText($state);

        $probeMisses = 0;
        $shadowedTotal = 0;
        $lookups = 0;
        $ladders = '';
        $listed = 0;

        foreach ($classified as $entry) {
            $lookups += max(1, (int)($entry['lookups'] ?? 1));
            if (($entry['anomaly'] ?? null) === 'probe-miss') {
                $probeMisses++;

                continue;
            }

            if (($entry['shadowed'] ?? []) !== []) {
                $shadowedTotal++;
            }

            if ($this->skip($entry, $shadowedOnly, $filter)) {
                continue;
            }

            $listed++;
            $ladders .= $this->ladder($entry);
        }

        return $this->controls($state, $formAction)
            . $this->ui->lede($this->summary(count($classified), $shadowedTotal, $listed, $lookups))
            . ($ladders === '' ? $this->ui->note($this->emptyReason($shadowedOnly, $filter)) : $ladders)
            . $this->anomalyNote($classified, $probeMisses);
    }

    /**
     * @param array<string,mixed> $state
     * @param string $formAction
     * @return string
     */
    private function controls(array $state, string $formAction): string
    {
        $filter = $this->tag->tag(
            'div',
            ['class' => 'field'],
            $this->tag->tag('label', ['for' => 'f-sub'], 'Path contains')
            . $this->tag->tag('input', [
                'id' => 'f-sub',
                'type' => 'text',
                'name' => 'fallback',
                'value' => $state['fallback'] ?? null,
                'placeholder' => '_tokens-generated',
            ])
        );

        $shadowed = $this->tag->tag(
            'label',
            ['class' => 'check'],
            $this->tag->tag('input', [
                'type' => 'checkbox',
                'name' => 'shadowed',
                'value' => '1',
                'checked' => !empty($state['shadowed']),
            ]) . ' Shadowed only'
        );

        return $this->tag->tag(
            'form',
            ['class' => 'controls', 'method' => 'get', 'action' => $formAction],
            $this->hidden($state)
            . $filter
            . $shadowed
            . $this->tag->tag('button', ['class' => 'btn primary', 'type' => 'submit'], 'Apply')
        );
    }

    /**
     * Carry the run token and the SQL thresholds through a filter submit.
     *
     * Without these, applying a fallback filter would silently reset the analysis sensitivity the
     * reader set two panels ago — and the URL would stop describing what is on screen.
     *
     * @param array<string,mixed> $state
     * @return string
     */
    private function hidden(array $state): string
    {
        // The open panel is this one, stated as a constant rather than carried from the query
        // string. Tab switching happens in the browser and does not change the URL, so `panel` is
        // absent on a board opened at its default view — and taking it from state meant submitting
        // this form bounced the reader back to Overview. A form inside a panel knows which panel it
        // is; it does not need to be told.
        $markup = $this->tag->tag('input', ['type' => 'hidden', 'name' => 'panel', 'value' => self::PANEL]);

        foreach (['token', 'nplus1', 'duplicate', 'slow'] as $key) {
            if (($state[$key] ?? null) === null || $state[$key] === '') {
                continue;
            }

            $markup .= $this->tag->tag('input', [
                'type' => 'hidden',
                'name' => $key,
                'value' => $state[$key],
            ]);
        }

        return $markup;
    }

    /**
     * One file, with its winner and every copy the winner hides.
     *
     * @param array<string,mixed> $entry
     * @return string
     */
    private function ladder(array $entry): string
    {
        $rungs = '';
        $winner = $entry['winner'] ?? null;

        if (is_string($winner) && $winner !== '') {
            $rungs .= $this->rung('won', $winner, 'won');
        }

        foreach ($this->asStrings($entry['shadowed'] ?? []) as $path) {
            $rungs .= $this->rung('shadowed', $path, 'shadowed');
        }

        $anomaly = $entry['anomaly'] ?? null;

        if ($anomaly !== null) {
            $rungs .= $this->tag->tag(
                'div',
                ['class' => 'rung anomaly'],
                $this->tag->tag('span', ['class' => 'mark'], $this->tag->text((string)$anomaly))
                . $this->tag->tag('span', ['class' => 'path'], $this->tag->text($this->anomalyText((string)$anomaly)))
            );
        }

        $lookups = (int)($entry['lookups'] ?? 1);

        $key = $this->tag->tag(
            'div',
            ['class' => 'ladder-key'],
            $this->tag->text($entry['file'] ?? '?')
            . (($entry['module'] ?? null) ? ' ' . $this->ui->chip((string)$entry['module']) : '')
            . ' ' . $this->ui->chip((string)($entry['type'] ?? '?'))
            // Repeat lookups are collapsed into one ladder; the count keeps that visible rather
            // than quietly implying the file was resolved once.
            . ($lookups > 1 ? ' ' . $this->ui->chip('×' . $lookups) : '')
        );

        return $this->tag->tag('div', ['class' => 'ladder'], $key . $rungs);
    }

    /**
     * @param string $modifier
     * @param string $path
     * @param string $mark
     * @return string
     */
    private function rung(string $modifier, string $path, string $mark): string
    {
        return $this->tag->tag(
            'div',
            ['class' => 'rung ' . $modifier],
            $this->tag->tag('span', ['class' => 'mark'], $this->tag->text($mark))
            . $this->tag->tag('span', ['class' => 'path'], $this->tag->text($path))
        );
    }

    /**
     * Whether this resolution is filtered out. Mirrors FallbackListRenderer::skip().
     *
     * @param array<string,mixed> $entry
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @return bool
     */
    private function skip(array $entry, bool $shadowedOnly, ?string $filter): bool
    {
        if ($shadowedOnly && ($entry['shadowed'] ?? []) === []) {
            return true;
        }

        if ($filter === null) {
            return false;
        }

        return !$this->matches($entry, $filter);
    }

    /**
     * The filter matches the file key or any path the lookup touched.
     *
     * @param array<string,mixed> $entry
     * @param string $filter
     * @return bool
     */
    private function matches(array $entry, string $filter): bool
    {
        $haystacks = array_merge(
            [(string)($entry['file'] ?? ''), (string)($entry['winner'] ?? '')],
            $this->asStrings($entry['shadowed'] ?? [])
        );

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && stripos($haystack, $filter) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $total Distinct files, after repeat lookups are collapsed.
     * @param int $shadowed
     * @param int $listed
     * @param int $lookups Raw resolutions, before collapsing.
     * @return string
     */
    private function summary(int $total, int $shadowed, int $listed, int $lookups): string
    {
        return sprintf(
            '%d of %d files were found in more than one place; %d shown, from %d lookups. The first '
            . 'directory searched wins, and every later copy is dead — it behaves exactly as if it '
            . 'had never been written.',
            $shadowed,
            $total,
            $listed,
            $lookups
        );
    }

    /**
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @return string
     */
    private function emptyReason(bool $shadowedOnly, ?string $filter): string
    {
        if ($shadowedOnly) {
            return 'No file was found in more than one place. Nothing is being shadowed.';
        }

        if ($filter !== null) {
            return 'No resolution matched the filter.';
        }

        return 'This run resolved no files. A full-page-cache hit loads no design and resolves nothing.';
    }

    /**
     * Anomalies are surfaced, never hidden: they mark where the replay could not be trusted.
     *
     * @param list<array<string,mixed>> $classified
     * @param int $probeMisses
     * @return string
     */
    private function anomalyNote(array $classified, int $probeMisses): string
    {
        $counts = [];

        foreach ($classified as $entry) {
            $anomaly = $entry['anomaly'] ?? null;

            if (is_string($anomaly) && $anomaly !== 'probe-miss') {
                $counts[$anomaly] = ($counts[$anomaly] ?? 0) + 1;
            }
        }

        $parts = [];

        if ($probeMisses > 0) {
            $parts[] = sprintf('%d probe-miss (counted, not listed — Magento may look for a file '
                . 'that does not exist)', $probeMisses);
        }

        foreach ($counts as $anomaly => $count) {
            $parts[] = sprintf('%d %s', $count, $anomaly);
        }

        return $parts === [] ? '' : $this->ui->note('Anomalies: ' . implode('; ', $parts) . '.');
    }

    /**
     * @param string $anomaly
     * @return string
     */
    private function anomalyText(string $anomaly): string
    {
        return match ($anomaly) {
            'replay-diverged' => 'the framework resolved this file but the replay could not find it — '
                . 'nothing below can be trusted for this entry',
            'winner-mismatch' => 'the replay picked a different winner than the request recorded',
            'candidates-unavailable' => 'the search directories could not be rebuilt',
            'unsupported-type' => 'this resolution type is not yet classified',
            default => $anomaly,
        };
    }

    /**
     * @param array<string,mixed> $state
     * @return string|null
     */
    private function filterText(array $state): ?string
    {
        $filter = $state['fallback'] ?? null;

        if (!is_string($filter)) {
            return null;
        }

        $filter = trim($filter);

        return $filter === '' ? null : $filter;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function asStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string)$item : '',
            array_filter($value, 'is_scalar')
        ));
    }
}

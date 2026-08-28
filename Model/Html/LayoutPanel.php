<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * The evidence behind the cache verdict.
 *
 * The load-bearing column is "generated?". Merged layout XML contains cacheable="false"
 * declarations that never produce an element — inside handles that did not apply, inside references
 * to blocks that were removed — and a panel that listed those as causes would contradict the
 * verdict printed directly above it. They are still shown, because their absence would look like
 * the panel had missed them, but they are marked as what they are.
 */
class LayoutPanel
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
     * @param array<string,mixed> $run
     * @param array<string,mixed> $verdict
     * @return string
     */
    public function render(array $run, array $verdict): string
    {
        $layout = $this->section($run, 'layout');

        return $this->ui->heading('Verdict evidence')
            . $this->ui->factsHtml([
                'Layout generated' => $this->tag->text(!empty($layout['generated']) ? 'yes' : 'no'),
                'isCacheable()' => $this->cacheable($layout),
                'Verdict' => $this->tag->text($verdict['summary'] ?? '—'),
                'Cause named' => $this->tag->text(!empty($verdict['cause_known']) ? 'yes' : 'no — cause unknown'),
            ])
            . $this->blocks($layout)
            . $this->optOuts($layout)
            . $this->handles($layout);
    }

    /**
     * @param array<string,mixed> $layout
     * @return string
     */
    private function cacheable(array $layout): string
    {
        $cacheable = $layout['cacheable'] ?? null;

        if ($cacheable === null) {
            return $this->ui->chip('not reported', 'v-none');
        }

        return $cacheable
            ? $this->ui->chip('true', 'v-hit')
            : $this->ui->chip('false', 'v-bad');
    }

    /**
     * @param array<string,mixed> $layout
     * @return string
     */
    private function blocks(array $layout): string
    {
        $blocks = $this->asList($layout['uncacheable_blocks'] ?? null);

        if ($blocks === []) {
            return '';
        }

        $rows = [];

        foreach ($blocks as $block) {
            $inPlay = !empty($block['in_play']);

            $rows[] = [
                $this->tag->text($block['name'] ?? '?'),
                $this->tag->text($block['class'] ?? '—'),
                $this->tag->text($block['template'] ?? '—'),
                $inPlay
                    ? $this->ui->chip('in play', 'v-bad')
                    : $this->ui->chip('not generated', 'v-none'),
            ];
        }

        return $this->ui->heading('Blocks declaring cacheable="false"', sprintf('%d', count($blocks)))
            . $this->ui->table(
                ['Block', 'Class', 'Template', 'Generated?'],
                $rows,
                [],
                'Blocks declaring cacheable="false"'
            )
            . $this->ui->note(
                'Only blocks confirmed generated are offered as a cause. A declaration that never '
                . 'produced an element cannot be why this page was uncacheable.'
            );
    }

    /**
     * @param array<string,mixed> $layout
     * @return string
     */
    private function optOuts(array $layout): string
    {
        $optOuts = $this->asList($layout['constructor_optouts'] ?? null);

        if ($optOuts === []) {
            return '';
        }

        $rows = [];

        foreach ($optOuts as $optOut) {
            $rows[] = [$this->tag->text($optOut['origin'] ?? 'unknown origin')];
        }

        return $this->ui->heading('Layouts constructed non-cacheable', sprintf('%d', count($optOuts)))
            . $this->ui->table(['Origin'], $rows, [], 'Layouts constructed non-cacheable');
    }

    /**
     * @param array<string,mixed> $layout
     * @return string
     */
    private function handles(array $layout): string
    {
        $handles = $layout['handles'] ?? [];

        if (!is_array($handles) || $handles === []) {
            return '';
        }

        $chips = '';

        foreach ($handles as $handle) {
            if (is_scalar($handle)) {
                $chips .= $this->ui->chip((string)$handle);
            }
        }

        return $this->ui->heading('Handles', sprintf('%d', count($handles)))
            . $this->tag->tag('p', ['class' => 'chips'], $chips);
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
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

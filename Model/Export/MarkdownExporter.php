<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Export;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;

/**
 * One run as a Markdown document.
 *
 * The board's most useful export is not a screenshot — it is text somebody can paste into an issue,
 * a pull request or a Claude session and have the evidence travel with the claim. Plain Markdown,
 * no ANSI, no HTML: the CLI's output is coloured for a terminal and does not survive that trip.
 *
 * Bind values appear exactly as stored. They were masked at capture time and are never unmasked
 * here — which also means an exported run is safe to paste somewhere other people will read it.
 */
class MarkdownExporter
{
    /**
     * How many statement findings and shadowed files to include before it stops being a summary.
     */
    private const MAX_ROWS = 20;

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $analysis From RunAnalysis::analyse().
     * @param array{slow_ms:float,nplus1:int,duplicate:int} $thresholds
     * @return string
     */
    public function export(array $run, array $analysis, array $thresholds): string
    {
        $sections = array_filter([
            $this->header($run, $analysis),
            $this->evidence($run, $analysis),
            $this->shadowed($analysis),
            $this->sql($analysis, $thresholds),
        ], static fn (string $section): bool => $section !== '');

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $analysis
     * @return string
     */
    private function header(array $run, array $analysis): string
    {
        $request = $this->section($run, 'request');
        $verdict = $this->section($analysis, 'verdict');

        // Through cell() like every other free-form value. This output exists for pasting into an
        // issue or a chat, which are rendering contexts — a recorded URI of
        // `/<img src=x onerror=...>` became raw HTML inside an `#` heading. GitHub sanitises; plenty
        // of local previewers and wikis do not.
        $title = sprintf(
            '# Profiler run %s — %s %s',
            $this->cell((string)($run['token'] ?? '?')),
            $this->cell((string)($request['method'] ?? '')),
            $this->cell((string)($request['url'] ?? ''))
        );

        $line = sprintf(
            '**%s** — %s',
            strtoupper((string)($verdict['status'] ?? 'unknown')),
            (string)($verdict['summary'] ?? '')
        );

        if (empty($verdict['cause_known']) && ($verdict['status'] ?? '') === 'uncacheable') {
            $line .= "\n\n> No generated block and no layout construction accounts for it — cause unknown.";
        }

        return $title . "\n\nCaptured " . (string)($run['captured_at'] ?? '?') . "\n\n" . $line;
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $analysis
     * @return string
     */
    private function evidence(array $run, array $analysis): string
    {
        $request = $this->section($run, 'request');
        $context = $this->section($run, 'context');
        $totals = $this->section($analysis, 'totals');

        $rows = [
            ['Duration', sprintf('%.1f ms', (float)($request['duration_ms'] ?? 0))],
            ['Peak memory', sprintf('%.1f MB', (float)($request['memory_peak_kb'] ?? 0) / 1024)],
            ['Statements', sprintf(
                '%d in %d shapes',
                (int)($totals['statements'] ?? 0),
                (int)($totals['shapes'] ?? 0)
            )],
            ['Fallbacks', sprintf(
                '%d resolved, %d shadowed',
                (int)($totals['fallbacks'] ?? 0),
                (int)($totals['shadowed'] ?? 0)
            )],
            ['Theme', sprintf(
                '%s (%s)',
                (string)($context['theme_path'] ?? '—'),
                (string)($context['theme_source'] ?? 'unknown')
            )],
            ['Store', (string)($context['store_code'] ?? '—')],
            ['Full action', (string)($request['full_action'] ?? 'none — never routed')],
        ];

        return "## Evidence\n\n" . $this->table(['Fact', 'Value'], $rows);
    }

    /**
     * @param array<string,mixed> $analysis
     * @return string
     */
    private function shadowed(array $analysis): string
    {
        $rows = [];

        foreach ($this->listOf($analysis, 'fallback') as $entry) {
            $shadowed = is_array($entry['shadowed'] ?? null) ? $entry['shadowed'] : [];

            if ($shadowed === []) {
                continue;
            }

            $rows[] = [
                $this->cell($entry['file'] ?? '?'),
                $this->cell($entry['winner'] ?? '?'),
                implode('<br>', array_map(fn (mixed $path): string => $this->cell($path), $shadowed)),
            ];
        }

        if ($rows === []) {
            return '';
        }

        $note = count($rows) > self::MAX_ROWS
            ? sprintf("\n\n_Showing %d of %d._", self::MAX_ROWS, count($rows))
            : '';

        return "## Shadowed files\n\nThe first directory searched wins; every later copy is dead.\n\n"
            . $this->table(['File', 'Won', 'Shadowed'], array_slice($rows, 0, self::MAX_ROWS))
            . $note;
    }

    /**
     * @param array<string,mixed> $analysis
     * @param array{slow_ms:float,nplus1:int,duplicate:int} $thresholds
     * @return string
     */
    private function sql(array $analysis, array $thresholds): string
    {
        $sql = $this->section($analysis, 'sql');
        $rows = [];

        foreach ($this->listOf($sql, 'groups') as $group) {
            $finding = $group['finding'] ?? null;

            if (!is_array($finding)) {
                continue;
            }

            $rows[] = [
                $this->findingLabel((string)($finding['kind'] ?? '')),
                (string)(int)($group['count'] ?? 0),
                sprintf('%.1f', (float)($group['total_ms'] ?? 0)),
                '`' . $this->oneLine((string)($group['sample'] ?? '')) . '`',
                $this->cell((string)($group['origin'] ?? '—')),
            ];
        }

        if ($rows === []) {
            return '';
        }

        $header = sprintf(
            "## SQL findings\n\nThresholds: N+1 ≥ %d executions, duplicate ≥ %d, slow > %.0f ms.\n\n",
            (int)$thresholds['nplus1'],
            (int)$thresholds['duplicate'],
            (float)$thresholds['slow_ms']
        );

        return $header . $this->table(
            ['Finding', '×', 'Total ms', 'Statement', 'Origin'],
            array_slice($rows, 0, self::MAX_ROWS)
        );
    }

    /**
     * @param string $kind
     * @return string
     */
    private function findingLabel(string $kind): string
    {
        return match ($kind) {
            QueryAnalyzer::N_PLUS_ONE => 'N+1',
            QueryAnalyzer::DUPLICATE => 'duplicate',
            QueryAnalyzer::SLOW => 'slow',
            default => $kind,
        };
    }

    /**
     * A path or file key as a code-span table cell.
     *
     * Every cell goes through here rather than being concatenated inline, for the same reason the
     * HTML side routes everything through Tag: a value that reaches a table unescaped is a value
     * somebody has to remember to check. A pipe in a path would silently split the row into two
     * columns wherever this Markdown is finally rendered.
     *
     * @param mixed $value
     * @return string
     */
    private function cell(mixed $value): string
    {
        return '`' . $this->oneLine(is_scalar($value) ? (string)$value : '?') . '`';
    }

    /**
     * A Markdown table cell cannot contain a newline or an unescaped pipe.
     *
     * @param string $text
     * @return string
     */
    private function oneLine(string $text): string
    {
        return str_replace('|', '\\|', trim((string)preg_replace('/\s+/', ' ', $text)));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return string
     */
    private function table(array $headers, array $rows): string
    {
        $lines = [
            '| ' . implode(' | ', $headers) . ' |',
            '|' . str_repeat('---|', count($headers)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines);
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

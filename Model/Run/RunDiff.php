<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;

/**
 * What changed between two captured runs.
 *
 * The reason this exists is the last comparison it makes: **which fallback winners moved**. Two
 * loads of the same URL that resolve different physical files mean the theme resolved differently
 * between them, and that is the single hardest thing to notice by reading either run alone — the
 * site behaves normally in both, and only the diff shows the file swapped underneath.
 *
 * Comparisons iterate the union of keys rather than the intersection. A shape present in one run
 * and absent from the other is the most interesting row in the table; an intersection would drop
 * exactly that.
 */
class RunDiff
{
    /**
     * Metrics compared numerically, with the direction that counts as worse.
     */
    private const METRICS = [
        'duration_ms' => 'Duration (ms)',
        'memory_peak_kb' => 'Peak memory (KB)',
    ];

    /**
     * @param \Muon\DevProfiler\Model\Analysis\CacheVerdict $verdicts
     */
    public function __construct(
        private readonly CacheVerdict $verdicts
    ) {
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    public function compare(array $left, array $right): array
    {
        return [
            'same_url' => $this->url($left) === $this->url($right),
            'sides' => [
                'a' => $this->side($left),
                'b' => $this->side($right),
            ],
            'verdict' => $this->verdictChange($left, $right),
            'metrics' => $this->metrics($left, $right),
            'handles' => $this->handles($left, $right),
            'queries' => $this->queries($left, $right),
            'winners' => $this->winners($left, $right),
        ];
    }

    /**
     * One side reduced to what the summary column shows.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function side(array $run): array
    {
        $request = $this->section($run, 'request');
        $context = $this->section($run, 'context');

        return [
            'token' => (string)($run['token'] ?? ''),
            'url' => $this->url($run),
            'method' => (string)($request['method'] ?? ''),
            'captured_at' => (string)($run['captured_at'] ?? ''),
            'duration_ms' => (float)($request['duration_ms'] ?? 0),
            'memory_peak_kb' => (int)($request['memory_peak_kb'] ?? 0),
            'statements' => $this->statements($run),
            'fallbacks' => count($this->list($run, 'fallback')),
            'theme_path' => $context['theme_path'] ?? null,
            'theme_source' => $context['theme_source'] ?? null,
            'cacheable' => $this->section($run, 'layout')['cacheable'] ?? null,
            'generated' => !empty($this->section($run, 'layout')['generated']),
        ];
    }

    /**
     * Whether the page went from cacheable to not, or the other way.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>|null
     */
    private function verdictChange(array $left, array $right): ?array
    {
        // Delegated, not recomputed. Deriving the verdict here from `generated` and `cacheable`
        // alone made this page disagree with the ledger beside it in two ways: it ignored
        // request.kind, so a static run and a cache-hit run both read as generated=false /
        // cacheable=null and compared equal, while the rail showed `n/a` against `hit`; and its
        // cause list skipped constructor_optouts entirely, so a page made uncacheable only by a
        // constructor opt-out — the headline discovery this toolchain exists for — showed a named
        // cause on the run page and an empty list here.
        $a = $this->verdictOf($left);
        $b = $this->verdictOf($right);

        if ($a['status'] === $b['status'] && $a['causes'] === $b['causes']) {
            return null;
        }

        return [
            'a' => [
                'status' => $a['status'],
                'generated' => $this->section($left, 'layout')['generated'] ?? null,
                'cacheable' => $this->section($left, 'layout')['cacheable'] ?? null,
            ],
            'b' => [
                'status' => $b['status'],
                'generated' => $this->section($right, 'layout')['generated'] ?? null,
                'cacheable' => $this->section($right, 'layout')['cacheable'] ?? null,
            ],
            'causes' => $b['causes'],
        ];
    }

    /**
     * The collector's verdict for one run, kind included.
     *
     * @param array<string,mixed> $run
     * @return array{status: string, causes: list<string>}
     */
    private function verdictOf(array $run): array
    {
        $kind = (string)($this->section($run, 'request')['kind'] ?? 'page');
        $verdict = $this->verdicts->verdict($this->section($run, 'layout'), $kind);

        $causes = [];

        foreach ($this->asList($verdict['causes'] ?? null) as $cause) {
            $causes[] = is_array($cause)
                ? (string)($cause['name'] ?? $cause['class'] ?? '?')
                : (string)$cause;
        }

        return ['status' => (string)($verdict['status'] ?? ''), 'causes' => $causes];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return list<array<string,mixed>>
     */
    private function metrics(array $left, array $right): array
    {
        $a = $this->side($left);
        $b = $this->side($right);

        $rows = [];

        foreach (self::METRICS as $key => $label) {
            $rows[] = $this->metric($label, (float)$a[$key], (float)$b[$key]);
        }

        $rows[] = $this->metric('Statements', (float)$a['statements'], (float)$b['statements']);
        $rows[] = $this->metric('Fallback resolutions', (float)$a['fallbacks'], (float)$b['fallbacks']);

        return $rows;
    }

    /**
     * @param string $label
     * @param float $a
     * @param float $b
     * @return array<string,mixed>
     */
    private function metric(string $label, float $a, float $b): array
    {
        return [
            'label' => $label,
            'a' => $a,
            'b' => $b,
            'delta' => round($b - $a, 1),
            'changed' => abs($b - $a) > 0.0001,
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array{added:list<string>,removed:list<string>}
     */
    private function handles(array $left, array $right): array
    {
        $a = $this->strings($this->section($left, 'layout')['handles'] ?? null);
        $b = $this->strings($this->section($right, 'layout')['handles'] ?? null);

        return [
            'added' => array_values(array_diff($b, $a)),
            'removed' => array_values(array_diff($a, $b)),
        ];
    }

    /**
     * Statement shapes present on one side only, or executed a different number of times.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return list<array<string,mixed>>
     */
    private function queries(array $left, array $right): array
    {
        $a = $this->byFingerprint($left);
        $b = $this->byFingerprint($right);

        $changes = [];

        foreach (array_keys($a + $b) as $fingerprint) {
            $before = $a[$fingerprint] ?? null;
            $after = $b[$fingerprint] ?? null;

            $countA = (int)($before['count'] ?? 0);
            $countB = (int)($after['count'] ?? 0);

            if ($countA === $countB) {
                continue;
            }

            $changes[] = [
                'fingerprint' => (string)$fingerprint,
                'sample' => (string)(($after['sample'] ?? $before['sample'] ?? '')),
                'origin' => $after['origin'] ?? $before['origin'] ?? null,
                'a' => $countA,
                'b' => $countB,
                'delta' => $countB - $countA,
            ];
        }

        usort($changes, static fn (array $x, array $y): int => abs($y['delta']) <=> abs($x['delta']));

        return $changes;
    }

    /**
     * Files whose winning copy differs between the two runs.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return list<array<string,mixed>>
     */
    private function winners(array $left, array $right): array
    {
        $a = $this->resolvedByFile($left);
        $b = $this->resolvedByFile($right);

        $moved = [];

        foreach (array_keys($a + $b) as $file) {
            $before = $a[$file] ?? null;
            $after = $b[$file] ?? null;

            if ($before === $after || $before === null || $after === null) {
                continue;
            }

            $moved[] = ['file' => (string)$file, 'a' => $before, 'b' => $after];
        }

        return $moved;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,array<string,mixed>>
     */
    private function byFingerprint(array $run): array
    {
        $groups = [];

        foreach ($this->list($run, 'queries') as $group) {
            $fingerprint = (string)($group['fingerprint'] ?? '');

            if ($fingerprint !== '') {
                $groups[$fingerprint] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,string>
     */
    private function resolvedByFile(array $run): array
    {
        $resolved = [];

        foreach ($this->list($run, 'fallback') as $entry) {
            $file = (string)($entry['file'] ?? '');
            $path = $entry['resolved'] ?? null;

            if ($file !== '' && is_string($path) && $path !== '') {
                $resolved[$file] = $path;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $run
     * @return int
     */
    private function statements(array $run): int
    {
        $total = 0;

        foreach ($this->list($run, 'queries') as $group) {
            $total += (int)($group['count'] ?? 0);
        }

        return $total;
    }

    /**
     * @param array<string,mixed> $run
     * @return string
     */
    private function url(array $run): string
    {
        return (string)($this->section($run, 'request')['url'] ?? '');
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function asList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param array<string,mixed> $run
     * @param string $key
     * @return list<array<string,mixed>>
     */
    private function list(array $run, string $key): array
    {
        return $this->asList($run[$key] ?? null);
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

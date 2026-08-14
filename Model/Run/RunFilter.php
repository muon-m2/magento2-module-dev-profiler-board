<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

/**
 * Which runs the ledger shows.
 *
 * Read from the query string like every other piece of analysis state, so a filtered ledger is a
 * link somebody can paste — "the uncacheable runs over 500 ms on this instance" is a sentence you
 * can send to someone.
 *
 * **Filtering happens over the whole ring, not over the rows the ledger already fetched.** That
 * distinction is the entire correctness of this class. The ledger is capped at `feedLimit`, so
 * filtering the fetched page would let "show me the uncacheable runs" come back empty while
 * uncacheable runs sat further down the ring — a filtered list reading as a complete answer, which
 * is the failure this module keeps building guards against.
 *
 * Every value is clamped or whitelisted. Nothing here reaches a filesystem path or a query; the
 * fields are compared against facts already decoded from a stored run.
 */
class RunFilter
{
    /** The verdicts CacheVerdict can produce; anything else is not a filter, it is a typo. */
    public const VERDICTS = ['hit', 'miss', 'uncacheable', 'unknown', 'n/a'];

    /** HTTP methods worth offering. A request with any other method never reaches a Magento route. */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * @param list<string> $verdicts Empty means every verdict.
     * @param string|null $method
     * @param int|null $status
     * @param float|null $minMs
     * @param float|null $maxMs
     * @param int|null $minStatements
     * @param int|null $maxStatements
     * @param string|null $url Case-insensitive substring of the recorded request URI.
     */
    public function __construct(
        public readonly array $verdicts = [],
        public readonly ?string $method = null,
        public readonly ?int $status = null,
        public readonly ?float $minMs = null,
        public readonly ?float $maxMs = null,
        public readonly ?int $minStatements = null,
        public readonly ?int $maxStatements = null,
        // Last, so adding it shifted no existing call site — the same rule that kept
        // RunFinalizer::excludedActions backward compatible.
        public readonly ?string $url = null
    ) {
    }

    /**
     * Whether this filter would exclude anything at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->count() > 0;
    }

    /**
     * How many criteria are set — shown on the collapsed filter toggle, so an active filter is never
     * invisible. A ledger that is quietly hiding rows is worse than one that is obviously doing so.
     *
     * @return int
     */
    public function count(): int
    {
        $set = [
            $this->verdicts !== [],
            $this->url !== null,
            $this->method !== null,
            $this->status !== null,
            $this->minMs !== null,
            $this->maxMs !== null,
            $this->minStatements !== null,
            $this->maxStatements !== null,
        ];

        return count(array_filter($set));
    }

    /**
     * Whether one ledger row survives the filter.
     *
     * Every criterion is an AND, and each is its own predicate — a single chain of guards had grown
     * past the complexity gate, and the gate was right: a filter is exactly the kind of code where
     * one misplaced condition silently hides rows.
     *
     * @param array<string,mixed> $row As built by RunSelector::feed().
     * @return bool
     */
    public function matches(array $row): bool
    {
        return $this->matchesVerdict($row)
            && $this->matchesUrl($row)
            && $this->matchesMethod($row)
            && $this->matchesStatus($row)
            && $this->inRange((float)($row['duration_ms'] ?? 0), $this->minMs, $this->maxMs)
            && $this->inRange(
                (float)(int)($row['statements'] ?? 0),
                $this->floatOrNull($this->minStatements),
                $this->floatOrNull($this->maxStatements)
            );
    }

    /**
     * @param array<string,mixed> $row
     * @return bool
     */
    private function matchesVerdict(array $row): bool
    {
        return $this->verdicts === [] || in_array((string)($row['verdict'] ?? ''), $this->verdicts, true);
    }

    /**
     * Case-insensitive substring over the whole recorded URI including its query — the same rule the
     * Fallback panel's "Path contains" uses, so the board has one behaviour to learn rather than
     * two. The needle is only ever a haystack search: never a pattern, never a path, never anything
     * that reaches the filesystem.
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    private function matchesUrl(array $row): bool
    {
        return $this->url === null || stripos((string)($row['url'] ?? ''), $this->url) !== false;
    }

    /**
     * @param array<string,mixed> $row
     * @return bool
     */
    private function matchesMethod(array $row): bool
    {
        return $this->method === null || strtoupper((string)($row['method'] ?? '')) === $this->method;
    }

    /**
     * @param array<string,mixed> $row
     * @return bool
     */
    private function matchesStatus(array $row): bool
    {
        return $this->status === null || (int)($row['status'] ?? 0) === $this->status;
    }

    /**
     * The filter as query parameters, for carrying into links and the live feed.
     *
     * @return array<string,string|int|float>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->verdicts !== []) {
            $query['verdict'] = implode(',', $this->verdicts);
        }

        foreach ([
            'url' => $this->url,
            'method' => $this->method,
            'status' => $this->status,
            'min_ms' => $this->minMs,
            'max_ms' => $this->maxMs,
            'min_stmt' => $this->minStatements,
            'max_stmt' => $this->maxStatements,
        ] as $key => $value) {
            if ($value !== null) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param float $value
     * @param float|null $min
     * @param float|null $max
     * @return bool
     */
    private function inRange(float $value, ?float $min, ?float $max): bool
    {
        // A reader who types the bounds the wrong way round means the range between them, not an
        // empty set — refusing to answer would be technically correct and useless.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ($min === null || $value >= $min) && ($max === null || $value <= $max);
    }

    /**
     * @param int|null $value
     * @return float|null
     */
    private function floatOrNull(?int $value): ?float
    {
        return $value === null ? null : (float)$value;
    }

}

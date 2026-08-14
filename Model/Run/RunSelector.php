<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Store\RunStore;

/**
 * Answers "which run is being looked at" and "what is in the ledger".
 *
 * Selection follows the CLI's rule rather than inventing a second one: with no token, the newest
 * **full document** run wins, not the newest run. A storefront page fires customer-section XHRs
 * immediately behind it, so the newest entry in the ring is almost never the page just loaded —
 * and a board that opened on the wrong run by default would be wrong in exactly the way that costs
 * the most time to notice.
 *
 * The ledger is deliberately cheap. It reads each run's recorded facts and asks CacheVerdict for a
 * status — no shadow classification, no query analysis. Those are the expensive read-time steps and
 * they belong to the run view, which is opened deliberately, not to the endpoint a browser tab
 * polls every few seconds.
 */
class RunSelector
{
    /**
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param \Muon\DevProfiler\Model\Analysis\CacheVerdict $verdict
     * @param int $feedLimit Ledger rows per request; RunStore's ring caps it further.
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly CacheVerdict $verdict,
        private readonly int $feedLimit = 25
    ) {
    }

    /**
     * The named run, or the most recent full document.
     *
     * @param string|null $token Already filtered by TokenFilter.
     * @return array<string,mixed>|null
     */
    public function select(?string $token): ?array
    {
        return $token !== null ? $this->store->load($token) : $this->store->loadLastDocument();
    }

    /**
     * The named run, or the most recent run of any kind — XHR and static asset included.
     *
     * Two methods rather than one with a flag, because the choice is not a variation of the same
     * question. "The latest page" and "the latest request" have different answers on every
     * storefront load: a page fires customer-section XHRs immediately behind it, so the newest
     * entry in the ring is almost never the page just loaded.
     *
     * @param string|null $token Already filtered by TokenFilter.
     * @return array<string,mixed>|null
     */
    public function selectAny(?string $token): ?array
    {
        return $token !== null ? $this->store->load($token) : $this->store->loadLast();
    }

    /**
     * How many runs the ring holds, which is not how many the ledger shows.
     *
     * The two differ whenever feedLimit is below the ring size, and conflating them makes a capped
     * list read as a complete one — the failure this whole tool exists to avoid.
     *
     * @return int
     */
    public function total(): int
    {
        return $this->store->count();
    }

    /**
     * Ledger rows, newest first.
     *
     * @param int|null $limit Clamped to feedLimit; null means feedLimit.
     * @return list<array<string,mixed>>
     */
    public function feed(?int $limit = null, ?RunFilter $filter = null): array
    {
        $cap = $this->limit($limit);

        // Unfiltered, read only as many runs as the ledger will show. Filtered, read the **whole
        // ring** and cap afterwards: filtering the first page would let "show me the uncacheable
        // runs" come back empty while uncacheable runs sat further down, which is a filtered list
        // presenting itself as a complete answer.
        $wanted = $filter !== null && $filter->isActive() ? $this->store->count() : $cap;

        $rows = [];

        foreach ($this->store->list(max(1, $wanted)) as $run) {
            $row = $this->row($run);

            if ($filter !== null && !$filter->matches($row)) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) >= $cap) {
                break;
            }
        }

        return $rows;
    }

    /**
     * How many runs in the ring match, regardless of how many the ledger will show.
     *
     * The counter needs this to say "6 of 50 match" rather than reporting the capped row count as
     * though it were the answer.
     *
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter $filter
     * @return int
     */
    public function matching(RunFilter $filter): int
    {
        if (!$filter->isActive()) {
            return $this->total();
        }

        $matched = 0;

        foreach ($this->store->list(max(1, $this->store->count())) as $run) {
            if ($filter->matches($this->row($run))) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * How many rows to read, whatever the caller asked for.
     *
     * @param int|null $requested
     * @return int
     */
    private function limit(?int $requested): int
    {
        if ($requested === null) {
            return max(1, $this->feedLimit);
        }

        return max(1, min($requested, max(1, $this->feedLimit)));
    }

    /**
     * One run reduced to what the ledger shows.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function row(array $run): array
    {
        $request = $this->section($run, 'request');
        $kind = (string)($request['kind'] ?? 'page');
        $verdict = $this->verdict->verdict($this->section($run, 'layout'), $kind);

        return [
            'token' => (string)($run['token'] ?? ''),
            'captured_at' => (string)($run['captured_at'] ?? ''),
            'method' => (string)($request['method'] ?? ''),
            'url' => (string)($request['url'] ?? ''),
            'full_action' => $request['full_action'] ?? null,
            'status' => $request['status'] ?? null,
            'kind' => $kind,
            'is_ajax' => (bool)($request['is_ajax'] ?? false),
            'duration_ms' => (float)($request['duration_ms'] ?? 0),
            'statements' => $this->statements($run),
            'verdict' => (string)$verdict['status'],
        ];
    }

    /**
     * How many statements the run issued, across all shapes.
     *
     * @param array<string,mixed> $run
     * @return int
     */
    private function statements(array $run): int
    {
        $queries = $run['queries'] ?? [];

        if (!is_array($queries)) {
            return 0;
        }

        $total = 0;

        foreach ($queries as $group) {
            if (is_array($group)) {
                $total += (int)($group['count'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * One top-level section of a stored run, guaranteed to be an array.
     *
     * A run file can be hand-edited or half-written; RunStore already returns null for anything it
     * cannot decode, but a decodable document with a missing section must not take the ledger down.
     *
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

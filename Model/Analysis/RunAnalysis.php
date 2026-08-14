<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Analysis;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfiler\Model\Analysis\ResolutionSet;
use Muon\DevProfiler\Model\Analysis\ShadowResolver;

/**
 * Runs the collector's own read-time analysis over one stored run.
 *
 * Every classification here is delegated, not reimplemented. That is a correctness requirement
 * rather than a preference: if the board decided for itself which candidates were shadowed or which
 * shape was an N+1, it could disagree with `make profile` about the same token, and two tools
 * disagreeing about the same evidence is worse than having only one.
 *
 * This is also where the expensive work is confined. ShadowResolver stats candidate directories on
 * disk; that cost belongs to a run view somebody opened deliberately, never to the ledger feed a
 * browser tab polls.
 */
class RunAnalysis
{
    /**
     * @param \Muon\DevProfiler\Model\Analysis\CacheVerdict $cacheVerdict
     * @param \Muon\DevProfiler\Model\Analysis\ShadowResolver $shadowResolver
     * @param \Muon\DevProfiler\Model\Analysis\QueryAnalyzer $queryAnalyzer
     * @param \Muon\DevProfiler\Model\Analysis\ResolutionSet $resolutions
     */
    public function __construct(
        private readonly CacheVerdict $cacheVerdict,
        private readonly ShadowResolver $shadowResolver,
        private readonly QueryAnalyzer $queryAnalyzer,
        private readonly ResolutionSet $resolutions
    ) {
    }

    /**
     * Verdict, shadow classification, SQL findings and the totals the banner shows.
     *
     * @param array<string,mixed> $run
     * @param array{slow_ms:float,nplus1:int,duplicate:int} $thresholds
     * @return array{verdict:array<string,mixed>,fallback:list<array<string,mixed>>,sql:array<string,mixed>,totals:array<string,int>}
     */
    public function analyse(array $run, array $thresholds): array
    {
        $request = $this->section($run, 'request');
        $verdict = $this->cacheVerdict->verdict($this->section($run, 'layout'), (string)($request['kind'] ?? 'page'));

        $fallback = $this->fallback($run);
        $sql = $this->queryAnalyzer->classify($this->list($run, 'queries'), $thresholds);

        return [
            'verdict' => $verdict,
            'fallback' => $fallback,
            'sql' => $sql,
            'totals' => [
                'statements' => (int)($sql['statements'] ?? 0),
                'shapes' => (int)($sql['shapes'] ?? 0),
                // Lookups, not files: Magento resolves the same file more than once per request, so
                // these two numbers differ and conflating them doubles the headline.
                'fallbacks' => count($this->list($run, 'fallback')),
                'files' => count($fallback),
                'shadowed' => $this->shadowedCount($fallback),
            ],
        ];
    }

    /**
     * Shadow classification, or nothing when the theme is unknown.
     *
     * ShadowResolver replays Magento's own lookup and needs a theme to rebuild the search
     * directories from. A run that never resolved one — a cache hit with no configured theme to
     * recover — cannot be classified, and guessing a theme would produce confident, wrong answers
     * about which file is live.
     *
     * @param array<string,mixed> $run
     * @return list<array<string,mixed>>
     */
    private function fallback(array $run): array
    {
        $resolutions = $this->list($run, 'fallback');

        if ($resolutions === []) {
            return [];
        }

        $themePath = (string)($this->section($run, 'context')['theme_path'] ?? '');

        if ($themePath === '') {
            return [];
        }

        // present() = collapse repeat lookups, then rank shadowed first. Shared with the console
        // renderer on purpose: without it the board showed four identical etc/view.xml rows where
        // `make profile` shows one, and a reader comparing the two surfaces could not tell which
        // was lying.
        return $this->resolutions->present($this->shadowResolver->classify($resolutions, $themePath));
    }

    /**
     * @param list<array<string,mixed>> $classified
     * @return int
     */
    private function shadowedCount(array $classified): int
    {
        $count = 0;

        foreach ($classified as $entry) {
            if (($entry['shadowed'] ?? []) !== []) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $run
     * @param string $key
     * @return list<array<string,mixed>>
     */
    private function list(array $run, string $key): array
    {
        $value = $run[$key] ?? [];

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

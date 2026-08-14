<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Board;

use Magento\Framework\App\RequestInterface;
use Muon\DevProfilerBoard\Model\Analysis\RunAnalysis;
use Muon\DevProfilerBoard\Model\Analysis\Thresholds;
use Muon\DevProfilerBoard\Model\Html\BoardPage;
use Muon\DevProfilerBoard\Model\Html\RunView;
use Muon\DevProfilerBoard\Model\Run\FilterReader;
use Muon\DevProfilerBoard\Model\Run\RunSelector;
use Muon\DevProfilerBoard\Model\Run\TokenFilter;

/**
 * Turns a request into a rendered run page.
 *
 * The board's front door and its permalink are the same view — `/muon_profiler/` is "the latest
 * page", `/muon_profiler/run/view?token=…` is "that one" — so the work lives here rather than being
 * written twice in two controllers that would then drift apart.
 *
 * Every value the request supplies is filtered here and nowhere else: the token through
 * TokenFilter, the thresholds through Thresholds' clamps, the panel against RunView's whitelist. A
 * controller receives already-safe values, which is why none of them needs to remember to validate.
 */
class RunPresenter
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Run\RunSelector $runs
     * @param \Muon\DevProfilerBoard\Model\Run\TokenFilter $tokens
     * @param \Muon\DevProfilerBoard\Model\Analysis\Thresholds $thresholds
     * @param \Muon\DevProfilerBoard\Model\Analysis\RunAnalysis $analysis
     * @param \Muon\DevProfilerBoard\Model\Html\RunView $view
     * @param \Muon\DevProfilerBoard\Model\Html\BoardPage $page
     * @param \Muon\DevProfilerBoard\Model\Run\FilterReader $filters
     */
    public function __construct(
        private readonly RunSelector $runs,
        private readonly TokenFilter $tokens,
        private readonly Thresholds $thresholds,
        private readonly RunAnalysis $analysis,
        private readonly RunView $view,
        private readonly BoardPage $page,
        private readonly FilterReader $filters
    ) {
    }

    /**
     * The complete board page for this request, or the "nothing to show" page.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return string
     */
    public function present(RequestInterface $request): string
    {
        $run = $this->run($request);

        if ($run === null) {
            return $this->emptyPage($request);
        }

        $state = $this->state($request);
        $analysis = $this->analysis->analyse($run, $this->thresholds->fromRequest($request));
        $token = (string)($run['token'] ?? '');

        return $this->page->render(
            'Run ' . $token . ' — Muon Profiler',
            $this->view->render($run, $analysis, $state),
            $token,
            $this->ledgerState($state),
            $this->filters->fromRequest($request)
        );
    }

    /**
     * The run this request is asking about.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return array<string,mixed>|null
     */
    public function run(RequestInterface $request): ?array
    {
        $token = $this->tokens->filter($this->stringParam($request, 'token'));

        // "any" mirrors the CLI's --any: without it the newest *document* wins, because a page
        // fires customer-section XHRs immediately behind it and the newest run is almost never the
        // page just loaded.
        return $request->getParam('any')
            ? $this->runs->selectAny($token)
            : $this->runs->select($token);
    }

    /**
     * Query state, filtered, for rendering back into forms and links.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return array<string,mixed>
     */
    public function state(RequestInterface $request): array
    {
        $thresholds = $this->thresholds->fromRequest($request);

        return [
            'token' => $this->tokens->filter($this->stringParam($request, 'token')),
            'panel' => $this->stringParam($request, 'panel'),
            'fallback' => $this->stringParam($request, 'fallback'),
            'shadowed' => $request->getParam('shadowed') ? '1' : null,
            'any' => $request->getParam('any') ? '1' : null,
            'nplus1' => $thresholds['nplus1'],
            'duplicate' => $thresholds['duplicate'],
            'slow' => $thresholds['slow_ms'],
        ];
    }

    /**
     * The subset of state a ledger link carries.
     *
     * The token is dropped — each row supplies its own — but the analysis sensitivity is kept, so
     * moving between runs does not silently reset the thresholds the reader chose.
     *
     * @param array<string,mixed> $state
     * @return array<string,string|int|float|null>
     */
    private function ledgerState(array $state): array
    {
        $carried = [];

        foreach (['panel', 'fallback', 'shadowed', 'nplus1', 'duplicate', 'slow'] as $key) {
            $value = $state[$key] ?? null;

            if (is_string($value) || is_int($value) || is_float($value)) {
                $carried[$key] = $value;
            }
        }

        return $carried;
    }

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @return string
     */
    private function emptyPage(RequestInterface $request): string
    {
        if ($this->tokens->filter($this->stringParam($request, 'token')) !== null) {
            // Never a dead end. A token going stale is not an error state — the ring evicts runs as
            // new ones arrive, so any bookmarked or long-open run URL reaches this page eventually,
            // through nobody's mistake. Without a way onward the reader has to edit the address bar.
            return $this->page->notice(
                'Run not found',
                'No run with that token is in the ring.',
                'The ring keeps the most recent runs and drops the oldest as new ones arrive, so a '
                . 'link to a specific run goes stale on its own. Nothing is wrong.',
                'Open the most recent run'
            );
        }

        // Straight after a clear the reader is not lost — they know why the ring is empty — but they
        // are one step from the trap the collector has a dedicated message for: the page cache is
        // still warm, so the next load resolves no files and the board looks broken. Say so here,
        // where they are, rather than letting them find out two reloads later.
        $cleared = $request->getParam('cleared');

        if ($cleared !== null) {
            return $this->page->notice(
                'Ring cleared',
                sprintf('%d run%s deleted. Reload a storefront page to capture a new one.', (int)$cleared, (int)$cleared === 1 ? '' : 's'),
                'For a cold capture, flush the page cache first — otherwise the next load is served '
                . 'from it, resolves no files and loads no theme, and the board will look empty for a '
                . 'reason that has nothing to do with clearing: bin/magento cache:flush'
            );
        }

        return $this->page->notice(
            'No runs recorded yet',
            'Load a storefront page, then come back.',
            'If runs never appear, confirm the web request runs in developer mode — MAGE_MODE in the '
            . 'FastCGI params can differ from what bin/magento deploy:mode:show reports for the CLI.'
        );
    }

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param string $name
     * @return string|null
     */
    private function stringParam(RequestInterface $request, string $name): ?string
    {
        $value = $request->getParam($name);

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }
}

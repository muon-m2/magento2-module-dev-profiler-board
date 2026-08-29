<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Compare;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Muon\DevProfilerBoard\Model\Board\LedgerResolver;
use Muon\DevProfilerBoard\Model\Html\BoardPage;
use Muon\DevProfilerBoard\Model\Html\ComparePanel;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;
use Muon\DevProfilerBoard\Model\Run\RunDiff;
use Muon\DevProfilerBoard\Model\Run\RunSelector;
use Muon\DevProfilerBoard\Model\Run\TokenFilter;

/**
 * Two runs, side by side.
 *
 * Both tokens are required — there is no "compare against the latest" default, because the run a
 * reader means is rarely the newest one and guessing would produce a confident comparison of the
 * wrong pair.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Muon\DevProfilerBoard\Model\Run\RunSelector $runs
     * @param \Muon\DevProfilerBoard\Model\Run\TokenFilter $tokens
     * @param \Muon\DevProfilerBoard\Model\Run\RunDiff $diff
     * @param \Muon\DevProfilerBoard\Model\Html\ComparePanel $panel
     * @param \Muon\DevProfilerBoard\Model\Html\BoardPage $page
     * @param \Muon\DevProfilerBoard\Model\Url\UrlBuilder $urls
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     * @param \Muon\DevProfilerBoard\Model\Board\LedgerResolver $ledgers
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunSelector $runs,
        private readonly TokenFilter $tokens,
        private readonly RunDiff $diff,
        private readonly ComparePanel $panel,
        private readonly BoardPage $page,
        private readonly UrlBuilder $urls,
        private readonly BoardResponse $response,
        private readonly LedgerResolver $ledgers
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->response->isOpen()) {
            return $this->response->notFound();
        }

        $action = $this->urls->link(UrlBuilder::ROUTE_COMPARE);
        $left = $this->load('a');
        $right = $this->load('b');

        if ($left === null || $right === null) {
            return $this->response->html($this->page->render(
                'Compare runs — Muon Profiler',
                $this->panel->render($this->emptyDiff(), $action),
                null,
                [],
                null,
                $this->ledgers->resolve()
            ));
        }

        return $this->response->html($this->page->render(
            'Compare runs — Muon Profiler',
            $this->panel->render($this->diff->compare($left, $right), $action),
            (string)($right['token'] ?? ''),
            [],
            null,
            $this->ledgers->resolve()
        ));
    }

    /**
     * @param string $param
     * @return array<string,mixed>|null
     */
    private function load(string $param): ?array
    {
        $value = $this->request->getParam($param);
        $token = $this->tokens->filter(is_scalar($value) ? (string)$value : null);

        return $token === null ? null : $this->runs->select($token);
    }

    /**
     * The shape ComparePanel expects when it has nothing to compare, so the form still renders and
     * a reader can fill in the tokens rather than meeting a dead end.
     *
     * @return array<string,mixed>
     */
    private function emptyDiff(): array
    {
        return [
            'same_url' => true,
            'sides' => [
                'a' => ['token' => $this->stringParam('a')],
                'b' => ['token' => $this->stringParam('b')],
            ],
            'verdict' => null,
            'metrics' => [],
            'handles' => [],
            'queries' => [],
            'winners' => [],
        ];
    }

    /**
     * @param string $name
     * @return string
     */
    private function stringParam(string $name): string
    {
        $value = $this->request->getParam($name);

        return (string)($this->tokens->filter(is_scalar($value) ? (string)$value : null) ?? '');
    }
}

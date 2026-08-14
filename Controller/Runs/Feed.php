<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Runs;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;
use Muon\DevProfilerBoard\Model\Run\FilterReader;
use Muon\DevProfilerBoard\Model\Run\RunSelector;

/**
 * The ledger, as JSON, for the auto-refresh poll.
 *
 * This is the one board route a browser calls repeatedly, so it is deliberately the cheapest: it
 * reads recorded facts and asks for a cache verdict, and never runs the shadow resolver or the query
 * analyzer. Those stat the filesystem and classify hundreds of statement groups, and would turn a
 * background tab into a load generator on the instance being profiled.
 */
class Feed implements HttpGetActionInterface
{
    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Muon\DevProfilerBoard\Model\Run\RunSelector $runs
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     * @param \Muon\DevProfilerBoard\Model\Run\FilterReader $filters
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunSelector $runs,
        private readonly BoardResponse $response,
        private readonly FilterReader $filters
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

        $limit = $this->request->getParam('limit');

        // The poll must apply the same filter the page was rendered with, or the ledger would
        // silently repopulate with rows the reader filtered out a few seconds earlier.
        $filter = $this->filters->fromRequest($this->request);
        $rows = $this->runs->feed(is_numeric($limit) ? (int)$limit : null, $filter);

        // `total` is the ring, `runs` is what fits in the ledger. They differ whenever feedLimit is
        // below the ring size, and the header counter needs both to avoid reporting a cap as a
        // total.
        $encoded = json_encode(
            [
                'runs' => $rows,
                'total' => $this->runs->total(),
                'matching' => $this->runs->matching($filter),
                'filtered' => $filter->isActive(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->response->json($encoded === false ? '{"runs":[],"total":0,"matching":0,"filtered":false}' : $encoded);
    }
}

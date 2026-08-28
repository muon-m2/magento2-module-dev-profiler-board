<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Board;

use Magento\Store\Model\StoreManagerInterface;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use Muon\DevProfilerBoard\Model\Run\RunSelector;

/**
 * Resolves everything the ledger needs, once, for whoever is about to render it.
 *
 * This used to live inside `BoardPage`, which put ring reads and store resolution in `Model\Html` —
 * the namespace whose job is markup, and where every other class takes `Tag` and `Widgets` and
 * nothing else. Both callers that render a board page need the same three answers, so they share
 * this rather than each growing a `RunSelector`.
 *
 * `RunSelector` memoizes its scan per request, so asking for the rows and the match count costs one
 * pass over the ring rather than two.
 */
class LedgerResolver
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Run\RunSelector $runs
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RunSelector $runs,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter|null $filter
     * @return \Muon\DevProfilerBoard\Model\Board\LedgerData
     */
    public function resolve(?RunFilter $filter = null): LedgerData
    {
        $filter ??= new RunFilter();

        return new LedgerData(
            $this->runs->feed(null, $filter),
            $this->runs->matching($filter),
            $this->runs->total(),
            $this->storeCode()
        );
    }

    /**
     * @return string
     */
    private function storeCode(): string
    {
        try {
            return (string)$this->storeManager->getStore()->getCode();
        } catch (\Throwable) {
            // The board is still readable without knowing which store rendered it.
            return '';
        }
    }
}

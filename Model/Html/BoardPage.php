<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Magento\Store\Model\StoreManagerInterface;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use Muon\DevProfilerBoard\Model\Run\RunSelector;

/**
 * Puts a page together: the ledger on the left, whatever the controller built on the right.
 *
 * Controllers stay thin because of this class — they resolve input, ask for analysis, and hand the
 * result here. None of them knows how a document is assembled, which is what keeps the
 * theme-independence guarantee in one place instead of eight.
 */
class BoardPage
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Document $document
     * @param \Muon\DevProfilerBoard\Model\Html\LedgerRail $rail
     * @param \Muon\DevProfilerBoard\Model\Run\RunSelector $runs
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Document $document,
        private readonly LedgerRail $rail,
        private readonly RunSelector $runs,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param string $title
     * @param string $main Markup for the detail column.
     * @param string|null $selected Token of the run being shown, for the ledger's current row.
     * @param array<string,string|int|float|null> $state Analysis state carried into ledger links.
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter|null $filter
     * @return string
     */
    public function render(
        string $title,
        string $main,
        ?string $selected = null,
        array $state = [],
        ?RunFilter $filter = null
    ): string {
        $filter ??= new RunFilter();
        $rows = $this->runs->feed(null, $filter);
        $matching = $this->runs->matching($filter);

        return $this->document->render(
            $title,
            // The filter is carried into every ledger link, so moving between runs does not silently
            // drop it — the same reason the analysis thresholds are carried.
            $this->rail->render($rows, $selected, $state + $filter->toQuery(), $filter, $matching, $this->runs->total()),
            $main,
            [
                'shown' => count($rows),
                'runs' => $this->runs->total(),
                'matching' => $matching,
                'filtered' => $filter->isActive(),
                'store' => $this->storeCode(),
            ],
            // Same query the ledger links carry, so the live feed polls the filtered slice.
            $filter->toQuery()
        );
    }

    /**
     * A page with no ledger — used when there is nothing to list.
     *
     * @param string $title
     * @param string $message
     * @param string $hint
     * @param string|null $onward Label for a link back to the board.
     * @return string
     */
    public function notice(string $title, string $message, string $hint = '', ?string $onward = null): string
    {
        // The counter is passed even here. A "run not found" page rendered with no meta would report
        // "no runs yet" while the ring held fifty — the same class of untruth as printing a cap as a
        // total, on the one page where a reader is already unsure what state the tool is in.
        return $this->document->notice($title, $message, $hint, [
            'runs' => $this->runs->total(),
            'store' => $this->storeCode(),
        ], $onward);
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

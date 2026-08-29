<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfilerBoard\Model\Board\LedgerData;
use Muon\DevProfilerBoard\Model\Run\RunFilter;

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
     */
    public function __construct(
        private readonly Document $document,
        private readonly LedgerRail $rail
    ) {
    }

    /**
     * @param string $title
     * @param string $main Markup for the detail column.
     * @param string|null $selected Token of the run being shown, for the ledger's current row.
     * @param array<string,string|int|float|null> $state Analysis state carried into ledger links.
     * @param \Muon\DevProfilerBoard\Model\Run\RunFilter|null $filter
     * @param \Muon\DevProfilerBoard\Model\Board\LedgerData|null $ledger Rows, counts and store,
     *        resolved by the caller. This class renders; it does not read the ring.
     * @return string
     */
    public function render(
        string $title,
        string $main,
        ?string $selected = null,
        array $state = [],
        ?RunFilter $filter = null,
        ?LedgerData $ledger = null
    ): string {
        $filter ??= new RunFilter();
        $ledger ??= new LedgerData();
        $rows = $ledger->rows;
        $matching = $ledger->matching;

        return $this->document->render(
            $title,
            // The filter is carried into every ledger link, so moving between runs does not silently
            // drop it — the same reason the analysis thresholds are carried.
            $this->rail->render(
                $rows,
                $selected,
                $state + $filter->toQuery(),
                $filter,
                $matching,
                $ledger->total,
                $ledger->formKey
            ),
            $main,
            [
                'shown' => count($rows),
                'runs' => $ledger->total,
                'matching' => $matching,
                'filtered' => $filter->isActive(),
                'store' => $ledger->store,
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
     * @param \Muon\DevProfilerBoard\Model\Board\LedgerData|null $ledger
     * @return string
     */
    public function notice(
        string $title,
        string $message,
        string $hint = '',
        ?string $onward = null,
        ?LedgerData $ledger = null
    ): string {
        $ledger ??= new LedgerData();

        // The counter is passed even here. A "run not found" page rendered with no meta would report
        // "no runs yet" while the ring held fifty — the same class of untruth as printing a cap as a
        // total, on the one page where a reader is already unsure what state the tool is in.
        return $this->document->notice($title, $message, $hint, [
            'runs' => $ledger->total,
            'store' => $ledger->store,
        ], $onward);
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Board;

/**
 * Everything the ledger needs, resolved before rendering starts.
 *
 * `BoardPage` used to inject `RunSelector` and `StoreManagerInterface` and read the ring itself,
 * which put data access inside `Model\Html` — the one namespace whose job is markup. It was also
 * the only class in there with no unit test, because testing it meant mocking the store, the
 * selector and the store manager to render some HTML.
 *
 * Passing this in instead keeps `Model\Html` renderers taking `Tag` and `Widgets`, and lets the
 * caller — which already resolved the filter — resolve the rows in the same place.
 */
class LedgerData
{
    /**
     * @param list<array<string,mixed>> $rows Ledger rows, already filtered and capped.
     * @param int $matching How many runs match across the whole ring, not just this page.
     * @param int $total How many runs the ring holds.
     * @param string $store Store code for the top bar; empty when it cannot be resolved.
     * @param string $formKey For the Clear form. Resolved here rather than inside the renderer,
     *        because FormKey is session-backed and materialising it is a side effect that a markup
     *        builder has no business causing.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $matching = 0,
        public readonly int $total = 0,
        public readonly string $store = '',
        public readonly string $formKey = ''
    ) {
    }
}

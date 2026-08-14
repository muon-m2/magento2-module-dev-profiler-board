<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Access;

use Muon\DevProfiler\Model\Run\Gate;

/**
 * Decides whether the board may be served at all.
 *
 * It asks the collector's own gate rather than re-testing developer mode, and that is the entire
 * point of the class. Two independent answers to "is profiling active here" would eventually
 * disagree — a board that renders while nothing is being recorded, or worse, one that renders on an
 * installation the collectors have correctly decided is production. There is one answer, and it
 * lives in Muon_DevProfiler.
 *
 * Gate fails closed: an installation that cannot report its own mode is treated as production. That
 * property is inherited here for free, and is why there is deliberately no configuration flag, no
 * IP allowlist and no "allow in production" argument to find and flip.
 */
class BoardGate
{
    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     */
    public function __construct(
        private readonly Gate $gate
    ) {
    }

    /**
     * Whether this request may see the board.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->gate->isProfiled();
    }
}

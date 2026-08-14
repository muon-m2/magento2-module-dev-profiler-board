<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Analysis;

use Magento\Framework\App\RequestInterface;
use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;

/**
 * Reads the SQL analysis sensitivity out of the query string.
 *
 * The collector stores statement groups; nothing about "this is an N+1" is decided until somebody
 * reads them. That is what makes a threshold control possible at all: changing one re-examines a
 * capture taken an hour ago without reloading the page it describes.
 *
 * Everything is clamped. A threshold arriving as 0, -1 or "drop table" would otherwise make the
 * analyzer classify every statement on the page as a finding, which is indistinguishable from a
 * broken tool.
 */
class Thresholds
{
    /** Below 2 executions, "repeated" has no meaning. */
    private const MIN_EXECUTIONS = 2;

    /** A shape executed a thousand times is a finding at any sane setting. */
    private const MAX_EXECUTIONS = 1000;

    /** Sub-millisecond thresholds report the whole page. */
    private const MIN_SLOW_MS = 0.1;

    /** A single statement over a minute is not something a threshold needs to reach for. */
    private const MAX_SLOW_MS = 60000.0;

    /**
     * Thresholds in the shape QueryAnalyzer::classify() expects.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return array{slow_ms:float,nplus1:int,duplicate:int}
     */
    public function fromRequest(RequestInterface $request): array
    {
        return [
            'slow_ms' => $this->float(
                $request->getParam('slow'),
                QueryAnalyzer::DEFAULT_SLOW_MS,
                self::MIN_SLOW_MS,
                self::MAX_SLOW_MS
            ),
            'nplus1' => $this->int(
                $request->getParam('nplus1'),
                QueryAnalyzer::DEFAULT_NPLUS1,
                self::MIN_EXECUTIONS,
                self::MAX_EXECUTIONS
            ),
            'duplicate' => $this->int(
                $request->getParam('duplicate'),
                QueryAnalyzer::DEFAULT_DUPLICATE,
                self::MIN_EXECUTIONS,
                self::MAX_EXECUTIONS
            ),
        ];
    }

    /**
     * The defaults, for a caller with no request in hand.
     *
     * @return array{slow_ms:float,nplus1:int,duplicate:int}
     */
    public function defaults(): array
    {
        return [
            'slow_ms' => QueryAnalyzer::DEFAULT_SLOW_MS,
            'nplus1' => QueryAnalyzer::DEFAULT_NPLUS1,
            'duplicate' => QueryAnalyzer::DEFAULT_DUPLICATE,
        ];
    }

    /**
     * A whole-number threshold, or the default when the value is unusable.
     *
     * @param mixed $value
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function int(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            return $default;
        }

        return max($min, min($max, (int)(string)$value));
    }

    /**
     * A millisecond threshold, or the default when the value is unusable.
     *
     * @param mixed $value
     * @param float $default
     * @param float $min
     * @param float $max
     * @return float
     */
    private function float(mixed $value, float $default, float $min, float $max): float
    {
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            return $default;
        }

        return max($min, min($max, (float)(string)$value));
    }
}

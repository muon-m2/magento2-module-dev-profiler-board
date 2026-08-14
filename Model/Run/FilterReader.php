<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

use Magento\Framework\App\RequestInterface;

/**
 * Builds a RunFilter from the query string.
 *
 * Separate from the filter itself for the same reason `Thresholds` is separate from
 * `QueryAnalyzer`: reading untrusted input is a different job from using the result, and keeping
 * them apart means the value object can be constructed directly in a test without inventing a
 * request.
 *
 * Everything is whitelisted or clamped here, so nothing downstream has to wonder. A verdict that is
 * not a verdict, a method that is not a method and a negative bound are all dropped rather than
 * passed on to be compared against.
 */
class FilterReader
{
    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @return \Muon\DevProfilerBoard\Model\Run\RunFilter
     */
    public function fromRequest(RequestInterface $request): RunFilter
    {
        return new RunFilter(
            $this->verdicts($request->getParam('verdict')),
            $this->method($request->getParam('method')),
            $this->positiveInt($request->getParam('status')),
            $this->positiveFloat($request->getParam('min_ms')),
            $this->positiveFloat($request->getParam('max_ms')),
            $this->positiveInt($request->getParam('min_stmt')),
            $this->positiveInt($request->getParam('max_stmt')),
            $this->text($request->getParam('url'))
        );
    }

    /**
     * Accepts either repeated `verdict[]` checkboxes or a comma-separated `verdict` — the form
     * submits the first, a hand-written or shared link is more likely to carry the second.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function verdicts(mixed $value): array
    {
        $raw = is_array($value) ? $value : explode(',', is_scalar($value) ? (string)$value : '');

        $wanted = array_map(
            static fn (mixed $item): string => strtolower(trim(is_scalar($item) ? (string)$item : '')),
            $raw
        );

        return array_values(array_intersect(RunFilter::VERDICTS, $wanted));
    }

    /**
     * A free-text needle, trimmed. Whitespace alone is not a criterion.
     *
     * @param mixed $value
     * @return string|null
     */
    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function method(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $method = strtoupper(trim((string)$value));

        return in_array($method, RunFilter::METHODS, true) ? $method : null;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function positiveInt(mixed $value): ?int
    {
        $number = $this->positiveFloat($value);

        return $number === null ? null : (int)$number;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function positiveFloat(mixed $value): ?float
    {
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            return null;
        }

        $number = (float)(string)$value;

        return $number < 0 ? null : $number;
    }
}

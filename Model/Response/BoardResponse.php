<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Response;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Muon\DevProfilerBoard\Model\Access\BoardGate;

/**
 * Builds every response the board sends.
 *
 * One place, for one reason: **no controller may ever construct a page**. A `Result\Page` would
 * merge layout XML and resolve templates through the theme fallback chain — coupling the board to
 * whichever theme is active, and adding its own resolutions to the fallback evidence it exists to
 * display. Everything here is a `Result\Raw` carrying a string this module built itself.
 *
 * That includes the closed-gate path. Forwarding to `noroute` would have rendered a themed CMS 404
 * and reintroduced exactly the coupling the module is built to avoid, so a closed board answers with
 * a plain-text 404 and no detail — which also means the board's existence is not disclosed on an
 * installation where it is unavailable.
 */
class BoardResponse
{
    private const HTML = 'text/html; charset=utf-8';
    private const JSON = 'application/json';
    private const TEXT = 'text/plain; charset=utf-8';
    private const CSS = 'text/css';
    private const JS = 'application/javascript';

    /**
     * @param \Magento\Framework\Controller\Result\RawFactory $rawFactory
     * @param \Muon\DevProfilerBoard\Model\Access\BoardGate $gate
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly BoardGate $gate
    ) {
    }

    /**
     * Whether this request may see the board at all.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->gate->isOpen();
    }

    /**
     * @param string $html
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function html(string $html): Raw
    {
        return $this->raw($html, self::HTML);
    }

    /**
     * @param string $json
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function json(string $json): Raw
    {
        return $this->raw($json, self::JSON);
    }

    /**
     * @param string $text
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function text(string $text): Raw
    {
        return $this->raw($text, self::TEXT);
    }

    /**
     * @param string $css
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function css(string $css): Raw
    {
        return $this->raw($css, self::CSS);
    }

    /**
     * @param string $js
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function javascript(string $js): Raw
    {
        return $this->raw($js, self::JS);
    }

    /**
     * The response for a request that may not see the board, and for one that asked for a run
     * that is not in the ring.
     *
     * @param string $message
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function notFound(string $message = 'Not found.'): Raw
    {
        $result = $this->raw($message . "\n", self::TEXT);
        $result->setHttpResponseCode(404);

        return $result;
    }

    /**
     * @param string $contents
     * @param string $contentType
     * @return \Magento\Framework\Controller\Result\Raw
     */
    private function raw(string $contents, string $contentType): Raw
    {
        $result = $this->rawFactory->create();
        $result->setContents($contents);

        // no-store rather than no-cache: a profiler view must never be served from anything's cache,
        // including a proxy the developer forgot is in front of this instance. noindex is belt and
        // braces — the board is developer-mode only, but a dev instance reachable by a crawler is a
        // situation that happens.
        $result->setHeader('Content-Type', $contentType, true);
        $result->setHeader('Cache-Control', 'no-store, private', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        return $result;
    }
}

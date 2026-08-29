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
    // Charset declared explicitly on every type, assets included. The HTML document declares UTF-8
    // first and browsers inherit it for a same-origin stylesheet, so this is not currently broken —
    // but that is an implicit chain, and both files carry non-ASCII in their comments.
    private const CSS = 'text/css; charset=utf-8';
    private const JS = 'application/javascript; charset=utf-8';

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
    public function css(string $css, ?string $etag = null): Raw
    {
        return $this->asset($css, self::CSS, $etag);
    }

    /**
     * @param string $js
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function javascript(string $js, ?string $etag = null): Raw
    {
        return $this->asset($js, self::JS, $etag);
    }

    /**
     * The response for a request that may not see the board, and for one that asked for a run
     * that is not in the ring.
     *
     * @param string $message
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function notFound(string $message = ''): Raw
    {
        // Empty by default, and with none of the board's own headers. An 11-byte text/plain body
        // carrying Cache-Control and X-Robots-Tag was a one-request oracle for "this store runs
        // Muon_DevProfilerBoard", where an undefined action under the same frontName falls through
        // to cms_noroute and returns a themed HTML 404. The class docblock claimed the board's
        // existence was not disclosed; it was.
        $result = $this->rawFactory->create();
        $result->setContents($message === '' ? '' : $message . "\n");
        $result->setHttpResponseCode(404);

        return $result;
    }

    /**
     * A cacheable static asset: the board's own CSS or JS.
     *
     * @param string $contents
     * @param string $contentType
     * @param string|null $etag
     * @return \Magento\Framework\Controller\Result\Raw
     */
    private function asset(string $contents, string $contentType, ?string $etag): Raw
    {
        $result = $this->raw($contents, $contentType);

        if ($etag !== null) {
            // Revalidation rather than no-store. These two files are versionless and carry no
            // per-visitor state, so the browser can ask instead of re-fetching — which saves a full
            // Magento bootstrap per board page load, twice.
            $result->setHeader('Cache-Control', 'no-cache, private', true);
            $result->setHeader('ETag', $etag, true);
        }

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

        // The bodies carry recorded request URIs, which a visitor chooses. The content types are
        // correct, so no current browser sniffs them into HTML — this is the defence-in-depth layer
        // for a future one, an embedding context, or a proxy that rewrites Content-Type.
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        return $result;
    }
}

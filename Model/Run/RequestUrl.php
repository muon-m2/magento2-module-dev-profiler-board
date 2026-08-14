<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

/**
 * Decides whether a recorded request URL may be turned into a link.
 *
 * The collector stores `request.url` exactly as the request arrived, and **the request URL is
 * attacker-controlled**: anyone who can reach the storefront chooses it. Rendering it as text is
 * already handled by Tag's escaping, but rendering it as an `href` is a different question, because
 * a link is something the reader is invited to follow.
 *
 * Two shapes have to be refused rather than escaped:
 *
 *  - `//evil.com/x` — a protocol-relative URL. It looks like a path, escapes cleanly, and navigates
 *    to another origin. A request to that path is recorded verbatim, so it is trivial to plant.
 *  - `https://…`, `javascript:…` — anything carrying a scheme. Escaper strips the dangerous ones,
 *    but a link off this instance is not what the reader is being offered here either way.
 *
 * What survives is a single-slash root-relative path, which the browser resolves against the board's
 * own origin — the same Magento instance the run came from. Nothing has to be reconstructed, and
 * the result cannot leave the site.
 */
class RequestUrl
{
    /**
     * One leading slash, and the next character is not another slash.
     */
    private const LOCAL_PATH = '#^/(?!/)#';

    /**
     * The URL as a followable link, or null when it must stay plain text.
     *
     * @param mixed $url As recorded in request.url.
     * @return string|null
     */
    public function openable(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        return preg_match(self::LOCAL_PATH, $url) === 1 ? $url : null;
    }
}

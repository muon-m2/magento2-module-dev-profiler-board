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
 *  - `/\evil.com/x` — the same attack with a backslash. In the WHATWG URL parser's relative-slash
 *    state a `\` routes into special-authority-ignore-slashes exactly as `/` does, so browsers
 *    resolve this to another origin too, while `escapeUrl()` leaves the backslash untouched. It is
 *    worse than the `//` form, because `/\evil.example\@muon.localhost/` renders with the real
 *    host at the end and reads as local.
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
     * One leading slash, and the next character is neither another slash nor a backslash.
     */
    private const LOCAL_PATH = '#^/(?![/\\\\])#';

    /**
     * A backslash anywhere in the first segment is refused outright.
     *
     * Browsers normalise `\` to `/` while resolving, so `/foo\@evil.example/` reaches a different
     * origin than the string suggests. Nothing legitimate in a Magento request URI needs one.
     */
    private const FIRST_SEGMENT_BACKSLASH = '#^/[^/]*\\\\#';

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

        if (preg_match(self::LOCAL_PATH, $url) !== 1) {
            return null;
        }

        return preg_match(self::FIRST_SEGMENT_BACKSLASH, $url) === 1 ? null : $url;
    }
}

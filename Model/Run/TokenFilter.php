<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Run;

/**
 * Reduces a run token to the only characters a run token can contain.
 *
 * RunStore applies the same rule before a token reaches a filesystem path, so this is not the
 * security boundary — it is the reason no controller ever holds a value it would have to remember
 * to escape. A token arrives from the query string, is filtered once on the way in, and is
 * thereafter safe to put in a link, an attribute or a heading.
 */
class TokenFilter
{
    /**
     * A run token is hex and nothing else — bin2hex(random_bytes(6)).
     */
    private const PATTERN = '/[^a-f0-9]/';

    /**
     * The token, or null when nothing usable was supplied.
     *
     * @param string|null $token
     * @return string|null
     */
    public function filter(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        // Lowercased first, deliberately. A token is hex and is stored lowercase, but it travels by
        // being read off a screen and typed back in — and anything that uppercases it on the way
        // (a heading, a spreadsheet, a chat client) would otherwise have every A-F character
        // stripped out here, leaving a shorter token that resolves to nothing or, worse, to a
        // different run. Hex is case-insensitive; treating it that way costs one function call.
        $filtered = (string)preg_replace(self::PATTERN, '', strtolower($token));

        return $filtered === '' ? null : $filtered;
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Magento\Framework\UrlInterface;

/**
 * Builds board links.
 *
 * Uses Magento's URL builder rather than concatenating a path, because this project routes stores by
 * path prefix — /en-us/, /de-de/ — and a hand-built "/muon_profiler/run/view" would 404 on every
 * store view but the default. The board is opened from whichever store happened to render the run
 * being investigated, so that is the common case, not the edge case.
 *
 * Analysis state — thresholds, filters, the open panel — lives in the query string rather than in a
 * session or a cookie. That is what makes any view of the board a link somebody can paste into an
 * issue and have land on the same evidence.
 */
class UrlBuilder
{
    public const ROUTE_INDEX = 'muon_profiler/index/index';
    public const ROUTE_RUN = 'muon_profiler/run/view';
    public const ROUTE_RUN_JSON = 'muon_profiler/run/json';
    public const ROUTE_RUN_MARKDOWN = 'muon_profiler/run/markdown';
    public const ROUTE_FEED = 'muon_profiler/runs/feed';
    public const ROUTE_CLEAR = 'muon_profiler/runs/clear';
    public const ROUTE_COMPARE = 'muon_profiler/compare/index';
    public const ROUTE_ASSET_CSS = 'muon_profiler/asset/stylesheet';
    public const ROUTE_ASSET_JS = 'muon_profiler/asset/script';

    /**
     * @param \Magento\Framework\UrlInterface $url
     */
    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * A board URL with the supplied query.
     *
     * @param string $route One of the ROUTE_* constants.
     * @param array<string,string|int|float|null> $query Null values are dropped.
     * @return string
     */
    public function link(string $route, array $query = []): string
    {
        $params = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->url->getUrl($route, $params === [] ? [] : ['_query' => $params]);
    }

    /**
     * A run view URL that carries the current analysis state forward.
     *
     * @param string $token
     * @param array<string,string|int|float|null> $state Thresholds, filters, open panel.
     * @return string
     */
    public function run(string $token, array $state = []): string
    {
        return $this->link(self::ROUTE_RUN, ['token' => $token] + $state);
    }
}

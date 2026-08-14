<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\UrlInterface;
use Muon\DevProfilerBoard\Model\Html\FilterPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\UrlBuilder;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * @see FilterPanel
 */
class FilterPanelTest extends TestCase
{
    use UnitEscaper;

    private FilterPanel $panel;

    protected function setUp(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/en-us/' . $route
        );

        $this->panel = new FilterPanel(new Tag($this->unitEscaper()), new UrlBuilder($url));
    }

    public function testTheFormIsCollapsedWhenNothingIsFiltered(): void
    {
        $html = $this->panel->render(new RunFilter(), 30, 30);

        self::assertStringContainsString('<form class="filter-form" method="get"', $html);
        self::assertStringContainsString(' hidden', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
    }

    /**
     * A reader arriving on a filtered link must see why the ledger is short without hunting for a
     * toggle — so the panel opens itself when a filter is already set.
     */
    public function testTheFormIsOpenWhenAFilterIsAlreadySet(): void
    {
        $html = $this->panel->render(new RunFilter(['miss']), 10, 30);

        self::assertStringContainsString('aria-expanded="true"', $html);
        self::assertStringNotContainsString('<form class="filter-form" method="get" action="/en-us/muon_profiler/index/index" hidden', $html);
    }

    public function testTheToggleCarriesTheActiveCount(): void
    {
        self::assertStringContainsString('Filter', $this->panel->render(new RunFilter(), 30, 30));
        self::assertStringContainsString('Filter · 2', $this->panel->render(new RunFilter(['miss'], 'GET'), 5, 30));
    }

    public function testACheckedVerdictIsMarkedAndColoured(): void
    {
        $html = $this->panel->render(new RunFilter(['uncacheable']), 3, 30);

        // Class attributes are entity-escaped, so assert the rendered form rather than the source
        // form — the same &#x20; the browser decodes back to a space.
        self::assertStringContainsString('verdict-pick&#x20;is-on&#x20;v-bad', $html);
        self::assertStringContainsString('value="uncacheable" checked', $html);
    }

    public function testTheCurrentValuesAreRenderedBackIntoTheControls(): void
    {
        $html = $this->panel->render(new RunFilter([], 'POST', 404, 100.0, 200.0, 5, 50), 1, 30);

        // One attribute at a time: asserting a multi-attribute substring would pin the rendering
        // order, which is not a contract.
        self::assertStringContainsString('value="POST" selected', $html);
        self::assertStringContainsString('name="status"', $html);
        self::assertStringContainsString('value="404"', $html);
        self::assertStringContainsString('name="min_ms"', $html);
        self::assertStringContainsString('value="100"', $html);
        self::assertStringContainsString('name="max_stmt"', $html);
        self::assertStringContainsString('value="50"', $html);
    }

    /**
     * A short ledger has two very different causes — a quiet ring, and a filter hiding most of it.
     * Saying which is the whole reason this line exists.
     */
    public function testTheSummaryStatesMatchesAgainstTheRing(): void
    {
        self::assertStringContainsString('11 of 33 runs match.', $this->panel->render(new RunFilter(['miss']), 11, 33));
        self::assertStringContainsString('No run in the ring matches — 0 of 33.', $this->panel->render(new RunFilter(['hit']), 0, 33));
    }

    public function testNoSummaryIsShownWhenNothingIsFiltered(): void
    {
        self::assertStringNotContainsString('filter-summary', $this->panel->render(new RunFilter(), 30, 30));
    }

    /**
     * Clearing is navigation back to the unfiltered ledger, so it is a link — it must work with
     * JavaScript off, and it only appears when there is something to clear.
     */
    public function testClearAppearsOnlyWhenAFilterIsSet(): void
    {
        self::assertStringNotContainsString('>Clear<', $this->panel->render(new RunFilter(), 30, 30));
        self::assertStringContainsString('>Clear<', $this->panel->render(new RunFilter(['miss']), 5, 30));
    }

    public function testTheFormSubmitsAsAGetSoAFilteredLedgerIsALink(): void
    {
        self::assertStringContainsString('method="get"', $this->panel->render(new RunFilter(), 30, 30));
    }
}

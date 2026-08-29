<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\UrlInterface;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * `Widgets` and `UrlBuilder` are shared by every panel, so a defect in either breaks several
 * surfaces at once — and neither had a test of its own. They were only ever exercised as a side
 * effect of testing something else, which is exactly the position where a shared primitive stops
 * being trustworthy.
 *
 * @see Widgets
 * @see UrlBuilder
 */
class StructureTest extends TestCase
{
    use UnitEscaper;

    private Widgets $ui;

    private UrlBuilder $urls;

    protected function setUp(): void
    {
        $tag = new Tag($this->unitEscaper());
        $this->ui = new Widgets($tag);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $route, array $params = []): string {
                $query = $params['_query'] ?? [];
                $base = 'https://muon.localhost/en-us/' . $route;

                return $query === [] ? $base : $base . '?' . http_build_query($query);
            }
        );
        $this->urls = new UrlBuilder($url);
    }

    /**
     * Header cells must carry scope. Implicit header-row inference is not guaranteed across screen
     * readers, and these tables are the panels' primary content.
     */
    public function testTableHeadersAreScoped(): void
    {
        $html = $this->ui->table(['Block', 'Class'], [['a', 'b']]);

        self::assertSame(2, substr_count($html, 'scope="col"'));
        self::assertStringContainsString('<th', $html);
    }

    public function testATableCanCarryAnAccessibleName(): void
    {
        $html = $this->ui->table(['A'], [['1']], [], 'Recorded statement shapes');

        self::assertStringContainsString('<caption', $html);
        self::assertStringContainsString('Recorded statement shapes', $html);
    }

    public function testATableWithoutACaptionEmitsNoEmptyOne(): void
    {
        self::assertStringNotContainsString('<caption', $this->ui->table(['A'], [['1']]));
    }

    public function testNumericColumnsAreMarkedForAlignment(): void
    {
        $html = $this->ui->table(['Name', 'Count'], [['x', '42']], [1]);

        self::assertStringContainsString('class="n"', $html);
    }

    /**
     * Panel headings sit under the page's h1. They were all h3 with no h1 or h2 above them, so the
     * outline started two levels down with nothing to orient against.
     */
    public function testPanelHeadingsDefaultToTheLevelBelowThePageTitle(): void
    {
        self::assertStringContainsString('<h2', $this->ui->heading('Request'));
    }

    public function testASubSectionCanAskForALowerLevel(): void
    {
        self::assertStringContainsString('<h3', $this->ui->heading('Handles', null, 'h3'));
    }

    public function testAnUnknownHeadingLevelFallsBackRatherThanEmittingIt(): void
    {
        $html = $this->ui->heading('X', null, 'script');

        self::assertStringContainsString('<h2', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testHeadingTextIsEscaped(): void
    {
        self::assertStringNotContainsString('<script>', $this->ui->heading('<script>alert(1)</script>'));
    }

    public function testARunLinkCarriesTheTokenAndTheAnalysisState(): void
    {
        $href = $this->urls->run('abc123', ['panel' => 'sql', 'slow' => 50]);

        self::assertStringContainsString('token=abc123', $href);
        self::assertStringContainsString('panel=sql', $href);
        self::assertStringContainsString('slow=50', $href);
    }

    /**
     * Null and empty values are dropped rather than emitted as `key=`, which the server would then
     * read back as a set-but-empty filter.
     */
    public function testEmptyStateValuesAreNotCarriedIntoLinks(): void
    {
        $href = $this->urls->link(UrlBuilder::ROUTE_INDEX, ['a' => null, 'b' => '', 'c' => 'kept']);

        self::assertStringNotContainsString('a=', $href);
        self::assertStringNotContainsString('b=', $href);
        self::assertStringContainsString('c=kept', $href);
    }

    public function testAnUnparameterisedLinkHasNoQueryString(): void
    {
        self::assertStringNotContainsString('?', $this->urls->link(UrlBuilder::ROUTE_INDEX));
    }

    /**
     * The board is reached through Magento's URL builder rather than a hand-built path, because this
     * install routes stores by path prefix — a constructed path would 404 on every non-default view.
     */
    public function testLinksGoThroughMagentosUrlBuilder(): void
    {
        self::assertStringStartsWith('https://muon.localhost/en-us/', $this->urls->link(UrlBuilder::ROUTE_FEED));
    }
}

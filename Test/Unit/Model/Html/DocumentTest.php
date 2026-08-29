<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\UrlInterface;
use Muon\DevProfilerBoard\Model\Html\Document;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * The theme-independence regression test.
 *
 * The whole point of this module is a page that resolves nothing through the theme fallback chain —
 * so that it cannot be styled by the storefront theme, and, more importantly, cannot add its own
 * resolutions to the fallback evidence it exists to display. A future "improvement" that renders
 * the board through a block and a .phtml would break that silently: the page would still look
 * right. These assertions fail instead.
 *
 * @see Document
 */
class DocumentTest extends TestCase
{
    use UnitEscaper;

    private Document $document;

    protected function setUp(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $route, array $params = []): string {
                $base = 'https://muon.localhost/en-us/' . $route;
                $query = $params['_query'] ?? [];

                return $query === [] ? $base : $base . '?' . http_build_query($query);
            }
        );

        $tag = new Tag($this->unitEscaper());
        $this->document = new Document($tag, new UrlBuilder($url));
    }

    /**
     * The live feed must ask for the slice the page was rendered with.
     *
     * The ledger is replaced wholesale by every poll, so a feed URL that omits the reader's filter
     * repopulates it with the rows they just filtered out — four seconds after the page loads, and
     * again on every tick. Carrying the filter here rather than reassembling it in JavaScript is
     * the point: `RunFilter::toQuery()` is the one place that knows the parameter names.
     */
    public function testTheLiveFeedUrlCarriesTheActiveFilter(): void
    {
        $html = $this->document->render(
            'T',
            '<li>rail</li>',
            '<p>main</p>',
            [],
            ['url' => 'home', 'verdict' => 'miss,hit', 'min_ms' => 50]
        );

        $feed = $this->feedUrl($html);

        self::assertStringContainsString('runs/feed', $feed);
        self::assertStringContainsString('url=home', $feed, 'the free-text URL filter was dropped');
        self::assertStringContainsString('verdict=miss%2Chit', $feed);
        self::assertStringContainsString('min_ms=50', $feed);
    }

    public function testAnUnfilteredPageAsksForAnUnfilteredFeed(): void
    {
        $feed = $this->feedUrl($this->document->render('T', '<li>rail</li>', '<p>main</p>'));

        self::assertStringContainsString('runs/feed', $feed);
        self::assertStringNotContainsString('?', $feed);
    }

    /**
     * The decoded `data-feed` attribute — it is HTML-attribute escaped in the markup.
     *
     * @param string $html
     * @return string
     */
    private function feedUrl(string $html): string
    {
        preg_match('/data-feed="([^"]*)"/', $html, $matches);

        self::assertArrayHasKey(1, $matches, 'the document rendered no data-feed attribute');

        return html_entity_decode((string)($matches[1] ?? ''), ENT_QUOTES, 'UTF-8');
    }

    public function testTheDocumentPullsInNothingFromMagentosAssetPipeline(): void
    {
        $html = $this->document->render('T', '<li>rail</li>', '<p>main</p>');

        foreach (['requirejs', 'x-magento-init', 'data-mage-init', 'pub/static', 'static/version', '.phtml'] as $token) {
            self::assertStringNotContainsString($token, $html, $token . ' reached the board document');
        }
    }

    public function testTheStylesheetAndScriptComeFromThisModulesOwnRoutes(): void
    {
        $html = $this->document->render('T', '', '<p>main</p>');

        self::assertStringContainsString('muon_profiler/asset/stylesheet', $html);
        self::assertStringContainsString('muon_profiler/asset/script', $html);
    }

    /**
     * These files are read over HTTP with a declared content type, but the charset meta still has
     * to come first: it is the one element that must precede any byte it governs.
     */
    public function testCharsetIsTheFirstThingInTheHead(): void
    {
        $html = $this->document->render('T', '', '');

        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertMatchesRegularExpression('/<head><meta charset="utf-8">/', $html);
        self::assertLessThan(
            strpos($html, '<title>'),
            strpos($html, 'charset'),
            'charset must precede the title'
        );
    }

    public function testTheDocumentAsksNotToBeIndexed(): void
    {
        self::assertStringContainsString('noindex', $this->document->render('T', '', ''));
    }

    public function testTheFeedUrlIsPublishedForTheScriptToFind(): void
    {
        self::assertStringContainsString('data-feed=', $this->document->render('T', '', ''));
    }

    public function testARailIsOmittedRatherThanRenderedEmpty(): void
    {
        $withRail = $this->document->render('T', '<li>x</li>', '');
        $without = $this->document->render('T', '', '');

        self::assertStringContainsString('<nav class="rail"', $withRail);
        self::assertStringNotContainsString('<nav', $without);
        self::assertStringContainsString('class="single"', $without);
    }

    public function testTheNoticePageStatesWhatToDoNext(): void
    {
        $html = $this->document->notice('No runs recorded yet', 'Load a storefront page.', 'Check MAGE_MODE.');

        self::assertStringContainsString('No runs recorded yet', $html);
        self::assertStringContainsString('Load a storefront page.', $html);
        self::assertStringContainsString('Check MAGE_MODE.', $html);
    }

    /**
     * The ledger is capped at feedLimit while the ring holds more — 25 against 50 by default. This
     * counter used to print the row count and label it "in ring", so once 25 runs had accumulated it
     * read "25 in ring" permanently while the ring actually held 50: a cap reported as a total, on a
     * page whose other panels go out of their way to say "N of M".
     *
     * @param int $shown
     * @param int $total
     * @param string $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ringCounts')]
    public function testTheRingCounterDistinguishesWhatIsShownFromWhatIsHeld(
        int $shown,
        int $total,
        string $expected
    ): void {
        $html = $this->document->render('T', '<li>x</li>', '', ['shown' => $shown, 'runs' => $total]);

        self::assertStringContainsString($expected, $html);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function ringCounts(): array
    {
        return [
            'ledger capped below the ring' => [25, 50, '25 of 50 in ring'],
            'ring smaller than the cap' => [7, 7, '7 in ring'],
            'ring exactly at the cap' => [25, 25, '25 in ring'],
            'nothing recorded yet' => [0, 0, 'no runs yet'],
        ];
    }

    public function testTheTitleIsEscaped(): void
    {
        $html = $this->document->render('<script>alert(1)</script>', '', '');

        self::assertStringNotContainsString('<script>alert', $html);
    }
}

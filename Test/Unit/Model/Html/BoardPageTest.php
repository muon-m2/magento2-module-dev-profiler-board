<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfilerBoard\Model\Board\LedgerData;
use Muon\DevProfilerBoard\Model\Html\BoardPage;
use Muon\DevProfilerBoard\Model\Html\Document;
use Muon\DevProfilerBoard\Model\Html\LedgerRail;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see BoardPage
 *
 * This was the only class in Model\Html with no test, because it injected RunSelector and
 * StoreManagerInterface and read the ring itself — testing it meant standing up the data layer to
 * render some HTML. Now it takes a Document, a LedgerRail and a resolved LedgerData, so the thing
 * it actually does can be asserted.
 */
#[AllowMockObjectsWithoutExpectations]
class BoardPageTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $meta = [];

    /** @var array<string,string|int|float> */
    private array $feedQuery = [];

    /**
     * @return BoardPage
     */
    private function page(): BoardPage
    {
        $this->meta = [];
        $this->feedQuery = [];

        $document = $this->createStub(Document::class);
        $document->method('render')->willReturnCallback(
            function (
                string $title,
                string $rail,
                string $main,
                array $meta = [],
                array $feedQuery = [],
                string $heading = ''
            ): string {
                $this->meta = $meta;
                $this->feedQuery = $feedQuery;

                return '<html>' . $title . $rail . $main . '</html>';
            }
        );

        $rail = $this->createStub(LedgerRail::class);
        $rail->method('render')->willReturn('<nav>rail</nav>');

        return new BoardPage($document, $rail);
    }

    public function testTheTopBarReportsTheRingRatherThanTheRowsOnScreen(): void
    {
        $ledger = new LedgerData([['token' => 'a'], ['token' => 'b']], 2, 50, 'en_us');

        $this->page()->render('T', '<p>main</p>', null, [], null, $ledger);

        self::assertSame(2, $this->meta['shown'], 'two rows are on screen');
        self::assertSame(50, $this->meta['runs'], 'but the ring holds fifty — printing 2 would be a lie');
        self::assertSame('en_us', $this->meta['store']);
    }

    /**
     * The live feed must poll the slice the page was rendered with, or it repopulates the ledger
     * with the rows the reader just filtered out.
     */
    public function testTheActiveFilterIsHandedToTheLiveFeed(): void
    {
        $filter = new RunFilter(['miss'], null, null, null, null, null, null, 'home');

        $this->page()->render('T', '<p>main</p>', null, [], $filter, new LedgerData());

        self::assertSame('home', $this->feedQuery['url'] ?? null);
        self::assertSame('miss', $this->feedQuery['verdict'] ?? null);
        self::assertTrue($this->meta['filtered']);
    }

    public function testAnUnfilteredPageSendsNoFilterToTheFeed(): void
    {
        $this->page()->render('T', '<p>main</p>', null, [], null, new LedgerData([], 0, 7, 'en_us'));

        self::assertSame([], $this->feedQuery);
        self::assertFalse($this->meta['filtered']);
    }

    public function testItRendersWithoutALedgerRatherThanFataling(): void
    {
        $html = $this->page()->render('T', '<p>main</p>');

        self::assertStringContainsString('main', $html);
        self::assertSame(0, $this->meta['runs']);
    }

    public function testTheNoticePageStillReportsTheRingSize(): void
    {
        $document = $this->createStub(Document::class);
        $document->method('notice')->willReturnCallback(
            function (string $t, string $m, string $h = '', array $meta = [], ?string $onward = null): string {
                $this->meta = $meta;

                return '<html>notice</html>';
            }
        );

        $page = new BoardPage($document, $this->createStub(LedgerRail::class));
        $page->notice('Run not found', 'No such token.', '', null, new LedgerData([], 0, 50, 'en_us'));

        self::assertSame(50, $this->meta['runs'], '"no runs yet" while the ring holds fifty is the untruth this avoids');
    }
}

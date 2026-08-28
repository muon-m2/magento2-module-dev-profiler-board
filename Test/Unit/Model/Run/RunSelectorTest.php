<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Run;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Store\RunStore;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use Muon\DevProfilerBoard\Model\Run\RunSelector;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see RunSelector
 */
#[AllowMockObjectsWithoutExpectations]
class RunSelectorTest extends TestCase
{
    /** @var MockObject&RunStore */
    private RunStore $store;

    protected function setUp(): void
    {
        $this->store = $this->createMock(RunStore::class);
    }

    public function testANamedTokenIsLoadedDirectly(): void
    {
        $this->store->expects(self::once())->method('load')->with('abc123')->willReturn(['token' => 'abc123']);
        $this->store->expects(self::never())->method('loadLastDocument');

        self::assertSame(['token' => 'abc123'], $this->selector()->select('abc123'));
    }

    /**
     * The default that matters. A storefront page fires customer-section XHRs immediately behind it,
     * so the newest entry in the ring is almost never the page just loaded — opening the board on it
     * would be wrong in exactly the way that costs the most time to notice.
     */
    public function testWithNoTokenTheNewestFullDocumentWins(): void
    {
        $this->store->expects(self::once())->method('loadLastDocument')->willReturn(['token' => 'doc']);
        $this->store->expects(self::never())->method('loadLast');

        self::assertSame(['token' => 'doc'], $this->selector()->select(null));
    }

    public function testSelectAnyTakesTheNewestRunOfAnyKind(): void
    {
        $this->store->expects(self::once())->method('loadLast')->willReturn(['token' => 'xhr']);
        $this->store->expects(self::never())->method('loadLastDocument');

        self::assertSame(['token' => 'xhr'], $this->selector()->selectAny(null));
    }

    public function testSelectAnyStillHonoursAnExplicitToken(): void
    {
        $this->store->expects(self::once())->method('load')->with('abc123')->willReturn(null);

        self::assertNull($this->selector()->selectAny('abc123'));
    }

    public function testAnUnknownTokenReturnsNull(): void
    {
        $this->store->expects(self::once())->method('load')->willReturn(null);

        self::assertNull($this->selector()->select('deadbeef'));
    }

    public function testTheFeedIsCappedAtTheConfiguredLimit(): void
    {
        $this->store->expects(self::once())->method('list')->with(5)->willReturn([]);

        $this->selector(5)->feed();
    }

    public function testARequestForMoreRowsThanConfiguredIsClampedDown(): void
    {
        $this->store->expects(self::once())->method('list')->with(5)->willReturn([]);

        $this->selector(5)->feed(1000);
    }

    public function testARequestForFewerRowsIsHonoured(): void
    {
        $this->store->expects(self::once())->method('list')->with(2)->willReturn([]);

        $this->selector(5)->feed(2);
    }

    public function testAZeroOrNegativeLimitStillAsksForAtLeastOneRow(): void
    {
        $this->store->expects(self::once())->method('list')->with(1)->willReturn([]);

        $this->selector(5)->feed(0);
    }

    public function testAFeedRowCarriesTheFactsTheLedgerShows(): void
    {
        $this->store->expects(self::once())->method('list')->willReturn([[
            'token' => 'abc123',
            'captured_at' => '2026-08-14T10:00:00+00:00',
            'request' => [
                'method' => 'GET',
                'url' => '/en-us/',
                'full_action' => 'cms_index_index',
                'status' => 200,
                'kind' => 'page',
                'is_ajax' => false,
                'duration_ms' => 12.5,
            ],
            'layout' => ['generated' => true, 'cacheable' => true],
            'queries' => [['count' => 3], ['count' => 4]],
        ]]);

        $row = $this->selector()->feed()[0];

        self::assertSame('abc123', $row['token']);
        self::assertSame('/en-us/', $row['url']);
        self::assertSame(200, $row['status']);
        self::assertSame(12.5, $row['duration_ms']);
        self::assertSame(7, $row['statements'], 'statement counts are summed across shapes');
        self::assertSame(CacheVerdict::MISS, $row['verdict']);
    }

    public function testARunWithMissingSectionsDoesNotTakeTheLedgerDown(): void
    {
        $this->store->expects(self::once())->method('list')->willReturn([['token' => 'partial']]);

        $row = $this->selector()->feed()[0];

        self::assertSame('partial', $row['token']);
        self::assertSame(0, $row['statements']);
        self::assertSame(CacheVerdict::HIT, $row['verdict'], 'no layout section reads as never generated');
    }

    /**
     * The correctness of the whole filter feature.
     *
     * The ledger is capped at feedLimit. Filtering the capped page instead of the ring would let
     * "show me the uncacheable runs" come back empty while uncacheable runs sat below the cut — a
     * filtered list presenting itself as a complete answer. So a filtered feed must read the whole
     * ring and cap afterwards, and an unfiltered one must not pay to.
     */
    public function testAFilteredFeedReadsTheWholeRingRatherThanTheCappedPage(): void
    {
        $this->store->method('count')->willReturn(40);
        $this->store->expects(self::once())->method('list')->with(40)->willReturn([]);

        $this->selector(5)->feed(null, new RunFilter(['uncacheable']));
    }

    public function testAnUnfilteredFeedOnlyReadsWhatTheLedgerShows(): void
    {
        $this->store->expects(self::once())->method('list')->with(5)->willReturn([]);
        $this->store->expects(self::never())->method('count');

        $this->selector(5)->feed(null, new RunFilter());
    }

    public function testAFilteredFeedIsStillCappedAtTheLedgerLimit(): void
    {
        $this->store->method('count')->willReturn(40);
        $this->store->method('list')->willReturn(array_fill(0, 40, $this->storedRun('miss')));

        self::assertCount(3, $this->selector(3)->feed(null, new RunFilter(['miss'])));
    }

    public function testRowsThatDoNotMatchAreLeftOut(): void
    {
        $this->store->method('count')->willReturn(3);
        $this->store->method('list')->willReturn([
            $this->storedRun('hit'),
            $this->storedRun('uncacheable'),
            $this->storedRun('hit'),
        ]);

        $rows = $this->selector(10)->feed(null, new RunFilter(['uncacheable']));

        self::assertCount(1, $rows);
        self::assertSame('uncacheable', $rows[0]['verdict']);
    }

    public function testMatchingCountsTheWholeRingNotThePage(): void
    {
        $this->store->method('count')->willReturn(30);
        $this->store->method('list')->willReturn(array_merge(
            array_fill(0, 20, $this->storedRun('hit')),
            array_fill(0, 10, $this->storedRun('uncacheable'))
        ));

        // Ten matches sit below a ledger cap of five; the counter must still say ten.
        self::assertSame(10, $this->selector(5)->matching(new RunFilter(['uncacheable'])));
    }

    public function testMatchingFallsBackToTheRingSizeWhenNothingIsFiltered(): void
    {
        $this->store->expects(self::once())->method('count')->willReturn(17);
        $this->store->expects(self::never())->method('list');

        self::assertSame(17, $this->selector()->matching(new RunFilter()));
    }

    /**
     * @param string $verdict
     * @return array<string,mixed>
     */
    private function storedRun(string $verdict): array
    {
        return [
            'token' => 'abc123',
            'request' => ['method' => 'GET', 'url' => '/en-us/', 'status' => 200, 'kind' => 'page', 'duration_ms' => 1.0],
            'layout' => $verdict === 'hit'
                ? ['generated' => false]
                : ['generated' => true, 'cacheable' => $verdict !== 'uncacheable'],
            'queries' => [],
        ];
    }

    /**
     * @param int $feedLimit
     * @return \Muon\DevProfilerBoard\Model\Run\RunSelector
     */
    private function selector(int $feedLimit = 25): RunSelector
    {
        return new RunSelector($this->store, new CacheVerdict(), $feedLimit);
    }

    /**
     * Every board page and every four-second poll asks for the rows and the match count together:
     * Feed and BoardPage both call feed() then matching(). Answering them separately meant two
     * independent full-ring scans, each re-listing the directory and re-decoding every run file.
     */
    public function testTheRowsAndTheMatchCountComeFromOneRingScan(): void
    {
        $runs = [];

        foreach (['hit', 'miss', 'uncacheable', 'miss'] as $i => $verdict) {
            $runs[] = [
                'token' => sprintf('%012d', $i),
                'request' => ['method' => 'GET', 'url' => '/en-us/p' . $i, 'status' => 200, 'kind' => 'page'],
                'layout' => ['generated' => $verdict !== 'hit', 'cacheable' => $verdict === 'miss'],
            ];
        }

        $this->store->expects(self::once())->method('count')->willReturn(count($runs));
        $this->store->expects(self::once())->method('list')->willReturn($runs);

        $selector = $this->selector();
        $filter = new RunFilter(['miss']);

        $rows = $selector->feed(null, $filter);
        $matching = $selector->matching($filter);

        self::assertCount(2, $rows, 'two of the four runs are a miss');
        self::assertSame(2, $matching);
    }

    /**
     * The cap must never be mistaken for the answer: the ledger shows a page, the counter reports
     * how many matched across the whole ring.
     */
    public function testTheMatchCountIsNotCappedByTheLedgerSize(): void
    {
        $runs = [];

        for ($i = 0; $i < 30; $i++) {
            $runs[] = [
                'token' => sprintf('%012d', $i),
                'request' => ['method' => 'GET', 'url' => '/en-us/p', 'status' => 200, 'kind' => 'page'],
                'layout' => ['generated' => true, 'cacheable' => true],
            ];
        }

        $this->store->method('count')->willReturn(30);
        $this->store->expects(self::once())->method('list')->willReturn($runs);

        $selector = $this->selector();
        $filter = new RunFilter(['miss']);

        self::assertCount(25, $selector->feed(null, $filter), 'the ledger is capped at feedLimit');
        self::assertSame(30, $selector->matching($filter), 'the count is not');
    }
}

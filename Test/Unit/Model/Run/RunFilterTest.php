<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Run;

use Magento\Framework\App\RequestInterface;
use Muon\DevProfilerBoard\Model\Run\FilterReader;
use Muon\DevProfilerBoard\Model\Run\RunFilter;
use PHPUnit\Framework\TestCase;

/**
 * @see RunFilter
 * @see FilterReader
 */
class RunFilterTest extends TestCase
{
    private FilterReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FilterReader();
    }

    public function testAnEmptyFilterIsInactiveAndMatchesEverything(): void
    {
        $filter = new RunFilter();

        self::assertFalse($filter->isActive());
        self::assertSame(0, $filter->count());
        self::assertTrue($filter->matches($this->row()));
    }

    public function testVerdictNarrowsToTheChosenOnes(): void
    {
        $filter = new RunFilter(['uncacheable', 'miss']);

        self::assertTrue($filter->matches($this->row(['verdict' => 'miss'])));
        self::assertTrue($filter->matches($this->row(['verdict' => 'uncacheable'])));
        self::assertFalse($filter->matches($this->row(['verdict' => 'hit'])));
    }

    public function testMethodAndStatusAreExactMatches(): void
    {
        self::assertTrue((new RunFilter([], 'GET'))->matches($this->row(['method' => 'get'])));
        self::assertFalse((new RunFilter([], 'POST'))->matches($this->row(['method' => 'GET'])));
        self::assertTrue((new RunFilter([], null, 404))->matches($this->row(['status' => 404])));
        self::assertFalse((new RunFilter([], null, 404))->matches($this->row(['status' => 200])));
    }

    public function testRangesAreInclusiveAtBothEnds(): void
    {
        $filter = new RunFilter([], null, null, 100.0, 200.0);

        self::assertTrue($filter->matches($this->row(['duration_ms' => 100.0])));
        self::assertTrue($filter->matches($this->row(['duration_ms' => 200.0])));
        self::assertFalse($filter->matches($this->row(['duration_ms' => 99.9])));
        self::assertFalse($filter->matches($this->row(['duration_ms' => 200.1])));
    }

    public function testAnOpenEndedRangeOnlyBoundsOneSide(): void
    {
        self::assertTrue((new RunFilter([], null, null, 100.0))->matches($this->row(['duration_ms' => 9999.0])));
        self::assertFalse((new RunFilter([], null, null, 100.0))->matches($this->row(['duration_ms' => 1.0])));
        self::assertTrue((new RunFilter([], null, null, null, 100.0))->matches($this->row(['duration_ms' => 1.0])));
    }

    /**
     * Bounds typed the wrong way round mean the range between them. Returning nothing would be
     * technically defensible and useless — the reader's intent is not in doubt.
     */
    public function testReversedBoundsAreReadAsTheRangeBetweenThem(): void
    {
        $filter = new RunFilter([], null, null, 200.0, 100.0);

        self::assertTrue($filter->matches($this->row(['duration_ms' => 150.0])));
        self::assertFalse($filter->matches($this->row(['duration_ms' => 250.0])));
    }

    public function testStatementsRangeWorksTheSameWay(): void
    {
        $filter = new RunFilter([], null, null, null, null, 20, 100);

        self::assertTrue($filter->matches($this->row(['statements' => 20])));
        self::assertTrue($filter->matches($this->row(['statements' => 100])));
        self::assertFalse($filter->matches($this->row(['statements' => 19])));
        self::assertFalse($filter->matches($this->row(['statements' => 101])));
    }

    /**
     * @param string $needle
     * @param string $url
     * @param bool $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('urlNeedles')]
    public function testUrlIsACaseInsensitiveSubstringOfTheWholeUri(string $needle, string $url, bool $expected): void
    {
        $filter = new RunFilter([], null, null, null, null, null, null, $needle);

        self::assertSame($expected, $filter->matches($this->row(['url' => $url])));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function urlNeedles(): array
    {
        return [
            'plain substring' => ['trade', '/en-us/trade-accounts', true],
            'different case' => ['TRADE', '/en-us/trade-accounts', true],
            'mixed case haystack' => ['trade', '/en-us/TRADE-accounts', true],
            'path prefix' => ['/en-us/', '/en-us/gear/bags.html', true],
            'matches the query string too' => ['sections=cart', '/customer/section/load/?sections=cart', true],
            'no match' => ['checkout', '/en-us/trade-accounts', false],
            'a needle is not a pattern' => ['.*', '/en-us/trade-accounts', false],
            'nor a wildcard' => ['trade*', '/en-us/trade-accounts', false],
        ];
    }

    public function testAUrlNeedleCountsAsOneActiveCriterion(): void
    {
        self::assertSame(1, (new RunFilter([], null, null, null, null, null, null, 'trade'))->count());
        self::assertSame('trade', (new RunFilter([], null, null, null, null, null, null, 'trade'))->toQuery()['url']);
    }

    public function testWhitespaceIsNotAUrlCriterion(): void
    {
        self::assertNull($this->read(['url' => '   '])->url);
        self::assertFalse($this->read(['url' => '   '])->isActive());
        self::assertSame('trade', $this->read(['url' => '  trade  '])->url, 'a real needle is trimmed, not dropped');
    }

    public function testCriteriaCombineAsAnAnd(): void
    {
        $filter = new RunFilter(['miss'], 'GET', 200, 100.0, null, 10, null);

        self::assertTrue($filter->matches($this->row()));
        self::assertFalse($filter->matches($this->row(['verdict' => 'hit'])));
        self::assertFalse($filter->matches($this->row(['statements' => 2])));
    }

    public function testTheActiveCountIsWhatTheToggleShows(): void
    {
        self::assertSame(3, (new RunFilter(['miss'], 'GET', 404))->count());
        self::assertSame(2, (new RunFilter([], null, null, 1.0, 2.0))->count());
    }

    public function testTheFilterRoundTripsThroughTheQueryString(): void
    {
        $query = (new RunFilter(['miss', 'hit'], 'GET', 404, 1.5, 2.5, 3, 4))->toQuery();

        self::assertSame('miss,hit', $query['verdict']);
        self::assertSame('GET', $query['method']);
        self::assertSame(404, $query['status']);
        self::assertSame(1.5, $query['min_ms']);
        self::assertSame(4, $query['max_stmt']);
        self::assertSame([], (new RunFilter())->toQuery(), 'an inactive filter adds nothing to a link');
    }

    // ── FilterReader ─────────────────────────────────────────────────────

    public function testTheReaderAcceptsCheckboxesAndCommaSeparatedVerdictsAlike(): void
    {
        self::assertSame(['hit', 'miss'], $this->read(['verdict' => ['miss', 'hit']])->verdicts);
        self::assertSame(['hit', 'miss'], $this->read(['verdict' => 'miss,hit'])->verdicts);
        self::assertSame(['miss'], $this->read(['verdict' => ' MISS '])->verdicts);
    }

    /**
     * Anything that is not a real criterion is discarded rather than compared against, so a typo
     * shows the whole ring instead of silently matching nothing.
     *
     * @param array<string,mixed> $params
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedInput')]
    public function testUnusableInputLeavesTheFilterInactive(array $params): void
    {
        self::assertFalse($this->read($params)->isActive());
    }

    /**
     * @return array<string, array{array<string,mixed>}>
     */
    public static function rejectedInput(): array
    {
        return [
            'verdict that is not one' => [['verdict' => 'drop table']],
            'method that is not one' => [['method' => 'TRACE']],
            'non-numeric status' => [['status' => 'abc']],
            'negative bound' => [['min_ms' => '-9']],
            'array where a scalar belongs' => [['status' => ['404']]],
            'empty strings' => [['verdict' => '', 'method' => '', 'status' => '']],
        ];
    }

    public function testTheReaderKeepsWhatIsValid(): void
    {
        $filter = $this->read([
            'verdict' => 'uncacheable',
            'method' => 'get',
            'status' => '404',
            'min_ms' => '250.5',
            'max_stmt' => '40',
        ]);

        self::assertSame(['uncacheable'], $filter->verdicts);
        self::assertSame('GET', $filter->method, 'method is normalised to upper case');
        self::assertSame(404, $filter->status);
        self::assertSame(250.5, $filter->minMs);
        self::assertSame(40, $filter->maxStatements);
        self::assertNull($filter->maxMs);
    }

    /**
     * @param array<string,mixed> $params
     * @return \Muon\DevProfilerBoard\Model\Run\RunFilter
     */
    private function read(array $params): RunFilter
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );

        return $this->reader->fromRequest($request);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'token' => 'abc123',
            'method' => 'GET',
            'status' => 200,
            'verdict' => 'miss',
            'duration_ms' => 150.0,
            'statements' => 42,
        ];
    }
}

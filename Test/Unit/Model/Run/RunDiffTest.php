<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Run;

use Muon\DevProfilerBoard\Model\Run\RunDiff;
use PHPUnit\Framework\TestCase;

/**
 * @see RunDiff
 */
class RunDiffTest extends TestCase
{
    private RunDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new RunDiff();
    }

    public function testComparingARunAgainstItselfReportsNothingChanged(): void
    {
        $run = $this->makeRun();
        $result = $this->diff->compare($run, $run);

        self::assertNull($result['verdict']);
        self::assertSame([], $result['queries']);
        self::assertSame([], $result['winners']);
        self::assertSame([], $result['handles']['added']);
        self::assertSame([], $result['handles']['removed']);
        self::assertSame([], array_filter($result['metrics'], static fn (array $m): bool => $m['changed']));
    }

    public function testAVerdictChangeIsReportedWithItsCause(): void
    {
        $hit = $this->makeRun(['layout' => ['generated' => false, 'cacheable' => null, 'handles' => []]]);
        $uncacheable = $this->makeRun(['layout' => [
            'generated' => true,
            'cacheable' => false,
            'handles' => [],
            'uncacheable_blocks' => [['name' => 'Magento\Framework\View\Element\FormKey', 'in_play' => true]],
        ]]);

        $result = $this->diff->compare($hit, $uncacheable);

        self::assertNotNull($result['verdict']);
        self::assertSame(false, $result['verdict']['b']['cacheable']);
        self::assertContains('Magento\Framework\View\Element\FormKey', $result['verdict']['causes']);
    }

    /**
     * A block that declared cacheable="false" but never produced an element cannot be the cause,
     * and naming it would contradict the verdict shown beside it.
     */
    public function testABlockThatWasNeverGeneratedIsNotNamedAsACause(): void
    {
        $before = $this->makeRun(['layout' => ['generated' => false, 'cacheable' => null, 'handles' => []]]);
        $after = $this->makeRun(['layout' => [
            'generated' => true,
            'cacheable' => false,
            'handles' => [],
            'uncacheable_blocks' => [['name' => 'Never\\Generated', 'in_play' => false]],
        ]]);

        self::assertSame([], $this->diff->compare($before, $after)['verdict']['causes']);
    }

    public function testScalarMetricsCarryASignedDelta(): void
    {
        $slow = $this->makeRun(['request' => ['duration_ms' => 400.0] + $this->request()]);

        $metrics = $this->diff->compare($this->makeRun(), $slow)['metrics'];
        $duration = $this->metric($metrics, 'Duration (ms)');

        self::assertTrue($duration['changed']);
        self::assertSame(300.0, $duration['delta']);
    }

    public function testHandlesAddedAndRemovedAreBothReported(): void
    {
        $before = $this->makeRun(['layout' => ['generated' => true, 'cacheable' => true, 'handles' => ['a', 'b']]]);
        $after = $this->makeRun(['layout' => ['generated' => true, 'cacheable' => true, 'handles' => ['b', 'c']]]);

        $handles = $this->diff->compare($before, $after)['handles'];

        self::assertSame(['c'], $handles['added']);
        self::assertSame(['a'], $handles['removed']);
    }

    /**
     * The union of keys, not the intersection: a shape present on one side only is the most
     * interesting row in the table, and an intersection would drop exactly that.
     */
    public function testAShapePresentOnOneSideOnlyIsReported(): void
    {
        $before = $this->makeRun(['queries' => []]);
        $after = $this->makeRun(['queries' => [
            ['fingerprint' => 'f1', 'sample' => 'SELECT 1', 'count' => 47, 'origin' => 'Loader.php:88'],
        ]]);

        $queries = $this->diff->compare($before, $after)['queries'];

        self::assertCount(1, $queries);
        self::assertSame(47, $queries[0]['delta']);
        self::assertSame(0, $queries[0]['a']);
    }

    public function testShapesAreOrderedByHowMuchTheyMoved(): void
    {
        $before = $this->makeRun(['queries' => []]);
        $after = $this->makeRun(['queries' => [
            ['fingerprint' => 'small', 'sample' => 's', 'count' => 2],
            ['fingerprint' => 'big', 'sample' => 'b', 'count' => 50],
        ]]);

        $queries = $this->diff->compare($before, $after)['queries'];

        self::assertSame('big', $queries[0]['fingerprint']);
    }

    /**
     * The comparison this view exists for: two loads of the same URL that resolve different
     * physical files mean the theme resolved differently between them.
     */
    public function testAFallbackWinnerThatMovedIsReported(): void
    {
        $before = $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-a/x.less']]]);
        $after = $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-b/x.less']]]);

        $winners = $this->diff->compare($before, $after)['winners'];

        self::assertCount(1, $winners);
        self::assertSame('theme-a/x.less', $winners[0]['a']);
        self::assertSame('theme-b/x.less', $winners[0]['b']);
    }

    public function testAWinnerThatDidNotMoveIsNotReported(): void
    {
        $run = $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-a/x.less']]]);

        self::assertSame([], $this->diff->compare($run, $run)['winners']);
    }

    /**
     * A file resolved in only one of the two runs is not a winner *change* — reporting it as one
     * would send a reader looking for a theme override that never moved.
     */
    public function testAFileResolvedInOnlyOneRunIsNotAWinnerChange(): void
    {
        $before = $this->makeRun(['fallback' => []]);
        $after = $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-a/x.less']]]);

        self::assertSame([], $this->diff->compare($before, $after)['winners']);
    }

    public function testDifferentUrlsAreFlagged(): void
    {
        $a = $this->makeRun();
        $b = $this->makeRun(['request' => ['url' => '/de-de/'] + $this->request()]);

        self::assertFalse($this->diff->compare($a, $b)['same_url']);
        self::assertTrue($this->diff->compare($a, $a)['same_url']);
    }

    /**
     * @param list<array<string,mixed>> $metrics
     * @param string $label
     * @return array<string,mixed>
     */
    private function metric(array $metrics, string $label): array
    {
        foreach ($metrics as $metric) {
            if (($metric['label'] ?? '') === $label) {
                return $metric;
            }
        }

        self::fail('no metric labelled ' . $label);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeRun(array $overrides = []): array
    {
        return $overrides + [
            'token' => 'abc123',
            'captured_at' => '2026-08-14T10:00:00+00:00',
            'request' => $this->request(),
            'context' => ['theme_path' => 'Muon/cosmic-custom', 'theme_source' => 'observed'],
            'layout' => ['generated' => true, 'cacheable' => true, 'handles' => ['default']],
            'fallback' => [],
            'queries' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function request(): array
    {
        return [
            'method' => 'GET',
            'url' => '/en-us/',
            'duration_ms' => 100.0,
            'memory_peak_kb' => 1024,
        ];
    }
}

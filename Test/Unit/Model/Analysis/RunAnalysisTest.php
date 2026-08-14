<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Analysis;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfiler\Model\Analysis\ResolutionSet;
use Muon\DevProfiler\Model\Analysis\ShadowResolver;
use Muon\DevProfilerBoard\Model\Analysis\RunAnalysis;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see RunAnalysis
 */
class RunAnalysisTest extends TestCase
{
    /** @var MockObject&ShadowResolver */
    private ShadowResolver $shadows;

    protected function setUp(): void
    {
        $this->shadows = $this->createMock(ShadowResolver::class);
    }

    public function testTheVerdictComesFromTheCollectorsOwnAnalyser(): void
    {
        $this->shadows->expects(self::never())->method('classify');

        $analysis = $this->analysis()->analyse(
            ['request' => ['kind' => 'page'], 'layout' => ['generated' => false], 'fallback' => [], 'queries' => []],
            $this->thresholds()
        );

        self::assertSame(CacheVerdict::HIT, $analysis['verdict']['status']);
    }

    public function testAStaticRunGetsTheNotApplicableVerdict(): void
    {
        $this->shadows->expects(self::never())->method('classify');

        $analysis = $this->analysis()->analyse(
            ['request' => ['kind' => 'static'], 'layout' => ['generated' => true], 'fallback' => [], 'queries' => []],
            $this->thresholds()
        );

        self::assertSame(CacheVerdict::NOT_APPLICABLE, $analysis['verdict']['status']);
    }

    /**
     * ShadowResolver rebuilds the search directories from a theme. Without one it cannot replay the
     * lookup, and guessing would produce confident, wrong answers about which file is live.
     */
    public function testNoThemeMeansNoShadowClassificationRatherThanAGuess(): void
    {
        $this->shadows->expects(self::never())->method('classify');

        $analysis = $this->analysis()->analyse(
            [
                'request' => ['kind' => 'page'],
                'layout' => ['generated' => true, 'cacheable' => true],
                'context' => ['theme_path' => null],
                'fallback' => [['file' => 'a.less', 'resolved' => 'x/a.less']],
                'queries' => [],
            ],
            $this->thresholds()
        );

        self::assertSame([], $analysis['fallback']);
    }

    public function testRepeatLookupsAreCollapsedBeforeTheyAreCounted(): void
    {
        $this->shadows->expects(self::once())->method('classify')->willReturn([
            $this->resolution('etc/view.xml', ['b/view.xml']),
            $this->resolution('etc/view.xml', ['b/view.xml']),
            $this->resolution('clean.xml', []),
        ]);

        $analysis = $this->analysis()->analyse(
            [
                'request' => ['kind' => 'page'],
                'layout' => ['generated' => true, 'cacheable' => true],
                'context' => ['theme_path' => 'Muon/cosmic-custom'],
                'fallback' => [['x'], ['x'], ['y']],
                'queries' => [],
            ],
            $this->thresholds()
        );

        self::assertCount(2, $analysis['fallback'], 'the repeat lookup was collapsed');
        self::assertSame(1, $analysis['totals']['shadowed'], 'and counted once, as the CLI counts it');
        self::assertSame(3, $analysis['totals']['fallbacks'], 'while the raw lookup count is preserved');
        self::assertSame(2, $analysis['totals']['files']);
    }

    public function testStatementTotalsComeFromTheAnalyser(): void
    {
        $this->shadows->expects(self::never())->method('classify');

        $analysis = $this->analysis()->analyse(
            [
                'request' => ['kind' => 'page'],
                'layout' => ['generated' => true, 'cacheable' => true],
                'fallback' => [],
                'queries' => [
                    ['fingerprint' => 'a', 'count' => 5, 'total_ms' => 1.0, 'max_ms' => 1.0],
                    ['fingerprint' => 'b', 'count' => 2, 'total_ms' => 1.0, 'max_ms' => 1.0],
                ],
            ],
            $this->thresholds()
        );

        self::assertSame(7, $analysis['totals']['statements']);
        self::assertSame(2, $analysis['totals']['shapes']);
    }

    /**
     * @param string $file
     * @param list<string> $shadowed
     * @return array<string,mixed>
     */
    private function resolution(string $file, array $shadowed): array
    {
        return [
            'type' => 'file',
            'file' => $file,
            'module' => null,
            'winner' => 'a/' . $file,
            'shadowed' => $shadowed,
            'anomaly' => null,
        ];
    }

    /**
     * @return array{slow_ms:float,nplus1:int,duplicate:int}
     */
    private function thresholds(): array
    {
        return ['slow_ms' => 50.0, 'nplus1' => 5, 'duplicate' => 3];
    }

    /**
     * @return \Muon\DevProfilerBoard\Model\Analysis\RunAnalysis
     */
    private function analysis(): RunAnalysis
    {
        return new RunAnalysis(new CacheVerdict(), $this->shadows, new QueryAnalyzer(), new ResolutionSet());
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Analysis;

use Magento\Framework\App\RequestInterface;
use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfilerBoard\Model\Analysis\Thresholds;
use PHPUnit\Framework\TestCase;

/**
 * @see Thresholds
 */
class ThresholdsTest extends TestCase
{
    private Thresholds $thresholds;

    protected function setUp(): void
    {
        $this->thresholds = new Thresholds();
    }

    public function testAbsentParametersFallBackToTheAnalyzersOwnDefaults(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request([]));

        self::assertSame(QueryAnalyzer::DEFAULT_NPLUS1, $resolved['nplus1']);
        self::assertSame(QueryAnalyzer::DEFAULT_DUPLICATE, $resolved['duplicate']);
        self::assertSame(QueryAnalyzer::DEFAULT_SLOW_MS, $resolved['slow_ms']);
    }

    public function testSuppliedValuesAreUsed(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request([
            'nplus1' => '3',
            'duplicate' => '4',
            'slow' => '10.5',
        ]));

        self::assertSame(3, $resolved['nplus1']);
        self::assertSame(4, $resolved['duplicate']);
        self::assertSame(10.5, $resolved['slow_ms']);
    }

    /**
     * Left unclamped, a zero or negative threshold makes the analyzer classify every statement on
     * the page as a finding — indistinguishable from a broken tool.
     */
    public function testZeroAndNegativeExecutionCountsAreClampedToTheFloor(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request([
            'nplus1' => '0',
            'duplicate' => '-7',
        ]));

        self::assertSame(2, $resolved['nplus1']);
        self::assertSame(2, $resolved['duplicate']);
    }

    public function testANegativeSlowThresholdIsClampedAboveZero(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request(['slow' => '-5']));

        self::assertGreaterThan(0, $resolved['slow_ms']);
    }

    public function testAbsurdlyLargeValuesAreClampedToTheCeiling(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request([
            'nplus1' => '999999',
            'slow' => '999999999',
        ]));

        self::assertSame(1000, $resolved['nplus1']);
        self::assertSame(60000.0, $resolved['slow_ms']);
    }

    public function testNonNumericValuesFallBackToDefaults(): void
    {
        $resolved = $this->thresholds->fromRequest($this->request([
            'nplus1' => 'drop table',
            'duplicate' => '',
            'slow' => ['array'],
        ]));

        self::assertSame(QueryAnalyzer::DEFAULT_NPLUS1, $resolved['nplus1']);
        self::assertSame(QueryAnalyzer::DEFAULT_DUPLICATE, $resolved['duplicate']);
        self::assertSame(QueryAnalyzer::DEFAULT_SLOW_MS, $resolved['slow_ms']);
    }

    public function testDefaultsMatchWhatTheAnalyzerWouldUseOnItsOwn(): void
    {
        self::assertSame(
            $this->thresholds->defaults(),
            $this->thresholds->fromRequest($this->request([]))
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return \Magento\Framework\App\RequestInterface
     */
    private function request(array $params): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );

        return $request;
    }
}

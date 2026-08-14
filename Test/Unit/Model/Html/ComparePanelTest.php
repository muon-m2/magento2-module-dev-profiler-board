<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfilerBoard\Model\Html\ComparePanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Model\Run\RunDiff;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * @see ComparePanel
 */
class ComparePanelTest extends TestCase
{
    use UnitEscaper;

    private ComparePanel $panel;

    private RunDiff $diff;

    protected function setUp(): void
    {
        $tag = new Tag($this->unitEscaper());
        $this->panel = new ComparePanel($tag, new Widgets($tag));
        $this->diff = new RunDiff();
    }

    public function testBothSidesAreRenderedWithTheirTokens(): void
    {
        $html = $this->render($this->makeRun(['token' => 'aaa']), $this->makeRun(['token' => 'bbb']));

        self::assertSame(2, substr_count($html, 'cmp-head'));
        self::assertStringContainsString('aaa', $html);
        self::assertStringContainsString('bbb', $html);
    }

    public function testComparingTwoDifferentUrlsIsFlaggedRatherThanRefused(): void
    {
        $html = $this->render(
            $this->makeRun(),
            $this->makeRun(['request' => ['url' => '/de-de/', 'duration_ms' => 100.0, 'memory_peak_kb' => 1]])
        );

        self::assertStringContainsString('different URLs', $html);
        self::assertStringContainsString('cmp-head', $html, 'the comparison is still rendered');
    }

    public function testIdenticalRunsSaySoInsteadOfShowingAnEmptyTable(): void
    {
        $run = $this->makeRun();

        self::assertStringContainsString('Nothing changed', $this->render($run, $run));
    }

    public function testAVerdictChangeIsShownWithItsCause(): void
    {
        $html = $this->render(
            $this->makeRun(['layout' => ['generated' => false, 'cacheable' => null, 'handles' => []]]),
            $this->makeRun(['layout' => [
                'generated' => true,
                'cacheable' => false,
                'handles' => [],
                'uncacheable_blocks' => [['name' => 'Magento\Framework\View\Element\FormKey', 'in_play' => true]],
            ]])
        );

        self::assertStringContainsString('verdict', $html);
        self::assertStringContainsString('FormKey', $html);
    }

    /**
     * The row this whole view exists for. When nothing moved it says so explicitly, because "no
     * rows" and "no change" look identical otherwise.
     */
    public function testAFallbackWinnerThatMovedIsReportedAndOneThatDidNotIsStated(): void
    {
        $moved = $this->render(
            $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-a/x.less']]]),
            $this->makeRun(['fallback' => [['file' => 'x.less', 'resolved' => 'theme-b/x.less']]])
        );

        self::assertStringContainsString('winner moved', $moved);
        self::assertStringContainsString('theme-b/x.less', $moved);

        // When something else changed but no winner did, say so — a reader chasing a theme override
        // cannot otherwise tell "nothing moved" from "the diff did not look".
        $slower = $this->render(
            $this->makeRun(),
            $this->makeRun(['request' => ['url' => '/en-us/', 'duration_ms' => 400.0, 'memory_peak_kb' => 1024]])
        );

        self::assertStringContainsString('No fallback winner moved', $slower);
        self::assertStringNotContainsString('Nothing changed', $slower);
    }

    public function testANewStatementShapeIsReportedWithItsDelta(): void
    {
        $html = $this->render(
            $this->makeRun(),
            $this->makeRun(['queries' => [[
                'fingerprint' => 'f1',
                'sample' => 'SELECT * FROM catalog_product_entity',
                'count' => 47,
                'origin' => 'Loader.php:88',
            ]]])
        );

        self::assertStringContainsString('+47', $html);
        self::assertStringContainsString('catalog_product_entity', $html);
    }

    public function testTheFormIsRenderedEvenWithNothingToCompare(): void
    {
        $html = $this->panel->render([
            'same_url' => true,
            'sides' => ['a' => ['token' => ''], 'b' => ['token' => '']],
            'verdict' => null,
            'metrics' => [],
            'handles' => [],
            'queries' => [],
            'winners' => [],
        ], '/en-us/muon_profiler/compare/index');

        self::assertStringContainsString('name="a"', $html);
        self::assertStringContainsString('name="b"', $html);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return string
     */
    private function render(array $left, array $right): string
    {
        return $this->panel->render(
            $this->diff->compare($left, $right),
            '/en-us/muon_profiler/compare/index'
        );
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
            'request' => ['method' => 'GET', 'url' => '/en-us/', 'duration_ms' => 100.0, 'memory_peak_kb' => 1024],
            'context' => ['theme_path' => 'Muon/cosmic-custom', 'theme_source' => 'observed'],
            'layout' => ['generated' => true, 'cacheable' => true, 'handles' => ['default']],
            'fallback' => [],
            'queries' => [],
        ];
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfilerBoard\Model\Html\FallbackPanel;
use Muon\DevProfilerBoard\Model\Html\SqlPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\VerdictBanner;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The panels were reached only by XssRegressionTest, which asserts one thing: that `alert(1)` does
 * not survive. Everything these classes actually decide — which finding label a shape earns, how an
 * anomaly is explained, whether a verdict names its cause — went unexercised, so a wrong label would
 * ship looking exactly like a right one.
 *
 * @see SqlPanel
 * @see FallbackPanel
 * @see VerdictBanner
 */
class PanelBranchesTest extends TestCase
{
    use UnitEscaper;

    private Tag $tag;

    private Widgets $ui;

    protected function setUp(): void
    {
        $this->tag = new Tag($this->unitEscaper());
        $this->ui = new Widgets($this->tag);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function findings(): array
    {
        return [
            [QueryAnalyzer::N_PLUS_ONE, 'n+1'],
            [QueryAnalyzer::DUPLICATE, 'duplicate'],
            [QueryAnalyzer::SLOW, 'slow'],
        ];
    }

    /**
     * An N+1 reported as a duplicate sends the reader looking for the wrong thing, which is the
     * distinction the fingerprint and sql_varies machinery exists to draw.
     *
     * @param string $kind
     * @param string $label
     * @return void
     */
    #[DataProvider('findings')]
    public function testEachFindingKindGetsItsOwnLabel(string $kind, string $label): void
    {
        // Built through the real analyzer rather than by hand: the panel reads the shape classify()
        // produces, and a fixture invented here would prove the panel renders my guess.
        $groups = [[
            'fingerprint' => 'SELECT * FROM cms_block WHERE identifier = ?',
            'sample' => 'SELECT * FROM cms_block WHERE identifier = ?',
            'count' => $kind === QueryAnalyzer::SLOW ? 1 : 40,
            'total_ms' => $kind === QueryAnalyzer::SLOW ? 500.0 : 40.0,
            'max_ms' => $kind === QueryAnalyzer::SLOW ? 500.0 : 2.0,
            'binds' => [],
            'origin' => 'Foo\\Bar::baz:12',
            'is_userland' => true,
            'sql_varies' => $kind === QueryAnalyzer::N_PLUS_ONE,
        ]];

        $analysis = (new QueryAnalyzer())->classify($groups, ['slow_ms' => 50, 'nplus1' => 5, 'duplicate' => 3]);

        $panel = new SqlPanel($this->tag, $this->ui);
        $html = $panel->render($analysis, [], '/muon_profiler/run/view');

        self::assertStringContainsString($label, $html, $kind . ' should be labelled ' . $label);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function anomalies(): array
    {
        return [
            ['replay-diverged', 'nothing below can be trusted'],
            ['winner-mismatch', 'different winner'],
            ['candidates-unavailable', 'could not be rebuilt'],
            ['unsupported-type', 'not yet classified'],
        ];
    }

    /**
     * An anomaly means the ladder below it may be wrong. Reporting the raw slug instead of saying
     * so leaves the reader trusting a resolution the tool has already doubted.
     *
     * @param string $anomaly
     * @param string $expected
     * @return void
     */
    #[DataProvider('anomalies')]
    public function testEveryAnomalyKindIsExplainedInWords(string $anomaly, string $expected): void
    {
        $panel = new FallbackPanel($this->tag, $this->ui);

        $html = $panel->render([
            ['type' => 'template', 'file' => 'html/header.phtml', 'anomaly' => $anomaly, 'candidates' => []],
        ], [], '/muon_profiler/run/view');

        // The slug stays as the badge (`<span class="mark">`) and the sentence sits beside it in
        // `<span class="path">`. Both are wanted: the badge names the kind, the sentence says what
        // it means for the ladder below.
        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('class="mark">' . $anomaly, $html);
    }

    public function testAnUnknownAnomalyFallsBackToItsOwnName(): void
    {
        $panel = new FallbackPanel($this->tag, $this->ui);

        $html = $panel->render([
            ['type' => 'template', 'file' => 'x.phtml', 'anomaly' => 'brand-new-kind', 'candidates' => []],
        ], [], '/muon_profiler/run/view');

        self::assertStringContainsString('brand-new-kind', $html, 'better the slug than silence');
    }

    public function testAVerdictNamesItsCause(): void
    {
        $banner = new VerdictBanner($this->tag, $this->ui);

        $html = $banner->render(
            ['token' => 'abc', 'request' => ['kind' => 'page']],
            ['status' => 'uncacheable', 'summary' => 'x', 'causes' => [['detail' => 'Magento\\Cms\\Block\\Widget']]]
        );

        self::assertStringContainsString('because', $html);
        self::assertStringContainsString('Magento\\Cms\\Block\\Widget', $html);
    }

    /**
     * More than one cause is summarised rather than listed, but the count must be honest.
     */
    public function testExtraCausesAreCountedNotDropped(): void
    {
        $banner = new VerdictBanner($this->tag, $this->ui);

        $html = $banner->render(
            ['token' => 'abc', 'request' => ['kind' => 'page']],
            ['status' => 'uncacheable', 'summary' => 'x', 'causes' => [
                ['detail' => 'A'], ['detail' => 'B'], ['detail' => 'C'],
            ]]
        );

        self::assertStringContainsString('and 2 more', $html);
    }

    public function testAVerdictWithNoKnownCauseClaimsNone(): void
    {
        $banner = new VerdictBanner($this->tag, $this->ui);

        $html = $banner->render(
            ['token' => 'abc', 'request' => ['kind' => 'page']],
            ['status' => 'uncacheable', 'summary' => 'x', 'causes' => []]
        );

        self::assertStringNotContainsString('because', $html);
    }
}

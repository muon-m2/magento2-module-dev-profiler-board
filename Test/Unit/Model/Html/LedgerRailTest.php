<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfilerBoard\Model\Html\FilterPanel;
use Muon\DevProfilerBoard\Model\Html\LedgerRail;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\UrlBuilder;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * The rail's markup is a contract: assets/board.js rebuilds these same rows from the JSON feed, so
 * a change to a class name or data attribute has to be made in both places. These assertions are
 * what make that break loudly instead of silently.
 *
 * @see LedgerRail
 */
class LedgerRailTest extends TestCase
{
    use UnitEscaper;

    private LedgerRail $rail;

    protected function setUp(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/en-us/' . $route
        );

        $tag = new Tag($this->unitEscaper());
        $this->rail = new LedgerRail($tag, new Widgets($tag), new UrlBuilder($url), $this->formKey(), new FilterPanel($tag, new UrlBuilder($url)));
    }

    public function testEachRowCarriesTheAttributesTheScriptDependsOn(): void
    {
        $html = $this->rail->render([$this->row()]);

        self::assertStringContainsString('data-ledger=', $html);
        self::assertStringContainsString('data-token=', $html);
        self::assertStringContainsString('data-spine=', $html);
        self::assertStringContainsString('class="run"', $html);
    }

    /**
     * @param string $verdict
     * @param string $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verdicts')]
    public function testTheSpineColourFollowsTheVerdict(string $verdict, string $expected): void
    {
        $html = $this->rail->render([$this->row(['verdict' => $verdict])]);

        self::assertStringContainsString('data-spine="' . $expected . '"', $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function verdicts(): array
    {
        return [
            'hit' => [CacheVerdict::HIT, 'v-hit'],
            'miss' => [CacheVerdict::MISS, 'v-miss'],
            'uncacheable' => [CacheVerdict::UNCACHEABLE, 'v-bad'],
            'unknown' => [CacheVerdict::UNKNOWN, 'v-none'],
            'not applicable' => [CacheVerdict::NOT_APPLICABLE, 'v-none'],
            'something unexpected' => ['nonsense', 'v-none'],
        ];
    }

    public function testTheSelectedRunIsMarkedForAssistiveTechnologyAndForCss(): void
    {
        $html = $this->rail->render([$this->row(['token' => 'aaa']), $this->row(['token' => 'bbb'])], 'bbb');

        self::assertSame(1, substr_count($html, 'aria-current="true"'));
    }

    public function testAnAjaxRunIsLabelledAndAStaticRunIsToo(): void
    {
        $ajax = $this->rail->render([$this->row(['is_ajax' => true])]);
        $static = $this->rail->render([$this->row(['kind' => 'static'])]);
        $page = $this->rail->render([$this->row(['kind' => 'page'])]);

        self::assertStringContainsString('ajax', $ajax);
        self::assertStringContainsString('static', $static);
        self::assertStringNotContainsString('>page<', $page, 'the default kind adds nothing to scan past');
    }

    public function testAnEmptyLedgerSaysWhatToDoRatherThanShowingNothing(): void
    {
        $html = $this->rail->render([]);

        self::assertStringContainsString('No runs recorded yet', $html);
        self::assertStringContainsString('Load a storefront page', $html);
    }

    public function testTheFooterCarriesTheCompareAndPauseControls(): void
    {
        $html = $this->rail->render([$this->row()]);

        self::assertStringContainsString('data-compare-toggle=', $html);
        self::assertStringContainsString('data-live-toggle=', $html);
    }

    public function testAnalysisStateIsCarriedIntoEveryRowsLink(): void
    {
        $captured = [];
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $route, array $params = []) use (&$captured): string {
                $captured[] = $params['_query'] ?? [];

                return '/en-us/' . $route;
            }
        );

        $tag = new Tag($this->unitEscaper());
        $rail = new LedgerRail($tag, new Widgets($tag), new UrlBuilder($url), $this->formKey(), new FilterPanel($tag, new UrlBuilder($url)));
        $rail->render([$this->row()], null, ['nplus1' => 3, 'panel' => 'sql']);

        self::assertSame(['token' => 'abc123', 'nplus1' => 3, 'panel' => 'sql'], $captured[0]);
    }

    public function testAPoisonedUrlIsRenderedInert(): void
    {
        $html = $this->rail->render([$this->row(['url' => '"><script>alert(1)</script>'])]);

        self::assertStringNotContainsString('<script>alert', $html);
    }

    /**
     * @return \Magento\Framework\Data\Form\FormKey
     */
    private function formKey(): FormKey
    {
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('test-form-key');

        return $formKey;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'token' => 'abc123',
            'captured_at' => '2026-08-14T10:00:00+00:00',
            'method' => 'GET',
            'url' => '/en-us/',
            'full_action' => 'cms_index_index',
            'status' => 200,
            'kind' => 'page',
            'is_ajax' => false,
            'duration_ms' => 12.5,
            'statements' => 7,
            'verdict' => CacheVerdict::MISS,
        ];
    }
}

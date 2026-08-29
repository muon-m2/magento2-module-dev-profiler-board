<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfilerBoard\Model\Html\FallbackPanel;
use Muon\DevProfilerBoard\Model\Html\LayoutPanel;
use Muon\DevProfilerBoard\Model\Html\OverviewPanel;
use Muon\DevProfilerBoard\Model\Html\RawPanel;
use Muon\DevProfilerBoard\Model\Html\RunView;
use Muon\DevProfilerBoard\Model\Html\SqlPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\UrlBuilder;
use Muon\DevProfilerBoard\Model\Html\VerdictBanner;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see RunView
 *
 * `activePanel()` is the one place a raw query-string value decides which panel body is shown.
 * `RunPresenter::state()` forwards `?panel=` unfiltered, so the whitelist here is the filter — and
 * nothing called RunView directly, so nothing exercised it.
 */
#[AllowMockObjectsWithoutExpectations]
class RunViewTest extends TestCase
{
    use UnitEscaper;

    /**
     * @return RunView
     */
    private function view(): RunView
    {
        $tag = new Tag($this->unitEscaper());

        $urls = $this->createStub(UrlBuilder::class);
        $urls->method('run')->willReturn('https://muon.localhost/en-us/muon_profiler/run/view');
        $urls->method('link')->willReturn('https://muon.localhost/en-us/muon_profiler/compare/index');

        $panel = static fn (string $name): string => '<p>' . $name . ' body</p>';

        $overview = $this->createStub(OverviewPanel::class);
        $overview->method('render')->willReturn($panel('overview'));
        $fallback = $this->createStub(FallbackPanel::class);
        $fallback->method('render')->willReturn($panel('fallback'));
        $sql = $this->createStub(SqlPanel::class);
        $sql->method('render')->willReturn($panel('sql'));
        $layout = $this->createStub(LayoutPanel::class);
        $layout->method('render')->willReturn($panel('layout'));
        $raw = $this->createStub(RawPanel::class);
        $raw->method('render')->willReturn($panel('raw'));
        $banner = $this->createStub(VerdictBanner::class);
        $banner->method('render')->willReturn('<div class="banner"></div>');

        return new RunView($tag, $urls, $banner, $overview, $fallback, $sql, $layout, $raw);
    }

    /**
     * @return array<string,mixed>
     */
    private function storedRun(): array
    {
        return [
            'token' => 'abc123',
            'schema' => 1,
            'request' => ['method' => 'GET', 'url' => '/en-us/', 'kind' => 'page', 'status' => 200],
            'layout' => ['generated' => true, 'cacheable' => true],
        ];
    }

    /**
     * @param string $panel
     * @return void
     */
    #[DataProvider('knownPanels')]
    public function testAKnownPanelIsTheOneRevealed(string $panel): void
    {
        $html = $this->view()->render($this->storedRun(), [], ['panel' => $panel]);

        self::assertMatchesRegularExpression(
            '/<section[^>]*id="p-' . $panel . '"(?![^>]*\bhidden\b)/',
            $html,
            $panel . ' should be the visible panel'
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function knownPanels(): array
    {
        return [['overview'], ['fallback'], ['sql'], ['layout'], ['raw']];
    }

    /**
     * @param string $panel
     * @return void
     */
    #[DataProvider('hostilePanels')]
    public function testAnUnknownPanelFallsBackToOverviewInsteadOfBeingEmitted(string $panel): void
    {
        $html = $this->view()->render($this->storedRun(), [], ['panel' => $panel]);

        self::assertMatchesRegularExpression('/<section[^>]*id="p-overview"(?![^>]*\bhidden\b)/', $html);
        self::assertStringNotContainsString('id="p-' . $panel . '"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * @return list<array{string}>
     */
    public static function hostilePanels(): array
    {
        return [
            ['nonsense'],
            ['"><script>alert(1)</script>'],
            ['../../etc/passwd'],
            [''],
        ];
    }

    public function testEveryPanelIsRenderedSoTheTabsHaveSomethingToReveal(): void
    {
        $html = $this->view()->render($this->storedRun(), [], []);

        foreach (self::knownPanels() as [$panel]) {
            self::assertStringContainsString('id="p-' . $panel . '"', $html);
        }
    }

    /**
     * The tabs are links so the panels are reachable with JavaScript disabled; board.js intercepts
     * the click to keep them behaving as tabs.
     */
    public function testTabsAreLinksAndPanelsPointBackAtThem(): void
    {
        $html = $this->view()->render($this->storedRun(), [], []);

        self::assertMatchesRegularExpression('/<a[^>]*role="tab"[^>]*href=/', $html);
        self::assertStringContainsString('aria-labelledby="t-overview"', $html);
    }
}

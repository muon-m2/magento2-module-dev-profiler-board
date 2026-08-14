<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfilerBoard\Model\Html\FallbackPanel;
use Muon\DevProfilerBoard\Model\Html\SqlPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * A panel's own form must submit back to that panel.
 *
 * Tab switching happens in the browser and does not change the URL, so `panel` is absent from the
 * query string on a board opened at its default view. Both forms used to carry `panel` through from
 * request state, which meant it was omitted — and submitting either one bounced the reader from the
 * panel they were working in back to Overview, silently, with their filter applied to a page they
 * were no longer looking at.
 *
 * A form inside a panel knows which panel it is. These tests hold it to that.
 */
class PanelFormStateTest extends TestCase
{
    use UnitEscaper;

    private FallbackPanel $fallback;

    private SqlPanel $sql;

    protected function setUp(): void
    {
        $tag = new Tag($this->unitEscaper());
        $ui = new Widgets($tag);

        $this->fallback = new FallbackPanel($tag, $ui);
        $this->sql = new SqlPanel($tag, $ui);
    }

    public function testTheFallbackFormSubmitsBackToTheFallbackPanel(): void
    {
        $html = $this->fallback->render([], ['token' => 'abc123'], '/run/view');

        self::assertStringContainsString('name="panel" value="fallback"', $html);
    }

    public function testTheSqlFormSubmitsBackToTheSqlPanel(): void
    {
        $html = $this->sql->render($this->analysis(), ['token' => 'abc123'], '/run/view');

        self::assertStringContainsString('name="panel" value="sql"', $html);
    }

    /**
     * The regression itself: no `panel` in state — which is the normal case, because the board's
     * default view has no `?panel=` in its URL.
     */
    public function testEachFormStatesItsPanelEvenWhenTheRequestDidNotNameOne(): void
    {
        $fallback = $this->fallback->render([], ['token' => 'abc123', 'panel' => null], '/run/view');
        $sql = $this->sql->render($this->analysis(), ['token' => 'abc123', 'panel' => null], '/run/view');

        self::assertStringContainsString('name="panel" value="fallback"', $fallback);
        self::assertStringContainsString('name="panel" value="sql"', $sql);
    }

    /**
     * And a stale value from the query string must not win over the panel doing the rendering: a
     * reader on SQL with ?panel=overview still left in the URL submits back to SQL.
     */
    public function testAPanelIgnoresAConflictingPanelValueInState(): void
    {
        $html = $this->sql->render($this->analysis(), ['token' => 'abc123', 'panel' => 'overview'], '/run/view');

        self::assertStringContainsString('name="panel" value="sql"', $html);
        self::assertStringNotContainsString('name="panel" value="overview"', $html);
    }

    public function testTheOtherPanelsAnalysisStateIsStillCarriedThrough(): void
    {
        $fallback = $this->fallback->render(
            [],
            ['token' => 'abc123', 'nplus1' => 3, 'duplicate' => 4, 'slow' => 10.0],
            '/run/view'
        );

        self::assertStringContainsString('name="nplus1" value="3"', $fallback);
        self::assertStringContainsString('name="duplicate" value="4"', $fallback);
        self::assertStringContainsString('name="slow" value="10"', $fallback);

        $sql = $this->sql->render(
            $this->analysis(),
            ['token' => 'abc123', 'fallback' => 'tokens', 'shadowed' => '1'],
            '/run/view'
        );

        self::assertStringContainsString('name="fallback" value="tokens"', $sql);
        self::assertStringContainsString('name="shadowed" value="1"', $sql);
    }

    /**
     * @return array<string,mixed>
     */
    private function analysis(): array
    {
        return ['groups' => [], 'shapes' => 0, 'statements' => 0, 'total_ms' => 0.0, 'findings' => 0];
    }
}

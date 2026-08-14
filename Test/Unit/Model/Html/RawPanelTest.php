<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\Serialize\Serializer\Json;
use Muon\DevProfilerBoard\Model\Html\RawPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * @see RawPanel
 */
class RawPanelTest extends TestCase
{
    use UnitEscaper;

    private RawPanel $panel;

    protected function setUp(): void
    {
        $tag = new Tag($this->unitEscaper());
        $this->panel = new RawPanel($tag, new Widgets($tag), new Json());
    }

    public function testTheStoredDocumentIsRenderedReadably(): void
    {
        $html = $this->panel->render(['token' => 'abc123', 'request' => ['url' => '/en-us/']], '/json', '/md');

        self::assertStringContainsString('abc123', $html);
        self::assertStringContainsString('<pre class="raw">', $html);
        self::assertStringContainsString("\n", $html, 'the JSON is pretty-printed, not one line');
    }

    public function testSlashesInPathsAreNotEscapedIntoNoise(): void
    {
        $html = $this->panel->render(['request' => ['url' => '/en-us/gear/bags.html']], '/json', '/md');

        self::assertStringNotContainsString('\\/en-us', $html);
    }

    public function testBothCopyActionsPointAtTheirEndpoints(): void
    {
        $html = $this->panel->render(['token' => 'abc123'], '/run/json?token=abc123', '/run/markdown?token=abc123');

        self::assertStringContainsString('data-copy-label="JSON"', $html);
        self::assertStringContainsString('data-copy-label="Markdown"', $html);
        self::assertStringContainsString('run&#x2F;json', $html);
        self::assertStringContainsString('run&#x2F;markdown', $html);
    }

    public function testAPoisonedValueIsRenderedInert(): void
    {
        $html = $this->panel->render(['request' => ['url' => '"><script>alert(1)</script>']], '/json', '/md');

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('alert', $html);
    }
}

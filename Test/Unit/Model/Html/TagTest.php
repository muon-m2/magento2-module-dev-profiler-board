<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Test\Unit\UnitEscaper;
use PHPUnit\Framework\TestCase;

/**
 * @see Tag
 */
class TagTest extends TestCase
{
    use UnitEscaper;

    private Tag $tag;

    protected function setUp(): void
    {
        $this->tag = new Tag($this->unitEscaper());
    }

    public function testBuildsAnElementWithChildren(): void
    {
        self::assertSame('<p class="x">hello</p>', $this->tag->tag('p', ['class' => 'x'], 'hello'));
    }

    public function testVoidElementsGetNoClosingTag(): void
    {
        self::assertSame('<meta charset="utf-8">', $this->tag->tag('meta', ['charset' => 'utf-8']));
        self::assertStringNotContainsString('</meta>', $this->tag->tag('meta', []));
    }

    public function testNullAndFalseAttributesAreDropped(): void
    {
        $markup = $this->tag->tag('div', ['id' => null, 'hidden' => false, 'class' => 'k'], '');

        self::assertSame('<div class="k"></div>', $markup);
    }

    public function testTrueAttributesAreWrittenBare(): void
    {
        self::assertSame('<div hidden></div>', $this->tag->tag('div', ['hidden' => true], ''));
    }

    /**
     * The regression behind R2's Medium finding. Magento's escapeUrl() already applies
     * escapeHtmlAttr(), so escaping its output a second time turns the "&" between query parameters
     * into "&amp;amp;" — which a browser decodes to a literal "&amp;", silently breaking every
     * threshold control on the board.
     */
    public function testAUrlAttributeIsEscapedExactlyOnce(): void
    {
        $markup = $this->tag->tag('a', ['href' => '/x?a=1&b=2'], 'go');

        self::assertStringContainsString('a=1&amp;b=2', $markup);
        self::assertStringNotContainsString('&amp;amp;', $markup);
    }

    public function testADangerousUrlSchemeIsStripped(): void
    {
        $markup = $this->tag->tag('a', ['href' => 'javascript:alert(1)'], 'go');

        self::assertStringNotContainsString('javascript:', $markup);
    }

    public function testTextIsEscaped(): void
    {
        $escaped = $this->tag->text('<b>&</b>');

        self::assertStringNotContainsString('<b>', $escaped);
        self::assertStringContainsString('&amp;', $escaped);
    }

    public function testBooleansAndNullRenderAsWords(): void
    {
        self::assertSame('yes', $this->tag->text(true));
        self::assertSame('no', $this->tag->text(false));
        self::assertSame('', $this->tag->text(null));
    }

    public function testANonScalarRendersAsAPlaceholderRatherThanRaising(): void
    {
        self::assertSame('—', $this->tag->text([1, 2, 3]));
    }
}

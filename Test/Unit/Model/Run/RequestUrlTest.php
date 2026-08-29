<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Run;

use Muon\DevProfilerBoard\Model\Run\RequestUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The recorded request URL is attacker-controlled — anyone who can reach the storefront chooses it,
 * and the collector stores it verbatim. Escaping makes it safe to *display*; this decides whether it
 * is safe to *offer as a link*, which is a stronger claim, because a link is something the reader is
 * invited to follow.
 *
 * @see RequestUrl
 */
class RequestUrlTest extends TestCase
{
    private RequestUrl $urls;

    protected function setUp(): void
    {
        $this->urls = new RequestUrl();
    }

    /**
     * @param string $recorded
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localPaths')]
    public function testARootRelativePathIsOfferedAsALink(string $recorded): void
    {
        self::assertSame($recorded, $this->urls->openable($recorded));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localPaths(): array
    {
        return [
            'home' => ['/'],
            'store-prefixed page' => ['/en-us/'],
            'deep path' => ['/en-us/gear/bags.html'],
            'with a query' => ['/en-us/customer/section/load/?sections=cart,messages'],
            'static asset' => ['/static/frontend/Muon/cosmic-custom/en_US/css/styles.css'],
        ];
    }

    /**
     * The one that matters. A request to "//evil.com/x" is recorded verbatim, escapes cleanly, and
     * as an href is protocol-relative — it navigates to another origin from a page the reader
     * trusts. It looks exactly like a path, which is why it is refused by rule rather than by eye.
     *
     * @param string $recorded
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUrls')]
    public function testAnythingThatCouldLeaveThisInstanceIsNotLinked(string $recorded): void
    {
        self::assertNull($this->urls->openable($recorded));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'protocol-relative' => ['//evil.example/x'],
            'protocol-relative with backslash' => ['//evil.example\\@muon.localhost/'],
            'absolute http' => ['http://evil.example/x'],
            'absolute https' => ['https://evil.example/x'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'schemeless host' => ['evil.example/x'],
            'relative path' => ['../../etc/passwd'],
            'empty' => [''],
        ];
    }

    public function testANonStringIsRefused(): void
    {
        self::assertNull($this->urls->openable(null));
        self::assertNull($this->urls->openable(['/en-us/']));
        self::assertNull($this->urls->openable(42));
    }

    /**
     * A refused URL is still shown to the reader — it is evidence about the request, and hiding it
     * would be worse than not linking it. Only the link is withheld.
     */
    public function testRefusingToLinkIsNotRefusingToShow(): void
    {
        self::assertNull($this->urls->openable('//evil.example/x'), 'not linkable');
        // The panel falls back to rendering the recorded value as escaped text; see OverviewPanel.
    }

    /**
     * The bypass that shipped in 1.0.0.
     *
     * LOCAL_PATH blocked `//` and nothing else. In the WHATWG URL parser's relative-slash state a
     * backslash routes into special-authority-ignore-slashes exactly as a slash does, so browsers
     * resolve `/\evil.example/` to another origin — and `escapeUrl()` leaves the backslash alone.
     * The second form is the dangerous one: it renders with the real host at the end and reads as
     * a local path.
     *
     * @return list<array{string}>
     */
    public static function backslashBypasses(): array
    {
        return [
            ['/\\evil.example/'],
            ['/\\evil.example\\@muon.localhost/'],
            ['/\\\\evil.example/'],
            ['/\\'],
        ];
    }

    /**
     * @param string $url
     * @return void
     */
    #[DataProvider('backslashBypasses')]
    public function testABackslashInTheFirstSegmentIsRefused(string $url): void
    {
        self::assertNull((new RequestUrl())->openable($url));
    }

    /**
     * The counterweight: the guard must not start refusing the ordinary paths it exists to allow.
     *
     * @return list<array{string}>
     */
    public static function ordinaryPaths(): array
    {
        return [
            ['/en-us/nb-home'],
            ['/en-us/catalog/product/view/id/42'],
            ['/en-us/catalogsearch/result/?q=lamp'],
            ['/'],
        ];
    }

    /**
     * @param string $url
     * @return void
     */
    #[DataProvider('ordinaryPaths')]
    public function testAnOrdinaryPathIsStillOpenable(string $url): void
    {
        self::assertSame($url, (new RequestUrl())->openable($url));
    }
}

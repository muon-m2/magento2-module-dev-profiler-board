<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Run;

use Muon\DevProfilerBoard\Model\Run\TokenFilter;
use PHPUnit\Framework\TestCase;

/**
 * @see TokenFilter
 */
class TokenFilterTest extends TestCase
{
    private TokenFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new TokenFilter();
    }

    public function testAValidTokenPassesThroughUnchanged(): void
    {
        self::assertSame('7f3a9c2e1b4d', $this->filter->filter('7f3a9c2e1b4d'));
    }

    /**
     * A token travels by being read off a screen and typed back in. Anything that uppercases it on
     * the way would otherwise have every A–F character stripped, leaving a shorter token that
     * resolves to nothing — or, worse, to a different run.
     */
    public function testAnUppercasedTokenIsAccepted(): void
    {
        self::assertSame('7f3a9c2e1b4d', $this->filter->filter('7F3A9C2E1B4D'));
    }

    public function testPathSeparatorsAndTraversalAreStripped(): void
    {
        self::assertSame('abc', $this->filter->filter('../../abc'));
        self::assertSame('abcdef', $this->filter->filter('abc/../def'));
    }

    public function testNullStaysNull(): void
    {
        self::assertNull($this->filter->filter(null));
    }

    public function testAValueWithNoHexAtAllBecomesNull(): void
    {
        self::assertNull($this->filter->filter(''));
        self::assertNull($this->filter->filter('*.json'));
        self::assertNull($this->filter->filter('../../'));
    }

    /**
     * The property that matters is not that hostile input becomes null — it is that whatever
     * survives is hex and nothing else. "../../etc/passwd" reduces to "ecad", which is a perfectly
     * well-formed token that simply matches no file in the ring; the traversal is gone, not merely
     * rejected.
     *
     * @param string $hostile
     * @param string $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileInput')]
    public function testWhateverSurvivesIsHexAndNothingElse(string $hostile, string $expected): void
    {
        $filtered = (string)$this->filter->filter($hostile);

        self::assertSame($expected, $filtered);
        self::assertMatchesRegularExpression('/^[a-f0-9]*$/', $filtered);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostileInput(): array
    {
        return [
            'traversal to a real file' => ['../../etc/passwd', 'ecad'],
            'markup' => ['<script>', 'c'],
            'null byte' => ["abc\0def", 'abcdef'],
            'wildcard' => ['*.json', ''],
            'absolute path' => ['/var/www/magento/app/etc/env.php', 'aaeaece'],
            'url-encoded null' => ['%00', '00'],
        ];
    }
}

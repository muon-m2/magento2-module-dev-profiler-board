<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Response;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Muon\DevProfilerBoard\Model\Access\BoardGate;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see BoardResponse
 *
 * The headers here are a stated privacy control, not decoration: a profiler dump must not be cached
 * by a proxy and must not be indexed. Nothing asserted them, and a header that silently stops being
 * sent looks exactly like one that is.
 */
#[AllowMockObjectsWithoutExpectations]
class BoardResponseTest extends TestCase
{
    /** @var array<string, string> */
    private array $headers = [];

    private int $status = 200;

    /**
     * @param bool $open
     * @return BoardResponse
     */
    private function response(bool $open = true): BoardResponse
    {
        $this->headers = [];
        $this->status = 200;

        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(
            function (string $name, string $value): Raw {
                $this->headers[strtolower($name)] = $value;

                return $this->createStub(Raw::class);
            }
        );
        $raw->method('setHttpResponseCode')->willReturnCallback(
            function (int $code): Raw {
                $this->status = $code;

                return $this->createStub(Raw::class);
            }
        );
        $raw->method('setContents')->willReturnSelf();

        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);

        $gate = $this->createMock(BoardGate::class);
        $gate->method('isOpen')->willReturn($open);

        return new BoardResponse($factory, $gate);
    }

    /**
     * @return list<array{string}>
     */
    public static function flavours(): array
    {
        return [['html'], ['json'], ['text'], ['css'], ['javascript']];
    }

    /**
     * @param string $method
     * @return void
     */
    #[DataProvider('flavours')]
    public function testEveryResponseRefusesToBeCachedOrIndexed(string $method): void
    {
        $this->response()->{$method}('x');

        self::assertSame('no-store, private', $this->headers['cache-control'] ?? null, $method);
        self::assertSame('noindex, nofollow', $this->headers['x-robots-tag'] ?? null, $method);
    }

    /**
     * @param string $method
     * @return void
     */
    #[DataProvider('flavours')]
    public function testEveryResponseDeclaresItsContentType(string $method): void
    {
        $this->response()->{$method}('x');

        self::assertArrayHasKey('content-type', $this->headers, $method);
        self::assertNotSame('', $this->headers['content-type']);
    }

    /**
     * A JSON endpoint served as text/html is an XSS vector, and this one echoes recorded request
     * URIs — which a visitor chooses.
     */
    public function testTheJsonEndpointIsNotServedAsHtml(): void
    {
        $this->response()->json('{}');

        self::assertStringContainsString('application/json', $this->headers['content-type']);
        self::assertStringNotContainsString('text/html', $this->headers['content-type']);
    }

    public function testNotFoundIsAFourOhFour(): void
    {
        $this->response()->notFound();

        self::assertSame(404, $this->status, 'a 200 with "Not found." in the body is not a 404');
    }

    public function testTheGateIsPassedThrough(): void
    {
        self::assertTrue($this->response(true)->isOpen());
        self::assertFalse($this->response(false)->isOpen());
    }
}

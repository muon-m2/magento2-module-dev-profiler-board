<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Response;

require_once __DIR__ . '/../../Stub/generated.php';

use Magento\Framework\App\Request\Http as HttpRequest;
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

    private string $body = '';

    /**
     * @param bool $open
     * @param string|false $ifNoneMatch What the caller already holds, if anything.
     * @return BoardResponse
     */
    private function response(bool $open = true, string|false $ifNoneMatch = false): BoardResponse
    {
        $this->headers = [];
        $this->status = 200;
        $this->body = '';

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
        $raw->method('setContents')->willReturnCallback(
            function (string $contents): Raw {
                $this->body = $contents;

                return $this->createStub(Raw::class);
            }
        );

        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);

        $gate = $this->createMock(BoardGate::class);
        $gate->method('isOpen')->willReturn($open);

        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willReturn($ifNoneMatch);

        return new BoardResponse($factory, $gate, $request);
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

    /**
     * The ETag has to actually do something.
     *
     * Setting the header without answering the conditional request it invites means the browser
     * sends If-None-Match on every board page load and is handed the full body back every time —
     * all of the request cost, none of the saving, and a response that looks correct on the wire.
     */
    public function testAMatchingIfNoneMatchGetsA304WithNoBody(): void
    {
        $response = $this->response(true, '"abc-123"');
        $response->css('body { color: red }', '"abc-123"');

        self::assertSame(304, $this->status);
        self::assertSame('', $this->body, 'a 304 carries no body');
        self::assertSame('"abc-123"', $this->headers['etag'] ?? null, 'RFC 9110 wants the validator repeated');
    }

    public function testAStaleIfNoneMatchGetsTheFullBody(): void
    {
        $response = $this->response(true, '"old"');
        $response->css('body { color: red }', '"new"');

        self::assertSame(200, $this->status);
        self::assertSame('body { color: red }', $this->body);
        self::assertSame('"new"', $this->headers['etag'] ?? null);
    }

    /**
     * A proxy may weaken a validator in transit, and the header is a list.
     */
    public function testAWeakenedOrListedValidatorStillMatches(): void
    {
        $response = $this->response(true, 'W/"zzz", "abc-123"');
        $response->css('body { color: red }', '"abc-123"');

        self::assertSame(304, $this->status);
    }

    /**
     * The closed gate must stay bare — an 11-byte body with the board's own headers was a
     * one-request oracle for the board's presence. But a 404 that *does* carry a message has
     * already passed the gate, and a body with no declared type is one a browser may sniff.
     */
    public function testAClosedGate404DisclosesNothingHeadersIncluded(): void
    {
        $this->response()->notFound();

        self::assertSame('', $this->body);
        self::assertSame([], $this->headers, 'any board header here is an oracle for the board');
    }

    public function testAMessaged404CarriesTheSafeHeaders(): void
    {
        $this->response()->notFound('No such run.');

        self::assertStringContainsString('No such run.', $this->body);
        self::assertSame('nosniff', $this->headers['x-content-type-options'] ?? null);
        self::assertStringContainsString('text/plain', $this->headers['content-type'] ?? '');
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Controller\Runs;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Muon\DevProfiler\Model\Store\RunStore;
use Muon\DevProfilerBoard\Controller\Runs\Clear;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see Clear
 *
 * The board's only state-changing request, on an unauthenticated storefront route, and the only
 * place a cross-site POST could do anything — what it would destroy is the evidence somebody is in
 * the middle of reading. The form-key check is the whole defence, so it is asserted rather than
 * assumed.
 */
#[AllowMockObjectsWithoutExpectations]
class ClearTest extends TestCase
{
    /** @var FormKeyValidator&MockObject */
    private FormKeyValidator $formKeyValidator;

    /** @var RunStore&MockObject */
    private RunStore $store;

    /** @var RedirectFactory&MockObject */
    private RedirectFactory $redirectFactory;

    /** @var BoardResponse&MockObject */
    private BoardResponse $response;

    /** @var Redirect&MockObject */
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->formKeyValidator = $this->createMock(FormKeyValidator::class);
        $this->store = $this->createMock(RunStore::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setUrl')->willReturnSelf();

        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->response = $this->createMock(BoardResponse::class);
    }

    /**
     * @return Clear
     */
    private function controller(): Clear
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->method('link')->willReturn('https://muon.localhost/muon_profiler/');

        return new Clear(
            $this->formKeyValidator,
            $this->store,
            $this->redirectFactory,
            $urls,
            $this->response
        );
    }

    /**
     * The framework waives the form key for anything that is not a POST *unless* the action answers
     * validateForCsrf() itself. This one does, so the check applies to every verb — which is what
     * closes the `<img src>` vector on a route that deletes data.
     */
    public function testTheFormKeyIsValidatedRatherThanWaived(): void
    {
        $this->response->method('isOpen')->willReturn(true);
        $request = $this->createMock(RequestInterface::class);

        $this->formKeyValidator->expects(self::once())
            ->method('validate')
            ->with($request)
            ->willReturn(false);

        self::assertFalse($this->controller()->validateForCsrf($request));
    }

    public function testAValidFormKeyIsAccepted(): void
    {
        $this->response->method('isOpen')->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $this->formKeyValidator->method('validate')->willReturn(true);

        self::assertTrue($this->controller()->validateForCsrf($request));
    }

    /**
     * Never null: returning null lets CsrfValidator fall back to its "not a POST, so allow it"
     * shortcut, which would reopen the vector this action closed.
     */
    public function testValidateForCsrfNeverDefersToTheFrameworkDefault(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $this->response->method('isOpen')->willReturn(true);

        foreach ([true, false] as $answer) {
            $validator = $this->createMock(FormKeyValidator::class);
            $validator->method('validate')->willReturn($answer);
            $this->formKeyValidator = $validator;

            self::assertNotNull($this->controller()->validateForCsrf($request));
        }
    }

    public function testARejectedRequestIsSentBackToTheBoardAndClearsNothing(): void
    {
        $this->store->expects(self::never())->method('clear');

        $exception = $this->controller()->createCsrfValidationException(
            $this->createMock(RequestInterface::class)
        );

        self::assertInstanceOf(InvalidRequestException::class, $exception);
        self::assertSame($this->redirect, $exception->getReplaceResult());
    }

    public function testItIsDeclaredPostOnly(): void
    {
        self::assertInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    /**
     * The gate is checked before the store is touched — a closed board must not delete runs even
     * for a request carrying a valid form key.
     */
    public function testAClosedGateClearsNothing(): void
    {
        $this->response->method('isOpen')->willReturn(false);
        $this->response->method('notFound')
            ->willReturn($this->createMock(\Magento\Framework\Controller\Result\Raw::class));

        $this->store->expects(self::never())->method('clear');

        $this->controller()->execute();
    }

    public function testAnOpenGateClearsTheRingAndRedirects(): void
    {
        $this->response->method('isOpen')->willReturn(true);

        $this->store->expects(self::once())->method('clear')->willReturn(7);
        $this->redirect->expects(self::once())->method('setUrl')->willReturnSelf();

        self::assertSame($this->redirect, $this->controller()->execute());
    }

    /**
     * FrontController validates before it dispatches, so execute()'s own gate check is never
     * reached on this path. Without the gate here, a form-key-less cross-site POST to a
     * production-mode instance answered 302 to the board index and planted "Invalid form key" in an
     * arbitrary shopper's session — announcing the module and writing into a stranger's session.
     *
     * Returning true is safe precisely because execute() then refuses with a 404.
     */
    public function testAClosedBoardDoesNotAnswerACrossSitePostAtAll(): void
    {
        $this->response->method('isOpen')->willReturn(false);
        $this->formKeyValidator->expects(self::never())->method('validate');

        self::assertTrue(
            $this->controller()->validateForCsrf($this->createMock(RequestInterface::class)),
            'the gate answers, and execute() returns the plain 404'
        );
    }
}

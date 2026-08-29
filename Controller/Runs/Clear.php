<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Runs;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Muon\DevProfiler\Model\Store\RunStore;
use Muon\DevProfilerBoard\Model\Url\UrlBuilder;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;

/**
 * Empties the ring.
 *
 * The board's **only** mutation, and the only reason it has a POST action at all. It exists because
 * the collector's own console output tells a reader to run `bin/magento muon:profile:clear` before capturing a
 * cold request — a documented workflow the board could otherwise not perform, which left a seam
 * between reading here and acting in a terminal.
 *
 * It deletes this module's own data and nothing else. Flushing Magento's page cache — the other half
 * of that workflow — is deliberately **not** done here: it mutates state this module does not own,
 * from a page with no authentication, and on a shared instance it would hit whoever else is using
 * it. The board says what to run instead.
 *
 * POST + form key, and a redirect afterwards so a refresh cannot silently clear a second time.
 */
class Clear implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param \Magento\Framework\Controller\Result\RedirectFactory $redirectFactory
     * @param \Muon\DevProfilerBoard\Model\Url\UrlBuilder $urls
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     */
    public function __construct(
        private readonly FormKeyValidator $formKeyValidator,
        private readonly RunStore $store,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlBuilder $urls,
        private readonly BoardResponse $response
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->response->isOpen()) {
            return $this->response->notFound();
        }

        $removed = $this->store->clear();

        // Redirect rather than render: the board is then reached by a GET, so refreshing the page
        // afterwards re-reads an empty ring instead of re-submitting the clear.
        return $this->redirectFactory->create()->setUrl(
            $this->urls->link(UrlBuilder::ROUTE_INDEX, ['cleared' => $removed])
        );
    }

    /**
     * Where a request that failed the form-key check is sent.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return \Magento\Framework\App\Request\InvalidRequestException|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is fixed by
     * CsrfAwareActionInterface. A rejected clear goes back to the board regardless of what was
     * requested — there is nothing in the request worth reading, and echoing any of it back would
     * be reflecting unvalidated input onto the page that just refused it.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // The message is also carried as a query parameter. Magento queues it for the message
        // manager, but the board renders a Result\Raw with no message area, and Magento_Theme's
        // MessagePlugin drains the queue into the mage-messages cookie that nothing here reads —
        // so a rejected clear silently redirected with no explanation.
        return new InvalidRequestException(
            $this->redirectFactory->create()->setUrl(
                $this->urls->link(UrlBuilder::ROUTE_INDEX, ['rejected' => 'form_key'])
            ),
            [__('Invalid form key. Please refresh the board and try again.')]
        );
    }

    /**
     * The form key is validated rather than waived.
     *
     * This is the board's only state-changing request, so it is also the only place a cross-site
     * POST could do anything — and what it would do is destroy the evidence somebody is in the
     * middle of reading.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Gate first. FrontController validates BEFORE dispatching, so execute()'s own isOpen()
        // check is never reached on this path — a form-key-less cross-site POST to a
        // production-mode instance answered 302 to the board index and planted "Invalid form key"
        // in an arbitrary shopper's session. Returning true here is safe precisely because
        // execute() then refuses with a 404.
        if (!$this->response->isOpen()) {
            return true;
        }

        return $this->formKeyValidator->validate($request);
    }
}

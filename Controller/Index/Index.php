<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Muon\DevProfilerBoard\Model\Board\RunPresenter;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;

/**
 * The board's front door: the most recent full-document run.
 *
 * Renders rather than redirects, so opening the board is one request and the URL stays stable while
 * new runs arrive behind it.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Muon\DevProfilerBoard\Model\Board\RunPresenter $presenter
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunPresenter $presenter,
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

        return $this->response->html($this->presenter->present($this->request));
    }
}

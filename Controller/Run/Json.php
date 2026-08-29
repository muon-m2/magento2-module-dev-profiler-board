<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Run;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Muon\DevProfilerBoard\Model\Board\RunPresenter;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;

/**
 * The stored document, verbatim.
 *
 * Nothing derived and nothing reformatted: this is the file the collector wrote, which is what makes
 * every claim elsewhere on the board checkable.
 */
class Json implements HttpGetActionInterface
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

        $run = $this->presenter->run($this->request);

        if ($run === null) {
            return $this->response->notFound('No such run.');
        }

        $encoded = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);

        return $this->response->json($encoded === false ? '{}' : $encoded);
    }
}

<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Controller\Asset;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Muon\DevProfilerBoard\Model\Asset\AssetReader;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;

/**
 * The board stylesheet. One fixed file; the request supplies no path.
 */
class Stylesheet implements HttpGetActionInterface
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Asset\AssetReader $assets
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     */
    public function __construct(
        private readonly AssetReader $assets,
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

        return $this->response->css(
            $this->assets->read(AssetReader::CSS) ?? '',
            $this->assets->fingerprint(AssetReader::CSS)
        );
    }
}

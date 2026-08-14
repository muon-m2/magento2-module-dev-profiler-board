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
use Muon\DevProfilerBoard\Model\Analysis\RunAnalysis;
use Muon\DevProfilerBoard\Model\Analysis\Thresholds;
use Muon\DevProfilerBoard\Model\Board\RunPresenter;
use Muon\DevProfilerBoard\Model\Export\MarkdownExporter;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;

/**
 * One run as Markdown, for pasting somewhere the evidence has to travel with the claim.
 *
 * Served as text/plain so a browser shows it rather than downloading it, and so the copy button can
 * fetch it directly.
 */
class Markdown implements HttpGetActionInterface
{
    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Muon\DevProfilerBoard\Model\Board\RunPresenter $presenter
     * @param \Muon\DevProfilerBoard\Model\Analysis\RunAnalysis $analysis
     * @param \Muon\DevProfilerBoard\Model\Analysis\Thresholds $thresholds
     * @param \Muon\DevProfilerBoard\Model\Export\MarkdownExporter $exporter
     * @param \Muon\DevProfilerBoard\Model\Response\BoardResponse $response
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunPresenter $presenter,
        private readonly RunAnalysis $analysis,
        private readonly Thresholds $thresholds,
        private readonly MarkdownExporter $exporter,
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

        $thresholds = $this->thresholds->fromRequest($this->request);

        return $this->response->text(
            $this->exporter->export($run, $this->analysis->analyse($run, $thresholds), $thresholds)
        );
    }
}

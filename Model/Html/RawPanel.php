<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * The stored document, verbatim.
 *
 * Every other panel is an interpretation; this one is the source. It matters more than it looks —
 * the module's own promise is that analysis happens at read time against recorded facts, and this
 * panel is where a reader can check that a claim upstairs is actually in the evidence.
 *
 * The copy actions here are the reason the board earns its place next to the CLI: a run pasted into
 * an issue, or into a Claude session, travels with its evidence attached.
 */
class RawPanel
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\Widgets $ui
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly Widgets $ui,
        private readonly Json $json
    ) {
    }

    /**
     * @param array<string,mixed> $run
     * @param string $jsonUrl
     * @param string $markdownUrl
     * @return string
     */
    public function render(array $run, string $jsonUrl, string $markdownUrl): string
    {
        return $this->actions($jsonUrl, $markdownUrl)
            . $this->tag->tag('pre', ['class' => 'raw'], $this->tag->text($this->pretty($run)));
    }

    /**
     * @param string $jsonUrl
     * @param string $markdownUrl
     * @return string
     */
    private function actions(string $jsonUrl, string $markdownUrl): string
    {
        $buttons = $this->tag->tag('button', [
            'class' => 'btn',
            'type' => 'button',
            'data-copy' => $jsonUrl,
            'data-copy-label' => 'JSON',
        ], 'Copy JSON')
            . $this->tag->tag('button', [
                'class' => 'btn',
                'type' => 'button',
                'data-copy' => $markdownUrl,
                'data-copy-label' => 'Markdown',
            ], 'Copy Markdown')
            . $this->tag->tag('a', [
                'class' => 'btn',
                'href' => $jsonUrl,
                'target' => '_blank',
                'rel' => 'noopener',
            ], 'Open JSON');

        return $this->tag->tag(
            'div',
            ['class' => 'controls'],
            $this->ui->lede('Exactly what the collector wrote. Nothing here is derived.')
            . $this->tag->tag('div', ['class' => 'actions'], $buttons)
        );
    }

    /**
     * @param array<string,mixed> $run
     * @return string
     */
    private function pretty(array $run): string
    {
        // Magento's Json serializer has no pretty-print option and this output is read by a human.
        $encoded = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? (string)$this->json->serialize($run) : $encoded;
    }
}

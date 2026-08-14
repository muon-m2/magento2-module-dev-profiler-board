<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Muon\DevProfilerBoard\Model\Run\RequestUrl;

/**
 * Everything the collector recorded about the request itself.
 *
 * The panel's real job is the notices at the bottom. A capped list that reads as a complete one is
 * the failure mode this whole module is built to avoid, so a truncated run says so in the same
 * place a reader is already looking.
 */
class OverviewPanel
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     * @param \Muon\DevProfilerBoard\Model\Html\Widgets $ui
     * @param \Muon\DevProfilerBoard\Model\Run\RequestUrl $requestUrl
     */
    public function __construct(
        private readonly Tag $tag,
        private readonly Widgets $ui,
        private readonly RequestUrl $requestUrl
    ) {
    }

    /**
     * @param array<string,mixed> $run
     * @return string
     */
    public function render(array $run): string
    {
        $request = $this->section($run, 'request');
        $context = $this->section($run, 'context');

        return $this->ui->heading('Request')
            . $this->ui->factsHtml([
                'Method' => $this->tag->text($request['method'] ?? '—'),
                'URL' => $this->url($request),
                // Null is not "unknown formatting" here — it means the request never routed, which
                // is what a full-page-cache hit and a static asset both look like.
                'Full action' => $this->tag->text($request['full_action'] ?? 'none — request never routed'),
                'Status' => $this->tag->text($request['status'] ?? '—'),
                'Kind' => $this->tag->text($request['kind'] ?? 'page'),
                'XHR' => $this->tag->text(!empty($request['is_ajax']) ? 'yes' : 'no'),
                'Captured' => $this->tag->text($run['captured_at'] ?? '—'),
                'Schema' => $this->tag->text($run['schema'] ?? '—'),
            ])
            . $this->ui->heading('Context')
            . $this->ui->factsHtml([
                'Store' => $this->tag->text($this->store($context)),
                'Website' => $this->tag->text($context['website_id'] ?? '—'),
                'Theme' => $this->theme($context),
            ])
            . $this->themeNote($context)
            . $this->truncation($run);
    }

    /**
     * The recorded URL, as a link back to the page it profiled when that is safe to offer.
     *
     * Opening it re-runs the page, and the ledger picks the new run up on its next poll — so the
     * loop of "change something, reload, compare" never leaves the board. It opens in a new tab
     * deliberately: the point is usually to compare the new run against this one, and replacing the
     * board loses the run you were reading.
     *
     * The href is the recorded path unchanged. It is root-relative, so the browser resolves it
     * against this instance and there is nothing to reconstruct — which also means it cannot be
     * pointed anywhere else. `RequestUrl` refuses the shapes that could be.
     *
     * @param array<string,mixed> $request
     * @return string
     */
    private function url(array $request): string
    {
        $recorded = $request['url'] ?? null;
        $href = $this->requestUrl->openable($recorded);

        if ($href === null) {
            return $this->tag->text($recorded ?? '—');
        }

        return $this->tag->tag(
            'a',
            [
                'href' => $href,
                'target' => '_blank',
                'rel' => 'noopener',
                'class' => 'open-url',
                'title' => 'Open this page in a new tab — the new run appears in the ledger',
            ],
            $this->tag->text($href) . $this->tag->tag('span', ['class' => 'open-url-mark'], '↗')
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return string
     */
    private function store(array $context): string
    {
        $code = (string)($context['store_code'] ?? '');

        if ($code === '') {
            return '— (request never resolved a store)';
        }

        return $context['store_id'] === null ? $code : sprintf('%s (id %d)', $code, (int)$context['store_id']);
    }

    /**
     * @param array<string,mixed> $context
     * @return string
     */
    private function theme(array $context): string
    {
        $path = (string)($context['theme_path'] ?? '');

        if ($path === '') {
            return $this->tag->text('—');
        }

        $source = (string)($context['theme_source'] ?? '');

        return $this->tag->text($path) . ' ' . $this->ui->chip(
            $source === '' ? 'unknown' : $source,
            $source === 'observed' ? 'v-hit' : 'v-none'
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return string
     */
    private function themeNote(array $context): string
    {
        if ((string)($context['theme_source'] ?? '') !== 'configured') {
            return '';
        }

        return $this->ui->note(
            'Theme reported as "configured", not "observed": this request resolved no files, so the '
            . 'theme shown is what the store is set to rather than one this request demonstrably '
            . 'used. That is what a full-page-cache hit looks like.'
        );
    }

    /**
     * @param array<string,mixed> $run
     * @return string
     */
    private function truncation(array $run): string
    {
        $truncated = $this->section($run, 'truncated');
        $notes = '';

        foreach (['fallback' => 'fallback resolutions', 'queries' => 'statement shapes'] as $key => $label) {
            $dropped = (int)($truncated[$key] ?? 0);

            if ($dropped > 0) {
                $notes .= $this->ui->note(sprintf(
                    'Capped: %d %s were recorded and %d more were refused after the per-list cap. '
                    . 'What is shown is a subset, not the whole page.',
                    count($this->section($run, $key === 'queries' ? 'queries' : 'fallback')),
                    $label,
                    $dropped
                ));
            }
        }

        return $notes;
    }

    /**
     * @param array<string,mixed> $run
     * @param string $key
     * @return array<string,mixed>
     */
    private function section(array $run, string $key): array
    {
        $section = $run[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}

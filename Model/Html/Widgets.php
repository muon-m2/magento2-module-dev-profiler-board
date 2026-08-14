<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

/**
 * The handful of shapes every panel is built from.
 *
 * Chips, key/value lists, tables and notes appear in all five panels; defining them once keeps the
 * panels short enough to read as content rather than markup, and means a change to how a table
 * scrolls happens in one place instead of five.
 *
 * Every method escapes what it is given. Callers pass recorded values straight in — no panel needs
 * to remember to escape, which is the same reason Tag exists.
 */
class Widgets
{
    /**
     * @param \Muon\DevProfilerBoard\Model\Html\Tag $tag
     */
    public function __construct(
        private readonly Tag $tag
    ) {
    }

    /**
     * A small labelled pill. Modifier carries verdict or severity colour.
     *
     * @param mixed $label
     * @param string|null $modifier
     * @return string
     */
    public function chip(mixed $label, ?string $modifier = null): string
    {
        return $this->tag->tag(
            'span',
            ['class' => $modifier === null ? 'chip' : 'chip ' . $modifier],
            $this->tag->text($label)
        );
    }

    /**
     * An uppercase section label.
     *
     * @param string $text
     * @return string
     */
    public function eyebrow(string $text): string
    {
        return $this->tag->tag('span', ['class' => 'eyebrow'], $this->tag->text($text));
    }

    /**
     * A section label that keeps the text's own case.
     *
     * Separate from eyebrow() rather than a flag on it, because the distinction is about the
     * content, not the styling: this is for anything a reader may copy back into a command. A run
     * token is lowercase hex, and uppercasing it for looks hands them a token that resolves to
     * nothing.
     *
     * @param string $text
     * @return string
     */
    public function eyebrowVerbatim(string $text): string
    {
        return $this->tag->tag('span', ['class' => 'eyebrow is-verbatim'], $this->tag->text($text));
    }

    /**
     * A panel heading.
     *
     * @param string $text
     * @param string|null $count
     * @return string
     */
    public function heading(string $text, ?string $count = null): string
    {
        $inner = $this->tag->text($text);

        if ($count !== null) {
            $inner .= ' ' . $this->eyebrow($count);
        }

        return $this->tag->tag('h3', [], $inner);
    }

    /**
     * A definition list of recorded facts.
     *
     * @param array<string,mixed> $pairs Label to value; values are escaped.
     * @return string
     */
    public function facts(array $pairs): string
    {
        $inner = '';

        foreach ($pairs as $label => $value) {
            $inner .= $this->tag->tag('dt', [], $this->tag->text($label))
                . $this->tag->tag('dd', [], $this->tag->text($value));
        }

        return $this->tag->tag('dl', ['class' => 'kv'], $inner);
    }

    /**
     * A definition list whose values are already-built markup.
     *
     * @param array<string,string> $pairs Label to markup built by Tag or this class.
     * @return string
     */
    public function factsHtml(array $pairs): string
    {
        $inner = '';

        foreach ($pairs as $label => $markup) {
            $inner .= $this->tag->tag('dt', [], $this->tag->text($label))
                . $this->tag->tag('dd', [], $markup);
        }

        return $this->tag->tag('dl', ['class' => 'kv'], $inner);
    }

    /**
     * A table in its own horizontal scroller, so a long SQL shape never makes the page scroll.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows Cells are already-built markup.
     * @param list<int> $numericColumns Column indexes to right-align with tabular figures.
     * @return string
     */
    public function table(array $headers, array $rows, array $numericColumns = []): string
    {
        $numeric = array_flip($numericColumns);

        $head = '';

        foreach ($headers as $header) {
            $head .= $this->tag->tag('th', [], $this->tag->text($header));
        }

        $body = '';

        foreach ($rows as $row) {
            $cells = '';

            foreach (array_values($row) as $index => $cell) {
                $cells .= $this->tag->tag('td', ['class' => isset($numeric[$index]) ? 'n' : null], $cell);
            }

            $body .= $this->tag->tag('tr', [], $cells);
        }

        $table = $this->tag->tag(
            'table',
            [],
            $this->tag->tag('thead', [], $this->tag->tag('tr', [], $head))
            . $this->tag->tag('tbody', [], $body)
        );

        return $this->tag->tag('div', ['class' => 'scroll'], $table);
    }

    /**
     * An aside that qualifies what is shown — a truncation, an anomaly count, a weaker claim.
     *
     * @param string $text
     * @return string
     */
    public function note(string $text): string
    {
        return $this->tag->tag('p', ['class' => 'note'], $this->tag->text($text));
    }

    /**
     * Introductory prose for a panel.
     *
     * @param string $text
     * @return string
     */
    public function lede(string $text): string
    {
        return $this->tag->tag('p', ['class' => 'lede'], $this->tag->text($text));
    }
}

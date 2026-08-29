<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Html;

use Magento\Framework\Escaper;

/**
 * The only place in this module where markup is assembled.
 *
 * Every renderer builds through these four methods, so "was this escaped?" has exactly one answer
 * instead of one answer per panel. That matters more here than it looks: a stored run holds the
 * request URL, and **the request URL is attacker-controlled**. Anyone who can reach the storefront
 * can put a payload in a path, and the profiler will faithfully record it. Rendering that raw would
 * turn a developer tool into stored XSS against the developer — on an instance that, by definition,
 * has developer mode on.
 *
 * Values reach markup through text() for content, attr() for attribute values and url() for hrefs.
 * The $innerHtml argument of tag() is the one input trusted to be markup, and callers only ever
 * pass it the output of these same methods.
 */
class Tag
{
    /**
     * Elements that must not be given a closing tag.
     */
    private const VOID = ['meta' => true, 'link' => true, 'input' => true, 'br' => true, 'hr' => true];

    /**
     * Attributes holding a URL, which needs escapeUrl() and *only* escapeUrl().
     *
     * Magento's escapeUrl() applies escapeHtml() — not escapeHtmlAttr() — after sanitising the
     * URL. That is quoted-attribute-safe but NOT unquoted-attribute-safe, so a single
     * escapeUrl() pass is only sufficient because attributes() always emits name="value"
     * with the quotes. Do not emit an unquoted URL attribute on the strength of this.
     *
     * Attribute and element NAMES are concatenated raw and must always be trusted literals,
     * never derived from a run or a request. Only values are escaped. Escaping the
     * result a second time turns the "&" between query parameters into "&amp;amp;", which a browser
     * decodes to a literal "&amp;" — so "?slow=10&nplus1=3" arrives as a single parameter named
     * "slow" with the rest glued to its value, and every threshold control on the board silently
     * stops working. Handling it here rather than at each call site is why callers cannot get it
     * wrong.
     */
    private const URL_ATTRIBUTES = ['href' => true, 'src' => true, 'action' => true];

    /**
     * @param \Magento\Framework\Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * One element, with escaped attributes and already-built children.
     *
     * @param string $name
     * @param array<string,string|int|float|bool|null> $attributes Null and false values are dropped.
     * @param string|null $innerHtml Markup built by this class; never a raw run value.
     * @return string
     */
    public function tag(string $name, array $attributes = [], ?string $innerHtml = null): string
    {
        $markup = '<' . $name . $this->attributes($attributes);

        if (isset(self::VOID[$name])) {
            return $markup . '>';
        }

        return $markup . '>' . ($innerHtml ?? '') . '</' . $name . '>';
    }

    /**
     * A text node. Anything read from a run goes through here.
     *
     * @param mixed $value
     * @return string
     */
    public function text(mixed $value): string
    {
        return $this->escaper->escapeHtml($this->scalar($value));
    }

    /**
     * An attribute value.
     *
     * @param mixed $value
     * @return string
     */
    public function attr(mixed $value): string
    {
        return $this->escaper->escapeHtmlAttr($this->scalar($value));
    }

    /**
     * A URL for an href or src.
     *
     * @param string $value
     * @return string
     */
    public function url(string $value): string
    {
        return $this->escaper->escapeUrl($value);
    }

    /**
     * Build an attribute string.
     *
     * @param array<string,string|int|float|bool|null> $attributes
     * @return string
     */
    private function attributes(array $attributes): string
    {
        $markup = '';

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            // A valueless attribute — hidden, defer, async — is written bare rather than as ="1".
            if ($value === true) {
                $markup .= ' ' . $name;

                continue;
            }

            $escaped = isset(self::URL_ATTRIBUTES[$name])
                ? $this->escaper->escapeUrl((string)$value)
                : $this->escaper->escapeHtmlAttr((string)$value);

            $markup .= ' ' . $name . '="' . $escaped . '"';
        }

        return $markup;
    }

    /**
     * Reduce a recorded value to a string without letting an array or object reach the escaper.
     *
     * A run is decoded JSON, so a key that normally holds a scalar can legitimately hold a list on
     * a malformed or hand-edited file. Escaper would raise on it; the panel that was rendering it
     * would take the whole board down over one bad file.
     *
     * @param mixed $value
     * @return string
     */
    private function scalar(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? 'yes' : ($value === false ? 'no' : '');
        }

        return is_scalar($value) ? (string)$value : '—';
    }
}

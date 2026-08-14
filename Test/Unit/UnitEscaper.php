<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Psr\Log\LoggerInterface;

/**
 * A real Escaper for unit tests.
 *
 * The renderer tests assert that a payload comes out inert, which a mocked escaper cannot show —
 * it would only prove that a method was called. Escaper resolves three collaborators lazily through
 * the global ObjectManager, which does not exist in a unit test, so they are injected directly.
 */
trait UnitEscaper
{
    /**
     * @return \Magento\Framework\Escaper
     */
    private function unitEscaper(): Escaper
    {
        $escaper = new Escaper();

        $collaborators = [
            'escaper' => new ZendEscaper(),
            'logger' => $this->createStub(LoggerInterface::class),
            'translateInline' => $this->createStub(InlineInterface::class),
        ];

        foreach ($collaborators as $property => $value) {
            (new \ReflectionProperty(Escaper::class, $property))->setValue($escaper, $value);
        }

        return $escaper;
    }
}

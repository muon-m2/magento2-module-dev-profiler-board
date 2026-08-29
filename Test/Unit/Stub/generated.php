<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

/**
 * Minimal stand-ins for Magento's *generated* factory classes.
 *
 * `Magento\Framework\Controller\Result\RawFactory` has no source file — the framework ships `Raw.php`
 * and `RedirectFactory.php` but generates `RawFactory` into `generated/code` on demand. A full
 * install therefore has it and a bare `composer install`, which is exactly what CI does, does not.
 * A test that doubles it passes locally and errors in CI with "Class or interface does not exist".
 *
 * Declared only when the real class is absent, so these tests run everywhere rather than being
 * skipped in CI — which would put them back in the category this module just left: tests that exist
 * and never run.
 */

if (!class_exists(\Magento\Framework\Controller\Result\RawFactory::class, false)) {
    // phpcs:disable
    eval(
        'namespace Magento\Framework\Controller\Result;'
        . 'class RawFactory {'
        . '    public function create(array $data = []): Raw { throw new \LogicException("stub"); }'
        . '}'
    );
    // phpcs:enable
}

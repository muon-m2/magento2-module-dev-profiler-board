<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Model\Asset;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Psr\Log\LoggerInterface;

/**
 * Serves the board's two static files straight from the module directory.
 *
 * They live in `assets/` rather than `view/frontend/web/`, on purpose: nothing in Magento's static
 * pipeline scans that directory, so `setup:static-content:deploy` neither picks them up nor needs to
 * run for the board to work, and editing the stylesheet shows up on the next reload instead of after
 * a deploy. It also means the board resolves no assets through the theme fallback chain — which is
 * the same evidence it exists to display.
 *
 * **No caller supplies a path.** The two files are named by a constant map keyed by a logical name,
 * so there is no filename, no extension and no directory segment reaching this class from a request.
 * A traversal bug is not fixed here; it is unavailable.
 */
class AssetReader
{
    public const CSS = 'css';
    public const JS = 'js';

    /**
     * Logical name to the file it means, relative to the module root.
     */
    private const FILES = [
        self::CSS => 'assets/board.css',
        self::JS => 'assets/board.js',
    ];

    /**
     * @param \Magento\Framework\Component\ComponentRegistrar $registrar
     * @param \Magento\Framework\Filesystem\Driver\File $driver
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly ComponentRegistrar $registrar,
        private readonly File $driver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * One of the two board assets, or null when it cannot be read.
     *
     * @param string $name One of self::CSS or self::JS.
     * @return string|null
     */
    public function read(string $name): ?string
    {
        $relative = self::FILES[$name] ?? null;

        if ($relative === null) {
            return null;
        }

        $root = $this->registrar->getPath(ComponentRegistrar::MODULE, 'Muon_DevProfilerBoard');

        if ($root === null) {
            return null;
        }

        try {
            return $this->driver->fileGetContents($root . '/' . $relative);
        } catch (\Throwable $e) {
            // A missing stylesheet leaves an unstyled but readable board; taking the page down over
            // it would be worse than serving it plain.
            $this->logger->debug('Muon_DevProfilerBoard could not read ' . $relative . ': ' . $e->getMessage());

            return null;
        }
    }
}

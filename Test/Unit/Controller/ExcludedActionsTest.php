<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The board must not record its own requests.
 *
 * `etc/di.xml` contributes every board action to `RunFinalizer::excludedActions`, and the module's
 * own comment used to say the two lists "are diffed in review". A human diff is the weakest possible
 * guard for this invariant, because its failure is silent and destructive: a tenth controller added
 * without an entry gets recorded like any other request, and since an open board polls the feed
 * every four seconds it evicts the runs the reader is looking at — one per poll, no error, no log
 * line, just evidence quietly disappearing.
 *
 * So the diff happens here instead. Both sides are read from disk, so neither can be satisfied by
 * updating the test.
 */
class ExcludedActionsTest extends TestCase
{
    private const ROUTE = 'muon_profiler';

    /**
     * @return string
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Full action names derived from the controllers on disk.
     *
     * Magento builds `getFullActionName()` as route_controller_action, lower-cased by the router's
     * class resolution — so `Controller/Runs/Feed.php` is `muon_profiler_runs_feed`.
     *
     * @return list<string>
     */
    private function actionsOnDisk(): array
    {
        $root = $this->moduleRoot() . '/Controller';
        $names = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            [$controller, $action] = explode('/', $relative);

            $names[] = strtolower(self::ROUTE . '_' . $controller . '_' . $action);
        }

        sort($names);

        return $names;
    }

    /**
     * Full action names contributed to RunFinalizer by etc/di.xml.
     *
     * @return list<string>
     */
    private function actionsExcluded(): array
    {
        $xml = simplexml_load_file($this->moduleRoot() . '/etc/di.xml');

        self::assertNotFalse($xml, 'etc/di.xml did not parse');

        $names = [];

        foreach ($xml->xpath('//argument[@name="excludedActions"]/item') ?: [] as $item) {
            $names[] = strtolower(trim((string)$item));
        }

        sort($names);

        return $names;
    }

    public function testEveryBoardControllerIsExcludedFromRecording(): void
    {
        $missing = array_diff($this->actionsOnDisk(), $this->actionsExcluded());

        self::assertSame(
            [],
            array_values($missing),
            'these controllers would record their own runs and evict the ledger: '
            . implode(', ', $missing)
        );
    }

    public function testNothingIsExcludedThatIsNoLongerARoute(): void
    {
        $stale = array_diff($this->actionsExcluded(), $this->actionsOnDisk());

        self::assertSame(
            [],
            array_values($stale),
            'these excluded actions no longer exist and should be removed: ' . implode(', ', $stale)
        );
    }

    /**
     * The registry is a shared one. A frontend-scoped contribution is silently discarded across
     * scopes, because Config::extend() array_replace()s the whole `arguments` entry rather than
     * merging item by item — so a third module contributing from global di.xml would lose its
     * entries entirely.
     */
    public function testTheContributionIsGloballyScoped(): void
    {
        self::assertFileExists($this->moduleRoot() . '/etc/di.xml');

        $frontend = $this->moduleRoot() . '/etc/frontend/di.xml';

        if (is_file($frontend)) {
            self::assertStringNotContainsString(
                'excludedActions',
                (string)file_get_contents($frontend),
                'excludedActions must be contributed globally, not per-area'
            );
        }
    }

    public function testTheRouteIdMatchesTheOneRegisteredForTheModule(): void
    {
        $xml = simplexml_load_file($this->moduleRoot() . '/etc/frontend/routes.xml');

        self::assertNotFalse($xml);

        $ids = [];

        foreach ($xml->xpath('//route') ?: [] as $route) {
            $ids[] = (string)$route['id'];
        }

        self::assertSame([self::ROUTE], $ids, 'the excluded action names are built from this id');
    }
}

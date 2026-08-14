<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Asset;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Muon\DevProfilerBoard\Model\Asset\AssetReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see AssetReader
 */
class AssetReaderTest extends TestCase
{
    private const ROOT = '/var/www/magento/app/code/Muon/DevProfilerBoard';

    /** @var MockObject&File */
    private File $driver;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(File::class);
    }

    public function testTheStylesheetIsReadFromAFixedPathInsideTheModule(): void
    {
        $this->driver->expects(self::once())
            ->method('fileGetContents')
            ->with(self::ROOT . '/assets/board.css')
            ->willReturn(':root{}');

        self::assertSame(':root{}', $this->reader()->read(AssetReader::CSS));
    }

    public function testTheScriptIsReadFromAFixedPathInsideTheModule(): void
    {
        $this->driver->expects(self::once())
            ->method('fileGetContents')
            ->with(self::ROOT . '/assets/board.js')
            ->willReturn('(function(){}());');

        self::assertSame('(function(){}());', $this->reader()->read(AssetReader::JS));
    }

    /**
     * The traversal defence is that no caller supplies a path at all: names are keys into a
     * constant map, so an unknown one resolves to nothing and never reaches the filesystem.
     *
     * @param string $name
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unmappedNames')]
    public function testAnUnmappedNameNeverReachesTheFilesystem(string $name): void
    {
        $this->driver->expects(self::never())->method('fileGetContents');

        self::assertNull($this->reader()->read($name));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unmappedNames(): array
    {
        return [
            'traversal' => ['../../../app/etc/env.php'],
            'absolute path' => ['/etc/passwd'],
            'a real file, wrong key' => ['assets/board.css'],
            'empty' => [''],
        ];
    }

    /**
     * A missing stylesheet leaves an unstyled but readable board; taking the page down over it
     * would be worse than serving it plain.
     */
    public function testAnUnreadableFileIsLoggedAndReportedAsNothing(): void
    {
        $this->driver->expects(self::once())
            ->method('fileGetContents')
            ->willThrowException(new \RuntimeException('gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');

        self::assertNull($this->reader($logger)->read(AssetReader::CSS));
    }

    /**
     * @param (MockObject&LoggerInterface)|null $logger
     * @return \Muon\DevProfilerBoard\Model\Asset\AssetReader
     */
    private function reader(?LoggerInterface $logger = null): AssetReader
    {
        $registrar = $this->createStub(ComponentRegistrar::class);
        $registrar->method('getPath')->willReturn(self::ROOT);

        return new AssetReader($registrar, $this->driver, $logger ?? $this->createStub(LoggerInterface::class));
    }
}

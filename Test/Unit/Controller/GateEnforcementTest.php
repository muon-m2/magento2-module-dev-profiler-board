<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Controller;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Muon\DevProfilerBoard\Model\Response\BoardResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Generator\MethodNamedMethodException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The board's only access control is one `isOpen()` line, hand-copied into every controller.
 *
 * There is no base class and no interceptor enforcing it — which means the check is exactly as
 * reliable as the next person remembering to write it. This test is that enforcement: it discovers
 * the controllers from disk rather than listing them, so a tenth one added without the check fails
 * here instead of quietly serving profiler data on a storefront route.
 */
#[AllowMockObjectsWithoutExpectations]
class GateEnforcementTest extends TestCase
{
    /**
     * Every action class under Controller/, found by walking the directory.
     *
     * @return array<string, array{class-string<ActionInterface>}>
     */
    public static function controllers(): array
    {
        $root = dirname(__DIR__, 3) . '/Controller';

        self::assertDirectoryExists($root);

        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            /** @var class-string<ActionInterface> $class */
            $class = 'Muon\\DevProfilerBoard\\Controller\\' . str_replace('/', '\\', $relative);

            $found[str_replace('\\', '/', $relative)] = [$class];
        }

        ksort($found);

        self::assertNotSame([], $found, 'no controllers discovered — the walk is broken, not the module');

        return $found;
    }

    /**
     * A closed gate must produce the 404 and nothing else.
     *
     * `notFound()` is asserted by identity, so a controller that renders its panel and *then*
     * checks the gate cannot pass: the object returned would be the html() result, not this one.
     *
     * @param class-string<ActionInterface> $class
     * @return void
     */
    #[DataProvider('controllers')]
    public function testEveryControllerRefusesWhenTheGateIsClosed(string $class): void
    {
        $notFound = $this->createMock(Raw::class);

        /** @var BoardResponse&\PHPUnit\Framework\MockObject\MockObject $response */
        $response = $this->createMock(BoardResponse::class);
        $response->method('isOpen')->willReturn(false);
        $response->expects(self::once())->method('notFound')->willReturn($notFound);

        $controller = $this->build($class, $response);

        self::assertSame(
            $notFound,
            $controller->execute(),
            sprintf('%s served a response with the gate closed', $class)
        );
    }

    /**
     * The gate is consulted before any collaborator is touched.
     *
     * Reading the run store or the filesystem first and discarding the result afterwards would
     * still satisfy the test above; it would not satisfy this one.
     *
     * @param class-string<ActionInterface> $class
     * @return void
     */
    #[DataProvider('controllers')]
    public function testAClosedGateReachesNoCollaborator(string $class): void
    {
        $notFound = $this->createMock(Raw::class);

        /** @var BoardResponse&\PHPUnit\Framework\MockObject\MockObject $response */
        $response = $this->createMock(BoardResponse::class);
        $response->method('isOpen')->willReturn(false);
        $response->method('notFound')->willReturn($notFound);

        $collaborators = [];
        $controller = $this->build($class, $response, $collaborators);

        foreach ($collaborators as $name => $mock) {
            $mock->expects(self::never())->method(self::anything());
            unset($name);
        }

        $controller->execute();
    }

    /**
     * Build a controller with a mock for every constructor argument.
     *
     * Reflection rather than a hand-written list, for the same reason the provider walks the
     * directory: a controller that grows a dependency must not silently drop out of coverage.
     *
     * @param class-string<ActionInterface> $class
     * @param BoardResponse $response
     * @param array<string, \PHPUnit\Framework\MockObject\MockObject> $collaborators
     * @return ActionInterface
     */
    private function build(string $class, BoardResponse $response, array &$collaborators = []): ActionInterface
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            self::assertInstanceOf(
                ReflectionNamedType::class,
                $type,
                sprintf('%s::$%s is untyped, so it cannot be doubled', $class, $parameter->getName())
            );

            if ($type->getName() === BoardResponse::class) {
                $arguments[] = $response;
                continue;
            }

            /** @var class-string $dependency */
            $dependency = $type->getName();

            try {
                $mock = $this->createMock($dependency);
            } catch (MethodNamedMethodException) {
                // PHPUnit cannot double a class declaring a method named `method`, even a private
                // one (FilterReader does). An uninitialised instance stands in: the point of this
                // argument is only that the controller can be constructed, and a closed gate must
                // never call it anyway — which the assertions on the doublable collaborators and
                // on the returned object between them still prove.
                $arguments[] = (new ReflectionClass($dependency))->newInstanceWithoutConstructor();
                continue;
            }

            $collaborators[$parameter->getName()] = $mock;
            $arguments[] = $mock;
        }

        return new $class(...$arguments);
    }
}

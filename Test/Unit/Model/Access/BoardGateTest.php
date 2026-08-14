<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Access;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfilerBoard\Model\Access\BoardGate;
use PHPUnit\Framework\TestCase;

/**
 * The board's only access control, so it is worth asserting rather than assuming.
 *
 * @see BoardGate
 */
class BoardGateTest extends TestCase
{
    public function testTheBoardIsOpenInDeveloperModeOnTheStorefront(): void
    {
        self::assertTrue($this->boardGate(State::MODE_DEVELOPER, Area::AREA_FRONTEND)->isOpen());
    }

    public function testTheBoardIsClosedInProductionMode(): void
    {
        self::assertFalse($this->boardGate(State::MODE_PRODUCTION, Area::AREA_FRONTEND)->isOpen());
    }

    /**
     * Default mode runs on real production sites, which is exactly why the collector treats it as
     * production rather than as "not production".
     */
    public function testTheBoardIsClosedInDefaultMode(): void
    {
        self::assertFalse($this->boardGate(State::MODE_DEFAULT, Area::AREA_FRONTEND)->isOpen());
    }

    public function testTheBoardIsClosedOutsideTheStorefront(): void
    {
        self::assertFalse($this->boardGate(State::MODE_DEVELOPER, Area::AREA_ADMINHTML)->isOpen());
    }

    /**
     * Fails closed: an installation that cannot report its own mode is treated as production,
     * inherited from Gate rather than re-decided here.
     */
    public function testTheBoardIsClosedWhenTheModeCannotBeRead(): void
    {
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);
        $state->method('getMode')->willThrowException(new \RuntimeException('cannot read mode'));

        self::assertFalse((new BoardGate(new Gate($state)))->isOpen());
    }

    /**
     * @param string $mode
     * @param string $area
     * @return \Muon\DevProfilerBoard\Model\Access\BoardGate
     */
    private function boardGate(string $mode, string $area): BoardGate
    {
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn($mode);
        $state->method('getAreaCode')->willReturn($area);

        return new BoardGate(new Gate($state));
    }
}

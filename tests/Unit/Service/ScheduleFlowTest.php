<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Verschieben per Ziehen, Rückgängig und Essenszeiten. */
final class ScheduleFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	/** @return array<string, string> „Titel“ => Uhrzeit am Tag */
	private function day(string $day): array {
		$list = [];
		foreach ($this->planning->program($this->camp, 'anna') as $item) {
			if ($item->getDay() === $day) {
				$list[$item->getTitle()] = $item->getStartTime();
			}
		}
		foreach ($this->planning->meals($this->camp, 'anna') as $meal) {
			if ($meal->getDay() === $day) {
				$list[$meal->getMealType()] = $meal->getMealTime();
			}
		}
		return $list;
	}

	private function itemId(string $day, string $title): int {
		foreach ($this->planning->program($this->camp, 'anna') as $item) {
			if ($item->getDay() === $day && $item->getTitle() === $title) {
				return (int)$item->getId();
			}
		}
		$this->fail("$title am $day nicht gefunden");
	}

	public function testMoveAndUndo(): void {
		$day = '2027-07-26';
		$before = $this->day($day);
		$result = $this->planning->moveEntry($this->camp, 'anna', 'program', $this->itemId($day, 'Morgenrunde'), $day, '08:00');
		$after = $this->day($day);
		$this->assertSame(['08:00', '08:15'], [$after['Morgenrunde'], $after['breakfast']]);
		$this->assertCount(2, $result['changes']);

		$this->planning->restoreTimes($this->camp, 'anna', $result['changes']);
		$this->assertSame($before, $this->day($day));
	}

	public function testMealTimesForAllDays(): void {
		$result = $this->planning->setMealTimes($this->camp, 'anna', 'breakfast', '07:30');
		$this->assertGreaterThan(5, count($result['changes']));
		$this->assertSame('07:30', $this->day('2027-07-27')['breakfast']);
		$this->assertSame([], $this->planning->programConflicts($this->camp, 'anna'));
	}

	/** Die Küche verschiebt Mahlzeiten, aber keine Programmpunkte. */
	public function testKitchenMovesMealsOnly(): void {
		$this->team->add($this->camp, 'anna', 'group', 'kueche', ['kueche']);
		$day = '2027-07-27';
		$before = $this->day($day);
		$this->planning->setMealTimes($this->camp, 'dora', 'breakfast', '08:30');
		$after = $this->day($day);
		$this->assertSame('08:30', $after['breakfast']);
		$this->assertSame($before['Morgenrunde'], $after['Morgenrunde']);

		$this->expectException(ForbiddenException::class);
		$this->planning->moveEntry($this->camp, 'dora', 'program', $this->itemId($day, 'Morgenrunde'), $day, '07:00');
	}
}

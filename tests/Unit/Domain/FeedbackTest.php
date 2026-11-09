<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Planning\ScheduleEntry as E;
use OCA\Lagerplaner\Domain\Planning\ScheduleOverflow;
use OCA\Lagerplaner\Domain\Planning\ScheduleShifter;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

/** Rückmeldungen aus dem ersten Praxistest. */
final class FeedbackTest extends TestCase {
	private static function m(string $time): int {
		return Timeline::minutes($time);
	}

	/** Morgenrunde vor das Frühstück: Frühstück rutscht nach, das Übrige bleibt dank Lücke. */
	public function testMorningRoundBeforeBreakfast(): void {
		$day = [new E('meal', 1, 'd', self::m('08:00'), 30, [], 'breakfast'), new E('program', 2, 'd', self::m('08:45'), 15, [], 'Morgenrunde'),
			new E('program', 3, 'd', self::m('09:30'), 90, [], 'Vormittag'), new E('meal', 4, 'd', self::m('12:30'), 30, [], 'lunch')];
		$this->assertSame(['program:2' => self::m('08:00'), 'meal:1' => self::m('08:15')], (new ScheduleShifter())->move($day, $day[1], self::m('08:00')));
	}

	/** Ohne Lücke rutscht alles Folgende nach; parallele Punkte anderer Gruppen bleiben. */
	public function testChainAndParallelGroups(): void {
		$day = [new E('program', 1, 'd', self::m('09:00'), 60, [], 'A'), new E('program', 2, 'd', self::m('10:00'), 60, ['rover'], 'Rover'),
			new E('program', 3, 'd', self::m('10:00'), 60, ['woelflinge'], 'Wölflinge'), new E('program', 4, 'd', self::m('11:00'), 60, [], 'B'),
			new E('program', 5, 'd', self::m('10:00'), 60, ['woelflinge'], 'Regen', 3), new E('meal', 6, 'd', self::m('10:15'), 30, [], 'lunch', null, true)];
		$moved = new E('program', 9, 'd2', self::m('14:00'), 30, ['rover'], 'Neu');
		$result = (new ScheduleShifter())->move($day, $moved, self::m('10:00'));
		$this->assertSame(['program:9' => self::m('10:00'), 'program:2' => self::m('10:30'), 'program:4' => self::m('11:30')], $result);

		// Wandert der Wölflinge-Punkt, wandert seine Schlechtwetteralternative mit.
		$result = (new ScheduleShifter())->move($day, $day[2], self::m('10:30'));
		$this->assertSame(self::m('10:30'), $result['program:5']);
	}

	public function testOverflowPastMidnightIsRefused(): void {
		$day = [new E('program', 1, 'd', self::m('22:00'), 90, [], 'Nachtwanderung')];
		$this->expectException(ScheduleOverflow::class);
		(new ScheduleShifter())->move($day, new E('program', 2, 'd', 0, 60, [], 'Lagerfeuer'), self::m('22:00'));
	}

	private function draft(array $overrides): \OCA\Lagerplaner\Domain\Wizard\Draft {
		return (new DraftGenerator())->generate(WizardInput::fromArray(array_replace_recursive(Fixtures::summerCamp(), $overrides)), new \DateTimeImmutable('2027-03-01'));
	}

	/** Tagesveranstaltung mit Beginn und Ende */
	public function testSingleDayEventWithTimes(): void {
		$draft = $this->draft(['start' => '2027-06-12', 'end' => '2027-06-12', 'arrivalTime' => '14:00', 'departureTime' => '18:00', 'catering' => ['meals' => ['lunch', 'snack', 'dinner']]]);
		$items = array_map(static fn ($i): string => $i->startTime . ' ' . $i->title, $draft->programItems);
		sort($items);
		$this->assertSame('14:00 Ankommen', $items[0]);
		$this->assertContains('17:15 Abschlussrunde', $items);
		$this->assertContains('18:00 Heimreise', $items);
		$this->assertSame([], array_map(static fn ($m): string => $m->mealType, array_filter($draft->meals, static fn ($m): bool => $m->mealType === 'lunch')));

		$times = [];
		for ($from = self::m('06:00'); $from <= self::m('18:00'); $from += 60) {
			for ($until = $from + 180; $until <= self::m('22:00'); $until += 60) {
				$times[] = [Timeline::time($from), Timeline::time($until)];
			}
		}
		foreach ($times as [$from, $until]) {
			$draft = $this->draft(['start' => '2027-06-12', 'end' => '2027-06-12', 'arrivalTime' => $from, 'departureTime' => $until, 'catering' => ['meals' => ['breakfast', 'lunch', 'snack', 'dinner']]]);
			$this->assertSame([], (new Timeline())->conflicts($draft->programItems, $draft->meals, []), "{$from}–{$until}");
		}
	}

	public function testSingleDayDefaultStaysUnchangedAndTooShortIsRefused(): void {
		$draft = $this->draft(['start' => '2027-06-12', 'end' => '2027-06-12']);
		$this->assertSame('09:30', $draft->programItems[0]->startTime);
		try {
			WizardInput::fromArray(array_replace(Fixtures::summerCamp(), ['start' => '2027-06-12', 'end' => '2027-06-12', 'arrivalTime' => '14:00', 'departureTime' => '16:00']));
			$this->fail('Zu kurze Tagesveranstaltung nicht abgelehnt');
		} catch (\OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput $e) {
			$this->assertArrayHasKey('departureTime', $e->errors);
		}
	}

	/** Gruppenhaus ohne Schlechtwetteralternativen; Hinweis weg, Aufgabe nur beim Zeltlager. */
	public function testWeatherOnlyAsTaskAndNotForHouses(): void {
		$tent = $this->draft([]);
		$house = $this->draft(['campType' => 'house']);
		$this->assertSame([], array_filter($tent->hints, static fn (string $h): bool => str_contains($h, 'Schlechtwetter')));
		$this->assertContains('Schlechtwetteralternativen festlegen', array_map(static fn ($t): string => $t->title, $tent->tasks));
		$this->assertNotSame([], array_filter($tent->programItems, static fn ($i): bool => $i->weatherAlternative !== ''));

		$this->assertSame([], array_filter($house->programItems, static fn ($i): bool => $i->weatherAlternative !== '' || $i->weatherAlternativeNeeded));
		$this->assertNotContains('Schlechtwetteralternativen festlegen', array_map(static fn ($t): string => $t->title, $house->tasks));
	}

	/** „Angaben beim Betreiber bestätigen“ gehört zum verbindlichen Buchen. */
	public function testBookingTaskIncludesConfirmation(): void {
		$titles = array_map(static fn ($t): string => $t->title, $this->draft([])->tasks);
		$this->assertNotContains('Angaben des Lagerplatzes beim Betreiber bestätigen lassen', $titles);
		$booking = array_values(array_filter($this->draft([])->tasks, static fn ($t): bool => $t->title === 'Lagerplatz anfragen und verbindlich buchen'))[0];
		$this->assertStringContainsString('vom Betreiber bestätigen', $booking->description);
	}
}

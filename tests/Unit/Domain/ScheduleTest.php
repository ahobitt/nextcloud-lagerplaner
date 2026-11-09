<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Planning\ScheduleConflicts;
use OCA\Lagerplaner\Domain\Planning\ScheduleEntry;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\GroupInput;
use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

/** Zeitkonflikte in Assistent und Programmplanung (Review Allgemein 0.6.1). */
final class ScheduleTest extends TestCase {
	/** Das Beispiel aus dem Review: 360 Minuten Wölflingsblock. */
	public function testOverlongBlocksAreRejected(): void {
		$data = Fixtures::summerCamp();
		$data['groups'][0]['blockMinutes'] = 360;
		try {
			WizardInput::fromArray($data);
			$this->fail('360 Minuten nicht abgelehnt');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('groups.0.blockMinutes', $e->errors);
		}
	}

	public function testSingleDayCampHasShorterMorning(): void {
		$data = Fixtures::summerCamp(['start' => '2027-07-24', 'end' => '2027-07-24']);
		$data['groups'][0]['blockMinutes'] = 180;
		try {
			WizardInput::fromArray($data);
			$this->fail('180 Minuten am eintägigen Lager nicht abgelehnt');
		} catch (InvalidWizardInput $e) {
			$this->assertStringContainsString('eintägigen', $e->errors['groups.0.blockMinutes']);
		}
	}

	/** Mit den längsten erlaubten Blöcken entsteht in keiner Kombination eine Überschneidung. */
	public function testLongestAllowedBlocksNeverOverlap(): void {
		foreach (['little', 'medium', 'much'] as $freeTime) {
			foreach ([['2027-07-24', '2027-08-04', WizardInput::MAX_BLOCK_MINUTES], ['2027-07-24', '2027-07-24', WizardInput::MAX_BLOCK_MINUTES_SINGLE_DAY]] as [$start, $end, $minutes]) {
				foreach ([true, false] as $separate) {
					$data = Fixtures::summerCamp(['start' => $start, 'end' => $end]);
					$data['program']['freeTime'] = $freeTime;
					foreach ($data['groups'] as $i => $group) {
						$data['groups'][$i]['blockMinutes'] = $minutes;
						$data['groups'][$i]['separateProgram'] = $separate;
					}
					$draft = (new DraftGenerator())->generate(WizardInput::fromArray($data), new \DateTimeImmutable('2027-03-01'));
					$keys = array_map(static fn (GroupInput $g): string => $g->key, array_filter($draft->input->participantGroups(), static fn (GroupInput $g): bool => $g->separateProgram));
					$this->assertSame([], (new Timeline())->conflicts($draft->programItems, $draft->meals, array_values($keys)), "$freeTime $start $minutes");
					$this->assertSame([], array_filter($draft->hints, static fn (string $h): bool => str_starts_with($h, 'Zeitliche')));
				}
			}
		}
	}

	private function entry(string $kind, int $id, string $start, int $minutes, array $groups = [], ?int $alternativeTo = null, bool $portable = false): ScheduleEntry {
		return new ScheduleEntry($kind, $id, '2027-07-26', Timeline::minutes($start), $minutes, $groups, "Eintrag $id", $alternativeTo, $portable);
	}

	public function testOverlapsWithinAGroupAndWithMealsAreFound(): void {
		$conflicts = (new ScheduleConflicts())->forProgramItems([
			$this->entry('program', 1, '09:30', 360, ['woelflinge']),
			$this->entry('meal', 2, '12:30', 30),
			$this->entry('program', 3, '14:30', 120, ['woelflinge']),
		]);

		$this->assertSame([2, 3], array_column($conflicts[1], 'id'));
		$this->assertSame('12:30', $conflicts[1][0]['start']);
		$this->assertSame([1], array_column($conflicts[3], 'id'));
	}

	public function testParallelGroupsAlternativesAndPortableMealsAreNoConflicts(): void {
		$conflicts = (new ScheduleConflicts())->forProgramItems([
			$this->entry('program', 1, '09:30', 150, ['woelflinge']),
			$this->entry('program', 2, '09:30', 150, ['pfadfinder']),
			$this->entry('program', 3, '09:30', 150, ['pfadfinder'], 2),
			$this->entry('program', 4, '09:30', 420),
			$this->entry('meal', 5, '12:30', 30, [], null, true),
		]);

		// Nur die ganztägige Wanderung für alle trifft auf die Gruppenprogramme.
		$this->assertSame([4], array_column($conflicts[1], 'id'));
		$this->assertSame([4], array_column($conflicts[2], 'id'));
		$this->assertSame([1, 2, 3], array_column($conflicts[4], 'id'));
	}

	private function weekend(?string $arrival, ?string $departure, string $campType = 'tent', array $meals = ['breakfast', 'lunch', 'dinner']): \OCA\Lagerplaner\Domain\Wizard\Draft {
		$data = Fixtures::summerCamp(['start' => '2027-05-07', 'end' => '2027-05-09', 'campType' => $campType]);
		$data['arrivalTime'] = $arrival;
		$data['departureTime'] = $departure;
		$data['catering']['meals'] = $meals;
		$data['program']['hike'] = false;
		$data['program']['wideGame'] = false;
		return (new DraftGenerator())->generate(WizardInput::fromArray($data), new \DateTimeImmutable('2027-03-01'));
	}

	/** @return list<string> „Uhrzeit Art“ der Mahlzeiten eines Tages */
	private function mealsOn(\OCA\Lagerplaner\Domain\Wizard\Draft $draft, string $day): array {
		$list = array_map(static fn ($m): string => $m->time . ' ' . $m->mealType, array_filter($draft->meals, static fn ($m): bool => $m->day === $day));
		sort($list);
		return array_values($list);
	}

	/** @return list<string> „Uhrzeit Titel“ der Programmpunkte eines Tages */
	private function itemsOn(\OCA\Lagerplaner\Domain\Wizard\Draft $draft, string $day): array {
		$list = array_map(static fn ($i): string => $i->startTime . ' ' . $i->title, array_filter($draft->programItems, static fn ($i): bool => $i->day === $day));
		sort($list);
		return array_values($list);
	}

	/** Wochenendlager: Ankunft Freitag 17 Uhr, Abreise Sonntag 11 Uhr. */
	public function testFridayEveningArrival(): void {
		$draft = $this->weekend('17:00', '11:00');

		$this->assertSame(['18:30 dinner'], $this->mealsOn($draft, '2027-05-07'));
		$this->assertSame(['17:00 Anreise und Zeltaufbau', '19:30 Lagereröffnung und Kennenlernen: Einstieg ins Thema „Wikinger“', '21:00 Abendrunde'], $this->itemsOn($draft, '2027-05-07'));
		$this->assertSame(['08:00 breakfast'], $this->mealsOn($draft, '2027-05-09'));
		$this->assertSame(['08:45 Zeltabbau und Platzabnahme', '10:15 Abschlussrunde', '11:00 Heimreise'], $this->itemsOn($draft, '2027-05-09'));
		$this->assertNotEmpty(array_filter($draft->hints, static fn (string $h): bool => str_contains($h, 'nur 90 Minuten')));
	}

	public function testLateArrivalWithoutDinnerAndLateDeparture(): void {
		$draft = $this->weekend('19:30', '17:00', 'house');

		$this->assertSame([], $this->mealsOn($draft, '2027-05-07'));
		$this->assertNotEmpty(array_filter($draft->hints, static fn (string $h): bool => str_contains($h, 'kein Abendessen')));
		$this->assertContains('14:00 Abschlussprogramm', $this->itemsOn($draft, '2027-05-09'));
		$this->assertSame(['08:00 breakfast', '12:30 lunch'], $this->mealsOn($draft, '2027-05-09'));
	}

	/** Keine Uhrzeit: genau der bisherige Ablauf. */
	public function testDefaultTimesKeepTheFormerSchedule(): void {
		$draft = $this->weekend(null, null);
		$this->assertSame(['12:30 lunch', '18:30 dinner'], $this->mealsOn($draft, '2027-05-07'));
		$this->assertSame(['10:00 Anreise und Zeltaufbau', '14:00 Kennenlernen und Lagerregeln', '19:30 Lagereröffnung: Einstieg ins Thema „Wikinger“', '21:00 Abendrunde'], $this->itemsOn($draft, '2027-05-07'));
		$this->assertSame(['08:45 Morgenrunde', '09:15 Zeltabbau und Platzabnahme', '13:15 Abschlussrunde', '14:00 Heimreise'], $this->itemsOn($draft, '2027-05-09'));
	}

	/** Jede erlaubte Ankunfts- und Abreisezeit ergibt einen Ablauf ohne Überschneidungen. */
	public function testNoOverlapsForAnyArrivalOrDeparture(): void {
		$times = static function (string $from, string $to): array {
			$list = [];
			for ($m = Timeline::minutes($from); $m <= Timeline::minutes($to); $m += 30) {
				$list[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
			}
			return $list;
		};
		$checked = 0;
		foreach (['tent', 'house'] as $type) {
			foreach ([['breakfast', 'lunch', 'dinner'], ['breakfast', 'lunch', 'snack', 'dinner']] as $meals) {
				foreach ($times(WizardInput::ARRIVAL_EARLIEST, WizardInput::ARRIVAL_LATEST) as $arrival) {
					foreach ($times(WizardInput::DEPARTURE_EARLIEST, WizardInput::DEPARTURE_LATEST) as $departure) {
						$draft = $this->weekend($arrival, $departure, $type, $meals);
						$this->assertSame([], (new Timeline())->conflicts($draft->programItems, $draft->meals, []), "$type $arrival $departure");
						foreach ($draft->programItems as $item) {
							$this->assertTrue($item->durationMinutes >= 15, "$type $arrival $departure {$item->title}");
						}
						$checked++;
					}
				}
			}
		}
		$this->assertSame(4 * 29 * 16, $checked);
	}

	public function testInvalidTimesAreRejected(): void {
		foreach ([['arrivalTime' => '05:00'], ['departureTime' => '09:00'], ['arrivalTime' => '25:00'], ['start' => '2027-05-07', 'end' => '2027-05-07', 'arrivalTime' => '17:00']] as $overrides) {
			try {
				WizardInput::fromArray(array_replace(Fixtures::summerCamp(), $overrides));
				$this->fail('Nicht abgelehnt: ' . json_encode($overrides));
			} catch (InvalidWizardInput $e) {
				$this->assertNotEmpty(array_intersect(array_keys($e->errors), ['arrivalTime', 'departureTime']));
			}
		}
	}
}

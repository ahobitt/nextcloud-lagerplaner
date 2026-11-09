<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use DateTimeImmutable;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\ProposedProgramItem;
use OCA\Lagerplaner\Domain\Wizard\ProposedTask;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

final class DraftGeneratorTest extends TestCase {
	private DateTimeImmutable $today;

	protected function setUp(): void {
		$this->today = new DateTimeImmutable('2026-10-11', new \DateTimeZone('UTC'));
	}

	private function draft(array $overrides = []) {
		return (new DraftGenerator())->generate(WizardInput::fromArray(Fixtures::summerCamp($overrides)), $this->today);
	}

	public function testTwelveDayCampHasArrivalMiddleAndDepartureStructure(): void {
		$draft = $this->draft();

		$this->assertCount(12, $draft->days);
		$titles = array_map(static fn (ProposedProgramItem $p): string => $p->day . ' ' . $p->title, $draft->programItems);
		$this->assertContains('2027-07-24 Anreise und Zeltaufbau', $titles);
		$this->assertContains('2027-07-24 Lagereröffnung: Einstieg ins Thema „Wikinger“', $titles);
		$this->assertContains('2027-08-04 Zeltabbau und Platzabnahme', $titles);
		$this->assertContains('2027-08-04 Heimreise', $titles);
	}

	public function testRuleKeysAreUniqueAndEverythingStartsAsProposal(): void {
		$draft = $this->draft();
		$keys = array_map(static fn ($e): string => $e->ruleKey(), [...$draft->programItems, ...$draft->meals, ...$draft->tasks]);

		$this->assertSame(count($keys), count(array_unique($keys)));
		$this->assertStringContainsString('core-de/2026.1#', $draft->programItems[0]->templateRef());
	}

	public function testHikeAndWideGameLandOnDifferentMiddleDays(): void {
		$draft = $this->draft();
		$hike = array_values(array_filter($draft->programItems, static fn ($p): bool => $p->category === 'hike'));
		$game = array_values(array_filter($draft->programItems, static fn ($p): bool => $p->category === 'wide_game'));

		$this->assertCount(1, $hike);
		$this->assertCount(1, $game);
		$this->assertSame('2027-07-28', $hike[0]->day);
		$this->assertSame('2027-08-01', $game[0]->day);
		$this->assertNotSame($hike[0]->day, $game[0]->day);
	}

	public function testMealsSkipBreakfastOnArrivalAndDinnerOnDeparture(): void {
		$draft = $this->draft();
		$keys = array_map(static fn ($m): string => $m->ruleKey(), $draft->meals);

		$this->assertCount(2 + 10 * 3 + 2, $draft->meals);
		$this->assertNotContains('2027-07-24|breakfast', $keys);
		$this->assertNotContains('2027-08-04|dinner', $keys);
		$packed = array_values(array_filter($draft->meals, static fn ($m): bool => $m->note === 'Lunchpaket für unterwegs'));
		$this->assertCount(1, $packed);
	}

	public function testSeparateProgramGroupsGetOwnAgeAppropriateBlocks(): void {
		$draft = $this->draft(['groups' => [0 => ['separateProgram' => true]]]);
		$morning = array_values(array_filter(
			$draft->programItems,
			static fn ($p): bool => $p->day === '2027-07-25' && $p->slot === 'morning',
		));

		$this->assertCount(2, $morning);
		$this->assertSame(['woelflinge'], $morning[0]->groups);
		$this->assertSame(90, $morning[0]->durationMinutes);
		$this->assertSame(['jungpfadfinder', 'pfadfinder', 'rover'], $morning[1]->groups);
		$this->assertSame(120, $morning[1]->durationMinutes);
		$this->assertSame('Vormittagsprogramm (gemeinsam)', $morning[1]->title);
	}

	public function testUnknownLocationCreatesSearchTaskAndHint(): void {
		$draft = $this->draft();
		$taskKeys = array_map(static fn (ProposedTask $t): string => $t->key, $draft->tasks);

		$this->assertContains('lagerplatz_suchen', $taskKeys);
		$this->assertContains('kuechenteam', $taskKeys);
		$this->assertNotContains('materialverantwortung', $taskKeys);
		$this->assertContains('Der Lagerort steht noch nicht fest. Die Aufgabe „Lagerplatz suchen“ ist angelegt.', $draft->hints);
	}

	public function testDueDatesAreNeverInThePast(): void {
		$draft = (new DraftGenerator())->generate(
			WizardInput::fromArray(Fixtures::summerCamp(['start' => '2026-11-01', 'end' => '2026-11-03'])),
			$this->today,
		);
		foreach ($draft->tasks as $task) {
			$this->assertGreaterThanOrEqual('2026-10-11', $task->dueDate);
		}
		$this->assertSame('2026-10-25', $this->taskDue($draft->tasks, 'einkauf'));
	}

	public function testSingleDayEventWorksAndExplainsMissingHike(): void {
		$draft = $this->draft(['start' => '2027-03-13', 'end' => '2027-03-13']);

		$this->assertCount(1, $draft->days);
		$this->assertContains('Für eine Tageswanderung ist das Lager zu kurz. Plane sie bei Bedarf manuell ein.', $draft->hints);
		$this->assertContains('Für das Geländespiel ist kein freier Lagertag übrig. Plane es bei Bedarf manuell ein.', $draft->hints);
	}

	public function testNoParticipantsStillProducesCommonBlocks(): void {
		$draft = (new DraftGenerator())->generate(
			WizardInput::fromArray(['name' => 'Leiterwochenende', 'start' => '2027-01-15', 'end' => '2027-01-17', 'campType' => 'house']),
			$this->today,
		);
		$morning = array_values(array_filter($draft->programItems, static fn ($p): bool => $p->slot === 'morning'));

		$this->assertCount(1, $morning);
		$this->assertSame([], $morning[0]->groups);
		$this->assertSame(120, $morning[0]->durationMinutes);
		$this->assertContains('Es sind noch keine Personenzahlen eingetragen. Mengen für Küche und Material fehlen deshalb.', $draft->hints);
	}

	/** Regression Review 0.1.0: Programmpunkte und Mahlzeiten überschneiden sich nicht. */
	public function testNoOverlapsInAnyConfiguration(): void {
		$timeline = new Timeline();
		$configs = [
			[],
			['catering' => ['meals' => ['breakfast', 'lunch', 'snack', 'dinner']]],
			['catering' => ['meals' => ['breakfast', 'lunch', 'snack', 'dinner']], 'program' => ['freeTime' => 'much'], 'groups' => [0 => ['separateProgram' => true], 3 => ['separateProgram' => true]]],
			['catering' => ['meals' => ['lunch', 'snack']], 'program' => ['freeTime' => 'little']],
			['start' => '2027-03-13', 'end' => '2027-03-13', 'catering' => ['meals' => ['lunch', 'snack']]],
			['start' => '2027-03-13', 'end' => '2027-03-14', 'campType' => 'house', 'catering' => ['meals' => ['breakfast', 'lunch', 'snack', 'dinner']]],
		];
		foreach ($configs as $i => $overrides) {
			$draft = $this->draft($overrides);
			$keys = array_map(static fn ($g): string => $g->key, $draft->input->participantGroups());
			$this->assertSame([], $timeline->conflicts($draft->programItems, $draft->meals, $keys), "Konfiguration $i");
		}
	}

	public function testTimelineDetectsOverlap(): void {
		$draft = $this->draft();
		$item = $draft->programItems[0];
		$clash = new ProposedProgramItem('x', $item->day, $item->startTime, 30, 'Doppelt', '', [], 'program', false, 'test');

		$this->assertNotSame([], (new Timeline())->conflicts([$item, $clash], [], []));
	}

	public function testSingleDayClosesAfterTheLongestBlock(): void {
		$draft = $this->draft([
			'start' => '2027-03-13', 'end' => '2027-03-13',
			'catering' => ['meals' => ['lunch', 'snack']],
			'groups' => [2 => ['separateProgram' => true]],
		]);
		$closing = array_values(array_filter($draft->programItems, static fn ($p): bool => $p->slot === 'closing'))[0];

		// Snack 14:00, Pfadfinder-Block 14:15 + 150 Minuten = 16:45
		$this->assertSame('16:45', $closing->startTime);
	}

	public function testGenerationIsDeterministic(): void {
		$this->assertSame($this->draft()->toArray(), $this->draft()->toArray());
	}

	/** @param list<ProposedTask> $tasks */
	private function taskDue(array $tasks, string $key): ?string {
		foreach ($tasks as $task) {
			if ($task->key === $key) {
				return $task->dueDate;
			}
		}
		return null;
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Wizard\InvalidWizardInput;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

/** Befunde aus dem Review der allgemeinen Lagerplanung 0.6.1. */
final class PlanningRulesTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	public function testGeneratedPlanHasNoConflicts(): void {
		$this->assertSame([], $this->planning->programConflicts($this->camp, 'anna'));
	}

	public function testManualItemOverlappingLunchIsReported(): void {
		$item = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Bastelstunde', 'day' => '2027-07-26', 'startTime' => '12:00', 'durationMinutes' => 60]);
		$conflicts = $this->planning->programConflicts($this->camp, 'anna');

		$this->assertSame(['lunch'], array_column($conflicts[(int)$item->getId()], 'title'));
		$this->assertSame(['12:30', '13:00'], [$conflicts[(int)$item->getId()][0]['start'], $conflicts[(int)$item->getId()][0]['end']]);
	}

	/** Ohne Entscheidung zu Programmpunkten einer entfernten Altersstufe wird nichts übernommen. */
	public function testRemovedAgeGroupNeedsDecisions(): void {
		$rover = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover']]);
		$both = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Hajk-Vorbereitung', 'day' => '2027-07-27', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover', 'pfadfinder']]);
		$input = Fixtures::summerCamp();
		$input['groups'] = array_values(array_filter($input['groups'], static fn (array $g): bool => $g['key'] !== 'rover'));

		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);
		$entry = array_values(array_filter($preview['camp']['changes'], static fn (array $c): bool => $c['kind'] === 'group_items'))[0];
		$this->assertSame([(int)$rover->getId(), (int)$both->getId()], array_column($entry['items'], 'id'));
		$this->assertNotContains('rover', array_column($entry['options'], 'key'));

		try {
			$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token'], [(string)$rover->getId() => 'pfadfinder']);
			$this->fail('Fehlende Entscheidung nicht abgelehnt');
		} catch (InvalidWizardInput $e) {
			$this->assertArrayHasKey('groupDecisions.' . $both->getId(), $e->errors);
		}
		// Nichts wurde übernommen: Die Rover sind noch da.
		$this->assertContains('rover', array_map(static fn ($g): string => $g->getGroupKey(), $this->camps->groups($this->camp)));

		$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token'], [(string)$rover->getId() => 'remove', (string)$both->getId() => 'all']);
		$program = array_column(array_map(static fn ($i): array => $i->jsonSerialize(), $this->planning->program($this->camp, 'anna')), null, 'id');
		$this->assertArrayNotHasKey((int)$rover->getId(), $program);
		$this->assertSame([], $program[(int)$both->getId()]['groups']);
	}

	public function testItemCanBeMovedToAnotherGroup(): void {
		$item = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover', 'pfadfinder']]);
		$input = Fixtures::summerCamp();
		$input['groups'] = array_values(array_filter($input['groups'], static fn (array $g): bool => $g['key'] !== 'rover'));
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);

		$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token'], [(string)$item->getId() => 'jungpfadfinder']);
		$program = array_column(array_map(static fn ($i): array => $i->jsonSerialize(), $this->planning->program($this->camp, 'anna')), null, 'id');
		$this->assertSame(['pfadfinder', 'jungpfadfinder'], $program[(int)$item->getId()]['groups']);
	}

	/** Im Assistenten wird eine Altersstufe auf 0 Personen gesetzt; das zählt als entfernt. */
	public function testAgeGroupSetToZeroCountsAsRemoved(): void {
		$item = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover']]);
		$input = Fixtures::summerCamp();
		foreach ($input['groups'] as $i => $group) {
			if ($group['key'] === 'rover') {
				$input['groups'][$i]['count'] = 0;
			}
		}
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);
		$entry = array_values(array_filter($preview['camp']['changes'], static fn (array $c): bool => $c['kind'] === 'group_items'))[0];

		$this->assertSame([(int)$item->getId()], array_column($entry['items'], 'id'));
	}

	public function testInvalidDecisionIsRejected(): void {
		$item = $this->planning->createProgramItem($this->camp, 'anna', ['title' => 'Roverrunde', 'day' => '2027-07-26', 'startTime' => '21:30', 'durationMinutes' => 30, 'groups' => ['rover']]);
		$input = Fixtures::summerCamp();
		$input['groups'] = array_values(array_filter($input['groups'], static fn (array $g): bool => $g['key'] !== 'rover'));
		$preview = $this->wizard->rerunPreview($this->camp, 'anna', $input);

		$this->expectException(InvalidWizardInput::class);
		$this->wizard->rerunApply($this->camp, 'anna', $input, $preview['token'], [(string)$item->getId() => 'rover']);
	}

	/** Festlegung: Wer Vorschläge löschen darf (Programmteam), darf sie auch wiederherstellen. */
	public function testProgrammeTeamMayRestoreProposals(): void {
		$this->team->add($this->camp, 'anna', 'user', 'carla', ['programm']);
		$item = $this->planning->program($this->camp, 'carla')[0];
		$this->planning->deleteProgramItem((int)$item->getId(), 'carla');

		$this->assertSame((int)$item->getId(), (int)$this->planning->restoreProgramItem((int)$item->getId(), 'carla')->getId());
	}
}

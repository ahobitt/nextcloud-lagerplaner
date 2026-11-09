<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use DateTimeImmutable;
use OCA\Lagerplaner\Domain\Provenance\Origin;
use OCA\Lagerplaner\Domain\Provenance\Provenance;
use OCA\Lagerplaner\Domain\Provenance\ReviewState;
use OCA\Lagerplaner\Domain\Rerun\ChangeType;
use OCA\Lagerplaner\Domain\Rerun\ExistingElement;
use OCA\Lagerplaner\Domain\Rerun\RerunPlanner;
use OCA\Lagerplaner\Domain\Wizard\Draft;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Domain\Wizard\ProposedElement;
use OCA\Lagerplaner\Domain\Wizard\WizardInput;
use PHPUnit\Framework\TestCase;

final class RerunPlannerTest extends TestCase {
	private function draft(array $overrides = []): Draft {
		return (new DraftGenerator())->generate(
			WizardInput::fromArray(Fixtures::summerCamp($overrides)),
			new DateTimeImmutable('2026-10-11', new \DateTimeZone('UTC')),
		);
	}

	/**
	 * Simuliert gespeicherte Elemente aus einem Entwurf.
	 *
	 * @param list<ProposedElement> $proposed
	 * @param array<string, Provenance> $overrides Regelkennung => abweichende Herkunft
	 * @return list<ExistingElement>
	 */
	private function stored(array $proposed, array $overrides = [], array $fingerprintOverrides = []): array {
		$existing = [];
		foreach ($proposed as $i => $element) {
			$key = $element->ruleKey();
			$existing[] = new ExistingElement(
				$i + 1,
				$key,
				$fingerprintOverrides[$key] ?? $element->fingerprint(),
				$overrides[$key] ?? new Provenance(Origin::Assistant, ReviewState::Proposal),
				$key,
			);
		}
		return $existing;
	}

	public function testSameInputProducesNoChanges(): void {
		$draft = $this->draft();
		$plan = (new RerunPlanner())->plan($this->stored($draft->programItems), $draft->programItems, true);

		$this->assertTrue($plan->isEmpty());
		$this->assertSame(count($draft->programItems), $plan->unchanged);
	}

	public function testOneDayLongerReplacesOnlyUntouchedProposals(): void {
		$before = $this->draft();
		$after = $this->draft(['end' => '2027-08-05']);
		$plan = (new RerunPlanner())->plan($this->stored($before->programItems), $after->programItems, true);

		$removed = array_map(static fn ($c): string => $c->existing->ruleKey, $plan->ofType(ChangeType::Remove));
		$added = array_map(static fn ($c): string => $c->proposed->ruleKey(), $plan->ofType(ChangeType::Add));

		$this->assertContains('2027-08-04|teardown|all', $removed);
		$this->assertContains('2027-08-04|morning|all', $added);
		$this->assertContains('2027-08-05|teardown|all', $added);
		$this->assertSame([], $plan->ofType(ChangeType::Conflict));
		$this->assertSame([], $plan->ofType(ChangeType::Orphaned));
	}

	public function testConfirmedItemIsNeverRemoved(): void {
		$before = $this->draft();
		$after = $this->draft(['end' => '2027-08-05']);
		$confirmed = new Provenance(Origin::Assistant, ReviewState::Confirmed);
		$plan = (new RerunPlanner())->plan(
			$this->stored($before->programItems, ['2027-08-04|teardown|all' => $confirmed]),
			$after->programItems,
			true,
		);

		$removed = array_map(static fn ($c): string => $c->existing->ruleKey, $plan->ofType(ChangeType::Remove));
		$orphaned = array_map(static fn ($c): string => $c->existing->ruleKey, $plan->ofType(ChangeType::Orphaned));
		$this->assertNotContains('2027-08-04|teardown|all', $removed);
		$this->assertContains('2027-08-04|teardown|all', $orphaned);
	}

	public function testEditedItemGetsAlternativeInsteadOfBeingOverwritten(): void {
		$before = $this->draft();
		$after = $this->draft(['program' => ['theme' => 'Mittelalter']]);
		$key = '2027-07-24|opening|all';
		$edited = new Provenance(Origin::Assistant, ReviewState::Proposal, 1_790_000_000);

		$plan = (new RerunPlanner())->plan($this->stored($before->programItems, [$key => $edited]), $after->programItems, true);
		$conflicts = $plan->ofType(ChangeType::Conflict);

		$this->assertCount(1, $conflicts);
		$this->assertSame($key, $conflicts[0]->existing->ruleKey);
		$this->assertTrue($conflicts[0]->addAsAlternative);
		$this->assertSame('Lagereröffnung: Einstieg ins Thema „Mittelalter“', $conflicts[0]->proposed->title);
		foreach ($plan->ofType(ChangeType::Update) as $update) {
			$this->assertNotSame($key, $update->existing->ruleKey);
		}
	}

	public function testExistingAlternativeIsReusedOnNextRun(): void {
		$after = $this->draft(['program' => ['theme' => 'Mittelalter']]);
		$key = '2027-07-24|opening|all';
		$opening = array_values(array_filter($after->programItems, static fn ($p): bool => $p->ruleKey() === $key))[0];

		$existing = [
			new ExistingElement(1, $key, 'altered-by-user', new Provenance(Origin::Assistant, ReviewState::Confirmed), 'Eröffnung'),
			new ExistingElement(2, $key, $opening->fingerprint(), new Provenance(Origin::Assistant, ReviewState::Proposal), 'Alternative'),
		];
		$plan = (new RerunPlanner())->plan($existing, [$opening], true);

		$conflicts = $plan->ofType(ChangeType::Conflict);
		$this->assertCount(1, $conflicts);
		$this->assertFalse($conflicts[0]->addAsAlternative);
		$this->assertSame([], $plan->ofType(ChangeType::Add));
		$this->assertSame(1, $plan->unchanged);
	}

	public function testTasksReportConflictsWithoutAlternatives(): void {
		$before = $this->draft();
		$after = $this->draft(['start' => '2027-07-31', 'end' => '2027-08-11']);
		$confirmedAll = [];
		foreach ($before->tasks as $task) {
			$confirmedAll[$task->ruleKey()] = new Provenance(Origin::Assistant, ReviewState::Confirmed);
		}
		$plan = (new RerunPlanner())->plan($this->stored($before->tasks, $confirmedAll), $after->tasks, false);

		$this->assertNotSame([], $plan->ofType(ChangeType::Conflict));
		foreach ($plan->ofType(ChangeType::Conflict) as $conflict) {
			$this->assertFalse($conflict->addAsAlternative);
		}
	}

	/** Regression Review 0.1.0: Ein gelöschter Vorschlag wird nicht erneut angelegt. */
	public function testDeletedProposalIsNotRecreated(): void {
		$draft = $this->draft();
		$key = '2027-07-24|evening_round|all';
		$deleted = new Provenance(Origin::Assistant, ReviewState::Proposal, null, 1_790_000_000);

		$plan = (new RerunPlanner())->plan($this->stored($draft->programItems, [$key => $deleted]), $draft->programItems, true);

		$this->assertSame([], $plan->ofType(ChangeType::Add));
		$this->assertSame([], $plan->ofType(ChangeType::Remove));
		$this->assertCount(1, $plan->ofType(ChangeType::Suppressed));
		$this->assertSame(0, $plan->effectiveCount());
	}

	public function testDeletedProposalOutsideNewDraftIsLeftAlone(): void {
		$before = $this->draft();
		$after = $this->draft(['end' => '2027-08-03']);
		$key = '2027-08-04|teardown|all';
		$deleted = new Provenance(Origin::Assistant, ReviewState::Proposal, null, 1_790_000_000);

		$plan = (new RerunPlanner())->plan($this->stored($before->programItems, [$key => $deleted]), $after->programItems, true);
		$keys = array_map(static fn ($c): ?string => $c->existing?->ruleKey, $plan->changes);

		$this->assertNotContains($key, $keys);
	}

	public function testConflictsWithoutAlternativeHaveNoEffect(): void {
		$draft = $this->draft();
		$key = '2027-07-24|opening|all';
		$edited = new Provenance(Origin::Assistant, ReviewState::Confirmed);
		$plan = (new RerunPlanner())->plan($this->stored($draft->programItems, [$key => $edited], [$key => 'edited']), $draft->programItems, false);

		$this->assertCount(1, $plan->ofType(ChangeType::Conflict));
		$this->assertSame(0, $plan->effectiveCount());
	}

	public function testManualItemsWithoutRuleKeyAreIgnored(): void {
		$draft = $this->draft();
		$manual = new ExistingElement(999, null, 'x', new Provenance(Origin::Manual, ReviewState::Confirmed), 'Eigener Punkt');
		$plan = (new RerunPlanner())->plan([...$this->stored($draft->programItems), $manual], $draft->programItems, true);

		$this->assertTrue($plan->isEmpty());
	}
}

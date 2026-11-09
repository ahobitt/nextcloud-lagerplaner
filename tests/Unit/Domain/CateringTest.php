<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use DateTimeImmutable;
use OCA\Lagerplaner\Domain\Catering\DietaryCombination;
use OCA\Lagerplaner\Domain\Catering\DietaryProfile;
use OCA\Lagerplaner\Domain\Catering\MenuVariant;
use OCA\Lagerplaner\Domain\Catering\MenuVariantPlan;
use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Domain\Catering\PortionCalculator;
use OCA\Lagerplaner\Domain\Catering\Requirement;
use OCA\Lagerplaner\Domain\Catering\SevereAllergies;
use PHPUnit\Framework\TestCase;

final class CateringTest extends TestCase {
	/** Beispieldaten aus Konzept 8.5. */
	private function profile(): DietaryProfile {
		return DietaryProfile::fromCounts([
			'none' => 27,
			'vegetarian' => 6,
			'vegan' => 3,
			'vegetarian+gluten_free' => 2,
			'vegan+gluten_free' => 1,
			'lactose_free' => 2,
			'unanswered' => 1,
		]);
	}

	/** Beispiel aus Konzept 9.8. */
	private function variants(): array {
		return [
			new MenuVariant('standard', 'Standard, Käse separat', [Requirement::LactoseFree]),
			new MenuVariant('veggie', 'Vegetarisch', [Requirement::Vegetarian]),
			new MenuVariant('vegan', 'Vegan', [Requirement::Vegan]),
			new MenuVariant('vegan_gf', 'Vegan und glutenfrei', [Requirement::Vegan, Requirement::GlutenFree]),
			new MenuVariant('open', 'Ungeklärt', []),
		];
	}

	public function testProfileTotalsMatchConceptExample(): void {
		$profile = $this->profile();

		$this->assertSame(42, $profile->total());
		$this->assertSame(
			['gluten_free' => 3, 'lactose_free' => 2, 'vegan' => 4, 'vegetarian' => 8],
			$profile->totalsPerRequirement(),
		);
		$this->assertSame([], $profile->checkAgainstParticipants(42));
		$this->assertSame(PlanIssue::TOTAL_MISMATCH, $profile->checkAgainstParticipants(40)[0]->code);
	}

	public function testCombinationKeysAreCanonical(): void {
		$a = DietaryCombination::fromKey('vegetarian+gluten_free', 1);
		$b = DietaryCombination::fromKey('gluten_free+vegetarian', 1);

		$this->assertSame($a->key, $b->key);
		$this->assertSame('Glutenfrei + vegetarisch', $a->label());
	}

	public function testDuplicateCombinationIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new DietaryProfile([DietaryCombination::fromKey('vegan', 1), DietaryCombination::fromKey('vegan', 2)]);
	}

	public function testConceptExampleAssignmentHasNoDoubleCounting(): void {
		$result = (new MenuVariantPlan())->evaluate($this->profile(), $this->variants(), [
			'none' => 'standard',
			'lactose_free' => 'standard',
			'vegetarian' => 'veggie',
			'vegan' => 'vegan',
			'gluten_free+vegetarian' => 'vegan_gf',
			'gluten_free+vegan' => 'vegan_gf',
			'unanswered' => 'open',
		]);

		$this->assertSame(['standard' => 29, 'veggie' => 6, 'vegan' => 3, 'vegan_gf' => 3, 'open' => 1], $result->headcounts);
		$this->assertSame(42, $result->total());
		$this->assertFalse($result->hasErrors());
		$this->assertCount(1, $result->issues);
		$this->assertSame(PlanIssue::UNRESOLVED, $result->issues[0]->code);
	}

	public function testVariantThatDoesNotFitIsAnError(): void {
		$result = (new MenuVariantPlan())->evaluate(
			DietaryProfile::fromCounts(['vegan' => 3]),
			$this->variants(),
			['vegan' => 'veggie'],
		);

		$this->assertTrue($result->hasErrors());
		$this->assertSame(PlanIssue::NOT_SATISFIED, $result->issues[0]->code);
		$this->assertStringContainsString('nicht vegan', $result->issues[0]->message);
	}

	public function testUnassignedAndUnknownAreReported(): void {
		$result = (new MenuVariantPlan())->evaluate(
			DietaryProfile::fromCounts(['none' => 10, 'vegan' => 1]),
			$this->variants(),
			['none' => 'standard', 'peanut_free' => 'standard'],
		);
		$codes = array_map(static fn (PlanIssue $i): string => $i->code, $result->issues);

		$this->assertContains(PlanIssue::UNASSIGNED, $codes);
		$this->assertContains(PlanIssue::UNKNOWN_COMBINATION, $codes);
		$this->assertSame(10, $result->total());
	}

	public function testOtherRequirementsAlwaysNeedManualCheck(): void {
		$result = (new MenuVariantPlan())->evaluate(
			DietaryProfile::fromCounts(['other' => 1]),
			[new MenuVariant('special', 'Sondervariante', [Requirement::Other])],
			['other' => 'special'],
		);

		$this->assertFalse($result->hasErrors());
		$this->assertSame(PlanIssue::MANUAL_CHECK, $result->issues[0]->code);
	}

	/** Regression Review 0.1.0: Ernährungsweisen dürfen keine Allergenfreiheit begründen. */
	public function testVeganOnlyImpliesVegetarian(): void {
		$values = array_map(static fn (Requirement $r): string => $r->value, Requirement::closure([Requirement::Vegan]));
		sort($values);

		$this->assertSame(['vegan', 'vegetarian'], $values);
		$this->assertSame('allergen', Requirement::MilkFree->kind());
		$this->assertSame('diet', Requirement::Vegan->kind());
	}

	/** Regression Review 0.1.0: Eine nur vegane Variante ist nicht automatisch milcheiweiß- oder eifrei. */
	public function testVeganVariantIsNotAcceptedForAllergies(): void {
		$vegan = [new MenuVariant('vegan', 'Vegan', [Requirement::Vegan])];
		foreach (['milk_free', 'egg_free', 'lactose_free'] as $allergen) {
			$result = (new MenuVariantPlan())->evaluate(DietaryProfile::fromCounts([$allergen => 1]), $vegan, [$allergen => 'vegan']);
			$this->assertTrue($result->hasErrors(), $allergen);
			$this->assertSame(PlanIssue::NOT_SATISFIED, $result->issues[0]->code);
		}

		$checked = [new MenuVariant('vegan_mf', 'Vegan, auf Milcheiweiß geprüft', [Requirement::Vegan, Requirement::MilkFree])];
		$ok = (new MenuVariantPlan())->evaluate(DietaryProfile::fromCounts(['milk_free' => 1]), $checked, ['milk_free' => 'vegan_mf']);
		$this->assertFalse($ok->hasErrors());
	}

	public function testPortionsMatchConceptExample(): void {
		$calc = new PortionCalculator();
		$portions = $calc->standardPortions(['woelflinge' => 10, 'rover' => 10], ['woelflinge' => 0.75, 'rover' => 1.15]);

		$this->assertSame(19.0, $portions);
		$this->assertSame(2375.0, $calc->ingredientQuantity(500, 4, $portions));
		$this->assertSame(2612.5, $calc->ingredientQuantity(500, 4, $portions, 10));
	}

	public function testMissingPortionFactorIsAnError(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new PortionCalculator())->standardPortions(['gaeste' => 3], []);
	}

	public function testSevereAllergyCreatesMandatoryTask(): void {
		$today = new DateTimeImmutable('2026-10-11', new \DateTimeZone('UTC'));
		$start = new DateTimeImmutable('2027-07-24', new \DateTimeZone('UTC'));

		$this->assertNull((new SevereAllergies(['peanuts' => 0]))->mandatoryTask($start, $today));

		$task = (new SevereAllergies(['peanuts' => 1]))->mandatoryTask($start, $today);
		$this->assertNotNull($task);
		$this->assertTrue($task->mandatory);
		$this->assertSame('2027-07-10', $task->dueDate);
		$this->assertSame('task|allergieverfahren', $task->ruleKey());
	}
}

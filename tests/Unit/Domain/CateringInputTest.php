<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Catering\DietaryNeedsInput;
use OCA\Lagerplaner\Domain\Catering\DietaryProfile;
use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\MealVariantPlanInput;
use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Domain\Catering\SevereAllergies;
use PHPUnit\Framework\TestCase;

final class CateringInputTest extends TestCase {
	/** Beispieldaten aus Konzept 8.5 in der Form, in der die Oberfläche sie sendet. */
	private function conceptExample(): array {
		return ['combinations' => [
			['key' => 'none', 'count' => 27],
			['key' => 'vegetarian', 'count' => 6],
			['key' => 'vegan', 'count' => 3],
			['key' => 'vegetarian+gluten_free', 'count' => 2],
			['key' => 'gluten_free+vegan', 'count' => 1],
			['key' => 'lactose_free', 'count' => '2'],
			['key' => 'unanswered', 'count' => 1],
			['key' => 'clarify', 'count' => 0],
		], 'severe' => ['peanuts' => 0]];
	}

	public function testConceptExampleIsAccepted(): void {
		$input = DietaryNeedsInput::fromArray($this->conceptExample());

		$this->assertSame(42, $input->profile->total());
		$this->assertCount(7, $input->profile->combinations());
		$this->assertNotNull($input->profile->get('gluten_free+vegetarian'));
		$this->assertSame([], $input->severe->reported());
	}

	public function testInvalidEntriesAreReportedPerField(): void {
		try {
			DietaryNeedsInput::fromArray(['combinations' => [
				['key' => 'vegan', 'count' => 1],
				['key' => 'vegan', 'count' => 2],
				['key' => 'raw_food', 'count' => 1],
				['key' => 'vegetarian', 'count' => -1],
			], 'severe' => ['shellfish' => 1, 'peanuts' => 'eins']]);
			$this->fail('Hätte abgelehnt werden müssen.');
		} catch (InvalidCateringInput $e) {
			$this->assertArrayHasKey('combinations.1.key', $e->errors);
			$this->assertArrayHasKey('combinations.2.key', $e->errors);
			$this->assertArrayHasKey('combinations.3.count', $e->errors);
			$this->assertArrayHasKey('severe.shellfish', $e->errors);
			$this->assertArrayHasKey('severe.peanuts', $e->errors);
		}
	}

	public function testTotalIsLimited(): void {
		$this->expectException(InvalidCateringInput::class);
		DietaryNeedsInput::fromArray(['combinations' => [['key' => 'none', 'count' => 4000], ['key' => 'vegan', 'count' => 2000]]]);
	}

	public function testSevereAllergyWithoutMatchingCombinationIsAnError(): void {
		$severe = new SevereAllergies(['peanuts' => 1]);

		$issues = $severe->consistencyIssues(DietaryProfile::fromCounts(['none' => 10]));
		$this->assertCount(1, $issues);
		$this->assertSame(PlanIssue::SEVERE_NOT_COVERED, $issues[0]->code);
		$this->assertStringContainsString('Erdnüsse', $issues[0]->message);

		$this->assertSame([], $severe->consistencyIssues(DietaryProfile::fromCounts(['none' => 9, 'peanut_free+vegan' => 1])));
	}

	public function testUnknownSevereAllergenIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SevereAllergies(['shellfish' => 1]);
	}

	public function testVariantPlanRoundTripStoresOnlyDeclaredRequirements(): void {
		$plan = MealVariantPlanInput::fromArray([
			'variants' => [
				['key' => 'std', 'label' => 'Standard, Käse separat', 'satisfies' => ['lactose_free']],
				['key' => 'vegan', 'label' => 'Vegan', 'satisfies' => ['vegan']],
			],
			'assignments' => ['none' => 'std', 'vegetarian+lactose_free' => 'vegan'],
		]);
		$stored = $plan->toArray();

		$this->assertSame(['vegan'], $stored['variants'][1]['satisfies']);
		$this->assertSame(['none' => 'std', 'lactose_free+vegetarian' => 'vegan'], $stored['assignments']);
		$this->assertSame($stored, MealVariantPlanInput::fromJson(json_encode($stored))->toArray());
	}

	public function testVariantPlanEvaluationFlagsUnsafeAssignment(): void {
		$plan = MealVariantPlanInput::fromArray([
			'variants' => [['key' => 'vegan', 'label' => 'Vegan', 'satisfies' => ['vegan']]],
			'assignments' => ['vegan' => 'vegan', 'milk_free' => 'vegan'],
		]);
		$result = $plan->evaluate(DietaryProfile::fromCounts(['vegan' => 3, 'milk_free' => 1]));

		$this->assertSame(['vegan' => 4], $result['headcounts']);
		$this->assertTrue($result['hasErrors']);
		$this->assertSame(PlanIssue::NOT_SATISFIED, $result['issues'][0]['code']);
	}

	/** Regression Review 0.6.1: Eine Variante mit Personen, aber ohne Rezept, wird gemeldet. */
	public function testVariantWithoutRecipeIsReported(): void {
		$plan = MealVariantPlanInput::fromArray([
			'variants' => [['key' => 'std', 'label' => 'Standard', 'recipeId' => 1], ['key' => 'veg', 'label' => 'Vegetarisch', 'satisfies' => ['vegetarian']]],
			'assignments' => ['none' => 'std', 'vegetarian' => 'veg'],
		]);
		$recipe = \OCA\Lagerplaner\Domain\Recipes\Recipe::fromArray(['title' => 'Reis', 'servings' => 10, 'diet' => 'vegan', 'contains' => [],
			'ingredients' => [['name' => 'Reis', 'quantity' => 1, 'unit' => 'kg']], 'steps' => ['x']]);
		$result = $plan->evaluate(DietaryProfile::fromCounts(['none' => 30, 'vegetarian' => 12]), [1 => $recipe], [1]);

		$this->assertSame([PlanIssue::RECIPE_NOT_ASSIGNED], array_column($result['issues'], 'code'));
		$this->assertStringContainsString('12 Personen', $result['issues'][0]['message']);
	}

	public function testInvalidVariantPlansAreRejected(): void {
		$cases = [
			'variants.1.key' => ['variants' => [['key' => 'a', 'label' => 'A'], ['key' => 'a', 'label' => 'B']]],
			'variants.0.label' => ['variants' => [['key' => 'a', 'label' => '']]],
			'variants.0.satisfies' => ['variants' => [['key' => 'a', 'label' => 'A', 'satisfies' => ['halal']]]],
			'assignments.vegan' => ['variants' => [['key' => 'a', 'label' => 'A']], 'assignments' => ['vegan' => 'b']],
		];
		foreach ($cases as $field => $data) {
			try {
				MealVariantPlanInput::fromArray($data);
				$this->fail('Nicht abgelehnt: ' . $field);
			} catch (InvalidCateringInput $e) {
				$this->assertArrayHasKey($field, $e->errors);
			}
		}
	}

	public function testBrokenStoredPlanBecomesEmpty(): void {
		$this->assertTrue(MealVariantPlanInput::fromJson('{kaputt')->isEmpty());
		$this->assertTrue(MealVariantPlanInput::fromJson(null)->isEmpty());
	}
}

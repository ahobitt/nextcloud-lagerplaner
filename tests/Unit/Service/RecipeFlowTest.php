<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\PlanIssue;
use OCA\Lagerplaner\Service\ForbiddenException;
use OCA\Lagerplaner\Tests\Unit\Domain\Fixtures;

final class RecipeFlowTest extends ServiceTestCase {
	private int $camp;

	protected function setUp(): void {
		parent::setUp();
		$this->camp = (int)$this->wizard->create('anna', Fixtures::summerCamp())->getId();
	}

	private function recipeId(string $templateKey): int {
		foreach ($this->recipes->list($this->camp, 'anna') as $recipe) {
			if ($recipe['templateKey'] === $templateKey) {
				return $recipe['id'];
			}
		}
		$this->fail('Rezept fehlt: ' . $templateKey);
	}

	private function lunchId(): int {
		foreach ($this->planning->meals($this->camp, 'anna') as $meal) {
			if ($meal->getMealType() === 'lunch' && $meal->getDay() === '2027-07-26') {
				return (int)$meal->getId();
			}
		}
		return 0;
	}

	public function testStarterRecipesCanBeImportedOnce(): void {
		$library = $this->recipes->library($this->camp, 'anna');
		$this->assertCount(18, $library);
		$this->assertFalse($library[0]['imported']);

		$this->assertSame(2, $this->recipes->import($this->camp, 'anna', ['milchreis', 'stockbrot']));
		$this->assertSame(1, $this->recipes->import($this->camp, 'anna', ['milchreis', 'kartoffelsuppe']));
		$this->assertCount(3, $this->recipes->list($this->camp, 'anna'));

		$this->expectException(\InvalidArgumentException::class);
		$this->recipes->import($this->camp, 'anna', ['pizza_hawaii']);
	}

	public function testRecipeIsScaledOnRequest(): void {
		$this->recipes->import($this->camp, 'anna', ['milchreis']);
		$detail = $this->recipes->get($this->recipeId('milchreis'), 'anna', 40);
		$milk = array_column($detail['scaled'], null, 'name')['Milch'];

		$this->assertSame([16.0, 'l'], [$milk['quantity'], $milk['unit']]);
	}

	public function testOwnRecipeCanBeCreatedAndEdited(): void {
		$recipe = ['title' => 'Nudelsalat', 'servings' => 8, 'diet' => 'vegetarian', 'contains' => ['gluten', 'egg'], 'category' => 'side',
			'ingredients' => [['name' => 'Nudeln', 'quantity' => '0,8', 'unit' => 'kg']], 'steps' => ['Kochen', 'Mischen']];
		$created = $this->recipes->create($this->camp, 'anna', $recipe);
		$this->assertSame('Nudelsalat', $created['title']);

		$recipe['title'] = 'Nudelsalat mit Pesto';
		$this->assertSame('Nudelsalat mit Pesto', $this->recipes->update($created['id'], 'anna', $recipe)['title']);
	}

	public function testHelpersMayReadButNotEditRecipes(): void {
		$this->team->add($this->camp, 'anna', 'group', 'leiterrunde', ['mitarbeit']);
		$this->recipes->import($this->camp, 'anna', ['stockbrot']);

		$this->assertCount(1, $this->recipes->list($this->camp, 'ben'));
		$this->expectException(ForbiddenException::class);
		$this->recipes->import($this->camp, 'ben', ['milchreis']);
	}

	public function testRecipeInVariantIsCheckedAndScaled(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 40], ['key' => 'gluten_free', 'count' => 2]]]);
		$this->recipes->import($this->camp, 'anna', ['spaghetti_tomatensosse', 'chili_sin_carne']);
		$spaghetti = $this->recipeId('spaghetti_tomatensosse');
		$this->recipes->confirmAllergens($spaghetti, 'anna');
		$this->recipes->confirmAllergens($this->recipeId('chili_sin_carne'), 'anna');

		$result = $this->catering->saveMealPlan($this->lunchId(), 'anna', [
			'variants' => [
				['key' => 'std', 'label' => 'Spaghetti', 'satisfies' => [], 'recipeId' => $spaghetti],
				['key' => 'gf', 'label' => 'Glutenfrei', 'satisfies' => ['gluten_free'], 'recipeId' => $spaghetti],
			],
			'assignments' => ['none' => 'std', 'gluten_free' => 'gf'],
		]);
		$codes = array_column($result['evaluation']['issues'], 'code');
		$this->assertContains(PlanIssue::RECIPE_CONFLICT, $codes);
		$this->assertTrue($result['evaluation']['hasErrors']);

		// Mit dem glutenfreien Chili passt es; die Mengen werden je Variante hochgerechnet.
		$result = $this->catering->saveMealPlan($this->lunchId(), 'anna', [
			'variants' => [
				['key' => 'std', 'label' => 'Spaghetti', 'satisfies' => [], 'recipeId' => $spaghetti],
				['key' => 'gf', 'label' => 'Chili', 'satisfies' => ['gluten_free'], 'recipeId' => $this->recipeId('chili_sin_carne')],
			],
			'assignments' => ['none' => 'std', 'gluten_free' => 'gf'],
		]);
		$this->assertFalse($result['evaluation']['hasErrors']);
		$quantities = array_column($result['quantities'], null, 'variantKey');
		// 39,85 Standardportionen für 42 Personen: 40 Personen ergeben 38 Portionen.
		$this->assertSame(38.0, $quantities['std']['portions']);
		$this->assertSame('Spaghetti mit Tomatensoße', $quantities['std']['recipeTitle']);
		$this->assertSame(['Spaghetti', 4.94, 'kg'], [$quantities['std']['ingredients'][0]['name'], $quantities['std']['ingredients'][0]['quantity'], $quantities['std']['ingredients'][0]['unit']]);
	}

	public function testSevereAllergyInRecipeTriggersHint(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 41], ['key' => 'peanut_free', 'count' => 1]], 'severe' => ['peanuts' => 1]]);
		$satay = $this->recipes->create($this->camp, 'anna', ['title' => 'Erdnusssoße', 'servings' => 10, 'diet' => 'vegan', 'contains' => ['peanuts'],
			'ingredients' => [['name' => 'Erdnussmus', 'quantity' => 300, 'unit' => 'g']], 'steps' => ['Verrühren']]);

		$result = $this->catering->saveMealPlan($this->lunchId(), 'anna', [
			'variants' => [['key' => 'std', 'label' => 'Standard', 'satisfies' => [], 'recipeId' => $satay['id']]],
			'assignments' => ['none' => 'std', 'peanut_free' => 'std'],
		]);

		$hints = implode(' ', $result['severeRecipeHints']);
		$this->assertStringContainsString('enthält Erdnüsse', $hints);
		$this->assertStringContainsString('nicht geprüft', $hints);
		$this->assertTrue($result['evaluation']['hasErrors']);
	}

	/** Regression Review 0.6.0: Grundrezepte gelten nach der Übernahme als ungeprüft. */
	public function testImportedRecipesNeedAllergenConfirmation(): void {
		$this->catering->saveNeeds($this->camp, 'anna', ['combinations' => [['key' => 'none', 'count' => 40], ['key' => 'gluten_free', 'count' => 2]]]);
		$this->recipes->import($this->camp, 'anna', ['chili_sin_carne']);
		$chili = $this->recipeId('chili_sin_carne');
		$plan = ['variants' => [['key' => 'gf', 'label' => 'Glutenfrei', 'satisfies' => ['gluten_free'], 'recipeId' => $chili]], 'assignments' => ['gluten_free' => 'gf']];

		$this->assertFalse($this->recipes->list($this->camp, 'anna')[0]['allergensChecked']);
		$codes = array_column($this->catering->saveMealPlan($this->lunchId(), 'anna', $plan)['evaluation']['issues'], 'code');
		$this->assertContains(PlanIssue::RECIPE_CONFLICT, $codes);

		$this->recipes->confirmAllergens($chili, 'anna');
		$codes = array_column($this->catering->saveMealPlan($this->lunchId(), 'anna', $plan)['evaluation']['issues'], 'code');
		$this->assertNotContains(PlanIssue::RECIPE_CONFLICT, $codes);

		// Nach dem Bearbeiten ist die Bestätigung wieder offen.
		$data = $this->recipes->get($chili, 'anna')['recipe'];
		$this->assertFalse($this->recipes->update($chili, 'anna', $data)['allergensChecked']);
	}

	public function testForeignRecipeIsRejected(): void {
		$this->expectException(InvalidCateringInput::class);
		$this->catering->saveMealPlan($this->lunchId(), 'anna', ['variants' => [['key' => 'std', 'label' => 'X', 'recipeId' => 9999]]]);
	}

	public function testDeletingARecipeRemovesItFromMealPlans(): void {
		$this->recipes->import($this->camp, 'anna', ['kartoffelsuppe']);
		$soup = $this->recipeId('kartoffelsuppe');
		$this->catering->saveMealPlan($this->lunchId(), 'anna', ['variants' => [['key' => 'std', 'label' => 'Suppe', 'recipeId' => $soup]]]);

		$this->recipes->delete($soup, 'anna');
		$this->assertNull($this->catering->mealPlan($this->lunchId(), 'anna')['plan']['variants'][0]['recipeId']);
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;
use OCA\Lagerplaner\Domain\Catering\MenuVariant;
use OCA\Lagerplaner\Domain\Catering\Requirement;
use OCA\Lagerplaner\Domain\Recipes\Allergen;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\RecipeCompatibility;
use OCA\Lagerplaner\Domain\Recipes\RecipeScaler;
use PHPUnit\Framework\TestCase;

final class RecipesTest extends TestCase {
	/** @return array<string, array<string, mixed>> */
	private function starter(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../data/starter-recipes.json'), true, 32, JSON_THROW_ON_ERROR);
		$byKey = [];
		foreach ($data['recipes'] as $recipe) {
			$byKey[$recipe['key']] = $recipe;
		}
		return $byKey;
	}

	public function testAllStarterRecipesAreValid(): void {
		$starter = $this->starter();
		$this->assertGreaterThanOrEqual(10, count($starter));
		foreach ($starter as $key => $data) {
			$recipe = Recipe::fromArray($data);
			$this->assertSame(10, $recipe->servings, $key);
			$this->assertNotSame([], $recipe->steps, $key);
			$this->assertSame('CC0 1.0', $recipe->source['license'], $key);
		}
	}

	public function testScalingConvertsUnitsAndRoundsCountablesUp(): void {
		$recipe = Recipe::fromArray($this->starter()['pfannkuchen']);
		$scaled = array_column((new RecipeScaler())->scale($recipe, 25), null, 'name');

		$this->assertSame([1.88, 'kg'], [$scaled['Weizenmehl']['quantity'], $scaled['Weizenmehl']['unit']]);
		$this->assertSame([3.75, 'l'], [$scaled['Milch']['quantity'], $scaled['Milch']['unit']]);
		$this->assertSame(25.0, $scaled['Eier']['quantity']);
		$this->assertFalse($scaled['Eier']['rounded']);
		$this->assertSame([5.0, false], [$scaled['Apfelmus']['quantity'], $scaled['Apfelmus']['rounded']]);
		// 12,5 Portionen: 12,5 Eier werden auf 13 aufgerundet und als gerundet markiert.
		$eggs = array_column((new RecipeScaler())->scale($recipe, 12.5), null, 'name')['Eier'];
		$this->assertSame([13.0, 12.5, true], [$eggs['quantity'], $eggs['exact'], $eggs['rounded']]);
		$this->assertSame(2.5, $scaled['Salz']['quantity']);
	}

	public function testSmallAmountsStayInSmallUnitsAndToTasteHasNoQuantity(): void {
		$recipe = Recipe::fromArray($this->starter()['chili_sin_carne']);
		$scaled = array_column((new RecipeScaler())->scale($recipe, 5), null, 'name');

		$this->assertSame([500.0, 'g'], [$scaled['Reis']['quantity'], $scaled['Reis']['unit']]);
		$this->assertNull($scaled['Salz und Pfeffer']['quantity']);
		$this->assertSame(0.5, $scaled['Chilipulver']['quantity']);
	}

	public function testRecipeConflictsWithVariantPromises(): void {
		$spaghetti = Recipe::fromArray($this->starter()['spaghetti_tomatensosse']);
		$milchreis = Recipe::fromArray($this->starter()['milchreis']);
		$check = new RecipeCompatibility();

		$this->assertSame([], $check->conflicts($spaghetti, new MenuVariant('v', 'Vegan', [Requirement::Vegan])));
		$this->assertCount(1, $check->conflicts($spaghetti, new MenuVariant('gf', 'Glutenfrei', [Requirement::GlutenFree]), true));
		$this->assertStringContainsString('enthält aber Laktose', $check->conflicts($milchreis, new MenuVariant('lf', 'Laktosefrei', [Requirement::LactoseFree]), true)[0]);
		$this->assertStringContainsString('nicht', $check->conflicts($milchreis, new MenuVariant('v', 'Vegan', [Requirement::Vegan]))[0]);
	}

	/** Regression Review 0.6.0: Fehlende Angaben in einem ungeprüften Rezept heißen nicht „allergenfrei“. */
	public function testUncheckedRecipeCannotCarryAnAllergenPromise(): void {
		$wheat = Recipe::fromArray(['title' => 'Weizennudeln ohne Angaben', 'servings' => 10, 'diet' => 'vegan', 'contains' => [],
			'ingredients' => [['name' => 'Weizennudeln', 'quantity' => 1, 'unit' => 'kg']], 'steps' => ['Kochen']]);
		$check = new RecipeCompatibility();
		$glutenFree = new MenuVariant('gf', 'Glutenfrei', [Requirement::GlutenFree]);

		$this->assertStringContainsString('nicht geprüft', $check->conflicts($wheat, $glutenFree)[0]);
		$this->assertSame([], $check->conflicts($wheat, $glutenFree, true));
		// Ernährungsweisen allein hängen nicht an der Allergenprüfung.
		$this->assertSame([], $check->conflicts($wheat, new MenuVariant('v', 'Vegan', [Requirement::Vegan])));
	}

	public function testLactoseFreeMilkRecipeFitsLactoseButNotMilkProtein(): void {
		$data = $this->starter()['milchreis'];
		$data['contains'] = ['milk_protein'];
		$recipe = Recipe::fromArray($data);
		$check = new RecipeCompatibility();

		$this->assertSame([], $check->conflicts($recipe, new MenuVariant('lf', 'Laktosefrei', [Requirement::LactoseFree]), true));
		$this->assertCount(1, $check->conflicts($recipe, new MenuVariant('mf', 'Milchfrei', [Requirement::MilkFree]), true));
	}

	public function testSevereAllergyKeysMapToRecipeAllergens(): void {
		$this->assertSame(Allergen::MilkProtein, Allergen::fromSevereKey('milk'));
		$this->assertNull(Allergen::fromSevereKey('fish'));
	}

	public function testInvalidRecipesAreRejectedPerField(): void {
		try {
			Recipe::fromArray([
				'title' => '',
				'servings' => 0,
				'ingredients' => [['name' => 'Mehl', 'quantity' => -1, 'unit' => 'g'], ['name' => 'Zucker', 'quantity' => 1, 'unit' => 'Tasse']],
				'steps' => [str_repeat('x', 1001)],
				'diet' => 'paleo',
				'contains' => ['fisch'],
			]);
			$this->fail('Nicht abgelehnt');
		} catch (InvalidCateringInput $e) {
			foreach (['title', 'servings', 'ingredients.0.quantity', 'ingredients.1.unit', 'steps.0', 'diet', 'contains'] as $field) {
				$this->assertArrayHasKey($field, $e->errors);
			}
		}
	}

	public function testOnlyWebLinksAreKeptAsSource(): void {
		$data = $this->starter()['stockbrot'];
		$data['source'] = ['name' => 'X', 'url' => 'javascript:alert(1)', 'license' => ''];
		$this->assertSame('', Recipe::fromArray($data)->source['url']);
	}
}

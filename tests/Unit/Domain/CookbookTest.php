<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Cookbook\CookbookRecipeReader;
use OCA\Lagerplaner\Domain\Cookbook\CookbookRecipeWriter;
use OCA\Lagerplaner\Domain\Cookbook\IngredientLineParser;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use PHPUnit\Framework\TestCase;

final class CookbookTest extends TestCase {
	/** Typische Schreibweisen aus Cookbook-Rezepten: [Zeile, Menge, Einheit, Name, Anmerkung, sicher] */
	public function testIngredientLines(): void {
		$cases = [
			['500 g Mehl', 500.0, 'g', 'Mehl', '', true],
			['500g Mehl', 500.0, 'g', 'Mehl', '', true],
			['1,5 kg Kartoffeln, festkochend', 1.5, 'kg', 'Kartoffeln', 'festkochend', true],
			['1/2 TL Zimt', 0.5, 'TL', 'Zimt', '', true],
			['1 ½ l Milch', 1.5, 'l', 'Milch', '', true],
			['2 EL Öl', 2.0, 'EL', 'Öl', '', true],
			['3 Zwiebeln', 3.0, 'Stück', 'Zwiebeln', '', true],
			['1 Dose Tomaten (400 g)', 1.0, 'Dose', 'Tomaten (400 g)', '', true],
			['4 cl Rum', 40.0, 'ml', 'Rum', '', true],
			['1 Pck. Vanillezucker', 1.0, 'Packung', 'Vanillezucker', '', true],
			['2 Zehen Knoblauch, gepresst', 2.0, 'Zehe', 'Knoblauch', 'gepresst', true],
			['Salz und Pfeffer', null, 'nach Geschmack', 'Salz und Pfeffer', '', true],
			['etwas Öl zum Braten', null, 'nach Geschmack', 'etwas Öl zum Braten', '', true],
			['<b>200 g</b> Butter', 200.0, 'g', 'Butter', '', true],
			// Unsicher: Bereich (größere Menge, damit es reicht) und Zeile ohne Menge
			['2-3 Zwiebeln', 3.0, 'Stück', 'Zwiebeln', '', false],
			['Frühlingszwiebeln', null, 'nach Geschmack', 'Frühlingszwiebeln', '', false],
		];
		$parser = new IngredientLineParser();
		foreach ($cases as [$line, $quantity, $unit, $name, $note, $certain]) {
			$r = $parser->parse($line);
			$this->assertSame([$quantity, $unit, $name, $note, $certain], [$r['quantity'], $r['unit'], $r['name'], $r['note'], $r['certain']], $line);
		}
	}

	private function cookbookJson(array $overrides = []): array {
		return $overrides + [
			'@context' => 'http://schema.org',
			'@type' => 'Recipe',
			'name' => 'Linsen-Dal',
			'recipeYield' => 4,
			'recipeCategory' => 'Hauptgericht',
			'keywords' => 'vegan,schnell',
			'totalTime' => 'PT1H15M',
			'recipeIngredient' => ['250 g rote Linsen', '1 Dose Kokosmilch (400 ml)', '2-3 Zwiebeln', 'Salz'],
			'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Zwiebeln anbraten.'], 'Linsen und Kokosmilch zugeben, 20 Minuten köcheln.'],
			'description' => 'Ein einfaches Dal.',
			'url' => 'https://example.org/dal',
		];
	}

	public function testReaderBuildsAValidCampRecipe(): void {
		$read = (new CookbookRecipeReader())->read($this->cookbookJson());
		$recipe = Recipe::fromArray($read['recipe']);

		$this->assertSame(['Linsen-Dal', 4, 'vegan', [], 75], [$recipe->title, $recipe->servings, $recipe->diet->value, $recipe->contains, $recipe->prepMinutes]);
		$this->assertSame(['Zwiebeln anbraten.', 'Linsen und Kokosmilch zugeben, 20 Minuten köcheln.'], $recipe->steps);
		$this->assertCount(4, $recipe->ingredients);
		$this->assertSame('https://example.org/dal', $recipe->source['url']);
		$this->assertStringContainsString('1 Zutat(en) ließen sich nicht sicher lesen', implode(' ', $read['review']));
		$this->assertStringContainsString('kennt keine Allergene', implode(' ', $read['review']));
	}

	/** Ohne eindeutige Stichwörter nie vegetarisch oder vegan zusagen. */
	public function testUnknownDietIsTheSafeDefault(): void {
		$read = (new CookbookRecipeReader())->read($this->cookbookJson(['keywords' => 'schnell', 'name' => 'Nudelauflauf', 'recipeYield' => '6 Portionen']));
		$this->assertSame(['none', 6], [$read['recipe']['diet'], $read['recipe']['servings']]);

		$noYield = (new CookbookRecipeReader())->read($this->cookbookJson(['recipeYield' => 'reichlich']));
		$this->assertSame(CookbookRecipeReader::DEFAULT_SERVINGS, $noYield['recipe']['servings']);
		$this->assertStringContainsString('Portionenzahl', $noYield['review'][0]);
	}

	public function testInstructionVariants(): void {
		$sections = (new CookbookRecipeReader())->read($this->cookbookJson(['recipeInstructions' => [
			['@type' => 'HowToSection', 'name' => 'Teig', 'itemListElement' => [['@type' => 'HowToStep', 'text' => 'Mehl sieben.'], ['@type' => 'HowToStep', 'text' => 'Kneten.']]],
			'Backen.',
		]]));
		$this->assertSame(['Mehl sieben.', 'Kneten.', 'Backen.'], $sections['recipe']['steps']);

		$none = (new CookbookRecipeReader())->read($this->cookbookJson(['recipeInstructions' => []]));
		$this->assertSame(['Zubereitung siehe Rezept in Cookbook.'], $none['recipe']['steps']);
	}

	/** Was der Lagerplaner nach Cookbook schreibt, liest er unverändert wieder ein. */
	public function testRoundTrip(): void {
		$recipe = Recipe::fromArray(['title' => 'Stockbrot', 'servings' => 10, 'diet' => 'vegan', 'contains' => ['gluten'], 'category' => 'campfire',
			'ingredients' => [['name' => 'Mehl', 'quantity' => 1, 'unit' => 'kg'], ['name' => 'Wasser', 'quantity' => 500, 'unit' => 'ml', 'note' => 'lauwarm'],
				['name' => 'Hefe', 'quantity' => 1, 'unit' => 'Packung'], ['name' => 'Salz', 'quantity' => null, 'unit' => 'nach Geschmack']],
			'steps' => ['Teig kneten.', 'Um Stöcke wickeln.']]);
		$json = (new CookbookRecipeWriter())->write($recipe, '2026-10-12T10:00:00+00:00');
		$this->assertSame(['1 kg Mehl', '500 ml Wasser, lauwarm', '1 Packung Hefe', 'Salz'], $json['recipeIngredient']);
		$this->assertSame('Lagerfeuer', $json['recipeCategory']);

		$back = Recipe::fromArray((new CookbookRecipeReader())->read($json)['recipe']);
		$this->assertSame(array_map(static fn ($i): array => $i->toArray(), $recipe->ingredients), array_map(static fn ($i): array => $i->toArray(), $back->ingredients));
		$this->assertSame(['vegan', 10, 'campfire'], [$back->diet->value, $back->servings, $back->category]);
	}
}

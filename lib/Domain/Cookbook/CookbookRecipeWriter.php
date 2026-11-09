<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Cookbook;

use OCA\Lagerplaner\Domain\Recipes\Ingredient;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\Unit;

/**
 * Übersetzt ein Lagerrezept in eine recipe.json für Cookbook (schema.org/Recipe), damit es
 * für künftige Lager in der Rezeptsammlung bereitsteht.
 */
final class CookbookRecipeWriter {
	private const CATEGORIES = ['main' => 'Hauptgericht', 'soup' => 'Suppe und Eintopf', 'breakfast' => 'Frühstück',
		'dessert' => 'Nachtisch', 'side' => 'Beilage', 'campfire' => 'Lagerfeuer'];
	private const DIETS = ['vegan' => 'vegan', 'vegetarian' => 'vegetarisch', 'none' => ''];

	/** @return array<string, mixed> */
	public function write(Recipe $recipe, string $now): array {
		$keywords = array_filter(['Lagerplaner', self::DIETS[$recipe->diet->value] ?? '']);
		$json = [
			'@context' => 'http://schema.org',
			'@type' => 'Recipe',
			'name' => $recipe->title,
			'description' => $recipe->note,
			'recipeYield' => $recipe->servings,
			'recipeCategory' => self::CATEGORIES[$recipe->category] ?? 'Hauptgericht',
			'keywords' => implode(',', $keywords),
			'recipeIngredient' => array_map(static fn (Ingredient $i): string => self::line($i), $recipe->ingredients),
			'recipeInstructions' => $recipe->steps,
			'tool' => [],
			'dateCreated' => $now,
			'dateModified' => $now,
			'url' => $recipe->source['url'] ?? '',
		];
		if ($recipe->prepMinutes !== null) {
			$json['totalTime'] = 'PT' . intdiv($recipe->prepMinutes, 60) . 'H' . ($recipe->prepMinutes % 60) . 'M';
		}
		return $json;
	}

	/** „500 g Mehl, gesiebt“ – so, dass Cookbook und der Lagerplaner es wieder lesen können. */
	public static function line(Ingredient $ingredient): string {
		$note = $ingredient->note === '' ? '' : ', ' . $ingredient->note;
		if ($ingredient->unit === Unit::ToTaste || $ingredient->quantity === null) {
			return $ingredient->name . $note;
		}
		$quantity = rtrim(rtrim(number_format($ingredient->quantity, 3, ',', ''), '0'), ',');
		$unit = $ingredient->unit === Unit::Piece ? '' : ' ' . $ingredient->unit->value;
		return $quantity . $unit . ' ' . $ingredient->name . $note;
	}
}

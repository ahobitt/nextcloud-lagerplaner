<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Recipes;

use OCA\Lagerplaner\Domain\Recipes\Recipe;

/**
 * Mitgelieferte Grundrezepte (data/starter-recipes.json), frei verwendbar unter CC0 1.0.
 * Die Texte sind für den Lagerplaner geschrieben, nicht aus anderen Quellen übernommen.
 */
class StarterRecipes {
	private const FILE = __DIR__ . '/../../data/starter-recipes.json';

	/** @var array<string, Recipe>|null */
	private ?array $recipes = null;

	/** @return array<string, Recipe> Schlüssel => Rezept */
	public function all(): array {
		if ($this->recipes === null) {
			$data = json_decode((string)file_get_contents(self::FILE), true, 32, JSON_THROW_ON_ERROR);
			$this->recipes = [];
			foreach ($data['recipes'] as $entry) {
				$this->recipes[$entry['key']] = Recipe::fromArray($entry);
			}
		}
		return $this->recipes;
	}
}

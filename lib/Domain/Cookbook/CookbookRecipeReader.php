<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Cookbook;

use OCA\Lagerplaner\Domain\Recipes\Recipe;

/**
 * Übersetzt ein Cookbook-Rezept (schema.org/Recipe aus recipe.json) in ein Lagerrezept.
 *
 * Cookbook kennt keine Allergene und keine Ernährungsweise. Deshalb gilt: Allergene leer
 * und ungeprüft, Ernährungsweise nur aus eindeutigen Stichwörtern, sonst „mit Fleisch“, damit
 * nichts fälschlich als vegetarisch oder vegan zugesagt wird. Alles Unsichere steht in den
 * Hinweisen, damit die Küche es vor dem Einsatz prüft.
 */
final class CookbookRecipeReader {
	public const DEFAULT_SERVINGS = 4;

	public function __construct(private IngredientLineParser $parser = new IngredientLineParser()) {
	}

	/**
	 * @param array<string, mixed> $json Inhalt der recipe.json
	 * @return array{recipe: array<string, mixed>, review: list<string>, lines: list<array<string, mixed>>}
	 */
	public function read(array $json): array {
		$review = [];
		$title = self::text($json['name'] ?? '') ?: 'Rezept aus Cookbook';

		$servings = self::servings($json['recipeYield'] ?? null);
		if ($servings === null) {
			$servings = self::DEFAULT_SERVINGS;
			$review[] = 'In Cookbook fehlt die Portionenzahl; angenommen sind ' . self::DEFAULT_SERVINGS . ' Portionen.';
		}

		$lines = [];
		$ingredients = [];
		foreach (self::list($json['recipeIngredient'] ?? []) as $raw) {
			if (!is_string($raw) || trim(strip_tags($raw)) === '') {
				continue;
			}
			$line = $this->parser->parse($raw);
			$lines[] = $line;
			$ingredients[] = ['name' => $line['name'], 'quantity' => $line['quantity'], 'unit' => $line['unit'], 'note' => $line['note']];
		}
		if (count($ingredients) > Recipe::MAX_INGREDIENTS) {
			$review[] = 'Das Rezept hat ' . count($ingredients) . ' Zutaten; übernommen sind die ersten ' . Recipe::MAX_INGREDIENTS . '.';
			$ingredients = array_slice($ingredients, 0, Recipe::MAX_INGREDIENTS);
			$lines = array_slice($lines, 0, Recipe::MAX_INGREDIENTS);
		}
		$uncertain = count(array_filter($lines, static fn (array $l): bool => !$l['certain']));
		if ($uncertain > 0) {
			$review[] = $uncertain . ' Zutat(en) ließen sich nicht sicher lesen und sind markiert; bitte Menge und Einheit prüfen.';
		}
		if ($ingredients === []) {
			$review[] = 'In Cookbook sind keine Zutaten angegeben.';
		}

		$steps = [];
		foreach (self::steps($json['recipeInstructions'] ?? []) as $step) {
			$steps[] = mb_substr($step, 0, 1000);
		}
		if (count($steps) > Recipe::MAX_STEPS) {
			$review[] = 'Das Rezept hat ' . count($steps) . ' Arbeitsschritte; die übrigen sind im letzten zusammengefasst.';
			$steps = [...array_slice($steps, 0, Recipe::MAX_STEPS - 1), mb_substr(implode(' ', array_slice($steps, Recipe::MAX_STEPS - 1)), 0, 1000)];
		}
		if ($steps === []) {
			$steps = ['Zubereitung siehe Rezept in Cookbook.'];
		}

		$keywords = mb_strtolower(implode(' ', [self::text($json['keywords'] ?? ''), self::text($json['recipeCategory'] ?? ''), $title]));
		$diet = match (true) {
			preg_match('/\bvegan/u', $keywords) === 1 => 'vegan',
			preg_match('/\b(vegetarisch|vegetarian|veggie)/u', $keywords) === 1 => 'vegetarian',
			default => 'none',
		};
		$review[] = $diet === 'none'
			? 'Cookbook sagt nichts zur Ernährungsweise; eingetragen ist „mit Fleisch oder Fisch“. Bitte anpassen, falls das Rezept vegetarisch oder vegan ist.'
			: 'Die Ernährungsweise stammt aus den Stichwörtern in Cookbook; bitte prüfen.';
		$review[] = 'Cookbook kennt keine Allergene. Bitte Allergene angeben und die Angaben bestätigen, bevor das Rezept für eine Variante mit Allergen-Zusage genutzt wird.';

		$description = self::text($json['description'] ?? '');
		$url = is_string($json['url'] ?? null) ? trim($json['url']) : '';
		return [
			'recipe' => [
				'title' => mb_substr($title, 0, Recipe::MAX_TITLE),
				'servings' => $servings,
				'diet' => $diet,
				'contains' => [],
				'category' => self::category($keywords),
				'prepMinutes' => self::minutes($json['totalTime'] ?? $json['cookTime'] ?? $json['prepTime'] ?? null),
				'ingredients' => $ingredients,
				'steps' => $steps,
				'note' => mb_substr($description, 0, 2000),
				'source' => $url === '' ? null : ['name' => 'Rezeptvorlage aus Cookbook', 'url' => $url, 'license' => ''],
			],
			'review' => $review,
			'lines' => $lines,
		];
	}

	/** Portionenzahl aus „4“, 4, „4 Portionen“ oder [4]; nur eindeutige ganze Zahlen. */
	private static function servings(mixed $yield): ?int {
		if (is_array($yield)) {
			$yield = $yield[0] ?? null;
		}
		if (is_int($yield) || (is_string($yield) && preg_match('/^\D*(\d{1,3})\D*$/u', trim($yield), $m) === 1)) {
			$value = is_int($yield) ? $yield : (int)$m[1];
			return $value >= 1 && $value <= 500 ? $value : null;
		}
		return null;
	}

	/** @return list<string> Arbeitsschritte aus Texten, HowToStep und HowToSection */
	private static function steps(mixed $raw): array {
		if (is_string($raw)) {
			$raw = preg_split('/\R+/u', $raw) ?: [];
		}
		$steps = [];
		foreach (self::list($raw) as $entry) {
			if (is_string($entry)) {
				$text = self::text($entry);
			} elseif (is_array($entry) && isset($entry['itemListElement'])) {
				$steps = [...$steps, ...self::steps($entry['itemListElement'])];
				continue;
			} elseif (is_array($entry)) {
				$text = self::text($entry['text'] ?? $entry['name'] ?? '');
			} else {
				continue;
			}
			if ($text !== '') {
				$steps[] = $text;
			}
		}
		return $steps;
	}

	private static function category(string $text): string {
		return match (true) {
			preg_match('/frühstück|breakfast|müsli|porridge/u', $text) === 1 => 'breakfast',
			preg_match('/suppe|soup|eintopf|stew/u', $text) === 1 => 'soup',
			preg_match('/dessert|nachtisch|nachspeise|kuchen|süß/u', $text) === 1 => 'dessert',
			preg_match('/beilage|side|salat/u', $text) === 1 => 'side',
			preg_match('/lagerfeuer|stockbrot|feuer|dutch oven|grill/u', $text) === 1 => 'campfire',
			default => 'main',
		};
	}

	/** ISO-8601-Dauer wie „PT1H30M“ in Minuten. */
	private static function minutes(mixed $duration): ?int {
		if (!is_string($duration) || preg_match('/^P(?:\d+D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', $duration, $m) !== 1) {
			return null;
		}
		$minutes = (int)($m[1] ?? 0) * 60 + (int)($m[2] ?? 0);
		return $minutes >= 1 && $minutes <= 1440 ? $minutes : null;
	}

	/** @return list<mixed> */
	private static function list(mixed $value): array {
		return is_array($value) ? array_values($value) : [];
	}

	private static function text(mixed $value): string {
		if (is_array($value)) {
			$value = implode(', ', array_filter($value, 'is_string'));
		}
		return is_string($value) ? trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '', ENT_QUOTES | ENT_HTML5)) : '';
	}
}

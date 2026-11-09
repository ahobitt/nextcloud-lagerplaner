<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

use OCA\Lagerplaner\Domain\Catering\InvalidCateringInput;

/**
 * Ein Rezept für eine Anzahl Standardportionen (Portionsfaktor 1,0).
 * Ernährungsweise und Allergene gibt die Küche an; sie ersetzen keine Prüfung
 * der tatsächlich gekauften Produkte (Konzept 9.9).
 */
final class Recipe {
	public const CATEGORIES = ['main', 'soup', 'breakfast', 'dessert', 'side', 'campfire'];
	public const MAX_TITLE = 120;
	public const MAX_INGREDIENTS = 60;
	public const MAX_STEPS = 30;

	/**
	 * @param list<Ingredient> $ingredients
	 * @param list<string> $steps
	 * @param list<Allergen> $contains
	 * @param array{name: string, url: string, license: string}|null $source
	 */
	public function __construct(
		public readonly string $title,
		public readonly int $servings,
		public readonly array $ingredients,
		public readonly array $steps,
		public readonly Diet $diet,
		public readonly array $contains,
		public readonly string $category,
		public readonly ?int $prepMinutes,
		public readonly string $note,
		public readonly ?array $source,
	) {
	}

	public function containsAllergen(Allergen $allergen): bool {
		return in_array($allergen, $this->contains, true);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidCateringInput
	 */
	public static function fromArray(array $data): self {
		$errors = [];
		$title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
		if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
			$errors['title'] = 'Der Titel muss zwischen 1 und ' . self::MAX_TITLE . ' Zeichen lang sein.';
		}
		$servings = self::int($data['servings'] ?? null);
		if ($servings === null || $servings < 1 || $servings > 500) {
			$errors['servings'] = 'Bitte gib an, für wie viele Portionen das Rezept gedacht ist (1 bis 500).';
		}

		$ingredients = [];
		$rawIngredients = $data['ingredients'] ?? [];
		if (!is_array($rawIngredients) || !array_is_list($rawIngredients) || count($rawIngredients) > self::MAX_INGREDIENTS) {
			$errors['ingredients'] = 'Höchstens ' . self::MAX_INGREDIENTS . ' Zutaten als Liste.';
			$rawIngredients = [];
		}
		foreach ($rawIngredients as $i => $raw) {
			$path = "ingredients.$i";
			$name = is_array($raw) && is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
			$unit = is_array($raw) && is_string($raw['unit'] ?? null) ? Unit::tryFrom($raw['unit']) : null;
			if ($name === '' || mb_strlen($name) > 80) {
				$errors["$path.name"] = 'Die Zutat braucht einen Namen mit höchstens 80 Zeichen.';
				continue;
			}
			if ($unit === null) {
				$errors["$path.unit"] = 'Unbekannte Einheit.';
				continue;
			}
			$quantity = $raw['quantity'] ?? null;
			if (is_string($quantity)) {
				$quantity = trim(str_replace(',', '.', $quantity));
				$quantity = $quantity === '' ? null : (is_numeric($quantity) ? (float)$quantity : false);
			} elseif (is_int($quantity)) {
				$quantity = (float)$quantity;
			}
			if ($unit === Unit::ToTaste) {
				$quantity = null;
			} elseif (!is_float($quantity) || $quantity <= 0 || $quantity > 100000) {
				$errors["$path.quantity"] = 'Bitte gib eine Menge größer als 0 an oder wähle „nach Geschmack“.';
				continue;
			}
			$note = is_string($raw['note'] ?? null) ? trim($raw['note']) : '';
			if (mb_strlen($note) > 80) {
				$errors["$path.note"] = 'Die Anmerkung darf höchstens 80 Zeichen lang sein.';
				continue;
			}
			$ingredients[] = new Ingredient($name, $quantity, $unit, $note);
		}

		$steps = [];
		$rawSteps = $data['steps'] ?? [];
		if (!is_array($rawSteps) || !array_is_list($rawSteps) || count($rawSteps) > self::MAX_STEPS) {
			$errors['steps'] = 'Höchstens ' . self::MAX_STEPS . ' Arbeitsschritte.';
			$rawSteps = [];
		}
		foreach ($rawSteps as $i => $step) {
			$step = is_string($step) ? trim($step) : '';
			if (mb_strlen($step) > 1000) {
				$errors["steps.$i"] = 'Ein Arbeitsschritt darf höchstens 1000 Zeichen lang sein.';
			} elseif ($step !== '') {
				$steps[] = $step;
			}
		}

		$diet = is_string($data['diet'] ?? null) ? Diet::tryFrom($data['diet']) : null;
		if ($diet === null) {
			$errors['diet'] = 'Bitte gib an, ob das Rezept vegan, vegetarisch oder mit Fleisch oder Fisch ist.';
		}
		$contains = [];
		foreach (is_array($data['contains'] ?? null) ? $data['contains'] : [] as $value) {
			$allergen = is_string($value) ? Allergen::tryFrom($value) : null;
			if ($allergen === null) {
				$errors['contains'] = 'Unbekanntes Allergen.';
				break;
			}
			$contains[$allergen->value] = $allergen;
		}
		$category = $data['category'] ?? 'main';
		if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
			$errors['category'] = 'Unbekannte Kategorie.';
		}
		$prep = $data['prepMinutes'] ?? null;
		$prep = $prep === null || $prep === '' ? null : self::int($prep);
		if ($prep !== null && ($prep < 1 || $prep > 1440)) {
			$errors['prepMinutes'] = 'Die Zubereitungszeit muss zwischen 1 und 1440 Minuten liegen.';
		}
		$note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
		if (mb_strlen($note) > 2000) {
			$errors['note'] = 'Der Hinweis darf höchstens 2000 Zeichen lang sein.';
		}
		$source = null;
		if (is_array($data['source'] ?? null)) {
			$source = [
				'name' => mb_substr(trim((string)($data['source']['name'] ?? '')), 0, 200),
				'url' => self::safeUrl($data['source']['url'] ?? ''),
				'license' => mb_substr(trim((string)($data['source']['license'] ?? '')), 0, 100),
			];
		}

		if ($errors !== []) {
			throw new InvalidCateringInput($errors);
		}
		/** @var Diet $diet */
		return new self($title, (int)$servings, $ingredients, $steps, $diet, array_values($contains), (string)$category, $prep, $note, $source);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'title' => $this->title,
			'servings' => $this->servings,
			'ingredients' => array_map(static fn (Ingredient $i): array => $i->toArray(), $this->ingredients),
			'steps' => $this->steps,
			'diet' => $this->diet->value,
			'contains' => array_map(static fn (Allergen $a): string => $a->value, $this->contains),
			'category' => $this->category,
			'prepMinutes' => $this->prepMinutes,
			'note' => $this->note,
			'source' => $this->source,
		];
	}

	private static function int(mixed $value): ?int {
		if (is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1) {
			return (int)trim($value);
		}
		return is_int($value) ? $value : null;
	}

	/** Nur http(s)-Links, damit keine javascript:-Adressen gespeichert werden. */
	private static function safeUrl(mixed $url): string {
		$url = is_string($url) ? trim($url) : '';
		return preg_match('#^https?://[^\s]{1,500}$#i', $url) === 1 ? $url : '';
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/**
 * Geprüfte Eingabe der aggregierten Ernährungsangaben eines Lagers (Konzept 8.5, 9.10).
 * Enthält nur Anzahlen, nie Personenbezug.
 */
final class DietaryNeedsInput {
	public const MAX_COMBINATIONS = 40;
	public const MAX_COUNT = 5000;

	private function __construct(
		public readonly DietaryProfile $profile,
		public readonly SevereAllergies $severe,
	) {
	}

	/**
	 * @param array<string, mixed> $data {combinations: list<{key, count}>, severe: array<string, int>}
	 * @throws InvalidCateringInput
	 */
	public static function fromArray(array $data): self {
		$errors = [];
		$combinations = [];
		$rawCombinations = $data['combinations'] ?? [];
		if (!is_array($rawCombinations) || !array_is_list($rawCombinations)) {
			throw new InvalidCateringInput(['combinations' => 'Die Bedarfskombinationen müssen als Liste übergeben werden.']);
		}
		if (count($rawCombinations) > self::MAX_COMBINATIONS) {
			throw new InvalidCateringInput(['combinations' => 'Es sind höchstens ' . self::MAX_COMBINATIONS . ' Bedarfskombinationen möglich.']);
		}

		$total = 0;
		foreach ($rawCombinations as $i => $entry) {
			$path = "combinations.$i";
			if (!is_array($entry) || !is_string($entry['key'] ?? null)) {
				$errors[$path] = 'Ungültige Bedarfskombination.';
				continue;
			}
			$count = self::count($entry['count'] ?? null);
			if ($count === null) {
				$errors["$path.count"] = 'Bitte gib eine ganze Zahl zwischen 0 und ' . self::MAX_COUNT . ' an.';
				continue;
			}
			try {
				$combination = DietaryCombination::fromKey($entry['key'], $count);
			} catch (\InvalidArgumentException) {
				$errors["$path.key"] = 'Unbekannte Anforderung in der Bedarfskombination.';
				continue;
			}
			if (isset($combinations[$combination->key])) {
				$errors["$path.key"] = '„' . $combination->label() . '“ ist doppelt angegeben.';
				continue;
			}
			if ($count > 0) {
				$combinations[$combination->key] = $combination;
				$total += $count;
			}
		}
		if ($total > self::MAX_COUNT) {
			$errors['combinations'] = 'Insgesamt sind höchstens ' . self::MAX_COUNT . ' Personen möglich.';
		}

		$severe = [];
		$rawSevere = $data['severe'] ?? [];
		if (!is_array($rawSevere)) {
			$errors['severe'] = 'Ungültige Angaben zu schweren Allergien.';
			$rawSevere = [];
		}
		foreach ($rawSevere as $allergen => $value) {
			if (!isset(SevereAllergies::ALLERGENS[$allergen])) {
				$errors["severe.$allergen"] = 'Unbekanntes Allergen.';
				continue;
			}
			$count = self::count($value);
			if ($count === null) {
				$errors["severe.$allergen"] = 'Bitte gib eine ganze Zahl zwischen 0 und ' . self::MAX_COUNT . ' an.';
				continue;
			}
			if ($count > 0) {
				$severe[$allergen] = $count;
			}
		}

		if ($errors !== []) {
			throw new InvalidCateringInput($errors);
		}
		return new self(new DietaryProfile(array_values($combinations)), new SevereAllergies($severe));
	}

	private static function count(mixed $value): ?int {
		if (is_string($value) && preg_match('/^\d{1,5}$/', $value) === 1) {
			$value = (int)$value;
		}
		return is_int($value) && $value >= 0 && $value <= self::MAX_COUNT ? $value : null;
	}
}

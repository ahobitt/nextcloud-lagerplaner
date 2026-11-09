<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

use OCA\Lagerplaner\Domain\Recipes\Unit;

/**
 * Fasst den Zutatenbedarf aller Mahlzeiten zu Einkaufslisten je Einkaufstermin zusammen
 * (Konzept 10.1 bis 10.3).
 *
 * Produktidentität: Zusammengelegt wird nur, was sicher dasselbe Produkt ist: gleicher
 * vollständiger Name (einschließlich Klammerzusätzen), gleicher Zutatenhinweis, gleiche
 * Allergen-Zusagen der Menüvariante und gleiche Einheitenfamilie. „Nudeln“ für eine
 * glutenfreie Variante und „Nudeln“ für alle anderen bleiben getrennte Positionen.
 * Der technische Schlüssel ist ein Hash dieser Eigenschaften: kurz und ohne
 * Verwechslung durch Trennzeichen in Namen oder Hinweisen.
 *
 * Mengen: Gewichte und Volumen werden in Gramm und Milliliter summiert, Zählbares je
 * Einheit; gerundet wird erst am Ende und nur nach oben. Andere Umrechnungen gibt es nicht.
 *
 * Termine: Haltbares kommt in den ersten Einkauf, Frisches in den letzten Einkauf vor dem
 * Bedarfstag. Liegt kein Einkauf vor dem Bedarfstag, wird die Position als zu spät markiert.
 */
final class ShoppingCalculator {
	/**
	 * @param list<Demand> $demands
	 * @param list<string> $tripDates Datum je Einkaufstermin (JJJJ-MM-TT), mindestens einer
	 * @return array<int, list<ShoppingLine>> Index des Einkaufstermins (wie übergeben) => Positionen
	 */
	public function calculate(array $demands, array $tripDates): array {
		if ($tripDates === []) {
			throw new \InvalidArgumentException('Es braucht mindestens einen Einkaufstermin.');
		}
		$order = $tripDates;
		asort($order);
		$sorted = array_keys($order);

		/** @var array<int, array<string, array<string, mixed>>> $buckets */
		$buckets = [];
		$familiesByName = [];
		foreach ($demands as $demand) {
			$factor = $demand->portions / $demand->recipe->servings;
			$promises = $demand->promises;
			sort($promises);
			foreach ($demand->recipe->ingredients as $ingredient) {
				$name = self::normalize($ingredient->name);
				// Leitungswasser wird nicht eingekauft.
				if ($name === 'wasser' || str_starts_with($name, 'wasser,')) {
					continue;
				}
				[$family, $amount] = self::base($ingredient->unit, $ingredient->quantity === null ? null : $ingredient->quantity * $factor);
				$category = GroceryCategory::classify($ingredient->name, $ingredient->unit);
				$trip = $this->trip($sorted, $tripDates, $demand->day, $category->isShelfStable());
				$key = self::key($name, self::normalize($ingredient->note), $promises, $family);
				$familiesByName[$name . '|' . implode('+', $promises)][$family] = true;

				$line = $buckets[$trip][$key] ?? [
					'key' => $key, 'name' => $ingredient->name, 'note' => $ingredient->note, 'family' => $family, 'amount' => 0.0,
					'category' => $category, 'days' => [], 'promises' => $promises, 'late' => false, 'identity' => $name . '|' . implode('+', $promises),
				];
				$line['amount'] += $amount ?? 0.0;
				$line['days'][$demand->day] = true;
				$line['late'] = $line['late'] || $demand->day < $tripDates[$trip];
				$buckets[$trip][$key] = $line;
			}
		}

		$result = [];
		foreach (array_keys($tripDates) as $trip) {
			$lines = [];
			foreach ($buckets[$trip] ?? [] as $line) {
				$days = array_keys($line['days']);
				sort($days);
				$lines[] = new ShoppingLine(
					$line['key'], $line['name'], $line['family'], $line['amount'], $line['category'],
					$line['note'] === '' ? [] : [$line['note']], $days, count($familiesByName[$line['identity']]) > 1,
					$line['promises'], $line['late'],
				);
			}
			usort($lines, static fn (ShoppingLine $a, ShoppingLine $b): int => [$a->category->order(), self::sortName($a->name), implode('+', $a->promises)] <=> [$b->category->order(), self::sortName($b->name), implode('+', $b->promises)]);
			$result[$trip] = $lines;
		}
		return $result;
	}

	/** Alphabetisch wie im Telefonbuch: „Äpfel“ steht bei A, nicht hinter Z. */
	private static function sortName(string $name): string {
		return strtr(mb_strtolower($name), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
	}

	/**
	 * Technischer Positionsschlüssel: 40 Zeichen, eindeutig kodiert.
	 *
	 * @param list<string> $promises
	 */
	public static function key(string $normalizedName, string $normalizedNote, array $promises, string $family): string {
		sort($promises);
		return sha1(json_encode([$normalizedName, $normalizedNote, array_values($promises), $family], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}

	/** Kleinschreibung und einfache Leerzeichen; Klammerzusätze bleiben Teil des Namens. */
	public static function normalize(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? $text);
	}

	/**
	 * Einheitenfamilie und Menge in der Basiseinheit.
	 *
	 * @return array{0: string, 1: ?float}
	 */
	public static function base(Unit $unit, ?float $quantity): array {
		return match ($unit) {
			Unit::Gram => ['g', $quantity],
			Unit::Kilogram => ['g', $quantity === null ? null : $quantity * 1000],
			Unit::Millilitre => ['ml', $quantity],
			Unit::Litre => ['ml', $quantity === null ? null : $quantity * 1000],
			Unit::ToTaste => ['taste', null],
			default => [$unit->value, $quantity],
		};
	}

	/**
	 * @param list<int> $sorted Indizes der Termine nach Datum
	 * @param list<string> $dates
	 */
	private function trip(array $sorted, array $dates, string $day, bool $shelfStable): int {
		if ($shelfStable) {
			return $sorted[0];
		}
		$chosen = $sorted[0];
		foreach ($sorted as $index) {
			if ($dates[$index] <= $day) {
				$chosen = $index;
			}
		}
		return $chosen;
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

use OCA\Lagerplaner\Domain\Catering\Requirement;

/** Eine Position der Einkaufsliste. Die Menge ist exakt in der Basiseinheit. */
final class ShoppingLine {
	/**
	 * @param list<string> $notes
	 * @param list<string> $days
	 * @param list<string> $promises Allergen-Zusagen der Variante (Requirement-Werte)
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $name,
		/** g, ml, taste oder eine zählbare Einheit wie Stück, Dose, EL */
		public readonly string $family,
		public readonly float $amount,
		public readonly GroceryCategory $category,
		public readonly array $notes,
		public readonly array $days,
		/** Dieselbe Zutat kommt in unvereinbaren Einheiten vor und muss geprüft werden. */
		public readonly bool $needsReview,
		public readonly array $promises = [],
		/** Mindestens ein Bedarfstag liegt vor dem Einkaufstermin. */
		public readonly bool $late = false,
	) {
	}

	public function hasQuantity(): bool {
		return $this->family !== 'taste';
	}

	/**
	 * Einkaufbare Menge: nach oben gerundet, Gramm und Milliliter ab 1000 in kg und l.
	 *
	 * @return array{quantity: ?float, unit: string}
	 */
	public static function buyable(string $family, float $amount): array {
		return match (true) {
			$family === 'taste' => ['quantity' => null, 'unit' => 'nach Geschmack'],
			$family === 'g' && $amount >= 1000 => ['quantity' => ceil(round($amount, 6) / 100) / 10, 'unit' => 'kg'],
			$family === 'g' => ['quantity' => max(10.0, ceil(round($amount, 6) / 10) * 10), 'unit' => 'g'],
			$family === 'ml' && $amount >= 1000 => ['quantity' => ceil(round($amount, 6) / 100) / 10, 'unit' => 'l'],
			$family === 'ml' => ['quantity' => max(10.0, ceil(round($amount, 6) / 10) * 10), 'unit' => 'ml'],
			in_array($family, ['EL', 'TL', 'Prise'], true) => ['quantity' => max(0.5, ceil(round($amount, 6) * 2) / 2), 'unit' => $family],
			default => ['quantity' => max(1.0, ceil(round($amount, 6) - 0.01)), 'unit' => $family],
		};
	}

	/**
	 * Menge in einer angegebenen Einheit in die Basiseinheit der Familie umrechnen.
	 * Nur sichere Umrechnungen (kg/g, l/ml, gleiche Einheit).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function toBase(string $family, float $quantity, string $unit): float {
		$factor = match (true) {
			$family === 'g' && $unit === 'g', $family === 'ml' && $unit === 'ml', $unit === $family => 1.0,
			$family === 'g' && $unit === 'kg', $family === 'ml' && $unit === 'l' => 1000.0,
			default => throw new \InvalidArgumentException('Diese Einheit passt nicht zur Position.'),
		};
		return $quantity * $factor;
	}

	/**
	 * Große Löffelmengen zusätzlich als ungefähres Volumen (1 EL = 15 ml, 1 TL = 5 ml),
	 * damit „143 EL Öl“ beim Einkauf verständlich wird. Ab 250 ml.
	 *
	 * @return array{quantity: ?float, unit: string}|null
	 */
	public function approximateVolume(): ?array {
		$millilitres = match ($this->family) {
			'EL' => $this->amount * 15,
			'TL' => $this->amount * 5,
			default => 0.0,
		};
		return $millilitres >= 250 ? self::buyable('ml', $millilitres) : null;
	}

	/** @return list<string> lesbare Zusagen, zum Beispiel „glutenfrei“ */
	public function promiseLabels(): array {
		return array_values(array_filter(array_map(static fn (string $p): ?string => Requirement::tryFrom($p)?->label(), $this->promises)));
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'key' => $this->key,
			'name' => $this->name,
			'category' => $this->category->value,
			'categoryLabel' => $this->category->label(),
			'notes' => $this->notes,
			'days' => $this->days,
			'needsReview' => $this->needsReview,
			'promises' => $this->promiseLabels(),
			'late' => $this->late,
			'approx' => $this->approximateVolume(),
		] + self::buyable($this->family, $this->amount);
	}
}

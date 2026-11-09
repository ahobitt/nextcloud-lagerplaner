<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Recipes;

final class Ingredient {
	public function __construct(
		public readonly string $name,
		/** null bei „nach Geschmack“ */
		public readonly ?float $quantity,
		public readonly Unit $unit,
		public readonly string $note = '',
	) {
	}

	/** @return array{name: string, quantity: ?float, unit: string, note: string} */
	public function toArray(): array {
		return ['name' => $this->name, 'quantity' => $this->quantity, 'unit' => $this->unit->value, 'note' => $this->note];
	}
}

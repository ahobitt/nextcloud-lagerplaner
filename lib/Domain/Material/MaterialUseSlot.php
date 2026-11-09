<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Material;

/** Ein Einsatz mit Menge und, bei Programmpunkten, seinem Zeitraum. */
final class MaterialUseSlot {
	public function __construct(
		/** null: Menge noch offen */
		public readonly ?float $quantity,
		/** null: für das ganze Lager, ohne Zeitraum */
		public readonly ?string $day = null,
		public readonly int $start = 0,
		public readonly int $end = 0,
	) {
	}
}

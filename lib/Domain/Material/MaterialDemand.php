<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Material;

/**
 * Gesamtbedarf eines Materials (Konzept 11.3).
 *
 * - Verbrauchsmaterial (Papier, Kreide) wird über alle Einsätze addiert.
 * - Wiederverwendbares (Seile, Bälle) wird nur so oft gebraucht, wie es gleichzeitig im
 *   Einsatz ist: höchste Summe der Mengen zeitlich überlappender Programmpunkte. Einsätze
 *   für das ganze Lager kommen immer hinzu.
 * - Einsätze ohne Menge werden gezählt, damit die Oberfläche „Menge offen“ zeigen kann.
 */
final class MaterialDemand {
	/**
	 * @param list<MaterialUseSlot> $uses
	 * @return array{total: float, openQuantities: int}
	 */
	public function aggregate(array $uses, bool $reusable): array {
		$open = 0;
		$campWide = 0.0;
		$events = [];
		$sum = 0.0;
		foreach ($uses as $use) {
			if ($use->quantity === null) {
				$open++;
				continue;
			}
			$sum += $use->quantity;
			if ($use->day === null) {
				$campWide += $use->quantity;
				continue;
			}
			// Am selben Zeitpunkt endet ein Einsatz, bevor der nächste beginnt: Hintereinander ist nicht gleichzeitig.
			$events[] = [$use->day, $use->start, 1, $use->quantity];
			$events[] = [$use->day, $use->end, 0, -$use->quantity];
		}
		if (!$reusable) {
			return ['total' => $sum, 'openQuantities' => $open];
		}
		usort($events, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
		$current = 0.0;
		$peak = 0.0;
		foreach ($events as [, , , $delta]) {
			$current += $delta;
			$peak = max($peak, $current);
		}
		return ['total' => $campWide + $peak, 'openQuantities' => $open];
	}
}

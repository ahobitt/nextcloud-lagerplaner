<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

/**
 * Teilt Vorräte und gekaufte Mengen den Positionen zu (Konzept 10.4).
 *
 * - Vorräte sind von Anfang an da und decken die Positionen in zeitlicher Reihenfolge.
 * - Gekauftes deckt zuerst die Position seines eigenen Einkaufstermins.
 * - Ein Überschuss (etwa nachdem eine Position auf einen anderen Termin gewandert ist)
 *   wird nur auf spätere Termine übertragen: Was am Donnerstag gekauft wird, hilft
 *   nicht für Montag. Jede Übertragung ist an der Position sichtbar.
 * - Als gedeckt gilt nur, was tatsächlich gedeckt ist; toleriert werden allein
 *   Rechenungenauigkeiten der Gleitkommazahlen (EPSILON), keine prozentualen Reste.
 */
final class CoverageAllocator {
	/** Basiseinheiten (Gramm, Milliliter, Stück); deutlich unter jeder einkaufbaren Menge. */
	public const EPSILON = 0.000001;

	/**
	 * @param array<int, list<ShoppingLine>> $lists Terminindex => Positionen
	 * @param list<int> $order Terminindizes nach Datum
	 * @param array<string, float> $stock Positionsschlüssel => vorhandene Menge
	 * @param array<string, array<int, float>> $purchases Positionsschlüssel => [Terminindex => gekaufte Menge]
	 * @return array<int, array<string, Coverage>>
	 */
	public function allocate(array $lists, array $order, array $stock, array $purchases): array {
		$keys = [];
		foreach ($lists as $lines) {
			foreach ($lines as $line) {
				$keys[$line->key] = true;
			}
		}

		$result = [];
		foreach (array_keys($keys) as $key) {
			$stockLeft = max(0.0, $stock[$key] ?? 0.0);
			$carry = 0.0;
			foreach ($order as $trip) {
				$own = max(0.0, $purchases[$key][$trip] ?? 0.0);
				$line = null;
				foreach ($lists[$trip] ?? [] as $candidate) {
					$line ??= $candidate->key === $key ? $candidate : null;
				}
				if ($line === null) {
					// Hier gekauft, aber hier nicht (mehr) gebraucht: für spätere Termine verfügbar.
					$carry += $own;
					continue;
				}
				$here = isset($purchases[$key][$trip]);
				if (!$line->hasQuantity()) {
					$covered = $here || ($stock[$key] ?? 0.0) > 0 || $carry > 0;
					$result[$trip][$key] = new Coverage(0.0, 0.0, 0.0, $covered ? 0.0 : 1.0, $here);
					continue;
				}
				$rest = $line->amount;
				$fromStock = min($rest, $stockLeft);
				$stockLeft -= $fromStock;
				$rest -= $fromStock;
				$fromOwn = min($rest, $own);
				$rest -= $fromOwn;
				$fromCarry = min($rest, $carry);
				$carry -= $fromCarry;
				$rest -= $fromCarry;
				$carry += $own - $fromOwn;
				$result[$trip][$key] = new Coverage($fromStock, $fromOwn, $fromCarry, $rest <= self::EPSILON ? 0.0 : $rest, $here);
			}
		}
		return $result;
	}
}

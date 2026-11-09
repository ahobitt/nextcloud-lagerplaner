<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Shopping;

/** Wie viel einer Position gedeckt ist (Basiseinheit) und woher. */
final class Coverage {
	public function __construct(
		public readonly float $fromStock,
		/** bei diesem Einkaufstermin gekauft */
		public readonly float $fromOwnPurchase,
		/** Überschuss früherer Einkäufe, der hierher übertragen wurde */
		public readonly float $fromEarlierPurchases,
		public readonly float $open,
		/** Bei diesem Einkaufstermin wurde etwas abgehakt. */
		public readonly bool $purchasedHere,
	) {
	}

	public function isDone(): bool {
		return $this->open <= 0.0;
	}

	public function covered(): float {
		return $this->fromStock + $this->fromOwnPurchase + $this->fromEarlierPurchases;
	}
}

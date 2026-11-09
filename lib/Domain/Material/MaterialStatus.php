<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Material;

/** Materialstatus nach Konzept 11.2, in der Reihenfolge des Ablaufs. */
enum MaterialStatus: string {
	case Needed = 'needed';
	case Available = 'available';
	case Packed = 'packed';
	case Transported = 'transported';
	case Returned = 'returned';
	case Missing = 'missing';

	public function label(): string {
		return match ($this) {
			self::Needed => 'Benötigt',
			self::Available => 'Vorhanden',
			self::Packed => 'Gepackt',
			self::Transported => 'Transportiert',
			self::Returned => 'Zurückgebracht',
			self::Missing => 'Fehlt oder beschädigt',
		};
	}
}

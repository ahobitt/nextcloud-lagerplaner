<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

enum SiteType: string {
	case Tent = 'tent';
	case House = 'house';
	case Both = 'both';

	public function label(): string {
		return match ($this) {
			self::Tent => 'Zeltplatz',
			self::House => 'Gruppenhaus',
			self::Both => 'Zeltplatz und Haus',
		};
	}

	/** Ein Platz mit Haus und Zeltwiese passt zu beiden Suchen. */
	public function offers(self $wanted): bool {
		return $this === $wanted || $this === self::Both;
	}
}

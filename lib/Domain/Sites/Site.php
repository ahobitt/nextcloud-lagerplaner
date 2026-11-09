<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/** Ein Zeltplatz oder Gruppenhaus im internen Datenmodell (Konzept 5.5). */
final class Site {
	public function __construct(
		public readonly string $sourceId,
		public readonly string $name,
		public readonly SiteType $type,
		public readonly ?string $street,
		public readonly ?string $postalCode,
		public readonly ?string $city,
		public readonly ?string $state,
		/** ISO-3166-Ländercode oder null, wenn die Quelle keinen nennt */
		public readonly ?string $country,
		public readonly ?float $lat,
		public readonly ?float $lon,
		public readonly ?int $capacity,
		public readonly ?int $beds,
		public readonly SiteFeatures $features,
		/** Preisangabe der Quelle als Text, nie als verbindlicher Filterwert */
		public readonly ?string $priceText,
		public readonly ?string $description,
		public readonly ?string $homepage,
		public readonly ?string $sourceUrl,
		/** Stand laut Quelle (JJJJ-MM-TT) */
		public readonly ?string $sourceDate,
	) {
	}
}

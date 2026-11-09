<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Einzelne Angaben eines Lagerplatzes, deren Herkunft getrennt festgehalten werden kann
 * (lp_site_facts). Stammdaten liefert die Quelle des Platzes; Zusatzangaben können später
 * von der Website des Platzes oder von Hand ergänzt werden.
 */
enum SiteAttribute: string {
	// Stammdaten
	case Name = 'name';
	case Type = 'type';
	case Location = 'location';
	case Website = 'website';
	/** Betriebsstatus mit Wirksamkeit */
	case Status = 'status';
	// Zusatzangaben
	case Address = 'address';
	case Capacity = 'capacity';
	case Beds = 'beds';
	case Water = 'water';
	case Sanitary = 'sanitary';
	case Kitchen = 'kitchen';
	case Electricity = 'electricity';
	case Fireplace = 'fireplace';
	case Accessible = 'accessible';
	case Price = 'price';

	/** Stammdaten, wie eine Campsitemap sie führt; Änderungen daran sind „geänderte Stammdaten“. */
	public function isBaseData(): bool {
		return in_array($this, [self::Name, self::Type, self::Location, self::Website, self::Status], true);
	}

	/**
	 * Wert einer Angabe zum Vergleichen (Betriebsstatus steht nicht im Site-Objekt: null).
	 */
	public function valueOf(Site $site): mixed {
		return match ($this) {
			self::Name => $site->name,
			self::Type => $site->type->value,
			self::Location => $site->lat === null ? null : [$site->lat, $site->lon],
			self::Website => $site->homepage,
			self::Address => array_filter(['street' => $site->street, 'postalCode' => $site->postalCode, 'city' => $site->city, 'state' => $site->state, 'country' => $site->country], static fn ($v) => $v !== null) ?: null,
			self::Capacity => $site->capacity,
			self::Beds => $site->beds,
			self::Price => $site->priceText,
			self::Status => null,
			default => $site->features->get($this->value),
		};
	}

	/** @return list<self> */
	public static function features(): array {
		return [self::Water, self::Sanitary, self::Kitchen, self::Electricity, self::Fireplace, self::Accessible];
	}
}

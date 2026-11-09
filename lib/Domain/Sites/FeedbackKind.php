<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/** Arten von Rückmeldungen („Feedback / Angaben ergänzen“) und ihre vorschlagbaren Werte. */
enum FeedbackKind: string {
	/** Angaben stimmen nicht mehr (Freitext) */
	case Outdated = 'outdated';
	/** Website hat sich geändert */
	case Website = 'website';
	/** Ausstattung fehlt oder stimmt nicht */
	case Equipment = 'equipment';
	/** Kapazität hat sich geändert */
	case Capacity = 'capacity';
	/** Platz ist geschlossen, dauerhaft oder vorübergehend */
	case Closed = 'closed';
	/** Ein Lagerplatz fehlt im Verzeichnis */
	case NewSite = 'new_site';
	/** Name ist falsch */
	case Name = 'name';
	/** Koordinaten sind falsch */
	case Location = 'location';
	/** Postleitzahl oder Ort stimmen nicht */
	case Address = 'address';
	/** Betreiber hat gewechselt */
	case Operator = 'operator';

	/**
	 * Betrifft die Rückmeldung die Stammdaten der Quelle (Index der Campsitemap)? Dann kann sie
	 * nach der Prüfung weitergegeben werden. Ausstattung, Kapazität, Ort sind Lagerplaner-Sache.
	 */
	public function upstreamRelevant(): bool {
		return in_array($this, [self::Website, self::Closed, self::NewSite, self::Name, self::Location, self::Operator], true);
	}

	/** @return list<string> erlaubte Werte */
	public function attributes(): array {
		return match ($this) {
			self::Outdated => [],
			self::Website => ['homepage'],
			self::Equipment => SiteFeatures::KEYS,
			self::Capacity => ['capacity', 'beds'],
			self::Closed => ['since', 'until'],
			self::NewSite => ['name', 'type', 'location', 'homepage'],
			self::Name => ['name'],
			self::Location => ['location'],
			self::Address => ['postalCode', 'city'],
			self::Operator => ['operator', 'homepage'],
		};
	}

	public function needsSite(): bool {
		return $this !== self::NewSite;
	}
}

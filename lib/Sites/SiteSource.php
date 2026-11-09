<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Sites;

/**
 * Eine Datenquelle des Lagerplatzverzeichnisses (Port). Sie liefert ihren aktuellen Stand im
 * internen Austauschformat; abgleichen und speichern übernimmt der SiteSynchronizer.
 * Weitere Quellen (OpenStreetMap, nationale Pfadfinderdatenbanken) kommen als weitere
 * Umsetzungen dazu, ohne Suche oder Oberfläche zu ändern.
 */
interface SiteSource {
	/** Technischer Schlüssel, gespeichert in lp_sites.source, etwa „schwarzzelt_it“ */
	public function key(): string;

	/** Name für die Quellenangabe, etwa „Schwarzzelt IT Campsitemap“ */
	public function name(): string;

	/** Link für die Quellenangabe (Projekt oder Karte) */
	public function url(): string;

	public function license(): ?string;

	/**
	 * Wohin Korrekturen und neue Plätze zurückgemeldet werden können, etwa ein Issue-Formular.
	 *
	 * @return array{issues?: string, mail?: string}
	 */
	public function feedback(): array;

	/**
	 * Aktueller Stand aus dem Netz.
	 *
	 * @throws SourceUnavailable wenn die Quelle nicht erreichbar oder unlesbar ist
	 */
	public function fetch(): SourceSnapshot;

	/** Mit der App mitgelieferter Stand, für Installationen ohne Netzzugang; null, wenn keiner */
	public function bundled(): ?SourceSnapshot;

	/**
	 * Angaben, für die die Quelle zuständig ist; alle anderen schreibt der Abgleich bei
	 * vorhandenen Plätzen nicht. null: alle (etwa eine eigene Liste).
	 *
	 * @return list<\OCA\Lagerplaner\Domain\Sites\SiteAttribute>|null
	 */
	public function attributes(): ?array;

	/** Ein Platz im Format der Quelle, für eine Rückmeldung von Hand (etwa eine CSV-Zeile) */
	public function describe(\OCA\Lagerplaner\Domain\Sites\Site $site): string;
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

/**
 * Nextclouds Entity speichert beim Einfügen nur Felder, die als geändert markiert sind. Ein
 * Setter mit demselben Wert wie der Vorgabewert der Eigenschaft markiert nichts; ohne
 * Datenbank-Vorgabewert fehlt das Feld dann im INSERT (NOT-NULL-Fehler, auf MariaDB im
 * nachsichtigen Modus ein leerer Wert). Deshalb gelten Vorgabewerte einer neuen Entität als
 * geändert. Aus der Datenbank geladene Entitäten setzt fromRow() danach zurück.
 */
trait PersistsDefaults {
	protected function persistDefaults(): void {
		foreach (get_object_vars($this) as $name => $value) {
			if ($value !== null && $name !== 'id' && !str_starts_with($name, '_')) {
				$this->markFieldUpdated($name);
			}
		}
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Sites;

/** Ein Stand einer Datenquelle im internen Austauschformat (siehe docs/site-import.md). */
final class SourceSnapshot {
	/**
	 * @param string $version Kennung des Stands, etwa ein Prüfwert des Inhalts
	 * @param list<array<string, mixed>> $entries Einträge im Austauschformat
	 * @param list<string> $skipped Gründe für übersprungene Zeilen der Quelle
	 */
	public function __construct(
		public readonly string $version,
		public readonly array $entries,
		public readonly array $skipped = [],
	) {
	}
}

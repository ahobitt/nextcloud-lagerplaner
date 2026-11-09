<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Sites;

/**
 * Liest die Campsitemap von Schwarzzelt IT (sites.csv) in das interne Austauschformat, das
 * SiteNormalizer versteht. Die Spaltennamen der CSV bleiben hier; das interne Modell kennt sie
 * nicht.
 *
 * Spalten: name, category, latitude, longitude, url; optional id. Kategorien: campsite →
 * Zeltplatz, house → Haus, „campsite house“ → beides. Ohne id-Spalte entsteht eine technische
 * Kennung aus dem Namen (sz-…); bringt die CSV später eine eigene id mit, gilt diese.
 */
final class SchwarzzeltCsv {
	public const CATEGORIES = ['campsite' => 'tent', 'house' => 'house', 'campsite house' => 'both', 'house campsite' => 'both'];
	public const REQUIRED = ['name', 'category', 'latitude', 'longitude'];

	/**
	 * @return array{entries: list<array<string, mixed>>, skipped: list<string>}
	 * @throws InvalidSiteData wenn die Datei keine CSV der Campsitemap ist
	 */
	public function parse(string $csv): array {
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
		if ($lines === [] || trim($lines[0]) === '') {
			throw new InvalidSiteData('Die Datei ist leer.');
		}
		$header = array_map(static fn (string $h): string => strtolower(trim($h)), str_getcsv($lines[0], ',', '"', '\\'));
		$missing = array_diff(self::REQUIRED, $header);
		if ($missing !== []) {
			throw new InvalidSiteData('Es fehlen Spalten: ' . implode(', ', $missing) . '.');
		}
		$entries = [];
		$skipped = [];
		$ids = [];
		foreach (array_slice($lines, 1) as $index => $line) {
			if (trim($line) === '') {
				continue;
			}
			$values = str_getcsv($line, ',', '"', '\\');
			$row = [];
			foreach ($header as $i => $key) {
				$row[$key] = trim((string)($values[$i] ?? ''));
			}
			$lineNo = $index + 2;
			$name = $row['name'];
			$type = self::CATEGORIES[strtolower(preg_replace('/\s+/', ' ', $row['category']) ?? '')] ?? null;
			$lat = is_numeric($row['latitude']) ? (float)$row['latitude'] : null;
			$lon = is_numeric($row['longitude']) ? (float)$row['longitude'] : null;
			if ($name === '') {
				$skipped[] = "Zeile $lineNo: ohne Namen.";
				continue;
			}
			if ($type === null) {
				$skipped[] = "Zeile $lineNo ($name): unbekannte Kategorie „{$row['category']}“.";
				continue;
			}
			if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
				$skipped[] = "Zeile $lineNo ($name): ungültige Koordinaten.";
				continue;
			}
			$id = ($row['id'] ?? '') !== '' ? mb_substr($row['id'], 0, 128) : self::technicalId($name);
			if (isset($ids[$id])) {
				$skipped[] = "Zeile $lineNo ($name): doppelter Eintrag.";
				continue;
			}
			$ids[$id] = true;
			$url = $row['url'] ?? '';
			$entries[] = [
				'sourceId' => $id,
				'name' => $name,
				'type' => $type,
				'coordinates' => ['lat' => $lat, 'lon' => $lon],
				'homepage' => preg_match('#^https?://#i', $url) === 1 ? $url : null,
			];
		}
		return ['entries' => $entries, 'skipped' => $skipped];
	}

	/**
	 * Nachvollziehbare technische Kennung aus dem Namen: Kleinbuchstaben, Umlaute aufgelöst,
	 * Satzzeichen entfernt, plus kurzer Prüfwert. Koordinaten gehen nicht ein, damit eine
	 * Korrektur der Lage keinen neuen Platz erzeugt.
	 */
	public static function technicalId(string $name): string {
		$normalized = mb_strtolower(trim($name));
		$normalized = strtr($normalized, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		$normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
		$normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
		return 'sz-' . substr(sha1($normalized), 0, 16);
	}
}

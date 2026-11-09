<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Sites;

use OCA\Lagerplaner\Domain\Sites\InvalidSiteData;
use OCA\Lagerplaner\Domain\Sites\SchwarzzeltCsv;
use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCP\Http\Client\IClientService;

/**
 * Campsitemap von Schwarzzelt IT (https://github.com/Schwarzzelt-IT/campsites).
 * Nutzung erlaubt mit Nennung von Schwarzzelt IT und möglichst einem Link; Korrekturen und
 * neue Plätze sind dort ausdrücklich erwünscht.
 */
class SchwarzzeltItSource implements SiteSource {
	public const KEY = 'schwarzzelt_it';
	public const NAME = 'Schwarzzelt IT Campsitemap';
	public const URL = 'https://github.com/Schwarzzelt-IT/campsites';
	public const CSV_URL = 'https://raw.githubusercontent.com/Schwarzzelt-IT/campsites/main/sites.csv';
	public const BUNDLED = __DIR__ . '/../../data/sites/schwarzzelt_it.csv';
	public const MAX_BYTES = 5 * 1024 * 1024;

	public function __construct(
		private IClientService $clients,
		private string $bundledFile = self::BUNDLED,
	) {
	}

	public function key(): string {
		return self::KEY;
	}

	public function name(): string {
		return self::NAME;
	}

	public function url(): string {
		return self::URL;
	}

	public function license(): ?string {
		return 'MIT, Nutzung mit Quellenangabe abgestimmt';
	}

	public function feedback(): array {
		return ['issues' => self::URL . '/issues/new', 'mail' => 'campsites@schwarzzelt-it.de'];
	}

	public function fetch(): SourceSnapshot {
		try {
			$response = $this->clients->newClient()->get(self::CSV_URL, ['timeout' => 20, 'headers' => ['Accept' => 'text/csv, text/plain']]);
		} catch (\Throwable $e) {
			throw new SourceUnavailable('Die Campsitemap ist nicht erreichbar: ' . $e->getMessage(), 0, $e);
		}
		$body = (string)$response->getBody();
		if ($body === '' || strlen($body) > self::MAX_BYTES) {
			throw new SourceUnavailable('Die Campsitemap lieferte keine verwertbare Datei.');
		}
		return $this->snapshot($body);
	}

	public function bundled(): ?SourceSnapshot {
		if (!is_file($this->bundledFile)) {
			return null;
		}
		return $this->snapshot((string)file_get_contents($this->bundledFile));
	}

	/** Die Campsitemap ist nur Stammdatenquelle: Name, Kategorie, Koordinaten, Betreiber-/Infoseite. */
	public function attributes(): ?array {
		return [SiteAttribute::Name, SiteAttribute::Type, SiteAttribute::Location, SiteAttribute::Website];
	}

	/** Eine Zeile wie in sites.csv: name,category,latitude,longitude,url */
	public function describe(Site $site): string {
		$category = array_search($site->type->value, ['campsite' => 'tent', 'house' => 'house', 'campsite house' => 'both'], true);
		// Koordinaten verlustfrei (kürzeste exakte Darstellung), damit sich die Zeile der Quelle zuordnen lässt
		$number = static fn (?float $v): string => $v === null ? '' : (string)json_encode($v);
		$fields = [$site->name, (string)$category, $number($site->lat), $number($site->lon), (string)$site->homepage];
		return implode(',', array_map(static fn (string $f): string => preg_match('/[",\n]/', $f) === 1 ? '"' . str_replace('"', '""', $f) . '"' : $f, $fields));
	}

	/** Version = Prüfwert des Inhalts, damit eine unveränderte Datei nichts neu schreibt. */
	public function snapshot(string $csv): SourceSnapshot {
		try {
			$parsed = (new SchwarzzeltCsv())->parse($csv);
		} catch (InvalidSiteData $e) {
			throw new SourceUnavailable($e->getMessage(), 0, $e);
		}
		return new SourceSnapshot('csv-' . substr(sha1($csv), 0, 12), $parsed['entries'], $parsed['skipped']);
	}
}

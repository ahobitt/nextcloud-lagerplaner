<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\SiteFactMapper;
use OCA\Lagerplaner\Db\SiteMapper;
use OCA\Lagerplaner\Db\SiteRecord;
use OCA\Lagerplaner\Db\SiteSourceMapper;
use OCA\Lagerplaner\Db\SiteSourceRecord;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Sites\FactMethod;
use OCA\Lagerplaner\Domain\Sites\InvalidSiteData;
use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCA\Lagerplaner\Domain\Sites\SiteNormalizer;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCA\Lagerplaner\Sites\SiteSource;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Sites\SourceUnavailable;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Gleicht den Stand einer Datenquelle mit dem Lagerplatzverzeichnis ab (Konzept 5.3).
 * Einzige Stelle, die importierte Plätze schreibt; der Import einer Datei (occ, Verwaltung)
 * läuft ebenfalls hierüber.
 *
 * - wiederholbar: unveränderter Inhalt schreibt nichts, bekannte Plätze werden aktualisiert
 * - stabile Zuordnung über die Kennung der Quelle; ändert sich die Kennung (etwa durch einen
 *   neuen Namen), wird der Platz über gleiche Website oder Lage (unter 150 m) wiedererkannt
 * - Angaben mit eigener Herkunft (Website, von Hand) werden nicht überschrieben
 * - fehlt ein Platz im neuen Stand, gilt er als „nicht mehr in der Quelle“, gelöscht wird nichts
 */
class SiteSynchronizer {
	public const MATCH_DISTANCE_KM = 0.15;
	/** Spalten je Angabe, für geschützte Angaben */
	private const COLUMNS = [
		'name' => ['name'], 'type' => ['siteType'], 'location' => ['lat', 'lon'], 'website' => ['homepage'],
		'address' => ['street', 'postalCode', 'city', 'state', 'country'], 'capacity' => ['capacity'], 'beds' => ['beds'],
		'water' => ['featureWater'], 'sanitary' => ['featureSanitary'], 'kitchen' => ['featureKitchen'],
		'electricity' => ['featureElectricity'], 'fireplace' => ['featureFireplace'], 'accessible' => ['featureAccessible'],
		'price' => ['priceText'],
	];

	public function __construct(
		private SiteMapper $sites,
		private SiteFactMapper $facts,
		private SiteSourceMapper $sourceRecords,
		private SiteSources $sources,
		private Transaction $transaction,
		private ITimeFactory $time,
		private SiteNormalizer $normalizer = new SiteNormalizer(),
	) {
	}

	/**
	 * Holt den aktuellen Stand einer Quelle und gleicht ihn ab. Ist die Quelle nicht erreichbar
	 * und wurde sie noch nie abgeglichen, dient der mitgelieferte Stand.
	 *
	 * @return array<string, mixed>
	 * @throws SourceUnavailable
	 */
	public function sync(string $key, bool $bundledOnly = false): array {
		$source = $this->sources->get($key) ?? throw new NotFoundException('Diese Datenquelle gibt es nicht.');
		$record = $this->sourceRecords->findByKey($key);
		try {
			$snapshot = $bundledOnly ? ($source->bundled() ?? throw new SourceUnavailable('Kein mitgelieferter Stand.')) : $source->fetch();
			$origin = $bundledOnly ? 'bundled' : 'online';
		} catch (SourceUnavailable $e) {
			$bundled = ($record === null || $record->getSyncedAt() === null) ? $source->bundled() : null;
			if ($bundled === null) {
				$this->remember($source, $record, null, 'Fehler: ' . mb_substr($e->getMessage(), 0, 200));
				throw $e;
			}
			$snapshot = $bundled;
			$origin = 'bundled';
		}
		if ($record !== null && $record->getSyncedVersion() === $snapshot->version) {
			$this->remember($source, $record, $snapshot->version, 'unverändert');
			return ['source' => $key, 'origin' => $origin, 'unchanged' => true, 'added' => 0, 'updated' => 0, 'missing' => 0, 'protected' => 0, 'skipped' => $snapshot->skipped];
		}
		$stats = $this->importEntries($key, $snapshot->version, $snapshot->entries, $snapshot->skipped, $source);
		$this->remember($source, $this->sourceRecords->findByKey($key), $snapshot->version,
			sprintf('%d neu, %d aktualisiert, %d nicht mehr in der Quelle, %d übersprungen', $stats['added'], $stats['updated'], $stats['missing'], count($stats['skipped'])));
		return ['origin' => $origin, 'unchanged' => false] + $stats;
	}

	/**
	 * Gleicht Einträge im Austauschformat ab.
	 *
	 * @param list<mixed> $entries
	 * @param list<string> $skipped
	 * @return array{source: string, added: int, updated: int, missing: int, protected: int, skipped: list<string>}
	 */
	public function importEntries(string $key, ?string $version, array $entries, array $skipped = [], ?SiteSource $source = null): array {
		$sites = [];
		foreach ($entries as $index => $entry) {
			try {
				$site = $this->normalizer->normalize(is_array($entry) ? $entry : []);
				if (isset($sites[$site->sourceId])) {
					throw new InvalidSiteData("Kennung {$site->sourceId} kommt doppelt vor.");
				}
				$sites[$site->sourceId] = $site;
			} catch (\InvalidArgumentException $e) {
				$skipped[] = 'Eintrag ' . ($index + 1) . ': ' . $e->getMessage();
			}
		}
		$existing = [];
		foreach ($this->sites->findBySource($key) as $row) {
			$existing[$row->getSourceId()] = $row;
		}
		if ($sites === [] && $existing !== []) {
			throw new \InvalidArgumentException('Der Datenstand enthält keinen gültigen Eintrag; nichts wurde geändert.');
		}

		// Plätze mit neuer Kennung über Website oder Lage wiedererkennen
		$unmatched = array_diff_key($existing, $sites);
		$renamed = [];
		foreach ($sites as $sourceId => $site) {
			if (isset($existing[$sourceId])) {
				continue;
			}
			foreach ($unmatched as $oldId => $row) {
				if (self::sameSite($row, $site)) {
					$renamed[$sourceId] = $row;
					unset($unmatched[$oldId]);
					break;
				}
			}
		}

		// Angaben, für die die Quelle nicht zuständig ist, schreibt sie bei vorhandenen Plätzen nicht
		$foreign = [];
		$responsible = $source?->attributes();
		if ($responsible !== null) {
			$owned = array_map(static fn (SiteAttribute $a): string => $a->value, $responsible);
			foreach (self::COLUMNS as $attribute => $columns) {
				if (!in_array($attribute, $owned, true)) {
					array_push($foreign, ...$columns);
				}
			}
		}
		$now = $this->time->getTime();
		return $this->transaction->run(function () use ($key, $version, $sites, $existing, $renamed, $unmatched, $skipped, $now, $source, $foreign): array {
			$stats = ['source' => $key, 'added' => 0, 'updated' => 0, 'missing' => 0, 'protected' => 0, 'skipped' => $skipped];
			foreach ($sites as $sourceId => $site) {
				$row = $existing[$sourceId] ?? $renamed[$sourceId] ?? null;
				$isNew = $row === null;
				$row ??= new SiteRecord();
				$byFacts = $isNew ? [] : $this->protectedColumns((int)$row->getId(), $site, $stats);
				$protected = $isNew ? [] : array_merge($byFacts, $foreign);
				$kept = [];
				foreach ($protected as $column) {
					$kept[$column] = $row->{'get' . ucfirst($column)}();
				}
				$original = clone $row;
				$row->setSource($key);
				$row->fillFrom($site);
				foreach ($kept as $column => $value) {
					$row->{'set' . ucfirst($column)}($value);
				}
				$stats['protected'] += $byFacts !== [] ? 1 : 0;
				$row->setStatus(SiteRecord::ACTIVE);
				// Nur echte Änderungen zählen: geschützte Spalten werden gesetzt und zurückgesetzt
				if (!$isNew && self::sameAs($row, $original)) {
					$row->resetUpdatedFields();
				}
				if ($isNew || self::changed($row)) {
					$row->setSnapshotVersion($version);
					$row->setImportedAt($now);
					$row->setUpdatedAt($now);
					$isNew ? $this->sites->insert($row) : $this->sites->update($row);
					$stats[$isNew ? 'added' : 'updated']++;
				}
			}
			foreach ($unmatched as $row) {
				if ($row->getStatus() !== SiteRecord::MISSING) {
					$row->setStatus(SiteRecord::MISSING);
					$row->setUpdatedAt($now);
					$this->sites->update($row);
				}
				$stats['missing']++;
			}
			if ($source !== null) {
				$this->remember($source, $this->sourceRecords->findByKey($key), null, null);
			}
			return $stats;
		});
	}

	/** Der nächste Abgleich importiert vollständig, auch wenn sich der Inhalt nicht geändert hat. */
	public function invalidate(string $key): void {
		$record = $this->sourceRecords->findByKey($key);
		if ($record !== null && $record->getSyncedVersion() !== null) {
			$record->setSyncedVersion(null);
			$this->sourceRecords->update($record);
		}
	}

	/**
	 * Vorrang: Bewusste Korrekturen von Hand bleiben immer. Angaben aus Rückmeldungen, Website und
	 * Geodaten bleiben, solange die Quelle nichts liefert oder noch denselben Wert hat wie bei der
	 * Ersetzung; ändert die Quelle ihn, gilt wieder die Quelle und der Herkunftseintrag entfällt.
	 *
	 * @return list<string> Spalten, die die Quelle nicht überschreibt
	 */
	private function protectedColumns(int $siteId, Site $incoming, array &$stats): array {
		$columns = [];
		foreach ($this->facts->findBySite($siteId) as $fact) {
			$attribute = SiteAttribute::tryFrom($fact->getAttribute());
			$method = FactMethod::tryFrom($fact->getMethod());
			if ($attribute !== null && $method !== null && !$method->isDeliberate()) {
				$value = $attribute->valueOf($incoming);
				$incomingJson = json_encode($value, JSON_UNESCAPED_UNICODE);
				if ($value !== null && $fact->getSourceValue() === null) {
					// Ältere Website-Angabe ohne festgehaltenen Quellwert: heutigen Wert der Quelle als Ausgangspunkt nehmen
					$fact->setSourceValue($incomingJson);
					$this->facts->update($fact);
				} elseif ($value !== null && $incomingJson !== $fact->getSourceValue()) {
					$this->facts->delete($fact);
					$stats['sourceWins'] = ($stats['sourceWins'] ?? 0) + 1;
					continue;
				}
			}
			array_push($columns, ...(self::COLUMNS[$fact->getAttribute()] ?? []));
		}
		return $columns;
	}

	/** @return list<string> Spalten einer Angabe */
	public static function columns(SiteAttribute $attribute): array {
		return self::COLUMNS[$attribute->value];
	}

	private static function sameAs(SiteRecord $row, SiteRecord $original): bool {
		foreach (array_keys($row->getUpdatedFields()) as $field) {
			if ($row->{'get' . ucfirst($field)}() !== $original->{'get' . ucfirst($field)}()) {
				return false;
			}
		}
		return true;
	}

	/** Die Entität markiert nur Felder, deren Wert sich wirklich geändert hat. */
	private static function changed(SiteRecord $row): bool {
		return $row->getUpdatedFields() !== [];
	}

	private static function sameSite(SiteRecord $row, Site $site): bool {
		$homepage = static fn (?string $url): string => rtrim(strtolower(preg_replace('#^https?://(www\.)?#i', '', (string)$url) ?? ''), '/');
		if ($site->homepage !== null && $homepage($row->getHomepage()) !== '' && $homepage($row->getHomepage()) === $homepage($site->homepage)) {
			return true;
		}
		if ($row->getLat() === null || $row->getLon() === null || $site->lat === null || $site->lon === null) {
			return false;
		}
		return SiteQuery::distanceKm((float)$row->getLat(), (float)$row->getLon(), $site->lat, $site->lon) < self::MATCH_DISTANCE_KM;
	}

	/** Quelle mit Namen, Link und letztem Abgleich festhalten (Quellenangabe je Platz). */
	private function remember(SiteSource $source, ?SiteSourceRecord $record, ?string $version, ?string $result): void {
		$now = $this->time->getTime();
		$isNew = $record === null;
		$record ??= new SiteSourceRecord();
		$record->setSourceKey($source->key());
		$record->setName($source->name());
		$record->setUrl($source->url());
		$record->setLicense($source->license());
		if ($result !== null) {
			$record->setSyncResult($result);
			if (!str_starts_with($result, 'Fehler')) {
				$record->setSyncedAt($now);
				$record->setSyncedVersion($version);
			}
		}
		$record->setUpdatedAt($now);
		$isNew ? $this->sourceRecords->insert($record) : $this->sourceRecords->update($record);
	}
}

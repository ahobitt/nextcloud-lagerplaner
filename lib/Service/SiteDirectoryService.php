<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\CampSiteMapper;
use OCA\Lagerplaner\Domain\Geo\CoordinateParser;
use OCA\Lagerplaner\Db\SiteMapper;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCA\Lagerplaner\Domain\IsoDate;
use OCA\Lagerplaner\Domain\Sites\FactMethod;
use OCA\Lagerplaner\Domain\Sites\OperatingStatus;
use OCA\Lagerplaner\Domain\Sites\FactConfidence;
use OCA\Lagerplaner\Db\SiteSourceMapper;
use OCA\Lagerplaner\Db\SiteFactMapper;
use OCA\Lagerplaner\Db\SiteFact;
use OCA\Lagerplaner\Db\SiteRecord;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Sites\InvalidSiteData;
use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteFeatures;
use OCA\Lagerplaner\Domain\Sites\SiteNormalizer;
use OCA\Lagerplaner\Domain\Sites\SiteQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lagerplatzverzeichnis der Instanz (Konzept 5).
 *
 * Datenstände kommen aus Datenquellen (SiteSource, abgeglichen vom SiteSynchronizer) oder als
 * Datei im neutralen Importformat „lagerplaner-sites/1“ (siehe docs/site-import.md). Eigene Einträge legen Nutzende an;
 * ändern dürfen sie die Person, die sie angelegt hat, und die Administration.
 */
class SiteDirectoryService {
	public const DEFAULT_HOME = 'default';
	public const FORMAT = 'lagerplaner-sites/1';
	public const MANUAL_LABEL = 'Eigener Eintrag';
	/** @var array<string, array{name: string, url: ?string}>|null Quellen je Anfrage */
	private ?array $sourceInfo = null;
	/** Ab diesem Alter wird ein Eintrag deutlich als alt gekennzeichnet (Konzept 5.7). */
	public const OLD_AFTER_DAYS = 365;

	public function __construct(
		private SiteMapper $sites,
		private CampSiteMapper $campSites,
		private SitesConfig $config,
		private Directory $directory,
		private Transaction $transaction,
		private ITimeFactory $time,
		private SiteSynchronizer $synchronizer,
		private SiteSourceMapper $sourceRecords,
		private SiteFactMapper $facts,
		private SiteSources $registry,
	) {
	}

	/** @return array{name: string, url: ?string} Name und Link für die Quellenangabe */
	public function sourceInfo(string $key): array {
		if ($key === SiteRecord::MANUAL) {
			return ['name' => self::MANUAL_LABEL, 'url' => null];
		}
		if ($this->sourceInfo === null) {
			$this->sourceInfo = [];
			foreach ($this->sourceRecords->findAll() as $record) {
				$this->sourceInfo[(string)$record->getSourceKey()] = ['name' => (string)$record->getName(), 'url' => $record->getUrl()];
			}
			foreach ($this->registry->all() as $source) {
				$this->sourceInfo[$source->key()] ??= ['name' => $source->name(), 'url' => $source->url()];
			}
		}
		return $this->sourceInfo[$key] ?? ['name' => $key, 'url' => null];
	}

	/** @return array<string, mixed> */
	public function search(string $uid, SiteQuery $query, int $limit = 50, int $offset = 0, ?string $homeId = null): array {
		$home = $this->resolveHome($uid, $homeId);
		$extra = [
			'canCreate' => $this->directory->isAdmin($uid),
			'home' => $home === null ? null : ['id' => $home['id'], 'label' => $home['label']],
			'homes' => array_map(static fn (array $h): array => ['id' => $h['id'], 'label' => $h['label'], 'editable' => $h['editable']], $this->homesFor($uid)),
		];
		$coordinates = $home === null ? null : [$home['lat'], $home['lon']];
		$result = $this->find($query, $limit, $offset, $coordinates) + $extra;
		// Fünfstellige PLZ ohne Treffer: Plätze in der PLZ-Region zeigen, statt nichts.
		if ($result['total'] === 0 && preg_match('/^\d{5}$/', $query->text) === 1) {
			foreach ([3, 2] as $digits) {
				$prefix = substr($query->text, 0, $digits);
				$wider = $this->find($query->inPostalRegion($prefix), $limit, $offset, $coordinates) + $extra;
				if ($wider['total'] > 0) {
					return $wider + ['notice' => sprintf('Kein Platz mit der PLZ %s. Gezeigt werden Plätze in der PLZ-Region %s…', $query->text, $prefix)];
				}
			}
		}
		return $result;
	}

	/** @return array{items: list<array<string, mixed>>, total: int, hasHome: bool, states: list<string>} */
	private function find(SiteQuery $query, int $limit, int $offset, ?array $home): array {
		$items = [];
		$states = [];
		$today = date('Y-m-d', $this->time->getTime());
		foreach ($this->visible() as $row) {
			// Zusammengeführte Dubletten nie, geschlossene Plätze nur auf Wunsch
			if ($row->getMergedInto() !== null
				|| (!$query->includeClosed && OperatingStatus::effective($row->getOperatingStatus(), $row->getStatusFrom(), $row->getStatusUntil(), $today) === OperatingStatus::Closed)) {
				continue;
			}
			$site = $row->toDomain();
			if ($site->state !== null) {
				$states[$site->state] = true;
			}
			$distance = $this->distance($site, $home);
			$unknown = $query->evaluate($site, $distance);
			if ($unknown !== null) {
				$items[] = $this->summary($row, $site, $distance) + ['unknown' => $unknown];
			}
		}
		usort($items, static fn (array $a, array $b): int => $home === null
			? strcasecmp($a['name'], $b['name'])
			: [$a['distanceKm'] === null, $a['distanceKm']] <=> [$b['distanceKm'] === null, $b['distanceKm']]);
		$states = array_keys($states);
		sort($states);
		return [
			'items' => array_slice($items, $offset, $limit),
			'total' => count($items),
			'hasHome' => $home !== null,
			'states' => $states,
		];
	}

	/** @return array<string, mixed> */
	public function get(int $id, string $uid, ?string $homeId = null): array {
		$row = $this->row($id);
		$site = $row->toDomain();
		$home = $this->resolveHome($uid, $homeId);
		return $this->summary($row, $site, $this->distance($site, $home === null ? null : [$home['lat'], $home['lon']])) + [
			'street' => $site->street,
			'country' => $site->country,
			'lat' => $site->lat,
			'lon' => $site->lon,
			'description' => $site->description,
			'homepage' => $site->homepage,
			'sourceUrl' => $site->sourceUrl,
			'snapshotVersion' => $row->getSnapshotVersion(),
			'operatorConfirmedAt' => $row->getOperatorConfirmedAt(),
			'osmUrl' => $site->lat === null ? null : sprintf('https://www.openstreetmap.org/?mlat=%1$.5F&mlon=%2$.5F#map=14/%1$.5F/%2$.5F', $site->lat, $site->lon),
			'canEdit' => $this->canEdit($row, $uid),
			'canCorrect' => $row->getSource() !== SiteRecord::MANUAL && $this->directory->isAdmin($uid),
			'feedback' => $this->registry->get((string)$row->getSource())?->feedback() ?? [],
			'facts' => $this->provenance($row, $site),
		];
	}

	/**
	 * Herkunft der vorhandenen Angaben: aus der Quelle des Platzes oder mit eigenem
	 * Herkunftseintrag (Website, von Hand) mit Prüfdatum und Sicherheit.
	 *
	 * @return array<string, array{method: string, sourceUrl: ?string, checkedAt: ?int, confidence: ?string, reported: bool}>
	 */
	private function provenance(SiteRecord $row, Site $site): array {
		$source = $this->sourceInfo((string)$row->getSource());
		$facts = [];
		foreach (SiteAttribute::cases() as $attribute) {
			if ($attribute === SiteAttribute::Status ? $row->getOperatingStatus() !== null : self::hasValue($site, $attribute)) {
				$facts[$attribute->value] = ['method' => $row->getSource() === SiteRecord::MANUAL ? 'manual' : 'source',
					'sourceUrl' => $site->sourceUrl ?? $source['url'], 'checkedAt' => $row->getImportedAt(), 'confidence' => null, 'reported' => false];
			}
		}
		foreach ($this->facts->findBySite((int)$row->getId()) as $fact) {
			$facts[$fact->getAttribute()] = ['method' => $fact->getMethod(), 'sourceUrl' => $fact->getSourceUrl(), 'checkedAt' => $fact->getCheckedAt(),
				'confidence' => $fact->getConfidence(), 'reported' => $fact->getReportedAt() !== null, 'note' => $fact->getNote()];
		}
		return $facts;
	}

	private static function hasValue(Site $site, SiteAttribute $attribute): bool {
		return match ($attribute) {
			SiteAttribute::Name, SiteAttribute::Type => true,
			SiteAttribute::Status => false,
			SiteAttribute::Location => $site->lat !== null,
			SiteAttribute::Website => $site->homepage !== null,
			SiteAttribute::Address => $site->street !== null || $site->postalCode !== null || $site->city !== null || $site->state !== null || $site->country !== null,
			SiteAttribute::Capacity => $site->capacity !== null,
			SiteAttribute::Beds => $site->beds !== null,
			SiteAttribute::Price => $site->priceText !== null,
			default => $site->features->get($attribute->value) !== null,
		};
	}

	/**
	 * Angaben eines importierten Platzes ergänzen oder korrigieren (Administration). Jede
	 * geänderte Angabe bekommt einen Herkunftseintrag „von Hand“: Eine Synchronisierung
	 * überschreibt sie nicht, und sie lässt sich an die Quelle zurückmelden.
	 *
	 * @param array<string, mixed> $data Felder wie im Austauschformat (name, type, coordinates, homepage,
	 *                                   address, capacity, beds, features, priceText)
	 */
	public function correct(int $id, string $uid, array $data, ?string $evidenceUrl = null): array {
		$row = $this->row($id);
		if ($row->getSource() === SiteRecord::MANUAL) {
			$this->updateManual($id, $uid, self::exchange($row->toDomain(), $data));
			return $this->get($id, $uid);
		}
		if (!$this->directory->isAdmin($uid)) {
			throw new ForbiddenException('Angaben importierter Plätze ergänzt oder korrigiert die Administration.');
		}
		$this->applyCorrection($id, $uid, $data, $evidenceUrl);
		return $this->get($id, $uid);
	}

	/**
	 * Korrektur schreiben, ohne Rechteprüfung (für correct() und übernommene Rückmeldungen).
	 * Betriebsstatus über operatingStatus, statusFrom, statusUntil.
	 */
	public function applyCorrection(int $id, string $uid, array $data, ?string $evidenceUrl = null, ?string $note = null, FactMethod $method = FactMethod::Manual): void {
		$row = $this->row($id);
		$before = $row->toDomain();
		$statusBefore = [$row->getOperatingStatus(), $row->getStatusFrom(), $row->getStatusUntil()];
		$status = array_intersect_key($data, ['operatingStatus' => true, 'statusFrom' => true, 'statusUntil' => true]);
		$data = array_diff_key($data, $status);
		if (isset($status['operatingStatus']) && OperatingStatus::tryFrom((string)$status['operatingStatus']) === null) {
			throw new \InvalidArgumentException('Unbekannter Betriebsstatus.');
		}
		foreach (['statusFrom', 'statusUntil'] as $key) {
			if (($status[$key] ?? null) !== null && !IsoDate::valid($status[$key])) {
				throw new \InvalidArgumentException('Datum bitte als JJJJ-MM-TT.');
			}
		}
		$after = $this->normalize(self::exchange($before, $data));
		$now = $this->time->getTime();
		$previous = [];
		foreach ($this->facts->findBySite($id) as $fact) {
			$previous[$fact->getAttribute()] = $fact;
		}
		// Quellwert festhalten (außer bei bewussten Korrekturen), damit eine spätere Änderung der Quelle wieder gilt
		$sourceValue = static fn (SiteAttribute $a, mixed $value): ?string => $method->isDeliberate() ? null
			: (isset($previous[$a->value]) ? $previous[$a->value]->getSourceValue() : json_encode($value, JSON_UNESCAPED_UNICODE));
		$this->transaction->run(function () use ($row, $before, $after, $data, $status, $statusBefore, $uid, $evidenceUrl, $note, $now, $method, $sourceValue): void {
			$row->fillFrom($after);
			if ($status !== []) {
				$row->setOperatingStatus($status['operatingStatus'] ?? $row->getOperatingStatus());
				$row->setStatusFrom(array_key_exists('statusFrom', $status) ? $status['statusFrom'] : $row->getStatusFrom());
				$row->setStatusUntil(array_key_exists('statusUntil', $status) ? $status['statusUntil'] : $row->getStatusUntil());
			}
			$row->setUpdatedAt($now);
			$this->sites->update($row);
			foreach (self::attributesIn($data) as $attribute) {
				if (self::valueOf($before, $attribute) !== self::valueOf($after, $attribute)) {
					$this->recordFact((int)$row->getId(), $attribute, $method, $uid, $evidenceUrl, FactConfidence::High, $now, $note, $sourceValue($attribute, self::valueOf($before, $attribute)));
				}
			}
			if ($status !== [] && $statusBefore !== [$row->getOperatingStatus(), $row->getStatusFrom(), $row->getStatusUntil()]) {
				$this->recordFact((int)$row->getId(), SiteAttribute::Status, $method, $uid, $evidenceUrl, FactConfidence::High, $now, $note,
					$sourceValue(SiteAttribute::Status, null));
			}
		});
	}

	/**
	 * Herkunft einer Angabe festhalten, etwa für eine künftige automatische Ergänzung von der
	 * Website des Platzes (FactMethod::Website). Eine neue Angabe gilt als noch nicht gemeldet.
	 */
	public function recordFact(int $siteId, SiteAttribute $attribute, FactMethod $method, ?string $uid, ?string $sourceUrl, ?FactConfidence $confidence, int $now, ?string $note = null, ?string $sourceValue = null): void {
		$fact = null;
		foreach ($this->facts->findBySite($siteId) as $existing) {
			if ($existing->getAttribute() === $attribute->value) {
				$fact = $existing;
			}
		}
		$isNew = $fact === null;
		$fact ??= new SiteFact();
		$fact->setSiteId($siteId);
		$fact->setAttribute($attribute->value);
		$fact->setMethod($method->value);
		$fact->setSourceUrl($sourceUrl);
		$fact->setCheckedAt($now);
		$fact->setConfidence($confidence?->value);
		$fact->setChangedBy($uid);
		$fact->setNote($note === null ? null : mb_substr($note, 0, 255));
		$fact->setSourceValue(!$method->isDeliberate() && $sourceValue !== null ? mb_substr($sourceValue, 0, 500) : null);
		$fact->setReportedAt(null);
		$fact->setUpdatedAt($now);
		$isNew ? $this->facts->insert($fact) : $this->facts->update($fact);
	}

	/** Platz als Domänenobjekt, etwa für eine Zeile im Format der Quelle */
	public function siteOf(int $id): Site {
		return $this->row($id)->toDomain();
	}

	/** @return list<SiteAttribute> Angaben, die in den übergebenen Feldern vorkommen */
	private static function attributesIn(array $data): array {
		$attributes = [];
		$map = ['name' => SiteAttribute::Name, 'type' => SiteAttribute::Type, 'coordinates' => SiteAttribute::Location, 'homepage' => SiteAttribute::Website,
			'address' => SiteAttribute::Address, 'capacity' => SiteAttribute::Capacity, 'beds' => SiteAttribute::Beds, 'priceText' => SiteAttribute::Price];
		foreach ($map as $key => $attribute) {
			if (array_key_exists($key, $data)) {
				$attributes[] = $attribute;
			}
		}
		foreach (SiteAttribute::features() as $feature) {
			if (is_array($data['features'] ?? null) && array_key_exists($feature->value, $data['features'])) {
				$attributes[] = $feature;
			}
		}
		return $attributes;
	}

	private static function valueOf(Site $site, SiteAttribute $attribute): mixed {
		return $attribute->valueOf($site);
	}

	/** Austauschformat eines Platzes, überlagert mit geänderten Feldern */
	private static function exchange(Site $site, array $data): array {
		$current = [
			'sourceId' => $site->sourceId, 'name' => $site->name, 'type' => $site->type->value,
			'address' => ['street' => $site->street, 'postalCode' => $site->postalCode, 'city' => $site->city, 'state' => $site->state, 'country' => $site->country],
			'coordinates' => $site->lat === null ? null : ['lat' => $site->lat, 'lon' => $site->lon],
			'capacity' => $site->capacity, 'beds' => $site->beds, 'features' => $site->features->toArray(), 'priceText' => $site->priceText,
			'description' => $site->description, 'homepage' => $site->homepage, 'sourceUrl' => $site->sourceUrl, 'sourceDate' => $site->sourceDate,
		];
		if (is_array($data['features'] ?? null)) {
			$data['features'] = $data['features'] + $current['features'];
		}
		if (is_array($data['address'] ?? null)) {
			$data['address'] = $data['address'] + $current['address'];
		}
		return ['sourceId' => $site->sourceId] + $data + $current;
	}

	/**
	 * Eigener Eintrag eines Stammes (Konzept 5.10), sichtbar für alle in dieser Nextcloud.
	 *
	 * @param array<string, mixed> $data Felder wie im Importformat
	 */
	public function createManual(string $uid, array $data): array {
		if (!$this->directory->isAdmin($uid)) {
			throw new ForbiddenException('Neue Plätze legt die Administration an; melde einen fehlenden Platz über „Lagerplatz fehlt? Melden“.');
		}
		return $this->createManaged($data, $uid);
	}

	/**
	 * Eigener Eintrag ohne Rechteprüfung. Ohne Eigentümer (null) verwaltet ihn nur die
	 * Administration, etwa einen übernommenen fehlenden Platz.
	 */
	public function createManaged(array $data, ?string $owner): array {
		$site = $this->normalize(['sourceId' => 'manual-' . bin2hex(random_bytes(8))] + $data);
		$row = new SiteRecord();
		$row->setSource(SiteRecord::MANUAL);
		$row->fillFrom($site);
		$row->setCreatedBy($owner);
		$row->setStatus(SiteRecord::ACTIVE);
		$row->setUpdatedAt($this->time->getTime());
		return ['id' => (int)$this->sites->insert($row)->getId()];
	}

	/** @param array<string, mixed> $data */
	public function updateManual(int $id, string $uid, array $data): void {
		$row = $this->editable($id, $uid);
		// Nur geänderte Felder ersetzen: was das Formular nicht kennt (Koordinaten, Land), bleibt erhalten
		$row->fillFrom($this->normalize(self::exchange($row->toDomain(), $data)));
		$row->setUpdatedAt($this->time->getTime());
		$this->sites->update($row);
	}

	public function deleteManual(int $id, string $uid): void {
		$row = $this->editable($id, $uid);
		if ($this->campSites->findBySite($id) !== []) {
			throw new \InvalidArgumentException('Dieser Platz steht auf der Merkliste eines Lagers und kann nicht gelöscht werden.');
		}
		$this->facts->deleteBySite($id);
		$this->sites->delete($row);
	}

	/**
	 * Übernimmt einen Datenstand einer Quelle (Konzept 5.3). Bestehende Einträge behalten ihre
	 * ID, damit Merklisten und Lager ihre Verknüpfungen behalten. Was im neuen Stand fehlt,
	 * wird als „nicht mehr in der Quelle“ gekennzeichnet, nicht gelöscht.
	 *
	 * @param array<string, mixed> $document
	 * @return array{source: string, added: int, updated: int, missing: int, skipped: list<string>}
	 * @throws \InvalidArgumentException bei ungültigem Dokument
	 */
	public function import(array $document): array {
		if (($document['format'] ?? null) !== self::FORMAT) {
			throw new \InvalidArgumentException('Unbekanntes Format; erwartet wird „' . self::FORMAT . '“.');
		}
		$source = $document['source'] ?? null;
		if (!is_string($source) || preg_match('/^[a-z][a-z0-9_-]{1,15}$/', $source) !== 1 || $source === SiteRecord::MANUAL) {
			throw new \InvalidArgumentException('Ungültige Quelle.');
		}
		$version = is_string($document['snapshotVersion'] ?? null) ? mb_substr($document['snapshotVersion'], 0, 32) : null;
		$raw = $document['sites'] ?? null;
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new \InvalidArgumentException('Das Dokument enthält keine Liste „sites“.');
		}
		return $this->synchronizer->importEntries($source, $version, $raw, [], $this->registry->get($source));
	}

	/** Zusammenfassung eines Eintrags für Liste, Merkliste und Vergleich. */
	/**
	 * Quellen des Verzeichnisses für die Verwaltung: eingebundene Datenquellen und Quellen mit
	 * Einträgen, je mit Anzahl, davon mit Koordinaten und nicht mehr in der Quelle, letztem
	 * Abgleich und ob sie ausgeblendet sind.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function sources(): array {
		$stats = [];
		foreach (array_keys($this->registry->all()) as $key) {
			$stats[$key] = ['count' => 0, 'withCoordinates' => 0, 'missing' => 0];
		}
		foreach ($this->sites->findAll() as $record) {
			$source = (string)$record->getSource();
			$stats[$source] ??= ['count' => 0, 'withCoordinates' => 0, 'missing' => 0];
			$stats[$source]['count']++;
			if ($record->getLat() !== null && $record->getLon() !== null) {
				$stats[$source]['withCoordinates']++;
			}
			if ($record->getStatus() === SiteRecord::MISSING) {
				$stats[$source]['missing']++;
			}
		}
		$disabled = $this->config->disabledSources();
		foreach ($disabled as $source) {
			$stats[$source] ??= ['count' => 0, 'withCoordinates' => 0, 'missing' => 0];
		}
		ksort($stats);
		$list = [];
		foreach ($stats as $source => $numbers) {
			$record = $this->sourceRecords->findByKey((string)$source);
			$info = $this->sourceInfo((string)$source);
			$list[] = ['source' => (string)$source, 'label' => $info['name'], 'link' => $info['url']] + $numbers + [
				'disabled' => in_array($source, $disabled, true),
				'canSync' => $this->registry->get((string)$source) !== null,
				'syncedAt' => $record?->getSyncedAt(),
				'syncResult' => $record?->getSyncResult(),
			];
		}
		return $list;
	}

	/** Zusammenfassung eines Platzes; mit Person gilt die Entfernung ab ihrem Stammesheim. */
	public function summaryFor(int $id, ?string $uid = null): ?array {
		try {
			$row = $this->sites->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
		$site = $row->toDomain();
		$home = $uid === null ? $this->config->home() : $this->resolveHomeCoordinates($uid);
		return $this->summary($row, $site, $this->distance($site, $home));
	}

	/**
	 * Stammesheime, die für eine Person in Frage kommen: die ihrer Stamm-Gruppen, dann der
	 * Standard-Standort. „editable“: Sie darf den Standort ändern (Gruppenadministration).
	 *
	 * @return list<array{id: string, label: string, lat: float, lon: float, editable: bool}>
	 */
	public function homesFor(string $uid): array {
		$homes = [];
		$groups = array_flip($this->directory->groupIdsOf($uid));
		foreach ($this->config->groupHomes() as $gid => $home) {
			if (isset($groups[$gid])) {
				$homes[] = ['id' => (string)$gid] + $home + ['editable' => $this->directory->isGroupAdmin($uid, (string)$gid)];
			}
		}
		usort($homes, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
		$default = $this->config->home();
		if ($default !== null) {
			$homes[] = ['id' => self::DEFAULT_HOME, 'label' => 'Standard-Standort', 'lat' => $default[0], 'lon' => $default[1], 'editable' => false];
		}
		return $homes;
	}

	/**
	 * Standort eines Stammes ändern, als dessen Gruppenadministration. Welche Gruppen Stämme
	 * sind, legt die Verwaltung fest; hier lässt sich nur der Standort ändern.
	 *
	 * @return list<array<string, mixed>> die Heime der Person danach
	 */
	public function updateGroupHome(string $uid, string $gid, string $input): array {
		$homes = $this->config->groupHomes();
		if (!isset($homes[$gid])) {
			throw new NotFoundException('Diese Gruppe ist kein Stamm mit Stammesheim.');
		}
		if (!$this->directory->isGroupAdmin($uid, $gid)) {
			throw new ForbiddenException('Den Standort ändern kann die Gruppenadministration des Stammes.');
		}
		$coordinates = (new CoordinateParser())->parse($input)
			?? throw new \InvalidArgumentException('Keine Koordinaten erkannt. Möglich sind etwa „51.0625, 7.0612“ oder ein Link von OpenStreetMap oder Google Maps.');
		$this->config->setGroupHome($gid, ['label' => $homes[$gid]['label'], 'lat' => $coordinates[0], 'lon' => $coordinates[1]]);
		return $this->homesFor($uid);
	}

	/** @return array{id: string, label: string, lat: float, lon: float, editable: bool}|null */
	private function resolveHome(string $uid, ?string $homeId): ?array {
		$homes = $this->homesFor($uid);
		foreach ($homes as $home) {
			if ($home['id'] === $homeId) {
				return $home;
			}
		}
		return $homes[0] ?? null;
	}

	/** @return array{0: float, 1: float}|null */
	private function resolveHomeCoordinates(string $uid): ?array {
		$home = $this->resolveHome($uid, null);
		return $home === null ? null : [$home['lat'], $home['lon']];
	}

	/** @return array<string, mixed> */
	private function summary(SiteRecord $row, Site $site, ?float $distance): array {
		$now = $this->time->getTime();
		$age = $row->getImportedAt() === null ? null : intdiv($now - (int)$row->getImportedAt(), 86400);
		return [
			'id' => $row->getId(),
			'name' => $site->name,
			'type' => $site->type->value,
			'typeLabel' => $site->type->label(),
			'street' => $site->street,
			'postalCode' => $site->postalCode,
			'city' => $site->city,
			'state' => $site->state,
			'capacity' => $site->capacity,
			'beds' => $site->beds,
			'features' => $site->features->toArray(),
			'priceText' => $site->priceText,
			'distanceKm' => $distance === null ? null : round($distance, 1),
			'country' => $site->country,
			'website' => $site->homepage,
			'operatingStatus' => OperatingStatus::effective($row->getOperatingStatus(), $row->getStatusFrom(), $row->getStatusUntil(), date('Y-m-d', $this->time->getTime()))?->value,
			'statusFrom' => $row->getStatusFrom(),
			'statusUntil' => $row->getStatusUntil(),
			'reviewNote' => $row->getReviewNote(),
			'capacityNote' => $row->getCapacityNote(),
			'mergedInto' => $row->getMergedInto(),
			'source' => $row->getSource(),
			'sourceLabel' => $this->sourceInfo((string)$row->getSource())['name'],
			'sourceLink' => $this->sourceInfo((string)$row->getSource())['url'],
			'sourceDate' => $site->sourceDate,
			'importedAt' => $row->getImportedAt(),
			'importedDaysAgo' => $age,
			'old' => $age !== null && $age > self::OLD_AFTER_DAYS,
			'missing' => $row->getStatus() === SiteRecord::MISSING,
		];
	}

	/** @return list<SiteRecord> ohne von der Administration abgeschaltete Quellen */
	private function visible(): array {
		$disabled = $this->config->disabledSources();
		return array_values(array_filter($this->sites->findAll(), static fn (SiteRecord $r): bool => !in_array($r->getSource(), $disabled, true)));
	}

	/** @param array{0: float, 1: float}|null $home */
	private function distance(Site $site, ?array $home): ?float {
		return $home === null || $site->lat === null || $site->lon === null ? null : SiteQuery::distanceKm($home[0], $home[1], $site->lat, $site->lon);
	}

	/** @param array<string, mixed> $data */
	private function normalize(array $data): Site {
		if (isset($data['features']) && is_array($data['features'])) {
			$data['features'] = array_intersect_key($data['features'], array_flip(SiteFeatures::KEYS));
		}
		return (new SiteNormalizer())->normalize($data);
	}

	private function row(int $id): SiteRecord {
		try {
			$row = $this->sites->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Lagerplatz nicht gefunden.');
		}
		if (in_array($row->getSource(), $this->config->disabledSources(), true)) {
			throw new NotFoundException('Lagerplatz nicht gefunden.');
		}
		return $row;
	}

	private function canEdit(SiteRecord $row, string $uid): bool {
		return $row->getSource() === SiteRecord::MANUAL && ($row->getCreatedBy() === $uid || $this->directory->isAdmin($uid));
	}

	private function editable(int $id, string $uid): SiteRecord {
		$row = $this->row($id);
		if ($row->getSource() !== SiteRecord::MANUAL) {
			throw new ForbiddenException('Importierte Einträge werden mit dem nächsten Datenstand korrigiert, nicht hier.');
		}
		if (!$this->canEdit($row, $uid)) {
			throw new ForbiddenException('Ändern darf die Person, die den Eintrag angelegt hat, oder die Administration.');
		}
		return $row;
	}
}

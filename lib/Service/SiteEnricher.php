<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\CampSiteMapper;
use OCA\Lagerplaner\Db\SiteFactMapper;
use OCA\Lagerplaner\Db\SiteMapper;
use OCA\Lagerplaner\Db\SiteRecord;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\IsoDate;
use OCA\Lagerplaner\Domain\Sites\FactConfidence;
use OCA\Lagerplaner\Domain\Sites\FactMethod;
use OCA\Lagerplaner\Domain\Sites\OperatingStatus;
use OCA\Lagerplaner\Domain\Sites\SchwarzzeltCsv;
use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lagerplaner-eigene Anreicherung (Format lagerplaner-campsites-enrichment/2) über den Plätzen
 * einer Quelle: Postleitzahl und Ort (aus den Koordinaten), Kapazität, Betten, Ausstattung,
 * Betriebsstatus und Prüfhinweise. Stammdaten (Name, Kategorie, Koordinaten, URL) gehören der
 * Quelle; frühere Korrekturen daran nimmt die Anreicherung zurück.
 *
 * - Zuordnung über original_name (wie bei der Quelle), sonst canonical_name
 * - leere Werte ändern nichts; true nur bei bestätigt, false nur bei ausdrücklich verneint
 * - Vorrang: von Hand und aus Rückmeldungen gesetzte Angaben bleiben unberührt
 * - wiederholbar: unveränderte Werte und Herkunft schreiben nichts; geänderte Herkunft
 *   (Methode, Beleg, Sicherheit, Prüfdatum, Hinweis) wird auch bei gleichem Wert übernommen
 */
class SiteEnricher {
	public const FORMAT = 'lagerplaner-campsites-enrichment/2';
	/** Version der Übernahmeregeln; eine neue Version übernimmt mitgelieferte Anreicherungen erneut */
	public const RULES = 3;
	private const FEATURES = ['water', 'sanitary', 'kitchen', 'electricity', 'fireplace', 'accessible'];
	private const BASE = ['name', 'type', 'location', 'website'];

	public function __construct(
		private SiteMapper $sites,
		private SiteFactMapper $facts,
		private SiteDirectoryService $directory,
		private CampSiteMapper $campSites,
		private Transaction $transaction,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, Site> $baseSites Stand der Quelle je Kennung, um zurückgenommene Stammdaten wiederherzustellen
	 * @return array{matched: int, updated: int, attributes: int, provenance: int, restored: int, unresolved: int, merged: int, unmatched: list<string>}
	 */
	public function apply(array $document, array $baseSites = []): array {
		if (($document['format'] ?? null) !== self::FORMAT || !is_string($document['source'] ?? null) || !is_array($document['records'] ?? null)) {
			throw new \InvalidArgumentException('Unbekanntes Format; erwartet wird „' . self::FORMAT . '“ mit source und records.');
		}
		$rows = [];
		foreach ($this->sites->findBySource($document['source']) as $row) {
			$rows[(string)$row->getSourceId()] = $row;
			$rows['name:' . mb_strtolower(trim((string)$row->getName()))] = $row;
		}
		$find = static function (?string $name) use ($rows): ?SiteRecord {
			if ($name === null || trim($name) === '') {
				return null;
			}
			return $rows[SchwarzzeltCsv::technicalId($name)] ?? $rows['name:' . mb_strtolower(trim($name))] ?? null;
		};
		$checked = strtotime((string)($document['reviewed_at'] ?? '')) ?: $this->time->getTime();

		return $this->transaction->run(function () use ($document, $find, $checked, $baseSites): array {
			$stats = ['matched' => 0, 'updated' => 0, 'attributes' => 0, 'provenance' => 0, 'restored' => 0, 'unresolved' => 0, 'merged' => 0, 'unmatched' => []];
			foreach ($document['records'] as $record) {
				$row = $find($record['original_name'] ?? null) ?? $find($record['canonical_name'] ?? null);
				if ($row === null) {
					$stats['unmatched'][] = (string)($record['original_name'] ?? '?');
					continue;
				}
				$stats['matched']++;
				$result = $this->enrich($row, $record, $checked, $baseSites[(string)$row->getSourceId()] ?? null);
				foreach (['attributes', 'provenance', 'restored', 'unresolved'] as $key) {
					$stats[$key] += $result[$key];
				}
				$stats['updated'] += $result['rowChanged'] || $result['attributes'] > 0 || $result['provenance'] > 0 ? 1 : 0;
			}
			foreach ($document['aliases'] ?? [] as $alias) {
				$row = $find($alias['alias_name'] ?? null);
				$target = $find($alias['canonical_name'] ?? null);
				if ($row !== null && $target !== null && $row->getId() !== $target->getId() && $row->getMergedInto() !== (int)$target->getId()) {
					$row->setMergedInto((int)$target->getId());
					$row->setUpdatedAt($this->time->getTime());
					$this->sites->update($row);
					$this->moveShortlistEntries((int)$row->getId(), (int)$target->getId());
					$stats['merged']++;
				}
			}
			return $stats;
		});
	}

	/** Merklisten-Einträge einer Dublette auf den kanonischen Platz umhängen (ohne doppelte Einträge je Lager). */
	private function moveShortlistEntries(int $fromId, int $toId): void {
		$taken = [];
		foreach ($this->campSites->findBySite($toId) as $entry) {
			$taken[(int)$entry->getCampId()] = true;
		}
		foreach ($this->campSites->findBySite($fromId) as $entry) {
			if (isset($taken[(int)$entry->getCampId()])) {
				$this->campSites->delete($entry);
			} else {
				$entry->setSiteId($toId);
				$this->campSites->update($entry);
			}
		}
	}

	private static function confidence(mixed $value): ?FactConfidence {
		return match ($value) {
			'high' => FactConfidence::High, 'medium', 'mixed' => FactConfidence::Medium, 'low' => FactConfidence::Low, default => null,
		};
	}

	/** @return array{attributes: int, provenance: int, restored: int, unresolved: int, rowChanged: bool} */
	private function enrich(SiteRecord $row, array $record, int $checked, ?Site $base): array {
		$result = ['attributes' => 0, 'provenance' => 0, 'restored' => 0, 'unresolved' => 0, 'rowChanged' => false];
		$facts = [];
		foreach ($this->facts->findBySite((int)$row->getId()) as $fact) {
			$facts[$fact->getAttribute()] = $fact;
		}

		// Stammdaten gehören der Quelle: frühere automatische Korrekturen zurücknehmen
		foreach (self::BASE as $attribute) {
			$fact = $facts[$attribute] ?? null;
			if ($fact === null || !in_array($fact->getMethod(), [FactMethod::Website->value, FactMethod::Geocoding->value], true)) {
				continue;
			}
			if (!$this->restoreBase($row, SiteAttribute::from($attribute), $base, $fact->getSourceValue())) {
				$result['unresolved']++;
			}
			$this->facts->delete($fact);
			unset($facts[$attribute]);
			$result['restored']++;
		}

		$before = $row->toDomain();
		$evidence = $record['source_url'] ?? $record['canonical_url'] ?? null;
		$confidence = self::confidence($record['confidence'] ?? null);
		$touched = [];
		$set = function (SiteAttribute $attribute, array $values, FactMethod $method, ?string $sourceUrl, ?FactConfidence $factConfidence) use ($row, $facts, &$touched): void {
			$existing = $facts[$attribute->value] ?? null;
			// Vorrang: von Hand und aus Rückmeldungen gesetzte Angaben bleiben
			if ($existing !== null && (FactMethod::tryFrom($existing->getMethod())?->rank() ?? 0) > FactMethod::Website->rank()) {
				return;
			}
			$differs = false;
			foreach ($values as $column => $value) {
				if ($row->{'get' . ucfirst($column)}() !== $value) {
					$row->{'set' . ucfirst($column)}($value);
					$differs = true;
				}
			}
			$touched[$attribute->value] = [$attribute, $method, $sourceUrl, $factConfidence, $differs];
		};

		$capacity = $record['capacity_max'] ?? $record['capacity_min'] ?? null;
		$capacityText = $record['capacity_text'] ?? null;
		if ($capacity !== null || $capacityText !== null) {
			$note = $capacityText !== null && !ctype_digit((string)$capacityText) ? mb_substr((string)$capacityText, 0, 255) : null;
			$set(SiteAttribute::Capacity, ['capacity' => $capacity === null ? $row->getCapacity() : (int)$capacity, 'capacityNote' => $note], FactMethod::Website, $evidence, $confidence);
		}
		$beds = $record['beds_total'] ?? $record['beds_max'] ?? $record['beds_min'] ?? null;
		if ($beds !== null) {
			$set(SiteAttribute::Beds, ['beds' => (int)$beds], FactMethod::Website, $evidence, $confidence);
		}
		foreach (self::FEATURES as $feature) {
			$value = $record[$feature] ?? null;
			if ($value !== null) {
				$set(SiteAttribute::from($feature), ['feature' . ucfirst($feature) => $value === 'partial' ? SiteRecord::PARTIAL : ($value === false ? 0 : 1)], FactMethod::Website, $evidence, $confidence);
			}
		}
		// Postleitzahl, Ort, Land: aus den Koordinaten ermittelt (Geodaten), nicht von der Website
		$postal = isset($record['postal_code']) ? mb_substr(trim((string)$record['postal_code']), 0, 16) : null;
		$city = isset($record['city']) ? mb_substr(trim((string)$record['city']), 0, 120) : null;
		$country = isset($record['country']) && preg_match('/^[A-Za-z]{2}$/', (string)$record['country']) === 1 ? strtoupper((string)$record['country']) : null;
		if ($postal || $city || $country) {
			$set(SiteAttribute::Address, array_filter(['postalCode' => $postal ?: null, 'city' => $city ?: null, 'country' => $country], static fn ($v) => $v !== null),
				FactMethod::Geocoding, null, self::confidence($record['location_confidence'] ?? null));
		}
		$status = OperatingStatus::tryFrom((string)($record['operating_status'] ?? ''));
		if ($status !== null) {
			$date = static fn ($v): ?string => IsoDate::valid($v) ? $v : null;
			$set(SiteAttribute::Status, ['operatingStatus' => $status->value, 'statusFrom' => $date($record['status_effective_from'] ?? null), 'statusUntil' => $date($record['status_effective_until'] ?? null)],
				FactMethod::Website, $evidence, $confidence);
		}
		$review = ($record['review_status'] ?? null) === 'open' ? mb_substr((string)($record['review_reason'] ?? 'Die Angaben werden noch geprüft.'), 0, 500) : null;
		if ($row->getReviewNote() !== $review) {
			$row->setReviewNote($review);
		}

		$result['rowChanged'] = $row->getUpdatedFields() !== [];
		if ($result['rowChanged']) {
			$row->setUpdatedAt($this->time->getTime());
			$this->sites->update($row);
		}
		foreach ($touched as [$attribute, $method, $sourceUrl, $factConfidence, $differs]) {
			$existing = $facts[$attribute->value] ?? null;
			$provenanceChanged = $existing !== null && [$existing->getMethod(), $existing->getSourceUrl(), $existing->getConfidence(), (int)$existing->getCheckedAt()]
				!== [$method->value, $sourceUrl, $factConfidence?->value, $checked];
			if (!$differs && !$provenanceChanged) {
				continue;
			}
			if (!$differs && $existing === null) {
				continue;
			}
			// Wert der Quelle vor der ersten Ersetzung, damit eine spätere Änderung der Quelle wieder gilt
			$sourceValue = $existing !== null ? $existing->getSourceValue() : json_encode($attribute->valueOf($before), JSON_UNESCAPED_UNICODE);
			$this->directory->recordFact((int)$row->getId(), $attribute, $method, null, $sourceUrl, $factConfidence, $checked, null, $sourceValue);
			$result[$differs ? 'attributes' : 'provenance']++;
		}
		return $result;
	}

	/** Stammdaten wieder auf den Stand der Quelle setzen; false, wenn er nicht bekannt ist. */
	private function restoreBase(SiteRecord $row, SiteAttribute $attribute, ?Site $base, ?string $sourceValue): bool {
		$value = $base !== null ? $attribute->valueOf($base) : ($sourceValue !== null ? json_decode($sourceValue, true) : null);
		if ($value === null && $base === null) {
			return false;
		}
		match ($attribute) {
			SiteAttribute::Name => $row->setName((string)$value),
			SiteAttribute::Type => $row->setSiteType((string)$value),
			SiteAttribute::Website => $row->setHomepage($value === null ? null : (string)$value),
			SiteAttribute::Location => is_array($value) ? [$row->setLat((float)$value[0]), $row->setLon((float)$value[1])] : null,
			default => null,
		};
		return true;
	}
}

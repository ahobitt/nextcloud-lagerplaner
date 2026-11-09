<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\SiteSourceMapper;
use OCA\Lagerplaner\Domain\Sites\SiteNormalizer;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Sites\SourceUnavailable;

/**
 * Mit der App mitgelieferte Stände der Datenquellen (data/sites/). Bei Installation und Update
 * übernimmt die App sie für Quellen, die noch nie abgeglichen wurden, damit das Verzeichnis
 * ohne Netzzugang gefüllt ist; danach hält der regelmäßige Abgleich es aktuell.
 */
class BundledSites {
	public const DIR = __DIR__ . '/../../data/sites';

	public function __construct(
		private SiteSources $sources,
		private SiteSynchronizer $synchronizer,
		private SiteSourceMapper $records,
		private SiteEnricher $enricher,
		private SitesConfig $config,
		private string $dir = self::DIR,
	) {
	}

	/** @return array<string, mixed>|null mitgelieferte Anreicherung einer Quelle (data/sites/<quelle>.enrichment.json) */
	public function enrichment(string $key): ?array {
		$file = $this->dir . '/' . $key . '.enrichment.json';
		$document = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		return is_array($document) ? $document : null;
	}

	/**
	 * Mitgelieferte Anreicherung übernehmen, wenn ihre Version neu ist (oder $force).
	 *
	 * @return array<string, mixed>|null Zahlen oder null, wenn es nichts zu tun gab
	 */
	public function applyEnrichment(string $key, bool $force = false): ?array {
		$document = $this->enrichment($key);
		if ($document === null) {
			return null;
		}
		// Version der Daten und der Übernahmeregeln: neue Regeln übernehmen erneut
		$version = (string)($document['version'] ?? $document['reviewed_at'] ?? '') . '#' . SiteEnricher::RULES;
		if (!$force && $this->config->enrichmentVersion($key) === $version) {
			return null;
		}
		$base = [];
		$normalizer = new SiteNormalizer();
		foreach ($this->sources->get($key)?->bundled()?->entries ?? [] as $entry) {
			try {
				$site = $normalizer->normalize($entry);
				$base[$site->sourceId] = $site;
			} catch (\InvalidArgumentException) {
			}
		}
		$stats = $this->enricher->apply($document, $base);
		if ($stats['restored'] > 0) {
			// zurückgenommene Stammdaten beim nächsten Abgleich vollständig von der Quelle holen
			$this->synchronizer->invalidate($key);
		}
		$this->config->setEnrichmentVersion($key, $version);
		return $stats;
	}

	/**
	 * @return list<array{source: string, label: string, version: string, count: int, importedVersion: string, current: bool}>
	 */
	public function status(): array {
		$list = [];
		foreach ($this->sources->all() as $source) {
			$snapshot = $source->bundled();
			if ($snapshot === null) {
				continue;
			}
			$imported = (string)$this->records->findByKey($source->key())?->getSyncedVersion();
			$enrichment = $this->enrichment($source->key());
			$list[] = [
				'source' => $source->key(),
				'label' => $source->name(),
				'version' => $snapshot->version,
				'count' => count($snapshot->entries),
				'importedVersion' => $imported,
				'current' => $imported === $snapshot->version,
				'enrichment' => $enrichment === null ? null : [
					'version' => (string)($enrichment['version'] ?? ''),
					'count' => count($enrichment['records'] ?? []),
					'applied' => $this->config->enrichmentVersion($source->key()) === (string)($enrichment['version'] ?? '') . '#' . SiteEnricher::RULES,
				],
			];
		}
		return $list;
	}

	/**
	 * Übernimmt den mitgelieferten Stand einer Quelle.
	 *
	 * @return array<string, mixed>
	 */
	public function import(string $key): array {
		$stats = $this->synchronizer->sync($key, true);
		return $stats + ['enrichment' => $this->applyEnrichment($key, true)];
	}

	/**
	 * Für jede Quelle ohne bisherigen Abgleich den mitgelieferten Stand übernehmen.
	 *
	 * @return array<string, array<string, mixed>|string> Quelle => Zahlen, „current“ oder Fehlertext
	 */
	public function importNeverSynced(): array {
		$result = [];
		foreach ($this->sources->all() as $source) {
			try {
				if ($this->records->findByKey($source->key())?->getSyncedAt() === null) {
					$result[$source->key()] = $this->synchronizer->sync($source->key(), true);
				} else {
					$result[$source->key()] = 'current';
				}
				// Anreicherung auch für schon abgeglichene Quellen, sobald eine neue Version mitkommt
				$enrichment = $this->applyEnrichment($source->key());
				if ($enrichment !== null) {
					$result[$source->key() . ' (Anreicherung)'] = $enrichment;
				}
			} catch (SourceUnavailable|\InvalidArgumentException $e) {
				$result[$source->key()] = $e->getMessage();
			}
		}
		return $result;
	}
}

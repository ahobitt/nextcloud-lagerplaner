<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Domain\Geo\CoordinateParser;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Sites\SourceUnavailable;

/**
 * Einstellungen der Verwaltung, damit niemand dafür occ braucht: Standort des Stammesheims,
 * Spielesuche und angezeigte Quellen des Lagerplatzverzeichnisses. Zugriff haben die
 * Administration und Gruppen, denen sie den Abschnitt „Lagerplaner“ delegiert hat.
 */
class AdminSettingsService {
	public function __construct(
		private SitesConfig $sites,
		private GamesConfig $games,
		private SiteDirectoryService $directory,
		private GameCatalogService $catalog,
		private Directory $people,
		private BundledSites $bundled,
		private SiteSynchronizer $synchronizer,
		private SiteSources $registry,
		private SiteFeedbackService $feedback,
	) {
	}

	/** Größte Datei zum Hochladen eines Datenstands */
	public const MAX_UPLOAD = 10 * 1024 * 1024;

	/** @return array<string, mixed> */
	public function state(): array {
		$home = $this->sites->home();
		$games = ['enabled' => $this->games->isEnabled(), 'syncedAt' => $this->games->syncedAt(), 'count' => 0, 'state' => 'disabled'];
		if ($games['enabled']) {
			try {
				$games['state'] = $this->catalog->state();
				$games['count'] = $this->catalog->count();
			} catch (GamesUnavailableException) {
			}
		}
		$groupHomes = [];
		foreach ($this->sites->groupHomes() as $gid => $groupHome) {
			$groupHomes[] = ['groupId' => (string)$gid, 'groupName' => $this->people->displayName(Directory::GROUP, (string)$gid)] + $groupHome
				+ ['text' => CoordinateParser::format($groupHome['lat'], $groupHome['lon'])];
		}
		return [
			'home' => $home === null ? null : ['lat' => $home[0], 'lon' => $home[1], 'text' => CoordinateParser::format($home[0], $home[1])],
			'groupHomes' => $groupHomes,
			'games' => $games,
			'sources' => $this->directory->sources(),
			'bundled' => $this->bundled->status(),
			'autoSync' => $this->sites->autoSync(),
			'feedbackOpen' => $this->feedback->countOpen(),
			'upstream' => array_values(array_map(fn ($source): array => [
				'source' => $source->key(), 'label' => $source->name(), 'count' => count($this->feedback->upstream($source->key())['items']),
			], $this->registry->all())),
		];
	}

	/**
	 * Standort des Stammesheims aus Koordinaten oder einem Kartenlink; leer entfernt ihn.
	 *
	 * @return array<string, mixed>
	 */
	public function setHome(string $input): array {
		if (trim($input) === '') {
			$this->sites->setHome(null);
			return $this->state();
		}
		$coordinates = (new CoordinateParser())->parse($input);
		if ($coordinates === null) {
			throw new \InvalidArgumentException('Keine Koordinaten erkannt. Möglich sind etwa „51.0625, 7.0612“ oder ein Link von OpenStreetMap oder Google Maps, der auf das Stammesheim zeigt.');
		}
		$this->sites->setHome($coordinates);
		return $this->state();
	}

	/**
	 * Eine Nextcloud-Gruppe als Stamm mit Stammesheim; ohne Bezeichnung gilt der Gruppenname.
	 *
	 * @return array<string, mixed>
	 */
	public function setGroupHome(string $gid, string $label, string $input): array {
		if (!$this->people->exists(Directory::GROUP, $gid)) {
			throw new \InvalidArgumentException('Diese Gruppe gibt es nicht.');
		}
		$coordinates = (new CoordinateParser())->parse($input)
			?? throw new \InvalidArgumentException('Keine Koordinaten erkannt. Möglich sind etwa „51.0625, 7.0612“ oder ein Link von OpenStreetMap oder Google Maps.');
		$label = trim($label) === '' ? $this->people->displayName(Directory::GROUP, $gid) : mb_substr(trim($label), 0, 120);
		$this->sites->setGroupHome($gid, ['label' => $label, 'lat' => $coordinates[0], 'lon' => $coordinates[1]]);
		return $this->state();
	}

	/** @return array<string, mixed> */
	public function removeGroupHome(string $gid): array {
		$this->sites->setGroupHome($gid, null);
		return $this->state();
	}

	/**
	 * Mitgelieferten Datenstand sofort übernehmen, ohne auf ein Update zu warten.
	 *
	 * @return array{stats: array<string, mixed>, state: array<string, mixed>}
	 */
	public function importBundled(string $source): array {
		try {
			$stats = $this->bundled->import($source);
		} catch (SourceUnavailable $e) {
			throw new \InvalidArgumentException($e->getMessage(), 0, $e);
		}
		return ['stats' => self::statsFor($stats), 'state' => $this->state()];
	}

	/**
	 * Hochgeladenen Datenstand im Format lagerplaner-sites/1 übernehmen, wie
	 * occ lagerplaner:sites:import. Eigene Einträge („manual“) bleiben unberührt.
	 *
	 * @return array{stats: array<string, mixed>, state: array<string, mixed>}
	 */
	public function importUpload(string $content): array {
		if (strlen($content) > self::MAX_UPLOAD) {
			throw new \InvalidArgumentException('Die Datei ist größer als 10 MB.');
		}
		$document = json_decode($content, true);
		if (!is_array($document)) {
			throw new \InvalidArgumentException('Die Datei ist kein gültiges JSON.');
		}
		if (($document['source'] ?? null) === 'manual') {
			throw new \InvalidArgumentException('Die Quelle „manual“ ist für eigene Einträge reserviert.');
		}
		$stats = $this->directory->import($document);
		return ['stats' => self::statsFor($stats) + ['source' => (string)$document['source']], 'state' => $this->state()];
	}

	/** @return array{added: int, updated: int, missing: int, skipped: int, reasons: list<string>} */
	private static function statsFor(array $stats): array {
		return ['added' => $stats['added'], 'updated' => $stats['updated'], 'missing' => $stats['missing'],
			'skipped' => count($stats['skipped']), 'reasons' => array_slice($stats['skipped'], 0, 10),
			'unchanged' => (bool)($stats['unchanged'] ?? false), 'protected' => (int)($stats['protected'] ?? 0)];
	}

	/**
	 * Eine Datenquelle jetzt abgleichen (aus dem Netz).
	 *
	 * @return array{stats: array<string, mixed>, state: array<string, mixed>}
	 */
	public function syncSource(string $key): array {
		try {
			$stats = $this->synchronizer->sync($key);
		} catch (SourceUnavailable $e) {
			throw new \InvalidArgumentException($e->getMessage(), 0, $e);
		}
		return ['stats' => self::statsFor($stats), 'state' => $this->state()];
	}

	/** @return array<string, mixed> */
	public function setAutoSync(bool $enabled): array {
		$this->sites->setAutoSync($enabled);
		return $this->state();
	}

	/** @return array<string, mixed> Rückmeldung an eine Quelle als Text für ein Issue */
	public function contributions(string $key): array {
		return $this->feedback->upstream($key);
	}

	/** @return array<string, mixed> */
	public function markReported(string $key): array {
		$this->feedback->markReported($key);
		return $this->state();
	}

	/** @return list<array{id: string, label: string}> */
	public function searchGroups(string $term): array {
		return $this->people->searchGroups($term, 20);
	}

	/** @return array<string, mixed> */
	public function setGamesEnabled(bool $enabled): array {
		$this->games->setEnabled($enabled);
		if ($enabled) {
			// Katalog im Hintergrund laden, falls er noch fehlt
			try {
				$this->catalog->state();
			} catch (GamesUnavailableException) {
			}
		}
		return $this->state();
	}

	/** @return array<string, mixed> */
	public function setSourceVisible(string $source, bool $visible): array {
		$known = array_column($this->directory->sources(), 'source');
		if (!in_array($source, $known, true)) {
			throw new \InvalidArgumentException('Diese Quelle gibt es nicht.');
		}
		$disabled = array_values(array_diff($this->sites->disabledSources(), [$source]));
		if (!$visible) {
			$disabled[] = $source;
		}
		$this->sites->setDisabledSources($disabled);
		return $this->state();
	}
}

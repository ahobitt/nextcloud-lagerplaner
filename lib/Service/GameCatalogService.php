<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\CatalogGame;
use OCA\Lagerplaner\Db\CatalogGameMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Games\GameLicense;
use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Games\GameSource;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lokaler Spielekatalog (Konzept 7.2, 7.5).
 *
 * Die Standard-API kann nicht nach ACF-Feldern filtern. Deshalb wird der Katalog
 * vollständig abgeglichen und lokal durchsucht. Das hält die Last auf der Website
 * gering und übernommene Spiele bleiben bei einem Ausfall nutzbar.
 */
class GameCatalogService {
	public const MAX_PAGES = 50;
	/** Ist der Katalog älter, wird er bei der nächsten Suche neu abgeglichen. */
	public const STALE_AFTER = 7 * 24 * 3600;

	public function __construct(
		private GameSource $source,
		private CatalogGameMapper $games,
		private GamesConfig $config,
		private Transaction $transaction,
		private ITimeFactory $time,
		private GamesImportQueue $queue,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->isEnabled();
	}

	public function license(): GameLicense {
		return $this->source->license();
	}

	/**
	 * @return array{added: int, updated: int, removed: int, skipped: int}
	 * @throws GamesUnavailableException
	 */
	public function sync(): array {
		if (!$this->isEnabled()) {
			throw new GamesUnavailableException('Die Spielesuche ist nicht eingeschaltet.');
		}

		$fetched = [];
		$skipped = 0;
		$page = 1;
		do {
			$result = $this->source->fetchPage($page);
			foreach ($result['games'] as $game) {
				$fetched[$game->sourceId] = $game;
			}
			$skipped += $result['skipped'];
			$pages = min($result['totalPages'], self::MAX_PAGES);
			$page++;
		} while ($page <= $pages);

		$now = $this->time->getTime();
		$stats = $this->transaction->run(function () use ($fetched, $now): array {
			$existing = [];
			foreach ($this->games->findBySource($this->source->key()) as $row) {
				$existing[(int)$row->getSourceId()] = $row;
			}

			// Schutz vor Fehlantworten: Fehlen auf einmal mehr als die Hälfte der Spiele, wird nichts gelöscht.
			$mayRemove = $fetched !== [] && count($fetched) >= count($existing) / 2;

			$stats = ['added' => 0, 'updated' => 0, 'removed' => 0];
			foreach ($fetched as $sourceId => $game) {
				$row = $existing[$sourceId] ?? new CatalogGame();
				$isNew = !isset($existing[$sourceId]);
				$row->fillFrom($game, $now);
				if ($isNew) {
					$this->games->insert($row);
					$stats['added']++;
				} else {
					$this->games->update($row);
					$stats['updated']++;
				}
				unset($existing[$sourceId]);
			}
			if ($mayRemove) {
				foreach ($existing as $row) {
					$this->games->delete($row);
					$stats['removed']++;
				}
			}
			return $stats;
		});
		$this->config->setSyncedAt($now);
		return $stats + ['skipped' => $skipped];
	}

	/**
	 * @return array{state: string, items: list<array<string, mixed>>, total: int, syncedAt: int, license: array<string, string>}
	 * @throws GamesUnavailableException
	 */
	public function search(GameQuery $query, int $limit = 30, int $offset = 0): array {
		if ($this->state() === 'importing') {
			return ['state' => 'importing', 'items' => [], 'total' => 0, 'syncedAt' => 0, 'license' => $this->source->license()->toArray()];
		}
		$scored = [];
		foreach ($this->games->findBySource($this->source->key()) as $row) {
			$game = $row->toDomain();
			if ($query->matches($game)) {
				$scored[] = [$query->score($game), $game, $row];
			}
		}
		usort($scored, static fn (array $a, array $b): int => [$b[0], mb_strtolower($a[1]->title)] <=> [$a[0], mb_strtolower($b[1]->title)]);

		return [
			'state' => 'ready',
			'items' => array_map(static fn (array $entry): array => $entry[2]->summaryArray(), array_slice($scored, $offset, $limit)),
			'total' => count($scored),
			'syncedAt' => $this->config->syncedAt(),
			'license' => $this->source->license()->toArray(),
		];
	}

	/** @throws NotFoundException */
	public function get(int $id): CatalogGame {
		try {
			return $this->games->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Spiel nicht gefunden.');
		}
	}

	/** @return array{types: list<string>, environments: list<string>} */
	public function facets(): array {
		if ($this->state() === 'importing') {
			return ['types' => [], 'environments' => []];
		}
		$types = [];
		$environments = [];
		foreach ($this->games->findBySource($this->source->key()) as $row) {
			$game = $row->toDomain();
			foreach ($game->types as $type) {
				$types[$type] = true;
			}
			foreach ($game->environments as $environment) {
				$environments[$environment] = true;
			}
		}
		$types = array_keys($types);
		$environments = array_keys($environments);
		sort($types);
		sort($environments);
		return ['types' => $types, 'environments' => $environments];
	}

	/**
	 * Stand des Katalogs. Abgeglichen wird nie im Aufruf der Oberfläche, sondern als
	 * Hintergrundaufgabe: Ohne Katalog heißt der Stand „importing“; ein veralteter Katalog
	 * bleibt nutzbar, während er im Hintergrund aktualisiert wird.
	 *
	 * @throws GamesUnavailableException wenn die Spielesuche nicht eingeschaltet ist
	 */
	/** Anzahl der Spiele im Katalog */
	public function count(): int {
		return count($this->games->findBySource($this->source->key()));
	}

	public function state(): string {
		if (!$this->isEnabled()) {
			throw new GamesUnavailableException('Die Spielesuche ist nicht eingeschaltet. Die Nextcloud-Administration kann sie aktivieren.');
		}
		$syncedAt = $this->config->syncedAt();
		if ($syncedAt === 0) {
			$this->queue->request();
			return $this->config->syncedAt() === 0 ? 'importing' : 'ready';
		}
		if ($this->time->getTime() - $syncedAt >= self::STALE_AFTER) {
			$this->queue->request();
		}
		return 'ready';
	}
}

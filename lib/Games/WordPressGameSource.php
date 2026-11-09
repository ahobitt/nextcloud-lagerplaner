<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Games;

use OCA\Lagerplaner\Domain\Games\GameLicense;
use OCA\Lagerplaner\Domain\Games\InvalidGameData;
use OCA\Lagerplaner\Domain\Games\WordPressGameNormalizer;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCP\Http\Client\IClientService;

/**
 * Liest Pfadfinder-Spiele.de über die Standard-REST-API von WordPress.
 *
 * Es werden nur die benötigten Felder abgefragt; ohne _fields enthielte jede
 * Antwort zusätzlich den vollständigen SEO-Kopf jedes Beitrags.
 */
class WordPressGameSource implements GameSource {
	public const KEY = 'pfadfinder-spiele.de';
	private const ENDPOINT = 'https://pfadfinder-spiele.de/wp-json/wp/v2/posts';
	private const FIELDS = 'id,status,title,excerpt,content,link,modified_gmt,acf';
	private const PER_PAGE = 100;

	private WordPressGameNormalizer $normalizer;

	public function __construct(private IClientService $clients) {
		$this->normalizer = new WordPressGameNormalizer(self::KEY, 'pfadfinder-spiele.de');
	}

	public function key(): string {
		return self::KEY;
	}

	/** Lizenz laut Betreiberin: gilt für alle Spieltexte der Seite. */
	public function license(): GameLicense {
		return new GameLicense(
			'CC BY-NC-SA 4.0',
			'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.de',
			'Dorothea Schümann, Pfadfinder-Spiele.de',
		);
	}

	public function fetchPage(int $page): array {
		try {
			$response = $this->clients->newClient()->get(self::ENDPOINT, [
				'query' => [
					'per_page' => self::PER_PAGE,
					'page' => $page,
					'_fields' => self::FIELDS,
					'orderby' => 'id',
					'order' => 'asc',
				],
				'timeout' => 20,
				'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Nextcloud-Lagerplaner'],
			]);
		} catch (\Throwable $e) {
			throw new GamesUnavailableException('Pfadfinder-Spiele.de ist gerade nicht erreichbar.', 0, $e);
		}
		if ($response->getStatusCode() !== 200) {
			throw new GamesUnavailableException('Pfadfinder-Spiele.de hat mit Status ' . $response->getStatusCode() . ' geantwortet.');
		}

		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		$posts = json_decode((string)$body, true, 64);
		if (!is_array($posts) || !array_is_list($posts)) {
			throw new GamesUnavailableException('Die Antwort von Pfadfinder-Spiele.de ist nicht lesbar.');
		}

		$games = [];
		$skipped = 0;
		foreach ($posts as $post) {
			try {
				if (!is_array($post)) {
					throw new InvalidGameData('Kein Objekt.');
				}
				$games[] = $this->normalizer->normalize($post);
			} catch (InvalidGameData) {
				$skipped++;
			}
		}
		return ['games' => $games, 'skipped' => $skipped, 'totalPages' => max(1, (int)$response->getHeader('X-WP-TotalPages'))];
	}
}

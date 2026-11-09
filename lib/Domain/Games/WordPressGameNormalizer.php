<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/**
 * Übersetzt einen Beitrag der WordPress-REST-API mit ACF-Feldern in ein Spiel.
 *
 * Feldbelegung von Pfadfinder-Spiele.de (Stand Oktober 2026):
 * spielart, umgebung, material, gruppenzusammensetzung, gruppengrose: Listen von Texten;
 * alter: Mindestalter; ungefahre_dauer: Minuten; bekannt_als: kommagetrennte Namen.
 * Fremde Daten werden defensiv gelesen; unbrauchbare Werte gelten als unbekannt.
 */
final class WordPressGameNormalizer {
	private const MAX_TITLE = 255;
	private const MAX_LIST_ITEM = 100;

	public function __construct(
		private readonly string $source,
		/** Nur Links auf diesen Host werden übernommen, zum Beispiel pfadfinder-spiele.de */
		private readonly string $host,
		private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
	) {
	}

	/**
	 * @param array<string, mixed> $post
	 * @throws InvalidGameData
	 */
	public function normalize(array $post): Game {
		$id = $post['id'] ?? null;
		if (!is_int($id) || $id <= 0) {
			throw new InvalidGameData('Beitrag ohne gültige Kennung.');
		}
		if (isset($post['status']) && $post['status'] !== 'publish') {
			throw new InvalidGameData("Beitrag $id ist nicht veröffentlicht.");
		}
		$title = $this->plain($post['title']['rendered'] ?? null);
		if ($title === '') {
			throw new InvalidGameData("Beitrag $id hat keinen Titel.");
		}
		$link = $post['link'] ?? '';
		if (!is_string($link) || !$this->isOwnLink($link)) {
			throw new InvalidGameData("Beitrag $id hat keinen Link auf {$this->host}.");
		}

		$acf = is_array($post['acf'] ?? null) ? $post['acf'] : [];
		[$groupMin, $groupMax] = $this->groupSize($acf['gruppengrose'] ?? []);

		return new Game(
			$this->source,
			$id,
			mb_substr($title, 0, self::MAX_TITLE),
			$this->plain($post['excerpt']['rendered'] ?? null),
			$this->sanitizer->clean(is_string($post['content']['rendered'] ?? null) ? $post['content']['rendered'] : ''),
			$link,
			is_string($post['modified_gmt'] ?? null) ? $post['modified_gmt'] : '',
			$this->positiveInt($acf['alter'] ?? null, 99),
			$this->positiveInt($acf['ungefahre_dauer'] ?? null, 24 * 60),
			$groupMin,
			$groupMax,
			$this->stringList($acf['spielart'] ?? []),
			$this->stringList($acf['umgebung'] ?? []),
			$this->stringList($acf['material'] ?? []),
			$this->stringList($acf['gruppenzusammensetzung'] ?? []),
			$this->stringList(is_string($acf['bekannt_als'] ?? null) ? explode(',', $acf['bekannt_als']) : []),
		);
	}

	/**
	 * Gruppengröße aus Freitext-Spannen wie „6 - 25 Personen“ oder „25 und mehr Personen“.
	 * Mehrere Angaben werden zur kleinsten Unter- und größten Obergrenze zusammengefasst.
	 *
	 * @return array{0: ?int, 1: ?int} [Minimum, Maximum]; Maximum null bedeutet nach oben offen
	 */
	public function groupSize(mixed $values): array {
		$mins = [];
		$maxs = [];
		$open = false;
		foreach ($this->stringList($values) as $value) {
			if (preg_match('/(\d+)\s*[-–]\s*(\d+)/u', $value, $m) === 1) {
				$mins[] = (int)$m[1];
				$maxs[] = (int)$m[2];
			} elseif (preg_match('/(?:ab\s*)?(\d+)\s*(?:und mehr|\+|oder mehr)/iu', $value, $m) === 1
				|| preg_match('/\bab\s*(\d+)/iu', $value, $m) === 1) {
				$mins[] = (int)$m[1];
				$open = true;
			} elseif (preg_match('/bis\s*(\d+)/iu', $value, $m) === 1) {
				$maxs[] = (int)$m[1];
			} elseif (preg_match('/^(\d+)\s*Person/iu', $value, $m) === 1) {
				$mins[] = (int)$m[1];
				$maxs[] = (int)$m[1];
			}
		}
		$min = $mins === [] ? null : min($mins);
		$max = ($open || $maxs === []) ? null : max($maxs);
		if ($min !== null && $max !== null && $max < $min) {
			$max = $min;
		}
		return [$min, $max];
	}

	private function isOwnLink(string $link): bool {
		$parts = parse_url($link);
		return ($parts['scheme'] ?? '') === 'https'
			&& in_array(strtolower($parts['host'] ?? ''), [$this->host, 'www.' . $this->host], true);
	}

	private function plain(mixed $html): string {
		if (!is_string($html)) {
			return '';
		}
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	private function positiveInt(mixed $value, int $max): ?int {
		if (is_string($value) && preg_match('/^\d{1,4}$/', trim($value)) === 1) {
			$value = (int)trim($value);
		}
		return is_int($value) && $value > 0 && $value <= $max ? $value : null;
	}

	/** @return list<string> */
	private function stringList(mixed $values): array {
		if (!is_array($values)) {
			return [];
		}
		$result = [];
		foreach ($values as $value) {
			if (!is_string($value)) {
				continue;
			}
			$value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($value !== '' && mb_strlen($value) <= self::MAX_LIST_ITEM) {
				$result[$value] = true;
			}
		}
		return array_keys($result);
	}
}

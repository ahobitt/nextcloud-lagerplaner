<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/** Suchkriterien der Spielesuche (Konzept 7.2). Leere Kriterien schränken nicht ein. */
final class GameQuery {
	public function __construct(
		public readonly string $text = '',
		/** Alter der jüngsten Teilnehmenden; das Spiel muss ab diesem Alter geeignet sein. */
		public readonly ?int $age = null,
		public readonly ?int $maxDuration = null,
		public readonly ?int $groupSize = null,
		public readonly string $environment = '',
		public readonly string $type = '',
		/** Spiele ohne Altersangabe trotz Altersfilter zeigen (gekennzeichnet) */
		public readonly bool $includeUnknownAge = true,
	) {
	}

	/**
	 * @param array<string, mixed> $params
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(array $params): self {
		$int = static function (string $key, int $max) use ($params): ?int {
			$value = $params[$key] ?? null;
			if ($value === null || $value === '') {
				return null;
			}
			if (is_string($value) && preg_match('/^\d{1,5}$/', $value) === 1) {
				$value = (int)$value;
			}
			if (!is_int($value) || $value < 0 || $value > $max) {
				throw new \InvalidArgumentException('Ungültiger Suchwert: ' . $key);
			}
			return $value;
		};
		$text = $params['q'] ?? '';
		if (!is_string($text) || mb_strlen($text) > 100) {
			throw new \InvalidArgumentException('Der Suchbegriff darf höchstens 100 Zeichen lang sein.');
		}
		$environment = $params['environment'] ?? '';
		$type = $params['type'] ?? '';
		if (!is_string($environment) || !is_string($type) || mb_strlen($environment) > 100 || mb_strlen($type) > 100) {
			throw new \InvalidArgumentException('Ungültiger Filter.');
		}
		$unknownAge = $params['unknownAge'] ?? '1';
		return new self(trim($text), $int('age', 99), $int('duration', 1440), $int('groupSize', 5000), $environment, $type,
			!in_array($unknownAge, ['0', 'false', false, 0], true));
	}

	public function matches(Game $game): bool {
		if ($this->age !== null && $game->minAge !== null && $game->minAge > $this->age) {
			return false;
		}
		// Unbekanntes Alter heißt nicht „geeignet“: Auf Wunsch ausblenden, sonst kennzeichnet die Oberfläche es.
		if ($this->age !== null && $game->minAge === null && !$this->includeUnknownAge) {
			return false;
		}
		if ($this->maxDuration !== null && $game->durationMinutes !== null && $game->durationMinutes > $this->maxDuration) {
			return false;
		}
		if ($this->groupSize !== null) {
			if ($game->groupMin !== null && $this->groupSize < $game->groupMin) {
				return false;
			}
			if ($game->groupMax !== null && $this->groupSize > $game->groupMax) {
				return false;
			}
		}
		if ($this->environment !== '' && !in_array($this->environment, $game->environments, true)) {
			return false;
		}
		if ($this->type !== '' && !in_array($this->type, $game->types, true)) {
			return false;
		}
		return $this->text === '' || $this->score($game) > 0;
	}

	/** Treffer im Titel oder in alternativen Namen zählen mehr als in der Beschreibung. */
	public function score(Game $game): int {
		if ($this->text === '') {
			return 1;
		}
		$needle = self::fold($this->text);
		$score = 0;
		if (str_contains(self::fold($game->title), $needle)) {
			$score += 10;
		}
		foreach ($game->knownAs as $name) {
			if (str_contains(self::fold($name), $needle)) {
				$score += 8;
			}
		}
		foreach ($game->types as $type) {
			if (str_contains(self::fold($type), $needle)) {
				$score += 3;
			}
		}
		if (str_contains(self::fold($game->summary), $needle)) {
			$score += 2;
		}
		return $score;
	}

	/** Kleinschreibung und Umlaute vereinheitlichen, damit „gelaende“ auch „Gelände“ findet. */
	public static function fold(string $text): string {
		return strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
	}
}

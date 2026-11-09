<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Templates;

/** Ein Programmpunkt in einer Vorlage (Konzept 6.4), ohne personenbezogene Daten. */
final class TemplateItem {
	public const CATEGORIES = ['program', 'ritual', 'free', 'evening', 'hike', 'wide_game', 'organisation'];

	/**
	 * @param list<string> $groups Altersstufen-Schlüssel; leer heißt alle
	 * @param list<array{name: string, quantity: ?float, unit: ?string, category: string, reusable: bool}> $materials
	 */
	public function __construct(
		/** Beginn HH:MM; bei Einzelvorlagen leer */
		public readonly string $startTime,
		public readonly int $durationMinutes,
		public readonly string $title,
		public readonly string $description,
		public readonly string $category,
		public readonly string $weatherAlternative,
		public readonly array $groups,
		public readonly array $materials = [],
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(array $raw, bool $needsStart): self {
		$title = is_string($raw['title'] ?? null) ? trim($raw['title']) : '';
		$start = is_string($raw['startTime'] ?? null) ? $raw['startTime'] : '';
		$duration = $raw['durationMinutes'] ?? null;
		if ($title === '' || mb_strlen($title) > 255) {
			throw new \InvalidArgumentException('Jeder Programmpunkt der Vorlage braucht einen Titel.');
		}
		if ($needsStart && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) !== 1) {
			throw new \InvalidArgumentException('Ungültige Uhrzeit in der Vorlage.');
		}
		if (!is_int($duration) || $duration < 5 || $duration > 1440) {
			throw new \InvalidArgumentException('Ungültige Dauer in der Vorlage.');
		}
		$category = is_string($raw['category'] ?? null) && in_array($raw['category'], self::CATEGORIES, true) ? $raw['category'] : 'program';
		$groups = array_values(array_filter(is_array($raw['groups'] ?? null) ? $raw['groups'] : [], static fn ($g): bool => is_string($g) && preg_match('/^[a-z0-9_-]{1,32}$/', $g) === 1));
		$materials = [];
		foreach (is_array($raw['materials'] ?? null) ? array_slice($raw['materials'], 0, 30) : [] as $material) {
			$name = is_array($material) && is_string($material['name'] ?? null) ? trim($material['name']) : '';
			if ($name === '' || mb_strlen($name) > 120) {
				continue;
			}
			$quantity = $material['quantity'] ?? null;
			$materials[] = [
				'name' => $name,
				'quantity' => is_int($quantity) || is_float($quantity) ? ((float)$quantity > 0 ? (float)$quantity : null) : null,
				'unit' => is_string($material['unit'] ?? null) && mb_strlen($material['unit']) <= 20 ? $material['unit'] : null,
				'category' => is_string($material['category'] ?? null) ? $material['category'] : 'other',
				'reusable' => (bool)($material['reusable'] ?? true),
			];
		}
		return new self(
			$needsStart ? $start : '',
			$duration,
			$title,
			mb_substr(is_string($raw['description'] ?? null) ? trim($raw['description']) : '', 0, 5000),
			$category,
			mb_substr(is_string($raw['weatherAlternative'] ?? null) ? trim($raw['weatherAlternative']) : '', 0, 2000),
			$groups,
			$materials,
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'startTime' => $this->startTime,
			'durationMinutes' => $this->durationMinutes,
			'title' => $this->title,
			'description' => $this->description,
			'category' => $this->category,
			'weatherAlternative' => $this->weatherAlternative,
			'groups' => $this->groups,
			'materials' => $this->materials,
		];
	}
}

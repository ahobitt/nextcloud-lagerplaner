<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Catering;

/**
 * Eine Bedarfskombination: Jede Person gehört zu genau einer (Konzept 8.5).
 *
 * Sonderfälle nach 8.7: „none“ (keine Einschränkungen bekannt),
 * „unanswered“ (Frage nicht beantwortet), „clarify“ (Angaben müssen geklärt werden).
 */
final class DietaryCombination {
	public const NONE = 'none';
	public const UNANSWERED = 'unanswered';
	public const CLARIFY = 'clarify';

	/** @param list<Requirement> $requirements */
	private function __construct(
		public readonly string $key,
		public readonly array $requirements,
		public readonly int $count,
	) {
	}

	public static function fromKey(string $key, int $count): self {
		if ($count < 0) {
			throw new \InvalidArgumentException('Die Anzahl darf nicht negativ sein.');
		}
		if (in_array($key, [self::NONE, self::UNANSWERED, self::CLARIFY], true)) {
			return new self($key, [], $count);
		}
		$requirements = [];
		foreach (explode('+', $key) as $part) {
			$requirement = Requirement::tryFrom($part);
			if ($requirement === null) {
				throw new \InvalidArgumentException('Unbekannte Anforderung: ' . $part);
			}
			$requirements[$requirement->value] = $requirement;
		}
		return self::fromRequirements(array_values($requirements), $count);
	}

	/** @param list<Requirement> $requirements */
	public static function fromRequirements(array $requirements, int $count): self {
		if ($requirements === []) {
			return new self(self::NONE, [], $count);
		}
		$values = array_unique(array_map(static fn (Requirement $r): string => $r->value, $requirements));
		sort($values);
		return new self(
			implode('+', $values),
			array_map(static fn (string $v): Requirement => Requirement::from($v), $values),
			$count,
		);
	}

	public function isUnresolved(): bool {
		return $this->key === self::UNANSWERED || $this->key === self::CLARIFY;
	}

	public function needsManualCheck(): bool {
		foreach ($this->requirements as $requirement) {
			if ($requirement->needsManualCheck()) {
				return true;
			}
		}
		return false;
	}

	public function label(): string {
		return match ($this->key) {
			self::NONE => 'Keine Einschränkung',
			self::UNANSWERED => 'Frage nicht beantwortet',
			self::CLARIFY => 'Angaben müssen geklärt werden',
			default => ucfirst(implode(' + ', array_map(static fn (Requirement $r): string => $r->label(), $this->requirements))),
		};
	}
}

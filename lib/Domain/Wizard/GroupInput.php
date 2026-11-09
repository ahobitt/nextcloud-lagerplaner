<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

/** Eine Altersstufe beziehungsweise Planungsgruppe mit geschätzter Personenzahl. */
final class GroupInput {
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly int $count,
		public readonly bool $separateProgram,
		public readonly bool $isLeaders,
		public readonly float $portionFactor,
		public readonly int $blockMinutes,
	) {
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'key' => $this->key,
			'label' => $this->label,
			'count' => $this->count,
			'separateProgram' => $this->separateProgram,
			'isLeaders' => $this->isLeaders,
			'portionFactor' => $this->portionFactor,
			'blockMinutes' => $this->blockMinutes,
		];
	}
}

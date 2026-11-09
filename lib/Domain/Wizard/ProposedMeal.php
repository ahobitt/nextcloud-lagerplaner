<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

final class ProposedMeal implements ProposedElement {
	public function __construct(
		public readonly string $day,
		public readonly string $mealType,
		public readonly string $time,
		public readonly bool $selfCatered,
		public readonly string $note,
		private readonly string $templateVersion,
		/** Wird unterwegs während einer Aktivität eingenommen (Lunchpaket), überschneidet sich also gewollt. */
		public readonly bool $portable = false,
	) {
	}

	public function ruleKey(): string {
		return $this->day . '|' . $this->mealType;
	}

	public function templateRef(): string {
		return $this->templateVersion . '#meal.' . $this->mealType;
	}

	public function fingerprint(): string {
		return hash('sha256', json_encode([$this->time, $this->selfCatered, $this->note], JSON_THROW_ON_ERROR));
	}

	public function toArray(): array {
		return [
			'ruleKey' => $this->ruleKey(),
			'templateRef' => $this->templateRef(),
			'day' => $this->day,
			'mealType' => $this->mealType,
			'time' => $this->time,
			'selfCatered' => $this->selfCatered,
			'note' => $this->note,
		];
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

final class ProposedProgramItem implements ProposedElement {
	/** @param list<string> $groups Gruppenkennungen; leer bedeutet „alle“ */
	public function __construct(
		public readonly string $slot,
		public readonly string $day,
		public readonly string $startTime,
		public readonly int $durationMinutes,
		public readonly string $title,
		public readonly string $description,
		public readonly array $groups,
		public readonly string $category,
		public readonly bool $weatherAlternativeNeeded,
		private readonly string $templateVersion,
		/** Vorschlag für schlechtes Wetter; leer, wenn keiner nötig ist */
		public readonly string $weatherAlternative = '',
		/** Eigene Herkunftskennung, etwa aus einer Tagesvorlage */
		private readonly ?string $customTemplateRef = null,
	) {
	}

	public function ruleKey(): string {
		return $this->day . '|' . $this->slot . '|' . ($this->groups === [] ? 'all' : implode('+', $this->groups));
	}

	public function templateRef(): string {
		return $this->customTemplateRef ?? $this->templateVersion . '#' . $this->slot;
	}

	public function fingerprint(): string {
		return hash('sha256', json_encode([
			$this->title, $this->description, $this->startTime, $this->durationMinutes,
			$this->groups, $this->category, $this->weatherAlternativeNeeded, $this->weatherAlternative, $this->templateRef(),
		], JSON_THROW_ON_ERROR));
	}

	public function toArray(): array {
		return [
			'ruleKey' => $this->ruleKey(),
			'templateRef' => $this->templateRef(),
			'day' => $this->day,
			'startTime' => $this->startTime,
			'durationMinutes' => $this->durationMinutes,
			'title' => $this->title,
			'description' => $this->description,
			'groups' => $this->groups,
			'category' => $this->category,
			'weatherAlternativeNeeded' => $this->weatherAlternativeNeeded,
			'weatherAlternative' => $this->weatherAlternative,
		];
	}
}

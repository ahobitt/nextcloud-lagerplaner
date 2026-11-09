<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

final class ProposedTask implements ProposedElement {
	public function __construct(
		public readonly string $key,
		public readonly string $title,
		public readonly string $description,
		public readonly string $dueDate,
		public readonly bool $mandatory,
		private readonly string $templateVersion,
	) {
	}

	public function ruleKey(): string {
		return 'task|' . $this->key;
	}

	public function templateRef(): string {
		return $this->templateVersion . '#task.' . $this->key;
	}

	public function fingerprint(): string {
		return hash('sha256', json_encode([$this->title, $this->description, $this->dueDate, $this->mandatory], JSON_THROW_ON_ERROR));
	}

	public function toArray(): array {
		return [
			'ruleKey' => $this->ruleKey(),
			'templateRef' => $this->templateRef(),
			'key' => $this->key,
			'title' => $this->title,
			'description' => $this->description,
			'dueDate' => $this->dueDate,
			'mandatory' => $this->mandatory,
		];
	}
}

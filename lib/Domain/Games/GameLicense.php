<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Games;

/** Lizenz und Namensnennung einer Spielequelle (Konzept 7.6). */
final class GameLicense {
	public function __construct(
		public readonly string $name,
		public readonly string $url,
		public readonly string $attribution,
	) {
	}

	/** @return array<string, string> */
	public function toArray(): array {
		return ['name' => $this->name, 'url' => $this->url, 'attribution' => $this->attribution];
	}
}

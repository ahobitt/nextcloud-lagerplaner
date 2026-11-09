<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Sites;

/** Alle eingebundenen Datenquellen. Eine neue Quelle wird hier ergänzt. */
class SiteSources {
	/** @var array<string, SiteSource> */
	private array $sources = [];

	public function __construct(SchwarzzeltItSource $schwarzzelt) {
		foreach ([$schwarzzelt] as $source) {
			$this->sources[$source->key()] = $source;
		}
	}

	/** @return array<string, SiteSource> */
	public function all(): array {
		return $this->sources;
	}

	public function get(string $key): ?SiteSource {
		return $this->sources[$key] ?? null;
	}
}

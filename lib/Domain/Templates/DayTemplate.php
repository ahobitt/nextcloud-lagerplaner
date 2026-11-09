<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Templates;

/** Tagesablauf als Vorlage: Programmpunkte mit Uhrzeiten, versioniert (Konzept 6.4). */
final class DayTemplate {
	public const MAX_ITEMS = 40;

	/** @param list<TemplateItem> $items nach Beginn sortiert */
	public function __construct(
		public readonly int $id,
		public readonly int $version,
		public readonly string $name,
		public readonly array $items,
	) {
	}

	/**
	 * @param array<string, mixed> $data {items: list<...>}
	 * @throws \InvalidArgumentException
	 */
	public static function items(array $data): array {
		$raw = $data['items'] ?? null;
		if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_ITEMS) {
			throw new \InvalidArgumentException('Eine Tagesvorlage braucht zwischen 1 und ' . self::MAX_ITEMS . ' Programmpunkte.');
		}
		$items = array_map(static fn (mixed $item): TemplateItem => TemplateItem::fromArray(is_array($item) ? $item : [], true), $raw);
		usort($items, static fn (TemplateItem $a, TemplateItem $b): int => strcmp($a->startTime, $b->startTime));
		return $items;
	}

	/** Herkunftskennung für erzeugte Elemente, damit sie auf Vorlage und Version zurückführbar sind. */
	public function ref(int $index): string {
		return 'user-template:' . $this->id . '@v' . $this->version . '#' . $index;
	}
}

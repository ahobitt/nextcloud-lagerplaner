<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Cookbook;

/**
 * Zugriff auf die Rezeptsammlung von Nextcloud Cookbook einer Person.
 *
 * Cookbook speichert jedes Rezept als Ordner mit recipe.json (schema.org/Recipe) im
 * Rezeptordner der Person; die Kennung eines Rezepts ist die Dateikennung seines Ordners.
 */
interface CookbookLibrary {
	public const READY = 'ready';
	public const NOT_INSTALLED = 'not_installed';
	/** Cookbook ist da, wurde aber noch nie geöffnet; dann gibt es noch keinen Rezeptordner. */
	public const NO_FOLDER = 'no_folder';

	public function status(string $uid): string;

	/** @return list<array{id: int, name: string, category: string, keywords: string, servings: ?int, modified: int}> */
	public function list(string $uid): array;

	/**
	 * @return array{data: array<string, mixed>, modified: int}
	 * @throws \OCA\Lagerplaner\Service\NotFoundException
	 */
	public function read(string $uid, int $id): array;

	/**
	 * Legt ein neues Rezept im Rezeptordner an.
	 *
	 * @param array<string, mixed> $data recipe.json
	 * @return array{id: int, modified: int}
	 */
	public function create(string $uid, string $name, array $data): array;
}

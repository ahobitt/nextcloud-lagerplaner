<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Access;

/** Benutzer und Gruppen der Nextcloud, soweit der Lagerplaner sie braucht. */
interface Directory {
	public const USER = 'user';
	public const GROUP = 'group';

	/** @return list<string> Gruppen, in denen die Person Mitglied ist */
	public function groupIdsOf(string $uid): array;

	public function exists(string $type, string $id): bool;

	public function isAdmin(string $uid): bool;

	/** @return list<string> Benutzer-IDs der Gruppenmitglieder (höchstens 500) */
	public function groupMembers(string $gid): array;

	public function displayName(string $type, string $id): string;

	/**
	 * Darf die anfragende Person nach den Freigabe-Einstellungen der Nextcloud mit dieser
	 * Person oder Gruppe teilen? Gilt auch beim Hinzufügen über die API.
	 */
	public function canShareWith(string $type, string $id): bool;

	/**
	 * Personen und Gruppen, mit denen die anfragende Person teilen darf.
	 *
	 * @return list<array{type: string, id: string, displayName: string}>
	 */
	public function search(string $term, int $limit): array;

	/**
	 * Gruppen nach Name oder Kennung, unabhängig von Freigabe-Einstellungen; nur für die
	 * Verwaltung, etwa um Stämme festzulegen.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function searchGroups(string $term, int $limit): array;

	/** Gruppenadministration (Nextcloud „Gruppenadmin“) dieser Gruppe oder Administration */
	public function isGroupAdmin(string $uid, string $gid): bool;
}

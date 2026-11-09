<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Access;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Group\ISubAdmin;
use OCP\Share\IShare;

/**
 * Nutzt die Personensuche der Nextcloud-Freigaben. Sie beachtet die Einstellungen der
 * Instanz, etwa „nur mit Mitgliedern der eigenen Gruppen teilen“ oder eingeschränkte
 * Vervollständigung von Namen.
 */
class NextcloudDirectory implements Directory {
	/** @var array<string, list<string>> */
	private array $groupCache = [];

	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
		private ISearch $collaborators,
		private ISubAdmin $subAdmins,
	) {
	}

	public function groupIdsOf(string $uid): array {
		if (!isset($this->groupCache[$uid])) {
			$user = $this->users->get($uid);
			$this->groupCache[$uid] = $user === null ? [] : array_values(array_map('strval', $this->groups->getUserGroupIds($user)));
		}
		return $this->groupCache[$uid];
	}

	public function isAdmin(string $uid): bool {
		return $this->groups->isAdmin($uid);
	}

	public function groupMembers(string $gid): array {
		return array_values(array_map('strval', array_keys($this->groups->displayNamesInGroup($gid, '', 500))));
	}

	public function exists(string $type, string $id): bool {
		return match ($type) {
			self::USER => $this->users->userExists($id),
			self::GROUP => $this->groups->groupExists($id),
			default => false,
		};
	}

	public function displayName(string $type, string $id): string {
		if ($type === self::USER) {
			return $this->users->getDisplayName($id) ?? $id;
		}
		return $this->groups->get($id)?->getDisplayName() ?? $id;
	}

	/** Dieselbe Personensuche wie beim Teilen von Dateien; nur ein exakter Treffer zählt. */
	public function canShareWith(string $type, string $id): bool {
		foreach ($this->search($id, 50) as $entry) {
			if ($entry['type'] === $type && $entry['id'] === $id) {
				return true;
			}
		}
		return false;
	}

	public function search(string $term, int $limit): array {
		[$result] = $this->collaborators->search($term, [IShare::TYPE_USER, IShare::TYPE_GROUP], false, $limit, 0);
		$found = [];
		foreach ([$result['exact'] ?? [], $result] as $bucket) {
			foreach (['users' => self::USER, 'groups' => self::GROUP] as $key => $type) {
				foreach ($bucket[$key] ?? [] as $entry) {
					$id = $entry['value']['shareWith'] ?? null;
					if (!is_string($id) || isset($found["$type:$id"])) {
						continue;
					}
					$found["$type:$id"] = ['type' => $type, 'id' => $id, 'displayName' => (string)($entry['label'] ?? $id)];
				}
			}
		}
		return array_values($found);
	}

	public function searchGroups(string $term, int $limit): array {
		$list = [];
		foreach ($this->groups->search(trim($term), $limit) as $group) {
			$list[] = ['id' => $group->getGID(), 'label' => $group->getDisplayName() ?: $group->getGID()];
		}
		return $list;
	}

	public function isGroupAdmin(string $uid, string $gid): bool {
		if ($this->isAdmin($uid)) {
			return true;
		}
		$user = $this->users->get($uid);
		$group = $this->groups->get($gid);
		return $user !== null && $group !== null && $this->subAdmins->isSubAdminOfGroup($user, $group);
	}
}

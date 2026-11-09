<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\CampMember;
use OCA\Lagerplaner\Db\CampMemberMapper;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Access\Role;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Wer an einem Lager mitarbeitet (Konzept 18.1).
 *
 * Mitglied kann eine Person oder eine ganze Nextcloud-Gruppe sein, zum Beispiel die
 * Leiterrunde. Wer neu in die Gruppe kommt, hat sofort Zugriff; wer sie verlässt,
 * verliert ihn. Verwalten darf nur die Lagerleitung.
 */
class TeamService {
	public const MAX_MEMBERS = 50;

	public function __construct(
		private CampService $camps,
		private CampMemberMapper $members,
		private Directory $directory,
		private ITimeFactory $time,
	) {
	}

	/** @return array{owner: array<string, mixed>, members: list<array<string, mixed>>} */
	public function list(int $campId, string $uid): array {
		$camp = $this->camps->require($campId, $uid, Permission::View);
		$owner = $camp->getOwnerUid();
		return [
			'owner' => ['type' => Directory::USER, 'id' => $owner, 'displayName' => $this->directory->displayName(Directory::USER, $owner), 'roles' => [Role::Leitung->value]],
			'members' => array_map(fn (CampMember $m): array => $this->toArray($m), $this->members->findByCamp($campId)),
		];
	}

	/**
	 * Fügt eine Person oder Gruppe hinzu. Ist sie schon Mitglied, werden ihre Rollen ersetzt.
	 *
	 * @param mixed $roles Liste von Rollenschlüsseln
	 * @return array<string, mixed>
	 */
	public function add(int $campId, string $uid, string $type, string $memberId, mixed $roles): array {
		$camp = $this->camps->require($campId, $uid, Permission::ManageMembers);
		if (!in_array($type, [Directory::USER, Directory::GROUP], true) || !$this->directory->exists($type, $memberId)) {
			throw new \InvalidArgumentException($type === Directory::GROUP ? 'Diese Gruppe gibt es nicht.' : 'Diese Person gibt es nicht.');
		}
		if ($type === Directory::USER && $memberId === $camp->getOwnerUid()) {
			throw new \InvalidArgumentException('Diese Person hat das Lager angelegt und ist immer Lagerleitung.');
		}
		// Die Freigabe-Einstellungen der Nextcloud gelten auch hier (etwa „nur mit Mitgliedern
		// der eigenen Gruppen teilen“), nicht nur in der Suche der Oberfläche.
		if (!$this->directory->canShareWith($type, $memberId)) {
			throw new \InvalidArgumentException('Mit dieser Person oder Gruppe darfst du laut den Freigabe-Einstellungen der Nextcloud nicht teilen.');
		}
		$roleList = Role::parseList($roles);

		try {
			$member = $this->members->findMember($campId, $type, $memberId);
			$member->setRoles(Role::toCsv($roleList));
			return $this->toArray($this->members->update($member));
		} catch (DoesNotExistException) {
		}
		if (count($this->members->findByCamp($campId)) >= self::MAX_MEMBERS) {
			throw new \InvalidArgumentException('Ein Lager kann höchstens ' . self::MAX_MEMBERS . ' Einträge im Team haben. Teile es mit einer Gruppe.');
		}
		$member = new CampMember();
		$member->setCampId($campId);
		$member->setMemberType($type);
		$member->setMemberId($memberId);
		$member->setRoles(Role::toCsv($roleList));
		$member->setCreatedBy($uid);
		$member->setCreatedAt($this->time->getTime());
		return $this->toArray($this->members->insert($member));
	}

	/**
	 * @param mixed $roles
	 * @return array<string, mixed>
	 */
	public function updateRoles(int $memberId, string $uid, mixed $roles): array {
		$member = $this->member($memberId, $uid);
		$member->setRoles(Role::toCsv(Role::parseList($roles)));
		return $this->toArray($this->members->update($member));
	}

	public function remove(int $memberId, string $uid): void {
		$this->members->delete($this->member($memberId, $uid));
	}

	/**
	 * Personen und Gruppen, die hinzugefügt werden können; vorhandene Mitglieder fehlen.
	 *
	 * @return list<array{type: string, id: string, displayName: string}>
	 */
	public function search(int $campId, string $uid, string $term): array {
		$camp = $this->camps->require($campId, $uid, Permission::ManageMembers);
		$term = trim($term);
		if ($term === '' || mb_strlen($term) > 100) {
			return [];
		}
		$taken = ['user:' . $camp->getOwnerUid() => true];
		foreach ($this->members->findByCamp($campId) as $member) {
			$taken[$member->getMemberType() . ':' . $member->getMemberId()] = true;
		}
		return array_values(array_filter(
			$this->directory->search($term, 15),
			static fn (array $entry): bool => !isset($taken[$entry['type'] . ':' . $entry['id']]),
		));
	}

	private function member(int $id, string $uid): CampMember {
		try {
			$member = $this->members->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Eintrag nicht gefunden.');
		}
		$this->camps->require((int)$member->getCampId(), $uid, Permission::ManageMembers);
		return $member;
	}

	/** @return array<string, mixed> */
	private function toArray(CampMember $member): array {
		return [
			'id' => $member->getId(),
			'type' => $member->getMemberType(),
			'memberId' => $member->getMemberId(),
			'displayName' => $this->directory->displayName($member->getMemberType(), $member->getMemberId()),
			'roles' => array_map(static fn (Role $r): string => $r->value, $member->roleList()),
		];
	}
}

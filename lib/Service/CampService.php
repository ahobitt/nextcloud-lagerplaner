<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\CampGroup;
use OCA\Lagerplaner\Db\CampGroupMapper;
use OCA\Lagerplaner\Db\CampMapper;
use OCA\Lagerplaner\Db\CampMemberMapper;
use OCA\Lagerplaner\Db\CampRecipeMapper;
use OCA\Lagerplaner\Db\CampSiteMapper;
use OCA\Lagerplaner\Db\ShoppingItemMapper;
use OCA\Lagerplaner\Db\ShoppingStockMapper;
use OCA\Lagerplaner\Db\ShoppingTripMapper;
use OCA\Lagerplaner\Db\DietNeedMapper;
use OCA\Lagerplaner\Db\MaterialDismissedMapper;
use OCA\Lagerplaner\Db\MaterialMapper;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\GenerationRunMapper;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Access\Role;
use OCA\Lagerplaner\Domain\Access\RolePolicy;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Zugriff auf Lager über Rollen (Konzept 18.1).
 *
 * Die anlegende Person ist immer Lagerleitung. Weitere Personen und ganze
 * Nextcloud-Gruppen erhalten Rollen über die Mitgliederliste des Lagers.
 */
class CampService {
	public function __construct(
		private CampMapper $camps,
		private CampGroupMapper $groups,
		private ProgramItemMapper $programItems,
		private TaskMapper $tasks,
		private MealMapper $meals,
		private GenerationRunMapper $runs,
		private Transaction $transaction,
		private ProgramGameMapper $programGames,
		private DietNeedMapper $dietNeeds,
		private CampMemberMapper $members,
		private Directory $directory,
		private CampRecipeMapper $recipes,
		private ShoppingTripMapper $shoppingTrips,
		private ShoppingItemMapper $shoppingItems,
		private ShoppingStockMapper $shoppingStock,
		private MaterialMapper $materials,
		private MaterialUseMapper $materialUses,
		private MaterialDismissedMapper $materialDismissed,
		private CampSiteMapper $campSites,
	) {
	}

	/** @return list<Camp> eigene und geteilte Lager, neueste zuerst */
	public function listForUser(string $uid): array {
		$byId = [];
		foreach ($this->camps->findByOwner($uid) as $camp) {
			$byId[(int)$camp->getId()] = $camp;
		}
		$shared = [];
		foreach ($this->members->findForPrincipal($uid, $this->directory->groupIdsOf($uid)) as $member) {
			if (!isset($byId[(int)$member->getCampId()])) {
				$shared[(int)$member->getCampId()] = true;
			}
		}
		foreach ($this->camps->findByIds(array_keys($shared)) as $camp) {
			$byId[(int)$camp->getId()] = $camp;
		}
		$camps = array_values($byId);
		usort($camps, static fn (Camp $a, Camp $b): int => strcmp($b->getStartDate(), $a->getStartDate()));
		return $camps;
	}

	/** @return list<Role> Rollen der Person im Lager, aus eigener Mitgliedschaft und ihren Gruppen */
	/** Darf die Person das im Lager? Ohne Zugriff aufs Lager: nein, ohne Ausnahme. */
	public function rolesAllow(int $campId, string $uid, Permission $permission): bool {
		try {
			$camp = $this->getForUser($campId, $uid);
		} catch (NotFoundException) {
			return false;
		}
		return RolePolicy::allows(array_values($this->rolesFor($camp, $uid)), $permission);
	}

	public function rolesFor(Camp $camp, string $uid): array {
		$roles = [];
		if ($camp->getOwnerUid() === $uid) {
			$roles[Role::Leitung->value] = Role::Leitung;
		}
		foreach ($this->members->findForPrincipal($uid, $this->directory->groupIdsOf($uid), (int)$camp->getId()) as $member) {
			foreach ($member->roleList() as $role) {
				$roles[$role->value] = $role;
			}
		}
		return array_values($roles);
	}

	/**
	 * Lager laden und Berechtigung prüfen. Ohne jede Rolle wirkt das Lager wie nicht vorhanden.
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function require(int $campId, string $uid, Permission $permission = Permission::View): Camp {
		try {
			$camp = $this->camps->find($campId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Lager nicht gefunden.');
		}
		$roles = $this->rolesFor($camp, $uid);
		if ($roles === []) {
			throw new NotFoundException('Lager nicht gefunden.');
		}
		if (!RolePolicy::allows($roles, $permission)) {
			throw new ForbiddenException('Dafür fehlt dir in diesem Lager die Berechtigung.');
		}
		return $camp;
	}

	/** @throws NotFoundException */
	public function getForUser(int $id, string $uid): Camp {
		return $this->require($id, $uid, Permission::View);
	}

	/**
	 * Alle Personen mit Zugriff auf das Lager: anlegende Person, einzelne Mitglieder und
	 * Mitglieder von Gruppen im Team. Für die Auswahl von Verantwortlichen.
	 *
	 * @return list<array{uid: string, displayName: string}>
	 */
	public function people(int $campId, string $uid): array {
		$camp = $this->require($campId, $uid, Permission::View);
		$uids = [$camp->getOwnerUid() => true];
		foreach ($this->members->findByCamp($campId) as $member) {
			if ($member->getMemberType() === Directory::USER) {
				$uids[$member->getMemberId()] = true;
			} else {
				foreach ($this->directory->groupMembers($member->getMemberId()) as $groupMember) {
					$uids[$groupMember] = true;
				}
			}
		}
		$people = array_map(fn (string $id): array => ['uid' => $id, 'displayName' => $this->directory->displayName(Directory::USER, $id)], array_map('strval', array_keys($uids)));
		usort($people, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $people;
	}

	/** Lagerort festlegen, etwa aus der Merkliste der Lagerplätze. */
	public function setLocation(Camp $camp, string $name, ?string $region, int $now): void {
		$camp->setLocationKnown(true);
		$camp->setLocationName(mb_substr($name, 0, 255));
		$camp->setRegion($region === null || $region === '' ? null : mb_substr($region, 0, 255));
		$camp->setUpdatedAt($now);
		$this->camps->update($camp);
	}

	/** @return array{isOwner: bool, roles: list<string>, can: array<string, bool>} für die Oberfläche */
	public function accessFor(Camp $camp, string $uid): array {
		$roles = $this->rolesFor($camp, $uid);
		return [
			'isOwner' => $camp->getOwnerUid() === $uid,
			'roles' => array_map(static fn (Role $r): string => $r->value, $roles),
			'can' => RolePolicy::capabilities($roles),
		];
	}

	/** Markiert eine Änderung an der Planung, damit offene Vorschauen ungültig werden. */
	public function touch(int $campId, int $now): void {
		$this->camps->bumpVersion($campId, $now);
	}

	/** @return list<CampGroup> */
	public function groups(int $campId): array {
		return $this->groups->findByCamp($campId);
	}

	public function delete(int $id, string $uid): void {
		$camp = $this->require($id, $uid, Permission::DeleteCamp);
		$this->transaction->run(function () use ($id, $camp): void {
			foreach ([$this->campSites, $this->materialDismissed, $this->materialUses, $this->materials, $this->shoppingStock, $this->shoppingItems, $this->shoppingTrips, $this->recipes, $this->members, $this->dietNeeds, $this->programGames, $this->programItems, $this->tasks, $this->meals, $this->groups, $this->runs] as $mapper) {
				$mapper->deleteByCamp($id);
			}
			$this->camps->delete($camp);
		});
	}
}

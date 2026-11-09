<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Access\Directory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends CampScopedMapper<CampMember> */
class CampMemberMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_camp_members', CampMember::class);
	}

	protected function orderColumns(): array {
		return ['member_type', 'member_id'];
	}

	/**
	 * Einträge für eine Person und ihre Gruppen, über alle Lager.
	 *
	 * @param list<string> $groupIds
	 * @return list<CampMember>
	 */
	public function findForPrincipal(string $uid, array $groupIds, ?int $campId = null): array {
		$qb = $this->db->getQueryBuilder();
		$user = $qb->expr()->andX(
			$qb->expr()->eq('member_type', $qb->createNamedParameter(Directory::USER)),
			$qb->expr()->eq('member_id', $qb->createNamedParameter($uid)),
		);
		$qb->select('*')->from($this->getTableName());
		if ($groupIds === []) {
			$qb->where($user);
		} else {
			$qb->where($qb->expr()->orX($user, $qb->expr()->andX(
				$qb->expr()->eq('member_type', $qb->createNamedParameter(Directory::GROUP)),
				$qb->expr()->in('member_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			)));
		}
		if ($campId !== null) {
			$qb->andWhere($qb->expr()->eq('camp_id', $qb->createNamedParameter($campId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findMember(int $campId, string $type, string $id): CampMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('camp_id', $qb->createNamedParameter($campId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('member_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}
}

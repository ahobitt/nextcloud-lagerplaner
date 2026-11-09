<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Camp> */
class CampMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_camps', Camp::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Camp {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Erhöht die Versionsnummer. Mit $expected nur, wenn das Lager noch diese Version hat.
	 *
	 * @return bool false, wenn ein anderer Vorgang das Lager inzwischen geändert hat
	 */
	public function bumpVersion(int $id, int $now, ?int $expected = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('version', $qb->createFunction('version + 1'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($expected !== null) {
			$qb->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($expected, IQueryBuilder::PARAM_INT)));
		}
		return $qb->executeStatement() === 1;
	}

	/**
	 * @param list<int> $ids
	 * @return list<Camp>
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/** @return list<Camp> */
	public function findByOwner(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
			->orderBy('start_date', 'DESC');
		return $this->findEntities($qb);
	}
}

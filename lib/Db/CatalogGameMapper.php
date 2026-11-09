<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<CatalogGame> */
class CatalogGameMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_games', CatalogGame::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): CatalogGame {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<CatalogGame> */
	public function findBySource(string $source): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source', $qb->createNamedParameter($source)))
			->orderBy('title', 'ASC');
		return $this->findEntities($qb);
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Template> */
class TemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_templates', Template::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Template {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<Template> eigene und von anderen geteilte Vorlagen */
	public function findVisible(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('shared', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
			))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}
}

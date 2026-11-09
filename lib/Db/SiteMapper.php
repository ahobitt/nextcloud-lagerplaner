<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Das Verzeichnis gilt für die ganze Nextcloud-Instanz (nicht je Lager).
 *
 * @extends QBMapper<SiteRecord>
 */
class SiteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_sites', SiteRecord::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): SiteRecord {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<SiteRecord> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SiteRecord> */
	public function findBySource(string $source): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		return $this->findEntities($qb);
	}
}

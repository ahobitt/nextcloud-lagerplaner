<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SiteFact> */
class SiteFactMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_site_facts', SiteFact::class);
	}

	/** @return list<SiteFact> */
	public function findBySite(int $siteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<SiteFact> Angaben, die noch nicht an die Quelle zurückgemeldet sind */
	public function findUnreported(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->isNull('reported_at'));
		return $this->findEntities($qb);
	}

	public function deleteBySite(int $siteId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}

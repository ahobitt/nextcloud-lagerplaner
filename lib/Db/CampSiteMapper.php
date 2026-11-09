<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends CampScopedMapper<CampSite> */
class CampSiteMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_camp_sites', CampSite::class);
	}

	protected function orderColumns(): array {
		return ['created_at'];
	}

	/** @return list<CampSite> Merklisteneinträge aller Lager zu einem Platz */
	public function findBySite(int $siteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<SiteFeedback> */
class SiteFeedbackMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_site_feedback', SiteFeedback::class);
	}

	/** @return list<SiteFeedback> neueste zuerst */
	public function findByStatus(string $status): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function get(int $id): SiteFeedback {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}
}

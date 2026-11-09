<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SiteFeedbackValue> */
class SiteFeedbackValueMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_site_feedback_values', SiteFeedbackValue::class);
	}

	/** @return list<SiteFeedbackValue> */
	public function findByFeedback(int $feedbackId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('feedback_id', $qb->createNamedParameter($feedbackId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
}

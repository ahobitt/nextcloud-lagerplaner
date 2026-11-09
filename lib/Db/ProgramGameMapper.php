<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends CampScopedMapper<ProgramGame> */
class ProgramGameMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_program_games', ProgramGame::class);
	}

	protected function orderColumns(): array {
		return ['created_at'];
	}

	/** @return list<ProgramGame> */
	public function findByProgramItem(int $programItemId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('program_item_id', $qb->createNamedParameter($programItemId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}

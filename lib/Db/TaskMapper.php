<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<Task> */
class TaskMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_tasks', Task::class);
	}

	protected function softDeletes(): bool {
		return true;
	}

	protected function orderColumns(): array {
		return ['due_date'];
	}

	/**
	 * Offene, nicht gelöschte Aufgaben mit Frist im Zeitraum, an die noch nicht erinnert wurde.
	 *
	 * @return list<Task>
	 */
	public function findDueForReminder(string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_OPEN)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->isNull('reminded_at'))
			->andWhere($qb->expr()->gte('due_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($to)));
		return $this->findEntities($qb);
	}
}

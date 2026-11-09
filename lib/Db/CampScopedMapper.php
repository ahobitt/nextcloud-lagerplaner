<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Gemeinsame Abfragen für Tabellen, deren Zeilen zu genau einem Lager gehören.
 *
 * @template T of Entity
 * @extends QBMapper<T>
 */
abstract class CampScopedMapper extends QBMapper {
	/** @return list<string> Sortierspalten */
	abstract protected function orderColumns(): array;

	/**
	 * @return T
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** Tabellen mit Löschmarkierung liefern in findByCamp nur nicht gelöschte Zeilen. */
	protected function softDeletes(): bool {
		return false;
	}

	/** @return list<T> */
	public function findByCamp(int $campId): array {
		return $this->select($campId, $this->softDeletes() ? false : null);
	}

	/** @return list<T> einschließlich gelöschter Vorschläge, für die Neuberechnung */
	public function findByCampIncludingDeleted(int $campId): array {
		return $this->select($campId, null);
	}

	/** @return list<T> */
	public function findDeletedByCamp(int $campId): array {
		return $this->softDeletes() ? $this->select($campId, true) : [];
	}

	/**
	 * @param bool|null $deleted null: alle, false: nur aktive, true: nur gelöschte
	 * @return list<T>
	 */
	private function select(int $campId, ?bool $deleted): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('camp_id', $qb->createNamedParameter($campId, IQueryBuilder::PARAM_INT)));
		if ($deleted === false) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		} elseif ($deleted === true) {
			$qb->andWhere($qb->expr()->isNotNull('deleted_at'));
		}
		foreach ($this->orderColumns() as $column) {
			$qb->addOrderBy($column, 'ASC');
		}
		$qb->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByCamp(int $campId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('camp_id', $qb->createNamedParameter($campId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

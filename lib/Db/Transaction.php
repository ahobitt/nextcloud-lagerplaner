<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** Führt einen Block in einer Datenbanktransaktion aus. */
class Transaction {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function run(callable $callback): mixed {
		$this->db->beginTransaction();
		try {
			$result = $callback();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

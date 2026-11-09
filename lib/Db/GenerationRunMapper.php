<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<GenerationRun> */
class GenerationRunMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_gen_runs', GenerationRun::class);
	}

	protected function orderColumns(): array {
		return ['created_at'];
	}
}

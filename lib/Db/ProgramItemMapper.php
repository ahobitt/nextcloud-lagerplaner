<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<ProgramItem> */
class ProgramItemMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_program_items', ProgramItem::class);
	}

	protected function softDeletes(): bool {
		return true;
	}

	protected function orderColumns(): array {
		return ['day', 'start_time'];
	}
}

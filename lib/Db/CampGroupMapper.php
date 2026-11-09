<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<CampGroup> */
class CampGroupMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_camp_groups', CampGroup::class);
	}

	protected function orderColumns(): array {
		return ['sort_order'];
	}
}

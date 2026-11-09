<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<MaterialUse> */
class MaterialUseMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_material_uses', MaterialUse::class);
	}

	protected function orderColumns(): array {
		return ['material_id', 'id'];
	}
}

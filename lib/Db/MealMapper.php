<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<Meal> */
class MealMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_meals', Meal::class);
	}

	protected function softDeletes(): bool {
		return true;
	}

	protected function orderColumns(): array {
		return ['day', 'meal_time'];
	}
}

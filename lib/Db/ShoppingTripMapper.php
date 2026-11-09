<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<ShoppingTrip> */
class ShoppingTripMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_shop_trips', ShoppingTrip::class);
	}

	protected function orderColumns(): array {
		return ['trip_date'];
	}
}

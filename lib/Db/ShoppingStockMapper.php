<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<ShoppingStock> */
class ShoppingStockMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_shop_stock', ShoppingStock::class);
	}

	protected function orderColumns(): array {
		return ['item_key'];
	}
}

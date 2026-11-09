<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\IDBConnection;

/** @extends CampScopedMapper<ShoppingItem> */
class ShoppingItemMapper extends CampScopedMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'lp_shop_items', ShoppingItem::class);
	}

	protected function orderColumns(): array {
		return ['trip_id', 'item_key'];
	}
}

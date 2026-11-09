<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Vorhandene Menge einer Zutat (Positionsschlüssel) für das ganze Lager, in der Basiseinheit.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getItemKey()
 * @method void setItemKey(string $itemKey)
 * @method float getBase()
 * @method void setBase(float $base)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getUnitFamily()
 * @method void setUnitFamily(?string $unitFamily)
 */
class ShoppingStock extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $itemKey;
	protected $base = 0.0;
	protected $updatedAt;
	protected $name;
	protected $unitFamily;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('base', Types::FLOAT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

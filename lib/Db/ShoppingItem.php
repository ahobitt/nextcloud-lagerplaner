<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Stand einer Position: berechnete Zeile (kind „line“, Schlüssel der Zutat) oder
 * eigener Posten (kind „extra“). Bei gekauften Zeilen wird die gekaufte Menge in der
 * Basiseinheit gespeichert, damit späterer Mehrbedarf erkennbar ist.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method int getTripId()
 * @method void setTripId(int $tripId)
 * @method string getItemKey()
 * @method void setItemKey(string $itemKey)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getAmountText()
 * @method void setAmountText(?string $amountText)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method bool|null getPurchased()
 * @method void setPurchased(bool $purchased)
 * @method float|null getPurchasedBase()
 * @method void setPurchasedBase(?float $purchasedBase)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getUnitFamily()
 * @method void setUnitFamily(?string $unitFamily)
 */
class ShoppingItem extends Entity {
	use PersistsDefaults;

	public const LINE = 'line';
	public const EXTRA = 'extra';

	protected $campId;
	protected $tripId;
	protected $itemKey;
	protected $kind;
	protected $name;
	protected $amountText;
	protected $category;
	protected $purchased = false;
	protected $purchasedBase;
	protected $updatedAt;
	protected $unitFamily;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('tripId', Types::BIGINT);
		$this->addType('purchased', Types::BOOLEAN);
		$this->addType('purchasedBase', Types::FLOAT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

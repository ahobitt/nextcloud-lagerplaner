<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Einkaufstermin, zum Beispiel Großeinkauf vor dem Lager oder Frischeeinkauf.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getTripDate()
 * @method void setTripDate(string $tripDate)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method int|null getModifiedAt()
 * @method void setModifiedAt(?int $modifiedAt)
 */
class ShoppingTrip extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $tripDate;
	protected $label;
	protected $createdAt;
	/** default: vom Lagerplaner angelegter Großeinkauf; manual: von Hand angelegt */
	protected $origin = 'manual';
	/** Von Hand geändert; dann verschiebt der Assistent den Termin nicht mehr. */
	protected $modifiedAt;

	public function __construct() {
		$this->addType('modifiedAt', Types::BIGINT);
		$this->addType('campId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

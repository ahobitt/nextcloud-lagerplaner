<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Einsatz eines Materials: für einen Programmpunkt oder das ganze Lager, mit Menge.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method int getMaterialId()
 * @method void setMaterialId(int $materialId)
 * @method int|null getProgramItemId()
 * @method void setProgramItemId(?int $programItemId)
 * @method float|null getQuantity()
 * @method void setQuantity(?float $quantity)
 * @method string|null getSourceRef()
 * @method void setSourceRef(?string $sourceRef)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class MaterialUse extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $materialId;
	protected $programItemId;
	protected $quantity;
	protected $sourceRef;
	protected $createdAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('materialId', Types::BIGINT);
		$this->addType('programItemId', Types::BIGINT);
		$this->addType('quantity', Types::FLOAT);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

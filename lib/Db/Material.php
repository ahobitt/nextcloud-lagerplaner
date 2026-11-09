<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Materialposten des Lagers, etwa „Seile“ oder „Erste-Hilfe-Tasche“ (Konzept 11.1).
 * Wofür und wie viel davon gebraucht wird, steht in den Einsätzen (MaterialUse).
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string|null getUnit()
 * @method void setUnit(?string $unit)
 * @method bool|null getReusable()
 * @method void setReusable(bool $reusable)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getResponsibleUid()
 * @method void setResponsibleUid(?string $responsibleUid)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Material extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $name;
	protected $category = 'other';
	protected $unit;
	protected $reusable = true;
	protected $status = 'needed';
	protected $responsibleUid;
	protected $note;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('reusable', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

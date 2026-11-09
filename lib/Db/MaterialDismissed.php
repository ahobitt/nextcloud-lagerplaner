<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein bewusst verworfener Materialvorschlag, damit er nicht wiederkommt.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getSourceRef()
 * @method void setSourceRef(string $sourceRef)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class MaterialDismissed extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $sourceRef;
	protected $createdAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

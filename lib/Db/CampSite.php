<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Platz auf der Merkliste eines Lagers, mit Anfragestatus und eigenen Notizen.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method int getSiteId()
 * @method void setSiteId(int $siteId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool|null getPreferred()
 * @method void setPreferred(bool $preferred)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class CampSite extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $siteId;
	protected $status = 'interesting';
	protected $preferred = false;
	protected $notes;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('siteId', Types::BIGINT);
		$this->addType('preferred', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

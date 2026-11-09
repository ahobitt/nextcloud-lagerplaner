<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Eine Datenquelle des Lagerplatzverzeichnisses mit Namen und Link für die Quellenangabe
 * und dem Stand der letzten Synchronisierung.
 *
 * @method string getSourceKey()
 * @method void setSourceKey(string $sourceKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string|null getLicense()
 * @method void setLicense(?string $license)
 * @method int|null getSyncedAt()
 * @method void setSyncedAt(?int $syncedAt)
 * @method string|null getSyncedVersion()
 * @method void setSyncedVersion(?string $syncedVersion)
 * @method string|null getSyncResult()
 * @method void setSyncResult(?string $syncResult)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SiteSourceRecord extends Entity {
	use PersistsDefaults;

	protected $sourceKey;
	protected $name;
	protected $url;
	protected $license;
	protected $syncedAt;
	protected $syncedVersion;
	protected $syncResult;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('syncedAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

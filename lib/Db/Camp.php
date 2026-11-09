<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string getEndDate()
 * @method void setEndDate(string $endDate)
 * @method string|null getOrganizer()
 * @method void setOrganizer(?string $organizer)
 * @method string getCampType()
 * @method void setCampType(string $campType)
 * @method bool|null getLocationKnown()
 * @method void setLocationKnown(bool $locationKnown)
 * @method string|null getLocationName()
 * @method void setLocationName(?string $locationName)
 * @method string|null getRegion()
 * @method void setRegion(?string $region)
 * @method string|null getTheme()
 * @method void setTheme(?string $theme)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int getVersion()
 * @method void setVersion(int $version)
 */
class Camp extends Entity implements JsonSerializable {
	use PersistsDefaults;

	protected $ownerUid;
	protected $name;
	protected $description;
	protected $startDate;
	protected $endDate;
	protected $organizer;
	protected $campType = 'tent';
	protected $locationKnown = false;
	protected $locationName;
	protected $region;
	protected $theme;
	protected $status = 'draft';
	protected $createdAt;
	protected $updatedAt;
	/** Wird bei jeder Änderung an der Planung erhöht (optimistisches Sperren). */
	protected $version = 0;

	public function __construct() {
		$this->addType('version', Types::BIGINT);
		$this->addType('locationKnown', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'startDate' => $this->startDate,
			'endDate' => $this->endDate,
			'organizer' => $this->organizer,
			'campType' => $this->campType,
			'locationKnown' => (bool)$this->locationKnown,
			'locationName' => $this->locationName,
			'region' => $this->region,
			'theme' => $this->theme,
			'status' => $this->status,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'version' => $this->version,
		];
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Domain\Access\Role;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Zugriff einer Person oder einer ganzen Nextcloud-Gruppe auf ein Lager.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getMemberType()
 * @method void setMemberType(string $memberType)
 * @method string getMemberId()
 * @method void setMemberId(string $memberId)
 * @method string getRoles()
 * @method void setRoles(string $roles)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CampMember extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $memberType;
	protected $memberId;
	protected $roles;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}

	/** @return list<Role> */
	public function roleList(): array {
		return Role::fromCsv($this->roles);
	}
}

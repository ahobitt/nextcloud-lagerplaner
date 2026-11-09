<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Gespeicherte Programm- oder Tagesvorlage.
 *
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getDataJson()
 * @method void setDataJson(string $dataJson)
 * @method bool|null getShared()
 * @method void setShared(bool $shared)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Template extends Entity {
	use PersistsDefaults;

	public const ITEM = 'item';
	public const DAY = 'day';

	protected $ownerUid;
	protected $kind;
	protected $name;
	protected $dataJson;
	protected $shared = false;
	protected $version = 1;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('shared', Types::BOOLEAN);
		$this->addType('version', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}

	/** @return array<string, mixed> */
	public function data(): array {
		$data = json_decode((string)$this->dataJson, true);
		return is_array($data) ? $data : [];
	}
}

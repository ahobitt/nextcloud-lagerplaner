<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Altersstufe beziehungsweise Planungsgruppe eines Lagers.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getGroupKey()
 * @method void setGroupKey(string $groupKey)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method int getEstimatedCount()
 * @method void setEstimatedCount(int $estimatedCount)
 * @method int|null getConfirmedCount()
 * @method void setConfirmedCount(?int $confirmedCount)
 * @method float getPortionFactor()
 * @method void setPortionFactor(float $portionFactor)
 * @method bool|null getSeparateProgram()
 * @method void setSeparateProgram(bool $separateProgram)
 * @method bool|null getIsLeaders()
 * @method void setIsLeaders(bool $isLeaders)
 * @method int getBlockMinutes()
 * @method void setBlockMinutes(int $blockMinutes)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 */
class CampGroup extends Entity implements JsonSerializable {
	use PersistsDefaults;

	protected $campId;
	protected $groupKey;
	protected $label;
	protected $estimatedCount = 0;
	protected $confirmedCount;
	protected $portionFactor = 1.0;
	protected $separateProgram = false;
	protected $isLeaders = false;
	protected $blockMinutes = 120;
	protected $sortOrder = 0;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('estimatedCount', Types::INTEGER);
		$this->addType('confirmedCount', Types::INTEGER);
		$this->addType('portionFactor', Types::FLOAT);
		$this->addType('separateProgram', Types::BOOLEAN);
		$this->addType('isLeaders', Types::BOOLEAN);
		$this->addType('blockMinutes', Types::INTEGER);
		$this->addType('sortOrder', Types::INTEGER);
		$this->persistDefaults();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'key' => $this->groupKey,
			'label' => $this->label,
			'count' => $this->estimatedCount,
			'confirmedCount' => $this->confirmedCount,
			'portionFactor' => $this->portionFactor,
			'separateProgram' => (bool)$this->separateProgram,
			'isLeaders' => (bool)$this->isLeaders,
			'blockMinutes' => $this->blockMinutes,
		];
	}
}

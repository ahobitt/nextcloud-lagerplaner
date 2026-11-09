<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Aggregierte Ernährungsangabe: Anzahl je Bedarfskombination oder je schwerem Allergen.
 * Ohne Personenbezug (Konzept 8.5, 18.2).
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getNeedKey()
 * @method void setNeedKey(string $needKey)
 * @method int getCount()
 * @method void setCount(int $count)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class DietNeed extends Entity {
	use PersistsDefaults;

	public const COMBINATION = 'combination';
	public const SEVERE = 'severe';

	protected $campId;
	protected $kind;
	protected $needKey;
	protected $count = 0;
	protected $updatedAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('count', Types::INTEGER);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

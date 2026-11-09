<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Herkunft einer einzelnen Angabe eines Lagerplatzes, wenn sie nicht aus der Quelle des
 * Platzes stammt: automatisch auf der Website erkannt oder von Hand ergänzt beziehungsweise
 * korrigiert. Der Wert selbst steht in lp_sites; hier stehen Herkunft, Prüfung und
 * Rückmeldestatus. Solche Angaben überschreibt eine Synchronisierung nicht.
 *
 * @method int getSiteId()
 * @method void setSiteId(int $siteId)
 * @method string getAttribute()
 * @method void setAttribute(string $attribute)
 * @method string getMethod()
 * @method void setMethod(string $method)
 * @method string|null getSourceUrl()
 * @method void setSourceUrl(?string $sourceUrl)
 * @method int|null getCheckedAt()
 * @method void setCheckedAt(?int $checkedAt)
 * @method string|null getConfidence()
 * @method void setConfidence(?string $confidence)
 * @method string|null getChangedBy()
 * @method void setChangedBy(?string $changedBy)
 * @method string|null getSourceValue()
 * @method void setSourceValue(?string $sourceValue)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getReportedAt()
 * @method void setReportedAt(?int $reportedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SiteFact extends Entity {
	use PersistsDefaults;

	protected $siteId;
	protected $attribute;
	protected $method;
	protected $sourceUrl;
	protected $checkedAt;
	protected $confidence;
	protected $changedBy;
	protected $reportedAt;
	/** Vermerk, etwa „Rückmeldung von …“ */
	protected $note;
	/** Angaben „laut Website“: Wert der Quelle zum Zeitpunkt der Ersetzung (JSON) */
	protected $sourceValue;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('siteId', Types::BIGINT);
		$this->addType('checkedAt', Types::BIGINT);
		$this->addType('reportedAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

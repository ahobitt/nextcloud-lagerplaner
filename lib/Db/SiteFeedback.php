<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Rückmeldung einer Person zu einem Lagerplatz („Feedback / Angaben ergänzen“) oder zu einem
 * fehlenden Platz (ohne siteId). Vorgeschlagene Werte stehen in lp_site_feedback_values.
 *
 * @method int|null getSiteId()
 * @method void setSiteId(?int $siteId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string|null getHandledBy()
 * @method void setHandledBy(?string $handledBy)
 * @method int|null getHandledAt()
 * @method void setHandledAt(?int $handledAt)
 * @method int|null getUpstreamRelevant()
 * @method void setUpstreamRelevant(?int $upstreamRelevant)
 * @method int|null getReportedAt()
 * @method void setReportedAt(?int $reportedAt)
 * @method string|null getHandledNote()
 * @method void setHandledNote(?string $handledNote)
 */
class SiteFeedback extends Entity {
	use PersistsDefaults;

	public const OPEN = 'open';
	public const ACCEPTED = 'accepted';
	public const REJECTED = 'rejected';

	protected $siteId;
	protected $kind;
	protected $message;
	protected $status = self::OPEN;
	protected $createdBy;
	protected $createdAt;
	protected $handledBy;
	protected $handledAt;
	protected $handledNote;
	/** 1: betrifft die Stammdaten der Quelle (für sie relevant), 0: nur Lagerplaner */
	protected $upstreamRelevant;
	/** an die Quelle weitergegeben am */
	protected $reportedAt;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('siteId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('handledAt', Types::BIGINT);
		$this->addType('upstreamRelevant', Types::SMALLINT);
		$this->addType('reportedAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

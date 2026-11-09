<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein vorgeschlagener Wert einer Rückmeldung, etwa homepage, capacity oder water.
 *
 * @method int getFeedbackId()
 * @method void setFeedbackId(int $feedbackId)
 * @method string getAttribute()
 * @method void setAttribute(string $attribute)
 * @method string|null getValue()
 * @method void setValue(?string $value)
 */
class SiteFeedbackValue extends Entity {
	use PersistsDefaults;

	protected $feedbackId;
	protected $attribute;
	protected $value;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('feedbackId', Types::BIGINT);
		$this->persistDefaults();
	}
}

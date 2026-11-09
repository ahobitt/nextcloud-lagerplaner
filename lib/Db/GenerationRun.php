<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein gespeicherter Assistenten- oder KI-Lauf (Konzept 17.6).
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getTemplateVersion()
 * @method void setTemplateVersion(string $templateVersion)
 * @method string getInputJson()
 * @method void setInputJson(string $inputJson)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class GenerationRun extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $kind = 'assistant';
	protected $templateVersion;
	protected $inputJson;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}
}

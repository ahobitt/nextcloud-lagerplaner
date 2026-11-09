<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein eingeplantes Spiel: Kopie der Anzeigedaten mit Quelle und Lizenz (Konzept 7.3, 7.6).
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method int getProgramItemId()
 * @method void setProgramItemId(int $programItemId)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string getLink()
 * @method void setLink(string $link)
 * @method int|null getDurationMinutes()
 * @method void setDurationMinutes(?int $durationMinutes)
 * @method string getMaterialsJson()
 * @method void setMaterialsJson(string $materialsJson)
 * @method string getLicenseName()
 * @method void setLicenseName(string $licenseName)
 * @method string getLicenseUrl()
 * @method void setLicenseUrl(string $licenseUrl)
 * @method string getAttribution()
 * @method void setAttribution(string $attribution)
 * @method string|null getSourceModified()
 * @method void setSourceModified(?string $sourceModified)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class ProgramGame extends Entity implements JsonSerializable {
	use PersistsDefaults;

	protected $campId;
	protected $programItemId;
	protected $source;
	protected $sourceId;
	protected $title;
	protected $summary;
	protected $link;
	protected $durationMinutes;
	protected $materialsJson = '[]';
	protected $licenseName;
	protected $licenseUrl;
	protected $attribution;
	protected $sourceModified;
	protected $createdAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('programItemId', Types::BIGINT);
		$this->addType('sourceId', Types::BIGINT);
		$this->addType('durationMinutes', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->persistDefaults();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'programItemId' => $this->programItemId,
			'title' => $this->title,
			'summary' => $this->summary,
			'link' => $this->link,
			'durationMinutes' => $this->durationMinutes,
			'materials' => CatalogGame::list($this->materialsJson),
			'license' => ['name' => $this->licenseName, 'url' => $this->licenseUrl, 'attribution' => $this->attribution],
		];
	}
}

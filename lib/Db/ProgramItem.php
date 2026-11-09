<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCA\Lagerplaner\Domain\Wizard\ProposedProgramItem;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getDay()
 * @method void setDay(string $day)
 * @method string getStartTime()
 * @method void setStartTime(string $startTime)
 * @method int getDurationMinutes()
 * @method void setDurationMinutes(int $durationMinutes)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getGroupsJson()
 * @method void setGroupsJson(string $groupsJson)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method bool|null getWeatherAltNeeded()
 * @method void setWeatherAltNeeded(bool $weatherAltNeeded)
 * @method string|null getWeatherAlternative()
 * @method void setWeatherAlternative(?string $weatherAlternative)
 * @method int|null getAlternativeTo()
 * @method void setAlternativeTo(?int $alternativeTo)
 */
class ProgramItem extends Entity implements JsonSerializable {
	use PersistsDefaults;

	use HasProvenance;

	protected $campId;
	protected $day;
	protected $startTime;
	protected $durationMinutes = 60;
	protected $title;
	protected $description;
	protected $groupsJson = '[]';
	protected $category = 'program';
	protected $weatherAltNeeded = false;
	protected $weatherAlternative;
	protected $alternativeTo;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('durationMinutes', Types::INTEGER);
		$this->addType('weatherAltNeeded', Types::BOOLEAN);
		$this->addType('alternativeTo', Types::BIGINT);
		$this->addProvenanceTypes();
		$this->persistDefaults();
	}

	public function fillFrom(ProposedProgramItem $p): void {
		$this->setDay($p->day);
		$this->setStartTime($p->startTime);
		$this->setDurationMinutes($p->durationMinutes);
		$this->setTitle($p->title);
		$this->setDescription($p->description === '' ? null : $p->description);
		$this->setGroupsJson(json_encode($p->groups, JSON_THROW_ON_ERROR));
		$this->setCategory($p->category);
		$this->setWeatherAltNeeded($p->weatherAlternativeNeeded);
		$this->setWeatherAlternative($p->weatherAlternative === '' ? null : $p->weatherAlternative);
	}

	public function label(): string {
		return $this->day . ' ' . $this->startTime . ' ' . $this->title;
	}

	/** @return list<string> Gruppenschlüssel; leer bedeutet alle Gruppen */
	public function groupList(): array {
		$groups = json_decode((string)$this->groupsJson, true);
		return is_array($groups) ? array_values(array_filter($groups, 'is_string')) : [];
	}

	/** @param list<string> $groups */
	public function setGroupList(array $groups): void {
		$this->setGroupsJson(json_encode(array_values(array_unique($groups)), JSON_THROW_ON_ERROR));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'campId' => $this->campId,
			'day' => $this->day,
			'startTime' => $this->startTime,
			'durationMinutes' => $this->durationMinutes,
			'title' => $this->title,
			'description' => $this->description,
			'groups' => json_decode((string)$this->groupsJson, true) ?? [],
			'category' => $this->category,
			'weatherAltNeeded' => (bool)$this->weatherAltNeeded,
			'weatherAlternative' => $this->weatherAlternative,
			'alternativeTo' => $this->alternativeTo,
		] + $this->provenanceJson();
	}
}

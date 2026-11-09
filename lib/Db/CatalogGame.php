<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Domain\Games\Game;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Spiel im lokalen Katalog.
 *
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getLink()
 * @method void setLink(string $link)
 * @method string|null getSourceModified()
 * @method void setSourceModified(?string $sourceModified)
 * @method int|null getMinAge()
 * @method void setMinAge(?int $minAge)
 * @method int|null getDurationMinutes()
 * @method void setDurationMinutes(?int $durationMinutes)
 * @method int|null getGroupMin()
 * @method void setGroupMin(?int $groupMin)
 * @method int|null getGroupMax()
 * @method void setGroupMax(?int $groupMax)
 * @method string getTypesJson()
 * @method void setTypesJson(string $typesJson)
 * @method string getEnvironmentsJson()
 * @method void setEnvironmentsJson(string $environmentsJson)
 * @method string getMaterialsJson()
 * @method void setMaterialsJson(string $materialsJson)
 * @method string getSuitabilityJson()
 * @method void setSuitabilityJson(string $suitabilityJson)
 * @method string getKnownAsJson()
 * @method void setKnownAsJson(string $knownAsJson)
 * @method int getSyncedAt()
 * @method void setSyncedAt(int $syncedAt)
 */
class CatalogGame extends Entity {
	use PersistsDefaults;

	protected $source;
	protected $sourceId;
	protected $title;
	protected $summary;
	protected $description;
	protected $link;
	protected $sourceModified;
	protected $minAge;
	protected $durationMinutes;
	protected $groupMin;
	protected $groupMax;
	protected $typesJson = '[]';
	protected $environmentsJson = '[]';
	protected $materialsJson = '[]';
	protected $suitabilityJson = '[]';
	protected $knownAsJson = '[]';
	protected $syncedAt;

	public function __construct() {
		$this->addType('sourceId', Types::BIGINT);
		$this->addType('minAge', Types::INTEGER);
		$this->addType('durationMinutes', Types::INTEGER);
		$this->addType('groupMin', Types::INTEGER);
		$this->addType('groupMax', Types::INTEGER);
		$this->addType('syncedAt', Types::BIGINT);
		$this->persistDefaults();
	}

	public function fillFrom(Game $game, int $now): void {
		$this->setSource($game->source);
		$this->setSourceId($game->sourceId);
		$this->setTitle($game->title);
		$this->setSummary($game->summary === '' ? null : $game->summary);
		$this->setDescription($game->descriptionHtml === '' ? null : $game->descriptionHtml);
		$this->setLink($game->link);
		$this->setSourceModified($game->sourceModified === '' ? null : $game->sourceModified);
		$this->setMinAge($game->minAge);
		$this->setDurationMinutes($game->durationMinutes);
		$this->setGroupMin($game->groupMin);
		$this->setGroupMax($game->groupMax);
		$this->setTypesJson(self::json($game->types, 2000));
		$this->setEnvironmentsJson(self::json($game->environments, 1000));
		$this->setMaterialsJson(self::json($game->materials, 2000));
		$this->setSuitabilityJson(self::json($game->suitability, 2000));
		$this->setKnownAsJson(self::json($game->knownAs, 2000));
		$this->setSyncedAt($now);
	}

	public function toDomain(): Game {
		return new Game(
			(string)$this->source, (int)$this->sourceId, (string)$this->title, (string)$this->summary,
			(string)$this->description, (string)$this->link, (string)$this->sourceModified,
			$this->minAge, $this->durationMinutes, $this->groupMin, $this->groupMax,
			self::list($this->typesJson), self::list($this->environmentsJson), self::list($this->materialsJson),
			self::list($this->suitabilityJson), self::list($this->knownAsJson),
		);
	}

	/** @return array<string, mixed> Daten für die Trefferliste */
	public function summaryArray(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'summary' => $this->summary,
			'link' => $this->link,
			'minAge' => $this->minAge,
			'durationMinutes' => $this->durationMinutes,
			'groupMin' => $this->groupMin,
			'groupMax' => $this->groupMax,
			'types' => self::list($this->typesJson),
			'environments' => self::list($this->environmentsJson),
			'knownAs' => self::list($this->knownAsJson),
		];
	}

	/** @return array<string, mixed> */
	public function detailArray(): array {
		return $this->summaryArray() + [
			'description' => $this->description,
			'materials' => self::list($this->materialsJson),
			'suitability' => self::list($this->suitabilityJson),
		];
	}

	/** @param list<string> $values */
	private static function json(array $values, int $maxLength): string {
		// Listen werden gekürzt, bis sie in die Spalte passen.
		while ($values !== [] && strlen($encoded = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $maxLength) {
			array_pop($values);
		}
		return $encoded ?? '[]';
	}

	/** @return list<string> */
	public static function list(?string $json): array {
		$values = json_decode((string)$json, true);
		return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
	}
}

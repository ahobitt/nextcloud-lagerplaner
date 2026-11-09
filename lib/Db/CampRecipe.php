<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Domain\Recipes\Allergen;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Rezept im Rezeptbestand eines Lagers.
 *
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDataJson()
 * @method void setDataJson(string $dataJson)
 * @method string|null getTemplateKey()
 * @method void setTemplateKey(?string $templateKey)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getCookbookId()
 * @method void setCookbookId(?int $cookbookId)
 * @method int|null getCookbookModified()
 * @method void setCookbookModified(?int $cookbookModified)
 * @method string|null getCookbookOwner()
 * @method void setCookbookOwner(?string $cookbookOwner)
 * @method string|null getAllergensCheckedBy()
 * @method void setAllergensCheckedBy(?string $allergensCheckedBy)
 * @method int|null getAllergensCheckedAt()
 * @method void setAllergensCheckedAt(?int $allergensCheckedAt)
 */
class CampRecipe extends Entity {
	use PersistsDefaults;

	protected $campId;
	protected $title;
	protected $dataJson;
	protected $templateKey;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;
	/** Verknüpftes Cookbook-Rezept: Ordnerkennung, Stand und wessen Sammlung */
	protected $cookbookId;
	protected $cookbookModified;
	protected $cookbookOwner;
	/** Wer die Allergenangaben bestätigt hat; leer heißt „nicht geprüft“. */
	protected $allergensCheckedBy;
	protected $allergensCheckedAt;

	public function __construct() {
		$this->addType('allergensCheckedAt', Types::BIGINT);
		$this->addType('cookbookId', Types::BIGINT);
		$this->addType('cookbookModified', Types::BIGINT);
		$this->addType('campId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}

	public function setRecipe(Recipe $recipe): void {
		$this->setTitle($recipe->title);
		$this->setDataJson(json_encode($recipe->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}

	public function isAllergensChecked(): bool {
		return $this->allergensCheckedAt !== null;
	}

	public function markAllergensChecked(?string $uid, int $now): void {
		$this->setAllergensCheckedBy($uid);
		$this->setAllergensCheckedAt($uid === null ? null : $now);
	}

	/** Gespeicherte Daten wurden beim Speichern geprüft; sie werden erneut validiert gelesen. */
	public function recipe(): Recipe {
		return Recipe::fromArray(json_decode($this->dataJson, true, 32, JSON_THROW_ON_ERROR));
	}

	/** @return array<string, mixed> Daten für die Rezeptliste */
	public function summary(): array {
		$recipe = $this->recipe();
		return [
			'id' => $this->id,
			'title' => $recipe->title,
			'servings' => $recipe->servings,
			'diet' => $recipe->diet->value,
			'contains' => array_map(static fn (Allergen $a): string => $a->value, $recipe->contains),
			'category' => $recipe->category,
			'prepMinutes' => $recipe->prepMinutes,
			'templateKey' => $this->templateKey,
			'allergensChecked' => $this->isAllergensChecked(),
			'allergensCheckedAt' => $this->allergensCheckedAt,
			'cookbook' => $this->cookbookId === null ? null : ['id' => (int)$this->cookbookId, 'modified' => (int)$this->cookbookModified, 'owner' => $this->cookbookOwner],
		];
	}
}

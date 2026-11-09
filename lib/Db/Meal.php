<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCA\Lagerplaner\Domain\Wizard\ProposedMeal;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getDay()
 * @method void setDay(string $day)
 * @method string getMealType()
 * @method void setMealType(string $mealType)
 * @method string getMealTime()
 * @method void setMealTime(string $mealTime)
 * @method bool|null getSelfCatered()
 * @method void setSelfCatered(bool $selfCatered)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method bool|null getCancelled()
 * @method void setCancelled(bool $cancelled)
 * @method bool|null getPortable()
 * @method void setPortable(bool $portable)
 * @method string|null getVariantPlan()
 * @method void setVariantPlan(?string $variantPlan)
 */
class Meal extends Entity implements JsonSerializable {
	use PersistsDefaults;

	use HasProvenance;

	protected $campId;
	protected $day;
	protected $mealType;
	protected $mealTime;
	protected $selfCatered = true;
	protected $note;
	/** Entfällt, etwa weil alle ein Lunchpaket mitbringen; zählt nicht für Mengen und Einkauf. */
	protected $cancelled = false;
	/** Unterwegs eingenommen (Lunchpaket), überschneidet sich gewollt mit dem Programm. */
	protected $portable = false;
	/** Menüvarianten und Zuordnung der Bedarfskombinationen als JSON (Konzept 9.8) */
	protected $variantPlan;

	public function __construct() {
		$this->addType('portable', Types::BOOLEAN);
		$this->addType('cancelled', Types::BOOLEAN);
		$this->addType('campId', Types::BIGINT);
		$this->addType('selfCatered', Types::BOOLEAN);
		$this->addProvenanceTypes();
		$this->persistDefaults();
	}

	public function fillFrom(ProposedMeal $p): void {
		$this->setDay($p->day);
		$this->setMealType($p->mealType);
		$this->setMealTime($p->time);
		$this->setSelfCatered($p->selfCatered);
		$this->setNote($p->note === '' ? null : $p->note);
		$this->setPortable($p->portable);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'campId' => $this->campId,
			'day' => $this->day,
			'mealType' => $this->mealType,
			'time' => $this->mealTime,
			'selfCatered' => (bool)$this->selfCatered,
			'note' => $this->note,
			'portable' => (bool)$this->portable,
			'cancelled' => (bool)$this->cancelled,
			'hasVariantPlan' => $this->variantPlan !== null && $this->variantPlan !== '',
		] + $this->provenanceJson();
	}
}

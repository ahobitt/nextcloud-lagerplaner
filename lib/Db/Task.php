<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use JsonSerializable;
use OCA\Lagerplaner\Domain\Wizard\ProposedTask;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCampId()
 * @method void setCampId(int $campId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getDueDate()
 * @method void setDueDate(?string $dueDate)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool|null getMandatory()
 * @method void setMandatory(bool $mandatory)
 * @method string getAssigneesJson()
 * @method void setAssigneesJson(string $assigneesJson)
 * @method string getPriority()
 * @method void setPriority(string $priority)
 * @method int|null getProgramItemId()
 * @method void setProgramItemId(?int $programItemId)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $completedBy)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $completedAt)
 * @method int|null getRemindedAt()
 * @method void setRemindedAt(?int $remindedAt)
 */
class Task extends Entity implements JsonSerializable {
	use PersistsDefaults;

	use HasProvenance;

	public const STATUS_OPEN = 'open';
	public const STATUS_DONE = 'done';

	protected $campId;
	protected $title;
	protected $description;
	protected $dueDate;
	protected $status = self::STATUS_OPEN;
	protected $mandatory = false;
	/** Verantwortliche Personen (Nextcloud-Benutzer), Konzept 12.1 */
	protected $assigneesJson = '[]';
	protected $priority = 'normal';
	protected $programItemId;
	protected $completedBy;
	protected $completedAt;
	/** Wann an die Frist erinnert wurde; einmal je Frist */
	protected $remindedAt;

	public function __construct() {
		$this->addType('campId', Types::BIGINT);
		$this->addType('mandatory', Types::BOOLEAN);
		$this->addType('programItemId', Types::BIGINT);
		$this->addType('completedAt', Types::BIGINT);
		$this->addType('remindedAt', Types::BIGINT);
		$this->addProvenanceTypes();
		$this->persistDefaults();
	}

	public function fillFrom(ProposedTask $p): void {
		$this->setTitle($p->title);
		$this->setDescription($p->description === '' ? null : $p->description);
		$this->setDueDate($p->dueDate);
		$this->setMandatory($p->mandatory);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'campId' => $this->campId,
			'title' => $this->title,
			'description' => $this->description,
			'dueDate' => $this->dueDate,
			'status' => $this->status,
			'mandatory' => (bool)$this->mandatory,
			'assignees' => $this->assigneeList(),
			'priority' => $this->priority,
			'programItemId' => $this->programItemId,
			'completedBy' => $this->completedBy,
			'completedAt' => $this->completedAt,
		] + $this->provenanceJson();
	}

	/** @return list<string> */
	public function assigneeList(): array {
		$list = json_decode((string)$this->assigneesJson, true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	/** @param list<string> $uids */
	public function setAssigneeList(array $uids): void {
		$this->setAssigneesJson(json_encode(array_values(array_unique($uids)), JSON_THROW_ON_ERROR));
	}
}

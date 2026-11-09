<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Controller;

use OCA\Lagerplaner\AppInfo\Application;
use OCA\Lagerplaner\Service\ShoppingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ShoppingApiController extends Controller {
	use JsonErrors;

	public function __construct(
		IRequest $request,
		private ShoppingService $shopping,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(fn () => $this->shopping->overview($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function addTrip(int $id, string $date = '', string $label = ''): JSONResponse {
		return $this->respond(function () use ($id, $date, $label) {
			$this->shopping->addTrip($id, (string)$this->userId, $date, $label);
			return ['ok' => true];
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateTrip(int $id, string $date = '', string $label = ''): JSONResponse {
		return $this->respond(function () use ($id, $date, $label) {
			$this->shopping->updateTrip($id, (string)$this->userId, $date, $label);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function deleteTrip(int $id): JSONResponse {
		return $this->respond(fn () => $this->shopping->deleteTrip($id, (string)$this->userId));
	}

	#[NoAdminRequired]
	public function setLine(int $id, string $key = '', bool $purchased = false): JSONResponse {
		return $this->respond(function () use ($id, $key, $purchased) {
			$this->shopping->setPurchased($id, (string)$this->userId, $key, $purchased);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function setStock(int $id, string $key = '', ?string $quantity = null, string $unit = ''): JSONResponse {
		return $this->respond(function () use ($id, $key, $quantity, $unit) {
			$value = $quantity === null || trim($quantity) === '' ? null : str_replace(',', '.', trim($quantity));
			if ($value !== null && !is_numeric($value)) {
				throw new \InvalidArgumentException('Bitte gib die vorhandene Menge als Zahl an.');
			}
			$this->shopping->setStock($id, (string)$this->userId, $key, $value === null ? null : (float)$value, $unit);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function addExtra(int $id, string $name = '', string $amount = '', string $category = 'other'): JSONResponse {
		return $this->respond(fn () => $this->shopping->addExtra($id, (string)$this->userId, $name, $amount, $category), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateExtra(int $id, bool $purchased = false): JSONResponse {
		return $this->respond(function () use ($id, $purchased) {
			$this->shopping->setExtraPurchased($id, (string)$this->userId, $purchased);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function deleteExtra(int $id): JSONResponse {
		return $this->respond(function () use ($id) {
			$this->shopping->deleteExtra($id, (string)$this->userId);
			return ['deleted' => $id];
		});
	}
}

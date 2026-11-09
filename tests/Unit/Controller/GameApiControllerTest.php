<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Controller;

use OCA\Lagerplaner\Controller\GameApiController;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Tests\Unit\Service\FakeCatalogGameMapper;
use OCA\Lagerplaner\Tests\Unit\Service\FakeGameSource;
use OCA\Lagerplaner\Tests\Unit\Service\FakeGamesConfig;
use OCA\Lagerplaner\Tests\Unit\Service\FakeImportQueue;
use OCA\Lagerplaner\Tests\Unit\Service\FixedTime;
use OCA\Lagerplaner\Tests\Unit\Service\InMemoryStore;
use OCA\Lagerplaner\Tests\Unit\Service\NoTransaction;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Regression: Die Suchfilter müssen beim Katalog ankommen. */
final class GameApiControllerTest extends TestCase {
	private function controller(bool $enabled = true): GameApiController {
		$queue = new FakeImportQueue();
		$catalog = new GameCatalogService(new FakeGameSource(), new FakeCatalogGameMapper(new InMemoryStore()), new FakeGamesConfig($enabled), new NoTransaction(), new FixedTime(), $queue);
		$queue->catalog = $catalog;
		return new GameApiController($this->createStub(IRequest::class), $catalog);
	}

	public function testFiltersReachTheCatalog(): void {
		$controller = $this->controller();

		$this->assertSame(4, $controller->search()->getData()['total']);
		$this->assertSame(1, $controller->search(q: 'zip')->getData()['total']);
		// Bis 10 Minuten: das 5-Minuten-Spiel und das Spiel mit unbekannter Dauer, das nicht ausgeschlossen wird.
		$this->assertSame(2, $controller->search(duration: '10')->getData()['total']);
		// Drinnen und ab 10 Jahren geeignet: das Spiel ab 12 fällt heraus.
		$this->assertSame(2, $controller->search(environment: 'Drinnen', age: '10')->getData()['total']);
		$this->assertSame(0, $controller->search(environment: 'Drinnen', age: '5')->getData()['total']);
	}

	public function testInvalidFilterIsABadRequest(): void {
		$this->assertSame(400, $this->controller()->search(age: 'zehn')->getStatus());
	}

	public function testDisabledIntegrationAnswersUnavailable(): void {
		$response = $this->controller(false)->search();

		$this->assertSame(503, $response->getStatus());
		$this->assertFalse($response->getData()['enabled']);
	}
}

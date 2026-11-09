<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use OCA\Lagerplaner\Domain\Rerun\RerunPlanner;
use OCA\Lagerplaner\Domain\Wizard\DraftGenerator;
use OCA\Lagerplaner\Service\CampService;
use OCA\Lagerplaner\Service\CateringService;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\CookbookService;
use OCA\Lagerplaner\Service\ExportService;
use OCA\Lagerplaner\Service\MaterialService;
use OCA\Lagerplaner\Service\TemplateService;
use OCA\Lagerplaner\Service\ShortlistService;
use OCA\Lagerplaner\Service\SiteDirectoryService;
use OCA\Lagerplaner\Service\SiteSynchronizer;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Recipes\StarterRecipes;
use OCA\Lagerplaner\Service\PlanningService;
use OCA\Lagerplaner\Service\RecipeService;
use OCA\Lagerplaner\Service\ShoppingService;
use OCA\Lagerplaner\Service\TeamService;
use OCA\Lagerplaner\Service\WizardService;
use PHPUnit\Framework\TestCase;

/** Alle Services mit In-Memory-Mappern und nachgebildetem Benutzerverzeichnis. */
abstract class ServiceTestCase extends TestCase {
	protected WizardService $wizard;
	protected PlanningService $planning;
	protected CampService $camps;
	protected CateringService $catering;
	protected GameCatalogService $catalog;
	protected TeamService $team;
	protected RecipeService $recipes;
	protected ShoppingService $shopping;
	protected FixedTime $time;
	protected FakeGameSource $source;
	protected FakeGamesConfig $gamesConfig;
	protected FakeDirectory $directory;
	protected FakeImportQueue $importQueue;
	protected FakeTaskNotifications $notifications;
	protected MaterialService $material;
	protected FakeTaskMapper $taskMapper;
	protected FakeCampMapper $campMapper;
	protected SiteDirectoryService $sites;
	protected TemplateService $templates;
	protected FakeCatalogGameMapper $catalogMapper;
	protected ExportService $exports;
	protected CookbookService $cookbook;
	protected FakeCookbookLibrary $cookbookLibrary;
	protected ShortlistService $shortlist;
	protected FakeSitesConfig $sitesConfig;
	protected FakeSiteFactMapper $siteFacts;
	protected FakeSiteMapper $siteMapper;
	protected FakeSiteSourceMapper $siteSources;
	protected FakeSchwarzzeltSource $schwarzzelt;
	protected SiteSources $registry;
	protected SiteSynchronizer $synchronizer;
	protected \OCA\Lagerplaner\Service\SiteEnricher $enricher;
	protected \OCA\Lagerplaner\Service\BundledSites $bundled;
	protected \OCA\Lagerplaner\Service\SiteFeedbackService $siteFeedback;
	protected FakeShoppingItemMapper $shoppingItems;
	protected FakeShoppingTripMapper $shoppingTrips;
	protected FakeProgramItemMapper $programItems;

	protected function setUp(): void {
		$store = new InMemoryStore();
		$this->time = new FixedTime();
		$camps = new FakeCampMapper($store);
		$groups = new FakeCampGroupMapper($store);
		$runs = new FakeGenerationRunMapper($store);
		$program = new FakeProgramItemMapper($store);
		$tasks = new FakeTaskMapper($store);
		$meals = new FakeMealMapper($store);
		$tx = new NoTransaction();
		$programGames = new FakeProgramGameMapper($store);
		$dietNeeds = new FakeDietNeedMapper($store);
		$members = new FakeCampMemberMapper($store);
		$recipeMapper = new FakeCampRecipeMapper($store);
		$trips = new FakeShoppingTripMapper($store);
		$items = new FakeShoppingItemMapper($store);
		$stock = new FakeShoppingStockMapper($store);
		$materials = new FakeMaterialMapper($store);
		$materialUses = new FakeMaterialUseMapper($store);
		$dismissed = new FakeMaterialDismissedMapper($store);
		$this->notifications = new FakeTaskNotifications();
		$campSites = new FakeCampSiteMapper($store);
		$this->sitesConfig = new FakeSitesConfig();
		$this->taskMapper = $tasks;
		$this->campMapper = $camps;
		$this->shoppingItems = $items;
		$this->shoppingTrips = $trips;
		$this->programItems = $program;
		$this->directory = new FakeDirectory();
		$this->source = new FakeGameSource();
		$this->gamesConfig = new FakeGamesConfig();
		$this->importQueue = new FakeImportQueue();
		$this->catalogMapper = new FakeCatalogGameMapper($store);
		$this->catalog = new GameCatalogService($this->source, $this->catalogMapper, $this->gamesConfig, $tx, $this->time, $this->importQueue);
		$this->importQueue->catalog = $this->catalog;

		$this->camps = new CampService($camps, $groups, $program, $tasks, $meals, $runs, $tx, $programGames, $dietNeeds, $members, $this->directory, $recipeMapper, $trips, $items, $stock, $materials, $materialUses, $dismissed, $campSites);
		$this->recipes = new RecipeService($this->camps, $recipeMapper, $meals, new StarterRecipes(), $tx, $this->time);
		$this->cookbookLibrary = new FakeCookbookLibrary();
		$this->cookbook = new CookbookService($this->camps, $this->cookbookLibrary, $recipeMapper, $this->recipes, $this->time);
		$this->planning = new PlanningService($this->camps, $program, $tasks, $meals, $this->time, $programGames, $this->catalog, $this->notifications, $materialUses);
		$siteMapper = $this->siteMapper = new FakeSiteMapper($store);
		$this->siteFacts = new FakeSiteFactMapper($store);
		$this->siteSources = new FakeSiteSourceMapper($store);
		$this->schwarzzelt = new FakeSchwarzzeltSource(\OCA\Lagerplaner\Sites\SchwarzzeltItSource::BUNDLED);
		$this->registry = new SiteSources($this->schwarzzelt);
		$this->synchronizer = new SiteSynchronizer($siteMapper, $this->siteFacts, $this->siteSources, $this->registry, $tx, $this->time);
		$this->sites = new SiteDirectoryService($siteMapper, $campSites, $this->sitesConfig, $this->directory, $tx, $this->time, $this->synchronizer, $this->siteSources, $this->siteFacts, $this->registry);
		$this->enricher = new \OCA\Lagerplaner\Service\SiteEnricher($siteMapper, $this->siteFacts, $this->sites, $campSites, $tx, $this->time);
		$this->bundled = new \OCA\Lagerplaner\Service\BundledSites($this->registry, $this->synchronizer, $this->siteSources, $this->enricher, $this->sitesConfig);
		$this->siteFeedback = new \OCA\Lagerplaner\Service\SiteFeedbackService(new FakeSiteFeedbackMapper($store), new FakeSiteFeedbackValueMapper($store), $this->sites, $this->directory, $tx, $this->time, $this->registry);
		$this->shortlist = new ShortlistService($this->camps, $campSites, $this->sites, $this->time);
		$this->material = new MaterialService($this->camps, $materials, $materialUses, $dismissed, $program, $programGames, $tx, $this->time);
		$this->templates = new TemplateService($this->camps, new FakeTemplateMapper($store), $program, $materials, $materialUses, $this->planning, $this->material, $this->directory, $tx, $this->time);
		$this->catering = new CateringService($this->camps, $dietNeeds, $meals, $tasks, $tx, $this->time, $this->recipes);
		$this->wizard = new WizardService(new DraftGenerator(), new RerunPlanner(), $this->camps, $camps, $groups, $runs, $program, $tasks, $meals, $tx, $this->time, $trips, $programGames, $items, $materialUses, $this->templates, $this->catering);
		$this->exports = new ExportService($this->camps, $this->planning, $this->material, $this->catering, $this->recipes, $programGames, $this->catalogMapper, $this->time);
		$this->shopping = new ShoppingService($this->camps, $this->catering, $trips, $items, $stock, $tx, $this->time);
		$this->team = new TeamService($this->camps, $members, $this->directory, $this->time);
	}
}

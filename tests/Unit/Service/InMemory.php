<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\CampMember;
use OCA\Lagerplaner\Db\CampMemberMapper;
use OCA\Lagerplaner\Db\CampRecipeMapper;
use OCA\Lagerplaner\Db\MaterialDismissedMapper;
use OCA\Lagerplaner\Db\MaterialMapper;
use OCA\Lagerplaner\Db\MaterialUseMapper;
use OCA\Lagerplaner\Db\Camp as CampEntity;
use OCA\Lagerplaner\Db\Task as TaskEntity;
use OCA\Lagerplaner\Service\TaskNotifications;
use OCA\Lagerplaner\Cookbook\CookbookLibrary;
use OCA\Lagerplaner\Db\CampSiteMapper;
use OCA\Lagerplaner\Db\SiteMapper;
use OCA\Lagerplaner\Db\Template;
use OCA\Lagerplaner\Db\TemplateMapper;
use OCA\Lagerplaner\Db\SiteRecord;
use OCA\Lagerplaner\Service\SitesConfig;
use OCA\Lagerplaner\Db\ShoppingItemMapper;
use OCA\Lagerplaner\Db\ShoppingStockMapper;
use OCA\Lagerplaner\Db\ShoppingTripMapper;
use OCA\Lagerplaner\Db\CatalogGame;
use OCA\Lagerplaner\Db\CatalogGameMapper;
use OCA\Lagerplaner\Db\DietNeedMapper;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Domain\Games\GameLicense;
use OCA\Lagerplaner\Domain\Games\InvalidGameData;
use OCA\Lagerplaner\Domain\Games\WordPressGameNormalizer;
use OCA\Lagerplaner\Games\GameSource;
use OCA\Lagerplaner\Service\GameCatalogService;
use OCA\Lagerplaner\Service\GamesConfig;
use OCA\Lagerplaner\Service\NotFoundException;
use OCA\Lagerplaner\Service\GamesImportQueue;
use OCA\Lagerplaner\Service\GamesUnavailableException;
use OCA\Lagerplaner\Db\CampGroupMapper;
use OCA\Lagerplaner\Db\CampMapper;
use OCA\Lagerplaner\Db\GenerationRunMapper;
use OCA\Lagerplaner\Db\MealMapper;
use OCA\Lagerplaner\Db\ProgramItemMapper;
use OCA\Lagerplaner\Db\TaskMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;

/** Speicher für die In-Memory-Mapper; ersetzt die Datenbank in Servicetests. */
final class InMemoryStore {
	/** @var array<string, array<int, Entity>> */
	public array $rows = [];
	/** Nur für den Testaufbau im Browser: Einstellungen der Spieleanbindung über Anfragen hinweg. */
	public mixed $gamesConfig = null;
	/** Konfiguration des Lagerplatzverzeichnisses, im Testaufbau über Anfragen hinweg gespeichert */
	public mixed $sitesConfig = null;
	/** @var array<string, int> Nächste ID je Tabelle, wie Autoincrement in der Datenbank */
	private array $nextIds = [];

	public function insert(string $table, Entity $entity): Entity {
		$this->nextIds[$table] ??= 1;
		$entity->setId($this->nextIds[$table]++);
		$entity->resetUpdatedFields();
		$this->rows[$table][$entity->getId()] = clone $entity;
		return $entity;
	}

	/** Schreibt wie QBMapper::update nur die geänderten Felder. */
	public function update(string $table, Entity $entity): Entity {
		if (!isset($this->rows[$table][$entity->getId()])) {
			throw new \LogicException('Update auf nicht vorhandene Zeile');
		}
		$stored = clone $this->rows[$table][$entity->getId()];
		foreach (array_keys($entity->getUpdatedFields()) as $field) {
			$stored->{'set' . ucfirst($field)}($entity->{'get' . ucfirst($field)}());
		}
		$stored->resetUpdatedFields();
		$entity->resetUpdatedFields();
		$this->rows[$table][$entity->getId()] = $stored;
		return $entity;
	}

	/** Nur für Lager: Version erhöhen, optional nur bei erwarteter Version. */
	public function bumpVersion(string $table, int $id, int $now, ?int $expected): bool {
		$row = $this->rows[$table][$id] ?? null;
		if ($row === null || ($expected !== null && (int)$row->getVersion() !== $expected)) {
			return false;
		}
		$row->setVersion((int)$row->getVersion() + 1);
		$row->setUpdatedAt($now);
		$row->resetUpdatedFields();
		return true;
	}

	public function delete(string $table, Entity $entity): Entity {
		unset($this->rows[$table][$entity->getId()]);
		return $entity;
	}

	public function find(string $table, int $id): Entity {
		if (!isset($this->rows[$table][$id])) {
			throw new DoesNotExistException('nicht gefunden');
		}
		return clone $this->rows[$table][$id];
	}

	/** @return list<Entity> */
	public function where(string $table, callable $filter): array {
		$rows = array_values(array_filter($this->rows[$table] ?? [], $filter));
		return array_map(static fn (Entity $e): Entity => clone $e, $rows);
	}
}

trait InMemoryScoped {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(static::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(static::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(static::class, $entity);
	}

	public function find(int $id): Entity {
		return $this->store->find(static::class, $id);
	}

	public function findByCamp(int $campId): array {
		return $this->store->where(static::class, static fn (Entity $e): bool => $e->getCampId() === $campId
			&& (!property_exists($e, 'deletedAt') || $e->getDeletedAt() === null));
	}

	public function findByCampIncludingDeleted(int $campId): array {
		return $this->store->where(static::class, static fn (Entity $e): bool => $e->getCampId() === $campId);
	}

	public function findDeletedByCamp(int $campId): array {
		return $this->store->where(static::class, static fn (Entity $e): bool => $e->getCampId() === $campId
			&& property_exists($e, 'deletedAt') && $e->getDeletedAt() !== null);
	}

	public function deleteByCamp(int $campId): void {
		foreach ($this->findByCampIncludingDeleted($campId) as $entity) {
			$this->store->delete(static::class, $entity);
		}
	}
}

final class FakeCampMapper extends CampMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(self::class, $entity);
	}

	public function find(int $id): Camp {
		/** @var Camp */
		return $this->store->find(self::class, $id);
	}

	public function bumpVersion(int $id, int $now, ?int $expected = null): bool {
		return $this->store->bumpVersion(self::class, $id, $now, $expected);
	}

	public function findByOwner(string $uid): array {
		return $this->store->where(self::class, static fn (Camp $c): bool => $c->getOwnerUid() === $uid);
	}

	public function findByIds(array $ids): array {
		return $this->store->where(self::class, static fn (Camp $c): bool => in_array((int)$c->getId(), $ids, true));
	}
}

final class FakeCampGroupMapper extends CampGroupMapper {
	use InMemoryScoped;
}

final class FakeGenerationRunMapper extends GenerationRunMapper {
	use InMemoryScoped;
}

final class FakeProgramItemMapper extends ProgramItemMapper {
	use InMemoryScoped;
}

final class FakeTaskMapper extends TaskMapper {
	use InMemoryScoped;

	public function findDueForReminder(string $from, string $to): array {
		return $this->store->where(self::class, static fn (TaskEntity $t): bool => $t->getStatus() === 'open' && $t->getDeletedAt() === null
			&& $t->getRemindedAt() === null && $t->getDueDate() !== null && $t->getDueDate() >= $from && $t->getDueDate() <= $to);
	}
}

final class FakeMealMapper extends MealMapper {
	use InMemoryScoped;
}

final class NoTransaction extends Transaction {
	public function __construct() {
	}

	public function run(callable $callback): mixed {
		return $callback();
	}
}

final class FixedTime implements ITimeFactory {
	public int $now = 1_791_100_000;

	public function __construct(private string $today = '2026-10-11') {
	}

	public function getTime(): int {
		return $this->now;
	}

	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		return new DateTime($time === 'today' ? $this->today : '@' . $this->now, $timezone ?? new DateTimeZone('UTC'));
	}

	public function withTimeZone(DateTimeZone $timezone): static {
		return $this;
	}

	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}

	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->now);
	}
}

final class FakeProgramGameMapper extends ProgramGameMapper {
	use InMemoryScoped;

	public function findByProgramItem(int $programItemId): array {
		return $this->store->where(self::class, static fn (Entity $e): bool => $e->getProgramItemId() === $programItemId);
	}
}

final class FakeCatalogGameMapper extends CatalogGameMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(self::class, $entity);
	}

	public function find(int $id): CatalogGame {
		/** @var CatalogGame */
		return $this->store->find(self::class, $id);
	}

	public function findBySource(string $source): array {
		return $this->store->where(self::class, static fn (CatalogGame $g): bool => $g->getSource() === $source);
	}
}

/** Liefert die Testdaten seitenweise wie die WordPress-API. */
final class FakeGameSource implements GameSource {
	public bool $fail = false;
	public int $requests = 0;
	/** @var list<array<string, mixed>> */
	public array $posts;
	private WordPressGameNormalizer $normalizer;

	public function __construct(private int $perPage = 2) {
		$this->posts = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/wordpress-posts.json'), true, 32, JSON_THROW_ON_ERROR);
		$this->normalizer = new WordPressGameNormalizer('pfadfinder-spiele.de', 'pfadfinder-spiele.de');
	}

	public function key(): string {
		return 'pfadfinder-spiele.de';
	}

	public function license(): GameLicense {
		return new GameLicense('CC BY-NC-SA 4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.de', 'Dorothea Schümann, Pfadfinder-Spiele.de');
	}

	public function fetchPage(int $page): array {
		$this->requests++;
		if ($this->fail) {
			throw new GamesUnavailableException('Pfadfinder-Spiele.de ist gerade nicht erreichbar.');
		}
		$games = [];
		$skipped = 0;
		foreach (array_slice($this->posts, ($page - 1) * $this->perPage, $this->perPage) as $post) {
			try {
				$games[] = $this->normalizer->normalize($post);
			} catch (InvalidGameData) {
				$skipped++;
			}
		}
		return ['games' => $games, 'skipped' => $skipped, 'totalPages' => max(1, (int)ceil(count($this->posts) / $this->perPage))];
	}
}

final class FakeGamesConfig extends GamesConfig {
	public int $syncedAt = 0;

	public function __construct(public bool $enabled = true) {
	}

	public function isEnabled(): bool {
		return $this->enabled;
	}

	public function setEnabled(bool $enabled): void {
		$this->enabled = $enabled;
	}

	public function syncedAt(): int {
		return $this->syncedAt;
	}

	public function setSyncedAt(int $time): void {
		$this->syncedAt = $time;
	}
}

final class FakeDietNeedMapper extends DietNeedMapper {
	use InMemoryScoped;
}

final class FakeCampMemberMapper extends CampMemberMapper {
	use InMemoryScoped;

	public function findForPrincipal(string $uid, array $groupIds, ?int $campId = null): array {
		return $this->store->where(self::class, static fn (CampMember $m): bool => ($campId === null || $m->getCampId() === $campId)
			&& (($m->getMemberType() === Directory::USER && $m->getMemberId() === $uid)
				|| ($m->getMemberType() === Directory::GROUP && in_array($m->getMemberId(), $groupIds, true))));
	}

	public function findMember(int $campId, string $type, string $id): CampMember {
		foreach ($this->findByCamp($campId) as $member) {
			if ($member->getMemberType() === $type && $member->getMemberId() === $id) {
				return $member;
			}
		}
		throw new DoesNotExistException('nicht gefunden');
	}
}

/** Benutzer und Gruppen wie in einer kleinen Stammes-Nextcloud. */
final class FakeDirectory implements Directory {
	public array $users = ['anna' => 'Anna Adler', 'ben' => 'Ben Berg', 'carla' => 'Carla Christ', 'dora' => 'Dora Dorn', 'emil' => 'Emil Ernst'];
	public array $groups = ['leiterrunde' => ['Leiterrunde', ['ben', 'carla']], 'kueche' => ['Küchenteam Stamm', ['dora']]];
	/** @var list<string> Ziele, mit denen die Freigabe-Einstellungen das Teilen verbieten („user:emil“) */
	public array $blocked = [];

	/** @var list<string> */
	public array $admins = [];

	public function isAdmin(string $uid): bool {
		return in_array($uid, $this->admins, true);
	}

	public function groupMembers(string $gid): array {
		return $this->groups[$gid][1] ?? [];
	}

	public function canShareWith(string $type, string $id): bool {
		return $this->exists($type, $id) && !in_array("$type:$id", $this->blocked, true);
	}

	public function groupIdsOf(string $uid): array {
		return array_keys(array_filter($this->groups, static fn (array $g): bool => in_array($uid, $g[1], true)));
	}

	public function exists(string $type, string $id): bool {
		return $type === self::USER ? isset($this->users[$id]) : ($type === self::GROUP && isset($this->groups[$id]));
	}

	public function displayName(string $type, string $id): string {
		return $type === self::USER ? ($this->users[$id] ?? $id) : ($this->groups[$id][0] ?? $id);
	}

	public function search(string $term, int $limit): array {
		$term = mb_strtolower($term);
		$found = [];
		foreach ($this->users as $id => $name) {
			if (str_contains(mb_strtolower($id . ' ' . $name), $term)) {
				$found[] = ['type' => self::USER, 'id' => $id, 'displayName' => $name];
			}
		}
		foreach ($this->groups as $id => [$name]) {
			if (str_contains(mb_strtolower($id . ' ' . $name), $term)) {
				$found[] = ['type' => self::GROUP, 'id' => $id, 'displayName' => $name];
			}
		}
		return array_slice($found, 0, $limit);
	}
	/** @var array<string, list<string>> Gruppe => Gruppenadministration */
	public array $groupAdmins = [];

	public function searchGroups(string $term, int $limit): array {
		$list = [];
		foreach ($this->groups as $gid => [$label]) {
			if ($term === '' || stripos($gid . ' ' . $label, $term) !== false) {
				$list[] = ['id' => (string)$gid, 'label' => $label];
			}
		}
		return array_slice($list, 0, $limit);
	}

	public function isGroupAdmin(string $uid, string $gid): bool {
		return $this->isAdmin($uid) || in_array($uid, $this->groupAdmins[$gid] ?? [], true);
	}
}

final class FakeCampRecipeMapper extends CampRecipeMapper {
	use InMemoryScoped;
}

final class FakeShoppingTripMapper extends ShoppingTripMapper {
	use InMemoryScoped;
}

final class FakeShoppingItemMapper extends ShoppingItemMapper {
	use InMemoryScoped;
}

final class FakeShoppingStockMapper extends ShoppingStockMapper {
	use InMemoryScoped;
}

/** Warteschlange für den Katalogabgleich; führt ihn wie ein sofort laufender Cron aus. */
final class FakeImportQueue extends GamesImportQueue {
	public ?GameCatalogService $catalog = null;
	public bool $immediate = true;
	public int $requests = 0;

	public function __construct() {
	}

	public function request(): void {
		$this->requests++;
		if ($this->immediate && $this->catalog !== null) {
			try {
				$this->catalog->sync();
			} catch (GamesUnavailableException) {
			}
		}
	}

	public function pending(): bool {
		return false;
	}
}

final class FakeMaterialMapper extends MaterialMapper {
	use InMemoryScoped;
}

final class FakeMaterialUseMapper extends MaterialUseMapper {
	use InMemoryScoped;
}

final class FakeMaterialDismissedMapper extends MaterialDismissedMapper {
	use InMemoryScoped;
}

/** Hält gesendete Benachrichtigungen fest, statt sie an die Nextcloud zu geben. */
final class FakeTaskNotifications extends TaskNotifications {
	/** @var list<array{0: string, 1: string, 2: string}> [Art, Empfänger, Aufgabe] */
	public array $sent = [];
	/** @var list<int> */
	public array $cleared = [];

	public function __construct() {
	}

	public function assigned(TaskEntity $task, CampEntity $camp, string $actorUid, array $uids, int $now): void {
		foreach ($uids as $uid) {
			$this->sent[] = ['assigned', $uid, $task->getTitle()];
		}
	}

	public function due(TaskEntity $task, CampEntity $camp, array $uids, int $now): void {
		foreach ($uids as $uid) {
			$this->sent[] = ['due', $uid, $task->getTitle()];
		}
	}

	public function clear(TaskEntity $task): void {
		$this->cleared[] = (int)$task->getId();
	}
}

final class FakeSiteMapper extends SiteMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(self::class, $entity);
	}

	public function find(int $id): SiteRecord {
		/** @var SiteRecord */
		return $this->store->find(self::class, $id);
	}

	public function findAll(): array {
		return $this->store->where(self::class, static fn (): bool => true);
	}

	public function findBySource(string $source): array {
		return $this->store->where(self::class, static fn (SiteRecord $s): bool => $s->getSource() === $source);
	}
}

final class FakeCampSiteMapper extends CampSiteMapper {
	use InMemoryScoped;

	public function findBySite(int $siteId): array {
		return $this->store->where(self::class, static fn (Entity $e): bool => $e->getSiteId() === $siteId);
	}
}

final class FakeSitesConfig extends SitesConfig {
	/** @var list<string> */
	public array $disabled = [];
	/** @var array{0: float, 1: float}|null */
	public ?array $homeCoordinates = null;
	/** @var array<string, array{label: string, lat: float, lon: float}> */
	public array $groupHomeList = [];

	public function groupHomes(): array {
		return $this->groupHomeList;
	}

	public function setGroupHome(string $gid, ?array $home): void {
		if ($home === null) {
			unset($this->groupHomeList[$gid]);
		} else {
			$this->groupHomeList[$gid] = $home;
		}
	}

	public bool $sync = true;
	/** @var array<string, string> */
	public array $enrichment = [];

	public function enrichmentVersion(string $source): string {
		return $this->enrichment[$source] ?? '';
	}

	public function setEnrichmentVersion(string $source, string $version): void {
		$this->enrichment[$source] = $version;
	}

	public function autoSync(): bool {
		return $this->sync;
	}

	public function setAutoSync(bool $enabled): void {
		$this->sync = $enabled;
	}

	public function __construct() {
	}

	public function disabledSources(): array {
		return $this->disabled;
	}

	public function setDisabledSources(array $sources): void {
		$this->disabled = array_values(array_unique($sources));
	}

	public function setHome(?array $coordinates): void {
		$this->homeCoordinates = $coordinates;
	}

	public function home(): ?array {
		return $this->homeCoordinates;
	}
}

final class FakeTemplateMapper extends TemplateMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(self::class, $entity);
	}

	public function find(int $id): Template {
		/** @var Template */
		return $this->store->find(self::class, $id);
	}

	public function findVisible(string $uid): array {
		return $this->store->where(self::class, static fn (Template $t): bool => $t->getOwnerUid() === $uid || (bool)$t->getShared());
	}
}

/** Cookbook-Sammlungen je Person, wie sie im Dateisystem lägen. */
final class FakeCookbookLibrary implements CookbookLibrary {
	/** @var array<string, string> Person => Status */
	public array $statuses = [];
	/** @var array<string, array<int, array{data: array<string, mixed>, modified: int}>> */
	public array $recipes = [];
	private int $nextId = 5000;

	public function status(string $uid): string {
		return $this->statuses[$uid] ?? self::READY;
	}

	public function add(string $uid, array $data, int $modified = 1000): int {
		$id = $this->nextId++;
		$this->recipes[$uid][$id] = ['data' => $data, 'modified' => $modified];
		return $id;
	}

	public function list(string $uid): array {
		$list = [];
		foreach ($this->recipes[$uid] ?? [] as $id => $entry) {
			$list[] = ['id' => $id, 'name' => (string)($entry['data']['name'] ?? ''), 'category' => (string)($entry['data']['recipeCategory'] ?? ''),
				'keywords' => (string)($entry['data']['keywords'] ?? ''), 'servings' => is_int($entry['data']['recipeYield'] ?? null) ? $entry['data']['recipeYield'] : null, 'modified' => $entry['modified']];
		}
		return $list;
	}

	public function read(string $uid, int $id): array {
		return $this->recipes[$uid][$id] ?? throw new NotFoundException('Dieses Rezept gibt es in Cookbook nicht mehr.');
	}

	public function create(string $uid, string $name, array $data): array {
		$id = $this->add($uid, $data + ['name' => $name], 2000);
		return ['id' => $id, 'modified' => 2000];
	}
}

/** Ausgabe eines Reparaturschritts, zum Nachlesen im Test. */
final class FakeOutput implements \OCP\Migration\IOutput {
	/** @var list<string> */
	public array $messages = [];

	public function debug(string $message): void {
	}

	public function info($message) {
		$this->messages[] = 'info: ' . $message;
	}

	public function warning($message) {
		$this->messages[] = 'warning: ' . $message;
	}

	public function startProgress($max = 0) {
	}

	public function advance($step = 1, $description = '') {
	}

	public function finishProgress() {
	}
}

final class FakeSiteSourceMapper extends \OCA\Lagerplaner\Db\SiteSourceMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function findByKey(string $key): ?\OCA\Lagerplaner\Db\SiteSourceRecord {
		foreach ($this->store->rows[self::class] ?? [] as $row) {
			if ($row->getSourceKey() === $key) {
				return clone $row;
			}
		}
		return null;
	}

	public function findAll(): array {
		return array_values(array_map(static fn ($r) => clone $r, $this->store->rows[self::class] ?? []));
	}
}

final class FakeSiteFactMapper extends \OCA\Lagerplaner\Db\SiteFactMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function delete(Entity $entity): Entity {
		return $this->store->delete(self::class, $entity);
	}

	public function findBySite(int $siteId): array {
		return array_values(array_map(static fn ($r) => clone $r, array_filter($this->store->rows[self::class] ?? [], static fn ($r) => (int)$r->getSiteId() === $siteId)));
	}

	public function findUnreported(): array {
		return array_values(array_map(static fn ($r) => clone $r, array_filter($this->store->rows[self::class] ?? [], static fn ($r) => $r->getReportedAt() === null)));
	}

	public function deleteBySite(int $siteId): void {
		foreach ($this->findBySite($siteId) as $fact) {
			$this->store->delete(self::class, $fact);
		}
	}
}

/** Campsitemap mit festem Inhalt statt GitHub; ohne Inhalt nicht erreichbar. */
final class FakeSchwarzzeltSource extends \OCA\Lagerplaner\Sites\SchwarzzeltItSource {
	public ?string $remote = null;

	public function __construct(string $bundledFile = '/nicht/vorhanden.csv') {
		parent::__construct(new NoNetworkClientService(), $bundledFile);
	}

	public function fetch(): \OCA\Lagerplaner\Sites\SourceSnapshot {
		if ($this->remote === null) {
			throw new \OCA\Lagerplaner\Sites\SourceUnavailable('Die Campsitemap ist nicht erreichbar.');
		}
		return $this->snapshot($this->remote);
	}
}

/** HTTP-Client ohne Netz: Tests rufen keine fremden Server auf. */
final class NoNetworkClientService implements \OCP\Http\Client\IClientService {
	public function newClient(): \OCP\Http\Client\IClient {
		throw new \LogicException('Im Test gibt es kein Netz.');
	}
}

final class FakeSiteFeedbackMapper extends \OCA\Lagerplaner\Db\SiteFeedbackMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function update(Entity $entity): Entity {
		return $this->store->update(self::class, $entity);
	}

	public function findByStatus(string $status): array {
		$rows = array_values(array_map(static fn ($r) => clone $r, array_filter($this->store->rows[self::class] ?? [], static fn ($r) => $r->getStatus() === $status)));
		usort($rows, static fn ($a, $b) => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);
		return $rows;
	}

	public function get(int $id): \OCA\Lagerplaner\Db\SiteFeedback {
		$row = $this->store->rows[self::class][$id] ?? throw new \OCP\AppFramework\Db\DoesNotExistException('nicht gefunden');
		return clone $row;
	}
}

final class FakeSiteFeedbackValueMapper extends \OCA\Lagerplaner\Db\SiteFeedbackValueMapper {
	public function __construct(private InMemoryStore $store) {
	}

	public function insert(Entity $entity): Entity {
		return $this->store->insert(self::class, $entity);
	}

	public function findByFeedback(int $feedbackId): array {
		return array_values(array_map(static fn ($r) => clone $r, array_filter($this->store->rows[self::class] ?? [], static fn ($r) => (int)$r->getFeedbackId() === $feedbackId)));
	}
}

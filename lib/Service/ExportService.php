<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use DateTimeImmutable;
use OCA\Lagerplaner\Db\Camp;
use OCA\Lagerplaner\Db\CatalogGameMapper;
use OCA\Lagerplaner\Db\Meal;
use OCA\Lagerplaner\Db\ProgramGame;
use OCA\Lagerplaner\Db\ProgramGameMapper;
use OCA\Lagerplaner\Db\ProgramItem;
use OCA\Lagerplaner\Db\Task;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Recipes\Allergen;
use OCA\Lagerplaner\Domain\Recipes\Recipe;
use OCA\Lagerplaner\Domain\Recipes\RecipeScaler;
use OCA\Lagerplaner\Domain\Wizard\Timeline;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Druckfertige Unterlagen für den Einsatz ohne Netz (Konzept 19).
 *
 * Erzeugt ein vollständiges HTML-Dokument im A4-Format, das die Oberfläche über den
 * Druckdialog des Browsers als PDF speichern lässt. Allgemeine Unterlagen enthalten keine
 * Ernährungs- oder Gesundheitsangaben; Speiseplan und Rezepte gibt es nur für Personen
 * mit Zugriff auf die Küchenplanung (Konzept 19.3).
 */
class ExportService {
	public const TYPES = ['program', 'day', 'group', 'games', 'tasks', 'meals', 'recipes'];
	private const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
	private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
	private const MEALS = ['breakfast' => 'Frühstück', 'lunch' => 'Mittagessen', 'snack' => 'Zwischenmahlzeit', 'dinner' => 'Abendessen'];
	private const STATUS = ['open' => 'offen', 'done' => 'erledigt'];
	private const PRIORITY = ['high' => 'wichtig', 'normal' => '', 'low' => 'kann warten'];

	public function __construct(
		private CampService $camps,
		private PlanningService $planning,
		private MaterialService $material,
		private CateringService $catering,
		private RecipeService $recipes,
		private ProgramGameMapper $programGames,
		private CatalogGameMapper $catalogGames,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{title: string, html: string}
	 * @throws \InvalidArgumentException bei unbekannter Art oder unpassendem Parameter
	 */
	public function document(int $campId, string $uid, string $type, string $param = ''): array {
		$camp = $this->camps->getForUser($campId, $uid);
		[$title, $body] = match ($type) {
			'program' => ['Lagerprogramm', $this->program($camp, $uid, null, null)],
			'day' => ['Tagesprogramm ' . $this->day($this->dayParam($camp, $param)), $this->program($camp, $uid, $this->dayParam($camp, $param), null)],
			'group' => $this->groupDocument($camp, $uid, $param),
			'games' => ['Spielanleitungen', $this->games($camp, $uid)],
			'tasks' => ['Aufgaben', $this->tasks($camp, $uid)],
			'meals' => ['Speiseplan für die Küche', $this->meals($camp, $uid)],
			'recipes' => ['Rezepte für die Küche', $this->recipeBook($camp, $uid)],
			default => throw new \InvalidArgumentException('Unbekannte Unterlage.'),
		};
		return ['title' => $title . ' – ' . $camp->getName(), 'html' => $this->shell($camp, $title, $body)];
	}

	/** @return array{0: string, 1: string} */
	private function groupDocument(Camp $camp, string $uid, string $key): array {
		foreach ($this->camps->groups((int)$camp->getId()) as $group) {
			if ($group->getGroupKey() === $key && !$group->getIsLeaders()) {
				return ['Programm für ' . $group->getLabel(), $this->program($camp, $uid, null, $key)];
			}
		}
		throw new \InvalidArgumentException('Unbekannte Altersstufe.');
	}

	private function program(Camp $camp, string $uid, ?string $onlyDay, ?string $group): string {
		$campId = (int)$camp->getId();
		$labels = [];
		foreach ($this->camps->groups($campId) as $g) {
			$labels[$g->getGroupKey()] = $g->getLabel();
		}
		$titles = [];
		$items = [];
		foreach ($this->planning->program($campId, $uid) as $item) {
			$titles[(int)$item->getId()] = $item->getTitle();
			$groups = $item->groupList();
			if (($onlyDay === null || $item->getDay() === $onlyDay) && ($group === null || $groups === [] || in_array($group, $groups, true))) {
				$items[$item->getDay()][] = $item;
			}
		}
		$games = [];
		foreach ($this->programGames->findByCamp($campId) as $link) {
			$games[(int)$link->getProgramItemId()][] = $link->getTitle();
		}
		$materials = [];
		foreach ($this->material->overview($campId, $uid)['items'] as $material) {
			foreach ($material['uses'] as $use) {
				if ($use['programItemId'] !== null) {
					$amount = $use['quantity'] === null ? '' : ' (' . self::number($use['quantity']) . ($material['unit'] ? ' ' . $material['unit'] : '') . ')';
					$materials[(int)$use['programItemId']][] = $material['name'] . $amount;
				}
			}
		}
		// Mahlzeiten nur mit Art und Uhrzeit, ohne Varianten oder Ernährungsangaben (Konzept 19.3).
		$meals = [];
		foreach ($this->planning->meals($campId, $uid) as $meal) {
			if ($onlyDay === null || $meal->getDay() === $onlyDay) {
				$meals[$meal->getDay()][] = $meal;
			}
		}

		$days = array_unique([...array_keys($items), ...array_keys($meals)]);
		sort($days);
		if ($days === []) {
			return '<p>Für diese Auswahl ist noch nichts geplant.</p>';
		}
		$html = '';
		foreach ($days as $day) {
			$rows = [];
			foreach ($items[$day] ?? [] as $item) {
				$rows[] = [$item->getStartTime(), $this->itemRow($item, $labels, $titles, $games, $materials)];
			}
			foreach ($meals[$day] ?? [] as $meal) {
				$rows[] = [$meal->getMealTime(), $this->mealRow($meal)];
			}
			usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
			$html .= '<section class="day"><h2>' . self::e($this->day($day)) . '</h2><table class="program">' . implode('', array_column($rows, 1)) . '</table></section>';
		}
		return $html;
	}

	/**
	 * @param array<string, string> $labels
	 * @param array<int, string> $titles
	 * @param array<int, list<string>> $games
	 * @param array<int, list<string>> $materials
	 */
	private function itemRow(ProgramItem $item, array $labels, array $titles, array $games, array $materials): string {
		$id = (int)$item->getId();
		$start = Timeline::minutes($item->getStartTime());
		$end = sprintf('%02d:%02d', intdiv(($start + (int)$item->getDurationMinutes()) % 1440, 60), ($start + (int)$item->getDurationMinutes()) % 60);
		$groups = 'für ' . ($item->groupList() === [] ? 'alle' : implode(', ', array_map(static fn (string $k): string => $labels[$k] ?? 'entfernte Gruppe', $item->groupList())));
		$notes = [];
		if ($item->getAlternativeTo() !== null) {
			$notes[] = '<div class="note">Alternative zu „' . self::e($titles[(int)$item->getAlternativeTo()] ?? '') . '“</div>';
		}
		if ($item->getDescription()) {
			$notes[] = '<div class="desc">' . nl2br(self::e((string)$item->getDescription())) . '</div>';
		}
		if ($item->getWeatherAlternative()) {
			$notes[] = '<div class="weather">Bei schlechtem Wetter: ' . self::e((string)$item->getWeatherAlternative()) . '</div>';
		}
		if (isset($games[$id])) {
			$notes[] = '<div class="extra">Spiel: ' . self::e(implode(', ', $games[$id])) . '</div>';
		}
		if (isset($materials[$id])) {
			$notes[] = '<div class="extra">Material: ' . self::e(implode(', ', $materials[$id])) . '</div>';
		}
		$proposal = $item->provenance()->isAutoChangeable() ? ' <span class="muted">(Vorschlag)</span>' : '';
		return '<tr><td class="time">' . self::e($item->getStartTime() . '–' . $end) . '</td><td><strong>' . self::e($item->getTitle()) . '</strong>'
			. $proposal . ' <span class="muted">' . self::e($groups) . '</span>' . implode('', $notes) . '</td></tr>';
	}

	private function mealRow(Meal $meal): string {
		$label = self::MEALS[$meal->getMealType()] ?? $meal->getMealType();
		$note = $meal->getCancelled() ? ' (entfällt)' : ($meal->getPortable() ? ' (unterwegs)' : (!$meal->getSelfCatered() ? ' (nicht selbst gekocht)' : ''));
		return '<tr class="meal"><td class="time">' . self::e($meal->getMealTime()) . '</td><td>' . self::e($label . $note) . '</td></tr>';
	}

	/** Spielanleitungen mit Urheber- und Lizenzhinweis (Konzept 7.3, 19.1). */
	private function games(Camp $camp, string $uid): string {
		$campId = (int)$camp->getId();
		$items = [];
		foreach ($this->planning->program($campId, $uid) as $item) {
			$items[(int)$item->getId()] = $item;
		}
		$links = array_values(array_filter($this->programGames->findByCamp($campId), static fn (ProgramGame $g): bool => isset($items[(int)$g->getProgramItemId()])));
		if ($links === []) {
			return '<p>Es sind noch keine Spiele eingeplant.</p>';
		}
		usort($links, static fn (ProgramGame $a, ProgramGame $b): int => [$items[(int)$a->getProgramItemId()]->getDay(), $items[(int)$a->getProgramItemId()]->getStartTime()]
			<=> [$items[(int)$b->getProgramItemId()]->getDay(), $items[(int)$b->getProgramItemId()]->getStartTime()]);
		// Volle Beschreibung aus dem lokalen Katalog, sofern vorhanden; sonst die Kurzfassung der Verknüpfung.
		$catalog = [];
		foreach (array_unique(array_map(static fn (ProgramGame $g): string => $g->getSource(), $links)) as $source) {
			foreach ($this->catalogGames->findBySource($source) as $game) {
				$catalog[$source . '|' . $game->getSourceId()] = $game;
			}
		}
		$html = '';
		foreach ($links as $link) {
			$item = $items[(int)$link->getProgramItemId()];
			$game = $catalog[$link->getSource() . '|' . $link->getSourceId()] ?? null;
			$materials = json_decode((string)$link->getMaterialsJson(), true);
			$html .= '<section class="game"><h2>' . self::e($link->getTitle()) . '</h2>'
				. '<p class="muted">' . self::e($this->day($item->getDay()) . ', ' . $item->getStartTime() . ': ' . $item->getTitle())
				. ($link->getDurationMinutes() ? self::e(', etwa ' . $link->getDurationMinutes() . ' Minuten') : '') . '</p>'
				// Die Beschreibung wurde beim Abgleich auf eine feste Auswahl von Tags bereinigt (HtmlSanitizer).
				. ($game?->getDescription() ? '<div class="desc">' . $game->getDescription() . '</div>' : '<p>' . self::e((string)$link->getSummary()) . '</p>')
				. (is_array($materials) && $materials !== [] ? '<p>Material: ' . self::e(implode(', ', array_filter($materials, 'is_string'))) . '</p>' : '')
				. '<p class="license">' . self::e($link->getAttribution()) . ', <a href="' . self::e($link->getLink()) . '">' . self::e($link->getLink()) . '</a>. Lizenz: '
				. '<a href="' . self::e($link->getLicenseUrl()) . '">' . self::e($link->getLicenseName()) . '</a></p></section>';
		}
		return $html;
	}

	private function tasks(Camp $camp, string $uid): string {
		$campId = (int)$camp->getId();
		$names = array_column($this->camps->people($campId, $uid), 'displayName', 'uid');
		$titles = [];
		foreach ($this->planning->program($campId, $uid) as $item) {
			$titles[(int)$item->getId()] = $item->getTitle();
		}
		$tasks = $this->planning->tasks($campId, $uid);
		usort($tasks, static fn (Task $a, Task $b): int => [$a->getStatus() !== 'open', $a->getDueDate() ?? '9999'] <=> [$b->getStatus() !== 'open', $b->getDueDate() ?? '9999']);
		if ($tasks === []) {
			return '<p>Keine Aufgaben.</p>';
		}
		$rows = array_map(fn (Task $task): string => '<tr><td class="box">' . ($task->getStatus() === 'done' ? '&#9745;' : '&#9744;') . '</td><td><strong>'
			. self::e($task->getTitle()) . '</strong>' . ($task->getMandatory() ? ' <span class="muted">(Pflicht)</span>' : '')
			. ($task->getProgramItemId() !== null && isset($titles[(int)$task->getProgramItemId()]) ? '<div class="muted">für ' . self::e($titles[(int)$task->getProgramItemId()]) . '</div>' : '')
			. '</td><td>' . self::e(implode(', ', array_map(static fn (string $u): string => $names[$u] ?? $u, $task->assigneeList())) ?: '–') . '</td><td>'
			. self::e($task->getDueDate() ? $this->shortDay($task->getDueDate()) : '–') . '</td><td>'
			. self::e(trim(self::STATUS[$task->getStatus()] . ' ' . self::PRIORITY[$task->getPriority()] ?? '')) . '</td></tr>', $tasks);
		return '<table class="list"><thead><tr><th></th><th>Aufgabe</th><th>Verantwortlich</th><th>Frist</th><th>Stand</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';
	}

	/** Speiseplan mit Menüvarianten und Bedarfskombinationen, ohne Namen (Konzept 19.3). */
	private function meals(Camp $camp, string $uid): string {
		$campId = (int)$camp->getId();
		$overview = $this->catering->overview($campId, $uid);
		$recipeTitles = array_column($this->recipes->list($campId, $uid), 'title', 'id');
		$html = '<p class="notice">Nur für die Küche: enthält zusammengefasste Ernährungsangaben, keine Namen.</p>';
		$needs = $this->catering->mealPlan((int)($overview['meals'][0]['id'] ?? 0), $uid)['needs'] ?? null;
		if ($needs !== null && $needs['combinations'] !== []) {
			$html .= '<h2>Bedarfskombinationen</h2><table class="list"><tbody>' . implode('', array_map(static fn (array $c): string => '<tr><td>' . self::e($c['label']) . '</td><td class="qty">' . (int)$c['count'] . '</td></tr>', $needs['combinations'])) . '</tbody></table>';
			$severe = array_filter($needs['severe'], static fn (array $s): bool => $s['count'] > 0);
			if ($severe !== []) {
				$html .= '<p class="notice">Schwere Allergien gemeldet: ' . self::e(implode(', ', array_map(static fn (array $s): string => $s['label'] . ' (' . $s['count'] . ')', $severe))) . '. Allergieverfahren beachten.</p>';
			}
		}
		$byDay = [];
		foreach ($overview['meals'] as $meal) {
			$byDay[$meal['day']][] = $meal;
		}
		ksort($byDay);
		foreach ($byDay as $day => $meals) {
			usort($meals, static fn (array $a, array $b): int => strcmp((string)$a['time'], (string)$b['time']));
			$rows = '';
			foreach ($meals as $meal) {
				$label = self::MEALS[$meal['mealType']] ?? $meal['mealType'];
				$detail = '';
				if ($meal['cancelled'] ?? false) {
					$detail = 'entfällt';
				} elseif (!$meal['selfCatered']) {
					$detail = 'nicht selbst gekocht';
				} elseif ($meal['hasVariantPlan'] ?? false) {
					$plan = $this->catering->mealPlan((int)$meal['id'], $uid);
					$detail = implode('<br>', array_map(static fn (array $v): string => self::e($v['label'] . ': ' . ($plan['evaluation']['headcounts'][$v['key']] ?? 0) . ' Personen, '
						. ($v['recipeId'] !== null ? ($recipeTitles[$v['recipeId']] ?? 'Rezept fehlt') : 'noch kein Rezept')), $plan['plan']['variants']));
				} else {
					$detail = 'noch keine Menüvarianten';
				}
				$rows .= '<tr><td class="time">' . self::e((string)$meal['time']) . '</td><td><strong>' . self::e($label) . '</strong><div>' . ($detail === '' ? '' : (str_contains($detail, '<br>') || $meal['hasVariantPlan'] ? $detail : self::e($detail))) . '</div></td></tr>';
			}
			$html .= '<section class="day"><h2>' . self::e($this->day($day)) . '</h2><table class="program">' . $rows . '</table></section>';
		}
		return $html;
	}

	/** Rezepte mit Mengen für die Personenzahl des Lagers. */
	private function recipeBook(Camp $camp, string $uid): string {
		$campId = (int)$camp->getId();
		$this->camps->require($campId, $uid, Permission::ViewCatering);
		$portions = 0.0;
		foreach ($this->camps->groups($campId) as $group) {
			$portions += (int)$group->getEstimatedCount() * (float)$group->getPortionFactor();
		}
		$portions = max(1.0, round($portions, 1));
		$list = $this->recipes->list($campId, $uid);
		if ($list === []) {
			return '<p>Es sind noch keine Rezepte angelegt.</p>';
		}
		$html = '<p class="muted">Mengen für etwa ' . self::e(self::number($portions)) . ' Standardportionen (alle Personen mit ihrem Portionsfaktor). Für einzelne Varianten gelten die Mengen aus der Küchenplanung.</p>';
		foreach ($list as $summary) {
			$detail = $this->recipes->get((int)$summary['id'], $uid, $portions);
			$recipe = Recipe::fromArray($detail['recipe']);
			$rows = implode('', array_map(static fn (array $line): string => '<tr><td class="qty">' . self::e($line['quantity'] === null ? '' : self::number($line['quantity']) . ' ' . $line['unit'])
				. '</td><td>' . self::e($line['name'] . ($line['quantity'] === null ? ' (' . $line['unit'] . ')' : '') . ($line['note'] !== '' ? ', ' . $line['note'] : '')) . '</td></tr>', (new RecipeScaler())->scale($recipe, $portions)));
			$allergens = $recipe->contains === [] ? 'keine angegeben' : implode(', ', array_map(static fn (Allergen $a): string => $a->label(), $recipe->contains));
			$html .= '<section class="recipe"><h2>' . self::e($recipe->title) . '</h2>'
				. '<p class="muted">Allergene: ' . self::e($allergens) . ($detail['allergensChecked'] ? ' (geprüft)' : ' – Angaben nicht geprüft') . '</p>'
				. '<table class="list">' . $rows . '</table><ol>' . implode('', array_map(static fn (string $s): string => '<li>' . self::e($s) . '</li>', $recipe->steps)) . '</ol></section>';
		}
		return $html;
	}

	private function shell(Camp $camp, string $title, string $body): string {
		$period = $this->shortDay($camp->getStartDate()) . ' bis ' . $this->shortDay($camp->getEndDate());
		$location = $camp->getLocationKnown() && $camp->getLocationName() ? ', ' . $camp->getLocationName() : '';
		$created = (new DateTimeImmutable('@' . $this->time->getTime()))->format('d.m.Y');
		return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . self::e($title . ' – ' . $camp->getName()) . '</title><style>'
			. '@page { size: A4; margin: 1.5cm; }'
			. 'body { font: 10.5pt/1.35 system-ui, sans-serif; color: #000; margin: 0; }'
			. 'header { border-bottom: 1.5pt solid #000; margin-bottom: 0.4cm; } header h1 { font-size: 17pt; margin: 0; } header p { margin: 2pt 0 4pt; color: #444; }'
			. 'h2 { font-size: 12.5pt; margin: 0.45cm 0 0.15cm; break-after: avoid; }'
			. 'table { width: 100%; border-collapse: collapse; } td, th { padding: 3pt 4pt; border-bottom: 0.5pt solid #bbb; vertical-align: top; text-align: left; }'
			. 'td.time { width: 6.5em; white-space: nowrap; font-variant-numeric: tabular-nums; } td.qty { width: 7em; text-align: right; white-space: nowrap; } td.box { width: 1.2em; font-size: 12pt; }'
			. 'tr { break-inside: avoid; } tr.meal td { color: #444; font-style: italic; }'
			. '.muted { color: #555; font-weight: normal; } .desc { margin-top: 2pt; } .weather, .extra, .note { margin-top: 2pt; font-size: 9.5pt; color: #333; }'
			. '.notice { border: 1pt solid #000; padding: 4pt 6pt; } .license { font-size: 9pt; color: #333; }'
			. 'section.game, section.recipe { break-inside: avoid-page; border-bottom: 0.5pt solid #888; padding-bottom: 0.2cm; }'
			. 'footer { margin-top: 0.5cm; font-size: 8.5pt; color: #666; } a { color: #000; }'
			. '</style></head><body><header><h1>' . self::e($title) . '</h1><p>' . self::e($camp->getName() . ', ' . $period . $location) . '</p></header>'
			. $body . '<footer>Erstellt am ' . self::e($created) . ' mit dem Lagerplaner. Stand der Planung zu diesem Zeitpunkt.</footer></body></html>';
	}

	private function dayParam(Camp $camp, string $day): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $day < $camp->getStartDate() || $day > $camp->getEndDate()) {
			throw new \InvalidArgumentException('Der Tag liegt nicht im Lagerzeitraum.');
		}
		return $day;
	}

	private function day(string $day): string {
		$date = new DateTimeImmutable($day);
		return self::WEEKDAYS[(int)$date->format('w')] . ', ' . (int)$date->format('j') . '. ' . self::MONTHS[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
	}

	private function shortDay(string $day): string {
		return (new DateTimeImmutable($day))->format('d.m.Y');
	}

	private static function number(float $value): string {
		return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
	}

	private static function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}

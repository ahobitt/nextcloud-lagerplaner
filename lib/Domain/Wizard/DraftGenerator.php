<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

use OCA\Lagerplaner\Domain\Templates\DayTemplate;

use DateTimeImmutable;

/**
 * Regelbasierter Lagerentwurf ohne KI (Konzept 3.8, 3.9).
 *
 * Die Regeln sind bewusst einfach und nachvollziehbar gehalten. Jede Regel hat
 * eine Kennung (Slot), die zusammen mit der Vorlagenversion als templateRef
 * am erzeugten Element gespeichert wird.
 */
final class DraftGenerator {
	public const TEMPLATE_VERSION = 'core-de/2026.1';

	private const FREE_MINUTES = ['little' => 60, 'medium' => 90, 'much' => 120];
	private const DEFAULT_BLOCK_MINUTES = 120;

	/** Aufgaben: Schlüssel => [Titel, Beschreibung, Tage vor Lagerbeginn] */
	private const TASKS = [
		'lagerplatz_suchen' => ['Lagerplatz suchen', 'Die Lagerplatzsuche ist mit Zeitraum, Personenzahl, Unterkunftsart und Region vorausgefüllt.', 210],
		'lagerplatz_buchen' => ['Lagerplatz anfragen und verbindlich buchen', 'Beim Buchen Kapazität, Preis, Küche und Sanitäranlagen vom Betreiber bestätigen lassen; die Angaben im Verzeichnis können veraltet sein. Den Stand der Anfrage in der Merkliste festhalten.', 180],
		'anmeldung' => ['Anmeldung vorbereiten', 'Ernährungsweise, Unverträglichkeiten und schwere Allergien standardisiert abfragen.', 120],
		'kuechenteam' => ['Küchenteam festlegen', '', 90],
		'materialverantwortung' => ['Materialverantwortung festlegen', '', 90],
		'transport' => ['Transport organisieren', '', 60],
		'programm' => ['Programm ausarbeiten und Verantwortliche eintragen', 'Vorschläge des Assistenten bestätigen, ändern oder ersetzen.', 30],
		'schlechtwetter' => ['Schlechtwetteralternativen festlegen', '', 30],
		'material' => ['Material sichten und Packliste erstellen', '', 21],
		'einkauf' => ['Einkaufsplanung abschließen', '', 7],
	];

	/** Eigene Tagesvorlage für normale Lagertage (Konzept 6.4); null: Regeln des Lagerplaners */
	private ?DayTemplate $dayTemplate = null;
	/** Im Gruppenhaus gibt es bei schlechtem Wetter einen Raum; Alternativen braucht es nicht. */
	private bool $house = false;

	public function generate(WizardInput $input, DateTimeImmutable $today, ?DayTemplate $dayTemplate = null): Draft {
		$this->dayTemplate = $dayTemplate;
		$this->house = $input->campType === 'house';
		$days = $this->days($input);
		$program = [];
		$meals = [];
		$hints = [];

		$count = count($days);
		$middle = $count >= 3 ? array_slice($days, 1, $count - 2) : [];
		$hikeDay = $this->pickHikeDay($input, $middle, $hints);
		$gameDay = $this->pickGameDay($input, $middle, $hikeDay, $hints);
		$freeDay = $this->pickFreeAfternoon($input, $middle, [$hikeDay, $gameDay]);

		if ($count === 1) {
			$this->singleDay($input, $days[0], $program, $meals);
		} else {
			$this->arrivalDay($input, $days[0], $program, $meals, $hints);
			foreach ($middle as $day) {
				$this->middleDay($input, $day, $day === $hikeDay, $day === $gameDay, $day === $freeDay, $program, $meals);
			}
			$this->departureDay($input, $days[$count - 1], $program, $meals, $hints);
		}

		$tasks = $this->tasks($input, $today);
		$this->generalHints($input, $program, $hints);
		$participants = array_sum(array_map(static fn (GroupInput $g): int => $g->count, $input->participantGroups()));
		$coverage = LeaderCoverage::hint($participants, $input->leaderCount());
		if ($coverage !== null) {
			array_unshift($hints, $coverage['text']);
		}

		// Sicherheitsnetz: Bei gültigen Eingaben entstehen keine Überschneidungen. Sollte es doch
		// welche geben, stehen sie als Hinweis im Entwurf, statt stillschweigend gespeichert zu werden.
		$groupKeys = array_map(static fn (GroupInput $g): string => $g->key, array_values(array_filter($input->participantGroups(), static fn (GroupInput $g): bool => $g->separateProgram)));
		foreach ((new Timeline())->conflicts($program, $meals, $groupKeys) as $conflict) {
			$hints[] = 'Zeitliche Überschneidung: ' . $conflict;
		}

		return new Draft(self::TEMPLATE_VERSION, $input, $days, $program, $meals, $tasks, $hints);
	}

	/** @return list<string> */
	private function days(WizardInput $input): array {
		$days = [];
		for ($d = $input->start; $d <= $input->end; $d = $d->modify('+1 day')) {
			$days[] = $d->format('Y-m-d');
		}
		return $days;
	}

	/**
	 * @param list<string> $middle
	 * @param list<string> $hints
	 */
	private function pickHikeDay(WizardInput $input, array $middle, array &$hints): ?string {
		if (!$input->hike) {
			return null;
		}
		if ($middle === []) {
			$hints[] = 'Für eine Tageswanderung ist das Lager zu kurz. Plane sie bei Bedarf manuell ein.';
			return null;
		}
		return $middle[intdiv(count($middle), 3)];
	}

	/**
	 * @param list<string> $middle
	 * @param list<string> $hints
	 */
	private function pickGameDay(WizardInput $input, array $middle, ?string $hikeDay, array &$hints): ?string {
		if (!$input->wideGame) {
			return null;
		}
		$candidates = array_values(array_filter($middle, static fn (string $d): bool => $d !== $hikeDay));
		if ($candidates === []) {
			$hints[] = 'Für das Geländespiel ist kein freier Lagertag übrig. Plane es bei Bedarf manuell ein.';
			return null;
		}
		return $candidates[min(count($candidates) - 1, intdiv(2 * count($candidates), 3))];
	}

	/**
	 * @param list<string> $middle
	 * @param list<?string> $taken
	 */
	private function pickFreeAfternoon(WizardInput $input, array $middle, array $taken): ?string {
		if ($input->freeTime !== 'much' || count($middle) < 4) {
			return null;
		}
		$candidates = array_values(array_filter($middle, static fn (string $d): bool => !in_array($d, $taken, true)));
		return $candidates === [] ? null : $candidates[intdiv(count($candidates), 2)];
	}

	/**
	 * Erster Tag ab der Ankunft. Ohne Uhrzeit wie bisher: Ankunft 10 Uhr, Mittagessen,
	 * Kennenlernen, Abendessen, Lagereröffnung, Abendrunde.
	 *
	 * Mit Uhrzeit gilt: Mahlzeiten gibt es erst ab 45 Minuten nach der Ankunft; der Aufbau
	 * endet spätestens mit der nächsten Mahlzeit; abends (ab 16 Uhr) ist er auf 90 Minuten
	 * gekürzt. Passt das Kennenlernen nicht mehr in den Nachmittag, geht es in der
	 * Lagereröffnung auf. Das Abendessen rückt nach hinten, wenn der Aufbau länger dauert,
	 * und entfällt, wenn es erst nach 20 Uhr möglich wäre.
	 *
	 * @param list<ProposedProgramItem> $program
	 * @param list<ProposedMeal> $meals
	 * @param list<string> $hints
	 */
	private function arrivalDay(WizardInput $input, string $day, array &$program, array &$meals, array &$hints): void {
		$tent = $input->campType === 'tent';
		$arrival = Timeline::minutes($input->arrivalTime ?? '10:00');
		$setup = $tent ? 150 : 90;
		if ($arrival >= Timeline::minutes('16:00')) {
			$setup = min($setup, 90);
		}
		$setupEnd = $arrival + $setup;
		$has = static fn (string $type): bool => in_array($type, $input->meals, true);

		$lunch = Timeline::minutes('12:30');
		$withLunch = $has('lunch') && $lunch >= $arrival + 45;
		if ($withLunch) {
			$setupEnd = min($setupEnd, $lunch);
		}
		$program[] = $this->item('arrival', $day, self::time($arrival), $setupEnd - $arrival,
			$tent ? 'Anreise und Zeltaufbau' : 'Anreise und Zimmerbezug', '', [], 'organisation', false);
		$cursor = $setupEnd;
		if ($withLunch) {
			$this->meal($input, $day, 'lunch', '12:30', $meals);
			$cursor = max($cursor, $lunch + Timeline::MEAL_MINUTES['lunch']);
		}

		$snack = Timeline::minutes('16:00');
		$dinner = Timeline::minutes('18:30');
		$latestStart = Timeline::minutes('16:30');
		$knowing = false;
		// Kennenlernen am frühen Nachmittag, wenn es vor die Zwischenmahlzeit passt
		$start = max(Timeline::minutes('14:00'), self::quarter($cursor));
		$limit = $has('snack') ? $snack : $dinner;
		if ($start <= $latestStart && $start + 90 <= $limit) {
			$program[] = $this->item('getting_to_know', $day, self::time($start), 90, 'Kennenlernen und Lagerregeln', '', [], 'program', true);
			$cursor = $start + 90;
			$knowing = true;
		}
		$snackAt = max($snack, self::quarter($cursor));
		if ($has('snack') && $snackAt + Timeline::MEAL_MINUTES['snack'] + 30 <= $dinner) {
			$this->meal($input, $day, 'snack', self::time($snackAt), $meals);
			$cursor = $snackAt + Timeline::MEAL_MINUTES['snack'];
		}
		if (!$knowing) {
			$start = self::quarter($cursor);
			if ($start <= $latestStart && $start + 90 <= $dinner) {
				$program[] = $this->item('getting_to_know', $day, self::time($start), 90, 'Kennenlernen und Lagerregeln', '', [], 'program', true);
				$cursor = $start + 90;
				$knowing = true;
			}
		}

		$dinnerAt = max($dinner, self::quarter($cursor));
		if ($has('dinner') && $dinnerAt <= Timeline::minutes('20:00')) {
			$this->meal($input, $day, 'dinner', self::time($dinnerAt), $meals);
			$cursor = $dinnerAt + Timeline::MEAL_MINUTES['dinner'];
		} elseif ($has('dinner')) {
			$hints[] = sprintf('Bei Ankunft um %s ist am ersten Tag kein Abendessen eingeplant, weil Ankunft und Aufbau bis %s dauern. Bitte vorher essen oder etwas mitbringen.',
				self::time($arrival), self::time($cursor));
		}

		$openingAt = max(Timeline::minutes('19:30'), self::quarter($cursor));
		$openingMinutes = $openingAt >= Timeline::minutes('20:30') ? 45 : 60;
		$title = $input->theme === '' ? 'Lagereröffnung' : 'Lagereröffnung: Einstieg ins Thema „' . $input->theme . '“';
		if (!$knowing) {
			$title = $input->theme === '' ? 'Lagereröffnung und Kennenlernen' : 'Lagereröffnung und Kennenlernen: Einstieg ins Thema „' . $input->theme . '“';
		}
		$program[] = $this->item('opening', $day, self::time($openingAt), $openingMinutes, $title, '', [], 'evening', $tent);
		$roundAt = max(Timeline::minutes('21:00'), self::quarter($openingAt + $openingMinutes));
		$program[] = $this->item('evening_round', $day, self::time($roundAt), 15, 'Abendrunde', '', [], 'ritual', false);
	}

	/**
	 * @param list<ProposedProgramItem> $program
	 * @param list<ProposedMeal> $meals
	 */
	private function middleDay(
		WizardInput $input,
		string $day,
		bool $hike,
		bool $wideGame,
		bool $freeAfternoon,
		array &$program,
		array &$meals,
	): void {
		$tent = $input->campType === 'tent';
		$free = self::FREE_MINUTES[$input->freeTime];
		$afternoon = $this->addMinutes('13:00', $free);

		// Normale Lagertage nach eigener Tagesvorlage; Wanderung, Geländespiel und freier
		// Nachmittag bleiben eigene Tage nach den Regeln des Lagerplaners.
		if ($this->dayTemplate !== null && !$hike && !$wideGame && !$freeAfternoon) {
			$this->meal($input, $day, 'breakfast', '08:00', $meals);
			$this->meal($input, $day, 'lunch', '12:30', $meals);
			$this->meal($input, $day, 'dinner', '18:30', $meals);
			$this->templateDay($input, $day, $this->dayTemplate, $program);
			return;
		}

		$this->meal($input, $day, 'breakfast', '08:00', $meals);
		$program[] = $this->item('morning_round', $day, '08:45', 15, 'Morgenrunde', '', [], 'ritual', false);

		if ($hike) {
			$program[] = $this->item('hike', $day, '09:30', 420, 'Tageswanderung',
				'Strecke und Länge an die jüngste beteiligte Altersstufe anpassen.', [], 'hike', true);
			$this->meal($input, $day, 'lunch', '12:30', $meals, 'Lunchpaket für unterwegs', true);
			$this->meal($input, $day, 'snack', '15:30', $meals, 'Unterwegs', true);
		} else {
			$this->blocks($input, 'morning', $day, '09:30', 'Vormittagsprogramm', $program);
			$this->meal($input, $day, 'lunch', '12:30', $meals);
			$program[] = $this->item('rest', $day, '13:00', $free, 'Mittagsruhe und freie Zeit', '', [], 'free', false);
			// Der Snack bildet eine kurze Pause vor dem Nachmittag, damit sich nichts überschneidet.
			$programStart = $this->snackBreak($input, $day, $afternoon, $meals);
			if ($wideGame) {
				$program[] = $this->item('wide_game', $day, $programStart, 180, 'Geländespiel',
					$input->theme === '' ? '' : 'Passend zum Lagerthema „' . $input->theme . '“.', [], 'wide_game', true);
			} elseif ($freeAfternoon) {
				$program[] = $this->item('free_afternoon', $day, $programStart, 180, 'Freier Nachmittag', '', [], 'free', false);
			} else {
				$this->blocks($input, 'afternoon', $day, $programStart, 'Nachmittagsprogramm', $program);
			}
		}

		$this->meal($input, $day, 'dinner', '18:30', $meals);
		$program[] = $this->item('evening', $day, '19:30', 75, 'Abendprogramm', '', [], 'evening', $tent);
		$program[] = $this->item('evening_round', $day, '21:00', 15, 'Abendrunde', '', [], 'ritual', false);
	}

	/**
	 * Letzter Tag bis zur Abreise. Ohne Uhrzeit wie bisher: Abbau ab 9:15, Mittagessen,
	 * Abschlussrunde, Heimreise um 14 Uhr.
	 *
	 * Mit Uhrzeit: Die Abschlussrunde liegt 45 Minuten vor der Abreise; Mittagessen gibt es,
	 * wenn danach noch Zeit bis zur Abschlussrunde bleibt; der Abbau endet vor dem
	 * Mittagessen oder der Abschlussrunde. Bei später Abreise kommt nachmittags ein
	 * Abschlussprogramm dazu; reicht die Zeit nicht für den vollen Abbau, entfällt die
 * Morgenrunde zugunsten des Abbaus.
	 *
	 * @param list<ProposedProgramItem> $program
	 * @param list<ProposedMeal> $meals
	 * @param list<string> $hints
	 */
	private function departureDay(WizardInput $input, string $day, array &$program, array &$meals, array &$hints): void {
		$tent = $input->campType === 'tent';
		$departure = Timeline::minutes($input->departureTime ?? '14:00');
		$closing = $departure - 45;
		$lunch = Timeline::minutes('12:30');
		$withLunch = in_array('lunch', $input->meals, true) && $lunch + Timeline::MEAL_MINUTES['lunch'] <= $closing;
		$wanted = $tent ? 165 : 135;

		$this->meal($input, $day, 'breakfast', '08:00', $meals);
		$teardownAt = Timeline::minutes('09:15');
		$limit = $withLunch ? $lunch : $closing;
		if ($limit - $teardownAt < $wanted) {
			// Knappe Zeit bis zur Abreise: Abbau direkt nach dem Frühstück, ohne Morgenrunde.
			$teardownAt = Timeline::minutes('08:45');
		} else {
			$program[] = $this->item('morning_round', $day, '08:45', 15, 'Morgenrunde', '', [], 'ritual', false);
		}
		$teardown = min($wanted, $limit - $teardownAt);
		$program[] = $this->item('teardown', $day, self::time($teardownAt), $teardown,
			$tent ? 'Zeltabbau und Platzabnahme' : 'Aufräumen und Hausabnahme', '', [], 'organisation', false);
		if ($teardown < $wanted) {
			$hints[] = sprintf('Für %s bleiben am letzten Tag nur %d Minuten; plant einen Teil schon am Vorabend ein.',
				$tent ? 'den Zeltabbau' : 'Aufräumen und Hausabnahme', $teardown);
		}
		if ($withLunch) {
			$this->meal($input, $day, 'lunch', '12:30', $meals);
			$afternoon = Timeline::minutes('14:00');
			$farewellEnd = $closing - 15;
			if ($farewellEnd - $afternoon >= 60) {
				$program[] = $this->item('farewell', $day, '14:00', $farewellEnd - $afternoon, 'Abschlussprogramm', '', [], 'program', true);
			}
		}
		$program[] = $this->item('closing', $day, self::time($closing), 30, 'Abschlussrunde', '', [], 'ritual', false);
		$program[] = $this->item('departure', $day, self::time($departure), 60, 'Heimreise', '', [], 'organisation', false);
	}

	private static function time(int $minutes): string {
		return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	/** Auf die nächste Viertelstunde aufrunden */
	private static function quarter(int $minutes): int {
		return (int)(ceil($minutes / 15) * 15);
	}

	/**
	 * @param list<ProposedProgramItem> $program
	 * @param list<ProposedMeal> $meals
	 */
	private function singleDay(WizardInput $input, string $day, array &$program, array &$meals): void {
		if ($input->arrivalTime !== null || $input->departureTime !== null) {
			$this->timedSingleDay($input, $day, $program, $meals);
			return;
		}
		$program[] = $this->item('arrival', $day, '09:30', 30, 'Ankommen', '', [], 'organisation', false);
		$this->blocks($input, 'morning', $day, '10:00', 'Vormittagsprogramm', $program);
		$this->meal($input, $day, 'lunch', '12:30', $meals);
		$programStart = $this->snackBreak($input, $day, '14:00', $meals);
		$this->blocks($input, 'afternoon', $day, $programStart, 'Nachmittagsprogramm', $program);

		// Abschluss erst, wenn der längste Nachmittagsblock vorbei ist, auf Viertelstunden gerundet.
		$latest = Timeline::minutes('16:30');
		foreach ($program as $item) {
			if ($item->day === $day && $item->slot === 'afternoon') {
				$latest = max($latest, Timeline::minutes($item->startTime) + $item->durationMinutes);
			}
		}
		$closing = $this->addMinutes('00:00', (int)(ceil($latest / 15) * 15));
		$program[] = $this->item('closing', $day, $closing, 30, 'Abschlussrunde', '', [], 'ritual', false);
		$program[] = $this->item('departure', $day, $this->addMinutes($closing, 30), 30, 'Heimreise', '', [], 'organisation', false);
	}

	/**
	 * Tagesveranstaltung mit Beginn und Ende (ohne Angabe 9:30 und 17:30): Ankommen,
	 * Programm vor und nach dem Mittagessen, Abschlussrunde 45 Minuten vor dem Ende und
	 * Heimreise. Mahlzeiten nur, wenn sie in die Zeit passen; Programmblöcke enden vor der
	 * nächsten Mahlzeit beziehungsweise vor der Abschlussrunde.
	 *
	 * @param list<ProposedProgramItem> $program
	 * @param list<ProposedMeal> $meals
	 */
	private function timedSingleDay(WizardInput $input, string $day, array &$program, array &$meals): void {
		$start = Timeline::minutes($input->arrivalTime ?? '09:30');
		$end = Timeline::minutes($input->departureTime ?? '17:30');
		$closing = $end - 45;
		$lunch = Timeline::minutes('12:30');
		$has = static fn (string $type): bool => in_array($type, $input->meals, true);

		$program[] = $this->item('arrival', $day, self::time($start), 30, 'Ankommen', '', [], 'organisation', false);
		$cursor = $start + 30;
		$withLunch = $has('lunch') && $lunch >= $cursor && $lunch + Timeline::MEAL_MINUTES['lunch'] + 30 <= $closing;
		if ($withLunch && $lunch - $cursor >= 45) {
			$this->blocks($input, 'morning', $day, self::time(self::quarter($cursor)), 'Vormittagsprogramm', $program, $lunch - self::quarter($cursor));
		}
		if ($withLunch) {
			$this->meal($input, $day, 'lunch', '12:30', $meals);
			$cursor = max($cursor, $lunch + Timeline::MEAL_MINUTES['lunch'] + 30);
		}
		$snack = Timeline::minutes('15:30');
		if ($has('snack') && $snack >= $cursor + 60 && $snack + Timeline::MEAL_MINUTES['snack'] + 30 <= $closing) {
			// Programm bis zur Zwischenmahlzeit, danach weiter bis zur Abschlussrunde
			$this->blocks($input, 'afternoon', $day, self::time(self::quarter($cursor)), $withLunch ? 'Nachmittagsprogramm' : 'Programm', $program, $snack - self::quarter($cursor));
			$this->meal($input, $day, 'snack', '15:30', $meals);
			$cursor = $snack + Timeline::MEAL_MINUTES['snack'];
			$title = 'Programm nach der Pause';
		} else {
			$title = $withLunch ? 'Nachmittagsprogramm' : 'Programm';
		}
		$from = self::quarter($cursor);
		if ($closing - 15 - $from >= 45) {
			$this->blocks($input, 'afternoon', $day, self::time($from), $title, $program, $closing - 15 - $from);
		}
		$program[] = $this->item('closing', $day, self::time($closing), 30, 'Abschlussrunde', '', [], 'ritual', false);
		$program[] = $this->item('departure', $day, self::time($end), 30, 'Heimreise', '', [], 'organisation', false);
	}

	/**
	 * Programmblöcke: eigene Blöcke für Gruppen mit eigenem Programm, ein gemeinsamer Block für den Rest.
	 *
	 * @param list<ProposedProgramItem> $program
	 */
	private function blocks(WizardInput $input, string $slot, string $day, string $start, string $title, array &$program, ?int $maxMinutes = null): void {
		$participants = $input->participantGroups();
		$separate = array_values(array_filter($participants, static fn (GroupInput $g): bool => $g->separateProgram));
		$common = array_values(array_filter($participants, static fn (GroupInput $g): bool => !$g->separateProgram));
		$description = $input->theme === '' ? '' : 'Lagerthema: ' . $input->theme;

		foreach ($separate as $group) {
			$program[] = $this->item($slot, $day, $start, $maxMinutes === null ? $group->blockMinutes : min($group->blockMinutes, $maxMinutes),
				$title . ' (' . $group->label . ')', $description, [$group->key], 'program', true);
		}

		if ($common !== [] || $participants === []) {
			$minutes = $common === []
				? self::DEFAULT_BLOCK_MINUTES
				: min(array_map(static fn (GroupInput $g): int => $g->blockMinutes, $common));
			$groups = $separate === [] ? [] : array_map(static fn (GroupInput $g): string => $g->key, $common);
			$label = match (true) {
				$separate === [] => $title,
				count($common) === 1 => $title . ' (' . $common[0]->label . ')',
				default => $title . ' (gemeinsam)',
			};
			$program[] = $this->item($slot, $day, $start, $maxMinutes === null ? $minutes : min($minutes, $maxMinutes), $label, $description, $groups, 'program', true);
		}
	}

	/** @param list<string> $groups */
	private function item(
		string $slot,
		string $day,
		string $start,
		int $minutes,
		string $title,
		string $description,
		array $groups,
		string $category,
		bool $weather,
	): ProposedProgramItem {
		$weather = $weather && !$this->house;
		return new ProposedProgramItem($slot, $day, $start, $minutes, $title, $description, $groups, $category, $weather, self::TEMPLATE_VERSION,
			$weather ? self::weatherAlternative($category) : '');
	}

	/**
	 * Konkrete Vorschläge für schlechtes Wetter (Konzept 3.9), statt nur „fehlt“ zu melden.
	 * Sie sind Vorschläge: Die Lagerleitung passt sie an Ort und Gruppe an.
	 */
	public static function weatherAlternative(string $category): string {
		return match ($category) {
			'hike' => 'Kürzere Runde in der Nähe oder Dorf- und Stadtrallye; Lunchpakete trotzdem vorbereiten.',
			'wide_game' => 'Stationenspiel unter Planen oder im Haus, mit denselben Gruppen und Punkten.',
			'evening' => 'Spiele- oder Singeabend im Gemeinschaftszelt oder Haus.',
			default => 'Workshops unter Planen oder im Gemeinschaftszelt, etwa Basteln, Knoten oder Spiele für drinnen aus der Spielesuche.',
		};
	}

	/**
	 * Programmpunkte eines Tages aus der Tagesvorlage. Punkte nur für Altersstufen, die im
	 * Lager nicht vorkommen, entfallen; Punkte für alle bleiben.
	 *
	 * @param list<ProposedProgramItem> $program
	 */
	private function templateDay(WizardInput $input, string $day, DayTemplate $template, array &$program): void {
		$present = array_map(static fn (GroupInput $g): string => $g->key, $input->participantGroups());
		foreach ($template->items as $index => $item) {
			$groups = $item->groups === [] ? [] : array_values(array_intersect($item->groups, $present));
			if ($item->groups !== [] && $groups === []) {
				continue;
			}
			$program[] = new ProposedProgramItem(
				'tpl' . $index, $day, $item->startTime, $item->durationMinutes, $item->title, $item->description,
				$groups, $item->category, $item->weatherAlternative !== '', self::TEMPLATE_VERSION,
				$item->weatherAlternative, $template->ref($index),
			);
		}
	}

	/** @param list<ProposedMeal> $meals */
	private function meal(WizardInput $input, string $day, string $type, string $time, array &$meals, string $note = '', bool $portable = false): void {
		if (!in_array($type, $input->meals, true)) {
			return;
		}
		$meals[] = new ProposedMeal($day, $type, $time, $input->selfCatering, $note, self::TEMPLATE_VERSION, $portable);
	}

	/**
	 * Legt den Nachmittagssnack als Pause an den Beginn des Nachmittags.
	 *
	 * @param list<ProposedMeal> $meals
	 * @return string Beginn des Nachmittagsprogramms
	 */
	private function snackBreak(WizardInput $input, string $day, string $afternoon, array &$meals): string {
		if (!in_array('snack', $input->meals, true)) {
			return $afternoon;
		}
		$this->meal($input, $day, 'snack', $afternoon, $meals);
		return $this->addMinutes($afternoon, Timeline::MEAL_MINUTES['snack']);
	}

	/** @return list<ProposedTask> */
	private function tasks(WizardInput $input, DateTimeImmutable $today): array {
		$keys = [];
		if (!$input->locationKnown) {
			$keys[] = 'lagerplatz_suchen';
		}
		array_push($keys, 'lagerplatz_buchen', 'anmeldung');
		if ($input->selfCatering && $input->kitchenTeam !== 'yes') {
			$keys[] = 'kuechenteam';
		}
		if ($input->materialResponsible !== 'yes') {
			$keys[] = 'materialverantwortung';
		}
		array_push($keys, 'transport', 'programm');
		// Schlechtwetter ist eine Aufgabe, kein Hinweis; im Gruppenhaus entfällt sie.
		if ($input->campType !== 'house') {
			$keys[] = 'schlechtwetter';
		}
		$keys[] = 'material';
		if ($input->selfCatering) {
			$keys[] = 'einkauf';
		}

		$todayDate = $today->setTime(0, 0);
		$tasks = [];
		foreach ($keys as $key) {
			[$title, $description, $offset] = self::TASKS[$key];
			$due = $input->start->modify('-' . $offset . ' days');
			if ($due < $todayDate) {
				$due = $todayDate;
			}
			$tasks[] = new ProposedTask($key, $title, $description, $due->format('Y-m-d'), false, self::TEMPLATE_VERSION);
		}
		usort($tasks, static fn (ProposedTask $a, ProposedTask $b): int => $a->dueDate <=> $b->dueDate);
		return $tasks;
	}

	/**
	 * @param list<ProposedProgramItem> $program
	 * @param list<string> $hints
	 */
	private function generalHints(WizardInput $input, array $program, array &$hints): void {
		if (!$input->locationKnown) {
			$hints[] = 'Der Lagerort steht noch nicht fest. Die Aufgabe „Lagerplatz suchen“ ist angelegt.';
		}
		if ($input->totalCount() === 0) {
			$hints[] = 'Es sind noch keine Personenzahlen eingetragen. Mengen für Küche und Material fehlen deshalb.';
		} elseif ($input->leaderCount() === 0) {
			$hints[] = 'Die Anzahl der Leitenden fehlt noch.';
		}
		if ($input->meals === []) {
			$hints[] = 'Es sind keine Mahlzeiten ausgewählt.';
		}
	}

	private function addMinutes(string $time, int $minutes): string {
		[$h, $m] = array_map('intval', explode(':', $time));
		$total = $h * 60 + $m + $minutes;
		return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Wizard;

use DateTimeImmutable;

/**
 * Validierte Eingaben des Lagerassistenten (Konzept 3.2 bis 3.7).
 *
 * Pflicht sind nur Name, Beginn und Ende. Alles andere hat Standardwerte,
 * damit Schritte übersprungen werden können.
 */
final class WizardInput {
	public const MAX_DAYS = 31;
	public const CAMP_TYPES = ['tent', 'house'];
	public const MEAL_TYPES = ['breakfast', 'lunch', 'snack', 'dinner'];
	public const FREE_TIME = ['little', 'medium', 'much'];
	public const TRISTATE = ['yes', 'no', 'unknown'];

	/** Grenzen der Eingaben (Datenbankspalten und realistische Planungsgrößen). */
	public const MAX_NAME = 200;
	public const MAX_TEXT = 200;
	public const MAX_THEME = 120;
	public const MAX_GROUP_LABEL = 60;
	public const MAX_GROUPS = 12;
	public const MAX_GROUP_COUNT = 2000;
	public const MAX_TOTAL = 5000;
	public const MIN_BLOCK_MINUTES = 30;
	/**
	 * Ein Programmblock muss in die Tagesstruktur passen: vormittags von 9:30 Uhr bis zum
	 * Mittagessen um 12:30 Uhr sind es 180 Minuten. An einem eintägigen Lager beginnt der
	 * Vormittag um 10 Uhr, dort sind es 150 Minuten.
	 */
	public const MAX_BLOCK_MINUTES = 180;
	public const MAX_BLOCK_MINUTES_SINGLE_DAY = 150;

	/** Standardgruppen mit Beispiel-Portionsfaktoren (9.3) und altersgerechter Blockdauer (3.9). */
	public const DEFAULT_GROUPS = [
		['key' => 'woelflinge', 'label' => 'Wölflinge', 'portionFactor' => 0.75, 'blockMinutes' => 90],
		['key' => 'jungpfadfinder', 'label' => 'Jungpfadfinder', 'portionFactor' => 0.90, 'blockMinutes' => 120],
		['key' => 'pfadfinder', 'label' => 'Pfadfinder', 'portionFactor' => 1.00, 'blockMinutes' => 150],
		['key' => 'rover', 'label' => 'Rover', 'portionFactor' => 1.15, 'blockMinutes' => 150],
		['key' => 'leitende', 'label' => 'Leitende', 'portionFactor' => 1.10, 'blockMinutes' => 150, 'isLeaders' => true],
	];

	/**
	 * @param list<GroupInput> $groups
	 * @param list<string> $meals
	 */
	private function __construct(
		public readonly string $name,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
		public readonly string $organizer,
		public readonly string $campType,
		public readonly array $groups,
		public readonly bool $locationKnown,
		public readonly string $locationName,
		public readonly string $region,
		public readonly string $theme,
		public readonly bool $hike,
		public readonly bool $wideGame,
		public readonly string $freeTime,
		public readonly bool $selfCatering,
		public readonly array $meals,
		public readonly string $kitchenTeam,
		public readonly string $materialResponsible,
		/** Eigene Tagesvorlage für normale Lagertage; null: Regeln des Lagerplaners */
		public readonly ?int $dayTemplateId = null,
		/** Ankunft am ersten Tag (HH:MM); null: wie bisher um 10 Uhr */
		public readonly ?string $arrivalTime = null,
		/** Abreise am letzten Tag (HH:MM); null: wie bisher um 14 Uhr */
		public readonly ?string $departureTime = null,
		/** Einfachen Speiseplan mit Gerichten vorschlagen, die fast immer gehen */
		public readonly bool $proposeMenu = false,
	) {
	}

	public const ARRIVAL_EARLIEST = '06:00';
	public const ARRIVAL_LATEST = '20:00';
	/** Frühestens 10:30, damit nach dem Frühstück Zeit für den Abbau bleibt. */
	public const DEPARTURE_EARLIEST = '10:30';
	public const DEPARTURE_LATEST = '18:00';
	/** Tagesveranstaltung: Ende frühestens 9 Uhr, spätestens 22 Uhr, mindestens drei Stunden */
	public const SINGLE_DAY_START = '09:00';
	public const SINGLE_DAY_END = '22:00';
	public const SINGLE_DAY_MINIMUM = 180;

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidWizardInput
	 */
	public static function fromArray(array $data): self {
		$errors = [];

		$name = self::text($data, 'name', self::MAX_NAME, $errors, 'Der Name');
		if ($name === '' && !isset($errors['name'])) {
			$errors['name'] = 'Bitte gib dem Lager einen Namen.';
		}

		$start = self::parseDate($data['start'] ?? null);
		$end = self::parseDate($data['end'] ?? null);
		if ($start === null) {
			$errors['start'] = 'Bitte gib den ersten Lagertag als Datum an.';
		}
		if ($end === null) {
			$errors['end'] = 'Bitte gib den letzten Lagertag als Datum an.';
		}
		if ($start !== null && $end !== null) {
			if ($end < $start) {
				$errors['end'] = 'Der letzte Lagertag liegt vor dem ersten.';
			} elseif ((int)$start->diff($end)->days + 1 > self::MAX_DAYS) {
				$errors['end'] = 'Ein Lager kann höchstens ' . self::MAX_DAYS . ' Tage dauern.';
			}
		}

		$organizer = self::text($data, 'organizer', self::MAX_TEXT, $errors, 'Die Angabe zum Veranstalter');
		$campType = $data['campType'] ?? 'tent';
		if (!is_string($campType) || !in_array($campType, self::CAMP_TYPES, true)) {
			$errors['campType'] = 'Bitte wähle Zeltlager oder Hausfreizeit.';
			$campType = 'tent';
		}

		$groups = self::parseGroups($data['groups'] ?? null, $errors);

		$location = self::section($data, 'location', $errors);
		$program = self::section($data, 'program', $errors);
		$catering = self::section($data, 'catering', $errors);
		$organisation = self::section($data, 'organisation', $errors);

		$locationKnown = self::bool($location, 'known', false, 'location.known', $errors);
		$locationName = self::text($location, 'name', self::MAX_TEXT, $errors, 'Der Lagerort', 'location.name');
		if ($locationKnown && $locationName === '' && !isset($errors['location.name'])) {
			$errors['location.name'] = 'Bitte gib den Lagerort an oder wähle „Ort noch offen“.';
		}
		$region = self::text($location, 'region', self::MAX_TEXT, $errors, 'Die Region', 'location.region');

		$theme = self::text($program, 'theme', self::MAX_THEME, $errors, 'Das Lagerthema', 'program.theme');
		$hike = self::bool($program, 'hike', false, 'program.hike', $errors);
		$dayTemplateId = $program['dayTemplateId'] ?? null;
		if ($dayTemplateId === '' || $dayTemplateId === 0) {
			$dayTemplateId = null;
		}
		if ($dayTemplateId !== null && (!is_numeric($dayTemplateId) || (int)$dayTemplateId < 1)) {
			$errors['program.dayTemplateId'] = 'Unbekannte Tagesvorlage.';
			$dayTemplateId = null;
		}
		$wideGame = self::bool($program, 'wideGame', false, 'program.wideGame', $errors);
		$freeTime = $program['freeTime'] ?? 'medium';
		if (!is_string($freeTime) || !in_array($freeTime, self::FREE_TIME, true)) {
			$errors['program.freeTime'] = 'Unbekannte Angabe zur freien Zeit.';
			$freeTime = 'medium';
		}

		$selfCatering = self::bool($catering, 'selfCatering', true, 'catering.selfCatering', $errors);
		$meals = $catering['meals'] ?? ['breakfast', 'lunch', 'dinner'];
		if (!is_array($meals) || array_filter($meals, static fn ($m): bool => !is_string($m) || !in_array($m, self::MEAL_TYPES, true)) !== []) {
			$errors['catering.meals'] = 'Unbekannte Mahlzeit ausgewählt.';
			$meals = [];
		}
		$meals = array_values(array_intersect(self::MEAL_TYPES, $meals));

		$proposeMenu = ($catering['proposeMenu'] ?? false) === true;
		$kitchenTeam = $catering['kitchenTeam'] ?? 'unknown';
		if (!is_string($kitchenTeam) || !in_array($kitchenTeam, self::TRISTATE, true)) {
			$errors['catering.kitchenTeam'] = 'Unbekannte Angabe zum Küchenteam.';
			$kitchenTeam = 'unknown';
		}
		$materialResponsible = $organisation['materialResponsible'] ?? 'unknown';
		if (!is_string($materialResponsible) || !in_array($materialResponsible, self::TRISTATE, true)) {
			$errors['organisation.materialResponsible'] = 'Unbekannte Angabe zur Materialverantwortung.';
			$materialResponsible = 'unknown';
		}

		$arrivalTime = self::time($data['arrivalTime'] ?? null, self::ARRIVAL_EARLIEST, self::ARRIVAL_LATEST, 'arrivalTime', $errors,
			'Die Ankunft muss zwischen ' . self::ARRIVAL_EARLIEST . ' und ' . self::ARRIVAL_LATEST . ' Uhr liegen.');
		$departureTime = self::time($data['departureTime'] ?? null, self::DEPARTURE_EARLIEST, self::DEPARTURE_LATEST, 'departureTime', $errors,
			'Die Abreise muss zwischen ' . self::DEPARTURE_EARLIEST . ' und ' . self::DEPARTURE_LATEST . ' Uhr liegen.');
		if ($start !== null && $end !== null && $start == $end) {
			// Tagesveranstaltung: Beginn und Ende am selben Tag, mindestens drei Stunden
			$errors = array_diff_key($errors, ['departureTime' => true]);
			$departureTime = self::time($data['departureTime'] ?? null, self::SINGLE_DAY_START, self::SINGLE_DAY_END, 'departureTime', $errors,
				'Das Ende muss zwischen ' . self::SINGLE_DAY_START . ' und ' . self::SINGLE_DAY_END . ' Uhr liegen.');
			$from = $arrivalTime ?? '09:30';
			$until = $departureTime ?? '17:30';
			if (!isset($errors['arrivalTime']) && !isset($errors['departureTime'])
				&& Timeline::minutes($until) - Timeline::minutes($from) < self::SINGLE_DAY_MINIMUM) {
				$errors['departureTime'] = 'Eine Tagesveranstaltung dauert mindestens drei Stunden; das Ende liegt zu früh.';
			}
		}

		if ($start !== null && $end !== null && $start == $end) {
			foreach ($groups as $i => $group) {
				if ($group->blockMinutes > self::MAX_BLOCK_MINUTES_SINGLE_DAY) {
					$errors["groups.$i.blockMinutes"] ??= 'An einem eintägigen Lager passen höchstens ' . self::MAX_BLOCK_MINUTES_SINGLE_DAY . ' Minuten zwischen Programmbeginn um 10 Uhr und das Mittagessen.';
				}
			}
		}

		if ($errors !== []) {
			throw new InvalidWizardInput($errors);
		}

		/** @var DateTimeImmutable $start */
		/** @var DateTimeImmutable $end */
		return new self(
			$name,
			$start,
			$end,
			$organizer,
			$campType,
			$groups,
			$locationKnown,
			$locationKnown ? $locationName : '',
			$region,
			$theme,
			$hike,
			$wideGame,
			$freeTime,
			$selfCatering,
			$meals,
			$kitchenTeam,
			$materialResponsible,
			$dayTemplateId === null ? null : (int)$dayTemplateId,
			$arrivalTime,
			$departureTime,
			$proposeMenu,
		);
	}

	/**
	 * Uhrzeit HH:MM im erlaubten Bereich; leer heißt „wie bisher“.
	 *
	 * @param array<string, string> $errors
	 */
	private static function time(mixed $value, string $min, string $max, string $field, array &$errors, string $message): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1 || $value < $min || $value > $max) {
			$errors[$field] = $message;
			return null;
		}
		return $value;
	}

	public function dayCount(): int {
		return (int)$this->start->diff($this->end)->days + 1;
	}

	/** @return list<GroupInput> Gruppen mit Personen, ohne Leitende */
	public function participantGroups(): array {
		return array_values(array_filter(
			$this->groups,
			static fn (GroupInput $g): bool => !$g->isLeaders && $g->count > 0,
		));
	}

	public function leaderCount(): int {
		$sum = 0;
		foreach ($this->groups as $group) {
			if ($group->isLeaders) {
				$sum += $group->count;
			}
		}
		return $sum;
	}

	public function totalCount(): int {
		return array_sum(array_map(static fn (GroupInput $g): int => $g->count, $this->groups));
	}

	/** @return array<string, mixed> Normalisierte Form, wird im Assistentenlauf gespeichert. */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'start' => $this->start->format('Y-m-d'),
			'end' => $this->end->format('Y-m-d'),
			'arrivalTime' => $this->arrivalTime,
			'departureTime' => $this->departureTime,
			'organizer' => $this->organizer,
			'campType' => $this->campType,
			'groups' => array_map(static fn (GroupInput $g): array => $g->toArray(), $this->groups),
			'location' => ['known' => $this->locationKnown, 'name' => $this->locationName, 'region' => $this->region],
			'program' => ['theme' => $this->theme, 'hike' => $this->hike, 'wideGame' => $this->wideGame, 'freeTime' => $this->freeTime, 'dayTemplateId' => $this->dayTemplateId],
			'catering' => ['selfCatering' => $this->selfCatering, 'meals' => $this->meals, 'kitchenTeam' => $this->kitchenTeam, 'proposeMenu' => $this->proposeMenu],
			'organisation' => ['materialResponsible' => $this->materialResponsible],
		];
	}

	private static function parseDate(mixed $value): ?DateTimeImmutable {
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			return null;
		}
		return $date;
	}

	/**
	 * @param array<string, string> $errors
	 * @return list<GroupInput>
	 */
	private static function parseGroups(mixed $raw, array &$errors): array {
		$defaults = [];
		foreach (self::DEFAULT_GROUPS as $default) {
			$defaults[$default['key']] = $default;
		}

		if ($raw === null || $raw === []) {
			$raw = array_map(static fn (array $d): array => ['key' => $d['key'], 'count' => 0], self::DEFAULT_GROUPS);
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			$errors['groups'] = 'Die Gruppen müssen als Liste übergeben werden.';
			return [];
		}
		if (count($raw) > self::MAX_GROUPS) {
			$errors['groups'] = 'Es sind höchstens ' . self::MAX_GROUPS . ' Gruppen möglich.';
			return [];
		}

		$groups = [];
		$seen = [];
		$total = 0;
		foreach ($raw as $i => $item) {
			$path = "groups.$i";
			if (!is_array($item)) {
				$errors[$path] = 'Ungültige Gruppenangabe.';
				continue;
			}
			$key = $item['key'] ?? null;
			if (!is_string($key) || preg_match('/^[a-z0-9_]{1,32}$/', $key) !== 1) {
				$errors["$path.key"] = 'Ungültige Gruppenkennung.';
				continue;
			}
			if (isset($seen[$key])) {
				$errors["$path.key"] = 'Jede Gruppe darf nur einmal vorkommen.';
				continue;
			}
			$seen[$key] = true;
			$default = $defaults[$key] ?? null;

			$count = self::integer($item, 'count', 0, 0, self::MAX_GROUP_COUNT, "$path.count", $errors,
				'Bitte gib eine ganze Zahl zwischen 0 und ' . self::MAX_GROUP_COUNT . ' an.');
			$blockMinutes = self::integer($item, 'blockMinutes', $default['blockMinutes'] ?? 120, self::MIN_BLOCK_MINUTES, self::MAX_BLOCK_MINUTES, "$path.blockMinutes", $errors,
				'Die Programmdauer muss zwischen ' . self::MIN_BLOCK_MINUTES . ' und ' . self::MAX_BLOCK_MINUTES . ' Minuten liegen; länger passt nicht zwischen Programmbeginn und Mittagessen.');

			$factor = $item['portionFactor'] ?? $default['portionFactor'] ?? 1.0;
			if (!is_int($factor) && !is_float($factor) && !(is_string($factor) && is_numeric($factor))) {
				$errors["$path.portionFactor"] = 'Der Portionsfaktor muss eine Zahl sein.';
				$factor = 1.0;
			}
			$factor = (float)$factor;
			if ($factor <= 0 || $factor > 3) {
				$errors["$path.portionFactor"] = 'Der Portionsfaktor muss größer als 0 und höchstens 3 sein.';
			}

			$item['label'] ??= $default['label'] ?? $key;
			$label = self::text($item, 'label', self::MAX_GROUP_LABEL, $errors, 'Der Gruppenname', "$path.label");
			$separate = self::bool($item, 'separateProgram', false, "$path.separateProgram", $errors);
			$leaders = self::bool($item, 'isLeaders', (bool)($default['isLeaders'] ?? false), "$path.isLeaders", $errors);

			$total += $count;
			$groups[] = new GroupInput($key, $label === '' ? $key : $label, $count, $separate, $leaders, round($factor, 2), $blockMinutes);
		}

		if ($total > self::MAX_TOTAL) {
			$errors['groups'] = 'Insgesamt sind höchstens ' . self::MAX_TOTAL . ' Personen möglich.';
		}
		return $groups;
	}

	/**
	 * Unterabschnitt der Eingabe; fehlt er, gelten die Standardwerte.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, string> $errors
	 * @return array<string, mixed>
	 */
	private static function section(array $data, string $key, array &$errors): array {
		$value = $data[$key] ?? [];
		if (!is_array($value)) {
			$errors[$key] = 'Ungültige Angaben.';
			return [];
		}
		return $value;
	}

	/**
	 * Text mit Längenprüfung; null und fehlend ergeben einen leeren Text.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, string> $errors
	 */
	private static function text(array $data, string $key, int $max, array &$errors, string $subject, ?string $path = null): string {
		$path ??= $key;
		$value = $data[$key] ?? '';
		if (!is_string($value)) {
			$errors[$path] = $subject . ' muss ein Text sein.';
			return '';
		}
		$value = trim($value);
		if (mb_strlen($value) > $max) {
			$errors[$path] = $subject . ' darf höchstens ' . $max . ' Zeichen lang sein.';
			return '';
		}
		return $value;
	}

	/**
	 * Ja/Nein-Angabe; nur echte Wahrheitswerte werden akzeptiert, damit „false“ als Text nicht als ja gilt.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, string> $errors
	 */
	private static function bool(array $data, string $key, bool $default, string $path, array &$errors): bool {
		$value = $data[$key] ?? $default;
		if (!is_bool($value)) {
			$errors[$path] = 'Ungültige Ja/Nein-Angabe.';
			return $default;
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, string> $errors
	 */
	private static function integer(array $data, string $key, int $default, int $min, int $max, string $path, array &$errors, string $message): int {
		$value = $data[$key] ?? $default;
		if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1) {
			$value = (int)$value;
		}
		if (!is_int($value) || $value < $min || $value > $max) {
			$errors[$path] = $message;
			return $default;
		}
		return $value;
	}
}

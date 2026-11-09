<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Material;

/** Materialgruppen nach Konzept 11.1. */
enum MaterialCategory: string {
	case Tents = 'tents';
	case Ropes = 'ropes';
	case Tools = 'tools';
	case Craft = 'craft';
	case Games = 'games';
	case Kitchen = 'kitchen';
	case FirstAid = 'first_aid';
	case Other = 'other';

	public function label(): string {
		return match ($this) {
			self::Tents => 'Zelte und Planen',
			self::Ropes => 'Seile und Befestigung',
			self::Tools => 'Werkzeug',
			self::Craft => 'Bastelmaterial',
			self::Games => 'Spielmaterial',
			self::Kitchen => 'Küchenmaterial',
			self::FirstAid => 'Erste Hilfe',
			self::Other => 'Sonstiges',
		};
	}

	public function order(): int {
		return (int)array_search($this, self::cases(), true);
	}

	/** Vorschlag für Materialhinweise aus Spielen, nach Stichwörtern. */
	public static function guess(string $name): self {
		$text = mb_strtolower($name);
		return match (true) {
			preg_match('/papier|stift|schere|kleber|farbe|bastel|karton|wolle/u', $text) === 1 => self::Craft,
			preg_match('/seil|schnur|hering|kordel/u', $text) === 1 => self::Ropes,
			preg_match('/zelt|plane|tarp|jurte|kohte/u', $text) === 1 => self::Tents,
			preg_match('/hammer|säge|axt|beil|spaten|werkzeug/u', $text) === 1 => self::Tools,
			preg_match('/pflaster|verband|erste hilfe/u', $text) === 1 => self::FirstAid,
			preg_match('/topf|pfanne|kelle|geschirr/u', $text) === 1 => self::Kitchen,
			default => self::Games,
		};
	}
}

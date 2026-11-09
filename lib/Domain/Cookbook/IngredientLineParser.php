<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Domain\Cookbook;

use OCA\Lagerplaner\Domain\Recipes\Unit;

/**
 * Liest eine Zutatenzeile aus Cookbook („500 g Mehl“, „1/2 TL Zimt“, „Salz und Pfeffer“)
 * in Menge, Einheit, Name und Anmerkung.
 *
 * Grundsatz: lieber zur Prüfung markieren als raten. Unsichere Zeilen (Mengenbereiche,
 * Zeilen ohne erkennbare Menge) kommen mit `certain = false` und dem Originaltext zurück.
 */
final class IngredientLineParser {
	/** Einheiten mit ihren Schreibweisen; Faktor für Umrechnung in die Zieleinheit. */
	private const UNITS = [
		'g' => [Unit::Gram, 1], 'gr' => [Unit::Gram, 1], 'gramm' => [Unit::Gram, 1],
		'kg' => [Unit::Kilogram, 1], 'kilo' => [Unit::Kilogram, 1], 'kilogramm' => [Unit::Kilogram, 1],
		'ml' => [Unit::Millilitre, 1], 'milliliter' => [Unit::Millilitre, 1],
		'cl' => [Unit::Millilitre, 10], 'dl' => [Unit::Millilitre, 100],
		'l' => [Unit::Litre, 1], 'liter' => [Unit::Litre, 1],
		'el' => [Unit::Tablespoon, 1], 'essl' => [Unit::Tablespoon, 1], 'esslöffel' => [Unit::Tablespoon, 1], 'tbsp' => [Unit::Tablespoon, 1],
		'tl' => [Unit::Teaspoon, 1], 'teel' => [Unit::Teaspoon, 1], 'teelöffel' => [Unit::Teaspoon, 1], 'tsp' => [Unit::Teaspoon, 1],
		'prise' => [Unit::Pinch, 1], 'prisen' => [Unit::Pinch, 1],
		'bund' => [Unit::Bunch, 1], 'bd' => [Unit::Bunch, 1],
		'dose' => [Unit::Can, 1], 'dosen' => [Unit::Can, 1],
		'packung' => [Unit::Pack, 1], 'packungen' => [Unit::Pack, 1], 'pck' => [Unit::Pack, 1], 'pkg' => [Unit::Pack, 1], 'päckchen' => [Unit::Pack, 1], 'pack' => [Unit::Pack, 1],
		'zehe' => [Unit::Clove, 1], 'zehen' => [Unit::Clove, 1],
		'glas' => [Unit::Jar, 1], 'gläser' => [Unit::Jar, 1],
		'stück' => [Unit::Piece, 1], 'stk' => [Unit::Piece, 1], 'st' => [Unit::Piece, 1],
	];
	private const FRACTIONS = ['½' => '.5', '¼' => '.25', '¾' => '.75', '⅓' => '.333', '⅔' => '.667', '⅛' => '.125'];
	/** Ausdrücke, die ohne Menge ausdrücklich „nach Geschmack“ meinen. */
	/** Gewürze ohne Menge sind üblicherweise „nach Geschmack“ gemeint. */
	private const SEASONING = '/^(salz|pfeffer|zucker|muskat|paprika(pulver)?|curry(pulver)?|chili|kräuter|salz (und|&) pfeffer|pfeffer (und|&) salz)$/iu';
	private const TO_TASTE = '/\b(nach geschmack|nach belieben|n\.\s?b\.|etwas|evtl\.?|eventuell|zum (bestreuen|garnieren|braten|einfetten))\b/iu';

	/** @return array{name: string, quantity: ?float, unit: string, note: string, certain: bool, original: string} */
	public function parse(string $line): array {
		$original = trim(preg_replace('/\s+/u', ' ', strip_tags($line)) ?? $line);
		$text = $original;
		$certain = true;

		// Unicode-Brüche (½) und „1 ½“ in Dezimalzahlen; Dezimalkomma in Punkt.
		foreach (self::FRACTIONS as $glyph => $decimal) {
			$text = preg_replace('/(\d+)\s*' . $glyph . '/u', '$1' . $decimal, $text) ?? $text;
			$text = str_replace($glyph, '0' . $decimal, $text);
		}
		$text = preg_replace('/(\d),(\d)/u', '$1.$2', $text) ?? $text;

		$quantity = null;
		// Gemischte Zahl „1 1/2“, Bruch „1/2“, Bereich „2-3“ oder Zahl
		if (preg_match('/^(\d+)\s+(\d+)\/(\d+)\s*(.*)$/u', $text, $m) === 1 && (int)$m[3] > 0) {
			$quantity = (int)$m[1] + (int)$m[2] / (int)$m[3];
			$rest = $m[4];
		} elseif (preg_match('/^(\d+)\/(\d+)\s*(.*)$/u', $text, $m) === 1 && (int)$m[2] > 0) {
			$quantity = (int)$m[1] / (int)$m[2];
			$rest = $m[3];
		} elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(?:-|–|bis)\s*(\d+(?:\.\d+)?)\s*(.*)$/u', $text, $m) === 1) {
			// Bereich: die größere Menge, damit es reicht; trotzdem prüfen lassen.
			$quantity = max((float)$m[1], (float)$m[2]);
			$rest = $m[3];
			$certain = false;
		} elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(.*)$/u', $text, $m) === 1) {
			$quantity = (float)$m[1];
			$rest = $m[2];
		} else {
			$rest = $text;
		}

		if ($quantity === null || $quantity <= 0) {
			[$name, $note] = $this->split($rest);
			$toTaste = preg_match(self::TO_TASTE, $original) === 1 || preg_match(self::SEASONING, $name) === 1;
			return $this->result($name, null, Unit::ToTaste, $note, $toTaste, $original);
		}

		$unit = Unit::Piece;
		if (preg_match('/^([\p{L}]+)\.?(?=\s|$)\s*(.*)$/u', $rest, $m) === 1 && isset(self::UNITS[mb_strtolower($m[1])])) {
			[$unit, $factor] = self::UNITS[mb_strtolower($m[1])];
			$quantity *= $factor;
			$rest = $m[2];
		}
		[$name, $note] = $this->split($rest);
		if ($name === '') {
			return $this->result($original, null, Unit::ToTaste, '', false, $original);
		}
		return $this->result($name, round($quantity, 3), $unit, $note, $certain, $original);
	}

	/** Text nach dem ersten Komma ist die Anmerkung; Klammerzusätze bleiben Teil des Namens. */
	private function split(string $rest): array {
		$rest = trim($rest, " \t-–:");
		$parts = explode(',', $rest, 2);
		return [trim($parts[0]), trim($parts[1] ?? '')];
	}

	/** @return array{name: string, quantity: ?float, unit: string, note: string, certain: bool, original: string} */
	private function result(string $name, ?float $quantity, Unit $unit, string $note, bool $certain, string $original): array {
		$certain = $certain && mb_strlen($name) <= 80 && mb_strlen($note) <= 80 && ($quantity === null || $quantity <= 100000);
		return [
			'name' => mb_substr($name === '' ? $original : $name, 0, 80),
			'quantity' => $quantity !== null && $quantity > 100000 ? 100000.0 : $quantity,
			'unit' => $unit->value,
			'note' => mb_substr($note, 0, 80),
			'certain' => $certain,
			'original' => $original,
		];
	}
}

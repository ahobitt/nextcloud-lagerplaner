<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Tests\Unit\Domain;

use OCA\Lagerplaner\Domain\Games\Game;
use OCA\Lagerplaner\Domain\Games\GameQuery;
use OCA\Lagerplaner\Domain\Games\HtmlSanitizer;
use OCA\Lagerplaner\Domain\Games\InvalidGameData;
use OCA\Lagerplaner\Domain\Games\WordPressGameNormalizer;
use PHPUnit\Framework\TestCase;

final class GamesTest extends TestCase {
	private WordPressGameNormalizer $normalizer;
	/** @var list<array<string, mixed>> */
	private array $posts;

	protected function setUp(): void {
		$this->normalizer = new WordPressGameNormalizer('pfadfinder-spiele.de', 'pfadfinder-spiele.de');
		$this->posts = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/wordpress-posts.json'), true, 32, JSON_THROW_ON_ERROR);
	}

	private function game(int $index): Game {
		return $this->normalizer->normalize($this->posts[$index]);
	}

	public function testAcfFieldsAreMapped(): void {
		$game = $this->game(0);

		$this->assertSame(101, $game->sourceId);
		$this->assertSame('Testspiel im Kreis – Variante', $game->title);
		$this->assertSame('Alle stehen im Kreis und reagieren auf „Kommandos“.', $game->summary);
		$this->assertSame(6, $game->minAge);
		$this->assertSame(15, $game->durationMinutes);
		$this->assertSame([6, 25], [$game->groupMin, $game->groupMax]);
		$this->assertSame(['Drinnen', 'Draußen'], $game->environments);
		$this->assertSame(['Konzentrationsspiel', 'Kreisspiel', 'Warm up'], $game->types);
		$this->assertSame([], $game->knownAs);
	}

	public function testRatingWidgetScriptsAndAttributesAreRemoved(): void {
		$html = $this->game(0)->descriptionHtml;

		$this->assertStringContainsString('<p>Alle stehen im Kreis.</p>', $html);
		$this->assertStringContainsString('<p>– Erstes Kommando</p>', $html);
		$this->assertStringContainsString('<p>Hinweis hier</p>', $html);
		foreach (['Stimmen', 'kksr', 'script', 'alert', 'onclick', 'javascript', '<a', '<div'] as $forbidden) {
			$this->assertSame(false, str_contains($html, $forbidden), $forbidden);
		}
	}

	public function testOpenEndedAndDuplicateGroupSizes(): void {
		$game = $this->game(1);

		$this->assertSame([6, null], [$game->groupMin, $game->groupMax]);
		$this->assertSame(6, $game->minAge);
	}

	public function testKnownAsIsSplit(): void {
		$this->assertSame(['Zip-Zop', 'Geländeball'], $this->game(2)->knownAs);
	}

	public function testForeignLinksAreRejected(): void {
		$this->expectException(InvalidGameData::class);
		$this->game(3);
	}

	public function testBrokenValuesBecomeUnknown(): void {
		$game = $this->game(4);

		$this->assertNull($game->minAge);
		$this->assertNull($game->durationMinutes);
		$this->assertSame([null, null], [$game->groupMin, $game->groupMax]);
		$this->assertSame([], $game->types);
		$this->assertSame(['Draußen'], $game->environments);
	}

	public function testUnpublishedPostsAreRejected(): void {
		$this->expectException(InvalidGameData::class);
		$this->normalizer->normalize(['status' => 'draft'] + $this->posts[0]);
	}

	public function testSanitizerKeepsListsAndEmphasis(): void {
		$clean = (new HtmlSanitizer())->clean('<ul><li><strong>A</strong></li><li><img src=x onerror=y>B</li></ul><iframe src="z"></iframe>');

		$this->assertSame('<ul><li><strong>A</strong></li><li>B</li></ul>', $clean);
	}

	public function testQueryFiltersByAgeDurationGroupAndEnvironment(): void {
		$games = [$this->game(0), $this->game(1), $this->game(2)];
		$titles = static fn (GameQuery $q): array => array_values(array_map(
			static fn (Game $g): string => $g->title,
			array_filter($games, static fn (Game $g): bool => $q->matches($g)),
		));

		$this->assertCount(2, $titles(new GameQuery(age: 8)));
		$this->assertSame(['Bewegungsspiel für Große Gruppen'], $titles(new GameQuery(maxDuration: 10)));
		$this->assertSame(['Bewegungsspiel für Große Gruppen'], $titles(new GameQuery(groupSize: 40)));
		$this->assertSame(['Fantasieball'], $titles(new GameQuery(text: 'zip')));
		$this->assertSame(['Fantasieball'], $titles(new GameQuery(text: 'gelaende')));
		$this->assertCount(2, $titles(new GameQuery(environment: 'Draußen')));
	}

	public function testUnknownValuesDoNotExcludeAGame(): void {
		$game = $this->game(4);

		$this->assertTrue((new GameQuery(age: 6, maxDuration: 10, groupSize: 3))->matches($game));
	}

	/** Regression Review Allgemein 0.6.1: Unbekanntes Alter ist nicht „geeignet“. */
	public function testUnknownAgeIsExplicit(): void {
		$unknown = $this->game(4);

		$this->assertTrue((new GameQuery(age: 6))->matches($unknown));
		$this->assertFalse((new GameQuery(age: 6, includeUnknownAge: false))->matches($unknown));
		$this->assertFalse(GameQuery::fromArray(['age' => '6', 'unknownAge' => '0'])->includeUnknownAge);
		// Ohne Altersfilter spielt die Einstellung keine Rolle.
		$this->assertTrue((new GameQuery(includeUnknownAge: false))->matches($unknown));
	}

	public function testQueryValidation(): void {
		$this->assertSame(8, GameQuery::fromArray(['age' => '8'])->age);
		$this->expectException(\InvalidArgumentException::class);
		GameQuery::fromArray(['duration' => '-5']);
	}
}

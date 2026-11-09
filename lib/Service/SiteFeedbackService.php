<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Access\Directory;
use OCA\Lagerplaner\Db\SiteFeedback;
use OCA\Lagerplaner\Db\SiteFeedbackMapper;
use OCA\Lagerplaner\Db\SiteFeedbackValue;
use OCA\Lagerplaner\Db\SiteFeedbackValueMapper;
use OCA\Lagerplaner\Db\Transaction;
use OCA\Lagerplaner\Domain\Geo\CoordinateParser;
use OCA\Lagerplaner\Domain\IsoDate;
use OCA\Lagerplaner\Domain\Sites\FactMethod;
use OCA\Lagerplaner\Domain\Sites\SiteAttribute;
use OCA\Lagerplaner\Domain\Sites\SiteNormalizer;
use OCA\Lagerplaner\Sites\SiteSources;
use OCA\Lagerplaner\Domain\Sites\FeedbackKind;
use OCA\Lagerplaner\Domain\Sites\SiteFeatures;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * „Feedback / Angaben ergänzen“: Rückmeldungen zu Lagerplätzen und fehlenden Plätzen von
 * allen, die den Lagerplaner nutzen. Die Administration übernimmt sie (Werte werden als von
 * Hand ergänzt gespeichert und können an die Quelle zurückgemeldet werden) oder lehnt sie ab.
 */
class SiteFeedbackService {
	public const MAX_MESSAGE = 2000;
	/** offene Rückmeldungen je Person, gegen versehentliche Massen-Meldungen */
	public const MAX_OPEN_PER_USER = 30;

	public function __construct(
		private SiteFeedbackMapper $feedback,
		private SiteFeedbackValueMapper $values,
		private SiteDirectoryService $sites,
		private Directory $people,
		private Transaction $transaction,
		private ITimeFactory $time,
		private SiteSources $registry,
	) {
	}

	/**
	 * @param array<string, mixed> $values vorgeschlagene Werte, je nach Art
	 * @return array{id: int}
	 */
	public function submit(string $uid, ?int $siteId, string $kind, array $values, string $message): array {
		$type = FeedbackKind::tryFrom($kind) ?? throw new \InvalidArgumentException('Unbekannte Art der Rückmeldung.');
		if ($uid === '') {
			throw new ForbiddenException('Rückmeldungen geben angemeldete Personen.');
		}
		if ($type->needsSite()) {
			if ($siteId === null) {
				throw new \InvalidArgumentException('Zu welchem Platz gehört die Rückmeldung?');
			}
			$this->sites->get($siteId, $uid);
		} else {
			$siteId = null;
		}
		$message = trim($message);
		if (mb_strlen($message) > self::MAX_MESSAGE) {
			throw new \InvalidArgumentException('Die Nachricht ist zu lang (höchstens 2000 Zeichen).');
		}
		$clean = $this->validate($type, $values, $message);
		$mine = array_filter($this->feedback->findByStatus(SiteFeedback::OPEN), static fn (SiteFeedback $f): bool => $f->getCreatedBy() === $uid);
		if (count($mine) >= self::MAX_OPEN_PER_USER) {
			throw new \InvalidArgumentException('Du hast schon viele offene Rückmeldungen; bitte warte, bis die Administration sie bearbeitet hat.');
		}
		return $this->transaction->run(function () use ($uid, $siteId, $type, $clean, $message): array {
			$entry = new SiteFeedback();
			$entry->setSiteId($siteId);
			$entry->setKind($type->value);
			$entry->setMessage($message === '' ? null : $message);
			$entry->setCreatedBy($uid);
			$entry->setCreatedAt($this->time->getTime());
			$entry->setUpstreamRelevant($type->upstreamRelevant() ? 1 : 0);
			$entry = $this->feedback->insert($entry);
			foreach ($clean as $attribute => $value) {
				$row = new SiteFeedbackValue();
				$row->setFeedbackId((int)$entry->getId());
				$row->setAttribute($attribute);
				$row->setValue($value);
				$this->values->insert($row);
			}
			return ['id' => (int)$entry->getId()];
		});
	}

	/** @return list<array<string, mixed>> offene Rückmeldungen, neueste zuerst (Verwaltung) */
	public function open(): array {
		$list = [];
		foreach ($this->feedback->findByStatus(SiteFeedback::OPEN) as $entry) {
			$site = null;
			if ($entry->getSiteId() !== null) {
				$summary = $this->sites->summaryFor((int)$entry->getSiteId());
				$site = $summary === null ? null : ['id' => $summary['id'], 'name' => $summary['name'], 'sourceLabel' => $summary['sourceLabel']];
			}
			$list[] = [
				'id' => (int)$entry->getId(),
				'kind' => $entry->getKind(),
				'site' => $site,
				'values' => $this->valuesOf((int)$entry->getId()),
				'message' => $entry->getMessage(),
				'createdBy' => $entry->getCreatedBy(),
				'createdByName' => $this->people->displayName(Directory::USER, $entry->getCreatedBy()),
				'createdAt' => (int)$entry->getCreatedAt(),
				'upstreamRelevant' => (bool)$entry->getUpstreamRelevant(),
			];
		}
		return $list;
	}

	public function countOpen(): int {
		return count($this->feedback->findByStatus(SiteFeedback::OPEN));
	}

	/**
	 * Übernehmen: vorgeschlagene Werte werden als von Hand ergänzt gespeichert (mit Vermerk der
	 * Rückmeldung); ein fehlender Platz wird ein eigener Eintrag der meldenden Person. Mit
	 * $apply = false nur als erledigt kennzeichnen.
	 *
	 * @return array{siteId: int|null}
	 */
	public function accept(int $id, string $uid, bool $apply = true, string $note = '', ?bool $upstream = null): array {
		$entry = $this->openEntry($id);
		if ($upstream !== null) {
			$entry->setUpstreamRelevant($upstream ? 1 : 0);
		}
		$kind = FeedbackKind::from($entry->getKind());
		$values = $this->valuesOf($id);
		$siteId = $entry->getSiteId() === null ? null : (int)$entry->getSiteId();
		$vermerk = 'Rückmeldung von ' . $this->people->displayName(Directory::USER, $entry->getCreatedBy());
		return $this->transaction->run(function () use ($entry, $kind, $values, $siteId, $uid, $apply, $note, $vermerk): array {
			if ($apply) {
				$data = match ($kind) {
					FeedbackKind::Website => ['homepage' => $values['homepage']],
					FeedbackKind::Capacity => array_map('intval', array_intersect_key($values, ['capacity' => 1, 'beds' => 1])),
					FeedbackKind::Equipment => ['features' => array_map(static fn (string $v): bool|string => $v === 'partial' ? 'partial' : $v === 'true', $values)],
					FeedbackKind::Closed => ['operatingStatus' => isset($values['until']) ? 'temporarily_unavailable' : 'closed',
						'statusFrom' => $values['since'] ?? null, 'statusUntil' => $values['until'] ?? null],
					FeedbackKind::Name => ['name' => $values['name']],
					FeedbackKind::Location => ['coordinates' => array_combine(['lat', 'lon'], self::coordinates($values['location']))],
					FeedbackKind::Address => ['address' => array_intersect_key($values, ['postalCode' => 1, 'city' => 1])],
					FeedbackKind::Operator => isset($values['homepage']) ? ['homepage' => $values['homepage']] : [],
					default => [],
				};
				if ($kind === FeedbackKind::NewSite) {
					[$lat, $lon] = isset($values['location']) ? array_map('floatval', explode(',', $values['location'])) : [null, null];
					// Von der Administration verwaltet (ohne Eigentümer); wer gemeldet hat, steht als Herkunft dabei
					$created = $this->sites->createManaged(array_filter([
						'name' => $values['name'], 'type' => $values['type'], 'homepage' => $values['homepage'] ?? null,
						'coordinates' => $lat === null ? null : ['lat' => $lat, 'lon' => $lon],
						'description' => $entry->getMessage(),
					], static fn ($v) => $v !== null), null);
					$siteId = (int)$created['id'];
					$this->sites->recordFact($siteId, SiteAttribute::Name, FactMethod::Feedback, $entry->getCreatedBy(), null, null, $this->time->getTime(), 'Gemeldet von ' . $this->people->displayName(Directory::USER, $entry->getCreatedBy()));
				} elseif ($data !== [] && $siteId !== null) {
					$this->sites->applyCorrection($siteId, $uid, $data, null, $vermerk, FactMethod::Feedback);
				}
			}
			$this->close($entry, SiteFeedback::ACCEPTED, $uid, $note);
			return ['siteId' => $siteId];
		});
	}

	/**
	 * „Mögliche Korrekturen für die Campsitemap“: geprüfte, übernommene Rückmeldungen, die die
	 * Stammdaten einer Quelle betreffen (upstream_relevant) und noch nicht weitergegeben sind.
	 * Die App ändert die Quelle nicht selbst; der Text dient einem Issue von Hand.
	 *
	 * @return array{source: string, items: list<array<string, mixed>>, text: string, feedback: array<string, string>}
	 */
	public function upstream(string $sourceKey): array {
		$source = $this->registry->get($sourceKey) ?? throw new NotFoundException('Diese Datenquelle gibt es nicht.');
		$items = [];
		foreach ($this->feedback->findByStatus(SiteFeedback::ACCEPTED) as $entry) {
			if (!$entry->getUpstreamRelevant() || $entry->getReportedAt() !== null) {
				continue;
			}
			$values = $this->valuesOf((int)$entry->getId());
			if ($entry->getSiteId() === null) {
				$site = (new SiteNormalizer())->normalize(array_filter(['sourceId' => 'neu', 'name' => $values['name'] ?? '?', 'type' => $values['type'] ?? 'tent',
					'coordinates' => isset($values['location']) ? array_combine(['lat', 'lon'], self::coordinates($values['location'])) : null, 'homepage' => $values['homepage'] ?? null], static fn ($v) => $v !== null));
			} else {
				$summary = $this->sites->summaryFor((int)$entry->getSiteId());
				if ($summary === null || $summary['source'] !== $sourceKey) {
					continue;
				}
				$site = $this->sites->siteOf((int)$entry->getSiteId());
			}
			$items[] = ['id' => (int)$entry->getId(), 'kind' => $entry->getKind(), 'name' => $site->name, 'line' => $source->describe($site),
				'values' => $values, 'message' => $entry->getMessage(), 'handledAt' => $entry->getHandledAt()];
		}
		return ['source' => $sourceKey, 'items' => $items, 'text' => self::upstreamText($source->name(), $items), 'feedback' => $source->feedback()];
	}

	/** Alles, was upstream() nennt, als weitergegeben kennzeichnen. */
	public function markReported(string $sourceKey): int {
		$now = $this->time->getTime();
		$ids = array_column($this->upstream($sourceKey)['items'], 'id');
		foreach ($ids as $id) {
			$entry = $this->feedback->get($id);
			$entry->setReportedAt($now);
			$this->feedback->update($entry);
		}
		return count($ids);
	}

	private const KIND_TEXT = ['website' => 'Website geändert oder nicht erreichbar', 'closed' => 'Platz geschlossen', 'new_site' => 'Neuer Platz',
		'name' => 'Name falsch', 'location' => 'Koordinaten falsch', 'operator' => 'Betreiber gewechselt', 'outdated' => 'Angaben veraltet'];

	private static function upstreamText(string $name, array $items): string {
		$text = "Mögliche Korrekturen für die $name (geprüft im Lagerplaner)\n";
		foreach ($items as $item) {
			$text .= "\n## " . (self::KIND_TEXT[$item['kind']] ?? $item['kind']) . ": {$item['name']}\n\n```csv\n{$item['line']}\n```\n";
			foreach ($item['values'] as $key => $value) {
				$text .= "- $key: $value\n";
			}
			if ($item['message']) {
				$text .= "- Hinweis: {$item['message']}\n";
			}
		}
		return $text;
	}

	/** @return array{0: float, 1: float} */
	private static function coordinates(string $value): array {
		[$lat, $lon] = array_map('floatval', explode(',', $value));
		return [$lat, $lon];
	}

	public function reject(int $id, string $uid, string $note = ''): void {
		$this->close($this->openEntry($id), SiteFeedback::REJECTED, $uid, $note);
	}

	/** @return array<string, string> */
	private function valuesOf(int $feedbackId): array {
		$values = [];
		foreach ($this->values->findByFeedback($feedbackId) as $value) {
			$values[$value->getAttribute()] = (string)$value->getValue();
		}
		return $values;
	}

	private function openEntry(int $id): SiteFeedback {
		try {
			$entry = $this->feedback->get($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Rückmeldung nicht gefunden.');
		}
		if ($entry->getStatus() !== SiteFeedback::OPEN) {
			throw new \InvalidArgumentException('Diese Rückmeldung ist schon bearbeitet.');
		}
		return $entry;
	}

	private function close(SiteFeedback $entry, string $status, string $uid, string $note): void {
		$entry->setStatus($status);
		$entry->setHandledBy($uid);
		$entry->setHandledAt($this->time->getTime());
		$entry->setHandledNote(trim($note) === '' ? null : mb_substr(trim($note), 0, 500));
		$this->feedback->update($entry);
	}

	/**
	 * @return array<string, string> geprüfte Werte als Text
	 * @throws \InvalidArgumentException
	 */
	private function validate(FeedbackKind $kind, array $values, string $message): array {
		$values = array_intersect_key($values, array_flip($kind->attributes()));
		$text = static fn ($v): string => is_scalar($v) ? trim((string)$v) : '';
		$url = static function (string $v): string {
			if (mb_strlen($v) > 500 || preg_match('#^https?://[^\s]+\.[^\s]+#i', $v) !== 1) {
				throw new \InvalidArgumentException('Bitte eine vollständige Adresse angeben, etwa https://www.beispiel.de.');
			}
			return $v;
		};
		$date = static function (string $v): string {
			if (!IsoDate::valid($v)) {
				throw new \InvalidArgumentException('Bitte ein gültiges Datum angeben (JJJJ-MM-TT).');
			}
			return $v;
		};
		$clean = [];
		switch ($kind) {
			case FeedbackKind::Outdated:
				if (mb_strlen($message) < 5) {
					throw new \InvalidArgumentException('Was stimmt nicht mehr? Bitte kurz beschreiben.');
				}
				break;
			case FeedbackKind::Website:
				$clean['homepage'] = $url($text($values['homepage'] ?? ''));
				break;
			case FeedbackKind::Equipment:
				foreach (SiteFeatures::KEYS as $key) {
					$v = $values[$key] ?? null;
					if ($v === true || $v === 'true' || $v === false || $v === 'false' || $v === 'partial') {
						$clean[$key] = $v === 'partial' ? 'partial' : (($v === true || $v === 'true') ? 'true' : 'false');
					}
				}
				if ($clean === []) {
					throw new \InvalidArgumentException('Bitte mindestens eine Angabe zur Ausstattung machen.');
				}
				break;
			case FeedbackKind::Capacity:
				foreach (['capacity' => 1, 'beds' => 0] as $key => $min) {
					$v = $text($values[$key] ?? '');
					if ($v !== '') {
						if (!ctype_digit($v) || (int)$v < $min || (int)$v > 100000) {
							throw new \InvalidArgumentException('Kapazität und Betten bitte als ganze Zahl.');
						}
						$clean[$key] = $v;
					}
				}
				if ($clean === []) {
					throw new \InvalidArgumentException('Bitte Kapazität oder Betten angeben.');
				}
				break;
			case FeedbackKind::Closed:
				foreach (['since', 'until'] as $key) {
					$v = $text($values[$key] ?? '');
					if ($v !== '') {
						$clean[$key] = $date($v);
					}
				}
				if (isset($clean['since'], $clean['until']) && $clean['until'] < $clean['since']) {
					throw new \InvalidArgumentException('Das Ende liegt vor dem Beginn.');
				}
				break;
			case FeedbackKind::Name:
				$name = $text($values['name'] ?? '');
				if ($name === '' || mb_strlen($name) > 200) {
					throw new \InvalidArgumentException('Bitte den richtigen Namen angeben.');
				}
				$clean['name'] = $name;
				break;
			case FeedbackKind::Location:
				$coordinates = (new CoordinateParser())->parse($text($values['location'] ?? ''))
					?? throw new \InvalidArgumentException('Keine Lage erkannt. Möglich sind Koordinaten wie „51.06, 7.06“ oder ein Kartenlink.');
				$clean['location'] = $coordinates[0] . ',' . $coordinates[1];
				break;
			case FeedbackKind::Address:
				foreach (['postalCode' => 16, 'city' => 120] as $key => $max) {
					$v = $text($values[$key] ?? '');
					if ($v !== '') {
						$clean[$key] = mb_substr($v, 0, $max);
					}
				}
				if ($clean === []) {
					throw new \InvalidArgumentException('Bitte Postleitzahl oder Ort angeben.');
				}
				break;
			case FeedbackKind::Operator:
				$operator = $text($values['operator'] ?? '');
				if ($operator !== '') {
					$clean['operator'] = mb_substr($operator, 0, 200);
				}
				$homepage = $text($values['homepage'] ?? '');
				if ($homepage !== '') {
					$clean['homepage'] = $url($homepage);
				}
				if ($clean === [] && mb_strlen($message) < 5) {
					throw new \InvalidArgumentException('Wer betreibt den Platz jetzt? Bitte kurz angeben.');
				}
				break;
			case FeedbackKind::NewSite:
				$name = $text($values['name'] ?? '');
				if ($name === '' || mb_strlen($name) > 200) {
					throw new \InvalidArgumentException('Bitte den Namen des Platzes angeben.');
				}
				$type = $text($values['type'] ?? '');
				if (!in_array($type, ['tent', 'house', 'both'], true)) {
					throw new \InvalidArgumentException('Bitte angeben, ob es ein Zeltplatz, ein Haus oder beides ist.');
				}
				$clean = ['name' => $name, 'type' => $type];
				$location = $text($values['location'] ?? '');
				if ($location !== '') {
					$coordinates = (new CoordinateParser())->parse($location)
						?? throw new \InvalidArgumentException('Keine Lage erkannt. Möglich sind Koordinaten wie „51.06, 7.06“ oder ein Kartenlink.');
					$clean['location'] = $coordinates[0] . ',' . $coordinates[1];
				}
				$homepage = $text($values['homepage'] ?? '');
				if ($homepage !== '') {
					$clean['homepage'] = $url($homepage);
				}
				break;
		}
		return $clean;
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Service;

use OCA\Lagerplaner\Db\CampSite;
use OCA\Lagerplaner\Db\CampSiteMapper;
use OCA\Lagerplaner\Domain\Access\Permission;
use OCA\Lagerplaner\Domain\Sites\ShortlistStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Merkliste der Lagerplätze eines Lagers mit Anfragestatus, bevorzugter Option und eigenen
 * Notizen (Konzept 5.9, 5.10). Die App bucht nichts beim Betreiber. Pflegen darf die
 * Lagerleitung, ansehen alle im Team.
 */
class ShortlistService {
	private const ORDER = ['booked', 'reserved', 'answered', 'requested', 'interesting', 'cancelled'];

	public function __construct(
		private CampService $camps,
		private CampSiteMapper $entries,
		private SiteDirectoryService $directory,
		private ITimeFactory $time,
	) {
	}

	/** @return array<string, mixed> */
	public function list(int $campId, string $uid): array {
		$camp = $this->camps->require($campId, $uid, Permission::View);
		$people = array_sum(array_map(static fn ($g): int => (int)$g->getEstimatedCount(), $this->camps->groups($campId)));
		$items = [];
		foreach ($this->entries->findByCamp($campId) as $entry) {
			$site = $this->directory->summaryFor((int)$entry->getSiteId(), $uid);
			if ($site === null) {
				continue;
			}
			// Zusammengeführte Dublette: den kanonischen Platz zeigen
			if ($site['mergedInto'] !== null) {
				$canonical = $this->directory->summaryFor((int)$site['mergedInto'], $uid);
				if ($canonical !== null) {
					$site = $canonical + ['mergedFrom' => $site['name']];
				}
			}
			$items[] = [
				'entryId' => $entry->getId(),
				'status' => $entry->getStatus(),
				'statusLabel' => (ShortlistStatus::tryFrom($entry->getStatus()) ?? ShortlistStatus::Interesting)->label(),
				'preferred' => (bool)$entry->getPreferred(),
				'notes' => $entry->getNotes(),
				'tooSmall' => $site['capacity'] !== null && $site['capacity'] < $people,
				'site' => $site,
			];
		}
		usort($items, static fn (array $a, array $b): int => [!$a['preferred'], array_search($a['status'], self::ORDER, true), $a['site']['name']]
			<=> [!$b['preferred'], array_search($b['status'], self::ORDER, true), $b['site']['name']]);
		return [
			'items' => $items,
			'people' => $people,
			'location' => ['known' => (bool)$camp->getLocationKnown(), 'name' => $camp->getLocationName(), 'region' => $camp->getRegion()],
			'statuses' => array_map(static fn (ShortlistStatus $s): array => ['key' => $s->value, 'label' => $s->label()], ShortlistStatus::cases()),
		];
	}

	public function add(int $campId, string $uid, int $siteId): void {
		$this->camps->require($campId, $uid, Permission::ManageSites);
		$site = $this->directory->summaryFor($siteId) ?? throw new NotFoundException('Lagerplatz nicht gefunden.');
		// Dubletten immer auf den kanonischen Platz auflösen
		for ($hops = 0; $site['mergedInto'] !== null && $hops < 5; $hops++) {
			$siteId = (int)$site['mergedInto'];
			$site = $this->directory->summaryFor($siteId) ?? throw new NotFoundException('Lagerplatz nicht gefunden.');
		}
		foreach ($this->entries->findByCamp($campId) as $entry) {
			if ((int)$entry->getSiteId() === $siteId) {
				return;
			}
		}
		$now = $this->time->getTime();
		$entry = new CampSite();
		$entry->setCampId($campId);
		$entry->setSiteId($siteId);
		$entry->setStatus(ShortlistStatus::Interesting->value);
		$entry->setPreferred(false);
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		$this->entries->insert($entry);
	}

	/** @param array<string, mixed> $fields status, preferred, notes */
	public function update(int $entryId, string $uid, array $fields): void {
		$entry = $this->entry($entryId, $uid);
		if (array_key_exists('status', $fields)) {
			if (!is_string($fields['status']) || ShortlistStatus::tryFrom($fields['status']) === null) {
				throw new \InvalidArgumentException('Unbekannter Status.');
			}
			$entry->setStatus($fields['status']);
		}
		if (array_key_exists('notes', $fields)) {
			$notes = is_string($fields['notes']) ? trim($fields['notes']) : '';
			if (mb_strlen($notes) > 5000) {
				throw new \InvalidArgumentException('Die Notizen dürfen höchstens 5000 Zeichen lang sein.');
			}
			$entry->setNotes($notes === '' ? null : $notes);
		}
		if (array_key_exists('preferred', $fields)) {
			$preferred = (bool)$fields['preferred'];
			// Höchstens ein Platz ist die bevorzugte Option.
			if ($preferred) {
				foreach ($this->entries->findByCamp((int)$entry->getCampId()) as $other) {
					if ($other->getId() !== $entry->getId() && $other->getPreferred()) {
						$other->setPreferred(false);
						$this->entries->update($other);
					}
				}
			}
			$entry->setPreferred($preferred);
		}
		$entry->setUpdatedAt($this->time->getTime());
		$this->entries->update($entry);
	}

	public function remove(int $entryId, string $uid): void {
		$this->entries->delete($this->entry($entryId, $uid));
	}

	/** Den Platz als Lagerort übernehmen: Name und Ort ins Lager, Region aus Bundesland oder Ort. */
	public function adopt(int $entryId, string $uid): void {
		$entry = $this->entry($entryId, $uid);
		$site = $this->directory->summaryFor((int)$entry->getSiteId()) ?? throw new NotFoundException('Lagerplatz nicht gefunden.');
		$camp = $this->camps->require((int)$entry->getCampId(), $uid, Permission::ManageSites);
		$name = $site['city'] !== null && !str_contains($site['name'], $site['city']) ? $site['name'] . ', ' . $site['city'] : $site['name'];
		$this->camps->setLocation($camp, $name, $site['state'] ?? $site['city'], $this->time->getTime());
	}

	private function entry(int $id, string $uid): CampSite {
		try {
			$entry = $this->entries->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Eintrag nicht gefunden.');
		}
		$this->camps->require((int)$entry->getCampId(), $uid, Permission::ManageSites);
		return $entry;
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Db;

use OCA\Lagerplaner\Domain\Sites\Site;
use OCA\Lagerplaner\Domain\Sites\SiteFeatures;
use OCA\Lagerplaner\Domain\Sites\SiteType;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Eintrag im Lagerplatzverzeichnis.
 *
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getSourceId()
 * @method void setSourceId(string $sourceId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSiteType()
 * @method void setSiteType(string $siteType)
 * @method string|null getStreet()
 * @method void setStreet(?string $street)
 * @method string|null getPostalCode()
 * @method void setPostalCode(?string $postalCode)
 * @method string|null getCity()
 * @method void setCity(?string $city)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method string getCountry()
 * @method void setCountry(?string $country)
 * @method float|null getLat()
 * @method void setLat(?float $lat)
 * @method float|null getLon()
 * @method void setLon(?float $lon)
 * @method int|null getCapacity()
 * @method void setCapacity(?int $capacity)
 * @method int|null getBeds()
 * @method void setBeds(?int $beds)
 * @method int|null getFeatureWater()
 * @method void setFeatureWater(?int $value)
 * @method int|null getFeatureSanitary()
 * @method void setFeatureSanitary(?int $value)
 * @method int|null getFeatureKitchen()
 * @method void setFeatureKitchen(?int $value)
 * @method int|null getFeatureElectricity()
 * @method void setFeatureElectricity(?int $value)
 * @method int|null getFeatureFireplace()
 * @method void setFeatureFireplace(?int $value)
 * @method int|null getFeatureAccessible()
 * @method void setFeatureAccessible(?int $value)
 * @method string|null getOperatingStatus()
 * @method void setOperatingStatus(?string $operatingStatus)
 * @method string|null getStatusFrom()
 * @method void setStatusFrom(?string $statusFrom)
 * @method string|null getStatusUntil()
 * @method void setStatusUntil(?string $statusUntil)
 * @method string|null getReviewNote()
 * @method void setReviewNote(?string $reviewNote)
 * @method string|null getCapacityNote()
 * @method void setCapacityNote(?string $capacityNote)
 * @method int|null getMergedInto()
 * @method void setMergedInto(?int $mergedInto)
 * @method int|null getReportedAt()
 * @method void setReportedAt(?int $reportedAt)
 * @method string|null getPriceText()
 * @method void setPriceText(?string $priceText)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getHomepage()
 * @method void setHomepage(?string $homepage)
 * @method string|null getSourceUrl()
 * @method void setSourceUrl(?string $sourceUrl)
 * @method string|null getSourceDate()
 * @method void setSourceDate(?string $sourceDate)
 * @method string|null getSnapshotVersion()
 * @method void setSnapshotVersion(?string $snapshotVersion)
 * @method int|null getImportedAt()
 * @method void setImportedAt(?int $importedAt)
 * @method int|null getOperatorConfirmedAt()
 * @method void setOperatorConfirmedAt(?int $operatorConfirmedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SiteRecord extends Entity {
	use PersistsDefaults;

	public const ACTIVE = 'active';
	public const MISSING = 'missing';
	public const MANUAL = 'manual';

	protected $source;
	protected $sourceId;
	protected $name;
	protected $siteType;
	protected $street;
	protected $postalCode;
	protected $city;
	protected $state;
	protected $country;
	protected $lat;
	protected $lon;
	protected $capacity;
	protected $beds;
	/** Ausstattung: 1 vorhanden, 0 nicht vorhanden, null unbekannt */
	protected $featureWater;
	protected $featureSanitary;
	protected $featureKitchen;
	protected $featureElectricity;
	protected $featureFireplace;
	protected $featureAccessible;
	protected $priceText;
	/** Eigene Einträge: an eine Quelle zurückgemeldet am */
	protected $reportedAt;
	/** Betriebsstatus (OperatingStatus) mit Wirksamkeit; null = nicht angegeben */
	protected $operatingStatus;
	protected $statusFrom;
	protected $statusUntil;
	/** Grund einer noch offenen Prüfung der Angaben */
	protected $reviewNote;
	/** Kapazität als Text, wenn eine Zahl nicht alles sagt („Haus 25–35, Zeltplatz 450“) */
	protected $capacityNote;
	/** Dublette: zusammengeführt in diesen Platz */
	protected $mergedInto;
	protected $description;
	protected $homepage;
	protected $sourceUrl;
	protected $sourceDate;
	protected $snapshotVersion;
	protected $importedAt;
	protected $operatorConfirmedAt;
	protected $status = self::ACTIVE;
	/** Ausstattung: 2 = teilweise vorhanden (neben 1, 0, null) */
	public const PARTIAL = 2;
	protected $createdBy;
	protected $updatedAt;

	public function __construct() {
		$this->addType('lat', Types::FLOAT);
		$this->addType('lon', Types::FLOAT);
		$this->addType('featureWater', Types::SMALLINT);
		$this->addType('featureSanitary', Types::SMALLINT);
		$this->addType('featureKitchen', Types::SMALLINT);
		$this->addType('featureElectricity', Types::SMALLINT);
		$this->addType('featureFireplace', Types::SMALLINT);
		$this->addType('featureAccessible', Types::SMALLINT);
		$this->addType('reportedAt', Types::BIGINT);
		$this->addType('mergedInto', Types::BIGINT);
		$this->addType('capacity', Types::INTEGER);
		$this->addType('beds', Types::INTEGER);
		$this->addType('importedAt', Types::BIGINT);
		$this->addType('operatorConfirmedAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->persistDefaults();
	}

	public function fillFrom(Site $site): void {
		$this->setSourceId($site->sourceId);
		$this->setName($site->name);
		$this->setSiteType($site->type->value);
		$this->setStreet($site->street);
		$this->setPostalCode($site->postalCode);
		$this->setCity($site->city);
		$this->setState($site->state);
		$this->setCountry($site->country);
		$this->setLat($site->lat);
		$this->setLon($site->lon);
		$this->setCapacity($site->capacity);
		$this->setBeds($site->beds);
		foreach ($site->features->toArray() as $key => $value) {
			$this->{'setFeature' . ucfirst($key)}($value === null ? null : ($value === SiteFeatures::PARTIAL ? self::PARTIAL : (int)$value));
		}
		$this->setPriceText($site->priceText);
		$this->setDescription($site->description);
		$this->setHomepage($site->homepage);
		$this->setSourceUrl($site->sourceUrl);
		$this->setSourceDate($site->sourceDate);
	}

	/** @return array<string, bool|string|null> Ausstattung aus den Spalten */
	public function featureValues(): array {
		$values = [];
		foreach (SiteFeatures::KEYS as $key) {
			$value = $this->{'feature' . ucfirst($key)};
			$values[$key] = $value === null ? null : ((int)$value === self::PARTIAL ? SiteFeatures::PARTIAL : (bool)(int)$value);
		}
		return $values;
	}

	public function toDomain(): Site {
		return new Site(
			(string)$this->sourceId, (string)$this->name, SiteType::tryFrom((string)$this->siteType) ?? SiteType::Tent,
			$this->street, $this->postalCode, $this->city, $this->state, $this->country,
			$this->lat, $this->lon, $this->capacity, $this->beds,
			SiteFeatures::fromArray($this->featureValues()),
			$this->priceText, $this->description, $this->homepage, $this->sourceUrl, $this->sourceDate,
		);
	}
}

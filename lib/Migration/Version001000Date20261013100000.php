<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.10.0: Verknüpfung von Lagerrezepten mit Nextcloud Cookbook. */
class Version001000Date20261013100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$recipes = $schema->getTable('lp_recipes');
		if (!$recipes->hasColumn('cookbook_id')) {
			// Dateikennung des Rezeptordners in Cookbook und Stand der übernommenen recipe.json
			$recipes->addColumn('cookbook_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$recipes->addColumn('cookbook_modified', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$recipes->addColumn('cookbook_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		return $schema;
	}
}

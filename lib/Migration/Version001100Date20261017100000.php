<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.11.0: einzelne Mahlzeiten können entfallen (etwa Lunchpaket von zu Hause). */
class Version001100Date20261017100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$meals = $schema->getTable('lp_meals');
		if (!$meals->hasColumn('cancelled')) {
			$meals->addColumn('cancelled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		}
		return $schema;
	}
}

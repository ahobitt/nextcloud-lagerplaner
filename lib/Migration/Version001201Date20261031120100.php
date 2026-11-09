<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.17.0: Die Ausstattung steht jetzt in eigenen Spalten; features_json entfällt. */
class Version001201Date20261031120100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$sites = $schema->getTable('lp_sites');
		if ($sites->hasColumn('features_json')) {
			$sites->dropColumn('features_json');
		}
		return $schema;
	}
}

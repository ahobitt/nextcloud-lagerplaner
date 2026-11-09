<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.17.0: Bei Angaben „laut Website“ der Wert, den die Quelle damals hatte. Ändert die Quelle
 * ihn später, gilt wieder die Quelle; von Hand korrigierte Angaben bleiben immer.
 */
class Version001203Date20261102120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$facts = $schema->getTable('lp_site_facts');
		if (!$facts->hasColumn('source_value')) {
			$facts->addColumn('source_value', Types::STRING, ['notnull' => false, 'length' => 500]);
		}
		return $schema;
	}
}

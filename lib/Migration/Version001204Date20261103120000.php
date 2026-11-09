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
 * 0.17.0: Rückmeldungen mit Einordnung, ob sie die Stammdaten der Quelle betreffen
 * (upstream_relevant), und wann sie an die Quelle weitergegeben wurden.
 */
class Version001204Date20261103120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$feedback = $schema->getTable('lp_site_feedback');
		if (!$feedback->hasColumn('upstream_relevant')) {
			$feedback->addColumn('upstream_relevant', Types::SMALLINT, ['notnull' => false]);
		}
		if (!$feedback->hasColumn('reported_at')) {
			$feedback->addColumn('reported_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		return $schema;
	}
}

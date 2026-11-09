<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.9.0: Programm- und Tagesvorlagen (Konzept 6.4). */
class Version000900Date20261012200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('lp_templates')) {
			$t = $schema->createTable('lp_templates');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			// item: einzelner Programmpunkt; day: Tagesablauf
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$t->addColumn('data_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('shared', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['owner_uid'], 'lp_templates_owner_idx');
		}
		return $schema;
	}
}

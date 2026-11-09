<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.7.0: Material und Packlisten (Konzept 11), Verantwortliche für Aufgaben (Konzept 12.1). */
class Version000700Date20261012090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tasks = $schema->getTable('lp_tasks');
		if (!$tasks->hasColumn('assignees_json')) {
			$tasks->addColumn('assignees_json', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '[]']);
			$tasks->addColumn('priority', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'normal']);
			$tasks->addColumn('program_item_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$tasks->addColumn('completed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$tasks->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$tasks->addColumn('reminded_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$tasks->addIndex(['status', 'due_date'], 'lp_tasks_due_idx');
		}

		if (!$schema->hasTable('lp_materials')) {
			$t = $schema->createTable('lp_materials');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('camp_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$t->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'other']);
			$t->addColumn('unit', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('reusable', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'needed']);
			$t->addColumn('responsible_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['camp_id'], 'lp_materials_camp_idx');
		}

		if (!$schema->hasTable('lp_material_uses')) {
			$t = $schema->createTable('lp_material_uses');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('camp_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('material_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// leer: für das ganze Lager
			$t->addColumn('program_item_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// leer: Menge noch offen
			$t->addColumn('quantity', Types::FLOAT, ['notnull' => false]);
			// Herkunft, etwa ein eingeplantes Spiel
			$t->addColumn('source_ref', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['camp_id'], 'lp_matuses_camp_idx');
			$t->addIndex(['material_id'], 'lp_matuses_material_idx');
		}

		if (!$schema->hasTable('lp_material_dismissed')) {
			$t = $schema->createTable('lp_material_dismissed');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('camp_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('source_ref', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['camp_id', 'source_ref'], 'lp_matdismissed_uniq');
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** 0.8.0: Lagerplatzverzeichnis (Konzept 5.5) und Merklisten der Lager (Konzept 5.9, 5.10). */
class Version000800Date20261012150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('lp_sites')) {
			$t = $schema->createTable('lp_sites');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// Quelle und stabile Kennung, auch über einen späteren Wechsel auf Feed oder API (Konzept 5.12)
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('source_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 200]);
			$t->addColumn('site_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			foreach (['street' => 200, 'postal_code' => 16, 'city' => 120, 'state' => 60] as $column => $length) {
				$t->addColumn($column, Types::STRING, ['notnull' => false, 'length' => $length]);
			}
			$t->addColumn('country', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => 'DE']);
			$t->addColumn('lat', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('lon', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('capacity', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('beds', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('features_json', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '{}']);
			$t->addColumn('price_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('homepage', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('source_url', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('source_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('snapshot_version', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('imported_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('operator_confirmed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// active; missing: im letzten Datenstand nicht mehr enthalten, gekennzeichnet statt gelöscht
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 12, 'default' => 'active']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['source', 'source_id'], 'lp_sites_source_uniq');
			$t->addIndex(['state'], 'lp_sites_state_idx');
		}

		if (!$schema->hasTable('lp_camp_sites')) {
			$t = $schema->createTable('lp_camp_sites');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('camp_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'interesting']);
			$t->addColumn('preferred', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			// eigene, nicht öffentliche Notizen; bleiben bei einem neuen Datenstand erhalten
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['camp_id', 'site_id'], 'lp_camp_sites_uniq');
		}

		return $schema;
	}
}

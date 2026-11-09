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
 * 0.17.0: Betriebsstatus und angereicherte Angaben, Rückmeldungen von Nutzerinnen und Nutzern.
 *
 * - lp_sites: Betriebsstatus mit Wirksamkeitsdaten, Hinweis auf offene Prüfung, Kapazitätshinweis
 *   als Text, zusammengeführte Dubletten
 * - lp_site_facts: Vermerk je Angabe (etwa „teilweise“)
 * - lp_site_feedback und lp_site_feedback_values: Rückmeldungen zu Plätzen und fehlende Plätze
 */
class Version001202Date20261101120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$sites = $schema->getTable('lp_sites');
		$columns = [
			'operating_status' => [Types::STRING, ['notnull' => false, 'length' => 24]],
			'status_from' => [Types::STRING, ['notnull' => false, 'length' => 10]],
			'status_until' => [Types::STRING, ['notnull' => false, 'length' => 10]],
			'review_note' => [Types::STRING, ['notnull' => false, 'length' => 500]],
			'capacity_note' => [Types::STRING, ['notnull' => false, 'length' => 255]],
			'merged_into' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true]],
		];
		foreach ($columns as $name => [$type, $options]) {
			if (!$sites->hasColumn($name)) {
				$sites->addColumn($name, $type, $options);
			}
		}
		$facts = $schema->getTable('lp_site_facts');
		if (!$facts->hasColumn('note')) {
			$facts->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 255]);
		}

		if (!$schema->hasTable('lp_site_feedback')) {
			$t = $schema->createTable('lp_site_feedback');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('message', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 12, 'default' => 'open']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('handled_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('handled_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('handled_note', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status'], 'lp_site_fb_status_idx');
			$t->addIndex(['site_id'], 'lp_site_fb_site_idx');
		}
		if (!$schema->hasTable('lp_site_feedback_values')) {
			$t = $schema->createTable('lp_site_feedback_values');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('feedback_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('attribute', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('value', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['feedback_id'], 'lp_site_fbv_fb_idx');
		}
		return $schema;
	}
}

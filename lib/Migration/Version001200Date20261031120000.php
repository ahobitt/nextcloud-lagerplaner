<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Lagerplaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.17.0: Lagerplatzdaten aus mehreren Quellen mit Herkunft je Angabe.
 *
 * - Ausstattung als eigene Spalten statt JSON (ja / nein / unbekannt = 1 / 0 / null)
 * - Land optional, ohne Voreinstellung (Plätze außerhalb Deutschlands)
 * - lp_site_sources: Datenquellen mit Namen, Link und letzter Synchronisierung
 * - lp_site_facts: Herkunft einzelner Angaben (Website, von Hand), Rückmeldestatus
 * - ScoutNet-Plätze, ihre Merklisten-Einträge und Einstellungen werden entfernt
 */
class Version001200Date20261031120000 extends SimpleMigrationStep {
	public const FEATURES = ['water', 'sanitary', 'kitchen', 'electricity', 'fireplace', 'accessible'];

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $config,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$sites = $schema->getTable('lp_sites');
		foreach (self::FEATURES as $feature) {
			if (!$sites->hasColumn('feature_' . $feature)) {
				$sites->addColumn('feature_' . $feature, Types::SMALLINT, ['notnull' => false]);
			}
		}
		$sites->getColumn('country')->setNotnull(false)->setDefault(null);
		// Eigene Einträge: wann sie an eine Quelle zurückgemeldet wurden
		if (!$sites->hasColumn('reported_at')) {
			$sites->addColumn('reported_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}

		if (!$schema->hasTable('lp_site_sources')) {
			$t = $schema->createTable('lp_site_sources');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('source_key', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$t->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('license', Types::STRING, ['notnull' => false, 'length' => 120]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('synced_version', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('sync_result', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['source_key'], 'lp_site_src_key_uniq');
		}

		if (!$schema->hasTable('lp_site_facts')) {
			$t = $schema->createTable('lp_site_facts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('attribute', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('method', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('source_url', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('checked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('confidence', Types::STRING, ['notnull' => false, 'length' => 8]);
			$t->addColumn('changed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('reported_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['site_id', 'attribute'], 'lp_site_fact_uniq');
			$t->addIndex(['method'], 'lp_site_fact_method_idx');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Ausstattung aus features_json in die neuen Spalten übertragen
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'features_json')->from('lp_sites');
		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$features = json_decode((string)$row['features_json'], true);
			$update = $this->db->getQueryBuilder();
			$update->update('lp_sites');
			foreach (self::FEATURES as $feature) {
				$value = is_array($features) && is_bool($features[$feature] ?? null) ? (int)$features[$feature] : null;
				$update->set('feature_' . $feature, $update->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT));
			}
			$update->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$result->closeCursor();

		// ScoutNet vorerst vollständig entfernen: Plätze, Merklisten-Einträge, Einstellungen
		$ids = $this->db->getQueryBuilder();
		$ids->select('id')->from('lp_sites')->where($ids->expr()->eq('source', $ids->createNamedParameter('scoutnet')));
		$siteIds = array_map('intval', $ids->executeQuery()->fetchFirstColumn());
		foreach (array_chunk($siteIds, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('lp_camp_sites')->where($delete->expr()->in('site_id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$delete = $this->db->getQueryBuilder();
		$delete->delete('lp_sites')->where($delete->expr()->eq('source', $delete->createNamedParameter('scoutnet')))->executeStatement();
		$this->config->deleteKey('lagerplaner', 'sites_bundled_scoutnet');
		$disabled = array_filter(explode(',', $this->config->getValueString('lagerplaner', 'sites_disabled', '')), static fn (string $s): bool => $s !== '' && $s !== 'scoutnet');
		$this->config->setValueString('lagerplaner', 'sites_disabled', implode(',', $disabled));
		if ($siteIds !== []) {
			$output->info(count($siteIds) . ' ScoutNet-Lagerplätze entfernt.');
		}
	}
}

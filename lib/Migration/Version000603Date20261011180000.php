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
 * Grundschema des Lagerplaners.
 *
 * Die Entwicklungsstände bis 0.6.2 wurden nie installiert; ihre Migrationen sind in
 * diesem Schritt zusammengefasst. Spätere Schemaänderungen kommen als eigene Schritte dazu.
 */
class Version000603Date20261011180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$id = static function ($table): void {
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
		};
		$campId = static fn ($table) => $table->addColumn('camp_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$bigint = static fn ($table, string $name, bool $notnull = true) => $table->addColumn($name, Types::BIGINT, ['notnull' => $notnull, 'unsigned' => true]);
		$string = static fn ($table, string $name, int $length, bool $notnull = true, ?string $default = null) =>
			$table->addColumn($name, Types::STRING, ['notnull' => $notnull, 'length' => $length] + ($default === null ? [] : ['default' => $default]));
		$bool = static fn ($table, string $name, bool $default = false) => $table->addColumn($name, Types::BOOLEAN, ['notnull' => false, 'default' => $default]);
		/** Herkunft und Prüfstatus jedes Planungselements (Konzept 3.10, 17.6) */
		$provenance = static function ($table) use ($string, $bigint): void {
			$string($table, 'origin', 16, true, 'manual');
			$string($table, 'review_state', 16, true, 'confirmed');
			$bigint($table, 'user_modified_at', false);
			$bigint($table, 'deleted_at', false);
			$bigint($table, 'gen_run_id', false);
			$string($table, 'template_ref', 64, false);
			$string($table, 'rule_key', 128, false);
			$string($table, 'fingerprint', 64, false);
			$bigint($table, 'created_at');
			$bigint($table, 'updated_at');
		};

		if ($schema->hasTable('lp_camps')) {
			return $schema;
		}

		$t = $schema->createTable('lp_camps');
		$id($t);
		$string($t, 'owner_uid', 64);
		$string($t, 'name', 200);
		$t->addColumn('description', Types::TEXT, ['notnull' => false]);
		$string($t, 'start_date', 10);
		$string($t, 'end_date', 10);
		$string($t, 'organizer', 200, false);
		$string($t, 'camp_type', 16, true, 'tent');
		$bool($t, 'location_known');
		$string($t, 'location_name', 255, false);
		$string($t, 'region', 255, false);
		$string($t, 'theme', 200, false);
		$string($t, 'status', 16, true, 'draft');
		$t->addColumn('version', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$bigint($t, 'created_at');
		$bigint($t, 'updated_at');
		$t->addIndex(['owner_uid'], 'lp_camps_owner_idx');

		$t = $schema->createTable('lp_camp_groups');
		$id($t);
		$campId($t);
		$string($t, 'group_key', 32);
		$string($t, 'label', 100);
		$t->addColumn('estimated_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$t->addColumn('confirmed_count', Types::INTEGER, ['notnull' => false]);
		$t->addColumn('portion_factor', Types::FLOAT, ['notnull' => true, 'default' => 1.0]);
		$bool($t, 'separate_program');
		$bool($t, 'is_leaders');
		$t->addColumn('block_minutes', Types::INTEGER, ['notnull' => true, 'default' => 120]);
		$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$t->addUniqueIndex(['camp_id', 'group_key'], 'lp_groups_camp_key_uniq');

		$t = $schema->createTable('lp_camp_members');
		$id($t);
		$campId($t);
		$string($t, 'member_type', 8);
		$string($t, 'member_id', 64);
		$string($t, 'roles', 255);
		$string($t, 'created_by', 64);
		$bigint($t, 'created_at');
		$t->addUniqueIndex(['camp_id', 'member_type', 'member_id'], 'lp_members_uniq');
		$t->addIndex(['member_type', 'member_id'], 'lp_members_member_idx');

		$t = $schema->createTable('lp_gen_runs');
		$id($t);
		$campId($t);
		$string($t, 'kind', 16);
		$string($t, 'template_version', 32);
		$t->addColumn('input_json', Types::TEXT, ['notnull' => true]);
		$string($t, 'created_by', 64);
		$bigint($t, 'created_at');
		$t->addIndex(['camp_id'], 'lp_runs_camp_idx');

		$t = $schema->createTable('lp_program_items');
		$id($t);
		$campId($t);
		$string($t, 'day', 10);
		$string($t, 'start_time', 5);
		$t->addColumn('duration_minutes', Types::INTEGER, ['notnull' => true, 'default' => 60]);
		$string($t, 'title', 255);
		$t->addColumn('description', Types::TEXT, ['notnull' => false]);
		$string($t, 'groups_json', 1000, true, '[]');
		$string($t, 'category', 32, true, 'program');
		$bool($t, 'weather_alt_needed');
		$t->addColumn('weather_alternative', Types::TEXT, ['notnull' => false]);
		$bigint($t, 'alternative_to', false);
		$provenance($t);
		$t->addIndex(['camp_id', 'day'], 'lp_prog_camp_day_idx');

		$t = $schema->createTable('lp_tasks');
		$id($t);
		$campId($t);
		$string($t, 'title', 255);
		$t->addColumn('description', Types::TEXT, ['notnull' => false]);
		$string($t, 'due_date', 10, false);
		$string($t, 'status', 16, true, 'open');
		$bool($t, 'mandatory');
		$provenance($t);
		$t->addIndex(['camp_id'], 'lp_tasks_camp_idx');

		$t = $schema->createTable('lp_meals');
		$id($t);
		$campId($t);
		$string($t, 'day', 10);
		$string($t, 'meal_type', 16);
		$string($t, 'meal_time', 5);
		$bool($t, 'self_catered', true);
		$string($t, 'note', 255, false);
		$bool($t, 'portable');
		$t->addColumn('variant_plan', Types::TEXT, ['notnull' => false]);
		$provenance($t);
		$t->addIndex(['camp_id', 'day'], 'lp_meals_camp_day_idx');

		$t = $schema->createTable('lp_games');
		$id($t);
		$string($t, 'source', 32);
		$bigint($t, 'source_id');
		$string($t, 'title', 255);
		$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
		$t->addColumn('description', Types::TEXT, ['notnull' => false]);
		$string($t, 'link', 500);
		$string($t, 'source_modified', 32, false);
		foreach (['min_age', 'duration_minutes', 'group_min', 'group_max'] as $column) {
			$t->addColumn($column, Types::INTEGER, ['notnull' => false]);
		}
		$string($t, 'types_json', 2000, true, '[]');
		$string($t, 'environments_json', 1000, true, '[]');
		$string($t, 'materials_json', 2000, true, '[]');
		$string($t, 'suitability_json', 2000, true, '[]');
		$string($t, 'known_as_json', 2000, true, '[]');
		$bigint($t, 'synced_at');
		$t->addUniqueIndex(['source', 'source_id'], 'lp_games_source_uniq');

		$t = $schema->createTable('lp_program_games');
		$id($t);
		$campId($t);
		$bigint($t, 'program_item_id');
		$string($t, 'source', 32);
		$bigint($t, 'source_id');
		$string($t, 'title', 255);
		$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
		$string($t, 'link', 500);
		$t->addColumn('duration_minutes', Types::INTEGER, ['notnull' => false]);
		$string($t, 'materials_json', 2000, true, '[]');
		$string($t, 'license_name', 64);
		$string($t, 'license_url', 255);
		$string($t, 'attribution', 255);
		$string($t, 'source_modified', 32, false);
		$bigint($t, 'created_at');
		$t->addIndex(['camp_id'], 'lp_pgames_camp_idx');
		$t->addIndex(['program_item_id'], 'lp_pgames_item_idx');

		$t = $schema->createTable('lp_diet_needs');
		$id($t);
		$campId($t);
		$string($t, 'kind', 16);
		$string($t, 'need_key', 128);
		$t->addColumn('count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$bigint($t, 'updated_at');
		$t->addUniqueIndex(['camp_id', 'kind', 'need_key'], 'lp_needs_uniq');

		$t = $schema->createTable('lp_recipes');
		$id($t);
		$campId($t);
		$string($t, 'title', 120);
		$t->addColumn('data_json', Types::TEXT, ['notnull' => true]);
		$string($t, 'template_key', 64, false);
		$string($t, 'allergens_checked_by', 64, false);
		$bigint($t, 'allergens_checked_at', false);
		$string($t, 'created_by', 64);
		$bigint($t, 'created_at');
		$bigint($t, 'updated_at');
		$t->addIndex(['camp_id'], 'lp_recipes_camp_idx');

		$t = $schema->createTable('lp_shop_trips');
		$id($t);
		$campId($t);
		$string($t, 'trip_date', 10);
		$string($t, 'label', 80);
		$string($t, 'origin', 8, true, 'manual');
		$bigint($t, 'modified_at', false);
		$bigint($t, 'created_at');
		$t->addIndex(['camp_id'], 'lp_shop_trips_camp_idx');

		// Gekaufte Mengen (kind „line“, Schlüssel = Hash der Produkteigenschaften) und eigene Posten (kind „extra“)
		$t = $schema->createTable('lp_shop_items');
		$id($t);
		$campId($t);
		$bigint($t, 'trip_id');
		$string($t, 'item_key', 64);
		$string($t, 'kind', 8);
		$string($t, 'name', 120, false);
		$string($t, 'unit_family', 16, false);
		$string($t, 'amount_text', 60, false);
		$string($t, 'category', 16, false);
		$bool($t, 'purchased');
		$t->addColumn('purchased_base', Types::FLOAT, ['notnull' => false]);
		$bigint($t, 'updated_at');
		$t->addUniqueIndex(['trip_id', 'item_key'], 'lp_shop_items_uniq');
		$t->addIndex(['camp_id'], 'lp_shop_items_camp_idx');

		$t = $schema->createTable('lp_shop_stock');
		$id($t);
		$campId($t);
		$string($t, 'item_key', 64);
		$string($t, 'name', 120, false);
		$string($t, 'unit_family', 16, false);
		$t->addColumn('base', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$bigint($t, 'updated_at');
		$t->addUniqueIndex(['camp_id', 'item_key'], 'lp_shop_stock_uniq');

		return $schema;
	}
}

<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000106Date20261106170000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_imports')) {
            $t = $s->createTable('stb_imports');
            foreach (
                [
                    'id' => 36,
                    'tree_id' => 36,
                    'actor_id' => 64,
                    'confirmer_id' => 64,
                    'profile' => 64,
                    'sha256' => 64,
                    'plan_hash' => 64,
                    'original_name' => 250,
                    'status' => 24,
                    'encoding' => 16,
                    'lease_token' => 36,
                    'failure_code' => 64,
                ]
                as $n => $l
            ) {
                $t->addColumn($n, 'string', ['length' => $l, 'default' => '']);
            }
            foreach (
                [
                    'revision' => 1,
                    'actor_revision' => 0,
                    'confirmer_revision' => 0,
                    'tree_revision' => 0,
                    'attempts' => 0,
                ]
                as $n => $v
            ) {
                $t->addColumn($n, 'integer', ['default' => $v]);
            }
            foreach (['size', 'created_at', 'updated_at', 'lease_until'] as $n) {
                $t->addColumn($n, 'bigint', ['default' => 0]);
            }
            foreach (['report_json', 'plan_json'] as $n) {
                $t->addColumn($n, 'text', ['length' => 16777215]);
            }
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['tree_id', 'sha256', 'profile'], 'stb_import_identity');
            $t->addIndex(['status', 'lease_until'], 'stb_import_work');
            $t->addIndex(['actor_id', 'created_at'], 'stb_import_rate');
        }
        if (!$s->hasTable('stb_import_nodes')) {
            $t = $s->createTable('stb_import_nodes');
            $t->addColumn('run_id', 'string', ['length' => 36]);
            $t->addColumn('ordinal', 'integer');
            $t->addColumn('data_json', 'text', ['length' => 16777215]);
            $t->setPrimaryKey(['run_id', 'ordinal']);
        }
        if (!$s->hasTable('stb_import_maps')) {
            $t = $s->createTable('stb_import_maps');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('run_id', 'string', ['length' => 36]);
            $t->addColumn('ordinal', 'integer');
            $t->addColumn('kind', 'string', ['length' => 24]);
            $t->addColumn('object_id', 'string', ['length' => 36]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['run_id', 'ordinal'], 'stb_import_map_run');
        }
        return $s;
    }
}

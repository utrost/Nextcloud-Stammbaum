<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000102Date20261106150000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_records')) {
            $t = $s->createTable('stb_records');
            foreach (
                [
                    'id' => 36,
                    'tree_id' => 36,
                    'origin_id' => 36,
                    'category' => 16,
                    'record_type' => 64,
                    'visibility' => 16,
                    'actor_id' => 64,
                ]
                as $name => $length
            ) {
                $t->addColumn($name, 'string', ['length' => $length]);
            }
            foreach (['data_json', 'original_json'] as $name) {
                $t->addColumn($name, 'text');
            }
            foreach (['revision' => 1, 'preferred' => 0] as $name => $default) {
                $t->addColumn($name, 'integer', ['default' => $default]);
            }
            $t->addColumn('created_at', 'bigint');
            $t->addColumn('updated_at', 'bigint');
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tree_id', 'visibility', 'category'], 'stb_record_tree');
            $t->addIndex(['origin_id', 'preferred'], 'stb_record_origin');
        }
        if (!$s->hasTable('stb_participants')) {
            $t = $s->createTable('stb_participants');
            foreach (['id' => 36, 'record_id' => 36, 'person_id' => 36, 'role' => 100] as $name => $length) {
                $t->addColumn($name, 'string', ['length' => $length]);
            }
            $t->addColumn('position', 'integer');
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['record_id', 'person_id'], 'stb_part_unique');
            $t->addIndex(['person_id', 'record_id'], 'stb_part_person');
        }
        return $s;
    }
}

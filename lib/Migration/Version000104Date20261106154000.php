<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000104Date20261106154000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_contexts')) {
            $t = $s->createTable('stb_contexts');
            foreach (['id' => 36, 'tree_id' => 36, 'label' => 250, 'visibility' => 16] as $n => $l) {
                $t->addColumn($n, 'string', ['length' => $l]);
            }
            $t->addColumn('relation_ids', 'text');
            $t->addColumn('revision', 'integer', ['default' => 1]);
            $t->addColumn('created_at', 'bigint');
            $t->addColumn('updated_at', 'bigint');
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tree_id', 'visibility'], 'stb_context_tree');
        }
        if (!$s->hasTable('stb_context_people')) {
            $t = $s->createTable('stb_context_people');
            foreach (
                ['id' => 36, 'context_id' => 36, 'person_id' => 36, 'role' => 16, 'description' => 250]
                as $n => $l
            ) {
                $t->addColumn($n, 'string', ['length' => $l]);
            }
            $t->addColumn('position', 'integer');
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['context_id', 'person_id'], 'stb_context_person_unique');
            $t->addIndex(['person_id', 'context_id'], 'stb_context_person');
        }
        return $s;
    }
}

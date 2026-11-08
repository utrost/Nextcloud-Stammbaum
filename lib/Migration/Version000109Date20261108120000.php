<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000109Date20261108120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_exports')) {
            $t = $s->createTable('stb_exports');
            foreach (['id' => 36, 'tree_id' => 36, 'actor_id' => 64, 'state' => 16] as $n => $l) {
                $t->addColumn($n, 'string', ['length' => $l]);
            }
            foreach (['tree_revision', 'actor_revision', 'created_at', 'updated_at', 'expires_at'] as $n) {
                $t->addColumn($n, 'bigint');
            }
            foreach (['options_json', 'report_json'] as $n) {
                $t->addColumn($n, 'text');
            }
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tree_id', 'actor_id', 'created_at'], 'stb_export_actor');
            $t->addIndex(['state', 'created_at'], 'stb_export_queue');
        }
        return $s;
    }
}

<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000116Date20261110080000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_task_links')) {
            $t = $s->createTable('stb_task_links');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('tree_id', 'string', ['length' => 36]);
            $t->addColumn('actor_id', 'string', ['length' => 64]);
            $t->addColumn('person_id', 'string', ['length' => 36]);
            $t->addColumn('collection_uri', 'string', ['length' => 255]);
            $t->addColumn('object_name', 'string', ['length' => 80]);
            $t->addColumn('request_id', 'string', ['length' => 80]);
            $t->addColumn('payload_hash', 'string', ['length' => 64]);
            $t->addColumn('payload_json', 'text');
            $t->addColumn('source_revision', 'integer');
            $t->addColumn('member_revision', 'integer');
            $t->addColumn('status', 'string', ['length' => 24]);
            $t->addColumn('created_at', 'bigint');
            $t->addColumn('updated_at', 'bigint');
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['actor_id', 'tree_id', 'request_id'], 'stb_task_request');
            $t->addIndex(['actor_id', 'tree_id', 'person_id', 'created_at'], 'stb_task_person');
        }
        return $s;
    }
}

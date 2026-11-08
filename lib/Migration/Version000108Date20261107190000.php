<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000108Date20261107190000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        $t = $s->getTable('stb_media');
        if (!$t->hasColumn('metadata_json')) {
            $t->addColumn('metadata_json', 'text', ['notnull' => false]);
            $t->addColumn('search_people', 'text', ['notnull' => false]);
            $t->addColumn('search_event', 'string', ['length' => 250, 'notnull' => false]);
            $t->addColumn('search_place', 'string', ['length' => 500, 'notnull' => false]);
            $t->addColumn('metadata_complete', 'integer', ['default' => 0]);
        }
        if (!$s->hasTable('stb_media_refs')) {
            $t = $s->createTable('stb_media_refs');
            $t->addColumn('media_id', 'string', ['length' => 36]);
            $t->addColumn('kind', 'string', ['length' => 16]);
            $t->addColumn('target_id', 'string', ['length' => 36]);
            $t->setPrimaryKey(['media_id', 'kind', 'target_id']);
            $t->addIndex(['kind', 'target_id'], 'stb_media_ref_target');
        }
        return $s;
    }
}

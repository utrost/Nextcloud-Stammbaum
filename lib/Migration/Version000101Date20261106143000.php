<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000101Date20261106143000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        foreach (['stb_sources', 'stb_citations'] as $name) {
            if ($s->hasTable($name)) {
                continue;
            }
            $t = $s->createTable($name);
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('tree_id', 'string', ['length' => 36]);
            if ($name === 'stb_sources') {
                $t->addColumn('title', 'string', ['length' => 250]);
                $t->addColumn('search_title', 'string', ['length' => 250]);
            } else {
                $t->addColumn('source_id', 'string', ['length' => 36]);
                $t->addIndex(['source_id'], 'stb_citation_source');
            }
            $t->addColumn('data_json', 'text');
            $t->addColumn('visibility', 'string', ['length' => 16]);
            $t->addColumn('revision', 'integer', ['default' => 1]);
            $t->addColumn('created_at', 'bigint');
            $t->addColumn('updated_at', 'bigint');
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tree_id', 'visibility'], $name . '_tree_vis');
        }
        if (!$s->hasTable('stb_targets')) {
            $t = $s->createTable('stb_targets');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('citation_id', 'string', ['length' => 36]);
            $t->addColumn('kind', 'string', ['length' => 16]);
            $t->addColumn('target_id', 'string', ['length' => 36]);
            $t->addColumn('effect', 'string', ['length' => 16]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['citation_id', 'kind', 'target_id'], 'stb_target_unique');
            $t->addIndex(['kind', 'target_id'], 'stb_target_lookup');
        }
        return $s;
    }
}

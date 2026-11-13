<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Typed task and calendar origins; old rows remain person links. */
final class Version000121Date20261113090000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        foreach (['stb_task_links', 'stb_calendar_links'] as $name) {
            if (!$schema->hasTable($name)) {
                continue;
            }
            $table = $schema->getTable($name);
            if (!$table->hasColumn('source_type')) {
                $table->addColumn('source_type', 'string', ['length' => 16, 'default' => 'person']);
            }
            if (!$table->hasColumn('source_id')) {
                $table->addColumn('source_id', 'string', ['length' => 36, 'notnull' => false]);
            }
            $table->addIndex(
                ['actor_id', 'tree_id', 'source_type', 'source_id'],
                $name === 'stb_task_links' ? 'stb_task_source' : 'stb_calendar_source',
            );
        }
        return $schema;
    }
}

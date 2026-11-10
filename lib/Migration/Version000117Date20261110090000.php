<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Remove an alpha-only pending text column from already-upgraded development fixtures. */
final class Version000117Date20261110090000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if ($s->hasTable('stb_task_links')) {
            $t = $s->getTable('stb_task_links');
            if ($t->hasColumn('payload_json')) {
                $t->dropColumn('payload_json');
            }
        }
        return $s;
    }
}

<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000105Date20261106155000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        $t = $s->getTable('stb_media');
        if (!$t->hasColumn('actor_revision')) {
            $t->addColumn('actor_revision', 'integer', ['default' => 0]);
        }
        return $s;
    }
}

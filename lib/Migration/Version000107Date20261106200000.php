<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000107Date20261106200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_preferences')) {
            $t = $s->createTable('stb_preferences');
            $t->addColumn('tree_id', 'string', ['length' => 36]);
            $t->addColumn('user_id', 'string', ['length' => 64]);
            $t->addColumn('data_json', 'text');
            $t->setPrimaryKey(['tree_id', 'user_id']);
            $t->addIndex(['user_id'], 'stb_pref_user');
        }
        return $s;
    }
}

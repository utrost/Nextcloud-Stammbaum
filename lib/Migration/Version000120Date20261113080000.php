<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Private connection identities and lifecycle fields shared by the three adapters. */
final class Version000120Date20261113080000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_connection_actors')) {
            $t = $s->createTable('stb_connection_actors');
            $t->addColumn('actor_id', 'string', ['length' => 64]);
            $t->addColumn('incarnation', 'string', ['length' => 36]);
            $t->addColumn('created_at', 'bigint');
            $t->setPrimaryKey(['actor_id']);
        }
        foreach (['stb_task_links', 'stb_calendar_links', 'stb_contact_links'] as $name) {
            if (!$s->hasTable($name)) {
                continue;
            }
            $t = $s->getTable($name);
            if (!$t->hasColumn('account_incarnation')) {
                $t->addColumn('account_incarnation', 'string', ['length' => 36, 'notnull' => false]);
            }
            if (!$t->hasColumn('destination_etag')) {
                $t->addColumn('destination_etag', 'string', ['length' => 128, 'notnull' => false]);
            }
            if (!$t->hasColumn('dependency_digest')) {
                $t->addColumn('dependency_digest', 'string', ['length' => 64, 'notnull' => false]);
            }
            if ($name === 'stb_contact_links') {
                if (!$t->hasColumn('status')) {
                    $t->addColumn('status', 'string', ['length' => 24, 'default' => 'linked']);
                }
                if (!$t->hasColumn('source_revision')) {
                    $t->addColumn('source_revision', 'integer', ['default' => 0]);
                }
                if (!$t->hasColumn('updated_at')) {
                    $t->addColumn('updated_at', 'bigint', ['default' => 0]);
                }
            }
            $t->addIndex(['actor_id', 'tree_id', 'created_at'], 'stb_' . substr($name, 4, 4) . '_owner_page');
        }
        return $s;
    }
}

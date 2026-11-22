<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

final class Version000123Date20261122120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('stb_tool_credentials')) {
            $table = $schema->createTable('stb_tool_credentials');
            $table->addColumn('id', 'string', ['length' => 36]);
            $table->addColumn('actor_id', 'string', ['length' => 64]);
            $table->addColumn('label', 'string', ['length' => 100]);
            $table->addColumn('secret_hash', 'string', ['length' => 64]);
            $table->addColumn('scopes_json', 'text');
            $table->addColumn('grants_json', 'text');
            $table->addColumn('created_at', 'bigint');
            $table->addColumn('expires_at', 'bigint');
            $table->addColumn('revoked_at', 'bigint', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['secret_hash'], 'stb_tool_secret');
            $table->addIndex(['actor_id', 'revoked_at', 'expires_at'], 'stb_tool_actor');
        }
        if (!$schema->hasTable('stb_tool_issue_limits')) {
            $table = $schema->createTable('stb_tool_issue_limits');
            $table->addColumn('actor_id', 'string', ['length' => 64]);
            $table->addColumn('window_start', 'bigint');
            $table->addColumn('attempts', 'integer');
            $table->setPrimaryKey(['actor_id']);
        }
        return $schema;
    }
}

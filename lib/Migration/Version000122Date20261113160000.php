<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

final class Version000122Date20261113160000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('stb_media_matches')) {
            $table = $schema->createTable('stb_media_matches');
            $table->addColumn('record_id', 'string', ['length' => 36]);
            $table->addColumn('field_index', 'integer');
            $table->addColumn('media_id', 'string', ['length' => 36]);
            $table->addColumn('actor_id', 'string', ['length' => 64]);
            $table->addColumn('created_at', 'bigint');
            $table->setPrimaryKey(['record_id', 'field_index']);
            $table->addIndex(['media_id'], 'stb_match_media');
        }
        return $schema;
    }
}

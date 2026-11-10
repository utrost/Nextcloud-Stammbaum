<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

final class Version000118Date20261110110000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_contact_links')) {
            $t = $s->createTable('stb_contact_links');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('tree_id', 'string', ['length' => 36]);
            $t->addColumn('person_id', 'string', ['length' => 36]);
            $t->addColumn('actor_id', 'string', ['length' => 64]);
            $t->addColumn('book_key', 'string', ['length' => 128]);
            $t->addColumn('card_uri', 'string', ['length' => 255]);
            $t->addColumn('card_uid', 'string', ['length' => 255]);
            $t->addColumn('card_hash', 'string', ['length' => 64]);
            $t->addColumn('request_id', 'string', ['length' => 80]);
            $t->addColumn('member_revision', 'integer');
            $t->addColumn('created_at', 'bigint');
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['actor_id', 'tree_id', 'request_id'], 'stb_contact_request');
            $t->addIndex(['actor_id', 'tree_id', 'person_id', 'created_at'], 'stb_contact_person');
        }
        return $s;
    }
}

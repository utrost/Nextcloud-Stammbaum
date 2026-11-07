<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000103Date20261106152000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        if (!$s->hasTable('stb_media')) {
            $t = $s->createTable('stb_media');
            foreach (
                [
                    'id' => 36,
                    'tree_id' => 36,
                    'original_name' => 250,
                    'title' => 250,
                    'mime' => 64,
                    'sha256' => 64,
                    'file_key' => 64,
                    'visibility' => 16,
                    'status' => 16,
                    'actor_id' => 64,
                ]
                as $n => $l
            ) {
                $t->addColumn($n, 'string', ['length' => $l]);
            }
            foreach (['size', 'created_at', 'updated_at'] as $n) {
                $t->addColumn($n, 'bigint');
            }
            foreach (['revision' => 1, 'width' => 0, 'height' => 0] as $n => $v) {
                $t->addColumn($n, 'integer', ['default' => $v]);
            }
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tree_id', 'status', 'visibility'], 'stb_media_tree');
        }
        if (!$s->hasTable('stb_media_people')) {
            $t = $s->createTable('stb_media_people');
            foreach (['id' => 36, 'media_id' => 36, 'person_id' => 36, 'purpose' => 16] as $n => $l) {
                $t->addColumn($n, 'string', ['length' => $l]);
            }
            $t->addColumn('crop_json', 'text');
            $t->addColumn('preferred', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['media_id', 'person_id'], 'stb_media_person_unique');
            $t->addIndex(['person_id', 'preferred'], 'stb_portrait_person');
        }
        return $s;
    }
}

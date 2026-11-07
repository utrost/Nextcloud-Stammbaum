<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version000100Date20261106140000 extends SimpleMigrationStep
{
    public function __construct(private IDBConnection $db) {}
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $s = $schemaClosure();
        $definitions = [
            'stb_state' => ['id' => ['integer', []], 'guard_value' => ['bigint', ['default' => 0]]],
            'stb_trees' => [
                'id' => ['string', ['length' => 36]],
                'name' => ['string', ['length' => 200]],
                'description' => ['text', []],
                'revision' => ['integer', ['default' => 1]],
                'home_id' => ['string', ['length' => 36, 'notnull' => false]],
                'created_at' => ['bigint', []],
                'updated_at' => ['bigint', []],
            ],
            'stb_members' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'user_id' => ['string', ['length' => 64]],
                'role' => ['string', ['length' => 16]],
                'export_allowed' => ['integer', ['default' => 0]],
                'active' => ['integer', ['default' => 1]],
                'revision' => ['integer', ['default' => 1]],
            ],
            'stb_people' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'given_name' => ['string', ['length' => 200]],
                'family_name' => ['string', ['length' => 200]],
                'search_text' => ['text', []],
                'data_json' => ['text', []],
                'visibility' => ['string', ['length' => 16]],
                'revision' => ['integer', ['default' => 1]],
                'deleted' => ['integer', ['default' => 0]],
                'created_at' => ['bigint', []],
                'updated_at' => ['bigint', []],
            ],
            'stb_claims' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'person_id' => ['string', ['length' => 36]],
                'kind' => ['string', ['length' => 32]],
                'value_json' => ['text', []],
                'assessment' => ['string', ['length' => 16]],
                'visibility' => ['string', ['length' => 16]],
                'preferred' => ['integer', ['default' => 0]],
                'created_at' => ['bigint', []],
                'actor_id' => ['string', ['length' => 64]],
            ],
            'stb_relations' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'source_id' => ['string', ['length' => 36]],
                'target_id' => ['string', ['length' => 36]],
                'kind' => ['string', ['length' => 16]],
                'parent_type' => ['string', ['length' => 16]],
                'revision' => ['integer', ['default' => 1]],
                'active' => ['integer', ['default' => 1]],
                'created_at' => ['bigint', []],
            ],
            'stb_history' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'actor_id' => ['string', ['length' => 64]],
                'action' => ['string', ['length' => 64]],
                'subject_id' => ['string', ['length' => 36, 'notnull' => false]],
                'data_json' => ['text', []],
                'visibility' => ['string', ['length' => 16]],
                'created_at' => ['bigint', []],
            ],
            'stb_receipts' => [
                'id' => ['string', ['length' => 64]],
                'payload_hash' => ['string', ['length' => 64]],
                'result_id' => ['string', ['length' => 36]],
                'created_at' => ['bigint', []],
            ],
            'stb_invites' => [
                'id' => ['string', ['length' => 36]],
                'tree_id' => ['string', ['length' => 36]],
                'token_hash' => ['string', ['length' => 64]],
                'issuer_id' => ['string', ['length' => 64]],
                'issuer_revision' => ['integer', []],
                'role' => ['string', ['length' => 16]],
                'export_allowed' => ['integer', ['default' => 0]],
                'expires_at' => ['bigint', []],
                'used' => ['integer', ['default' => 0]],
            ],
        ];
        foreach ($definitions as $name => $columns) {
            if ($s->hasTable($name)) {
                continue;
            }
            $t = $s->createTable($name);
            foreach ($columns as $key => [$type, $opts]) {
                $t->addColumn($key, $type, $opts);
            }
            $t->setPrimaryKey(['id']);
            if (isset($columns['tree_id'])) {
                $t->addIndex(['tree_id'], $name . '_tree');
            }
            if ($name === 'stb_members') {
                $t->addUniqueIndex(['tree_id', 'user_id'], 'stb_member_unique');
                $t->addIndex(['user_id', 'active'], 'stb_member_user');
            }
            if ($name === 'stb_people') {
                $t->addIndex(['tree_id', 'deleted', 'visibility'], 'stb_people_visible');
            }
            if ($name === 'stb_claims') {
                $t->addIndex(['person_id', 'kind', 'preferred'], 'stb_claim_person');
            }
            if ($name === 'stb_relations') {
                $t->addIndex(['tree_id', 'active', 'source_id'], 'stb_rel_source');
                $t->addIndex(['tree_id', 'active', 'target_id'], 'stb_rel_target');
            }
            if ($name === 'stb_history') {
                $t->addIndex(['tree_id', 'subject_id', 'created_at'], 'stb_history_subject');
            }
            if ($name === 'stb_invites') {
                $t->addUniqueIndex(['token_hash'], 'stb_invite_token');
            }
        }
        return $s;
    }
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        if (!$this->db->executeQuery('SELECT id FROM *PREFIX*stb_state WHERE id = 1')->fetchOne()) {
            $this->db->executeStatement('INSERT INTO *PREFIX*stb_state (id,guard_value) VALUES (1,0)');
        }
    }
}

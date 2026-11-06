<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\Files\{IAppData, NotFoundException};
use OCP\Files\SimpleFS\ISimpleFolder;

final class Imports
{
    private const RETAINED_BYTES = 536870912;
    private const TERMINAL = ['committed', 'cancelled'];
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IAppData $appData,
        private Gedcom $parser,
    ) {}
    private function folder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder('imports');
        } catch (NotFoundException) {
            return $this->appData->newFolder('imports');
        }
    }
    private function row(string $tree, string $id, string $actor): array
    {
        $this->trees->access($tree, $actor, 'manager');
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_imports WHERE tree_id = ? AND id = ?', [
            $tree,
            $id,
        ]);
        if (!$r) {
            throw Problem::missing();
        }
        return $r;
    }
    private function dto(array $r): array
    {
        return [
            'id' => $r['id'],
            'treeId' => $r['tree_id'],
            'profile' => $r['profile'],
            'status' => $r['status'],
            'revision' => (int) $r['revision'],
            'treeRevision' => (int) $r['tree_revision'],
            'originalName' => $r['original_name'],
            'size' => (int) $r['size'],
            'sha256' => $r['sha256'],
            'planHash' => $r['plan_hash'],
            'encoding' => $r['encoding'],
            'failureCode' => $r['failure_code'],
            'createdAt' => (int) $r['created_at'],
            'updatedAt' => (int) $r['updated_at'],
            'report' => json_decode($r['report_json'], true),
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        return $this->dto($this->row($tree, $id, $actor));
    }
    public function list(string $tree, string $actor, int $page = 0): array
    {
        $this->trees->access($tree, $actor, 'manager');
        $page = max(0, min(10000, $page));
        $rows = $this->store->rows(
            'SELECT id, tree_id, profile, status, revision, tree_revision, original_name, size, sha256, plan_hash, encoding, failure_code, created_at, updated_at, report_json FROM *PREFIX*stb_imports WHERE tree_id = ? ORDER BY created_at DESC, id DESC LIMIT 26 OFFSET ' .
                $page * 25,
            [$tree],
        );
        return [
            'items' => array_map($this->dto(...), array_slice($rows, 0, 25)),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    public function structure(string $tree, string $id, string $actor, int $page = 0): array
    {
        $this->row($tree, $id, $actor);
        $page = max(0, min(1000, $page));
        $rows = $this->store->rows(
            'SELECT data_json FROM *PREFIX*stb_import_nodes WHERE run_id = ? ORDER BY ordinal LIMIT 101 OFFSET ' .
                $page * 100,
            [$id],
        );
        return [
            'items' => array_map(fn($r) => json_decode($r['data_json'], true), array_slice($rows, 0, 100)),
            'nextPage' => count($rows) > 100 ? $page + 1 : null,
        ];
    }
    private function emptyTree(string $tree): void
    {
        // Tombstones and standalone sources also make this a populated research tree.
        foreach (
            ['people', 'claims', 'relations', 'sources', 'citations', 'records', 'media', 'contexts']
            as $table
        ) {
            if (
                $this->store->one('SELECT id FROM *PREFIX*stb_' . $table . ' WHERE tree_id = ? LIMIT 1', [
                    $tree,
                ])
            ) {
                throw new Problem(
                    'tree_not_empty',
                    'Choose an empty tree. Import into an existing research tree is not supported.',
                    409,
                );
            }
        }
    }
    private function authority(array $r, bool $confirm = false): array
    {
        $prefix = $confirm ? 'confirmer' : 'actor';
        $m = $this->trees->access($r['tree_id'], $r[$prefix . '_id'], 'manager');
        if ((int) $m['revision'] !== (int) $r[$prefix . '_revision']) {
            throw new Problem(
                'authority_changed',
                'The initiating permission changed. This run cannot continue.',
                409,
            );
        }
        return $m;
    }
    private function bytes(array $r): string
    {
        $bytes = $this->folder()->getFile($r['id'])->getContent();
        if (strlen($bytes) !== (int) $r['size'] || !hash_equals($r['sha256'], hash('sha256', $bytes))) {
            throw new Problem('original_mismatch', 'The original failed its integrity check.', 409);
        }
        return $bytes;
    }
    public function original(string $tree, string $id, string $actor): array
    {
        $r = $this->row($tree, $id, $actor);
        return ['bytes' => $this->bytes($r), 'name' => $r['original_name']];
    }
    public function upload(string $tree, string $actor, array $file): array
    {
        $this->trees->access($tree, $actor, 'manager');
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($file['tmp_name'] ?? '')
        ) {
            throw new Problem('upload_failed', 'The upload was not received completely.');
        }
        $size = filesize($file['tmp_name']);
        if (!$size || $size > Gedcom::MAX_BYTES) {
            throw new Problem('import_size', 'Choose a GEDCOM file up to 8 MiB.', 413);
        }
        $bytes = file_get_contents($file['tmp_name']);
        $hash = hash('sha256', $bytes);
        $name = Values::text(
            preg_replace('/[\x00-\x1F\x7F]/', '_', basename(str_replace('\\', '/', (string) $file['name']))),
            'filename',
            250,
            true,
        );
        $created = false;
        $id = $this->store->write(function () use ($tree, $actor, $hash, $size, $name, &$created) {
            $m = $this->trees->access($tree, $actor, 'manager');
            $old = $this->store->one(
                'SELECT id FROM *PREFIX*stb_imports WHERE tree_id = ? AND sha256 = ? AND profile = ?',
                [$tree, $hash, Gedcom::PROFILE],
            );
            if ($old) {
                return $old['id'];
            }
            $this->emptyTree($tree);
            $rate = $this->store->one(
                'SELECT COUNT(*) AS total FROM *PREFIX*stb_imports WHERE actor_id = ? AND created_at > ?',
                [$actor, time() - 3600],
            );
            if ((int) $rate['total'] >= 5) {
                throw new Problem(
                    'import_rate',
                    'Only five new import runs per account per hour are allowed.',
                    429,
                );
            }
            $pending = $this->store->one(
                "SELECT COUNT(*) AS total FROM *PREFIX*stb_imports WHERE status IN ('uploading','queued','analyzing','ready','commit_queued','activating')",
            );
            if ((int) $pending['total'] >= 20) {
                throw new Problem(
                    'import_capacity',
                    'The installation has twenty pending imports. Finish or cancel a run first.',
                    429,
                );
            }
            $used = $this->store->one('SELECT COALESCE(SUM(size),0) AS total FROM *PREFIX*stb_imports');
            if ((int) $used['total'] + $size > self::RETAINED_BYTES) {
                throw new Problem('import_quota', 'The 512 MiB retained-import allowance is full.', 507);
            }
            $t = $this->trees->get($tree, $actor);
            $id = Store::id();
            $this->store->insert('stb_imports', [
                'id' => $id,
                'tree_id' => $tree,
                'actor_id' => $actor,
                'actor_revision' => (int) $m['revision'],
                'tree_revision' => $t['revision'],
                'profile' => Gedcom::PROFILE,
                'sha256' => $hash,
                'original_name' => $name,
                'size' => $size,
                'status' => 'uploading',
                'report_json' => '{}',
                'plan_json' => '{}',
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $created = true;
            return $id;
        });
        // Deduplication includes interrupted/failed/cancelled runs; it never rewrites an original.
        if (!$created) {
            return $this->get($tree, $id, $actor);
        }
        try {
            $stored = $this->folder()->newFile($id);
            $stored->putContent($bytes);
            $r = $this->row($tree, $id, $actor);
            $this->bytes($r);
            $this->store->write(function () use ($tree, $id, $actor) {
                $r = $this->row($tree, $id, $actor);
                $this->authority($r);
                if ($r['status'] !== 'uploading') {
                    return;
                }
                $this->store->execute(
                    "UPDATE *PREFIX*stb_imports SET status = 'queued', revision = revision + 1, updated_at = ? WHERE id = ?",
                    [time(), $id],
                );
            });
        } catch (\Throwable $e) {
            $this->failRun($id, '', $e instanceof Problem ? $e->errorCode : 'original_storage');
            throw $e;
        }
        return $this->get($tree, $id, $actor);
    }
    public function cancel(string $tree, string $id, string $actor, array $body): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $body) {
            $r = $this->row($tree, $id, $actor);
            if ($r['status'] === 'cancelled') {
                return $this->dto($r);
            }
            $this->store->version($r, $body['revision'] ?? null);
            if ($r['status'] === 'committed') {
                throw new Problem('import_committed', 'A completed import cannot be cancelled.', 409);
            }
            $this->store->execute(
                "UPDATE *PREFIX*stb_imports SET status = 'cancelled', lease_token = '', lease_until = 0, revision = revision + 1, updated_at = ? WHERE id = ?",
                [time(), $id],
            );
            $this->store->audit($tree, $actor, 'import_cancelled', null, ['runId' => $id], 'management');
            return $this->get($tree, $id, $actor);
        });
    }
    public function confirm(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $r = $this->row($tree, $id, $actor);
            $m = $this->trees->access($tree, $actor, 'manager');
            if (
                ($b['sha256'] ?? null) !== $r['sha256'] ||
                ($b['planHash'] ?? null) !== $r['plan_hash'] ||
                ($b['treeRevision'] ?? null) !== (int) $r['tree_revision'] ||
                ($b['acknowledgeReview'] ?? false) !== true
            ) {
                throw new Problem(
                    'review_binding',
                    'Review this exact file and result before confirming.',
                    409,
                );
            }
            if (in_array($r['status'], ['commit_queued', 'activating', 'committed'], true)) {
                return $this->dto($r);
            }
            $this->store->version($r, $b['revision'] ?? null);
            if ($r['status'] !== 'ready') {
                throw new Problem(
                    'import_not_ready',
                    'Only a successfully reviewed import can be activated.',
                    409,
                );
            }
            $this->emptyTree($tree);
            $this->store->version($this->trees->get($tree, $actor), (int) $r['tree_revision']);
            $this->store->execute(
                "UPDATE *PREFIX*stb_imports SET status = 'commit_queued', confirmer_id = ?, confirmer_revision = ?, attempts = 0, revision = revision + 1, updated_at = ? WHERE id = ?",
                [$actor, (int) $m['revision'], time(), $id],
            );
            return $this->get($tree, $id, $actor);
        });
    }
    private function failRun(string $id, string $token, string $code): void
    {
        $this->store->write(function () use ($id, $token, $code) {
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_imports WHERE id = ?', [$id]);
            if (!$r || in_array($r['status'], self::TERMINAL, true) || $r['lease_token'] !== $token) {
                return;
            }
            $this->store->execute(
                "UPDATE *PREFIX*stb_imports SET status = 'failed', failure_code = ?, lease_token = '', lease_until = 0, revision = revision + 1, updated_at = ? WHERE id = ?",
                [$code, time(), $id],
            );
        });
    }
    /** One bounded job per invocation; leases fence old workers, transactions fence publication. */
    public function work(): ?string
    {
        $job = $this->store->write(function () {
            $now = time();
            $this->store->execute(
                "UPDATE *PREFIX*stb_imports SET status = 'failed', failure_code = 'upload_interrupted', revision = revision + 1, updated_at = ? WHERE status = 'uploading' AND created_at < ?",
                [$now, $now - 1800],
            );
            foreach (
                $this->store->rows(
                    "SELECT * FROM *PREFIX*stb_imports WHERE status IN ('analyzing','activating') AND lease_until < ?",
                    [$now],
                )
                as $r
            ) {
                $status =
                    (int) $r['attempts'] >= 3
                        ? 'failed'
                        : ($r['status'] === 'analyzing'
                            ? 'queued'
                            : 'commit_queued');
                $this->store->execute(
                    'UPDATE *PREFIX*stb_imports SET status = ?, failure_code = ?, lease_token = ?, lease_until = 0, revision = revision + 1, updated_at = ? WHERE id = ?',
                    [$status, $status === 'failed' ? 'worker_interrupted' : '', '', $now, $r['id']],
                );
            }
            $r = $this->store->one(
                "SELECT * FROM *PREFIX*stb_imports WHERE status IN ('queued','commit_queued') ORDER BY created_at, id LIMIT 1",
            );
            if (!$r) {
                return null;
            }
            try {
                $this->authority($r, $r['status'] === 'commit_queued');
            } catch (Problem $e) {
                $this->store->execute(
                    "UPDATE *PREFIX*stb_imports SET status = 'failed', failure_code = ?, revision = revision + 1, updated_at = ? WHERE id = ?",
                    [$e->errorCode, $now, $r['id']],
                );
                return ['id' => $r['id'], 'rejected' => true];
            }
            $r['lease_token'] = Store::id();
            $r['status'] = $r['status'] === 'queued' ? 'analyzing' : 'activating';
            $this->store->execute(
                'UPDATE *PREFIX*stb_imports SET status = ?, lease_token = ?, lease_until = ?, attempts = attempts + 1, revision = revision + 1, updated_at = ? WHERE id = ?',
                [$r['status'], $r['lease_token'], $now + 300, $now, $r['id']],
            );
            return $r;
        });
        if (!$job) {
            return null;
        }
        if ($job['rejected'] ?? false) {
            return $job['id'];
        }
        try {
            $this->authority($job, $job['status'] === 'activating');
            if ($job['profile'] !== Gedcom::PROFILE) {
                throw new Problem('profile_unavailable', 'This import profile is not available.', 409);
            }
            $bytes = $this->bytes($job);
            if ($job['status'] === 'analyzing') {
                $result = $this->parser->analyze($bytes);
                $this->store->write(function () use ($job, $result) {
                    $r = $this->leased($job);
                    if (!$r) {
                        return;
                    }
                    $this->authority($r);
                    foreach ($result['nodes'] as $node) {
                        $this->store->insert('stb_import_nodes', [
                            'run_id' => $r['id'],
                            'ordinal' => $node['ordinal'],
                            'data_json' => Store::json($node),
                        ]);
                    }
                    $this->store->execute(
                        'UPDATE *PREFIX*stb_imports SET status = ?, encoding = ?, report_json = ?, plan_json = ?, plan_hash = ?, lease_token = ?, lease_until = 0, revision = revision + 1, updated_at = ? WHERE id = ?',
                        [
                            $result['report']['errors'] ? 'blocked' : 'ready',
                            $result['encoding'],
                            Store::json($result['report']),
                            Store::json($result['plan']),
                            $result['planHash'],
                            '',
                            time(),
                            $r['id'],
                        ],
                    );
                });
            } else {
                $this->store->write(function () use ($job) {
                    $r = $this->leased($job);
                    if (!$r) {
                        return;
                    }
                    $m = $this->authority($r, true);
                    $tree = $r['tree_id'];
                    $this->emptyTree($tree);
                    $this->store->version(
                        $this->trees->get($tree, $r['confirmer_id']),
                        (int) $r['tree_revision'],
                    );
                    $plan = json_decode($r['plan_json'], true, 512, JSON_THROW_ON_ERROR);
                    $report = json_decode($r['report_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (
                        ($report['errors'] ?? 1) !== 0 ||
                        !hash_equals(
                            $r['plan_hash'],
                            hash('sha256', Store::json([$r['profile'], $plan, $report])),
                        )
                    ) {
                        throw new Problem(
                            'plan_mismatch',
                            'The reviewed plan failed its integrity check.',
                            409,
                        );
                    }
                    $this->activate($r, $plan, $m);
                    $this->store->execute(
                        "UPDATE *PREFIX*stb_imports SET status = 'committed', lease_token = '', lease_until = 0, revision = revision + 1, updated_at = ? WHERE id = ?",
                        [time(), $r['id']],
                    );
                });
            }
        } catch (\Throwable $e) {
            // Never persist exception text: it can contain input values, SQL or private paths.
            $this->failRun(
                $job['id'],
                $job['lease_token'],
                $e instanceof Problem ? $e->errorCode : 'worker_failed',
            );
        }
        return $job['id'];
    }
    private function leased(array $job): ?array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_imports WHERE id = ?', [$job['id']]);
        return $r &&
            $r['status'] === $job['status'] &&
            $r['lease_token'] === $job['lease_token'] &&
            (int) $r['lease_until'] >= time()
            ? $r
            : null;
    }
    private function mapping(string $run, int $line, string $kind, string $id): void
    {
        $this->store->insert('stb_import_maps', [
            'id' => Store::id(),
            'run_id' => $run,
            'ordinal' => $line,
            'kind' => $kind,
            'object_id' => $id,
        ]);
    }
    /** Only called under Store's mutation guard after current authority and review checks. */
    private function activate(array $run, array $plan, array $membership): void
    {
        $tree = $run['tree_id'];
        $actor = $run['confirmer_id'];
        $ids = [];
        $now = time();
        foreach ($plan['people'] as $xref => $p) {
            $id = $this->people->createInside(
                $tree,
                $actor,
                $p['data'] + ['confirmUnnamed' => true],
                $membership,
            );
            $ids['person:' . $xref] = $id;
            $this->mapping($run['id'], $p['line'], 'person', $id);
            foreach (
                $this->store->rows('SELECT id, kind FROM *PREFIX*stb_claims WHERE person_id = ?', [$id])
                as $claim
            ) {
                $ids['claim:' . $xref . ':' . $claim['kind']] = $claim['id'];
                $this->mapping(
                    $run['id'],
                    $p['claimLines'][$claim['kind']] ?? $p['line'],
                    'claim',
                    $claim['id'],
                );
            }
        }
        foreach ($plan['sources'] as $xref => $s) {
            $id = Store::id();
            $ids['source:' . $xref] = $id;
            $d = $s['data'];
            $this->store->insert('stb_sources', [
                'id' => $id,
                'tree_id' => $tree,
                'title' => $d['title'],
                'search_title' => mb_strtolower($d['title']),
                'data_json' => Store::json($d),
                'visibility' => $s['visibility'],
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->mapping($run['id'], $s['line'], 'source', $id);
        }
        foreach ($plan['relations'] as $key => $r) {
            $id = Store::id();
            $ids['relation:' . $key] = $id;
            $source = $ids['person:' . $r['source']];
            $target = $ids['person:' . $r['target']];
            if ($r['kind'] === 'partner' && strcmp($source, $target) > 0) {
                [$source, $target] = [$target, $source];
            }
            $this->store->insert('stb_relations', [
                'id' => $id,
                'tree_id' => $tree,
                'source_id' => $source,
                'target_id' => $target,
                'kind' => $r['kind'],
                'parent_type' => $r['parentType'],
                'revision' => 1,
                'active' => 1,
                'created_at' => $now,
            ]);
            $this->mapping($run['id'], $r['line'], 'relation', $id);
        }
        foreach ($plan['contexts'] as $c) {
            $id = Store::id();
            $ids['context:' . $c['line']] = $id;
            $this->store->insert('stb_contexts', [
                'id' => $id,
                'tree_id' => $tree,
                'label' => 'Imported family',
                'visibility' => $c['visibility'],
                'relation_ids' => Store::json(
                    array_map(fn($key) => $ids['relation:' . $key], $c['relations']),
                ),
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $position = 0;
            foreach (['parents' => 'parent', 'children' => 'child'] as $group => $role) {
                foreach ($c[$group] as $xref) {
                    $member = Store::id();
                    $this->store->insert('stb_context_people', [
                        'id' => $member,
                        'context_id' => $id,
                        'person_id' => $ids['person:' . $xref],
                        'role' => $role,
                        'description' => '',
                        'position' => $position++,
                    ]);
                    $this->mapping($run['id'], $c['line'], 'context_member', $member);
                }
            }
            $this->mapping($run['id'], $c['line'], 'context', $id);
        }
        foreach ($plan['records'] as $r) {
            $id = Store::id();
            $ids['record:' . $r['line']] = $id;
            $participants = array_map(
                fn($xref) => ['personId' => $ids['person:' . $xref], 'role' => 'subject'],
                $r['people'],
            );
            $this->store->insert('stb_records', [
                'id' => $id,
                'tree_id' => $tree,
                'origin_id' => $participants[0]['personId'],
                'category' => $r['category'],
                'record_type' => $r['type'],
                'visibility' => $r['visibility'],
                'data_json' => Store::json($r['data']),
                'original_json' => Store::json(
                    $r['data'] + ['participants' => $participants, 'visibility' => $r['visibility']],
                ),
                'revision' => 1,
                'preferred' => 0,
                'actor_id' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($participants as $position => $p) {
                $member = Store::id();
                $this->store->insert('stb_participants', [
                    'id' => $member,
                    'record_id' => $id,
                    'person_id' => $p['personId'],
                    'role' => $p['role'],
                    'position' => $position,
                ]);
                $this->mapping($run['id'], $r['line'], 'participant', $member);
            }
            $this->mapping($run['id'], $r['line'], 'record', $id);
        }
        foreach ($plan['citations'] as $c) {
            $id = Store::id();
            $target = $ids[$c['target']] ?? null;
            $source = $ids['source:' . $c['source']] ?? null;
            if (!$target || !$source) {
                throw new Problem('mapping_missing', 'An evidence target is missing.', 409);
            }
            $this->store->insert('stb_citations', [
                'id' => $id,
                'tree_id' => $tree,
                'source_id' => $source,
                'data_json' => Store::json($c['data']),
                'visibility' => $c['visibility'],
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $targetId = Store::id();
            $this->store->insert('stb_targets', [
                'id' => $targetId,
                'citation_id' => $id,
                'kind' => explode(':', $c['target'])[0],
                'target_id' => $target,
                'effect' => 'reference',
            ]);
            $this->mapping($run['id'], $c['line'], 'citation', $id);
            $this->mapping($run['id'], $c['line'], 'citation_target', $targetId);
        }
        // Consolidate createInside's intermediate revisions/audits into one atomic import operation.
        $this->store->execute(
            "DELETE FROM *PREFIX*stb_history WHERE tree_id = ? AND action = 'person_created'",
            [$tree],
        );
        foreach ($plan['people'] as $xref => $person) {
            $this->store->audit(
                $tree,
                $actor,
                'person_imported',
                $ids['person:' . $xref],
                $person['data'] + ['importRunId' => $run['id'], 'importLine' => $person['line']],
                $person['data']['visibility'],
            );
        }
        $this->store->execute('UPDATE *PREFIX*stb_trees SET revision = ?, updated_at = ? WHERE id = ?', [
            (int) $run['tree_revision'] + 1,
            $now,
            $tree,
        ]);
        $this->store->audit(
            $tree,
            $actor,
            'gedcom_imported',
            null,
            ['runId' => $run['id'], 'sha256' => $run['sha256'], 'profile' => $run['profile']],
            'management',
        );
    }
}

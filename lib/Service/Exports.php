<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\Files\{IAppData, NotFoundException};
use OCP\Files\SimpleFS\ISimpleFolder;
final class Exports
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private GedcomExport $projection,
        private Media $media,
        private IAppData $appData,
    ) {}
    private function folder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder('exports-v1');
        } catch (NotFoundException) {
            return $this->appData->newFolder('exports-v1');
        }
    }
    private function authority(string $tree, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        if (!Trees::management($m) && !$m['export_allowed']) {
            throw new Problem('forbidden', 'Your tree role does not allow this action.', 403);
        }
        return $m;
    }
    private function options(array $b, array $m): array
    {
        $o = [];
        foreach (['living', 'management', 'media'] as $k) {
            $o[$k] = ($b[$k] ?? false) === true;
        }
        if ($o['management'] && !Trees::management($m)) {
            throw new Problem('forbidden', 'Your tree role does not allow this action.', 403);
        }
        return $o;
    }
    private function review(string $tree, string $actor, array $b): array
    {
        $m = $this->authority($tree, $actor);
        $o = $this->options($b, $m);
        $x = $this->projection->project($tree, $actor, $o);
        return [
            'revision' => $this->trees->get($tree, $actor)['revision'],
            'membershipRevision' => (int) $m['revision'],
            'options' => $o,
            'counts' => array_map('count', $x),
            'warnings' => GedcomExport::WARNINGS,
            'profile' => GedcomExport::PROFILE,
        ];
    }
    public function preview(string $tree, string $actor, array $b): array
    {
        return $this->store->write(fn() => $this->review($tree, $actor, $b));
    }
    public function create(string $tree, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $actor, $b) {
            $r = $this->review($tree, $actor, $b['options'] ?? []);
            if (($b['acknowledged'] ?? false) !== true) {
                throw new Problem('acknowledgement', 'Review and acknowledge the export limitations.');
            }
            if (
                ($b['revision'] ?? null) !== $r['revision'] ||
                ($b['membershipRevision'] ?? null) !== $r['membershipRevision']
            ) {
                throw new Problem(
                    'conflict',
                    'The tree or your access changed. Review the export again.',
                    412,
                );
            }
            $id = $this->store->receipt(
                $actor,
                'export:' . $tree,
                (string) ($b['requestId'] ?? ''),
                [$r['revision'], $r['membershipRevision'], $r['options']],
                function () use ($tree, $actor, $r) {
                    $rate = $this->store->one(
                        'SELECT COUNT(*) AS n FROM *PREFIX*stb_exports WHERE actor_id = ? AND created_at > ?',
                        [$actor, time() - 3600],
                    );
                    $queue = $this->store->one(
                        "SELECT COUNT(*) AS n FROM *PREFIX*stb_exports WHERE state IN ('queued','running')",
                    );
                    if ((int) $rate['n'] >= 10 || (int) $queue['n'] >= 20) {
                        throw new Problem(
                            'export_busy',
                            'The export queue or hourly allowance is full. Try again later.',
                            429,
                        );
                    }
                    $id = Store::id();
                    $now = time();
                    $this->store->insert('stb_exports', [
                        'id' => $id,
                        'tree_id' => $tree,
                        'actor_id' => $actor,
                        'state' => 'queued',
                        'tree_revision' => $r['revision'],
                        'actor_revision' => $r['membershipRevision'],
                        'created_at' => $now,
                        'updated_at' => $now,
                        'expires_at' => $now + 86400,
                        'options_json' => Store::json($r['options']),
                        'report_json' => Store::json([
                            'warnings' => $r['warnings'],
                            'counts' => $r['counts'],
                            'profile' => $r['profile'],
                        ]),
                    ]);
                    return $id;
                },
            );
            return $this->get($tree, $id, $actor);
        });
    }
    private function valid(array $r): bool
    {
        try {
            $m = $this->authority($r['tree_id'], $r['actor_id']);
            return time() < (int) $r['expires_at'] &&
                (int) $m['revision'] === (int) $r['actor_revision'] &&
                $this->trees->get($r['tree_id'], $r['actor_id'])['revision'] === (int) $r['tree_revision'];
        } catch (Problem) {
            return false;
        }
    }
    private function row(string $tree, string $id, string $actor): array
    {
        $this->authority($tree, $actor);
        $r = $this->store->one(
            'SELECT * FROM *PREFIX*stb_exports WHERE tree_id = ? AND id = ? AND actor_id = ?',
            [$tree, $id, $actor],
        );
        if (!$r) {
            throw Problem::missing();
        }
        return $r;
    }
    private function dto(array $r): array
    {
        $state = $r['state'];
        if (in_array($state, ['queued', 'running', 'ready'], true) && !$this->valid($r)) {
            $state = time() >= (int) $r['expires_at'] ? 'expired' : 'invalid';
        }
        return [
            'id' => $r['id'],
            'state' => $state,
            'createdAt' => (int) $r['created_at'],
            'expiresAt' => (int) $r['expires_at'],
            'options' => json_decode($r['options_json'], true),
            'report' => in_array($state, ['queued', 'running', 'ready'], true)
                ? json_decode($r['report_json'], true)
                : null,
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        return $this->dto($this->row($tree, $id, $actor));
    }
    public function list(string $tree, string $actor, int $page = 0): array
    {
        $this->authority($tree, $actor);
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_exports WHERE tree_id = ? AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 26 OFFSET ' .
                max(0, min(10000, $page)) * 25,
            [$tree, $actor],
        );
        return [
            'items' => array_map(fn($r) => $this->dto($r), array_slice($rows, 0, 25)),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    public function cancel(string $tree, string $id, string $actor): array
    {
        return $this->store->write(function () use ($tree, $id, $actor) {
            $r = $this->row($tree, $id, $actor);
            if (!in_array($r['state'], ['queued', 'running'], true)) {
                throw new Problem('export_state', 'This export cannot be cancelled.', 409);
            }
            $this->store->execute(
                "UPDATE *PREFIX*stb_exports SET state = 'cancelled', updated_at = ? WHERE id = ?",
                [time(), $id],
            );
            return $this->get($tree, $id, $actor);
        });
    }
    public function download(string $tree, string $id, string $actor, string $part): array
    {
        if (!in_array($part, ['gedcom', 'report', 'package'], true)) {
            throw Problem::missing();
        }
        return $this->store->write(function () use ($tree, $id, $actor, $part) {
            $r = $this->row($tree, $id, $actor);
            if ($r['state'] !== 'ready' || !$this->valid($r)) {
                throw new Problem(
                    'export_unavailable',
                    'This export is no longer available. Review and create a new export.',
                    409,
                );
            }
            $o = json_decode($r['options_json'], true);
            if ($part === 'package' && !$o['media']) {
                throw Problem::missing();
            }
            $ext = ['gedcom' => 'ged', 'report' => 'json', 'package' => 'zip'][$part];
            $bytes = $this->folder()
                ->getFile($id . '.' . $ext)
                ->getContent();
            return [
                'bytes' => $bytes,
                'name' => 'stammbaum.' . $ext,
                'mime' => [
                    'ged' => 'text/plain; charset=UTF-8',
                    'json' => 'application/json',
                    'zip' => 'application/zip',
                ][$ext],
            ];
        });
    }
    public function work(): ?string
    {
        $r = $this->store->write(function () {
            $this->store->execute(
                "UPDATE *PREFIX*stb_exports SET state = 'failed' WHERE state = 'running' AND updated_at < ?",
                [time() - 600],
            );
            foreach (
                $this->store->rows(
                    "SELECT * FROM *PREFIX*stb_exports WHERE state IN ('queued','running','ready') ORDER BY created_at LIMIT 100",
                )
                as $old
            ) {
                if (!$this->valid($old)) {
                    $this->store->execute('UPDATE *PREFIX*stb_exports SET state = ? WHERE id = ?', [
                        time() >= (int) $old['expires_at'] ? 'expired' : 'invalid',
                        $old['id'],
                    ]);
                }
            }
            $r = $this->store->one(
                "SELECT * FROM *PREFIX*stb_exports WHERE state = 'queued' ORDER BY created_at, id LIMIT 1",
            );
            if ($r) {
                $this->store->execute(
                    "UPDATE *PREFIX*stb_exports SET state = 'running', updated_at = ? WHERE id = ?",
                    [time(), $r['id']],
                );
            }
            return $r;
        });
        // Private generated artifacts are disposable; retained evidence is in other folders.
        foreach (
            $this->store->rows(
                "SELECT id FROM *PREFIX*stb_exports WHERE state IN ('expired','invalid','cancelled','failed') AND report_json <> '{}' ORDER BY created_at LIMIT 100",
            )
            as $old
        ) {
            foreach (['ged', 'json', 'zip'] as $ext) {
                $name = $old['id'] . '.' . $ext;
                if ($this->folder()->fileExists($name)) {
                    $this->folder()->getFile($name)->delete();
                }
            }
            $this->store->execute("UPDATE *PREFIX*stb_exports SET report_json = '{}' WHERE id = ?", [
                $old['id'],
            ]);
        }
        if (!$r) {
            return null;
        }
        $id = $r['id'];
        $temporary = null;
        try {
            $o = json_decode($r['options_json'], true);
            $x = $this->store->write(function () use ($r, $o) {
                if (!$this->valid($r)) {
                    throw Problem::missing();
                }
                return $this->projection->project($r['tree_id'], $r['actor_id'], $o);
            });
            $output = $this->projection->render($x);
            $files = ['tree.ged' => $output['gedcom'], 'report.json' => Store::json($output['report'])];
            $folder = $this->folder();
            $folder->newFile($id . '.ged')->putContent($files['tree.ged']);
            $folder->newFile($id . '.json')->putContent($files['report.json']);
            if ($o['media']) {
                $temporary = tempnam(sys_get_temp_dir(), 'stb-export-');
                chmod($temporary, 0600);
                $zip = new \ZipArchive();
                if ($zip->open($temporary, \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Export archive unavailable');
                }
                try {
                    $manifest = [];
                    foreach ($files as $name => $bytes) {
                        $zip->addFromString($name, $bytes);
                        $manifest[$name] = hash('sha256', $bytes);
                    }
                    foreach ($x['media'] as $mediaId => $v) {
                        if (!$this->valid($r)) {
                            throw Problem::missing();
                        }
                        $d = $this->media->download($r['tree_id'], $mediaId, $r['actor_id']);
                        $ext = match ($v['mime']) {
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            default => 'pdf',
                        };
                        $name = "media/$mediaId.$ext";
                        $zip->addFromString($name, $d['bytes']);
                        $manifest[$name] = hash('sha256', $d['bytes']);
                    }
                    $zip->addFromString('checksums.json', Store::json($manifest));
                } finally {
                    $zip->close();
                }
                $folder->newFile($id . '.zip')->putContent(file_get_contents($temporary));
            }
            $this->store->write(function () use ($r, $id) {
                $fresh = $this->store->one('SELECT state FROM *PREFIX*stb_exports WHERE id = ?', [$id]);
                if ($fresh['state'] !== 'running') {
                    return;
                }
                $this->store->execute(
                    'UPDATE *PREFIX*stb_exports SET state = ?, updated_at = ? WHERE id = ?',
                    [$this->valid($r) ? 'ready' : 'invalid', time(), $id],
                );
            });
        } catch (\Throwable $e) {
            $this->store->write(
                fn() => $this->store->execute(
                    "UPDATE *PREFIX*stb_exports SET state = 'failed', updated_at = ? WHERE id = ? AND state = 'running'",
                    [time(), $id],
                ),
            );
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            // Cancellation cleanup can race a worker that is still writing. The
            // worker also removes its own unpublished output after its last write.
            $fresh = $this->store->one('SELECT state FROM *PREFIX*stb_exports WHERE id = ?', [$id]);
            if (!$fresh || $fresh['state'] !== 'ready') {
                foreach (['ged', 'json', 'zip'] as $ext) {
                    $name = $id . '.' . $ext;
                    if ($this->folder()->fileExists($name)) {
                        $this->folder()->getFile($name)->delete();
                    }
                }
            }
        }
        return $id;
    }
}

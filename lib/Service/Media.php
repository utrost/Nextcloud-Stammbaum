<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\Files\{IAppData, NotFoundException};
use OCP\Files\SimpleFS\ISimpleFolder;
final class Media
{
    public const MAX_BYTES = 10485760,
        QUOTA = 1073741824;
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IAppData $appData,
    ) {}
    private function folder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder('originals');
        } catch (NotFoundException) {
            return $this->appData->newFolder('originals');
        }
    }
    private function row(string $tree, string $id, array $m, bool $pending = false): array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_media WHERE tree_id = ? AND id = ?', [$tree, $id]);
        if (
            !$r ||
            (!$pending && $r['status'] !== 'ready') ||
            ($r['visibility'] !== 'members' && !Trees::management($m))
        ) {
            throw Problem::missing();
        }
        $r['assignments'] = array_map(function ($a) use ($tree, $m) {
            $p = $this->people->dto($this->people->row($tree, $a['person_id'], $m));
            return [
                'personId' => $a['person_id'],
                'person' => $p,
                'purpose' => $a['purpose'],
                'crop' => json_decode($a['crop_json'], true),
                'preferred' => (bool) $a['preferred'],
            ];
        }, $this->store->rows(
            'SELECT * FROM *PREFIX*stb_media_people WHERE media_id = ? ORDER BY person_id',
            [$id],
        ));
        return $r;
    }
    private function dto(array $r): array
    {
        return [
            'id' => $r['id'],
            'title' => $r['title'],
            'originalName' => $r['original_name'],
            'mime' => $r['mime'],
            'size' => (int) $r['size'],
            'sha256' => $r['sha256'],
            'width' => (int) $r['width'],
            'height' => (int) $r['height'],
            'visibility' => $r['visibility'],
            'revision' => (int) $r['revision'],
            'assignments' => $r['assignments'],
            'createdAt' => (int) $r['created_at'],
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        return $this->dto($this->row($tree, $id, $this->trees->access($tree, $actor)));
    }
    public function list(string $tree, string $actor, string $person = '', int $page = 0): array
    {
        $m = $this->trees->access($tree, $actor);
        $params = [$tree];
        $where = "v.tree_id = ? AND v.status = 'ready'";
        if ($person !== '') {
            $this->people->row($tree, $person, $m);
            $where .=
                ' AND EXISTS (SELECT 1 FROM *PREFIX*stb_media_people a WHERE a.media_id = v.id AND a.person_id = ?)';
            $params[] = $person;
        }
        if (!Trees::management($m)) {
            $where .= " AND v.visibility = 'members'";
        }
        $where .=
            ' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_media_people a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.media_id = v.id AND (p.id IS NULL OR p.deleted = 1' .
            (!Trees::management($m) ? " OR p.visibility <> 'members'" : '') .
            '))';
        $rows = $this->store->rows(
            'SELECT v.id FROM *PREFIX*stb_media v WHERE ' .
                $where .
                ' ORDER BY v.created_at DESC, v.id DESC LIMIT 26 OFFSET ' .
                max(0, min(10000, $page)) * 25,
            $params,
        );
        return [
            'items' => array_map(fn($r) => $this->get($tree, $r['id'], $actor), array_slice($rows, 0, 25)),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    private function validateUpload(array $f): array
    {
        if (
            ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($f['tmp_name'] ?? '')
        ) {
            throw new Problem('upload_failed', 'The upload was not received completely.');
        }
        $size = filesize($f['tmp_name']);
        if (!$size || $size > self::MAX_BYTES) {
            throw new Problem('media_size', 'Choose a file up to 10 MiB.', 413);
        }
        $bytes = file_get_contents($f['tmp_name']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $width = 0;
        $height = 0;
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $info = @getimagesizefromstring($bytes);
            if (
                !$info ||
                $info['mime'] !== $mime ||
                $info[0] > 16000 ||
                $info[1] > 16000 ||
                $info[0] * $info[1] > 16000000
            ) {
                throw new Problem(
                    'image_dimensions',
                    'Images must have at most 16 million pixels and 16,000 pixels on either side.',
                );
            }
            $image = @imagecreatefromstring($bytes);
            if (!$image) {
                throw new Problem('invalid_image', 'This image cannot be decoded.');
            }
            imagedestroy($image);
            [$width, $height] = $info;
        } elseif ($mime === 'application/pdf') {
            if (!str_starts_with($bytes, '%PDF-') || !str_contains(substr($bytes, -2048), '%%EOF')) {
                throw new Problem('invalid_pdf', 'This file is not a complete PDF attachment.');
            }
        } else {
            throw new Problem(
                'media_type',
                'Choose a JPEG, PNG or PDF file. SVG and executable content are not accepted.',
            );
        }
        return [
            'bytes' => $bytes,
            'size' => $size,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $bytes),
            'name' => Values::text(
                preg_replace('/[\x00-\x1F\x7F]/', '_', basename(str_replace('\\', '/', (string) $f['name']))),
                'fileName',
                250,
                true,
            ),
        ];
    }
    public function upload(string $tree, string $actor, array $file, array $b): array
    {
        $this->trees->access($tree, $actor, 'editor');
        $f = $this->validateUpload($file);
        $id = $this->store->write(function () use ($tree, $actor, $f, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            if ($visibility !== 'members' && !Trees::management($m)) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            $title = Values::text($b['title'] ?? $f['name'], 'title', 250, true);
            $person = Values::text($b['personId'] ?? '', 'personId', 36);
            if ($person !== '') {
                $this->people->row($tree, $person, $m);
            }
            return $this->store->receipt(
                $actor,
                'media:' . $tree,
                (string) ($b['requestId'] ?? ''),
                [$f['sha256'], $f['size'], $f['name'], $title, $visibility, $person],
                function () use ($tree, $actor, $f, $title, $visibility, $person, $m) {
                    $used =
                        (int) ($this->store->one(
                            'SELECT COALESCE(SUM(size),0) AS total FROM *PREFIX*stb_media',
                        )['total'] ?? 0);
                    if ($used + $f['size'] > self::QUOTA) {
                        throw new Problem(
                            'media_quota',
                            'The installation’s 1 GiB original-media allowance is full.',
                            507,
                        );
                    }
                    $id = Store::id();
                    $this->store->insert('stb_media', [
                        'id' => $id,
                        'tree_id' => $tree,
                        'original_name' => $f['name'],
                        'title' => $title,
                        'mime' => $f['mime'],
                        'sha256' => $f['sha256'],
                        'file_key' => $id,
                        'visibility' => $visibility,
                        'status' => 'pending',
                        'actor_id' => $actor,
                        'actor_revision' => (int) $m['revision'],
                        'size' => $f['size'],
                        'created_at' => time(),
                        'updated_at' => time(),
                        'revision' => 1,
                        'width' => $f['width'],
                        'height' => $f['height'],
                    ]);
                    if ($person !== '') {
                        $this->store->insert('stb_media_people', [
                            'id' => Store::id(),
                            'media_id' => $id,
                            'person_id' => $person,
                            'purpose' => 'attachment',
                            'crop_json' => Store::json(['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]),
                            'preferred' => 0,
                        ]);
                    }
                    return $id;
                },
            );
        });
        $membership = $this->trees->access($tree, $actor, 'editor');
        $r = $this->row($tree, $id, $membership, true);
        if ($r['status'] !== 'ready' && (int) $r['actor_revision'] !== (int) $membership['revision']) {
            throw new Problem(
                'upload_authority_changed',
                'Your authority changed during this upload. Start a new upload.',
                409,
            );
        }
        if ($r['status'] === 'ready') {
            return $this->dto($r);
        }
        // Persist the reservation first: failed or interrupted writes remain accounted for.
        $folder = $this->folder();
        $stored = $folder->fileExists($r['file_key'])
            ? $folder->getFile($r['file_key'])
            : $folder->newFile($r['file_key']);
        $stored->putContent($f['bytes']);
        if (!hash_equals($r['sha256'], hash('sha256', $stored->getContent()))) {
            throw new Problem(
                'original_mismatch',
                'Original verification failed; the media has not been published.',
                500,
            );
        }
        return $this->store->write(function () use ($tree, $id, $actor) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m, true);
            if ($r['status'] !== 'ready' && (int) $r['actor_revision'] !== (int) $m['revision']) {
                throw new Problem(
                    'upload_authority_changed',
                    'Your authority changed during this upload. Start a new upload.',
                    409,
                );
            }
            if ($r['status'] !== 'ready') {
                $this->store->execute("UPDATE *PREFIX*stb_media SET status = 'ready' WHERE id = ?", [$id]);
                $this->store->bump($tree);
                $this->store->audit($tree, $actor, 'media_uploaded', null, ['mediaId' => $id], 'management');
            }
            return $this->get($tree, $id, $actor);
        });
    }
    public function save(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m);
            $this->store->version($r, $b['revision'] ?? null);
            $title = Values::text($b['title'] ?? '', 'title', 250, true);
            $visibility = Values::choice(
                $b['visibility'] ?? $r['visibility'],
                ['members', 'management'],
                'visibility',
            );
            if (!Trees::management($m) && $visibility !== $r['visibility']) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            if (
                $r['visibility'] === 'management' &&
                $visibility === 'members' &&
                ($b['confirmRelease'] ?? false) !== true
            ) {
                throw new Problem('release_confirmation', 'Confirm release of this media to tree members.');
            }
            $items = $b['assignments'] ?? [];
            if (!is_array($items) || !array_is_list($items) || count($items) > 32) {
                throw new Problem('assignments', 'Choose up to 32 person assignments.');
            }
            $clean = [];
            $seen = [];
            foreach ($items as $a) {
                if (!is_array($a)) {
                    throw new Problem('assignments', 'Provide valid assignments.');
                }
                $pid = Values::text($a['personId'] ?? '', 'personId', 36, true);
                if (isset($seen[$pid])) {
                    throw new Problem('duplicate_assignment', 'Each person may be assigned once.');
                }
                $seen[$pid] = true;
                $this->people->row($tree, $pid, $m);
                $purpose = Values::choice(
                    $a['purpose'] ?? 'attachment',
                    ['attachment', 'portrait'],
                    'purpose',
                );
                if ($purpose === 'portrait' && $r['mime'] === 'application/pdf') {
                    throw new Problem('portrait_image', 'Only an image can be a portrait.');
                }
                $crop = $a['crop'] ?? ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1];
                foreach (['x', 'y', 'width', 'height'] as $k) {
                    if (
                        !isset($crop[$k]) ||
                        !is_numeric($crop[$k]) ||
                        !is_finite((float) $crop[$k]) ||
                        $crop[$k] < 0 ||
                        $crop[$k] > 1
                    ) {
                        throw new Problem('crop', 'Choose a crop inside the image.');
                    }
                    $crop[$k] = (float) $crop[$k];
                }
                if (
                    $crop['width'] <= 0 ||
                    $crop['height'] <= 0 ||
                    $crop['x'] + $crop['width'] > 1.000001 ||
                    $crop['y'] + $crop['height'] > 1.000001
                ) {
                    throw new Problem('crop', 'Choose a nonempty crop inside the image.');
                }
                $preferred = ($a['preferred'] ?? false) === true && $purpose === 'portrait';
                if ($preferred) {
                    foreach (
                        $this->store->rows(
                            'SELECT media_id FROM *PREFIX*stb_media_people WHERE person_id = ? AND preferred = 1',
                            [$pid],
                        )
                        as $prior
                    ) {
                        $this->row($tree, $prior['media_id'], $m);
                        if ($prior['media_id'] !== $id) {
                            $this->store->execute(
                                'UPDATE *PREFIX*stb_media SET revision = revision + 1, updated_at = ? WHERE id = ?',
                                [time(), $prior['media_id']],
                            );
                        }
                    }
                    $this->store->execute(
                        'UPDATE *PREFIX*stb_media_people SET preferred = 0 WHERE person_id = ?',
                        [$pid],
                    );
                }
                $clean[] = [
                    'personId' => $pid,
                    'purpose' => $purpose,
                    'crop' => $crop,
                    'preferred' => $preferred,
                ];
            }
            $this->store->execute('DELETE FROM *PREFIX*stb_media_people WHERE media_id = ?', [$id]);
            foreach ($clean as $a) {
                $this->store->insert('stb_media_people', [
                    'id' => Store::id(),
                    'media_id' => $id,
                    'person_id' => $a['personId'],
                    'purpose' => $a['purpose'],
                    'crop_json' => Store::json($a['crop']),
                    'preferred' => $a['preferred'] ? 1 : 0,
                ]);
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_media SET title = ?, visibility = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                [$title, $visibility, time(), $id],
            );
            $this->store->bump($tree);
            $this->store->audit($tree, $actor, 'media_updated', null, ['mediaId' => $id], 'management');
            return $this->get($tree, $id, $actor);
        });
    }
    public function download(
        string $tree,
        string $id,
        string $actor,
        bool $preview = false,
        string $person = '',
    ): array {
        $r = $this->row($tree, $id, $this->trees->access($tree, $actor));
        $bytes = $this->folder()->getFile($r['file_key'])->getContent();
        if (!hash_equals($r['sha256'], hash('sha256', $bytes))) {
            throw new Problem('original_mismatch', 'The stored original failed its integrity check.', 500);
        }
        if (!$preview) {
            return ['bytes' => $bytes, 'name' => $r['original_name'], 'mime' => $r['mime']];
        }
        if ($r['mime'] === 'application/pdf') {
            throw new Problem('preview_unavailable', 'PDF attachments have no inline preview.', 404);
        }
        $im = imagecreatefromstring($bytes);
        if ($person !== '') {
            $a =
                array_values(array_filter($r['assignments'], fn($a) => $a['personId'] === $person))[0] ??
                null;
            if (!$a) {
                throw Problem::missing();
            }
            $c = $a['crop'];
            $crop = imagecrop($im, [
                'x' => (int) floor($c['x'] * imagesx($im)),
                'y' => (int) floor($c['y'] * imagesy($im)),
                'width' => max(1, (int) floor($c['width'] * imagesx($im))),
                'height' => max(1, (int) floor($c['height'] * imagesy($im))),
            ]);
            imagedestroy($im);
            $im = $crop;
        }
        $ratio = min(1, 600 / max(imagesx($im), imagesy($im)));
        $out = imagecreatetruecolor(
            max(1, (int) (imagesx($im) * $ratio)),
            max(1, (int) (imagesy($im) * $ratio)),
        );
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($im), imagesy($im));
        ob_start();
        imagepng($out);
        $bytes = ob_get_clean();
        imagedestroy($im);
        imagedestroy($out);
        return ['bytes' => $bytes, 'name' => 'preview.png', 'mime' => 'image/png'];
    }
}

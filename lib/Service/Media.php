<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\Files\{IAppData, IRootFolder, File, NotFoundException};
use OCP\Files\SimpleFS\ISimpleFolder;
final class Media
{
    public const MAX_BYTES = 10485760,
        QUOTA = 1073741824;
    private array $orientationCache = [];
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IAppData $appData,
        private IRootFolder $rootFolder,
        private PreviewCache $previews,
        private PdfPreview $pdf,
        private MediaMetadata $metadata,
        private Records $records,
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
        $r['assignments'] = array_map(function ($a) use ($tree, $m, $r) {
            $p = $this->people->dto($this->people->row($tree, $a['person_id'], $m));
            $crop = json_decode($a['crop_json'], true);
            if (!isset($crop['space']) && $r['mime'] === 'image/jpeg' && $r['status'] === 'ready') {
                $crop = ImageOrientation::crop($crop, $this->orientation($r));
            }
            unset($crop['space']);
            return [
                'personId' => $a['person_id'],
                'person' => $p,
                'purpose' => $a['purpose'],
                'crop' => $crop,
                'preferred' => (bool) $a['preferred'],
            ];
        }, $this->store->rows(
            'SELECT * FROM *PREFIX*stb_media_people WHERE media_id = ? ORDER BY person_id',
            [$id],
        ));
        $r['metadata'] = $this->metadata->read($tree, $r, $m);
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
            'metadata' => $r['metadata'],
            'createdAt' => (int) $r['created_at'],
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        return $this->dto($this->row($tree, $id, $this->trees->access($tree, $actor)));
    }
    public function list(
        string $tree,
        string $actor,
        string $person = '',
        int $page = 0,
        array $filters = [],
    ): array {
        $m = $this->trees->access($tree, $actor);
        $params = [$tree];
        $where = "v.tree_id = ? AND v.status = 'ready'";
        if ($person !== '') {
            $this->people->row($tree, $person, $m);
            $where .=
                " AND (EXISTS (SELECT 1 FROM *PREFIX*stb_media_people a WHERE a.media_id = v.id AND a.person_id = ?) OR EXISTS (SELECT 1 FROM *PREFIX*stb_media_refs x WHERE x.media_id = v.id AND x.kind = 'person' AND x.target_id = ?))";
            $params[] = $person;
            $params[] = $person;
        }
        if (!Trees::management($m)) {
            $where .= " AND v.visibility = 'members'";
        }
        $where .=
            ' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_media_people a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.media_id = v.id AND (p.id IS NULL OR p.deleted = 1' .
            (!Trees::management($m) ? " OR p.visibility <> 'members'" : '') .
            '))';
        $where .= MediaMetadata::visibleSql($m);
        $type = Values::choice($filters['type'] ?? '', ['', 'image', 'pdf'], 'type');
        if ($type !== '') {
            $where .=
                $type === 'pdf' ? " AND v.mime = 'application/pdf'" : " AND v.mime <> 'application/pdf'";
        }
        foreach (
            ['personQuery' => 'search_people', 'eventQuery' => 'search_event', 'place' => 'search_place']
            as $key => $column
        ) {
            $value = Values::text($filters[$key] ?? '', $key, 200);
            if ($value !== '') {
                $where .= " AND v.$column LIKE ? ESCAPE '!'";
                $params[] =
                    '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)) . '%';
            }
        }
        if (($filters['missing'] ?? false) === true) {
            $where .= ' AND v.metadata_complete = 0';
        }
        $event = Values::text($filters['event'] ?? '', 'event', 36);
        if ($event !== '') {
            // Authorize the context even when it contains no media.
            $this->metadata->clean($tree, ['eventId' => $event], $m);
            $where .=
                " AND EXISTS (SELECT 1 FROM *PREFIX*stb_media_refs x WHERE x.media_id = v.id AND x.kind = 'event' AND x.target_id = ?)";
            $params[] = $event;
        }
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
    public function trash(string $tree, string $actor, int $page = 0): array
    {
        $this->trees->access($tree, $actor, 'manager');
        $rows = $this->store->rows(
            "SELECT id, title, mime, size, revision, updated_at FROM *PREFIX*stb_media WHERE tree_id = ? AND status = 'trashed' ORDER BY updated_at DESC, id DESC LIMIT 26 OFFSET " .
                max(0, min(10000, $page)) * 25,
            [$tree],
        );
        return [
            'items' => array_map(
                fn($r) => [
                    'id' => $r['id'],
                    'title' => $r['title'],
                    'mime' => $r['mime'],
                    'size' => (int) $r['size'],
                    'revision' => (int) $r['revision'],
                    'deletedAt' => (int) $r['updated_at'],
                ],
                array_slice($rows, 0, 25),
            ),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    public function impact(string $tree, string $id, string $actor): array
    {
        return $this->store->write(function () use ($tree, $id, $actor) {
            $m = $this->trees->access($tree, $actor, 'manager');
            $r = $this->row($tree, $id, $m);
            return [
                'revision' => (int) $r['revision'],
                'treeRevision' => $this->trees->get($tree, $actor)['revision'],
                'assignments' => count($r['assignments']),
                'people' => count($r['metadata']['people']),
                'event' => !empty($r['metadata']['eventId']),
                'size' => (int) $r['size'],
            ];
        });
    }
    public function lifecycle(
        string $tree,
        string $id,
        string $actor,
        mixed $revision,
        mixed $treeRevision,
        bool $restore,
    ): array {
        return $this->store->write(function () use ($tree, $id, $actor, $revision, $treeRevision, $restore) {
            $m = $this->trees->access($tree, $actor, 'manager');
            $raw = $this->store->one('SELECT * FROM *PREFIX*stb_media WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$raw || $raw['status'] !== ($restore ? 'trashed' : 'ready')) {
                throw Problem::missing();
            }
            $this->store->version($raw, $revision);
            if (!$restore) {
                $this->store->version($this->trees->get($tree, $actor), $treeRevision);
            }
            // Validate retained dependencies before making the original available again.
            $r = $this->row($tree, $id, $m, $restore);
            $this->store->execute('UPDATE *PREFIX*stb_media_people SET preferred = 0 WHERE media_id = ?', [
                $id,
            ]);
            $this->store->execute(
                'UPDATE *PREFIX*stb_media SET status = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                [$restore ? 'ready' : 'trashed', time(), $id],
            );
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                $restore ? 'media_restored' : 'media_trashed',
                null,
                ['mediaId' => $id],
                'management',
            );
            return ['id' => $id, 'revision' => (int) $r['revision'] + 1];
        });
    }
    public function events(string $tree, string $actor, string $query = '', int $page = 0): array
    {
        return $this->metadata->events($tree, $actor, $query, $page, $this->trees);
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
        return $this->validateBytes($bytes, (string) $f['name']);
    }
    private function validateBytes(string $bytes, string $name): array
    {
        $size = strlen($bytes);
        if (!$size || $size > self::MAX_BYTES) {
            throw new Problem('media_size', 'Choose a file up to 10 MiB.', 413);
        }
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
                preg_replace('/[\x00-\x1F\x7F]/', '_', basename(str_replace('\\', '/', $name))),
                'fileName',
                250,
                true,
            ),
        ];
    }
    public function upload(string $tree, string $actor, array $file, array $b): array
    {
        $this->trees->access($tree, $actor, 'editor');
        return $this->storeOriginal($tree, $actor, $this->validateUpload($file), $b);
    }
    private function storeOriginal(
        string $tree,
        string $actor,
        array $f,
        array $b,
        ?callable $sourceCheck = null,
    ): array {
        $id = $this->store->write(function () use ($tree, $actor, $f, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $referenceId = Values::text($b['referenceRecordId'] ?? '', 'referenceRecordId', 36);
            $referenceField = (int) ($b['referenceField'] ?? -1);
            $referenceRevision = $b['referenceRevision'] ?? null;
            if ($referenceId !== '' && $referenceField < 0) {
                throw Problem::missing();
            }
            $reference =
                $referenceId !== ''
                    ? $this->referenceEntry($tree, $actor, $referenceId, $referenceField, $referenceRevision)
                    : null;
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            if (
                $reference &&
                $reference['record']['visibility'] === 'management' &&
                $visibility !== 'management'
            ) {
                throw new Problem(
                    'reference_visibility',
                    'This reference requires management-only media.',
                    422,
                );
            }
            if ($visibility !== 'members' && !Trees::management($m)) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            $title = Values::text($b['title'] ?? $f['name'], 'title', 250, true);
            $person = Values::text($b['personId'] ?? '', 'personId', 36);
            if ($reference) {
                $expected =
                    $reference['record']['category'] === 'event' ? '' : $reference['record']['origin_id'];
                if ($person !== '' && $person !== $expected) {
                    throw Problem::missing();
                }
                $person = $expected;
            }
            if ($person !== '') {
                $this->people->row($tree, $person, $m);
            }
            return $this->store->receipt(
                $actor,
                'media:' . $tree,
                (string) ($b['requestId'] ?? ''),
                [
                    $f['sha256'],
                    $f['size'],
                    $f['name'],
                    $title,
                    $visibility,
                    $person,
                    $referenceId,
                    $referenceField,
                    $referenceRevision,
                ],
                function () use (
                    $tree,
                    $actor,
                    $f,
                    $title,
                    $visibility,
                    $person,
                    $m,
                    $referenceId,
                    $referenceField,
                ) {
                    if ($referenceId !== '') {
                        $this->assertReferenceUnmatched($tree, $actor, $referenceId, $referenceField);
                    }
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
                            'crop_json' => Store::json([
                                'x' => 0,
                                'y' => 0,
                                'width' => 1,
                                'height' => 1,
                                'space' => 'oriented',
                            ]),
                            'preferred' => 0,
                        ]);
                    }
                    return $id;
                },
            );
        });
        $membership = $this->trees->access($tree, $actor, 'editor');
        $r = $this->row($tree, $id, $membership, true);
        if (!in_array($r['status'], ['pending', 'ready'], true)) {
            throw Problem::missing();
        }
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
        return $this->store->write(function () use ($tree, $id, $actor, $sourceCheck, $b) {
            if ($sourceCheck) {
                $sourceCheck();
            }
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m, true);
            if (!in_array($r['status'], ['pending', 'ready'], true)) {
                throw Problem::missing();
            }
            if ($r['status'] !== 'ready' && (int) $r['actor_revision'] !== (int) $m['revision']) {
                throw new Problem(
                    'upload_authority_changed',
                    'Your authority changed during this upload. Start a new upload.',
                    409,
                );
            }
            if ($r['status'] !== 'ready') {
                $referenceId = (string) ($b['referenceRecordId'] ?? '');
                if ($referenceId !== '') {
                    $field = (int) ($b['referenceField'] ?? -1);
                    $reference = $this->referenceEntry(
                        $tree,
                        $actor,
                        $referenceId,
                        $field,
                        $b['referenceRevision'] ?? null,
                    );
                    $this->assertReferenceUnmatched($tree, $actor, $referenceId, $field);
                    $this->store->insert('stb_media_matches', [
                        'record_id' => $referenceId,
                        'field_index' => $field,
                        'media_id' => $id,
                        'actor_id' => $actor,
                        'created_at' => time(),
                    ]);
                    if ($reference['record']['category'] === 'event') {
                        $metadata = $this->metadata->clean($tree, ['eventId' => $referenceId], $m);
                        $this->metadata->write($id, $metadata);
                    }
                }
                $this->store->execute("UPDATE *PREFIX*stb_media SET status = 'ready' WHERE id = ?", [$id]);
                $this->store->bump($tree);
                $this->store->audit($tree, $actor, 'media_uploaded', null, ['mediaId' => $id], 'management');
            }
            return $this->get($tree, $id, $actor);
        });
    }
    private function source(string $actor, int $id): File
    {
        foreach ($this->rootFolder->getUserFolder($actor)->getById($id) as $node) {
            if ($node instanceof File && $node->isReadable()) {
                return $node;
            }
        }
        throw Problem::missing();
    }
    public function selectFile(string $tree, string $actor, string $path): array
    {
        $this->trees->access($tree, $actor, 'editor');
        // The native picker returns a path relative to the current user's Files root.
        if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw Problem::missing();
        }
        try {
            $node = $this->rootFolder->getUserFolder($actor)->get($path);
        } catch (NotFoundException) {
            throw Problem::missing();
        }
        if (!($node instanceof File) || !$node->isReadable()) {
            throw Problem::missing();
        }
        if ($node->getSize() <= 0 || $node->getSize() > self::MAX_BYTES) {
            throw new Problem('media_size', 'Choose a file up to 10 MiB.', 413);
        }
        if (!in_array($node->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new Problem(
                'media_type',
                'Choose a JPEG, PNG or PDF file. SVG and executable content are not accepted.',
            );
        }
        return [
            'id' => $node->getId(),
            'etag' => $node->getEtag(),
            'name' => $node->getName(),
            'size' => $node->getSize(),
            'mime' => $node->getMimeType(),
        ];
    }
    /** The immutable import entry supplies the exact FILE wording, even after record edits. */
    private function referenceEntry(
        string $tree,
        string $actor,
        string $recordId,
        int $fieldIndex,
        ?int $revision = null,
    ): array {
        $membership = $this->trees->access($tree, $actor);
        $record = $this->records->row($tree, $recordId, $membership);
        if ($revision !== null) {
            $this->store->version($record, $revision);
        }
        $original = json_decode($record['original_json'], true);
        if (
            ($original['title'] ?? '') !== 'Missing media reference' ||
            (($original['visibility'] ?? 'members') !== 'members' && !Trees::management($membership)) ||
            !$this->store->one(
                'SELECT m.object_id FROM *PREFIX*stb_import_maps m JOIN *PREFIX*stb_imports i ON i.id = m.run_id WHERE i.tree_id = ? AND m.kind = ? AND m.object_id = ?',
                [$tree, 'record', $recordId],
            )
        ) {
            throw Problem::missing();
        }
        foreach ($original['participants'] ?? [] as $participant) {
            $this->people->row($tree, $participant['personId'], $membership);
        }
        $field = $original['fields'][$fieldIndex] ?? null;
        if ($fieldIndex >= 0 && (($field['label'] ?? '') !== 'FILE' || ($field['value'] ?? '') === '')) {
            throw Problem::missing();
        }
        return ['record' => $record, 'path' => $field['value'] ?? ''];
    }
    private function assertReferenceUnmatched(string $tree, string $actor, string $recordId, int $field): void
    {
        $match = $this->store->one(
            'SELECT media_id FROM *PREFIX*stb_media_matches WHERE record_id = ? AND field_index = ?',
            [$recordId, $field],
        );
        if (!$match) {
            return;
        }
        try {
            $this->get($tree, $match['media_id'], $actor);
        } catch (Problem $e) {
            if ($e->status !== 404 || !Trees::management($this->trees->access($tree, $actor))) {
                throw Problem::missing();
            }
        }
        throw new Problem('reference_matched', 'This file reference already has a private copy.', 409);
    }
    public function referenceReview(string $tree, string $actor, string $recordId): array
    {
        $first = $this->referenceEntry($tree, $actor, $recordId, -1);
        $record = $first['record'];
        $original = json_decode($record['original_json'], true);
        $references = [];
        foreach ($original['fields'] as $index => $field) {
            if ($field['label'] !== 'FILE' || $field['value'] === '') {
                continue;
            }
            $match = $this->store->one(
                'SELECT media_id FROM *PREFIX*stb_media_matches WHERE record_id = ? AND field_index = ?',
                [$recordId, $index],
            );
            $medium = null;
            if ($match) {
                try {
                    $medium = $this->get($tree, $match['media_id'], $actor);
                } catch (Problem $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                    if (!Trees::management($this->trees->access($tree, $actor))) {
                        throw Problem::missing();
                    }
                }
            }
            $references[] = [
                'fieldIndex' => $index,
                'path' => $field['value'],
                'state' => !$match ? 'missing' : ($medium ? 'matched' : 'unavailable'),
                'medium' => $medium,
            ];
        }
        return [
            'recordId' => $recordId,
            'revision' => (int) $record['revision'],
            'visibility' => $record['visibility'],
            'references' => $references,
        ];
    }
    public function importFile(string $tree, string $actor, int $fileId, string $etag, array $b): array
    {
        $this->trees->access($tree, $actor, 'editor');
        $node = $this->source($actor, $fileId);
        $node->lock(\OCP\Lock\ILockingProvider::LOCK_SHARED);
        try {
            $check = function () use ($actor, $fileId, $etag) {
                $current = $this->source($actor, $fileId);
                if ($etag === '' || !hash_equals($etag, $current->getEtag())) {
                    throw new Problem(
                        'source_changed',
                        'The source file changed. Choose it again before importing.',
                        409,
                    );
                }
            };
            $check();
            if ($node->getSize() <= 0 || $node->getSize() > self::MAX_BYTES) {
                throw new Problem('media_size', 'Choose a file up to 10 MiB.', 413);
            }
            $stream = $node->fopen('r');
            if (!is_resource($stream)) {
                throw Problem::missing();
            }
            try {
                $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if ($bytes === false) {
                throw Problem::missing();
            }
            $check();
            return $this->storeOriginal(
                $tree,
                $actor,
                $this->validateBytes($bytes, $node->getName()),
                $b,
                $check,
            );
        } finally {
            $node->unlock(\OCP\Lock\ILockingProvider::LOCK_SHARED);
        }
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
            $metadata = $this->metadata->clean($tree, $b['metadata'] ?? $r['metadata'], $m);
            $items = $b['assignments'] ?? $r['assignments'];
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
                    'crop_json' => Store::json([...$a['crop'], 'space' => 'oriented']),
                    'preferred' => $a['preferred'] ? 1 : 0,
                ]);
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_media SET title = ?, visibility = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                [$title, $visibility, time(), $id],
            );
            $this->metadata->write($id, $metadata);
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                'media_updated',
                null,
                ['mediaId' => $id, 'previous' => $r['metadata'], 'current' => $metadata],
                'management',
            );
            return $this->get($tree, $id, $actor);
        });
    }
    private function previewAuthority(
        string $tree,
        string $id,
        string $actor,
        array $row,
        array $membership,
    ): void {
        $current = $this->trees->access($tree, $actor);
        $media = $this->row($tree, $id, $current);
        if (
            (int) $current['revision'] !== (int) $membership['revision'] ||
            (int) $row['revision'] !== (int) $media['revision']
        ) {
            throw new Problem(
                'preview_changed',
                'The media changed. Close the viewer and reload the library.',
                409,
            );
        }
    }
    private function originalBytes(array $row): string
    {
        $bytes = $this->folder()->getFile($row['file_key'])->getContent();
        if (!hash_equals($row['sha256'], hash('sha256', $bytes))) {
            throw new Problem('original_mismatch', 'The stored original failed its integrity check.', 500);
        }
        return $bytes;
    }
    private function orientation(array $row, ?string $bytes = null): int
    {
        if ($row['mime'] !== 'image/jpeg') {
            return 1;
        }
        $hash = $row['sha256'];
        return $this->orientationCache[$hash] ??= ImageOrientation::fromJpeg(
            $bytes ?? $this->originalBytes($row),
        );
    }
    private function pdfPages(array $r, string $bytes, callable $authorize): int
    {
        return (int) $this->previews->remember(
            'pdf-info-v1:' . $r['sha256'],
            $authorize,
            fn() => (string) $this->pdf->pages($bytes),
        );
    }
    public function previewInfo(string $tree, string $id, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        $r = $this->row($tree, $id, $m);
        $check = fn() => $this->previewAuthority($tree, $id, $actor, $r, $m);
        $pages = $r['mime'] === 'application/pdf' ? $this->pdfPages($r, $this->originalBytes($r), $check) : 1;
        $check();
        return ['pages' => $pages, 'revision' => (int) $r['revision'], 'mime' => $r['mime']];
    }
    public function download(
        string $tree,
        string $id,
        string $actor,
        bool $preview = false,
        string $person = '',
        int $size = 600,
        int $page = 1,
    ): array {
        $m = $this->trees->access($tree, $actor);
        $r = $this->row($tree, $id, $m);
        $bytes = $this->originalBytes($r);
        if (!$preview) {
            return ['bytes' => $bytes, 'name' => $r['original_name'], 'mime' => $r['mime']];
        }
        if (!in_array($size, [320, 600, 1600], true) || $page < 1 || $page > PdfPreview::MAX_PAGES) {
            throw new Problem('preview_dimensions', 'Choose a supported preview size and page.');
        }
        $crop = null;
        if ($person !== '') {
            $a =
                array_values(array_filter($r['assignments'], fn($a) => $a['personId'] === $person))[0] ??
                null;
            if (!$a) {
                throw Problem::missing();
            }
            if ($r['mime'] !== 'application/pdf') {
                $crop = $a['crop'];
            }
        }
        $check = fn() => $this->previewAuthority($tree, $id, $actor, $r, $m);
        $pages = $r['mime'] === 'application/pdf' ? $this->pdfPages($r, $bytes, $check) : 1;
        if ($page > $pages) {
            throw new Problem('preview_page', 'This page is not available.', 404);
        }
        $orientation = $this->orientation($r, $bytes);
        $identity = Store::json(['raster-v2', $r['sha256'], $r['mime'], $crop, $size, $page]);
        $result = $this->previews->remember($identity, $check, function () use (
            $bytes,
            $r,
            $crop,
            $size,
            $page,
            $orientation,
        ) {
            if ($r['mime'] === 'application/pdf') {
                return $this->pdf->render($bytes, $page, $size);
            }
            $im = @imagecreatefromstring($bytes);
            if (!$im) {
                throw new Problem(
                    'preview_unavailable',
                    'Preview unavailable. You can still download the original.',
                    422,
                );
            }
            $im = ImageOrientation::orient($im, $orientation);
            if ($crop !== null) {
                $cropped = imagecrop($im, [
                    'x' => (int) floor($crop['x'] * imagesx($im)),
                    'y' => (int) floor($crop['y'] * imagesy($im)),
                    'width' => max(1, (int) floor($crop['width'] * imagesx($im))),
                    'height' => max(1, (int) floor($crop['height'] * imagesy($im))),
                ]);
                imagedestroy($im);
                $im = $cropped;
                if (!$im) {
                    throw new Problem(
                        'preview_unavailable',
                        'Preview unavailable. You can still download the original.',
                        422,
                    );
                }
            }
            $ratio = min(1, $size / max(imagesx($im), imagesy($im)));
            $out = imagecreatetruecolor(
                max(1, (int) (imagesx($im) * $ratio)),
                max(1, (int) (imagesy($im) * $ratio)),
            );
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled(
                $out,
                $im,
                0,
                0,
                0,
                0,
                imagesx($out),
                imagesy($out),
                imagesx($im),
                imagesy($im),
            );
            ob_start();
            imagepng($out);
            $value = ob_get_clean();
            imagedestroy($im);
            imagedestroy($out);
            return $value;
        });
        return ['bytes' => $result, 'name' => 'preview.png', 'mime' => 'image/png'];
    }
}

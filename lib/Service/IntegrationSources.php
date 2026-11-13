<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

/** The single authorization boundary for details copied into another Nextcloud app. */
final class IntegrationSources
{
    public function __construct(
        private Trees $trees,
        private People $people,
        private Evidence $evidence,
        private Records $records,
        private Media $media,
    ) {}

    public function get(
        string $tree,
        string $actor,
        string $type,
        string $id,
        bool $includeLiving,
        bool $includeManagement,
    ): array {
        if (
            !in_array($type, ['person', 'source', 'event', 'medium'], true) ||
            !preg_match('/\A[0-9a-f-]{36}\z/D', $id)
        ) {
            throw Problem::missing();
        }
        $member = $this->trees->access($tree, $actor);
        if (!Trees::management($member) && !(bool) $member['export_allowed']) {
            throw new Problem('forbidden', 'Export permission is required to copy genealogy details.', 403);
        }
        $people = [];
        if ($type === 'person') {
            $dto = $this->people->dto($this->people->row($tree, $id, $member));
            $people[] = $dto;
            $label = trim($dto['givenName'] . ' ' . $dto['familyName']) ?: 'Unnamed person';
        } elseif ($type === 'source') {
            $dto = $this->evidence->source($tree, $id, $actor);
            $label = $dto['title'];
        } elseif ($type === 'event') {
            $dto = $this->records->get($tree, $id, $actor);
            if ($dto['category'] !== 'event') {
                throw Problem::missing();
            }
            $people = array_column($dto['participants'], 'person');
            $label = $dto['title'];
        } else {
            $dto = $this->media->get($tree, $id, $actor);
            $people = array_column($dto['assignments'], 'person');
            foreach ($dto['metadata']['people'] as $entry) {
                if (isset($entry['person'])) {
                    $people[] = $entry['person'];
                } elseif ($entry['name'] !== '') {
                    // A name without a tree record has unknown living status.
                    $people[] = ['livingStatus' => 'unknown', 'visibility' => $dto['visibility']];
                }
            }
            foreach ($dto['metadata']['linkedEvent']['participants'] ?? [] as $participant) {
                $people[] = $participant['person'];
            }
            $label = $dto['title'];
        }
        if (($dto['visibility'] ?? 'members') === 'management' && !$includeManagement) {
            throw new Problem('review_required', 'Review inclusion of management-only research.');
        }
        foreach ($people as $person) {
            if ($person['livingStatus'] !== 'deceased' && !$includeLiving) {
                throw new Problem(
                    'review_required',
                    'Review inclusion of a living person or unknown living status.',
                );
            }
            if ($person['visibility'] === 'management' && !$includeManagement) {
                throw new Problem('review_required', 'Review inclusion of a management-only person.');
            }
        }
        $treeRevision = (int) $this->trees->get($tree, $actor)['revision'];
        $dependencies = array_map(
            fn($person) => [
                $person['id'] ?? '',
                $person['revision'] ?? 0,
                $person['visibility'],
                $person['livingStatus'],
            ],
            $people,
        );
        if ($type === 'medium' && isset($dto['metadata']['linkedEvent'])) {
            $event = $dto['metadata']['linkedEvent'];
            $dependencies[] = [$event['id'], $event['revision'], $event['visibility']];
        }
        return [
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'revision' => (int) $dto['revision'],
            'memberRevision' => (int) $member['revision'],
            'treeRevision' => $treeRevision,
            'dependencyDigest' => hash(
                'sha256',
                Store::json([$type, $id, $dto['revision'], $dto['visibility'] ?? 'members', $dependencies]),
            ),
            'livingWarning' => count(array_filter($people, fn($p) => $p['livingStatus'] !== 'deceased')) > 0,
            'managementWarning' =>
                ($dto['visibility'] ?? 'members') === 'management' ||
                count(array_filter($people, fn($p) => $p['visibility'] === 'management')) > 0,
            'person' => $type === 'person' ? $dto : null,
            'anchorPersonId' => $type === 'event' ? $people[0]['id'] ?? '' : '',
        ];
    }
}

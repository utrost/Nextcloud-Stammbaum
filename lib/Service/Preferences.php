<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\IConfig;
final class Preferences
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IConfig $config,
    ) {}
    private function read(string $actor, string $key, array $fallback): array
    {
        try {
            $v = json_decode(
                $this->config->getUserValue($actor, 'stammbaum', $key, ''),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            return is_array($v) ? $v : $fallback;
        } catch (\JsonException) {
            return $fallback;
        }
    }
    public function locale(string $actor): array
    {
        $this->trees->active($actor);
        $native = $this->config->getUserValue($actor, 'core', 'lang', 'de');
        return $this->read($actor, 'locale', [
            'revision' => 1,
            'locale' => str_starts_with($native, 'en') ? 'en' : 'de',
        ]);
    }
    public function saveLocale(string $actor, array $b): array
    {
        return $this->store->write(function () use ($actor, $b) {
            $r = $this->locale($actor);
            $this->store->version($r, $b['revision'] ?? null);
            $r = [
                'revision' => $r['revision'] + 1,
                'locale' => Values::choice($b['locale'] ?? '', ['en', 'de'], 'locale'),
            ];
            $this->config->setUserValue($actor, 'stammbaum', 'locale', Store::json($r));
            return $r;
        });
    }
    private function state(string $tree, string $actor): array
    {
        return $this->read($actor, 'views:' . $tree, ['revision' => 1, 'items' => []]);
    }
    private function allowed(string $tree, array $view, array $m): bool
    {
        try {
            $this->people->row($tree, $view['focus'], $m);
            if ($view['selected']) {
                $this->people->row($tree, $view['selected'], $m);
            }
            return true;
        } catch (Problem) {
            return false;
        }
    }
    public function views(string $tree, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        $r = $this->state($tree, $actor);
        $r['items'] = array_values(array_filter($r['items'], fn($v) => $this->allowed($tree, $v, $m)));
        return $r;
    }
    public function saveView(string $tree, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $actor, $b) {
            $m = $this->trees->access($tree, $actor);
            $id = $this->store->receipt(
                $actor,
                'view:' . $tree,
                (string) ($b['requestId'] ?? ''),
                $b,
                function () use ($tree, $actor, $b, $m) {
                    $state = $this->state($tree, $actor);
                    $this->store->version($state, $b['revision'] ?? null);
                    if (count($state['items']) >= 30) {
                        throw new Problem(
                            'view_limit',
                            'Remove a saved view before adding another. This version stores up to 30 views per tree.',
                        );
                    }
                    $focus = Values::text($b['focus'] ?? '', 'focus', 36, true);
                    $selected = Values::text($b['selected'] ?? '', 'selected', 36);
                    $this->people->row($tree, $focus, $m);
                    if ($selected !== '') {
                        $this->people->row($tree, $selected, $m);
                    }
                    $mode = Values::choice(
                        $b['mode'] ?? 'family',
                        ['family', 'ancestors', 'descendants'],
                        'mode',
                    );
                    $depths = [];
                    foreach (['up' => 3, 'down' => 2] as $key => $default) {
                        $v = $b[$key] ?? $default;
                        if (!is_int($v) || $v < 0 || $v > 8) {
                            throw new Problem(
                                'view_depth',
                                'Choose generation depths between zero and eight.',
                            );
                        }
                        $depths[$key] = $v;
                    }
                    $camera = $b['camera'] ?? [];
                    if (!is_array($camera)) {
                        throw new Problem('camera', 'Choose a valid camera position.');
                    }
                    foreach (['x', 'y', 'zoom'] as $key) {
                        if (
                            !isset($camera[$key]) ||
                            !is_numeric($camera[$key]) ||
                            !is_finite((float) $camera[$key]) ||
                            abs((float) $camera[$key]) > 1000000
                        ) {
                            throw new Problem('camera', 'Choose a valid camera position.');
                        }
                        $camera[$key] = (float) $camera[$key];
                    }
                    if ($camera['zoom'] < 0.2 || $camera['zoom'] > 2) {
                        throw new Problem('camera', 'Choose a supported zoom.');
                    }
                    $id = Store::id();
                    $state['items'][] = [
                        'id' => $id,
                        'label' => Values::text($b['label'] ?? '', 'label', 100, true),
                        'focus' => $focus,
                        'selected' => $selected,
                        'mode' => $mode,
                        ...$depths,
                        'camera' => array_intersect_key($camera, array_flip(['x', 'y', 'zoom'])),
                    ];
                    $state['revision']++;
                    $this->config->setUserValue($actor, 'stammbaum', 'views:' . $tree, Store::json($state));
                    return $id;
                },
            );
            $views = $this->views($tree, $actor);
            if (!array_filter($views['items'], fn($v) => $v['id'] === $id)) {
                throw Problem::missing();
            }
            return $views;
        });
    }
    public function deleteView(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $this->trees->access($tree, $actor);
            $r = $this->state($tree, $actor);
            $this->store->version($r, $b['revision'] ?? null);
            $items = array_values(array_filter($r['items'], fn($v) => $v['id'] !== $id));
            if (count($items) === count($r['items'])) {
                throw Problem::missing();
            }
            $r['items'] = $items;
            $r['revision']++;
            $this->config->setUserValue($actor, 'stammbaum', 'views:' . $tree, Store::json($r));
            return $this->views($tree, $actor);
        });
    }
}

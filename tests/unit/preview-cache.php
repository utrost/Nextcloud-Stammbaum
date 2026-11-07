<?php
declare(strict_types=1);
// Small in-memory implementations isolate eviction, duplicate work and authorization races.
namespace OCP\Files {
    interface IAppData {}
    class NotFoundException extends \RuntimeException {}
}
namespace OCP\Files\SimpleFS {
    interface ISimpleFolder {}
}
namespace OCP\Lock {
    interface ILockingProvider
    {
        public const LOCK_EXCLUSIVE = 2;
    }
    class LockedException extends \RuntimeException {}
}
namespace {
    require __DIR__ . '/../../lib/Service/Problem.php';
    require __DIR__ . '/../../lib/Service/PreviewCache.php';
    use OCA\Stammbaum\Service\{PreviewCache, Problem};
    use OCP\Files\NotFoundException;
    final class CacheFile
    {
        public string $bytes = '';
        public int $time;
        public ?int $declaredSize = null;
        public function __construct(public CacheFolder $folder, public string $name)
        {
            $this->time = time();
        }
        public function getName(): string
        {
            return $this->name;
        }
        public function getMTime(): int
        {
            return $this->time;
        }
        public function getSize(): int
        {
            return $this->declaredSize ?? strlen($this->bytes);
        }
        public function getContent(): string
        {
            return $this->bytes;
        }
        public function putContent(string $v): void
        {
            $this->bytes = $v;
        }
        public function delete(): void
        {
            unset($this->folder->files[$this->name]);
        }
    }
    final class CacheFolder implements \OCP\Files\SimpleFS\ISimpleFolder
    {
        public array $files = [];
        public function getFile(string $k): CacheFile
        {
            return $this->files[$k] ?? throw new NotFoundException();
        }
        public function newFile(string $k): CacheFile
        {
            return $this->files[$k] = new CacheFile($this, $k);
        }
        public function getDirectoryListing(): array
        {
            return array_values($this->files);
        }
    }
    final class CacheData implements \OCP\Files\IAppData
    {
        public function __construct(public CacheFolder $folder) {}
        public function getFolder(string $k): CacheFolder
        {
            return $this->folder;
        }
    }
    final class CacheLocks implements \OCP\Lock\ILockingProvider
    {
        public array $held = [];
        public function acquireLock(string $k, int $t): void
        {
            if (strlen($k) > 64) {
                throw new \RuntimeException('Nextcloud lock keys must fit the database column');
            }
            if (isset($this->held[$k])) {
                throw new \OCP\Lock\LockedException();
            }
            $this->held[$k] = true;
        }
        public function releaseLock(string $k, int $t): void
        {
            unset($this->held[$k]);
        }
    }
    function check(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new \RuntimeException($message);
        }
    }
    $folder = new CacheFolder();
    $locks = new CacheLocks();
    $cache = new PreviewCache(new CacheData($folder), $locks);
    $auth = fn() => null;
    $renders = 0;
    $render = function () use (&$renders) {
        $renders++;
        return 'derivative';
    };
    check($cache->remember('first', $auth, $render) === 'derivative', 'cold result');
    check($cache->remember('first', $auth, $render) === 'derivative' && $renders === 1, 'warm avoids render');
    try {
        $cache->remember('first', fn() => throw Problem::missing(), $render);
        throw new \RuntimeException('warm bypass');
    } catch (Problem $e) {
        check($e->status === 404, 'warm authorization');
    }
    $allowed = true;
    try {
        $cache->remember(
            'race',
            function () use (&$allowed) {
                if (!$allowed) {
                    throw Problem::missing();
                }
            },
            function () use (&$allowed) {
                $allowed = false;
                return 'private output';
            },
        );
        throw new \RuntimeException('race bypass');
    } catch (Problem $e) {
        check($e->status === 404, 'revoked before publication');
    }
    check(
        !isset($folder->files[hash('sha256', 'race')]) && !$locks->held,
        'revoked output not cached and lock released',
    );
    $locks->held[hash('sha256', 'stammbaum/preview/' . hash('sha256', 'busy'))] = true;
    try {
        $cache->remember('busy', $auth, $render);
        throw new \RuntimeException('duplicate work');
    } catch (Problem $e) {
        check($e->status === 503 && $renders === 1, 'busy does not render');
    }
    $locks->held = [];
    $file = $folder->getFile(hash('sha256', 'first'));
    $file->bytes = 'corrupt';
    check(
        $cache->remember('first', $auth, $render) === 'derivative' && $renders === 2,
        'corruption regenerates',
    );
    $folder->getFile(hash('sha256', 'first'))->time = time() - PreviewCache::TTL - 2;
    $cache->remember('first', $auth, $render);
    check($renders === 3, 'expired cache regenerates');
    $folder->files = [];
    for ($i = 0; $i < PreviewCache::MAX_FILES; $i++) {
        $folder->newFile('old-' . $i)->putContent('small');
    }
    $cache->remember('count', $auth, $render);
    check(count($folder->files) === PreviewCache::MAX_FILES, 'entry count bounded');
    $folder->files = [];
    for ($i = 0; $i < 8; $i++) {
        $f = $folder->newFile('large-' . $i);
        $f->declaredSize = 10000000;
    }
    $cache->remember('bytes', $auth, fn() => str_repeat('a', 1024));
    check(
        array_sum(array_map(fn($f) => $f->getSize(), $folder->files)) <= PreviewCache::MAX_BYTES,
        'byte budget enforced',
    );
    try {
        $cache->remember('oversized', $auth, fn() => str_repeat('x', PreviewCache::MAX_ENTRY + 1));
        throw new \RuntimeException('oversized accepted');
    } catch (Problem $e) {
        check($e->errorCode === 'preview_limit', 'output bound');
    }
    check(!$locks->held, 'all locks released');
    echo "Preview cache integrity, eviction, limits, duplicate work and revoked publication passed.\n";
}

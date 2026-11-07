<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\Files\{IAppData, NotFoundException};
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\{ILockingProvider, LockedException};
/** Disposable private derivatives. No browser/public cache is authorized by this cache. */
final class PreviewCache
{
    public const MAX_BYTES = 67108864,
        MAX_FILES = 256,
        MAX_ENTRY = 12582912,
        TTL = 604800;
    public function __construct(private IAppData $appData, private ILockingProvider $locks) {}
    private function folder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder('media-previews-v1');
        } catch (NotFoundException) {
            return $this->appData->newFolder('media-previews-v1');
        }
    }
    private function read(ISimpleFolder $folder, string $key): ?string
    {
        try {
            $f = $folder->getFile($key);
            if ($f->getMTime() < time() - self::TTL || $f->getSize() > self::MAX_ENTRY + 65) {
                return null;
            }
            $value = $f->getContent();
            if (
                strlen($value) < 65 ||
                !hash_equals(substr($value, 0, 64), hash('sha256', substr($value, 65)))
            ) {
                return null;
            }
            return substr($value, 65);
        } catch (NotFoundException) {
            return null;
        }
    }
    public function remember(string $identity, callable $authorize, callable $render): string
    {
        $key = hash('sha256', $identity);
        $folder = $this->folder();
        $authorize();
        $value = $this->read($folder, $key);
        if ($value !== null) {
            $authorize();
            return $value;
        }
        $lock = hash('sha256', 'stammbaum/preview/' . $key);
        try {
            $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new Problem('preview_busy', 'The preview is being prepared. Please retry.', 503);
        }
        try {
            $value = $this->read($folder, $key);
            if ($value === null) {
                $value = $render();
                if (strlen($value) > self::MAX_ENTRY) {
                    throw new Problem(
                        'preview_limit',
                        'This preview exceeds the display limits. Download the original instead.',
                        422,
                    );
                }
                $authorize();
                $budgetLock = 'stammbaum/preview-budget';
                try {
                    $this->locks->acquireLock($budgetLock, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (LockedException) {
                    $authorize();
                    return $value;
                }
                try {
                    $files = $folder->getDirectoryListing();
                    usort($files, fn($a, $b) => $a->getMTime() <=> $b->getMTime());
                    $total = array_sum(array_map(fn($f) => $f->getSize(), $files));
                    $count = count($files);
                    foreach ($files as $f) {
                        if (
                            $f->getName() === $key ||
                            $f->getMTime() < time() - self::TTL ||
                            $count >= self::MAX_FILES ||
                            $total + strlen($value) + 65 > self::MAX_BYTES
                        ) {
                            $total -= $f->getSize();
                            $count--;
                            $f->delete();
                        }
                    }
                    $authorize();
                    $folder->newFile($key)->putContent(hash('sha256', $value) . "\n" . $value);
                } finally {
                    $this->locks->releaseLock($budgetLock, ILockingProvider::LOCK_EXCLUSIVE);
                }
            }
            $authorize();
            return $value;
        } finally {
            $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}

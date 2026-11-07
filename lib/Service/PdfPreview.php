<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use Symfony\Component\Process\Process;
/** Optional Linux Poppler renderer; never execute PDF actions or expose source PDFs inline. */
final class PdfPreview
{
    public const MAX_PAGES = 100;
    private function unavailable(): Problem
    {
        return new Problem(
            'preview_unavailable',
            'Preview unavailable. You can still download the original.',
            422,
        );
    }
    private function run(array $args): string
    {
        if (!is_executable('/usr/bin/prlimit') || !is_executable($args[0])) {
            throw $this->unavailable();
        }
        $p = new Process(
            [
                '/usr/bin/prlimit',
                '--as=268435456',
                '--cpu=5',
                '--fsize=12582912',
                '--nofile=64',
                '--',
                ...$args,
            ],
            null,
            ['LC_ALL' => 'C'],
        );
        $p->setTimeout(8);
        try {
            $p->run(function ($type, $data) use ($p) {
                if (strlen($p->getOutput()) + strlen($p->getErrorOutput()) > 65536) {
                    $p->stop(0);
                }
            });
            if (!$p->isSuccessful()) {
                throw $this->unavailable();
            }
            return $p->getOutput();
        } catch (\Throwable) {
            throw $this->unavailable();
        }
    }
    private function temporary(string $bytes, callable $fn): mixed
    {
        $dir = sys_get_temp_dir() . '/stammbaum-pdf-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) {
            throw $this->unavailable();
        }
        try {
            $path = $dir . '/original.pdf';
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw $this->unavailable();
            }
            chmod($path, 0600);
            return $fn($path, $dir . '/page');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($dir);
        }
    }
    public function pages(string $bytes): int
    {
        return $this->temporary($bytes, function ($path) {
            $info = $this->run(['/usr/bin/pdfinfo', $path]);
            if (
                preg_match('/^Encrypted:\s+yes/m', $info) ||
                !preg_match('/^Pages:\s+(\d+)/m', $info, $m) ||
                (int) $m[1] < 1 ||
                (int) $m[1] > self::MAX_PAGES
            ) {
                throw $this->unavailable();
            }
            return (int) $m[1];
        });
    }
    public function render(string $bytes, int $page, int $size): string
    {
        return $this->temporary($bytes, function ($path, $output) use ($page, $size) {
            $this->run([
                '/usr/bin/pdftoppm',
                '-f',
                (string) $page,
                '-l',
                (string) $page,
                '-singlefile',
                '-scale-to',
                (string) $size,
                '-png',
                $path,
                $output,
            ]);
            $png = $output . '.png';
            if (!is_file($png) || filesize($png) > PreviewCache::MAX_ENTRY) {
                throw $this->unavailable();
            }
            $bytes = file_get_contents($png);
            $info = @getimagesizefromstring($bytes);
            if (!$info || $info['mime'] !== 'image/png' || $info[0] > $size || $info[1] > $size) {
                throw $this->unavailable();
            }
            // Re-encode to discard renderer metadata as well as original document metadata.
            $im = @imagecreatefromstring($bytes);
            if (!$im) {
                throw $this->unavailable();
            }
            ob_start();
            imagepng($im);
            $bytes = ob_get_clean();
            imagedestroy($im);
            return $bytes;
        });
    }
}

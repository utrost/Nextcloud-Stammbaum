<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/Problem.php';
require __DIR__ . '/../../lib/Service/PdfPreview.php';
if (is_executable('/usr/bin/pdfinfo') && is_executable('/usr/bin/prlimit')) {
    throw new RuntimeException('Run the missing-runtime test in the unmodified Nextcloud image.');
}
$before = glob(sys_get_temp_dir() . '/stammbaum-pdf-*') ?: [];
try {
    (new \OCA\Stammbaum\Service\PdfPreview())->pages(
        file_get_contents(__DIR__ . '/../fixtures/media-two-pages.pdf'),
    );
    throw new RuntimeException('Missing renderer accepted');
} catch (\OCA\Stammbaum\Service\Problem $e) {
    if ($e->status !== 422 || $e->errorCode !== 'preview_unavailable') {
        throw $e;
    }
}
if ((glob(sys_get_temp_dir() . '/stammbaum-pdf-*') ?: []) !== $before) {
    throw new RuntimeException('Temporary original leaked');
}
echo "Missing PDF runtime returns a safe fallback and removes temporary originals.\n";

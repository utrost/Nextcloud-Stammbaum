# Existing Nextcloud media — alpha.17

The first increment for [#74](https://github.com/utrost/Nextcloud-Stammbaum/issues/74) adds **Choose from Nextcloud Files** alongside device uploads. The native Nextcloud 34 picker supports existing JPEG/PNG images and PDF documents, including readable shared files. Selection displays filename, type, size, title and destination visibility before **Import private copy**.

The copy is private Stammbaum media, consumes the existing installation quota and retains exact source bytes. Source changes after selection require a fresh selection; later edits, deletion or share revocation do not alter an already imported copy. Original source files remain untouched. No Office formats, live synchronization or source-path disclosure are introduced. The [architecture](architecture.md) records endpoints, locking, validation and publication checks.

## Verification — 7 November 2026

Seventeen distinct targeted Playwright tests passed on the isolated Nextcloud/MariaDB instance: five new Files-copy tests, four existing media tests and eight upload-draft/navigation/localization regressions. The initial seven-test run passed, followed by the expanded five-test Files suite and eight-test regression suite. This is targeted coverage, not a new full-suite or CI result.

Coverage includes native picker confirmation and keyboard submission; desktop/390px/320px layouts with inspected screenshots; owned PDFs and read-only shared PNGs; denied source access; viewer denial; revoked shares; changed/deleted sources; unchanged downloaded originals; idempotent retries; path traversal rejection; disguised-content rejection; existing portrait crops/privacy; upload limits; draft preservation; and German feedback. Four JavaScript unit/catalog checks, build/route checks, PHP media syntax, formatting and archive/documentation validation passed.

The exact alpha.17 runtime package SHA-256 is `4af75d1dbb9e707d844c227c2d68521dba591d2cbe012e23afbc854f4fc1fb18`. It was deployed to both the isolated instance and Alice. An Alice Playwright smoke with a temporary fictional ordinary account verified the native picker, private PDF copy, unchanged download, source deletion independence and zero browser errors. The temporary account, source file, genealogy rows and private copied original were cleaned up. Alice stayed healthy; Library and Relate versions and enablement were unchanged.

## Remaining work

Keep #74 open for full acceptance: explicit missing-GEDCOM-reference matching remains with #34/#36; mid-copy source/authority revocation and quota/concurrency fault injection need additional verification; the new selection-specific cancel/failure recovery journey needs dedicated coverage. Existing quota and membership publication checks are reused, not claimed newly proven for every race. Preview caching/gallery viewing (#71), PDF thumbnails/viewer (#72), and EXIF orientation (#69) remain separate. PDFs currently show an icon and download as attachments.

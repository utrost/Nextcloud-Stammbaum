# Image and PDF previews — alpha.18

This increment implements the preview workflow tracked in [#71](https://github.com/utrost/Nextcloud-Stammbaum/issues/71) and [#72](https://github.com/utrost/Nextcloud-Stammbaum/issues/72). Media from device uploads and existing Nextcloud Files shares the same private pipeline. Thumbnails load only near the viewport. **Open preview** opens a keyboard-accessible dialog with zoom, PDF page controls, close/focus return and **Download original**. PDF content is rendered as images; the UI explains that selectable text and document accessibility features require the original.

The [architecture](architecture.md) defines cache limits, authorization rechecks, renderer resource limits, raw-image crop compatibility and original integrity. EXIF orientation, GEDCOM-reference matching and Office documents are not added by this iteration. Office documents remain explicitly out of scope. This does not alter the archived release-0.15 baseline.

## Optional PDF runtime

On Linux, provide the distribution-maintained `poppler-utils` package (`pdfinfo` and `pdftoppm`) and `prlimit` from `util-linux`. Alice already had these tools; no shared-system package changes were needed. The disposable Nextcloud test container and CI install `poppler-utils` explicitly. An installation without these executables retains image previews and PDF original downloads, with an honest preview-unavailable message.

For persistent Docker deployments, install the optional packages in the operator's own Nextcloud image, rather than relying on modifications to a running container. Keep Poppler security updates current. The app package does not bundle Poppler or an Office converter. This renderer adapter is Linux-specific; no other OS support is claimed. Deployment still targets Nextcloud 34 and PHP 8.3+.

Commands are passed as argument arrays to Nextcloud's available [Symfony Process component](https://symfony.com/doc/current/components/process.html), with explicit timeout and `prlimit` bounds. Poppler's page-to-image conversion never provides interactive PDF scripting or external document services. Preview content stays behind Stammbaum authorization instead of Nextcloud public shares or canonical user Files permissions.

## Verification

On 7 November 2026, all **22 affected Playwright tests passed in 3.0 minutes** against the final isolated runtime: five Files-copy tests, five new preview tests, four media regressions and eight navigation/localization checks. Desktop, 390px and 320px viewer screenshots were inspected. The tests cover cache hit/regeneration/corruption/concurrent requests, warm-cache hidden dependencies, unchanged originals/tree revisions, PDF pages and zoom, malformed/encrypted/over-limit documents, keyboard close/focus return and clearing displayed images after revoked access.

Four JavaScript unit/catalog tests passed. Dedicated PHP tests passed for cache byte/count/age limits, duplicate-work suppression, forced authorization loss before cache publication, lock release, missing PDF runtime and temporary-file cleanup. Formatting, build/route checks, PHP renderer syntax and documentation/archive validation passed. CI now installs the optional PDF runtime and runs the new tests, but no new CI or full application-suite pass is claimed here.

Testing found and fixed a Nextcloud lock-key truncation bug that left regeneration falsely busy, an unnamed zoom selector, and unreliable native Escape cancellation after page navigation. The final tests include these regressions; the existing screenshot baselines were not replaced to hide failures.

The exact alpha.18 package SHA-256 is `29518a9b3a68fef4128f13e23d44785039c95020a66870956229498071386937`. It was deployed to the isolated instance and Alice. The first Alice preflight stopped on a transient pre-existing upgrade condition; deployment was retried only after a healthy status, without any global upgrade. A final Playwright smoke using a temporary fictional ordinary account passed native Files selection, PDF copying, thumbnail display, page navigation, desktop/phone presentation, Escape close, original-byte equality and source deletion independence, with zero browser errors. All smoke account/tree/source/original/cache data was cleaned; a fixture from an unsuccessful pre-deployment smoke was also removed under identity guards. Alice remained healthy and sibling app versions/enablement were unchanged.

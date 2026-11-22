# Build, test and operation

## Build a runtime package

Use Node 24, npm 11, Python 3 and Docker for the documented development workflow.

```sh
npm ci
npm run check
npm run format:check
npm run package
```

`build/stammbaum-<version>.tar.gz` and its `.sha256` sidecar contain deterministic runtime artifacts. Packaging excludes documents, tests, dependencies, fixture credentials and development configuration. The content-hashed asset manifest must match the packaged JS/CSS. Commit only the current generated asset pair; the build removes superseded local bundles.

## Supported scaffold and licenses

The current supported development target is Nextcloud 34 with PHP 8.3+ and MariaDB. CI creates Nextcloud 34.0.4/MariaDB 11 from scratch, checks PHP 8.3 syntax, installs the exact package and executes the native browser suite. PostgreSQL, SQLite and other Nextcloud majors are not certified by this evidence; see the [platform adaptation decision](platform-adaptation.md) for the verified matrix and boundaries.

Stammbaum is AGPL-3.0-or-later. The bundled Vue runtime is MIT licensed; [THIRD-PARTY-NOTICES](../THIRD-PARTY-NOTICES) contains its notice and license text, and both that file and [LICENSE](../LICENSE) ship in every runtime archive. Exact build-tool dependencies and their declared licenses are recorded in `package-lock.json`; build tools are not distributed as app runtime dependencies. Runtime scripts, styles and icons are packaged locally; the UI inherits the host Nextcloud theme/fonts without loading an app-owned external font service.

There are no app-specific database credentials, standalone login service or initial administrator settings to enter. Nextcloud owns authentication and host configuration; unauthenticated app entry redirects to its login. An unavailable startup API displays an error and explicit Retry. Missing host/database installation prerequisites are operator setup failures, not an empty genealogy dataset. The app-specific installer refuses an unhealthy or unsupported host before deployment. The upgrade and recovery boundaries below remain applicable.

## Deploy to a development container

Use the local Docker context and set `--container` to a Nextcloud 34 container you control. The installer changes only the Stammbaum app; check the host's normal backup and change procedures before using it with real data. Use the disposable fixture below for destructive tests.

```sh
npm run package
python3 scripts/deploy-test.py --container nextcloud
```

The installer verifies the archive checksum and layout, requires a healthy Nextcloud 34, installs only `custom_apps/stammbaum`, and enables the app. It invokes Nextcloud's public app-specific upgrade API, not a global upgrade command. Apache reloads gracefully so PHP picks up the new code. A fresh process resolves and idempotently registers Stammbaum’s declared background jobs, and deployment records that check. Before/after checks require unchanged host version, maintenance state, and sibling app enablement/versions. A local verification record is written to ignored `build/`.

Open the native **Stammbaum** navigation entry. Existing Nextcloud accounts can create their own trees. No initial administrator tree membership or genealogy records are seeded by installation.

An app upgrade can require schema changes. Do not downgrade code across incompatible migrations. For this early development release, disable Stammbaum if a defect prevents safe use; disabling does not itself erase records. Migration rollback and production release automation remain open backlog work.

## Disposable integration fixture

```sh
python3 scripts/test-instance.py
npm run package
python3 scripts/deploy-test.py
npx playwright test
```

The fixture uses only `stammbaum-iteration-nc`, `stammbaum-iteration-db` and the `stammbaum-iteration` network, bound to loopback port 9096. It never resets the shared Nextcloud installation or the older standalone Stammbaum containers. Re-running setup reuses its existing state.

Nextcloud 34.0.4 and MariaDB 11 are the fixture images. Five fictional accounts are created; `stb_outsider` is a Nextcloud administrator with no automatic tree access. Generated credentials live only in ignored, mode-0600 `build/test-instance.json`. Do not publish that file. Tests involving account deletion, quota manipulation and recovery use this fixture exclusively.

Playwright currently uses `/usr/bin/google-chrome`. Unit tests use Node; PHP syntax can be checked in `php:8.3-cli`. `tests/unit/values.php` and `tests/unit/gedcom.php` need `mbstring`, available in the Nextcloud container. Browser results and screenshots are in ignored `test-results/`.

### Optional same-instance apps

CI pins Nextcloud 34.0.4, Calendar 6.6.5, Tasks 0.18.1, Contacts 8.9.3 and the built-in DAV backend 1.40.0. Record the exact version matrix with each result. The app remains usable without any of those optional clients. Calendar/Tasks choices are discovered per signed-in account; a writable collection must also support VEVENT/VTODO respectively. An absent or read-only destination is unavailable for publication. Collection sharing may be marked `shared-with-me` or `unknown`; the public API cannot prove that a calendar owned by the acting account has not later been shared outward. Verify its audience in Nextcloud before copying sensitive details. No server credential, remote URL or host-wide app configuration is needed.

Task creation uses the acting browser's authenticated same-origin CalDAV session. A tree permission revocation after the server returns a reviewed VTODO but before the browser writes it can leave a copy in Tasks. Stammbaum refuses to confirm the link after revocation and never reactivates it on regrant; the account holder should inspect or remove the copy in Tasks. Calendar creation runs in the foreground through Nextcloud's public calendar interface and also cannot roll back a remote success after a cross-app authority change. The [integration API contract](integration-api-contract.md) and [private connection lifecycle](connections-iteration.md) describe status and cleanup. Restored app data requires the authority-invalidation command below before reopening genealogy access.

Native screenshot baselines live beside `research-ui.spec.js` and `import-ui.spec.js`. Install `fonts-noto-core` for these checks; screenshot assertions explicitly select Noto Sans from Nextcloud's font stack to avoid operating-system font substitutions. Review generated images before accepting baseline changes. CI retains result JSON and screenshots as `stammbaum-browser-results`; fixture credentials and browser traces are excluded.

Alpha.13 adds `stb_preferences` for versioned personal navigation. Existing saved views remain readable and migrate on their next successful preference write. Include this table with the other `stb_` tables in consistent backups. Personal-home writes do not modify shared tree metadata or grant editing rights.

The suite tests actual native sessions, CSRF, MariaDB transactions, Nextcloud account events, upload/download bytes and browser journeys. A passed build alone is not application acceptance. The fixture retains fictional test records; its growing tree list is not production seed data.

## Original-media retention

JPEG/PNG/PDF originals are private app data. A 10 MiB file limit and installation-wide 1 GiB logical allowance are enforced. Images are limited to 16 million pixels and 16,000 pixels per dimension. Pending reservations remain counted, including after a failed or interrupted upload. Detaching an assignment does not delete its original.

A matching retry can finish an interrupted upload only while the initiating membership generation remains current. After revocation, password/account invalidation or recovery invalidation, start a new request. Existing unfinished reservations remain retained. General retention cleanup, package matching and orphan-repair tooling are not yet implemented; do not delete files behind live database rows to reclaim capacity.

The download endpoint verifies the original's SHA-256 every time. A mismatch fails closed. The app neither alters originals during cropping nor presents a derivative as an original.

## Consistent recovery boundary

Use the host's backup and restore process to capture Nextcloud identity and configuration, the full database including all `stb_` tables, the custom app and theme files, and the data stores including Stammbaum AppData. Keep retained media and import originals with the records that describe them. A GEDCOM export cannot restore memberships, assignments, history or original files. [Nextcloud backup guide](https://docs.nextcloud.com/server/stable/admin_manual/maintenance/backup.html) · [Nextcloud restore guide](https://docs.nextcloud.com/server/stable/admin_manual/maintenance/restore.html)

The operator owns backup scheduling, encryption, storage access, protected retention and periodic restore tests. Keep encryption keys recoverable separately from backup media, and protect database dumps, configuration and original files as private data. Installation does not schedule backups. Use the host's supported quiescing or consistent snapshot procedure so HTTP requests, cron and background workers cannot write to any component during capture.

1. Restore the database and files to an isolated target using compatible Nextcloud, database and app versions. Verify the backup checksums and restore with HTTP and all workers stopped. If any step fails, keep the target offline and retry into fresh storage from the verified backup set.
2. Check that `occ status --output=json` reports the expected version and no database upgrade needed. With HTTP and workers still stopped, turn maintenance mode off and run authority invalidation from a CLI process using the restored configuration. Nextcloud 34 does not load Stammbaum commands in maintenance mode. Run these commands from the Nextcloud directory as the HTTP service user.

   ```sh
   php occ maintenance:mode --off
   php occ stammbaum:invalidate-authority
   ```

3. After invalidation succeeds, verify the restored installation in isolation before opening general access. Confirm tree memberships and history, check original bytes and assignment links, and verify that previously issued invitations and export downloads fail. The command also invalidates unfinished uploads, pending imports, and pending Tasks/Calendar/Contacts authority. Old app connections do not resume after recovery. Their owners can inspect and unlink their opaque receipts. Handle restored Nextcloud sessions and app passwords according to the host's own recovery policy before reopening access.

Run the exercise separately from other tests on the disposable fictional fixture after installing the runtime package. It briefly stops that fixture, then restores to a separate database and volume before opening the clone on loopback. It also retries an interrupted restore. The exercise stores temporary, unencrypted test artifacts privately and removes them on exit. Production backups need the encryption and retention controls above.

```sh
STAMMBAUM_RECOVERY_EXERCISE=1 npx playwright test tests/e2e/recovery-restore.spec.js --reporter=list
```

Verified on Nextcloud 34.0.4 and MariaDB 11.8.9 with Stammbaum 0.1.0-alpha.33 (archive SHA-256 `a5710b05219de620515f33580adb33e651560fd3837716332c096731499fdfd8`). The restore test and both existing recovery tests passed locally. The restore preserved the edited person, attributed history, media assignment, memberships and PDF/GEDCOM original checksums. The interrupted target never started HTTP. After retry, the nonmember administrator still had no tree access, old invitations and export downloads were denied, and the workers rejected the restored import activation and queued export. A fresh invitation and export worked.

[Issue #45](https://github.com/utrost/Nextcloud-Stammbaum/issues/45) remains open for recovery of scoped tool credentials and webhook signing keys. This exercise covers the existing authority model and does not certify the full A30 scenario.

## Import background processing

The [GEDCOM profile](gedcom-import-profile.md) describes the exact supported subset. Managers/owners upload and review under **Imports**, then confirm activation into an empty tree. Native host cron processes the app's registered `ProcessImports` timed job. The desired interval is 60 seconds; a host running cron every five minutes may take up to another cycle for each analysis/activation phase. The browser can be closed while a queued job runs. Do not change a shared host's scheduling merely to accelerate a test.

To process one pending Stammbaum job manually through the same authority and lease checks:

```sh
docker exec -u www-data nextcloud php occ stammbaum:process-imports
```

Use `stammbaum-iteration-nc` instead for isolated fixtures. The CLI prints only the processed UUID or that nothing is pending; inspect the manager-only report for the actual outcome. Expired 300-second leases are recovered by a later worker, with at most three interrupted attempts per phase. Failed/cancelled runs retain their original reservation and cannot be restarted by uploading identical bytes to the same tree.

The 512 MiB import-original allowance is separate from the 1 GiB media allowance. All `stb_imports`, `stb_import_nodes`, `stb_import_maps` rows and private `IAppData/imports` files belong to the consistent recovery set. There is no purge command or automatic retention expiry. A failed original write may retain a reservation without a complete downloadable file. Neither deletion behind live rows nor code downgrade is a supported repair.

The import integration suite uses fictional data and temporary database triggers to test mid-transaction failures and concurrent visibility. Those fault tests are pinned to the disposable fixture and must never run against the shared host.

### Long-lived host workers during an app upgrade

An already-running `background-job:worker` process can retain old app code across an upgrade and fail to resolve a newly added job. Reloading Apache alone does not reload such a CLI worker.

Before relying on new app jobs, gracefully recycle an identified long-lived host worker through its normal process manager, preserving its arguments and scheduling. In the inspected Nextcloud 34 code, SIGTERM/SIGINT are cooperative: the worker checks the interrupted flag between jobs. Check currently running work first; do not force-kill an active job. Then run the app deployment verification again to reconcile its declared jobs.

The app installer does not automatically signal arbitrary host processes. `occ background-job:list --class='OCA\Stammbaum\BackgroundJob\ProcessImports'` checks the exact class name; this option is not a substring search.

## Optional image/PDF preview cache

See [alpha.18 preview operations and limits](media-previews-iteration.md). Preview cache data in private AppData `media-previews-v1` is disposable and can be regenerated; it is separate from retained `originals`. Never delete originals to clear thumbnails. Cache eviction is bounded and automatic on publication; a missing/corrupt cache value regenerates on demand. PDF viewing needs distribution-maintained Poppler utilities plus `prlimit`; missing or unsupported renderers leave original downloads available. Do not enable public Nextcloud shares to make genealogy previews work.

Alpha.19 adds media metadata columns and `stb_media_refs` through an additive app migration. Include them in the existing full database backup/recovery scope; there is no original-file conversion. See [metadata contract](media-metadata-iteration.md).

## Export jobs and media trash (alpha.20)

Nextcloud background execution runs `OCA\Stammbaum\BackgroundJob\ProcessExports`. Verify the host cron runner is active for automatic completion; the application does not process exports inside browser GET requests. Queued/running capacity is twenty installation-wide; creation is limited to ten per account/hour. Jobs left running for ten minutes become failed, requiring fresh review.

Generated results in private AppData `exports-v1` are disposable after invalidation/expiry; the worker removes terminal artifacts in bounded batches. Originals in `originals` and retained import data are separate recovery material. Trashed media retains its original bytes and quota allocation. Do not delete originals to reclaim trash capacity. Include `stb_exports` in consistent database recovery; `stammbaum:invalidate-authority` makes restored exports unusable by advancing tree/membership revisions. See [export/lifecycle limits](export-media-lifecycle-iteration.md).

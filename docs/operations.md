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

## Alice deployment

Alice is this workstation. Use the default local Docker context. The shared development container is `nextcloud`; Library and Relate must remain intact. The user explicitly waived backups for Alice development deployment. No SSH or new deployment approval is needed for this authorized scope.

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

Native screenshot baselines live beside `research-ui.spec.js` and `import-ui.spec.js`. Install `fonts-noto-core` for these checks; screenshot assertions explicitly select Noto Sans from Nextcloud's font stack to avoid operating-system font substitutions. Review generated images before accepting baseline changes. CI retains result JSON and screenshots as `stammbaum-browser-results`; fixture credentials and browser traces are excluded.

Alpha.13 adds `stb_preferences` for versioned personal navigation. Existing saved views remain readable and migrate on their next successful preference write. Include this table with the other `stb_` tables in consistent backups. Personal-home writes do not modify shared tree metadata or grant editing rights.

The suite tests actual native sessions, CSRF, MariaDB transactions, Nextcloud account events, upload/download bytes and browser journeys. A passed build alone is not application acceptance. The fixture retains fictional test records; its growing tree list is not production seed data.

## Original-media retention

JPEG/PNG/PDF originals are private app data. A 10 MiB file limit and installation-wide 1 GiB logical allowance are enforced. Images are limited to 16 million pixels and 16,000 pixels per dimension. Pending reservations remain counted, including after a failed or interrupted upload. Detaching an assignment does not delete its original.

A matching retry can finish an interrupted upload only while the initiating membership generation remains current. After revocation, password/account invalidation or recovery invalidation, start a new request. Existing unfinished reservations remain retained. General retention cleanup, package matching and orphan-repair tooling are not yet implemented; do not delete files behind live database rows to reclaim capacity.

The download endpoint verifies the original's SHA-256 every time. A mismatch fails closed. The app neither alters originals during cropping nor presents a derivative as an original.

## Consistent recovery boundary

The production requirement remains a consistent recovery of Nextcloud identity/configuration, all `stb_` tables and private app data. Backing up only a GEDCOM file or only the app directory cannot restore this system. Automated consistent backup/restore tooling and a full disaster-recovery exercise remain I06 work; Alice's development exception does not waive that product requirement.

After restoring a consistent host/app snapshot, before reopening genealogy access, run:

```sh
docker exec -u www-data nextcloud php occ stammbaum:invalidate-authority
```

This revokes restored invitation links and invalidates unfinished-upload and pending-import authority while preserving current memberships and completed originals. Nextcloud session/app-password recovery remains a host responsibility. The isolated test verifies this command's effects, not an end-to-end backup restoration.

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

Alice had a pre-existing `background-job:worker --stop_after=5m` process that had remained alive for several days. It could not resolve the newly added Stammbaum job and Nextcloud removed that job registration; fresh PHP processes resolved it correctly. Reloading Apache alone does not reload such a CLI worker.

Before relying on new app jobs, gracefully recycle an identified long-lived host worker through its normal process manager, preserving its arguments and scheduling. In the inspected Nextcloud 34 code, SIGTERM/SIGINT are cooperative: the worker checks the interrupted flag between jobs. Check currently running work first; do not force-kill an active job. Then run the app deployment verification again to reconcile its declared jobs. Alice’s identified idle worker was reloaded this way, with no cron schedule or sibling app configuration changes.

The app installer does not automatically signal arbitrary host processes. `occ background-job:list --class='OCA\Stammbaum\BackgroundJob\ProcessImports'` checks the exact class name; this option is not a substring search.

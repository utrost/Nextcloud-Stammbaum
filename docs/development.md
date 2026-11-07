# Development references and test environment

Reviewed 6 November 2026. Stammbaum is the third app in this development environment: Library is published; Inventory, now displayed as Relate, is in advanced development. Reuse their established Nextcloud practices while keeping Stammbaum independently buildable and deployable.

## Source projects

| Project | Local checkout | Documents reviewed |
| --- | --- | --- |
| Library | `/home/uwe/Developer/utrost/Library` | `docs/nextcloud-app-guidelines.md`, `docs/nextcloud-ui-starter.md`, `docs/local-ci-cd.md`, README |
| Relate / Inventory | `/home/uwe/Developer/inventory` | `docs/alice.md`, `docs/development.md`, `docs/architecture.md`, `docs/compatibility.md`, `docs/usability-acceptance.md`, README |

The active Relate checkout is `/home/uwe/Developer/inventory`; `/home/uwe/Developer/utrost/inventory` contains an older specification, and `/home/uwe/Developer/utrost/relate` contains an earlier documentation handoff. Relate retains the technical identity `utrost_inventory`; its display-name change did not rename storage, routes or packages.

Reviewed local revisions: Library `a010c9a0663391c0de5b34c2332dd49ad38f85e8`, Relate `9b3ff69effe5c27e1bd0b031d5d26b757affd47a`. The reviewed files had no local modifications. Library's local revision was not available through GitHub during this review; its local CI/CD document is a local reference, not a claimed published source.

Available public references:

- [Library architecture/app guidelines](https://github.com/utrost/Library/blob/c4c541b6df58ddea57d62d4a51c3b696b3be963c/docs/nextcloud-app-guidelines.md)
- [Library UI starter](https://github.com/utrost/Library/blob/c4c541b6df58ddea57d62d4a51c3b696b3be963c/docs/nextcloud-ui-starter.md)
- [Relate development](https://github.com/utrost/inventory/blob/9b3ff69effe5c27e1bd0b031d5d26b757affd47a/docs/development.md)
- [Relate architecture](https://github.com/utrost/inventory/blob/9b3ff69effe5c27e1bd0b031d5d26b757affd47a/docs/architecture.md)
- [Relate compatibility](https://github.com/utrost/inventory/blob/9b3ff69effe5c27e1bd0b031d5d26b757affd47a/docs/compatibility.md)
- [Relate usability acceptance](https://github.com/utrost/inventory/blob/9b3ff69effe5c27e1bd0b031d5d26b757affd47a/docs/usability-acceptance.md)

## Alice: authorized development system

The user explicitly authorizes Alice for development/testing and states that no backup is necessary for this system. Routine Stammbaum development deployments therefore do not require a pre-deployment backup or another approval solely because a sister-project runbook prescribed one. This instruction supersedes those inherited operational precautions for Alice.

Keep changes within Stammbaum's app directory, tables, configuration and fictional fixtures. Relate and Library share the Nextcloud installation; their records and installations are not disposable Stammbaum fixtures. Use dedicated disposable fixtures for broad fault injection, host-wide account changes or load tests that would interfere with the other apps. The initial documentation review was read-only. Subsequent authorized development and app deployment are recorded in [iteration-01.md](iteration-01.md); no Alice pre-deployment backup is required.

Connection addresses remain in the existing local Alice runbook and workstation configuration, not copied into public issue bodies. The verified containers are `nextcloud`, `nextcloud-db` and `nextcloud-cron`. This workspace runs directly on Alice (`hostname` reports `Alice`); the default Docker context uses `unix:///var/run/docker.sock`. Deploy through local `docker cp` and `docker exec`, as Library’s `scripts/local-cd.sh` does. No SSH connection is needed.

### Observed versus documented environment

| Evidence | Result |
| --- | --- |
| Live read-only `status.php` check, 6 November 2026 | Nextcloud `34.0.3.2`; installed; maintenance off; no database upgrade required |
| Local Docker checks | Direct container access verified via the default Unix socket; `occ status` succeeds as `www-data` |
| Live container checks, 6 November 2026 | PHP `8.5.10`, Relate `0.28.1-alpha.1`, Library `0.4.0-alpha.2` enabled; database container image `mariadb:11` |
| Earlier Relate runbook | MariaDB `11.8.9`; exact live database patch version not rechecked |

Use Nextcloud 34 as the initial development target, subject to the supported-version decision in [F01](https://github.com/utrost/Nextcloud-Stammbaum/issues/2). Do not infer wider support from Library's separate release matrix. Local container access is confirmed. Earlier failed SSH attempts were irrelevant because this workspace is already on the Docker host.

Useful verified read-only checks are:

```sh
docker exec -u www-data nextcloud php occ status --output=json
docker exec -u www-data nextcloud php occ app:list
```

The current HTTP development environment requires feature detection for secure-context-only browser APIs. Relate specifically encountered unavailable `crypto.randomUUID`; use an appropriate fallback for local UI keys without weakening server-generated identifiers or security tokens. Test on the actual HTTP origin rather than only localhost.

The no-backup development instruction does not remove Stammbaum's product requirement for consistent backup, restoration and credential invalidation. [I06](https://github.com/utrost/Nextcloud-Stammbaum/issues/45) implements and verifies that capability in an isolated fictional recovery fixture; it is not an Alice deployment prerequisite.

## Patterns to reuse

| Area | Application to Stammbaum | Existing issues |
| --- | --- | --- |
| Native architecture | PHP controllers/services, Nextcloud APIs and Vue frontend; stable app identity and table prefix; narrow tested compatibility. Keep Library/Relate optional references, not dependencies. | F01, F02, F03 |
| Route security | Explicit method/auth/CSRF/authorization/DTO/cache contracts, generic unavailable-object responses, allowlisted outputs and safe diagnostics. Replace owner-only checks with tree roles and all-target privacy. | A03, A04, I02 |
| Schema and queries | Align migrations and reviewer-facing schema; index actual authorized query shapes, use stable pagination and batched lookups. Avoid loading the full tree or N+1 list requests. | F03, W01, W02 |
| Private media | Relate's private app-data originals and regenerable previews fit this product. Unlike Library's canonical Files model, genealogy originals must stay behind tree authorization. | F04, M01, M02 |
| Jobs | App-owned durable state, leases/checkpoints, bounded progress and cancellation through Nextcloud background execution. Recheck initiating permissions before publication. | F06, M06, M08 |
| App shell | One Nextcloud navigation/content/details shell, consistent header/toolbars, theme tokens and reachable mobile controls. Keep technical metadata secondary. | F02, W03, I05 |
| Editor and navigation | One canonical editor; explicit Save/Discard; applied list context separate from typed search; preserve focus/scroll and graph focus versus selection. | W01, W04, W05 |
| Request failures | Ignore stale responses; failed lookup is not an empty result; preserve failed page cursor and user input. Report uploading separately from server processing. | W01, W05, M01, I05 |
| Draft storage | Detect blocked/quota-exhausted/malformed browser storage, keep in-memory edits and show honest recovery limits. Do not promise persistence across browser closure. | W05, I05 |
| UI verification | Desktop 1440px, phone 390px and narrow 320px; keyboard/IME, long text, theme contrast and reduced motion. Coarse-pointer controls should offer 44px targets. Test focus/hit targets, not just overflow. | I05 |
| Packaging | Build locally with lockfiles; single app-root runtime archive and checksum; exclude development/private files; install/enable/upgrade and smoke the exact archive. Version assets to diagnose stale browser caches. | F02, I08 |
| Test evidence | Layer service/frontend/build/package/HTTP/browser tests; verify MariaDB behavior separately from SQLite browser fixtures. Record actual versions, outcomes and skips. | I07, I08, I09 |

Start with one thin journey: empty tree → create person → search/open → edit/save/discard → return to the same view. Extend the shared contracts as evidence, relationships and exchange features arrive.

Do not copy Relate's single configured-owner gate, its catalogue inheritance model, Library's file-deletion behavior, old version assertions or their production backup gates. Do not copy another app's browser tests wholesale: some mutate owner configuration or seed large catalogues. Review their fixture scope and adapt them to Stammbaum.

## Immediate backlog implications

The existing foundation and delivery issues are updated with these references rather than duplicated. Alice supplies an existing development target; F02 does not require provisioning another permanent host. Separate disposable databases/containers remain useful for migration and isolation tests. The roadmap remains the release 0.15 parity scope with deferred baseline obligations; reading sibling documentation alone does not implement or certify any of those features. The subsequent first iteration and its tests are recorded separately in [iteration-01.md](iteration-01.md).

# Second iteration — reviewed GEDCOM import

Version **0.1.0-alpha.9** adds a native manager-only GEDCOM journey: upload an unchanged original, wait for analysis, review diagnostics and a preview, explicitly confirm the exact result, then activate it atomically in an empty tree. The work builds on [the manual research iteration](iteration-01.md). The intermediate alpha.8 profile remains executable for stored runs; alpha.9 uses a new profile for corrected calendar-escape and orphan-note diagnostics.

The named profile **`nc-gedcom-551-alpha2`** is a bounded subset, with an explicit [support matrix and retention contract](gedcom-import-profile.md). Basic people, historical dates/places, explicit family membership, partnership assertions, notes, sources and citations become editable research. Unsupported standard fields block activation; vendor extensions remain preserved with warnings. No full M2a–M2d compatibility, current-state export or release-0.15 parity is claimed.

## Delivered contracts

| Backlog | Implemented increment | Remaining boundary |
| --- | --- | --- |
| [F06 #7](https://github.com/utrost/Nextcloud-Stammbaum/issues/7) | Durable import states, bounded cron/CLI worker, lease fencing, recovery, cancellation, current-account/generation checks and transactional publication | General export/integration job system and richer progress remain unfinished |
| [M04 #35](https://github.com/utrost/Nextcloud-Stammbaum/issues/35) | Versioned strict GEDCOM 5.5/5.5.1 reader, UTF-8/ASCII/BOM UTF-16, preserved bytes/hierarchy/offsets, bounded work and original download | Broader profile evolution/consumer verification remains unfinished |
| [M05 #36](https://github.com/utrost/Nextcloud-Stammbaum/issues/36) | Closed semantic whitelist, immutable source-line maps, exact supported citation targets, conservative privacy and honest bounded diagnostics | Cumulative M2a–M2d names/events/contacts/repositories/media/adoption mappings remain core work |
| [M06 #37](https://github.com/utrost/Nextcloud-Stammbaum/issues/37) | Manager-only upload/review/raw/original/confirm/cancel, deduplication, review hashes/revisions, empty-tree checks, persistent quotas and atomic activation | Complete acceptance with the full mapping profile remains unfinished |

The UI uses Nextcloud sessions and CSRF. An unrelated Nextcloud administrator has no genealogy access. English/German import screens distinguish upload, analysis, review, activation, failure and cancellation; they never show an invented progress percentage. Original downloads are labeled explicitly and do not claim current-state export.

Three new tables hold runs, ordered nodes and mappings. Private originals live in `IAppData/imports`. Failed/cancelled reservations remain counted; there is no automatic purge or misleading restart button. The existing recovery-authority command also invalidates pending imports through membership generations.

## Verification record

The implementation is tested with fictional fixtures in the isolated Nextcloud/MariaDB environment. The import tests cover review bindings and idempotency, private reports/raw/originals, unsupported fields, revoked-and-regranted authority, stale destinations, cancellation, expired leases, real database rollback faults, parallel workers, concurrent readers, original corruption, rate/quota/pending capacity, the 2,000-person limit and native cron execution from the browser journey.

Local verification: **54 integration/browser tests passed** with zero failures, skips or retries in 8.0 minutes. After a canonical-partnership ordering correction, all 15 import tests passed again. All **16 import tests** then passed in 2.6 minutes, including exact old-profile compatibility and new-profile activation. After broadening the calendar-escape recognition, the parser unit suite and affected profile integration test passed again. The final 2,000-person analysis/review/activation fixture took 4,505 ms; this single fictional measurement is not a production throughput guarantee. Three JavaScript unit tests, PHP date/person checks, GEDCOM encoding/semantic/resource-boundary tests, PHP 8.3 syntax, formatting, runtime dependency audit and documentation/archive checks passed.

The exact runtime package SHA-256 is `475d414ffc49090c7eb0f6fc51eddd7dd9603066486a6a9dbb0d3b866a8d3182`. The final alpha.9 package upgraded the isolated Nextcloud 34.0.4 / MariaDB fixture and Alice’s Nextcloud **34.0.3.2 / PHP 8.5.10**. Alice’s authenticated smoke used a temporary ordinary Nextcloud account and the actual HTTP origin. Normal host background processing analyzed the file and activated three fictional people only after explicit review. The unchanged original download and imported-family view passed without browser errors; the final Alice journey completed in 126 seconds. Its temporary account, genealogy/import rows and original file were removed afterward.

An existing long-lived host worker initially could not load the new job and removed its registration. Its installed cooperative signal handling was inspected, then the identified idle worker was gracefully reloaded with the same arguments. The final update refreshed the two verified idle workers cooperatively and preserved their count and arguments. Cron scheduling was unchanged. Deployment now resolves and idempotently verifies the app’s declared jobs in a fresh process; the missing-job repair was also exercised in the disposable instance. See [operator instructions](operations.md) for the host-worker boundary.

Alice remained healthy with maintenance off and no database upgrade pending. Library **0.4.0-alpha.2** and Relate **0.28.1-alpha.1** retained their versions and enablement. No development-system backup was created. Fault injection, quota manipulation and concurrency tests ran only in the isolated fixture.

All 59 work-issue titles, bodies and milestones still match `backlog.json`, with the roadmap tracker making 60 issues total. The original 100 archive hashes, local HTML assets and A01–A30 coverage checks pass. Fresh remote CI results are reported on the draft PR after execution.

![Fictional GEDCOM import review](screenshots/iteration-02-import-review.png)

## Scope retained

No issue specification, original priority, A01–A30 mapping or archived source document is weakened to match this increment. The original 60-issue backlog remains authoritative. Original-file download does not implement [M07 current-state export](https://github.com/utrost/Nextcloud-Stammbaum/issues/38), [M08 export jobs](https://github.com/utrost/Nextcloud-Stammbaum/issues/39), ZIP/media matching, scoped external credentials, integration APIs, webhooks, portable archives or full operational recovery.

The original private-storage reservation limits and global write guard remain conservative alpha choices. The advertised import bounds are exercised in a fictional fixture; production throughput, universal consumer interoperability and broad accessibility certification are not claimed.


## Subsequent workspace iteration — alpha.13

The native workspace now uses Nextcloud font and colour tokens, readable diagram labels, and an expanded person research layout. The expanded view keeps evidence beside retained assertions and relatives while preserving the mounted diagram, focus, selection, camera and upload drafts. Import review groups counts compactly, places the active review ahead of previous runs on phones, and retains explicit confirmation, diagnostics, original download and structure access.

Personal home is separate from the shared tree home. All members can set their own home; managers can change or clear the shared home. The most recently saved named view supplies an accessible opening focus and camera, followed by personal home, shared home and the first visible person. Clearing personal home preserves camera and branch history. Preference conflicts retain the proposal and require explicit comparison/retry. Uncertain saved-view retries reuse the original operation, preventing duplication when a successful response is lost. Existing saved views migrate on their next successful preference write into the new transactional `stb_preferences` table.

Playwright review also exposed and reproduced dense-family Fit clipping and absent two-finger zoom. Both are fixed, including a 100% focus reset and a rendered 200-person phone check. Resizing keeps the viewed center; partner lines no longer inherit the unknown-parent pattern. The German catalog now covers the literal UI and validation messages checked by the source audit; browser checks verify translated errors without translating authored names, places or literal dates.

This work addresses the focused UI follow-ups #66–#68 and personal-home requirements #14, and advances the existing diagram/navigation/localization work #27, #28 and #43. Local expansions, broader device/accessibility certification and the full release-0.15 acceptance matrix remain open. Native screenshot baselines test the app's own design rather than reproducing the standalone illustrations.


## Local family exploration — alpha.15

Selected boundary people can expand one additional authorized neighborhood in diagram or text view. Ordered expansions remain bounded at twenty, retain focus/selection/camera and return with Previous branch. Family contexts expand without inventing parentage, including parents omitted from the initial depth-limited group. The original node, edge, neighbor and contextual-group limits remain in force.

Failures clear the drawing, preserve the requested expansion list for Retry, and offer an explicit reset when a reference is no longer available. Late responses are ignored. Read-only members can navigate without gaining genealogy editing rights or advancing tree revisions. Named-view navigation now records the previous mode/depths before applying the new view, so branch return restores the actual prior state.

This advances #26–#28. It does not add expansion persistence to named saved views or copied links, new layouts, reference-connector styling, or release-wide parity certification. The expanded Playwright suite covers ordered/mode-specific neighborhoods, hidden bridges, contexts, limits, repeated ancestry, social loops, revoked visibility, branch history, German read-only phone navigation and failure/retry races. Executed results and deployment evidence are recorded on PR #61 after verification.


## Focused acceptance close-out — 7 November 2026

The alpha.15 runtime is unchanged. Ten additional fictional Playwright regressions fill acceptance-evidence gaps; parser checks additionally cover both supported GEDCOM versions, declaration mismatches, ZIP rejection and the exact inclusive 8 MiB limit. The review covers these eight issue contracts:

| Issue | Accepted contract |
| --- | --- |
| [#3](https://github.com/utrost/Nextcloud-Stammbaum/issues/3) | Native scaffold, clean installation, authentication and startup recovery |
| [#9](https://github.com/utrost/Nextcloud-Stammbaum/issues/9) | Private tree creation, duplicate identities, metadata permissions and empty states |
| [#18](https://github.com/utrost/Nextcloud-Stammbaum/issues/18) | Explicit relationships, atomic relative creation and concurrent ancestry protection |
| [#19](https://github.com/utrost/Nextcloud-Stammbaum/issues/19) | Ordered contexts with explicit membership and no inferred genealogy |
| [#23](https://github.com/utrost/Nextcloud-Stammbaum/issues/23) | Safe notes, private biographies and retained provenance |
| [#27](https://github.com/utrost/Nextcloud-Stammbaum/issues/27) | Native diagram controls and equivalent authorized text navigation |
| [#35](https://github.com/utrost/Nextcloud-Stammbaum/issues/35) | Versioned bounded parsing and immutable original/structure preservation |
| [#37](https://github.com/utrost/Nextcloud-Stammbaum/issues/37) | Reviewed, authorized and atomic empty-tree import workflow |

The public issue bodies and `backlog.json` retain the original requirements and now link each completed checklist to reproducible evidence. Final executed totals and CI are recorded on PR #61. This closes the named contracts, not their broader parent capabilities: #36 semantic mappings, #26 projection/reference semantics, #28 broader navigation, #2 compatibility and #47/#48 release-wide acceptance remain separate obligations. The parser/workflow can correctly preserve and reject unsupported input without claiming it can import that input successfully.

The existing runtime package and Alice installation are byte-for-byte unchanged by this test/documentation close-out; no schema or application deployment is needed.


## Practical GEDCOM compatibility — alpha.16

The new `nc-gedcom-551-alpha3` profile adds repeatable events/occupations, name components and conflicting-name assertions, confidential address/contact/religion details, citation context, and explicit missing-media reference statements. Complete grouped diagnostics keep warnings visible even when the individual message list reaches its 200-entry bound. See the [current support matrix](gedcom-import-profile.md) for exact mappings, native adaptations and remaining exclusions. Alpha1 and alpha2 interpretations remain unchanged, with golden plan hashes and stored-profile activation regression coverage.

The focused fictional browser journey verifies review/confirmation, separate occupations and alternative names, exact original download, private record denial, confidentiality retained after edits, missing-media no-fetch behavior, and desktop/390px/320px layouts. Parser tests cover unsupported nested structures, required FACT types, repeated singletons, original profile hashes and literal attribute wording. The complete Playwright suite remains the release check; its run is recorded on the draft PR and the #36 progress comment.

A user-authorized private compatibility run completed browser review and atomic activation, matched all published object counts to the reviewed plan, verified provenance for every imported record, checked the unchanged original, and verified one tree revision increment. It used an ephemeral ordinary account on the isolated Nextcloud fixture. The temporary tree, imported objects, preserved original and account were removed. No private input, names, screenshots, traces or detailed reports are part of the repository or CI artifacts. Public regression fixtures are fictional.

This advances #36 without closing its cumulative M2a–M2d contract. Corroborated ADOP BOTH, repeated/ambiguous names, source repositories and native media-library MISSING/EXTERNAL objects remain unfinished. Missing references currently live as editable research details; package matching, current-state GEDCOM export and full migration remain separate open work.

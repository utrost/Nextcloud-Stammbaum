# Tighter Nextcloud integration

Design proposal, 8 November 2026. This is new enhancement scope requested by the user, outside release-0.15 parity acceptance. Implemented increments and remaining limits are recorded in the [Tasks](tasks-iteration.md), [Contacts](contacts-iteration.md) and [Calendar](calendar-iteration.md) iteration notes. Initial compatibility target: Nextcloud 34; exact supported versions are pinned and verified per adapter.

## Product direction

Let a researcher move between genealogy, research tasks, appointments and current contact details without entering the same context repeatedly. Keep genealogy evidence authoritative in Stammbaum and respect each Nextcloud application's independent permissions. All integrations are optional; unavailable apps must never prevent normal research.

| Entry point | Journey | Owner of resulting information |
| --- | --- | --- |
| Person, source, event or medium → Create research task | Review a minimal title and backlink, choose a writable task list, optionally set due date and priority, create, then open or refresh status | Tasks owns scheduling, text and completion; completion never proves a genealogy fact |
| Person/event → Add to calendar | Choose an appointment or an eligible anniversary, review exact date/recurrence and copied fields, select a calendar, create, then open | Calendar owns the appointment; Stammbaum owns the underlying historical assertion |
| Person → Link contact | Search the current user's authorized address books, inspect a candidate and link explicitly; optionally review copying selected current contact fields into Stammbaum | Contacts owns the current card; imported genealogy contact records are independent reviewed copies |
| Media → Choose from Nextcloud Files | Existing explicit private image/PDF copy workflow | Stammbaum owns the unchanged imported original; Files retains its independent source |

Links belong to the initiating account, not every member of the tree. Other members cannot enumerate someone else's tasks, address books, calendars or connection metadata. Use a small contextual action menu and a personal **Connections** view for status, refresh, conflict resolution and unlinking. No new top-level genealogy workspace is required for the first release.

## Scope and sequence

1. Establish supported adapters, capability discovery and the shared review/authorization contract.
2. Implement the connection registry and safe lifecycle before enabling writes to other apps.
3. Deliver Tasks first, then Calendar, then Contacts.
4. Complete cross-app privacy, compatibility and browser acceptance before advertising the integrations as supported.

Files selection remains owned by #74; explicit GEDCOM media matching stays in #34/#36. This concept does not reopen completed media metadata/gallery work. Generic external credentials, API contracts and webhooks remain #40–#42, and are not prerequisites for same-instance, session-authenticated interactions. Broader collaboration/OAuth remains #58. Confidential genealogy contact records remain #24; this design adds deliberate address-book linking rather than replacing them.

No Office support, external servers, OAuth delegation, automatic tree-to-address-book export, bulk synchronization, invitations, attendee emails, public feeds, shared task assignment, folder scanning, OCR, face matching or genealogy inference. Unified Search, Dashboard widgets, Activity/Notifications, Deck, Mail, Talk and direct Library/Relate object links are possible later proposals, not commitments in this issue set. Existing Library and Relate are references, never runtime dependencies.

## Ownership and privacy contract

Three independent checks are required: the account is enabled; it has current tree/record access; and it has the needed permission on the destination or source in the other app. Administrator status grants no genealogy bypass. A Nextcloud account, a genealogy person and an address-book card are separate identities.

Outbound task/calendar content is a disclosure/export, even when it is only a person name or backlink. Require existing export authority (owner/manager or an explicit viewer/editor export grant), enforce all-target visibility, and show exactly which fields leave Stammbaum. Default previews exclude living people, unknown living status and management-only content, as current export does. Explicit inclusion is separately acknowledged and allowed only within existing role/visibility permissions. Unauthorized previews return no hidden counts, labels or identifiers. Ordinary membership is sufficient for a private local contact link when the person and card are readable; copying into genealogy requires manager or owner authority under #24 and revision checks. Export permission never grants genealogy edit rights.

Default to an owned, unshared destination collection and minimal content. A user may deliberately choose another writable collection only after a review explains its independent audience; if sharing cannot be determined, say that rather than claim privacy. Shared collections are not a mechanism for granting tree access. Never change collection sharing as a side effect. A backlink contains an opaque target route, not a person's name, credentials or a bearer token, and always rechecks tree access when opened.

Copy confirmation explains that destination readers and synchronized devices can retain the information. Tree revocation stops new source reads and updates; it cannot recall existing external copies. Destination sharing can change later. There is no claim of atomic access revocation across two applications. Recheck authority immediately before an outbound write and after completion; any intervening revocation marks the operation as requiring cleanup and suppresses source content. Record and test this residual cross-system race, rather than calling it a transaction.

Never place genealogy details in URL query strings, logs, browser notifications or diagnostic messages. Do not embed original photos/PDFs or private download links in tasks, calendar entries or contacts. Keep caches private and bounded; destination content is fetched only for the acting user and never included in shared genealogy responses.

## Adapter and API design

Use documented public Nextcloud interfaces where they support the complete permission/ETag contract, or same-origin authenticated CalDAV/CardDAV. Confirm the precise supported mechanism against Nextcloud 34 before implementation; public manager discovery does not imply a general write API. Do not access another app's tables or rely on unversioned private PHP classes, arbitrary remote URLs, administrator impersonation or stored account passwords. No personal tool credential is needed for this feature.

The adapter issue must prove a safe review-to-write flow, including server-authorized payload construction and destination permission checks. If the chosen interface cannot provide it, keep the action unavailable and record the limitation. The first release uses foreground explicit operations; no unattended outbound synchronization or background impersonation. Background work may invalidate local connections using existing authority-generation patterns without accessing DAV content.

Proposed authenticated application contracts under `/apps/stammbaum/api/v1` (final route names belong in #41's route policy):

- `GET /integrations/capabilities`: per-account availability and supported actions, without other users' collections.
- `GET /trees/{tree}/connections`: authorized, owner-only, paginated connections.
- `POST /trees/{tree}/connections/review`: build a bounded preview for one operation, source revision, dependency digest, current authority generation, destination identity and destination ETag when applicable.
- `POST /trees/{tree}/connections`: confirm a single-use review plus idempotency key; return the outcome or an honest uncertain state.
- `POST /trees/{tree}/connections/{id}/refresh` and `/review-update`: read current status or prepare an explicit reconciliation; no silent push.
- `DELETE /trees/{tree}/connections/{id}`: unlink local metadata only. A separate explicitly confirmed remove-created-item operation may conditionally delete an unchanged item originally created by Stammbaum. Never delete a pre-existing linked contact.

Use CSRF protection, current account/tree/resource checks, generic unavailable responses and no-store responses. A narrow account-scoped cleanup operation must allow unlinking an opaque connection after tree access is lost, without returning the lost source identity or any former content. External removal still requires current destination authority and an explicit review of destination data the user can currently read.

Proposed private registry fields: opaque ID, actor UID/account incarnation, tree and typed source IDs, provider, collection/object identity, deterministic random DAV UID, creation ownership flag, source revision/dependency digest, authority generation, last observed ETag, operation/idempotency identity, status and timestamps. Store no raw credentials or durable copies of card/event/task text. Opaque destination identifiers are private metadata, never tree-member-visible fields. Set pagination, review expiry and payload bounds in the adapter contract; no unbounded listing or bulk writes.

Reviews expire after five minutes and are invalid after any bound source or authority change. Process one object per confirmation. Idempotent retries reuse the same UID and conditional create; a timeout is an uncertain outcome, not permission to create another object. Reconcile a destination success followed by a local failure against that identity. Updates/deletes require If-Match or a verified equivalent; conflicts preserve the user's proposed changes. Pin these semantics in contract tests.

## Connection lifecycle

States: pending, linked, source-changed, destination-changed, unavailable, authority-invalidated, outcome-unknown and unlinked. Keep status descriptions understandable in English and German. Refresh failures must never look like an empty list or a successful deletion.

- Source edits flag a connection for review; do not overwrite a task completed or edited in Tasks. Pure connection operations do not change tree revisions or history facts.
- Person/event deletion, media trash or a newly hidden dependency makes the source unavailable immediately. Stop showing its labels, disable updates, and retain only the minimum private cleanup identity. Restoration does not resume a connection automatically.
- Membership/export revocation, disable/delete/recreated account and recovery invalidation permanently invalidate pending confirmations. Regrant requires a fresh reviewed connection; never revive an old operation.
- Destination deletion, collection movement, permission loss or app disable does not delete genealogy facts. Allow retry, relink or unlink with current permissions. Do not find replacement cards by name or email automatically.
- Unlink leaves the external object intact. Removing an app-created object requires a separate review, ownership evidence and its current ETag; refuse silent deletion of an externally modified object. Linked pre-existing contacts are never deleted by Stammbaum.
- Account deletion removes its private connections/receipts under a bounded retention policy specified in the lifecycle issue; a recreated UID cannot inherit them. Restore invalidates reviews/operations before any further writes. No automatic cleanup of originals or unrelated app records.

## Tasks workflow

Support a person, source, shared event or medium as context. Default title is a generic research action; include a source label only in the reviewed eligible payload. Task description contains a short user-authored instruction and authorized backlink, not an automatic biography or evidence dump. Support optional due date and priority; no assignee or attendee workflow in this slice.

Tasks is authoritative for text, due date and completion. Show current status with a refresh action; open the task using a supported link or fall back to opening the task list. Unknown/deleted/unreadable is distinct from completed. Avoid a second local editable task store. Link refresh and task completion must not alter relationships, assertions, media assignments or tree revisions.

## Calendar workflow

Support one-time appointments with explicit timezone/time or an all-day date, and opt-in yearly anniversaries from an exact known month/day. The historical date is never modified by scheduling an appointment. Approximate dates, ranges, non-Gregorian/unsupported dates and year-only assertions cannot silently supply an anniversary; the user can separately enter a research appointment date. When year is unknown, omit age and elapsed-year claims. For 29 February, initial yearly recurrence occurs only on 29 February; explain skipped non-leap years before confirmation.

Keep recurrence rules, timezone, all-day semantics, title, optional location and source backlink visible in review. Do not infer attendees from genealogy participants or send invitations. Do not copy confidential event notes automatically. Source corrections trigger review; never silently rewrite calendar edits.

If a linked Contact supplies a birthday and Nextcloud's birthday calendar already covers it, warn about potential duplication and default to no extra event. Only claim a duplicate when it can be established from current authorized data; otherwise explain that detection is incomplete. The generated birthday calendar is not an editable destination.

## Contacts workflow

Search only address books readable by the current account; select a specific card explicitly and show enough authorized distinguishing information. Do not match by name/email automatically or create genealogy people/accounts from cards. Permit multiple explicit card links per person, with each mapping private to its actor; do not treat duplicate cards as duplicate genealogy people.

Initial scope is linking and optional one-way, field-reviewed copy of current email, phone and postal address into #24's confidential contact records. No outbound card creation/editing or automatic bidirectional synchronization. Show source and destination values, choose append/replace deliberately, preserve provenance without exposing private card identifiers to other tree members, and apply manager-only visibility to copied contact records. Existing #24 authorization requires a manager or owner to create any genealogy contact record; this is stronger than the original #85 wording, which said editor authority. Editors can create private links but cannot copy into management-only records. A card access grant is not authority to broaden genealogy visibility. Contact data copied to a record is retained independently after address-book access is lost, as explicitly acknowledged during review. Later card changes require a new field comparison; never overwrite research silently.

## Verification and release gate

Use fictional accounts, trees, calendars, tasks and contacts in a disposable installation with the exact declared app versions. Do not install or mutate sibling apps on shared Alice merely to satisfy these tests. Preserve Library and Relate; any eventual Alice smoke uses scoped fixtures and needs no pre-deployment backup.

Required evidence: all four roles with/without export grants; admin without membership; living/unknown/management filters; hidden dependencies; foreign-account collection/object IDs; shared/read-only collections; app absent/disabled; source and destination permission changes; stale revisions and ETags; duplicate clicks and lost responses; partial success; account disable/delete/recreation; revoke/regrant; restoration and cleanup. Test that genealogy revisions remain unchanged for external operations, while explicit contact-record copies produce normal revision-checked history.

Playwright journeys must cover creation/linking, review, cancel, reopening, refresh, conflict, deletion versus unlink, permission loss, honest uncertain outcomes and retry in English/German at 1440, 390 and 320 pixels, including keyboard focus and inspected screenshots. Protocol tests verify DAV UID uniqueness, conditional writes, date/recurrence semantics, no attendee emails, no private payload in logs and no original-byte changes. Test upgrade/recovery and capability degradation with no optional app installed. Record executed evidence separately; publishing this design is not test evidence.

## Sources and requirement mapping

Official references checked 8 November 2026 describe the integration mechanisms; they do not certify compatibility with the target installation:

- [Nextcloud groupware](https://docs.nextcloud.com/server/stable/user_manual/en/groupware/): Calendar/Contacts use CalDAV/CardDAV.
- [Tasks upstream](https://github.com/nextcloud/tasks/): CalDAV synchronization and ETag conflict handling.
- [Calendar user manual](https://docs.nextcloud.com/server/stable/user_manual/en/groupware/calendar.html): calendar sharing and the generated Contacts birthday calendar.
- [Nextcloud dependency injection](https://docs.nextcloud.com/server/stable/developer_manual/basics/dependency_injection.html): public services including Contacts manager; exact read/write capabilities require adapter verification.

New requirements are NCINT-01 adapters/review, NCINT-02 lifecycle, NCINT-03 Tasks, NCINT-04 Calendar, NCINT-05 Contacts and NCINT-06 acceptance. They extend the native adaptation; none is an original source requirement ID. Existing RECHT/SICHT privacy obligations and NF-03 localization still apply. A03 (no implicit operator access), A13 (shared-target privacy), A26 (locale without changing research) and A30 (recovery, as applicable) are regression references, not evidence of completed parity. Original requirements stay in their existing issues.

## Published implementation work

Tracker: [#80](https://github.com/utrost/Nextcloud-Stammbaum/issues/80). Milestone: **Nextcloud integration enhancements**. These six implementation issues are separate from parity milestone completion.

| Requirement / issue | Outcome | Dependencies |
| --- | --- | --- |
| [NCINT-01 · #81](https://github.com/utrost/Nextcloud-Stammbaum/issues/81) | Establish supported Nextcloud integration adapters and reviewed write contracts | #2, #6, #10, #11 |
| [NCINT-02 · #82](https://github.com/utrost/Nextcloud-Stammbaum/issues/82) | Track private integration connections and reconcile revocation, conflicts and partial success | #81, #7, #8, #45 |
| [NCINT-03 · #83](https://github.com/utrost/Nextcloud-Stammbaum/issues/83) | Create and follow research tasks from genealogy context in Nextcloud Tasks | #81, #82 |
| [NCINT-04 · #84](https://github.com/utrost/Nextcloud-Stammbaum/issues/84) | Publish reviewed appointments and precise family anniversaries to Nextcloud Calendar | #81, #82, #16, #22 |
| [NCINT-05 · #85](https://github.com/utrost/Nextcloud-Stammbaum/issues/85) | Link genealogy people to Nextcloud Contacts with deliberate confidential field copies | #81, #82, #24 |
| [NCINT-06 · #86](https://github.com/utrost/Nextcloud-Stammbaum/issues/86) | Verify cross-app privacy, compatibility and complete Nextcloud integration journeys | #81, #82, #83, #84, #85, #44, #46 |

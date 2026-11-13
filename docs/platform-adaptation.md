# Native platform decision (F01)

Decision recorded 13 November 2026. This app implements the archived release 0.15 genealogy behavior inside Nextcloud. Nextcloud supplies accounts, sessions, password recovery, database connections, application storage and cron. Stammbaum owns trees, membership, genealogy records, revisions, private originals and domain authorization. A Nextcloud administrator gains no tree access without membership.

## Compatibility boundary

| Component | Declared or intended boundary | Executed evidence |
| --- | --- | --- |
| Nextcloud | Only major 34 in `appinfo/info.xml`; other majors require an independent compatibility run | Isolated 34.0.4.1 fixture and Alice 34.0.3.2 |
| PHP | Minimum 8.3 syntax; host must also meet its Nextcloud release requirements | 8.5.10 runtime and 8.3 syntax check |
| Database | Nextcloud's `IDBConnection` and additive schema migrations; MariaDB/InnoDB is the app's verified database | Isolated MariaDB 11.8.9, including concurrent writes and upgrades. PostgreSQL and SQLite are not certified for this app. |
| Browser | Native Nextcloud UI with keyboard and responsive layouts; no browser-specific APIs required for core genealogy | Google Chrome 148 Playwright on desktop and narrow viewports. Firefox, Safari and Edge remain unverified. |

The official [Nextcloud release/PHP matrix](https://github.com/nextcloud/server/wiki/Releases-and-PHP-versions), [system requirements](https://docs.nextcloud.com/server/stable/admin_manual/installation/system_requirements.html) and [app metadata contract](https://docs.nextcloud.com/server/stable/developer_manual/app_development/info.html) describe the host's broader supported combinations. The `stable` documentation links may move to a newer major; the matrix above is this app's narrower verified claim, not a claim that every Nextcloud-supported PHP/database/browser combination passed. `appinfo/info.xml` blocks other server majors. Vue 3 is bundled locally; the app uses Nextcloud navigation, theme tokens and public PHP APIs with its session, CSRF and translation context. Its own Vue controls keep the genealogy-specific editor and diagram accessible without adding a second UI runtime. There is no separate app server, account table or password service.

## Identity, invitation and session decisions

- A person is an independent genealogy identity. No person, email address or Nextcloud group automatically becomes a member. Existing host accounts may be granted a tree role or may review a 48-hour, single-use invitation and explicitly accept it while signed in. New collaborators first need a host administrator to provision a Nextcloud account; the invitation stays usable until expiry or revocation.
- Nextcloud owns setup, login, password reset, account reauthentication, session lifetime and account disable/delete. Stammbaum neither resets passwords nor terminates other apps' sessions on app startup/restart. Expired sessions lead back to the host login while unsaved browser edits remain subject to the normal draft limitations.
- User disable, password change and account deletion advance membership generations and revoke outstanding invitations; deleted and recreated account IDs do not inherit membership. The app serializes its own final-active-owner checks with membership mutations. A host administrator may forcibly disable/delete the last owner outside that transaction; this is an operator recovery situation, never grounds for fabricating an owner or granting genealogy access to the host administrator.
- A consistent restore requires `occ stammbaum:invalidate-authority` before reopening access. It invalidates outstanding invitations, pending uploads and cross-app confirmations through generation changes. Nextcloud session and app-password invalidation remains host-owned. Separate scoped external-tool credentials are not yet implemented and remain [I01](https://github.com/utrost/Nextcloud-Stammbaum/issues/40).

## Locale, storage and operations

The app's versioned `en`/`de` preference overrides the host language for Stammbaum only. On a new app preference, a host language beginning with `en` selects English; other host languages select German. Research names, literal dates, quotations and saved wording are never translated. Nextcloud owns its account locale and sessions; app preference changes do not alter them.

Original GEDCOM, image and PDF bytes use private Nextcloud `IAppData`, addressed by opaque IDs and authorized downloads. The app enforces 512 MiB retained GEDCOM originals and 1 GiB logical media accounting. Pending and trashed originals remain counted; derived previews and disposable export artifacts are separate. This is not ordinary user Files storage or a public share. Host cron runs app-registered import/export jobs; each job rechecks initiating authority, revision and membership generation before publication. The host owns the cron schedule, but the app owns its job records and limits.

A recoverable installation must consistently restore Nextcloud identity/configuration, all `stb_` tables and private AppData originals, then invalidate restored authority. Alice is an authorized development instance where the user waived a pre-deployment backup; that does not waive the product's recovery obligation [I06](https://github.com/utrost/Nextcloud-Stammbaum/issues/45). See [operations](operations.md) for the exact command and package process.

## Verification and remaining limits

The isolated fixture tests a host administrator without tree membership, invitation acceptance, account disable/password/delete/recreation, role and visibility checks, and an expired-session recovery path. The app has not certified a wider database/browser matrix, host-operated identity recovery after forced last-owner removal, or an end-to-end production backup/restore. Those stay in their delivery issues rather than being silently counted as platform parity.

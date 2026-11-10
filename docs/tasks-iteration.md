# Alpha.22: first Nextcloud Tasks journey

This iteration implements a small part of [the optional Nextcloud integration concept](nextcloud-integration-concept.md) and issues #81–#83. It does not close those issues or change the release-0.15 parity baseline. The exact verified development target is Nextcloud 34.0.4, Tasks 0.18.1 and the built-in DAV backend 1.40.0. CI installs the official Tasks 0.18.1 release only into its disposable fictional fixture and checks the pinned SHA-256; Alice already has Tasks 0.18.1.

## Delivered journey

An account with export authority can open a person and choose **Create research task**. The app discovers writable calendars through Nextcloud's public calendar manager, then uses the signed-in account's same-origin CalDAV session to identify collections supporting VTODO. The user enters a title, optional note and due date, reviews the copied content and separate task-list audience, then confirms. Living or unknown-status people require a separate explicit inclusion choice. A management-only person is available only to a manager/owner. The task includes a route back to that person; opening the route rechecks current tree access.

The server creates a private operation row with a random UUID and source/membership revisions. The browser submits one VTODO at a deterministic object name with `If-None-Match: *`. On success the server verifies the VTODO UID through the public calendar search interface, marks the link as created and discards the temporary task text. Repeating the same confirmation reuses the operation ID; the conditional DAV write prevents overwriting an existing object. A failed verification is shown as uncertain rather than as a successful task. Review data expires after five minutes; new task operations are limited to 20 per account per hour. Pending copied task text is scrubbed after that time when connections are loaded; the opaque ID remains for investigation.

Links are per account, not shared with the tree. The app does not grant access to task collections, alter genealogy facts or change tree revisions for task operations. The private registry is removed on account deletion/recreation. Password change or account disable invalidates pending operations; later membership changes prevent older operations from being completed or shown as active. The destination Tasks app and any devices it syncs with retain their own copies and permissions.

## Architecture boundary

Nextcloud 34's public calendar manager supports discovery, permission checks and search, but does not expose a general VTODO write method. The first slice therefore performs the DAV PUT in the authenticated browser session. The server authorizes and constructs the payload immediately before returning it. A tree permission change between that response and the browser's DAV PUT cannot be made atomic across the two apps; the post-write check refuses to publish a valid Stammbaum link after a source or authority change, but it cannot recall the external copy. The user is told about this disclosure boundary. A future server-mediated adapter or stronger protocol flow is required before #81/#82 can be closed.

Further work remains in #81–#83: collection audience classification, a complete account incarnation and cleanup contract, direct task status/completion refresh, destination deletion and movement handling, personal unlink/removal UI, source/event/media entry points, priority, due-date/timezone refinements, and tests for revocation exactly between authorization and DAV publication. Current **Open Tasks** goes to the Tasks app rather than relying on an unverified item URL. Calendar and Contacts are separate #84–#85 work.

## Verification

The focused Playwright test uses a fictional person and real VTODO collection. It checks the review at 1440, 390 and 320 px, keyboard confirmation, the persisted task UID/backlink, conditional duplicate rejection, no tree revision change, denied viewer export, living/unknown default, idempotent reservation and stale person edits. The entire browser suite, CI and Alice smoke results will be recorded after execution; documentation checks alone do not certify this journey.

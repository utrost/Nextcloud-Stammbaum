# Native app architecture

## Native application

The stable app ID is `stammbaum`, PHP namespace `OCA\Stammbaum`, table prefix `stb_`. PHP controllers use Nextcloud's authenticated session, CSRF middleware, service container, migrations, user events and `IAppData`. A Vue 3 application runs inside the native Nextcloud shell. Vite builds content-hashed static assets; no separate application server or authentication database is required.

The initial supported target is Nextcloud 34. PHP 8.3 syntax and PHP 8.5 runtime have been checked. MariaDB is tested; PostgreSQL and SQLite compatibility is not claimed. Runtime dependencies are Nextcloud and bundled Vue. Build tooling and test tools are excluded from release archives.

## Authorization and writes

`Trees` resolves an enabled Nextcloud account and an active tree membership for every request. Viewer, editor, manager and owner are independent per tree. Managers and owners can export; other roles hold a separate export grant, although export generation is not implemented yet. Nextcloud administrator membership is never a genealogy bypass.

Person, assertion, source, citation, record, context and media visibility is either `members` or `management`. Shared citations require access to their source and every target. Shared records require access to every participant. Media and contexts require access to every assigned person. List queries apply these conditions before pagination. Missing and inaccessible object IDs produce the same unavailable response. Responses and authorized original/preview downloads use `private, no-store`.

`Store::write` uses a transactional singleton write guard. It serializes membership changes, graph-cycle checks, revision checks, quota reservations and app writes across PHP workers. This conservative installation-wide lock favors correctness for the alpha; it is not a high-throughput claim. All SQL values are bound parameters. Integer page offsets and bounded table identifiers are application-controlled.

Mutations carry the record revision. A stale write returns HTTP 412; omitted revisions return 428. User-created records use actor/scope/request-bound idempotency receipts. Receipts retain result identities, never replay private response bodies, and responses recheck current access. Receipt expiry/pruning and a broader retry policy remain unfinished.

## Storage

| Tables | Responsibility |
| --- | --- |
| `stb_state` | Serialized-write guard; lazily initialized because fresh Nextcloud installs run schema-only migrations |
| `stb_trees`, `stb_members`, `stb_invites` | Tree metadata, role/export grants, persistent membership generations and hashed single-use invitations |
| `stb_people`, `stb_claims`, `stb_relations` | Current people, retained assertions and explicit active/inactive parent/partner connections |
| `stb_sources`, `stb_citations`, `stb_targets` | Reusable sources, citations and independent target effects |
| `stb_records`, `stb_participants` | Shared events, notes, contacts, structured details and ordered participants |
| `stb_contexts`, `stb_context_people` | Ordered family groupings and references to existing connections |
| `stb_preferences` | Per-account/per-tree saved views, personal home and opening view, with transactional revisions |
| `stb_media`, `stb_media_people` | Original integrity/quota reservations, publication state, assignments and reversible crop/portrait metadata |
| `stb_history`, `stb_receipts` | Attributed operations and creation retry identities |
| `stb_imports`, `stb_import_nodes`, `stb_import_maps` | Durable import runs/leases/review bindings, immutable ordered input structure and source-line ownership mappings |

Language uses app-scoped Nextcloud user configuration without modifying the host's language setting. Personal home and saved views use the app-owned preference table, with legacy user-configuration fallback as described below. Saved views are filtered against current access before being returned.

Original files live in the app's private `IAppData` `originals` folder under random opaque IDs. They are not ordinary user Files objects and have no public-sharing route. Uploads validate type, decoded image dimensions and byte limits before reserving quota. The reservation commits before filesystem writing; interrupted originals remain counted. A hash-verified original becomes visible only after another current-role and membership-generation check. Revocation followed by regrant cannot revive a pending upload. There is no physical-purge interface.

Alpha.17 adds two CSRF-protected editor endpoints: `POST /trees/{tree}/media/select-file` resolves the native picker's path strictly within the current user's Nextcloud Files root and returns file ID, ETag, name, MIME and size; `POST /trees/{tree}/media/import-file` accepts that ID/ETag plus the normal title, visibility, optional person and idempotency key. Confirmation re-resolves the file through the current user's accessible nodes, holds a shared source lock, reads at most 10 MiB plus one byte, validates actual content with the upload pipeline and rechecks source version/access before publication. File paths and share metadata are not persisted in genealogy records. Copies use the existing private quota reservation and integrity verification. Selecting a file does not mutate genealogy; importing publishes one ordinary medium. Later source edits, deletion or share revocation do not alter an already-confirmed private copy. The UI explains this boundary explicitly. No source file is modified. Readable shared files are supported; Office documents and arbitrary URLs are not.

Previews are separately decoded and re-encoded PNG responses. Crops are normalized assignment metadata and never overwrite original bytes. Alpha.18 adds private derivative caching, gallery thumbnails and a larger image/PDF-page viewer. JPEG orientation/EXIF-aware presentation remains #69; cache renderer version `raster-v1` explicitly retains the existing raw-image crop coordinates. A future orientation change must version the cache and define crop migration.

`GET /trees/{tree}/media/{id}/preview` accepts only sizes 320, 600 (backward-compatible default), and 1600, plus a one-based PDF page. `preview-info` supplies authorized page count and media revision. The viewer uses raster pages, never an executable document iframe. Original downloads stay attachments, byte-identical and authorization checked. Current membership generation, medium visibility, every assigned person and medium revision are rechecked before returning cached or generated bytes. A denied gallery/viewer request clears displayed pixels and removes the inaccessible card; focus/visibility changes and a 30-second visible-preview refresh recheck access. Already downloaded content cannot be recalled.

Private AppData folder `media-previews-v1` holds checksum-enveloped disposable values keyed by source SHA-256, format, renderer version, normalized assignment crop, size and page; PDF page counts have a separate versioned key. Source originals are integrity-checked even on cache hits. Per-key locks suppress duplicate rendering; a separate budget lock serializes eviction/publication. The installation-wide derivative budget is 64 MiB, 256 entries, 12 MiB per value plus its 65-byte checksum envelope. Entries expire after seven days; expired/oldest entries are removed on writes, and expired hits regenerate. Cache corruption or deletion regenerates derivatives without genealogy/tree revision changes. If the budget lock is busy, the authorized derivative may be served without caching. Locks are at most 64 characters to fit Nextcloud's database provider.

PDF support is optional: Linux `/usr/bin/pdfinfo` and `/usr/bin/pdftoppm` (Poppler) plus `/usr/bin/prlimit`. Documents are limited to 100 pages; encrypted or malformed PDFs have a download fallback. Each subprocess has 256 MiB address-space, five CPU-second, eight wall-second, 12 MiB file-output and 64-file-descriptor limits; captured diagnostics are capped at 64 KiB. The PDF is placed in a random private temporary directory and removed in `finally`. One requested page is rasterized within the chosen pixel box, then re-encoded to strip metadata. No shell, PDF scripts, attachments, links or remote document services are executed. Rendering is bounded foreground work with lazy loading and explicit retry; it does not add unbounded background jobs or claim richer asynchronous job progress. See [preview delivery notes](media-previews-iteration.md) for runtime and verification details.

The diagram keeps focus, selection and camera independent. Resizing preserves the viewed center; expanding research keeps the diagram mounted. Fit supports the full bounded 200-person projection, including narrow viewports; the percentage control restores the focus to a readable 100%. Saved camera zoom is validated between 0.1% and 200%. Two-finger gestures transform the camera around their midpoint without selecting a card or changing genealogy.

### Local family expansion

`GET /trees/{tree}/graph` accepts optional repeated `expand[]=<person-id>` query parameters. The list contains at most 20 distinct IDs, in application order. Each anchor must already belong to the currently authorized base projection or a preceding expansion; unavailable, foreign or unreachable anchors return the same 404. Malformed, duplicate or oversized lists return 422. Existing clients may omit the parameter.

Each anchor adds one neighborhood: parents in ancestor mode, children in descendant mode, and parents/children/partners plus authorized family-context members in family mode. Context membership creates no relationship. Expanding an existing partial context includes its previously depth-excluded members, preserving recorded member order. Identity and relationship maps prevent duplication and terminate repeated ancestry/social loops. The existing 200-person, 1,000-line, 400-neighbor/person and 20-context limits remain global to the response. Limit feedback describes an incomplete view, never a complete absence of relatives.

Responses add `boundaryIds` (only displayed people with authorized, mode-eligible neighbors outside the projection) and `expansions` (the accepted ordered list). Context boundary discovery uses the same visibility predicate as context listing and an existence query, avoiding full context hydration for every displayed person. Boundary indicators reveal neither hidden IDs nor hidden population counts.

The shared expansion controls work in diagram and text views. They preserve focus, selection and camera; the deterministic layout may reflow as people are added. Previous branch stores expansions and text/diagram choice alongside the existing focus, selection, mode, depths and camera. Refocusing, opening a named view, changing mode/depths or switching trees starts a fresh expansion list. Reset is explicit. A failed graph request clears the drawing and retains the intended list for Retry; obsolete responses cannot replace a newer graph. Local expansions are session navigation state, not genealogy mutations, database preferences or copied-link parameters. No schema migration is required.

## Genealogy semantics

Unknown names, dates, sex and living status remain unknown. Partial, approximate, ranged and literal dates preserve their form. Calendar validation and mixed-precision boundary checks do not replace missing components with invented dates.

Editing a person's name or life values retains previous assertions. Historical assessment is independent of display preference. Explicitly releasing a private person's current values creates releasable current assertions while retaining restricted historic values. The separate [GEDCOM import profile](gedcom-import-profile.md) retains original bytes, ordered nodes and mappings in addition to the current assertions/history.

Parent connections have explicit types. Biological cycles and duplicate parent connections are rejected; nonbiological loops remain navigable with bounded traversal. Partnerships require an explicit action; a further episode requires confirmation. Atomic relative creation rolls back the new person when its connection fails.

Contexts group and order people without generating parentage or partnerships. Inactive connection references remain readable until a context is saved, when those references are removed. Their underlying relationship history is retained.

Shared events have an immutable originating person and category. Saving confirms the union of previous and proposed participants, including explicitly removed participants. Contacts and religious affiliation are always management-only. Original entry wording remains retained and is filtered independently when current wording is released.

Notes render a small, text-node-based formatting vocabulary. HTML and image instructions remain literal. Only deliberate HTTP(S) links are rendered; opening a note fetches no external resource.

Logical person deletion deactivates connections and clears a shared home. The UI reviews counts against a tree revision before deletion. Shared records depending on a deleted person become unavailable. Restoration makes the person available again but does not reactivate relationships.

## API and lifecycle

Personal navigation uses `GET /trees/{tree}/views` and versioned `PUT /trees/{tree}/views/home` with an explicit `homeId` (or `null` to clear). Every member can change only their own preferences. Responses include an authorized home summary and opening view; hidden or deleted pointers become `null` without exposing their IDs. The most recently saved named view supplies the opening focus, selection, generations and camera. Otherwise opening falls back to personal home, shared home and the first visible person. An explicit focus in a view link takes precedence. Home navigation uses personal then shared home independently of the opening view.

Home and view changes share a revision. Conflicts preserve the proposed operation and require loading the current preferences and explicitly applying the proposal to that version, or discarding it. Clearing personal home preserves the active camera, branch history and saved views. Legacy native user-config saved views remain readable and migrate into `stb_preferences` on their next successful mutation. Subsequent reads use the app-owned row inside the write transaction, avoiding stale user-config caches during concurrent writes. Account deletion/recreation removes these rows. Language remains a native account preference.

Native browser routes are under `/apps/stammbaum/api/v1`; their declarations are in `appinfo/routes.php`. Families include trees/members/invitations, people/assertions/history, relations/graph, sources/citations, records, contexts, media, imports, personal views and language preferences. These authenticated browser routes are **not** the scoped external-tool API promised by I02. No external credential or webhook endpoint is exposed.

All writes retain Nextcloud CSRF protection. Read-only routes explicitly permit normal authenticated browser GETs. Uploads use bounded multipart input; original and preview reads reauthorize every request. Application errors expose controlled messages; unexpected exceptions are logged server-side without returning stack traces.

Native disable and password-change events invalidate outstanding invitation authority by advancing membership generations. Native deletion and creation revoke old memberships, preventing a recreated account ID from inheriting access. Nextcloud owns sessions, provisioning and password recovery. A host administrator can still forcibly disable the last owner; the app does not block host administration or silently appoint an administrator as owner. Operator-assisted ownership recovery is unfinished.

`occ stammbaum:invalidate-authority` revokes invitations and advances membership generations after a consistent restore. It leaves host sessions and valid genealogy memberships intact. This command is not a complete backup/recovery system, and it does not claim to invalidate external credentials or exports that have not yet been implemented.

## Import jobs

`Imports` reserves durable quota before private original storage, then queues analysis. `Gedcom` is a bounded parser with a closed semantic whitelist; unsupported standard fields block activation. A report/plan hash binds explicit confirmation. Nextcloud background execution and the one-job CLI command share the same persisted lease and current-authority checks. There is no browser endpoint for executing arbitrary worker code.

A successful activation uses the existing global write guard and one database transaction for people, assertions, relations, contexts, evidence, mappings, history, the destination revision and terminal status. Readers cannot observe partial research. Lease fencing prevents a cancelled or replaced worker from publishing. The source-line maps remain independent of subsequent edited values.

Read [the profile contract](gedcom-import-profile.md) for exact mappings, native privacy adaptations, state transitions, limits, retention and remaining parity obligations. This is the first durable import worker, not a completed general job system for exports or integrations.

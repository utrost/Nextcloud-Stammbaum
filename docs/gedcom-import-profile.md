# Native GEDCOM import profile

Current profile **`nc-gedcom-551-alpha3`**, introduced in **0.1.0-alpha.16**, is a conservative development subset. It does **not** claim the cumulative M2a–M2d migration profile or full release-0.15 parity. Parser/preservation acceptance is tracked in [M04 #35](https://github.com/utrost/Nextcloud-Stammbaum/issues/35) and reviewed-workflow acceptance in [M06 #37](https://github.com/utrost/Nextcloud-Stammbaum/issues/37). Their focused acceptance does not complete [M05 #36 semantic mappings](https://github.com/utrost/Nextcloud-Stammbaum/issues/36) or the broader [F06 #7 job contracts](https://github.com/utrost/Nextcloud-Stammbaum/issues/7), which remain open with their original requirements and priorities.

The source contracts are pinned at `d5809c3470fe53604ffd4307428c3a68053e77b5`: [M2a](https://github.com/utrost/Stammbaum/blob/d5809c3470fe53604ffd4307428c3a68053e77b5/docs/migration/m2a-profile.md) and the cumulative [M2d amendment](https://github.com/utrost/Stammbaum/blob/d5809c3470fe53604ffd4307428c3a68053e77b5/docs/migration/m2d-profile.md). This document records the native implementation boundary, not a replacement specification.

## Supported projection

| Input | Current behavior |
| --- | --- |
| Container/encoding | GEDCOM 5.5 or 5.5.1, LINEAGE-LINKED; strict UTF-8 with/without BOM, ASCII, or BOM-marked UTF-16LE/BE declared UNICODE. No decoding replacement or encoding fallback. |
| People/names | One NAME with GIVN/SURN; suffixes and NPFX/NSFX/SPFX/NICK become readable name-component records. Conflicting literal/component forms create separate unassessed assertions with explicit components as provisional display and a warning. Empty names stay unnamed. Repeated NAME, ambiguous delimiters and non-birth TYPE remain blocked. |
| Sex/life status | Only explicit SEX M/F/U maps to male/female/unknown. No sex comes from HUSB/WIFE. Absence stays unknown; DEAT with empty value or Y records deceased. Negated/other BIRT/DEAT/MARR/DIV values block. |
| Vital assertions | At most one BIRT and one DEAT; DATE and literal PLAC populate current life values and retain a separate imported event statement. Sources on the event target that statement. |
| Dates | YYYY, month/year, day/month/year; ABT/BEF/AFT/CAL/EST, BET…AND, FROM…TO and one-sided FROM/TO. Invalid calendar dates, unfamiliar wording and calendar escapes remain literal with a warning; no calendar conversion or invented precision. |
| Families | Explicit HUSB/WIFE/CHIL references create ordered contexts and parent links. FAMC/FAMS may corroborate, never invent, membership. FAMC/PEDI birth/adopted/foster selects parent type; absence is unknown. Repeated/unresolved/conflicting memberships block. All parent cycles block conservatively in this import subset. |
| Partnerships | MARR/DIV with two explicit parent participants creates a shared event and a partnership episode. Co-parent membership alone creates no partnership. Separate FAM records retain separate partnership episodes. Repeated same-kind family events block. |
| Notes | Literal inline or shared NOTE with ordered CONT newline and CONC concatenation. `@@` decodes once after assembly. Unreferenced root NOTE remains structural with a warning. No automatic external requests. |
| Sources | Root SOUR with required TITL, AUTH, PUBL and TEXT; current reusable source plus original structure. Repositories, inline sources and additional source substructures block for now. |
| Citations | SOUR pointer at person, NAME, supported event, inline note or FAM; PAGE, DATA/TEXT/DATE, QUAY, NOTE and literal EVEN/ROLE context. Exact person/name-claim/event/context targets; neutral reference effect. QUAY remains literal reasoning, never assessed reliability. Document date is separate from event date. |
| Privacy | RESN confidential/privacy/locked conservatively restricts its supported record. A private vital event restricts its person; private family or partnership-event information restricts all context participants because native edges have no independent visibility. Shared private notes restrict their containing statement/citation. Current all-participant/all-target authorization still applies. |
| Administrative/vendor data | Header administrative blocks and root SUBM/SUBN remain structural. Individual/family RIN and REFN identifiers remain preserved administrative data. Unknown `_` extension subtrees remain structural with a warning. They do not become facts. Other unconsumed standard fields block activation. |

Names, dates, source text and notes render as text or the app's safe formatting vocabulary. File names never select storage paths. Repository structures, inline sources, repeated/ambiguous names, ADOP events and unlisted standard structures still **block** rather than disappear. This profile has no ZIP extraction, media matching, populated-tree merge or outbound network access.

### Additional alpha3 projections

- Individual CHR/BAPM/BURI/CREM/BARM/BASM/BLES/CHRA/CONF/FCOM/ORDN/NATU/EMIG/IMMI/CENS/PROB/WILL/GRAD/RETI/EVEN and OCCU/EDUC/RESI/NATI/RELI/DSCR/PROP/TITL/FACT are separate editable records. Original tags remain in titles; repeated occurrences remain separate, without inferred truth ranking. FACT requires a nonempty TYPE. Burial never becomes death; a literal N is visibly labelled negated.
- Family ENGA/MARB/MARC/MARL/MARS/ANUL/DIVF/CENS/RESI/EVEN preserve complete context participants. They create no partnership; only the existing explicit MARR/DIV rule does. DATE/PLAC and literal TYPE/AGE/AGNC/CAUS/RELI, HUSB/WIFE age details, notes and exact record citations are retained. Unsupported nested standard fields and repeated singletons still block.
- ADDR with CONT/CONC and ADR1/ADR2/ADR3/CITY/STAE/POST/CTRY, and repeated PHON/EMAIL/FAX/WWW remain labelled text in the containing record. Contact-bearing records and religion are management-only with a complete review-warning count. An immutable confidentiality marker prevents later edits or a changed type from releasing those details. Other people/family members remain visible according to their own ACLs; this native adaptation restricts the containing record instead of automatically concealing the entire person.
- Inline or referenced OBJE preserves FILE, FORM, TITL and NOTE as an explicit **Missing media reference** in person Details (shared references use an event record). The original reference is retained in immutable record wording and original GEDCOM nodes. Nothing is fetched, copied or treated as an available original or portrait. These are readable/editable reference statements, **not yet native media-library MISSING/EXTERNAL objects or package matches**; that part of M2d remains under #36/#34.
- Citation EVEN/ROLE is literal reasoning only, never an inferred event or endorsement of discovery services. Administrative RIN/REFN and unrecognized vendor data remain inspectable in original structure.

New record fields obey native bounds: 15,000-character event descriptors within 20,000-character record text, 1,000-character scalar details, 2,000-character address/media fields, and at most 32 media-reference fields. Length violations block instead of truncating source meaning. Original bytes remain unchanged regardless of projection bounds.

## Preservation and review

The upload reserves quota and a durable run before writing unchanged bytes to the private `IAppData/imports` folder under the run UUID. First-upload metadata is retained. The original SHA-256 is verified after storage, before processing/activation, and on every original download. Neither cancellation, editing imported research nor an identical retry changes that original. An interrupted storage write can leave an unavailable or incomplete original; it fails closed and its reservation remains counted.

For syntactically valid input, `stb_import_nodes` preserves every physical line's ordinal, parent, level, tag, XREF, value and original byte offset, including BOM/CRLF/UTF-16 offsets. Syntax failures retain bytes and a safe diagnostic without fabricating a partial parsed hierarchy. Nodes are classified NORMALIZED, STRUCTURAL or UNMAPPED; reasons accompany structural subtrees. Unmapped standard fields prevent activation. `stb_import_maps` retains source-line identities for every created domain object and dependent membership/target row; date/place claims map to their corresponding input field. Mapping identities survive later edits.

Reports show complete error/warning totals and complete category counts with up to 200 details, errors first, plus 20 preview people and projection counts. If a fatal error stops analysis, `complete=false` and the UI explicitly marks counts and preview as incomplete. Raw structure uses 100-line pages. Original downloads, raw structure, reports, uploads, confirmation and cancellation require current manager/owner membership; Nextcloud administrators have no bypass.

Confirmation acknowledges the report and binds the original SHA-256, profile-derived interpretation hash, run revision and destination revision. The server stores the confirming account and its membership generation. The destination must contain no genealogy, including standalone sources, tombstones, media or contexts; memberships and personal navigation preferences are allowed. Same `(tree, SHA-256, profile)` returns the existing run, including failed, cancelled and committed runs, preserving its original metadata. Matching repeated confirmations are idempotent. There is no misleading restart action: another copy requires another empty tree.

Profile semantics do not change under an already published identifier. New uploads use alpha3; stored alpha1 and alpha2 runs continue through their original interpreters and retain their exact review/plan hashes. Golden hashes captured from the deployed alpha.8 and alpha.15 parsers verify both historical profiles, including inputs whose support changes in alpha3. Unknown profiles fail closed.

Alpha2 corrects two edge cases: GEDCOM calendar escapes remain literal dates with warnings, and an unreferenced NOTE no longer hides an unsupported standard subfield. Alpha1 rejected calendar escapes and retained the entire orphan NOTE subtree as structural material. Existing alpha1 reports/plans are not rewritten; their unchanged original and recorded profile remain available. New alpha2 orphan-note preservation recognizes text continuations and restrictions, while other standard subfields block activation.

## Durable execution and atomic publication

The normal states are `uploading → queued → analyzing → ready/blocked`, then `ready → commit_queued → activating → committed`. Explicit cancellation produces `cancelled`; storage, authority, integrity or worker failures produce `failed`. Analysis and activation have distinct persisted leases with UUID tokens and a 300-second deadline. Expired work is reclaimable; after three interrupted attempts that phase fails. Upload reservations still unfinished after 30 minutes fail as interrupted. Originals/reports remain retained.

Nextcloud registers `OCA\Stammbaum\BackgroundJob\ProcessImports`, with a 60-second desired interval and no parallel host runs. Actual scheduling follows the installation's cron configuration. A cron invocation processes at most five jobs, checking a 45-second scheduling budget between jobs; each individual job is bounded by the input/profile limits and a lease, not forcibly interrupted at 45 seconds. `occ stammbaum:process-imports` invokes the same worker for one pending job. Separate processes cannot claim the same unexpired lease.

The global transactional write guard serializes claims, cancellation, membership changes and publication. The worker checks the recorded active account/manager generation at claim and again before result publication. Activation also checks the original, reviewed plan, tree revision and complete emptiness. All domain inserts, mappings, per-person attributed import history, one tree import audit, one tree revision increment and the committed run status become visible in one MariaDB transaction. A failure rolls everything back. An expired/cancelled/replaced lease cannot publish.

Cancellation and revocation have a clear transaction boundary. If cancellation/revocation wins before activation obtains the write guard, no research is published. Once activation owns that guard, it completes or rolls back atomically; a waiting cancellation observes the completed/conflicting revision and cannot undo the import. A later revocation does not erase research that was validly committed earlier.

## Limits and retention

| Limit | Value |
| --- | --- |
| Original input | 8 MiB |
| People | 2,000 |
| Physical lines / characters per line / nesting level | 100,000 / 16,384 / 64 |
| Interpretation | At most 50,000 parsed nodes and 50,000 work units; repeated projections count again, with one unit per node visit plus one per 256 bytes of its value |
| Existing native field limits | Names 200 characters; places/locators 500; notes 20,000; source title/author 250, publication 1,000, description 4,000; quotation 8,000; citation reasoning 4,000; context members 400 |
| New runs | Five per initiating account per hour; identical retries do not consume another run |
| Pending installation-wide | Twenty uploading/queued/analyzing/ready/commit-queued/activating runs |
| Retained original reservations | 512 MiB, including failed/cancelled/interrupted uploads |
| Report/list bounds | 200 diagnostics, 20 preview people, 25 runs/page, 100 structure lines/page |

No automatic expiry, purge, retry/reanalysis UI or orphan cleanup is provided. Do not remove files behind live database rows to reclaim quota. Future cleanup needs an explicit integrity-aware retention contract. Production recovery must consistently include all app tables and private import/media originals; the authority-invalidation command makes pending restored work fail its generation checks. A full backup/restore exercise remains unfinished.

## Verification and remaining obligations

`tests/unit/gedcom.php` exercises strict encoding, byte offsets, continuation/escape semantics, dates, membership and privacy rules, unsupported structures and every parser resource boundary. `tests/e2e/imports.spec.js` uses real Nextcloud sessions, MariaDB, native cron/CLI workers and fictional data for review bindings, rollback, cancellation, lease recovery, concurrent workers/readers, revoked authority, original integrity, quota/rate/capacity limits, the 2,000-person boundary and desktop/mobile browser review. Executed release results are recorded in [iteration-02.md](iteration-02.md).

Full M2a–M2d semantic coverage, consumer interoperability, general integration jobs, richer progress, orphan/retention tooling, current-state export and complete recovery remain open. Original-file download is never a substitute for current-state export: a future exporter must reconcile preserved mappings with edits/deletions and must not replay obsolete original facts or resurrect relationships.

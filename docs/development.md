# Development references and test environment

Stammbaum is a native Nextcloud app. [Library](https://github.com/utrost/Library) and [Relate](https://github.com/utrost/inventory) provide useful architecture and UI references, but neither is a runtime dependency. Read their public documentation when adopting a pattern and verify it against Stammbaum's separate tree-role, record-visibility and export-permission contracts.

## Supported development target

The current target is Nextcloud 34, PHP 8.3+ and MariaDB. CI builds and installs the runtime package on a disposable Nextcloud 34 fixture, then runs protocol and browser tests with fictional accounts and records. Other Nextcloud majors and database engines need their own compatibility evidence; see [platform adaptation](platform-adaptation.md).

Use [operations](operations.md) for the exact build, disposable fixture, migration and packaging commands. The fixture uses a dedicated Docker network and containers, generated credentials in ignored `build/`, and no production genealogy data. Browser screenshots are made from fictional fixtures.

## Patterns to reuse

| Area | Application to Stammbaum | Issues |
| --- | --- | --- |
| Native architecture | PHP controllers and services, Nextcloud APIs and a Vue frontend; stable app identity and table prefix. | F01–F03 |
| Route security | Explicit method, authentication, CSRF, authorization, DTO and cache contracts; generic unavailable-object responses. | A03, A04, I02 |
| Schema and queries | Indexed authorized query shapes, stable pagination and batched lookups. | F03, W01, W02 |
| Private media | App-data originals and regenerable private previews behind tree authorization. | F04, M01, M02 |
| Jobs | Durable app-owned state, bounded progress, cancellation and authority checks before publication. | F06, M06, M08 |
| App shell | Nextcloud navigation, theme tokens, responsive controls and accessible feedback. | F02, W03, I05 |
| Editor and navigation | Explicit Save/Discard, preserved drafts, independent graph focus and selection. | W01, W04, W05 |
| Request failures | Ignore obsolete responses, retain failed cursors and user input, and offer Retry. | W01, W05, I05 |
| Verification | Service, package, migration, HTTP and Playwright tests using disposable fictional fixtures. | I07–I09 |

Do not copy another app's ownership gate, file-deletion behavior or browser fixtures wholesale. Test the privacy and lifecycle rules that are specific to genealogy. Run the [backup and restore exercise](operations.md#consistent-recovery-boundary) separately from the main browser suite. It verifies the current recovery behavior on fictional data. Webhook signing-key recovery and full recovery acceptance remain [open](https://github.com/utrost/Nextcloud-Stammbaum/issues/45).

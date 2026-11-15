# Project working conventions

- Read `docs/development.md` before choosing infrastructure, frontend conventions, test setup or deployment procedures. Library and Relate are references, not runtime dependencies.
- Use the disposable, fictional test fixture for broad fault injection, account changes and load tests. Keep changes to other Nextcloud apps out of scope.
- Keep credentials, local host details and private connection information outside tracked files and issue bodies.
- Preserve the archived specification in `docs/intial-spec/`. Record adaptations in current documentation and relevant issues rather than silently rewriting the source handoff.
- Keep `docs/backlog.json` synchronized when changing published issue specifications. Do not claim application acceptance tests passed based on documentation checks or sibling-project evidence.

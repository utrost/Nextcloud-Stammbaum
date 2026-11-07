# Project working conventions

- Read `docs/development.md` before choosing infrastructure, frontend conventions, test setup or deployment procedures. Library and Inventory/Relate are existing sibling Nextcloud apps with reusable documentation; they are references, not runtime dependencies.
- This workspace already runs on Alice. Use the local default Docker context (`unix:///var/run/docker.sock`), `docker cp`, and `docker exec` against `nextcloud`; no SSH access is needed.
- Alice is the user-authorized development/test system. The user explicitly says no pre-deployment backup is necessary there. Do not import backup requirements or repeated deployment approval gates from sibling runbooks. Keep operations scoped to Stammbaum and preserve the other apps.
- The Alice development exception does not remove the product's backup/recovery requirements or authorize changes to other installations.
- Use fictional Stammbaum fixtures. Keep credentials and private connection details outside tracked files and issue bodies.
- Preserve the archived specification in `docs/intial-spec/`. Record adaptations in current documentation and relevant existing issues rather than silently rewriting the source handoff.
- Keep `docs/backlog.json` synchronized when changing published issue specifications. Do not claim application acceptance tests passed based on documentation checks or sibling-project evidence.

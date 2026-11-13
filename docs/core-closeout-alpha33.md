# Alpha.33 core closeout evidence

This iteration closes five bounded release-parity issues; it does not certify the full release 0.15 parity matrix. Tests use only fictional trees and the isolated Nextcloud 34/MariaDB fixture. The app has no dependency on Library or Relate.

| Issue | Delivered behavior and executed acceptance path |
| --- | --- |
| [F01 · #2](https://github.com/utrost/Nextcloud-Stammbaum/issues/2) | [Native platform decision](platform-adaptation.md) records the verified matrix, identity/session/locale/storage/job/restore adaptations and explicit uncertified combinations. Native administrator without membership, sign-in and lifecycle cases run in browser tests. |
| [A01 · #8](https://github.com/utrost/Nextcloud-Stammbaum/issues/8) | Nextcloud disable/password/delete/recreation events revoke invitations and old account authority; host admins gain no tree access. Browser tests check host redirect on session expiry, API 401 cleanup, and concurrent final-owner departure. Host-forced last-owner deletion remains an operator recovery boundary, not an implicit administrator grant. |
| [A05 · #12](https://github.com/utrost/Nextcloud-Stammbaum/issues/12) | One-time invitation links now survive the host login redirect and are removed from the app URL before its API requests. The management UI explains host account provisioning. Browser tests check signed-out review/acceptance, manager invitation limits, issuer demotion, expiry, single use and simultaneous acceptance. |
| [G01 · #15](https://github.com/utrost/Nextcloud-Stammbaum/issues/15) | Person creation/editing retains unknown values and prior assertions without inventing relations or events. Browser tests check `other` sex, unchanged saves, clearing a preferred place, stale revisions and draft-preserving editor conflicts. |
| [W01 · #25](https://github.com/utrost/Nextcloud-Stammbaum/issues/25) | Authorized search pages names, alternatives and notes; duplicate names keep distinct identities. Selection can remain outside the focused branch. Search now clears cached results if membership is revoked during a request. Browser tests cover case-insensitive later pages, hidden records, stale access and off-branch selection. |

## Executed verification

- The broad isolated Playwright run passed **187/187** on the preceding alpha.33 package. The only subsequent application changes were early invitation-token URL scrubbing and clearing open editor state on API 401.
- The final archive `stammbaum-0.1.0-alpha.33.tar.gz` has SHA-256 `9804d0e2555ebfeedd10347893e6fe449c48d149a56a5447e7133cf0c9a0e6d6`. Its isolated install and focused Playwright run passed **28/28** closeout, domain, lifecycle and workspace tests, including the two new concurrent races and citation retention. This is the exact-package evidence for the final changes; the full 187-test run was not repeated after them.
- `npm run check`, `npm run format:check`, `python3 scripts/check-docs.py` and `git diff --check` passed. The documentation check verified the archived source hashes, HTML assets, local links and A01–A30 backlog coverage.
- The same archive was deployed to Alice. Deployment verified Nextcloud health, Stammbaum job registration and unchanged Library/Relate enablement and versions. An Alice browser smoke with a dedicated fictional account/tree passed current-asset, login, family navigation, unchanged-revision, light/dark and 390/320-pixel checks, then removed its fixtures without browser errors. The 390-pixel invitation review screenshot was inspected and had no clipping or unreachable action.

Later database/browser certification, backup recovery, scoped external credentials and the overall acceptance matrix remain separate open issues.

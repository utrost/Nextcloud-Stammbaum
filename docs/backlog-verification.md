# Documentation and backlog verification

Verified on 6 November 2026 against initial repository commit `37040733982e5a2b3dacfb48f07ef3ba7a33f05f`.

- All 100 original documentation files are present under `docs/intial-spec/`.
- SHA-256 checks confirm 99 original files are byte-identical, including PDF and text. HTML differs only by fifteen absolute-to-relative illustration URL substitutions.
- Twelve added screenshot hashes match the unchanged original illustration provenance manifest. The assets came from the later local documentation export, not the pinned source application commit.
- All HTML resources and internal anchors resolve. README/roadmap/archive Markdown file links resolve.
- A local headless Chrome load rendered all fifteen images, with zero failed images, remote HTTP requests or page errors.
- GitHub contains one tracker and 59 work issues: 47 parity/foundation and 12 deferred. All published work-issue bodies, titles, labels and milestone assignments match `backlog.json`.
- Every declared dependency refers to a published issue; the dependency graph is acyclic. The roadmap tracker lists every work issue.
- Every Appendix A example A01–A30 has direct implementation coverage in addition to the final acceptance issue. The roadmap records chapter coverage and capacity/retention mappings.
- Referenced detailed source documents exist at pinned source commit `d5809c3470fe53604ffd4307428c3a68053e77b5`.
- `git diff --check` passed.

These checks validate documentation and backlog publication. They were repeated during the first implementation iteration: all 100 archive hashes, local HTML assets, 60 published issue titles/bodies/milestones and A01–A30 mappings still agree. Application validation is reported separately in [iteration-01.md](iteration-01.md). Published issue specifications continue to reference the immutable source commit and have not been weakened to match the alpha implementation.

# Nextcloud Stammbaum

A native Nextcloud app for private, collaborative family-history research. The development alpha implements trees, people, relationships, evidence, shared events, notes, private media, reviewed GEDCOM import and current-state export. It is an early alpha, not yet release-0.15 parity.

## Try the development alpha

Open **Stammbaum** in Nextcloud, create a tree, and add a person. Use the person workspace to add relatives, group siblings, record evidence and events, or upload an original photograph. Tree owners manage membership and invitations under **Manage**. Nextcloud administrators receive no automatic genealogy access.

Use **Expand person research** to read evidence alongside retained assertions and relatives. Each member can set a personal home without changing the shared home. Saved views retain their focus, selection and camera; the most recently saved view is used when reopening the tree. The diagram supports mouse, keyboard and two-finger navigation, with a text alternative. Select a boundary person and use **Expand relatives** to reveal one more local neighborhood without changing focus or camera. **Previous branch** restores local expansions; **Reset local expansions** returns to the chosen generation depths. Expansions stay in the current session’s branch history and are not included in named saved views or copied links.

For existing research, create an empty tree and open **Imports**. Upload a supported GEDCOM file, review its diagnostics and preview, then explicitly confirm activation. Nextcloud processes it in the background. The current profile includes additional events, occupations, name components and confidential contact details. Missing media references appear in person Details; the referenced files are not automatically downloaded. The [named import profile](docs/gedcom-import-profile.md) is deliberately bounded; unsupported standard fields block activation.

In **Media**, upload a JPEG, PNG or PDF from your device or use **Choose from Nextcloud Files**. Confirm **Import private copy** to store unchanged bytes under the tree's permissions. The copy counts against Stammbaum's storage quota and remains available if the source later changes or is deleted. The gallery has private cached thumbnails and **Open preview** for larger images and PDF pages, with zoom and page controls. PDF previews require the optional Poppler runtime; unsupported documents keep their original download. Explicit GEDCOM media matching remains separate work.

Use **Exports** to review the included research, privacy options and format limitations before generating GEDCOM or an optional package of original media. Results are private to the requester, expire after 24 hours and become invalid after tree/access changes. Managers can review **Move medium to trash** and restore retained media through **Media trash**; originals continue to occupy storage. See the [export and media lifecycle contract](docs/export-media-lifecycle-iteration.md) and [alpha.21 export reliability changes](docs/export-reliability-iteration.md).

The app currently targets **Nextcloud 34**, PHP **8.3+**, with MariaDB verified. It uses Nextcloud accounts and private app storage; Library and Relate are development references, not dependencies.

- [GEDCOM iteration and verification](docs/iteration-02.md)
- [Import profile, limits and preserved-original contract](docs/gedcom-import-profile.md)
- [First-iteration scope, evidence and remaining work](docs/iteration-01.md)
- [Architecture and data contracts](docs/architecture.md)
- [Build, install, test and recovery instructions](docs/operations.md)
- [Development environment and lessons from Library/Relate](docs/development.md)
- [Implementation roadmap](docs/implementation-roadmap.md)
- [Nextcloud Tasks, Calendar and Contacts integration concept](docs/nextcloud-integration-concept.md)
- [First Tasks integration journey and verified limits](docs/tasks-iteration.md)
- [GitHub roadmap and implementation backlog](https://github.com/utrost/Nextcloud-Stammbaum/issues/1)

## Build

Node 24 and npm 11 are used for development. Runtime installation needs no Node process.

```sh
npm ci
npm run check
npm run package
```

The package and SHA-256 file appear in `build/`. The archive contains one `stammbaum/` runtime directory. See the operator instructions for Alice deployment and the isolated test fixture.

## Specification

The parity baseline is documented release 0.15, adapted to Nextcloud identity and platform services. The 47 foundation/parity issues and 12 deferred issues retain their original priorities; this first iteration does not close them wholesale.

- [Initial specification — PDF](docs/intial-spec/current-product-specification.pdf)
- [Initial specification — HTML](docs/intial-spec/current-product-specification.html), with local illustrations
- [Initial specification — text](docs/intial-spec/current-product-specification.txt)
- [Reviewable issue specifications and published URLs](docs/backlog.json)
- [Archive and backlog verification](docs/backlog-verification.md)

`intial-spec` intentionally preserves the requested spelling. All original documents and render artifacts are retained. Development fixtures are fictional; private family information does not belong in this repository.

Photos and PDFs support descriptive dates, named people shown or mentioned (including people outside the tree), events, places and source/credit. Read the [metadata and gallery contract](docs/media-metadata-iteration.md).

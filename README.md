# Nextcloud Stammbaum

A native Nextcloud app for private, collaborative family-history research. The first development iteration implements trees, people, relationships, evidence, shared events, notes and private media. It is an early alpha, not yet release-0.15 parity.

## Try the first iteration

Open **Stammbaum** in Nextcloud, create a tree, and add a person. Use the person workspace to add relatives, group siblings, record evidence and events, or upload an original photograph. Tree owners manage membership and invitations under **Manage**. Nextcloud administrators receive no automatic genealogy access.

The app currently targets **Nextcloud 34**, PHP **8.3+**, with MariaDB verified. It uses Nextcloud accounts and private app storage; Library and Relate are development references, not dependencies.

- [First-iteration scope, evidence and remaining work](docs/iteration-01.md)
- [Architecture and data contracts](docs/architecture.md)
- [Build, install, test and recovery instructions](docs/operations.md)
- [Development environment and lessons from Library/Relate](docs/development.md)
- [Implementation roadmap](docs/implementation-roadmap.md)
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

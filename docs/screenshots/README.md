# Screenshot provenance

The three `*-alpha33.png` images linked from the root README were captured on 15 November 2026 from the `0.1.0-alpha.33` app in the disposable Nextcloud 34 test fixture. The family, research and import tests create fictional people and sources; no real family record or production account was used. The images show the actual browser output and have no location, creator or camera metadata.

To recapture them after starting the [disposable fixture](../operations.md), run:

```sh
STAMMBAUM_PUBLIC_SCREENSHOTS=1 npx playwright test tests/e2e/research-ui.spec.js tests/e2e/import-ui.spec.js -g 'family and research layouts remain readable|compact import review retains diagnostics'
```

Inspect `test-results/public-family.png`, `test-results/research-en-1440.png` and `test-results/public-import.png` before copying them to the correspondingly named `*-alpha33.png` files here. Other images in this directory document earlier iterations and are not presented as current screenshots.

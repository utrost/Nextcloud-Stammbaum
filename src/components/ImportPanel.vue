<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import { useDraftGuard } from '../draftGuard.js';
const props = defineProps({ treeId: String });
const emit = defineEmits(['changed', 'open-tree']);
const items = ref([]),
  nextPage = ref(null),
  selected = ref(null),
  file = ref(null),
  input = ref(null);
const busy = ref(false),
  loading = ref(false),
  error = ref(''),
  reviewed = ref(false);
const nodes = ref(null),
  nodePage = ref(null),
  structureBusy = ref(false);
const pending = new Set(['uploading', 'queued', 'analyzing', 'commit_queued', 'activating']);
const labels = {
  uploading: 'Receiving original',
  queued: 'Waiting for analysis',
  analyzing: 'Analyzing',
  ready: 'Ready for review',
  blocked: 'Activation blocked',
  commit_queued: 'Waiting for activation',
  activating: 'Activating',
  committed: 'Import completed',
  cancelled: 'Import cancelled',
  failed: 'Import failed',
};
const issueText = {
  unsupported_standard_field: 'This standard field is not supported by this import profile.',
  vendor_extension: 'Vendor information is preserved in the original structure but is not interpreted.',
  orphan_note: 'An unreferenced note remains in the original structure.',
  literal_date: 'This date remains literal wording; no calendar date was inferred.',
  unresolved_reference: 'A reference is missing or points to a different kind of record.',
  conflicting_name_components: 'The full name and its components disagree.',
  unsupported_encoding: 'Use UTF-8, ASCII, or UTF-16 with a byte-order mark and matching declaration.',
  gedcom_version: 'Only GEDCOM 5.5 and 5.5.1 are supported by this profile.',
  tree_not_empty: 'The destination is no longer empty. Nothing was imported.',
  conflict: 'The destination changed after review. Nothing was imported.',
  authority_changed: 'The initiating permission changed. Nothing was imported.',
  forbidden: 'The initiating account no longer has import permission.',
  not_found: 'The initiating account no longer has access to this tree.',
  unauthenticated: 'The initiating account is no longer active.',
  worker_interrupted: 'Processing was interrupted repeatedly. The original remains retained.',
  original_mismatch: 'The stored original failed its integrity check.',
  plan_mismatch: 'The reviewed plan failed its integrity check.',
};
const base = `/trees/${props.treeId}/imports`;
let alive = true,
  sequence = 0,
  timer;
useDraftGuard(
  computed(() => file.value !== null),
  busy,
);
function update(run) {
  const previous = selected.value;
  selected.value = run;
  items.value = items.value.map((r) => (r.id === run.id ? run : r));
  if (previous?.id !== run.id || previous?.revision !== run.revision) reviewed.value = false;
  if (run.status === 'committed' && previous?.id === run.id && previous.status !== 'committed')
    emit('changed');
}
async function load(page = 0) {
  loading.value = true;
  try {
    const r = await api(`${base}?page=${page}`);
    if (!alive) return;
    items.value = page ? [...items.value, ...r.items] : r.items;
    nextPage.value = r.nextPage;
  } catch (e) {
    if (alive) error.value = t(e.message);
  } finally {
    if (alive) loading.value = false;
  }
}
async function open(run) {
  const seq = ++sequence;
  nodes.value = null;
  nodePage.value = null;
  reviewed.value = false;
  error.value = '';
  selected.value = run;
  try {
    const r = await api(`${base}/${run.id}`);
    if (alive && seq === sequence) update(r);
  } catch (e) {
    if (alive && seq === sequence) {
      error.value = t(e.message);
      selected.value = null;
    }
  }
}
async function refresh() {
  if (busy.value) return;
  error.value = '';
  await load();
  if (selected.value) await open(selected.value);
}
async function upload() {
  if (!file.value) return;
  busy.value = true;
  error.value = '';
  try {
    const body = new FormData();
    body.append('file', file.value);
    const response = await fetch(apiBase + base, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { requesttoken: window.OC.requestToken },
      body,
    });
    const run = await response.json();
    if (!response.ok) throw new Error(run.message || 'The upload was not received completely.');
    file.value = null;
    input.value.value = '';
    await load();
    await open(run);
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
async function change(action) {
  const run = selected.value;
  if (!run) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api(`${base}/${run.id}/${action}`, {
      method: 'POST',
      body: {
        revision: run.revision,
        ...(action === 'confirm'
          ? {
              treeRevision: run.treeRevision,
              sha256: run.sha256,
              planHash: run.planHash,
              acknowledgeReview: reviewed.value,
            }
          : {}),
      },
    });
    update(result);
  } catch (e) {
    error.value = t(e.message);
    // Keep the failed review visible; explicit refresh is required before trying again.
    reviewed.value = false;
  } finally {
    busy.value = false;
  }
}
async function structure(page = 0) {
  const id = selected.value.id,
    seq = sequence;
  structureBusy.value = true;
  try {
    const r = await api(`${base}/${id}/structure?page=${page}`);
    if (alive && seq === sequence) {
      nodes.value = r.items;
      nodePage.value = r.nextPage;
    }
  } catch (e) {
    if (alive && seq === sequence) error.value = t(e.message);
  } finally {
    if (alive) structureBusy.value = false;
  }
}
async function poll() {
  if (!alive) return;
  const run = selected.value,
    seq = sequence;
  if (run && pending.has(run.status) && !busy.value) {
    try {
      const result = await api(`${base}/${run.id}`);
      if (alive && seq === sequence && selected.value?.id === run.id) update(result);
    } catch (e) {
      if (alive && seq === sequence) {
        error.value = t(e.message);
        if ([401, 403, 404].includes(e.status)) {
          selected.value = null;
          nodes.value = null;
          items.value = [];
        }
      }
    }
  }
  if (alive) timer = setTimeout(poll, 3000);
}
onMounted(async () => {
  await load();
  if (alive) poll();
});
onBeforeUnmount(() => {
  alive = false;
  sequence++;
  clearTimeout(timer);
});
</script>

<template>
  <section class="import-panel">
    <header class="section-heading">
      <h2>{{ t('GEDCOM import') }}</h2>
      <button :disabled="busy || loading" @click="refresh">{{ t('Refresh') }}</button>
    </header>
    <p>
      {{ t('Import into an empty tree. Review the result before any people or evidence become active.') }}
    </p>
    <details class="panel">
      <summary>{{ t('Supported import scope') }}</summary>
      <p>
        {{
          t(
            'GEDCOM 5.5 / 5.5.1, up to 8 MiB and 2,000 people. UTF-8, ASCII, or UTF-16 with a byte-order mark.',
          )
        }}
      </p>
      <p>
        {{
          t(
            'Basic names, vital dates and places, explicit family links, notes, sources and citations. Unsupported standard fields block activation. Vendor extensions remain preserved with warnings.',
          )
        }}
      </p>
      <p>
        {{
          t(
            'No ZIP archives, media files, populated-tree merge, or complete migration-profile compatibility yet.',
          )
        }}
      </p>
      <p>
        {{
          t(
            'Originals and reports remain private to tree managers and owners, including failed and cancelled runs. Downloading the original is not a current-state export.',
          )
        }}
      </p>
    </details>
    <form class="panel import-upload" @submit.prevent="upload">
      <label
        >{{ t('GEDCOM file')
        }}<input
          ref="input"
          type="file"
          accept=".ged,.gedcom,text/plain"
          :disabled="busy"
          @change="file = $event.target.files[0] || null"
      /></label>
      <button class="primary" :disabled="busy || !file">
        {{ busy ? t('Please wait…') : t('Upload for review') }}
      </button>
    </form>
    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <div class="import-layout">
      <section class="panel import-runs" :aria-label="t('Import runs')">
        <p v-if="loading" role="status">{{ t('Loading…') }}</p>
        <p v-else-if="!items.length">{{ t('No imports yet') }}</p>
        <button
          v-for="run in items"
          :key="run.id"
          class="import-run"
          :aria-pressed="selected?.id === run.id"
          :disabled="busy"
          @click="open(run)"
        >
          <strong>{{ run.originalName }}</strong
          ><span>{{ t(labels[run.status]) }}</span>
          <small>{{ new Date(run.createdAt * 1000).toLocaleString() }}</small>
        </button>
        <button v-if="nextPage !== null" :disabled="busy || loading" @click="load(nextPage)">
          {{ t('Load more') }}
        </button>
      </section>
      <section v-if="selected" class="panel import-review" :aria-label="t('Import review')">
        <h3>{{ selected.originalName }}</h3>
        <p role="status">
          <strong>{{ t(labels[selected.status]) }}</strong>
        </p>
        <p v-if="pending.has(selected.status)">
          {{ t('Nextcloud background jobs process this request. You may leave this page and return later.') }}
        </p>
        <p v-if="selected.failureCode" class="error">
          {{
            t(
              issueText[selected.failureCode] ||
                'This run could not finish safely. No partial import was published.',
            )
          }}
          <small>({{ selected.failureCode }})</small>
        </p>
        <p v-if="selected.status === 'cancelled'">
          {{ t('Nothing was activated. The original and any completed review remain retained.') }}
        </p>
        <p v-if="selected.status === 'committed'">
          {{
            t(
              'The reviewed research is now available in this tree. Original-file downloads retain the uploaded bytes.',
            )
          }}
        </p>
        <button v-if="selected.status === 'committed'" @click="emit('open-tree')">
          {{ t('Open imported family') }}
        </button>
        <template v-if="selected.report?.counts">
          <dl class="import-counts">
            <template v-for="(count, kind) in selected.report.counts" :key="kind"
              ><dt>{{ t(kind[0].toUpperCase() + kind.slice(1)) }}</dt>
              <dd>{{ count }}</dd></template
            >
          </dl>
          <p>
            {{ selected.report.errors }} {{ t('errors') }} · {{ selected.report.warnings }}
            {{ t('warnings') }}
          </p>
          <ul v-if="selected.report.issues.length" class="import-issues">
            <li v-for="(issue, i) in selected.report.issues" :key="i">
              <strong
                >{{ t(issue.severity === 'warning' ? 'Warning' : 'Error') }} · {{ t('Line') }}
                {{ issue.line }}</strong
              >:
              {{
                t(issueText[issue.code] || 'This input structure needs attention before it can be imported.')
              }}
              <small>({{ issue.code }})</small>
            </li>
          </ul>
          <p v-if="selected.report.errors + selected.report.warnings > 200">
            {{ t('Showing the first 200 diagnostics; totals include every diagnostic.') }}
          </p>
          <h4>{{ t('Preview people') }}</h4>
          <ul>
            <li v-for="(p, i) in selected.report.preview" :key="i">
              {{ [p.givenName, p.familyName].filter(Boolean).join(' ') || t('Unnamed person') }}
              <span v-if="p.visibility === 'management'" class="badge">{{ t('Management only') }}</span>
            </li>
          </ul>
          <p class="muted">
            {{
              t(
                selected.report.complete
                  ? 'The preview shows up to twenty people. Counts cover the complete file.'
                  : 'Analysis stopped at a blocking error. These counts and previews are incomplete.',
              )
            }}
          </p>
        </template>
        <div v-if="selected.status === 'ready'" class="import-confirm">
          <label class="check"
            ><input v-model="reviewed" type="checkbox" :disabled="busy" />{{
              t('I reviewed this file, the preview and all warnings. Activate this result in the empty tree.')
            }}</label
          >
          <button class="primary" :disabled="busy || !reviewed" @click="change('confirm')">
            {{ t('Confirm import') }}
          </button>
        </div>
        <div class="import-actions">
          <a :href="`${apiBase}${base}/${selected.id}/original`" download>{{
            t('Download unchanged original')
          }}</a>
          <button
            v-if="!['committed', 'cancelled'].includes(selected.status)"
            :disabled="busy"
            @click="change('cancel')"
          >
            {{ t('Cancel import') }}
          </button>
        </div>
        <details class="import-technical">
          <summary>{{ t('Preserved structure and verification') }}</summary>
          <dl>
            <dt>{{ t('Profile') }}</dt>
            <dd>{{ selected.profile }}</dd>
            <dt>{{ t('Encoding') }}</dt>
            <dd>{{ selected.encoding || '—' }}</dd>
            <dt>SHA-256</dt>
            <dd>
              <code>{{ selected.sha256 }}</code>
            </dd>
            <dt>{{ t('Interpretation checksum') }}</dt>
            <dd>
              <code>{{ selected.planHash || '—' }}</code>
            </dd>
          </dl>
          <button :disabled="structureBusy" @click="structure()">{{ t('Show original structure') }}</button>
          <ol v-if="nodes" class="import-nodes">
            <li v-for="n in nodes" :key="n.ordinal">
              <code>{{ n.ordinal }} · {{ n.level }} {{ n.xref }} {{ n.tag }} {{ n.value }}</code
              ><small>{{ n.classification }}</small>
            </li>
          </ol>
          <button v-if="nodePage !== null" :disabled="structureBusy" @click="structure(nodePage)">
            {{ t('Next structure page') }}
          </button>
        </details>
      </section>
    </div>
  </section>
</template>

<style scoped>
.import-panel {
  padding: 20px;
  min-width: 0;
}
.section-heading,
.import-upload,
.import-actions {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
}
.section-heading {
  justify-content: space-between;
}
.import-upload {
  margin: 16px 0;
}
.import-upload label {
  flex: 1;
  min-width: 0;
}
.import-upload input {
  display: block;
  max-width: 100%;
}
.import-layout {
  display: grid;
  grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}
.import-runs,
.import-review {
  min-width: 0;
}
.import-run {
  display: flex;
  flex-direction: column;
  text-align: start;
  width: 100%;
  height: auto;
  gap: 4px;
  margin-bottom: 8px;
  white-space: normal;
  overflow-wrap: anywhere;
  padding: 12px;
}
.import-run[aria-pressed='true'] {
  border-color: var(--color-primary-element);
  background: var(--color-primary-element-light);
}
.import-counts {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px 24px;
  max-width: 340px;
}
.import-review h3,
.import-review code,
.import-review dd {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.import-issues {
  max-height: 280px;
  overflow: auto;
  padding-inline-start: 24px;
}
.import-confirm {
  margin: 20px 0;
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.import-confirm .check {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}
.import-actions {
  margin: 20px 0;
}
.import-technical {
  margin-top: 20px;
}
.import-nodes {
  list-style: none;
  padding: 0;
  max-height: 360px;
  overflow: auto;
}
.import-nodes li {
  padding: 8px 0;
  border-bottom: 1px solid var(--color-border);
}
.import-nodes small {
  display: block;
}
button,
summary,
.import-actions a {
  min-height: 44px;
}
summary {
  cursor: pointer;
  padding: 8px 0;
}
@media (max-width: 700px) {
  .import-layout {
    grid-template-columns: 1fr;
  }
  .import-panel {
    padding: 12px;
  }
  .import-runs {
    max-height: 240px;
    overflow: auto;
  }
}
</style>

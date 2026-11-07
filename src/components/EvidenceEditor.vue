<script setup>
import { useDraftGuard } from '../draftGuard.js';
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { t, title } from '../i18n.js';
import { requestId, personName } from '../domain.js';
import DateField from './DateField.vue';
const props = defineProps({
  treeId: String,
  management: Boolean,
  kind: String,
  record: Object,
  person: Object,
  claims: Array,
  relations: Array,
  initialSource: Object,
});
const emit = defineEmits(['saved', 'close']);
const isSource = computed(() => props.kind === 'source'),
  dialog = ref(null),
  busy = ref(false),
  error = ref(''),
  conflict = ref(false),
  current = ref(null);
const sourceFields = [
  ['title', 'Title', 250],
  ['type', 'Source type', 100],
  ['author', 'Author', 250],
  ['publication', 'Publication', 1000],
  ['repository', 'Repository', 250],
  ['callNumber', 'Call number', 250],
  ['url', 'Web address', 1500],
  ['description', 'Description', 4000],
];
const defaults = isSource.value
  ? Object.fromEntries(sourceFields.map(([key]) => [key, '']))
  : {
      sourceId: props.initialSource?.id || '',
      locator: '',
      quotation: '',
      url: '',
      documentDate: { kind: 'exact', value: '', end: '' },
      accessDate: { kind: 'exact', value: '', end: '' },
      reliability: 'unassessed',
      reasoning: '',
      targets: props.person
        ? [
            {
              kind: 'person',
              targetId: props.person.id,
              effect: 'reference',
              label: personName(props.person, t('Unnamed person')),
            },
          ]
        : [],
    };
const draft = reactive({
    ...defaults,
    visibility: 'members',
    ...JSON.parse(JSON.stringify(props.record || {})),
  }),
  original = JSON.stringify(draft);
const dirty = computed(() => JSON.stringify(draft) !== original),
  sources = ref(props.record?.source ? [props.record.source] : []),
  sourceQuery = ref(''),
  sourcePage = ref(null),
  sourceError = ref(''),
  searchBusy = ref(false);
const targetQuery = ref(''),
  targetRecords = ref([]),
  targetPeople = ref([]),
  targetError = ref(''),
  targetBusy = ref(false);
let request = requestId(),
  sourceSequence = 0,
  targetSequence = 0;
function close() {
  if (busy.value) return;
  if (dirty.value && !confirm(t('Discard unsaved changes?'))) return;
  emit('close');
}
function unload(e) {
  if (dirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}
onMounted(() => {
  dialog.value.showModal();
  window.addEventListener('beforeunload', unload);
  if (!isSource.value) {
    searchSources();
    if (props.person)
      api(`/trees/${props.treeId}/records?person=${props.person.id}`)
        .then((r) => (targetRecords.value = r.items))
        .catch((e) => (targetError.value = t(e.message)));
  }
});
onBeforeUnmount(() => {
  sourceSequence++;
  targetSequence++;
  window.removeEventListener('beforeunload', unload);
});
async function searchSources(page = 0) {
  const seq = ++sourceSequence;
  sourceError.value = '';
  searchBusy.value = true;
  try {
    const r = await api(
      `/trees/${props.treeId}/sources?query=${encodeURIComponent(sourceQuery.value)}&page=${page}`,
    );
    if (seq !== sourceSequence) return;
    sources.value = page ? [...sources.value, ...r.items] : r.items;
    sourcePage.value = r.nextPage;
  } catch (e) {
    if (seq === sourceSequence) sourceError.value = t(e.message);
  } finally {
    if (seq === sourceSequence) searchBusy.value = false;
  }
}
async function searchTargets() {
  const seq = ++targetSequence;
  targetError.value = '';
  targetBusy.value = true;
  try {
    const r = await api(`/trees/${props.treeId}/people?query=${encodeURIComponent(targetQuery.value)}`);
    if (seq === targetSequence) targetPeople.value = r.items;
  } catch (e) {
    if (seq === targetSequence) targetError.value = t(e.message);
  } finally {
    if (seq === targetSequence) targetBusy.value = false;
  }
}
function addTarget(kind, targetId, label) {
  if (!draft.targets.some((x) => x.kind === kind && x.targetId === targetId))
    draft.targets.push({ kind, targetId, label, effect: 'reference' });
}
async function loadCurrent() {
  try {
    current.value = await api(
      `/trees/${props.treeId}/${isSource.value ? 'sources' : 'citations'}/${props.record.id}`,
    );
  } catch (e) {
    error.value = t(e.message);
  }
}
function reconcile() {
  draft.revision = current.value.revision;
  conflict.value = false;
  current.value = null;
  error.value = '';
  request = requestId();
}
async function save() {
  busy.value = true;
  error.value = '';
  try {
    const fields = isSource.value
      ? sourceFields.map((f) => f[0])
      : ['sourceId', 'locator', 'quotation', 'url', 'documentDate', 'accessDate', 'reliability', 'reasoning'];
    const body = Object.fromEntries(
      [...fields, 'visibility', 'revision'].filter((k) => draft[k] !== undefined).map((k) => [k, draft[k]]),
    );
    if (!isSource.value)
      body.targets = draft.targets.map(({ kind, targetId, effect }) => ({ kind, targetId, effect }));
    body.requestId = request;
    const r = await api(
      `/trees/${props.treeId}/${isSource.value ? 'sources' : 'citations'}${props.record ? '/' + props.record.id : ''}`,
      { method: props.record ? 'PUT' : 'POST', body },
    );
    emit('saved', r);
  } catch (e) {
    error.value = t(e.message);
    conflict.value = e.status === 412;
    if (e.code === 'retry_conflict') request = requestId();
  } finally {
    busy.value = false;
  }
}
useDraftGuard(dirty, busy);
</script>
<template>
  <dialog ref="dialog" class="stb-dialog" aria-labelledby="evidence-editor-title" @cancel.prevent="close">
    <form @submit.prevent="save">
      <header>
        <h2 id="evidence-editor-title">
          {{
            t(isSource ? (record ? 'Edit source' : 'New source') : record ? 'Edit citation' : 'New citation')
          }}
        </h2>
        <button type="button" class="icon-button" :disabled="busy" :aria-label="t('Close')" @click="close">
          ×
        </button>
      </header>
      <div class="dialog-body">
        <template v-if="isSource"
          ><label v-for="[field, label, max] in sourceFields" :key="field"
            >{{ t(label)
            }}<textarea
              v-if="['description', 'publication'].includes(field)"
              v-model="draft[field]"
              :maxlength="max"
              rows="3"
            ></textarea
            ><input
              v-else
              v-model="draft[field]"
              :maxlength="max"
              :required="field === 'title'"
              :type="field === 'url' ? 'url' : 'text'" /></label
        ></template>
        <template v-else>
          <p class="muted">
            {{
              t(
                'A citation records a specific passage. Reuse it across people and assertions without copying the source.',
              )
            }}
          </p>
          <div class="field-grid">
            <label
              >{{ t('Find source')
              }}<input v-model="sourceQuery" type="search" @keydown.enter.prevent="searchSources()" /></label
            ><button type="button" :disabled="searchBusy" @click="searchSources()">
              {{ t('Search sources') }}
            </button>
          </div>
          <p v-if="sourceError" class="feedback error" role="alert">{{ sourceError }}</p>
          <label
            >{{ t('Source')
            }}<select v-model="draft.sourceId" :aria-label="t('Source')" required>
              <option value="" disabled>{{ t('Choose source') }}</option>
              <option
                v-if="record?.source && !sources.some((s) => s.id === record.sourceId)"
                :value="record.sourceId"
              >
                {{ record.source.title }}
              </option>
              <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.title }}</option>
            </select></label
          >
          <button
            v-if="sourcePage !== null"
            type="button"
            :disabled="searchBusy"
            @click="searchSources(sourcePage)"
          >
            {{ t('Load more') }}
          </button>
          <p v-if="!sources.length && !searchBusy && !sourceError" class="muted">
            {{ t('Create a source in the Sources tab first.') }}
          </p>
          <label
            >{{ t('Locator')
            }}<input
              v-model="draft.locator"
              maxlength="500"
              :placeholder="t('Page, entry, image or reference')" /></label
          ><label
            >{{ t('Quotation')
            }}<textarea v-model="draft.quotation" maxlength="8000" rows="4"></textarea></label
          ><label>{{ t('Web address') }}<input v-model="draft.url" type="url" maxlength="1500" /></label>
          <DateField v-model="draft.documentDate" :label="t('Document date')" prefix="document" /><DateField
            v-model="draft.accessDate"
            :label="t('Access date')"
            prefix="access"
          />
          <div class="field-grid">
            <label
              >{{ t('Reliability')
              }}<select v-model="draft.reliability">
                <option v-for="v in ['unassessed', 'low', 'medium', 'high']" :value="v">
                  {{ t(title(v)) }}
                </option>
              </select></label
            ><label
              >{{ t('Reasoning') }}<textarea v-model="draft.reasoning" maxlength="4000" rows="3"></textarea>
            </label>
          </div>
          <h3>{{ t('Evidence targets') }}</h3>
          <p class="muted">
            {{
              t(
                'Removing a target keeps the citation and source. General person references do not prove an assertion.',
              )
            }}
          </p>
          <div
            v-for="(target, index) in draft.targets"
            :key="target.kind + target.targetId"
            class="evidence-target"
          >
            <strong>{{ target.label || target.kind }}</strong
            ><label v-if="target.kind !== 'person'"
              >{{ t('Effect')
              }}<select v-model="target.effect">
                <option v-for="v in ['reference', 'supports', 'contradicts']" :value="v">
                  {{ t(title(v)) }}
                </option>
              </select></label
            ><span v-else class="badge">{{ t('Reference') }}</span
            ><button
              type="button"
              :aria-label="t('Detach') + ' ' + target.label"
              @click="draft.targets.splice(index, 1)"
            >
              {{ t('Detach') }}
            </button>
          </div>
          <details>
            <summary>{{ t('Add evidence target') }}</summary>
            <div v-if="person" class="picker-results">
              <button
                type="button"
                @click="addTarget('person', person.id, personName(person, t('Unnamed person')))"
              >
                {{ personName(person, t('Unnamed person')) }}</button
              ><button
                v-for="c in claims || []"
                :key="c.id"
                type="button"
                @click="addTarget('claim', c.id, c.kind + ' · ' + JSON.stringify(c.value))"
              >
                {{ c.kind }} · {{ JSON.stringify(c.value) }}</button
              ><button
                v-for="r in targetRecords"
                :key="r.id"
                type="button"
                @click="addTarget('record', r.id, r.title)"
              >
                {{ r.title }}</button
              ><button
                v-for="r in relations || []"
                :key="r.id"
                type="button"
                @click="
                  addTarget(
                    'relation',
                    r.id,
                    t(title(r.kind)) + ' · ' + personName(r.person, t('Unnamed person')),
                  )
                "
              >
                {{ t(title(r.kind)) }} · {{ personName(r.person, t('Unnamed person')) }}
              </button>
            </div>
            <label
              >{{ t('Find another person')
              }}<input v-model="targetQuery" type="search" @keydown.enter.prevent="searchTargets" /></label
            ><button type="button" :disabled="targetBusy" @click="searchTargets">
              {{ t('Search people') }}
            </button>
            <p v-if="targetError" class="feedback error" role="alert">{{ targetError }}</p>
            <div class="picker-results">
              <button
                v-for="p in targetPeople"
                type="button"
                @click="addTarget('person', p.id, personName(p, t('Unnamed person')))"
              >
                {{ personName(p, t('Unnamed person')) }}
              </button>
            </div>
          </details>
        </template>
        <label v-if="management"
          >{{ t('Visibility')
          }}<select v-model="draft.visibility">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        >
        <p v-if="error" class="feedback error" role="alert">{{ error }}</p>
        <section v-if="conflict" class="comparison">
          <h3>{{ t('Your draft is still here') }}</h3>
          <button type="button" @click="loadCurrent">{{ t('Load current version') }}</button
          ><template v-if="current"
            ><p>{{ t('Compare the saved information with your draft before retrying.') }}</p>
            <pre class="evidence-comparison">{{ JSON.stringify(current, null, 2) }}</pre>
            <button type="button" @click="reconcile">
              {{ t('I compared both versions; keep my draft') }}
            </button></template
          >
        </section>
      </div>
      <footer>
        <button type="button" :disabled="busy" @click="close">{{ t('Cancel') }}</button
        ><button class="primary" :disabled="busy || conflict">{{ t(busy ? 'Saving…' : 'Save') }}</button>
      </footer>
    </form>
  </dialog>
</template>

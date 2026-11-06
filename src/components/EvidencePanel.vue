<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { api } from '../api.js';
import { t, title } from '../i18n.js';
import { dateText } from '../domain.js';
import EvidenceEditor from './EvidenceEditor.vue';
const props = defineProps({
  treeId: String,
  canEdit: Boolean,
  management: Boolean,
  person: Object,
  claims: Array,
  relations: Array,
});
const sources = ref([]),
  citations = ref([]),
  query = ref(''),
  source = ref(null),
  sourcePage = ref(null),
  citationPage = ref(null),
  loading = ref(false),
  error = ref(''),
  editor = ref(null),
  reuse = ref(false),
  busy = ref(false);
let sequence = 0;
async function load(page = 0, more = '') {
  const seq = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    if (!props.person && !source.value) {
      const r = await api(
        `/trees/${props.treeId}/sources?query=${encodeURIComponent(query.value)}&page=${page}`,
      );
      if (seq !== sequence) return;
      sources.value = more ? [...sources.value, ...r.items] : r.items;
      sourcePage.value = r.nextPage;
    } else {
      const p = new URLSearchParams({ page: String(page) });
      if (props.person && !reuse.value) p.set('person', props.person.id);
      if (source.value) p.set('source', source.value.id);
      const r = await api(`/trees/${props.treeId}/citations?${p}`);
      if (seq !== sequence) return;
      citations.value = more ? [...citations.value, ...r.items] : r.items;
      citationPage.value = r.nextPage;
    }
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
function openSource(s) {
  source.value = s;
  load();
}
function back() {
  source.value = null;
  load();
}
async function saved() {
  editor.value = null;
  if (source.value) source.value = await api(`/trees/${props.treeId}/sources/${source.value.id}`);
  await load();
}
async function attach(c) {
  busy.value = true;
  error.value = '';
  try {
    const current = await api(`/trees/${props.treeId}/citations/${c.id}`);
    const fields = [
      'sourceId',
      'locator',
      'quotation',
      'url',
      'documentDate',
      'accessDate',
      'reliability',
      'reasoning',
      'visibility',
      'revision',
    ];
    const body = Object.fromEntries(fields.map((k) => [k, current[k]]));
    body.targets = current.targets.map(({ kind, targetId, effect }) => ({ kind, targetId, effect }));
    if (!body.targets.some((x) => x.kind === 'person' && x.targetId === props.person.id))
      body.targets.push({ kind: 'person', targetId: props.person.id, effect: 'reference' });
    await api(`/trees/${props.treeId}/citations/${c.id}`, { method: 'PUT', body });
    reuse.value = false;
    await load();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
watch(
  () => props.person?.id,
  () => {
    reuse.value = false;
    source.value = null;
    load();
  },
);
onMounted(() => load());
onBeforeUnmount(() => sequence++);
</script>
<template>
  <section class="evidence-panel">
    <div class="button-row">
      <h2 v-if="!person">{{ source ? source.title : t('Sources') }}</h2>
      <button v-if="source" @click="back">← {{ t('Sources') }}</button
      ><button v-if="canEdit && !person && !source" class="primary" @click="editor = { kind: 'source' }">
        ＋ {{ t('New source') }}</button
      ><button v-if="canEdit && (person || source)" @click="editor = { kind: 'citation' }">
        ＋ {{ t('New citation') }}</button
      ><button
        v-if="person && canEdit"
        @click="
          reuse = !reuse;
          load();
        "
      >
        {{ t(reuse ? 'Show this person’s evidence' : 'Reuse existing citation') }}
      </button>
    </div>
    <form v-if="!person && !source" class="people-toolbar" @submit.prevent="load()">
      <label class="search-label"
        ><span class="sr-only">{{ t('Search sources') }}</span
        ><input v-model="query" type="search" :placeholder="t('Search sources')" /></label
      ><button>{{ t('Search') }}</button>
    </form>
    <div v-if="error" class="feedback error" role="alert">
      {{ error }} <button @click="load()">{{ t('Retry') }}</button>
    </div>
    <p v-if="loading" role="status">{{ t('Loading…') }}</p>
    <template v-if="!person && !source"
      ><p v-if="!sources.length && !loading && !error" class="empty">
        {{ t('No sources yet. Record where your information comes from.') }}
      </p>
      <div class="source-grid">
        <article v-for="s in sources" :key="s.id" class="panel source-card">
          <span class="eyebrow">{{ s.type || t('Source') }}</span>
          <h3>
            <button class="text-button" @click="openSource(s)">{{ s.title }}</button>
          </h3>
          <p>{{ [s.author, s.repository, s.callNumber].filter(Boolean).join(' · ') }}</p>
          <span v-if="s.visibility === 'management'" class="badge">{{ t('Management only') }}</span
          ><button v-if="canEdit" @click="editor = { kind: 'source', record: s }">
            {{ t('Edit source') }}
          </button>
        </article>
      </div>
      <button v-if="sourcePage !== null" :disabled="loading" @click="load(sourcePage, 'more')">
        {{ t('Load more') }}
      </button></template
    >
    <template v-else>
      <article v-if="source" class="panel source-description">
        <dl>
          <template
            v-for="[field, label] in [
              ['type', 'Source type'],
              ['author', 'Author'],
              ['publication', 'Publication'],
              ['repository', 'Repository'],
              ['callNumber', 'Call number'],
              ['description', 'Description'],
            ]"
            ><template v-if="source[field]"
              ><dt>{{ t(label) }}</dt>
              <dd class="preserve-text">{{ source[field] }}</dd></template
            ></template
          >
        </dl>
        <p v-if="source.url">
          <a :href="source.url" target="_blank" rel="noopener noreferrer">{{ t('Open source website') }} ↗</a>
        </p>
        <button v-if="canEdit" @click="editor = { kind: 'source', record: source }">
          {{ t('Edit source') }}
        </button>
      </article>
      <p v-if="reuse" class="muted">
        {{
          t(
            'Choose an existing citation to reference it from this person. Its existing targets remain attached.',
          )
        }}
      </p>
      <p v-if="!citations.length && !loading && !error" class="muted">{{ t('No citations yet') }}</p>
      <article v-for="c in citations" :key="c.id" class="panel citation-card">
        <span class="eyebrow">{{ t('Citation') }}</span>
        <h3>{{ c.source.title }}</h3>
        <p v-if="c.locator">
          <strong>{{ c.locator }}</strong>
        </p>
        <blockquote v-if="c.quotation" class="preserve-text">{{ c.quotation }}</blockquote>
        <p v-if="c.documentDate.value">{{ t('Document date') }}: {{ dateText(c.documentDate) }}</p>
        <p v-if="c.accessDate.value">{{ t('Access date') }}: {{ dateText(c.accessDate) }}</p>
        <p>{{ t('Reliability') }}: {{ t(title(c.reliability)) }}</p>
        <p v-if="c.reasoning" class="preserve-text">{{ c.reasoning }}</p>
        <p v-if="c.url">
          <a :href="c.url" target="_blank" rel="noopener noreferrer">{{ t('Open source website') }} ↗</a>
        </p>
        <ul class="citation-targets">
          <li v-for="target in c.targets" :key="target.kind + target.targetId">
            {{ target.label }} <span class="badge">{{ t(title(target.effect)) }}</span>
          </li>
        </ul>
        <span v-if="c.visibility === 'management'" class="badge">{{ t('Management only') }}</span>
        <div v-if="canEdit" class="button-row">
          <button @click="editor = { kind: 'citation', record: c }">{{ t('Edit citation') }}</button
          ><button
            v-if="reuse && !c.targets.some((x) => x.kind === 'person' && x.targetId === person.id)"
            :disabled="busy"
            @click="attach(c)"
          >
            {{ t('Reference from this person') }}
          </button>
        </div>
      </article>
      <button v-if="citationPage !== null" :disabled="loading" @click="load(citationPage, 'more')">
        {{ t('Load more') }}
      </button>
    </template>
    <EvidenceEditor
      v-if="editor"
      :tree-id="treeId"
      :management="management"
      :kind="editor.kind"
      :record="editor.record"
      :initial-source="source"
      :person="person"
      :claims="claims"
      :relations="relations"
      @close="editor = null"
      @saved="saved"
    />
  </section>
</template>

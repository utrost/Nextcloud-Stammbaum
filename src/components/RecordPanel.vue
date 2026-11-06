<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { t, title } from '../i18n.js';
import { dateText, personName } from '../domain.js';
import RecordEditor from './RecordEditor.vue';
import SafeText from './SafeText.js';
const props = defineProps({
  treeId: String,
  person: Object,
  canEdit: Boolean,
  management: Boolean,
  section: String,
});
const emit = defineEmits(['evidence']);
const records = ref([]),
  page = ref(null),
  loading = ref(false),
  error = ref(''),
  editor = ref(null),
  busy = ref(false);
let sequence = 0;
const label = (v) => t(title(v.replaceAll('_', ' '))),
  name = (p) => personName(p, t('Unnamed person'));
const items = computed(() =>
  records.value
    .filter((r) =>
      props.section === 'details'
        ? ['contact', 'attribute'].includes(r.category)
        : r.category === (props.section === 'notes' ? 'note' : 'event'),
    )
    .sort((a, b) =>
      props.section === 'notes'
        ? Number(b.preferred) - Number(a.preferred)
        : (a.date.value && a.date.kind !== 'literal' ? a.date.value : '99999').localeCompare(
            b.date.value && b.date.kind !== 'literal' ? b.date.value : '99999',
          ),
    ),
);
async function load(next = 0) {
  const seq = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const cat = props.section === 'notes' ? 'note' : props.section === 'timeline' ? 'event' : '';
    const r = await api(
      `/trees/${props.treeId}/records?person=${props.person.id}&category=${cat}&page=${next}`,
    );
    if (seq !== sequence) return;
    records.value = next ? [...records.value, ...r.items] : r.items;
    page.value = r.nextPage;
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
async function saved() {
  editor.value = null;
  await load();
}
async function prefer(r) {
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/records/${r.id}/prefer`, {
      method: 'POST',
      body: { revision: r.revision },
    });
    await load();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
watch(
  () => [props.person.id, props.section],
  () => {
    records.value = [];
    load();
  },
);
onMounted(() => load());
onBeforeUnmount(() => sequence++);
</script>
<template>
  <section class="record-panel">
    <div v-if="canEdit" class="button-row">
      <button
        v-for="category in section === 'notes'
          ? ['note']
          : section === 'details'
            ? management
              ? ['attribute', 'contact']
              : ['attribute']
            : ['event']"
        @click="editor = { category }"
      >
        + {{ t('New ' + category) }}
      </button>
    </div>
    <p v-if="error" class="feedback error" role="alert">
      {{ error }} <button @click="load()">{{ t('Retry') }}</button>
    </p>
    <p v-if="loading" role="status">{{ t('Loading…') }}</p>
    <template v-if="section === 'timeline'"
      ><article
        v-for="[kind, date, place] in [
          ['Birth', person.birthDate, person.birthPlace],
          ['Death', person.deathDate, person.deathPlace],
        ].filter((x) => x[1].value || x[2])"
        class="timeline-life"
      >
        <strong>{{ t(kind) }}</strong>
        <p>{{ dateText(date) }} {{ place }}</p>
      </article>
      <p class="muted">
        {{ t('Life dates first, then loaded events. Load further pages when offered.') }}
      </p></template
    >
    <p v-if="!loading && !error && !items.length" class="muted">{{ t('No records yet') }}</p>
    <article v-for="r in items" :key="r.id" class="record-card">
      <div class="eyebrow">
        {{ label(r.type) }} <span v-if="r.preferred" class="badge">{{ t('Preferred biography') }}</span>
      </div>
      <h3>{{ r.title }}</h3>
      <p v-if="r.date.value || r.date.end || r.place">
        {{ dateText(r.date) }} · {{ r.place }}
        <span v-if="r.ongoing" class="badge">{{ t('Current / ongoing') }}</span>
      </p>
      <SafeText v-if="r.category === 'note'" :text="r.text" />
      <p v-else class="preserve-text">{{ r.text }}</p>
      <dl v-if="r.fields.length" class="record-fields">
        <div v-for="(f, i) in r.fields" :key="i">
          <dt>{{ f.label }}</dt>
          <dd>{{ f.value }} {{ f.unit }}</dd>
        </div>
      </dl>
      <ul v-if="r.category === 'event'" class="citation-targets">
        <li v-for="p in r.participants" :key="p.personId">{{ name(p.person) }} · {{ label(p.role) }}</li>
      </ul>
      <span v-if="r.visibility === 'management'" class="badge">{{ t('Management only') }}</span>
      <details v-if="r.original" class="record-provenance">
        <summary>{{ t('Original entry') }}</summary>
        <p class="muted">{{ t('Recorded by') }} {{ r.actorId }}</p>
        <p>{{ r.original.title }}</p>
        <p class="preserve-text">{{ r.original.text }}</p>
        <p>{{ dateText(r.original.date) }} {{ r.original.place }}</p>
        <dl class="record-fields">
          <div v-for="f in r.original.fields">
            <dt>{{ f.label }}</dt>
            <dd>{{ f.value }} {{ f.unit }}</dd>
          </div>
        </dl>
      </details>
      <div class="button-row">
        <button v-if="canEdit" @click="editor = { record: r, category: r.category }">
          {{ t('Edit record') }}</button
        ><button v-if="canEdit && r.category === 'note' && !r.preferred" :disabled="busy" @click="prefer(r)">
          {{ t('Use as biography') }}</button
        ><button @click="emit('evidence')">{{ t('Sources and citations') }}</button>
      </div>
    </article>
    <button v-if="page !== null" :disabled="loading" @click="load(page)">{{ t('Load more') }}</button
    ><RecordEditor
      v-if="editor"
      :tree-id="treeId"
      :person="person"
      :management="management"
      :category="editor.category"
      :record="editor.record"
      @close="editor = null"
      @saved="saved"
    />
  </section>
</template>

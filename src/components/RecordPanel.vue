<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { api } from '../api.js';
import { t, title } from '../i18n.js';
import { dateText, personName } from '../domain.js';
import MediaPanel from './MediaPanel.vue';
import MissingMediaMatch from './MissingMediaMatch.vue';
import ResearchTaskPanel from './ResearchTaskPanel.vue';
import CalendarPanel from './CalendarPanel.vue';
import RecordEditor from './RecordEditor.vue';
import SafeText from './SafeText.js';
const props = defineProps({
  treeId: String,
  person: Object,
  canEdit: Boolean,
  canExport: Boolean,
  management: Boolean,
  section: String,
  targetEventId: String,
});
const emit = defineEmits(['evidence', 'person']);
const records = ref([]),
  page = ref(null),
  loading = ref(false),
  error = ref(''),
  editor = ref(null),
  mediaEvent = ref(null),
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
async function refreshRecord(id) {
  try {
    const current = await api(`/trees/${props.treeId}/records/${id}`);
    records.value = records.value.map((r) => (r.id === id ? current : r));
  } catch (e) {
    error.value = t(e.message);
  }
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
    mediaEvent.value = null;
    load();
  },
);
onMounted(async () => {
  await load();
  const seq = sequence;
  if (props.section === 'timeline' && props.targetEventId) {
    try {
      const event = await api(`/trees/${props.treeId}/records/${props.targetEventId}`);
      if (seq !== sequence) return;
      if (
        event.category !== 'event' ||
        !event.participants.some((participant) => participant.person.id === props.person.id)
      ) {
        throw new Error('This information is not available.');
      }
      if (!records.value.some((item) => item.id === event.id)) records.value = [event, ...records.value];
      await nextTick();
      document.querySelector(`[data-record-id="${event.id}"]`)?.focus({ preventScroll: false });
    } catch (e) {
      if (seq === sequence) error.value = t(e.message);
    }
  }
});
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
    <article v-for="r in items" :key="r.id" :data-record-id="r.id" tabindex="-1" class="record-card">
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
          <dt>
            {{
              r.original?.title === 'Missing media reference' && f.label === 'Availability'
                ? t(f.label)
                : f.label
            }}
          </dt>
          <dd>
            {{
              r.original?.title === 'Missing media reference' && f.label === 'Availability'
                ? t(f.value)
                : f.value
            }}
            {{ f.unit }}
          </dd>
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
      <MissingMediaMatch
        v-if="r.original?.title === 'Missing media reference'"
        :tree-id="treeId"
        :record="r"
        :can-edit="canEdit"
        @matched="refreshRecord(r.id)"
      />
      <div class="button-row">
        <button v-if="r.category === 'event'" @click="mediaEvent = mediaEvent === r.id ? null : r.id">
          {{ t('Event media') }}
        </button>
        <button v-if="canEdit" @click="editor = { record: r, category: r.category }">
          {{ t('Edit record') }}</button
        ><button v-if="canEdit && r.category === 'note' && !r.preferred" :disabled="busy" @click="prefer(r)">
          {{ t('Use as biography') }}</button
        ><button @click="emit('evidence')">{{ t('Sources and citations') }}</button>
      </div>
      <div v-if="r.category === 'event'" class="button-row">
        <ResearchTaskPanel :tree-id="treeId" :source="r" source-type="event" :can-export="canExport" />
        <CalendarPanel :tree-id="treeId" :event="r" :can-export="canExport" />
      </div>
      <MediaPanel
        v-if="mediaEvent === r.id"
        :tree-id="treeId"
        :event="r"
        :can-edit="canEdit"
        :can-export="canExport"
        :management="management"
        @person="emit('person', $event)"
      />
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

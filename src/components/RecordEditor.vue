<script setup>
import { useDraftGuard } from '../draftGuard.js';
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { t, title, locale } from '../i18n.js';
import { requestId, personName } from '../domain.js';
import DateField from './DateField.vue';
const props = defineProps({
  treeId: String,
  person: Object,
  record: Object,
  category: String,
  management: Boolean,
});
const emit = defineEmits(['close', 'saved']);
const choices = {
  event: [
    'marriage',
    'civil_marriage',
    'divorce',
    'separation',
    'adoption',
    'military_service',
    'occupation',
    'education',
    'residence',
    'migration',
    'baptism',
    'burial',
    'census',
    'probate',
    'will',
    'custom',
  ],
  note: ['note'],
  contact: ['email', 'phone', 'address', 'website', 'custom'],
  attribute: ['name_components', 'qualification', 'organization', 'religion', 'custom'],
};
const dialog = ref(null),
  busy = ref(false),
  error = ref(''),
  conflict = ref(false),
  current = ref(null),
  confirmed = ref(false);
const draft = reactive(
  props.record
    ? JSON.parse(JSON.stringify(props.record))
    : {
        originId: props.person.id,
        category: props.category,
        type: choices[props.category][0],
        title: '',
        text: '',
        date: { kind: 'exact', value: '', end: '' },
        place: '',
        ongoing: false,
        fields: [],
        participants: [{ personId: props.person.id, role: 'subject', person: props.person }],
        visibility: props.category === 'contact' ? 'management' : 'members',
      },
);
const original = JSON.stringify(draft),
  dirty = computed(() => JSON.stringify(draft) !== original),
  privateOnly = computed(() => draft.category === 'contact' || draft.type === 'religion');
const baseParticipants = ref(props.record?.participants || []);
const affected = computed(() => [
  ...new Map([...baseParticipants.value, ...draft.participants].map((p) => [p.personId, p])).values(),
]);
const query = ref(''),
  results = ref([]),
  searchError = ref(''),
  searching = ref(false);
let sequence = 0,
  request = requestId();
const label = (v) => t(title(v.replaceAll('_', ' '))),
  name = (p) => personName(p, t('Unnamed person'));
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
});
onBeforeUnmount(() => {
  sequence++;
  window.removeEventListener('beforeunload', unload);
});
async function search() {
  const seq = ++sequence;
  searching.value = true;
  searchError.value = '';
  try {
    const r = await api(`/trees/${props.treeId}/people?query=${encodeURIComponent(query.value)}`);
    if (seq === sequence) results.value = r.items;
  } catch (e) {
    if (seq === sequence) searchError.value = t(e.message);
  } finally {
    if (seq === sequence) searching.value = false;
  }
}
function add(p) {
  if (!draft.participants.some((x) => x.personId === p.id)) {
    draft.participants.push({ personId: p.id, role: 'participant', person: p });
    confirmed.value = false;
  }
}
async function loadCurrent() {
  try {
    current.value = await api(`/trees/${props.treeId}/records/${props.record.id}`);
  } catch (e) {
    error.value = t(e.message);
  }
}
function reconcile() {
  draft.revision = current.value.revision;
  baseParticipants.value = current.value.participants;
  current.value = null;
  conflict.value = false;
  error.value = '';
  request = requestId();
  confirmed.value = false;
}
async function save() {
  busy.value = true;
  error.value = '';
  try {
    const keys = [
      'originId',
      'category',
      'type',
      'title',
      'text',
      'date',
      'place',
      'ongoing',
      'fields',
      'visibility',
      'revision',
      'confirmRelease',
    ];
    const body = Object.fromEntries(keys.filter((k) => draft[k] !== undefined).map((k) => [k, draft[k]]));
    body.participants = draft.participants.map(({ personId, role }) => ({ personId, role }));
    body.confirmedParticipants = confirmed.value ? affected.value.map((p) => p.personId) : [];
    body.requestId = request;
    const r = await api(`/trees/${props.treeId}/records${props.record ? '/' + props.record.id : ''}`, {
      method: props.record ? 'PUT' : 'POST',
      body,
    });
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
  <dialog ref="dialog" class="stb-dialog" aria-labelledby="record-editor-title" @cancel.prevent="close">
    <form @submit.prevent="save">
      <header>
        <div>
          <span class="eyebrow">{{ name(person) }}</span>
          <h2 id="record-editor-title">
            {{ t(record ? 'Edit record' : 'New record') }} · {{ label(draft.category) }}
          </h2>
        </div>
        <button type="button" class="icon-button" :disabled="busy" :aria-label="t('Close')" @click="close">
          ×
        </button>
      </header>
      <div class="dialog-body">
        <label
          >{{ t('Type')
          }}<select v-model="draft.type" :aria-label="t('Type')">
            <option
              v-for="v in choices[draft.category].filter((v) => management || v !== 'religion')"
              :value="v"
            >
              {{ label(v) }}
            </option>
          </select></label
        ><label>{{ t('Title') }}<input v-model="draft.title" maxlength="250" required /></label
        ><label
          >{{ t(draft.category === 'note' ? 'Note text' : 'Description')
          }}<textarea v-model="draft.text" maxlength="20000" rows="6"></textarea>
        </label>
        <p v-if="draft.category === 'note'" class="muted">
          {{
            t(
              'Use paragraphs, **bold**, *emphasis*, lists and [label](https://example.org) links. HTML and image instructions remain literal text.',
            )
          }}
        </p>
        <template v-if="draft.category !== 'note'"
          ><DateField v-model="draft.date" :label="t('Date')" prefix="record" /><label
            >{{ t('Place') }}<input v-model="draft.place" maxlength="500" /></label
          ><label v-if="draft.category === 'event'" class="check"
            ><input type="checkbox" v-model="draft.ongoing" />{{ t('Current / ongoing') }}</label
          >
          <h3>{{ t('Structured fields') }}</h3>
          <div v-for="(f, index) in draft.fields" :key="index" class="structured-field">
            <label>{{ t('Label') }}<input v-model="f.label" maxlength="100" required /></label
            ><label>{{ t('Value') }}<input v-model="f.value" maxlength="2000" /></label
            ><label>{{ t('Unit') }}<input v-model="f.unit" maxlength="50" /></label
            ><button type="button" @click="draft.fields.splice(index, 1)">{{ t('Remove') }}</button>
          </div>
          <button
            type="button"
            :disabled="draft.fields.length >= 32"
            @click="draft.fields.push({ label: '', value: '', unit: '' })"
          >
            ＋ {{ t('Add field') }}
          </button></template
        >
        <section v-if="draft.category === 'event'" class="record-participants">
          <h3>{{ t('Participants') }}</h3>
          <p class="muted">
            {{
              t(
                'One shared record appears in each participant’s timeline. The original person remains attached.',
              )
            }}
          </p>
          <div v-for="(p, index) in draft.participants" :key="p.personId" class="evidence-target">
            <strong>{{ name(p.person) }}</strong
            ><label>{{ t('Role') }}<input v-model="p.role" maxlength="100" required /></label
            ><button
              v-if="p.personId !== draft.originId"
              type="button"
              @click="
                draft.participants.splice(index, 1);
                confirmed = false;
              "
            >
              {{ t('Remove') }}
            </button>
          </div>
          <label
            >{{ t('Find another person')
            }}<input v-model="query" type="search" @keydown.enter.prevent="search" /></label
          ><button type="button" :disabled="searching" @click="search">{{ t('Search people') }}</button>
          <p v-if="searchError" role="alert">{{ searchError }}</p>
          <div class="picker-results">
            <button
              v-for="p in results.filter((p) => !draft.participants.some((x) => x.personId === p.id))"
              type="button"
              @click="add(p)"
            >
              {{ name(p) }}
            </button>
          </div>
          <label class="check"
            ><input type="checkbox" v-model="confirmed" required />{{
              t('I confirm this change for these affected people:')
            }}
            {{ affected.map((p) => name(p.person)).join(', ') }}</label
          >
        </section>
        <p v-if="privateOnly" class="feedback">
          {{ t('Contacts and religious affiliation are management-only.') }}
        </p>
        <label v-else-if="management"
          >{{ t('Visibility')
          }}<select v-model="draft.visibility" :aria-label="t('Visibility')">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        ><label
          v-if="record?.visibility === 'management' && draft.visibility === 'members' && !privateOnly"
          class="check"
          ><input type="checkbox" v-model="draft.confirmRelease" required />{{
            t('Release these current values to all tree members. Private history stays restricted.')
          }}</label
        >
        <label class="language-label"
          >Language<select v-model="locale">
            <option value="en">English</option>
            <option value="de">Deutsch</option>
          </select></label
        >
        <p v-if="error" role="alert" class="feedback error">{{ error }}</p>
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

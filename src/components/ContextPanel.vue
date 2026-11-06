<script setup>
import { useDraftGuard } from '../draftGuard.js';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { api } from '../api.js';
import { t, title } from '../i18n.js';
import { requestId, personName } from '../domain.js';
const props = defineProps({ treeId: String, person: Object, canEdit: Boolean, management: Boolean }),
  emit = defineEmits(['changed', 'select']);
const items = ref([]),
  page = ref(null),
  loading = ref(false),
  error = ref(''),
  dialog = ref(null),
  editing = ref(false),
  originalRecord = ref(null),
  busy = ref(false),
  draft = reactive({}),
  query = ref(''),
  results = ref([]),
  connections = ref([]),
  conflict = ref(false),
  current = ref(null);
let sequence = 0,
  searchSequence = 0,
  request = requestId(),
  original = '';
const name = (p) => personName(p, t('Unnamed person')),
  dirty = computed(() => editing.value && JSON.stringify(draft) !== original);
const choices = computed(() =>
  connections.value.filter(
    (r) =>
      r.active !== false &&
      draft.members?.some((m) => m.personId === r.sourceId) &&
      draft.members?.some((m) => m.personId === r.targetId),
  ),
);
const memberNames = computed(() =>
  Object.fromEntries((draft.members || []).map((m) => [m.personId, name(m.person)])),
);
async function load(next = 0) {
  const seq = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const r = await api(`/trees/${props.treeId}/contexts?person=${props.person.id}&page=${next}`);
    if (seq !== sequence) return;
    items.value = next ? [...items.value, ...r.items] : r.items;
    page.value = r.nextPage;
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
async function loadConnections(id) {
  try {
    const r = await api(`/trees/${props.treeId}/people/${id}/relations`);
    if (editing.value)
      connections.value = [...new Map([...connections.value, ...r.items].map((x) => [x.id, x])).values()];
  } catch (e) {
    error.value = t(e.message);
  }
}
async function edit(record) {
  originalRecord.value = record || null;
  Object.keys(draft).forEach((k) => delete draft[k]);
  Object.assign(
    draft,
    record
      ? JSON.parse(JSON.stringify(record))
      : {
          label: '',
          visibility: 'members',
          members: [{ personId: props.person.id, person: props.person, role: 'child', description: '' }],
          relations: [],
        },
  );
  draft.relationIds = draft.relations.filter((r) => r.active).map((r) => r.id);
  original = JSON.stringify(draft);
  connections.value = [...draft.relations];
  editing.value = true;
  conflict.value = false;
  current.value = null;
  error.value = '';
  request = requestId();
  await nextTick();
  dialog.value.showModal();
  loadConnections(props.person.id);
}
function close() {
  if (busy.value) return;
  if (dirty.value && !confirm(t('Discard unsaved changes?'))) return;
  dialog.value.close();
  editing.value = false;
  searchSequence++;
}
function move(index, step) {
  const list = [...draft.members];
  [list[index], list[index + step]] = [list[index + step], list[index]];
  draft.members = list;
}
async function search() {
  const seq = ++searchSequence;
  try {
    const r = await api(`/trees/${props.treeId}/people?query=${encodeURIComponent(query.value)}`);
    if (seq === searchSequence) results.value = r.items;
  } catch (e) {
    error.value = t(e.message);
  }
}
function add(p) {
  if (!draft.members.some((m) => m.personId === p.id)) {
    draft.members.push({ personId: p.id, person: p, role: 'child', description: '' });
    loadConnections(p.id);
  }
}
async function loadCurrent() {
  try {
    current.value = await api(`/trees/${props.treeId}/contexts/${originalRecord.value.id}`);
  } catch (e) {
    error.value = t(e.message);
  }
}
function reconcile() {
  draft.revision = current.value.revision;
  conflict.value = false;
  current.value = null;
  error.value = '';
}
async function save() {
  busy.value = true;
  error.value = '';
  try {
    const body = {
      label: draft.label,
      visibility: draft.visibility,
      revision: draft.revision,
      confirmRelease: draft.confirmRelease === true,
      requestId: request,
      members: draft.members.map(({ personId, role, description }) => ({ personId, role, description })),
      relationIds: draft.relationIds.filter((id) => choices.value.some((r) => r.id === id)),
    };
    await api(`/trees/${props.treeId}/contexts${originalRecord.value ? '/' + originalRecord.value.id : ''}`, {
      method: originalRecord.value ? 'PUT' : 'POST',
      body,
    });
    dialog.value.close();
    editing.value = false;
    await load();
    emit('changed');
  } catch (e) {
    error.value = t(e.message);
    conflict.value = e.status === 412;
    if (e.code === 'retry_conflict') request = requestId();
  } finally {
    busy.value = false;
  }
}
function unload(e) {
  if (dirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}
watch(
  () => props.person.id,
  () => {
    items.value = [];
    load();
  },
);
onMounted(() => {
  load();
  window.addEventListener('beforeunload', unload);
});
onBeforeUnmount(() => {
  sequence++;
  searchSequence++;
  window.removeEventListener('beforeunload', unload);
});
useDraftGuard(dirty, busy);
</script>
<template>
  <section class="context-panel">
    <h3>{{ t('Family contexts') }}</h3>
    <p class="muted">
      {{
        t(
          'Group and order known people, including siblings with unknown parents. Grouping creates no connections.',
        )
      }}
    </p>
    <button v-if="canEdit" @click="edit()">＋ {{ t('New context') }}</button>
    <p v-if="error && !editing" class="feedback error" role="alert">
      {{ error }} <button @click="load()">{{ t('Retry') }}</button>
    </p>
    <p v-if="loading" role="status">{{ t('Loading…') }}</p>
    <article v-for="c in items" :key="c.id" class="record-card">
      <h3>{{ c.label }}</h3>
      <ol class="context-members">
        <li v-for="m in c.members" :key="m.personId">
          <button class="text-button" @click="emit('select', m.personId)">{{ name(m.person) }}</button> ·
          {{ t(title(m.role)) }}<small v-if="m.description">{{ m.description }}</small>
        </li>
      </ol>
      <p v-if="c.relations.some((r) => !r.active)" class="muted">
        {{
          t(
            'This context retains inactive connection references. Saving drops those references, while history remains.',
          )
        }}
      </p>
      <button v-if="canEdit" @click="edit(c)">{{ t('Edit context') }}</button>
    </article>
    <button v-if="page !== null" :disabled="loading" @click="load(page)">{{ t('Load more') }}</button>
    <dialog ref="dialog" class="stb-dialog" aria-labelledby="context-title" @cancel.prevent="close">
      <form v-if="editing" @submit.prevent="save">
        <header>
          <h2 id="context-title">{{ t(originalRecord ? 'Edit context' : 'New context') }}</h2>
          <button type="button" class="icon-button" :disabled="busy" :aria-label="t('Close')" @click="close">
            ×
          </button>
        </header>
        <div class="dialog-body">
          <p class="muted">
            {{
              t(
                'Grouping creates no parent relationship or partnership. The order below is your recorded order.',
              )
            }}
          </p>
          <label>{{ t('Label') }}<input v-model="draft.label" maxlength="250" required /></label>
          <section v-for="(m, index) in draft.members" :key="m.personId" class="context-member-edit">
            <h3>{{ name(m.person) }}</h3>
            <div class="field-grid">
              <label
                >{{ t('Role')
                }}<select v-model="m.role" :aria-label="t('Role') + ' ' + name(m.person)">
                  <option value="parent">{{ t('Parent') }}</option>
                  <option value="child">{{ t('Child') }}</option>
                </select></label
              ><label>{{ t('Role description') }}<input v-model="m.description" maxlength="250" /></label>
            </div>
            <div class="button-row">
              <button
                type="button"
                :disabled="index === 0"
                :aria-label="t('Move up') + ' ' + name(m.person)"
                @click="move(index, -1)"
              >
                ↑ {{ t('Move up') }}</button
              ><button
                type="button"
                :disabled="index === draft.members.length - 1"
                :aria-label="t('Move down') + ' ' + name(m.person)"
                @click="move(index, 1)"
              >
                ↓ {{ t('Move down') }}</button
              ><button type="button" @click="draft.members.splice(index, 1)">{{ t('Remove') }}</button>
            </div>
          </section>
          <label
            >{{ t('Find another person')
            }}<input v-model="query" type="search" @keydown.enter.prevent="search" /></label
          ><button type="button" @click="search">{{ t('Search people') }}</button>
          <div class="picker-results">
            <button
              v-for="p in results.filter((p) => !draft.members.some((m) => m.personId === p.id))"
              type="button"
              @click="add(p)"
            >
              {{ name(p) }}
            </button>
          </div>
          <h3>{{ t('Reference existing connections') }}</h3>
          <label v-for="r in choices" :key="r.id" class="check"
            ><input v-model="draft.relationIds" type="checkbox" :value="r.id" />{{
              memberNames[r.sourceId]
            }}
            → {{ memberNames[r.targetId] }} · {{ t(r.kind === 'partner' ? 'Partner' : 'Parent') }}</label
          >
          <p v-if="!choices.length" class="muted">{{ t('No existing connections between these people.') }}</p>
          <label v-if="management"
            >{{ t('Visibility')
            }}<select v-model="draft.visibility" :aria-label="t('Visibility')">
              <option value="members">{{ t('All tree members') }}</option>
              <option value="management">{{ t('Management only') }}</option>
            </select></label
          ><label
            v-if="originalRecord?.visibility === 'management' && draft.visibility === 'members'"
            class="check"
            ><input v-model="draft.confirmRelease" type="checkbox" required />{{
              t('Release these current values to all tree members. Private history stays restricted.')
            }}</label
          >
          <p v-if="error" class="feedback error" role="alert">{{ error }}</p>
          <section v-if="conflict" class="comparison">
            <h3>{{ t('Your draft is still here') }}</h3>
            <button type="button" @click="loadCurrent">{{ t('Load current version') }}</button
            ><template v-if="current">
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
  </section>
</template>

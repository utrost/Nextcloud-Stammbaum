<script setup>
import { useDraftGuard } from '../draftGuard.js';
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { t, locale } from '../i18n.js';
import { emptyPerson, editable, personName, dateText, requestId } from '../domain.js';
import { api } from '../api.js';
import PersonFields from './PersonFields.vue';
const props = defineProps({ person: Object, management: Boolean, treeId: String, relativeTo: Object });
const emit = defineEmits(['saved', 'close']);
const dialog = ref(null),
  busy = ref(false),
  error = ref(''),
  conflict = ref(false),
  current = ref(null),
  compared = ref(false);
const draft = reactive(
  props.person ? { ...editable(props.person), revision: props.person.revision } : emptyPerson(),
);
const original = JSON.stringify(draft),
  direction = ref('child'),
  parentType = ref('unknown'),
  existing = ref(false),
  query = ref(''),
  results = ref([]),
  chosen = ref(null),
  searching = ref(false),
  searchError = ref(''),
  episode = ref(false);
let request = requestId(),
  searchSequence = 0,
  searchTimer;
const dirty = computed(
  () =>
    JSON.stringify(draft) !== original ||
    chosen.value !== null ||
    direction.value !== 'child' ||
    parentType.value !== 'unknown' ||
    existing.value ||
    episode.value,
);
const name = (p) => personName(p, t('Unnamed person'));
const comparisons = computed(() =>
  current.value
    ? [
        [t('Name'), name(current.value), name(draft)],
        [t('Display note'), current.value.displayNote, draft.displayNote],
        [t('Living status'), current.value.livingStatus, draft.livingStatus],
        [t('Sex'), current.value.sex, draft.sex],
        [t('Visibility'), current.value.visibility, draft.visibility],
        [t('Birth'), dateText(current.value.birthDate), dateText(draft.birthDate)],
        [t('Birth place'), current.value.birthPlace, draft.birthPlace],
        [t('Death'), dateText(current.value.deathDate), dateText(draft.deathDate)],
        [t('Death place'), current.value.deathPlace, draft.deathPlace],
      ].filter((r) => r[1] !== r[2])
    : [],
);
function close() {
  if (busy.value) return;
  if (dirty.value && !window.confirm(t('Discard unsaved changes?'))) return;
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
  window.removeEventListener('beforeunload', unload);
  clearTimeout(searchTimer);
  searchSequence++;
});
async function loadCurrent() {
  try {
    current.value = await api(`/trees/${props.treeId}/people/${props.person?.id || props.relativeTo.id}`);
    compared.value = false;
  } catch (e) {
    error.value = t(e.message);
  }
}
function reconcile() {
  if (!current.value) return;
  draft.revision = current.value.revision;
  compared.value = true;
  conflict.value = false;
  request = requestId();
  error.value = '';
}
function search() {
  clearTimeout(searchTimer);
  chosen.value = null;
  results.value = [];
  searchError.value = '';
  searching.value = true;
  const seq = ++searchSequence;
  searchTimer = setTimeout(async () => {
    try {
      const r = await api(`/trees/${props.treeId}/people?query=${encodeURIComponent(query.value)}`);
      if (seq === searchSequence) results.value = r.items.filter((p) => p.id !== props.relativeTo.id);
    } catch (e) {
      if (seq === searchSequence) searchError.value = t(e.message);
    } finally {
      if (seq === searchSequence) searching.value = false;
    }
  }, 250);
}
async function save() {
  busy.value = true;
  error.value = '';
  try {
    let result;
    if (props.relativeTo) {
      const body = {
        requestId: request,
        revision: draft.revision ?? props.relativeTo.revision,
        direction: direction.value,
        parentType: parentType.value,
        confirmEpisode: episode.value,
      };
      if (existing.value) {
        if (!chosen.value) throw new Error(t('Choose person'));
        body.personId = chosen.value.id;
        body.targetRevision = chosen.value.revision;
      } else body.person = { ...draft };
      result = await api(`/trees/${props.treeId}/people/${props.relativeTo.id}/relations`, {
        method: 'POST',
        body,
      });
      emit('saved', {
        relative: true,
        personId: result.sourceId === props.relativeTo.id ? result.targetId : result.sourceId,
      });
    } else {
      result = await api(`/trees/${props.treeId}/people${props.person ? '/' + props.person.id : ''}`, {
        method: props.person ? 'PUT' : 'POST',
        body: { ...draft, requestId: request },
      });
      emit('saved', { person: result });
    }
  } catch (e) {
    error.value = t(e.message);
    conflict.value = e.status === 412;
    if (e.status === 409 && e.code === 'retry_conflict') request = requestId();
  } finally {
    busy.value = false;
  }
}
useDraftGuard(dirty, busy);
</script>
<template>
  <dialog ref="dialog" class="stb-dialog" aria-labelledby="editor-title" @cancel.prevent="close">
    <form @submit.prevent="save">
      <header>
        <div>
          <span class="eyebrow">{{
            relativeTo ? name(relativeTo) : t('Unknown information stays unknown.')
          }}</span>
          <h2 id="editor-title">
            {{ t(relativeTo ? 'Add relative' : person ? 'Edit person' : 'New person') }}
          </h2>
        </div>
        <button type="button" class="icon-button" :aria-label="t('Close')" :disabled="busy" @click="close">
          ×
        </button>
      </header>
      <div class="dialog-body">
        <template v-if="relativeTo">
          <div class="field-grid">
            <label for="relative-direction"
              >{{ t('Connections')
              }}<select id="relative-direction" v-model="direction">
                <option value="parent">{{ t('Parent') }}</option>
                <option value="child">{{ t('Child') }}</option>
                <option value="partner">{{ t('Partner') }}</option>
              </select></label
            >
            <label v-if="direction !== 'partner'" for="parent-type"
              >{{ t('Parent relationship')
              }}<select id="parent-type" v-model="parentType">
                <option
                  v-for="v in ['unknown', 'biological', 'adoptive', 'foster', 'step', 'other']"
                  :value="v"
                >
                  {{ t(v[0].toUpperCase() + v.slice(1)) }}
                </option>
              </select></label
            >
          </div>
          <p class="muted">{{ t('A name, sex or partnership never implies parenthood.') }}</p>
          <div class="segmented">
            <button type="button" :aria-pressed="!existing" @click="existing = false">
              {{ t('Create a new person') }}</button
            ><button
              type="button"
              :aria-pressed="existing"
              @click="
                existing = true;
                search();
              "
            >
              {{ t('Connect an existing person') }}
            </button>
          </div>
          <div v-if="existing">
            <label for="relative-search"
              >{{ t('Search people') }}<input id="relative-search" v-model="query" @input="search"
            /></label>
            <p v-if="searching" role="status">{{ t('Loading…') }}</p>
            <p v-if="searchError" role="alert">{{ searchError }}</p>
            <div class="picker-results">
              <button
                v-for="p in results"
                :key="p.id"
                type="button"
                :aria-pressed="chosen?.id === p.id"
                @click="chosen = p"
              >
                {{ name(p) }} <small>{{ dateText(p.birthDate) }}</small>
              </button>
            </div>
            <p v-if="!searching && !searchError && !results.length">{{ t('No matching people') }}</p>
          </div>
          <label v-if="direction === 'partner'" class="check"
            ><input type="checkbox" v-model="episode" />{{
              t('This is a separate partnership episode.')
            }}</label
          >
        </template>
        <PersonFields
          v-if="!relativeTo || !existing"
          :draft="draft"
          :management="management"
          :releasing="person?.visibility === 'management'"
        />
        <p class="muted" v-if="!relativeTo">
          {{ t('Changes retain previous names, dates and places as assertions.') }}
        </p>
        <div v-if="error" class="feedback error" role="alert">{{ error }}</div>
        <div v-if="conflict || current" class="comparison">
          <p>{{ t('Your draft is still here.') }}</p>
          <button type="button" @click="loadCurrent">{{ t('Load current version for comparison') }}</button>
          <template v-if="current"
            ><table v-if="person">
              <thead>
                <tr>
                  <th></th>
                  <th>{{ t('Current saved value') }}</th>
                  <th>{{ t('Your draft') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in comparisons">
                  <th>{{ r[0] }}</th>
                  <td>{{ r[1] || t('Not recorded') }}</td>
                  <td>{{ r[2] || t('Not recorded') }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else>{{ name(current) }} · {{ t('Current saved value') }}: {{ current.revision }}</p>
            <button type="button" :disabled="compared" @click="reconcile">
              {{ t('I compared the changes. Keep my draft and use the current version.') }}
            </button></template
          >
        </div>
      </div>
      <footer>
        <select v-model="locale" aria-label="Language" style="width: 110px; margin: 0 auto 0 0">
          <option value="de">Deutsch</option>
          <option value="en">English</option></select
        ><button type="button" :disabled="busy" @click="close">{{ t('Cancel') }}</button
        ><button
          class="primary"
          :disabled="busy || conflict || (relativeTo && existing && !chosen)"
          type="submit"
        >
          {{ t(busy ? 'Saving…' : relativeTo ? 'Connect' : 'Save') }}
        </button>
      </footer>
    </form>
  </dialog>
</template>

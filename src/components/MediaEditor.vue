<script setup>
import { useDraftGuard } from '../draftGuard.js';
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import { personName } from '../domain.js';
const props = defineProps({ treeId: String, record: Object, management: Boolean }),
  emit = defineEmits(['close', 'saved']);
const dialog = ref(null),
  draft = reactive(JSON.parse(JSON.stringify(props.record))),
  busy = ref(false),
  error = ref(''),
  conflict = ref(false),
  current = ref(null),
  query = ref(''),
  results = ref([]),
  searching = ref(false);
const original = JSON.stringify(draft),
  dirty = computed(() => JSON.stringify(draft) !== original),
  name = (p) => personName(p, t('Unnamed person'));
let sequence = 0;
const preview = computed(
  () => `${apiBase}/trees/${props.treeId}/media/${props.record.id}/preview?revision=${props.record.revision}`,
);
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
  error.value = '';
  try {
    const r = await api(`/trees/${props.treeId}/people?query=${encodeURIComponent(query.value)}`);
    if (seq === sequence) results.value = r.items;
  } catch (e) {
    error.value = t(e.message);
  } finally {
    if (seq === sequence) searching.value = false;
  }
}
function add(p) {
  if (!draft.assignments.some((a) => a.personId === p.id))
    draft.assignments.push({
      personId: p.id,
      person: p,
      purpose: 'attachment',
      preferred: false,
      crop: { x: 0, y: 0, width: 1, height: 1 },
    });
}
async function loadCurrent() {
  try {
    current.value = await api(`/trees/${props.treeId}/media/${props.record.id}`);
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
    const result = await api(`/trees/${props.treeId}/media/${props.record.id}`, {
      method: 'PUT',
      body: {
        title: draft.title,
        visibility: draft.visibility,
        revision: draft.revision,
        confirmRelease: draft.confirmRelease === true,
        assignments: draft.assignments.map(({ personId, purpose, preferred, crop }) => ({
          personId,
          purpose,
          preferred,
          crop,
        })),
      },
    });
    emit('saved', result);
  } catch (e) {
    error.value = t(e.message);
    conflict.value = e.status === 412;
  } finally {
    busy.value = false;
  }
}
useDraftGuard(dirty, busy);
</script>
<template>
  <dialog ref="dialog" class="stb-dialog" aria-labelledby="media-editor-title" @cancel.prevent="close">
    <form @submit.prevent="save">
      <header>
        <h2 id="media-editor-title">{{ t('Edit media') }}</h2>
        <button class="icon-button" type="button" :disabled="busy" :aria-label="t('Close')" @click="close">
          ×
        </button>
      </header>
      <div class="dialog-body">
        <img
          v-if="record.mime !== 'application/pdf'"
          class="media-editor-preview"
          :src="preview"
          :alt="record.title"
        />
        <p class="muted">
          {{ t('The original file stays unchanged. Crops and portrait choices affect presentation only.') }}
        </p>
        <label>{{ t('Title') }}<input v-model="draft.title" maxlength="250" required /></label
        ><label v-if="management"
          >{{ t('Visibility')
          }}<select v-model="draft.visibility" :aria-label="t('Visibility')">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        ><label v-if="record.visibility === 'management' && draft.visibility === 'members'" class="check"
          ><input v-model="draft.confirmRelease" type="checkbox" required />{{
            t('Release these current values to all tree members. Private history stays restricted.')
          }}</label
        >
        <h3>{{ t('Person assignments') }}</h3>
        <p class="muted">{{ t('Detaching keeps the original in the media library.') }}</p>
        <section v-for="(a, index) in draft.assignments" :key="a.personId" class="media-assignment">
          <div class="button-row">
            <h3>{{ name(a.person) }}</h3>
            <button
              type="button"
              :aria-label="t('Detach') + ' ' + name(a.person)"
              @click="draft.assignments.splice(index, 1)"
            >
              {{ t('Detach') }}
            </button>
          </div>
          <label
            >{{ t('Purpose')
            }}<select v-model="a.purpose" :aria-label="t('Purpose')" @change="a.preferred = false">
              <option value="attachment">{{ t('Attachment') }}</option>
              <option v-if="record.mime !== 'application/pdf'" value="portrait">{{ t('Portrait') }}</option>
            </select></label
          ><label v-if="a.purpose === 'portrait'" class="check"
            ><input type="checkbox" v-model="a.preferred" />{{ t('Preferred portrait') }}</label
          ><template v-if="record.mime !== 'application/pdf'"
            ><div class="crop-fields">
              <label
                v-for="[key, label] in [
                  ['x', 'Left'],
                  ['y', 'Top'],
                  ['width', 'Width'],
                  ['height', 'Height'],
                ]"
                >{{ t(label) }} (%)<input
                  type="number"
                  :value="Math.round(a.crop[key] * 10000) / 100"
                  min="0"
                  max="100"
                  step="0.1"
                  required
                  @input="a.crop[key] = Number($event.target.value) / 100"
              /></label>
            </div>
            <div class="crop-overview">
              <img :src="preview" :alt="t('Crop preview')" /><span
                :style="{
                  left: a.crop.x * 100 + '%',
                  top: a.crop.y * 100 + '%',
                  width: a.crop.width * 100 + '%',
                  height: a.crop.height * 100 + '%',
                }"
              ></span>
            </div>
            <button type="button" @click="a.crop = { x: 0, y: 0, width: 1, height: 1 }">
              {{ t('Reset crop') }}
            </button></template
          >
        </section>
        <label
          >{{ t('Find another person')
          }}<input type="search" v-model="query" @keydown.enter.prevent="search" /></label
        ><button type="button" :disabled="searching" @click="search">{{ t('Search people') }}</button>
        <div class="picker-results">
          <button
            v-for="p in results.filter((p) => !draft.assignments.some((a) => a.personId === p.id))"
            type="button"
            @click="add(p)"
          >
            {{ name(p) }}
          </button>
        </div>
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
</template>

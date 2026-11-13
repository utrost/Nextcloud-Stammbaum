<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { api, apiBase } from '../api.js';
import { requestId } from '../domain.js';
import { t } from '../i18n.js';
import MediaPreview from './MediaPreview.vue';

const props = defineProps({ treeId: String, record: Object, canEdit: Boolean });
const emit = defineEmits(['matched']);
const review = ref(null),
  selected = ref(null),
  title = ref(''),
  busy = ref(false),
  loading = ref(false),
  error = ref('');
let sequence = 0;
const base = () => `/trees/${props.treeId}/media`;
async function load() {
  const current = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const result = await api(`${base()}/reference/${props.record.id}`);
    if (current === sequence) review.value = result;
  } catch (e) {
    if (current === sequence) {
      review.value = null;
      error.value = t(e.message);
    }
  } finally {
    if (current === sequence) loading.value = false;
  }
}
function choose(reference) {
  error.value = '';
  if (!window.OC?.dialogs?.filepicker) {
    error.value = t('The Nextcloud file picker is unavailable. Reload the page and try again.');
    return;
  }
  window.OC.dialogs.filepicker(
    t('Choose from Nextcloud Files'),
    async (path) => {
      if (!path) return;
      busy.value = true;
      try {
        const file = await api(`${base()}/select-file`, { method: 'POST', body: { path } });
        selected.value = { reference, file, requestId: requestId() };
        title.value = file.name;
      } catch (e) {
        error.value = t(e.message);
      } finally {
        busy.value = false;
      }
    },
    false,
    ['image/jpeg', 'image/png', 'application/pdf'],
    true,
  );
}
async function match() {
  if (!selected.value || !review.value) return;
  busy.value = true;
  error.value = '';
  try {
    const choice = selected.value;
    await api(`${base()}/import-file`, {
      method: 'POST',
      body: {
        fileId: choice.file.id,
        etag: choice.file.etag,
        title: title.value,
        visibility: review.value.visibility,
        requestId: choice.requestId,
        referenceRecordId: review.value.recordId,
        referenceField: choice.reference.fieldIndex,
        referenceRevision: review.value.revision,
      },
    });
    selected.value = null;
    await load();
    emit('matched');
  } catch (e) {
    error.value = t(e.message);
    if (e.code === 'retry_conflict') selected.value.requestId = requestId();
  } finally {
    busy.value = false;
  }
}
watch(
  () => [props.treeId, props.record.id, props.record.revision],
  () => {
    selected.value = null;
    review.value = null;
    load();
  },
);
onMounted(load);
onBeforeUnmount(() => sequence++);
</script>

<template>
  <section class="missing-media-match" :aria-busy="loading || busy">
    <h4>{{ t('Referenced media') }}</h4>
    <p v-if="loading" role="status">{{ t('Loading media references…') }}</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <button v-if="error && !loading" type="button" :disabled="busy" @click="load">
      {{ t('Retry') }}
    </button>
    <ul v-if="review?.references.length" class="missing-media-list">
      <li v-for="reference in review.references" :key="reference.fieldIndex">
        <p class="preserve-text">
          <strong>{{ t('Original FILE reference') }}:</strong> {{ reference.path }}
        </p>
        <template v-if="reference.medium">
          <p>{{ t('Matched private copy') }}: {{ reference.medium.title }}</p>
          <MediaPreview
            :url="`${apiBase}${base()}/${reference.medium.id}/preview?revision=${reference.medium.revision}&size=320`"
            :label="reference.medium.title"
            lazy
            @unavailable="load"
          />
          <a :href="`${apiBase}${base()}/${reference.medium.id}/original`" download>{{
            t('Download original')
          }}</a>
        </template>
        <p v-else-if="reference.state === 'unavailable'" class="muted">
          {{ t('The matched copy is unavailable. A manager can review media trash and permissions.') }}
        </p>
        <button v-else-if="canEdit" type="button" :disabled="busy" @click="choose(reference)">
          {{ t('Match a Nextcloud Files copy') }}
        </button>
        <p v-else class="muted">{{ t('No private copy has been matched yet.') }}</p>
      </li>
    </ul>
    <div v-if="selected && review" class="missing-media-confirm">
      <h5>{{ t('Review private copy') }}</h5>
      <p class="preserve-text">{{ t('Original FILE reference') }}: {{ selected.reference.path }}</p>
      <p>
        {{ t('Nextcloud file') }}: {{ selected.file.name }} · {{ selected.file.mime }} ·
        {{ selected.file.size }} {{ t('bytes') }}
      </p>
      <p>
        {{ t('Target') }}: {{ t('this family tree') }} ·
        {{ t(review.visibility === 'management' ? 'Management only' : 'Tree members') }}
      </p>
      <p class="muted">
        {{
          t(
            'An unchanged private copy will consume Stammbaum quota. Later source edits, deletion or sharing changes will not alter this copy. The original GEDCOM reference remains unchanged.',
          )
        }}
      </p>
      <label>{{ t('Media title') }} <input v-model="title" :disabled="busy" maxlength="250" /></label>
      <div class="button-row">
        <button class="primary" type="button" :disabled="busy || !title.trim()" @click="match">
          {{ t('Import and match private copy') }}
        </button>
        <button type="button" :disabled="busy" @click="selected = null">{{ t('Cancel') }}</button>
      </div>
    </div>
  </section>
</template>

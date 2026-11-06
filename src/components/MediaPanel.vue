<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue';
import { useDraftGuard } from '../draftGuard.js';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import { requestId, personName } from '../domain.js';
import MediaEditor from './MediaEditor.vue';
const props = defineProps({ treeId: String, person: Object, canEdit: Boolean, management: Boolean }),
  emit = defineEmits(['changed']);
const items = ref([]),
  page = ref(null),
  loading = ref(false),
  error = ref(''),
  editor = ref(null),
  file = ref(null),
  fileInput = ref(null),
  uploading = ref(false),
  title = ref(''),
  visibility = ref('members');
useDraftGuard(
  computed(() => file.value !== null),
  uploading,
);
let sequence = 0,
  request = requestId();
const url = (m, kind = 'preview') =>
  `${apiBase}/trees/${props.treeId}/media/${m.id}/${kind}?revision=${m.revision}${props.person ? '&person=' + props.person.id : ''}`;
async function load(next = 0) {
  const seq = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const r = await api(`/trees/${props.treeId}/media?person=${props.person?.id || ''}&page=${next}`);
    if (seq !== sequence) return;
    items.value = next ? [...items.value, ...r.items] : r.items;
    page.value = r.nextPage;
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
function choose(e) {
  file.value = e.target.files[0] || null;
  title.value = file.value?.name || '';
  request = requestId();
  error.value = '';
}
async function upload() {
  if (!file.value) return;
  uploading.value = true;
  error.value = '';
  try {
    const body = new FormData();
    body.append('file', file.value);
    body.append('title', title.value);
    body.append('visibility', visibility.value);
    body.append('personId', props.person?.id || '');
    body.append('requestId', request);
    const response = await fetch(`${apiBase}/trees/${props.treeId}/media`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { requesttoken: window.OC.requestToken },
      body,
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error('The upload was not received completely.');
    }
    if (!response.ok) {
      if (result.error === 'retry_conflict') request = requestId();
      throw new Error(result.message || 'Media could not be processed.');
    }
    file.value = null;
    fileInput.value.value = '';
    title.value = '';
    request = requestId();
    await load();
    emit('changed');
  } catch (e) {
    error.value = t(e.message);
  } finally {
    uploading.value = false;
  }
}
async function saved() {
  editor.value = null;
  await load();
  emit('changed');
}
function unload(e) {
  if (uploading.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}
watch(
  () => props.person?.id,
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
  window.removeEventListener('beforeunload', unload);
});
</script>
<template>
  <section class="media-panel">
    <h2 v-if="!person">{{ t('Media library') }}</h2>
    <form v-if="canEdit" class="media-upload" @submit.prevent="upload">
      <label
        >{{ t('Choose original')
        }}<input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          :disabled="uploading"
          @change="choose"
      /></label>
      <p class="muted">
        {{
          t(
            'JPEG, PNG or PDF. Up to 10 MiB; images up to 16 million pixels. Originals stay private and unchanged.',
          )
        }}
      </p>
      <template v-if="file"
        ><label
          >{{ t('Title') }}<input v-model="title" maxlength="250" required :disabled="uploading" /></label
        ><label v-if="management"
          >{{ t('Visibility')
          }}<select v-model="visibility" :disabled="uploading" :aria-label="t('Visibility')">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        ><button class="primary" :disabled="uploading">
          {{ t(uploading ? 'Uploading and verifying…' : 'Upload original') }}
        </button></template
      >
    </form>
    <p v-if="error" class="feedback error" role="alert">
      {{ error }} <button v-if="!uploading" @click="load()">{{ t('Reload library') }}</button>
    </p>
    <p v-if="loading" role="status">{{ t('Loading…') }}</p>
    <p v-if="!items.length && !loading && !error" class="muted">{{ t('No media yet') }}</p>
    <div class="media-grid">
      <article v-for="m in items" :key="m.id" class="panel media-card">
        <img v-if="m.mime !== 'application/pdf'" :src="url(m)" :alt="m.title" loading="lazy" /><span
          v-else
          class="pdf-placeholder"
          >PDF</span
        >
        <h3>{{ m.title }}</h3>
        <small>{{ m.originalName }} · {{ (m.size / 1024).toFixed(1) }} KiB</small
        ><span v-if="m.visibility === 'management'" class="badge">{{ t('Management only') }}</span>
        <p v-for="a in m.assignments" class="media-caption">
          {{ personName(a.person, t('Unnamed person')) }} ·
          {{ t(a.purpose === 'portrait' ? 'Portrait' : 'Attachment')
          }}<span v-if="a.preferred"> · {{ t('Preferred') }}</span>
        </p>
        <details>
          <summary>{{ t('Original integrity') }}</summary>
          <small class="file-hash">SHA-256 {{ m.sha256 }}</small>
        </details>
        <div class="button-row">
          <a class="download-original" :href="url(m, 'original')">{{ t('Download original') }}</a
          ><button v-if="canEdit" @click="editor = m">{{ t('Edit media') }}</button>
        </div>
      </article>
    </div>
    <button v-if="page !== null" :disabled="loading" @click="load(page)">{{ t('Load more') }}</button
    ><MediaEditor
      v-if="editor"
      :record="editor"
      :tree-id="treeId"
      :management="management"
      @close="editor = null"
      @saved="saved"
    />
  </section>
</template>

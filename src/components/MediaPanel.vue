<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue';
import { useDraftGuard } from '../draftGuard.js';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import { requestId, personName } from '../domain.js';
import MediaEditor from './MediaEditor.vue';
import MediaPreview from './MediaPreview.vue';
import MediaViewer from './MediaViewer.vue';
const props = defineProps({ treeId: String, person: Object, canEdit: Boolean, management: Boolean }),
  emit = defineEmits(['changed']);
const items = ref([]),
  page = ref(null),
  loading = ref(false),
  error = ref(''),
  editor = ref(null),
  viewer = ref(null),
  file = ref(null),
  cloudFile = ref(null),
  selecting = ref(false),
  fileInput = ref(null),
  uploading = ref(false),
  title = ref(''),
  visibility = ref('members');
useDraftGuard(
  computed(() => file.value !== null || cloudFile.value !== null),
  uploading,
);
let sequence = 0,
  request = requestId();
const url = (m, kind = 'preview') =>
  `${apiBase}/trees/${props.treeId}/media/${m.id}/${kind}?revision=${m.revision}${props.person ? '&person=' + props.person.id : ''}`;
function unavailable(id) {
  items.value = items.value.filter((item) => item.id !== id);
}
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
    if (seq === sequence) {
      error.value = t(e.message);
      if ([401, 403, 404].includes(e.status)) {
        items.value = [];
        viewer.value = null;
      }
    }
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
function choose(e) {
  cloudFile.value = null;
  file.value = e.target.files[0] || null;
  title.value = file.value?.name || '';
  request = requestId();
  error.value = '';
}
function chooseCloud() {
  error.value = '';
  if (!window.OC?.dialogs?.filepicker) {
    error.value = t('The Nextcloud file picker is unavailable. Reload the page and try again.');
    return;
  }
  window.OC.dialogs.filepicker(
    t('Choose from Nextcloud Files'),
    async (path) => {
      if (!path) return;
      selecting.value = true;
      try {
        const selected = await api(`/trees/${props.treeId}/media/select-file`, {
          method: 'POST',
          body: { path },
        });
        cloudFile.value = selected;
        file.value = null;
        fileInput.value.value = '';
        title.value = selected.name;
        request = requestId();
      } catch (e) {
        error.value = t(e.message);
      } finally {
        selecting.value = false;
      }
    },
    false,
    ['image/jpeg', 'image/png', 'application/pdf'],
    true,
  );
}
async function importCloud() {
  uploading.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/media/import-file`, {
      method: 'POST',
      body: {
        fileId: cloudFile.value.id,
        etag: cloudFile.value.etag,
        requestId: request,
        title: title.value,
        personId: props.person?.id || '',
        visibility: visibility.value,
      },
    });
    cloudFile.value = null;
    title.value = '';
    request = requestId();
    await load();
    emit('changed');
  } catch (e) {
    if (e.code === 'retry_conflict') request = requestId();
    error.value = t(e.message);
  } finally {
    uploading.value = false;
  }
}
async function upload() {
  if (cloudFile.value) return importCloud();
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
      <button type="button" :disabled="uploading || selecting" @click="chooseCloud">
        {{ t('Choose from Nextcloud Files') }}
      </button>
      <p v-if="selecting" role="status">{{ t('Loading…') }}</p>
      <p class="muted">
        {{
          t(
            'JPEG, PNG or PDF. Up to 10 MiB; images up to 16 million pixels. Originals stay private and unchanged.',
          )
        }}
      </p>
      <template v-if="file || cloudFile"
        ><label
          >{{ t('Title') }}<input v-model="title" maxlength="250" required :disabled="uploading" /></label
        ><label v-if="management"
          >{{ t('Visibility')
          }}<select v-model="visibility" :disabled="uploading" :aria-label="t('Visibility')">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        >
        <p v-if="cloudFile" class="muted">
          {{ cloudFile.name }} · {{ (cloudFile.size / 1024).toFixed(1) }} KiB · {{ cloudFile.mime }} <br />{{
            t(
              'A private copy will be stored in this tree and count toward its installation quota. Later changes, deletion or sharing changes in Files will not affect this copy.',
            )
          }}
          <br />{{ t(visibility === 'management' ? 'Management only' : 'All tree members') }}
        </p>
        <button
          v-if="cloudFile"
          type="button"
          :disabled="uploading"
          @click="
            cloudFile = null;
            title = '';
          "
        >
          {{ t('Cancel') }}</button
        ><button class="primary" :disabled="uploading">
          {{
            t(uploading ? 'Uploading and verifying…' : cloudFile ? 'Import private copy' : 'Upload original')
          }}
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
        <MediaPreview :url="url(m) + '&size=320'" :label="m.title" lazy @unavailable="unavailable(m.id)" />
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
          <button @click="viewer = m">{{ t('Open preview') }}</button>
          <a class="download-original" :href="url(m, 'original')">{{ t('Download original') }}</a
          ><button v-if="canEdit" @click="editor = m">{{ t('Edit media') }}</button>
        </div>
      </article>
    </div>
    <button v-if="page !== null" :disabled="loading" @click="load(page)">{{ t('Load more') }}</button
    ><MediaViewer
      v-if="viewer"
      :tree-id="treeId"
      :record="viewer"
      :person-id="person?.id || ''"
      @close="viewer = null"
      @unavailable="unavailable(viewer.id)"
    /><MediaEditor
      v-if="editor"
      :record="editor"
      :tree-id="treeId"
      :management="management"
      @close="editor = null"
      @saved="saved"
    />
  </section>
</template>

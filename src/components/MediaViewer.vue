<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import MediaPreview from './MediaPreview.vue';
import MediaDetails from './MediaDetails.vue';
const props = defineProps({ treeId: String, record: Object, personId: String, canEdit: Boolean });
const emit = defineEmits(['close', 'unavailable', 'edit', 'person', 'event']);
const dialog = ref(null),
  page = ref(1),
  pages = ref(1),
  zoom = ref(100),
  ready = ref(false),
  error = ref('');
const base = `${apiBase}/trees/${props.treeId}/media/${props.record.id}`;
const url = computed(
  () =>
    `${base}/preview?size=1600&page=${page.value}${props.personId ? '&person=' + encodeURIComponent(props.personId) : ''}`,
);
let opener,
  live = true,
  timer;
function close() {
  emit('close');
}
function escape(event) {
  if (event.key === 'Escape' && dialog.value?.open) {
    event.preventDefault();
    event.stopPropagation();
    close();
  }
}
function unavailable() {
  emit('unavailable');
  close();
}
async function info() {
  error.value = '';
  ready.value = false;
  try {
    const meta = await api(`/trees/${props.treeId}/media/${props.record.id}/preview-info`);
    if (!live) return;
    pages.value = meta.pages;
    ready.value = true;
  } catch (e) {
    if (!live) return;
    if ([401, 403, 404].includes(e.status)) {
      unavailable();
      return;
    }
    error.value = t(e.message);
  }
}
async function authorize() {
  try {
    await api(`/trees/${props.treeId}/media/${props.record.id}`);
  } catch (e) {
    if (live && [401, 403, 404].includes(e.status)) unavailable();
  }
}
onMounted(() => {
  opener = document.activeElement;
  dialog.value.showModal();
  window.addEventListener('keydown', escape, true);
  info();
  timer = setInterval(authorize, 30000);
  window.addEventListener('focus', authorize);
});
onBeforeUnmount(() => {
  live = false;
  clearInterval(timer);
  window.removeEventListener('focus', authorize);
  window.removeEventListener('keydown', escape, true);
  dialog.value?.close();
  if (opener?.isConnected) opener.focus();
});
</script>
<template>
  <dialog
    ref="dialog"
    class="stb-dialog media-viewer"
    aria-labelledby="media-viewer-title"
    @cancel.prevent="close"
  >
    <header>
      <div>
        <h2 id="media-viewer-title">{{ record.title }}</h2>
        <p class="muted">{{ record.originalName }}</p>
      </div>
      <button type="button" @click="close">{{ t('Close viewer') }}</button>
    </header>
    <div class="viewer-toolbar" role="group" :aria-label="t('Preview controls')">
      <div v-if="record.mime === 'application/pdf' && ready" class="viewer-pages">
        <button :disabled="page === 1" @click="page--">{{ t('Previous page') }}</button>
        <span role="status">{{ t('Page') }} {{ page }} / {{ pages }}</span>
        <button :disabled="page === pages" @click="page++">{{ t('Next page') }}</button>
      </div>
      <label v-if="ready"
        >{{ t('Zoom')
        }}<select v-model.number="zoom" :aria-label="t('Zoom')">
          <option :value="100">{{ t('Fit width') }}</option>
          <option :value="150">150%</option>
          <option :value="200">200%</option>
        </select></label
      >
      <a class="download-original" :href="base + '/original'">{{ t('Download original') }}</a>
    </div>
    <div class="viewer-content">
      <div class="viewer-stage" tabindex="0" :aria-label="t('Media preview')">
        <MediaPreview
          v-if="ready"
          :key="page"
          :url="url"
          :label="record.mime === 'application/pdf' ? `${t('Page')} ${page} / ${pages}` : record.title"
          :style="{ width: zoom + '%' }"
          @unavailable="unavailable"
        />
        <div v-else-if="error" class="preview-fallback">
          <p role="alert">{{ error }}</p>
          <button @click="info">{{ t('Retry preview') }}</button>
        </div>
        <p v-else role="status">{{ t('Preparing preview…') }}</p>
      </div>
      <aside class="viewer-details">
        <MediaDetails
          :metadata="record.metadata"
          @person="emit('person', $event)"
          @event="emit('event', $event)"
        /><button v-if="canEdit" @click="emit('edit')">{{ t('Edit details') }}</button>
      </aside>
    </div>
    <p v-if="record.mime === 'application/pdf'" class="muted viewer-note">
      {{
        t(
          'Image-based preview. Download the original for selectable text and document accessibility features.',
        )
      }}
    </p>
  </dialog>
</template>

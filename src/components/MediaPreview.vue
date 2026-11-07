<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { t } from '../i18n.js';
const props = defineProps({ url: String, label: String, lazy: Boolean });
const emit = defineEmits(['unavailable']);
const root = ref(null),
  image = ref(''),
  loading = ref(false),
  error = ref('');
let observer,
  timer,
  controller,
  generation = 0,
  visible = !props.lazy;
function clear() {
  if (image.value) URL.revokeObjectURL(image.value);
  image.value = '';
}
async function load() {
  const current = ++generation;
  controller?.abort();
  if (!visible || document.hidden) {
    clear();
    return;
  }
  controller = new AbortController();
  const signal = controller.signal;
  loading.value = true;
  error.value = '';
  try {
    let response;
    for (let attempt = 0; attempt < 3; attempt++) {
      response = await fetch(props.url, { credentials: 'same-origin', cache: 'no-store', signal });
      if (response.status !== 503 || attempt === 2) break;
      await new Promise((resolve) => setTimeout(resolve, 350 * (attempt + 1)));
      if (signal.aborted) return;
    }
    if (!response.ok) {
      if ([401, 403, 404].includes(response.status)) emit('unavailable');
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || 'Preview unavailable. You can still download the original.');
    }
    const blob = await response.blob();
    if (current !== generation) return;
    clear();
    image.value = URL.createObjectURL(blob);
  } catch (e) {
    if (current !== generation || e.name === 'AbortError') return;
    clear();
    error.value = t(
      e.message === 'Failed to fetch' ? 'The connection was interrupted. Please try again.' : e.message,
    );
  } finally {
    if (current === generation) loading.value = false;
  }
}
function changed() {
  clear();
  load();
}
watch(() => props.url, changed);
onMounted(() => {
  if (props.lazy && 'IntersectionObserver' in window) {
    observer = new IntersectionObserver(
      ([entry]) => {
        visible = entry.isIntersecting;
        if (visible) load();
        else {
          generation++;
          controller?.abort();
          clear();
        }
      },
      { rootMargin: '100px' },
    );
    observer.observe(root.value);
  } else {
    visible = true;
    load();
  }
  timer = setInterval(() => {
    if (visible && !document.hidden) load();
  }, 30000);
  window.addEventListener('focus', changed);
  document.addEventListener('visibilitychange', changed);
});
onBeforeUnmount(() => {
  generation++;
  controller?.abort();
  observer?.disconnect();
  clearInterval(timer);
  clear();
  window.removeEventListener('focus', changed);
  document.removeEventListener('visibilitychange', changed);
});
</script>
<template>
  <div ref="root" class="media-preview" :aria-busy="loading">
    <img
      v-if="image"
      :src="image"
      :alt="label"
      @error="
        clear();
        error = t('Preview unavailable. You can still download the original.');
      "
    />
    <p v-else-if="loading" class="muted" role="status">{{ t('Preparing preview…') }}</p>
    <div v-else-if="error" class="preview-fallback">
      <span aria-hidden="true" class="document-symbol">▤</span>
      <p role="status">{{ error }}</p>
      <button type="button" @click="load">{{ t('Retry preview') }}</button>
    </div>
    <span v-else class="document-symbol" aria-hidden="true">▤</span>
  </div>
</template>

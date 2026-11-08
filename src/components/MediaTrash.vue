<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { t } from '../i18n.js';
const props = defineProps({ treeId: String });
const emit = defineEmits(['changed']);
const items = ref([]),
  next = ref(null),
  busy = ref(false),
  error = ref('');
let sequence = 0,
  requested = 0;
async function load(page = 0) {
  const seq = ++sequence;
  requested = page;
  busy.value = true;
  error.value = '';
  try {
    const result = await api(`/trees/${props.treeId}/media-trash?page=${page}`);
    if (seq !== sequence) return;
    items.value = page ? [...items.value, ...result.items] : result.items;
    next.value = result.nextPage;
  } catch (e) {
    if (seq === sequence) {
      items.value = [];
      next.value = null;
      error.value = t(e.message);
    }
  } finally {
    if (seq === sequence) busy.value = false;
  }
}
async function restore(item) {
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/media/${item.id}/restore`, {
      method: 'POST',
      body: { revision: item.revision },
    });
    emit('changed');
    await load();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
onMounted(() => load());
onBeforeUnmount(() => sequence++);
</script>
<template>
  <section class="panel">
    <h3>{{ t('Media trash') }}</h3>
    <p>
      {{
        t(
          'Trashed originals are retained for recovery and still count toward storage. Restoration does not select a preferred portrait.',
        )
      }}
    </p>
    <p v-if="error" class="feedback error" role="alert">
      {{ error }} <button :disabled="busy" @click="load(requested)">{{ t('Retry') }}</button>
    </p>
    <p v-if="!busy && !error && !items.length">{{ t('Media trash is empty') }}</p>
    <ul>
      <li v-for="item in items" :key="item.id">
        {{ item.title }} · {{ (item.size / 1024).toFixed(1) }} KiB
        <button :disabled="busy" @click="restore(item)">{{ t('Restore medium') }}</button>
      </li>
    </ul>
    <button v-if="next !== null" :disabled="busy" @click="load(next)">{{ t('Load more') }}</button>
  </section>
</template>

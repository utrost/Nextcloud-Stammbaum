<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { t } from '../i18n.js';
import { personName } from '../domain.js';
const props = defineProps({ treeId: String, kind: String });
const emit = defineEmits(['select']);
const query = ref(''),
  items = ref([]),
  next = ref(null),
  busy = ref(false),
  error = ref('');
let sequence = 0,
  requested = 0,
  applied = '';
async function search(page = 0, retry = false) {
  if (!retry && page === 0) applied = query.value;
  requested = page;
  const seq = ++sequence;
  busy.value = true;
  error.value = '';
  try {
    const result = await api(
      `/trees/${props.treeId}/${props.kind === 'event' ? 'media-events' : 'people'}?query=${encodeURIComponent(applied)}&page=${page}`,
    );
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
onBeforeUnmount(() => sequence++);
</script>
<template>
  <div class="media-link-picker">
    <label
      >{{ t(kind === 'event' ? 'Find event' : 'Find tree person')
      }}<input v-model="query" type="search" maxlength="200" @keydown.enter.prevent="search()"
    /></label>
    <button type="button" :disabled="busy" @click="search()">{{ t('Search') }}</button>
    <p v-if="busy" role="status">{{ t('Loading…') }}</p>
    <p v-if="error" role="alert">
      {{ error }} <button type="button" @click="search(requested, true)">{{ t('Retry') }}</button>
    </p>
    <div class="picker-results">
      <button v-for="item in items" :key="item.id" type="button" @click="emit('select', item)">
        {{ kind === 'event' ? item.title : personName(item, t('Unnamed person')) }}
      </button>
    </div>
    <button v-if="next !== null" type="button" :disabled="busy" @click="search(next)">
      {{ t('Load more') }}
    </button>
  </div>
</template>

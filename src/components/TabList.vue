<script setup>
import { ref, watch } from 'vue';
import { t, title } from '../i18n.js';
const props = defineProps({ id: String, label: String, items: Array, modelValue: String });
const emit = defineEmits(['select']);
const focused = ref(props.modelValue);
watch(
  () => props.modelValue,
  (value) => {
    focused.value = value;
  },
);
watch(
  () => props.items,
  (items) => {
    if (!items.includes(focused.value)) focused.value = props.modelValue;
  },
);
function key(event) {
  const index = props.items.indexOf(focused.value);
  const next = {
    ArrowRight: (index + 1) % props.items.length,
    ArrowLeft: (index + props.items.length - 1) % props.items.length,
    Home: 0,
    End: props.items.length - 1,
  }[event.key];
  if (next === undefined) return;
  event.preventDefault();
  focused.value = props.items[next];
  event.currentTarget.querySelectorAll('[role=tab]')[next].focus();
}
</script>
<template>
  <div class="page-tabs" role="tablist" :aria-label="label" @keydown="key">
    <button
      v-for="item in items"
      :key="item"
      :id="`${id}-tab-${item}`"
      role="tab"
      :aria-selected="modelValue === item"
      :aria-controls="`${id}-panel`"
      :tabindex="focused === item ? 0 : -1"
      :class="{ active: modelValue === item }"
      @focus="focused = item"
      @click="emit('select', item)"
    >
      {{ t(title(item)) }}
    </button>
  </div>
</template>

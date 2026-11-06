<script setup>
import { t } from '../i18n.js';
defineProps({ modelValue: Object, label: String, prefix: String });
const emit = defineEmits(['update:modelValue']);
const kinds = [
  ['exact', 'Exact / partial'],
  ['about', 'About'],
  ['before', 'Before'],
  ['after', 'After'],
  ['between', 'Between'],
  ['period', 'From / to'],
  ['calculated', 'Calculated'],
  ['estimated', 'Estimated'],
  ['literal', 'Literal wording'],
];
function set(value, key, input) {
  emit('update:modelValue', {
    ...value,
    [key]: input,
    ...(key === 'kind' && !['between', 'period'].includes(input) ? { end: '' } : {}),
  });
}
</script>
<template>
  <fieldset class="date-field">
    <legend>{{ label }}</legend>
    <div class="date-row">
      <label :for="prefix + '-kind'"
        >{{ t('Date form')
        }}<select
          :id="prefix + '-kind'"
          :value="modelValue.kind"
          @change="set(modelValue, 'kind', $event.target.value)"
        >
          <option v-for="[key, label] in kinds" :key="key" :value="key">{{ t(label) }}</option>
        </select></label
      >
      <label :for="prefix + '-value'"
        >{{ t('Date or wording')
        }}<input
          :id="prefix + '-value'"
          :value="modelValue.value"
          maxlength="250"
          :placeholder="modelValue.kind === 'literal' ? '03/04' : '1890-03-12'"
          @input="set(modelValue, 'value', $event.target.value)"
      /></label>
      <label v-if="['between', 'period'].includes(modelValue.kind)" :for="prefix + '-end'"
        >{{ t('End')
        }}<input
          :id="prefix + '-end'"
          :value="modelValue.end"
          maxlength="10"
          placeholder="1900"
          @input="set(modelValue, 'end', $event.target.value)"
      /></label>
    </div>
    <small>{{
      t(
        modelValue.kind === 'literal'
          ? 'Keep uncertain wording exactly as recorded.'
          : 'Use YYYY, YYYY-MM or YYYY-MM-DD. Leave unknown parts empty.',
      )
    }}</small>
  </fieldset>
</template>

<script setup>
import { t } from '../i18n.js';
import { dateText, personName } from '../domain.js';
defineProps({ metadata: Object });
const emit = defineEmits(['person', 'event']);
</script>
<template>
  <section class="media-description" :aria-label="t('Descriptive details')">
    <h3>{{ t('Descriptive details') }}</h3>
    <p v-if="metadata.description" class="preserve-text">{{ metadata.description }}</p>
    <dl>
      <dt>{{ t('Subject date or year') }}</dt>
      <dd>{{ dateText(metadata.date) || t('Not recorded') }}</dd>
    </dl>
    <template v-for="kind in ['shown', 'mentioned']" :key="kind">
      <h4 v-if="metadata.people.some((p) => p.association === kind)">
        {{ t(kind === 'shown' ? 'Shown' : 'Mentioned') }}
      </h4>
      <ul v-if="metadata.people.some((p) => p.association === kind)">
        <li v-for="(p, i) in metadata.people.filter((p) => p.association === kind)" :key="i">
          <button v-if="p.personId" class="text-link" @click="emit('person', p.personId)">
            {{ personName(p.person, t('Unnamed person')) }}
          </button>
          <span v-else>{{ p.name || t('Unidentified person') }}</span>
          <small v-if="p.personId && p.name && p.name !== personName(p.person)"> · {{ p.name }}</small>
          <small v-if="p.page"> · {{ t('Page reference') }} {{ p.page }}</small>
        </li>
      </ul>
    </template>
    <dl>
      <template v-if="metadata.event || metadata.eventId"
        ><dt>{{ t('Event') }}</dt>
        <dd>
          <button v-if="metadata.eventId" class="text-link" @click="emit('event', metadata.linkedEvent)">
            {{ metadata.linkedEvent.title }}</button
          ><span v-if="!metadata.eventId || metadata.event !== metadata.linkedEvent?.title">{{
            metadata.event
          }}</span>
        </dd></template
      >
      <dt>{{ t('Historical place') }}</dt>
      <dd>{{ metadata.place || t('Not recorded') }}</dd>
      <template v-if="metadata.source"
        ><dt>{{ t('Source description') }}</dt>
        <dd class="preserve-text">{{ metadata.source }}</dd></template
      >
      <template v-if="metadata.credit"
        ><dt>{{ t('Credit or rights') }}</dt>
        <dd>{{ metadata.credit }}</dd></template
      >
    </dl>
  </section>
</template>

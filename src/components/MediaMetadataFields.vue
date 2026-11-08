<script setup>
import { ref } from 'vue';
import { t } from '../i18n.js';
import { personName } from '../domain.js';
import DateField from './DateField.vue';
import MediaLinkPicker from './MediaLinkPicker.vue';
const props = defineProps({ metadata: Object, treeId: String, mime: String });
const linking = ref(null),
  eventPicker = ref(false);
function link(p, person) {
  p.personId = person.id;
  p.person = person;
  if (!p.name) p.name = personName(person, t('Unnamed person'));
  linking.value = null;
}
function event(record) {
  props.metadata.eventId = record.id;
  props.metadata.linkedEvent = record;
  if (!props.metadata.event) props.metadata.event = record.title;
  eventPicker.value = false;
}
</script>
<template>
  <section class="media-metadata-fields">
    <h3>{{ t('Description') }}</h3>
    <label>{{ t('Description') }}<textarea v-model="metadata.description" maxlength="4000" rows="3" /></label>
    <DateField v-model="metadata.date" :label="t('Subject date or year')" prefix="media-date" />
    <p class="muted">{{ t('Describe when the subject occurred, not when the file was uploaded.') }}</p>
    <h3>{{ t('People and event') }}</h3>
    <h4>{{ t('People shown or mentioned') }}</h4>
    <p class="muted">
      {{ t('Names do not need a tree record. Leave the name empty for an unidentified person.') }}
    </p>
    <section v-for="(p, i) in metadata.people" :key="i" class="named-person">
      <label>{{ t('Recorded name') }}<input v-model="p.name" maxlength="250" /></label>
      <div class="form-grid">
        <label
          >{{ t('Association')
          }}<select v-model="p.association" :aria-label="t('Association')">
            <option value="shown">{{ t('Shown') }}</option>
            <option value="mentioned">{{ t('Mentioned') }}</option>
          </select></label
        >
        <label v-if="mime === 'application/pdf'"
          >{{ t('Page reference') }}<input v-model="p.page" maxlength="100"
        /></label>
      </div>
      <p v-if="p.personId">{{ t('Linked person') }}: {{ personName(p.person, t('Unnamed person')) }}</p>
      <div class="button-row">
        <button
          v-if="p.personId"
          type="button"
          @click="
            p.personId = '';
            delete p.person;
          "
        >
          {{ t('Unlink person') }}</button
        ><button v-else type="button" @click="linking = linking === i ? null : i">
          {{ t('Link to tree person') }}</button
        ><button
          type="button"
          @click="
            metadata.people.splice(i, 1);
            linking = null;
          "
        >
          {{ t('Remove named person') }}
        </button>
      </div>
      <MediaLinkPicker v-if="linking === i" :tree-id="treeId" kind="person" @select="link(p, $event)" />
    </section>
    <button
      type="button"
      :disabled="metadata.people.length >= 32"
      @click="
        metadata.people.push({
          name: '',
          personId: '',
          association: mime === 'application/pdf' ? 'mentioned' : 'shown',
          page: '',
        })
      "
    >
      {{ t('Add named person') }}
    </button>
    <label>{{ t('Event description') }}<input v-model="metadata.event" maxlength="250" /></label>
    <p v-if="metadata.eventId">{{ t('Linked event') }}: {{ metadata.linkedEvent?.title }}</p>
    <button
      v-if="metadata.eventId"
      type="button"
      @click="
        metadata.eventId = '';
        delete metadata.linkedEvent;
      "
    >
      {{ t('Unlink event') }}
    </button>
    <button v-else type="button" @click="eventPicker = !eventPicker">{{ t('Link existing event') }}</button>
    <MediaLinkPicker v-if="eventPicker" :tree-id="treeId" kind="event" @select="event" />
    <label>{{ t('Historical place') }}<input v-model="metadata.place" maxlength="500" /></label>
    <label
      >{{ t('Source description') }}<textarea v-model="metadata.source" maxlength="2000" rows="2" />
    </label>
    <label>{{ t('Credit or rights') }}<input v-model="metadata.credit" maxlength="500" /></label>
    <p class="muted">
      {{ t('Names and places follow this medium’s visibility. Linked records may restrict access further.') }}
    </p>
  </section>
</template>

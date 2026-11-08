<script setup>
import { computed } from 'vue';
import { t } from '../i18n.js';
import { dateText, personName } from '../domain.js';
const props = defineProps({ draft: Object, current: Object });
function fields(value) {
  const m = value.metadata;
  return {
    Title: value.title,
    Visibility: t(value.visibility === 'management' ? 'Management only' : 'All tree members'),
    Description: m.description,
    'Subject date or year': dateText(m.date),
    'People shown or mentioned': m.people
      .map((p) =>
        [
          p.name || t('Unidentified person'),
          t(p.association === 'shown' ? 'Shown' : 'Mentioned'),
          p.personId ? t('Linked person') + ': ' + personName(p.person, t('Unnamed person')) : '',
          p.page,
        ]
          .filter(Boolean)
          .join(' · '),
      )
      .join('\n'),
    Event: [m.event, m.linkedEvent?.title].filter(Boolean).join(' · '),
    'Historical place': m.place,
    'Source description': m.source,
    'Credit or rights': m.credit,
    'Portraits and attachments': value.assignments
      .map((a) =>
        [
          personName(a.person, t('Unnamed person')),
          t(a.purpose === 'portrait' ? 'Portrait' : 'Attachment'),
          a.preferred ? t('Preferred portrait') : '',
          ...[
            ['x', 'Left'],
            ['y', 'Top'],
            ['width', 'Width'],
            ['height', 'Height'],
          ].map(([key, label]) => `${t(label)}: ${Math.round(a.crop[key] * 10000) / 100}%`),
        ]
          .filter(Boolean)
          .join(' · '),
      )
      .join('\n'),
  };
}
const rows = computed(() => {
  const mine = fields(props.draft),
    saved = fields(props.current);
  return Object.keys(mine).map((label) => ({
    label,
    mine: mine[label],
    saved: saved[label],
    changed: mine[label] !== saved[label],
  }));
});
</script>
<template>
  <div class="media-conflict">
    <section v-for="row in rows" :key="row.label" :class="{ 'conflict-changed': row.changed }">
      <h4>
        {{ t(row.label) }} <span v-if="row.changed" class="badge">{{ t('Different') }}</span>
      </h4>
      <dl>
        <dt>{{ t('Your changes') }}</dt>
        <dd class="preserve-text">{{ row.mine || t('Not recorded') }}</dd>
        <dt>{{ t('Saved version') }}</dt>
        <dd class="preserve-text">{{ row.saved || t('Not recorded') }}</dd>
      </dl>
    </section>
  </div>
</template>

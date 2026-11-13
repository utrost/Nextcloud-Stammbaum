<script setup>
import { computed, ref, watch } from 'vue';
import { api, userId } from '../api.js';
import { requestId, personName } from '../domain.js';
import { t } from '../i18n.js';
import { captureCreatedEtag } from '../integrations.js';

const props = defineProps({ treeId: String, person: Object, event: Object, canExport: Boolean });
const origin = computed(() => props.event || props.person);
const originType = computed(() => (props.event ? 'event' : 'person'));
const originLabel = computed(() => props.event?.title || personName(props.person, t('Unnamed person')));
const originPeople = computed(() =>
  props.event ? props.event.participants.map((p) => p.person) : [props.person],
);
const livingWarning = computed(() => originPeople.value.some((p) => p?.livingStatus !== 'deceased'));
const managementWarning = computed(
  () =>
    origin.value?.visibility === 'management' ||
    originPeople.value.some((p) => p?.visibility === 'management'),
);
const selectedAudience = computed(
  () => collections.value.find((item) => item.uri === collection.value)?.audience || 'unknown',
);
const open = ref(false),
  busy = ref(false),
  error = ref(''),
  collections = ref([]),
  readOnlyCount = ref(0),
  nextCollectionPage = ref(null),
  collection = ref(''),
  mode = ref('appointment'),
  anniversaryKind = ref('birth'),
  title = ref(''),
  date = ref(''),
  time = ref(''),
  timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Berlin'),
  location = ref(''),
  includeLiving = ref(false),
  includeManagement = ref(false),
  review = ref(null),
  acknowledged = ref(false),
  acknowledgedDuplicate = ref(false),
  links = ref([]);
const calendarUrl = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/index.php/apps/calendar/`;
const davRoot = () =>
  `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/remote.php/dav/calendars/${encodeURIComponent(userId)}`;
let key = requestId(),
  sequence = 0,
  reviewSequence = 0;

async function discoverEventCalendars(items) {
  const response = await fetch(`${davRoot()}/`, {
    method: 'PROPFIND',
    credentials: 'same-origin',
    headers: {
      Depth: '1',
      'Content-Type': 'application/xml; charset=utf-8',
      requesttoken: window.OC?.requestToken || document.getElementById('stammbaum-app')?.dataset.requestToken,
    },
    body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:supported-calendar-component-set/></d:prop></d:propfind>',
  });
  if (response.status !== 207) throw new Error('Calendars could not be loaded. Try again.');
  const xml = new DOMParser().parseFromString(await response.text(), 'application/xml');
  if (xml.querySelector('parsererror')) throw new Error('Calendars could not be loaded. Try again.');
  const available = new Set();
  for (const node of xml.getElementsByTagNameNS('DAV:', 'response')) {
    const href = node.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || '';
    const path = new URL(href, window.location.origin).pathname;
    const uri = decodeURIComponent(path.replace(/\/$/, '').split('/').pop() || '');
    const components = [...node.getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'comp')];
    if (components.some((component) => component.getAttribute('name') === 'VEVENT')) available.add(uri);
  }
  return items.filter((item) => available.has(item.uri));
}

async function loadLinks() {
  const treeId = props.treeId,
    sourceId = origin.value.id;
  const result = await api(
    `/trees/${treeId}/calendar?sourceType=${originType.value}&sourceId=${encodeURIComponent(sourceId)}`,
  );
  if (treeId === props.treeId && sourceId === origin.value?.id) links.value = result.items;
}

async function toggle() {
  open.value = !open.value;
  if (!open.value) {
    sequence++;
    reviewSequence++;
    busy.value = false;
    return;
  }
  const seq = ++sequence;
  busy.value = true;
  error.value = '';
  title.value ||= `${t('Family appointment')}: ${originLabel.value}`;
  try {
    const result = await api('/integrations/calendar/collections?page=0');
    if (!result.available) throw new Error('Nextcloud Calendar is not available for this account.');
    if (seq !== sequence) return;
    const detected = await discoverEventCalendars([...result.items, ...(result.readOnlyItems || [])]);
    if (seq !== sequence) return;
    collections.value = detected.filter((item) => item.state !== 'read-only');
    readOnlyCount.value = detected.length - collections.value.length;
    nextCollectionPage.value = result.nextPage;
    if (!collections.value.some((c) => c.uri === collection.value))
      collection.value = collections.value[0]?.uri || '';
    await loadLinks();
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function loadMoreCollections() {
  const next = nextCollectionPage.value;
  if (next === null || busy.value) return;
  const seq = sequence;
  busy.value = true;
  error.value = '';
  try {
    const result = await api(`/integrations/calendar/collections?page=${next}`);
    if (!result.available) throw new Error('Nextcloud Calendar is not available for this account.');
    const detected = await discoverEventCalendars([...result.items, ...(result.readOnlyItems || [])]);
    if (seq !== sequence) return;
    const seen = new Set(collections.value.map((item) => item.uri));
    collections.value = [
      ...collections.value,
      ...detected.filter((item) => item.state !== 'read-only' && !seen.has(item.uri)),
    ];
    readOnlyCount.value += detected.filter((item) => item.state === 'read-only').length;
    nextCollectionPage.value = result.nextPage;
    if (!collection.value) collection.value = collections.value[0]?.uri || '';
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

watch(
  () => [props.treeId, origin.value?.id, originType.value],
  () => {
    sequence++;
    reviewSequence++;
    open.value = false;
    busy.value = false;
    collections.value = [];
    readOnlyCount.value = 0;
    nextCollectionPage.value = null;
    review.value = null;
    links.value = [];
    mode.value = 'appointment';
    anniversaryKind.value = 'birth';
    title.value = '';
    date.value = '';
    time.value = '';
    location.value = '';
    includeLiving.value = false;
    includeManagement.value = false;
    acknowledged.value = false;
    acknowledgedDuplicate.value = false;
    key = requestId();
  },
);

watch([mode, anniversaryKind], () => {
  if (mode.value === 'anniversary' && !props.event) {
    title.value = `${t(anniversaryKind.value === 'birth' ? 'Birthday of' : 'Remembrance of')} ${personName(props.person, t('Unnamed person'))}`;
  }
});
watch(
  [
    title,
    date,
    time,
    timezone,
    location,
    collection,
    mode,
    anniversaryKind,
    includeLiving,
    includeManagement,
  ],
  () => {
    reviewSequence++;
    review.value = null;
    acknowledged.value = false;
    acknowledgedDuplicate.value = false;
    key = requestId();
  },
);

async function preview() {
  const seq = ++reviewSequence,
    treeId = props.treeId,
    sourceId = origin.value.id;
  busy.value = true;
  error.value = '';
  review.value = null;
  try {
    const result = await api(`/trees/${treeId}/calendar/review`, {
      method: 'POST',
      body: {
        sourceType: originType.value,
        sourceId,
        collection: collection.value,
        mode: mode.value,
        anniversaryKind: anniversaryKind.value,
        title: title.value,
        date: date.value,
        time: time.value,
        timezone: timezone.value,
        location: location.value,
        includeLiving: includeLiving.value,
        includeManagement: includeManagement.value,
      },
    });
    if (seq === reviewSequence && treeId === props.treeId && sourceId === origin.value?.id)
      review.value = result;
  } catch (e) {
    if (seq === reviewSequence) error.value = t(e.message);
  } finally {
    if (seq === reviewSequence) busy.value = false;
  }
}

async function create() {
  if (!review.value) return;
  const seq = sequence,
    treeId = props.treeId,
    sourceId = origin.value.id;
  busy.value = true;
  error.value = '';
  try {
    const created = await api(`/trees/${treeId}/calendar`, {
      method: 'POST',
      body: {
        review: review.value,
        acknowledged: acknowledged.value,
        acknowledgedDuplicate: acknowledgedDuplicate.value,
        requestId: key,
      },
    });
    if (created.status === 'linked')
      await captureCreatedEtag(treeId, 'calendar', created.id, review.value.collection);
    if (seq !== sequence || treeId !== props.treeId || sourceId !== origin.value?.id) return;
    review.value = null;
    acknowledged.value = false;
    acknowledgedDuplicate.value = false;
    key = requestId();
    await loadLinks();
  } catch (e) {
    if (seq === sequence && treeId === props.treeId && sourceId === origin.value?.id) {
      error.value = t(e.message);
      if (e.status === 412) review.value = null;
    }
  } finally {
    if (seq === sequence && treeId === props.treeId && sourceId === origin.value?.id) busy.value = false;
  }
}

async function refresh() {
  busy.value = true;
  error.value = '';
  try {
    await loadLinks();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}

async function unlink(id) {
  if (!confirm(t('Unlink this event? The Calendar entry remains.'))) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/calendar/${id}`, { method: 'DELETE' });
    await loadLinks();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div v-if="canExport" class="calendar-appointments">
    <button type="button" :aria-expanded="open" @click="toggle">{{ t('Add to Nextcloud Calendar') }}</button>
    <section v-if="open" class="task-review" :aria-label="t('Calendar event')">
      <p>
        {{
          t(
            'Calendar owns the event. Its sharing permissions and synchronized copies are separate from this tree.',
          )
        }}
      </p>
      <p v-if="busy" role="status">{{ t('Working…') }}</p>
      <p v-if="error" role="alert" class="feedback error">{{ error }}</p>
      <p v-if="!busy && !collections.length">{{ t('No writable calendar is available.') }}</p>
      <p v-if="readOnlyCount" class="muted">{{ t('Read-only calendars are unavailable for creation.') }}</p>
      <form v-if="collections.length && !review" @submit.prevent="preview">
        <fieldset :disabled="busy">
          <label
            >{{ t('Calendar')
            }}<select v-model="collection" required>
              <option v-for="item in collections" :key="item.uri" :value="item.uri">{{ item.name }}</option>
            </select></label
          >
          <p class="muted">
            {{
              t(
                selectedAudience === 'shared-with-me'
                  ? 'This calendar is shared with your account. Other readers may see copied details.'
                  : 'This calendar’s outward sharing could not be verified. Review its audience before continuing.',
              )
            }}
          </p>
          <label
            >{{ t('Event type')
            }}<select v-model="mode">
              <option value="appointment">{{ t('One-time appointment') }}</option>
              <option v-if="!event" value="anniversary">{{ t('Yearly anniversary') }}</option>
            </select></label
          >
          <label v-if="mode === 'anniversary'"
            >{{ t('Anniversary of')
            }}<select v-model="anniversaryKind">
              <option value="birth">{{ t('Birth') }}</option>
              <option value="death">{{ t('Death') }}</option>
            </select></label
          >
          <template v-else>
            <label>{{ t('Appointment date') }}<input v-model="date" type="date" required /></label>
            <label>{{ t('Start time (optional; one hour)') }}<input v-model="time" type="time" /></label>
            <label v-if="time"
              >{{ t('Timezone') }}<input v-model="timezone" required maxlength="100"
            /></label>
          </template>
          <label>{{ t('Event title') }}<input v-model="title" required maxlength="200" /></label>
          <label>{{ t('Location (optional)') }}<input v-model="location" maxlength="300" /></label>
          <label v-if="livingWarning" class="check"
            ><input v-model="includeLiving" type="checkbox" />{{
              t(
                event
                  ? 'Include living or unknown-status people in the event'
                  : 'Include this living or unknown-status person in the event',
              )
            }}</label
          >
          <label v-if="managementWarning" class="check"
            ><input v-model="includeManagement" type="checkbox" />{{
              t(
                event
                  ? 'Include management-only research in the event'
                  : 'Include this management-only person in the event',
              )
            }}</label
          >
          <button type="submit">{{ t('Review event') }}</button>
        </fieldset>
      </form>
      <button v-if="nextCollectionPage !== null && !review" :disabled="busy" @click="loadMoreCollections">
        {{ t('Load more calendars') }}
      </button>
      <div v-if="review" class="task-preview">
        <h3>{{ t('Review calendar event') }}</h3>
        <p>
          <strong>{{ review.title }}</strong> · {{ review.personName }}
        </p>
        <p>
          {{ review.date
          }}<span v-if="review.time"> {{ review.time }} · {{ review.timezone }} · {{ t('one hour') }}</span>
          <span v-else-if="review.mode === 'anniversary'"> · {{ review.timezone }}</span>
        </p>
        <p v-if="review.mode === 'anniversary'">
          {{ t('Repeats yearly')
          }}<span v-if="review.date.slice(5) === '02-29'">
            · {{ t('29 February repeats only in leap years') }}</span
          >
        </p>
        <p v-if="review.location">{{ t('Location') }}: {{ review.location }}</p>
        <p>
          {{
            t(
              event
                ? 'A link back to this research will be included. Opening it still requires tree access.'
                : 'A private link back to this person will be included.',
            )
          }}
        </p>
        <p>{{ t(review.audienceWarning) }}</p>
        <p v-if="review.mode === 'anniversary' && review.anniversaryKind === 'birth'" class="muted">
          {{ t('Nextcloud may already show a birthday from Contacts. Duplicate detection is incomplete.') }}
        </p>
        <label v-if="review.linkedContactBirthday" class="check"
          ><input v-model="acknowledgedDuplicate" type="checkbox" />{{
            t('I want another birthday event despite the linked contact')
          }}</label
        >
        <label class="check"
          ><input v-model="acknowledged" type="checkbox" />{{
            t('I understand who may see this event')
          }}</label
        >
        <div class="button-row">
          <button
            :disabled="busy || !acknowledged || (review.linkedContactBirthday && !acknowledgedDuplicate)"
            @click="create"
          >
            {{ t('Create calendar event') }}
          </button>
          <button :disabled="busy" @click="review = null">{{ t('Change event details') }}</button>
        </div>
      </div>
      <div v-if="links.length" class="task-links">
        <h3>{{ t('My linked calendar events') }}</h3>
        <ul>
          <li v-for="link in links" :key="link.id">
            {{
              t(
                link.status === 'linked'
                  ? 'Linked event'
                  : link.status === 'source-changed'
                    ? 'Source changed; review before updating'
                    : link.status === 'destination-changed'
                      ? 'Calendar event is missing or moved'
                      : link.status === 'unavailable'
                        ? 'Calendar access is unavailable'
                        : link.status === 'outcome-unknown'
                          ? 'Event result uncertain; check Calendar'
                          : 'Event creation pending',
              )
            }}
            · {{ link.collection }}
            <button :disabled="busy" @click="unlink(link.id)">{{ t('Unlink event') }}</button>
          </li>
        </ul>
        <div class="button-row">
          <a :href="calendarUrl">{{ t('Open Calendar') }}</a
          ><button :disabled="busy" @click="refresh">{{ t('Refresh links') }}</button>
        </div>
      </div>
    </section>
  </div>
</template>

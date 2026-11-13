<script setup>
import { computed, ref, watch } from 'vue';
import { api, userId } from '../api.js';
import { requestId, personName } from '../domain.js';
import { t } from '../i18n.js';
import { captureCreatedEtag } from '../integrations.js';

const props = defineProps({
  treeId: String,
  person: Object,
  source: Object,
  sourceType: String,
  canExport: Boolean,
});
const origin = computed(() => props.source || props.person);
const originType = computed(() => props.sourceType || 'person');
const originLabel = computed(() =>
  originType.value === 'person'
    ? personName(origin.value, t('Unnamed person'))
    : origin.value?.title || t('Untitled'),
);
const originPeople = computed(() =>
  originType.value === 'person'
    ? [origin.value]
    : originType.value === 'event'
      ? origin.value?.participants?.map((p) => p.person) || []
      : originType.value === 'medium'
        ? [
            ...(origin.value?.assignments?.map((a) => a.person) || []),
            ...(origin.value?.metadata?.people?.map((p) => p.person || { livingStatus: 'unknown' }) || []),
          ]
        : [],
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
  title = ref(''),
  note = ref(''),
  due = ref(''),
  priority = ref('0'),
  includeLiving = ref(false),
  includeManagement = ref(false),
  review = ref(null),
  acknowledged = ref(false),
  links = ref([]);
let key = requestId(),
  sequence = 0,
  reviewSequence = 0;
const tasksUrl = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/index.php/apps/tasks/`;

const davRoot = () =>
  `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/remote.php/dav/calendars/${encodeURIComponent(userId)}`;

async function discoverTaskCalendars(items) {
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
  if (response.status !== 207) throw new Error('Task lists could not be loaded. Try again.');
  const xml = new DOMParser().parseFromString(await response.text(), 'application/xml');
  if (xml.querySelector('parsererror')) throw new Error('Task lists could not be loaded. Try again.');
  const available = new Set();
  for (const node of xml.getElementsByTagNameNS('DAV:', 'response')) {
    const href = node.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || '';
    const path = new URL(href, window.location.origin).pathname;
    const uri = decodeURIComponent(path.replace(/\/$/, '').split('/').pop() || '');
    const components = [...node.getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'comp')];
    if (components.some((component) => component.getAttribute('name') === 'VTODO')) available.add(uri);
  }
  return items.filter((item) => available.has(item.uri));
}

async function loadLinks() {
  const treeId = props.treeId,
    sourceId = origin.value.id;
  const result = await api(
    `/trees/${treeId}/tasks?sourceType=${encodeURIComponent(originType.value)}&sourceId=${encodeURIComponent(sourceId)}`,
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
  title.value ||= `${t('Research')} ${originLabel.value}`;
  try {
    const result = await api('/integrations/tasks/collections?page=0');
    if (!result.available) throw new Error('Nextcloud Tasks is not available for this account.');
    const detected = await discoverTaskCalendars([...result.items, ...(result.readOnlyItems || [])]);
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
    const result = await api(`/integrations/tasks/collections?page=${next}`);
    if (!result.available) throw new Error('Nextcloud Tasks is not available for this account.');
    const detected = await discoverTaskCalendars([...result.items, ...(result.readOnlyItems || [])]);
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
  () => [origin.value?.id, originType.value, props.treeId],
  () => {
    sequence++;
    reviewSequence++;
    open.value = false;
    busy.value = false;
    review.value = null;
    links.value = [];
    collections.value = [];
    readOnlyCount.value = 0;
    nextCollectionPage.value = null;
    title.value = '';
    note.value = '';
    due.value = '';
    priority.value = '0';
    includeLiving.value = false;
    includeManagement.value = false;
    acknowledged.value = false;
    key = requestId();
  },
);

watch([title, note, due, priority, collection, includeLiving, includeManagement], () => {
  reviewSequence++;
  review.value = null;
  acknowledged.value = false;
  key = requestId();
});

async function preview() {
  const seq = ++reviewSequence,
    treeId = props.treeId,
    sourceId = origin.value.id;
  busy.value = true;
  error.value = '';
  review.value = null;
  try {
    const result = await api(`/trees/${treeId}/tasks/review`, {
      method: 'POST',
      body: {
        sourceType: originType.value,
        sourceId,
        collection: collection.value,
        title: title.value,
        note: note.value,
        due: due.value,
        priority: priority.value,
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
  const treeId = props.treeId,
    sourceId = origin.value.id,
    seq = sequence;
  busy.value = true;
  error.value = '';
  let reservation;
  try {
    reservation = await api(`/trees/${treeId}/tasks`, {
      method: 'POST',
      body: { review: review.value, acknowledged: acknowledged.value, requestId: key },
    });
    if (reservation.status !== 'linked') {
      const uri = `${davRoot()}/${encodeURIComponent(reservation.collection)}/${encodeURIComponent(reservation.objectName)}`;
      const response = await fetch(uri, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-None-Match': '*',
          requesttoken:
            window.OC?.requestToken || document.getElementById('stammbaum-app')?.dataset.requestToken,
        },
        body: reservation.ics,
      });
      if (![201, 204, 412].includes(response.status)) {
        throw new Error(
          response.status === 403
            ? 'You can no longer write to this task list.'
            : 'Task creation could not be confirmed. Refresh before trying again.',
        );
      }
      await api(`/trees/${treeId}/tasks/${reservation.id}/complete`, { method: 'POST' });
    }
    await captureCreatedEtag(treeId, 'task', reservation.id, reservation.collection || collection.value);
    if (seq !== sequence || treeId !== props.treeId || sourceId !== origin.value?.id) return;
    review.value = null;
    acknowledged.value = false;
    key = requestId();
    await loadLinks();
  } catch (e) {
    if (reservation) {
      try {
        await api(`/trees/${treeId}/tasks/${reservation.id}/complete`, { method: 'POST' });
        if (seq !== sequence || treeId !== props.treeId || sourceId !== origin.value?.id) return;
        await loadLinks();
        review.value = null;
        return;
      } catch {
        /* Keep the same request ID for a safe retry. */
      }
    }
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
</script>

<template>
  <div v-if="canExport" class="research-tasks">
    <button type="button" :aria-expanded="open" @click="toggle">{{ t('Create research task') }}</button>
    <section v-if="open" class="task-review" :aria-label="t('Research task')">
      <p>
        {{
          t(
            'Tasks owns the resulting task. People with access to the chosen task list may see its copied details.',
          )
        }}
      </p>
      <p v-if="busy" role="status">{{ t('Working…') }}</p>
      <p v-if="error" role="alert" class="feedback error">{{ error }}</p>
      <p v-if="!busy && !collections.length">{{ t('No writable task list is available.') }}</p>
      <p v-if="readOnlyCount" class="muted">{{ t('Read-only task lists are unavailable for creation.') }}</p>
      <form v-if="collections.length" @submit.prevent="preview">
        <fieldset :disabled="busy">
          <label
            >{{ t('Task list') }}
            <select v-model="collection" required>
              <option v-for="item in collections" :key="item.uri" :value="item.uri">{{ item.name }}</option>
            </select>
          </label>
          <p class="muted">
            {{
              t(
                selectedAudience === 'shared-with-me'
                  ? 'This task list is shared with your account. Other readers may see copied details.'
                  : 'This task list’s outward sharing could not be verified. Review its audience before continuing.',
              )
            }}
          </p>
          <label>{{ t('Task title') }}<input v-model="title" maxlength="200" required /></label>
          <label>{{ t('Research instruction') }}<textarea v-model="note" maxlength="1000" /></label>
          <label>{{ t('Due date (optional)') }}<input v-model="due" type="date" /></label>
          <label
            >{{ t('Priority')
            }}<select v-model="priority">
              <option value="0">{{ t('Unspecified') }}</option>
              <option value="1">{{ t('High') }}</option>
              <option value="5">{{ t('Normal') }}</option>
              <option value="9">{{ t('Low') }}</option>
            </select></label
          >
          <label v-if="livingWarning" class="check"
            ><input v-model="includeLiving" type="checkbox" />{{
              t(
                originType === 'person'
                  ? 'Include this living or unknown-status person in the task'
                  : 'Include living or unknown-status people in the task',
              )
            }}</label
          >
          <label v-if="managementWarning" class="check"
            ><input v-model="includeManagement" type="checkbox" />{{
              t(
                originType === 'person'
                  ? 'Include this management-only person in the task'
                  : 'Include management-only research in the task',
              )
            }}</label
          >
          <button type="submit">{{ t('Review task') }}</button>
        </fieldset>
      </form>
      <button v-if="nextCollectionPage !== null" :disabled="busy" @click="loadMoreCollections">
        {{ t('Load more task lists') }}
      </button>
      <div v-if="review" class="task-preview">
        <h3>{{ t('Review copied details') }}</h3>
        <p>
          <strong>{{ review.title }}</strong> · {{ review.personName }}
        </p>
        <p v-if="review.note" class="preserve-text">{{ review.note }}</p>
        <p>
          {{ t('A link back to this research will be included. Opening it still requires tree access.') }}
        </p>
        <p>{{ t(review.audienceWarning) }}</p>
        <label class="check"
          ><input v-model="acknowledged" type="checkbox" />{{
            t('I understand who may see this task')
          }}</label
        >
        <div class="button-row">
          <button :disabled="busy || !acknowledged" @click="create">{{ t('Create task') }}</button
          ><button :disabled="busy" @click="review = null">{{ t('Cancel') }}</button>
        </div>
      </div>
      <div v-if="links.length" class="task-links">
        <h3>{{ t('My linked tasks') }}</h3>
        <p class="muted">{{ t('Task completion is read from Tasks; edit the task there.') }}</p>
        <ul>
          <li v-for="link in links" :key="link.id">
            {{
              t(
                link.status === 'linked'
                  ? 'Linked task'
                  : link.status === 'source-changed'
                    ? 'Source changed; review before updating'
                    : link.status === 'outcome-unknown'
                      ? 'Task result uncertain; check Tasks'
                      : link.status === 'destination-changed'
                        ? 'Task was removed or moved in Tasks'
                        : link.status === 'unavailable'
                          ? 'Task list is unavailable'
                          : 'Task creation pending',
              )
            }}
            ·
            {{ link.collection }}
            <span v-if="link.completion === 'complete'"> · {{ t('Completed') }}</span>
            <span v-else-if="link.completion === 'open'"> · {{ t('Open') }}</span>
          </li>
        </ul>
        <div class="button-row">
          <a :href="tasksUrl">{{ t('Open Tasks') }}</a>
          <button :disabled="busy" @click="refresh">{{ t('Refresh links') }}</button>
        </div>
      </div>
    </section>
  </div>
</template>

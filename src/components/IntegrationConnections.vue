<script setup>
import { ref, onMounted, watch } from 'vue';
import { api } from '../api.js';
import { t } from '../i18n.js';
import { calendarObjectUrl } from '../integrations.js';

const props = defineProps({ treeId: { type: String, required: true } });
const emit = defineEmits(['open-person']);
const items = ref([]);
const page = ref(0);
const nextPage = ref(null);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');
const removal = ref(null);
const removalAcknowledged = ref(false);
let sequence = 0;
function clearDisplayedConnections() {
  items.value = [];
  removal.value = null;
  removalAcknowledged.value = false;
  nextPage.value = null;
}
const statusText = (status) =>
  t(
    {
      pending: 'Pending confirmation',
      linked: 'Linked',
      'source-changed': 'Source changed — review before updating',
      'destination-changed': 'External item changed or was removed',
      unavailable: 'Source or external app unavailable',
      'authority-invalidated': 'Access changed — new review required',
      'outcome-unknown': 'Outcome uncertain — check the external app',
      unlinked: 'Unlinked locally',
    }[status] || status,
  );
const kindText = (kind) =>
  t({ task: 'Research task', calendar: 'Calendar event', contact: 'Contact card' }[kind] || kind);

async function load(target = 0) {
  const ticket = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const result = await api(`/trees/${props.treeId}/connections?page=${target}`);
    if (ticket !== sequence) return;
    items.value = result.items;
    page.value = target;
    nextPage.value = result.nextPage;
  } catch (e) {
    if (ticket === sequence) {
      clearDisplayedConnections();
      error.value = t(e.message);
    }
  } finally {
    if (ticket === sequence) loading.value = false;
  }
}
async function refresh(item) {
  busy.value = item.id;
  error.value = '';
  try {
    let result = await api(`/trees/${props.treeId}/connections/${item.kind}/${item.id}/refresh`, {
      method: 'POST',
    });
    if (result.canRemoveExternal) {
      const response = await fetch(calendarObjectUrl(result.collection, `${result.id}.ics`), {
        credentials: 'same-origin',
        cache: 'no-store',
      }).catch(() => {
        throw new Error('Destination could not be checked. Try again.');
      });
      const etag = response.headers.get('ETag');
      if (!response.ok || !etag) throw new Error('Destination could not be checked. Try again.');
      result = await api(`/trees/${props.treeId}/connections/${item.kind}/${item.id}/observe-etag`, {
        method: 'POST',
        body: { etag },
      });
    }
    items.value = items.value.map((entry) =>
      entry.id === item.id && entry.kind === item.kind ? result : entry,
    );
    notice.value = t('Connection status refreshed.');
  } catch (e) {
    clearDisplayedConnections();
    error.value = t(e.message);
  } finally {
    busy.value = '';
  }
}
async function unlink(item) {
  if (!confirm(t('Unlink this connection locally? The external item will remain.'))) return;
  busy.value = item.id;
  error.value = '';
  try {
    const result = await api(`/trees/${props.treeId}/connections/${item.kind}/${item.id}`, {
      method: 'DELETE',
    });
    items.value = items.value.map((entry) =>
      entry.id === item.id && entry.kind === item.kind
        ? { ...entry, ...result, source: null, collection: null }
        : entry,
    );
    notice.value = t('Local connection removed. The external item remains.');
  } catch (e) {
    clearDisplayedConnections();
    error.value = t(e.message);
  } finally {
    busy.value = '';
  }
}
async function reviewExternalRemoval(item) {
  busy.value = item.id;
  error.value = '';
  removal.value = null;
  removalAcknowledged.value = false;
  try {
    const review = await api(`/trees/${props.treeId}/connections/${item.kind}/${item.id}/review-removal`, {
      method: 'POST',
    });
    const response = await fetch(calendarObjectUrl(review.collection, review.objectName), {
      credentials: 'same-origin',
      cache: 'no-store',
    }).catch(() => {
      throw new Error('Destination could not be checked. Try again.');
    });
    if (!response.ok) throw new Error('Destination could not be checked. Try again.');
    const observed = response.headers.get('ETag');
    if (observed && observed !== review.etag) {
      const changed = await api(`/trees/${props.treeId}/connections/${item.kind}/${item.id}/observe-etag`, {
        method: 'POST',
        body: { etag: observed },
      });
      items.value = items.value.map((entry) =>
        entry.id === item.id && entry.kind === item.kind ? changed : entry,
      );
    }
    if (observed !== review.etag) throw new Error('The external item changed. Remove it in its own app.');
    removal.value = review;
  } catch (e) {
    clearDisplayedConnections();
    error.value = t(e.message);
  } finally {
    busy.value = '';
  }
}
async function removeExternal() {
  if (!removal.value || !removalAcknowledged.value) return;
  const review = removal.value;
  busy.value = review.id;
  error.value = '';
  try {
    const current = await api(
      `/trees/${props.treeId}/connections/${review.kind}/${review.id}/review-removal`,
      { method: 'POST' },
    );
    if (
      current.etag !== review.etag ||
      current.sourceRevision !== review.sourceRevision ||
      current.memberRevision !== review.memberRevision
    )
      throw new Error('Review external removal again.');
    const url = calendarObjectUrl(review.collection, review.objectName);
    const check = await fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(() => {
      throw new Error('Destination could not be checked. Try again.');
    });
    if (!check.ok) throw new Error('Destination could not be checked. Try again.');
    const observed = check.headers.get('ETag');
    if (observed && observed !== review.etag) {
      const changed = await api(
        `/trees/${props.treeId}/connections/${review.kind}/${review.id}/observe-etag`,
        { method: 'POST', body: { etag: observed } },
      );
      items.value = items.value.map((entry) =>
        entry.id === review.id && entry.kind === review.kind ? changed : entry,
      );
    }
    if (observed !== review.etag) throw new Error('The external item changed. Remove it in its own app.');
    const response = await fetch(url, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'If-Match': review.etag,
        requesttoken:
          window.OC?.requestToken || document.getElementById('stammbaum-app')?.dataset.requestToken,
      },
    }).catch(() => {
      throw new Error('External removal could not be verified. Refresh before retrying.');
    });
    if (![200, 204].includes(response.status))
      throw new Error(
        response.status === 412
          ? 'The external item changed. Remove it in its own app.'
          : 'External removal could not be verified. Refresh before retrying.',
      );
    await api(`/trees/${props.treeId}/connections/${review.kind}/${review.id}/complete-removal`, {
      method: 'POST',
      body: { review: current, acknowledged: true },
    });
    removal.value = null;
    removalAcknowledged.value = false;
    notice.value = t('External item removed and local link closed.');
    await load(page.value);
  } catch (e) {
    clearDisplayedConnections();
    error.value = t(e.message);
  } finally {
    busy.value = '';
  }
}
onMounted(() => load());
watch(
  () => props.treeId,
  () => {
    items.value = [];
    load();
  },
);
</script>

<template>
  <section class="panel integration-connections" aria-labelledby="integration-connections-heading">
    <div class="panel-heading">
      <div>
        <h2 id="integration-connections-heading">{{ t('My app connections') }}</h2>
        <p class="muted">
          {{
            t(
              'Only you can see these links. Tasks, Calendar and Contacts keep their own copies and sharing permissions.',
            )
          }}
        </p>
      </div>
      <button :disabled="loading || !!busy" @click="load(page)">{{ t('Refresh list') }}</button>
    </div>
    <p v-if="error" role="alert" class="feedback error">
      {{ error }} <button @click="load(page)">{{ t('Retry') }}</button>
    </p>
    <p v-if="notice" role="status" class="feedback success">{{ notice }}</p>
    <p v-if="loading" role="status">{{ t('Loading connections…') }}</p>
    <p v-else-if="!items.length && !error" class="muted">
      {{ t('No personal app connections in this tree yet.') }}
    </p>
    <ul v-if="items.length" class="connection-list">
      <li v-for="item in items" :key="`${item.kind}-${item.id}`" class="connection-card">
        <div>
          <strong>{{ kindText(item.kind) }}</strong>
          <span class="status-pill">{{ statusText(item.status) }}</span>
          <p v-if="item.source">
            {{ item.source.label }} <small v-if="item.collection">· {{ item.collection }}</small>
          </p>
          <p
            v-if="item.kind === 'task' && item.status === 'linked' && item.completion !== 'unknown'"
            class="muted"
          >
            {{ t(item.completion === 'complete' ? 'Completed in Tasks' : 'Open in Tasks') }}
          </p>
          <p v-if="!item.source && item.status !== 'unlinked'" class="muted">
            {{ t('Source details are hidden. You can still remove this private link.') }}
          </p>
          <p v-if="item.status === 'outcome-unknown'" class="muted">
            {{
              t(
                'Check the destination before starting a new copy. Retrying this link will not create another item.',
              )
            }}
          </p>
          <p v-if="item.status === 'destination-changed' || item.status === 'source-changed'" class="muted">
            {{ t('Nothing was overwritten. Review the source and destination before making a new copy.') }}
          </p>
        </div>
        <div class="button-row">
          <button
            v-if="item.source?.type === 'person'"
            :disabled="loading || !!busy"
            @click="emit('open-person', item.source.id)"
          >
            {{ t('Open source') }}
          </button>
          <button :disabled="loading || !!busy" @click="refresh(item)">{{ t('Refresh status') }}</button>
          <button v-if="item.status !== 'unlinked'" :disabled="loading || !!busy" @click="unlink(item)">
            {{ t('Unlink locally') }}
          </button>
          <button
            v-if="item.canRemoveExternal"
            :disabled="loading || !!busy"
            @click="reviewExternalRemoval(item)"
          >
            {{ t('Review external removal') }}
          </button>
        </div>
      </li>
    </ul>
    <section v-if="removal" class="connection-removal" aria-labelledby="connection-removal-heading">
      <h3 id="connection-removal-heading">{{ t('Remove created external item') }}</h3>
      <p>{{ kindText(removal.kind) }} · {{ removal.source.label }}</p>
      <p class="muted">
        {{
          t(
            'This deletes the unchanged item from its Nextcloud app. Synced copies may remain. A changed item will be refused.',
          )
        }}
      </p>
      <label class="check"
        ><input v-model="removalAcknowledged" type="checkbox" />{{
          t('I understand the external item will be deleted')
        }}</label
      >
      <div class="button-row">
        <button class="primary" :disabled="!!busy || !removalAcknowledged" @click="removeExternal">
          {{ t('Delete external item') }}
        </button>
        <button :disabled="!!busy" @click="removal = null">{{ t('Cancel') }}</button>
      </div>
    </section>
    <div v-if="page || nextPage !== null" class="button-row">
      <button :disabled="loading || !page" @click="load(page - 1)">{{ t('Previous') }}</button>
      <button :disabled="loading || nextPage === null" @click="load(nextPage)">{{ t('Next') }}</button>
    </div>
    <p class="muted">
      {{
        t(
          'Unlinking never deletes the external item. Remove an item directly in its Nextcloud app when needed.',
        )
      }}
    </p>
  </section>
</template>

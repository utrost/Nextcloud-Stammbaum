<script setup>
import { ref, reactive, watch, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useDraftGuard, canNavigate } from '../draftGuard.js';
import { api, apiBase } from '../api.js';
import { t } from '../i18n.js';
import { requestId, personName, dateText } from '../domain.js';
import MediaLinkPicker from './MediaLinkPicker.vue';
import MediaTrash from './MediaTrash.vue';
import MediaEditor from './MediaEditor.vue';
import MediaPreview from './MediaPreview.vue';
import MediaViewer from './MediaViewer.vue';
import ResearchTaskPanel from './ResearchTaskPanel.vue';
const props = defineProps({
    treeId: String,
    person: Object,
    event: Object,
    canEdit: Boolean,
    canExport: Boolean,
    management: Boolean,
    openMediumId: String,
  }),
  emit = defineEmits(['changed', 'person']);
const reviewRegion = ref(null);
const showTrash = ref(false),
  removal = ref(null),
  removing = ref(false);
async function reviewRemoval(m) {
  error.value = '';
  try {
    removal.value = { media: m, impact: await api(`/trees/${props.treeId}/media/${m.id}/impact`) };
    await nextTick();
    reviewRegion.value?.focus();
  } catch (e) {
    error.value = t(e.message);
  }
}
async function removeMedium() {
  removing.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/media/${removal.value.media.id}/delete`, {
      method: 'POST',
      body: removal.value.impact,
    });
    removal.value = null;
    viewer.value = null;
    emit('changed');
    await load();
  } catch (e) {
    error.value = t(e.message);
    removal.value = null;
  } finally {
    removing.value = false;
  }
}
const filters = reactive({ type: '', personQuery: '', eventQuery: '', place: '', missing: false });
let applied = {},
  requestedPage = 0;
const eventContext = ref(props.event || null),
  linkedPerson = ref(null),
  pickingPerson = ref(false);
const items = ref([]),
  page = ref(null),
  loading = ref(false),
  contextDenied = ref(false),
  error = ref(''),
  editor = ref(null),
  viewer = ref(null),
  file = ref(null),
  cloudFile = ref(null),
  selecting = ref(false),
  fileInput = ref(null),
  uploading = ref(false),
  title = ref(''),
  visibility = ref('members');
useDraftGuard(
  computed(() => file.value !== null || cloudFile.value !== null),
  uploading,
);
let sequence = 0,
  request = requestId();
const url = (m, kind = 'preview') =>
  `${apiBase}/trees/${props.treeId}/media/${m.id}/${kind}?revision=${m.revision}${props.person && m.assignments.some((a) => a.personId === props.person.id) ? '&person=' + props.person.id : ''}`;
function unavailable(id) {
  items.value = items.value.filter((item) => item.id !== id);
}
async function load(next = 0) {
  requestedPage = next;
  const seq = ++sequence;
  loading.value = true;
  error.value = '';
  try {
    const query = new URLSearchParams({
      ...applied,
      person: props.person?.id || linkedPerson.value?.id || '',
      event: eventContext.value?.id || '',
      page: next,
    });
    const r = await api(`/trees/${props.treeId}/media?${query}`);
    if (seq !== sequence) return;
    items.value = next ? [...items.value, ...r.items] : r.items;
    page.value = r.nextPage;
    contextDenied.value = false;
  } catch (e) {
    if (seq === sequence) {
      error.value = t(e.message);
      items.value = [];
      page.value = null;
      if ([401, 403, 404].includes(e.status)) {
        contextDenied.value = true;
        items.value = [];
        viewer.value = null;
      }
    }
  } finally {
    if (seq === sequence) loading.value = false;
  }
}
function choose(e) {
  cloudFile.value = null;
  file.value = e.target.files[0] || null;
  title.value = file.value?.name || '';
  request = requestId();
  error.value = '';
}
function chooseCloud() {
  error.value = '';
  if (!window.OC?.dialogs?.filepicker) {
    error.value = t('The Nextcloud file picker is unavailable. Reload the page and try again.');
    return;
  }
  window.OC.dialogs.filepicker(
    t('Choose from Nextcloud Files'),
    async (path) => {
      if (!path) return;
      selecting.value = true;
      try {
        const selected = await api(`/trees/${props.treeId}/media/select-file`, {
          method: 'POST',
          body: { path },
        });
        cloudFile.value = selected;
        file.value = null;
        fileInput.value.value = '';
        title.value = selected.name;
        request = requestId();
      } catch (e) {
        error.value = t(e.message);
      } finally {
        selecting.value = false;
      }
    },
    false,
    ['image/jpeg', 'image/png', 'application/pdf'],
    true,
  );
}
async function importCloud() {
  uploading.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/media/import-file`, {
      method: 'POST',
      body: {
        fileId: cloudFile.value.id,
        etag: cloudFile.value.etag,
        requestId: request,
        title: title.value,
        personId: props.person?.id || '',
        visibility: visibility.value,
      },
    });
    cloudFile.value = null;
    title.value = '';
    request = requestId();
    await load();
    emit('changed');
  } catch (e) {
    if (e.code === 'retry_conflict') request = requestId();
    error.value = t(e.message);
  } finally {
    uploading.value = false;
  }
}
async function upload() {
  if (cloudFile.value) return importCloud();
  if (!file.value) return;
  uploading.value = true;
  error.value = '';
  try {
    const body = new FormData();
    body.append('file', file.value);
    body.append('title', title.value);
    body.append('visibility', visibility.value);
    body.append('personId', props.person?.id || '');
    body.append('requestId', request);
    const response = await fetch(`${apiBase}/trees/${props.treeId}/media`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { requesttoken: window.OC.requestToken },
      body,
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error('The upload was not received completely.');
    }
    if (!response.ok) {
      if (result.error === 'retry_conflict') request = requestId();
      throw new Error(result.message || 'Media could not be processed.');
    }
    file.value = null;
    fileInput.value.value = '';
    title.value = '';
    request = requestId();
    await load();
    emit('changed');
  } catch (e) {
    error.value = t(e.message);
  } finally {
    uploading.value = false;
  }
}
function applyFilters() {
  applied = { ...filters, missing: filters.missing ? '1' : '0' };
  viewer.value = null;
  items.value = [];
  load();
}
function resetFilters() {
  linkedPerson.value = null;
  pickingPerson.value = false;
  Object.assign(filters, { type: '', personQuery: '', eventQuery: '', place: '', missing: false });
  applyFilters();
}
function openPerson(id) {
  viewer.value = null;
  emit('person', id);
}
function openEvent(event) {
  if (!canNavigate()) return;
  file.value = null;
  cloudFile.value = null;
  title.value = '';
  viewer.value = null;
  eventContext.value = event;
  resetFilters();
}
async function saved() {
  editor.value = null;
  await load();
  emit('changed');
}
function unload(e) {
  if (uploading.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}
watch(
  () => [props.person?.id, props.event?.id],
  () => {
    items.value = [];
    viewer.value = null;
    editor.value = null;
    eventContext.value = props.event || null;
    resetFilters();
  },
);
onMounted(async () => {
  window.addEventListener('beforeunload', unload);
  await load();
  const seq = sequence;
  if (props.openMediumId && !props.person && !props.event) {
    try {
      const medium = await api(`/trees/${props.treeId}/media/${props.openMediumId}`);
      if (seq !== sequence) return;
      if (!items.value.some((item) => item.id === medium.id)) items.value = [medium, ...items.value];
      await nextTick();
      document.querySelector(`[data-medium-id="${medium.id}"]`)?.focus({ preventScroll: false });
    } catch (e) {
      if (seq === sequence) error.value = t(e.message);
    }
  }
});
onBeforeUnmount(() => {
  sequence++;
  window.removeEventListener('beforeunload', unload);
});
</script>
<template>
  <section class="media-panel">
    <button v-if="management && !person" @click="showTrash = !showTrash">{{ t('Media trash') }}</button>
    <MediaTrash
      v-if="management && showTrash"
      :tree-id="treeId"
      @changed="
        load();
        emit('changed');
      "
    />
    <section
      v-if="removal"
      ref="reviewRegion"
      tabindex="-1"
      class="panel"
      role="region"
      :aria-label="t('Review media removal')"
    >
      <h3>{{ t('Review media removal') }}: {{ removal.media.title }}</h3>
      <p>
        {{ t('Attachments') }}: {{ removal.impact.assignments }} · {{ t('People shown or mentioned') }}:
        {{ removal.impact.people }} · {{ t('Linked event') }}: {{ removal.impact.event ? t('Yes') : t('No') }}
      </p>
      <p>
        {{
          t(
            'This removes the medium from galleries, portraits, downloads and exports. The original remains in trash and still counts toward storage.',
          )
        }}
      </p>
      <button :disabled="removing" @click="removeMedium">{{ t('Move medium to trash') }}</button>
      <button :disabled="removing" @click="removal = null">{{ t('Cancel') }}</button>
    </section>
    <h2 v-if="!person">{{ t('Media library') }}</h2>
    <section v-if="eventContext && !contextDenied" class="panel media-event-context">
      <h3>{{ eventContext.title }}</h3>
      <p>{{ dateText(eventContext.date) }} · {{ eventContext.place }}</p>
      <p class="preserve-text">{{ eventContext.text }}</p>
      <button
        v-if="!event"
        @click="
          eventContext = null;
          resetFilters();
        "
      >
        {{ t('Back to media library') }}
      </button>
    </section>
    <button
      v-if="eventContext && contextDenied && !event"
      @click="
        eventContext = null;
        resetFilters();
      "
    >
      {{ t('Back to media library') }}
    </button>
    <div v-if="!person" class="media-person-filter">
      <button v-if="!linkedPerson" @click="pickingPerson = !pickingPerson">
        {{ t('Filter by tree person') }}
      </button>
      <p v-else-if="!contextDenied">
        {{ personName(linkedPerson) }}
        <button
          @click="
            linkedPerson = null;
            applyFilters();
          "
        >
          {{ t('Clear person filter') }}
        </button>
      </p>
      <MediaLinkPicker
        v-if="pickingPerson"
        :tree-id="treeId"
        kind="person"
        @select="
          linkedPerson = $event;
          pickingPerson = false;
          applyFilters();
        "
      />
    </div>
    <form class="media-filters" @submit.prevent="applyFilters">
      <label
        >{{ t('Media type')
        }}<select v-model="filters.type" :aria-label="t('Media type')">
          <option value="">{{ t('All media') }}</option>
          <option value="image">{{ t('Images') }}</option>
          <option value="pdf">PDF</option>
        </select></label
      >
      <label
        >{{ t('Named person') }}<input v-model="filters.personQuery" type="search" maxlength="200"
      /></label>
      <label v-if="!eventContext"
        >{{ t('Event filter') }}<input v-model="filters.eventQuery" type="search" maxlength="200"
      /></label>
      <label>{{ t('Place filter') }}<input v-model="filters.place" type="search" maxlength="200" /></label>
      <label class="check"
        ><input v-model="filters.missing" type="checkbox" />{{ t('Missing details') }}</label
      >
      <p class="muted">
        {{
          t('Missing details means no subject date, named people or place. Names match recorded captions.')
        }}
      </p>
      <div class="button-row">
        <button type="submit" :disabled="loading">{{ t('Apply filters') }}</button
        ><button type="button" @click="resetFilters">{{ t('Reset filters') }}</button>
      </div>
    </form>
    <form v-if="canEdit && !eventContext" class="media-upload" @submit.prevent="upload">
      <label
        >{{ t('Choose original')
        }}<input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          :disabled="uploading"
          @change="choose"
      /></label>
      <button type="button" :disabled="uploading || selecting" @click="chooseCloud">
        {{ t('Choose from Nextcloud Files') }}
      </button>
      <p v-if="selecting" role="status">{{ t('Loading…') }}</p>
      <p class="muted">
        {{
          t(
            'JPEG, PNG or PDF. Up to 10 MiB; images up to 16 million pixels. Originals stay private and unchanged.',
          )
        }}
      </p>
      <template v-if="file || cloudFile"
        ><label
          >{{ t('Title') }}<input v-model="title" maxlength="250" required :disabled="uploading" /></label
        ><label v-if="management"
          >{{ t('Visibility')
          }}<select v-model="visibility" :disabled="uploading" :aria-label="t('Visibility')">
            <option value="members">{{ t('All tree members') }}</option>
            <option value="management">{{ t('Management only') }}</option>
          </select></label
        >
        <p v-if="cloudFile" class="muted">
          {{ cloudFile.name }} · {{ (cloudFile.size / 1024).toFixed(1) }} KiB · {{ cloudFile.mime }} <br />{{
            t(
              'A private copy will be stored in this tree and count toward its installation quota. Later changes, deletion or sharing changes in Files will not affect this copy.',
            )
          }}
          <br />{{ t(visibility === 'management' ? 'Management only' : 'All tree members') }}
        </p>
        <button
          v-if="cloudFile"
          type="button"
          :disabled="uploading"
          @click="
            cloudFile = null;
            title = '';
          "
        >
          {{ t('Cancel') }}</button
        ><button class="primary" :disabled="uploading">
          {{
            t(uploading ? 'Uploading and verifying…' : cloudFile ? 'Import private copy' : 'Upload original')
          }}
        </button></template
      >
    </form>
    <p v-if="error" class="feedback error" role="alert">
      {{ error }} <button v-if="!uploading" @click="load(requestedPage)">{{ t('Reload library') }}</button>
    </p>
    <p v-if="loading" role="status">{{ t('Loading…') }}</p>
    <p v-if="!items.length && !loading && !error" class="muted">
      {{
        t(
          Object.values(applied).some((v) => v && v !== '0') || linkedPerson
            ? 'No matching media'
            : 'No media yet',
        )
      }}
    </p>
    <div class="media-grid">
      <article v-for="m in items" :key="m.id" :data-medium-id="m.id" tabindex="-1" class="panel media-card">
        <MediaPreview :url="url(m) + '&size=320'" :label="m.title" lazy @unavailable="unavailable(m.id)" />
        <span class="badge">{{ m.mime === 'application/pdf' ? 'PDF' : t('Image') }}</span>
        <h3>{{ m.title }}</h3>
        <p class="media-caption">{{ dateText(m.metadata.date) || t('Date not recorded') }}</p>
        <p v-for="(p, i) in m.metadata.people.slice(0, 3)" :key="i" class="media-caption">
          {{ t(p.association === 'shown' ? 'Shown' : 'Mentioned') }}:
          {{ p.name || personName(p.person, t('Unidentified person')) }}
        </p>
        <small v-if="m.metadata.people.length > 3"
          >+ {{ m.metadata.people.length - 3 }} {{ t('People') }}</small
        >
        <small>{{ m.originalName }} · {{ (m.size / 1024).toFixed(1) }} KiB</small
        ><span v-if="m.visibility === 'management'" class="badge">{{ t('Management only') }}</span>
        <p v-for="a in m.assignments" class="media-caption">
          {{ personName(a.person, t('Unnamed person')) }} ·
          {{ t(a.purpose === 'portrait' ? 'Portrait' : 'Attachment')
          }}<span v-if="a.preferred"> · {{ t('Preferred') }}</span>
        </p>
        <details>
          <summary>{{ t('Original integrity') }}</summary>
          <small class="file-hash">SHA-256 {{ m.sha256 }}</small>
        </details>
        <div class="button-row">
          <button @click="viewer = m">{{ t('Open preview') }}</button>
          <a class="download-original" :href="url(m, 'original')">{{ t('Download original') }}</a
          ><button v-if="canEdit" @click="editor = m">{{ t('Edit media') }}</button
          ><button v-if="management" @click="reviewRemoval(m)">{{ t('Move medium to trash') }}</button>
        </div>
        <ResearchTaskPanel :tree-id="treeId" :source="m" source-type="medium" :can-export="canExport" />
      </article>
    </div>
    <button v-if="page !== null" :disabled="loading" @click="load(page)">{{ t('Load more') }}</button
    ><MediaViewer
      v-if="viewer"
      :tree-id="treeId"
      :record="viewer"
      :person-id="person && viewer.assignments.some((a) => a.personId === person.id) ? person.id : ''"
      :can-edit="canEdit"
      @edit="
        editor = viewer;
        viewer = null;
      "
      @person="openPerson"
      @event="openEvent"
      @close="viewer = null"
      @unavailable="unavailable(viewer.id)"
    /><MediaEditor
      v-if="editor"
      :record="editor"
      :tree-id="treeId"
      :management="management"
      @close="editor = null"
      @saved="saved"
    />
  </section>
</template>

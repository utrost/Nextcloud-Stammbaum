<script setup>
import { ref, reactive, watch, onMounted, onBeforeUnmount } from 'vue';
import { api, apiBase } from '../api.js';
import { requestId } from '../domain.js';
import { t } from '../i18n.js';
const props = defineProps({ treeId: String, management: Boolean });
const options = reactive({ living: false, management: false, media: false });
const review = ref(null),
  acknowledged = ref(false),
  jobs = ref([]),
  busy = ref(false),
  error = ref(''),
  next = ref(null);
let sequence = 0,
  timer,
  key = requestId();
watch(options, () => {
  review.value = null;
  acknowledged.value = false;
  sequence++;
});
async function preview() {
  const seq = ++sequence;
  busy.value = true;
  error.value = '';
  review.value = null;
  acknowledged.value = false;
  try {
    const result = await api(`/trees/${props.treeId}/exports/preview`, {
      method: 'POST',
      body: { options: { ...options } },
    });
    if (seq === sequence) {
      review.value = result;
      key = requestId();
    }
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
async function load(page = 0) {
  try {
    const result = await api(`/trees/${props.treeId}/exports?page=${page}`);
    jobs.value = page ? [...jobs.value, ...result.items] : result.items;
    next.value = result.nextPage;
  } catch (e) {
    jobs.value = [];
    next.value = null;
    error.value = t(e.message);
  }
}
async function create() {
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/exports`, {
      method: 'POST',
      body: { ...review.value, acknowledged: acknowledged.value, requestId: key },
    });
    review.value = null;
    acknowledged.value = false;
    await load();
  } catch (e) {
    error.value = t(e.message);
    if (e.status === 412) review.value = null;
  } finally {
    busy.value = false;
  }
}
async function cancel(job) {
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/exports/${job.id}/cancel`, { method: 'POST' });
    await load();
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
const url = (job, part) => `${apiBase}/trees/${props.treeId}/exports/${job.id}/${part}`;
onMounted(() => {
  load();
  timer = setInterval(() => {
    if (!busy.value) load();
  }, 10000);
});
onBeforeUnmount(() => {
  sequence++;
  clearInterval(timer);
});
</script>
<template>
  <section class="panel export-panel">
    <h2>{{ t('Export current research') }}</h2>
    <p>
      {{
        t(
          'Review a GEDCOM 5.5.1 export of the current tree. This is not a backup or a download of an original import.',
        )
      }}
    </p>
    <fieldset :disabled="busy">
      <legend>{{ t('Export options') }}</legend>
      <label class="check"
        ><input v-model="options.living" type="checkbox" />{{
          t('Include living people and unknown living status')
        }}</label
      >
      <label v-if="management" class="check"
        ><input v-model="options.management" type="checkbox" />{{
          t('Include management-only records')
        }}</label
      >
      <label class="check"
        ><input v-model="options.media" type="checkbox" />{{ t('Package original images and PDFs') }}</label
      >
      <button @click="preview">{{ t('Review export') }}</button>
    </fieldset>
    <p v-if="error" class="feedback error" role="alert">{{ error }}</p>
    <section v-if="review" aria-live="polite">
      <h3>{{ t('Included research') }}</h3>
      <dl>
        <template v-for="(count, kind) in review.counts" :key="kind"
          ><dt>
            {{
              t(
                {
                  people: 'People',
                  records: 'Records',
                  media: 'Media',
                  relations: 'Relationships',
                  claims: 'Assertions',
                  sources: 'Sources',
                  citations: 'Citations',
                }[kind],
              )
            }}
          </dt>
          <dd>{{ count }}</dd></template
        >
      </dl>
      <ul>
        <li v-for="warning in review.warnings" :key="warning">{{ t(warning) }}</li>
      </ul>
      <label class="check"
        ><input v-model="acknowledged" type="checkbox" />{{
          t('I reviewed the included research and export limitations.')
        }}</label
      >
      <button class="primary" :disabled="busy || !acknowledged" @click="create">
        {{ t('Create export') }}
      </button>
    </section>
    <h3>{{ t('Your export jobs') }}</h3>
    <p>
      {{
        t('Results expire after 24 hours. Tree edits or access changes invalidate older exports permanently.')
      }}
    </p>
    <button :disabled="busy" @click="load()">{{ t('Refresh') }}</button>
    <p v-if="!jobs.length">{{ t('No export jobs yet') }}</p>
    <article v-for="job in jobs" :key="job.id" class="panel">
      <p>
        {{ new Date(job.createdAt * 1000).toLocaleString() }} ·
        <strong>{{
          t(
            {
              queued: 'Queued',
              running: 'Processing',
              ready: 'Ready',
              cancelled: 'Cancelled',
              failed: 'Failed',
              invalid: 'Invalidated',
              expired: 'Expired',
            }[job.state],
          )
        }}</strong>
      </p>
      <div v-if="job.state === 'ready'" class="button-row">
        <a :href="url(job, 'gedcom')">{{ t('Download GEDCOM') }}</a
        ><a :href="url(job, 'report')">{{ t('Download fidelity report') }}</a
        ><a v-if="job.options.media" :href="url(job, 'package')">{{ t('Download media package') }}</a>
      </div>
      <button v-if="['queued', 'running'].includes(job.state)" :disabled="busy" @click="cancel(job)">
        {{ t('Cancel export') }}
      </button>
      <p v-if="['invalid', 'expired', 'failed'].includes(job.state)">
        {{ t('Review and create a new export.') }}
      </p>
    </article>
    <button v-if="next !== null" :disabled="busy" @click="load(next)">{{ t('Load more') }}</button>
  </section>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { api } from '../api.js';
import { requestId } from '../domain.js';
import { t } from '../i18n.js';

const props = defineProps({ treeId: String, person: Object, management: Boolean });
const emit = defineEmits(['copied']);
const open = ref(false),
  busy = ref(false),
  error = ref(''),
  books = ref([]),
  bookKey = ref(''),
  query = ref(''),
  searched = ref(false),
  candidates = ref([]),
  nextSearchPage = ref(null),
  links = ref([]),
  nextLinkPage = ref(null),
  linkReview = ref(null),
  copyDraft = ref(null),
  copyCurrent = ref([]),
  copyReview = ref(null),
  acknowledged = ref(false);
let sequence = 0,
  linkRequest = requestId(),
  copyRequest = requestId();
const contactsUrl = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/apps/contacts/`;
const selectedBook = computed(() => books.value.find((b) => b.key === bookKey.value));
const replaceChoices = computed(() => copyCurrent.value.filter((r) => r.kind === copyDraft.value?.kind));

function resetReviews() {
  linkReview.value = null;
  copyDraft.value = null;
  copyCurrent.value = [];
  copyReview.value = null;
  acknowledged.value = false;
  linkRequest = requestId();
  copyRequest = requestId();
}

watch(
  () => [props.treeId, props.person?.id],
  () => {
    sequence++;
    open.value = false;
    busy.value = false;
    error.value = '';
    books.value = [];
    candidates.value = [];
    searched.value = false;
    links.value = [];
    resetReviews();
  },
);
onBeforeUnmount(() => sequence++);

async function loadLinks(page = 0) {
  const treeId = props.treeId,
    personId = props.person.id;
  const result = await api(
    `/trees/${treeId}/contacts/links?person=${encodeURIComponent(personId)}&page=${page}`,
  );
  if (treeId !== props.treeId || personId !== props.person.id) return;
  links.value = page ? [...links.value, ...result.items] : result.items;
  nextLinkPage.value = result.nextPage;
}

async function toggle() {
  open.value = !open.value;
  if (!open.value) return;
  const seq = ++sequence;
  busy.value = true;
  error.value = '';
  try {
    const result = await api(
      `/trees/${props.treeId}/contacts/books?person=${encodeURIComponent(props.person.id)}`,
    );
    if (seq !== sequence) return;
    if (!result.available) throw new Error('Nextcloud Contacts is not available for this account.');
    books.value = result.items;
    if (!books.value.some((b) => b.key === bookKey.value))
      bookKey.value = books.value.find((b) => !b.system)?.key || books.value[0]?.key || '';
    await loadLinks();
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function search(page = 0) {
  if (query.value.trim().length < 3 || !bookKey.value) {
    error.value = t('Enter at least three characters to search contacts.');
    return;
  }
  const seq = ++sequence,
    treeId = props.treeId,
    personId = props.person.id;
  busy.value = true;
  error.value = '';
  linkReview.value = null;
  try {
    const result = await api(`/trees/${treeId}/contacts/search`, {
      method: 'POST',
      body: { personId, bookKey: bookKey.value, query: query.value.trim(), page },
    });
    if (seq !== sequence || treeId !== props.treeId || personId !== props.person.id) return;
    candidates.value = page ? [...candidates.value, ...result.items] : result.items;
    nextSearchPage.value = result.nextPage;
    searched.value = true;
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function choose(card) {
  const seq = ++sequence;
  busy.value = true;
  error.value = '';
  linkReview.value = null;
  try {
    const result = await api(`/trees/${props.treeId}/contacts/review-link`, {
      method: 'POST',
      body: {
        personId: props.person.id,
        bookKey: card.bookKey,
        cardUid: card.cardUid,
        cardUri: card.cardUri,
      },
    });
    if (seq === sequence) {
      linkReview.value = result;
      linkRequest = requestId();
    }
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function link() {
  if (!linkReview.value) return;
  const seq = sequence;
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/contacts/links`, {
      method: 'POST',
      body: { review: linkReview.value, requestId: linkRequest },
    });
    if (seq !== sequence) return;
    linkReview.value = null;
    linkRequest = requestId();
    await loadLinks();
  } catch (e) {
    if (seq === sequence) {
      error.value = t(e.message);
      if (e.status === 412) linkReview.value = null;
    }
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function unlink(id) {
  if (!confirm(t('Unlink this contact? The card and saved genealogy copies remain.'))) return;
  const seq = sequence;
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/contacts/links/${id}`, { method: 'DELETE' });
    if (seq === sequence) {
      if (copyDraft.value?.linkId === id) copyDraft.value = null;
      await loadLinks();
    }
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function prepareCopy(linkId, kind, index) {
  copyDraft.value = { linkId, kind, index, mode: 'append', replaceId: '' };
  copyCurrent.value = [];
  copyReview.value = null;
  acknowledged.value = false;
  await reviewCopy(true);
}

async function reviewCopy(preparation = false) {
  if (!copyDraft.value) return;
  const draft = { ...copyDraft.value },
    seq = ++sequence;
  busy.value = true;
  error.value = '';
  copyReview.value = null;
  try {
    const result = await api(`/trees/${props.treeId}/contacts/links/${draft.linkId}/review-copy`, {
      method: 'POST',
      body: {
        kind: draft.kind,
        index: draft.index,
        mode: draft.mode,
        replaceId: draft.mode === 'replace' ? draft.replaceId : null,
      },
    });
    if (seq !== sequence) return;
    copyCurrent.value = result.current;
    if (!preparation) {
      copyReview.value = result;
      copyRequest = requestId();
      acknowledged.value = false;
    }
  } catch (e) {
    if (seq === sequence) error.value = t(e.message);
  } finally {
    if (seq === sequence) busy.value = false;
  }
}

async function copy() {
  if (!copyReview.value || !acknowledged.value) return;
  const seq = sequence,
    review = copyReview.value;
  busy.value = true;
  error.value = '';
  try {
    await api(`/trees/${props.treeId}/contacts/links/${review.linkId}/copy`, {
      method: 'POST',
      body: { review, requestId: copyRequest, acknowledged: true },
    });
    if (seq === sequence) {
      copyDraft.value = null;
      copyReview.value = null;
      copyCurrent.value = [];
      acknowledged.value = false;
      emit('copied');
    }
  } catch (e) {
    if (seq === sequence) {
      error.value = t(e.message);
      if (e.status === 412) copyReview.value = null;
    }
  } finally {
    if (seq === sequence) busy.value = false;
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
  <div class="contact-links">
    <button type="button" :aria-expanded="open" @click="toggle">{{ t('Link Nextcloud contact') }}</button>
    <section v-if="open" class="contact-link-workspace" :aria-label="t('Nextcloud contact links')">
      <p>
        {{
          t(
            'Links are private to your account. A contact card and a genealogy person remain separate identities.',
          )
        }}
      </p>
      <p v-if="!management" class="muted">
        {{ t('Only tree managers and owners can copy contact details into confidential genealogy records.') }}
      </p>
      <p v-if="busy" role="status">{{ t('Working…') }}</p>
      <p v-if="error" role="alert" class="feedback error">{{ error }}</p>
      <p v-if="!books.length && !busy && !error" class="muted">
        {{ t('No readable address books are available.') }}
      </p>
      <form v-if="books.length" class="contact-search" @submit.prevent="search()">
        <label
          >{{ t('Address book') }}
          <select
            v-model="bookKey"
            :disabled="busy"
            @change="
              candidates = [];
              searched = false;
              nextSearchPage = null;
              linkReview = null;
            "
          >
            <option v-for="book in books" :key="book.key" :value="book.key">
              {{ book.name }}{{ book.system ? ` · ${t('System')}` : book.shared ? ` · ${t('Shared')}` : '' }}
            </option>
          </select>
        </label>
        <label
          >{{ t('Find contact') }}
          <input
            v-model="query"
            type="search"
            minlength="3"
            maxlength="100"
            :disabled="busy"
            @input="
              linkReview = null;
              candidates = [];
              searched = false;
              nextSearchPage = null;
            "
          />
        </label>
        <button type="submit" :disabled="busy || query.trim().length < 3">{{ t('Search contacts') }}</button>
      </form>
      <p v-if="selectedBook?.readOnly" class="muted">
        {{ t('This address book is read-only; linking still works.') }}
      </p>
      <ul v-if="candidates.length" class="contact-candidates">
        <li v-for="card in candidates" :key="`${card.bookKey}:${card.cardUid}:${card.cardUri}`">
          <strong>{{ card.name }}</strong>
          <small>{{ card.fields.email[0]?.display || card.fields.phone[0]?.display || card.cardUid }}</small>
          <button type="button" :disabled="busy" @click="choose(card)">{{ t('Review link') }}</button>
        </li>
      </ul>
      <p v-if="!busy && searched && !candidates.length && nextSearchPage === null" class="muted">
        {{ t('No matching contacts on this page.') }}
      </p>
      <button v-if="nextSearchPage !== null" type="button" :disabled="busy" @click="search(nextSearchPage)">
        {{ t('Load more') }}
      </button>
      <div v-if="linkReview" class="contact-copy-review">
        <h3>{{ t('Review private link') }}</h3>
        <p>
          <strong>{{ linkReview.card.name }}</strong> · {{ linkReview.card.bookName }}
        </p>
        <p>{{ t('No contact details are copied into the tree by linking.') }}</p>
        <div class="button-row">
          <button type="button" :disabled="busy" @click="link">{{ t('Link contact') }}</button>
          <button type="button" :disabled="busy" @click="linkReview = null">{{ t('Cancel') }}</button>
        </div>
      </div>
      <div class="contact-linked">
        <div class="button-row">
          <h3>{{ t('My linked contacts') }}</h3>
          <button type="button" :disabled="busy" @click="refresh">{{ t('Refresh') }}</button>
        </div>
        <p v-if="!links.length" class="muted">{{ t('No contacts linked yet.') }}</p>
        <article v-for="linkItem in links" :key="linkItem.id" class="contact-linked-card">
          <template v-if="linkItem.card">
            <strong>{{ linkItem.card.name }}</strong>
            <small>{{ linkItem.card.bookName }}</small>
            <p v-if="linkItem.status === 'changed'" class="muted">
              {{ t('The contact changed. Review its current fields before copying.') }}
            </p>
            <div v-for="kind in ['email', 'phone', 'address']" :key="kind">
              <div
                v-for="(entry, index) in linkItem.card.fields[kind]"
                :key="`${kind}:${index}`"
                class="contact-field-row"
              >
                <span
                  ><strong>{{ t(kind) }}</strong
                  ><small v-if="entry.label">{{ entry.label }}</small
                  ><br />{{ entry.display }}</span
                >
                <button
                  v-if="management"
                  type="button"
                  :disabled="busy"
                  @click="prepareCopy(linkItem.id, kind, index)"
                >
                  {{ t('Compare and copy') }}
                </button>
              </div>
            </div>
          </template>
          <p v-else>{{ t('This card is unavailable. Its saved genealogy copies remain.') }}</p>
          <button type="button" :disabled="busy" @click="unlink(linkItem.id)">
            {{ t('Unlink contact') }}
          </button>
        </article>
        <button v-if="nextLinkPage !== null" type="button" :disabled="busy" @click="loadLinks(nextLinkPage)">
          {{ t('Load more') }}
        </button>
      </div>
      <div v-if="copyDraft" class="contact-copy-review">
        <h3>{{ t('Compare contact field') }}</h3>
        <p>{{ t('Current saved genealogy values') }}</p>
        <ul v-if="replaceChoices.length">
          <li v-for="r in replaceChoices" :key="r.id">{{ r.title }} · {{ r.value }}</li>
        </ul>
        <p v-else class="muted">{{ t('No saved value of this type yet.') }}</p>
        <label
          >{{ t('Copy action') }}
          <select
            v-model="copyDraft.mode"
            :disabled="busy"
            @change="
              copyReview = null;
              acknowledged = false;
            "
          >
            <option value="append">{{ t('Add new copy') }}</option>
            <option v-if="replaceChoices.length" value="replace">
              {{ t('Replace saved copy') }}
            </option>
          </select>
        </label>
        <label v-if="copyDraft.mode === 'replace'"
          >{{ t('Record to replace') }}
          <select
            v-model="copyDraft.replaceId"
            :disabled="busy"
            @change="
              copyReview = null;
              acknowledged = false;
            "
          >
            <option value="">{{ t('Choose a record') }}</option>
            <option v-for="r in replaceChoices" :key="r.id" :value="r.id">
              {{ r.title }} · {{ r.value }}
            </option>
          </select>
        </label>
        <button
          type="button"
          :disabled="busy || (copyDraft.mode === 'replace' && !copyDraft.replaceId)"
          @click="reviewCopy()"
        >
          {{ t('Review copy') }}
        </button>
        <div v-if="copyReview" class="contact-copy-confirm">
          <h4>{{ t('Review genealogy copy') }}</h4>
          <p>
            {{ t('Current card value') }}: <strong>{{ copyReview.proposed.display }}</strong>
          </p>
          <p v-if="copyReview.mode === 'replace'">
            {{ t('Saved value to replace') }}:
            {{ copyReview.current.find((r) => r.id === copyReview.replaceId)?.value }}
          </p>
          <p>{{ t(copyReview.retentionWarning) }}</p>
          <label class="check"
            ><input v-model="acknowledged" type="checkbox" />{{
              t('I understand this confidential copy is retained independently')
            }}</label
          >
          <button type="button" :disabled="busy || !acknowledged" @click="copy">
            {{ t('Copy into genealogy') }}
          </button>
        </div>
        <button
          type="button"
          :disabled="busy"
          @click="
            copyDraft = null;
            copyReview = null;
          "
        >
          {{ t('Cancel') }}
        </button>
      </div>
      <a :href="contactsUrl" target="_blank" rel="noopener noreferrer">{{ t('Open Contacts') }}</a>
    </section>
  </div>
</template>

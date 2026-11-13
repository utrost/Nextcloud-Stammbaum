<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { canNavigate, useDraftGuard } from './draftGuard.js';
import { api, userId, apiBase } from './api.js';
import { t, title, roles, locale } from './i18n.js';
import { personName, dateText, requestId } from './domain.js';
import PersonEditor from './components/PersonEditor.vue';
import FamilyGraph from './components/FamilyGraph.vue';
import EvidencePanel from './components/EvidencePanel.vue';
import RecordPanel from './components/RecordPanel.vue';
import MediaPanel from './components/MediaPanel.vue';
import ExportPanel from './components/ExportPanel.vue';
import ImportPanel from './components/ImportPanel.vue';
import ContextPanel from './components/ContextPanel.vue';
import TabList from './components/TabList.vue';
import ResearchTaskPanel from './components/ResearchTaskPanel.vue';
import CalendarPanel from './components/CalendarPanel.vue';
import ContactLinkPanel from './components/ContactLinkPanel.vue';
import IntegrationConnections from './components/IntegrationConnections.vue';
const contactCopyCounter = ref(0);
const savedViews = ref({ revision: 1, items: [], home: null, openingView: null }),
  preferenceDraft = ref(null),
  preferenceCurrent = ref(null),
  preferenceError = ref(''),
  preferenceFailure = ref(null),
  preferenceBusy = ref(false),
  viewLabel = ref(''),
  viewChoice = ref(''),
  localeRevision = ref(null);
let localeQueue = Promise.resolve();
watch(locale, (value) => {
  if (localeRevision.value === null) return;
  localeQueue = localeQueue.then(async () => {
    try {
      const result = await api('/preferences', {
        method: 'PUT',
        body: { locale: value, revision: localeRevision.value },
      });
      localeRevision.value = result.revision;
    } catch (e) {
      notice.value = t('Language changed here, but the account preference could not be saved.');
    }
  });
});
const trees = ref([]),
  tree = ref(null),
  tab = ref('family'),
  loading = ref(true),
  busy = ref(false),
  error = ref(''),
  treeLoadFailure = ref(null),
  notice = ref(''),
  navOpen = ref(false);
const linkedSourceId = ref(''),
  linkedMediumId = ref(''),
  linkedEventId = ref('');
const portrait = ref(null),
  people = ref([]),
  nextPage = ref(null),
  query = ref(''),
  peopleLoading = ref(false),
  peopleError = ref(''),
  selected = ref(null),
  detailLoading = ref(false),
  relations = ref([]),
  claims = ref([]),
  history = ref([]),
  historyPage = ref(null),
  detailTab = ref('connections'),
  researchExpanded = ref(false),
  researchToggle = ref(null);
const graph = ref({ people: [], relations: [], limited: false, focusId: null }),
  graphLoading = ref(false),
  graphError = ref(''),
  focus = ref(''),
  expansions = ref([]),
  graphTextView = ref(false),
  mode = ref('family'),
  up = ref(3),
  down = ref(2),
  camera = ref({ x: 20, y: 20, zoom: 1 }),
  cameraReady = ref(false),
  branches = ref([]);
const editor = ref(null),
  treeDialog = ref(null),
  treeDialogOpen = ref(false),
  treeName = ref(''),
  treeDescription = ref(''),
  treeRequest = ref(requestId());
const invitations = ref([]),
  members = ref([]),
  trash = ref([]),
  memberId = ref(''),
  memberRole = ref('viewer'),
  memberExport = ref(false),
  inviteRole = ref('viewer'),
  inviteExport = ref(false),
  inviteUrl = ref(''),
  inviteLink = ref(null);
const editTreeName = ref(''),
  editTreeDescription = ref(''),
  inviteToken = ref(''),
  invitation = ref(null);
let startupSequence = 0;
let searchTimer,
  peopleSequence = 0,
  detailSequence = 0,
  graphSequence = 0,
  treeSequence = 0;
const name = (p) => personName(p, t('Unnamed person'));
const management = computed(() => ['manager', 'owner'].includes(tree.value?.role));
const canEdit = computed(() => ['editor', 'manager', 'owner'].includes(tree.value?.role));
const expandableSelection = computed(
  () =>
    !graphError.value &&
    selected.value &&
    graph.value.boundaryIds?.includes(selected.value.id) &&
    !expansions.value.includes(selected.value.id),
);
const homeId = computed(() => savedViews.value.home?.id || tree.value?.homeId || null);
const selectedOnBranch = computed(() => graph.value.people.some((p) => p.id === selected.value?.id));
const roleOptions = computed(() => (tree.value?.role === 'owner' ? roles : ['viewer', 'editor']));
const mainTabs = computed(() => [
  'family',
  'people',
  'sources',
  'media',
  'connections',
  ...(tree.value?.canExport ? ['exports'] : []),
  ...(management.value ? ['imports', 'manage'] : []),
]);
const detailTabs = [
  'connections',
  'timeline',
  'notes',
  'details',
  'assertions',
  'evidence',
  'media',
  'history',
];
const newTreeDirty = computed(() => treeDialogOpen.value && !!(treeName.value || treeDescription.value));
const treeDetailsDirty = computed(
  () =>
    tree.value &&
    tab.value === 'manage' &&
    (editTreeName.value !== tree.value.name || editTreeDescription.value !== tree.value.description),
);
useDraftGuard(
  computed(() => newTreeDirty.value || treeDetailsDirty.value),
  computed(() => busy.value && (treeDialogOpen.value || tab.value === 'manage')),
);
useDraftGuard(
  computed(() => preferenceDraft.value !== null),
  preferenceBusy,
);
function setCamera(value) {
  camera.value = value;
  cameraReady.value = true;
}
function resetTreeDrafts() {
  preferenceDraft.value = null;
  preferenceCurrent.value = null;
  preferenceError.value = '';
  if (tree.value) {
    editTreeName.value = tree.value.name;
    editTreeDescription.value = tree.value.description;
  }
}
function navigate() {
  if (!canNavigate()) return false;
  resetTreeDrafts();
  return true;
}
function treeUnload(event) {
  if (
    newTreeDirty.value ||
    treeDetailsDirty.value ||
    preferenceDraft.value ||
    preferenceBusy.value ||
    (treeDialogOpen.value && busy.value)
  ) {
    event.preventDefault();
    event.returnValue = '';
  }
}
function closeTreeDialog() {
  if (busy.value || (newTreeDirty.value && !confirm(t('Discard unsaved changes?')))) return;
  treeDialogOpen.value = false;
  treeDialog.value.close();
}
function changeDetailTab(value) {
  if (value !== detailTab.value && navigate()) detailTab.value = value;
}
const datetime = (x) =>
  new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(x * 1000),
  );
const claimName = (k) =>
  t(
    {
      name: 'Name assertion',
      birth_date: 'Birth date',
      death_date: 'Death date',
      birth_place: 'Birth place',
      death_place: 'Death place',
    }[k] || k,
  );
const claimValue = (c) =>
  c.kind === 'name' ? name(c.value) : c.kind.endsWith('_date') ? dateText(c.value) : c.value;
const orderedRelations = computed(() => {
  const rank = (r) => (r.kind === 'partner' ? 1 : r.sourceId === selected.value?.id ? 2 : 0);
  return [...relations.value].sort(
    (a, b) => rank(a) - rank(b) || name(a.person).localeCompare(name(b.person)) || a.id.localeCompare(b.id),
  );
});
const orderedClaims = computed(() => {
  const kinds = ['name', 'birth_date', 'birth_place', 'death_date', 'death_place'];
  return [...claims.value].sort(
    (a, b) =>
      kinds.indexOf(a.kind) - kinds.indexOf(b.kind) ||
      Number(b.preferred) - Number(a.preferred) ||
      String(claimValue(a)).localeCompare(String(claimValue(b))) ||
      a.id.localeCompare(b.id),
  );
});
const actionName = (a) =>
  t(
    {
      person_created: 'Person created',
      person_imported: 'Person imported from GEDCOM',
      person_updated: 'Person updated',
      person_restored: 'Person restored',
      person_deleted: 'Person deleted',
      assertion_assessed: 'Assertion assessed',
      connection_created: 'Connection created',
      connection_removed: 'Connection removed',
    }[a] || a,
  );
async function action(fn, message = '') {
  busy.value = true;
  error.value = '';
  try {
    await fn();
    if (message) notice.value = t(message);
  } catch (e) {
    error.value = t(e.message);
  } finally {
    busy.value = false;
  }
}
function urlState() {
  if (!tree.value) return;
  const p = new URLSearchParams({ tree: tree.value.id, view: tab.value });
  if (focus.value) p.set('focus', focus.value);
  if (selected.value) p.set('person', selected.value.id);
  window.history.replaceState(null, '', '#' + p);
}
async function refreshTrees() {
  const r = await api('/trees');
  trees.value = r.items;
}
async function importsChanged() {
  const id = tree.value?.id;
  try {
    const current = await api(`/trees/${id}`);
    if (tree.value?.id !== id) return;
    tree.value = current;
    await loadPeople();
    if (tree.value?.id !== id) return;
    if (!focus.value) focus.value = people.value[0]?.id || '';
    if (focus.value) await loadGraph();
    await refreshTrees();
  } catch (e) {
    if (tree.value?.id === id) error.value = t(e.message);
  }
}
async function loadPeople(page = 0) {
  const seq = ++peopleSequence;
  peopleLoading.value = true;
  peopleError.value = '';
  const id = tree.value.id;
  try {
    const r = await api(`/trees/${id}/people?query=${encodeURIComponent(query.value)}&page=${page}`);
    if (seq !== peopleSequence || tree.value?.id !== id) return;
    people.value = page ? [...people.value, ...r.items] : r.items;
    nextPage.value = r.nextPage;
  } catch (e) {
    if (seq === peopleSequence) peopleError.value = t(e.message);
  } finally {
    if (seq === peopleSequence) peopleLoading.value = false;
  }
}
function search() {
  clearTimeout(searchTimer);
  peopleSequence++;
  peopleLoading.value = true;
  searchTimer = setTimeout(() => loadPeople(), 250);
}
async function chooseTree(id, initial = {}, authorized = false) {
  if (!authorized && !navigate()) return;
  const seq = ++treeSequence;
  treeLoadFailure.value = null;
  savedViews.value = { revision: 1, items: [], home: null, openingView: null };
  viewChoice.value = '';
  tree.value = null;
  researchExpanded.value = false;
  selected.value = null;
  linkedSourceId.value = '';
  linkedMediumId.value = '';
  linkedEventId.value = '';
  focus.value = '';
  camera.value = { x: 20, y: 20, zoom: 1 };
  cameraReady.value = false;
  branches.value = [];
  expansions.value = [];
  graphTextView.value = false;
  people.value = [];
  query.value = '';
  graph.value = { people: [], relations: [], limited: false, focusId: null };
  error.value = '';
  notice.value = '';
  navOpen.value = false;
  loading.value = true;
  peopleSequence++;
  detailSequence++;
  graphSequence++;
  try {
    const r = await api(`/trees/${id}`);
    if (seq !== treeSequence) return;
    tree.value = r;
    editTreeName.value = r.name;
    editTreeDescription.value = r.description;
    tab.value = [
      'family',
      'people',
      'sources',
      'media',
      'connections',
      'imports',
      'exports',
      'manage',
    ].includes(initial.view)
      ? initial.view
      : 'family';
    if (['manage', 'imports'].includes(tab.value) && !management.value) tab.value = 'family';
    const validTarget = (value) => typeof value === 'string' && /^[0-9a-f-]{36}$/.test(value);
    linkedSourceId.value = tab.value === 'sources' && validTarget(initial.source) ? initial.source : '';
    linkedMediumId.value = tab.value === 'media' && validTarget(initial.medium) ? initial.medium : '';
    await loadPeople();
    if (seq !== treeSequence) return;
    if (peopleError.value) throw new Error(peopleError.value);
    const preferences = await api(`/trees/${id}/views`);
    if (seq !== treeSequence) return;
    savedViews.value = preferences;
    const opening =
      !initial.focus || initial.focus === savedViews.value.openingView?.focus
        ? savedViews.value.openingView
        : null;
    mode.value = opening?.mode || 'family';
    up.value = opening?.up ?? 3;
    down.value = opening?.down ?? 2;
    if (opening) setCamera({ ...opening.camera });
    focus.value =
      initial.focus || opening?.focus || savedViews.value.home?.id || r.homeId || people.value[0]?.id || '';
    if (initial.person || opening?.selected) await inspect(initial.person || opening.selected);
    if (seq !== treeSequence) return;
    linkedEventId.value =
      tab.value === 'people' && selected.value && validTarget(initial.event) ? initial.event : '';
    if (linkedEventId.value) detailTab.value = 'timeline';
    if (focus.value) await loadGraph();
    if (seq !== treeSequence) return;
    if (tab.value === 'manage') await loadManage();
    if (seq !== treeSequence) return;
    urlState();
  } catch (e) {
    if (seq === treeSequence) {
      error.value = t(e.message);
      treeLoadFailure.value = { id, initial };
      tree.value = null;
    }
  } finally {
    if (seq === treeSequence) loading.value = false;
  }
}
function retryError() {
  const failed = treeLoadFailure.value;
  error.value = '';
  if (failed) chooseTree(failed.id, failed.initial);
  else if (!tree.value) startup();
}
async function loadGraph() {
  const seq = ++graphSequence;
  graphLoading.value = true;
  graphError.value = '';
  const id = tree.value.id;
  try {
    const expansionQuery = expansions.value.map((id) => '&expand[]=' + encodeURIComponent(id)).join('');
    const r = await api(
      `/trees/${id}/graph?focus=${encodeURIComponent(focus.value)}&mode=${mode.value}&up=${up.value}&down=${down.value}${expansionQuery}`,
    );
    if (seq === graphSequence && tree.value?.id === id) {
      graph.value = r;
      return true;
    }
  } catch (e) {
    if (seq === graphSequence) {
      graphError.value = t(e.message);
      graph.value = { people: [], relations: [], limited: false, focusId: null };
    }
  } finally {
    if (seq === graphSequence) graphLoading.value = false;
  }
}
async function expandRelatives() {
  if (!expandableSelection.value || graphLoading.value || expansions.value.length >= 20 || !navigate())
    return;
  const id = selected.value.id;
  const tid = tree.value.id;
  expansions.value = [...expansions.value, id];
  const loaded = await loadGraph();
  if (tree.value?.id !== tid || selected.value?.id !== id) return;
  await nextTick();
  const target = loaded
    ? document.querySelector(`.graph-shell [data-person-id="${id}"]`)
    : document.querySelector('.graph-error button');
  target?.focus({ preventScroll: true });
}
async function resetExpansions() {
  if (graphLoading.value || !navigate()) return;
  expansions.value = [];
  await loadGraph();
}
function changeProjection() {
  expansions.value = [];
  return loadGraph();
}
async function openMediaPerson(id) {
  if (!navigate()) return;
  tab.value = 'people';
  await inspect(id);
}
async function selectPerson(id) {
  if (selected.value?.id === id || !navigate()) return;
  await inspect(id);
}
async function inspect(id) {
  linkedEventId.value = '';
  const seq = ++detailSequence;
  detailLoading.value = true;
  error.value = '';
  const tid = tree.value.id;
  selected.value = null;
  portrait.value = null;
  relations.value = [];
  claims.value = [];
  history.value = [];
  try {
    const [p, r, c, h, media] = await Promise.all([
      api(`/trees/${tid}/people/${id}`),
      api(`/trees/${tid}/people/${id}/relations`),
      api(`/trees/${tid}/people/${id}/claims`),
      api(`/trees/${tid}/people/${id}/history`),
      api(`/trees/${tid}/media?person=${id}`),
    ]);
    if (seq !== detailSequence || tree.value?.id !== tid) return;
    selected.value = p;
    portrait.value =
      media.items.find((m) => m.assignments.some((a) => a.personId === id && a.preferred)) || null;
    relations.value = r.items;
    claims.value = c.items;
    history.value = h.items;
    historyPage.value = h.nextPage;
    detailTab.value = 'connections';
    urlState();
  } catch (e) {
    if (seq === detailSequence) error.value = t(e.message);
  } finally {
    if (seq === detailSequence) detailLoading.value = false;
  }
}
async function toggleResearch() {
  // The current panel and diagram stay mounted: drafts and camera are unchanged.
  researchExpanded.value = !researchExpanded.value;
  await nextTick();
  researchToggle.value?.focus();
}
async function mediaChanged() {
  if (!selected.value) return;
  const id = selected.value.id;
  const r = await api(`/trees/${tree.value.id}/media?person=${id}`);
  if (selected.value?.id === id)
    portrait.value = r.items.find((m) => m.assignments.some((a) => a.personId === id && a.preferred)) || null;
}
async function dismissDetails() {
  if (!navigate()) return;
  const id = selected.value?.id;
  closeDetails();
  await nextTick();
  const target = [...document.querySelectorAll('[data-person-id]')].find(
    (element) => element.dataset.personId === id,
  );
  (target || document.querySelector('.graph-viewport, .people-toolbar input'))?.focus();
}
function closeDetails() {
  researchExpanded.value = false;
  detailSequence++;
  selected.value = null;
  detailLoading.value = false;
  urlState();
}
async function branch(id, view = null) {
  if (!navigate()) return;
  researchExpanded.value = false;
  if (focus.value) {
    branches.value = [
      ...branches.value.slice(-29),
      {
        focus: focus.value,
        selected: selected.value?.id,
        mode: mode.value,
        up: up.value,
        down: down.value,
        camera: { ...camera.value },
        expansions: [...expansions.value],
        textView: graphTextView.value,
      },
    ];
  }
  focus.value = id;
  expansions.value = [];
  if (view) {
    mode.value = view.mode;
    up.value = view.up;
    down.value = view.down;
  }
  tab.value = 'family';
  await loadGraph();
  urlState();
}
async function previous() {
  if (!navigate()) return;
  const prior = branches.value.pop();
  if (!prior) return;
  focus.value = prior.focus;
  mode.value = prior.mode;
  up.value = prior.up;
  down.value = prior.down;
  expansions.value = [...(prior.expansions || [])];
  graphTextView.value = prior.textView ?? false;
  await loadGraph();
  if (prior.selected) await inspect(prior.selected);
  else closeDetails();
  await nextTick();
  camera.value = prior.camera;
  urlState();
}
async function changeTab(value) {
  if (value === tab.value || !navigate()) return;
  researchExpanded.value = false;
  linkedSourceId.value = '';
  linkedMediumId.value = '';
  linkedEventId.value = '';
  tab.value = value;
  urlState();
  if (value === 'manage') await action(loadManage);
}
function createTree() {
  if (!navigate()) return;
  treeDialogOpen.value = true;
  treeName.value = '';
  treeDescription.value = '';
  treeRequest.value = requestId();
  treeDialog.value.showModal();
}
async function saveTree() {
  await action(async () => {
    const r = await api('/trees', {
      method: 'POST',
      body: { name: treeName.value, description: treeDescription.value, requestId: treeRequest.value },
    });
    treeDialogOpen.value = false;
    treeDialog.value.close();
    await refreshTrees();
    await chooseTree(r.id, {}, true);
  }, 'Tree created');
}
async function updated({ person, relative, personId }) {
  editor.value = null;
  const selectedId = person?.id || personId;
  await action(
    async () => {
      tree.value = await api(`/trees/${tree.value.id}`);
      await refreshTrees();
      await loadPeople();
      if (!focus.value) focus.value = selectedId;
      await loadGraph();
      await inspect(selectedId);
    },
    relative ? 'Connection created' : 'Person saved',
  );
}
async function loadManage() {
  const id = tree.value.id;
  const [m, d, i] = await Promise.all([
    api(`/trees/${id}/members`),
    api(`/trees/${id}/people?deleted=true`),
    api(`/trees/${id}/invitations`),
  ]);
  if (tree.value?.id !== id) return;
  members.value = m.items;
  trash.value = d.items;
  invitations.value = i.items;
}
async function saveTreeDetails(home = tree.value.homeId) {
  await action(async () => {
    tree.value = await api(`/trees/${tree.value.id}`, {
      method: 'PUT',
      body: {
        name: editTreeName.value,
        description: editTreeDescription.value,
        homeId: home,
        revision: tree.value.revision,
      },
    });
    resetTreeDrafts();
    await refreshTrees();
  }, 'Saved');
}
async function memberSave(m, remove = false) {
  if (remove && !confirm(t('Remove this membership?'))) return;
  await action(async () => {
    await api(`/trees/${tree.value.id}/members`, {
      method: 'POST',
      body: { userId: m.userId, role: m.role, revision: m.revision, exportAllowed: m.exportAllowed, remove },
    });
    await refreshTrees();
    if (remove && m.userId === userId) {
      tree.value = null;
      selected.value = null;
      window.history.replaceState(null, '', location.pathname);
      return;
    }
    tree.value = await api(`/trees/${tree.value.id}`);
    await loadManage();
  }, 'Member updated');
}
async function addMember() {
  const existing = members.value.find((m) => m.userId === memberId.value);
  await memberSave({
    userId: memberId.value,
    role: memberRole.value,
    revision: existing?.revision ?? 0,
    exportAllowed: memberExport.value,
  });
  if (!error.value) memberId.value = '';
}
async function createInvite() {
  await action(async () => {
    const r = await api(`/trees/${tree.value.id}/invitations`, {
      method: 'POST',
      body: { role: inviteRole.value, exportAllowed: inviteExport.value },
    });
    inviteUrl.value = location.origin + location.pathname + '#invite=' + r.token;
    await loadManage();
  });
}
async function revokeInvite(i) {
  if (!confirm(t('Revoke this invitation?'))) return;
  await action(async () => {
    await api(`/trees/${tree.value.id}/invitations/${i.id}/revoke`, { method: 'POST', body: {} });
    inviteUrl.value = '';
    await loadManage();
  }, 'Invitation revoked');
}
async function copyInvite() {
  try {
    await navigator.clipboard.writeText(inviteUrl.value);
    notice.value = t('Copied');
  } catch {
    inviteLink.value.focus();
    inviteLink.value.select();
  }
}
async function acceptInvite() {
  await action(async () => {
    const r = await api('/invitations/accept', { method: 'POST', body: { token: inviteToken.value } });
    invitation.value = null;
    inviteToken.value = '';
    await refreshTrees();
    await chooseTree(r.id, {}, true);
  });
}
async function removePerson() {
  await action(async () => {
    const id = selected.value.id;
    const impact = await api(`/trees/${tree.value.id}/people/${id}/deletion-impact`);
    const summary = [
      t('Connections') + ': ' + impact.connections,
      t('Family contexts') + ': ' + impact.contexts,
      t('Linked records') + ': ' + impact.records,
      t('Media') + ': ' + impact.media,
    ].join(' · ');
    if (
      !confirm(
        name(selected.value) +
          '\n' +
          summary +
          '\n\n' +
          t(
            'Delete this person from active use? Their connections will be deactivated. History is retained; this is not permanent erasure.',
          ) +
          '\n' +
          t('Shared records linked to this person become unavailable until the person is restored.'),
      )
    )
      return;
    await api(`/trees/${tree.value.id}/people/${id}/delete`, {
      method: 'POST',
      body: { revision: impact.revision, treeRevision: impact.treeRevision, confirmed: true },
    });
    closeDetails();
    await loadPeople();
    if (focus.value === id) {
      focus.value = people.value[0]?.id || '';
      expansions.value = [];
    }
    await loadGraph();
    tree.value = await api(`/trees/${tree.value.id}`);
    notice.value = t('Person deleted');
  });
}
async function restorePerson(p) {
  if (!confirm(t('Restore this person? Former connections will not return automatically.'))) return;
  await action(async () => {
    await api(`/trees/${tree.value.id}/people/${p.id}/restore`, {
      method: 'POST',
      body: { revision: p.revision, confirmed: true },
    });
    await loadManage();
    await loadPeople();
  }, 'Person restored');
}
async function removeConnection(r) {
  if (!confirm(t('Remove this connection? Both people remain. This does not record a divorce or death.')))
    return;
  await action(async () => {
    await api(`/trees/${tree.value.id}/relations/${r.id}/remove`, {
      method: 'POST',
      body: { revision: r.revision },
    });
    await inspect(selected.value.id);
    await loadGraph();
  }, 'Saved');
}
async function claimAction(c, kind, assessment) {
  const id = selected.value.id;
  await action(async () => {
    await api(`/trees/${tree.value.id}/people/${id}/claims/${kind}`, {
      method: 'POST',
      body: { claimId: c.id, revision: selected.value.revision, ...(assessment ? { assessment } : {}) },
    });
    await inspect(id);
    detailTab.value = 'assertions';
    await loadPeople();
    await loadGraph();
  }, 'Saved');
}
async function moreHistory() {
  const id = selected.value.id;
  await action(async () => {
    const r = await api(`/trees/${tree.value.id}/people/${id}/history?page=${historyPage.value}`);
    if (selected.value?.id === id) {
      history.value.push(...r.items);
      historyPage.value = r.nextPage;
    }
  });
}
async function savePreference(draft) {
  if (preferenceBusy.value) return;
  preferenceDraft.value = draft;
  preferenceError.value = '';
  preferenceFailure.value = null;
  preferenceCurrent.value = null;
  preferenceBusy.value = true;
  const tid = tree.value.id;
  try {
    const result = await api(`/trees/${tid}/views${draft.suffix}`, {
      method: draft.method,
      body: draft.body,
    });
    if (tree.value?.id !== tid) return;
    savedViews.value = result;
    if (draft.kind === 'view') viewLabel.value = '';
    if (draft.kind === 'remove') viewChoice.value = '';
    preferenceDraft.value = null;
    preferenceCurrent.value = null;
    notice.value = t('Personal navigation preference saved');
  } catch (e) {
    if (tree.value?.id === tid) {
      preferenceError.value = t(e.message);
      preferenceFailure.value = e.status || 0;
    }
  } finally {
    preferenceBusy.value = false;
  }
}
function saveNamedView() {
  if (preferenceDraft.value || preferenceBusy.value) return;
  savePreference({
    kind: 'view',
    label: viewLabel.value,
    suffix: '',
    method: 'POST',
    body: {
      revision: savedViews.value.revision,
      requestId: requestId(),
      label: viewLabel.value,
      focus: focus.value,
      selected: selected.value?.id || '',
      mode: mode.value,
      up: up.value,
      down: down.value,
      camera: { ...camera.value },
    },
  });
}
function savePersonalHome(person) {
  if (preferenceDraft.value || preferenceBusy.value) return;
  savePreference({
    kind: 'home',
    label: person ? name(person) : t('Follow shared home'),
    suffix: '/home',
    method: 'PUT',
    body: {
      revision: savedViews.value.revision,
      homeId: person?.id || null,
    },
  });
}
async function comparePreferences() {
  if (preferenceBusy.value) return;
  preferenceBusy.value = true;
  try {
    preferenceCurrent.value = await api(`/trees/${tree.value.id}/views`);
  } catch (e) {
    preferenceError.value = t(e.message);
  } finally {
    preferenceBusy.value = false;
  }
}
function applyPreference() {
  const draft = preferenceDraft.value;
  if (!draft || !preferenceCurrent.value) return;
  const body = { ...draft.body, revision: preferenceCurrent.value.revision };
  if (draft.kind === 'view') body.requestId = requestId();
  preferenceCurrent.value = null;
  savePreference({ ...draft, body });
}
function discardPreference() {
  if (preferenceBusy.value) return;
  if (preferenceCurrent.value) savedViews.value = preferenceCurrent.value;
  preferenceDraft.value = null;
  preferenceCurrent.value = null;
  preferenceError.value = '';
}
async function openNamedView() {
  if (!navigate()) return;
  const v = savedViews.value.items.find((v) => v.id === viewChoice.value);
  if (!v) return;
  await branch(v.focus, v);
  if (v.selected) await inspect(v.selected);
  else closeDetails();
  await nextTick();
  camera.value = { ...v.camera };
}
function deleteNamedView() {
  const view = savedViews.value.items.find((v) => v.id === viewChoice.value);
  if (!view || preferenceDraft.value || preferenceBusy.value) return;
  savePreference({
    kind: 'remove',
    label: view.label,
    suffix: `/${view.id}`,
    method: 'DELETE',
    body: { revision: savedViews.value.revision },
  });
}
async function startup(authorized = false) {
  const startupRequest = ++startupSequence;
  // Invalidate older tree loads before waiting for the account/tree list.
  treeSequence++;
  loading.value = true;
  error.value = '';
  try {
    if (localeRevision.value === null) {
      const pref = await api('/preferences');
      if (startupRequest !== startupSequence) return;
      localeRevision.value = pref.revision;
    }
    const available = await api('/trees');
    if (startupRequest !== startupSequence) return;
    trees.value = available.items;
    const hash = new URLSearchParams(location.hash.slice(1));
    if (hash.has('invite')) {
      inviteToken.value = hash.get('invite');
      window.history.replaceState(null, '', location.pathname);
      const result = await api('/invitations/review', { method: 'POST', body: { token: inviteToken.value } });
      if (startupRequest === startupSequence) invitation.value = result;
    } else if (hash.has('tree')) {
      await chooseTree(hash.get('tree'), Object.fromEntries(hash), authorized);
    }
  } catch (e) {
    if (startupRequest === startupSequence) error.value = t(e.message);
  } finally {
    if (startupRequest === startupSequence) loading.value = false;
  }
}
async function routeChanged() {
  if (!navigate()) {
    urlState();
    return;
  }
  editor.value = null;
  treeDialogOpen.value = false;
  treeDialog.value?.close();
  await nextTick();
  startup(true);
}
onMounted(() => {
  startup();
  window.addEventListener('hashchange', routeChanged);
  window.addEventListener('beforeunload', treeUnload);
});
onBeforeUnmount(() => {
  startupSequence++;
  window.removeEventListener('hashchange', routeChanged);
  window.removeEventListener('beforeunload', treeUnload);
  clearTimeout(searchTimer);
  peopleSequence++;
  detailSequence++;
  graphSequence++;
  treeSequence++;
});
</script>
<template>
  <div class="stb-shell">
    <nav class="stb-nav" :class="{ open: navOpen }" :aria-label="t('Navigation')">
      <div class="brand">
        <svg viewBox="0 0 32 32" aria-hidden="true">
          <path d="M16 27V14M6 6v8h20V6M6 23v-4h20v4" />
          <circle cx="6" cy="5" r="3" />
          <circle cx="26" cy="5" r="3" />
          <circle cx="6" cy="26" r="3" />
          <circle cx="26" cy="26" r="3" />
        </svg>
        <div>
          <strong>Stammbaum</strong><small>{{ t('Research workspace') }}</small>
        </div>
      </div>
      <div class="nav-heading">{{ t('Your trees') }}</div>
      <button
        v-for="tr in trees"
        :key="tr.id"
        class="tree-nav-item"
        :class="{ active: tree?.id === tr.id }"
        :aria-current="tree?.id === tr.id ? 'page' : undefined"
        @click="chooseTree(tr.id)"
      >
        <span class="tree-dot" aria-hidden="true">⌘</span
        ><span
          ><strong>{{ tr.name }}</strong
          ><small>{{ t(title(tr.role)) }} · {{ tr.id.slice(0, 6) }}</small></span
        >
      </button>
      <button class="new-tree-button" @click="createTree">+ {{ t('New tree') }}</button>
      <div class="nav-bottom">
        <span class="badge">0.1 · {{ t('Early version') }}</span>
        <p>{{ t('Only invited members can see a tree.') }}</p>
        <label class="language-label"
          ><span class="sr-only">Language</span
          ><select v-model="locale" aria-label="Language">
            <option value="de">Deutsch</option>
            <option value="en">English</option>
          </select></label
        >
      </div>
    </nav>
    <main class="stb-main">
      <button class="mobile-nav" :aria-label="t('Open navigation')" @click="navOpen = !navOpen">
        ☰ Stammbaum
      </button>
      <div v-if="error" class="feedback error" role="alert">
        {{ error }}
        <button @click="retryError">
          {{ t('Retry') }}
        </button>
      </div>
      <div v-if="notice" class="feedback success" role="status">
        {{ notice }}<button class="icon-button" :aria-label="t('Close')" @click="notice = ''">×</button>
      </div>
      <section v-if="preferenceDraft && preferenceError" class="panel preference-conflict" role="alert">
        <h2>{{ t('Review your navigation preference') }}</h2>
        <p>{{ preferenceError }}</p>
        <p>
          {{ t('Your proposed preference') }}: <strong>{{ preferenceDraft.label }}</strong>
        </p>
        <div v-if="preferenceCurrent">
          <p>
            {{ t('Current personal home') }}:
            {{ preferenceCurrent.home ? name(preferenceCurrent.home) : t('Follow shared home') }}
          </p>
          <p>
            {{ t('Current starting view') }}: {{ preferenceCurrent.openingView?.label || t('Home person') }}
          </p>
        </div>
        <div class="button-row">
          <button
            v-if="preferenceFailure !== 412"
            :disabled="preferenceBusy"
            @click="savePreference(preferenceDraft)"
          >
            {{ t('Retry same operation') }}
          </button>
          <button v-else :disabled="preferenceBusy" @click="comparePreferences">
            {{ t('Load current preferences for comparison') }}
          </button>
          <button v-if="preferenceCurrent" :disabled="preferenceBusy" @click="applyPreference">
            {{ t('Apply my preference to this version') }}
          </button>
          <button :disabled="preferenceBusy" @click="discardPreference">
            {{ t('Discard proposed preference') }}
          </button>
        </div>
      </section>
      <section v-if="invitation" class="welcome invitation">
        <span class="eyebrow">{{ t('Invitation') }}</span>
        <h1>{{ t('You have been invited to') }} {{ invitation.treeName }}</h1>
        <p>{{ t('Role') }}: {{ t(title(invitation.role)) }}</p>
        <p>{{ t('Export permission') }}: {{ invitation.exportAllowed ? '✓' : '—' }}</p>
        <div class="button-row">
          <button class="primary" :disabled="busy" @click="acceptInvite">{{ t('Accept invitation') }}</button
          ><button
            @click="
              invitation = null;
              inviteToken = '';
            "
          >
            {{ t('Decline') }}
          </button>
        </div>
      </section>
      <div v-else-if="loading" class="empty" role="status">
        <span class="spinner"></span>{{ t('Loading…') }}
      </div>
      <section v-else-if="!tree" class="welcome">
        <span class="eyebrow">STAMMBAUM</span>
        <h1>{{ t('Family history, together') }}</h1>
        <p class="lead">{{ t('A private place for people, relationships and the history behind them.') }}</p>
        <div class="welcome-art" aria-hidden="true">
          <span>◎</span><i></i><span>◎</span><i></i><span>◎</span>
        </div>
        <h2>{{ t(trees.length ? 'Your trees' : 'No trees yet') }}</h2>
        <p>{{ t('Start with someone you know.') }}</p>
        <button class="primary" @click="createTree">
          + {{ t(trees.length ? 'New tree' : 'Create your first tree') }}
        </button>
        <div v-if="trees.length" class="tree-tiles">
          <button v-for="tr in trees" @click="chooseTree(tr.id)">
            <strong>{{ tr.name }}</strong
            ><small>{{ t(title(tr.role)) }}</small>
          </button>
        </div>
      </section>
      <template v-else>
        <header class="page-header">
          <div>
            <div class="eyebrow">
              {{ t('Tree') }} <span class="role-badge">{{ t(title(tree.role)) }}</span>
            </div>
            <h1>{{ tree.name }}</h1>
            <p v-if="tree.description" class="muted">{{ tree.description }}</p>
          </div>
          <button v-if="canEdit" class="primary" @click="editor = {}">+ {{ t('New person') }}</button>
        </header>
        <button
          class="leave-tree"
          :disabled="busy"
          @click="memberSave({ userId, revision: tree.membershipRevision, role: tree.role }, true)"
        >
          {{ t('Leave tree') }}
        </button>
        <TabList
          id="workspace"
          :label="t('Tree workspace')"
          :items="mainTabs"
          :model-value="tab"
          @select="changeTab"
        />
        <div id="workspace-panel" role="tabpanel" :aria-labelledby="`workspace-tab-${tab}`" tabindex="0">
          <section v-if="tab === 'manage'" class="manage-grid">
            <form class="panel" @submit.prevent="saveTreeDetails()">
              <h2>{{ t('Tree details') }}</h2>
              <label>{{ t('Name') }}<input v-model="editTreeName" maxlength="200" required /></label
              ><label
                >{{ t('Description')
                }}<textarea v-model="editTreeDescription" maxlength="2000" rows="3"></textarea></label
              ><button class="primary" :disabled="busy">{{ t('Save') }}</button>
            </form>
            <section class="panel">
              <h2>{{ t('Members') }}</h2>
              <div v-for="m in members" :key="m.userId" class="member-row">
                <strong
                  >{{ m.displayName
                  }}<small
                    >{{ m.userId
                    }}{{ !m.active ? ' · ' + t('Removed') : !m.enabled ? ' · ' + t('Disabled') : '' }}</small
                  ></strong
                ><select
                  v-model="m.role"
                  :aria-label="t('Role') + ' ' + m.userId"
                  :disabled="busy || (tree.role !== 'owner' && ['manager', 'owner'].includes(m.role))"
                  @change="m.exportAllowed = false"
                >
                  <option v-for="r in roles" :value="r">{{ t(title(r)) }}</option></select
                ><label class="check"
                  ><input
                    v-model="m.exportAllowed"
                    type="checkbox"
                    :disabled="busy || ['manager', 'owner'].includes(m.role)"
                  />{{ t('Export permission') }}</label
                >
                <div class="button-row">
                  <button :disabled="busy" @click="memberSave(m)">{{ t('Save') }}</button
                  ><button :disabled="busy" @click="memberSave(m, true)">{{ t('Remove') }}</button>
                </div>
              </div>
              <form @submit.prevent="addMember">
                <p class="muted">{{ t('Use an existing Nextcloud account ID, or share an invitation.') }}</p>
                <div class="field-grid">
                  <label>{{ t('Account ID') }}<input v-model="memberId" required maxlength="64" /></label
                  ><label
                    >{{ t('Role')
                    }}<select v-model="memberRole">
                      <option v-for="r in roleOptions" :value="r">{{ t(title(r)) }}</option>
                    </select></label
                  >
                </div>
                <label class="check"
                  ><input v-model="memberExport" type="checkbox" />{{ t('Export permission') }}</label
                ><button :disabled="busy">{{ t('Add member') }}</button>
              </form>
            </section>
            <section class="panel">
              <h2>{{ t('Invitation') }}</h2>
              <label
                >{{ t('Role')
                }}<select v-model="inviteRole">
                  <option v-for="r in roleOptions" :value="r">{{ t(title(r)) }}</option>
                </select></label
              ><label class="check"
                ><input type="checkbox" v-model="inviteExport" />{{ t('Export permission') }}</label
              ><button :disabled="busy" @click="createInvite">{{ t('Create invitation') }}</button>
              <p class="muted">
                {{ t('Single use. Expires after 48 hours. The recipient must sign in to Nextcloud.') }}
              </p>
              <template v-if="inviteUrl"
                ><label
                  >{{ t('Invitation link')
                  }}<input
                    ref="inviteLink"
                    :value="inviteUrl"
                    readonly
                    @focus="$event.target.select()" /></label
                ><button @click="copyInvite">{{ t('Copy') }}</button></template
              >
              <h3>{{ t('Active invitations') }}</h3>
              <p v-if="!invitations.length" class="muted">{{ t('No active invitations') }}</p>
              <div v-for="i in invitations" :key="i.id" class="compact-row">
                <span
                  >{{ t(title(i.role)) }} · {{ i.issuerId
                  }}<small>{{ t('Expires') }} {{ datetime(i.expiresAt) }}</small></span
                ><button v-if="i.canRevoke" :disabled="busy" @click="revokeInvite(i)">
                  {{ t('Revoke') }}
                </button>
              </div>
            </section>
            <section class="panel">
              <h2>{{ t('Deleted people') }}</h2>
              <p v-if="!trash.length" class="muted">{{ t('No deleted people') }}</p>
              <div v-for="p in trash" :key="p.id" class="compact-row">
                <span>{{ name(p) }}</span
                ><button :disabled="busy" @click="restorePerson(p)">{{ t('Restore') }}</button>
              </div>
            </section>
          </section>
          <ExportPanel
            v-else-if="tab === 'exports' && tree.canExport"
            :tree-id="tree.id"
            :management="management"
          />
          <ImportPanel
            v-else-if="tab === 'imports' && management"
            :key="tree.id"
            :tree-id="tree.id"
            @changed="importsChanged"
            @open-tree="changeTab('family')"
          />
          <EvidencePanel
            v-else-if="tab === 'sources'"
            :key="tree.id"
            :tree-id="tree.id"
            :open-source-id="linkedSourceId"
            :management="management"
            :can-edit="canEdit"
            :can-export="tree.canExport"
          />
          <MediaPanel
            v-else-if="tab === 'media'"
            @person="openMediaPerson"
            :key="tree.id"
            :tree-id="tree.id"
            :open-medium-id="linkedMediumId"
            :management="management"
            :can-edit="canEdit"
            :can-export="tree.canExport"
          />
          <IntegrationConnections
            v-else-if="tab === 'connections'"
            :key="tree.id"
            :tree-id="tree.id"
            @open-person="openMediaPerson"
          />
          <div
            v-else
            class="workspace"
            :class="{ 'has-details': selected || detailLoading, 'research-expanded': researchExpanded }"
          >
            <section v-show="!researchExpanded" class="work-area">
              <div v-if="tab === 'people'" class="people-toolbar">
                <label class="search-label"
                  ><span class="sr-only">{{ t('Search people') }}</span
                  ><input
                    type="search"
                    v-model="query"
                    :placeholder="t('Search names and display notes')"
                    @input="search" /></label
                ><span v-if="peopleLoading" role="status">{{ t('Loading…') }}</span>
              </div>
              <div v-if="tab === 'people' && peopleError" class="feedback error" role="alert">
                {{ peopleError }} <button @click="loadPeople()">{{ t('Retry') }}</button>
              </div>
              <template v-if="tab === 'people'">
                <div v-if="!peopleLoading && !peopleError && !people.length" class="empty">
                  <span class="empty-glyph">◎</span>
                  <h2>{{ t(query ? 'No matching people' : 'No people yet') }}</h2>
                  <p>
                    {{
                      t(
                        query
                          ? 'Try a different name or note.'
                          : 'Add the first person to start your family history.',
                      )
                    }}
                  </p>
                </div>
                <div class="people-list">
                  <button
                    v-for="p in people"
                    :key="p.id"
                    :aria-pressed="selected?.id === p.id"
                    class="person-row"
                    :data-person-id="p.id"
                    @click="selectPerson(p.id)"
                  >
                    <span class="avatar">{{
                      (p.givenName?.[0] || p.familyName?.[0] || '?').toUpperCase()
                    }}</span
                    ><span class="row-content"
                      ><strong>{{ name(p) }}</strong
                      ><small>{{
                        [dateText(p.birthDate), p.birthPlace].filter(Boolean).join(' · ') ||
                        t('No date recorded')
                      }}</small
                      ><small v-if="p.displayNote" class="row-note">{{ p.displayNote }}</small></span
                    ><span class="badge">{{ t(title(p.livingStatus)) }}</span
                    ><span aria-hidden="true">›</span>
                  </button>
                </div>
                <button v-if="nextPage !== null" :disabled="peopleLoading" @click="loadPeople(nextPage)">
                  {{ t('Load more') }}
                </button>
              </template>
              <template v-else>
                <div v-if="people.length || focus" class="branch-toolbar">
                  <div class="button-row">
                    <button :disabled="!branches.length || graphLoading" @click="previous">
                      ← {{ t('Previous branch') }}</button
                    ><button :disabled="!homeId || graphLoading" @click="branch(homeId)">
                      {{ t('Home person') }}
                    </button>
                  </div>
                  <div class="branch-options">
                    <label
                      ><span class="sr-only">{{ t('Family') }}</span
                      ><select v-model="mode" @change="changeProjection">
                        <option value="family">{{ t('Family') }}</option>
                        <option value="ancestors">{{ t('Ancestors') }}</option>
                        <option value="descendants">{{ t('Descendants') }}</option>
                      </select></label
                    ><label
                      >{{ t('Generations up')
                      }}<input
                        type="number"
                        min="0"
                        max="8"
                        v-model.number="up"
                        @change="changeProjection" /></label
                    ><label
                      >{{ t('Generations down')
                      }}<input type="number" min="0" max="8" v-model.number="down" @change="changeProjection"
                    /></label>
                  </div>
                </div>
                <details v-if="focus" class="saved-views">
                  <summary>{{ t('Saved views') }}</summary>
                  <form class="button-row" @submit.prevent="saveNamedView">
                    <label
                      >{{ t('View name')
                      }}<input
                        v-model="viewLabel"
                        maxlength="100"
                        required
                        :disabled="preferenceBusy || !!preferenceDraft" /></label
                    ><button :disabled="busy || preferenceBusy || !!preferenceDraft">
                      {{ t('Save current view') }}
                    </button>
                  </form>
                  <div v-if="savedViews.items.length" class="button-row">
                    <label
                      >{{ t('Saved views')
                      }}<select v-model="viewChoice" :aria-label="t('Saved views')">
                        <option value="">{{ t('Choose view') }}</option>
                        <option v-for="v in savedViews.items" :key="v.id" :value="v.id">{{ v.label }}</option>
                      </select></label
                    ><button :disabled="!viewChoice || busy" @click="openNamedView">
                      {{ t('Open view') }}</button
                    ><button :disabled="!viewChoice || busy" @click="deleteNamedView">
                      {{ t('Remove') }}
                    </button>
                  </div>
                  <p class="muted">
                    {{ t('Saved views belong to your account and respect your current tree access.') }}
                    {{ t('The most recently saved view is used when reopening this tree.') }}
                    {{
                      t('Local expansions are kept in branch history, not in saved views or copied links.')
                    }}
                  </p>
                </details>
                <div class="personal-home">
                  <span
                    >{{ t('Personal home') }}:
                    {{ savedViews.home ? name(savedViews.home) : t('Follow shared home') }}</span
                  >
                  <button
                    v-if="savedViews.home"
                    :disabled="preferenceBusy || !!preferenceDraft"
                    @click="savePersonalHome(null)"
                  >
                    {{ t('Clear personal home') }}
                  </button>
                </div>
                <div v-if="expansions.length || expandableSelection" class="expansion-toolbar">
                  <span v-if="expansions.length"
                    >{{ t('Local expansions') }}: {{ expansions.length }} / 20</span
                  >
                  <button
                    v-if="expandableSelection"
                    :disabled="graphLoading || expansions.length >= 20"
                    @click="expandRelatives"
                  >
                    {{ t('Expand relatives') }}
                  </button>
                  <button v-if="expansions.length" :disabled="graphLoading" @click="resetExpansions">
                    {{ t('Reset local expansions') }}
                  </button>
                  <p v-if="expansions.length >= 20" class="muted" role="status">
                    {{ t('Local expansion limit reached. Refocus to explore another branch.') }}
                  </p>
                </div>
                <p v-if="graphLoading && graph.people.length" role="status">{{ t('Loading…') }}</p>
                <div v-if="graphLoading && !graph.people.length" class="empty" role="status">
                  <span class="spinner"></span>{{ t('Loading…') }}
                </div>
                <div v-else-if="graphError" class="feedback error graph-error" role="alert">
                  {{ graphError }} <button @click="loadGraph">{{ t('Retry') }}</button>
                </div>
                <FamilyGraph
                  v-else-if="graph.people.length"
                  :graph="graph"
                  v-model:text-view="graphTextView"
                  :aria-busy="graphLoading"
                  :selected="selected?.id"
                  :model-value="camera"
                  :restore-camera="cameraReady"
                  @update:model-value="setCamera"
                  @select="(p) => selectPerson(p.id)"
                />
                <div v-else class="empty">
                  <span class="empty-glyph">⌘</span>
                  <h2>{{ t('No people yet') }}</h2>
                  <p>{{ t('Add the first person to start your family history.') }}</p>
                  <button v-if="canEdit" class="primary" @click="editor = {}">+ {{ t('New person') }}</button>
                </div>
              </template>
            </section>
            <aside v-if="selected || detailLoading" class="details" :aria-label="t('Current values')">
              <button
                class="detail-close icon-button"
                :aria-label="t('Close details')"
                @click="dismissDetails"
              >
                ×
              </button>
              <div v-if="detailLoading" class="empty" role="status">{{ t('Loading…') }}</div>
              <template v-else-if="selected">
                <button
                  ref="researchToggle"
                  class="research-toggle"
                  :aria-expanded="researchExpanded"
                  @click="toggleResearch"
                >
                  {{ t(researchExpanded ? 'Back to family workspace' : 'Expand person research') }}
                </button>
                <div class="person-overview">
                  <div class="person-heading">
                    <img
                      v-if="portrait"
                      class="person-portrait"
                      :src="`${apiBase}/trees/${tree.id}/media/${portrait.id}/preview?person=${selected.id}&revision=${portrait.revision}`"
                      :alt="name(selected)"
                    /><span v-else class="avatar large">{{
                      (selected.givenName?.[0] || selected.familyName?.[0] || '?').toUpperCase()
                    }}</span
                    ><span class="badge">{{ t(title(selected.livingStatus)) }}</span>
                    <h2>{{ name(selected) }}</h2>
                    <p v-if="selected.displayNote" class="preserve-text muted">{{ selected.displayNote }}</p>
                    <span v-if="selected.visibility === 'management'" class="badge">{{
                      t('Management only')
                    }}</span>
                    <p v-if="tab === 'family' && !selectedOnBranch" class="muted">
                      {{ t('Outside this branch') }}
                    </p>
                  </div>
                  <dl class="life-dates">
                    <div>
                      <dt>{{ t('Birth') }}</dt>
                      <dd>
                        {{ dateText(selected.birthDate) || t('Not recorded')
                        }}<small v-if="selected.birthPlace">{{ selected.birthPlace }}</small>
                      </dd>
                    </div>
                    <div>
                      <dt>{{ t('Death') }}</dt>
                      <dd>
                        {{ dateText(selected.deathDate) || t('Not recorded')
                        }}<small v-if="selected.deathPlace">{{ selected.deathPlace }}</small>
                      </dd>
                    </div>
                  </dl>
                </div>
                <div class="detail-actions">
                  <button
                    :disabled="preferenceBusy || !!preferenceDraft || savedViews.home?.id === selected.id"
                    @click="savePersonalHome(selected)"
                  >
                    {{ t('Set as personal home') }}
                  </button>
                  <button @click="branch(selected.id)">{{ t('Show family branch') }}</button
                  ><button v-if="canEdit" @click="editor = { person: selected }">
                    {{ t('Edit person') }}</button
                  ><button v-if="canEdit" @click="editor = { relativeTo: selected }">
                    {{ t('Add relative') }}
                  </button>
                </div>
                <ResearchTaskPanel :tree-id="tree.id" :person="selected" :can-export="tree.canExport" />
                <CalendarPanel :tree-id="tree.id" :person="selected" :can-export="tree.canExport" />
                <ContactLinkPanel
                  :tree-id="tree.id"
                  :person="selected"
                  :management="management"
                  @copied="
                    detailTab = 'details';
                    contactCopyCounter++;
                  "
                />
                <TabList
                  id="person"
                  class="small-tabs"
                  :label="t('Person details')"
                  :items="detailTabs"
                  :model-value="detailTab"
                  @select="changeDetailTab"
                />
                <div class="research-content">
                  <div
                    id="person-panel"
                    role="tabpanel"
                    :aria-labelledby="`person-tab-${detailTab}`"
                    tabindex="0"
                  >
                    <section v-if="detailTab === 'connections'" class="detail-section">
                      <p v-if="!relations.length" class="muted">{{ t('No connections yet') }}</p>
                      <div v-for="r in orderedRelations" :key="r.id" class="relation-row">
                        <div>
                          <small
                            >{{
                              t(
                                r.kind === 'partner'
                                  ? 'Partner'
                                  : r.sourceId === selected.id
                                    ? 'Child'
                                    : 'Parent',
                              )
                            }}<template v-if="r.kind === 'parent'">
                              · {{ t(title(r.parentType)) }}</template
                            ></small
                          ><button class="text-button" @click="selectPerson(r.person.id)">
                            {{ name(r.person) }}
                          </button>
                        </div>
                        <button
                          v-if="canEdit"
                          class="icon-button"
                          :aria-label="t('Remove connection')"
                          :disabled="busy"
                          @click="removeConnection(r)"
                        >
                          ×
                        </button>
                      </div>
                    </section>
                    <section v-if="detailTab === 'assertions'" class="detail-section">
                      <p class="muted">{{ t('Display preference is separate from historical judgment.') }}</p>
                      <div v-for="c in orderedClaims" :key="c.id" class="assertion">
                        <small
                          >{{ claimName(c.kind) }}
                          <span v-if="c.preferred" class="badge">{{ t('Preferred') }}</span></small
                        ><strong>{{ claimValue(c) }}</strong
                        ><span v-if="c.visibility === 'management'" class="muted">{{
                          t('Management only')
                        }}</span
                        ><label v-if="canEdit"
                          >{{ t('Assessment')
                          }}<select
                            :value="c.assessment"
                            :disabled="busy"
                            @change="claimAction(c, 'assess', $event.target.value)"
                          >
                            <option
                              v-for="v in ['unassessed', 'supported', 'disputed', 'rejected']"
                              :value="v"
                            >
                              {{ t(title(v)) }}
                            </option>
                          </select></label
                        ><small v-else>{{ t(title(c.assessment)) }}</small
                        ><button
                          v-if="canEdit && !c.preferred"
                          :disabled="busy"
                          @click="claimAction(c, 'prefer')"
                        >
                          {{ t('Use for display') }}
                        </button>
                      </div>
                    </section>
                    <ContextPanel
                      v-if="detailTab === 'connections'"
                      :tree-id="tree.id"
                      :person="selected"
                      :can-edit="canEdit"
                      :management="management"
                      @changed="loadGraph"
                      @select="selectPerson"
                    />
                    <MediaPanel
                      v-if="detailTab === 'media'"
                      :tree-id="tree.id"
                      :person="selected"
                      :can-edit="canEdit"
                      :can-export="tree.canExport"
                      :management="management"
                      @changed="mediaChanged"
                      @person="selectPerson"
                    />
                    <RecordPanel
                      v-if="['timeline', 'notes', 'details'].includes(detailTab)"
                      :key="`${selected.id}:${detailTab}:${contactCopyCounter}`"
                      :tree-id="tree.id"
                      :person="selected"
                      :can-edit="canEdit"
                      :can-export="tree.canExport"
                      :management="management"
                      :section="detailTab"
                      :target-event-id="linkedEventId"
                      @evidence="changeDetailTab('evidence')"
                      @person="selectPerson"
                    />
                    <EvidencePanel
                      v-if="detailTab === 'evidence'"
                      :tree-id="tree.id"
                      :management="management"
                      :can-edit="canEdit"
                      :can-export="tree.canExport"
                      :person="selected"
                      :claims="claims"
                      :relations="relations"
                    />
                    <section v-if="detailTab === 'history'" class="detail-section">
                      <p class="muted">
                        {{ t('All changes are attributed to the account that made them.') }}
                      </p>
                      <div v-for="h in history" :key="h.id" class="history-entry">
                        <strong>{{ actionName(h.action) }}</strong
                        ><small>{{ datetime(h.createdAt) }} · {{ h.actorId }}</small>
                        <details v-if="h.data.givenName !== undefined">
                          <summary>{{ t('Current values') }}</summary>
                          <p>{{ name(h.data) }}</p>
                          <p>
                            {{ t('Birth') }}: {{ dateText(h.data.birthDate) || t('Not recorded') }}
                            {{ h.data.birthPlace }}
                          </p>
                          <p>
                            {{ t('Death') }}: {{ dateText(h.data.deathDate) || t('Not recorded') }}
                            {{ h.data.deathPlace }}
                          </p>
                        </details>
                      </div>
                      <button v-if="historyPage !== null" @click="moreHistory">{{ t('Load more') }}</button>
                    </section>
                  </div>
                  <section
                    v-if="researchExpanded"
                    class="research-context"
                    :aria-label="t('Research context')"
                  >
                    <h3>{{ t('Research context') }}</h3>
                    <p class="muted">
                      {{ t('Retained assertions and connections for comparison with the current panel.') }}
                    </p>
                    <h4>{{ t('Assertions') }}</h4>
                    <div v-for="c in orderedClaims" :key="c.id" class="assertion">
                      <small
                        >{{ claimName(c.kind) }}
                        <span v-if="c.preferred" class="badge">{{ t('Preferred') }}</span></small
                      >
                      <strong>{{ claimValue(c) }}</strong>
                      <small>{{ t(title(c.assessment)) }}</small>
                    </div>
                    <h4>{{ t('Connections') }}</h4>
                    <p v-if="!relations.length" class="muted">{{ t('No connections yet') }}</p>
                    <div v-for="r in orderedRelations" :key="r.id" class="relation-row">
                      <span
                        >{{ name(r.person)
                        }}<small
                          >{{
                            t(
                              r.kind === 'partner'
                                ? 'Partner'
                                : r.sourceId === selected.id
                                  ? 'Child'
                                  : 'Parent',
                            )
                          }}<template v-if="r.kind === 'parent'">
                            · {{ t(title(r.parentType)) }}</template
                          ></small
                        ></span
                      >
                    </div>
                  </section>
                </div>
                <div v-if="management" class="danger-actions">
                  <button :disabled="busy" @click="saveTreeDetails(selected.id)">
                    {{ t('Set as shared home') }}</button
                  ><button v-if="tree.homeId" :disabled="busy" @click="saveTreeDetails(null)">
                    {{ t('Clear shared home') }}</button
                  ><button class="danger" :disabled="busy" @click="removePerson">
                    {{ t('Delete person') }}
                  </button>
                </div>
              </template>
            </aside>
          </div>
        </div>
        <p class="iteration-note">
          {{
            t(
              'This early version supports manual research, reviewed GEDCOM import and current-state export. Review exchange limitations before transferring research. Optional Nextcloud Tasks, Calendar and Contacts actions are available from a person.',
            )
          }}
        </p>
      </template>
    </main>
    <dialog
      ref="treeDialog"
      class="stb-dialog compact-dialog"
      aria-labelledby="new-tree-title"
      @cancel.prevent="closeTreeDialog"
    >
      <form @submit.prevent="saveTree">
        <header>
          <h2 id="new-tree-title">{{ t('New tree') }}</h2>
        </header>
        <div class="dialog-body">
          <p class="muted">{{ t('Only invited members can see a tree.') }}</p>
          <label>{{ t('Name') }}<input v-model="treeName" required maxlength="200" autofocus /></label
          ><label
            >{{ t('Description') }}<textarea v-model="treeDescription" maxlength="2000" rows="3"></textarea>
          </label>
          <p v-if="error" class="feedback error" role="alert">{{ error }}</p>
        </div>
        <footer>
          <button type="button" :disabled="busy" @click="closeTreeDialog">{{ t('Cancel') }}</button
          ><button class="primary" :disabled="busy">{{ t(busy ? 'Saving…' : 'Create tree') }}</button>
        </footer>
      </form>
    </dialog>
    <PersonEditor
      v-if="editor"
      :person="editor.person"
      :relative-to="editor.relativeTo"
      :tree-id="tree.id"
      :management="management"
      @close="editor = null"
      @saved="updated"
    />
  </div>
</template>

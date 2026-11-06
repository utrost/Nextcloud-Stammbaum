import { onBeforeUnmount } from 'vue';
import { t } from './i18n.js';
const guards = new Map();
export function useDraftGuard(dirty, busy) {
  const key = Symbol();
  guards.set(key, () => ({ dirty: dirty.value, busy: busy?.value === true }));
  onBeforeUnmount(() => guards.delete(key));
}
export function canNavigate() {
  const states = [...guards.values()].map((g) => g());
  if (states.some((s) => s.busy)) return false;
  return !states.some((s) => s.dirty) || window.confirm(t('Discard unsaved changes?'));
}

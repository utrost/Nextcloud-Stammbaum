import de from './translations/de.js';
import { ref } from 'vue';
export const locale = ref(
  document.getElementById('stammbaum-app')?.dataset.locale ||
    (document.documentElement.lang?.startsWith('en') ? 'en' : 'de'),
);

export function t(message) {
  return locale.value === 'de' ? (de[message] ?? message) : message;
}
export const roles = ['viewer', 'editor', 'manager', 'owner'];
export const title = (x) => (x ? x[0].toUpperCase() + x.slice(1) : '');

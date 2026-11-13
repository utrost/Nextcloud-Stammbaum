import { api, userId } from './api.js';

export function calendarObjectUrl(collection, objectName) {
  const root = window.OC?.getRootPath?.() || window.OC?.webroot || '';
  return `${root}/remote.php/dav/calendars/${encodeURIComponent(userId)}/${encodeURIComponent(collection)}/${encodeURIComponent(objectName)}`;
}

/** Best effort: a missing initial ETag disables in-app external removal. */
export async function captureCreatedEtag(treeId, kind, id, collection) {
  try {
    const response = await fetch(calendarObjectUrl(collection, `${id}.ics`), {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    if (!response.ok) return false;
    const etag = response.headers.get('ETag');
    if (!etag) return false;
    await api(`/trees/${treeId}/connections/${kind}/${id}/etag`, { method: 'POST', body: { etag } });
    return true;
  } catch {
    return false;
  }
}

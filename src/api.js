const root = document.getElementById('stammbaum-app');
export const userId = root.dataset.userId;
export const apiBase = root.dataset.apiBase;
export async function api(path, { method = 'GET', body, signal } = {}) {
  const response = await fetch(apiBase + path, {
    method,
    credentials: 'same-origin',
    signal,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      requesttoken: window.OC?.requestToken || root.dataset.requestToken,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  }).catch((error) => {
    if (error?.name === 'AbortError') throw error;
    throw new Error('The connection was interrupted. Please try again.');
  });
  // Nextcloud may redirect an expired browser session to its HTML login page.
  // Treat that as an authentication failure rather than a malformed API reply.
  if (response.redirected && /\/login(?:\/|$)/.test(new URL(response.url).pathname)) {
    window.dispatchEvent(new Event('stammbaum:auth-expired'));
    throw Object.assign(new Error('Sign in to continue.'), { status: 401 });
  }
  let data;
  try {
    data = await response.json();
  } catch {
    if (response.status === 401) window.dispatchEvent(new Event('stammbaum:auth-expired'));
    throw Object.assign(
      new Error(response.status === 401 ? 'Sign in to continue.' : 'Loading failed. Please try again.'),
      { status: response.status },
    );
  }
  if (!response.ok) {
    if (response.status === 401) window.dispatchEvent(new Event('stammbaum:auth-expired'));
    throw Object.assign(new Error(data.message || 'Loading failed. Please try again.'), {
      status: response.status,
      code: data.error,
      details: data.details,
    });
  }
  return data;
}

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
  let data;
  try {
    data = await response.json();
  } catch {
    throw Object.assign(
      new Error(response.status === 401 ? 'Sign in to continue.' : 'Loading failed. Please try again.'),
      { status: response.status },
    );
  }
  if (!response.ok)
    throw Object.assign(new Error(data.message || 'Loading failed. Please try again.'), {
      status: response.status,
      code: data.error,
      details: data.details,
    });
  return data;
}

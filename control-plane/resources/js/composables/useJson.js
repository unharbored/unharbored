/**
 * A JSON call to a controller that answers with data and does not navigate (a lookup, or a check
 * that either passes or says what is wrong). A 422 comes back as `errors`: { field: message }.
 *
 *   const { ok, data, errors } = await postJson('/apps/new/check', { input })
 */
function xsrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function call(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let data = null;
    try { data = await response.json(); } catch { /* an empty body */ }
    if (response.status === 422) {
        const errors = {};
        for (const [field, messages] of Object.entries(data?.errors ?? {})) errors[field] = messages[0];
        return { ok: false, status: 422, data, errors };
    }
    return { ok: response.ok, status: response.status, data, errors: {} };
}

export const getJson = (url, params = {}) => call('GET', url + (Object.keys(params).length ? `?${new URLSearchParams(params)}` : ''));
export const postJson = (url, body = {}) => call('POST', url, body);

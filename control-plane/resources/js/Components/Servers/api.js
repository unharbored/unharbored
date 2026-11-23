import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/** The XSRF token Laravel keeps in a cookie, for requests that are not Inertia visits. */
function xsrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A plain JSON request, for the two server calls that answer with data and not a page. */
export async function json(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(`${response.status}`);
    }

    return response.json();
}

/** The path of one server's action: /servers/<node>/<rest>. */
export const serverUrl = (node, rest) => `/servers/${encodeURIComponent(node)}/${rest}`;

/**
 * Validation messages from the last request, kept until the next action. The page reloads itself every few
 * seconds and Inertia clears its errors on each reload, so a message would vanish before it was read.
 */
export function useKeptErrors() {
    const page = usePage();
    const errors = ref({});
    watch(() => page.props.errors, (now) => {
        if (now && Object.keys(now).length) {
            errors.value = { ...now };
        }
    }, { deep: true });

    return { errors, clearErrors: () => { errors.value = {}; } };
}

import { computed, reactive, toValue } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { confirm } from './useConsole';
import { postJson } from './useJson';

/**
 * What every Settings tab does with the server: send an action for this application, ask first when
 * it matters, look something up without leaving the page, and read the error a field should show.
 *
 *   const { send, ask, lookup, error } = useSiteSettings(() => props.site.id);
 *   send('env', { envText })                      // a redirect back, or on to the operation it queued
 *   if (await ask({ title, message })) send(...)
 *   const { ok, data } = await lookup('env/reveal')  // JSON, no navigation
 */
export function useSiteSettings(siteId) {
    const page = usePage();
    const lookupErrors = reactive({});
    // siteId may be a getter, so a component that stays mounted while the page moves to another application follows it.
    const url = (path) => `/site/settings/${toValue(siteId)}/${path}`;

    function send(path, data = {}, options = {}) {
        for (const key of Object.keys(lookupErrors)) delete lookupErrors[key];
        router.post(url(path), data, { preserveScroll: true, preserveState: true, ...options });
    }

    async function lookup(path, data = {}) {
        for (const key of Object.keys(lookupErrors)) delete lookupErrors[key];
        const result = await postJson(url(path), data);
        Object.assign(lookupErrors, result.errors);
        return result;
    }

    /** The message to show under a field: from a lookup that failed, or from the last action. */
    const error = (key) => lookupErrors[key] ?? page.props.errors?.[key];

    return { send, lookup, error, ask: confirm, can: computed(() => page.props.can), url };
}

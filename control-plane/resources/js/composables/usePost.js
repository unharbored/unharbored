import { router } from '@inertiajs/vue3';

/**
 * A POST that keeps the scroll position and the page's local state:
 *   const post = usePost();  post('/site/deploy', { site: 3 });
 * Pass defaults to merge into every call: usePost({ site: id }).
 */
export function usePost(defaults = {}) {
    return (url, data = {}, options = {}) =>
        router.post(url, { ...defaults, ...data }, { preserveScroll: true, preserveState: true, ...options });
}

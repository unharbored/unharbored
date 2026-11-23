import { reactive, watch, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * A filter bar whose values live in the URL query string, so a page can be
 * bookmarked and a reload keeps it. Changing a value asks the server again
 * (only the props named in `only`), after `delay` ms so typing is not a request per key.
 *
 *   const form = useFilters(props.filters, ['filters', 'rows'])
 *
 * Empty, false and zero values are left out of the URL.
 */
export function useFilters(initial, only, delay = 300) {
    const form = reactive({ ...initial });
    let timer;

    const query = () => {
        const q = {};
        for (const [key, value] of Object.entries(form)) {
            if (value !== '' && value !== false && value !== 0 && value !== null) q[key] = value === true ? 1 : value;
        }
        return q;
    };

    const apply = () => {
        clearTimeout(timer);
        router.get(window.location.pathname, query(), { only, preserveState: true, preserveScroll: true, replace: true });
    };

    watch(form, () => {
        clearTimeout(timer);
        timer = setTimeout(apply, delay);
    });
    onUnmounted(() => clearTimeout(timer));

    return form;
}

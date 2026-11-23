import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Look again every `ms` milliseconds for the named props only (a partial reload),
 * not while the browser tab is hidden.
 */
export function usePolling(only, ms) {
    let timer;
    const tick = () => {
        if (document.visibilityState === 'visible') {
            router.reload({ only, preserveScroll: true, preserveState: true, async: true });
        }
    };
    onMounted(() => { timer = setInterval(tick, ms); });
    onUnmounted(() => clearInterval(timer));
}

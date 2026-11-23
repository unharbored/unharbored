import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { confirm } from '../../composables/useConsole.js';
import { serverUrl } from './api.js';

/**
 * What every part of a server's page needs to act on it: whether it can be commanded now, a button's
 * reason when it cannot, and a post to one of its actions after asking, when it asks.
 * `node` is a ref to the server; `before` runs first (it clears the errors kept from the last action).
 */
export function useServerActions(node, before = () => {}) {
    const unavailable = computed(() => !node.value.connected || node.value.busy);

    /** Posts to /servers/<node>/<rest>. The server answers with the operation's page or goes back. */
    async function act(rest, data = {}, message = null, tone = 'info', ok = undefined) {
        if (message !== null && !(await confirm({ message, tone, ok }))) {
            return;
        }
        before();
        router.post(serverUrl(node.value.id, rest), data, { preserveScroll: true });
    }

    /** A tooltip for something that needs the agent online and the server idle. */
    const busyReason = (does) => (!unavailable.value
        ? does
        : `Unavailable: ${node.value.connected ? 'an operation is already running on this server.' : 'the agent is offline.'}`);

    return { unavailable, act, busyReason };
}

export const caption = 'font-mono text-[10px] tracking-[0.18em] text-muted uppercase';
export const note = 'text-[13px] text-faint';
export const row = 'flex flex-wrap items-center justify-between gap-3';
export const check = 'inline-flex min-h-11 cursor-pointer items-center gap-2.5';
export const usage = (pct) => (pct >= 90 ? 'font-semibold text-failed' : 'font-semibold text-ink-strong');

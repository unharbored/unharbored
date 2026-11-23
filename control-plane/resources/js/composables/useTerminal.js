import { ref } from 'vue';

/**
 * A terminal in the browser on one of a server's consoles: xterm from the CDN, the session opened and
 * started through the console routes, and its output read from Reverb. Used by an application's Commands
 * page and by a server's console drawer.
 *
 *   const t = useTerminal(socketUrl);
 *   await t.start(element, { site, node, as: 'deploy' });   // `element` is on the page already
 *   t.dispose();                                              // when it is closed
 */
export function useTerminal(socketUrl) {
    const opening = ref(false);
    const note = ref('');

    // xterm is loaded from the CDN when a console is first opened, not with the page.
    const XTERM = 'https://cdn.jsdelivr.net/npm/xterm@5.3.0';
    const FIT = 'https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js';
    let libs = null;
    function load(tag, attrs) {
        return new Promise((resolve, reject) => {
            const el = document.createElement(tag);
            Object.assign(el, attrs, { onload: resolve, onerror: () => reject(new Error('The terminal library did not load. Check the connection and reload.')) });
            document.head.appendChild(el);
        });
    }
    function loadLibs() {
        if (window.Terminal && window.FitAddon) return Promise.resolve();
        libs ??= Promise.all([
            load('link', { rel: 'stylesheet', href: `${XTERM}/css/xterm.css` }),
            load('script', { src: `${XTERM}/lib/xterm.js` }).then(() => load('script', { src: FIT })),
        ]).catch((e) => { libs = null; throw e; });
        return libs;
    }

    let term = null, fit = null, ws = null, session = null, lastSeq = 0, ended = false, chain = Promise.resolve(), pending = '', watchdog = null;

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    function post(path, body) {
        return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body || {}) })
            .then((r) => r.json().catch(() => ({})).then((j) => { if (!r.ok) throw new Error(j.error || `Request failed (${r.status})`); return j; }));
    }
    // Each step is written into the console as it happens, so a blank one never has to be guessed at.
    function step(text) { if (term) term.write(`\x1b[90m${text}\x1b[0m\r\n`); }
    const b64 = (s) => btoa(unescape(encodeURIComponent(s)));
    function bytes(b) { const bin = atob(b), a = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a; }

    // What is typed goes in order, and what is typed while a request is out goes in the next one.
    function send(data) {
        if (!session || ended) return;
        pending += data;
        chain = chain.then(() => {
            if (!pending) return;
            const d = pending; pending = '';
            return post(`/console/${session}/input`, { data: b64(d) }).catch((e) => finish(e.message));
        });
    }

    function finish(message) {
        if (ended) return;
        ended = true;
        if (term) term.write(`\r\n\x1b[90m[${message || 'the console has ended'}]\x1b[0m\r\n`);
        if (ws) { try { ws.close(); } catch (e) { /* closing anyway */ } }
    }

    // The Pusher protocol Reverb speaks, just enough of it: connect, have the channel authorised, subscribe.
    function listen(id, ready) {
        const channel = `private-console.${id}`;
        let subscribed = false;
        step('connecting to the live channel…');
        ws = new WebSocket(socketUrl);
        const wait = setTimeout(() => { if (!subscribed) finish(`the live channel did not answer (${socketUrl.split('?')[0]})`); }, 10000);
        ws.onmessage = (m) => {
            const msg = JSON.parse(m.data);
            if (msg.event === 'pusher:connection_established') {
                const socket = JSON.parse(msg.data).socket_id;
                step('connected; having the console authorised…');
                fetch('/broadcasting/auth', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ socket_id: socket, channel_name: channel }) })
                    .then((r) => { if (!r.ok) throw new Error('This console could not be authorised.'); return r.json(); })
                    .then((a) => { ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: a.auth, channel } })); })
                    .catch((e) => finish(e.message));
            } else if (msg.event === 'pusher_internal:subscription_succeeded') {
                subscribed = true; clearTimeout(wait);
                step('listening; starting the shell…');
                ready();
            } else if (msg.event === 'pusher:ping') {
                ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
            } else if (msg.event === '.output' || msg.event === 'output') {
                const p = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
                clearTimeout(watchdog);
                if (p.data && p.seq > lastSeq) { lastSeq = p.seq; term.write(bytes(p.data)); }
                if (p.error) finish(p.error);
                else if (p.exit !== undefined) finish(`the shell exited (${p.exit})`);
            }
        };
        ws.onclose = () => { if (!ended) finish('lost the connection to the server'); };
    }

    function dispose() {
        if (session && !ended) post(`/console/${session}/close`).catch(() => {});
        ended = true;
        clearTimeout(watchdog);
        if (ws) { try { ws.close(); } catch (e) { /* closing anyway */ } }
        if (term) { term.dispose(); term = null; }
        session = null;
    }

    /** Mounts a terminal in `el` and opens a shell on `node` as `as`. */
    async function start(el, { site, node, as }) {
        try { await loadLibs(); } catch (e) { note.value = e.message; return; }
        opening.value = true; note.value = ''; ended = false; lastSeq = 0; pending = '';

        term = new window.Terminal({ cursorBlink: true, fontFamily: 'Space Mono, ui-monospace, Menlo, monospace', fontSize: 14, theme: { background: '#101114', foreground: '#e6e6e6' }, scrollback: 5000 });
        fit = new window.FitAddon.FitAddon();
        term.loadAddon(fit);
        term.open(el);
        fit.fit();
        term.onData(send);
        term.onResize((s) => { if (session && !ended) post(`/console/${session}/resize`, { cols: s.cols, rows: s.rows }).catch(() => {}); });

        try {
            const r = await post('/console/open', { site, node, as_user: as });
            session = r.session;
            // Listening first, then starting the shell, so its prompt is not missed.
            listen(session, () => post(`/console/${session}/start`, { cols: term.cols, rows: term.rows }).then(() => {
                term.focus();
                // The server's agent answers with the prompt within a moment; if nothing comes, say what is likely.
                watchdog = setTimeout(() => { if (!lastSeq) finish("the server's agent did not answer. It probably runs a build from before consoles; upgrade it on Servers."); }, 10000);
            }).catch((e) => finish(e.message)));
        } catch (e) {
            finish(e.message);
        }
        opening.value = false;
    }

    /** True while a shell is open and has not ended. */
    const live = () => session !== null && !ended;
    const refit = () => { if (fit) fit.fit(); };
    const focus = () => { if (term) term.focus(); };

    return { opening, note, start, dispose, refit, focus, live };
}

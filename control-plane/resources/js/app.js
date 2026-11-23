import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

// Pages live in resources/js/Pages, one file per console page.
const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} · Unharbored` : 'Unharbored'),
    resolve: (name) => pages[`./Pages/${name}.vue`],
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});

/**
 * The console's own dialogs and toasts (public/js/dialogs.js, loaded by the root view),
 * in place of the browser's confirm() and alert().
 *
 *   if (await confirm({ title: 'Remove it?', message: '...', ok: 'Remove', tone: 'danger' })) { ... }
 */
export const confirm = (options) => window.cm.confirm(options);
export const alertBox = (options) => window.cm.alert(options);
export const toast = (message, options) => window.cm.toast(message, options);

<script setup>
import Toggle from '../ui/Toggle.vue';
import Badge from '../ui/Badge.vue';
import Btn from '../ui/Btn.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, ask, error } = useSiteSettings(() => props.site.id);

const branch = ref('');
const database = ref('');
const days = ref(7);
const follow = ref(false);
const seeder = ref(props.data.previewSeeder);
const exclude = ref(props.data.previewExclude);
const prBase = ref(props.data.previewPrBase);
const defaultDatabase = ref(props.data.previewDefaultDatabase);
const overrides = ref(props.data.previewOverrides);

const create = () => send('previews', { branch: branch.value, follow: follow.value, days: days.value, database: database.value });
const keepSettings = () => send('previews/settings', { seeder: seeder.value, exclude: exclude.value, database: defaultDatabase.value, overrides: overrides.value, prBase: prBase.value });
async function deletePreview(p) {
    if (await ask({ title: 'Delete the preview?', message: `Delete the preview of ${p.branch}? Its code, database and files on the preview server are removed.`, ok: 'Delete', tone: 'danger' })) {
        send(`previews/${p.id}/delete`);
    }
}
</script>

<template>
  <Panel id="previews" title="Previews">
    <template #note>A branch of {{ site.name }} at its own URL, on the preview server</template>
      <div v-for="p in data.previews" :key="p.id" class="flex justify-between gap-3 flex-wrap items-center">
        <span><a :href="p.url" target="_blank" rel="noopener"><b>{{ p.branch }}</b></a><Badge v-if="p.pr" plain class="ml-1"> PR #{{ p.pr }}</Badge> <span class="text-faint text-[13px]">· {{ p.url }} · {{ p.database }} database · {{ p.follow ? 'follows pushes' : 'pinned' }} · {{ p.idle ? 'deleted after ' + p.idle + ' idle days' : 'kept' }}<template v-if="p.op"> · <a :href="`/operation?operation=${p.op.id}`">{{ p.op.status }} {{ p.op.ago }}</a></template></span></span>
        <div class="flex flex-wrap gap-2">
          <Btn small :href="`/site/settings?site=${p.id}`">Settings</Btn>
          <Btn small danger @click="deletePreview(p)">Delete</Btn>
        </div>
      </div>
      <template v-if="data.previewServer">
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Branch" v-slot="{ id }" class="flex-[2_1_220px]"><input :id="id" v-model="branch" placeholder="feature/new-checkout" autocomplete="off"></Field>
          <Field label="Database" class="flex-[0_1_170px]" v-slot="{ id }">
            <select :id="id" v-model="database"><option value="">Site default ({{ data.previewDefaultDatabase }})</option><option value="fresh">Fresh</option><option value="clone">Clone of this site's</option><option value="shared">This site's own</option><option value="sqlite">SQLite</option></select></Field>
          <Field label="Delete after idle days" v-slot="{ id }" class="flex-[0_1_150px]"><input :id="id" v-model.number="days" type="number" min="0" max="90"></Field>
          <Toggle v-model="follow" class="self-end">Redeploy on every push</Toggle>
          <Btn primary @click="create">Create preview</Btn>
        </div>
        <span v-if="error('previewBranch')" class="text-[13px] text-failed">{{ error('previewBranch') }}</span>
        <span class="text-faint text-[13px]">0 days keeps it until deleted. Fresh: an empty database of this site's kind on its cluster, migrated and seeded if set below. Clone: the same, holding a copy of this site's data. This site's own: the live database, so its migrations and seeders never run. SQLite: an empty file on the preview server. Every preview has its own app key, cache and files, sends mail to its log, and never reaches this site's Valkey or bucket.</span>
      </template>
      <span v-else class="text-faint text-[13px]">There is no preview server yet.</span>
      <details>
        <summary class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase cursor-pointer">Preview settings</summary>
        <div class="flex flex-wrap items-end gap-3 mt-2">
          <Field label="Seeder run on a new preview's database" v-slot="{ id }"><input :id="id" v-model="seeder" placeholder="PreviewSeeder" autocomplete="off"></Field>
          <Field label="Production-only keys, left out of previews" v-slot="{ id }" class="flex-[2_1_260px]"><input :id="id" v-model="exclude" placeholder="STRIPE_SECRET, SENTRY_DSN" autocomplete="off"></Field>
          <Field label="Preview pull requests into" v-slot="{ id }" class="flex-[1_1_200px]"><input :id="id" v-model="prBase" placeholder="main (empty: none)" autocomplete="off"></Field>
          <Field label="Database by default" v-slot="{ id }" class="flex-[0_1_170px]">
            <select :id="id" v-model="defaultDatabase"><option value="fresh">Fresh</option><option value="clone">Clone</option><option value="shared">This site's own</option><option value="sqlite">SQLite</option></select></Field>
        </div>
        <Field label="Overrides, set last in every new preview's .env" v-slot="{ id }" class="mt-2">
          <textarea :id="id" v-model="overrides" rows="5" spellcheck="false" :placeholder="'OAUTH_REDIRECT_URI={url}/auth/callback\nOAUTH_CLIENT_ID=previews'"></textarea></Field>
        <div class="flex flex-wrap gap-2 mt-2"><Btn @click="keepSettings">Save</Btn></div>
        <span v-for="key in ['previewPrBase', 'previewDefaultDatabase', 'previewOverrides', 'previewSeeder', 'previewExclude']" :key="key" v-show="error(key)" class="text-[13px] text-failed">{{ error(key) }}</span>
        <span class="text-faint text-[13px]">Valkey, Spaces and mail credentials are never copied, whatever is listed here. In overrides, {url}, {domain}, {branch}, {slug} and {site} are filled in per preview. Overrides are kept in the vault. A pull request into the branch named gets a preview that follows its pushes and is deleted when it closes; one from a fork never does.</span>
      </details>
  </Panel>
</template>

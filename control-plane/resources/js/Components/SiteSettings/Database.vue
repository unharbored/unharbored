<script setup>
import Toggle from '../ui/Toggle.vue';
import Btn from '../ui/Btn.vue';
import FormError from '../ui/FormError.vue';
import Field from '../ui/Field.vue';
import Panel from '../ui/Panel.vue';
import { ref } from 'vue';
import { useSiteSettings } from '../../composables/useSiteSettings';

const props = defineProps({ site: Object, data: Object });
const { send, lookup, ask, error } = useSiteSettings(() => props.site.id);

const note = ref('');
const connectionUrl = ref('');
const connectionFor = ref('');
const cloneShown = ref(null);
const cloneName = ref('');
const cloneSchemaOnly = ref(false);
const cloneSql = ref(props.data.cloneSql);
const cluster = ref(0);

/** Fetches the connection URL, shows it, and puts it on the clipboard when the browser allows. */
async function copyConnection(clone = 0) {
    const { ok, data } = await lookup('database/connection', { clone });
    if (!ok) return;
    connectionUrl.value = data.url;
    connectionFor.value = clone > 0 ? `clone-${clone}` : 'site';
    try {
        await navigator.clipboard.writeText(data.url);
        note.value = 'Connection string copied.';
    } catch {
        note.value = 'Select the connection string below and copy it.';
    }
}
function hideConnection() {
    connectionUrl.value = '';
    connectionFor.value = '';
}
async function showClone(id) {
    const { ok, data } = await lookup(`clones/${id}/reveal`);
    if (ok) cloneShown.value = { id, lines: data.lines };
}
async function deleteClone(c) {
    if (await ask({ title: 'Delete the copy?', message: `Delete the copy ${c.name}? Its data is removed and this cannot be undone.`, ok: 'Delete', tone: 'danger' })) {
        cloneShown.value = null;
        send(`clones/${c.id}/drop`);
    }
}
async function cloneDatabase() {
    if (await ask({ title: 'Clone the database?', message: `Copy ${props.site.name}'s database into a new one called what you typed? Nothing is changed in this one.`, ok: 'Clone' })) {
        send('clones', { name: cloneName.value, schemaOnly: cloneSchemaOnly.value, sql: cloneSql.value });
    }
}
function saveCloneSql() {
    send('clones/sql', { sql: cloneSql.value });
}
function addExample(i) {
    cloneSql.value = `${cloneSql.value.trimEnd()}\n${props.data.cloneExamples[i]?.sql ?? ''}`.trimStart();
}
async function disconnect() {
    if (await ask({ title: 'Disconnect the database?', message: `Disconnect ${props.site.name}'s managed database? The database and all its data stay on the cluster, and its credentials stay in the app's .env, so the app keeps connecting. Unharbored just stops managing it.`, ok: 'Disconnect', tone: 'danger' })) {
        send('database/detach');
    }
}
async function moveUploads() {
    if (await ask({ title: 'Move uploads to Spaces?', message: `Give ${props.site.name} its own private Spaces bucket and make it the default disk? Spaces costs $5 a month per account for the first 250 GB. Files already uploaded are not copied, and the app needs league/flysystem-aws-s3-v3.`, ok: 'Move uploads' })) {
        send('storage');
    }
}
function backUp() {
    send('backup');
}
async function restoreFrom(b) {
    if (await ask({ title: 'Restore this backup?', message: `Put ${props.site.name}'s database back as it was at ${b.at}? The site goes into maintenance mode for the restore, and what is there now is backed up first.`, ok: 'Restore', tone: 'danger' })) {
        send('backup/restore', { object: b.object });
    }
}
async function restoreFromCluster(b) {
    if (await ask({ title: 'Restore this backup?', message: `Put ${props.site.name}'s database back as it was in DigitalOcean's backup of ${b.at}? A temporary cluster is made from that backup (several minutes, billed while it exists, then destroyed); the site is in maintenance mode only while its data is copied, and what is there now is backed up first.`, ok: 'Restore', tone: 'danger' })) {
        send('backup/restore-cluster', { createdAt: b.created_at });
    }
}
const local = ref('');
async function useLocal() {
    const label = props.data.localDatabases.find((d) => d.service === local.value)?.label ?? '';
    if (await ask({ title: `Use ${label} on this server?`, message: `Install ${label} on ${props.site.node} if it is not there, give ${props.site.name} a database and user of its own, and point the app at it. Data already in its current database is not copied; migrations build the schema on the next deploy.`, ok: 'Set it up' })) {
        send('database/local', { service: local.value });
    }
}
async function attach() {
    if (await ask({ title: 'Attach the cluster?', message: `Attach ${props.site.name} to this cluster? It gets its own database and user there. Existing data is not copied over; migrations build the schema on the next deploy.`, ok: 'Attach' })) {
        send('database/attach', { cluster: cluster.value });
    }
}
</script>

<template>
  <Panel id="database" title="Database" note="Applies on next deploy">
      <p v-if="note" class="text-accent text-[13px] m-0" role="status">{{ note }}</p>
      <div class="flex justify-between gap-3 flex-wrap items-center">
        <span>Data lives in: <b :class="data.database.managed ? 'text-accent' : 'font-semibold text-ink-strong'">{{ data.database.label }}</b></span>
        <span class="flex flex-wrap gap-1.5">
          <Btn small v-if="['managed', 'external'].includes(data.database.kind)" title="Copy the database as one connection URL for psql, TablePlus and the like. It contains the password, so it is audited." @click="copyConnection(0)">Copy connection string</Btn>
          <Btn small danger v-if="data.database.managed" @click="disconnect">Disconnect database</Btn>
        </span>
      </div>
      <FormError :message="error('database')" />
      <div v-if="connectionUrl !== ''" class="flex flex-col gap-1.5">
        <label class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase" for="conn-url">Connection string {{ connectionFor === 'site' ? '' : '(the copy)' }}</label>
        <input id="conn-url" class="font-mono w-full" readonly :value="connectionUrl" @focus="$event.target.select()">
        <div class="flex flex-wrap gap-2"><Btn small @click="hideConnection">Hide</Btn><span class="text-faint text-[13px] self-center">It contains the password. Revealing it is recorded in the audit log.</span></div>
      </div>
      <span v-if="data.database.managed" class="text-faint text-[13px]">Disconnecting keeps the database and its data. Nothing is copied or dropped; the connection in the app's .env keeps working. Edit the variables or attach another cluster to move it.</span>
      <span>Cache, sessions and queue: <b class="font-semibold text-ink-strong">{{ data.cache }}</b></span>
      <span v-if="data.bucket">Uploads: <b class="text-accent">Spaces bucket {{ data.bucket }}</b> <span class="text-faint text-[13px]">· private · the default disk</span></span>
      <template v-else>
        <div class="flex justify-between gap-3 flex-wrap items-center">
          <span>Uploads: <b class="font-semibold text-ink-strong">the disk of each server</b></span>
          <Btn small @click="moveUploads">Move uploads to Spaces</Btn>
        </div>
        <span v-if="error('storage')" class="text-[13px] text-failed">{{ error('storage') }}</span>
        <span class="text-faint text-[13px]">The app needs <code>league/flysystem-aws-s3-v3</code> for Laravel's s3 disk.</span>
      </template>
      <div class="flex flex-col gap-2">
        <div class="flex justify-between gap-3 flex-wrap items-center">
          <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Backups · {{ data.backupDays }} days kept</span>
          <Btn small @click="backUp">Back up now</Btn>
        </div>
        <Toggle :model-value="data.backupsOn" @update:model-value="(on) => send('backup/setting', { on })">Back up automatically: nightly at 03:47 UTC and before every deploy's migrations</Toggle>
        <div v-for="b in data.backups" :key="b.object" class="flex justify-between gap-3 flex-wrap items-center">
          <span><b class="font-semibold text-ink-strong">{{ b.at }}</b> <span class="text-faint text-[13px]">· {{ b.kind }} · {{ b.size }} · {{ b.ago }}</span></span>
          <Btn small danger @click="restoreFrom(b)">Restore</Btn>
        </div>
        <span v-if="data.backups.length === 0" class="text-faint text-[13px]">None yet. {{ data.backupsOn ? 'One is taken nightly at 03:47 UTC and before every deploy\'s migrations.' : 'Automatic backups are off: switch them on above once the application holds data worth keeping.' }}</span>
        <span v-if="error('backup')" class="text-[13px] text-failed">{{ error('backup') }}</span>
        <template v-if="data.clusterBackups.length > 0">
          <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase mt-2">DigitalOcean's daily backups of {{ data.clusterBackups[0].cluster }}</span>
          <div v-for="b in data.clusterBackups" :key="b.created_at" class="flex justify-between gap-3 flex-wrap items-center">
            <span><b class="font-semibold text-ink-strong">{{ b.at }}</b> <span class="text-faint text-[13px]">· the whole cluster, kept by DigitalOcean</span></span>
            <Btn small danger @click="restoreFromCluster(b)">Restore</Btn>
          </div>
          <span class="text-faint text-[13px]">A second, independent copy: use these if the site's own backups above are missing or damaged.</span>
        </template>
      </div>
      <div class="flex flex-col gap-2">
        <span class="font-mono text-[10px] tracking-[0.18em] text-muted uppercase">Clone database</span>
        <div v-for="c in data.clones" :key="c.id" class="flex justify-between gap-3 flex-wrap items-center">
          <span><b class="font-semibold text-ink-strong font-mono">{{ c.name }}</b> <span class="text-faint text-[13px]">· {{ c.engine }} · {{ c.schema_only ? 'schema only' : 'schema and data' }}<template v-if="c.host"> · {{ c.host }}</template></span></span>
          <span class="flex gap-1.5">
            <Btn small title="Show the connection as .env lines. Revealing it is audited." @click="showClone(c.id)">Connection</Btn>
            <Btn small v-if="c.engine !== 'sqlite'" title="Copy the copy's connection as one URL. Audited." @click="copyConnection(c.id)">Copy string</Btn>
            <Btn small danger title="Remove the copy and its data" @click="deleteClone(c)">Delete</Btn>
          </span>
        </div>
        <pre v-if="cloneShown" class="whitespace-pre-wrap m-0 overflow-x-auto rounded-[10px] border border-line-strong bg-sidebar p-3 font-mono text-[12.5px] leading-[1.75] text-[var(--log-text)]"><template v-for="(v, k) in cloneShown.lines" :key="k">{{ k }}={{ v }}
</template></pre>
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Name of the copy" v-slot="{ id }"><input :id="id" v-model="cloneName" class="font-mono" placeholder="staging_check" autocomplete="off"></Field>
          <Toggle v-model="cloneSchemaOnly" class="self-end">Schema only, no data</Toggle>
          <Btn title="Dump this database now and load it into a new one" @click="cloneDatabase">Clone</Btn>
        </div>
        <Field label="Run these on the copy after it is restored (optional)" v-slot="{ id }">
          <textarea :id="id" v-model="cloneSql" rows="5" class="font-mono" spellcheck="false" placeholder="UPDATE users SET password = '{{bcrypt:secret}}';"></textarea>
          <span class="flex flex-wrap items-center gap-1.5">
            <span class="text-faint text-[13px]">Insert example:</span>
            <Btn small v-for="(e, i) in data.cloneExamples" :key="i" @click="addExample(i)">{{ e.label }}</Btn>
            <Btn small title="Every clone of this application starts with this text. Empty clears it." @click="saveCloneSql">Save for this application</Btn>
          </span>
          <span class="text-faint text-[13px]">Statements end with a semicolon and run in order on the copy only, in one transaction where the database allows it. If one fails the copy is dropped. &#123;&#123;bcrypt:word&#125;&#125; becomes a password hash and &#123;&#123;now&#125;&#125; the current time. Files, shells, other databases and roles are refused.</span>
        </Field>
        <span v-if="error('clone')" class="text-[13px] text-failed" role="alert">{{ error('clone') }}</span>
        <span class="text-faint text-[13px]">A fresh dump, not the newest backup. On a managed cluster the copy is a new database with its own user; with SQLite it is a file in shared/. Nothing is written to this database. Use Connection to paste it into an application's variables.</span>
      </div>
      <template v-if="data.localDatabases.length > 0">
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Put the data on this server's own database" class="flex-[1_1_180px]" v-slot="{ id }">
            <select :id="id" v-model="local"><option value="">Choose…</option><option v-for="d in data.localDatabases" :key="d.service" :value="d.service">{{ d.label }} on this server</option></select></Field>
          <Btn :disabled="local === ''" @click="useLocal">Set it up</Btn>
        </div>
        <span v-if="error('local')" class="text-[13px] text-failed">{{ error('local') }}</span>
        <span class="text-faint text-[13px]">Installed on the server if needed, listening on 127.0.0.1 only, with its own database and user for this application. Nothing is copied from the current database; migrations build the schema on the next deploy. Backups of it are included.</span>
      </template>
      <template v-if="data.clusters.length > 0">
        <div class="flex flex-wrap items-end gap-3">
          <Field label="Attach a cluster: a database moves the data, Valkey moves cache, sessions and queue" class="flex-[1_1_180px]" v-slot="{ id }">
            <select :id="id" v-model.number="cluster"><option :value="0">Choose a cluster…</option><option v-for="c in data.clusters" :key="c.id" :value="c.id">{{ c.label }}</option></select></Field>
          <Btn @click="attach">Attach</Btn>
        </div>
        <span v-if="error('cluster')" class="text-[13px] text-failed">{{ error('cluster') }}</span>
        <span class="text-faint text-[13px]">A database cluster gives the site its own database and user, never the admin account; data already in SQLite is not copied. Valkey has one password per cluster, so sites sharing one are kept apart by key prefix only.</span>
      </template>
      <span v-else class="text-faint text-[13px]">No online cluster yet. <a href="/databases">Create one</a>.</span>
  </Panel>
</template>

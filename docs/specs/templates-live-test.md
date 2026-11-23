# Templates step 2: live test checklist (RustFS, native runner)

Not run yet. Do it after deploying step 2, on a **throwaway 1 GB server** that has the server stack installed (nginx, certbot) and is online in the control plane. Do not use a server that holds anything. Commands run on the control plane unless marked (server).

Before you start: note the server id (`web-1.org-1` below) and its public IPv4 address, written with dashes for sslip.io, e.g. `203.0.113.7` becomes `203-0-113-7`. Use two names: `s3.203-0-113-7.sslip.io` and `console.203-0-113-7.sslip.io`.

## 1. Install through a domain with HTTPS

1. `php artisan service:install web-1.org-1 rustfs files --input=s3_host=s3.203-0-113-7.sslip.io --input=ui_host=console.203-0-113-7.sslip.io --input=storage=5GB`
   - `storage=5GB` matters: the default 50GB is refused on a 1 GB server's disk, correctly.
   - Expect stages check, fetch, prepare, run, proxy, health, then `== succeeded`.
2. `php artisan service:list` shows `files`, status `running`, both addresses `https://`.
3. (server) `systemctl status cm-svc-files` is active; `ps -o user= -C rustfs` prints `rustfs`; `getent passwd rustfs` shows shell `/usr/sbin/nologin`.
4. (server) `ls -ld /etc/cloud-manager/services/files.env` is `-rw------- root root`; `ls -ld /srv/cloud-manager/data/files` is `drwxr-x--- rustfs`; `ls -l /tmp/tmp.*` finds no leftover download in `/srv/cloud-manager/services/files/`.
5. (server) `systemctl show cm-svc-files -p MemoryMax -p NoNewPrivileges -p ProtectSystem -p PrivateTmp` shows 1073741824, yes, strict, yes. `sudo -u rustfs touch /srv/cloud-manager/services/files/releases/1/x` fails (read-only release).
6. From your laptop: `curl -sI https://s3.203-0-113-7.sslip.io/` returns an answer over a valid Let's Encrypt certificate (RustFS answers 403 or similar without credentials; a TLS error or a 502 is a failure). `curl -sI http://s3...` redirects with 301 to https. `https://console...` loads the RustFS console and the login accepts the keys from step 7.
7. Read the generated keys for the test only: on the control plane, `php artisan tinker --execute="echo app(App\Lib\Vault\Vault::class)->get('org-1', 'service:'.DB::table('services')->value('id').':access_key');"` (and `secret_key`). Do not paste them anywhere.
8. Check the other 1 GB-server worries: `free -m` (server) with RustFS idle, then after the upload below. Note both numbers in the roadmap row.

## 2. A large upload through nginx (the `proxy` settings)

1. Use an S3 client with the keys from 1.7, for example `aws --endpoint-url https://s3.203-0-113-7.sslip.io s3 mb s3://test` then `dd if=/dev/urandom of=/tmp/big bs=1M count=600` and `aws --endpoint-url https://s3.203-0-113-7.sslip.io s3 cp /tmp/big s3://test/big`.
   - Pass: it completes (no `413 Request Entity Too Large`, which would mean `client_max_body_size` is not 0) and does not stall at the start while nginx buffers the whole body (`request_buffering off`).
2. `aws ... s3 cp s3://test/big /tmp/big2` and `cmp /tmp/big /tmp/big2` is clean.
3. (server) `/etc/nginx/sites-enabled/cm-svc-files-9000.conf` contains `client_max_body_size 0;` and `proxy_request_buffering off;`.
4. Record the result in templates.md section 11, decision 5.

## 3. Stop, start, restart

1. `php artisan service:action web-1.org-1 files stop`; (server) `systemctl is-active cm-svc-files` is `inactive` and `systemctl is-enabled cm-svc-files` is `disabled`; the https address now returns 502; `service:list` says `stopped`.
2. `... start`: active and enabled again, the address works, status `running`.
3. `... restart`: `systemctl show cm-svc-files -p ActiveEnterTimestamp` changes; the address works.
4. A Developer may do these; check with `--as=<a developer's email>`. `--as=<a viewer's email>` is refused.

## 4. Failure and retry

1. Install a second service with a name whose domain does not resolve, for example `--input=s3_host=nope.example.invalid`. Expect the proxy stage to fail at certbot, the unit stopped, `service:list` status `failed` with the error text, and `/srv/cloud-manager/data/<name>` still present.
2. Install again with a good domain and the same name: it converges (same service, same generated keys, no second certificate if one was issued).
3. Remove it (see 5).
4. Port clash: `service:install web-1.org-1 rustfs again ...` while `files` exists is refused with "Port 9000 on this server is already used by the service files".

## 5. Remove, keeping and deleting data

1. Upload one small object. `php artisan service:remove web-1.org-1 files` with no flag is refused ("keep it, or delete it").
2. `php artisan service:remove web-1.org-1 files --keep-data`. (server) the unit, the env file, `/etc/nginx/sites-enabled/cm-svc-files-*.conf`, `/srv/cloud-manager/services/files` and the two certificates (`certbot certificates`) are gone; `/srv/cloud-manager/data/files` is still there with the object's files.
3. Install `files` again with the same inputs. It starts with the kept data folder; the generated keys are new (the old ones went with the service), so check whether RustFS needs the old keys to read the kept data and write that down.
4. `php artisan service:remove web-1.org-1 files --delete-data`: `/srv/cloud-manager/data/files` is gone.

## 6. Secrets are absent from logs

On the control plane, with the keys from 1.7 in the shell variables `AK` and `SK`:

```
php artisan tinker --execute='foreach (["operations","audit_records","services","service_endpoints","log_lines"] as $t) echo $t, " ", strlen(json_encode(DB::table($t)->get())), "\n";'
php artisan tinker --execute='$d = json_encode([DB::table("operations")->get(), DB::table("audit_records")->get(), DB::table("services")->get()]); foreach ([getenv("AK"), getenv("SK")] as $s) var_dump(str_contains($d, $s));'
grep -r -F -e "$AK" -e "$SK" storage/logs && echo LEAK || echo clean
```

Both `var_dump`s are `false` and the grep prints `clean`. (server) `journalctl -u cm-svc-files | grep -F -e "$AK" -e "$SK"` finds nothing, and `ps aux | grep -F "$SK"` finds nothing (the keys are in the environment file, never an argument).

## 7. Step 4: Docker and Uptime Kuma (containers)

Not run yet. A second **throwaway 1 GB Ubuntu 24.04 server** with the server stack, online in the control plane. Use `kuma.203-0-113-7.sslip.io` style names. (server) means on the server.

1. Baseline: (server) `free -m` and note "available". `php artisan server:containers web-2.org-1` is refused with the memory text; the console Containers page shows the warning and the Prepare button stays disabled until the box is ticked.
2. `php artisan server:containers web-2.org-1 --acknowledge-small`. Expect stages check, install, configure, verify, `== succeeded`. (server) `docker version`, `systemctl is-active docker`, `cat /etc/docker/daemon.json` matches the spec, `ss -ltn | grep -E ':(2375|2376)'` finds nothing, `ls /etc/apt/keyrings/docker.asc`, and `gpg --show-keys /etc/apt/keyrings/docker.asc` shows 9DC858229FC7DD38854AE2D88D81803C0EBFCD88.
3. Run step 2 again: succeeds, nothing restarts (`systemctl show docker -p ActiveEnterTimestamp` unchanged).
4. Memory with Docker on 1 GB: (server) `free -m` now, with no containers: write down the difference from step 1 (the spec's estimate is about 100 MB). Record it in the spec.
5. Install: `php artisan service:install web-2.org-1 uptime-kuma kuma --input=host=kuma.203-0-113-7.sslip.io`. Expect `== succeeded`. Open `https://kuma.203-0-113-7.sslip.io/`, create the admin account, add a monitor. (server) `docker ps`, `docker inspect cm-svc-kuma --format '{{.HostConfig.CapDrop}} {{.HostConfig.SecurityOpt}} {{.HostConfig.Memory}} {{.HostConfig.PidsLimit}} {{.HostConfig.RestartPolicy.Name}}'` shows `[ALL] [no-new-privileges] 536870912 512 unless-stopped`, `docker image inspect --format '{{.RepoDigests}}' louislam/uptime-kuma` has the digest from the template, `ls -l /etc/cloud-manager/services/kuma.env` is `-rw-------` root, `docker volume ls` has `cm-svc-kuma-kuma`.
6. **Not reachable from outside.** (server) `docker port cm-svc-kuma` shows `127.0.0.1:23000`. From your own computer: `curl -m 5 http://<server ip>:23000/` and `curl -m 5 http://<server ip>:3001/` both fail to connect, with the firewall off and then on (console Firewall settings, web ports anywhere). `nmap -p 3001,23000 <server ip>` shows them closed or filtered. `curl -sI https://kuma...` still works.
7. Memory with Uptime Kuma running: (server) `free -m`, `docker stats --no-stream`; the console Containers page after Refresh shows the same container memory. Leave it for ten minutes and check it stays under 512 MB with one monitor. If Kuma is killed, `docker inspect cm-svc-kuma --format '{{.State.OOMKilled}}'`.
8. Stop, start, restart from the service page and `service:action`: `docker ps -a` shows the state, the address answers after start, and after a stop the site returns a 502 from nginx. Reboot the server: Kuma comes back by itself after a restart but stays stopped if it was stopped before the reboot.
9. Failure: install a second one with a domain that does not resolve (`kuma2`). Expect the proxy stage to fail, `docker ps -a` shows no `cm-svc-kuma2` and `docker volume ls` still shows its volume. Reinstall with a good domain and the same name: it converges on the same host port.
10. Remove keeping the data: `php artisan service:remove web-2.org-1 kuma --keep-data`; the container and `kuma.env` are gone, the volume is still there. Reinstall `kuma`: the admin account and monitor are still there. Then `--delete-data`: `docker volume ls` no longer has it.
11. Cleanup: (server) make a dangling image: `printf 'FROM alpine:3.20\nRUN echo a\n' | docker build -q -t scratch - && printf 'FROM alpine:3.20\nRUN echo b\n' | docker build -q -t scratch -` (the first image loses its tag and is dangling). Refresh the Containers page: Images shows reclaimable space. Click Clean up unused layers: the operation log shows the two prune commands and their "reclaimed" lines; the running Kuma, its volume and its image are untouched (`docker ps`, `docker volume ls`); `docker rmi scratch` afterwards. Check the audit page for `server.containers_cleaned`.
12. Firewall sentence: the Firewall settings panel in Servers shows the sentence about containers and 127.0.0.1. Gitea (extra port 2222) is separate: with the firewall on, installing it with ports approved adds `ufw status` 2222/tcp (not offered yet; try it by command).

## 8. Step 5: backups, restores and updates

Not run yet. Use the RustFS server from section 1 and the Uptime Kuma server from section 7 (both can be the throwaway ones; give each some data first: an object in RustFS, a monitor in Uptime Kuma). To test an update you need a newer template. A local-folder file with a built-in's id is refused (duplicate id), so bump the built-in file on a throwaway branch of the control plane: `version: 2` in `templates/rustfs.yml` with a download that exists and its real sha256; the same for `uptime-kuma.yml` (a newer tag and its digest). (server) means on the server.

**Backups**

1. RustFS: `php artisan service:backup web-1.org-1 files`. Expect one stage, `== succeeded`. (server) `ls -l /var/backups/cloud-manager/services/files/` shows `<stamp>-manual.tar.gz`, mode `-rw-------` root, in a `drwx------` folder; `tar -tzf` lists `files/` with the object's data; the service stayed up (`systemctl is-active cm-svc-files`, no gap in `journalctl -u cm-svc-files`). The service page Backups list shows it with a size and "this server only".
2. Consistency: `backup: { stop: true }` is now set in the template, so the unit is stopped meanwhile (the log says so, the address returns 502 for the seconds it takes). Take a backup while uploading a 600 MB object (section 2): does it restore to a working store? How long was the gap for a large store? If the gap is too long, say so here and reconsider.
3. Uptime Kuma: `service:backup web-2.org-1 kuma`. (server) the archive holds `kuma/` (the volume `cm-svc-kuma-kuma`, as `/data/kuma/...`: `tar -tzf` shows `./kuma/kuma.db`). **Check that the service's own image has `tar`** (the throwaway runs `docker run --rm --entrypoint tar <the pinned image>`). If it does not, the failure message says so; record it here and the fix (a different image for backups) is open. `docker ps` shows no leftover container and `docker images` shows no new image (nothing was pulled).
4. SQLite: Kuma's database is SQLite in WAL mode and its template now says `backup: { stop: true }`, so the container is stopped for the copy; check that the backup restores (step 7) and note the downtime.
5. Bucket: run `php artisan backup:offsite <org>` if there is no bucket. Create a service with the Backups choice `spaces`, back it up: the log says it was uploaded; the bucket has `services/<id>-<name>/<stamp>-manual.tar.gz` (check in the Spaces console); (server) `ls -la /var/backups/cloud-manager/services/<name>/` has no `.transfer.cfg` left. The Backups list says "this server and the backup bucket". With the choice `snapshot`, nothing is uploaded. With `spaces` and the bucket removed, the log says "there is none" and the copy stays on the server.
6. Nightly: `php artisan services:backup` queues one backup per running or stopped service whose choice is not `none`; a service set to `none` is skipped and its manual button still works. Check `/var/lib/cloud-manager/service-backups.log` after the real 03:57 run on the host once `deploy.sh` has installed the cron file (`grep services:backup /etc/cron.d/*`).
7. Retention: the age is the row's `created_at`, not the file's time. In tinker set an old backup row's `created_at` to 20 days ago, then take another backup of that service: the old archive is removed from the server and the bucket, and the newest backup is always kept.

**Restore**

8. RustFS: delete the test object, then restore the backup from the service page (type the name). Stages snapshot, stop, restore, start, health; a `pre restore` backup now appears in the list; the object is back; `ls -ld /srv/cloud-manager/data/files` still `drwxr-x--- rustfs`, files owned by `rustfs`; no `files.before-restore` or `.cm-restore-files` is left over.
9. Uptime Kuma: delete the monitor, restore: the monitor is back, the container runs with the same flags (`docker inspect`), and `docker volume ls` still shows one volume. If the image has no `sh`, the failure says so and the service is started again.
10. Restore with the wrong name typed is refused; `service:restore web-1.org-1 files <name> --confirm=files` works. Restoring a bucket-only backup: move the local archive away (`mv`), restore: the log shows the download, then it works.
11. A viewer and a developer see no Back up now, Restore or Update buttons; both are refused over the API (a `deploy` token gets 403).

**Updates**

12. RustFS: the service page and the Services list say "Update available: version 2" with the changes (download, checksum). `service:update web-1.org-1 files --check` prints the same. Press Update: stages snapshot, fetch, switch, health. (server) `ls /srv/cloud-manager/services/files/releases/` shows `1` and `2`; `systemctl cat cm-svc-files | grep ExecStart` points at `releases/2`; the data is untouched and the object is still served; the generated keys are unchanged (compare with section 1.7); `/var/backups/cloud-manager/services/files/update-prev` is gone; the audit page has `service.updated` with both versions and the checksum.
13. **Forced bad health (must roll back).** Publish version 3 whose `health` port is wrong (`health: { port: 9999 }`) or whose command exits at once. Update: the health stage fails after 60 seconds, the log says "putting version 2 back", the operation is failed, the service page shows "running" on version 2 with the rolled-back note, `systemctl cat cm-svc-files | grep ExecStart` is `releases/2` again, `systemctl is-active` is active, the object is served, `releases/3` is still there (never removed on a failure), audit has `service.update_rolled_back`. Do the same for Uptime Kuma (a digest of an image that starts and exits): `docker ps` shows `cm-svc-kuma` running the old image, `docker ps -a` no `cm-svc-kuma-previous`.
14. Double failure (optional, destructive): make version 3 fail and also `chmod -x` the version 2 binary so the put-back cannot start: the service is `failed` with both errors and the page says the backup is in the list. Then restore the permissions and press Restart.
15. Previous release gone: `rm -rf /srv/cloud-manager/services/files/releases/<installed version>` (throwaway only), press Update: refused before anything changes, with the message naming the folder.
16. Kuma: after an update `docker ps` shows the new image digest (`docker inspect cm-svc-kuma --format '{{.Image}}'`), the monitor and data are still there, `docker image ls` shows the old image kept, and after three updates the oldest image is removed while the running one and the two before it stay. RustFS: after three updates `ls releases/` shows the running one and two others.
17. Host change: a version whose download is on another host shows the highlighted notice and the Update button does nothing until the box is ticked; over the API a POST without `"confirm": true` is 409. A version that changes the extra ports or adds a required input asks for them.
18. Secrets: after every update above, `grep -r -F -e "$AK" -e "$SK" /var/lib/... storage/logs` as in section 6 finds nothing in operation rows, audit or logs, and `ps aux | grep -F "$SK"` is empty during the update.

## 9. Applications from a Dockerfile and from an image (step 6)

Use a throwaway **1 GB** server prepared for containers (section 7), a public repository with a small Dockerfile app (a web server that prints its commit and answers `/up`, with an `EXPOSE`), a second branch or commit that changes the output, a second Dockerfile that exits at once, and a private image in a registry you control with a token. DNS for two domains (or use the sslip.io address).

**Dockerfile application**

1. New application, From a Dockerfile: pick the repository. The page says "We found a Dockerfile", shows the EXPOSE port, and notes a `docker-compose.yml` if the repository has one ("noticed and never run"). The server list marks the 1 GB server "Under 2 GB: building here uses the server's memory". Create with a domain and a health path.
2. The first deploy: stages setup, fetch, build, start, health, switch, prune; `== succeeded`. (server) `docker images` shows `cm-app-<name>:<commit12>`; `docker ps` shows `cm-app-<name>-23000` published as `127.0.0.1:23000->…`; `ss -ltnp | grep 23000` is `127.0.0.1` only; `docker inspect cm-app-<name>-23000 --format '{{.HostConfig.CapDrop}} {{.HostConfig.SecurityOpt}} {{.HostConfig.Memory}} {{.HostConfig.PidsLimit}} {{.HostConfig.Privileged}} {{.HostConfig.NetworkMode}}'` shows `[ALL] [no-new-privileges] … false bridge`. Does the build finish on 1 GB? Record the memory used (`docker stats`, `free -m`) and whether the OOM killer fired (`dmesg | grep -i kill`).
3. HTTPS: the https operation issues the certificate; `curl -sI https://<domain>/` is 200 (or the app's answer). From **another machine**: `curl --max-time 5 http://<server ip>:23000/` fails (the port is not reachable from outside), and so does the port the container itself listens on.
4. **Zero downtime.** In one shell run `while true; do curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/up; sleep 0.2; done | uniq -c`. Change the app's output and Deploy again. Expect only 200s through the whole deploy (a few 502s would mean the switch is not seamless: record how many), the page shows the new output afterwards, `docker ps` shows one container (`cm-app-<name>-23001`), the old one is gone, and `/etc/nginx/sites-enabled/<name>.conf` proxies to 23001. A third deploy goes back to 23000.
5. **Forced bad health.** Change the Dockerfile so the app does not answer (or set the health path to `/nope`) and Deploy. The health stage fails after 60 seconds, the operation says "the live release is unchanged", `docker ps` shows only the old container, `docker ps -a` shows no `cm-app-<name>-<newport>`, the curl loop shows 200s throughout, and the Container page still shows the old port. Also try a Dockerfile with a syntax error (build fails, nothing started) and a container that exits at once (the health stage fails fast with "the container stopped").
6. **Rollback.** After two good deploys press Roll back: the previous image starts on a new port, nginx switches back, the output is the previous one, `docker ps` shows one container, the loop shows no failures. Press it again: it goes forward. After four deploys `docker images` shows the live image and two older ones, no more; `docker image ls` never lost the running one.
7. Variables: add a secret on the Variables page, deploy, then `docker exec <container> env | grep <KEY>` shows it; `ps aux | grep <value>` and `grep -r <value> /var/log` find nothing; the file `/etc/cloud-manager/apps/<name>.env` is `-rw-------` root. The operation log and the audit page show no value.
8. Memory limit: set it below what the app needs; the container is OOM-killed and the health stage says it stopped.
9. Refusals: the scheduler, workers, background processes, Reverb, previews, a second server, a load balancer and autoscaling are absent from the pages and refused (with a plain message) over the API and the command line.
10. Delete the application with and without "delete data": containers, the env file, images and (with data) volumes are removed; without, volumes stay.

**Docker image application**

11. From a Docker image with `<registry>/<image>:<tag>`: the review shows "Unharbored has not reviewed this image". First deploy: the log shows `pinned sha256:…`; `docker ps` shows the container running `<image>@sha256:…`; the Container page shows the same digest. `docker pull <image>:<tag>` on the server later (or push a new image under the same tag) does not change what runs.
12. **Update by button.** Push a new image under the same tag. Container page, Check for an update: it shows the running digest and the new one. Press Update to this digest: it starts beside the old one, switches, and the page shows the new digest; the old image is kept for a rollback. With the tag unchanged the check says so and offers nothing. A tag that no longer exists fails the check with the registry's message.
13. **Private registry.** Create the application with a registry user and token. (server) during the pull, `ls -ld /etc/cloud-manager/docker-auth/<name>` is `drwx------ root`; afterwards neither it nor `<name>.token` exists, and `~root/.docker/config.json` has no entry for the registry. `ps aux` during the pull shows no token; the operation log, audit page and the application's rows contain none. A wrong token fails with the registry's message and still leaves nothing in `/etc/cloud-manager/docker-auth/`. Change and remove the login on the Container page.
14. A digest given in the reference (`image@sha256:…`) is pulled and verified; a wrong digest fails with "The registry answered …".
15. Data folders: give `/data`; `docker volume ls` shows `cm-app-<name>-data0`; data written there survives a deploy and a rollback.

**Backups (step 5 follow-up).** With `backup: { stop: true }` now on RustFS and Uptime Kuma, repeat sections 8.1 to 8.4: the service is down only while the archive is made (the log says "stopped meanwhile"), `docker ps` shows Kuma running again afterwards, and a restore of a backup taken this way is clean.

## Not covered here

Section 7 covers Docker and Uptime Kuma. The GitHub runner and Gitea are not offered; their definitions are unverified and need their own live test (the runner's registration with a real token and its ephemeral restart, Gitea's `config edit-ini --apply-env`, built-in SSH on 2222 and its firewall approval in step 3).

## Results: first live pass, 2026-11-16

Two throwaway 1 GB droplets (Ubuntu, nyc3), deployed code at the security-review commit plus the fixes below. Both destroyed afterwards.

**Passed**
- RustFS (native): install with HTTPS (sha256 verified, `cm-rustfs` user, no shell, 0600 env file, 0750 data, hardening flags as written), a 300 MB upload and download through nginx (no 413, identical bytes), stop/start/restart, port clash refused, failed install (unresolvable domain) then retry converges, remove with keep and with delete of data, restore after delete, update 1 to 2, and automatic rollback of a bad version 3 (version 2 put back and serving, release 3 kept).
- Docker preparation on 1 GB: pinned key accepted (one key in the file), idempotent re-run, no TCP socket. Docker costs about 60 MB of available memory (the spec estimated 100 MB).
- Uptime Kuma (container): hardening flags, ports closed from outside, backup and restore (marker file), update, and rollback of a version that never answers.
- Dockerfile application: clone, symlink-guarded build, start beside, health, nginx switch, HTTPS; three redeploys with 16 and then 99 consecutive 200s (zero downtime); a forced bad health left the live release untouched; rollback. Image application: digest pinned on first pull, update check reports unchanged.
- Secrets: neither generated RustFS key appears in operations, audit, services or the logs; secrets absent from the control plane's database dump outside the vault.

**Bugs found and fixed**
1. `symfony/yaml` was a dev dependency; production installs without dev packages, so templates could not load there. Moved to `require`.
2. Uptime Kuma crash-looped under `--cap-drop ALL` (EACCES creating its data folders). Added `runs.capabilities`, an allowlist (CHOWN, DAC_OVERRIDE, FOWNER, SETUID, SETGID, NET_BIND_SERVICE); Kuma needs DAC_OVERRIDE only.
3. The health stage passed on the first answer while the container then died. It now also requires ten seconds up with no restart.

**Not covered**: private registry login, the Spaces bucket backup choice, nightly cron, retention, Kuma with a real account and monitor, a host reboot, the double-failure case, Gitea and the GitHub runner, build memory on a larger repo, the UI pages in a browser, and the viewer/developer button checks (permissions are covered by tests only).

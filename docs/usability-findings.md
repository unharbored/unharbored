# Usability findings

Notes from first-time-user tests: agents that had never seen the product, using only the browser, against a local sandbox (no connected server, so operations queue but do not finish). Each finding says how many testers hit it.

## Round 1, 2026-11-16

Personas: freelance developer (new app from a GitHub repo), non-technical founder (public Docker image), careful sysadmin (delete an app and its data), hobbyist (storage service, alerts, invite a viewer).

### Fixed

- New application wizard: Review looked dead when the planner's first error was `provider`, `storage` or `previews`, which the page never showed. All now shown; a test fails if any planner error key has no place on the page. (freelancer)

### Open, most repeated first

1. **No usable server** (all four). The server picker is disabled but Review says "pick one of the servers listed". "Prepare it" leaves the form and loses what was typed. The offline error shows an internal name (`web-1.org-1`) and no next step.
2. **Raw `@else FITS` text** in the RustFS install form's Server row (hobbyist). Probably the directive-adjacency problem: Unscripted needs a space between `@endif` and a following `@if`.
3. **"Gateway unreachable" banner** (all four). No explanation, no link. The Servers page explains it at length.
4. **Status strings.** "DEPLOYING build 15863m" (should read as days or "stuck"), "000712Z" as a release label, "1 rows". The first two are old local seed data but show how a real stuck deploy would look.
5. **Delete application** (sysadmin).
   - Confirmation is a browser dialog plus one tick box; nothing lists what will be deleted (domain, server, database file, uploads, secrets, the running deploy). Replace with a modal that lists them and asks for the app name.
   - "This cannot be undone" in the header conflicts with the footnote saying data stays unless the box is ticked. Split into "Remove the app" and "Remove the app and erase its data".
   - The "pending changes, Deploy now" banner shows on the Delete tab.
   - "vault" and "stored secrets" are jargon; name the database and files.
6. **Alerts** (hobbyist). "Nothing needs you" shows green while no destination is set and the gateway is down. Email setup says to edit `MAIL_MAILER` in `.env`. Thresholds are bare inputs; write each as a sentence ("Alert when CPU is above 90% for 10 minutes").
7. **Jargon** (founder, hobbyist, freelancer). Docker image form: digest, registry, volume, read-only root filesystem, health check path, 127.0.0.1. Container port is unexplained (nginx uses 80). "run php artisan github:setup" shown to UI users. Domain placeholder mentions sslip.io with no DNS hint. Backup options (snapshot, spaces, none) unexplained.
8. **Navigation.** People and invitations live under the small top-right Account link. Invite role defaults to Developer, not Viewer. Four similar "new application" buttons on the Applications page. Services vs Applications vs Databases is not explained.
9. **Sign-in.** The password field arrived with six dots and the first attempt failed; the lockout message does not say how long to wait. Partly caused by testers sharing one login, but the autofill and the message are worth checking.
10. **Smaller.** Registry user and token fields picked up the login email through browser autofill (add `autocomplete="off"`, label "only for private images"). "Framework: not found" reads like an error after choosing a type. Templates "Not yet available" with no reason. Memory pill oversized on one template card. "Catalogue" and "templates" used for the same thing.

### What testers found clear

Delete tab named plainly with a non-destructive default and "it never destroys a machine"; the three-step wizard indicator; prefilled name and branch; "Detection is a suggestion"; "This can't be changed after the app is created"; the Alerts intro and Send a test alert; the invite copy and role descriptions; the Servers page.

### Limits of this round

- One shared browser and one shared login: testers hit the sign-in lockout and may have collided. Run them one at a time, or give each its own account.
- A browser `confirm()` dialog cannot be seen or answered by the testing tool, so any button using `u-confirm` looks like it does nothing. Testers need to be told to stop and report when they press one.
- No connected server, so nothing past "queued" was seen: deploy progress, failure messages, logs, rollback and the real result of a delete are untested.

## Round 2, 2026-11-17

Eight more first-time-user tests, this time against a local console with a fake gateway (`scripts/dev/fake-gateway.py`) so operations ran and finished, with one login per role and three local hostnames so testers did not share sessions. Personas: Viewer, brand-new owner with an empty account, security admin (HTTPS, own certificate, password), developer (deploy and rollback), on-call (a deploy that fails on purpose), hobbyist running a RustFS service from creation to removal, keyboard-only accessibility, phone-width layout.

### Fixed during the round

- A refused page showed a 500 with a stack trace to a Viewer (a view wraps the exception); now a plain "Not allowed" page. Nav hides Audit log and API tokens, and Applications hides the creation buttons and audit link, for roles that cannot use them.
- Component confirmations (`u-confirm`, 56 of them) used the browser's native dialog, which blocked testing and shows no consequences. They now use the console's own dialog.
- The failed-operation alert scan stopped at the first unfinished operation, so one stuck operation hid every later failure. Fixed, with a test.
- New application wizard: errors for `provider`, `storage` and `previews` had no place on the page, so Review looked dead. Fixed, with a test that every planner error key is shown.
- `@can` is not supported inside components (printed as text); permission flags in view data instead. Deploy, Roll back, Refresh and Renew only show to Developer and up.

### Fixed after the round (same day)

- Leaked `@else FITS` text on the install form and servers page: a directive glued to the word before it is printed as text by Unscripted's compiler. Fixed, and a test now fails if it happens in any component.
- New application wizard says "You need a server first", with links, when only Custom is available and no server exists.
- The site page now says when the last operation failed and what is still being served, and shows what is live (release, commit, how long ago).
- Operation pages: outcome notice with next links, steps that never started say "not run" instead of "waiting", the error wraps and has a Copy button.
- Certificate upload never updated the page: the "Queued" panel used a server class the browser does not have, so the redraw crashed. Found by doing it in the browser. Also fixed in the Containers view; a test now fails if a component template names a server class. Refresh opens its operation; the Domain card says "Your own certificate".
- Rollback: the deployments list names the release a rollback went to, the release serving carries LIVE, and the audit log names the person who deployed or rolled back instead of "control-plane" (pushes and schedules keep their own label). A rollback where the server does not say which release it switched to now fails and leaves what is shown as live alone, instead of recording a blank.
- Install forms grey out a server whose port is taken, with the reason, before Review (not after picking it); "Prepare it" opens in a new tab with a "Check the servers again" button so what was typed is kept.
- View-only roles: the Servers page and each application's Settings tab show a plain note and every control inside is switched off (a disabled fieldset), the add-server forms are hidden, so Reveal and the other buttons that only produced a refusal are no longer offered. Developers on the Servers page still see the buttons (they can reveal the sudo password, which needs the console permission) and get the server's refusal for infrastructure changes.
- Phone width: the Applications table becomes one labelled card per row, the nav wraps instead of hiding Logs, Alerts, Audit log and API tokens, inputs are 16 px (no iOS zoom), buttons and tabs are at least 44 px tall. No sideways overflow on Applications, a site, Servers, Services or Alerts at 390 px.
- Alerts: a notice when nowhere is set to send alerts, no green "Nothing needs you" in that case, and the mail wording no longer sends users to a `.env` file.
- Delete application lists what will happen (stop serving the domain, remove from the console, data kept or erased depending on the box), the data box says it erases for good, and the application's name must be typed. The API keeps its own explicit flags.
- Developers on the Servers page: infrastructure controls are switched off like for a Viewer, but the sudo password Reveal (which their role allows) stays usable.
- Help text: every built-in template input explains itself (DNS A record reminders, storage units, what snapshot/spaces/none mean); the Docker image form explains container port, health check, memory limit, domain, data folders and what "the version is looked up once" means; "run php artisan github:setup" and "Droplets in the connected DigitalOcean account" are gone.
- An account with no applications sees a Getting started panel on the Applications page (server, GitHub, first application, alerts, team), with steps ticked as they are done.
- Navigation links have real accessible names and a shortcuts attribute; there is a skip link; the certificate row wraps so the site page no longer overflows at phone width.

### Open, ranked

**A. Silent or hidden failure (fix first)**
1. Install forms and the wizard disable nothing and say nothing when no server can take the job: the server option should be disabled up front with the reason (offline, not prepared for containers, port in use), Review should explain itself, and "Prepare it" should not lose the form. Seen by 6 of 8 testers across the Docker image form, the application wizard, the RustFS form, and Add a server. (service-new, container-app-new, new-app, servers)
2. A failed deploy is not loud: no alert (until the stuck-operation fix and a scheduler), the Applications list and site page do not say "last deploy failed, still serving X", the failure page has no Retry, Copy, or next step, a build error is attributed to the Setup step, and later steps say "waiting" instead of "skipped".
3. After Refresh certificate or Upload certificate nothing changes on screen until a reload; the Domain card keeps saying "Let's Encrypt" after a custom upload.
4. The RustFS install form only shows a port conflict after the server is picked.

**B. Know what is live**
5. The site page does not say which release is live, since when, or by whom. After a rollback no deployment row says LIVE and the rollback row does not name the release it restored. Release names are timestamps, the commit column shows a dash, "who" is "Command line" or "control-plane".
6. Deploy and rollback result screens have no outcome banner or next actions (Visit site, Roll back, View deployments); the rollback confirmation does not say what changes or that the database is not rolled back.
7. Audit log records deploys with actor "control-plane" instead of the person; rollback rows do not name the release; each deploy spreads over about ten command rows.

**C. First run and wording**
8. A new account lands on an empty Applications page with four similar buttons and "New site, above" next to a button called "New application". Needs a getting-started panel: connect a provider or add your own server, wait for Ready, connect GitHub or paste a repo URL, create the application, add variables and a domain, deploy.
9. Jargon shown to users: "run php artisan github:setup", Droplets, digest, registry, health check path, 127.0.0.1, "Agents serve build dev", gateway, apply_recipe, PEM, certbot. The "gateway unreachable" pill has no explanation or link.
10. Install and create forms have no help: domains and DNS (and the server's IP address, which review says is "not known"), storage units, what snapshot, spaces and none mean, container port (nginx uses 80).
11. Finished install screen has no "what next": no link to the service, addresses, credentials, DNS reminder. The credential reveal has no copy button or sensitivity note. After "keep data" nothing lists the leftover data and backups.
12. Alerts says "Nothing needs you" in green when no destination is set; email setup tells people to edit `.env`; thresholds are bare inputs.
13. Delete application: a native-style single confirmation with no list of what goes (domain, database file, uploads, secrets, the running deploy), and it mixes "cannot be undone" with data that stays unless ticked.
14. Teams and People hide under the small Account link; the invite role defaults to Developer instead of Viewer.
15. Certificate display shows no year or issuer; truncated-certificate error is misleading; HTTPS can only be turned on in Settings although "HTTPS: off" on the Environment page looks clickable; the pending-changes banner appears for settings that apply at once.

**D. Role UX still open**
16. Viewers see enabled "Reveal" on the sudo password and variables (the server refuses; the buttons are misleading), the add-server forms, and the whole Settings tree. Settings (about 45 actions) is not gated per button.

**E. Accessibility**
17. Nav links are named by their shortcut ("G S"); no skip link; form errors not announced or linked to fields and some labels not tied to inputs; the confirm dialog is not exposed as a labelled dialog and focuses Confirm first; two theme buttons; no `aria-current`; no live regions; small uppercase monospace labels near 3:1 contrast (by eye).

**F. Phone width (390 px)**
18. The site page overflows sideways (651 px) because the certificate row does not wrap; the Applications table hides Last operation and Source with no cue; the nav strip hides Logs, Alerts, Audit log and API tokens behind an unmarked scroll; many tap targets under 40 px; form fields under 16 px make iOS zoom; labels 9.5 to 10.5 px.

### Not tested or limited

- The Manager role never ran (the on-call tester was signed in as an owner); the Developer role only through deploy and rollback.
- The fake gateway returns empty command output, so screens that read it (Docker version, certificate dates, log lines) look emptier than they will on a real server.
- Contrast was judged by eye, not measured.
- Window resize does not work through the browser tool; phone width was tested in a 390 px frame, which exercises the page's own responsive rules but not a real phone's browser chrome.
- Session sharing between hostnames was reported once and not reproduced; logout only ends its own session.

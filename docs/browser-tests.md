# Browser tests run by agents

Test cases an agent works through in a real browser, as a person would,
against the live console. They find what unit and component tests cannot:
a click that throws in the browser, a page that opens scrolled to the
bottom, a wrong default, a confusing message.

The first run (2026-11-08, the new application wizard) found four bugs the
test suite had passed: a repository click that did nothing, steps opening
at the old scroll position, `.env.example` renaming the app "Laravel", and
a review that did not say which cluster.

## Procedure

**Who runs them.** An agent with the Claude in Chrome extension, in the
person's own browser, already signed in to the console. The agent never
types a password, token or key; a case that needs one is run by the person.

**Before a run.**
1. Note the deployed commit (`git log --oneline -1` on master, and that
   it was deployed).
2. Open a new tab for the run; close it at the end.
3. Pick the cases: by default every **R** case. **C** cases when asked.
   **B** cases only when the person says so for that run, case by case.

**During a case.**
1. Follow the steps in order. Do only what the step says: no other
   buttons, no exploring other pages.
2. After every action, check the browser console for
   `Exception|Error|Uncaught`. Any match fails the step, whatever the page
   shows.
3. Compare what is on screen with **Expect**. Read the text, not only the
   layout: wrong numbers, names or defaults are failures.
4. On a failure: take a screenshot, note the step, expected and actual,
   and the console line, then go on to the next case. Do not fix anything
   mid-run.
5. Never press Create, Delete, Deploy, Save or Revoke unless the step says
   so and the case's class is allowed for this run.
6. Do the **Cleanup** even when the case fails, and check that it took.

**Report.** One table, then the failures:

| Case | Result | Note |
|---|---|---|
| R5 | pass | |
| R6 | fail | step 4: cost shows ~$29, expected ~$65–161 |

Each failure: the step, expected, actual, console line, screenshot. Say
which cases were skipped and why, and what the run created and removed.

**Cases that need his credentials.** Some C and B cases need something
only he can provide: a signed-in browser, a GitHub action, a token typed
into a prompt. Before the run, list those cases and exactly what each needs
from him, and ask once. He does the credential step himself; you wait for
him to say it is done, then carry on. Never ask him to paste a secret into
chat, and never type a password or token into a field. A case whose
credential step is not done is reported as **blocked**, not failed.

**Running many at once.** Cases in different classes share one browser
session, so run them in one agent, one tab at a time. Several agents in
parallel only for **R** cases, each in its own tab.

**Classes.**

| Class | Meaning | When |
|---|---|---|
| **R** | Read-only: looks, clicks through, stops before anything that changes state | Any time |
| **C** | Creates something free and removes it within the case | When asked |
| **B** | Bills money, changes a live site, or acts outside the console (GitHub) | Only with the person's go-ahead for that run |

## Read-only cases

**R1 · Sign-in redirect.** Open `/sites` signed in. Expect: the Sites page,
the sidebar with Sites, Servers, Databases, Logs, Alerts, Audit log, API
tokens; the gateway shows connected and a node count.

**R2 · Navigation and shortcuts.** Click each sidebar link in turn. Then on
Sites press `g` then `v`, `g` then `d`, `g` then `s`. Then click
[ Light mode ], reload, and switch back. Expect: each page opens with its
own heading and no console errors; the shortcuts open Servers, Databases
and Sites; the theme survives the reload.

**R3 · Sites list to a site.** On Sites, read each row; click `demo`.
Expect: each row has a URL, a server, a live commit and a last operation;
demo's page shows the live release and earlier releases.

**R4 · An operation.** From Sites › Recent activity, open the newest
operation. Expect: title, who started it, each stage with a status and
time, the log; a failed one shows its error.

**R5 · New application, single server, to review.** Sites › + New
application. Select `your-org/demo-app`. Continue. Keep
Single server, New server, New York 3, 1 vCPU · 2 GB, SQLite, cache in
the database, the server's disk. Click Start from .env.example. Review.
Then Back. Expect: step 1 shows Laravel, PHP and Vite for `main`, and fills
the name; each step opens at the top of the page; the variables do not
include APP_NAME, APP_KEY, APP_URL or any DB_, REDIS_ or AWS_ key; the
review says single server, a new server in nyc3, SQLite, cache in the
database, disk, about $12/mo, and the steps server → address → deploy →
https; Back returns to step 2. **Do not press Create application.**

**R6 · New application, autoscaling, to review.** As R5 to step 2, then
choose Autoscaling. Expect: the Pool section (fewest 1, most 4, 70% CPU);
the database switches to PostgreSQL on a cluster, the cache to Valkey,
uploads to a Spaces bucket; SQLite is not offered; one line says disks are
not shared; the review shows a cost range and ends with balancer →
balancer-address → pool, and says autoscaling is fixed once created.
**Do not press Create application.**

**R7 · New application, a bad name.** As R5, but change the name to
`Bad Name`, then Continue and Review. Expect: the review is not reached; an
error says lowercase letters, digits and dashes.

**R8 · Servers.** Open Servers. Expect: each server with connected or not,
agent version, and a metrics chart; switching the chart (CPU, memory,
disk) and range redraws it.

**R9 · Databases.** Open Databases. Expect: `main` (PostgreSQL) and `cache`
(Valkey) listed as online. **Do not press Destroy.**

**R10 · Logs.** Open Logs, search `error`, then pick a site. Expect:
results narrow as you type; lines show time, server and source; no
secrets in any line (passwords, tokens and keys appear redacted).

**R11 · Alerts.** Open Alerts. Expect: open and recent alerts, thresholds
and delivery settings shown. **Do not save.**

**R12 · Audit log.** Open Audit log, filter by an action. Expect: rows
filter; a badge says the chain is intact. (Fails while the uncommitted
breadcrumb edit in `audit.blade.php` is in place: it errors the page.)

**R13 · Site settings.** Open demo › Settings. Expect: Source, Domain &
HTTPS, Database, Servers, Processes, Previews and Environment, each
filled in; the Environment textarea shows the .env; previews list `PR #1`
if that PR is open. **Do not save anything.**

## Cases that create and remove

**C1 · API token.** API tokens › create one named `browser-test`, scope
read. Expect: the token shown once, starting `cm_`. Revoke it. Expect: it
shows revoked. Cleanup: it is revoked.

**C2 · Branch preview.** demo › Settings › Previews: branch `main`,
database SQLite, idle days 1, Create preview. Wait for the operation to
succeed (about a minute). Open its URL. Expect: the page answers, its
environment is `preview`, the URL is
`main-N.demo.preview.example.com` over HTTPS. Then Delete the preview.
Expect: the delete operation succeeds and the preview leaves the list.
Cleanup: deleted.

**C3 · Variables and their history.** On the test site `wiztest` (not a
real app) › Settings › Environment: add `BROWSER_TEST=1`, save. Expect:
saved, marked as pending until a deploy, a new version in the history.
Restore the version before it. Expect: `BROWSER_TEST` gone. Cleanup: it
is gone.

## Cases that cost money or reach outside

**B1 · New application on an existing server.** As R5, but An existing
server › `web-1`, name `browser-test`, then Create application. Expect:
the operation runs address → deploy → https and succeeds within about a
minute; the URL answers over HTTPS. Cleanup: **blocked** until
applications can be deleted from the console; until then, ask the person.

**B2 · New application on a new server.** As R5 with a new server, then
Create. Expect: server (about four minutes) → address → deploy → https all
succeed. Bills about $12/mo while it exists. Cleanup: delete the
application and its server.

**B3 · Pull request preview.** In `your-org/demo-app`, open
a pull request into `main` from a branch in the same repository. Expect:
within a minute a preview with a `PR #n` badge appears on demo's
Previews, builds, and serves the branch. Close the pull request. Expect:
the preview is deleted. Cleanup: the PR is closed, its branch deleted.

## Growing this

- Add a case for every new screen, and one for every bug a run finds, so
  it stays found.
- A case that has passed unchanged for a while is a candidate for an
  automated browser test (Pest's browser plugin runs real Chromium) that
  runs on every change; agents then keep to new screens and to judging
  what reads badly, which scripts cannot.

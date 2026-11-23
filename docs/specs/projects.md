# Projects

Status: steps 1 and 2 built (data, write paths, minimal Projects tab); steps 3 (switcher, filtered lists) and 4 (API filter, CLI, MCP) next. Roadmap Phase 7h item "Projects". Parent spec: [organisations-and-roles.md](organisations-and-roles.md) (model, role table, **decided** items are quoted from there).

## Summary of the recommendation

A project is a **named folder of applications inside an organisation**, one client or one product. In v1 it is a grouping and a filter, **not a security boundary**: roles stay organisation-wide, servers, databases, clusters, buckets, keys and members stay organisation-wide. The whole change is one table, one column (`sites.project_id`), a default project per organisation, a filter in the console and API, and a Projects section on the Account page. No new level of URL, no environments table.

**Honest limit, say it to users:** until `project_members` exists, anyone in the organisation can open any project's application by id. The switcher narrows what you see, it does not stop you.

## 1. What a project is, and the minimum data model

**For:** one client (agency use) or one product (a company with a web app and an API). **Contains:** applications, and with them their preview environments, deployments, operations, variables, backups, domains. Nothing else (section 2).

Today (verified in code): an "application" is a `sites` row; a preview is another `sites` row with `parent_site_id` set (`Previews.php` around line 181). `sites()` in `components/sites.blade.php` lists every row for the organisation, previews included, ordered by name. Everything else hangs off `site_id` (operations, env versions, scheduled jobs, heartbeats, archives) so it follows the site for free.

Schema, one migration:

```
projects: id, organization_id (string, indexed), name, slug, created_at, updated_at
          unique(organization_id, slug), unique(organization_id, name)
sites:    project_id  unsignedBigInteger, indexed, NOT NULL after backfill
```

- No foreign key constraints exist in these migrations today (they use plain indexed columns); follow that convention. Deleting is guarded in code, not by the database.
- **Existing data:** the migration creates one project named `Default` (slug `default`) for every row in `organizations` and sets `sites.project_id` on every site, previews included, from its organisation's default. The migration follows the style of `unique_site_names_per_organisation`: fail loudly rather than guess. New organisations get a `Default` project in `AccountController::createOrganization` (the same place that makes the creator Owner).
- A project name is free text; the slug is derived and used by the API and CLI. Rename changes the name only, never the slug.
- **Invariant:** a preview's `project_id` equals its parent's. Set it when the preview is made (`Previews::create`, the same place `parent_site_id` is set) and update parent and previews together when an application moves.
- **Site names stay unique per organisation, not per project.** The application's database, database user and `/home/deploy/<name>` directory are named from the site name (audit finding 7), so per-project uniqueness would reopen that hole. Tradeoff: two clients cannot both have an app called `shop` in one organisation; they use `acme-shop`. Accept this.
- Do not put the project into any identifier: not node ids, vault keys, database names, certificate subjects, resource slugs. Moving an application between projects must stay a one-row update.

## 2. What belongs to a project and what stays organisation-wide

| Thing | v1 | Why |
| --- | --- | --- |
| Applications and previews | **project** | The point of the feature. |
| Deployments, operations, logs, env versions, backups, scheduled jobs, heartbeats | project, via the site | They reference `site_id`; no column added. |
| Alerts | org-wide table; the console list may filter by the site's project | `alerts` has `organization_id` only; many alerts are about a server, which has no project. |
| Servers (`nodes`), balancers, firewalls | **organisation** | Roadmap: servers are "not assumed" to be per project. Many apps share a server today; one server can hold apps from two projects. |
| Database and Redis clusters, buckets (`resources` rows) | **organisation**, shared | Roadmap: can be shared across projects. An application picks a cluster and gets its own database and user on it, so sharing already works. |
| Provider keys (`cloud_credentials`), DNS (Spaceship), AWS and Hetzner keys, GitHub accounts, SSH keys, Tailscale, alert settings, billing | **organisation** | They authorise the whole account; splitting them is a security feature, not a grouping feature. |
| Members, roles, invitations, API tokens | **organisation** | Decided: roles not per project in v1. |
| Audit log | organisation (one chain per organisation) | See section 6. |

Tradeoff the roadmap already accepted: an agency that wants "client A's developer can never see client B's server" gets nothing from v1. That is the later `project_members` work, and it will also force a decision on servers (section 5).

## 3. How environments map, and the Phase 7b relationship

**Today:** there is no environment object. Production is a `sites` row with `parent_site_id IS NULL`; each preview is a `sites` row pointing at it. The console's "Environment" tab is a tab on a site row. The roadmap's Application -> Environment split (7b: "Applications, each with environments: production and every preview side by side") is a presentation change on those same rows.

**Smallest step that does not block 7b:** add nothing for environments. `project_id` sits on every `sites` row and is kept equal between parent and previews. Then:

- 7b's Applications list is `sites WHERE parent_site_id IS NULL AND project_id = ?` with previews grouped underneath by `parent_site_id`. The project filter and the 7b list compose without either knowing about the other.
- If a real `applications` / `environments` split is ever done, `applications.project_id` is the production row's `project_id`, a one-line backfill; previews' copies are dropped. Nothing in this spec has to be undone.
- **Not recommended now:** an `environments` table or a `project -> application -> environment` table chain. It touches `sites.php` (2,234 lines), `OperationRunner`, `Deployer`, `Previews` (about 20 references to `parent_site_id` in app code) for no user-visible gain. Flag: this is the part that is bigger than it looks, and it should be its own phase, decided with 7b, not smuggled in with projects.
- Named non-prod environments ("staging" as a long-lived sibling of production, not a PR preview) do not exist today and are not added here. If wanted, a staging app is today a second application in the same project.

## 4. UI

**Recommendation: a filter plus a switcher, not a new level.** Laravel Cloud's own hierarchy puts projects as the top of the sidebar; the cheap, reversible version of that is a control next to the organisation name.

- **Switcher:** in the top bar beside the organisation switcher, a "Project" control with `All projects` (default) and each project. The choice is held in the session as `project_id`, like `Org::SESSION_KEY`, and validated against the current organisation's projects on every read (same pattern as `Org::id()`: never trusted from the session). Switching organisation clears it. Show the control only when the organisation has more than one project, exactly like the organisation switcher, so everyone with one `Default` project sees nothing new.
- **Where it filters:** the Applications list (`sites()`), the Activity panel on the same page (`activity()`: operations whose site is in the project), the Audit list (site-related rows), Alerts (those tied to a site). **Where it does not:** Servers, Databases, Storage, Account, Tokens. Those pages show a small "organisation-wide" note under the title so the switcher is not misread.
- **New application wizard:** a Project select, defaulting to the current filter, else `Default`. Required.
- **Application settings, General:** a Project select to move it (Manager and Owner, the `create` permission, like creating an application). Moves previews with it.
- **Projects section on the Account page** (left index next to People, SSH keys): list with application counts, create, rename, delete (only when empty). Gate `create` (Manager and Owner), already in the role table ("Create or delete an application, environment or project"). No new permission name.
- **URLs:** unchanged. The console uses query-string routes (`/sites`, `/site?site=ID`), so `/sites?project=<slug>` is accepted as a way to link to a filtered list and sets the session value; everything else keeps working. No `/projects/{slug}/...` routes: they would double every route and fight the 7b layout.
- **Not built:** a project dashboard page. The filtered Applications list is the project page. Revisit if a project grows its own settings.

## 5. Permissions: v1 and the later narrowing

v1: no change. `Roles` stays as is; project create, rename, delete and move are the existing `create` gate; viewing is `view`. A test pins that a Developer cannot create or move.

**Later (`project_members`):** a table `(project_id, user_id)` that narrows reach for members without changing roles, per the parent spec. Absent rows for a user means "all projects" (so existing members keep working). For this to be a small change later and not a rewrite, **do these now, at no cost**:

1. **One door for reads.** Add `App\Lib\Auth\Projects` with `current(): ?int`, `visible(): ?array` (null means all, in v1 always) and `sites(): Builder` (the org-scoped, project-filtered `sites` query). New code in this feature goes through it, and the list and API paths are converted. Later, `visible()` consults `project_members` and every converted caller is covered. Unconverted callers (there are dozens of `DB::table('sites')->where('organization_id', Org::id())` lookups by id) are the work later; this is why v1 is honestly not a boundary.
2. **Lookups by id are the real boundary.** When narrowing arrives, `#[Server]` methods and `V1Controller::find()` must refuse a site outside `visible()`, not just hide it from lists. Keep `find()` as the single API lookup (it already is) and give the console one equivalent.
3. **Do not leak through shared things.** A shared cluster or server shows which applications use it. Under narrowing, a member who cannot see project B must not see B's application names on a shared cluster card or a server card. Keep those cards reading application names through one function so it can filter.
4. **Servers are the unresolved question.** Narrowed Developers can still open a console on a server that holds another project's secrets (a shell reaches every secret on it, per the role table). The honest answers are: narrowed members get no console on shared servers, or servers gain a nullable `project_id` ("reserved for"). Do not add that column now; decide when narrowing is built. Do not add anything to `nodes` or `resources` in this feature so the choice stays open.
5. **API tokens:** keep the token organisation-scoped and capped at the creator's role. A later `api_tokens.project_id` (nullable, null means all) is a one-column addition checked in `find()` and the list endpoints. Not built now.
6. **Audit and alerts** need project awareness at read time later; section 6 keeps the data for it.
7. Never encode project in ids (section 1), and keep `projects.id` internal with `slug` as the public handle, so a future "project owner" or per-project settings do not change any key.

## 6. API, tokens, CLI, MCP, audit

**API (`routes/api.php`, `V1Controller`)**

- `GET /api/v1/projects`: id, slug, name, application count. Read scope.
- `GET /api/v1/sites` gains optional `?project=<slug>` and returns `project` (slug) in `siteSummary()`; `preview_of` stays. Everything else keeps its URL. `GET /sites/{site}` and the rest are unchanged (sites are still found by name or id inside the organisation).
- No create/rename/delete project endpoint in v1; that is console-only (Manager action, rare). Say if the CLI should create projects.
- Tokens: unchanged. `whoami` is unchanged. A token sees all projects of its organisation (stated in the token creation help text, so nobody thinks a token is project-limited).

**CLI and MCP (`cmd/cloud/main.go`, `cmd/cloud/mcp.go`)**

- `cloud projects`; `cloud sites --project <slug>`; list output gains a Project column.
- MCP: new `list_projects`; `list_sites` gets an optional `project` argument. `create_preview` and the rest are unchanged because previews inherit.
- Add a case to `cmd/cloud/main_test.go` (its fake server returns `/api/v1/sites` today). An older `cloud` against the new server keeps working (extra JSON field, new optional parameter); a new `cloud` against an older server fails on `/projects` with the server's 404, which should read "this server has no projects yet", not a stack trace.

**Audit**

- New actions: `project.created`, `project.renamed`, `project.deleted`, `site.moved` with `resource_type: 'project'` and `resource_id` the project id (and for `site.moved`, metadata `from` and `to`). They go through `AuditLog::record` like `user.role_changed`.
- **Do not add a `project_id` column to `audit_records`.** The record hash covers specific columns (`AuditLog::hash`, `columnsOf`), so adding a column changes the chain format and every verification of existing chains. Instead put `project_id` into `metadata` for site-scoped actions going forward. Cost: old records have no project, and a per-project audit filter is a JSON metadata filter (fine at this size) that is empty for history. If a column is wanted, it is a deliberate chain-version bump and a separate decision.
- The audit page's project filter is therefore best-effort for history: it can resolve older rows through `resource_type = 'site'` and the site's current project (which is wrong after a move). State this on the page ("shows current project").

## 7. Migration plan: independently deployable steps

Each step ships alone and is safe if the next never happens.

| # | Step | Size | Notes |
| --- | --- | --- | --- |
| 1 | `projects` table, `sites.project_id`, `Default` project per organisation, backfill, `Projects` helper class, default project on new organisations, `Previews` sets project from parent | S (about half a day) | Invisible to users. **Sequential, first.** Deploy with the backfill checked on a copy of the production database. |
| 2 | Write paths: wizard Project select, move in application General settings, API `siteSummary.project`, audit actions and metadata | M | Sequential after 1; they touch the shared wizard and `Sites.php`. |
| 3 | Console: top-bar switcher, filtered Applications and Activity lists, Account -> Projects section, organisation-wide notes on infrastructure pages | M | Parallel with 4 after step 2. Disjoint files (blade components and `Projects`). |
| 4 | API `?project=`, `GET /projects`, `cloud`, MCP, tests in Go and PHP | S-M | Parallel sub-agent with 3. Touches `V1Controller`, `routes/api.php`, `cmd/cm`. |
| 5 | Audit page project filter, alerts filter | S | After 3. Optional; skip until asked. |
| later | `project_members`, project-scoped tokens, servers decision, enforced lookups | L | Separate spec when wanted. |

Parallel suitability: steps 1 and 2 are small and share files with everything, so one agent, in order. 3 and 4 can run as two sub-agents with a written contract (the `Projects` helper API and the JSON shapes above). Tests for each step are written in the same step, not after.

## 8. Tests

Following `OrganisationIsolationTest`, `RolesTest`, `ServerMethodPermissionsTest`:

- Migration: sites with and without an organisation row get the organisation's `Default` project; previews included; re-running is a no-op; an organisation with no sites still gets a project.
- New organisation gets a `Default` project, and the creator can create a second.
- Project names and slugs are unique per organisation, and the same name in two organisations is fine.
- Create, rename and delete: delete refused when applications exist; Developer and Viewer refused (permissions test must still pass: every `#[Server]` method names a permission, including the new ones).
- Move an application: previews move with it; a preview created afterwards inherits the parent's project; moving to another organisation's project is refused (404).
- Applications list: with the filter set, only that project's applications and their previews; a project id from another organisation in the session is ignored; switching organisation clears it.
- Isolation: a second organisation cannot see, filter by or move into this one's projects by guessed id.
- API: `/projects`, `/sites?project=`, unknown slug gives 404, `siteSummary.project` present. Token behaviour unchanged (a read token still cannot deploy).
- Audit: the new actions are recorded and the chain still verifies (`AuditLog::verify`) after a move.
- Go: `cloud projects`, `cloud sites --project`, and the 404 message against an old server.

## 9. Risks

- **It is not a boundary** (stated up top). If someone sells it as client isolation, that is a security bug in the sales copy. Mitigation: wording in the UI and the roadmap, and the one-door design in section 5.
- **Backfill on production:** touches every `sites` row. Low risk, but run it on a copy; `project_id` NOT NULL only after the backfill succeeded, and the migration must tolerate new sites inserted between steps (hence the `Projects` helper is deployed together with the migration).
- **Code paths that insert sites** without a project: the wizard (`NewApplications`), previews (webhook and API), `OperationRunner` restore from archive (`OperationRunner.php` around line 1245 builds a site row from an archive, with `parent_site_id => null`), adopt flows. Each must set `project_id` or the NOT NULL fails at runtime. Grep for `DB::table('sites')->insert`/`insertGetId` and cover every one; the archive restore should reuse the archived site's project if it still exists, else `Default`.
- **Deleted project with archived sites:** `site_archives` should keep `project_id` or the restore falls back to `Default`. Pick the fallback; it is simpler.
- **Filter confusion:** a session-held filter hides applications, which looks like data loss. Mitigation: always show the active filter in the top bar and an "All projects" link on an empty list.
- **Scope creep toward environments:** section 3. The tempting rewrite is large and belongs with 7b.
- **Switcher clutter** for single-project organisations: hidden unless there are two projects.
- **Shared things look project-less:** users will ask "which project is this server in". The organisation-wide notes answer it for now.

## Decisions (locked)

All six recommendations above were accepted; a second, independent review (Fable) agreed with each.

1. Filter-only v1. The switcher itself says "Projects group applications; they do not restrict access" (not only the docs), and it is hidden until an organisation has two or more projects. Do not offer it to public signups until `project_members` exists.
2. Move between projects: Manager and Owner (`create` gate).
3. Site names stay unique per organisation.
4. Nothing added to `nodes` or `resources`.
5. No Application/Environment table split.
6. API and CLI list and filter only; creating, renaming, deleting and moving projects is console only.

Added from the second review: shared server and cluster cards read application names through one function so narrowing can filter them later; `Previews::freeName` uniqueness is per node while the organisation index is per name, so a preview can fail creation today (fix opportunistically, not part of this feature).

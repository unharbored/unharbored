# Organisations, members, roles and platform administrators

Status: built up to enforcement of roles (memberships, role table, gates on every action, switcher, invitations with roles, role changes). Not built: billing, platform administrators and support access, projects, per-project access. Decisions already made are marked **decided**.

## The model

```
User ──< Membership (role) >── Organization ──< Project ──< Application ──< Environment
User.is_platform_admin   (a flag on the user, separate from any organisation)
```

- A **user** is a person with one login. **decided:** a user can belong to several organisations, with a switcher. The current organisation is kept in the session.
- A **membership** is one user in one organisation with one role. A user has at most one membership per organisation.
- An **organisation** is the account, the billing unit, and the isolation boundary. **decided**
- A **project** groups applications (one client, or one product). **decided** Roles are not per project in the first version (see "Later").
- A **platform administrator** is an operator of the service, not a member of any organisation.

## Roles

**decided:** Owner, Manager, Developer, Viewer, from most to least. Manager sits between Developer and Owner and has everything an Owner has except billing (and the two things that go with it, below). No custom roles and no teams in the first version. The name is Manager, not Admin, so it is never confused with a platform administrator.

| Can | Owner | Manager | Developer | Viewer |
| --- | :-: | :-: | :-: | :-: |
| See applications, environments, deployments, logs, metrics, servers, databases | yes | yes | yes | yes |
| See environment variable names | yes | yes | yes | yes |
| See environment variable values, and secrets | yes | yes | yes | no |
| Deploy, roll back, restart, run scheduled tasks | yes | yes | yes | no |
| Edit variables, domains, build settings, workers | yes | yes | yes | no |
| Open a console on a server (a shell reaches every secret on it) | yes | yes | yes | no |
| Create API tokens (scope capped at the creator's role) | yes | yes | yes | read only |
| See the audit log | yes | yes | yes | no |
| Create or delete an application, environment or project | yes | yes | no | no |
| Create, resize or delete servers and databases | yes | yes | no | no |
| Connect or remove cloud providers, DNS and GitHub accounts | yes | yes | no | no |
| Invite and remove members, change roles (not Owners; see rules) | yes | yes | no | no |
| See and change billing | yes | no | no | no |
| Delete the organisation, transfer ownership | yes | no | no | no |

Rules:
- A Manager manages everyone below Owner: they can invite, remove and change the role of Viewers, Developers and other Managers, but cannot invite an Owner, promote anyone to Owner, or remove or demote an Owner. Otherwise a Manager could take over the organisation, and billing with it.
- Deleting the organisation and transferring ownership stay with Owners even though they are not literally billing: deleting ends the billing relationship and cannot be undone. Say if you want Managers to have these.
- An organisation always has at least one Owner. The last one cannot leave, be removed or be demoted.
- Invitations carry a role, expire, and are single use. **decided:** a copyable link for now, no email service.
- Removing a member revokes their sessions and the API tokens they created in that organisation.
- A member sees only the organisations they belong to. Nothing in one organisation is visible from another, including by guessing an id.
- **decided:** a Developer cannot create or delete resources: applications, environments, projects, servers, databases. They work inside what exists. Previews that a pull request opens are created by the webhook under the organisation's settings, not by the Developer, so they keep working.
- **decided:** a Developer can read the audit log.

## Billing

- **decided:** the organisation is billed, never the user. Members are free: adding a Developer or Viewer changes nothing on the bill.
- Only Owners see or change billing, and they are the billing contacts. A user who is a member of an organisation they do not own is never charged for it, and owning several organisations means several separate bills.
- Platform administrators are never billed and never counted.
- What the organisation pays for (a plan, usage, or both) is not decided and is separate from this spec. Nothing here forces seats.

## Platform administrators

Needed to run the service and to support testers. The risk is that "can see anything" includes customers' secrets, so this is deliberately narrower than "anything":

| | Platform administrator |
| --- | --- |
| See every organisation, member, application, server, deployment, operation and audit entry | yes, read-only, by default |
| See variable values, vault contents, console output, database contents | no |
| Change anything inside an organisation | only through support access, below |
| Suspend an organisation, issue invitation links, reset a password, rotate a platform secret | yes |

- **Support access:** to change something in an organisation, an administrator opens a time-limited session (default 1 hour) with a reason. It acts with Developer permissions, never Owner.
- Everything an administrator does inside an organisation is written to that organisation's audit log with the administrator's name, the reason and the time, and the organisation's Owners can read it.
- The flag is set only from the command line (`php artisan user:make-admin`), never through the web interface or an API. Administrator accounts require two-factor sign-in, which is built but switched off today; turning it on for administrators comes first.
- Tradeoff: an administrator who cannot read secrets cannot fully debug a failed deploy that depends on one. That is intended; they ask the Owner, or the Owner opens a support session knowingly.

## What this needs in the code

1. **Replace the hard-coded `org-1`** (about 62 places) with one helper that returns the current organisation, and make every query go through it. Do this first. Nothing else is safe until it is done.
2. `memberships` table (user, organisation, role, timestamps; unique on user and organisation). Backfill: every existing user becomes an Owner of `org-1`.
3. `users.is_platform_admin`.
4. One authorisation check (a gate per permission in the table above) called by every action and every `#[Server]` method. The role table lives in one place.
5. `invitations` gain a role and an organisation.
6. Organisation switcher in the layout; the chosen organisation is checked against memberships on every request, not trusted from the session.
7. API tokens keep their organisation and scopes: `read`, `deploy`, `manage` map onto Viewer, Developer and Manager (a token never carries billing or ownership powers). A token cannot be created above its creator's role.
8. **Gateway fixes from the 2026-11-08 security audit, before a second organisation connects an agent:** key sessions by organisation and node, and carry the expected organisation through commands and callbacks.
9. GitHub: one GitHub App for the platform, installations mapped to organisations; until then an account approval is a Manager action. Members linking their own GitHub login is later.

## Later, not in the first version

- Per-project membership, so an agency can give a client's developer one project only. The table above allows it: add `project_members` that narrows a member's reach, without changing roles.
- Teams, custom roles, SSO, enforced two-factor for organisations.
- Email for invitations, verification and password reset.

# Build speed

Notes from 2026-11-07: where deploy time goes today, what is missing, and
what would make builds fast. Nothing here is built yet.

## Measured

Recent successful deploys of `demo` (operation stages, seconds):

| Operation | fetch | build | activate | workers | prune | https |
|---|---|---|---|---|---|---|
| #99 preview (clone db) | 2 | 7 | 1 | 1 | 0 | 9 |
| #96 PR preview | 2 | 8 | 0 | 0 | 0 | 8 |
| #91 deploy | 1 | 9 | 0 | 1 | 0 | – |
| #88 deploy (3 servers) | 1 | 23 | 0 | 9 | 4 | – |

These look fast because `demo` has a small `composer.lock` and no front end.
They are not representative of a real application.

## What a build does now

`Deployer::buildRecipe()`, per release, on each building server:

1. `composer install --no-dev` into the new release directory. Composer's
   download cache in the deploy user's home survives between releases, but
   every package is installed into the new `vendor/` from scratch.
2. `artisan migrate` (primary only), then a seeder on a preview's first
   deploy.
3. `artisan optimize`.

A site with more than one server and a storage bucket builds once on the
primary and ships a tarball to the others (`Deployer::artifact()`); without
a bucket every server clones and builds for itself.

## Gaps

- **No front-end build.** Nothing runs `npm`. A Laravel app using Vite ships
  without `public/build`, so its pages load without CSS or JS. The server
  stack does not install Node either.
- **No dependency reuse.** A deploy that changes only PHP code still runs a
  full `composer install`; with an `npm` step added, a full `npm ci` too.
  On a typical app those two are most of the build time.
- **Build commands are fixed.** An app that needs an extra step (private
  Composer auth, `php artisan icons:cache`, a Sentry upload) cannot add one.
- **Step timings are not shown.** The operation records stage times, but
  the console does not show per-step durations, so a slow step is not
  visible.

## What would make it fast, biggest win first

1. **Reuse `vendor/` and `node_modules/` when their lock file is unchanged.**
   Compare the hash of `composer.lock` (and `package-lock.json`) with the
   previous release's. When equal, copy the directory from the previous
   release with hard links (`cp -al`) instead of installing: seconds instead
   of a minute or more. Then `composer dump-autoload --optimize` for the new
   code. Most deploys change code, not dependencies.
2. **Add the asset build** when `package.json` exists: `npm ci` (or the
   reuse above) and `npm run build`. Add Node LTS to the server stack recipe.
3. **Configurable build and deploy commands** per environment, defaulting to
   today's steps: build commands (Composer, npm, extras) separate from
   deploy commands (migrations) that run just before the switch.
4. **Show per-step timings** on the Deployments page, so the slow step is
   obvious.
5. **Always build once.** Multi-server sites without a bucket still build on
   every server; shipping the build over the gateway or a temporary bucket
   would make every server identical and cut the load to one build.

Related, noted at the same time: a clearer view of why a build failed (one
row per step, the failed step opened, a plain explanation of common
failures) and a variables table instead of one `.env` textarea. Both belong
to the console redesign (ROADMAP Phase 7b).

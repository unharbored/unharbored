# Applications on Kubernetes, without Helm

Status: **proposal.** Nothing is built. Written for teams that already deploy to Kubernetes clusters (for example with Helm from Harness) and want Unharbored to take over two jobs: deploying a new version, and managing variables. This replaces an earlier draft that ran Helm itself; see "Why not Helm".

## Goal

Connect an existing cluster, claim a running application, and from then on:

1. **Deploy** a new image tag (from the console, or from CI through the existing API token), watch the rollout, and roll back.
2. **Manage variables** (plain and secret) with the history, audit and restart behaviour applications already have.
3. **Change a small, fixed set of settings** in a form: image, replicas, resources, the service, and optionally autoscaling, probes and an ingress. Nobody writes or maintains a chart or YAML.

## Not the goal

- **Not building images.** Build, push, tests, scans and approvals stay where they are (Harness CI, GitHub Actions). Unharbored is told the tag, or is given it by hand.
- Not creating clusters or node pools, and not cluster autoscaling. The cluster does its own.
- Not canary, blue-green, or promotion between environments.
- **Not the full flexibility of Kubernetes.** Volumes, sidecars, init containers, affinity, tolerations and security contexts are not in the form (a plain node selector is). An application that uses them is refused when claimed, with the list of what it uses (see "Claiming"). There is no free-form YAML.
- Not Argo CD or Flux. A workload either manages is refused.
- Not a Kubernetes dashboard. Pods, restarts, events and logs are shown for the claimed application only. A pod console is not in version 1.

## Provider: DigitalOcean Kubernetes first

Version 1 is a **DigitalOcean feature**: the clusters are DigitalOcean Kubernetes (DOKS) and the images are in DigitalOcean's container registry. That follows the rest of the product (one provider per application, the first provider being DigitalOcean) and keeps the first version honest about what has been verified.

What it changes:

- **Two ways to connect.** *Manual*: the API URL, the CA certificate and a token, which works for any cluster and is needed when the cluster is in a DigitalOcean account Unharbored is not connected to (the team's real clusters are in a work account; only a personal one is connected). *From the account*: the connected DigitalOcean account (`CredentialStore`, `App\Lib\DigitalOcean\Client`) lists its clusters and the user picks one, with the URL and CA taken from the cluster's record. Manual is built first; the list is a convenience on top.
- **The credential.** DigitalOcean can issue cluster credentials from the account token, but those carry broad rights. Version 1 offers both: the account-issued credential (quick, broad) and a namespace-scoped service account token (the recommendation, with the exact manifest shown). Whether DigitalOcean's issued credentials are short-lived and how they renew is checked in step 0, not assumed.
- **Image tags from the registry.** When the registry is in the connected account, the deploy form lists the tags in the application's repository so a deploy picks one. Otherwise (a registry in another account) the tag is typed, or CI names it through the API. Typing is always allowed.
- **The seam.** The cluster API client is written against a small interface (list clusters, give an API address and credential), with DigitalOcean as the only driver, as `DatabaseDriver` is for databases. EKS, GKE, AKS and clusters reached by a plain kubeconfig are later drivers. Nothing else in the feature is provider-specific: the form, the renderer, the deploy and the variables work on any conformant cluster.
- **Not in version 1:** creating, resizing or upgrading DOKS clusters, node pools, and the cluster autoscaler. They are the provider's, and Unharbored only reads them.

## What exists, and is reused

Applications (`sites`), per-application variables with versions and layers (`site_env_versions`), the vault, API tokens for CI, the audit log, projects and roles, and long-running operations (`operations`). `ContainerApps` is the precedent for an application that is not built on a server: a `sites` row with a runtime, plus `ContainerApps::refuse()` for the server features it lacks.

## Step 0 findings so far

One application on one real dev cluster (a Deployment, Service, HPA, ConfigMap and Secret), read-only:

- **The form covers all of it:** one container, image from the registry, port, `envFrom`, requests and limits, default rolling update, NodePort Service, HPA on memory. No probes, volumes, security context or pull secret (the cluster has the registry integration).
- **Differences from the chart in the repository:** resource names carry an environment prefix; the HPA is outside the release (hand-created) while the chart has autoscaling off, so every Helm deploy resets `replicas` and the HPA rescales; the Service has a different name pattern.
- **Seven applications share one namespace**, each its own Helm release; about sixty Helm release records are kept.
- **Three applications checked.** The form needed two additions, now in it: a node selector (two of three use one) and the rolling-update limits (two use surge 2, unavailable 0). The third application has no variables, and its chart already adds config-hash annotations of an empty string.
- **Secrets in the plain ConfigMap, confirmed:** two secret-looking keys are in the ConfigMap, not the Secret. The import flags them.
- Still to check: whether the cluster API is reachable from the control plane.

## What a Harness-managed application looks like

Verified against one real repository; assumed, not known, for others.

- A Helm chart in the repository with one values file per environment. The deploy is a Harness `HelmDeploy` step: a real Helm release with a fixed name, run by a per-environment delegate.
- The values files set very little: the namespace, the image repository and tag, two labels (`env`, `service`), the variables, and (production) a replica count. Everything else is the chart's default: CPU and memory requests and limits, and a NodePort Service on a fixed port. Ingress and autoscaling are off. The chart supports probes, volumes, node selectors, affinity, tolerations and security contexts, and none are set.
- Variables are defined in the pipeline per environment and the values file refers to them (`<+stage.variables.X>`); secrets are in Harness's secret manager. The chart turns them into a ConfigMap and a Secret that the Deployment reads with `envFrom`.

Two consequences:

- **The current values are in the cluster.** Secrets that exist only in Harness's secret manager cannot be exported from Harness, but they are in the live Secret and ConfigMap and can be read from there.
- Today a variable change alone does not restart pods (there is no checksum annotation), so it takes effect only when the image changes too.

## Decisions

### 1. Unharbored renders and applies a fixed set of resources

Unharbored builds the manifests itself from the application's settings and applies them through the Kubernetes API with **server-side apply** (field manager `unharbored`). The resources are fixed: a Deployment, a Service, a ConfigMap and a Secret, and optionally an HPA and an Ingress. The control plane makes the API calls directly with a scoped token; there is no `helm`, no `kubectl`, and no chart to fetch.

### Why not Helm

An earlier draft ran `helm upgrade`. For a fixed set of six resources it adds more than it removes: the `helm` binary, fetching the chart from git, cleaning the Harness expressions out of the values files, Helm's stuck `pending-upgrade` states, and a chart someone has to maintain. What Helm gives (rollout wait, atomic undo, revision history) is small once the shape is fixed.

**Tradeoff:** Unharbored owns the apply, wait and rollback logic, and anything outside the form is not supported. Both are deliberate: it is what "just allow what we use" means. If a team later needs more than the form allows, bring-your-own-chart is a separate feature, not a reason to build it now.

### 2. Where the API calls come from: the control plane, version 1

The control plane calls the cluster API with a service account token kept in the vault.

**Tradeoff:** the control plane holds a credential that can change a cluster (the provider token it already holds can issue one, so little is new), and needs network reach to the cluster API. Managed clusters normally have a public, token-protected API endpoint, so reach is usually there; an API restricted to a private network is not supported in version 1. Later alternative: an agent inside the cluster that dials out, as a delegate does. It would keep the credential in the cluster, and means revisiting the security model's decision that an agent's exec is unrestricted. Not version 1.

### 3. An application is a `sites` row with a `kubernetes` runtime

Following `ContainerApps`: it inherits variables, versions, tokens, audit, projects and roles, and `refuse()` lists the server features it lacks (servers, processes, scheduler, domains and certificates as the platform does them, backups, previews). Its settings (cluster, namespace, names, the form's values) live in the application's settings JSON.

**Tradeoff:** a separate table and pages would be cleaner but would repeat variables, tokens, audit and permissions. Reuse is cheaper; the cost is a long refuse list, which `ContainerApps` already shows is manageable.

### 4. Variables are the ConfigMap and the Secret, and a change rolls the pods

Plain variables become the ConfigMap, secret ones the Secret; the Deployment reads both with `envFrom`. `envFrom` is left out when the application has no variables (one real application has none). A hash of both is written as an annotation on the pod template, so changing a variable changes the template and Kubernetes performs a rolling update. No separate restart step, and the behaviour is the same as for an image change.

## The form

| Section | Fields | Notes |
|---|---|---|
| Image | repository, tag, registry secret (optional) | The tag is what a deploy changes. |
| Replicas | a number, or autoscaling: min, max, CPU target, memory target (optional) | With autoscaling on, the Deployment does not set `replicas`, or the apply would fight the HPA. |
| Resources | CPU and memory requests and limits | |
| Node selector (optional) | key and value pairs, e.g. a node pool label | Seen on two of three real applications. Simple labels only: no affinity or tolerations. |
| Rolling update | max surge and max unavailable (a number or a percentage) | Defaults 25% each. Two of three real applications use surge 2, unavailable 0 (never take a pod down before its replacement is ready). |
| Service | type (ClusterIP, NodePort, LoadBalancer), port, target port, node port | |
| Probes (optional) | liveness and readiness: path, port, delays | See "Rollout checks". |
| Ingress (optional) | host, TLS secret, class | Off unless someone needs it. |
| Variables | the existing editor | Plain or secret. |
| Imported, shown, not edited | namespace, resource names, selector labels | The Deployment's selector cannot change after creation, so these must match what is running. |

## Data

- `clusters` (organisation, project, provider (`digitalocean`), the provider's cluster id, name, API URL, CA certificate, credential kind and token in the vault, namespaces it may touch, last checked, last error). A cluster belongs to one project.
- The application's `sites` row, runtime `kubernetes`: the cluster, namespace, names, the form's values, and which resources Unharbored owns.
- A **deploy record** per deploy: the tag, a snapshot of the form's values, the variables version used, who and when, and the result. This is the rollback list; a rollback re-renders from a previous record.
- Every resource Unharbored applies carries the label `app.kubernetes.io/managed-by: unharbored`. That is how it tells its own resources from others, and what a prune may delete.

## Claiming an existing application

1. Pick a cluster, a namespace and a Deployment.
2. Unharbored reads the Deployment, its Service, ConfigMap, Secret, HPA and Ingress, and fills in the form from them. Each resource's name is recorded **as found** (they need not share a pattern: a Deployment `dev-x` can have a Service `dev-service-x`). The HPA is found by what it targets (`scaleTargetRef`), not by label or release: in the real cluster it was created by hand, outside the Helm release.
3. **Fields it cannot represent** (a second container, volumes, affinity, tolerations, a security context with content, init containers, extra annotations that matter). An empty security context counts as none, and `checksum/*` pod annotations (a chart's own config hashes) are ignored and replaced by Unharbored's are listed and the claim is **refused**. The list is the answer to "why": nothing is silently dropped.
4. Variables are imported from the live ConfigMap and Secret as the first version, secrets marked as secrets. A key in the ConfigMap whose name looks like a secret (`SECRET`, `TOKEN`, `KEY`, `PASSWORD`) is flagged, with an offer to move it to the Secret: in the real values files two such keys are in the plain ConfigMap.
5. Before anything is applied, Unharbored shows the difference between what it would apply and what is live. It should be only the ownership label and the variables hash annotation, and the page says so; anything else is shown and must be confirmed.
6. The first apply takes ownership of the fields it manages (`force`), which also takes them from Helm's field manager. After that Helm's tooling will fight any change, which is why the cutover below comes first.

## Deploying

A deploy (console button, or `POST` with an API token from CI, naming the tag) is one operation per application, one at a time:

1. Render the resources from the form's values, the tag and the current variables.
2. Apply them in order (ConfigMap, Secret, Service, Deployment, then the HPA and Ingress, or delete those if they were switched off).
3. **Wait for the rollout** (see below).
4. Record the result. A failed rollout is **re-applied from the previous deploy record automatically**, as `helm --atomic` would, and the failure says which pods failed and why (events). This is on by default and can be switched off per application.

Rollback is the same operation from an earlier deploy record.

## Rollout checks

The wait compares the Deployment's status with what was applied (observed generation, updated, ready and available replicas) with a deadline, and reads pod events on failure. **Without a readiness probe Kubernetes treats a pod as ready as soon as it starts**, so a crashing application is only caught once it restarts repeatedly. The page says this when no probe is set, and the form makes a readiness probe easy to add. The production chart shown to the team has none.

## Safety

- **Scoped access.** Many applications share one namespace, so a namespace-wide Role would let one claimed application touch the others. The service account gets a Role limited by `resourceNames` to the resources of the claimed applications (extended at each claim, shown as a manifest to apply), plus read on pods, logs and events in the namespace. Namespace-wide is allowed as a choice, labelled as broader. Cluster-admin is refused. Setup shows the exact manifest to apply, and the connect step checks what the token can do and says plainly what it found.
- **Argo CD or Flux** (their labels and annotations on the resources) are detected and refused: a second writer would undo our changes.
- **Someone else changed it.** A deploy compares the Deployment's generation with the last one Unharbored applied, and stops with the difference if something else (Harness, a person, `kubectl`) changed it since.
- **Cutover.** Per application: claim it (nothing changes), confirm the diff, disable Harness's deploy stage for that application, deploy once from Unharbored. Helm's release records stay in the cluster, stale and harmless; a confirmed cleanup can remove them later (a permanent deletion, so it asks).
- **Secrets** are in the vault and in the live Secret object (as today). Values are never written to logs or the audit log, and API errors are scrubbed before they are stored.
- Every connect, claim, deploy, rollback and settings or variable change is in the audit log. Roles: changing a cluster, settings or a variable is a manager's action, as for servers.

## Failure cases to handle

- The token expired or lost access: the cluster shows as failing, deploys refuse with that reason.
- The API is unreachable from the control plane: say so and name the address tried.
- A rollout fails: automatic re-apply of the previous deploy, with the reason; if that also fails, the state is shown and nothing is retried silently.
- A resource Unharbored owns was deleted by hand: the next deploy recreates it; the page says it was missing.
- An object has a field the form cannot represent after claim (added later by someone else): the next deploy stops and lists it, as on claim.
- Two deploys at once (console and CI): one at a time per application; the second is told why.
- A very large Secret, or a name that is not a valid variable name: refused with the reason.

## Steps

**0. Check the assumptions (before any code).** Also check, against the real DigitalOcean account: what the API returns for a cluster's address and CA, what credential it issues and how long it lasts, and that the registry's tags can be listed with the token already stored. In a real cluster, list the Deployment, Service, ConfigMap, Secret, HPA and Ingress for one application. Compare field by field with the form: what the form covers, what the live objects use that it does not, and what other tools write to these objects. This decides whether the form is really enough.

**1. Connect a cluster, read only.** Pick a cluster from the connected DigitalOcean account, choose the credential (see "Provider"), test it and show what it can do. List the allowed namespaces' Deployments with their image, replicas and pods, and show a pod's restarts, events and logs. Nothing writes.

**2. Claim and import.** The claim flow above: form filled from the live objects, unsupported fields refused, variables imported as version 1, the diff shown. Nothing is applied.

**3. Deploy and roll back.** Rendering, apply, wait, automatic re-apply on failure, the API token endpoint for CI, and rollback.

**4. Settings and variables.** The settings form, with autoscaling, probes and ingress, and the variables editor with history and the rolling update on a change.

## Tests

Fake the cluster API in the operation tests: connect refusals, claim (accepted, and refused for each unsupported field), the diff, the render of every resource, apply order and prune, deploy success, a failed rollout with automatic re-apply, an external change detected, Argo and Flux detection, the hash annotation changing with a variable, the manager-only routes in the permissions test, and secret scrubbing. A real run uses a local `kind` cluster with a small application: no provider cost.

## Verification

Before calling it done: a full run on a `kind` cluster (connect, claim, deploy, variable change, failed rollout, rollback), then one real application on a non-production cluster with Harness's deploy stage disabled for it. Until the second, say "not run against a real setup".

## Cost

Steps 1 and 2 are small. Step 3 is the largest: the renderer, the apply and wait logic, and the failure handling. Step 4 reuses the existing editor. About the size of "services from templates", with a Kubernetes API client in place of a container runtime. The cluster credential and the apply logic are the parts that deserve review.

## Decided

1. **The control plane makes the API calls in version 1.** It already holds the provider token, which can issue cluster credentials, so holding a cluster credential adds no new power; an in-cluster agent would add an image, RBAC, enrollment and upgrades, and reopen the agent-exec question. Revisit only if a cluster's API is closed to the control plane's address, or the credential must stay in the cluster.
2. **Many applications per environment.** An application is one workload in one environment, one `sites` row each; a cluster or namespace holds many, and a project groups them. Claiming is one Deployment at a time, with a bulk claim of a namespace as a later option.
3. **A failed rollout is re-applied from the previous deploy automatically.** On by default, switchable per application.
4. **Nothing else writes to these objects.** Step 0 still checks, and the "someone else changed it" check stays.

## Open questions

1. **Is the form enough?** For the three applications checked, yes, with the node selector and rolling-update fields added (see "Step 0 findings"). The remaining four applications in that cluster are not checked; a claim refuses any that need more, with the list. If a real application needs a field the form lacks, the choice is to add the field or refuse that application. It should not be to add a YAML escape hatch.
2. **Is the cluster's API open to the control plane?** If a firewall restricts it by address, the control plane's address must be allowed.

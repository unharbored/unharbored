# Template signing keys: custody and rotation (draft)

Status: draft. Anything marked **DECISION** has not been made; the rest describes what the code does today or what is proposed.

Two ed25519 keys sign template indexes (docs/specs/templates.md section 3): the **official** key (the official index) and the **community** key (the community index built from the public repository). Built-in templates ship in the release and need no key. A leaked key lets someone publish a template that installs trust as official or community, which then downloads and runs a program on customers' servers, so these keys are the most sensitive secrets in the project.

Format: raw ed25519 (libsodium). The public key is 32 bytes, base64, in `config('templates.official_public_key')` / `community_public_key` (env `CM_TEMPLATES_OFFICIAL_KEY`, `CM_TEMPLATES_COMMUNITY_KEY`). The secret key is 64 bytes, base64. Made with `php artisan templates:keygen <file>`, which prints only the public key.

## 1. Where each key lives

| | Official key | Community key |
| --- | --- | --- |
| Signs | the official index | the community index |
| Day-to-day copy | a secret in the CI that builds the official index (**DECISION**: which repository; the official templates are today the built-in folder, so the official index may not exist until we want updates between releases) | a repository secret `CM_TEMPLATES_SIGNING_KEY` in the community repository, available only to the `publish.yml` job on `main` |
| Offline backup | one encrypted copy, offline (**DECISION**: where; see section 5) | same (**DECISION**: the same place, or a separate one) |
| Public half | in the control plane config, shipped in each release | same |

Rules for both:

- The secret exists in two places only: the CI secret store and the offline backup. Never in a repository, a chat, a ticket, a log or an `.env` on a server. `templates:keygen` writes it to a 0600 file once; delete that file after storing the two copies.
- `templates:build-index` reads the key from a file or an environment variable and never accepts it as an argument, so it does not appear in the process list or shell history, and it never prints it.
- Use a separate key per tier. The community repository's CI never holds the official key, so a compromised community workflow cannot publish an official template.
- In the community repository, protect `main` (reviewed pull requests only, a CODEOWNERS review for `.github/`), and restrict the secret to an environment with required reviewers if the hosting plan allows (**DECISION**).

## 2. Who can use a key

- Community key: only the `publish.yml` workflow, which runs after a maintainer merges to `main`. Humans never sign by hand. Anyone who can change `publish.yml` on `main` can exfiltrate the secret, so changes to `.github/` need the maintainer set's review.
- Official key: the same pattern for its CI. **DECISION**: who the maintainers are, and whether a second person must approve a release of the official index.
- The offline backup is read by one named person (**DECISION**: who) and only for a rotation or a lost CI secret.

## 3. Rotation

Planned rotation (yearly, or when the holder changes) and emergency rotation use the same mechanism, because installs can only learn a new public key from a release of the control plane.

1. Generate the new pair (`templates:keygen`). Store the new secret as described above, under a new secret name or replacing the old one only at step 4.
2. Ship a control-plane release whose config holds **both public keys**: `CM_TEMPLATES_COMMUNITY_KEY="<old>,<new>"` (comma-separated). Sources accepts an index whose signature verifies against any listed key. **This is implemented**: `Sources::unverified()` splits the setting on commas; one key, as today, still works. No other code change is needed.
3. Wait for installs to take the release (**DECISION**: how long; suggest at least 30 days, and until the update-check numbers show most installs on it). Until then the index must still verify against what old installs hold, so keep signing with the OLD key.
4. Switch CI to sign with the new key. Installs on the dual-key release accept it; installs still on the old release start refusing the index and fall back to their cached catalogue (with the failure shown to a Manager), which is the safe direction.
5. Ship a release that drops the old public key. Destroy the old secret (CI and offline).

The control plane has no way to fetch a key from the network on purpose: a key delivered by the same channel an attacker controls proves nothing.

## 4. A suspected leak

Treat any of these as a leak: the secret appeared in a log or a chat, a CI run or workflow change we cannot explain, a departing maintainer who held it, a repository-secret exposure notice from the host.

1. **Stop publishing.** Disable the `publish.yml` workflow and delete the key from the CI secret store. Pull the index: remove `index.json` and `index.json.sig` from the bucket (or replace them with an index that lists no templates, signed with the new key once it exists). Installs then keep their cached catalogue and report the source as unreachable; nothing new can be installed from it, and nothing already installed changes.
2. **Revoke by release.** Ship a control-plane release that drops the leaked public key (and, in an emergency, carries only the new key; there is no overlap period, so installs on the old release lose that index until they update). This is the only revocation there is: a key in an old install stays trusted until the install updates, which is why the CI must stop signing at step 1 and why installs should update.
3. **Look for what was signed.** Compare the bucket's history and every `sha256` in the published indexes against the repository at the time. Anything not from a merged commit is a bad template: remove it from the index, and tell owners of any install of it (the `services` table records template id and version).
4. **Rotate.** New key as in section 3, steps 1 and 5; the dual-key step is skipped when the old key is compromised.
5. Write up what happened (**DECISION**: where incident notes live and who is told).

## 5. Open decisions for Matt

1. **Who holds the keys.** Which people can read the CI secret, which one person holds the offline backup. (Suggest: you for both, a second maintainer added later.)
2. **Where the offline copy lives.** A password manager entry with a recovery contact, a hardware-encrypted USB in a safe, or a secrets manager with its own audit log. It must survive the loss of the CI account.
3. **One key or two.** Draft assumes two (official and community) so the community CI never holds the official key. Cheaper: one key, community only until an official index is needed.
4. **Whether an official index exists at launch.** Built-in templates ship in the release and cover the first services; the official index is only for official updates between releases. Not needed for step 7 to ship the community side.
5. **Rotation cadence and overlap length** (suggest yearly, 30 days overlap).
6. **Approval for releases of the official index** (a second reviewer or not).
7. **Bucket and CDN.** Which S3-compatible bucket and domain; publish-only credentials for the CI user; the public URL is what goes in `config('templates.sources')` (a `community` entry with the `url`), which the control-plane release sets together with the public key.
8. **Public keys in config.** `official_public_key` and `community_public_key` are empty until 1 and 2 are decided; with an empty key, an official or community index is refused (not trusted by default).

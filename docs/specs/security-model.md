# Security model

What this system trusts, what it does not, and which controls are implemented
versus planned.

Written to be reviewed adversarially. If a claim here is not backed by a test,
it says so.

## Assets, ranked

1. **Customer DigitalOcean credentials.** An OAuth grant for a customer
   account can create, destroy and read infrastructure. Compromise is
   catastrophic and not recoverable by us.
2. **The organisation CA private key.** Mints node identities for an entire
   organisation. Compromise means impersonating any node, or issuing a
   certificate for a node that does not exist.
3. **Customer environment variables.** Database passwords, API keys,
   application secrets, held so they can be written to nodes.
4. **Node private keys.** One node each. Compromise means impersonating that
   node to the control plane.
5. **Enrollment tokens.** Single use, short lived, bound to one identity.
   Lowest value by design.

## Trust boundaries

```
  operator ──▶ control plane ──▶ DigitalOcean API
                    │
                    │ mTLS, agent-initiated
                    ▼
                  agent ──▶ node root
```

**The control plane does not trust the agent.** Node identity comes from the
verified client certificate, never from what the agent says about itself. A
`hello` claiming a different node than its certificate closes the connection.

**The agent does not fully trust the control plane.** It will not execute an
arbitrary step type, and `exec` is allow-listed. A compromised control plane
can do a great deal of damage, but not by instructing a node to run anything
it likes.

**Neither trusts the network.** TLS 1.3 only, mutual authentication, and a
pinned CA rather than the host root store.

## Implemented controls

Each is backed by a named test.

| Control | Where | Test |
|---|---|---|
| Node cannot choose its own identity | `pki.CA.IssueNode` overrides the CSR subject | `TestIssueNodeIgnoresCSRCommonName`, `TestEnrolleeCannotChooseItsIdentity` |
| Enrollment tokens survive exactly one use, under concurrency | `enroll.MemoryStore.Consume` | `TestTokenIsSingleUse`, `TestConcurrentEnrollYieldsOneWinner` |
| A malformed CSR still burns the token | `enroll.Service.Enroll` | `TestMalformedCSRStillBurnsToken` |
| Rejection reasons are not disclosed to unauthenticated callers | `enroll.RejectionError` | `TestRejectionIsOpaque` |
| Only a token hash is stored | `enroll.HashToken` | `TestStoreHoldsNoPlaintextToken` |
| CSR proof of possession is verified | `pki.ParseCSR` | `TestParseCSRRejectsTamperedSignature` |
| Node certificates are client-auth only | `pki.CA.IssueNode` | `TestNodeCertificateIsClientAuthOnly` |
| A foreign CA never validates | `transport.ServerTLS` | `TestForeignCAIsRejected`, `TestServerRejectsForeignCertificate` |
| Anonymous clients are refused | `RequireAndVerifyClientCert` | `TestServerRejectsClientWithoutCertificate` |
| The agent rejects an impostor control plane | `transport.ClientTLS` | `TestClientRejectsForeignServer`, `TestClientVerifiesServerName` |
| TLS 1.2 cannot be negotiated | `minTLSVersion` | `TestTLS13IsRequired` |
| Unknown recipe step types are refused, not executed | `recipe.StepSpec.build` | `TestUnknownStepTypeIsRejected`, `TestApplyRecipeRejectsUnknownStepType` |
| File modes cannot request setuid | `recipe.parseMode` | `TestParseMode` |
| Secrets do not reach step descriptions or deploy logs | `StepFile.Sensitive` | `TestFileRedactsSensitiveDescription`, `TestSensitiveSpecDoesNotLeakIntoDescriptions` |
| An exec allow list, when configured, cannot be defeated by a path | `agentcore.checkAllowed` | `TestConfiguredAllowListIsEnforced`, `TestConfiguredAllowListRejectsPaths` |
| Site names cannot escape the sites root | `agentcore.siteManager` | `TestSiteNameCannotEscapeSitesRoot` |
| Release IDs from webhook refs cannot traverse paths | `deploy.NewReleaseID`, `ValidateReleaseID` | `TestNewReleaseIDSanitisesHostileRefs`, `TestValidateReleaseIDRejectsTraversal` |
| Shared paths cannot escape the release directory | `deploy.validateRel` | `TestPrepareRejectsEscapingSharedPath` |
| The agent private key is not group or world readable | `agentcore.writeFileAtomic` | `TestAgentKeyIsNotReadableByOthers` |
| Oversized frames are rejected without allocating | `protocol.Reader.Read` | `TestReadRejectsOversizedFrame` |
| Replayed commands do not re-execute | `agentcore.idempotencyCache` | `TestIdempotencyKeySuppressesDuplicateExecution` |
| Credentials are never stored in plaintext | `Vault::put` | `test_plaintext_is_never_stored` |
| One tenant's ciphertext is useless under another's key | per-organisation data keys | `test_one_organisation_cannot_read_another_with_its_own_key` |
| A ciphertext moved or renamed in the database fails to decrypt | AEAD associated data | `test_a_ciphertext_moved_between_organisations_fails_to_decrypt`, `test_a_renamed_ciphertext_fails_to_decrypt` |
| A lost master key fails loudly, not silently empty | `Vault::get` | `test_a_lost_master_key_fails_loudly` |
| Master key rotation does not re-encrypt secrets | `Vault::rewrapDataKeys` | `test_rewrapping_changes_data_keys_but_not_ciphertexts` |
| Audit records cannot be edited undetected | hash chain | `test_editing_a_record_is_detected` |
| Audit records cannot be removed undetected, including from the end | chain head comparison | `test_deleting_records_from_the_end_is_detected` |
| Tampering with one tenant's chain does not break another's | per-organisation chains | `test_tampering_with_one_organisation_does_not_break_another` |
| Secrets in audit metadata are redacted on the way in | `AuditLog::redact` | `test_sensitive_metadata_keys_are_redacted` |
| Failures and denials are recorded, not only successes | `Outcome` | `test_failures_and_denials_are_recorded` |
| Enrollment rejections are audited even when unattributable | `EnrollmentService::auditRejection` | `test_rejections_are_audited_even_when_unattributable` |
| A node cannot choose its own identity (control plane side) | `CertificateAuthority::issueNodeCertificate` | `test_a_node_cannot_choose_its_own_identity` |
| Issued certificates keep their client-auth restriction | post-issue verification | `test_extensions_survive_signing`, `test_issued_certificates_are_client_auth_only` |
| A certificate from another organisation does not chain | one CA per tenant | `test_a_certificate_from_another_organisation_does_not_chain` |
| Hostile CSR input produces a typed rejection, not a crypto exception | `publicKeyFromCsr` | `test_hostile_csr_input_produces_a_typed_rejection` |
| The DigitalOcean token never reaches an exception | `DigitalOcean\Client` | `test_the_token_never_appears_in_an_exception` |
| Rate limit budgets are isolated per customer account | `RateLimiter` | `test_rate_limit_budgets_are_isolated_per_account` |
| A keyless POST is never retried | `Client::post` | `test_a_post_without_an_idempotency_key_is_not_retried` |
| Resources that may be billed are always visible | `ResourceState::mayIncurCost` | `test_billable_includes_everything_we_are_unsure_about` |
| A timed-out create is orphaned, not forgotten | `ResourceState::Orphaned` | `test_a_timed_out_create_becomes_orphaned_not_forgotten` |
| The two languages agree about the wire | `cmd/conformance` | `ConformanceTest` |

## Design choices that are controls

**No SSH private keys at rest.** The dominant difference from Forge. See
ADR 0001.

**No inbound port on the node.** The agent dials out, so a provisioned Droplet
can run default-deny with no SSH exception.

**Zero third-party dependencies in the agent.** The supply chain surface of
the root-privileged component is the Go standard library. See ADR 0003.

**A closed step set.** Seven primitives in the recipe engine. Adding a
capability means shipping a new agent rather than sending a novel payload.
See ADR 0005.

Note the limit of that claim: one of the seven primitives is a command step,
and command steps are not restricted. The closed set bounds what a recipe can
*describe declaratively*, not what it can execute. See the note below.

**Short-lived node certificates.** Thirty days, renewed at seven. A leaked
node key stops being useful quickly, and constant renewal means the path is
exercised rather than discovered broken during an incident.

**Mode set before rename.** Files written through a temp file have their mode
applied before the rename, so a secret is never briefly world readable.

## Not yet implemented

These are design commitments from PLAN.md with no code behind them. Do not
treat the list above as complete coverage.

| Gap | Where it belongs | Note |
|---|---|---|
| **KMS-backed master key** | Control plane | The vault is built and the `KeyManager` interface exists precisely so this is an implementation swap, but today the master key lives in configuration. Until it moves, a leak of config plus database is sufficient to decrypt everything. |
| **Certificate renewal** | Agent | `pki.NeedsRenewal` exists and is tested, but nothing calls it. A node will expire after 30 days. |
| **Certificate revocation** | Control plane | No CRL or OCSP. Revoking a node today means rotating the organisation CA. |
| **Secret redaction in log shipping** | Agent, Phase 5 | Log shipping is not implemented. When it is, redaction must be a pipeline stage, not best effort. |
| **Rate limiting on enrollment** | Control plane | Tokens are 256 bits, so guessing is impractical, but the endpoint is unauthenticated and should be rate limited anyway. |
| **Agent upgrade path** | Both | Shipping software to customer machines means being able to update it. Unsolved. |
| **Tenant isolation testing** | Control plane | PLAN.md scopes this as its own work item. Nothing to test yet. |

## Exec is unrestricted by default, deliberately

Earlier versions shipped an allow list on the `exec` command, justified as
stopping a compromised control plane from running arbitrary commands on a
node. That justification did not hold, and the list has been made opt-in.

Two reasons it did not hold:

1. **It was already bypassable.** The recipe engine has a command step, and
   command steps were never allow-listed. A control plane that wanted
   arbitrary execution would send a recipe rather than an exec. The list
   closed one door in a room with two.

2. **It restricted the wrong party.** The customer owns the server, pays for
   it, and has root on it through their own DigitalOcean account. A list of
   commands we are willing to run on their behalf inconveniences the owner
   and nobody else.

The net effect was that `tail` and `systemctl status` were refused for the
person who owned the machine, while an attacker holding the control plane was
unaffected. That is worse than no control, because it reads like one.

The mechanism remains for organisations that want a narrower surface for
their own reasons, such as running nodes on someone else's behalf. It is off
unless configured.

**What actually bounds a compromised control plane** is narrower than the old
claim and worth stating plainly: the agent is a compiled binary with a fixed
command set rather than an interpreter, so a control plane can only invoke
behaviour the agent already implements. It cannot, for instance, make the
agent exfiltrate the node's private key, because no command does that. That
is a real boundary. It is not a sandbox.

## Concurrency

The agent multiplexes heartbeats, progress streaming and command replies over
a single connection, so `internal/transport` is the most concurrency-sensitive
code in the system and the place a data race would do the most damage.

It runs clean under the race detector on both Windows and Linux, including a
stress pass that repeats the concurrent packages with varied parallelism.
`scripts/test-race.sh`.

That is evidence, not proof. The race detector reports races that occur during
a run; it cannot report ones that did not happen to interleave. Two schedulers
and repeated runs raise the odds of catching something, and nothing more.

## Known residual risks

**A compromised control plane is game over for managed nodes.** It can issue
certificates, send recipes, and run commands. The agent's fixed command set
means it can only do things the agent implements, which rules out some
attacks, but running arbitrary commands as root is one of the things the
agent implements. This is inherent to the category: a remote management
platform that cannot manage remotely is not one.

**The organisation CA is a single point of compromise per tenant.** Mitigated
by scoping one CA per organisation, so a breach is contained to one tenant,
and by holding the key in the vault rather than beside the certificate. The
vault's master key is still config-held, so the mitigation is partial.

**Enrollment tokens travel in cloud-init user_data.** Readable by anyone with
DigitalOcean API access to the customer account, which is a group that already
has more power than the token confers. The token is single use and expires in
an hour, and the agent deletes the token file after use.

**The agent runs as root.** Required to manage nginx, PHP-FPM and systemd. The
per-site unprivileged deploy user described in PLAN.md §2.2 is not yet
implemented, so deploy operations currently run with more privilege than they
should.

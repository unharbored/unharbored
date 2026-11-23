# Certificate control

Status: **agreed; steps 1-4 built (DNS validation later).** Answers recorded at the bottom.

## What the user wants

Today a site gets one kind of certificate: Let's Encrypt over HTTP-01, issued by
`Sites::certificateRecipe`, renewed by certbot's own timer, with no way to see
or influence any of it. Users (and Forge, which this follows) expect to:

- see when the certificate expires and whether it renews on its own
- renew it now
- upload their own certificate (a paid or corporate one)
- choose how it is issued and renewed

## What exists

- `sites.tls` (bool) is the only state. The vhost reads
  `/etc/letsencrypt/live/{domain}/fullchain.pem` and `privkey.pem`
  (`Sites.php` ~1891, ~1905).
- `LoadBalancers::syncCertificate` reads the same live path to upload to the balancer.
- `retireCertificateRecipe` removes the certbot lineage on domain change.
- Web ports must stay open for HTTP-01, which is why Tailscale's tailnet-only web option is refused when a certificate exists.

## Design: smallest thing that works

One new column pair on `sites`, no new table:

- `cert_source` — `letsencrypt` (default) or `custom`
- `cert_expires_at` — read from the server, shown in the UI

### 1. Expiry and status (do first, read-only, no risk)

An operation runs `openssl x509 -enddate -noout -in <fullchain>` through the
agent and stores `cert_expires_at`. The site page shows "Expires 12 Feb (in 61
days)", amber under 21 days, red under 7. Refresh on the site page's existing
poll and after every issue or renew.

### 2. Renew now

`certbot renew --cert-name {domain} --force-renewal --deploy-hook 'systemctl reload nginx'`
as an operation with stages renew, reload, and (when a balancer exists)
`syncCertificate`. Custom certificates refuse it with "upload a new one".
Allowed for roles with `infrastructure`; audited as `site.certificate_renewed`.

### 3. Upload your own certificate

Form takes a PEM certificate chain and private key (optionally a passphrase-free
key only; encrypted keys are refused with a clear message). Validation on the
control plane before anything touches the server:

- chain parses, leaf covers the site's domain (SAN or wildcard)
- key matches the leaf (compare public key moduli/points)
- not already expired; warn under 21 days

Files are written by recipe (key as `sensitive`, mode 0600) to
`/etc/cloud-manager/certs/{domain}/`, never into `/etc/letsencrypt`, so certbot
can never overwrite them. The vhost generator picks the path from
`cert_source`. The key is not stored in our database after the write. A site
switching to `custom` also disables certbot renewal for that name. Switching
back to Let's Encrypt issues a fresh certificate and removes the uploaded files.
The load balancer sync reads whichever path is active.

Expiry reminders for custom certificates are the point of this feature: the
user must renew by hand, so the page and the weekly digest (if one exists by
then) warn at 30, 14 and 7 days.

### 4. Renewal options (small)

- **Auto-renew on/off** for Let's Encrypt: certbot renews every certificate on
  the box with one timer, so "off" means setting `autorenew = False` in that
  name's `renewal/{domain}.conf`. Default on. Off shows the same expiry warnings.
- No custom renewal schedule or days-before setting. certbot's 30-days-before
  default is fine and a knob invites misconfiguration.

### 5. Later: DNS-validated and wildcard certificates

Needs DNS provider credentials per organisation (same vault pattern as
Tailscale) and certbot DNS plugins per provider. This is the only route to
wildcards and the only way to keep web ports closed, which would lift the
Tailscale restriction. Larger than 1-4 combined; its own spec when wanted.

## Build order

1. Expiry display (migration, operation, UI, tests)
2. Renew now
3. Upload custom certificate, with vhost and balancer path switch
4. Auto-renew toggle
5. DNS validation (separate spec)

## Not doing

- CSR generation, certificate cloning between sites, per-site ACME accounts or
  contact emails, other ACME providers (ZeroSSL, Buypass). Say if you want any.

## Decisions

1. First slice is steps 1-4; DNS validation is its own spec later.
2. A custom certificate goes to the **primary (home) server only**. Its vhost is the only one with an `ssl_certificate`; with a balancer TLS ends at the balancer, and extra servers answer port 80 only. With a balancer, the balancer copy is uploaded from the primary as now. (Revised in review: the earlier "every server" decision is dropped because secondary nodes never terminate TLS, so a private key there was exposure with no use, and nodes added later would not have had it anyway.)
3. Expiry warnings use the **existing alerts system** (`App\Lib\Alerts\Alerts`: checked every minute, webhook plus email, opens once and clears once). Add one rule, `certificate_days` (default 14, 0 = off), that opens an alert for any site whose `cert_expires_at` is inside the window, and clears when it renews. This is the event and notification hook; richer routing can be added in Alerts later. The site page also shows the date and colour.

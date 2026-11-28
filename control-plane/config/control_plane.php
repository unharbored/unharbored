<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------
    | Session address
    |---------------------------------------------------------------------
    |
    | The host:port agents are told to connect to after enrolling, and hold a
    | long-lived mutually authenticated connection to.
    |
    | This is not the HTTP address. Enrollment is an ordinary HTTPS request
    | that PHP-FPM can serve; the session is a persistent mTLS stream that it
    | cannot. See docs/adr/0007-session-gateway.md.
    |
    */

    'session_address' => env('CONTROL_PLANE_SESSION_ADDRESS', '127.0.0.1:9443'),

    /*
    |---------------------------------------------------------------------
    | Server name
    |---------------------------------------------------------------------
    |
    | The name on the control plane certificate. Agents verify it, so it must
    | match whatever terminates TLS in front of this application.
    |
    */

    // The address this server connects out from, shown where a user must allow it through a firewall (SSH to their own server).
    'outbound_ip' => env('CONTROL_PLANE_OUTBOUND_IP'),

    // GitHub accounts (besides the app's owner) whose installations of the GitHub App we use, comma separated. Needed once the app is public.
    'github_allowed_accounts' => env('GITHUB_ALLOWED_ACCOUNTS', ''),

    'server_name' => env('CONTROL_PLANE_SERVER_NAME', 'control-plane.local'),

    /*
    |---------------------------------------------------------------------
    | Agent distribution
    |---------------------------------------------------------------------
    |
    | Where agent builds live, and what version to report. The control plane
    | serves them itself and hashes whatever is present, so there is no second
    | thing to host or keep in sync. Build with scripts/build-agent.sh.
    |
    | The checksum is not optional: the binary is downloaded over the network
    | and then run as root, so verifying it is the only thing between a
    | hijacked download and root on a fresh machine.
    |
    */

    'agent_directory' => env('AGENT_DIRECTORY', storage_path('agent')),

    'agent_version' => env('AGENT_VERSION', 'dev'),

    /*
    | The base URL a booting node uses to reach this control plane.
    |
    | Must be reachable from the Droplet, which means it is not localhost. It
    | is also where the node fetches the agent binary and enrolls, so getting
    | it wrong produces a Droplet that boots and never appears.
    */

    'public_url' => env('CONTROL_PLANE_PUBLIC_URL', ''),

    /*
    | The organisation whose CA signed the gateway's server certificate.
    |
    | An agent pins one certificate to recognise the gateway. The gateway has a single server certificate, so on an
    | installation with several teams every agent must pin that same CA, not its own team's: each team's CA still
    | issues that team's node certificates and is the only one its agents' client certificates are checked against.
    | Empty means each organisation's own CA, which is right for an installation with one.
    */
    'server_ca_organization' => env('CONTROL_PLANE_SERVER_CA_ORG', ''),

    /*
    |---------------------------------------------------------------------
    | Enrollment rate limit
    |---------------------------------------------------------------------
    |
    | Attempts per minute per IP. Tokens carry 256 bits of entropy so guessing
    | is not the threat; this bounds the cost of someone pointing a load
    | generator at the one endpoint that does public key cryptography.
    |
    */

    'enrollment_attempts_per_minute' => (int) env('ENROLLMENT_RATE_LIMIT', 30),

    /*
    |---------------------------------------------------------------------
    | Session gateway
    |---------------------------------------------------------------------
    |
    | The gateway holds every agent connection, which PHP-FPM cannot. See
    | docs/adr/0007-session-gateway.md.
    |
    | The token authenticates the link in both directions. Anything that can
    | present it can run commands as root on every connected node, so the
    | gateway must not be reachable from outside and the token is a second
    | line of defence rather than the first.
    |
    */

    'gateway_url' => env('GATEWAY_URL', 'http://127.0.0.1:9444'),

    'gateway_token' => env('GATEWAY_TOKEN', ''),

    /*
    | Previews answer at <branch>.<site>.<preview_domain>. A wildcard DNS
    | record for *.<preview_domain> points at the preview server.
    */

    'preview_domain' => env('PREVIEW_DOMAIN'),

    /*
    |---------------------------------------------------------------------
    | Custom code folder
    |---------------------------------------------------------------------
    |
    | Where CustomServiceProvider looks for an install's own providers,
    | routes and migrations. Not in git. See docs/self-hosting.md.
    |
    */

    'custom_path' => base_path('custom'),

];

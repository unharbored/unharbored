<?php

declare(strict_types=1);

namespace App\Lib\Providers\Custom;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Operations\OperationRunner;
use App\Lib\Vault\Vault;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
use SensitiveParameter;

/**
 * Custom provisioning: log in as root (or a sudo user) once, install the agent,
 * keep nothing. After this step SSH is never used again; repair, deploy,
 * upgrade and removal all go through the agent (ADR 0001).
 *
 * The login is held in the vault under a one-off name for the minutes the
 * install takes, deleted when it ends, success or failure, and by a sweep of
 * anything older than an hour. It never enters a job payload, the audit log or
 * the operation log; the log shows the host key fingerprint instead.
 */
final class SshAdoption
{
    /** Minutes a secret may live, however it ends. */
    public const SECRET_TTL_MINUTES = 60;

    /** Memory below this cannot run the stack. A "1 GB" machine reports a little less. */
    private const MIN_MEMORY_KB = 900_000;

    public function __construct(
        private readonly Vault $vault,
        private readonly OperationRunner $runner,
        private readonly EnrollmentService $enrollment,
        private readonly CloudInit $cloudInit,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Records the request and queues the operation. Returns the operation id.
     *
     * @throws InvalidArgumentException naming the field at fault
     */
    public function start(
        string $organizationId,
        string $name,
        string $host,
        int $port,
        string $user,
        #[SensitiveParameter] ?string $privateKey,
        #[SensitiveParameter] ?string $passphrase,
        #[SensitiveParameter] ?string $password,
        ?string $expectedFingerprint,
        string $actor,
        ?string $role = null,
    ): int {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $name)) {
            throw new InvalidArgumentException('name: lowercase letters, digits and dashes.');
        }
        $nodeId = "{$name}.{$organizationId}";
        if (DB::table('nodes')->where('node_id', $nodeId)->exists()
            || DB::table('resources')->where('organization_id', $organizationId)->where('slug', $name)->exists()) {
            throw new InvalidArgumentException("name: there is already a server called {$name}.");
        }
        if (! preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$|^[0-9a-fA-F:]+$/', $host)) {
            throw new InvalidArgumentException('host: a hostname or an IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('port: 1 to 65535.');
        }
        if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            throw new InvalidArgumentException('user: a login name such as root.');
        }
        $privateKey = $privateKey !== null && trim($privateKey) !== '' ? trim($privateKey)."\n" : null;
        $password = $password !== null && $password !== '' ? $password : null;
        if (($privateKey === null) === ($password === null)) {
            throw new InvalidArgumentException('key: give a private key or a password, not both and not neither.');
        }
        if ($privateKey !== null) {
            try {
                PublicKeyLoader::load($privateKey, ($passphrase ?? '') !== '' ? $passphrase : false);
            } catch (\Throwable) {
                throw new InvalidArgumentException('key: that is not a private key, or the passphrase is wrong.');
            }
        }
        $expected = $expectedFingerprint !== null && trim($expectedFingerprint) !== '' ? trim($expectedFingerprint) : null;
        if ($expected !== null && ! preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $expected)) {
            throw new InvalidArgumentException('fingerprint: as ssh shows it, SHA256: and 43 characters.');
        }

        if ($role !== null) {
            if ($role !== \App\Lib\Providers\Incus\IncusHost::ROLE) {
                throw new InvalidArgumentException('role: an Incus host, or nothing.');
            }
            try {
                app(\App\Lib\Providers\Incus\IncusHost::class)->assertCanAdd($organizationId);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('name: '.$e->getMessage());
            }
        }

        $vaultKey = 'ssh-adopt.'.bin2hex(random_bytes(12));
        $this->vault->put($organizationId, $vaultKey, json_encode(['key' => $privateKey, 'passphrase' => $passphrase, 'password' => $password], JSON_THROW_ON_ERROR), ['purpose' => 'ssh-adopt']);

        $id = DB::table('ssh_adoptions')->insertGetId([
            'organization_id' => $organizationId, 'node_id' => $nodeId, 'host' => $host, 'port' => $port, 'ssh_user' => $user,
            'expected_fingerprint' => $expected, 'vault_key' => $vaultKey, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $operation = $this->runner->queueAdoptSsh($organizationId, $nodeId, $id, 'console', $actor, $role);
        DB::table('ssh_adoptions')->where('id', $id)->update(['operation_id' => $operation]);

        // Who connected to where: never the credential.
        $this->audit->record($organizationId, Actor::user($actor), 'server.ssh_adoption_started', resourceType: 'node', resourceId: $nodeId,
            metadata: ['host' => $host, 'port' => $port, 'user' => $user, 'expected_fingerprint' => $expected]);

        return $operation;
    }

    /**
     * Connect, check, install. The login is discarded however this ends.
     *
     * @param  Closure(string): void  $stage
     * @param  Closure(string, string): void  $log
     */
    public function connectAndInstall(object $adoption, Closure $stage, Closure $log): void
    {
        $shell = app(RemoteShell::class);
        try {
            $stage('connect');
            $fingerprint = $shell->connect($adoption->host, (int) $adoption->port);
            $log('ssh', "{$adoption->host}:{$adoption->port} host key {$fingerprint}");
            if ($adoption->expected_fingerprint !== null && $adoption->expected_fingerprint !== $fingerprint) {
                throw new RuntimeException("The host key is {$fingerprint}, not the {$adoption->expected_fingerprint} you expected. Nothing was sent. If you did not change the server, someone may be in the way.");
            }
            $secret = json_decode((string) $this->vault->get($adoption->organization_id, (string) $adoption->vault_key), true)
                ?: throw new RuntimeException('The login is no longer held; start again.');
            $shell->authenticate($adoption->ssh_user, $secret['key'] ?? null, $secret['passphrase'] ?? null, $secret['password'] ?? null);
            unset($secret);
            DB::table('ssh_adoptions')->where('id', $adoption->id)->update(['fingerprint' => $fingerprint, 'updated_at' => now()]);
            $log('ssh', "logged in as {$adoption->ssh_user}");

            $stage('preflight');
            $sudo = $adoption->ssh_user === 'root' ? '' : 'sudo -n ';
            $problems = $this->preflight($shell, $adoption->ssh_user, $log);
            if ($problems !== []) {
                throw new RuntimeException("This machine is not ready, and nothing was changed on it:\n- ".implode("\n- ", $problems));
            }

            $stage('install');
            $token = $this->enrollment->issueToken($adoption->organization_id, $adoption->node_id, 15 * 60);
            $ca = $this->enrollment->certificateAuthorityFor($adoption->organization_id)->certificatePem();
            $script = base64_encode($this->cloudInit->installScript($adoption->node_id, $token, $ca));
            $r = $shell->run('umask 077; f=$(mktemp) && printf %s '.escapeshellarg($script)." | base64 -d > \"\$f\" && {$sudo}bash \"\$f\"; rc=\$?; rm -f \"\$f\"; exit \$rc", 600);
            foreach (array_slice(preg_split('/\R/', trim($r['output'])) ?: [], -8) as $line) {
                if (trim($line) !== '') {
                    $log('install', trim($line));
                }
            }
            if ($r['exit'] !== 0) {
                throw new RuntimeException('The installer failed on the server (exit '.$r['exit'].'). Its last lines are above.');
            }
            DB::table('ssh_adoptions')->where('id', $adoption->id)->update(['installed_at' => now(), 'updated_at' => now()]);
        } finally {
            $shell->disconnect();
            $this->discard($adoption);
        }
    }

    /**
     * What stops this machine from taking the agent and stack, each said
     * plainly. Reads only.
     *
     * @param  Closure(string, string): void  $log
     * @return list<string>
     */
    private function preflight(RemoteShell $shell, string $user, Closure $log): array
    {
        $problems = [];
        $out = fn (string $cmd): array => $shell->run($cmd, 60);
        $check = function (string $what, bool $ok, string $problem, string $detail = '') use (&$problems, $log): void {
            $log('check', ($ok ? 'ok    ' : 'FAIL  ').$what.($detail !== '' ? " ({$detail})" : ''));
            if (! $ok) {
                $problems[] = $problem;
            }
        };

        $os = trim($out('. /etc/os-release 2>/dev/null; echo "${ID:-?} ${VERSION_ID:-?}"')['output']);
        $check('Ubuntu 24.04', $os === 'ubuntu 24.04', "It runs {$os}; the stack is built for Ubuntu 24.04.", $os);

        $arch = trim($out('uname -m')['output']);
        $check('x86_64 or aarch64', in_array($arch, ['x86_64', 'aarch64'], true), "Architecture {$arch} has no agent build.", $arch);

        if ($user === 'root') {
            $check('root', true, '');
        } else {
            $check('passwordless sudo', $out('sudo -n true')['exit'] === 0, "{$user} cannot run sudo without a password; log in as root or allow it.");
        }

        $check('systemd', $out('[ -d /run/systemd/system ]')['exit'] === 0, 'It does not run systemd.');

        $kb = (int) trim($out("awk '/MemTotal/ {print \$2}' /proc/meminfo")['output']);
        $check('1 GB of memory', $kb >= self::MIN_MEMORY_KB, 'It has '.round($kb / 1024).' MB of memory; the stack needs about 1 GB.', round($kb / 1024).' MB');

        $busy = trim($out("ss -ltn 2>/dev/null | awk '\$4 ~ /:(80|443)\$/ {print \$4}' | tr '\\n' ' '")['output']);
        $check('ports 80 and 443 free', $busy === '', "Something already listens on {$busy}; nginx needs both ports.");

        $apt = $out('command -v apt-get >/dev/null && apt-get -s install curl >/dev/null 2>&1');
        $check('apt works', $apt['exit'] === 0, 'apt-get cannot run (a held lock or a broken package list).');

        $name = (string) config('control_plane.server_name');
        $tcp = fn (int $port) => $out("timeout 8 bash -c '</dev/tcp/{$name}/{$port}' 2>/dev/null")['exit'] === 0;
        $check("outbound {$name}:443", $tcp(443), "It cannot reach {$name} on port 443, so the agent could not download or enroll.");
        $check("outbound {$name}:9443", $tcp(9443), "It cannot reach {$name} on port 9443, the gateway the agent holds open.");

        return $problems;
    }

    /** Forgets the login. Safe to repeat. */
    public function discard(object $adoption): void
    {
        if ($adoption->vault_key !== null) {
            $this->vault->forget($adoption->organization_id, (string) $adoption->vault_key);
            DB::table('ssh_adoptions')->where('id', $adoption->id)->update(['vault_key' => null, 'updated_at' => now()]);
        }
    }

    /** Deletes every login still held after its time. @return int how many */
    public function sweep(): int
    {
        $n = 0;
        foreach (DB::table('ssh_adoptions')->whereNotNull('vault_key')->where('created_at', '<', now()->subMinutes(self::SECRET_TTL_MINUTES))->get() as $adoption) {
            $this->discard($adoption);
            $n++;
        }

        return $n;
    }
}

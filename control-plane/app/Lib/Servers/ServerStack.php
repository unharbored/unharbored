<?php

declare(strict_types=1);

namespace App\Lib\Servers;

/**
 * The base software every application server runs: nginx, PHP-FPM,
 * supervisor, git and Composer, plus the unprivileged user that owns the
 * sites.
 *
 * The output is a recipe spec for the agent's apply_recipe command. Every
 * step is idempotent, so applying it to a server that already has the stack
 * changes nothing; that is what makes it safe to re-run after a partial
 * failure, and it is the property the tests pin.
 *
 * Sites themselves are not configured here. This is what exists before the
 * first site does.
 */
final class ServerStack
{
    /**
     * The branch installed by default. The ondrej/php PPA carries several,
     * and a server can run more than one at a time so different applications
     * on it can choose their own.
     */
    public const PHP_VERSION = '8.4';

    /** Branches the platform offers, default first. All are still supported. */
    public const PHP_VERSIONS = ['8.4', '8.3', '8.5', '8.2'];

    /** The PHP binary every platform command names explicitly. */
    public const PHP_BINARY = '/usr/bin/php'.self::PHP_VERSION;

    public static function phpBinary(string $version): string
    {
        return '/usr/bin/php'.$version;
    }

    public static function supportsPhp(string $version): bool
    {
        return in_array($version, self::PHP_VERSIONS, true);
    }

    /** The packages one PHP branch needs: its FPM, CLI and the extensions. */
    public static function phpPackages(string $version): array
    {
        return array_map(fn (string $ext): string => "php{$version}-{$ext}", self::PHP_EXTENSIONS);
    }

    /** Installed as a phar, not from apt; see spec(). */
    /** The PostgreSQL client major version; at least that of any managed cluster. */
    public const PG_CLIENT = '18';

    public const COMPOSER = '/usr/local/bin/composer';

    /** Shared HTTP-01 challenge directory for every site on the node. */
    public const ACME_WEBROOT = '/var/www/acme';

    /** The user that owns every site and runs PHP-FPM and workers. */
    public const DEPLOY_USER = 'deploy';

    /**
     * The user people log in as: SSH by key, sudo by a password Unharbored
     * keeps. One constant, recorded per server as it is built, so the name can
     * change later without stranding a server that has the old one.
     */
    public const ADMIN_USER = 'cloudmgr';

    /** Room for long server names: an sslip.io name on a balancer passes nginx's default. */
    public const NGINX_NAMES = "server_names_hash_bucket_size 128;\n";

    /**
     * The extensions a typical Laravel application expects. MySQL and
     * PostgreSQL drivers are both present because Phase 2 attaches managed
     * databases of either kind without reprovisioning the server.
     */
    private const PHP_EXTENSIONS = [
        'fpm', 'cli', 'mbstring', 'xml', 'curl', 'zip', 'bcmath', 'intl',
        'sqlite3', 'mysql', 'pgsql', 'redis', 'gd',
    ];

    /**
     * @param  array<string, mixed>  $phpSettings  the default branch's PhpSettings; the defaults if none are given
     * @param  array{user: string, hash: string, keys: list<string>}|null  $admin  the login user, its password hash and keys
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function spec(array $phpSettings = PhpSettings::DEFAULTS, ?array $admin = null): array
    {
        $php = self::PHP_VERSION;
        $user = self::DEPLOY_USER;

        // Packages come from archive.ubuntu.com and from the PHP PPA, and a download that stalls once fails a whole install
        // (the PPA's mirror did, on a first server). apt tries each one again before giving up.
        $steps = [['id' => 'apt-retries', 'type' => 'file', 'path' => '/etc/apt/apt.conf.d/80-cm-retries', 'content_b64' => base64_encode("Acquire::Retries \"5\";\n"), 'mode' => '0644']];

        foreach (['nginx', 'supervisor', 'cron', 'git', 'unzip', 'acl', 'certbot'] as $package) {
            $steps[] = ['id' => "pkg-{$package}", 'type' => 'package', 'name' => $package];
        }

        // The PPA's maintainer name is not ASCII, and add-apt-repository fails
        // decoding it under the C locale an agent runs with.
        $steps[] = [
            'id' => 'php-ppa',
            'type' => 'command',
            'name' => 'env',
            'args' => ['LC_ALL=C.UTF-8', 'add-apt-repository', '-y', 'ppa:ondrej/php'],
            'unless' => ['sh', '-c', 'ls /etc/apt/sources.list.d/ | grep -q ondrej-ubuntu-php'],
            'description' => 'ondrej/php PPA is configured',
        ];

        // Database clients for site backups. pg_dump must be at least the
        // server's major version, and DigitalOcean runs a newer PostgreSQL
        // than Ubuntu ships, so the client comes from PostgreSQL's own
        // repository.
        $steps[] = [
            'id' => 'pgdg-repo',
            'type' => 'command',
            'name' => 'sh',
            'args' => ['-c', 'install -d /usr/share/postgresql-common/pgdg'
                .' && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc'
                .' && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo $VERSION_CODENAME)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
                .' && apt-get update -q'],
            'unless' => ['test', '-f', '/etc/apt/sources.list.d/pgdg.list'],
            'description' => 'PostgreSQL apt repository is configured',
        ];
        foreach (['postgresql-client-'.self::PG_CLIENT, 'mysql-client'] as $package) {
            $steps[] = ['id' => "pkg-{$package}", 'type' => 'package', 'name' => $package];
        }

        foreach (self::PHP_EXTENSIONS as $ext) {
            $steps[] = ['id' => "pkg-php-{$ext}", 'type' => 'package', 'name' => "php{$php}-{$ext}"];
        }

        // Not apt's composer: with the PPA present its php-cli dependency
        // resolves to the newest PHP the PPA has, which then becomes the
        // system php. Commands name PHP_BINARY explicitly regardless.
        $composer = self::COMPOSER;
        $steps[] = [
            'id' => 'composer',
            'type' => 'command',
            'name' => 'sh',
            'args' => ['-c', "curl -fsSL https://getcomposer.org/download/latest-2.x/composer.phar -o {$composer}.tmp && chmod 755 {$composer}.tmp && mv {$composer}.tmp {$composer}"],
            'unless' => ['test', '-x', $composer],
            'description' => 'composer is installed',
        ];

        // apt's composer pulls the PPA's newest php-cli and makes it the
        // system php. The phar above is used instead. PHP branches are not
        // purged: a server may run 8.3 and 8.4 side by side.
        $steps[] = [
            'id' => 'retire-apt-composer',
            'type' => 'command',
            'name' => 'sh',
            'args' => ['-c', 'apt-get purge -y composer && apt-get autoremove -y'],
            'unless' => ['sh', '-c', "! dpkg -l | grep -qE '^ii +composer '"],
            'description' => 'apt composer is removed',
        ];

        $steps[] = [
            'id' => 'deploy-user',
            'type' => 'command',
            'name' => 'useradd',
            'args' => ['--create-home', '--shell', '/bin/bash', '--user-group', $user],
            'unless' => ['id', '-u', $user],
            'description' => "user {$user} exists",
        ];

        // useradd creates the home 0750. nginx runs as www-data and has to
        // traverse it to reach each site's public directory.
        $steps[] = ['id' => 'deploy-home', 'type' => 'dir', 'path' => "/home/{$user}", 'mode' => '0755'];

        // Where certbot writes HTTP-01 challenges. Every site's port 80 block
        // serves /.well-known/acme-challenge from here.
        $steps[] = ['id' => 'acme-webroot', 'type' => 'dir', 'path' => self::ACME_WEBROOT, 'mode' => '0755'];

        // PHP runs as the deploy user so the application can write its own
        // storage and cache without world-writable directories. nginx keeps
        // access to the socket through its owner and group.
        array_push($steps, ...$this->phpSettingsFiles($php, $phpSettings));

        // Every deploy reloads PHP-FPM. With the default timeout of 0, a
        // reload kills requests in flight: a deploy behind a load balancer
        // showed it as a handful of 502s. With one, old workers finish what
        // they are doing first.
        $fpmConf = "/etc/php/{$php}/fpm/php-fpm.conf";
        $steps[] = [
            'id' => 'php-fpm-graceful-reload',
            'type' => 'command',
            'name' => 'sed',
            'args' => ['-i', '-E', 's/^;?process_control_timeout = .*/process_control_timeout = 30s/', $fpmConf],
            'unless' => ['grep', '-q', '^process_control_timeout = 30s', $fpmConf],
            'description' => 'PHP-FPM reloads let requests finish',
        ];

        // The distribution's default site answers any Host header with the
        // nginx welcome page. Unknown hosts should get nothing.
        $steps[] = ['id' => 'nginx-no-default', 'type' => 'absent', 'path' => '/etc/nginx/sites-enabled/default'];
        $steps[] = ['id' => 'nginx-names', 'type' => 'file', 'path' => '/etc/nginx/conf.d/00-server-names.conf',
            'content_b64' => base64_encode(self::NGINX_NAMES), 'mode' => '0644'];
        $steps[] = [
            'id' => 'nginx-catch-all',
            'type' => 'file',
            'path' => '/etc/nginx/conf.d/00-catch-all.conf',
            'content_b64' => base64_encode($this->catchAll()),
            'mode' => '0644',
        ];

        if ($admin !== null) {
            array_push($steps, ...$this->adminSteps($admin['user'], $admin['hash'], $admin['keys']));
        }

        foreach (["php{$php}-fpm", 'nginx', 'supervisor', 'cron'] as $unit) {
            $steps[] = ['id' => "svc-{$unit}", 'type' => 'service', 'name' => $unit, 'state' => 'running'];
        }

        // Reload last, so configuration written above takes effect whether
        // the services were just started or have been running for months.
        $steps[] = ['id' => "reload-php{$php}-fpm", 'type' => 'service', 'name' => "php{$php}-fpm", 'state' => 'reloaded'];
        $steps[] = ['id' => 'reload-nginx', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'];

        return ['name' => 'server-stack', 'steps' => $steps];
    }

    /**
     * The login user, and the keys that may use it. It joins the sudo group,
     * which asks for a password; the password is set from a hash through a file
     * that is removed, so it never appears in a command or a log. SSH for this
     * user is confined to keys, so a password can never be guessed over the
     * network. The keys sit between markers, so a key someone added by hand
     * stays.
     *
     * @param  list<string>  $keys  public key lines
     * @return list<array<string, mixed>>
     */
    public function adminSteps(string $user, string $hash, array $keys): array
    {
        $home = "/home/{$user}";
        $hashFile = '/root/.cm-admin-hash';
        $sshd = '/etc/ssh/sshd_config.d/50-cloud-manager.conf';
        $block = "# BEGIN cloud-manager\n".($keys === [] ? '' : implode("\n", $keys)."\n")."# END cloud-manager\n";
        $authorized = "{$home}/.ssh/authorized_keys";

        return [
            ['id' => 'admin-user', 'type' => 'command', 'name' => 'useradd', 'args' => ['--create-home', '--shell', '/bin/bash', '--user-group', $user],
                'unless' => ['id', '-u', $user], 'description' => "login user {$user} exists"],
            ['id' => 'admin-sudo', 'type' => 'command', 'name' => 'usermod', 'args' => ['-aG', 'sudo', $user], 'description' => "{$user} can use sudo"],
            ['id' => 'admin-hash', 'type' => 'file', 'path' => $hashFile, 'content_b64' => base64_encode($hash), 'mode' => '0600', 'sensitive' => true],
            ['id' => 'admin-password', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "usermod -p \"\$(cat {$hashFile})\" {$user}; rc=\$?; rm -f {$hashFile}; exit \$rc"], 'description' => "{$user}'s sudo password is set"],
            // Cloud images ship an SSH server; minimal ones (an Incus container) do not.
            ['id' => 'admin-ssh-server', 'type' => 'package', 'name' => 'openssh-server'],
            // Only this user: nobody else's login on the server changes.
            ['id' => 'admin-ssh-config', 'type' => 'file', 'path' => $sshd, 'mode' => '0644',
                'content_b64' => base64_encode("# Managed by cloud-manager.\nMatch User {$user}\n    PasswordAuthentication no\n    AuthenticationMethods publickey\n")],
            ['id' => 'admin-ssh-check', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "mkdir -p /run/sshd && sshd -t 2>&1 && exit 0; rm -f {$sshd}; exit 1"], 'description' => 'sshd configuration is valid'],
            ['id' => 'admin-ssh-reload', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true'], 'description' => 'sshd rereads its configuration'],
            ['id' => 'admin-ssh-dir', 'type' => 'command', 'name' => 'install', 'args' => ['-d', '-o', $user, '-g', $user, '-m', '0700', "{$home}/.ssh"],
                'description' => "{$home}/.ssh exists"],
            ['id' => 'admin-keys', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "touch {$authorized} && sed -i '/^# BEGIN cloud-manager\$/,/^# END cloud-manager\$/d' {$authorized} && echo ".base64_encode($block)." | base64 -d >> {$authorized} && chown {$user}:{$user} {$authorized} && chmod 600 {$authorized}"],
                'description' => count($keys).' SSH '.(count($keys) === 1 ? 'key is' : 'keys are').' installed'],
        ];
    }

    /**
     * Locks SSH down, or puts it back: no login as root and no passwords, only
     * keys. A drop-in that sorts before cloud-init's own (sshd takes the first
     * value it reads), checked with sshd -t before sshd rereads it, and removed
     * again if sshd refuses it, so a typo never changes who can get in.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function hardeningRecipe(bool $on): array
    {
        $path = '/etc/ssh/sshd_config.d/40-cloud-manager-hardening.conf';
        $reload = ['id' => 'ssh-reload', 'type' => 'command', 'name' => 'sh',
            'args' => ['-c', 'systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true'], 'description' => 'sshd rereads its configuration'];

        return ['name' => 'ssh-hardening', 'steps' => $on ? [
            ['id' => 'ssh-hardening', 'type' => 'file', 'path' => $path, 'mode' => '0644',
                'content_b64' => base64_encode("# Managed by cloud-manager. Removed by Allow root and password login again.\nPermitRootLogin no\nPasswordAuthentication no\nKbdInteractiveAuthentication no\n")],
            ['id' => 'ssh-check', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "mkdir -p /run/sshd && sshd -t 2>&1 && exit 0; rm -f {$path}; exit 1"], 'description' => 'sshd configuration is valid'],
            $reload,
        ] : [
            ['id' => 'ssh-hardening', 'type' => 'absent', 'path' => $path],
            $reload,
        ]];
    }

    /**
     * Just the login user and its keys, for changing them on a running server.
     *
     * @param  array{user: string, hash: string, keys: list<string>}  $admin
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function adminRecipe(array $admin): array
    {
        return ['name' => 'admin-access', 'steps' => $this->adminSteps($admin['user'], $admin['hash'], $admin['keys'])];
    }

    /**
     * Installs one more PHP branch beside the default: its FPM, CLI and the
     * extensions, in its own pool and service. Idempotent, so re-running is
     * safe. Applications already on another branch are untouched.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function phpInstallRecipe(string $version, array $phpSettings = PhpSettings::DEFAULTS): array
    {
        if (! self::supportsPhp($version)) {
            throw new \InvalidArgumentException("PHP {$version} is not offered.");
        }

        $steps = [[
            'id' => 'php-ppa',
            'type' => 'command',
            'name' => 'env',
            'args' => ['LC_ALL=C.UTF-8', 'add-apt-repository', '-y', 'ppa:ondrej/php'],
            'unless' => ['sh', '-c', 'ls /etc/apt/sources.list.d/ | grep -q ondrej-ubuntu-php'],
            'description' => 'ondrej/php PPA is configured',
        ]];

        foreach (self::PHP_EXTENSIONS as $ext) {
            $steps[] = ['id' => "pkg-php-{$ext}", 'type' => 'package', 'name' => "php{$version}-{$ext}"];
        }

        array_push($steps, ...$this->phpSettingsFiles($version, $phpSettings));
        $fpmConf = "/etc/php/{$version}/fpm/php-fpm.conf";
        $steps[] = [
            'id' => 'php-fpm-graceful-reload',
            'type' => 'command',
            'name' => 'sed',
            'args' => ['-i', '-E', 's/^;?process_control_timeout = .*/process_control_timeout = 30s/', $fpmConf],
            'unless' => ['grep', '-q', '^process_control_timeout = 30s', $fpmConf],
            'description' => 'PHP-FPM reloads let requests finish',
        ];
        $steps[] = ['id' => "svc-php{$version}-fpm", 'type' => 'service', 'name' => "php{$version}-fpm", 'state' => 'running'];
        $steps[] = ['id' => "reload-php{$version}-fpm", 'type' => 'service', 'name' => "php{$version}-fpm", 'state' => 'reloaded'];

        return ['name' => "php-{$version}", 'steps' => $steps];
    }

    private function fpmPool(string $version = self::PHP_VERSION, int $maxChildren = 10): string
    {
        $php = $version;
        $user = self::DEPLOY_USER;
        // FPM refuses to start if the spare counts exceed the worker limit.
        $start = min(2, $maxChildren);
        $spare = min(3, $maxChildren);

        return <<<CONF
; Managed by cloud-manager. Changes here are overwritten.
[www]
user = {$user}
group = {$user}
listen = /run/php/php{$php}-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = {$maxChildren}
pm.start_servers = {$start}
pm.min_spare_servers = 1
pm.max_spare_servers = {$spare}
pm.max_requests = 500

CONF;
    }

    /**
     * The three files a branch's settings are written to: its FPM pool, the
     * ini for web requests and the ini for the command line. The ini files are
     * in conf.d under a name PHP package updates never replace.
     *
     * @param  array<string, mixed>  $php  PhpSettings, complete
     * @return list<array<string, mixed>>
     */
    private function phpSettingsFiles(string $version, array $php): array
    {
        return [
            ['id' => 'php-fpm-pool', 'type' => 'file', 'path' => "/etc/php/{$version}/fpm/pool.d/www.conf",
                'content_b64' => base64_encode($this->fpmPool($version, (int) $php['pm_max_children'])), 'mode' => '0644'],
            ['id' => 'php-ini-fpm', 'type' => 'file', 'path' => "/etc/php/{$version}/fpm/conf.d/99-cloud-manager.ini",
                'content_b64' => base64_encode(PhpSettings::iniForFpm($php)), 'mode' => '0644'],
            ['id' => 'php-ini-cli', 'type' => 'file', 'path' => "/etc/php/{$version}/cli/conf.d/99-cloud-manager.ini",
                'content_b64' => base64_encode(PhpSettings::iniForCli($php)), 'mode' => '0644'],
        ];
    }

    /**
     * Applies a branch's saved settings on its own: back up the three files,
     * write them, ask PHP-FPM to check its configuration, put the old files
     * back if it objects, and reload gracefully so requests in flight finish.
     *
     * @param  array<string, mixed>  $php  PhpSettings, complete
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function phpSettingsRecipe(string $version, array $php): array
    {
        if (! self::supportsPhp($version)) {
            throw new \InvalidArgumentException("PHP {$version} is not offered.");
        }
        $dir = "/var/backups/cloud-manager/php/{$version}";
        $files = ['pool' => "/etc/php/{$version}/fpm/pool.d/www.conf", 'fpm' => "/etc/php/{$version}/fpm/conf.d/99-cloud-manager.ini",
            'cli' => "/etc/php/{$version}/cli/conf.d/99-cloud-manager.ini"];
        $backup = 'install -d -m 0700 '.$dir;
        $restore = '';
        foreach ($files as $name => $path) {
            // With no file now, an older backup is stale and must not come back over a failed first write.
            $backup .= " && if [ -f {$path} ]; then cp -p {$path} {$dir}/{$name}; else rm -f {$dir}/{$name}; fi";
            $restore .= "if [ -f {$dir}/{$name} ]; then cp -p {$dir}/{$name} {$path}; else rm -f {$path}; fi; ";
        }
        // php-fpm -t exits 0 on a bad ini line and only prints a PHP: warning, so the output is read too.
        $check = "out=\$(php-fpm{$version} -t 2>&1); rc=\$?; echo \"\$out\"; "
            ."if [ \$rc -eq 0 ] && ! echo \"\$out\" | grep -qE 'PHP:|syntax error|ERROR'; then exit 0; fi; {$restore}exit 1";

        return ['name' => "php-settings-{$version}", 'steps' => [
            ['id' => 'php-settings-backup', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $backup], 'description' => "previous php{$version} settings kept"],
            ...$this->phpSettingsFiles($version, $php),
            ['id' => 'php-settings-check', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $check], 'description' => "php{$version}-fpm configuration is valid"],
            ['id' => "reload-php{$version}-fpm", 'type' => 'service', 'name' => "php{$version}-fpm", 'state' => 'reloaded'],
        ]];
    }

    private function catchAll(): string
    {
        return <<<'CONF'
# Managed by cloud-manager. Requests for a host no site claims are dropped.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}

CONF;
    }
}

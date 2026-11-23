<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use App\Lib\Services\SetupActions;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates one template file against schema 1 (docs/specs/templates.md,
 * section 2) and collects every error with a path (runs.ports) and, as far as
 * the YAML parser lets us tell, a line. The YAML parser only reports a line for
 * syntax errors; for the rest the line is found by searching the text for the
 * key, so it is a best guess.
 *
 * Nothing here runs or downloads anything.
 */
final class DefinitionChecker
{
    public const SCHEMA = 1;

    private const TOP = ['schema', 'id', 'version', 'name', 'category', 'description', 'memory', 'runtime', 'inputs', 'secrets', 'runs', 'proxy', 'requires', 'extra_ports', 'licence', 'source', 'acknowledge', 'backup'];

    private const RUNS_NATIVE = ['download', 'sha256', 'unpack', 'binary', 'user', 'data', 'command', 'setup', 'env', 'ports', 'health', 'limits'];

    private const RUNS_CONTAINER = ['image', 'volumes', 'user', 'read_only', 'capabilities', 'ports', 'env', 'health', 'limits'];

    /** Keys a template might use to escape to the host, refused by name so the message says why. */
    private const REFUSED = [
        'privileged' => 'privileged mode is not allowed',
        'network' => 'network: host is not allowed, the network mode cannot be set',
        'pid' => 'pid: host is not allowed, the process namespace cannot be set',
        'cap_add' => 'added capabilities are not allowed',
        'devices' => 'host devices are not allowed',
        'mounts' => 'only named volumes are allowed (use volumes)',
    ];

    /** The only capabilities a container template may ask for back after `--cap-drop ALL`; each is named in the review screen. */
    public const CAPABILITIES = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETUID', 'SETGID', 'NET_BIND_SERVICE'];

    private const PLACEHOLDER = '/\{\{\s*(.*?)\s*\}\}/';

    /** @var list<array{path: string, line: ?int, message: string}> */
    private array $errors = [];

    /** @var list<string> */
    private array $lines;

    /** @var array<string, array<string, mixed>> declared inputs by key */
    private array $inputs = [];

    /** @var list<string> */
    private array $secrets = [];

    private string $runtime = '';

    public function __construct(private readonly string $yaml)
    {
        $this->lines = preg_split('/\R/', $yaml) ?: [];
    }

    /** @return list<array{path: string, line: ?int, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed>|null the normalised definition, or null when there are errors */
    public function run(): ?array
    {
        try {
            $data = Yaml::parse($this->yaml);
        } catch (ParseException $e) {
            $this->error('', 'not valid YAML: '.preg_replace('/ at line \d+.*$/s', '', $e->getMessage()), $e->getParsedLine() ?: null, false);

            return null;
        }
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            $this->error('', 'a template must be a YAML mapping of fields', null, false);

            return null;
        }

        if (! array_key_exists('schema', $data)) {
            $this->error('schema', 'is required (the number '.self::SCHEMA.')');
        } elseif (is_int($data['schema']) && $data['schema'] !== self::SCHEMA) {
            $this->error('schema', 'this install does not know schema '.$data['schema'].' (it reads schema '.self::SCHEMA.'); update Unharbored or use a file written for schema '.self::SCHEMA);

            return null;
        } elseif ($data['schema'] !== self::SCHEMA) {
            $this->error('schema', 'must be the number '.self::SCHEMA);
        }

        $this->unknown('', $data, self::TOP);

        $out = ['schema' => self::SCHEMA];
        $out['id'] = $this->slug('id', $data['id'] ?? null, true);
        $version = $data['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            $this->error('version', 'is required and must be a whole number from 1');
        }
        $out['version'] = is_int($version) ? $version : 0;
        foreach (['name', 'category', 'description'] as $k) {
            $out[$k] = $this->text($k, $data[$k] ?? null, true);
        }
        foreach (['licence', 'source', 'acknowledge'] as $k) {
            $out[$k] = array_key_exists($k, $data) ? $this->text($k, $data[$k], false) : null;
        }
        $out['memory'] = $this->size('memory', $data['memory'] ?? null, true);

        $runtime = $data['runtime'] ?? null;
        if (! in_array($runtime, ['native', 'container'], true)) {
            $this->error('runtime', 'is required and must be native or container');
            $runtime = '';
        }
        $this->runtime = (string) $runtime;
        $out['runtime'] = $this->runtime;

        $out['inputs'] = $this->inputs($data['inputs'] ?? null);
        $out['secrets'] = $this->secrets($data['secrets'] ?? []);
        $out['runs'] = $this->runs($data['runs'] ?? null);
        $out['proxy'] = $this->proxy($data['proxy'] ?? []);
        $out['requires'] = $this->requires($data['requires'] ?? []);
        $out['extra_ports'] = $this->extraPorts($data['extra_ports'] ?? []);
        $out['backup'] = $this->backup($data['backup'] ?? []);

        return $this->errors === [] ? $out : null;
    }

    // ---- top level -------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function inputs(mixed $v): array
    {
        if (! is_array($v) || ! array_is_list($v) || $v === []) {
            $this->error('inputs', 'is required: a list that includes a "name" input of type slug');

            return [];
        }
        $out = [];
        foreach ($v as $i => $in) {
            $p = "inputs[$i]";
            if (! is_array($in) || array_is_list($in)) {
                $this->error($p, 'must be a mapping with key, type and label');

                continue;
            }
            $this->unknown($p, $in, ['key', 'type', 'label', 'default', 'options', 'help']);
            $key = $this->key("$p.key", $in['key'] ?? null);
            $type = $in['type'] ?? null;
            if (! in_array($type, Definition::INPUT_TYPES, true)) {
                $this->error("$p.type", 'must be one of '.implode(', ', Definition::INPUT_TYPES));
                $type = null;
            }
            $norm = ['key' => $key, 'type' => $type, 'label' => $this->text("$p.label", $in['label'] ?? null, true)];
            if (array_key_exists('help', $in)) {
                $norm['help'] = $this->text("$p.help", $in['help'], false);
            }
            if ($type === 'choice') {
                $opts = $in['options'] ?? null;
                if (! is_array($opts) || ! array_is_list($opts) || $opts === [] || array_filter($opts, fn ($o) => ! is_string($o) || $o === '') !== []) {
                    $this->error("$p.options", 'a choice needs a list of option strings');
                } elseif (array_filter($opts, fn ($o) => ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $o)) !== []) {
                    $this->error("$p.options", 'each option is 1 to 64 letters, numbers, . _ or - (it can end up in a command)');
                } else {
                    $norm['options'] = array_values($opts);
                }
            } elseif (array_key_exists('options', $in)) {
                $this->error("$p.options", 'only a choice input has options');
            }
            if (array_key_exists('default', $in)) {
                if ($type === 'secret') {
                    $this->error("$p.default", 'a secret input cannot have a default');
                } elseif ($type !== null) {
                    try {
                        $norm['default'] = Definition::coerce($norm, $in['default']);
                    } catch (InvalidArgumentException $e) {
                        $this->error("$p.default", $e->getMessage());
                    }
                }
            }
            if ($key !== '') {
                if (isset($this->inputs[$key])) {
                    $this->error("$p.key", "duplicate input \"$key\"");
                }
                $this->inputs[$key] = $norm;
            }
            $out[] = $norm;
        }
        if (($this->inputs['name']['type'] ?? null) !== 'slug') {
            $this->error('inputs', 'needs an input with key "name" and type slug (it names the service)');
        }

        return $out;
    }

    /** @return list<array{key: string, generate: string}> */
    private function secrets(mixed $v): array
    {
        if (! is_array($v) || ! array_is_list($v)) {
            $this->error('secrets', 'must be a list');

            return [];
        }
        $out = [];
        foreach ($v as $i => $s) {
            $p = "secrets[$i]";
            if (! is_array($s) || array_is_list($s)) {
                $this->error($p, 'must be a mapping with key and generate');

                continue;
            }
            $this->unknown($p, $s, ['key', 'generate']);
            $key = $this->key("$p.key", $s['key'] ?? null);
            if (! in_array($s['generate'] ?? null, Definition::GENERATORS, true)) {
                $this->error("$p.generate", 'must be one of '.implode(', ', Definition::GENERATORS));
            }
            if (in_array($key, $this->secrets, true)) {
                $this->error("$p.key", "duplicate secret \"$key\"");
            }
            $this->secrets[] = $key;
            $out[] = ['key' => $key, 'generate' => (string) ($s['generate'] ?? '')];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function runs(mixed $v): array
    {
        if (! is_array($v) || array_is_list($v)) {
            $this->error('runs', 'is required and must be a mapping');

            return [];
        }
        foreach (self::REFUSED as $k => $why) {
            if (array_key_exists($k, $v)) {
                $this->error("runs.$k", $why);
            }
        }
        $v = array_diff_key($v, self::REFUSED);
        $native = $this->runtime === 'native';
        $container = $this->runtime === 'container';
        $this->unknown('runs', $v, $native ? self::RUNS_NATIVE : ($container ? self::RUNS_CONTAINER : array_unique([...self::RUNS_NATIVE, ...self::RUNS_CONTAINER])));
        $out = [];

        if (array_key_exists('user', $v)) {
            $u = $v['user'];
            if ($u === 0 || $u === '0' || $u === 'root' || (is_string($u) && str_starts_with($u, 'root:'))) {
                $this->error('runs.user', 'the service may not run as root');
            } elseif (! is_string($u) || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $u)) {
                $this->error('runs.user', 'must be a plain system user name (lower case letters, digits, - and _)');
            } elseif ($native && ! str_starts_with($u, 'cm-')) {
                // Never a name that may already belong to the host (www-data, postgres, a deploy user): the service gets its own.
                $this->error('runs.user', 'must start with cm- so it is a user of its own, never an account already on the server (for example cm-'.$u.')');
            }
            $out['user'] = is_string($u) ? $u : '';
        } elseif ($native) {
            $this->error('runs.user', 'is required: the service runs as its own user');
        }

        if ($native) {
            $out += $this->native($v);
        } elseif ($container) {
            $out += $this->container($v);
        }

        $out['env'] = $this->env($v['env'] ?? []);
        $out['ports'] = $this->ports($v['ports'] ?? null);
        $out['health'] = $this->health($v['health'] ?? null, array_keys($out['ports']));
        $out['limits'] = $this->limits($v['limits'] ?? []);

        return $out;
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function native(array $v): array
    {
        $out = [];
        $dl = $v['download'] ?? null;
        if (! is_string($dl) || ! preg_match('#^https://[^/\s@]+/\S*$#D', $dl) || str_contains($dl, '{{')) {
            $this->error('runs.download', 'is required: a literal https:// URL');
        }
        $out['download'] = is_string($dl) ? $dl : '';
        $sha = $v['sha256'] ?? null;
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            $this->error('runs.sha256', is_string($sha) || $sha === null ? 'is required: the 64 character sha256 of the download, checked before anything is run' : 'must be the 64 hex characters in quotes');
        }
        $out['sha256'] = is_string($sha) ? $sha : '';
        $unpack = $v['unpack'] ?? 'none';
        if (! in_array($unpack, ['zip', 'tar.gz', 'none'], true)) {
            $this->error('runs.unpack', 'must be zip, tar.gz or none');
        }
        $out['unpack'] = (string) $unpack;
        $bin = $v['binary'] ?? null;
        if (! is_string($bin) || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $bin) || str_contains($bin, '..')) {
            $this->error('runs.binary', 'is required: a relative file name inside the unpacked download');
        }
        $out['binary'] = is_string($bin) ? $bin : '';

        $data = $v['data'] ?? null;
        if (! is_string($data) || ! str_starts_with($data, '/srv/cloud-manager/data/') || str_contains($data, '..')) {
            $this->error('runs.data', 'is required and must be inside /srv/cloud-manager/data/ (the service data folder)');
        } else {
            $this->placeholders('runs.data', $data, onlyName: true);
        }
        $out['data'] = is_string($data) ? $data : '';

        $cmd = $v['command'] ?? null;
        if (! is_string($cmd) || trim($cmd) === '') {
            $this->error('runs.command', 'is required');
        } else {
            foreach (['&&', '||', ';', '|', '`', '$(', "\n"] as $op) {
                if (str_contains($cmd, $op)) {
                    $this->error('runs.command', 'contains the shell operator '.($op === "\n" ? 'newline' : $op).'; a command is one program and its arguments, there is no shell');
                    break;
                }
            }
            $this->placeholders('runs.command', $cmd, binary: true, command: true);
        }
        $out['command'] = is_string($cmd) ? $cmd : '';

        if (array_key_exists('setup', $v)) {
            $out['setup'] = $this->setup($v['setup']);
        }

        return $out;
    }

    /** One named, reviewed setup action (App\Lib\Services\SetupActions), run before the first start. */
    private function setup(mixed $v): string
    {
        if (! is_string($v) || ! in_array($v, SetupActions::NAMES, true)) {
            $this->error('runs.setup', 'unknown setup action'.(is_string($v) ? " \"$v\"" : '').'; the known ones are '.implode(', ', SetupActions::NAMES));

            return '';
        }
        foreach (SetupActions::inputs($v) as $key => $type) {
            if (($this->inputs[$key]['type'] ?? null) !== $type) {
                $this->error('runs.setup', "the action $v needs an input \"$key\" of type $type");
            }
        }

        return $v;
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function container(array $v): array
    {
        $out = [];
        $img = $v['image'] ?? null;
        if (! is_string($img) || ! preg_match('#^[a-z0-9][a-z0-9._/-]*(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?@sha256:[a-f0-9]{64}$#D', $img)) {
            $this->error('runs.image', is_string($img) && ! str_contains($img, '@sha256:') ? 'must be pinned by digest (name@sha256:<64 hex>), a tag can move' : 'is required: name@sha256:<64 hex>');
        }
        $out['image'] = is_string($img) ? $img : '';

        // Off unless the template says so: whether an image tolerates a read-only root filesystem is not something we can know.
        $ro = $v['read_only'] ?? false;
        if (! is_bool($ro)) {
            $this->error('runs.read_only', 'must be true or false');
        }
        $out['read_only'] = $ro === true;

        // Everything is dropped; an image whose entrypoint needs a little of root's power can name it, from a short list.
        $caps = $v['capabilities'] ?? [];
        if (! is_array($caps) || ! array_is_list($caps) || array_diff($caps, self::CAPABILITIES) !== [] || count($caps) !== count(array_unique($caps))) {
            $this->error('runs.capabilities', 'must be a list of any of '.implode(', ', self::CAPABILITIES).' (anything else, such as SYS_ADMIN or NET_ADMIN, is refused)');
            $caps = [];
        }
        $out['capabilities'] = array_values($caps);

        $vols = $v['volumes'] ?? [];
        $out['volumes'] = [];
        if (! is_array($vols) || ! array_is_list($vols)) {
            $this->error('runs.volumes', 'must be a list');
        } else {
            foreach ($vols as $i => $vol) {
                $p = "runs.volumes[$i]";
                if (! is_string($vol)) {
                    $this->error($p, 'must be a string like "{{ name }}:/app/data"');

                    continue;
                }
                $out['volumes'][] = $vol;
                $parts = explode(':', $vol);
                if (str_contains($vol, 'docker.sock')) {
                    $this->error($p, 'mounting the Docker socket is not allowed');
                } elseif (count($parts) < 2 || count($parts) > 3 || ! str_starts_with($parts[1], '/') || (count($parts) === 3 && ! in_array($parts[2], ['ro', 'rw'], true))) {
                    $this->error($p, 'must be "<volume name>:<path in the container>" with an optional :ro');
                } elseif (preg_match('#^[/.~]#', $parts[0]) || str_contains($parts[0], '/')) {
                    $this->error($p, 'mounting a host folder is not allowed, only a named volume');
                } else {
                    $this->placeholders($p, $parts[0], onlyName: true);
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function env(mixed $v): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->error('runs.env', 'must be a mapping of NAME: value');

            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            $p = "runs.env.$k";
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $k)) {
                $this->error($p, 'is not a valid variable name');
            }
            if (! is_scalar($val)) {
                $this->error($p, 'must be a text value');

                continue;
            }
            $s = is_bool($val) ? ($val ? 'true' : 'false') : (string) $val;
            $this->placeholders($p, $s, secrets: true);
            $out[(string) $k] = $s;
        }

        return $out;
    }

    /** @return array<int, string> port => "{{ inputs.key }}" */
    private function ports(mixed $v): array
    {
        if ($v === null || $v === []) {
            return []; // a service with nothing to serve (the GitHub runner) has no ports
        }
        if (! is_array($v) || array_is_list($v)) {
            $this->error('runs.ports', 'must be a mapping of port: "{{ inputs.<domain input> }}"');

            return [];
        }
        $out = [];
        foreach ($v as $port => $domain) {
            $p = "runs.ports.$port";
            if (! is_int($port) || $port < 1 || $port > 65535) {
                $this->error($p, 'the key must be a port number; a host address is not allowed, services always bind 127.0.0.1 and nginx serves the domain');

                continue;
            }
            if (! is_string($domain) || ! preg_match('/^\{\{\s*inputs\.([a-z][a-z0-9_]*)\s*\}\}$/D', $domain, $m)) {
                $this->error($p, is_string($domain) && preg_match('/[.:]\d|^[\d.]+$|^[0-9a-f:]*:[0-9a-f:]*$/i', $domain) ? 'maps to a host address; services always bind 127.0.0.1, give an input placeholder for the domain' : 'must be "{{ inputs.<domain input> }}"');

                continue;
            }
            if (($this->inputs[$m[1]]['type'] ?? null) !== 'domain') {
                $this->error($p, "inputs.{$m[1]} must be declared with type domain");
            }
            $out[$port] = $domain;
        }

        return $out;
    }

    /** @param list<int> $ports @return array<string, mixed> */
    private function health(mixed $v, array $ports): array
    {
        if ($v === null) {
            return [];
        }
        if (! is_array($v) || array_is_list($v) || $v === []) {
            $this->error('runs.health', 'must be { port: N } or { path: /health }');

            return [];
        }
        $this->unknown('runs.health', $v, ['port', 'path']);
        $out = [];
        if (array_key_exists('port', $v)) {
            if (! is_int($v['port']) || ! in_array($v['port'], $ports, true)) {
                $this->error('runs.health.port', 'must be one of the ports in runs.ports');
            }
            $out['port'] = $v['port'];
        }
        if (array_key_exists('path', $v)) {
            if (! is_string($v['path']) || ! preg_match('#^/[A-Za-z0-9._~/-]*$#D', $v['path'])) {
                $this->error('runs.health.path', 'must be a path starting with /');
            }
            $out['path'] = $v['path'];
        }
        if ($out === [] && $v !== []) {
            $this->error('runs.health', 'needs a port or a path');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function limits(mixed $v): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->error('runs.limits', 'must be a mapping');

            return [];
        }
        $this->unknown('runs.limits', $v, ['memory', 'cpu']);
        $out = [];
        if (array_key_exists('memory', $v)) {
            $out['memory'] = $this->size('runs.limits.memory', $v['memory'], true);
        }
        if (array_key_exists('cpu', $v)) {
            if (! (is_int($v['cpu']) || is_float($v['cpu'])) || $v['cpu'] <= 0 || $v['cpu'] > 64) {
                $this->error('runs.limits.cpu', 'must be a number of CPUs above 0');
            }
            $out['cpu'] = $v['cpu'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function proxy(mixed $v): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->error('proxy', 'must be a mapping');

            return [];
        }
        $this->unknown('proxy', $v, ['client_max_body_size', 'request_buffering']);
        $b = $v['client_max_body_size'] ?? null;
        if (array_key_exists('client_max_body_size', $v) && ! (is_int($b) && $b >= 0) && ! (is_string($b) && Definition::parseSize($b) !== null)) {
            $this->error('proxy.client_max_body_size', 'must be 0 (no limit) or a size such as 100MB');
        }
        $r = $v['request_buffering'] ?? null;
        if (array_key_exists('request_buffering', $v) && ! is_bool($r) && ! in_array($r, ['on', 'off'], true)) {
            $this->error('proxy.request_buffering', 'must be on or off');
        }

        return $v;
    }

    /** @return array{stop: bool} */
    private function backup(mixed $v): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->error('backup', 'must be a mapping such as { stop: true }');

            return ['stop' => false];
        }
        $this->unknown('backup', $v, ['stop']);
        if (array_key_exists('stop', $v) && ! is_bool($v['stop'])) {
            $this->error('backup.stop', 'must be true or false');
        }

        return ['stop' => ($v['stop'] ?? false) === true];
    }

    /** @return list<string> */
    private function requires(mixed $v): array
    {
        if (! is_array($v) || ! array_is_list($v)) {
            $this->error('requires', 'must be a list such as [ containers, role:manager ]');

            return [];
        }
        $out = [];
        foreach ($v as $i => $r) {
            if (! is_string($r) || ! preg_match('/^(containers|role:(manager|developer|owner))$/D', $r)) {
                $this->error("requires[$i]", 'must be containers or role:manager, role:developer or role:owner');

                continue;
            }
            $out[] = $r;
        }
        if ($this->runtime === 'container' && ! in_array('containers', $out, true)) {
            $this->error('requires', 'a container service must require containers');
        }

        return $out;
    }

    /** @return list<array{port: int, proto: string, label: string}> */
    private function extraPorts(mixed $v): array
    {
        if (! is_array($v) || ! array_is_list($v)) {
            $this->error('extra_ports', 'must be a list of { port, proto, label }');

            return [];
        }
        $out = [];
        foreach ($v as $i => $e) {
            $p = "extra_ports[$i]";
            if (! is_array($e) || array_is_list($e)) {
                $this->error($p, 'must be { port, proto, label }');

                continue;
            }
            $this->unknown($p, $e, ['port', 'proto', 'label']);
            $port = $e['port'] ?? null;
            if (! is_int($port) || $port < 1 || $port > 65535 || in_array($port, [80, 443], true)) {
                $this->error("$p.port", 'must be a port number other than 80 and 443');
            }
            if (! in_array($e['proto'] ?? null, ['tcp', 'udp'], true)) {
                $this->error("$p.proto", 'must be tcp or udp');
            }
            $out[] = ['port' => is_int($port) ? $port : 0, 'proto' => (string) ($e['proto'] ?? ''), 'label' => $this->text("$p.label", $e['label'] ?? null, true)];
        }

        return $out;
    }

    // ---- pieces ----------------------------------------------------------

    /**
     * Checks every {{ placeholder }} in a string. Only name, data, binary,
     * inputs.<declared> and secrets.<declared> exist. Secrets and secret inputs
     * only go in env, text inputs never in a command.
     */
    private function placeholders(string $path, string $value, bool $onlyName = false, bool $binary = false, bool $secrets = false, bool $command = false): void
    {
        preg_match_all(self::PLACEHOLDER, $value, $m);
        foreach ($m[1] as $ref) {
            if ($ref === 'name') {
                continue;
            }
            if ($onlyName) {
                $this->error($path, "placeholder {{ $ref }} is not allowed here, only {{ name }}");
            } elseif ($ref === 'data') {
                if ($this->runtime !== 'native') {
                    $this->error($path, '{{ data }} exists only for native services');
                }
            } elseif ($ref === 'binary') {
                if (! $binary) {
                    $this->error($path, '{{ binary }} can only be used in the command');
                }
            } elseif (preg_match('/^secrets\.([a-z][a-z0-9_]*)$/D', $ref, $k)) {
                if (! in_array($k[1], $this->secrets, true)) {
                    $this->error($path, "unknown placeholder {{ $ref }}: no such secret is declared");
                } elseif (! $secrets) {
                    $this->error($path, "{{ $ref }} can only be used in env, never in a command or a path");
                }
            } elseif (preg_match('/^inputs\.([a-z][a-z0-9_]*)$/D', $ref, $k)) {
                $type = $this->inputs[$k[1]]['type'] ?? null;
                if (! isset($this->inputs[$k[1]])) {
                    $this->error($path, "unknown placeholder {{ $ref }}: no such input is declared");
                } elseif ($type === 'secret' && ! $secrets) {
                    $this->error($path, "{{ $ref }} is a secret input and can only be used in env");
                } elseif ($command && $type === 'text') {
                    $this->error($path, "{{ $ref }} is free text and cannot be used in a command, use env");
                }
            } else {
                $this->error($path, "unknown placeholder {{ $ref }}");
            }
        }
        if (str_contains(preg_replace(self::PLACEHOLDER, '', $value) ?? '', '{{') || str_contains(preg_replace(self::PLACEHOLDER, '', $value) ?? '', '}}')) {
            $this->error($path, 'has an unclosed {{ or }}');
        }
    }

    private function slug(string $path, mixed $v, bool $required): string
    {
        if (! is_string($v) || ! preg_match(Definition::SLUG, $v)) {
            $this->error($path, ($required ? 'is required and ' : '').'must be a slug: lower case letters, digits and -, up to 40 characters');

            return '';
        }

        return $v;
    }

    private function key(string $path, mixed $v): string
    {
        if (! is_string($v) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $v)) {
            $this->error($path, 'is required: lower case letters, digits and _, starting with a letter');

            return '';
        }

        return $v;
    }

    private function text(string $path, mixed $v, bool $required): string
    {
        if (! is_string($v) || trim($v) === '' || strlen($v) > 500 || preg_match('/[\x00-\x08\x0B-\x1F]/', $v)) {
            $this->error($path, $required ? 'is required: a short piece of text' : 'must be a short piece of text');

            return '';
        }

        return $v;
    }

    private function size(string $path, mixed $v, bool $required): string
    {
        if (! is_string($v) || Definition::parseSize($v) === null) {
            $this->error($path, ($required ? 'is required and ' : '').'must be a size such as 512MB or 2GB');

            return '';
        }

        return $v;
    }

    /** @param array<mixed> $arr @param list<string> $allowed */
    private function unknown(string $path, array $arr, array $allowed): void
    {
        foreach (array_keys($arr) as $k) {
            if (! in_array((string) $k, $allowed, true)) {
                $this->error($path === '' ? (string) $k : "$path.$k", 'unknown field');
            }
        }
    }

    private function error(string $path, string $message, ?int $line = null, bool $find = true): void
    {
        $this->errors[] = ['path' => $path, 'line' => $line ?? ($find ? $this->lineOf($path) : null), 'message' => $message];
    }

    /** Best-effort line: find each key of the path in turn, then the n-th list item for an index. */
    private function lineOf(string $path): ?int
    {
        if ($path === '') {
            return null;
        }
        $at = 0;
        $found = null;
        foreach (explode('.', $path) as $seg) {
            preg_match('/^([^\[]*)((?:\[\d+\])*)$/D', $seg, $m);
            if (($m[1] ?? '') !== '') {
                $re = '/^\s*(?:-\s*)?(?:\{\s*)?["\']?'.preg_quote($m[1], '/').'["\']?\s*:/';
                $hit = $this->search($re, $at);
                if ($hit === null) {
                    return $found === null ? null : $found + 1;
                }
                $at = $found = $hit;
            }
            preg_match_all('/\[(\d+)\]/', $m[2] ?? '', $idx);
            foreach ($idx[1] as $n) {
                $items = [];
                $indent = null;
                for ($i = $at + 1; $i < count($this->lines); $i++) {
                    if (preg_match('/^(\s*)-\s/', $this->lines[$i], $mm)) {
                        $indent ??= strlen($mm[1]);
                        if (strlen($mm[1]) === $indent) {
                            $items[] = $i;
                        }
                    } elseif ($indent !== null && trim($this->lines[$i]) !== '' && preg_match('/^(\s*)\S/', $this->lines[$i], $mm) && strlen($mm[1]) < $indent) {
                        break;
                    }
                }
                if (! isset($items[(int) $n])) {
                    return $found === null ? null : $found + 1;
                }
                $at = $found = $items[(int) $n];
            }
        }

        return $found === null ? null : $found + 1;
    }

    private function search(string $re, int $from): ?int
    {
        for ($i = $from; $i < count($this->lines); $i++) {
            if (preg_match($re, $this->lines[$i])) {
                return $i;
            }
        }

        return null;
    }
}

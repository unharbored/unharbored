<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\Definition;
use App\Lib\Templates\InvalidDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Schema 1: what is accepted, and every refusal in docs/specs/templates.md section 2. */
final class DefinitionTest extends TestCase
{
    use Fixtures;

    /** @return list<array{path: string, line: ?int, message: string}> */
    private function errorsOf(array $data): array
    {
        try {
            Definition::parse($this->yaml($data));
        } catch (InvalidDefinition $e) {
            return $e->errors;
        }
        $this->fail('expected the definition to be refused');
    }

    private function assertRefused(array $data, string $path, string $fragment): void
    {
        foreach ($this->errorsOf($data) as $e) {
            if ($e['path'] === $path && str_contains($e['message'], $fragment)) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail("no error at $path containing \"$fragment\"; got: ".json_encode($this->errorsOf($data)));
    }

    public function test_valid_native_and_container_definitions_parse(): void
    {
        $n = Definition::parse($this->yaml($this->nativeArray()));
        $this->assertSame('demo', $n->id);
        $this->assertSame(2, $n->version);
        $this->assertSame(1024 ** 3, $n->memoryBytes());
        $this->assertSame('example.com', $n->downloadHost());
        $this->assertNull($n->imageRepository());

        $c = Definition::parse($this->yaml($this->containerArray()));
        $this->assertSame('container', $c->runtime);
        $this->assertSame('example/demo', $c->imageRepository());
        $this->assertNull($c->downloadHost());
    }

    public function test_it_is_immutable(): void
    {
        $d = Definition::parse($this->yaml($this->nativeArray()));
        $this->expectException(\Error::class);
        $d->id = 'other'; // @phpstan-ignore-line
    }

    public function test_unknown_field_at_any_level_is_an_error(): void
    {
        $d = $this->nativeArray();
        $d['surprise'] = 1;
        $d['runs']['extra'] = 1;
        $d['inputs'][0]['colour'] = 'red';
        $d['secrets'][0]['length'] = 9;
        $d['proxy']['gzip'] = 'on';
        $d['runs']['health']['interval'] = 5;
        $d['runs']['limits']['disk'] = '1GB';
        $d['extra_ports'][0]['note'] = 'x';
        foreach (['surprise', 'runs.extra', 'inputs[0].colour', 'secrets[0].length', 'proxy.gzip', 'runs.health.interval', 'runs.limits.disk', 'extra_ports[0].note'] as $path) {
            $this->assertRefused($d, $path, 'unknown field');
        }
    }

    public function test_all_errors_are_collected_with_paths_and_lines(): void
    {
        $d = $this->nativeArray();
        unset($d['runs']['sha256']);
        $d['runs']['user'] = 'root';
        $d['category'] = '';
        $errors = $this->errorsOf($d);
        $paths = array_column($errors, 'path');
        $this->assertContains('runs.sha256', $paths);
        $this->assertContains('runs.user', $paths);
        $this->assertContains('category', $paths);

        $user = array_values(array_filter($errors, fn ($e) => $e['path'] === 'runs.user'))[0];
        $lines = preg_split('/\R/', $this->yaml($d));
        $this->assertStringContainsString('user:', $lines[$user['line'] - 1]);
    }

    public function test_yaml_syntax_error_reports_its_line(): void
    {
        try {
            Definition::parse("schema: 1\nid: [unclosed\n");
            $this->fail('expected a refusal');
        } catch (InvalidDefinition $e) {
            $this->assertCount(1, $e->errors);
            $this->assertNotNull($e->errors[0]['line']);
            $this->assertStringContainsString('not valid YAML', $e->errors[0]['message']);
        }
    }

    public function test_schema_version_other_than_one_is_a_clear_error(): void
    {
        $d = $this->nativeArray();
        $d['schema'] = 2;
        $errors = $this->errorsOf($d);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('this install does not know schema 2', $errors[0]['message']);

        unset($d['schema']);
        $this->assertRefused($d, 'schema', 'is required');
        $d['schema'] = 'one';
        $this->assertRefused($d, 'schema', 'must be the number 1');
    }

    public function test_download_without_sha256_is_refused(): void
    {
        $d = $this->nativeArray();
        unset($d['runs']['sha256']);
        $this->assertRefused($d, 'runs.sha256', 'is required');
        $d['runs']['sha256'] = 'abc123';
        $this->assertRefused($d, 'runs.sha256', 'is required');
        $d['runs']['sha256'] = self::SHA;
        $d['runs']['download'] = 'http://example.com/demo.zip';
        $this->assertRefused($d, 'runs.download', 'https');
    }

    public function test_image_without_a_digest_is_refused(): void
    {
        $d = $this->containerArray();
        $d['runs']['image'] = 'example/demo:1.0';
        $this->assertRefused($d, 'runs.image', 'pinned by digest');
        $d['runs']['image'] = 'example/demo@sha256:abc';
        $this->assertRefused($d, 'runs.image', 'name@sha256');
    }

    #[DataProvider('escapes')]
    public function test_host_escapes_are_refused(string $key, mixed $value): void
    {
        $d = $this->containerArray();
        $d['runs'][$key] = $value;
        $this->assertRefused($d, "runs.$key", 'not allowed');
    }

    public static function escapes(): array
    {
        return [
            'privileged' => ['privileged', true],
            'network host' => ['network', 'host'],
            'pid host' => ['pid', 'host'],
            'added capabilities' => ['cap_add', ['SYS_ADMIN']],
            'devices' => ['devices', ['/dev/mem']],
        ];
    }

    public function test_a_container_may_name_a_short_list_of_capabilities_and_nothing_else(): void
    {
        $ok = $this->containerArray();
        $ok['runs']['capabilities'] = ['DAC_OVERRIDE'];
        $this->assertSame(['DAC_OVERRIDE'], Definition::parse($this->yaml($ok))->runs['capabilities']);

        foreach ([['SYS_ADMIN'], ['NET_ADMIN'], ['ALL'], ['DAC_OVERRIDE', 'DAC_OVERRIDE'], 'DAC_OVERRIDE'] as $bad) {
            $d = $this->containerArray();
            $d['runs']['capabilities'] = $bad;
            $this->assertNotSame([], $this->errorsOf($d), json_encode($bad));
        }
    }

    public function test_docker_socket_and_host_folder_mounts_are_refused(): void
    {
        $d = $this->containerArray();
        $d['runs']['volumes'] = ['/var/run/docker.sock:/var/run/docker.sock'];
        $this->assertRefused($d, 'runs.volumes[0]', 'Docker socket');

        foreach (['/etc:/host-etc', './data:/data', '~/x:/data', 'a/b:/data'] as $vol) {
            $d['runs']['volumes'] = [$vol];
            $this->assertRefused($d, 'runs.volumes[0]', 'host folder');
        }
        $d['runs']['volumes'] = ['{{ inputs.host }}:/data'];
        $this->assertRefused($d, 'runs.volumes[0]', 'only {{ name }}');
        $d['runs']['volumes'] = ['data:/x', '{{ name }}:/y:ro'];
        $this->assertNotNull(Definition::parse($this->yaml($d)));
    }

    public function test_native_data_must_be_the_service_data_folder(): void
    {
        $d = $this->nativeArray();
        $d['runs']['data'] = '/etc';
        $this->assertRefused($d, 'runs.data', '/srv/cloud-manager/data/');
        $d['runs']['data'] = '/srv/cloud-manager/data/../../etc';
        $this->assertRefused($d, 'runs.data', '/srv/cloud-manager/data/');
    }

    public function test_ports_to_host_bound_addresses_are_refused(): void
    {
        $d = $this->nativeArray();
        $d['runs']['ports'] = ['0.0.0.0:9000' => '{{ inputs.host }}'];
        $this->assertRefused($d, 'runs.ports.0.0.0.0:9000', 'always bind 127.0.0.1');

        $d['runs']['ports'] = [9000 => '0.0.0.0:8080'];
        $this->assertRefused($d, 'runs.ports.9000', 'always bind 127.0.0.1');

        $d['runs']['ports'] = [9000 => 'files.example.com'];
        $this->assertRefused($d, 'runs.ports.9000', 'must be "{{ inputs.');

        $d['runs']['ports'] = [9000 => '{{ inputs.storage }}'];
        $this->assertRefused($d, 'runs.ports.9000', 'type domain');
    }

    public function test_user_root_is_refused(): void
    {
        foreach (['root', '0', 0, 'root:root'] as $u) {
            $d = $this->nativeArray();
            $d['runs']['user'] = $u;
            $this->assertRefused($d, 'runs.user', 'root');
        }
        $c = $this->containerArray();
        $c['runs']['user'] = 'root';
        $this->assertRefused($c, 'runs.user', 'root');
    }

    public function test_unknown_placeholders_are_refused(): void
    {
        foreach (['{{ inputs.nope }}', '{{ secrets.nope }}', '{{ whatever }}', '{{ inputs.host | upper }}', '{{ 1 + 1 }}'] as $p) {
            $d = $this->nativeArray();
            $d['runs']['env']['X'] = $p;
            $this->assertRefused($d, 'runs.env.X', 'unknown placeholder');
        }
        $d = $this->nativeArray();
        $d['runs']['env']['X'] = 'a {{ name';
        $this->assertRefused($d, 'runs.env.X', 'unclosed');
        $d = $this->nativeArray();
        $d['runs']['env']['X'] = '{{ binary }}';
        $this->assertRefused($d, 'runs.env.X', 'only be used in the command');
    }

    public function test_secrets_and_free_text_stay_out_of_commands(): void
    {
        $d = $this->nativeArray();
        $d['runs']['command'] = '{{ binary }} --key {{ secrets.access_key }}';
        $this->assertRefused($d, 'runs.command', 'only be used in env');
        $d['runs']['command'] = '{{ binary }} --key {{ inputs.token }}';
        $this->assertRefused($d, 'runs.command', 'secret input');
        $d['runs']['command'] = '{{ binary }} --note {{ inputs.note }}';
        $this->assertRefused($d, 'runs.command', 'free text');
    }

    #[DataProvider('operators')]
    public function test_shell_operators_in_a_command_are_refused(string $op): void
    {
        $d = $this->nativeArray();
        $d['runs']['command'] = "{{ binary }} start $op rm";
        $this->assertRefused($d, 'runs.command', 'shell operator');
    }

    public static function operators(): array
    {
        return [[';'], ['&&'], ['||'], ['|'], ['`id`'], ['$(id)']];
    }

    public function test_extra_ports_shape(): void
    {
        $d = $this->nativeArray();
        $d['extra_ports'] = [['port' => 443, 'proto' => 'tcp', 'label' => 'x'], ['port' => 2222, 'proto' => 'icmp', 'label' => 'x'], ['port' => 2222, 'proto' => 'tcp']];
        $this->assertRefused($d, 'extra_ports[0].port', 'other than 80 and 443');
        $this->assertRefused($d, 'extra_ports[1].proto', 'tcp or udp');
        $this->assertRefused($d, 'extra_ports[2].label', 'is required');
        $d['extra_ports'] = ['2222'];
        $this->assertRefused($d, 'extra_ports[0]', '{ port, proto, label }');
    }

    public function test_input_types_and_their_defaults(): void
    {
        $d = $this->nativeArray();
        $d['inputs'][] = ['key' => 'odd', 'type' => 'color', 'label' => 'x'];
        $this->assertRefused($d, 'inputs[7].type', 'must be one of slug, domain, size, choice, text, number, secret');

        $d = $this->nativeArray();
        $d['inputs'][2]['default'] = 'lots';
        $this->assertRefused($d, 'inputs[2].default', 'size');
        $d = $this->nativeArray();
        $d['inputs'][3]['default'] = 'z';
        $this->assertRefused($d, 'inputs[3].default', 'must be one of a, b');
        $d = $this->nativeArray();
        unset($d['inputs'][3]['options']);
        $this->assertRefused($d, 'inputs[3].options', 'list of option strings');
        $d = $this->nativeArray();
        $d['inputs'][6]['default'] = 'x';
        $this->assertRefused($d, 'inputs[6].default', 'secret input cannot have a default');
        $d = $this->nativeArray();
        $d['inputs'][1]['key'] = 'name';
        $this->assertRefused($d, 'inputs[1].key', 'duplicate');
        $d = $this->nativeArray();
        array_shift($d['inputs']);
        $this->assertRefused($d, 'inputs', 'key "name"');
    }

    public function test_secrets_generate_word_or_password(): void
    {
        $d = $this->nativeArray();
        $d['secrets'][0]['generate'] = 'uuid';
        $this->assertRefused($d, 'secrets[0].generate', 'word, password');
    }

    public function test_limits_and_memory_are_sizes(): void
    {
        $d = $this->nativeArray();
        $d['memory'] = 'a lot';
        $d['runs']['limits'] = ['memory' => '1 gigabyte', 'cpu' => 0];
        $this->assertRefused($d, 'memory', 'size');
        $this->assertRefused($d, 'runs.limits.memory', 'size');
        $this->assertRefused($d, 'runs.limits.cpu', 'above 0');
    }

    public function test_requires_entries(): void
    {
        $d = $this->nativeArray();
        $d['requires'] = ['role:admin', 'docker', 'role:developer', 'role:owner'];
        $errors = array_column($this->errorsOf($d), 'path');
        $this->assertContains('requires[0]', $errors);
        $this->assertContains('requires[1]', $errors);
        $this->assertNotContains('requires[2]', $errors);
        $this->assertNotContains('requires[3]', $errors);

        $c = $this->containerArray();
        $c['requires'] = ['role:manager'];
        $this->assertRefused($c, 'requires', 'must require containers');
    }

    public function test_health_must_point_at_a_declared_port(): void
    {
        $d = $this->nativeArray();
        $d['runs']['health'] = ['port' => 1234];
        $this->assertRefused($d, 'runs.health.port', 'runs.ports');
    }

    public function test_a_service_with_nothing_to_serve_needs_no_ports(): void
    {
        $d = $this->nativeArray();
        $d['runs']['ports'] = [];
        unset($d['runs']['health']);
        $this->assertSame([], Definition::parse($this->yaml($d))->runs['ports']);
    }

    // ---- placeholders and values ----------------------------------------

    private function values(): array
    {
        return ['name' => 'store', 'host' => 'Files.Example.com', 'token' => 'ghp_x'];
    }

    public function test_render_fills_placeholders_and_applies_defaults(): void
    {
        $d = Definition::parse($this->yaml($this->nativeArray()));
        $runs = $d->render($this->values(), ['access_key' => 'AK', 'secret_key' => 'SK'], '/srv/cloud-manager/services/store/demo');
        $this->assertSame('/srv/cloud-manager/data/store', $runs['data']);
        $this->assertSame('/srv/cloud-manager/services/store/demo --listen 127.0.0.1:9000 --mode a /srv/cloud-manager/data/store', $runs['command']);
        $this->assertSame(['KEY' => 'AK', 'SECRET' => 'SK', 'TOKEN' => 'ghp_x', 'NOTE' => 'hello'], $runs['env']);
        $this->assertSame([9000 => 'files.example.com'], $runs['ports']);

        $c = Definition::parse($this->yaml($this->containerArray()));
        $this->assertSame(['store:/app/data'], $c->render($this->values(), ['access_key' => 'a', 'secret_key' => 'b'])['volumes']);
    }

    public function test_needs_lists_referenced_inputs_and_secrets(): void
    {
        $needs = Definition::parse($this->yaml($this->nativeArray()))->needs();
        $this->assertEqualsCanonicalizing(['mode', 'token', 'note', 'host'], $needs['inputs']);
        $this->assertEqualsCanonicalizing(['access_key', 'secret_key'], $needs['secrets']);
    }

    #[DataProvider('badValues')]
    public function test_render_rejects_a_value_that_does_not_fit_its_type(string $key, mixed $value): void
    {
        $d = Definition::parse($this->yaml($this->nativeArray()));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);
        $d->render([...$this->values(), $key => $value], ['access_key' => 'a', 'secret_key' => 'b']);
    }

    public static function badValues(): array
    {
        return [
            'slug upper' => ['name', 'My Store'],
            'slug too long' => ['name', str_repeat('a', 41)],
            'domain no dot' => ['host', 'localhost'],
            'domain ip' => ['host', '10.0.0.1'],
            'domain underscore' => ['host', 'a_b.example.com'],
            'domain path' => ['host', 'example.com/x'],
            'domain hyphen edge' => ['host', '-a.example.com'],
            'size words' => ['storage', 'big'],
            'size zero' => ['storage', '0GB'],
            'choice outside' => ['mode', 'c'],
            'number text' => ['count', 'many'],
            'text newline' => ['note', "a\nb"],
            'secret empty-ish control' => ['token', "a\x00b"],
        ];
    }

    public function test_render_needs_every_required_value_and_every_secret_and_refuses_unknown_inputs(): void
    {
        $d = Definition::parse($this->yaml($this->nativeArray()));
        $errors = $d->checkInputs(['name' => 'x', 'bogus' => 1]);
        $this->assertArrayHasKey('host', $errors);
        $this->assertArrayHasKey('token', $errors);
        $this->assertArrayHasKey('bogus', $errors);
        $this->assertArrayNotHasKey('storage', $errors);

        $this->expectExceptionMessage('secrets.access_key');
        $d->render($this->values(), ['secret_key' => 'b']);
    }

    public function test_size_parsing_and_hostnames(): void
    {
        $this->assertSame(50 * 1024 ** 3, Definition::parseSize('50GB'));
        $this->assertSame(512 * 1024 ** 2, Definition::parseSize('512 mb'));
        $this->assertSame((int) (1.5 * 1024 ** 3), Definition::parseSize('1.5GB'));
        $this->assertNull(Definition::parseSize('50'));
        $this->assertNull(Definition::parseSize('GB'));
        $this->assertTrue(Definition::isHostname('a.b-c.example.com'));
        $this->assertFalse(Definition::isHostname('example'));
        $this->assertFalse(Definition::isHostname('a..com'));
        $this->assertFalse(Definition::isHostname('example.123'));
    }
}

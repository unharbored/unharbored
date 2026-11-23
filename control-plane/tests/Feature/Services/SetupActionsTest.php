<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\ServiceRecipes;
use App\Lib\Services\SetupActions;
use App\Lib\Templates\Definition;
use App\Lib\Templates\InvalidDefinition;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Templates\Fixtures;
use Tests\TestCase;

/** The closed registry of setup actions (runs.setup): the two the first templates need, and the refusal of any other. */
final class SetupActionsTest extends TestCase
{
    use Fixtures;

    private function context(string $template, array $inputs): array
    {
        $d = Definition::fromFile(base_path("templates/{$template}.yml"));
        $secrets = array_column(array_map(fn ($s) => ['k' => $s['key'], 'v' => 'x'], $d->secrets), 'v', 'k');

        return ServiceRecipes::context($d, $inputs['name'], $inputs, $secrets);
    }

    public function test_runner_register_is_one_fixed_command_that_takes_the_token_from_the_environment_file(): void
    {
        $c = $this->context('github-runner', ['name' => 'ci', 'url' => 'https://github.com/acme/app', 'token' => 'SECRET-TOKEN']);
        $steps = SetupActions::steps('runner-register', $c);

        $this->assertSame(['setup-runner-dependencies', 'setup-runner-register'], array_column($steps, 'id'));
        $this->assertSame(['/srv/cloud-manager/services/ci/releases/1/bin/installdependencies.sh', []], [$steps[0]['name'], $steps[0]['args']]);
        $s = $steps[1];
        $this->assertSame(['command', 'systemd-run'], [$s['type'], $s['name']]);
        $this->assertSame(['test', '-f', '/srv/cloud-manager/services/ci/releases/1/.runner'], $s['unless'], 'registered once');
        $args = $s['args'];
        $at = array_search('--', $args, true);
        $this->assertSame(['/srv/cloud-manager/services/ci/releases/1/config.sh', '--unattended', '--ephemeral', '--replace', '--name', 'ci', '--url', 'https://github.com/acme/app', '--work', '_work'], array_slice($args, $at + 1));
        $this->assertContains('User=cm-gh-runner', $args);
        $this->assertContains('WorkingDirectory=/srv/cloud-manager/services/ci/releases/1', $args);
        $this->assertContains('EnvironmentFile=/etc/cloud-manager/services/ci.env', $args);
        $this->assertContains('ReadWritePaths=/srv/cloud-manager/data/ci /srv/cloud-manager/services/ci/releases/1', $args, 'the runner keeps its registration in its release folder');
        $this->assertStringNotContainsString('SECRET-TOKEN', json_encode($steps), 'the token is never in the step');

        $run = ServiceRecipes::run($c)['steps'];
        $this->assertSame(['setup-runner-dependencies', 'setup-runner-register', 'start'], array_column($run, 'id'), 'registration comes before the first start');
        $prepare = array_column(ServiceRecipes::prepare($c)['steps'], null, 'id');
        $this->assertSame(['-R', 'cm-gh-runner:', '/srv/cloud-manager/services/ci/releases/1'], $prepare['release-owner']['args']);
        $this->assertStringContainsString('ReadWritePaths=/srv/cloud-manager/data/ci /srv/cloud-manager/services/ci/releases/1', ServiceRecipes::unitFile($c));
        $this->assertStringContainsString("WorkingDirectory=/srv/cloud-manager/services/ci/releases/1\n", ServiceRecipes::unitFile($c));
        $this->assertStringContainsString('ACTIONS_RUNNER_INPUT_TOKEN="SECRET-TOKEN"', base64_decode($prepare['env']['content_b64']));
    }

    public function test_runner_register_refuses_a_url_that_is_not_plain_https(): void
    {
        foreach (['http://github.com/acme/app', 'https://github.com/acme/app; rm -rf /', 'git@github.com:acme/app.git', 'https://user:pw@github.com/acme/app'] as $url) {
            try {
                SetupActions::steps('runner-register', $this->context('github-runner', ['name' => 'ci', 'url' => $url, 'token' => 'x']));
                $this->fail("accepted {$url}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('https://', $e->getMessage());
            }
        }
    }

    public function test_environment_to_ini_writes_app_ini_before_the_first_start_and_only_when_it_is_missing(): void
    {
        $c = $this->context('gitea', ['name' => 'git', 'host' => 'git.example.com']);
        $run = ServiceRecipes::run($c)['steps'];
        $this->assertSame(['setup-ini-dir-custom', 'setup-ini-dir-conf', 'setup-ini-file', 'setup-environment-to-ini', 'start'], array_column($run, 'id'));

        $dirs = array_column($run, null, 'id');
        $this->assertSame(['dir', '/srv/cloud-manager/data/git/custom/conf', 'cm-gitea', '/srv/cloud-manager/data'], [$dirs['setup-ini-dir-conf']['type'], $dirs['setup-ini-dir-conf']['path'], $dirs['setup-ini-dir-conf']['owner'], $dirs['setup-ini-dir-conf']['base']]);
        $cmd = $dirs['setup-environment-to-ini'];
        $at = array_search('--', $cmd['args'], true);
        $ini = '/srv/cloud-manager/data/git/custom/conf/app.ini';
        $this->assertSame(['/srv/cloud-manager/services/git/releases/1/gitea-28.4.0-linux-amd64', '--work-path', '/srv/cloud-manager/data/git', 'config', 'edit-ini', '--config', $ini, '--apply-env', '--out', $ini], array_slice($cmd['args'], $at + 1));
        $this->assertSame(['test', '-s', $ini], $cmd['unless']);
        $this->assertSame(['install', ['-o', 'cm-gitea', '-m', '0640', '/dev/null', $ini], ['test', '-e', $ini]], [$dirs['setup-ini-file']['name'], $dirs['setup-ini-file']['args'], $dirs['setup-ini-file']['unless']]);
        $this->assertContains('User=cm-gitea', $cmd['args']);
        $this->assertContains('ReadWritePaths=/srv/cloud-manager/data/git', $cmd['args']);
        $this->assertStringNotContainsString('ReadWritePaths=/srv/cloud-manager/data/git /srv', ServiceRecipes::unitFile($c), 'Gitea does not write to its release');
    }

    public function test_a_definition_cannot_smuggle_a_newline_into_the_unit_file(): void
    {
        // a newline in a choice option or the binary would start a new [Service] directive (ExecStartPre=+... runs as root)
        $a = $this->nativeArray();
        $a['inputs'][3]['options'] = ["snapshot\nExecStartPre=+/bin/sh /tmp/x", 'b'];
        $a['inputs'][3]['default'] = "snapshot\nExecStartPre=+/bin/sh /tmp/x";
        $this->assertStringContainsString('each option is 1 to 64 letters', $this->firstError($a)['message']);

        $a = $this->nativeArray();
        $a['runs']['binary'] = "demo\n";
        $this->assertSame('runs.binary', $this->firstError($a)['path']);
    }

    public function test_a_native_service_needs_a_user_of_its_own_with_the_cm_prefix(): void
    {
        foreach (['www-data', 'postgres', 'ubuntu'] as $user) {
            $a = $this->nativeArray();
            $a['runs']['user'] = $user;
            $this->assertSame('runs.user', $this->firstError($a)['path']);
            $this->assertStringContainsString('must start with cm-', $this->firstError($a)['message']);
        }
    }

    public function test_a_service_with_no_setup_action_just_starts(): void
    {
        $c = $this->context('rustfs', ['name' => 'files', 's3_host' => 'a.example.com', 'ui_host' => 'b.example.com', 'storage' => '5GB', 'backup' => 'none']);
        $this->assertSame(['start'], array_column(ServiceRecipes::run($c)['steps'], 'id'));
    }

    public function test_an_unknown_setup_action_is_a_validation_error(): void
    {
        $a = $this->nativeArray();
        $a['runs']['setup'] = 'rm-rf';
        $this->assertSame('runs.setup', $this->firstError($a)['path']);
        $this->assertStringContainsString('unknown setup action "rm-rf"; the known ones are runner-register, environment-to-ini', $this->firstError($a)['message']);

        $a['runs']['setup'] = ['run' => 'sh'];
        $this->assertStringContainsString('unknown setup action', $this->firstError($a)['message']);
    }

    public function test_a_setup_action_needs_the_inputs_it_reads(): void
    {
        $a = $this->nativeArray();
        $a['runs']['setup'] = 'runner-register';
        $a['inputs'] = array_values(array_filter($a['inputs'], fn ($i) => $i['key'] !== 'token'));
        $a['runs']['env']['TOKEN'] = 'x';
        $this->assertStringContainsString('the action runner-register needs an input "url" of type text', $this->firstError($a)['message']);
    }

    public function test_a_known_action_with_its_inputs_is_accepted_and_kept(): void
    {
        $a = $this->nativeArray();
        $a['runs']['setup'] = 'environment-to-ini';
        $this->assertSame('environment-to-ini', Definition::parse(Yaml::dump($a, 6))->runs['setup']);
    }

    /** @return array{path: string, line: ?int, message: string} */
    private function firstError(array $definition): array
    {
        try {
            Definition::parse(Yaml::dump($definition, 6));
        } catch (InvalidDefinition $e) {
            return $e->errors[0];
        }
        $this->fail('expected an error');
    }
}

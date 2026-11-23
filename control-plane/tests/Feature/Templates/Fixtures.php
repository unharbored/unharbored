<?php

namespace Tests\Feature\Templates;

use Symfony\Component\Yaml\Yaml;

/** Valid templates as arrays, mutated by a test and dumped to YAML. */
trait Fixtures
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array<string, mixed> */
    private function nativeArray(): array
    {
        return [
            'schema' => 1, 'id' => 'demo', 'version' => 2, 'name' => 'Demo', 'category' => 'Storage',
            'description' => 'A demo.', 'memory' => '1GB', 'runtime' => 'native',
            'inputs' => [
                ['key' => 'name', 'type' => 'slug', 'label' => 'Name'],
                ['key' => 'host', 'type' => 'domain', 'label' => 'Domain'],
                ['key' => 'storage', 'type' => 'size', 'label' => 'Storage', 'default' => '50GB'],
                ['key' => 'mode', 'type' => 'choice', 'label' => 'Mode', 'options' => ['a', 'b'], 'default' => 'a'],
                ['key' => 'note', 'type' => 'text', 'label' => 'Note', 'default' => 'hello'],
                ['key' => 'count', 'type' => 'number', 'label' => 'Count', 'default' => 3],
                ['key' => 'token', 'type' => 'secret', 'label' => 'Token'],
            ],
            'secrets' => [['key' => 'access_key', 'generate' => 'word'], ['key' => 'secret_key', 'generate' => 'password']],
            'runs' => [
                'download' => 'https://example.com/demo.zip', 'sha256' => self::SHA, 'unpack' => 'zip',
                'binary' => 'demo', 'user' => 'cm-demo', 'data' => '/srv/cloud-manager/data/{{ name }}',
                'command' => '{{ binary }} --listen 127.0.0.1:9000 --mode {{ inputs.mode }} {{ data }}',
                'env' => ['KEY' => '{{ secrets.access_key }}', 'SECRET' => '{{ secrets.secret_key }}', 'TOKEN' => '{{ inputs.token }}', 'NOTE' => '{{ inputs.note }}'],
                'ports' => [9000 => '{{ inputs.host }}'],
                'health' => ['port' => 9000],
                'limits' => ['memory' => '1GB'],
            ],
            'proxy' => ['client_max_body_size' => 0, 'request_buffering' => false],
            'requires' => ['role:manager'],
            'extra_ports' => [['port' => 2222, 'proto' => 'tcp', 'label' => 'Git over SSH']],
        ];
    }

    /** @return array<string, mixed> */
    private function containerArray(): array
    {
        $d = $this->nativeArray();
        $d['runtime'] = 'container';
        $d['runs'] = [
            'image' => 'example/demo:1.0@sha256:'.self::SHA,
            'volumes' => ['{{ name }}:/app/data'],
            'ports' => [3001 => '{{ inputs.host }}'],
            'env' => [], 'health' => ['port' => 3001], 'limits' => ['memory' => '256MB', 'cpu' => 1],
        ];
        $d['requires'] = ['containers', 'role:manager'];

        return $d;
    }

    private function yaml(array $data): string
    {
        return Yaml::dump($data, 8, 2);
    }
}

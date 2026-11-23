<?php

declare(strict_types=1);

namespace App\Lib\Providers\Custom;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

final class PhpseclibShell implements RemoteShell
{
    private ?SSH2 $ssh = null;

    public function connect(string $host, int $port): string
    {
        $this->ssh = new SSH2($host, $port, 15);
        $key = $this->ssh->getServerPublicHostKey();
        if (! is_string($key) || ! str_contains($key, ' ')) {
            throw new RuntimeException("Could not reach {$host}:{$port} over SSH.");
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode(explode(' ', $key)[1], true), true)), '=');
    }

    public function authenticate(string $user, ?string $privateKey, ?string $passphrase, ?string $password): void
    {
        $ssh = $this->ssh ?? throw new RuntimeException('Not connected.');
        try {
            $credential = $privateKey !== null ? PublicKeyLoader::load($privateKey, $passphrase ?: false) : $password;
            $ok = $ssh->login($user, $credential);
        } catch (\Throwable $e) {
            throw new RuntimeException('The login could not be used: '.$e->getMessage(), 0, $e);
        }
        if (! $ok) {
            throw new RuntimeException("{$user} was refused. Check the key or password, and that SSH allows {$user} to log in.");
        }
    }

    public function run(string $command, int $timeoutSeconds = 120): array
    {
        $ssh = $this->ssh ?? throw new RuntimeException('Not connected.');
        $ssh->setTimeout($timeoutSeconds);
        $output = (string) $ssh->exec($command);

        return ['exit' => (int) $ssh->getExitStatus(), 'output' => $output];
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }
}

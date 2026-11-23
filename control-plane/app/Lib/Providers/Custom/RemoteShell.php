<?php

declare(strict_types=1);

namespace App\Lib\Providers\Custom;

/**
 * One SSH session to a machine we are about to adopt. An interface so the
 * adoption flow is tested without a server.
 */
interface RemoteShell
{
    /** Connect and return the host key's fingerprint ("SHA256:…") before anything is sent to authenticate. */
    public function connect(string $host, int $port): string;

    /** @throws \RuntimeException when the login is refused */
    public function authenticate(string $user, #[\SensitiveParameter] ?string $privateKey, #[\SensitiveParameter] ?string $passphrase, #[\SensitiveParameter] ?string $password): void;

    /** @return array{exit: int, output: string} */
    public function run(string $command, int $timeoutSeconds = 120): array;

    public function disconnect(): void;
}

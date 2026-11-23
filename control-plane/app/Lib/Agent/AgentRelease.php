<?php

declare(strict_types=1);

namespace App\Lib\Agent;

use App\Lib\Agent\Exceptions\AgentBinaryMissing;

/**
 * The agent builds this control plane can hand to a booting node.
 *
 * Checksums are computed once and cached, because cloud-init generation reads
 * one on every provision and hashing a 6 MB binary per request is wasteful
 * for a value that only changes when the file does.
 */
final class AgentRelease
{
    /**
     * Platforms a node might ask for. Deliberately a fixed list: the platform
     * arrives in a URL from an unauthenticated caller, and anything not on
     * this list must not reach the filesystem.
     */
    public const PLATFORMS = ['linux-amd64', 'linux-arm64'];

    /** @var array<string, string> */
    private array $checksums = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $version,
    ) {}

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Absolute path to a platform build.
     *
     * @throws AgentBinaryMissing
     */
    public function path(string $platform): string
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            // Rejected against an allow list rather than sanitised. The value
            // comes from a URL and becomes a path, and a list is easier to be
            // sure about than an escaping rule.
            throw new AgentBinaryMissing(sprintf('Unknown platform %s.', $platform));
        }

        $path = $this->directory.'/cloud-manager-agent-'.$platform;

        if (! is_file($path)) {
            throw new AgentBinaryMissing(sprintf(
                'No agent build for %s. Run scripts/build-agent.sh, which writes into %s.',
                $platform,
                $this->directory
            ));
        }

        return $path;
    }

    public function has(string $platform): bool
    {
        try {
            $this->path($platform);

            return true;
        } catch (AgentBinaryMissing) {
            return false;
        }
    }

    /**
     * The checksum a node verifies before running the binary.
     *
     * This is the control that makes an unauthenticated download safe, so a
     * missing binary is an error rather than an empty string: silently
     * generating cloud-init with no checksum would produce nodes that run
     * whatever they were given.
     *
     * @throws AgentBinaryMissing
     */
    public function sha256(string $platform): string
    {
        if (isset($this->checksums[$platform])) {
            return $this->checksums[$platform];
        }

        $path = $this->path($platform);

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new AgentBinaryMissing(sprintf('Could not hash the agent build for %s.', $platform));
        }

        return $this->checksums[$platform] = $hash;
    }

    /**
     * @throws AgentBinaryMissing
     */
    public function size(string $platform): int
    {
        $size = filesize($this->path($platform));

        return $size === false ? 0 : $size;
    }

    /**
     * Platforms that are actually present.
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        return array_values(array_filter(self::PLATFORMS, fn (string $p) => $this->has($p)));
    }
}

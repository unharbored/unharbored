<?php

declare(strict_types=1);

namespace App\Lib\Audit;

/**
 * Who performed an action.
 *
 * The type matters as much as the identifier. Reading back "who destroyed
 * this droplet" is a different conversation depending on whether the answer
 * is a person, the scheduler, or an agent acting on its own behalf.
 */
final readonly class Actor
{
    public const TYPE_USER = 'user';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_AGENT = 'agent';
    public const TYPE_API_TOKEN = 'api_token';

    private function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $label = null,
    ) {}

    public static function user(string $id, ?string $label = null): self
    {
        return new self(self::TYPE_USER, $id, $label);
    }

    /**
     * An action taken by the platform itself: a scheduled reconciliation, a
     * retry, an autoscaler decision. Never use this for something a person
     * triggered, or the audit log stops answering the question it exists for.
     */
    public static function system(string $label): self
    {
        return new self(self::TYPE_SYSTEM, null, $label);
    }

    public static function agent(string $nodeId): self
    {
        return new self(self::TYPE_AGENT, $nodeId);
    }

    public static function apiToken(string $tokenId, ?string $label = null): self
    {
        return new self(self::TYPE_API_TOKEN, $tokenId, $label);
    }
}

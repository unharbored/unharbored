<?php

declare(strict_types=1);

namespace App\Lib\Console;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What a console's shell wrote, sent to the one page showing it. Sent at once
 * rather than queued: someone is typing and waiting.
 */
final class ConsoleOutput implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<string, mixed>  $payload  seq, data (base64), exit, error */
    public function __construct(public readonly string $session, public readonly array $payload) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("console.{$this->session}");
    }

    public function broadcastAs(): string
    {
        return 'output';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

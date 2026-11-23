<?php

declare(strict_types=1);

namespace App\Lib\Operations;

use Illuminate\Support\Carbon;

/**
 * Formatting for the console's loaders. Loaders run on the server and hand
 * the browser plain strings, since component templates run in the browser
 * where Carbon and the like do not exist.
 */
final class Present
{
    /** "just now", "22 min ago", "3 h ago", "4 Nov". */
    public static function ago(?string $timestamp): string
    {
        if ($timestamp === null) {
            return '';
        }
        $seconds = max(0, time() - Carbon::parse($timestamp)->getTimestamp());

        return match (true) {
            $seconds < 45 => 'just now',
            $seconds < 3600 => intdiv($seconds + 30, 60).' min ago',
            $seconds < 86400 => intdiv($seconds + 1800, 3600).' h ago',
            default => Carbon::parse($timestamp)->format('j M'),
        };
    }

    /** "11s", "2m 50s". */
    public static function duration(?string $from, ?string $to = null): string
    {
        if ($from === null) {
            return '';
        }
        $seconds = max(0, ($to === null ? time() : Carbon::parse($to)->getTimestamp()) - Carbon::parse($from)->getTimestamp());

        return $seconds < 60 ? "{$seconds}s" : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }

    /**
     * An operation's status as a badge: class and label.
     *
     * @return array{class: string, label: string}
     */
    public static function badge(object $operation): array
    {
        return match ($operation->status) {
            'queued' => ['class' => 'running', 'label' => 'Queued'],
            'running' => ['class' => 'running', 'label' => $operation->kind === 'deploy' ? 'Deploying' : 'Running'],
            'failed' => ['class' => 'failed', 'label' => 'Failed'],
            default => ['class' => 'live', 'label' => match ($operation->kind) {
                'deploy' => 'Live',
                'rollback' => 'Rolled back',
                default => 'Done',
            }],
        };
    }

    /** The stage an operation is in, or failed in. */
    public static function currentStage(object $operation): ?string
    {
        foreach (json_decode((string) $operation->stages, true) ?: [] as $stage) {
            if (in_array($stage['status'], ['running', 'failed'], true)) {
                return $stage['name'];
            }
        }

        return null;
    }

    /** Who started it, as the console says it. */
    public static function startedBy(object $operation): string
    {
        return match ($operation->trigger) {
            'push' => "Push · {$operation->actor}",
            'cli' => 'Command line',
            default => $operation->actor,
        };
    }

    /** Commits are kept whole and shown as git does, seven characters. */
    public static function commit(?string $commit): ?string
    {
        return $commit === null || $commit === '' ? null : substr($commit, 0, 7);
    }

    public static function shortRelease(?string $release): string
    {
        return $release === null ? '' : (string) preg_replace('/^\d{8}T(\d{6}Z).*$/', '$1', $release);
    }
}

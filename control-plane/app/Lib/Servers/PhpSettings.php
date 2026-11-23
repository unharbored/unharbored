<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A server's PHP settings for one branch: the limits that decide what an
 * application can upload and how long it may run, FPM's worker count, and any
 * directives of the user's own. Kept per server and branch, and written to a
 * file PHP package updates never touch.
 */
final class PhpSettings
{
    /** What a server runs with until its owner changes it. */
    public const DEFAULTS = [
        'upload_max_filesize' => '64M',
        'post_max_size' => '64M',
        'memory_limit' => '256M',
        'max_execution_time' => 60,
        'pm_max_children' => 10,
        'extra' => '',
    ];

    /** Loaded extensions and files the platform's own setup depends on stay out of a user's directives. */
    private const FORBIDDEN = ['extension', 'zend_extension', 'auto_prepend_file', 'auto_append_file'];

    /**
     * The settings in force for a server's branch: its saved ones, else the defaults.
     *
     * @return array{upload_max_filesize: string, post_max_size: string, memory_limit: string, max_execution_time: int, pm_max_children: int, extra: string}
     */
    public static function for(string $nodeId, string $version): array
    {
        $row = DB::table('server_php_settings')->where('node_id', $nodeId)->where('version', $version)->first();

        return $row === null ? self::DEFAULTS : [
            'upload_max_filesize' => (string) $row->upload_max_filesize,
            'post_max_size' => (string) $row->post_max_size,
            'memory_limit' => (string) $row->memory_limit,
            'max_execution_time' => (int) $row->max_execution_time,
            'pm_max_children' => (int) $row->pm_max_children,
            'extra' => (string) ($row->extra ?? ''),
        ];
    }

    /** Whether the server's branch has settings of its own. */
    public static function isCustom(string $nodeId, string $version): bool
    {
        return DB::table('server_php_settings')->where('node_id', $nodeId)->where('version', $version)->exists();
    }

    /**
     * Checks a set of settings and returns it complete, or says what is wrong.
     *
     * @param  array<string, mixed>  $in
     * @return array{upload_max_filesize: string, post_max_size: string, memory_limit: string, max_execution_time: int, pm_max_children: int, extra: string}
     */
    public static function normalize(array $in): array
    {
        $in += self::DEFAULTS;
        $size = function (string $name, bool $allowUnlimited = false) use ($in): string {
            $v = strtoupper(trim((string) $in[$name]));
            if (! preg_match('/^\d{1,6}[KMG]?$/', $v) && ! ($allowUnlimited && $v === '-1')) {
                throw new InvalidArgumentException("{$name} is a size such as 64M".self::unlimited($allowUnlimited).'.');
            }

            return $v;
        };
        $int = function (string $name, int $min, int $max) use ($in): int {
            $v = $in[$name];
            if (! is_numeric($v) || (int) $v < $min || (int) $v > $max) {
                throw new InvalidArgumentException("{$name} is a whole number between {$min} and {$max}.");
            }

            return (int) $v;
        };
        $out = [
            'upload_max_filesize' => $size('upload_max_filesize'),
            'post_max_size' => $size('post_max_size'),
            'memory_limit' => $size('memory_limit', true),
            'max_execution_time' => $int('max_execution_time', 0, 3600),
            'pm_max_children' => $int('pm_max_children', 1, 200),
            'extra' => self::checkExtra((string) $in['extra']),
        ];
        if (self::megabytes($out['post_max_size']) < self::megabytes($out['upload_max_filesize'])) {
            throw new InvalidArgumentException('post_max_size has to be at least as large as upload_max_filesize, or the upload is cut off.');
        }

        return $out;
    }

    /** Directives the user wrote: plain ini lines, none that load code. */
    public static function checkExtra(string $extra): string
    {
        $extra = trim(str_replace("\r\n", "\n", $extra));
        if (strlen($extra) > 5000) {
            throw new InvalidArgumentException('The extra directives are longer than 5,000 characters.');
        }
        foreach (explode("\n", $extra) as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || preg_match('/^\[[^\]\n]+\]$/', $line)) {
                continue;
            }
            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*\S.*$/', $line, $m)) {
                throw new InvalidArgumentException('Line '.($n + 1).' is not a directive. Write name = value, or start a comment with ;');
            }
            if (in_array(strtolower($m[1]), self::FORBIDDEN, true)) {
                throw new InvalidArgumentException("{$m[1]} cannot be set here: it loads code onto the server.");
            }
        }

        return $extra;
    }

    /** A size in php.ini's shorthand, as megabytes (a plain number is bytes). -1 is unlimited. */
    public static function megabytes(string $size): float
    {
        $size = strtoupper(trim($size));
        if ($size === '-1') {
            return INF;
        }
        $n = (float) $size;

        return match (substr($size, -1)) {
            'G' => $n * 1024,
            'M' => $n,
            'K' => $n / 1024,
            default => $n / 1048576,
        };
    }

    /** What an upload is really limited to by PHP: the smaller of the two settings. */
    public static function uploadLimitMb(array $settings): float
    {
        return min(self::megabytes($settings['upload_max_filesize']), self::megabytes($settings['post_max_size']));
    }

    /** Saves a server's settings for one branch; this only records them. */
    public static function save(string $nodeId, string $version, array $settings): array
    {
        $s = self::normalize($settings);
        DB::table('server_php_settings')->updateOrInsert(['node_id' => $nodeId, 'version' => $version],
            $s + ['updated_at' => now(), 'created_at' => now()]);

        return $s;
    }

    /** The file PHP-FPM reads for web requests. */
    public static function iniForFpm(array $s): string
    {
        return "; Managed by cloud-manager. Changes made here are overwritten; edit them in the console.\n"
            ."upload_max_filesize = {$s['upload_max_filesize']}\n"
            ."post_max_size = {$s['post_max_size']}\n"
            ."memory_limit = {$s['memory_limit']}\n"
            ."max_execution_time = {$s['max_execution_time']}\n"
            .($s['extra'] === '' ? '' : "\n; Your directives\n{$s['extra']}\n");
    }

    /**
     * The file the command line reads. Only the user's own directives: the
     * limits above are for web requests, and a queue worker or an artisan
     * command must not inherit a 60 second execution limit.
     */
    public static function iniForCli(array $s): string
    {
        return "; Managed by cloud-manager. Changes made here are overwritten; edit them in the console.\n"
            .($s['extra'] === '' ? '' : "\n; Your directives\n{$s['extra']}\n");
    }

    private static function unlimited(bool $allowed): string
    {
        return $allowed ? ', or -1 for no limit' : '';
    }
}

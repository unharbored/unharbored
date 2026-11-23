<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Conversion queries for a database clone: SQL the owner wants run on the copy
 * after it is restored (set every password, scrub the emails), so the copy is
 * safe to test with.
 *
 * This is the first line of defence: it fills the placeholders, splits the text
 * into statements, and refuses constructs that reach outside the database
 * (files, shells, other databases, roles). The real boundary is that the
 * statements run as the copy's own database user, never the source's or an
 * admin's (see DatabaseClones::script). Nothing here executes SQL.
 */
final class CloneConversion
{
    public const MAX_LENGTH = 20000;

    /** Rules every engine shares: [pattern on the lower-cased text with literals blanked, why]. */
    private const COMMON = [
        ['/\\\\/', 'backslash commands such as \\! \\c \\copy and \\i are not allowed'],
        ['/^(begin|start|commit|end|rollback|abort|savepoint|release)\b/', 'transaction control is not allowed, the statements already run in one transaction where the database allows it'],
        ['/^(prepare|execute|deallocate)\b/', 'prepared and dynamic statements are not allowed'],
        ['/^(use|source|connect|system|shell|load)\b/', 'switching database, running files or a shell is not allowed'],
        ['/\b(sys_exec|sys_eval|xp_cmdshell)\b/', 'running a shell command is not allowed'],
        ['/^(grant|revoke)\b/', 'changing privileges is not allowed'],
        ['/\bcreate\s+(or\s+replace\s+)?(temp(orary)?\s+)?(definer\b|function|procedure|trigger|event|language|server|user|role|database|extension|aggregate|operator|foreign|plugin)\b/', 'creating functions, triggers, users, extensions or databases is not allowed'],
        ['/\balter\s+(system|role|user|database|extension|server|event|function|procedure)\b/', 'altering the server, roles or extensions is not allowed'],
        ['/\bdrop\s+(database|role|user|server|extension)\b/', 'dropping databases, roles or extensions is not allowed'],
    ];

    private const ENGINE = [
        'pgsql' => [
            ['/^copy\b/', 'COPY reads and writes files and programs, so it is not allowed'],
            ['/^set\s+(session\s+|local\s+)?(role|session\s+authorization|authorization)\b/', 'SET ROLE and SET SESSION AUTHORIZATION are not allowed'],
            ['/^reset\s+(role|session)/', 'RESET ROLE and RESET SESSION AUTHORIZATION are not allowed'],
            ['/\bset_config\b/', 'set_config is not allowed'],
            ['/^do\b/', 'DO blocks are not allowed'],
            ['/\bpg_(read_\w*file|ls_\w+|stat_file|file_\w+|logdir_\w+|reload_conf|rotate_logfile|terminate_backend|cancel_backend|promote|read_server_files|write_server_files|execute_server_program)\b/', 'server file and process functions are not allowed'],
            ['/\blo_\w+/', 'large-object import and export (lo_import, lo_export) are not allowed'],
            ['/\b(dblink\w*|postgres_fdw|file_fdw)\b/', 'reaching another database (dblink, foreign servers) is not allowed'],
        ],
        'mysql' => [
            ['/\bload\s+(data|xml)\b/', 'LOAD DATA reads files and is not allowed'],
            ['/\binto\s+(outfile|dumpfile)\b/', 'INTO OUTFILE and INTO DUMPFILE write files and are not allowed'],
            ['/\bload_file\s*\(/', 'LOAD_FILE reads files and is not allowed'],
            ['/^set\s+(global|persist|persist_only)\b|@@(global|persist|persist_only)\./', 'changing global settings is not allowed'],
            ['/^set\s+(session\s+)?(sql_log_bin|default\s+role|role|password)\b/', 'changing roles, passwords or the binary log is not allowed'],
            ['/\b(install|uninstall)\s+(plugin|component|soname)\b/', 'plugins are not allowed'],
            ['/^(shutdown|kill|flush|change\s+(master|replication)|start\s+(slave|replica))\b/', 'server administration is not allowed'],
        ],
        'sqlite' => [
            ['/\b(attach|detach)\b/', 'ATTACH and DETACH reach other database files and are not allowed'],
            ['/\bload_extension\b/', 'load_extension is not allowed'],
            ['/\bpragma\b/', 'PRAGMA is not allowed'],
            ['/\bvacuum\b/', 'VACUUM is not allowed'],
            ['/\b(readfile|writefile|edit|fts3_tokenizer)\b/', 'file functions are not allowed'],
            ['/\bcreate\s+virtual\s+table\b/', 'virtual tables are not allowed'],
            ['/^\./', 'dot commands are not allowed'],
        ],
    ];

    /**
     * Placeholders filled, the text split, every statement checked. Returns the
     * statements to run, in order. Throws InvalidArgumentException in plain English.
     *
     * @return list<string>
     */
    public static function prepare(string $text, string $engine): array
    {
        if (! isset(self::ENGINE[$engine])) {
            throw new InvalidArgumentException("Conversion queries are not supported for {$engine}.");
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('The conversion queries are too long, at most '.self::MAX_LENGTH.' characters.');
        }
        $statements = self::split(self::substitute($text), $engine);
        foreach ($statements as $i => $s) {
            self::check($i + 1, $s['masked'], $engine);
        }

        return array_column($statements, 'sql');
    }

    /** {{bcrypt:word}} becomes a bcrypt hash made here, {{now}} the current time. */
    public static function substitute(string $text): string
    {
        $hashes = [];
        $text = preg_replace_callback('/\{\{bcrypt:([^{}\s\']{1,72})\}\}/', function ($m) use (&$hashes) {
            return $hashes[$m[1]] ??= Hash::driver('bcrypt')->make($m[1]);
        }, $text) ?? $text;

        return str_replace('{{now}}', now()->format('Y-m-d H:i:s'), $text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * Ready-made statements that fit the engine, for the form's buttons.
     *
     * @return list<array{label: string, sql: string}>
     */
    public static function examples(string $engine): array
    {
        $email = $engine === 'mysql' ? "CONCAT('user', id, '@example.test')" : "'user' || id || '@example.test'";

        return [
            ['label' => 'Set all passwords', 'sql' => "UPDATE users SET password = '{{bcrypt:secret}}';"],
            ['label' => 'Blank all emails', 'sql' => "UPDATE users SET email = {$email};"],
            ['label' => 'Clear remember tokens', 'sql' => 'UPDATE users SET remember_token = NULL;'],
        ];
    }

    private static function check(int $n, string $masked, string $engine): void
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', strtolower($masked)));
        foreach ([...self::COMMON, ...self::ENGINE[$engine]] as [$pattern, $why]) {
            if (preg_match($pattern, $sql)) {
                throw new InvalidArgumentException("Statement {$n} is not allowed: {$why}.");
            }
        }
    }

    /**
     * Splits on semicolons that are not inside a quote, a dollar-quote (PostgreSQL) or a
     * comment. Comments become a space. 'masked' is the same statement with the inside of
     * string literals and quoted names blanked, which is what the rules are matched against.
     *
     * @return list<array{sql: string, masked: string}>
     */
    public static function split(string $text, string $engine): array
    {
        $out = [];
        $sql = $masked = '';
        $n = strlen($text);
        $push = function () use (&$out, &$sql, &$masked) {
            if (trim($sql) !== '') {
                $out[] = ['sql' => trim($sql), 'masked' => trim($masked)];
            }
            $sql = $masked = '';
        };
        for ($i = 0; $i < $n; $i++) {
            $c = $text[$i];
            $next = $text[$i + 1] ?? '';
            if ($c === "'" || $c === '"' || $c === '`') {
                // MySQL treats a backslash in any quote as an escape; PostgreSQL only in E'...'.
                $escapes = $engine === 'mysql' || ($engine === 'pgsql' && $c === "'" && in_array($text[$i - 1] ?? '', ['e', 'E'], true));
                $j = $i + 1;
                while (true) {
                    if ($j >= $n) {
                        throw new InvalidArgumentException('A quote is never closed.');
                    }
                    if ($escapes && $text[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($text[$j] === $c) {
                        if (($text[$j + 1] ?? '') === $c) {
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $sql .= substr($text, $i, $j - $i + 1);
                $masked .= $c.str_repeat(' ', $j - $i - 1).$c;
                $i = $j;
            } elseif ($c === '-' && $next === '-' && ($engine !== 'mysql' || in_array($text[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))
                || ($c === '#' && $engine === 'mysql')) {
                $end = strpos($text, "\n", $i);
                $i = $end === false ? $n : $end - 1;
                $sql .= ' ';
                $masked .= ' ';
            } elseif ($c === '/' && $next === '*') {
                if ($engine === 'mysql' && (($text[$i + 2] ?? '') === '!' || substr($text, $i + 2, 2) === 'M!')) {
                    throw new InvalidArgumentException('MySQL version comments (/*! ... */) run as code and are not allowed.');
                }
                $end = strpos($text, '*/', $i + 2);
                if ($end === false) {
                    throw new InvalidArgumentException('A comment is never closed.');
                }
                $i = $end + 1;
                $sql .= ' ';
                $masked .= ' ';
            } elseif ($c === '$' && $engine === 'pgsql' && ! preg_match('/[A-Za-z0-9_$]/', $text[$i - 1] ?? ' ') && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $text, $m, 0, $i)) {
                $end = strpos($text, $m[0], $i + strlen($m[0]));
                if ($end === false) {
                    throw new InvalidArgumentException('A dollar-quoted string is never closed.');
                }
                $chunk = substr($text, $i, $end + strlen($m[0]) - $i);
                $sql .= $chunk;
                $masked .= $chunk; // its body is scanned, not blanked: it can hide code
                $i = $end + strlen($m[0]) - 1;
            } elseif ($c === ';') {
                $push();
            } else {
                $sql .= $c;
                $masked .= $c;
            }
        }
        $push();

        return $out;
    }
}

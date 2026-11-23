<?php

namespace Tests\Feature\Sites;

use App\Lib\Sites\CloneConversion;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversion queries: the splitter, the placeholders, the examples and the
 * list of constructs that reach outside the copy. The scoped database user is
 * the real boundary; these are the first line.
 */
final class CloneConversionTest extends TestCase
{
    /** @return list<string> */
    private function split(string $text, string $engine = 'pgsql'): array
    {
        return array_column(CloneConversion::split($text, $engine), 'sql');
    }

    public function test_statements_split_on_semicolons_and_empty_ones_are_dropped(): void
    {
        $this->assertSame(['UPDATE a SET b = 1', 'DELETE FROM c'], $this->split(" UPDATE a SET b = 1;;\n ; DELETE FROM c ; \n"));
        $this->assertSame([], $this->split("  ;\n;"));
        $this->assertSame([], $this->split(''));
    }

    public function test_semicolons_in_strings_names_and_comments_are_not_separators(): void
    {
        $this->assertSame(["UPDATE a SET b = 'x;y'", 'UPDATE c SET d = 1'], $this->split("UPDATE a SET b = 'x;y'; UPDATE c SET d = 1"));
        $this->assertSame(["UPDATE a SET b = 'it''s; fine'"], $this->split("UPDATE a SET b = 'it''s; fine';"));
        $this->assertSame(['UPDATE "we;ird" SET b = 1'], $this->split('UPDATE "we;ird" SET b = 1;'));
        $this->assertSame(['UPDATE `we;ird` SET b = 1'], $this->split('UPDATE `we;ird` SET b = 1;', 'mysql'));
        $this->assertSame(['SELECT 1', 'SELECT 2'], array_map('trim', $this->split("SELECT 1 -- not; a split\n; SELECT 2 /* nor; this */;")));
        $this->assertSame(['SELECT 1 , 2'], array_map(fn ($q) => preg_replace('/ +/', ' ', $q), $this->split('SELECT 1 /* a; b */ , 2')));
    }

    public function test_engine_specific_quoting(): void
    {
        // MySQL: a backslash escapes a quote, # and "-- " are comments, "--x" is not.
        $this->assertSame(["UPDATE a SET b = 'it\\'s; ok'"], $this->split("UPDATE a SET b = 'it\\'s; ok';", 'mysql'));
        $this->assertSame(['SELECT 1', 'SELECT 2'], array_map('trim', $this->split("SELECT 1 # x; y\n; SELECT 2", 'mysql')));
        $this->assertCount(2, $this->split('SELECT 1--1; SELECT 2', 'mysql'));
        // PostgreSQL: a backslash is plain, except in E'...'.
        $this->assertCount(2, $this->split("SELECT 'a\\'; SELECT 2", 'pgsql'));
        $this->assertCount(1, $this->split("SELECT E'a\\'; b';", 'pgsql'));
        // Dollar quotes: PostgreSQL only.
        $this->assertSame(['SELECT $$a;b$$', 'SELECT $t$x; $$ y$t$'], $this->split('SELECT $$a;b$$; SELECT $t$x; $$ y$t$;'));
        $this->assertSame(['UPDATE a SET b = $1'], $this->split('UPDATE a SET b = $1;'));
        $this->assertCount(3, $this->split('SELECT $$a; SELECT 2; $$', 'mysql'));
    }

    public function test_unclosed_quotes_and_comments_are_refused(): void
    {
        foreach (["SELECT 'a", 'SELECT "a', 'SELECT /* a', 'SELECT $$ a'] as $sql) {
            try {
                $this->split($sql);
                $this->fail("{$sql} should be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('never closed', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->split('SELECT /*! 1 */ 1', 'mysql');
    }

    public function test_placeholders_are_filled_before_the_text_is_checked(): void
    {
        $text = CloneConversion::substitute("UPDATE users SET password = '{{bcrypt:secret}}', pw2 = '{{bcrypt:secret}}', at = '{{now}}', x = '{{other}}';");
        preg_match_all('/\$2y\$\d+\$[.\/A-Za-z0-9]{53}/', $text, $m);
        $this->assertCount(2, $m[0]);
        $this->assertTrue(Hash::driver('bcrypt')->check('secret', $m[0][0]));
        $this->assertSame($m[0][0], $m[0][1], 'The same word gives the same hash in one run.');
        $this->assertMatchesRegularExpression("/at = '\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d'/", $text);
        $this->assertStringContainsString("'{{other}}'", $text);
        $this->assertSame(["UPDATE users SET password = '{$m[0][0]}'"], CloneConversion::prepare("UPDATE users SET password = '{{bcrypt:secret}}';", 'pgsql') ? [CloneConversion::prepare("UPDATE users SET password = '{$m[0][0]}';", 'pgsql')[0]] : []);
        $this->assertSame(CloneConversion::hash('a'), CloneConversion::hash(" a\n"));
    }

    public function test_examples_fit_the_engine_and_pass_their_own_checks(): void
    {
        foreach (['pgsql', 'mysql', 'sqlite'] as $engine) {
            $examples = CloneConversion::examples($engine);
            $this->assertCount(3, $examples);
            foreach ($examples as $e) {
                $this->assertCount(1, CloneConversion::prepare($e['sql'], $engine), "{$engine}: {$e['label']}");
            }
        }
        $this->assertStringContainsString("'user' || id || '@example.test'", CloneConversion::examples('pgsql')[1]['sql']);
        $this->assertStringContainsString("'user' || id || '@example.test'", CloneConversion::examples('sqlite')[1]['sql']);
        $this->assertStringContainsString("CONCAT('user', id, '@example.test')", CloneConversion::examples('mysql')[1]['sql']);
        $this->assertStringNotContainsString('||', CloneConversion::examples('mysql')[1]['sql']);
        $this->assertStringContainsString('{{bcrypt:secret}}', CloneConversion::examples('mysql')[0]['sql']);
        $this->assertStringContainsString('remember_token = NULL', CloneConversion::examples('pgsql')[2]['sql']);
    }

    /** @return array<string, array{string, string}> */
    public static function forbidden(): array
    {
        $common = [
            'psql shell' => '\\! ls', 'psql connect' => '\\c other', 'psql copy' => '\\copy users to x', 'psql include' => '\\i /etc/x.sql',
            'backslash after a statement' => "SELECT 1 \\gexec", 'use' => 'USE other_db', 'connect' => 'CONNECT other', 'source' => 'SOURCE /tmp/x.sql',
            'transaction control' => 'COMMIT', 'begin' => 'BEGIN', 'prepare' => 'PREPARE x AS SELECT 1', 'execute' => 'EXECUTE x',
            'grant' => 'GRANT ALL ON users TO x', 'create extension' => 'CREATE EXTENSION dblink', 'create function' => 'CREATE OR REPLACE FUNCTION f() RETURNS int AS $$ SELECT 1 $$ LANGUAGE sql',
            'create user' => 'CREATE USER x', 'drop database' => 'DROP DATABASE shop', 'alter system' => 'ALTER SYSTEM SET x = 1', 'shell' => 'SELECT sys_exec(\'id\')',
            'hidden by case and spacing' => "cReAtE   \n EXTENSION x",
        ];
        $engine = [
            'pgsql' => [
                'copy program' => "COPY users TO PROGRAM 'sh'", 'copy from' => "COPY users FROM '/etc/passwd'", 'set session authorization' => 'SET SESSION AUTHORIZATION postgres',
                'set role' => 'SET ROLE postgres', 'set local role' => 'SET LOCAL ROLE x', 'reset role' => 'RESET ROLE', 'pg_read_file' => "SELECT pg_read_file('/etc/passwd')", 'pg_ls_dir' => "SELECT pg_ls_dir('/')",
                'lo_import' => "SELECT lo_import('/etc/passwd')", 'lo_export' => "SELECT lo_export(1, '/tmp/x')", 'dblink' => "SELECT * FROM dblink('dbname=shop', 'select 1') AS t(a int)",
                'do block' => 'DO $$ BEGIN PERFORM 1; END $$', 'set_config' => "SELECT set_config('role', 'x', false)", 'foreign server' => 'CREATE SERVER s FOREIGN DATA WRAPPER postgres_fdw',
                'load' => "LOAD '/lib/x.so'", 'hidden in dollar quote' => 'SELECT $$ x $$, pg_read_file($q$/etc/passwd$q$)', 'pg_terminate_backend' => 'SELECT pg_terminate_backend(1)',
            ],
            'mysql' => [
                'load data local infile' => "LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t", 'into outfile' => "SELECT * FROM users INTO OUTFILE '/tmp/x'", 'into dumpfile' => "SELECT 1 INTO DUMPFILE '/tmp/x'",
                'load_file' => "SELECT LOAD_FILE('/etc/passwd')", 'set global' => 'SET GLOBAL local_infile = 1', 'set persist' => 'SET PERSIST x = 1', 'at at global' => 'SET @@global.sql_mode = 1',
                'set role' => 'SET ROLE ALL', 'set password' => "SET PASSWORD = 'x'", 'install plugin' => "INSTALL PLUGIN x SONAME 'x.so'", 'kill' => 'KILL 1', 'shutdown' => 'SHUTDOWN',
                'create trigger' => 'CREATE DEFINER = root TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.a = 1', 'tabs between words' => "LOAD\tDATA INFILE 'x' INTO TABLE t",
            ],
            'sqlite' => [
                'attach' => "ATTACH DATABASE '/srv/other/shared/database.sqlite' AS o", 'detach' => 'DETACH o', 'load_extension' => "SELECT load_extension('/tmp/x.so')", 'pragma' => 'PRAGMA writable_schema = 1',
                'vacuum into' => "VACUUM INTO '/tmp/x'", 'readfile' => "SELECT readfile('/etc/passwd')", 'virtual table' => 'CREATE VIRTUAL TABLE t USING fts5(a)', 'dot command' => '.shell ls',
            ],
        ];
        $cases = [];
        foreach (['pgsql', 'mysql', 'sqlite'] as $e) {
            foreach ($common + $engine[$e] as $name => $sql) {
                $cases["{$e}: {$name}"] = [$e, $sql];
            }
        }

        return $cases;
    }

    #[DataProvider('forbidden')]
    public function test_constructs_that_reach_outside_the_copy_are_refused(string $engine, string $sql): void
    {
        try {
            CloneConversion::prepare("UPDATE ok SET a = 1;\n{$sql};", $engine);
            $this->fail("{$engine} should refuse: {$sql}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('Statement 2 is not allowed: ', $e->getMessage());
        }
    }

    public function test_ordinary_conversions_and_words_inside_values_are_allowed(): void
    {
        $sql = "UPDATE users SET role = 'a', copy = 1, global = 2, password = '{{bcrypt:secret}}', note = 'copy this; use pg_read_file \\ never' WHERE role <> 'admin';\n"
            ."DELETE FROM sessions;\nUPDATE users SET name = 'Load Data, Attach' /* USE x */;\nINSERT INTO settings (k, v) VALUES ('mail', 'log');\nTRUNCATE jobs;";
        foreach (['pgsql', 'mysql', 'sqlite'] as $engine) {
            $this->assertCount(5, CloneConversion::prepare($sql, $engine), $engine);
        }
        $this->assertSame([], CloneConversion::prepare("  \n", 'pgsql'));
    }

    public function test_the_error_names_the_statement_and_unknown_engines_and_long_text_are_refused(): void
    {
        try {
            CloneConversion::prepare("UPDATE a SET b = 1;\nUPDATE a SET b = 2;\nCOPY a TO PROGRAM 'x';", 'pgsql');
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Statement 3 is not allowed', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        CloneConversion::prepare(str_repeat('x', CloneConversion::MAX_LENGTH + 1), 'pgsql');
    }

    public function test_an_unsupported_engine_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloneConversion::prepare('SELECT 1', 'oracle');
    }
}

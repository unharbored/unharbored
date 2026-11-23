<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The MySQL snapshot. Runs only when the suite is pointed at a MySQL database
 * (DB_CONNECTION=mysql and the usual DB_* variables) with the mysql client
 * installed; it restores the dump into a scratch database, because a backup is
 * only worth what restores from it.
 */
final class BackupDatabaseMysqlTest extends TestCase
{
    private string $dir;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs the suite run against MySQL.');
        }
        $this->dir = sys_get_temp_dir().'/cp-mysql-backups-'.bin2hex(random_bytes(4));
        $this->scratch = 'backup_restore_'.bin2hex(random_bytes(4));
        Schema::dropIfExists('backup_probe');
        Schema::create('backup_probe', function ($table) {
            $table->id();
            $table->string('name');
        });
        DB::table('backup_probe')->insert(['name' => 'demo ünï']);
    }

    protected function tearDown(): void
    {
        if (isset($this->scratch)) {
            Schema::dropIfExists('backup_probe');
            DB::statement("DROP DATABASE IF EXISTS `{$this->scratch}`");
            array_map('unlink', glob("{$this->dir}/*") ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_a_dump_restores_to_the_same_data(): void
    {
        $this->artisan('backup:database', ['--to' => $this->dir])->assertSuccessful();

        $snapshots = glob("{$this->dir}/control-plane-*.sql.gz");
        $this->assertCount(1, $snapshots);
        $this->assertSame('600', substr(sprintf('%o', fileperms($snapshots[0])), -3), 'Snapshots hold every secret, encrypted; owner only.');

        $c = config('database.connections.mysql');
        DB::statement("CREATE DATABASE `{$this->scratch}` CHARACTER SET utf8mb4");
        $restore = Process::fromShellCommandline(
            'gunzip -c "$FILE" | mysql --host="$H" --port="$P" --user="$U" "$DB"',
            null,
            ['FILE' => $snapshots[0], 'H' => $c['host'], 'P' => $c['port'], 'U' => $c['username'], 'DB' => $this->scratch, 'MYSQL_PWD' => $c['password'] ?? ''],
        );
        $restore->mustRun();

        $this->assertSame('demo ünï', DB::selectOne("SELECT name FROM `{$this->scratch}`.backup_probe")->name);
    }

    public function test_only_the_newest_are_kept(): void
    {
        mkdir($this->dir, 0700);
        foreach (['20260101T000000Z', '20260102T000000Z', '20260103T000000Z'] as $old) {
            touch("{$this->dir}/control-plane-{$old}.sql.gz");
        }

        $this->artisan('backup:database', ['--to' => $this->dir, '--keep' => 2])->assertSuccessful();

        $left = array_map('basename', glob("{$this->dir}/control-plane-*.sql.gz"));
        $this->assertCount(2, $left);
        $this->assertNotContains('control-plane-20260101T000000Z.sql.gz', $left);
        $this->assertNotContains('control-plane-20260102T000000Z.sql.gz', $left);
    }

    public function test_a_failed_dump_leaves_no_snapshot_and_fails(): void
    {
        config(['database.connections.mysql.password' => 'wrong-password']);
        DB::purge('mysql');

        try {
            $this->artisan('backup:database', ['--to' => $this->dir])->run();
            $this->fail('A dump that cannot connect must not succeed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mysqldump did not finish', $e->getMessage());
        } finally {
            config(['database.connections.mysql.password' => 'secret']);
            DB::purge('mysql');
        }
        $this->assertSame([], glob("{$this->dir}/control-plane-*.sql.gz") ?: []);
    }
}

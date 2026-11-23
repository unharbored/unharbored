<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backups are only worth what restores from them, so the test restores one.
 */
final class BackupDatabaseTest extends TestCase
{
    private string $db;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The SQLite snapshot; BackupDatabaseMysqlTest covers MySQL.');
        }
        $this->db = sys_get_temp_dir().'/cp-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->dir = sys_get_temp_dir().'/cp-backups-'.bin2hex(random_bytes(4));
        touch($this->db);
        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)');
        DB::table('sites')->insert(['name' => 'demo']);
        // Where an off-server copy would be recorded; none here.
        DB::statement('CREATE TABLE resources (id INTEGER PRIMARY KEY, organization_id TEXT, type TEXT, state TEXT, attributes TEXT)');
    }

    protected function tearDown(): void
    {
        if (! isset($this->db)) {
            parent::tearDown();

            return;
        }
        DB::purge('sqlite');
        @unlink($this->db);
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_snapshot_restores_to_the_same_data(): void
    {
        $this->artisan('backup:database', ['--to' => $this->dir])->assertSuccessful();

        $snapshots = glob("{$this->dir}/control-plane-*.sqlite.gz");
        $this->assertCount(1, $snapshots);
        $this->assertSame('600', substr(sprintf('%o', fileperms($snapshots[0])), -3), 'Snapshots hold every secret, encrypted; owner only.');

        $restored = "{$this->dir}/restored.sqlite";
        file_put_contents($restored, gzdecode((string) file_get_contents($snapshots[0])));
        $this->assertSame('demo', (new \PDO("sqlite:{$restored}"))->query('SELECT name FROM sites')->fetchColumn());
        unlink($restored);
    }

    public function test_only_the_newest_are_kept(): void
    {
        mkdir($this->dir, 0700);
        foreach (['20260101T000000Z', '20260102T000000Z', '20260103T000000Z'] as $old) {
            touch("{$this->dir}/control-plane-{$old}.sqlite.gz");
        }

        $this->artisan('backup:database', ['--to' => $this->dir, '--keep' => 2])->assertSuccessful();

        $left = array_map('basename', glob("{$this->dir}/control-plane-*.sqlite.gz"));
        $this->assertCount(2, $left);
        $this->assertNotContains('control-plane-20260101T000000Z.sqlite.gz', $left);
        $this->assertNotContains('control-plane-20260102T000000Z.sqlite.gz', $left);
    }
}

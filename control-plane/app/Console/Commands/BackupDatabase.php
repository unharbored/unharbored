<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\Spaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * backup:database — a consistent, compressed snapshot of the control plane's
 * database, keeping the newest N.
 *
 * SQLite: VACUUM INTO writes a clean copy while the app keeps running, so a
 * snapshot taken mid-deploy is still a valid database (control-plane-*.sqlite.gz).
 * MySQL: mysqldump --single-transaction reads one consistent view without
 * locking the app (control-plane-*.sql.gz); restore it with
 * `gunzip -c <file> | mysql <database>`. Needs the mysqldump client installed.
 * Secrets in it are encrypted by
 * the vault, whose master key is NOT in the snapshot: a backup is only
 * restorable with VAULT_MASTER_KEY kept somewhere else.
 *
 * Once backup:offsite has made a bucket, each snapshot is also copied there
 * and the bucket keeps the newest N too. Old copies are pruned here, not by
 * a Spaces expiry rule, so if this server dies its last snapshots stay.
 */
final class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--to=/var/lib/cloud-manager/backups : Directory for snapshots} {--keep=14 : How many to keep}';

    protected $description = 'Snapshot the database, compressed, and prune old snapshots';

    public function handle(Spaces $spaces): int
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            $this->error('Only SQLite and MySQL are backed up by this command.');

            return self::FAILURE;
        }
        $ext = $driver === 'mysql' ? 'sql' : 'sqlite';

        $dir = rtrim((string) $this->option('to'), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        $stamp = gmdate('Ymd\THis\Z');
        $gz = $driver === 'mysql' ? $this->snapshotMysql($dir, $stamp) : $this->snapshotSqlite($dir, $stamp);
        chmod($gz, 0600);

        $snapshots = glob("{$dir}/control-plane-*.{$ext}.gz") ?: [];
        rsort($snapshots);
        foreach (array_slice($snapshots, max(1, (int) $this->option('keep'))) as $old) {
            unlink($old);
        }

        $this->info(sprintf('%s (%d KB); %d kept.', basename($gz), (int) ceil(filesize($gz) / 1024), min(count($snapshots), (int) $this->option('keep'))));

        $target = $spaces->backupTarget();
        if ($target === null) {
            $this->warn('No off-server copy: run backup:offsite to set one up.');

            return self::SUCCESS;
        }
        $this->info($spaces->copyBackup($target, $gz, max(1, (int) $this->option('keep'))));

        return self::SUCCESS;
    }

    /** A compressed VACUUM INTO copy, opened and integrity-checked before it is trusted. */
    private function snapshotSqlite(string $dir, string $stamp): string
    {
        $raw = "{$dir}/control-plane-{$stamp}.sqlite";
        DB::statement('VACUUM INTO ?', [$raw]);

        // Prove the copy opens and is whole before trusting it.
        $check = new \PDO("sqlite:{$raw}");
        $integrity = $check->query('PRAGMA integrity_check')->fetchColumn();
        $check = null;
        if ($integrity !== 'ok') {
            @unlink($raw);
            throw new RuntimeException("The snapshot failed its integrity check: {$integrity}");
        }

        $gz = "{$raw}.gz";
        $in = fopen($raw, 'rb');
        $out = gzopen($gz, 'wb9');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        unlink($raw);

        return $gz;
    }

    /**
     * mysqldump streamed into a gzip file. The password goes in the environment, not the command line, where other
     * users could read it. A dump is only whole if mysqldump exited cleanly and wrote its closing marker, which it
     * leaves off a dump that was cut short.
     */
    private function snapshotMysql(string $dir, string $stamp): string
    {
        $c = config('database.connections.'.DB::getDefaultConnection());
        $gz = "{$dir}/control-plane-{$stamp}.sql.gz";

        $command = ['mysqldump', '--single-transaction', '--quick', '--no-tablespaces', '--default-character-set=utf8mb4', '--user='.$c['username']];
        if (! empty($c['unix_socket'])) {
            $command[] = '--socket='.$c['unix_socket'];
        } else {
            array_push($command, '--host='.$c['host'], '--port='.$c['port']);
        }
        $command[] = $c['database'];

        $env = ['PATH' => (string) getenv('PATH')];
        if (($c['password'] ?? '') !== '') {
            $env['MYSQL_PWD'] = (string) $c['password'];
        }
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not run mysqldump: is the MySQL client installed?');
        }

        $out = gzopen($gz, 'wb9');
        $tail = '';
        while (! feof($pipes[1])) {
            $chunk = (string) fread($pipes[1], 1 << 20);
            gzwrite($out, $chunk);
            $tail = substr($tail.$chunk, -300);
        }
        gzclose($out);
        $errors = trim((string) stream_get_contents($pipes[2]));
        $status = proc_close($process);

        if ($status !== 0 || ! str_contains($tail, '-- Dump completed')) {
            @unlink($gz);
            throw new RuntimeException('mysqldump did not finish: '.($errors !== '' ? $errors : "exit status {$status}"));
        }

        return $gz;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * audit_records is append-only in code; these triggers make the database
     * refuse an UPDATE or DELETE too, so a stray query or compromised app
     * code cannot rewrite or remove history. audit_chain_heads is left alone:
     * it moves on every append.
     *
     * SQLite and MySQL. On Postgres, REVOKE UPDATE, DELETE on audit_records
     * from the application's role instead.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            return;
        }
        $this->drop();
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'audit_records_no_'.strtolower($event);
            DB::unprepared($driver === 'mysql'
                ? "CREATE TRIGGER {$name} BEFORE {$event} ON audit_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit records are append-only'"
                : "CREATE TRIGGER {$name} BEFORE {$event} ON audit_records BEGIN SELECT RAISE(ABORT, 'audit records are append-only'); END");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'mysql'], true)) {
            $this->drop();
        }
    }

    private function drop(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_records_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_records_no_delete');
    }
};

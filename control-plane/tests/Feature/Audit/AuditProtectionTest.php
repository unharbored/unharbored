<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function record(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            (new AuditLog())->record('org-1', Actor::system('t'), 'thing.happened', resourceId: (string) $i);
        }
    }

    public function test_an_update_is_refused(): void
    {
        $this->record();
        $this->expectExceptionMessage('audit records are append-only');
        DB::table('audit_records')->update(['action' => 'tampered']);
    }

    public function test_a_delete_is_refused(): void
    {
        $this->record();
        $this->expectExceptionMessage('audit records are append-only');
        DB::table('audit_records')->delete();
    }

    public function test_appending_still_works_and_the_chain_verifies(): void
    {
        $this->record(5);
        (new AuditLog())->record('org-2', Actor::system('t'), 'thing.happened');

        $this->assertSame(6, DB::table('audit_records')->count());
        $this->assertTrue((new AuditLog())->verify('org-1')->intact);
        $this->assertTrue((new AuditLog())->verify('org-2')->intact);
    }
}

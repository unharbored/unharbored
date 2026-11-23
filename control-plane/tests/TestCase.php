<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /** Pages are Vue; tests look at the props, so no built assets are needed. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // A dev server running on this machine (public/hot) would otherwise render the page; tests read the HTML as built.
        config(['inertia.ssr.enabled' => false]);
    }

    /**
     * Signing in as a user the test has not placed in an organisation makes
     * them an Owner of org-1, as every user was before roles. A test that
     * wants another arrangement gives the user a membership first.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && Schema::hasTable('memberships') && ! DB::table('memberships')->where('user_id', $user->id)->exists()) {
            DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        }

        return parent::actingAs($user, $guard);
    }

    /** For a test that tampers with audit_records on purpose, to prove verify() notices. */
    protected function dropAuditTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_records_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_records_no_delete');
    }
}

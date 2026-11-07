<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleTransitionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function activeCredentials(User $user, string $sessionId): int
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        return $user->createToken('mobile', ['platform'], now()->addDay())->accessToken->id;
    }

    public function test_eloquent_role_change_revokes_all_existing_tokens_and_browser_sessions(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $tokenId = $this->activeCredentials($user, 'role-old-session');
        $oldRememberToken = $user->getRememberToken();

        $user->role = 'admin';
        $user->save();

        $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'role-old-session']);
        $this->assertNotSame($oldRememberToken, $user->fresh()->getRememberToken());
    }

    public function test_unrelated_profile_update_and_noop_role_assignment_do_not_revoke_credentials(): void
    {
        $user = User::factory()->create(['role' => 'vendor']);
        $tokenId = $this->activeCredentials($user, 'unchanged-role-session');
        $oldRememberToken = $user->getRememberToken();

        $user->name = 'Updated name';
        $user->save();
        Artisan::call('platform:role', ['email' => $user->email, 'role' => 'vendor']);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-role-session']);
        $this->assertSame($oldRememberToken, $user->fresh()->getRememberToken());
    }

    public function test_cli_operator_role_promotion_revokes_prior_sessions_and_tokens(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $tokenId = $this->activeCredentials($user, 'cli-old-session');

        $this->assertSame(0, Artisan::call('platform:role', ['email' => $user->email, 'role' => 'admin']));

        $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'cli-old-session']);
    }

    public function test_operator_cli_role_changes_preserve_audit_provenance_and_noops(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->activeCredentials($user, 'operator-audit-session');

        $this->assertSame(0, Artisan::call('platform:role', [
            'email' => $user->email,
            'role' => 'admin',
            '--ticket' => 'SEC-2026:1742',
        ]));
        $this->assertDatabaseHas('operator_role_changes', [
            'target_user_id' => $user->id,
            'previous_role' => 'customer',
            'assigned_role' => 'admin',
            'source' => 'platform:role',
            'ticket_reference' => 'SEC-2026:1742',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token]);
        $this->assertDatabaseMissing('sessions', ['id' => 'operator-audit-session']);
        $this->assertDatabaseCount('operator_role_changes', 1);

        // Re-running a command at its desired role does not manufacture
        // another privilege-change audit event or invalidate new sessions.
        $newToken = $user->fresh()->createToken('new-mobile', ['platform'], now()->addDay())->accessToken->id;
        $this->assertSame(0, Artisan::call('platform:role', [
            'email' => $user->email, 'role' => 'admin', '--ticket' => 'SEC-2026:1742',
        ]));
        $this->assertDatabaseCount('operator_role_changes', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $newToken]);

        $this->assertSame(0, Artisan::call('platform:role', [
            'email' => $user->email, 'role' => 'vendor',
        ]));
        $this->assertDatabaseHas('operator_role_changes', [
            'target_user_id' => $user->id,
            'previous_role' => 'admin',
            'assigned_role' => 'vendor',
            'source' => 'platform:role',
            'ticket_reference' => null,
        ]);
        $this->assertDatabaseCount('operator_role_changes', 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $newToken]);
    }

    public function test_invalid_cli_ticket_and_role_cannot_mutate_privileges_or_create_audit(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->assertSame(1, Artisan::call('platform:role', [
            'email' => $user->email,
            'role' => 'admin',
            '--ticket' => 'bad/wildcard%reference',
        ]));
        $this->assertSame(1, Artisan::call('platform:role', [
            'email' => $user->email,
            'role' => 'superadmin',
        ]));
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertDatabaseCount('operator_role_changes', 0);
    }

    public function test_unverified_mailbox_cannot_receive_administrator_privileges(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);
        $tokenId = $this->activeCredentials($user, 'unverified-admin-session');

        $this->assertSame(1, Artisan::call('platform:role', [
            'email' => $user->email,
            'role' => 'admin',
            '--ticket' => 'SEC-VERIFY:17',
        ]));
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('sessions', ['id' => 'unverified-admin-session']);
        $this->assertDatabaseCount('operator_role_changes', 0);

        $user->markEmailAsVerified();
        $this->assertSame(0, Artisan::call('platform:role', [
            'email' => $user->email,
            'role' => 'admin',
            '--ticket' => 'SEC-VERIFY:17',
        ]));
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'unverified-admin-session']);
        $this->assertDatabaseHas('operator_role_changes', [
            'target_user_id' => $user->id,
            'previous_role' => 'customer',
            'assigned_role' => 'admin',
            'ticket_reference' => 'SEC-VERIFY:17',
        ]);
    }

    public function test_admin_kyc_approval_revokes_applicants_old_customer_credentials(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $tokenId = $this->activeCredentials($owner, 'kyc-old-session');
        $admin = User::factory()->create(['role' => 'admin']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic licensed operator',
            'country' => 'AE',
            'trade_license_number' => 'TEST-LICENSE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'submitted',
        ]);

        // The production approval path requires an uploaded trade-license
        // record. Create synthetic metadata only; no private file is needed.
        VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $owner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'storage_path' => 'synthetic-kyc-evidence.enc',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/vendor-ops/companies/'.$company->id.'/review', [
                'status' => 'approved',
                'reason' => 'Synthetic approved company for role-boundary test',
            ])->assertOk();

        $this->assertSame('vendor', $owner->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'kyc-old-session']);
    }
}

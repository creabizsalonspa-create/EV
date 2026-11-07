<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
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

        $user->role = 'admin';
        $user->save();

        $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'role-old-session']);
    }

    public function test_unrelated_profile_update_and_noop_role_assignment_do_not_revoke_credentials(): void
    {
        $user = User::factory()->create(['role' => 'vendor']);
        $tokenId = $this->activeCredentials($user, 'unchanged-role-session');

        $user->name = 'Updated name';
        $user->save();
        Artisan::call('platform:role', ['email' => $user->email, 'role' => 'vendor']);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-role-session']);
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

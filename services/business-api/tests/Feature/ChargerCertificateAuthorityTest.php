<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerCertificateController;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargerCertificates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerCertificateAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'Synthetic certificate authority',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'name' => 'Synthetic',
            'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic',
            'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'CURRENT-CERT', 'manufacturer' => 'Synthetic',
            'model' => 'Offline', 'source' => 'simulator', 'ocpp_protocol' => '2.0.1',
        ]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/admission';
        $profile = $this->actingAs($owner)->postJson($base,
            ['identity' => 'ev-test-certificate-current-actor'])->assertCreated()->json();

        return compact('owner', 'company', 'charger', 'profile');
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Stale certificate authority must not be accepted.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public static function changes(): array
    {
        return [
            'epoch' => ['auth_epoch', 4, 409],
            'password' => ['password', 'rotated-certificate-password', 409],
            'role' => ['role', 'customer', 409],
            'suspended' => ['account_status', 'suspended', 403],
        ];
    }

    #[DataProvider('changes')]
    public function test_revoked_certificate_operator_cannot_read_enroll_or_cancel_from_captured_login(string $field, mixed $value, int $status): void
    {
        $f = $this->fixture();
        $service = app(ChargerCertificates::class);
        $actor = $f['owner']; // original authenticated request snapshot
        DB::table('users')->where('id', $actor->id)->update([$field => $value]);

        $this->denied(fn () => $service->scope($actor, $f['company'], $f['charger']), $status);
        $intent = [
            'idempotency_key' => (string) Str::uuid(),
            'expected_actor_id' => $actor->id,
            'expected_profile_id' => $f['profile']['id'],
            'expected_version' => 0,
            'consent' => true,
            'reason' => 'Synthetic stale certificate authority attempted enrollment',
            'public_certificate' => 'not-a-certificate',
        ];
        $this->denied(fn () => $service->apply($actor, $f['company'], $f['charger'], 'enroll', $intent), $status);
        $this->denied(fn () => $service->resolve($actor, $f['company'], $f['charger'], [
            'idempotency_key' => (string) Str::uuid(),
            'expected_actor_id' => $actor->id,
            'expected_profile_id' => $f['profile']['id'],
            'expected_action' => 'enroll',
            'cancel_unissued' => true,
        ]), $status);
        $request = Request::create('/private-certificate-history', 'GET');
        $request->setUserResolver(fn () => $actor);
        $this->denied(fn () => app(ChargerCertificateController::class)
            ->index($request, $f['company'], $f['charger'], $service), $status);

        $this->assertDatabaseCount('charger_certificates', 0);
        $this->assertDatabaseCount('charger_certificate_actions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function bearerChanges(): array
    {
        return [
            ['deleted'],
            ['expired'],
            ['maximum_age'],
        ];
    }

    #[DataProvider('bearerChanges')]
    public function test_revoked_or_expired_individual_bearer_cannot_read_or_mutate_certificate_authority(string $change): void
    {
        $f = $this->fixture();
        $issued = $f['owner']->createToken('certificate-authority-fixture');
        $actor = $f['owner']->fresh()->withAccessToken($issued->accessToken);
        match ($change) {
            'deleted' => $issued->accessToken->delete(),
            'expired' => $issued->accessToken->forceFill(['expires_at' => now()->subSecond()])->save(),
            'maximum_age' => $issued->accessToken->forceFill(['created_at' => now()->subMinutes(2)])->save(),
        };
        if ($change === 'maximum_age') {
            config(['sanctum.expiration' => 1]);
        }
        $service = app(ChargerCertificates::class);
        $this->denied(fn () => $service->scope($actor, $f['company'], $f['charger']), 403);
        $this->denied(fn () => $service->apply($actor, $f['company'], $f['charger'], 'enroll', [
            'idempotency_key' => (string) Str::uuid(),
            'expected_actor_id' => $actor->id,
            'expected_profile_id' => $f['profile']['id'],
            'expected_version' => 0,
            'consent' => true,
            'reason' => 'Expired individual bearer must not enroll a certificate',
            'public_certificate' => 'not-a-certificate',
        ]), 403);
        $this->denied(fn () => $service->apply($actor, $f['company'], $f['charger'], 'revoke', [
            'idempotency_key' => (string) Str::uuid(),
            'expected_actor_id' => $actor->id,
            'expected_profile_id' => $f['profile']['id'],
            'expected_version' => 0,
            'consent' => true,
            'reason' => 'Expired individual bearer must not revoke a certificate',
            'certificate_id' => 1,
        ]), 403);
        $this->denied(fn () => $service->resolve($actor, $f['company'], $f['charger'], [
            'idempotency_key' => (string) Str::uuid(),
            'expected_actor_id' => $actor->id,
            'expected_profile_id' => $f['profile']['id'],
            'expected_action' => 'enroll',
            'cancel_unissued' => true,
        ]), 403);
        $request = Request::create('/private-certificate-history', 'GET');
        $request->setUserResolver(fn () => $actor);
        $this->denied(fn () => app(ChargerCertificateController::class)
            ->index($request, $f['company'], $f['charger'], $service), 403);
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->assertDatabaseCount('charger_certificate_actions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        // The account itself remains valid; a different current credential
        // must still be able to read its own private certificate history.
        $replacement = $f['owner']->fresh()->createToken('current-certificate-fixture');
        $request->setUserResolver(fn () => $f['owner']->fresh()->withAccessToken($replacement->accessToken));
        $this->assertSame(200, app(ChargerCertificateController::class)
            ->index($request, $f['company'], $f['charger'], $service)->getStatusCode());
    }

    public function test_current_authenticated_owner_retains_private_certificate_list_after_new_login(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
        $fresh = $f['owner']->fresh();
        $request = Request::create('/private-certificate-history', 'GET');
        $request->setUserResolver(fn () => $fresh);
        $response = app(ChargerCertificateController::class)
            ->index($request, $f['company'], $f['charger'], app(ChargerCertificates::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('charger_certificate_actions', 0);
    }
}

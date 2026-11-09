<?php

namespace Tests\Feature;

use App\Http\Controllers\RiderAccessController;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration-only privacy regression for the new read-only rider history.
 * Runs against Agent1's history API once integrated with Agent9's route guards.
 * No physical card provision, source command, invoice or live PSP is invoked.
 */
class RiderHistoryCrossTenantIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_activity_history_is_private_to_token_owner_and_operator_company_on_both_mounts(): void
    {
        if (! method_exists(RiderAccessController::class, 'activity')
            || ! method_exists(RiderAccessController::class, 'grantActivity')) {
            $this->markTestSkipped('Requires Agent1 rider history endpoints on the integrated branch.');
        }

        $owner = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $otherVendor = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);

        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'TEST scoped history station',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $company = VendorCompany::create([
            'owner_id' => $vendor->id, 'legal_name' => 'TEST history operator',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-A',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $otherCompany = VendorCompany::create([
            'owner_id' => $otherVendor->id, 'legal_name' => 'TEST unrelated operator',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-B',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'TEST history site', 'country' => 'AE',
            'timezone' => 'Asia/Dubai', 'address' => 'Synthetic only',
            'latitude' => 25, 'longitude' => 55,
        ]);

        $this->actingAs($owner);
        $token = $this->postJson('/api/rider-access/tokens', [
            'label' => 'Private synthetic card',
            'token_type' => 'ISO14443',
            'value' => 'AABBCC123456',
            'expires_at' => now()->addMonth()->format('Y-m-d\\TH:i:s\\Z'),
            'idempotency_key' => 'agent9-private-rider-history',
        ])->assertCreated()->json('token.id');
        $grant = $this->postJson('/api/rider-access/tokens/'.$token.'/grants', [
            'station_id' => $station->id,
        ])->assertCreated()->json('id');

        $this->actingAs($vendor)->postJson(
            '/api/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/review',
            [
                'state' => 'approved', 'note' => 'PRIVATE-OPERATOR-EVIDENCE',
                'expires_at' => now()->addDay()->format('Y-m-d\\TH:i:s\\Z'),
            ]
        )->assertOk();

        foreach (['/api', '/api/v1'] as $prefix) {
            $customerUri = $prefix.'/rider-access/tokens/'.$token.'/activity';
            $operatorUri = $prefix.'/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/activity';
            $otherCompanyUri = $prefix.'/vendor-ops/companies/'.$otherCompany->id.'/rider-grants/'.$grant.'/activity';

            // The operator must never read the customer's private token history.
            $this->actingAs($vendor)->getJson($customerUri)->assertNotFound();
            $this->actingAs($otherVendor)->getJson($operatorUri)->assertNotFound();
            $this->actingAs($otherVendor)->getJson($otherCompanyUri)->assertNotFound();
            $this->actingAs($otherCustomer)->getJson($customerUri)->assertNotFound();
            $this->actingAs($otherCustomer)->getJson($operatorUri)->assertNotFound();

            $operatorHistory = $this->actingAs($vendor)->getJson($operatorUri)->assertOk();
            $this->assertStringNotContainsString('PRIVATE-OPERATOR-EVIDENCE', $operatorHistory->getContent());
            $this->assertStringNotContainsString('AABBCC123456', $operatorHistory->getContent());
            $operatorHistory->assertJsonPath('read_only', true)->assertJsonPath('hardware_verified', false);

            $customerHistory = $this->actingAs($owner)->getJson($customerUri)->assertOk();
            $customerHistory->assertJsonPath('read_only', true)->assertJsonPath('charging_authorized', false);
            $this->assertStringNotContainsString('PRIVATE-OPERATOR-EVIDENCE', $customerHistory->getContent());
            $this->assertStringNotContainsString('AABBCC123456', $customerHistory->getContent());

            $this->actingAs($admin)->getJson($operatorUri)->assertOk();

            // Query validation must reject attempts to access unsupported raw
            // audit records or excessive pages via either authenticated mount.
            $this->actingAs($owner)->getJson($customerUri.'?kind=raw')->assertUnprocessable();
            $this->getJson($customerUri.'?page=100001')->assertUnprocessable();
            $this->actingAs($vendor)->getJson($operatorUri.'?kind=raw')->assertUnprocessable();

            foreach ([$operatorHistory, $customerHistory] as $privateHistory) {
                $this->assertStringContainsString('no-store', (string) $privateHistory->headers->get('Cache-Control'));
                foreach (['actor_id', 'token_digest', 'review_note', 'privatePayload'] as $secretField) {
                    $this->assertStringNotContainsString($secretField, $privateHistory->getContent());
                }
            }

            foreach ([str_repeat('9', 19), str_repeat('9', 120), '-1', '1e20'] as $invalid) {
                $this->getJson($prefix.'/vendor-ops/companies/'.$company->id.
                    '/rider-grants/'.$invalid.'/activity')->assertNotFound();
            }
        }

        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}

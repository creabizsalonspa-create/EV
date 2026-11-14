<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerAdmissionController;
use App\Http\Controllers\ChargerDiagnosticLogController;
use App\Http\Controllers\ChargerDiagnosticsController;
use App\Http\Controllers\ChargingRepairController;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerAuthorityRevocationTest extends TestCase
{
    use RefreshDatabase;

    public static function changes(): array
    {
        $rows = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['epoch', 'password', 'role', 'suspension'] as $change) {
                $rows[] = [$protocol, $change];
            }
        }

        return $rows;
    }

    #[DataProvider('changes')]
    public function test_diagnostic_repair_and_provisioning_handlers_never_replace_a_revoked_login_with_current_authority(string $protocol, string $change): void
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $actor->id, 'name' => 'Private fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $actor->id, 'legal_name' => 'Fixture', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'REVOCATION', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-revocation', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $values = match ($change) {
            'epoch' => ['auth_epoch' => $actor->auth_epoch + 1],
            'password' => ['password' => bcrypt('new-synthetic-password')],
            'role' => ['role' => 'admin'],
            'suspension' => ['account_status' => 'suspended'],
        };
        DB::table('users')->where('id', $actor->id)->update($values);
        foreach (['logs', 'repairs', 'diagnostics', 'provisioning'] as $operation) {
            $request = Request::create('/private-source', 'GET');
            $request->setUserResolver(fn () => $actor);
            try {
                match ($operation) {
                    'logs' => app(ChargerDiagnosticLogController::class)->index($request, $company, $charger),
                    'repairs' => app(ChargingRepairController::class)->index($request, $company, $charger),
                    'diagnostics' => app(ChargerDiagnosticsController::class)($request, $company, $charger),
                    'provisioning' => app(ChargerAdmissionController::class)->show($request, $company, $charger),
                };
                $this->fail('Revoked login retained '.$operation.' authority.');
            } catch (HttpException $e) {
                $this->assertSame($change === 'suspension' ? 403 : 409, $e->getStatusCode());
            }
            $this->assertSame($actor, $request->user(), 'The captured login must remain the original snapshot.');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_admission_profiles', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Services\Ocpi\CpoCatalogContract;
use App\Services\Ocpi\CpoSessionSource;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OcpiCpoSessionContractTest extends TestCase
{
    public function test_sender_advertisement_and_both_exports_require_the_same_complete_local_cpo_mapping(): void
    {
        Http::fake();
        $provider = new OcpiProvider(['connection_key' => 'session-contract']);
        config([
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [['role' => 'CPO', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic CPO']]]],
            'ocpi.connections.session-contract' => ['managed_credentials' => true, 'party_role' => 'EMSP', 'environment' => 'sandbox'],
        ]);
        $contract = ['id' => 'synthetic-export', 'sites' => [10 => []], 'tariffs' => []];
        $mapping = ['site_id' => 10, 'token_uid' => 'TEST-TOKEN', 'contract_id' => 'TEST-CONTRACT', 'evse_id' => 'AE*EVX*E123'];
        $service = app(CpoCatalogContract::class);
        $valid = $contract + ['session_exports' => [20 => $mapping]];
        config(['ocpi.connections.session-contract.cpo_catalog' => $valid]);
        $this->assertSame($valid, $service->approved($provider));
        $this->assertSame([20 => $mapping], $service->sessionExports($provider, $valid));
        $this->assertSame(['locations', 'tariffs', 'sessions', 'cdrs'], $service->modules($provider));

        foreach ([
            [20 => array_replace($mapping, ['evse_id' => 'NL*EVX*E123'])],
            [20 => array_replace($mapping, ['evse_id' => 'AE*BAD*E123'])],
            [20 => array_replace($mapping, ['evse_id' => 'AE*EVX*E'])],
            [20 => array_replace($mapping, ['site_id' => 99])],
            [20 => array_replace($mapping, ['site_id' => true])],
            [20 => array_replace($mapping, ['token_uid' => 'REAL-TOKEN'])],
            [20 => array_replace($mapping, ['contract_id' => null])],
            [20 => $mapping, 21 => 'bad'],
            [0 => $mapping],
            ['invalid-id' => $mapping],
        ] as $invalid) {
            $candidate = $contract + ['session_exports' => $invalid];
            config(['ocpi.connections.session-contract.cpo_catalog' => $candidate]);
            // Keep the original credential fingerprint inputs and unrelated
            // catalog capabilities intact even when exports require review.
            $this->assertSame($candidate, $service->approved($provider));
            $this->assertSame([], $service->sessionExports($provider, $candidate));
            $this->assertSame(['locations', 'tariffs'], $service->modules($provider));
            foreach (['sessions', 'cdrs'] as $module) {
                try {
                    app(CpoSessionSource::class)->payload($provider, $candidate, $module, 20);
                    $this->fail('An invalid mapping reached the charging source.');
                } catch (HttpException $error) {
                    $this->assertSame(409, $error->getStatusCode());
                }
            }
        }
        config(['ocpi.connections.session-contract.cpo_catalog' => $valid]);
        $this->assertSame(['locations', 'tariffs', 'sessions', 'cdrs'], $service->modules($provider));
        Http::assertNothingSent();
    }
}

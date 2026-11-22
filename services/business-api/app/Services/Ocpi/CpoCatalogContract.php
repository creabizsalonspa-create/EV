<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;

final class CpoCatalogContract
{
    public const MODULES = ['locations', 'tariffs'];

    public const MAX_RECORD_BYTES = 262_144;

    public function approved(OcpiProvider $provider): ?array
    {
        $connection = config('ocpi.connections.'.$provider->connection_key);
        $contract = $connection['cpo_catalog'] ?? null;
        if ($contract === null) {
            return null;
        }
        $role = app(CredentialExchange::class)->localConfiguration($provider)['roles'][0];
        abort_unless(
            $connection['managed_credentials'] === true
            && $connection['party_role'] === 'EMSP'
            && ($role['role'] ?? null) === 'CPO'
            && is_array($contract)
            && is_string($contract['id'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $contract['id'])
            && is_array($contract['sites'] ?? null)
            && count($contract['sites']) <= 250
            && is_array($contract['tariffs'] ?? null)
            && array_is_list($contract['tariffs'])
            && count($contract['tariffs']) <= 500,
            409, 'CPO catalog contract is invalid.'
        );

        return $contract;
    }
}

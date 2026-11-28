<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;

final class CpoCatalogContract
{
    public const MODULES = ['locations', 'tariffs'];

    public const SESSION_MODULES = ['sessions', 'cdrs'];

    public function modules(OcpiProvider $provider): array
    {
        $contract = $this->approved($provider);
        if ($contract === null) {
            return [];
        }

        return $this->sessionExports($provider, $contract) === [] ? self::MODULES : [...self::MODULES, ...self::SESSION_MODULES];
    }

    /**
     * A complete, valid synthetic mapping is required before advertising
     * final session/CDR senders. Do not change the credential fingerprint
     * or disable unrelated location/tariff publication for a bad mapping.
     */
    public function sessionExports(OcpiProvider $provider, array $contract): array
    {
        $exports = $contract['session_exports'] ?? [];
        $connection = config('ocpi.connections.'.$provider->connection_key);
        if (! app()->environment(['local', 'testing']) || ($connection['environment'] ?? null) !== 'sandbox'
            || ! is_array($exports) || count($exports) > 250 || $exports === []) {
            return [];
        }
        $role = app(CredentialExchange::class)->localConfiguration($provider)['roles'][0];
        if ($role['role'] !== 'CPO' || ($connection['party_role'] ?? null) !== 'EMSP') {
            return [];
        }
        $prefix = $role['country_code'].'*'.$role['party_id'].'*E';
        foreach ($exports as $session => $mapping) {
            if (! is_int($session) || $session < 1 || ! is_array($mapping)) {
                return [];
            }
            $site = $mapping['site_id'] ?? null;
            if ((! is_int($site) && (! is_string($site) || ! preg_match('/^[1-9][0-9]*$/D', $site)))
                || filter_var($site, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || ! is_array($contract['sites'][(int) $site] ?? null)) {
                return [];
            }
            foreach (['token_uid', 'contract_id'] as $key) {
                if (! is_string($mapping[$key] ?? null) || ! preg_match('/^TEST-[A-Za-z0-9-]{1,31}$/D', $mapping[$key])) {
                    return [];
                }
            }
            if (! is_string($mapping['evse_id'] ?? null)
                || ! preg_match('/^[A-Z]{2}\*[A-Z0-9]{3}\*E[A-Z0-9*]{1,30}$/D', $mapping['evse_id'])
                || ! str_starts_with($mapping['evse_id'], $prefix)) {
                return [];
            }
        }

        return $exports;
    }

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

        if (isset($contract['session_exports'])) {
            abort_unless(app()->environment(['local', 'testing']) && ($connection['environment'] ?? null) === 'sandbox'
                && is_array($contract['session_exports']) && count($contract['session_exports']) <= 250, 409, 'Session exports require an explicit synthetic sandbox contract.');
        }

        return $contract;
    }
}

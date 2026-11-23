<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use Throwable;

/**
 * Private capability evidence. A negotiated module is not live roaming,
 * station availability, partner conformance, or customer permission.
 */
final class PartnerCapabilityMatrix
{
    private const MODULES = ['locations', 'tariffs', 'sessions', 'cdrs'];

    public function read(
        OcpiProvider $provider,
        bool $configured,
        bool $credentialsCurrent,
        bool $ownerActive,
        array $configuredReceivers,
        array $activeReceivers,
    ): array {
        $connection = config('ocpi.connections.'.$provider->connection_key);
        $connection = is_array($connection) ? $connection : [];
        $authorized = $provider->authorized && $ownerActive;
        $negotiated = $authorized && $provider->version === '2.2.1';
        $managed = ($connection['managed_credentials'] ?? false) === true;
        $connectionCurrent = false;
        if ($negotiated && (! $managed || $credentialsCurrent)) {
            try {
                app(PartnerClient::class)->connection($provider);
                $connectionCurrent = true;
            } catch (Throwable) {
                // A stale, revoked, or unprovisioned peer is not an active reader.
            }
        }
        $outbound = $connectionCurrent;

        $cpoModules = [];
        if ($configured) {
            try {
                $contract = app(CpoCatalogContract::class);
                // The later, independently reviewed CPO session/CDR branch
                // introduces modules() behind explicit sandbox exports.
                // Preserve parity in either dependency merge order.
                $cpoModules = method_exists($contract, 'modules')
                    ? $contract->modules($provider)
                    : ($contract->approved($provider) === null ? [] : CpoCatalogContract::MODULES);
            } catch (Throwable) {
                // An invalid/missing CPO contract never advertises senders.
            }
        }
        $senderCurrent = $negotiated && $credentialsCurrent;
        $sandbox = app()->environment(['local', 'testing'])
            && ($connection['environment'] ?? null) === 'sandbox';

        $modules = [];
        foreach (self::MODULES as $module) {
            $modules[] = [
                'module' => $module,
                'partner_sender_negotiated' => $outbound && is_string($provider->endpoints[$module] ?? null),
                'local_receiver_configured' => in_array($module, $configuredReceivers, true),
                'local_receiver_active' => in_array($module, $activeReceivers, true),
                'local_sender_configured' => in_array($module, $cpoModules, true),
                'local_sender_active' => $senderCurrent && in_array($module, $cpoModules, true),
                'sandbox_delivery_software_enabled' => $sandbox && $senderCurrent
                    && config('ocpi.cpo_push_enabled') === true
                    && in_array($module, $cpoModules, true)
                    && is_string($provider->endpoints[$module.'_receiver'] ?? null),
            ];
        }

        return [
            'evidence' => 'NEGOTIATED_AND_CONFIGURED_SOFTWARE_ONLY',
            'peer_role' => $configured && in_array($connection['party_role'] ?? null, ['CPO', 'EMSP'], true)
                ? $connection['party_role'] : null,
            'credentials_current' => $credentialsCurrent,
            'modules' => $modules,
            'live_roaming_verified' => false,
            'physical_availability_verified' => false,
            'partner_conformance_verified' => false,
            'payment_authority' => false,
        ];
    }
}

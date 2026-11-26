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

    private function approvedEndpoint(OcpiProvider $provider, string $key, bool $mutation = false): bool
    {
        $endpoint = $provider->endpoints[$key] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return false;
        }
        try {
            $url = app(PartnerClient::class)->guardedUrl($provider, $endpoint);

            return ! $mutation || parse_url($url, PHP_URL_QUERY) === null;
        } catch (Throwable) {
            return false;
        }
    }

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
        $senderCurrent = $negotiated && $credentialsCurrent && $connectionCurrent;
        $sandbox = app()->environment(['local', 'testing'])
            && ($connection['environment'] ?? null) === 'sandbox';

        $modules = [];
        foreach (self::MODULES as $module) {
            $modules[] = [
                'module' => $module,
                'partner_sender_negotiated' => $outbound && PartnerReadProfile::allows($connection, $module)
                    && $this->approvedEndpoint($provider, $module),
                'local_receiver_configured' => in_array($module, $configuredReceivers, true),
                'local_receiver_active' => in_array($module, $activeReceivers, true),
                // Active credential reception can precede version negotiation.
                // Keep that backward-compatible flag distinct from request readiness.
                'local_receiver_request_ready' => $configured && $provider->authorized
                    && $provider->version === '2.2.1' && in_array($module, $activeReceivers, true)
                    && in_array($module, $configuredReceivers, true)
                    && $this->approvedEndpoint($provider, $module),
                'local_sender_configured' => in_array($module, $cpoModules, true),
                'local_sender_active' => $senderCurrent && in_array($module, $cpoModules, true),
                'sandbox_delivery_software_enabled' => $sandbox && $senderCurrent
                    && config('ocpi.cpo_push_enabled') === true
                    && in_array($module, $cpoModules, true)
                    && $this->approvedEndpoint($provider, $module.'_receiver', true),
            ];
        }

        // Read the existing sandbox policies; never dispatch a command or
        // infer target, customer consent, physical availability or billing.
        $commandPolicyReady = false;
        $reservationPolicyReady = false;
        if ($sandbox && $configured && $senderCurrent) {
            try {
                app(SandboxRoaming::class)->newWorkPolicy($provider);
                $commandPolicyReady = true;
                $reservationPolicyReady = app(ReservationRoaming::class)->supported($provider);
            } catch (Throwable) {
                // Missing, stale or invalid policy evidence stays unavailable.
            }
        }

        return [
            'evidence' => 'NEGOTIATED_AND_CONFIGURED_SOFTWARE_ONLY',
            'peer_role' => $configured && in_array($connection['party_role'] ?? null, ['CPO', 'EMSP'], true)
                ? $connection['party_role'] : null,
            'credentials_current' => $credentialsCurrent,
            'modules' => $modules,
            'sandbox_command_policy_ready' => $commandPolicyReady,
            'sandbox_reservation_policy_ready' => $reservationPolicyReady,
            'live_roaming_verified' => false,
            'physical_availability_verified' => false,
            'partner_conformance_verified' => false,
            'payment_authority' => false,
        ];
    }
}

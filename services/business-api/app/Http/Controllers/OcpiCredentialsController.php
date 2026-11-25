<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\EnrollmentExpiry;
use App\Services\Ocpi\EnrollmentProof;
use App\Services\Ocpi\IncomingEnrollment;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerCapabilityMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class OcpiCredentialsController extends Controller
{
    public function status(Request $r, OcpiProvider $provider, CredentialExchange $exchange)
    {
        return DB::transaction(function () use ($r, $provider, $exchange) {
            // Private credential and module metadata is tied to the current
            // authenticated identity, not a stale request/session snapshot.
            $snapshot = $r->user();
            abort_unless($snapshot instanceof User
                && ($snapshot->role === 'admin'
                    || ($snapshot->role === 'vendor' && (int) $snapshot->id === (int) $provider->owner_id)), 404);
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->account_status === 'active'
                && in_array($actor->role, ['vendor', 'admin'], true), 403);
            abort_unless($actor->role === $snapshot->role
                && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password),
                409, 'Account credentials changed. Sign in again.');

            // Serialize provider ownership and credential-state reads with
            // account revocation and administrative partner changes. Lock
            // owner before provider, matching the existing onboarding order.
            $owner = (int) $actor->id === (int) $provider->owner_id
                ? $actor : User::whereKey($provider->owner_id)->lockForUpdate()->first();
            $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner && (int) $owner->id === (int) $provider->owner_id,
                409, 'Provider ownership changed. Reload to review current credentials.');
            abort_unless($actor->role === 'admin'
                || (int) $actor->id === (int) $provider->owner_id, 404);
            $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
            $configured = true;
            $receiverModules = [];
            try {
                $exchange->configuration($provider);
                $receiverModules = $exchange->receiverModules($provider);
            } catch (Throwable) {
                $configured = false;
            }
            $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->first();
            $state = $link->state ?? 'not_exchanged';
            $incoming = config('ocpi.connections.'.$provider->connection_key.'.registration_direction', 'outbound') === 'incoming';
            $bootstrap = (string) config('ocpi.connections.'.$provider->connection_key.($incoming ? '.inbound_authorization_token' : '.authorization_token'), '');
            $raw = base64_decode($bootstrap, true);
            $bootstrapReady = is_string($raw) && preg_match('/^[\x21-\x7e]{1,64}$/D', $raw) && base64_encode($raw) === $bootstrap && (! $link || ! hash_equals($link->bootstrap_hash, hash('sha256', $bootstrap)));
            if ($configured && $link && $state === 'active' && ! hash_equals($link->configuration_hash, $exchange->configurationHash($provider))) {
                $state = 'configuration_changed';
            }

            if ($link && $link->owner_id !== $provider->owner_id) {
                $state = 'ownership_changed';
            }
            if ($link && $state === 'awaiting_registration' && EnrollmentProof::current($link) === null) {
                $state = 'enrollment_expired';
            }
            $actions = [];
            $ownerVendor = $owner->role === 'vendor';
            $ownerActive = $ownerVendor && $owner->account_status === 'active';
            if ($configured && $provider->authorized && $r->user()->role === 'admin' && $ownerVendor) {
                if ($state === 'active') {
                    $actions = $ownerActive ? ['rotate', 'unregister'] : ['unregister'];
                } elseif ($ownerActive && $bootstrapReady && in_array($state, ['not_exchanged', 'revoked', 'unregistered', 'awaiting_registration', 'enrollment_expired'], true)) {
                    $actions = [$incoming ? 'prepare_incoming' : 'register'];
                }
            }

            // A persisted state label alone is not proof that this generation's
            // opaque peer/local tokens remain present. Never advertise active
            // receiver rights or a CPO Sender for a partial credential record.
            $tokenBound = $link && $state === 'active' && $link->owner_id === $provider->owner_id
                && $link->generation >= 1 && is_string($link->local_token_digest)
                && preg_match('/^[a-f0-9]{64}$/D', $link->local_token_digest)
                && is_string($link->local_token_ciphertext) && $link->local_token_ciphertext !== ''
                && is_string($link->remote_token_ciphertext) && $link->remote_token_ciphertext !== '';
            if ($tokenBound) {
                try {
                    $localToken = Crypt::decryptString($link->local_token_ciphertext);
                    $remoteToken = Crypt::decryptString($link->remote_token_ciphertext);
                    $tokenBound = preg_match('/^[\x21-\x7e]{1,64}$/D', $localToken)
                        && preg_match('/^[\x21-\x7e]{1,64}$/D', $remoteToken)
                        && hash_equals($link->local_token_digest, hash('sha256', $localToken));
                } catch (Throwable) {
                    $tokenBound = false;
                }
            }
            if ($configured && $state === 'active' && ! $tokenBound) {
                // An incomplete or corrupted active link is not safe to rotate.
                // Keep explicit de-registration available to a current admin.
                $actions = $r->user()->role === 'admin' ? ['unregister'] : [];
            }
            $activeReceivers = $configured && $tokenBound && $provider->authorized
                ? ($ownerActive ? $receiverModules : array_values(array_intersect($receiverModules, ['sessions', 'cdrs']))) : [];
            $capabilities = app(PartnerCapabilityMatrix::class)->read(
                $provider, $configured, $configured && $tokenBound && $provider->authorized && $ownerActive,
                $ownerActive, $receiverModules, $activeReceivers
            );

            return response()->json(['capability_matrix' => $capabilities, 'configured_receiver_modules' => $receiverModules, 'active_receiver_modules' => $activeReceivers, 'configured' => $configured, 'state' => $state, 'generation' => $link->generation ?? 0, 'exchanged_at' => $link->exchanged_at ?? null, 'bootstrap_expires_at' => EnrollmentExpiry::parse($link->bootstrap_expires_at ?? null) !== null ? $link->bootstrap_expires_at : null, 'available_actions' => $actions, 'partner_acceptance_verified' => false, 'charging_commands_enabled' => false, 'billing_eligible' => false, 'operations' => DB::table('ocpi_credential_operations')->where('provider_id', $provider->id)->orderByDesc('id')->paginate(25, ['id', 'generation', 'action', 'state', 'attempted_at', 'completed_at', 'created_at'])->toArray()])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function exchange(Request $r, OcpiProvider $provider, CredentialExchange $exchange)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['action' => 'required|in:register,rotate,unregister,prepare_incoming', 'reason' => 'required|string|min:10|max:2000', 'idempotency_key' => 'required|string|max:100']);
        try {
            $result = $v['action'] === 'prepare_incoming' ? app(IncomingEnrollment::class)->prepare($provider, $r->user()->id, $v, $r->user()) : $exchange->exchange($provider, $r->user()->id, $v, $r->user());
        } catch (OcpiException) {
            abort(409, 'Partner credentials discovery is unavailable; no application token is disclosed.');
        }

        return response()->json($result + ['automatic_retry' => false, 'partner_acceptance_verified' => false, 'charging_commands_enabled' => false, 'billing_eligible' => false])->header('Cache-Control', 'private, no-store');
    }
}

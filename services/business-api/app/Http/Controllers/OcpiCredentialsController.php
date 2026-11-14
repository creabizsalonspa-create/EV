<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\IncomingEnrollment;
use App\Services\Ocpi\OcpiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OcpiCredentialsController extends Controller
{
    public function status(Request $r, OcpiProvider $provider, CredentialExchange $exchange)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $r->user()->id === $provider->owner_id), 404);
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
        if ($link && $state === 'awaiting_registration' && (! $link->bootstrap_expires_at || CarbonImmutable::parse($link->bootstrap_expires_at)->isPast())) {
            $state = 'enrollment_expired';
        }
        $actions = [];
        $owner = User::find($provider->owner_id);
        $ownerVendor = $owner?->role === 'vendor';
        $ownerActive = $ownerVendor && $owner->account_status === 'active';
        if ($configured && $provider->authorized && $r->user()->role === 'admin' && $ownerVendor) {
            if ($state === 'active') {
                $actions = $ownerActive ? ['rotate', 'unregister'] : ['unregister'];
            } elseif ($ownerActive && $bootstrapReady && in_array($state, ['not_exchanged', 'revoked', 'unregistered', 'awaiting_registration', 'enrollment_expired'], true)) {
                $actions = [$incoming ? 'prepare_incoming' : 'register'];
            }
        }

        return response()->json(['configured_receiver_modules' => $receiverModules, 'active_receiver_modules' => $configured && $state === 'active' && $provider->authorized ? ($ownerActive ? $receiverModules : array_values(array_intersect($receiverModules, ['sessions', 'cdrs']))) : [], 'configured' => $configured, 'state' => $state, 'generation' => $link->generation ?? 0, 'exchanged_at' => $link->exchanged_at ?? null, 'bootstrap_expires_at' => $link->bootstrap_expires_at ?? null, 'available_actions' => $actions, 'partner_acceptance_verified' => false, 'charging_commands_enabled' => false, 'billing_eligible' => false, 'operations' => DB::table('ocpi_credential_operations')->where('provider_id', $provider->id)->orderByDesc('id')->paginate(25, ['id', 'generation', 'action', 'state', 'attempted_at', 'completed_at', 'created_at'])->toArray()])->header('Cache-Control', 'private, no-store');
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

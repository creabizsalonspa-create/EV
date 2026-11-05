<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
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
        try {
            $exchange->configuration($provider);
        } catch (Throwable) {
            $configured = false;
        }
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->first();
        $state = $link->state ?? 'not_exchanged';
        $bootstrap = (string) config('ocpi.connections.'.$provider->connection_key.'.authorization_token', '');
        $raw = base64_decode($bootstrap, true);
        $bootstrapReady = is_string($raw) && preg_match('/^[\x21-\x7e]{1,64}$/D', $raw) && base64_encode($raw) === $bootstrap && (! $link || ! hash_equals($link->bootstrap_hash, hash('sha256', $bootstrap)));
        if ($configured && $link && $state === 'active' && ! hash_equals($link->configuration_hash, $exchange->configurationHash($provider))) {
            $state = 'configuration_changed';
        }

        if ($link && $link->owner_id !== $provider->owner_id) {
            $state = 'ownership_changed';
        }
        $actions = [];
        if ($configured && $provider->authorized && $r->user()->role === 'admin') {
            if ($state === 'active') {
                $actions = ['rotate', 'unregister'];
            } elseif ($bootstrapReady && in_array($state, ['not_exchanged', 'revoked', 'unregistered'], true)) {
                $actions = ['register'];
            }
        }

        return response()->json(['configured' => $configured, 'state' => $state, 'generation' => $link->generation ?? 0, 'exchanged_at' => $link->exchanged_at ?? null, 'available_actions' => $actions, 'partner_acceptance_verified' => false, 'charging_commands_enabled' => false, 'billing_eligible' => false, 'operations' => DB::table('ocpi_credential_operations')->where('provider_id', $provider->id)->orderByDesc('id')->paginate(25, ['id', 'generation', 'action', 'state', 'attempted_at', 'completed_at', 'created_at'])->toArray()])->header('Cache-Control', 'private, no-store');
    }

    public function exchange(Request $r, OcpiProvider $provider, CredentialExchange $exchange)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['action' => 'required|in:register,rotate,unregister', 'reason' => 'required|string|min:10|max:2000', 'idempotency_key' => 'required|string|max:100']);
        try {
            $result = $exchange->exchange($provider, $r->user()->id, $v);
        } catch (OcpiException) {
            abort(409, 'Partner credentials discovery is unavailable; no application token is disclosed.');
        }

        return response()->json($result + ['automatic_retry' => false, 'partner_acceptance_verified' => false, 'charging_commands_enabled' => false, 'billing_eligible' => false])->header('Cache-Control', 'private, no-store');
    }
}

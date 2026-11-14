<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Charging\ChargingActorConsent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit non-billable sandbox authority; imported records never grant charging on their own. */
class SandboxRoaming
{
    public const TERMS = 'sandbox-roaming-v1';

    public function policy(OcpiProvider $provider): array
    {
        abort_unless(app()->environment(['local', 'testing']) && config('ocpi.test_commands_enabled') && $provider->authorized && $provider->version === '2.2.1' && User::whereKey($provider->owner_id)->where('role', 'vendor')->exists(), 409, 'Approved sandbox roaming is unavailable.');
        [$c,$local] = app(CredentialExchange::class)->configuration($provider);
        abort_unless(($c['test_commands'] ?? false) === true && ($c['managed_credentials'] ?? false) === true && ($c['environment'] ?? null) === 'sandbox' && $c['party_role'] === 'CPO' && $local['roles'][0]['role'] === 'EMSP' && is_string($c['test_command_contract'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $c['test_command_contract']), 409, 'An approved sandbox contract is required.');
        abort_unless(in_array('sessions', app(CredentialExchange::class)->receiverModules($provider), true) && in_array('cdrs', app(CredentialExchange::class)->receiverModules($provider), true), 409, 'Approved session and CDR reception is required.');
        foreach (['locations', 'tariffs', 'sessions', 'cdrs', 'commands_receiver', 'tokens_receiver'] as $module) {
            $url = $provider->endpoints[$module] ?? null;
            abort_unless(is_string($url) && parse_url($url, PHP_URL_QUERY) === null, 409, 'Required negotiated partner modules are unavailable.');
            app(PartnerClient::class)->guardedUrl($provider, $url);
        }
        app(PartnerClient::class)->connection($provider);
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->where('state', 'active')->first();
        abort_unless($link && $link->owner_id === $provider->owner_id && hash_equals($link->configuration_hash, app(CredentialExchange::class)->configurationHash($provider)), 409, 'Current managed partner authority is required.');

        return [$c, $local, $link];
    }

    public function offer(OcpiRecord $location, string $evseUid, string $connectorId, string $tariffId): array
    {
        $p = OcpiProvider::findOrFail($location->ocpi_provider_id);
        [$c,$local,$link] = $this->policy($p);
        abort_unless(User::whereKey($p->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409, 'Partner owner is unavailable for new charging.');
        abort_unless($location->module === 'locations' && $location->country_code === $c['party_country'] && $location->party_id === $c['party_id'] && ($location->normalized['publish'] ?? false) === true && $location->source_updated_at->betweenIncluded(now()->subMinutes(5), now()), 409, 'Refresh the approved partner location before requesting a test session.');
        $raw = $location->payload;
        $evses = array_values(array_filter($raw['evses'] ?? [], fn ($e) => is_array($e) && ($e['uid'] ?? null) === $evseUid));
        abort_unless(count($evses) === 1 && ($evses[0]['status'] ?? null) === 'AVAILABLE', 409, 'Partner does not freshly report this EVSE available.');
        $connectors = array_values(array_filter($evses[0]['connectors'] ?? [], fn ($x) => is_array($x) && ($x['id'] ?? null) === $connectorId));
        abort_unless(count($connectors) === 1 && in_array($tariffId, $connectors[0]['tariff_ids'] ?? [], true), 409, 'An advertised connector tariff is required.');
        $tariff = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'tariffs')->where('country_code', $c['party_country'])->where('party_id', $c['party_id'])->where('external_id', $tariffId)->firstOrFail();
        abort_unless($tariff->source_updated_at->betweenIncluded(now()->subMinutes(5), now()), 409, 'Refresh the reported tariff before consent.');
        $rawTariff = $tariff->payload;
        abort_unless(count($rawTariff['elements'] ?? []) === 1 && empty($rawTariff['elements'][0]['restrictions']) && ! isset($rawTariff['min_price']) && ! isset($rawTariff['max_price']), 409, 'Unsupported tariff restrictions require review before sandbox consent.');
        if (isset($rawTariff['start_date_time'])) {
            abort_unless(CarbonImmutable::parse($rawTariff['start_date_time'])->lte(now()), 409);
        }
        if (isset($rawTariff['end_date_time'])) {
            abort_unless(CarbonImmutable::parse($rawTariff['end_date_time'])->gt(now()), 409);
        }
        foreach ($tariff->normalized['elements'] as $element) {
            foreach ($element['price_components'] as $price) {
                abort_unless(is_string($price['price']) && preg_match('/^(0|[1-9][0-9]{0,6})(?:\.[0-9]{1,6})?$/D', $price['price']), 409, 'Unsupported tariff precision requires review.');
            }
        }
        $target = ['country_code' => $location->country_code, 'party_id' => $location->party_id, 'location_id' => $location->external_id, 'evse_uid' => $evseUid, 'connector_id' => $connectorId, 'location_name' => $location->normalized['name'], 'tariff_id' => $tariff->external_id, 'tariff' => $tariff->normalized, 'location_hash' => $location->content_hash, 'tariff_hash' => $tariff->content_hash];
        $hash = hash('sha256', json_encode([$p->id, $p->authorization_revision, $link->generation, $c['test_command_contract'], $target, self::TERMS], JSON_THROW_ON_ERROR));

        return ['provider' => $p, 'configuration' => $c, 'local' => $local, 'link' => $link, 'tariff' => $tariff, 'target' => $target, 'hash' => $hash];
    }

    public function consentCurrent(object $order): bool
    {
        $driver = User::whereKey($order->user_id)->first();

        return User::whereKey($order->provider_owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists() && $driver?->role === 'customer' && app(ChargingActorConsent::class)->matches($driver, $order->consent_auth_epoch === null ? null : (int) $order->consent_auth_epoch);
    }

    public function token(object $order, bool $valid = true): array
    {
        $p = OcpiProvider::findOrFail($order->provider_id);
        [, $local] = $this->policy($p);
        $role = $local['roles'][0];
        $valid = $valid && $this->consentCurrent($order);

        return ['country_code' => $role['country_code'], 'party_id' => $role['party_id'], 'uid' => Crypt::decryptString($order->token_ciphertext), 'type' => 'APP_USER', 'contract_id' => $order->authorization_reference, 'issuer' => mb_substr($role['business_details']['name'], 0, 64), 'valid' => $valid, 'whitelist' => 'NEVER', 'last_updated' => CarbonImmutable::parse($order->updated_at)->utc()->toISOString()];
    }

    public function create(User $actor, OcpiRecord $location, array $v): array
    {
        abort_unless($actor->role === 'customer', 403);
        $hash = hash('sha256', json_encode([$location->id, $v['evse_uid'], $v['connector_id'], $v['tariff_id'], $v['offer_hash'], self::TERMS], JSON_THROW_ON_ERROR));
        $old = DB::table('ocpi_roaming_orders')->where('user_id', $actor->id)->where('request_key', $v['request_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->intent_hash, $hash), 409);

            return ['order' => $old, 'created' => false];
        }
        $offer = $this->offer($location, $v['evse_uid'], $v['connector_id'], $v['tariff_id']);
        abort_unless($v['consent'] === true && $v['terms_version'] === self::TERMS && hash_equals($offer['hash'], $v['offer_hash']), 409, 'Review the current sandbox offer before consent.');

        return DB::transaction(function () use ($actor, $location, $v, $offer, $hash) {
            $p = OcpiProvider::whereKey($offer['provider']->id)->lockForUpdate()->firstOrFail();
            $current = app(ChargingActorConsent::class)->capture($actor);
            abort_unless($current->role === 'customer', 403);
            $fresh = $this->offer($location->fresh(), $v['evse_uid'], $v['connector_id'], $v['tariff_id']);
            abort_unless(hash_equals($offer['hash'], $fresh['hash']), 409);
            $old = DB::table('ocpi_roaming_orders')->where('user_id', $actor->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->intent_hash, $hash), 409);

                return ['order' => $old, 'created' => false];
            }
            $key = hash('sha256', json_encode([$p->id, $location->country_code, $location->party_id, strtoupper($location->external_id), strtoupper($v['evse_uid'])], JSON_THROW_ON_ERROR));
            abort_if(DB::table('ocpi_roaming_orders')->where('active_target_key', $key)->exists(), 409, 'An unresolved test order already holds this partner EVSE.');
            abort_if(DB::table('ocpi_roaming_orders')->where('user_id', $actor->id)->whereNotNull('active_target_key')->exists(), 409, 'Resolve the existing sandbox charging order first.');
            $uid = strtoupper(bin2hex(random_bytes(10)));
            $id = DB::table('ocpi_roaming_orders')->insertGetId(['consent_auth_epoch' => (int) $current->auth_epoch, 'user_id' => $actor->id, 'provider_id' => $p->id, 'location_record_id' => $location->id, 'tariff_record_id' => $offer['tariff']->id, 'request_key' => $v['request_key'], 'intent_hash' => $hash, 'offer_hash' => $offer['hash'], 'contract_reference' => $offer['configuration']['test_command_contract'], 'provider_owner_id' => $p->owner_id, 'counterparty_hash' => $offer['link']->configuration_hash, 'source_record_floor' => (int) DB::table('ocpi_records')->where('ocpi_provider_id', $p->id)->max('id'), 'provider_revision' => $p->authorization_revision, 'credential_generation' => $offer['link']->generation, 'authorization_reference' => (string) Str::ulid(), 'target_ciphertext' => Crypt::encryptString(json_encode($offer['target'], JSON_THROW_ON_ERROR)), 'token_ciphertext' => Crypt::encryptString($uid), 'token_digest' => hash('sha256', $uid), 'token_state' => 'unregistered', 'state' => 'prepared', 'active_target_key' => $key, 'consented_at' => now(), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'ocpi.sandbox_consent_recorded', 'subject' => (string) $id, 'created_at' => now()]);

            return ['order' => DB::table('ocpi_roaming_orders')->find($id), 'created' => true];
        }, 3);
    }

    public function current(object $order, bool $start = false): array
    {
        $p = OcpiProvider::findOrFail($order->provider_id);
        [$c,$local,$link] = $this->policy($p);
        abort_unless($p->owner_id === $order->provider_owner_id && hash_equals($order->counterparty_hash, $link->configuration_hash) && $c['test_command_contract'] === $order->contract_reference && User::whereKey($order->user_id)->exists(), 409, 'Original roaming counterparty authority changed; source review is required.');
        if ($start) {
            $driver = User::whereKey($order->user_id)->first();
            abort_unless($driver?->role === 'customer' && app(ChargingActorConsent::class)->matches($driver, $order->consent_auth_epoch === null ? null : (int) $order->consent_auth_epoch), 409, 'Charging consent was revoked or the customer account is unavailable.');
            abort_unless(User::whereKey($p->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409, 'Partner owner is unavailable for new charging.');
            abort_unless($p->authorization_revision === $order->provider_revision && $link->generation === $order->credential_generation, 409, 'Review current credentials and terms before starting.');
        }
        if ($start) {
            abort_unless($order->token_revoked_at === null && CarbonImmutable::parse($order->expires_at)->isFuture(), 409, 'Sandbox start consent expired or was revoked.');
        }

        return [$p, $c, $local, $link];
    }

    public function ownsSourceSession(object $order, OcpiRecord $session): bool
    {
        if ($session->module !== 'sessions' || $session->ocpi_provider_id !== $order->provider_id) {
            return false;
        }
        $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $raw = $session->payload;
        $n = $session->normalized;
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'START_SESSION')->first();
        if (! $command) {
            return false;
        }
        $token = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR)['token'];

        return $session->country_code === $target['country_code'] && $session->party_id === $target['party_id'] && ($raw['authorization_reference'] ?? null) === $order->authorization_reference && ($raw['cdr_token']['uid'] ?? null) === Crypt::decryptString($order->token_ciphertext) && ($raw['cdr_token']['type'] ?? null) === 'APP_USER' && ($raw['cdr_token']['country_code'] ?? null) === $token['country_code'] && ($raw['cdr_token']['party_id'] ?? null) === $token['party_id'] && ($raw['cdr_token']['contract_id'] ?? null) === $token['contract_id'] && ($n['location_id'] ?? null) === $target['location_id'] && ($n['evse_uid'] ?? null) === $target['evse_uid'] && ($n['connector_id'] ?? null) === $target['connector_id'];
    }

    public function present(object $order, bool $driver = false): array
    {
        $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $commands = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->orderByDesc('id')->limit(25)->get(['id', 'action', 'state', 'transport_result', 'callback_result', 'attempted_at', 'callback_at']);
        $canStop = false;
        try {
            $this->current($order);
            $source = $order->source_session_record_id ? OcpiRecord::find($order->source_session_record_id) : null;
            $canStop = $driver && $order->state === 'source_active' && $source && ($source->normalized['status'] ?? null) === 'ACTIVE' && $this->ownsSourceSession($order, $source) && ! DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'STOP_SESSION')->whereNotIn('state', ['partner_rejected'])->exists();
        } catch (\Throwable) {
        }

        $cost = null;
        if ($order->source_cdr_record_id) {
            $cdr = OcpiRecord::find($order->source_cdr_record_id);
            if ($cdr && $cdr->ocpi_provider_id === $order->provider_id) {
                $n = $cdr->normalized;
                $safe = [];
                foreach (['excl_vat', 'incl_vat'] as $k) {
                    $v = $n['cost'][$k] ?? null;
                    if (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,12})(?:\.[0-9]{1,6})?$/D', $v)) {
                        $safe[$k] = $v;
                    }
                }$cost = ['currency' => $n['currency'], 'amounts' => $safe];
            }
        }

        $invoice = DB::table('ocpi_test_invoice_receipts')->where('order_id', $order->id)->first();
        $canInvoice = $driver && app(TestInvoiceLineage::class)->available(User::findOrFail($order->user_id), $order);
        $invoiceId = $invoice && ($driver || OcpiProvider::find($order->provider_id)?->owner_id === $order->provider_owner_id) ? $invoice->invoice_id : null;

        return ['reported_cost' => $cost, 'can_stop' => $canStop, 'can_revoke' => $driver && $order->token_revoked_at === null, 'id' => $order->id, 'provider_id' => $order->provider_id, 'location_name' => $target['location_name'], 'evse_uid' => $target['evse_uid'], 'connector_id' => $target['connector_id'], 'state' => $order->state, 'token_state' => $order->token_state, 'expires_at' => $order->expires_at, 'energy_wh' => $order->energy_wh, 'source_status' => $order->source_status, 'reconciliation_state' => $order->reconciliation_state, 'source_observed_at' => $order->source_observed_at, 'created_at' => $order->created_at, 'commands' => $commands, 'evidence' => 'SANDBOX_PARTNER_UNVERIFIED', 'billing_eligible' => false, 'start_consent_current' => $this->consentCurrent($order), 'local_invoice_created' => $invoiceId !== null, 'test_invoice_id' => $invoiceId, 'test_invoice_evidence' => $invoiceId ? 'SIMULATED_CANONICAL_LEDGER' : null, 'can_preview_test_invoice' => $canInvoice, 'real_payment_processed' => false, 'physical_charging_verified' => false, 'live_commands_enabled' => false];
    }
}

<?php

namespace App\Services\Ocpi;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\SessionQuote;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Separate explicit approved TEST billing consent. No network calls or payment collection. */
class TestInvoiceLineage
{
    public const TERMS = 'ocpi-simulated-invoice-v1';

    private function decimal(mixed $value, int $places): int
    {
        abort_unless(is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,6}))?$/D', $value, $m), 409, 'Unsupported exact source amount.');
        $fraction = rtrim($m[2] ?? '', '0');
        abort_unless(strlen($fraction) <= $places, 409, 'Source amount needs unsupported minor-unit rounding.');
        $scale = 10 ** $places;
        $major = (int) $m[1];
        abort_unless($major <= intdiv(2000000000, $scale), 409, 'Source amount exceeds the supported bound.');
        $amount = $major * $scale + (int) str_pad($fraction, $places, '0');
        abort_unless($amount <= 2000000000, 409);

        return $amount;
    }

    private function facts(User $actor, object $order): array
    {
        abort_unless(app()->environment(['local', 'testing']) && config('ocpi.test_billing_enabled') && $actor->role === 'customer' && $order->user_id === $actor->id, 409, 'Approved separate TEST billing is unavailable.');
        [$provider,$c,,$link] = app(SandboxRoaming::class)->current($order);
        $policy = $c['test_billing_contract'] ?? null;
        $keys = ['reference', 'revision', 'command_contract', 'company_id', 'country', 'currency', 'minor_units', 'rate_minor_per_kwh', 'tax_bps', 'commission_bps'];
        abort_unless(is_array($policy) && count($policy) === count($keys) && array_diff($keys, array_keys($policy)) === [] && is_string($policy['reference']) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $policy['reference']) && $policy['command_contract'] === $c['test_command_contract'] && $policy['country'] === $c['party_country'] && is_string($policy['currency']) && preg_match('/^[A-Z]{3}$/D', $policy['currency']), 409, 'An approved commercial TEST contract is required.');
        foreach (['revision' => [1, 2147483647], 'company_id' => [1, 2147483647], 'minor_units' => [0, 3], 'rate_minor_per_kwh' => [1, 1000000], 'tax_bps' => [0, 10000], 'commission_bps' => [0, 10000]] as $k => [$min,$max]) {
            abort_unless(is_int($policy[$k]) && $policy[$k] >= $min && $policy[$k] <= $max, 409, 'Invalid approved TEST pricing policy.');
        }
        $company = VendorCompany::findOrFail($policy['company_id']);
        abort_unless($company->owner_id === $provider->owner_id, 409, 'Approved merchant company changed.');
        app(VendorAccess::class)->operational($company);
        abort_unless($order->state === 'source_completed' && $order->source_status === 'COMPLETED' && $order->reconciliation_state === 'source_consistent' && $order->source_session_record_id && $order->source_cdr_record_id && $order->energy_wh > 0, 409, 'Observe a completed consistent owned source session and CDR first.');
        $session = OcpiRecord::findOrFail($order->source_session_record_id);
        $cdr = OcpiRecord::findOrFail($order->source_cdr_record_id);
        abort_unless($cdr->module === 'cdrs' && $cdr->ocpi_provider_id === $provider->id && hash_equals($order->source_session_hash, $session->content_hash) && hash_equals($order->source_cdr_hash, $cdr->content_hash) && app(SandboxRoaming::class)->ownsSourceSession($order, $session) && app(CdrReconciler::class)->inspect($provider, $cdr)['status'] === 'source_consistent', 409, 'Source lineage changed; reconcile before financial consent.');
        abort_unless(DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'START_SESSION')->whereNotNull('attempted_at')->whereNotIn('state', ['partner_rejected', 'not_dispatched'])->exists(), 409, 'Original customer-authorized source start is required.');
        $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $tariff = $target['tariff'];
        $elements = $tariff['elements'] ?? [];
        abort_unless($tariff['currency'] === $policy['currency'] && $cdr->normalized['currency'] === $policy['currency'] && count($elements) === 1 && count($elements[0]['price_components'] ?? []) === 1, 409, 'Only approved single energy pricing is supported.');
        $price = $elements[0]['price_components'][0];
        abort_unless($price['type'] === 'ENERGY' && $price['step_size'] === 1 && $this->decimal($price['price'], $policy['minor_units']) === $policy['rate_minor_per_kwh'] && $price['vat'] !== null && $this->decimal($price['vat'], 2) === $policy['tax_bps'], 409, 'Reported frozen tariff does not match the approved TEST contract.');
        $subtotal = Money::multiplyDivide((int) $order->energy_wh, $policy['rate_minor_per_kwh'], 1000);
        $tax = Money::multiplyDivide($subtotal, $policy['tax_bps'], 10000);
        $total = $subtotal + $tax;
        abort_unless($subtotal > 0 && $total <= 2000000000 && $this->decimal($cdr->normalized['cost']['excl_vat'] ?? null, $policy['minor_units']) === $subtotal, 409, 'Source cost does not exactly reconcile with approved TEST pricing.');
        if (isset($cdr->normalized['cost']['incl_vat'])) {
            abort_unless($this->decimal($cdr->normalized['cost']['incl_vat'], $policy['minor_units']) === $total, 409, 'Source gross cost requires review.');
        }
        $start = CarbonImmutable::parse($session->normalized['start_date_time']);
        $end = CarbonImmutable::parse($session->normalized['end_date_time']);
        abort_unless($end->gt($start) && $end->lte(now()->addSeconds(5)) && $start->diffInHours($end) <= 48, 409, 'Source transaction times require review.');
        $facts = ['provider_name' => $provider->name, 'location_name' => $target['location_name'], 'order_id' => $order->id, 'provider_id' => $provider->id, 'vendor_id' => $provider->owner_id, 'country' => $policy['country'], 'currency' => $policy['currency'], 'minor_units' => $policy['minor_units'], 'rate_minor_per_kwh' => $policy['rate_minor_per_kwh'], 'tax_bps' => $policy['tax_bps'], 'commission_bps' => $policy['commission_bps'], 'energy_wh' => (int) $order->energy_wh, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $total, 'started_at' => $start->toISOString(), 'stopped_at' => $end->toISOString(), 'policy_hash' => hash('sha256', json_encode([$policy, $company->updated_at?->toISOString()], JSON_THROW_ON_ERROR)), 'source_session_hash' => $session->content_hash, 'source_cdr_hash' => $cdr->content_hash, 'source_session_record_id' => $session->id, 'source_cdr_record_id' => $cdr->id, 'provider_revision' => $provider->authorization_revision, 'credential_generation' => $link->generation];

        return $facts;
    }

    private function digest(array $facts): string
    {
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    private function encode(array $body): string
    {
        abort_unless(strlen((string) config('app.key')) >= 32, 503, 'Application signing key is required.');
        $data = base64_encode(json_encode($body, JSON_THROW_ON_ERROR));

        return $data.'.'.hash_hmac('sha256', $data, (string) config('app.key'));
    }

    private function verify(string $token, User $actor, object $order, array $facts): void
    {
        abort_unless(strlen((string) config('app.key')) >= 32, 503, 'Application signing key is required.');
        $parts = explode('.', $token);
        abort_unless(count($parts) === 2 && hash_equals(hash_hmac('sha256', $parts[0], (string) config('app.key')), $parts[1]), 409, 'Refresh the signed TEST invoice preview.');
        $decoded = base64_decode($parts[0], true);
        abort_unless($decoded !== false, 409);
        try {
            $body = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409);
        }
        abort_unless(is_array($body) && ($body['actor'] ?? null) === $actor->id && ($body['order'] ?? null) === $order->id && is_int($body['expires'] ?? null) && $body['expires'] >= now()->timestamp && $body['expires'] <= now()->addMinutes(5)->timestamp && hash_equals($this->digest($facts), $body['facts'] ?? ''), 409, 'Source, policy or preview expiry changed; refresh before consent.');
    }

    public function preview(User $actor, object $order): array
    {
        $facts = $this->facts(User::findOrFail($actor->id), $order);
        abort_if(DB::table('ocpi_test_invoice_receipts')->where('order_id', $order->id)->exists(), 409, 'A canonical TEST invoice already exists.');
        $expires = now()->addMinutes(5)->timestamp;

        return array_intersect_key($facts, array_flip(['provider_name', 'location_name', 'currency', 'minor_units', 'rate_minor_per_kwh', 'tax_bps', 'energy_wh', 'subtotal_minor', 'tax_minor', 'total_minor'])) + ['preview_token' => $this->encode(['actor' => $actor->id, 'order' => $order->id, 'facts' => $this->digest($facts), 'expires' => $expires]), 'expires_at' => $expires, 'terms_version' => self::TERMS, 'evidence' => 'SIMULATED', 'physical_charging_verified' => false, 'real_payment_processed' => false, 'automatic_charge' => false];
    }

    public function issue(User $actor, object $order, array $v): Invoice
    {
        $hash = hash('sha256', json_encode([$order->id, $v], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $order, $v, $hash) {
            $provider = OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $users = User::whereIn('id', [$actor->id, $provider->owner_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $users->get($actor->id);
            abort_unless($user, 404);
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $user->id, 404);
            $old = DB::table('ocpi_test_invoice_receipts')->where('actor_id', $user->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless($old->order_id === $row->id && hash_equals($old->intent_hash, $hash), 409, 'TEST billing request identity conflicts.');

                return Invoice::findOrFail($old->invoice_id);
            }
            abort_if(DB::table('ocpi_test_invoice_cancellations')->where('actor_id', $user->id)->where('request_key', $v['request_key'])->exists(), 409, 'This saved TEST invoice request was cancelled; prepare new consent.');
            abort_if(DB::table('ocpi_test_invoice_receipts')->where('order_id', $row->id)->exists(), 409, 'Review the existing canonical TEST invoice.');
            [$provider,$c] = app(SandboxRoaming::class)->current($row);
            if (is_array($c['test_billing_contract'] ?? null)) {
                VendorCompany::whereKey($c['test_billing_contract']['company_id'] ?? 0)->lockForUpdate()->firstOrFail();
            }
            $facts = $this->facts($user, $row);
            $this->verify($v['preview_token'], $user, $row, $facts);
            abort_unless($v['expected_total_minor'] === $facts['total_minor'] && $v['expected_currency'] === $facts['currency'] && $v['expected_minor_units'] === $facts['minor_units'] && $v['expected_energy_wh'] === $facts['energy_wh'], 409, 'The displayed TEST invoice amounts do not match current source facts.');
            $session = ChargingSession::create(['reservation_id' => null, 'roaming_order_id' => $row->id, 'status' => 'stopped', 'energy_wh' => $facts['energy_wh'], 'rate_minor_per_kwh' => $facts['rate_minor_per_kwh'], 'currency' => $facts['currency'], 'total_minor' => $facts['subtotal_minor'], 'stopped_at' => CarbonImmutable::parse($facts['stopped_at']), 'created_at' => CarbonImmutable::parse($facts['started_at'])]);
            SessionQuote::create(['charging_session_id' => $session->id, 'tariff_version_id' => null, 'user_id' => $user->id, 'vendor_id' => $facts['vendor_id'], 'country' => $facts['country'], 'currency' => $facts['currency'], 'minor_units' => $facts['minor_units'], 'rate_minor_per_kwh' => $facts['rate_minor_per_kwh'], 'tax_bps' => $facts['tax_bps'], 'commission_bps' => $facts['commission_bps'], 'evidence' => 'SIMULATED']);
            $invoice = app(FinanceService::class)->issue($session, $user->id);
            abort_unless($invoice->total_minor === $facts['total_minor'], 409, 'Canonical invoice does not match the consented preview.');
            DB::table('ocpi_test_invoice_receipts')->insert(['order_id' => $row->id, 'charging_session_id' => $session->id, 'invoice_id' => $invoice->id, 'actor_id' => $user->id, 'request_key' => $v['request_key'], 'intent_hash' => $hash, 'source_session_record_id' => $facts['source_session_record_id'], 'source_cdr_record_id' => $facts['source_cdr_record_id'], 'source_session_hash' => $facts['source_session_hash'], 'source_cdr_hash' => $facts['source_cdr_hash'], 'policy_hash' => $facts['policy_hash'], 'snapshot_ciphertext' => Crypt::encryptString(json_encode($facts, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'ocpi.explicit_test_invoice_consented', 'subject' => (string) $row->id, 'created_at' => now()]);

            return $invoice;
        }, 3);
    }

    /** Serialized resolution can revoke an unissued identity before a delayed POST.
     * It never deletes, refunds, pays or edits an existing invoice. */
    public function resolve(User $actor, object $order, array $v): array
    {
        $hash = hash('sha256', json_encode([$order->id, $actor->id, $v['request_key'], 'cancel_unissued'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $order, $v, $hash) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $user->id, 404);
            $identity = DB::table('ocpi_test_invoice_receipts')->where('actor_id', $user->id)->where('request_key', $v['request_key'])->first();
            abort_if($identity && $identity->order_id !== $row->id, 409, 'Saved request identity belongs to another order.');
            $receipt = DB::table('ocpi_test_invoice_receipts')->where('order_id', $row->id)->first();
            if ($receipt) {

                return ['state' => 'issued', 'order_id' => $row->id, 'invoice_id' => $receipt->invoice_id, 'real_payment_processed' => false, 'financial_records_changed' => false];
            }
            $old = DB::table('ocpi_test_invoice_cancellations')->where('actor_id', $user->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless($old->order_id === $row->id && hash_equals($old->intent_hash, $hash), 409);
            } else {
                abort_if(DB::table('ocpi_test_invoice_cancellations')->where('actor_id', $user->id)->where('created_at', '>=', now()->subDay())->count() >= 30, 429, 'Saved request resolution limit reached.');
                DB::table('ocpi_test_invoice_cancellations')->insert(['order_id' => $row->id, 'actor_id' => $user->id, 'request_key' => $v['request_key'], 'intent_hash' => $hash, 'created_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'ocpi.unissued_test_invoice_intent_cancelled', 'subject' => (string) $row->id, 'created_at' => now()]);
            }

            return ['state' => 'cancelled_unissued', 'order_id' => $row->id, 'invoice_id' => null, 'real_payment_processed' => false, 'financial_records_changed' => false];
        }, 3);
    }

    public function available(User $actor, object $order): bool
    {
        if (! config('ocpi.test_billing_enabled')) {
            return false;
        }
        try {
            $this->facts($actor, $order);

            return ! DB::table('ocpi_test_invoice_receipts')->where('order_id', $order->id)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

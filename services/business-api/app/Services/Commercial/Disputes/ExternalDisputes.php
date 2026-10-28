<?php

namespace App\Services\Commercial\Disputes;

use App\Models\Finance\Invoice;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ExternalDisputes
{
    public function __construct(private StripeTestDisputes $provider) {}

    public function webhook(string $raw, string $signature): array
    {
        $event = app(StripeSandboxProvider::class)->verifyWebhook($raw, $signature);
        abort_unless(in_array($event['type'] ?? '', ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'], true), 422, 'Unsupported dispute event.');
        $object = $event['data']['object'] ?? [];
        abort_unless(is_array($object) && is_string($object['id'] ?? null), 422);
        $fingerprint = hash('sha256', $raw);
        if ($old = DB::table('external_dispute_events')->where('event_reference', $event['id'])->first()) {
            abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Provider event payload changed.');

            return ['duplicate' => true, 'dispute_id' => $old->dispute_id];
        }
        // Provider GET is authoritative even when callbacks arrive out of order.
        $current = $this->provider->dispute($object['id']);
        $record = $this->apply($current, $event['id'], $fingerprint, $event['type']);

        return ['duplicate' => false, 'dispute_id' => $record->id];
    }

    public function reconcile(int $id): object
    {
        $old = DB::table('external_disputes')->find($id);
        abort_unless($old, 404);
        $body = $this->provider->dispute($old->provider_reference);
        $row = $this->apply($body, 'inspect:'.$id.':'.hash('sha256', json_encode($body)), hash('sha256', json_encode($body)), 'provider.inspect');
        $this->recoverEvidence($id, $body);
        DB::table('external_disputes')->where('id', $id)->update(['provider_observed_at' => now(), 'updated_at' => now()]);

        return $row;
    }

    public function scan(int $limit): array
    {
        abort_unless($limit >= 1 && $limit <= 100, 422);
        abort_if(app()->environment('production'), 503, 'Provider dispute scanning supports TEST only.');
        $counts = ['inspected' => 0, 'attention' => 0, 'failed' => 0];
        $ids = DB::table('external_disputes')->where('settlement_hold', true)->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $row = $this->reconcile((int) $id);
                $counts['inspected']++;
                if ($row->status === 'lost' || ($row->evidence_due_at && Carbon::parse($row->evidence_due_at)->isPast() && in_array($row->status, ['needs_response', 'warning_needs_response'], true))) {
                    $counts['attention']++;
                }
            } catch (\Throwable $error) {
                DB::table('external_disputes')->where('id', $id)->update(['updated_at' => now()]);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    public function apply(array $body, string $event, string $hash, string $type): object
    {
        abort_unless(($body['livemode'] ?? null) === false && preg_match('/^dp_[A-Za-z0-9]+$/D', $body['id'] ?? '') && preg_match('/^ch_[A-Za-z0-9]+$/D', $body['charge'] ?? '') && preg_match('/^pi_[A-Za-z0-9]+$/D', $body['payment_intent'] ?? ''), 422, 'Invalid provider dispute identity.');
        $status = $body['status'] ?? '';
        abort_unless(in_array($status, ['warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost'], true), 422, 'Unknown provider dispute state.');
        $charge = $this->provider->request('/charges/'.$body['charge']);
        abort_unless(($charge['id'] ?? '') === $body['charge'] && ($charge['payment_intent'] ?? '') === $body['payment_intent'] && ($charge['paid'] ?? false) === true && ($charge['captured'] ?? false) === true, 409, 'Disputed charge does not prove a captured payment.');
        $checkout = DB::table('gateway_checkouts')->where('provider', 'stripe_test')->where('provider_reference', $body['payment_intent'])->first();
        abort_unless($checkout && $checkout->status === 'captured' && strtoupper($body['currency'] ?? '') === $checkout->currency && strtoupper($charge['currency'] ?? '') === $checkout->currency && (int) ($charge['amount_captured'] ?? -1) === (int) $checkout->amount_minor && is_int($body['amount'] ?? null) && $body['amount'] > 0 && $body['amount'] <= (int) $checkout->amount_minor, 409, 'Dispute amount, currency or captured checkout mismatch.');

        return DB::transaction(function () use ($body, $event, $hash, $type, $status, $checkout) {
            $invoice = Invoice::whereKey($checkout->invoice_id)->lockForUpdate()->firstOrFail();
            if ($prior = DB::table('external_dispute_events')->where('event_reference', $event)->first()) {
                abort_unless(hash_equals($prior->fingerprint, $hash), 409);

                return DB::table('external_disputes')->find($prior->dispute_id);
            }
            $row = DB::table('external_disputes')->where('provider_reference', $body['id'])->lockForUpdate()->first();
            $hold = ! in_array($status, ['won', 'warning_closed'], true);
            $due = $body['evidence_details']['due_by'] ?? null;
            abort_unless($due === null || (is_int($due) && $due > 0 && $due < 4102444800), 422, 'Invalid evidence deadline.');
            $values = ['status' => $status, 'reason' => substr((string) ($body['reason'] ?? 'unknown'), 0, 100), 'evidence_due_at' => $due ? Carbon::createFromTimestampUTC($due) : null, 'settlement_hold' => $hold, 'provider_observed_at' => now(), 'updated_at' => now()];
            if ($row) {
                abort_unless((int) $row->checkout_id === (int) $checkout->id && $row->charge_reference === $body['charge'] && (int) $row->amount_minor === $body['amount'] && $row->currency === $checkout->currency, 409, 'Historical dispute economics changed.');
                DB::table('external_disputes')->where('id', $row->id)->update($values);
                $id = $row->id;
                $case = $row->case_id;
            } else {
                $key = hash('sha256', 'external:stripe_test:'.$body['id']);
                $case = DB::table('gateway_cases')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'kind' => 'dispute', 'request_key' => $key, 'fingerprint' => $hash, 'amount_minor' => $body['amount'], 'reason' => 'Provider TEST dispute: '.substr((string) ($body['reason'] ?? 'unknown'), 0, 100), 'status' => $hold ? 'open' : 'rejected', 'created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('external_disputes')->insertGetId($values + ['checkout_id' => $checkout->id, 'case_id' => $case, 'provider_reference' => $body['id'], 'charge_reference' => $body['charge'], 'currency' => $checkout->currency, 'amount_minor' => $body['amount'], 'created_at' => now()]);
            }
            // Lost disputes stay held for explicit canonical financial resolution;
            // never manufacture refunds or silently release a vendor payable.
            DB::table('gateway_cases')->where('id', $case)->update(['status' => $hold ? 'open' : 'rejected', 'resolution' => 'Authoritative provider TEST status: '.$status, 'updated_at' => now()]);
            DB::table('external_dispute_events')->insert(['dispute_id' => $id, 'event_reference' => $event, 'fingerprint' => $hash, 'type' => $type, 'status' => $status, 'created_at' => now()]);

            return DB::table('external_disputes')->find($id);
        }, 3);
    }

    private function recoverEvidence(int $id, array $body): void
    {
        $providerEvidence = $body['evidence'] ?? [];
        if (! is_array($providerEvidence)) {
            return;
        }
        foreach (DB::table('external_dispute_evidence')->where('dispute_id', $id)->whereIn('status', ['uncertain', 'sending'])->get() as $operation) {
            if ($operation->status === 'sending' && $operation->lease_until && Carbon::parse($operation->lease_until)->isFuture()) {
                continue;
            }
            $expected = json_decode(Crypt::decryptString($operation->encrypted_payload), true, 16, JSON_THROW_ON_ERROR);
            $matches = true;
            foreach ($expected as $key => $value) {
                $matches = $matches && ($providerEvidence[$key] ?? null) === $value;
            }
            if ($operation->submit) {
                $matches = $matches && (int) ($body['evidence_details']['submission_count'] ?? 0) > 0 && ! in_array($body['status'] ?? '', ['needs_response', 'warning_needs_response'], true);
            }
            if ($matches) {
                DB::table('external_dispute_evidence')->where('id', $operation->id)->whereIn('status', ['uncertain', 'sending'])->update(['status' => 'confirmed', 'lease_until' => null, 'updated_at' => now()]);
            }
        }
    }

    public function evidence(int $id, int $actor, array $evidence, bool $submit, string $key): object
    {
        abort_unless(DB::table('users')->where('id', $actor)->where('role', 'admin')->exists(), 403);
        abort_unless($evidence !== [] && count(array_diff(array_keys($evidence), ['product_description', 'service_date', 'refund_policy_disclosure', 'refund_refusal_explanation', 'access_activity_log', 'uncategorized_text'])) === 0, 422);
        foreach ($evidence as $value) {
            abort_unless(is_string($value) && strlen($value) > 0 && strlen($value) <= 5000, 422);
        }
        ksort($evidence);
        $requestKey = hash('sha256', $actor.':'.$key);
        $hash = hash('sha256', json_encode([$id, $evidence, $submit], JSON_THROW_ON_ERROR));
        if ($old = DB::table('external_dispute_evidence')->where('request_key', $requestKey)->first()) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Evidence key payload changed.');
            if ($old->status === 'confirmed') {
                return $old;
            }
        }
        // Establish the current provider deadline and recover accepted evidence
        // before deciding whether another request with the SAME key is safe.
        $this->reconcile($id);
        $operation = DB::transaction(function () use ($id, $actor, $evidence, $submit, $requestKey, $hash) {
            $dispute = DB::table('external_disputes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($dispute, 404);
            $old = DB::table('external_dispute_evidence')->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Evidence key payload changed.');
                if ($old->status === 'confirmed') {
                    return $old;
                }
                abort_unless(Carbon::parse($old->retry_until)->isFuture() && (! $old->lease_until || Carbon::parse($old->lease_until)->isPast()), 409, 'Evidence submission is leased or outside provider retry retention; reconcile instead.');
                abort_unless(in_array($dispute->status, ['needs_response', 'warning_needs_response'], true) && $dispute->evidence_due_at && Carbon::parse($dispute->evidence_due_at)->isFuture(), 409, 'Provider evidence deadline or submission state does not permit a retry.');
                $oid = $old->id;
            } else {
                abort_if(DB::table('external_dispute_evidence')->where('dispute_id', $id)->whereIn('status', ['sending', 'uncertain'])->exists(), 409, 'Reconcile existing uncertain evidence submission.');
                abort_unless(in_array($dispute->status, ['needs_response', 'warning_needs_response'], true) && $dispute->evidence_due_at && Carbon::parse($dispute->evidence_due_at)->isFuture(), 409, 'Evidence deadline passed or provider does not accept evidence.');
                $oid = DB::table('external_dispute_evidence')->insertGetId(['dispute_id' => $id, 'actor_id' => $actor, 'request_key' => $requestKey, 'fingerprint' => $hash, 'encrypted_payload' => Crypt::encryptString(json_encode($evidence, JSON_THROW_ON_ERROR)), 'submit' => $submit, 'status' => 'sending', 'retry_until' => now()->addHours(23), 'lease_until' => now()->addMinutes(3), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('external_dispute_evidence')->where('id', $oid)->update(['status' => 'sending', 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);

            return DB::table('external_dispute_evidence')->find($oid);
        }, 3);

        return $this->sendEvidence($operation);
    }

    public function retryEvidence(int $id, int $operationId, int $actor): object
    {
        abort_unless(DB::table('users')->where('id', $actor)->where('role', 'admin')->exists(), 403);
        $initial = DB::table('external_dispute_evidence')->where('id', $operationId)->where('dispute_id', $id)->first();
        abort_unless($initial, 404);
        if ($initial->status === 'confirmed') {
            return $initial;
        }
        // Explicit retry always inspects the original provider object first.
        $this->reconcile($id);
        $operation = DB::transaction(function () use ($id, $operationId, $actor) {
            $dispute = DB::table('external_disputes')->where('id', $id)->lockForUpdate()->first();
            $operation = DB::table('external_dispute_evidence')->where('id', $operationId)->where('dispute_id', $id)->lockForUpdate()->first();
            abort_unless($dispute && $operation, 404);
            if ($operation->status === 'confirmed') {
                return $operation;
            }
            abort_unless(in_array($operation->status, ['sending', 'uncertain'], true) && Carbon::parse($operation->retry_until)->isFuture() && (! $operation->lease_until || Carbon::parse($operation->lease_until)->isPast()), 409, 'Evidence is leased or past safe retry retention; inspect/investigate instead.');
            abort_unless(in_array($dispute->status, ['needs_response', 'warning_needs_response'], true) && $dispute->evidence_due_at && Carbon::parse($dispute->evidence_due_at)->isFuture(), 409, 'Provider evidence deadline or state does not permit retry.');
            DB::table('external_dispute_evidence')->where('id', $operationId)->update(['status' => 'sending', 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'finance.dispute.evidence_retry', 'subject' => (string) $operationId, 'created_at' => now()]);

            return DB::table('external_dispute_evidence')->find($operationId);
        }, 3);

        return $this->sendEvidence($operation);
    }

    private function sendEvidence(object $operation): object
    {
        if ($operation->status === 'confirmed') {
            return $operation;
        }
        $evidence = json_decode(Crypt::decryptString($operation->encrypted_payload), true, 16, JSON_THROW_ON_ERROR);
        $submit = (bool) $operation->submit;
        $requestKey = $operation->request_key;
        $dispute = DB::table('external_disputes')->find($operation->dispute_id);
        try {
            $body = $this->provider->request('/disputes/'.$dispute->provider_reference, ['evidence' => $evidence, 'submit' => $submit ? 'true' : 'false'], $requestKey);
            abort_unless(($body['id'] ?? null) === $dispute->provider_reference, 502);
            foreach ($evidence as $field => $value) {
                abort_unless(($body['evidence'][$field] ?? null) === $value, 502, 'Provider did not confirm submitted evidence.');
            }
            if ($submit) {
                abort_unless((int) ($body['evidence_details']['submission_count'] ?? 0) > 0 && ! in_array($body['status'] ?? '', ['needs_response', 'warning_needs_response'], true), 502, 'Provider has not confirmed final evidence submission.');
            }
            $this->apply($body, 'evidence:'.$operation->id, hash('sha256', json_encode($body)), 'evidence.confirmed');
            DB::table('external_dispute_evidence')->where('id', $operation->id)->update(['status' => 'confirmed', 'lease_until' => null, 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('external_dispute_evidence')->where('id', $operation->id)->update(['status' => 'uncertain', 'lease_until' => null, 'updated_at' => now()]);
            throw $error;
        }

        return DB::table('external_dispute_evidence')->find($operation->id);
    }
}

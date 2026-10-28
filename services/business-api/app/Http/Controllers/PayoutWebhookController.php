<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Payouts\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutWebhookController extends Controller
{
    public function stripe(Request $r, PayoutService $service)
    {
        $service->enabled();
        $secret = config('payouts.stripe_webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'Configure the payout TEST webhook signing secret.');
        $raw = $r->getContent();
        abort_if(strlen($raw) > 262144, 413);
        $signature = $r->header('Stripe-Signature', '');
        $fields = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $fields[$pair[0]][] = $pair[1];
            }
        }
        $timestamp = $fields['t'][0] ?? '';
        abort_unless(count($fields['t'] ?? []) === 1 && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401, 'Invalid payout webhook timestamp.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        abort_unless(count(array_filter($fields['v1'] ?? [], fn ($v) => hash_equals($expected, $v))) > 0, 401, 'Invalid payout webhook signature.');
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            abort(422, 'Invalid payout webhook JSON.');
        }
        abort_unless(is_array($body) && ($body['livemode'] ?? null) === false && is_string($body['id'] ?? null) && preg_match('/^evt_[A-Za-z0-9]+$/D', $body['id'] ?? '') && in_array($body['type'] ?? '', ['payout.created', 'payout.updated', 'payout.paid', 'payout.failed', 'payout.canceled'], true), 422);
        $object = $body['data']['object'] ?? [];
        abort_unless(is_array($object) && is_array($object['metadata'] ?? null), 422, 'Invalid payout webhook object.');
        $attempt = DB::table('commercial_payout_attempts')->where('reference', $object['metadata']['creabiz_payout_attempt'] ?? '')->first();
        abort_unless($attempt, 404);
        $payout = DB::table('commercial_payouts')->find($attempt->payout_id);
        $account = $service->account((int) $payout->account_id);
        abort_unless($payout->provider === 'stripe_test' && ($body['account'] ?? null) === $account->account_reference && ($object['object'] ?? null) === 'payout' && ($object['livemode'] ?? null) === false && is_string($object['id'] ?? null) && preg_match('/^po_[A-Za-z0-9]+$/D', $object['id'] ?? '') && ($object['amount'] ?? null) === (int) $payout->amount_minor && strtoupper($object['currency'] ?? '') === $payout->currency && ($object['destination'] ?? null) === $account->bank_reference && ($object['metadata']['creabiz_settlement'] ?? null) === (string) $payout->settlement_id && (! $attempt->provider_reference || $attempt->provider_reference === $object['id']), 409, 'Payout webhook scope mismatch.');
        $fingerprint = hash('sha256', $raw);
        $old = DB::transaction(function () use ($body, $attempt, $fingerprint, $payout) {
            // Parent payout row serializes event insertion across simultaneous duplicates.
            DB::table('commercial_payouts')->where('id', $payout->id)->lockForUpdate()->first();
            $old = DB::table('commercial_payout_webhooks')->where('provider_event', $body['id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Payout webhook event changed.');

                return $old;
            }
            $id = DB::table('commercial_payout_webhooks')->insertGetId(['provider_event' => $body['id'], 'fingerprint' => $fingerprint, 'attempt_id' => $attempt->id, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('commercial_payout_webhooks')->find($id);
        }, 3);
        if ($old->status === 'processed') {
            return response()->json(['accepted' => true, 'duplicate' => true, 'real_money_moved' => false]);
        }
        // Signed notifications trigger authoritative retrieval; stale notifications cannot reverse a newer fact.
        $attempt->provider_reference = $object['id'];
        $result = $service->provider('stripe_test')->retrieve($payout, $account, $attempt);
        abort_unless($result, 409);
        DB::transaction(function () use ($service, $payout, $attempt, $result, $old) {
            $service->observe($payout, $attempt, $result, null, true);
            DB::table('commercial_payout_webhooks')->where('id', $old->id)->update(['status' => 'processed', 'updated_at' => now()]);
        }, 3);

        return response()->json(['accepted' => true, 'real_money_moved' => false]);
    }
}

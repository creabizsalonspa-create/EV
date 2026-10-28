<?php

use App\Services\Commercial\Disputes\ExternalDisputes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// The included fixture checks local/testing SQLite and unique synthetic identities.
// HTTP contracts are faked only within this CLI process; server routes gain no bypass.
ob_start();
require __DIR__.'/invoice-history.php';
ob_end_clean();
config(['payments.stripe_test_secret' => 'sk_test_contractonly']);
Http::preventStrayRequests();
$payment = 'pi_Dispute'.$run;
$charge = 'ch_Dispute'.$run;
$checkout = DB::table('gateway_checkouts')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $users['customer']->id, 'provider' => 'stripe_test', 'provider_reference' => $payment, 'request_key' => hash('sha256', 'dispute-browser:'.$run), 'fingerprint' => hash('sha256', 'dispute-browser:'.$run), 'currency' => $invoice->currency, 'amount_minor' => $invoice->total_minor, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
Http::fake(['api.stripe.com/v1/charges/'.$charge => Http::response(['id' => $charge, 'livemode' => false, 'payment_intent' => $payment, 'paid' => true, 'captured' => true, 'amount_captured' => (int) $invoice->total_minor, 'currency' => strtolower($invoice->currency)])]);
$ids = [];
foreach (['needs_response', 'lost', 'won'] as $index => $status) {
    $body = ['id' => 'dp_Dispute'.$run.$index, 'livemode' => false, 'charge' => $charge, 'payment_intent' => $payment, 'amount' => 300, 'currency' => strtolower($invoice->currency), 'status' => $status, 'reason' => 'fraudulent', 'evidence_details' => ['due_by' => now()->addDays(2)->timestamp], 'evidence' => []];
    $row = app(ExternalDisputes::class)->apply($body, 'fixture:'.$run.':'.$index, hash('sha256', json_encode($body)), 'provider.fixture');
    $ids[$status] = $row->id;
}
echo json_encode(['fixture_only' => true, 'provider_evidence' => 'HTTP_FAKE_ONLY', 'ids' => $ids, 'invoice' => $invoice->id]);

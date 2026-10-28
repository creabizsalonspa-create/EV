<?php

use App\Models\Finance\Invoice;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Commercial\Payments\PaymentRecovery;
use Illuminate\Support\Facades\DB;

// The shared fixture enforces local SQLite, creates only synthetic identities
// and posts immutable simulated invoice journals through the canonical service.
ob_start();
require __DIR__.'/invoice-history.php';
ob_end_clean();
config(['payments.provider' => 'stripe_test']);
$invoice = Invoice::where('user_id', $users['customer']->id)->orderBy('id')->firstOrFail();
$checkout = app(GatewayService::class)->checkout($invoice, $users['customer']->id, 100, 'native-recovery');
try {
    app(PaymentRecovery::class)->run('charging', $checkout, $users['customer']->id, 'authorize', 'native-recovery', ['authorize', 100, null], function () {
        PaymentRecovery::sent();
        throw new RuntimeException('Synthetic lost provider response; no external request sent');
    });
    throw new RuntimeException('Expected synthetic uncertainty');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Synthetic lost provider response; no external request sent') {
        throw $e;
    }
}
$intent = DB::table('payment_recovery_intents')->where('checkout_id', $checkout->id)->where('scope', 'charging')->first();
echo json_encode(['intent' => $intent->id, 'invoice' => $invoice->id, 'fixture_only' => true]);

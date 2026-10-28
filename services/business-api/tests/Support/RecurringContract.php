<?php

// Synthetic provider fixtures only. Never loaded by application services.

namespace Tests\Support;

use App\Models\User;
use App\Services\Commercial\PaymentMandates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RecurringContract
{
    public static function mandate(User $user): int
    {
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.hosted_return_url' => 'https://example.test/payments', 'payments.collection_enabled' => true]);
        $ref = null;
        $suffix = (string) $user->id;
        Http::preventStrayRequests();
        Http::fake(function ($r) use (&$ref, $suffix) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/customers') {
                return Http::response(['id' => 'cus_Contract'.$suffix, 'livemode' => false, 'metadata' => $r['metadata']]);
            }
            if ($path === '/v1/checkout/sessions') {
                $ref = $r['client_reference_id'];
            }
            if (str_starts_with($path, '/v1/checkout/sessions')) {
                return Http::response(['id' => 'cs_test_Contract'.$suffix, 'livemode' => false, 'mode' => 'setup', 'customer' => 'cus_Contract'.$suffix, 'client_reference_id' => $ref, 'metadata' => ['creabiz_enrollment' => $ref], 'url' => 'https://checkout.stripe.com/c/pay/test', 'status' => $r->method() === 'GET' ? 'complete' : 'open', 'setup_intent' => ['id' => 'seti_Contract'.$suffix, 'livemode' => false, 'customer' => 'cus_Contract'.$suffix, 'status' => 'succeeded', 'usage' => 'off_session', 'metadata' => ['creabiz_enrollment' => $ref], 'payment_method' => 'pm_Contract'.$suffix]]);
            }
            if ($path === '/v1/payment_methods/pm_Contract'.$suffix) {
                return Http::response(['id' => 'pm_Contract'.$suffix, 'livemode' => false, 'customer' => 'cus_Contract'.$suffix, 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => now()->year + 3]]);
            }
            throw new \LogicException('Unrecognized synthetic enrollment request.');
        });
        $m = app(PaymentMandates::class);
        $e = $m->begin($user, 'contract-enrollment', 'recurring-v1');
        $m->inspect($user, $e['id']);

        return DB::table('payment_mandates')->where('user_id', $user->id)->value('id');
    }
}

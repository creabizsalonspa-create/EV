<?php

namespace App\Http\Controllers;

use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionCreditController extends Controller
{
    public function index(Request $r, int $subscription, SubscriptionService $service)
    {
        $service->access($r->user(), $subscription);

        return DB::table('subscription_credits')->where('subscription_id', $subscription)->orderByDesc('id')->paginate(25);
    }

    public function refund(Request $r, int $subscription, SubscriptionCreditService $service)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'amount_minor' => 'required|integer|between:1,2000000000', 'reason' => 'required|string|max:500', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($service->refund($r->user(), $subscription, (int) $v['invoice_id'], (int) $v['amount_minor'], $v['reason'], $v['idempotency_key']), 201);
    }

    public function show(Request $r, int $credit, SubscriptionCreditService $service)
    {
        return response()->json($service->detail($r->user(), $credit));
    }

    public function stripeWebhook(Request $r, SubscriptionCreditService $service)
    {
        return response()->json($service->stripeWebhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}

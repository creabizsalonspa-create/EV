<?php

namespace App\Http\Controllers;

use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionGatewayController extends Controller
{
    public function index(Request $r)
    {
        return DB::table('subscription_gateway_checkouts')->when($r->user()->role !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('id')->paginate(25);
    }

    public function checkout(Request $r, int $subscription, SubscriptionService $subscriptions, SubscriptionGateway $gateway)
    {
        $subscriptions->access($r->user(), $subscription);
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($gateway->checkout($r->user(), $subscription, (int) $v['invoice_id'], $v['idempotency_key']), 201);
    }

    public function operate(Request $r, int $checkout, string $operation, SubscriptionGateway $gateway)
    {
        $gateway->access($r->user(), $checkout);
        $v = $r->validate(['card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited', 'idempotency_key' => 'required|string|max:100', 'token' => [Rule::requiredIf($operation === 'authorize'), 'sometimes', 'string', 'max:120']]);

        return response()->json($gateway->operate($r->user(), $checkout, $operation, $v['idempotency_key'], $v['token'] ?? null));
    }

    public function reconciliation(Request $r, int $checkout, SubscriptionGateway $gateway)
    {
        return response()->json($gateway->reconcile($r->user(), $checkout));
    }

    public function stripeWebhook(Request $r, SubscriptionGateway $gateway)
    {
        return response()->json($gateway->stripeWebhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}

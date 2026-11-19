<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionGatewayController extends Controller
{
    public function index(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(DB::table('subscription_gateway_checkouts')
                ->when($actor->role !== 'admin', fn ($q) => $q->where('user_id', $actor->id))
                ->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function checkout(Request $r, int $subscription, SubscriptionService $subscriptions, SubscriptionGateway $gateway)
    {
        $actor = DB::transaction(function () use ($r, $subscription, $subscriptions) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            $subscriptions->access($current, $subscription);

            return $current;
        }, 3);
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($gateway->checkout($actor, $subscription,
            (int) $v['invoice_id'], $v['idempotency_key']), 201);
    }

    public function operate(Request $r, int $checkout, string $operation, SubscriptionGateway $gateway)
    {
        $actor = DB::transaction(function () use ($r, $checkout, $gateway) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            $gateway->access($current, $checkout);

            return $current;
        }, 3);
        $v = $r->validate(['card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited', 'idempotency_key' => 'required|string|max:100', 'token' => [Rule::requiredIf($operation === 'authorize'), 'sometimes', 'string', 'max:120']]);

        return response()->json($gateway->operate($actor, $checkout,
            $operation, $v['idempotency_key'], $v['token'] ?? null));
    }

    public function reconciliation(Request $r, int $checkout, SubscriptionGateway $gateway)
    {
        $actor = DB::transaction(function () use ($r, $checkout, $gateway) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            $gateway->access($current, $checkout);

            return $current;
        }, 3);

        // Provider observation remains owned by PaymentRecovery and must not
        // run under a controller-level database transaction.
        return response()->json($gateway->reconcile($actor, $checkout))
            ->header('Cache-Control', 'private, no-store');
    }

    public function stripeWebhook(Request $r, SubscriptionGateway $gateway)
    {
        return response()->json($gateway->stripeWebhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}

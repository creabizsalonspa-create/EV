<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionCreditController extends Controller
{
    public function index(Request $r, int $subscription, SubscriptionService $service)
    {
        return DB::transaction(function () use ($r, $subscription, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $service->access($actor, $subscription);
            $page = $r->validate(['page' => 'sometimes|integer|between:1,100000']);

            return response()->json(DB::table('subscription_credits')
                ->where('subscription_id', $subscription)->orderByDesc('id')
                ->paginate(25, ['*'], 'page', (int) ($page['page'] ?? 1)))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function refund(Request $r, int $subscription, SubscriptionCreditService $service, SubscriptionService $subscriptions)
    {
        abort_unless($r->user()->role === 'admin', 403);

        // The private subscription is not disclosed through different
        // validation errors to former Admin credentials or unrelated users.
        // Do not hold a controller transaction across provider I/O:
        // the service independently rechecks the same snapshot at both
        // durable transaction boundaries before test-provider refund.
        $actor = DB::transaction(function () use ($r, $subscription, $subscriptions) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($current->role === 'admin', 403);
            $subscriptions->access($current, $subscription, true);

            return $current;
        }, 3);
        $v = $r->validate([
            'invoice_id' => 'required|integer|min:1',
            'amount_minor' => 'required|integer|between:1,2000000000',
            'reason' => 'required|string|max:500',
            'idempotency_key' => 'required|string|max:100',
        ]);

        return response()->json($service->refund($actor, $subscription,
            (int) $v['invoice_id'], (int) $v['amount_minor'],
            $v['reason'], $v['idempotency_key']), 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, int $credit, SubscriptionCreditService $service)
    {
        return DB::transaction(function () use ($r, $credit, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json($service->detail($actor, $credit))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function stripeWebhook(Request $r, SubscriptionCreditService $service)
    {
        return response()->json($service->stripeWebhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}

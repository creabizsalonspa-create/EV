<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\PaymentMandates;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use App\Services\Commercial\SubscriptionCollections;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringPaymentController extends Controller
{
    public function methods(Request $r)
    {
        $a = DB::table('payment_customer_accounts')->where('user_id', $r->user()->id)->first();
        $rows = DB::table('payment_mandates')->where('user_id', $r->user()->id)->select(['id', 'brand', 'last_four', 'exp_month', 'exp_year', 'status', 'version', 'detach_status', 'consent_version', 'consented_at', 'consent_expires_at', 'revoked_at'])->orderByDesc('id')->paginate(25)->through(fn ($m) => (array) $m + ['usable' => app(PaymentMandates::class)->usable($m)]);

        return response()->json(['methods' => $rows, 'default_method_id' => $a?->default_method_id, 'version' => $a?->version ?? 0, 'consent_version' => config('payments.mandate_consent_version'), 'hosted_available' => ! app()->environment('production') && config('payments.provider') === 'stripe_test' && (bool) config('payments.stripe_test_secret') && (bool) config('payments.hosted_return_url'), 'collection_available' => (bool) config('payments.collection_enabled') && ! app()->environment('production') && config('payments.provider') === 'stripe_test', 'evidence' => 'STRIPE_TEST_CONTRACT', 'external_provider_verified' => false])->header('Cache-Control', 'no-store');
    }

    public function enrollments(Request $r, PaymentMandates $m)
    {
        return DB::table('payment_enrollments')->where('user_id', $r->user()->id)->orderByDesc('id')->paginate(25)->through(fn ($e) => $m->enrollmentDto($e));
    }

    public function begin(Request $r, PaymentMandates $m)
    {
        $v = $r->validate(['consent' => 'required|accepted', 'consent_version' => 'required|string|max:40', 'idempotency_key' => 'required|string|max:100', 'payment_method' => 'prohibited', 'card_number' => 'prohibited', 'cvv' => 'prohibited', 'customer' => 'prohibited', 'pan' => 'prohibited', 'cvc' => 'prohibited', 'client_secret' => 'prohibited']);

        return response()->json($m->begin($r->user(), $v['idempotency_key'], $v['consent_version']), 201)->header('Cache-Control', 'no-store');
    }

    public function inspect(Request $r, int $enrollment, PaymentMandates $m)
    {
        return response()->json($m->inspect($r->user(), $enrollment))->header('Cache-Control', 'no-store');
    }

    public function defaultMethod(Request $r, int $method, PaymentMandates $m)
    {
        $m->method($r->user(), $method);
        $v = $r->validate(['expected_version' => 'required|integer|min:0']);
        $m->setDefault($r->user(), $method, (int) $v['expected_version']);

        return $this->methods($r);
    }

    public function revoke(Request $r, int $method, PaymentMandates $m)
    {
        $m->method($r->user(), $method);
        $v = $r->validate(['expected_version' => 'required|integer|min:1']);
        $m->revoke($r->user(), $method, (int) $v['expected_version']);

        return $this->methods($r);
    }

    public function detach(Request $r, int $method, PaymentMandates $m)
    {
        $m->detach($r->user(), $method);

        return $this->methods($r);
    }

    public function subscriptions(Request $r)
    {
        return DB::table('commercial_subscriptions as s')->where('s.user_id', $r->user()->id)->orderByDesc('s.id')->paginate(25)->through(function ($s) {
            $s->collection_policy = DB::table('subscription_collection_policies')->where('subscription_id', $s->id)->first();
            $s->latest_collection = DB::table('subscription_collections')->where('subscription_id', $s->id)->orderByDesc('id')->first(['id', 'invoice_id', 'checkout_id', 'attempts', 'state', 'failure_code', 'next_attempt_at', 'updated_at']);

            return $s;
        });
    }

    public function policy(Request $r, int $subscription, SubscriptionCollections $c)
    {
        app(SubscriptionService::class)->access($r->user(), $subscription);
        $v = $r->validate(['enabled' => 'required|boolean', 'mandate_id' => 'required_if:enabled,true|nullable|integer|min:1', 'consent' => 'required_if:enabled,true|accepted', 'expected_version' => 'required|integer|min:0']);

        return response()->json($c->policy($r->user(), $subscription, $v['enabled'], isset($v['mandate_id']) ? (int) $v['mandate_id'] : null, (int) $v['expected_version']));
    }

    public function authenticate(Request $r, int $checkout, SubscriptionGateway $g)
    {
        return response()->json($g->authenticate($r->user(), $checkout))->header('Cache-Control', 'no-store');
    }

    public function events(Request $r)
    {
        return DB::table('recurring_payment_events')->where('user_id', $r->user()->id)->select(['id', 'subject', 'kind', 'created_at'])->orderByDesc('id')->paginate(25);
    }

    public function webhook(Request $r, PaymentMandates $m)
    {
        $event = (new StripeSandboxProvider)->verifyWebhook($r->getContent(), $r->header('Stripe-Signature', ''));
        if (! in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.expired'], true)) {
            return ['accepted' => true, 'ignored' => true];
        }
        $key = 'stripe:'.$event['id'];
        $hash = hash('sha256', $r->getContent());
        $old = DB::table('recurring_payment_events')->where('provider_event', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409);

            return ['accepted' => true, 'duplicate' => true];
        }
        $ref = $event['data']['object']['metadata']['creabiz_enrollment'] ?? '';
        $e = DB::table('payment_enrollments')->where('reference', $ref)->first();
        abort_unless($e, 404);
        abort_unless(is_string($event['data']['object']['id'] ?? null) && (! $e->session_hash || hash_equals($e->session_hash, hash('sha256', $event['data']['object']['id']))), 409, 'Callback session identity changed.');
        $m->inspect(User::findOrFail($e->user_id), $e->id);
        try {
            $m->event($e->user_id, 'enrollment:'.$e->id, 'provider_callback_verified', $key, $hash);
        } catch (UniqueConstraintViolationException $error) {
            $old = DB::table('recurring_payment_events')->where('provider_event', $key)->first();
            abort_unless($old && hash_equals($old->fingerprint, $hash), 409);
        }

        return ['accepted' => true, 'duplicate' => false, 'external_provider_verified' => false];
    }
}

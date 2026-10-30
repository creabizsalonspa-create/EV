<?php

namespace App\Services\Commercial\Campaigns;

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reservation of paid package delivery units. Never a second monetary ledger. */
class CampaignService
{
    public function enabled(): void
    {
        abort_unless(config('campaigns.enabled'), 503, 'Commercial campaign management is disabled.');
    }

    public function billing(int $invoice, int $vendor, bool $lock = false): array
    {
        abort_unless(User::whereKey($vendor)->where('role', 'vendor')->exists(), 409, 'The billing account is no longer an eligible vendor.');
        $i = DB::table('commercial_subscription_invoices')->where('id', $invoice)->first();
        abort_unless($i, 404);
        $s = DB::table('commercial_subscriptions')->where('id', $i->subscription_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($s && (int) $s->user_id === $vendor, 404);
        $receipt = DB::table('commercial_subscription_receipts')->where('invoice_id', $invoice)->first();
        $uncertain = DB::table('payment_recovery_intents as p')->join('subscription_gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'subscription')->where('c.invoice_id', $invoice)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists();
        $pendingRefund = DB::table('subscription_credit_requests as r')->leftJoin('subscription_credits as c', 'c.request_id', '=', 'r.id')->leftJoin('payment_recovery_intents as p', fn ($join) => $join->on('p.identity', '=', 'r.recovery_identity')->on('p.checkout_id', '=', 'r.checkout_id')->where('p.scope', 'subscription')->where('p.kind', 'refund'))->where('r.invoice_id', $invoice)->whereNull('c.id')->where(fn ($q) => $q->whereNull('p.id')->orWhere('p.status', '!=', 'failed'))->exists();
        abort_if($uncertain || $pendingRefund, 409, 'Pending or uncertain package payment/refund must be reconciled before campaign allocation or delivery.');
        $p = DB::table('commercial_plan_versions')->find($i->plan_id);
        $e = json_decode($i->entitlements, true, 32, JSON_THROW_ON_ERROR);
        $summary = app(SubscriptionService::class)->summary($s->id);
        abort_unless($p->audience === 'vendor' && $summary['entitlements_active'] && $summary['ledger_balanced'] && (int) $s->cycle_number === (int) $i->cycle_number && $receipt && $receipt->currency === $i->currency && $p->currency === $i->currency && (int) $p->minor_units === (int) $i->minor_units && (int) $i->minor_units >= 0 && (int) $i->minor_units <= 3 && (int) $i->total_minor > 0 && (int) $receipt->amount_minor === (int) $i->total_minor && ! DB::table('subscription_credits')->where('invoice_id', $invoice)->exists(), 409, 'An active fully paid vendor package without credits is required.');
        foreach (['campaign_slots', 'campaign_units'] as $key) {
            abort_unless(isset($e[$key]) && is_int($e[$key]) && $e[$key] > 0 && $e[$key] <= 1000000, 409, 'The paid package must explicitly grant campaign slots and delivery units.');
        }

        return [$i, $e, $receipt];
    }

    private function usage(int $invoice): array
    {
        $deliveries = DB::table('commercial_campaign_deliveries')->where('kind', 'delivery')->select('campaign_id')->selectRaw('COUNT(*) AS used')->groupBy('campaign_id');
        $row = DB::table('commercial_campaigns as c')->leftJoinSub($deliveries, 'd', 'd.campaign_id', '=', 'c.id')->where('c.invoice_id', $invoice)->selectRaw("COALESCE(SUM(CASE WHEN c.status IN ('cancelled','rejected') THEN COALESCE(d.used,0) ELSE c.budget_units END),0) AS reserved, COALESCE(SUM(CASE WHEN c.status NOT IN ('cancelled','rejected') AND c.ends_at > ? THEN 1 ELSE 0 END),0) AS slots", [now()])->first();

        return [(int) $row->reserved, (int) $row->slots];
    }

    public function create(User $vendor, array $v): object
    {
        $this->enabled();
        abort_unless($vendor->role === 'vendor', 403);
        abort_unless(isset($v['budget_units']) && is_int($v['budget_units']) && $v['budget_units'] >= 1 && $v['budget_units'] <= 1000000, 422, 'Campaign delivery budget must be a bounded positive integer.');

        return DB::transaction(function () use ($vendor, $v) {
            $vendor = User::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            abort_unless($vendor->role === 'vendor', 403);
            $key = hash('sha256', $vendor->id.':'.$v['idempotency_key']);
            unset($v['idempotency_key']);
            ksort($v);
            $fingerprint = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
            if ($old = DB::table('commercial_campaigns')->where('request_key', $key)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Campaign request key payload changed.');

                return $old;
            }
            [$invoice, $e] = $this->billing($v['invoice_id'], $vendor->id, true);
            $station = DB::table('stations')->where('id', $v['station_id'])->where('vendor_id', $vendor->id)->lockForUpdate()->first();
            abort_unless($station && $station->country === $v['country'], 404);
            abort_unless(CarbonImmutable::parse($v['starts_at'])->gte(CarbonImmutable::parse($invoice->period_start)) && CarbonImmutable::parse($v['ends_at'])->lte(CarbonImmutable::parse($invoice->period_end)) && CarbonImmutable::parse($v['ends_at'])->isFuture(), 409, 'Campaign dates must fit the paid billing cycle.');
            [$reserved, $slots] = $this->usage($invoice->id);
            abort_unless($slots < $e['campaign_slots'] && $v['budget_units'] <= $e['campaign_units'] - $reserved, 409, 'Paid campaign slot or delivery-unit limit exceeded.');
            $id = DB::table('commercial_campaigns')->insertGetId($v + ['vendor_id' => $vendor->id, 'request_key' => $key, 'fingerprint' => $fingerprint, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            $this->event($id, $vendor->id, 'created', 'Paid package units reserved; not an additional cash charge.');

            return DB::table('commercial_campaigns')->find($id);
        }, 3);
    }

    public function action(int $id, User $actor, string $action, string $reason): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor, $action, $reason) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $hint = DB::table('commercial_campaigns')->find($id) ?? abort(404);
            User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('commercial_campaigns')->where('id', $id)->lockForUpdate()->first();
            $admin = $actor->role === 'admin';
            abort_unless($admin || ($actor->role === 'vendor' && (int) $row->vendor_id === $actor->id), 404);
            $targets = ['submit' => ['draft', 'submitted'], 'approve' => ['submitted', 'approved'], 'reject' => ['submitted', 'rejected'], 'pause' => ['approved', 'paused'], 'resume' => ['paused', 'approved'], 'suspend' => ['approved', 'suspended'], 'reinstate' => ['suspended', 'approved']];
            if ($action === 'cancel') {
                abort_unless(in_array($row->status, ['draft', 'submitted', 'approved', 'paused', 'suspended', 'cancelled'], true), 409);
                $from = $row->status;
                $to = 'cancelled';
            } else {
                abort_unless(isset($targets[$action]), 422);
                [$from, $to] = $targets[$action];
                abort_unless(in_array($row->status, [$from, $to], true), 409, 'Campaign state does not permit this operation.');
            }
            abort_unless(in_array($action, ['approve', 'reject', 'suspend', 'reinstate'], true) ? $admin : ! $admin, 403, 'Vendor controls and administrator review are separate.');
            if ($row->status === $to) {
                abort_unless(DB::table('commercial_campaign_events')->where('campaign_id', $id)->where('action', $action)->where('reason', $reason)->exists(), 409, 'Repeated campaign decision reason changed.');

                return $row;
            }
            if (in_array($action, ['submit', 'approve', 'resume', 'reinstate'], true)) {
                $this->billing($row->invoice_id, $row->vendor_id, true);
                abort_unless(CarbonImmutable::parse($row->ends_at)->isFuture(), 409, 'Campaign has expired.');
            }
            DB::table('commercial_campaigns')->where('id', $id)->update(['status' => $to, 'updated_at' => now()]);
            $this->event($id, $actor->id, $action, $reason);

            return DB::table('commercial_campaigns')->find($id);
        }, 3);
    }

    public function delivered(int $id): int
    {
        return DB::table('commercial_campaign_deliveries')->where('campaign_id', $id)->where('kind', 'delivery')->count();
    }

    public function eligible(object $c, bool $ignoreBudget = false): bool
    {
        if (! config('campaigns.enabled') || $c->status !== 'approved' || CarbonImmutable::parse($c->starts_at)->isFuture() || ! CarbonImmutable::parse($c->ends_at)->isFuture() || (! $ignoreBudget && $this->delivered($c->id) >= (int) $c->budget_units)) {
            return false;
        }
        $station = DB::table('stations')->where('id', $c->station_id)->where('vendor_id', $c->vendor_id)->where('country', $c->country)->exists();
        if (! $station) {
            return false;
        }
        try {
            $this->billing($c->invoice_id, $c->vendor_id);
        } catch (HttpException $error) {
            return false;
        }

        return true;
    }

    public function ingest(array $v): array
    {
        $this->enabled();
        abort_unless(in_array($v['kind'] ?? null, ['delivery', 'click'], true), 422, 'Unknown publisher event kind.');
        abort_unless(($v['kind'] === 'click') === ! empty($v['parent_event_id']), 422, 'Only click events require a parent delivery identity.');

        try {
            return DB::transaction(function () use ($v) {
                $hint = DB::table('commercial_campaigns')->find($v['campaign_id']) ?? abort(404);
                User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
                $c = DB::table('commercial_campaigns')->where('id', $hint->id)->lockForUpdate()->first();
                ksort($v);
                $fingerprint = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
                if ($old = DB::table('commercial_campaign_deliveries')->where('event_id', $v['event_id'])->first()) {
                    abort_unless((int) $old->campaign_id === (int) $c->id && hash_equals($old->fingerprint, $fingerprint), 409, 'Delivery event identity changed.');

                    return ['event_id' => $old->event_id, 'duplicate' => true];
                }
                $this->billing($c->invoice_id, $c->vendor_id, true);
                abort_unless(DB::table('stations')->where('id', $c->station_id)->where('vendor_id', $c->vendor_id)->where('country', $c->country)->lockForUpdate()->first(), 409, 'Station ownership or country changed.');
                abort_unless($this->eligible($c, $v['kind'] === 'click') && $v['country'] === $c->country && $v['placement'] === $c->placement && ($c->region === null || $c->region === ($v['region'] ?? null)), 409, 'Campaign is not eligible for this delivery context.');
                $occurred = CarbonImmutable::parse($v['occurred_at']);
                abort_unless($occurred->gte(CarbonImmutable::parse($c->starts_at)) && $occurred->gte(CarbonImmutable::parse($c->created_at)) && $occurred->lt(CarbonImmutable::parse($c->ends_at)) && $occurred->betweenIncluded(now()->subMinutes(5), now()->addMinute()), 422, 'Delivery occurrence is outside the allowed interval.');
                $parent = null;
                if ($v['kind'] === 'click') {
                    $parent = DB::table('commercial_campaign_deliveries')->where('event_id', $v['parent_event_id'] ?? '')->where('campaign_id', $c->id)->where('kind', 'delivery')->first();
                    abort_unless($parent && CarbonImmutable::parse($parent->occurred_at)->lte($occurred), 409, 'A click must reference a prior verified server delivery for this campaign.');
                    abort_if(DB::table('commercial_campaign_deliveries')->where('parent_delivery_id', $parent->id)->exists(), 409, 'This server delivery already has a click attribution.');
                }
                DB::table('commercial_campaign_deliveries')->insert(['parent_delivery_id' => $parent?->id, 'campaign_id' => $c->id, 'event_id' => $v['event_id'], 'fingerprint' => $fingerprint, 'kind' => $v['kind'], 'occurred_at' => $occurred, 'created_at' => now()]);

                return ['event_id' => $v['event_id'], 'duplicate' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            abort(409, 'Delivery identity is already reserved; inspect the original campaign event.');
        }
    }

    private function event(int $id, int $actor, string $action, string $reason): void
    {
        DB::table('commercial_campaign_events')->insert(['campaign_id' => $id, 'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'commercial.campaign.'.$action, 'subject' => (string) $id, 'created_at' => now()]);
    }
}

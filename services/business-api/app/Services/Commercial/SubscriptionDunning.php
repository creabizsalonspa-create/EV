<?php

namespace App\Services\Commercial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** In-app reminders only: no collection, entitlement mutation or payment retry. */
class SubscriptionDunning
{
    public const DAYS = [1 => 1, 2 => 3, 3 => 7];

    public function invoice(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $invoice = DB::table('commercial_subscription_invoices')->find($id);
            if (! $invoice) {
                return false;
            }
            // Same serialization boundary as receipts, renewals and credits.
            $s = DB::table('commercial_subscriptions')->where('id', $invoice->subscription_id)->lockForUpdate()->first();
            if (! $s || $s->status === 'cancelled' || (int) $invoice->cycle_number !== (int) $s->cycle_number + 1 || (int) $invoice->total_minor === 0 || DB::table('commercial_subscription_receipts')->where('invoice_id', $id)->exists()) {
                return false;
            }
            // Never encourage a replacement payment while an existing provider
            // operation is unresolved. Recovery owns that operation's outcome.
            if (self::held($id)) {
                return false;
            }
            $stage = 0;
            $start = CarbonImmutable::parse($invoice->period_start);
            foreach (self::DAYS as $candidate => $days) {
                if ($start->addDays($days)->lessThanOrEqualTo(now())) {
                    $stage = $candidate;
                }
            }
            // A delayed worker emits only the latest due stage, not a burst
            // of historical reminders. A backwards clock cannot emit old stages.
            $prior = (int) DB::table('subscription_dunning_notices')->where('invoice_id', $id)->max('stage');
            if ($stage <= $prior) {
                return false;
            }
            DB::table('subscription_dunning_notices')->insert(['invoice_id' => $id, 'stage' => $stage, 'due_at' => $start->addDays(self::DAYS[$stage]), 'created_at' => now()]);

            return true;
        }, 3);
    }

    public static function held(int $invoice): bool
    {
        return DB::table('payment_recovery_intents as p')->join('subscription_gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'subscription')->where('c.invoice_id', $invoice)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists();
    }

    public function scan(int $limit): int
    {
        // Select only actionable latest cycles without receipts, holds or a
        // completed final reminder so settled/held history cannot starve work.
        $ids = DB::table('commercial_subscription_invoices as i')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->where('s.status', '!=', 'cancelled')->whereColumn('i.cycle_number', DB::raw('s.cycle_number + 1'))->where('i.total_minor', '>', 0)->where('i.period_start', '<=', now()->subDay())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('commercial_subscription_receipts as r')->whereColumn('r.invoice_id', 'i.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscription_dunning_notices as n')->whereColumn('n.invoice_id', 'i.id')->where('n.stage', 3))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_recovery_intents as p')->join('subscription_gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'subscription')->whereColumn('c.invoice_id', 'i.id')->whereIn('p.status', ['executing', 'uncertain', 'needs_review']))
            ->where(function ($q) {
                foreach (self::DAYS as $stage => $days) {
                    $q->orWhere(fn ($q) => $q->where('i.period_start', '<=', now()->subDays($days))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscription_dunning_notices as n')->whereColumn('n.invoice_id', 'i.id')->where('n.stage', '>=', $stage)));
                }
            })
            ->orderBy('i.id')->limit($limit)->pluck('i.id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += (int) $this->invoice($id);
        }

        return $sent;
    }
}

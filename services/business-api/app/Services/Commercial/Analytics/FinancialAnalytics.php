<?php

namespace App\Services\Commercial\Analytics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Each fact is aggregated independently: invoices, cash postings and reservations never net together. */
class FinancialAnalytics
{
    private const MAX_EXACT_JSON_INTEGER = 9007199254740991;

    private function scope(Builder $q, User $actor, string $customer, ?string $vendor = null): Builder
    {
        if ($actor->role !== 'admin') {
            $q->where($actor->role === 'vendor' && $vendor ? $vendor : $customer, $actor->id);
        }

        return $q;
    }

    private function aggregate(Builder $q, string $period, string $category, string $currency, string $precision, string $amount, string $state, string $evidence, string $integrity = '1'): Builder
    {
        $q = clone $q;

        return $q->selectRaw("'{$period}' AS period, '{$category}' AS category, {$currency} AS currency, {$precision} AS minor_units, {$state} AS state, {$evidence} AS evidence, COUNT(*) AS record_count, COALESCE(SUM({$amount}),0) AS amount_minor, MIN({$integrity}) AS source_valid")
            ->groupByRaw("{$currency}, {$precision}")
            ->when(! str_starts_with($state, "'"), fn ($q) => $q->groupByRaw($state))
            ->when(! str_starts_with($evidence, "'"), fn ($q) => $q->groupByRaw($evidence));
    }

    private function bounded(Builder $q, string $column, CarbonImmutable $from, CarbonImmutable $until, ?string $currency, string $currencyColumn, CarbonImmutable $asOf): Builder
    {
        return $q->where($column, '>=', $from)->where($column, '<', $until)->where($column, '<=', $asOf)->when($currency, fn ($q) => $q->where($currencyColumn, $currency));
    }

    private function facts(User $actor, string $period, CarbonImmutable $from, CarbonImmutable $until, ?string $currency, CarbonImmutable $asOf): array
    {
        $queries = [];
        $charge = fn () => $this->scope(DB::table('finance_invoices as i'), $actor, 'i.user_id', 'i.vendor_id');
        $queries[] = $this->aggregate($this->bounded($charge(), 'i.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, 'charging_invoiced', 'i.currency', 'i.minor_units', 'i.total_minor', "'issued'", 'i.evidence');
        foreach (['finance_payments' => 'charging_collected', 'finance_refunds' => 'charging_refunded'] as $table => $category) {
            $q = $this->scope(DB::table($table.' as p')->join('finance_invoices as i', 'i.id', '=', 'p.invoice_id'), $actor, 'i.user_id', 'i.vendor_id');
            $queries[] = $this->aggregate($this->bounded($q, 'p.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, $category, 'i.currency', 'i.minor_units', 'p.amount_minor', "'posted'", 'p.evidence');
            if (in_array($actor->role, ['vendor', 'admin'], true) && $table === 'finance_refunds') {
                foreach (['commission_minor' => 'commission_reversed', 'vendor_minor' => 'vendor_allocation_reversed'] as $field => $allocation) {
                    $queries[] = $this->aggregate($this->bounded(clone $q, 'p.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, $allocation, 'i.currency', 'i.minor_units', 'p.'.$field, "'allocated'", 'p.evidence');
                }
            }
        }
        if (in_array($actor->role, ['vendor', 'admin'], true)) {
            foreach (['commission_minor' => 'commission_allocated', 'vendor_minor' => 'vendor_allocated'] as $field => $category) {
                $queries[] = $this->aggregate($this->bounded($charge(), 'i.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, $category, 'i.currency', 'i.minor_units', 'i.'.$field, "'allocated'", 'i.evidence');
            }
            foreach (['commercial_settlements' => ['settlement_instruction', 'state'], 'commercial_payouts' => ['payout_instruction', 'status']] as $table => [$category, $state]) {
                $q = $this->scope(DB::table($table.' as p'), $actor, 'p.vendor_id');
                $queries[] = $this->aggregate($this->bounded($q, 'p.created_at', $from, $until, $currency, 'p.currency', $asOf), $period, $category, 'p.currency', 'p.minor_units', 'p.amount_minor', 'p.'.$state, 'p.evidence');
            }
        }
        $subscriptions = fn () => $this->scope(DB::table('commercial_subscription_invoices as i')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id'), $actor, 's.user_id');
        $queries[] = $this->aggregate($this->bounded($subscriptions(), 'i.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, 'subscription_invoiced', 'i.currency', 'i.minor_units', 'i.total_minor', "'issued'", 'i.evidence');
        foreach (['commercial_subscription_receipts' => 'subscription_collected', 'subscription_credits' => 'subscription_refunded'] as $table => $category) {
            $q = $subscriptions()->join($table.' as p', 'p.invoice_id', '=', 'i.id');
            $queries[] = $this->aggregate($this->bounded($q, 'p.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, $category, 'i.currency', 'i.minor_units', 'p.amount_minor', "'posted'", 'p.evidence', $table === 'subscription_credits' ? 'CASE WHEN p.currency = i.currency AND p.minor_units = i.minor_units THEN 1 ELSE 0 END' : 'CASE WHEN p.currency = i.currency THEN 1 ELSE 0 END');
        }
        $disputes = $this->scope(DB::table('external_disputes as d')->join('gateway_checkouts as c', 'c.id', '=', 'd.checkout_id')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id'), $actor, 'i.user_id', 'i.vendor_id');
        $queries[] = $this->aggregate($this->bounded($disputes, 'd.created_at', $from, $until, $currency, 'i.currency', $asOf), $period, 'provider_dispute', 'i.currency', 'i.minor_units', 'd.amount_minor', 'd.status', "'STRIPE_TEST_HTTP'");
        $corporate = $this->scope(DB::table('corporate_statements as c')->join('fleets as f', 'f.id', '=', 'c.fleet_id'), $actor, 'f.owner_id');
        $queries[] = $this->aggregate($this->bounded($corporate, 'c.created_at', $from, $until, $currency, 'c.currency', $asOf), $period, 'corporate_invoiced', 'c.currency', 'c.minor_units', 'c.total_minor', "'consolidated_reference'", "'SIMULATED'");
        $paid = DB::table('finance_payments')->where('created_at', '<=', $asOf)->select('invoice_id')->selectRaw('SUM(amount_minor) AS paid')->groupBy('invoice_id');
        $outstanding = $this->scope(DB::table('corporate_statements as c')->join('fleets as f', 'f.id', '=', 'c.fleet_id')->join('corporate_statement_lines as l', 'l.statement_id', '=', 'c.id')->leftJoinSub($paid, 'p', 'p.invoice_id', '=', 'l.invoice_id'), $actor, 'f.owner_id');
        foreach (['not_due' => [null, null], 'overdue_1_30' => [30, 0], 'overdue_31_60' => [60, 30], 'overdue_61_90' => [90, 60], 'overdue_over_90' => [null, 90]] as $bucket => [$older, $newer]) {
            $q = clone $outstanding;
            if ($bucket === 'not_due') {
                $q->where('c.due_at', '>=', $asOf);
            } else {
                $q->where('c.due_at', '<', $asOf->subDays($newer));
                if ($older) {
                    $q->where('c.due_at', '>=', $asOf->subDays($older));
                }
            }
            $q->whereRaw('l.total_minor > COALESCE(p.paid,0)');
            $queries[] = $this->aggregate($this->bounded($q, 'c.created_at', $from, $until, $currency, 'c.currency', $asOf), $period, 'corporate_receivable', 'c.currency', 'c.minor_units', 'CASE WHEN l.total_minor > COALESCE(p.paid,0) THEN l.total_minor - COALESCE(p.paid,0) ELSE 0 END', "'{$bucket}'", "'SIMULATED'");
        }

        $points = $this->scope(DB::table('loyalty_ledger as l'), $actor, 'l.user_id')->where('l.created_at', '>=', $from)->where('l.created_at', '<', $until)->where('l.created_at', '<=', $asOf);
        $queries[] = $points->selectRaw("'{$period}' AS period, 'loyalty_points' AS category, 'POINTS' AS currency, 0 AS minor_units, l.kind AS state, 'NON_CASH' AS evidence, COUNT(*) AS record_count, SUM(l.points) AS amount_minor, 1 AS source_valid")->groupBy('l.kind');

        return $queries;
    }

    private function runRate(User $actor, ?string $currency, CarbonImmutable $asOf): Builder
    {
        $credits = DB::table('subscription_credits')->where('created_at', '<=', $asOf)->select('invoice_id')->selectRaw('SUM(subtotal_minor) AS credited')->groupBy('invoice_id');
        $q = $this->scope(DB::table('commercial_subscription_invoices as i')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')->join('commercial_plan_versions as p', 'p.id', '=', 'i.plan_id')->join('commercial_subscription_receipts as r', 'r.invoice_id', '=', 'i.id')->leftJoinSub($credits, 'c', 'c.invoice_id', '=', 'i.id'), $actor, 's.user_id')
            ->where('s.status', 'active')->where('s.cancel_at_period_end', false)->whereColumn('s.cycle_number', 'i.cycle_number')->whereColumn('r.amount_minor', 'i.total_minor')->where('i.period_start', '<=', $asOf)->where('i.period_end', '>', $asOf)->where('r.created_at', '<=', $asOf)->whereIn('p.interval', ['month', 'year'])->when($currency, fn ($q) => $q->where('i.currency', $currency));

        return $this->aggregate($q, 'snapshot', 'paid_cycle_run_rate', 'i.currency', 'i.minor_units', 'CASE WHEN i.subtotal_minor > COALESCE(c.credited,0) THEN i.subtotal_minor - COALESCE(c.credited,0) ELSE 0 END', 'p.interval', 'r.evidence');
    }

    private function exact(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        abort_if($integer === false || $integer < 0 || $integer > self::MAX_EXACT_JSON_INTEGER, 409, 'Analytics exceeds exact client integer bounds; narrow the reporting period.');

        return $integer;
    }

    public function report(User $actor, array $filters, string $operation = 'view'): array
    {
        abort_unless(in_array($actor->role, ['admin', 'vendor', 'customer'], true), 403);
        $asOf = CarbonImmutable::now('UTC');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'] ?? $asOf->subDays(29)->toDateString(), 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'] ?? $asOf->toDateString(), 'UTC')->addDay();
        $days = (int) $from->diffInDays($end, false);
        abort_unless($days >= 1 && $days <= 366 && $end->lte($asOf->addDay()->startOfDay()), 422, 'Select an inclusive UTC period of1..366days ending no later than today.');
        $currency = $filters['currency'] ?? null;
        $queries = array_merge($this->facts($actor, 'current', $from, $end, $currency, $asOf), $this->facts($actor, 'previous', $from->subDays($days), $from, $currency, $asOf), [$this->runRate($actor, $currency, $asOf)]);
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }
        // One SQL snapshot. Never join payment/refund detail rows to one another.
        $rows = DB::query()->fromSub($union, 'facts')->orderBy('period')->orderBy('category')->orderBy('currency')->orderBy('minor_units')->orderBy('state')->orderBy('evidence')->get();
        abort_if($rows->count() > 2500, 409, 'Too many analytic groups; select a currency or shorter period.');
        $pointGroups = [];
        $runRates = [];
        $groups = $rows->map(function ($row) use (&$pointGroups, &$runRates) {
            abort_unless((int) $row->source_valid === 1, 409, 'Financial source currency/precision does not reconcile; investigate imported postings.');
            if ($row->category === 'loyalty_points') {
                $points = filter_var($row->amount_minor, FILTER_VALIDATE_INT);
                abort_if($points === false || $points > self::MAX_EXACT_JSON_INTEGER || $points < -self::MAX_EXACT_JSON_INTEGER, 409, 'Points report exceeds exact client integer bounds.');
                $pointGroups[] = ['period' => $row->period, 'kind' => $row->state, 'entries' => $this->exact($row->record_count), 'points' => $points];

                return null;
            }
            $precision = filter_var($row->minor_units, FILTER_VALIDATE_INT);
            abort_unless($precision !== false && $precision >= 0 && $precision <= 3 && preg_match('/^[A-Z]{3}$/D', $row->currency), 409, 'Unresolved source currency or precision.');

            if ($row->category === 'paid_cycle_run_rate') {
                $amount = $this->exact($row->amount_minor);
                $multiplier = $row->state === 'month' ? 12 : 1;
                abort_if($amount > intdiv(self::MAX_EXACT_JSON_INTEGER, $multiplier), 409, 'Run-rate exceeds exact client integer bounds.');
                $key = $row->currency.':'.$precision.':'.$row->evidence;
                $r = $runRates[$key] ?? ['currency' => $row->currency, 'minor_units' => $precision, 'evidence' => $row->evidence, 'subscriptions' => 0, 'annual_equivalent_minor' => 0];
                $weighted = $amount * $multiplier;
                abort_if($r['annual_equivalent_minor'] > self::MAX_EXACT_JSON_INTEGER - $weighted, 409, 'Run-rate exceeds exact client integer bounds.');
                $r['annual_equivalent_minor'] += $weighted;
                $r['subscriptions'] += $this->exact($row->record_count);
                $r['monthly_equivalent_minor'] = intdiv($r['annual_equivalent_minor'], 12) + ($r['annual_equivalent_minor'] % 12 >= 6 ? 1 : 0);
                $runRates[$key] = $r;

                return null;
            }

            return ['period' => $row->period, 'category' => $row->category, 'currency' => $row->currency, 'minor_units' => $precision, 'state' => $row->state, 'evidence' => $row->evidence, 'record_count' => $this->exact($row->record_count), 'amount_minor' => $this->exact($row->amount_minor)];
        })->filter()->values()->all();
        abort_unless(in_array($operation, ['view', 'export', 'pdf'], true), 422);
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.analytics.'.$operation, 'subject' => $from->toDateString().':'.$end->subDay()->toDateString().':'.($currency ?? 'all'), 'created_at' => now()]);

        return ['groups' => $groups, 'category_labels' => AnalyticsPresentation::CATEGORIES, 'state_labels' => AnalyticsPresentation::STATES, 'scope' => $actor->role === 'admin' ? 'platform' : 'own_charging_and_own_billing', 'points' => ['unit' => 'NON_MONETARY_POINTS', 'cash_value' => false, 'groups' => $pointGroups], 'recurring_run_rates' => array_values($runRates), 'run_rate_semantics' => 'Current paid, non-cancelling cycle subtotal net of posted credits, annual/monthly equivalent; monthly half-up rounding at currency precision. Not recognized revenue or guaranteed future collection.', 'period' => ['from' => $from->toDateString(), 'to' => $end->subDay()->toDateString(), 'previous_from' => $from->subDays($days)->toDateString(), 'previous_to' => $from->subDay()->toDateString(), 'timezone' => 'UTC'], 'as_of' => $asOf->toIso8601String(), 'real_money_verified' => false, 'semantics' => 'Posting-time amounts; current status of period-created instructions; current receivable aging. Categories are separate facts and must never be added as one revenue total. Corporate statements reference charging invoices, not additional revenue.'];
    }

    public function csv(array $report): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['period', 'category', 'currency', 'minor_units', 'state', 'evidence', 'records', 'amount_minor', 'from_utc', 'to_utc', 'as_of_utc'], ',', '"', '');
        $safe = fn ($v) => preg_match('/^[\s\p{C}]*[=+\-@]/u', (string) $v) ? "'".$v : $v;
        foreach ($report['groups'] as $g) {
            $cells = array_merge(array_values($g), [$report['period']['from'], $report['period']['to'], $report['as_of']]);
            fputcsv($f, array_map(fn ($v) => preg_match('/^[\s\p{C}]*[=+\-@]/u', (string) $v) ? "'".$v : $v, $cells), ',', '"', '');
        }
        fputcsv($f, [], ',', '"', '');
        fputcsv($f, ['NON_MONETARY_POINTS_NO_CASH_VALUE', 'period', 'kind', 'entries', 'points'], ',', '"', '');
        foreach ($report['points']['groups'] as $g) {
            fputcsv($f, array_map($safe, ['NON_MONETARY_POINTS', $g['period'], $g['kind'], $g['entries'], $g['points']]), ',', '"', '');
        }
        fputcsv($f, [], ',', '"', '');
        fputcsv($f, ['PAID_CYCLE_EQUIVALENT_NOT_RECOGNIZED_REVENUE', 'currency', 'minor_units', 'evidence', 'subscriptions', 'annual_equivalent_minor', 'monthly_equivalent_minor'], ',', '"', '');
        foreach ($report['recurring_run_rates'] as $g) {
            fputcsv($f, array_map($safe, array_merge(['PAID_CYCLE_EQUIVALENT'], array_values($g))), ',', '"', '');
        }
        rewind($f);
        $bytes = stream_get_contents($f);
        fclose($f);

        return $bytes;
    }
}

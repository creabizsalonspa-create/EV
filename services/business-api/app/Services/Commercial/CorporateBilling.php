<?php

namespace App\Services\Commercial;

use App\Models\Finance\Invoice;
use App\Models\Fleet;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorporateBilling
{
    public function fleet(int $id, int $actor, bool $manage = false): object
    {
        $fleet = Fleet::findOrFail($id);
        $admin = User::findOrFail($actor)->role === 'admin';
        $owner = (int) $fleet->owner_id === $actor;
        abort_unless($admin || $owner || (! $manage && DB::table('fleet_members')->where('fleet_id', $id)->where('user_id', $actor)->where('status', 'active')->exists()), 404);

        return $fleet;
    }

    /** Authorization and payer identity must come from the row after acquiring its lock. */
    private function lockManager(int $fleetId, int $actor): object
    {
        $fleet = Fleet::whereKey($fleetId)->lockForUpdate()->firstOrFail();
        abort_unless(User::findOrFail($actor)->role === 'admin' || (int) $fleet->owner_id === $actor, 404);

        return $fleet;
    }

    private function active(object $agreement): void
    {
        abort_unless(Carbon::parse($agreement->effective_from)->lte(now()) && Carbon::parse($agreement->expires_at)->gt(now()) && DB::table('corporate_agreement_acceptances')->where('agreement_id', $agreement->id)->exists(), 409, 'An accepted effective corporate agreement is required.');
    }

    public function agreement(int $id): object
    {
        return DB::table('corporate_agreements')->find($id) ?? abort(404);
    }

    private function key(int $actor, string $key): string
    {
        return hash('sha256', $actor.':'.$key);
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function replay(string $table, string $key, string $hash): ?object
    {
        $old = DB::table($table)->where('request_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Corporate request key payload changed.');
        }

        return $old;
    }

    public function publish(int $fleetId, int $actor, array $v): object
    {
        abort_unless(User::findOrFail($actor)->role === 'admin', 403);

        return DB::transaction(function () use ($fleetId, $actor, $v) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            Fleet::whereKey($fleetId)->lockForUpdate()->firstOrFail();
            $key = $this->key($actor, $v['idempotency_key']);
            unset($v['idempotency_key']);
            $hash = $this->hash([$fleetId, $v]);
            if ($old = $this->replay('corporate_agreements', $key, $hash)) {
                return $old;
            }
            $fleet = Fleet::findOrFail($fleetId);
            $region = DB::table('regions')->where('country', $fleet->country)->first();
            abort_unless($region && $region->currency === $v['currency'] && (int) $region->minor_units === (int) $v['minor_units'], 409, 'Agreement currency/precision must match the configured corporate region.');
            abort_unless(Carbon::parse($v['effective_from'])->lt(Carbon::parse($v['expires_at'])), 422);
            // Immutable versions never overlap within a corporate currency.
            abort_if(DB::table('corporate_agreements')->where('fleet_id', $fleetId)->where('currency', $v['currency'])->where('effective_from', '<', $v['expires_at'])->where('expires_at', '>', $v['effective_from'])->exists(), 409, 'Corporate agreement terms overlap an existing immutable version.');
            $id = DB::table('corporate_agreements')->insertGetId($v + ['fleet_id' => $fleetId, 'created_by' => $actor, 'reference' => 'CB-AGR-'.Str::ulid(), 'request_key' => $key, 'fingerprint' => $hash, 'created_at' => now()]);

            return $this->agreement($id);
        }, 3);
    }

    public function accept(int $id, int $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $a = $this->agreement($id);
            $f = Fleet::whereKey($a->fleet_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $f->owner_id === $actor, 404);
            abort_unless(Carbon::parse($a->expires_at)->gt(now()), 409, 'Agreement expired.');
            if (! DB::table('corporate_agreement_acceptances')->where('agreement_id', $id)->exists()) {
                DB::table('corporate_agreement_acceptances')->insert(['agreement_id' => $id, 'accepted_by' => $actor, 'created_at' => now()]);
            }

            return $a;
        }, 3);
    }

    private function cleanInvoice(Invoice $invoice): void
    {
        abort_unless((int) DB::table('finance_payments')->where('invoice_id', $invoice->id)->sum('amount_minor') === 0, 409, 'Corporate billing only accepts wholly unpaid invoices.');
        abort_if(DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->exists(), 409, 'An invoice with a PSP checkout must remain on its original provider collection path.');
        abort_if(DB::table('finance_refund_requests')->where('invoice_id', $invoice->id)->where('status', 'pending')->exists() || DB::table('gateway_cases')->where('invoice_id', $invoice->id)->where('status', 'open')->exists(), 409, 'Unresolved financial cases hold corporate billing.');
    }

    public function claim(int $agreementId, int $actor, array $v): object
    {
        return DB::transaction(function () use ($agreementId, $actor, $v) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $a = $this->agreement($agreementId);
            $this->fleet((int) $a->fleet_id, $actor);
            $fleet = Fleet::whereKey($a->fleet_id)->lockForUpdate()->firstOrFail();
            if ((int) $fleet->owner_id !== $actor) {
                $member = DB::table('fleet_members')->where('fleet_id', $a->fleet_id)->where('user_id', $actor)->lockForUpdate()->first();
                abort_unless($member && $member->status === 'active', 404);
            }
            $this->active($a);
            $key = $this->key($actor, $v['idempotency_key']);
            $hash = $this->hash([$agreementId, (int) $v['invoice_id'], (int) $v['cost_center_id']]);
            if ($old = $this->replay('corporate_claims', $key, $hash)) {
                return $old;
            }
            $invoice = Invoice::whereKey($v['invoice_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $invoice->user_id === $actor, 404);
            $center = DB::table('cost_centers')->where('id', $v['cost_center_id'])->where('fleet_id', $a->fleet_id)->first();
            abort_unless($center, 404);
            abort_unless($invoice->currency === $a->currency && (int) $invoice->minor_units === (int) $a->minor_units && $invoice->country === Fleet::findOrFail($a->fleet_id)->country, 409, 'Corporate invoice jurisdiction/currency mismatch.');
            $this->cleanInvoice($invoice);
            abort_if(DB::table('corporate_claims')->where('invoice_id', $invoice->id)->exists(), 409, 'This invoice already has a corporate claim.');
            $id = DB::table('corporate_claims')->insertGetId(['agreement_id' => $a->id, 'invoice_id' => $invoice->id, 'driver_id' => $actor, 'cost_center_id' => $center->id, 'cost_center_code' => $center->code, 'cost_center_name' => $center->name, 'amount_minor' => $invoice->total_minor, 'currency' => $a->currency, 'minor_units' => $a->minor_units, 'request_key' => $key, 'fingerprint' => $hash, 'created_at' => now()]);

            return DB::table('corporate_claims')->find($id);
        }, 3);
    }

    public function decide(int $claimId, int $actor, string $decision, string $reason): object
    {
        return DB::transaction(function () use ($claimId, $actor, $decision, $reason) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $claim = DB::table('corporate_claims')->find($claimId) ?? abort(404);
            $a = $this->agreement($claim->agreement_id);
            $this->lockManager((int) $a->fleet_id, $actor);
            if ($old = DB::table('corporate_claim_decisions')->where('claim_id', $claimId)->first()) {
                abort_unless($old->decision === $decision && $old->reason === $reason, 409, 'Claim decision is immutable.');

                return $old;
            }
            if ($decision === 'approved') {
                $this->active($a);
                $this->cleanInvoice(Invoice::whereKey($claim->invoice_id)->lockForUpdate()->firstOrFail());
            }
            $id = DB::table('corporate_claim_decisions')->insertGetId(['claim_id' => $claimId, 'actor_id' => $actor, 'decision' => $decision, 'reason' => $reason, 'created_at' => now()]);

            return DB::table('corporate_claim_decisions')->find($id);
        }, 3);
    }

    public function issue(int $agreementId, int $actor, array $v): object
    {
        return DB::transaction(function () use ($agreementId, $actor, $v) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $a = $this->agreement($agreementId);
            $fleet = $this->lockManager((int) $a->fleet_id, $actor);
            $this->active($a);
            $ids = array_map('intval', $v['claim_ids']);
            sort($ids);
            abort_unless(count($ids) === count(array_unique($ids)), 422, 'Duplicate claim IDs.');
            $key = $this->key($actor, $v['idempotency_key']);
            $hash = $this->hash([$agreementId, $v['period'], $ids]);
            if ($old = $this->replay('corporate_statements', $key, $hash)) {
                return $old;
            }
            $subtotal = 0;
            $tax = 0;
            $total = 0;
            $items = [];
            foreach ($ids as $id) {
                $claim = DB::table('corporate_claims')->where('id', $id)->where('agreement_id', $agreementId)->first();
                abort_unless($claim, 404);
                abort_unless(DB::table('corporate_claim_decisions')->where('claim_id', $id)->where('decision', 'approved')->exists(), 409, 'Every consolidated claim requires approval.');
                abort_if(DB::table('corporate_statement_lines')->where('claim_id', $id)->exists(), 409, 'Claim already consolidated.');
                $invoice = Invoice::whereKey($claim->invoice_id)->lockForUpdate()->firstOrFail();
                $this->cleanInvoice($invoice);
                abort_unless(Carbon::parse($invoice->created_at)->format('Y-m') === $v['period'], 409, 'Invoice is outside statement month.');
                abort_unless($total <= PHP_INT_MAX - (int) $invoice->total_minor, 409, 'Corporate amount overflow.');
                $subtotal += (int) $invoice->subtotal_minor;
                $tax += (int) $invoice->tax_minor;
                $total += (int) $invoice->total_minor;
                $items[] = [$claim, $invoice];
            }
            $open = (int) DB::table('corporate_statements')->where('fleet_id', $a->fleet_id)->where('currency', $a->currency)->whereNotIn('id', DB::table('corporate_receipts')->select('statement_id'))->sum('total_minor');
            abort_unless($total > 0 && $total <= (int) $a->credit_limit_minor - $open, 409, 'Corporate credit limit exceeded.');
            $due = now()->addDays((int) $a->payment_terms_days);
            $id = DB::table('corporate_statements')->insertGetId(['agreement_id' => $a->id, 'fleet_id' => $a->fleet_id, 'issued_by' => $actor, 'payer_id' => $fleet->owner_id, 'number' => 'CB-CORP-'.Str::ulid(), 'period' => $v['period'], 'currency' => $a->currency, 'minor_units' => $a->minor_units, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $total, 'due_at' => $due, 'agreement_snapshot' => json_encode($a, JSON_THROW_ON_ERROR), 'request_key' => $key, 'fingerprint' => $hash, 'created_at' => now()]);
            foreach ($items as [$claim,$invoice]) {
                DB::table('corporate_statement_lines')->insert(['statement_id' => $id, 'claim_id' => $claim->id, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'driver_id' => $claim->driver_id, 'cost_center_id' => $claim->cost_center_id, 'cost_center_code' => $claim->cost_center_code, 'cost_center_name' => $claim->cost_center_name, 'subtotal_minor' => $invoice->subtotal_minor, 'tax_minor' => $invoice->tax_minor, 'total_minor' => $invoice->total_minor, 'created_at' => now()]);
            }

            return DB::table('corporate_statements')->find($id);
        }, 3);
    }

    /** Internal derived state; callers must authorize the containing financial resource first. */
    public function statementStatus(object $statement): string
    {
        if (DB::table('corporate_receipts')->where('statement_id', $statement->id)->exists()) {
            return 'simulated_paid';
        }
        $invoiceIds = DB::table('corporate_statement_lines')->where('statement_id', $statement->id)->select('invoice_id');
        if (DB::table('finance_payments')->whereIn('invoice_id', $invoiceIds)->exists() || DB::table('gateway_checkouts')->whereIn('invoice_id', $invoiceIds)->exists()) {
            return 'collection_attention';
        }

        return Carbon::parse($statement->due_at)->lt(now()) ? 'overdue' : 'issued';
    }

    public function statement(int $id, int $actor): array
    {
        $s = DB::table('corporate_statements')->find($id) ?? abort(404);
        $this->fleet((int) $s->fleet_id, $actor, true);
        $receipt = DB::table('corporate_receipts')->where('statement_id', $id)->first();

        $lines = DB::table('corporate_statement_lines')->where('statement_id', $id)->orderBy('id')->get();
        $invoiceIds = $lines->pluck('invoice_id');
        $paid = (int) DB::table('finance_payments')->whereIn('invoice_id', $invoiceIds)->sum('amount_minor');
        $refunded = (int) DB::table('finance_refunds')->whereIn('invoice_id', $invoiceIds)->sum('amount_minor');

        return ['statement' => $s, 'lines' => $lines, 'receipt' => $receipt, 'state' => $this->statementStatus($s), 'paid_minor' => $paid, 'refunded_minor' => $refunded, 'net_collected_minor' => $paid - $refunded, 'credit_notes' => DB::table('finance_refunds')->whereIn('invoice_id', $invoiceIds)->orderBy('id')->get(['id', 'invoice_id', 'credit_note_number', 'amount_minor', 'tax_minor', 'reason', 'evidence', 'created_at']), 'real_payment_processed' => false];
    }

    public function simulatePayment(int $id, int $actor, string $key): object
    {
        abort_unless(! app()->environment('production') && config('payments.provider') === 'mock', 503, 'Corporate payment simulation requires non-production mock provider.');

        return DB::transaction(function () use ($id, $actor, $key) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $s = DB::table('corporate_statements')->find($id) ?? abort(404);
            $fleet = Fleet::whereKey($s->fleet_id)->lockForUpdate()->firstOrFail();
            // Only the snapshotted corporate payer can authorize even simulated collection.
            abort_unless($actor === (int) $s->payer_id && $actor === (int) $fleet->owner_id, 404);
            $request = $this->key($actor, $key);
            $hash = $this->hash([$id]);
            if ($old = $this->replay('corporate_receipts', $request, $hash)) {
                return $old;
            }
            abort_if(DB::table('corporate_receipts')->where('statement_id', $id)->exists(), 409, 'Statement already paid.');
            $lines = DB::table('corporate_statement_lines')->where('statement_id', $id)->orderBy('invoice_id')->get();
            $payments = [];
            foreach ($lines as $line) {
                $invoice = Invoice::whereKey($line->invoice_id)->lockForUpdate()->firstOrFail();
                $this->cleanInvoice($invoice);
                $payments[] = app(FinanceService::class)->pay($invoice, $actor, (int) $line->total_minor, 'corporate-'.$id.'-'.$line->id);
            }
            $receipt = DB::table('corporate_receipts')->insertGetId(['statement_id' => $id, 'actor_id' => $actor, 'number' => 'SIM-CORP-RCPT-'.Str::ulid(), 'request_key' => $request, 'fingerprint' => $hash, 'amount_minor' => $s->total_minor, 'evidence' => 'SIMULATED', 'created_at' => now()]);
            foreach ($payments as $payment) {
                DB::table('corporate_receipt_payments')->insert(['receipt_id' => $receipt, 'payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id, 'created_at' => now()]);
            }

            return DB::table('corporate_receipts')->find($receipt);
        }, 3);
    }
}

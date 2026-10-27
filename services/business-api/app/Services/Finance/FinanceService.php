<?php

namespace App\Services\Finance;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\Journal;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceService
{
    public function activeTariff(Station $station, ?\DateTimeInterface $at = null): ?TariffVersion
    {
        return TariffVersion::where('station_id', $station->id)->where('effective_from', '<=', $at ?? now())->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function snapshotSession(ChargingSession $session, ?TariffVersion $selectedTariff = null): SessionQuote
    {
        return DB::transaction(function () use ($session, $selectedTariff) {
            $session = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $old = SessionQuote::where('charging_session_id', $session->id)->first();
            if ($old) {
                return $old;
            }
            $reservation = Reservation::findOrFail($session->reservation_id);
            $station = Station::findOrFail($reservation->station_id);
            abort_unless($station->source === 'simulator', 409, 'Only simulated finance is enabled.');
            $tariff = $selectedTariff;
            if ($tariff) {
                abort_unless($tariff->station_id === $station->id && $tariff->effective_from <= $session->created_at->format('Y-m-d H:i:s'), 409, 'Tariff is not effective for this session.');
            }
            $region = DB::table('regions')->where('country', $station->country)->first();
            abort_unless($session->currency === $station->currency, 409, 'Session currency mismatch.');
            if ($tariff) {
                abort_unless($tariff->rate_minor_per_kwh === $session->rate_minor_per_kwh && $tariff->currency === $session->currency, 409, 'Session rate does not match its effective tariff; reconcile before invoicing.');
            }

            return SessionQuote::create(['charging_session_id' => $session->id, 'tariff_version_id' => $tariff?->id, 'user_id' => $reservation->user_id, 'vendor_id' => $station->vendor_id, 'country' => $station->country, 'currency' => $session->currency, 'minor_units' => $tariff?->minor_units ?? $region?->minor_units ?? 2, 'rate_minor_per_kwh' => $session->rate_minor_per_kwh, 'tax_bps' => $tariff?->tax_bps ?? 0, 'commission_bps' => $tariff?->commission_bps ?? 0, 'evidence' => 'SIMULATED']);
        }, 3);
    }

    public function issue(ChargingSession $session, int $actorId): Invoice
    {
        abort_if(app()->environment('production'), 503, 'Simulated invoicing is disabled in production.');

        return DB::transaction(function () use ($session, $actorId) {
            $session = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $old = Invoice::where('charging_session_id', $session->id)->first();
            if ($old) {
                return $old;
            }
            abort_unless($session->status === 'stopped' && $session->stopped_at !== null, 409, 'Finalize the simulated session first.');
            $quote = $this->snapshotSession($session);
            $subtotal = Money::multiplyDivide((int) $session->energy_wh, (int) $quote->rate_minor_per_kwh, 1000);
            abort_unless($session->total_minor === $subtotal, 409, 'Session total does not reconcile with its energy and rate.');
            $tax = Money::multiplyDivide($subtotal, (int) $quote->tax_bps, 10000);
            $commission = Money::multiplyDivide($subtotal, (int) $quote->commission_bps, 10000);
            $vendor = $subtotal - $commission;
            $invoice = Invoice::create(['number' => 'SIM-'.Str::ulid(), 'charging_session_id' => $session->id, 'quote_id' => $quote->id, 'user_id' => $quote->user_id, 'vendor_id' => $quote->vendor_id, 'country' => $quote->country, 'currency' => $quote->currency, 'minor_units' => $quote->minor_units, 'energy_wh' => $session->energy_wh, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'commission_minor' => $commission, 'vendor_minor' => $vendor, 'total_minor' => $subtotal + $tax, 'evidence' => 'SIMULATED']);
            $this->post($invoice, 'invoice', 'invoice:'.$invoice->id, ['total' => $subtotal + $tax], $subtotal + $tax, [['receivable:user:'.$invoice->user_id, $subtotal + $tax, 0], ['vendor_payable:'.$invoice->vendor_id, 0, $vendor], ['platform_commission', 0, $commission], ['tax_payable:'.$invoice->country, 0, $tax]]);
            $this->audit($actorId, 'finance.simulated_invoice_issued', $invoice->id);

            return $invoice;
        }, 3);
    }

    private function post(Invoice $invoice, string $kind, string $key, array $payload, int $amount, array $entries): Journal
    {
        $hash = hash('sha256', json_encode([$invoice->id, $payload], JSON_THROW_ON_ERROR));
        $old = Journal::where('idempotency_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Finance idempotency key payload mismatch.');

            return $old;
        }
        $debits = 0;
        $credits = 0;
        foreach ($entries as [$account,$debit,$credit]) {
            if (! is_int($debit) || ! is_int($credit) || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw new \InvalidArgumentException('Invalid journal entry.');
            }$debits += $debit;
            $credits += $credit;
        }
        if ($debits !== $credits || $debits !== $amount) {
            throw new \LogicException('Unbalanced journal rejected.');
        }
        $journal = Journal::create(['invoice_id' => $invoice->id, 'kind' => $kind, 'idempotency_key' => $key, 'fingerprint' => $hash, 'currency' => $invoice->currency, 'amount_minor' => $amount]);
        foreach ($entries as [$account,$debit,$credit]) {
            if ($debit !== 0 || $credit !== 0) {
                JournalEntry::create(['journal_id' => $journal->id, 'account' => $account, 'debit_minor' => $debit, 'credit_minor' => $credit]);
            }
        }

        return $journal;
    }

    private function simulated(Invoice $invoice): void
    {
        abort_unless($invoice->evidence === 'SIMULATED' && ! app()->environment('production'), 503, 'Only explicitly simulated finance is enabled; no PSP is connected.');
    }

    public function pay(Invoice $invoice, int $actorId, int $amount, string $key): Payment
    {
        $this->simulated($invoice);

        try {
            return DB::transaction(function () use ($invoice, $actorId, $amount, $key) {
                $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $eventKey = 'payment:'.$actorId.':'.$key;
                $prior = Journal::where('idempotency_key', $eventKey)->first();
                if ($prior) {
                    abort_unless($prior->invoice_id === $invoice->id && $prior->amount_minor === $amount, 409, 'Finance idempotency key payload mismatch.');

                    return Payment::where('journal_id', $prior->id)->firstOrFail();
                }
                $paid = (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor');
                abort_unless($amount > 0 && $amount <= $invoice->total_minor - $paid, 409, 'Payment exceeds the unpaid invoice amount.');
                $j = $this->post($invoice, 'payment', $eventKey, ['amount' => $amount], $amount, [['cash:simulator', $amount, 0], ['receivable:user:'.$invoice->user_id, 0, $amount]]);
                $payment = Payment::create(['invoice_id' => $invoice->id, 'journal_id' => $j->id, 'actor_id' => $actorId, 'amount_minor' => $amount, 'evidence' => 'SIMULATED']);
                $this->audit($actorId, 'finance.simulated_payment', $payment->id);

                return $payment;
            }, 3);
        } catch (QueryException $exception) {
            $prior = $this->journalAfterUniqueConflict($exception, 'payment:'.$actorId.':'.$key);
            abort_unless($prior->invoice_id === $invoice->id && $prior->amount_minor === $amount, 409, 'Finance idempotency key payload mismatch.');

            return Payment::where('journal_id', $prior->id)->firstOrFail();
        }
    }

    public function refund(Invoice $invoice, int $actorId, int $amount, string $key, string $reason): Refund
    {
        $this->simulated($invoice);

        try {
            return DB::transaction(function () use ($invoice, $actorId, $amount, $key, $reason) {
                $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $eventKey = 'refund:'.$actorId.':'.$key;
                $prior = Journal::where('idempotency_key', $eventKey)->first();
                if ($prior) {
                    $old = Refund::where('journal_id', $prior->id)->firstOrFail();
                    abort_unless($prior->invoice_id === $invoice->id && $prior->amount_minor === $amount && $old->reason === $reason, 409, 'Finance idempotency key payload mismatch.');

                    return $old;
                }
                $refunded = (int) Refund::where('invoice_id', $invoice->id)->sum('amount_minor');
                $paid = (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor');
                abort_unless($amount > 0 && $amount <= $paid - $refunded, 409, 'Refund exceeds captured simulated funds.');
                $remaining = ['tax_minor' => (int) $invoice->tax_minor - (int) Refund::where('invoice_id', $invoice->id)->sum('tax_minor'), 'commission_minor' => (int) $invoice->commission_minor - (int) Refund::where('invoice_id', $invoice->id)->sum('commission_minor'), 'vendor_minor' => (int) $invoice->vendor_minor - (int) Refund::where('invoice_id', $invoice->id)->sum('vendor_minor')];
                $allocation = Money::allocate($amount, $remaining);
                $j = $this->post($invoice, 'refund', $eventKey, ['amount' => $amount, 'reason' => $reason], $amount, [['tax_payable:'.$invoice->country, $allocation['tax_minor'], 0], ['platform_commission', $allocation['commission_minor'], 0], ['vendor_payable:'.$invoice->vendor_id, $allocation['vendor_minor'], 0], ['cash:simulator', 0, $amount]]);
                $refund = Refund::create(['invoice_id' => $invoice->id, 'journal_id' => $j->id, 'actor_id' => $actorId, 'credit_note_number' => 'SIM-CN-'.Str::ulid(), 'amount_minor' => $amount, ...$allocation, 'reason' => $reason, 'evidence' => 'SIMULATED']);
                $this->audit($actorId, 'finance.simulated_refund', $refund->id);

                return $refund;
            }, 3);
        } catch (QueryException $exception) {
            $prior = $this->journalAfterUniqueConflict($exception, 'refund:'.$actorId.':'.$key);
            $old = Refund::where('journal_id', $prior->id)->firstOrFail();
            abort_unless($prior->invoice_id === $invoice->id && $prior->amount_minor === $amount && $old->reason === $reason, 409, 'Finance idempotency key payload mismatch.');

            return $old;
        }
    }

    private function journalAfterUniqueConflict(QueryException $exception, string $key): Journal
    {
        // Different invoices have different row locks. Their actor-scoped request
        // keys can still race on the shared journal constraint. Recover only a
        // committed matching key after rolling back the failed transaction.
        if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            throw $exception;
        }
        $journal = Journal::where('idempotency_key', $key)->first();
        if (! $journal) {
            throw $exception;
        }

        return $journal;
    }

    public function summary(Invoice $invoice): array
    {
        $paid = (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor');
        $refunded = (int) Refund::where('invoice_id', $invoice->id)->sum('amount_minor');
        $due = max(0, $invoice->total_minor - $paid);
        $entries = DB::table('finance_entries')->whereIn('journal_id', Journal::where('invoice_id', $invoice->id)->select('id'));
        $debit = (int) (clone $entries)->sum('debit_minor');
        $credit = (int) (clone $entries)->sum('credit_minor');

        return ['invoice' => $invoice, 'paid_minor' => $paid, 'refunded_minor' => $refunded, 'net_collected_minor' => $paid - $refunded, 'credit_notes_minor' => $refunded, 'amount_due_minor' => $due, 'state' => $refunded > 0 ? ($refunded === $invoice->total_minor ? 'refunded' : 'partially_refunded') : ($due === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')), 'ledger_balanced' => $debit === $credit, 'payment_provider' => 'SIMULATOR_ONLY', 'real_payment_processed' => false];
    }

    private function audit(int $actor, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }
}

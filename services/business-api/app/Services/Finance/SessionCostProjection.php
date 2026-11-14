<?php

namespace App\Services\Finance;

use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\SessionQuote;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Read-only presentation of a scoped session's canonical quote and invoice. */
final class SessionCostProjection
{
    public function forSessions(Collection $sessions, Collection $links): Collection
    {
        $ids = $sessions->pluck('id');
        $quotes = SessionQuote::whereIn('charging_session_id', $ids)->get()->keyBy('charging_session_id');
        $invoices = Invoice::whereIn('charging_session_id', $ids)->get()->keyBy('charging_session_id');
        $owners = Reservation::whereIn('id', $sessions->pluck('reservation_id'))->pluck('user_id', 'id');

        return $sessions->mapWithKeys(fn ($session) => [$session->id => $this->present($session, $quotes->get($session->id), $invoices->get($session->id), $links->get($session->id), (int) $owners->get($session->reservation_id))]);
    }

    public function present(ChargingSession $session, ?SessionQuote $quote, ?Invoice $invoice, ?SessionLink $link, int $owner): array
    {
        $base = ['state' => 'unavailable', 'evidence' => 'SIMULATED', 'scope' => 'CANONICAL_SESSION_QUOTE',
            'as_of' => now()->toISOString(), 'physical_charging_verified' => false, 'real_payment_processed' => false,
            'external_fees_included' => false, 'spending_cap' => false, 'automatic_stop' => false];
        if (! $quote || $quote->user_id !== $owner || $quote->evidence !== 'SIMULATED' || $quote->currency !== $session->currency
            || $quote->rate_minor_per_kwh !== (int) $session->rate_minor_per_kwh || $quote->minor_units < 0 || $quote->minor_units > 3
            || ! preg_match('/^[A-Z]{3}$/D', $quote->currency) || $quote->rate_minor_per_kwh < 0 || $quote->rate_minor_per_kwh > 1000000
            || $quote->tax_bps < 0 || $quote->tax_bps > 10000 || (int) $session->energy_wh < 0) {
            return $base;
        }
        if ($link && ($link->started_at === null || $link->last_meter_wh === null || $link->meter_start_wh === null || $link->last_meter_wh < $link->meter_start_wh || $link->last_meter_wh - $link->meter_start_wh !== (int) $session->energy_wh)) {
            return array_replace($base, ['state' => 'awaiting_meter']);
        }
        try {
            $subtotal = Money::multiplyDivide((int) $session->energy_wh, $quote->rate_minor_per_kwh, 1000);
            $tax = Money::multiplyDivide($subtotal, $quote->tax_bps, 10000);
        } catch (InvalidArgumentException) {
            return $base;
        }
        if ($subtotal > PHP_INT_MAX - $tax) {
            return $base;
        }
        $total = $subtotal + $tax;
        if ($invoice && ($invoice->quote_id !== $quote->id || $invoice->user_id !== $owner || $invoice->evidence !== 'SIMULATED'
            || $invoice->currency !== $quote->currency || $invoice->minor_units !== $quote->minor_units || $invoice->energy_wh !== (int) $session->energy_wh
            || $invoice->subtotal_minor !== $subtotal || $invoice->tax_minor !== $tax || $invoice->total_minor !== $total
            || $session->status !== 'stopped' || $session->stopped_at === null)) {
            return $base;
        }
        $at = $link?->last_event_at;
        $age = $at && $at->lte(now()) ? now()->timestamp - $at->timestamp : null;

        return array_replace($base, ['state' => $invoice ? 'invoiced' : 'estimated', 'currency' => $quote->currency, 'minor_units' => $quote->minor_units,
            'rate_minor_per_kwh' => (string) $quote->rate_minor_per_kwh, 'tax_bps' => $quote->tax_bps,
            'energy_wh' => (string) $session->energy_wh, 'subtotal_minor' => (string) $subtotal, 'tax_minor' => (string) $tax, 'total_minor' => (string) $total,
            'invoice_id' => $invoice?->id, 'source_meter_at' => $at?->toISOString(),
            'meter_freshness_at_read' => ! $link ? 'APPLICATION_SIMULATOR' : ($age === null ? 'UNKNOWN' : ($age < 300 ? 'RECENT' : 'STALE'))]);
    }
}

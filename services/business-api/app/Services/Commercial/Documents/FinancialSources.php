<?php

namespace App\Services\Commercial\Documents;

use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use Illuminate\Support\Facades\DB;

class FinancialSources
{
    public const TABLES = [
        'charging_invoice' => 'finance_invoices',
        'charging_credit' => 'finance_refunds',
        'subscription_invoice' => 'commercial_subscription_invoices',
        'subscription_credit' => 'subscription_credits',
        'vendor_settlement' => 'commercial_settlements',
        'corporate_statement' => 'corporate_statements',
    ];

    public function read(User $actor, string $kind, int $id, bool $lock = false): array
    {
        abort_unless(isset(self::TABLES[$kind]), 422);
        $query = DB::table(self::TABLES[$kind])->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        $credit = str_ends_with($kind, '_credit');
        if ($kind === 'corporate_statement') {
            $detail = app(CorporateBilling::class)->statement($id, $actor->id);
            $user = (int) $row->payer_id;
            $vendor = null;
            $lines = $detail['lines']->map(fn ($line) => ['reference' => $line->invoice_number, 'invoice_id' => (int) $line->invoice_id, 'amount_minor' => (int) $line->total_minor, 'subtotal_minor' => (int) $line->subtotal_minor, 'tax_minor' => (int) $line->tax_minor, 'cost_center_code' => $line->cost_center_code, 'cost_center_name' => $line->cost_center_name, 'reason' => $line->cost_center_code.' — '.$line->cost_center_name])->all();
            abort_unless(array_sum(array_column($lines, 'amount_minor')) === (int) $row->total_minor && array_sum(array_column($lines, 'subtotal_minor')) === (int) $row->subtotal_minor && array_sum(array_column($lines, 'tax_minor')) === (int) $row->tax_minor, 409, 'Corporate statement source lines do not reconcile.');
            $countries = DB::table('finance_invoices')->whereIn('id', array_column($lines, 'invoice_id'))->pluck('country')->unique();
            abort_unless($countries->count() === 1, 409, 'Corporate statement requires one immutable invoice jurisdiction.');
            $values = [
                'country' => $countries->first(), 'issuer_vendor_id' => null, 'tax_bps' => null,
                'original_number' => $row->number, 'invoice_reference' => $row->number,
                'subtotal_minor' => (int) $row->subtotal_minor, 'tax_minor' => (int) $row->tax_minor, 'total_minor' => (int) $row->total_minor,
                'currency' => $row->currency, 'minor_units' => (int) $row->minor_units,
                'evidence' => 'SIMULATED_CORPORATE_STATEMENT', 'state_at_generation' => $detail['state'],
                'description_en' => 'Corporate consolidated statement', 'description_ar' => 'كشف حساب شركة مجمع',
                'lines' => $lines, 'period' => $row->period, 'due_at' => $row->due_at,
                'tax_treatment' => 'CONSOLIDATED_SOURCE_TAX_NO_NEW_TAX',
                'status_notice_en' => 'Corporate statement state at generation: '.$detail['state'].'. Consolidation does not replace underlying invoices or prove real payment.',
                'status_notice_ar' => 'حالة كشف الشركة وقت الإنشاء: '.$detail['state'].'. التجميع لا يحل محل الفواتير الأصلية ولا يثبت دفعاً فعلياً.',
            ];
        } elseif ($kind === 'vendor_settlement') {
            $user = $vendor = (int) $row->vendor_id;
            abort_unless($actor->id === $vendor || $actor->role === 'admin', 404);
            $lines = DB::table('commercial_settlement_lines')->where('settlement_id', $id)->orderBy('id')->get()->map(function ($line) {
                $invoice = $line->invoice_id ? DB::table('finance_invoices')->find($line->invoice_id) : null;
                $adjustment = $line->adjustment_id ? DB::table('commercial_adjustments')->find($line->adjustment_id) : null;
                abort_unless(($invoice !== null) !== ($adjustment !== null), 409, 'Settlement source line requires reconciliation.');

                return ['invoice_id' => $line->invoice_id ? (int) $line->invoice_id : null, 'adjustment_id' => $line->adjustment_id ? (int) $line->adjustment_id : null, 'reference' => $invoice?->number ?? ('ADJ-'.$adjustment->id), 'amount_minor' => (int) $line->amount_minor, 'reason' => $adjustment?->reason];
            })->all();
            abort_unless(array_sum(array_column($lines, 'amount_minor')) === (int) $row->amount_minor, 409, 'Settlement statement lines do not reconcile.');
            $values = [
                'country' => null, 'issuer_vendor_id' => null, 'tax_bps' => null,
                'original_number' => $row->number, 'invoice_reference' => $row->number,
                'subtotal_minor' => (int) $row->amount_minor, 'tax_minor' => 0, 'total_minor' => (int) $row->amount_minor,
                'currency' => $row->currency, 'minor_units' => (int) $row->minor_units,
                'evidence' => $row->evidence, 'state_at_generation' => $row->state,
                'description_en' => 'Vendor settlement statement', 'description_ar' => 'كشف تسوية المورد',
                'lines' => $lines, 'tax_treatment' => 'STATEMENT_ONLY_NO_NEW_TAX',
                'status_notice_en' => 'Settlement state at generation: '.$row->state.'. This snapshot is not proof of a real bank transfer; consult the current payout record.',
                'status_notice_ar' => 'حالة التسوية وقت الإنشاء: '.$row->state.'. هذه النسخة لا تثبت تحويلاً مصرفياً فعلياً؛ راجع سجل الصرف الحالي.',
            ];
        } elseif (str_starts_with($kind, 'charging_')) {
            $invoice = $credit ? DB::table('finance_invoices')->find($row->invoice_id) : $row;
            $user = (int) $invoice->user_id;
            $vendor = (int) $invoice->vendor_id;
            $quote = DB::table('finance_session_quotes')->find($invoice->quote_id);
            $values = [
                'country' => $invoice->country,
                'tax_bps' => (int) $quote->tax_bps,
                'original_number' => $credit ? $row->credit_note_number : $row->number,
                'invoice_reference' => $invoice->number,
                'subtotal_minor' => $credit ? (int) $row->amount_minor - (int) $row->tax_minor : (int) $row->subtotal_minor,
                'tax_minor' => (int) $row->tax_minor,
                'total_minor' => $credit ? (int) $row->amount_minor : (int) $row->total_minor,
                'currency' => $invoice->currency, 'minor_units' => (int) $invoice->minor_units,
                'evidence' => $row->evidence,
                'description_en' => $credit ? 'Charging credit note' : 'Charging session',
                'description_ar' => $credit ? 'إشعار دائن للشحن' : 'جلسة شحن',
                'energy_wh' => $credit ? null : (int) $invoice->energy_wh,
            ];
        } else {
            $invoice = $credit ? DB::table('commercial_subscription_invoices')->find($row->invoice_id) : $row;
            $subscription = DB::table('commercial_subscriptions')->find($invoice->subscription_id);
            $plan = DB::table('commercial_plan_versions')->find($invoice->plan_id);
            $user = (int) $subscription->user_id;
            $vendor = null;
            $values = [
                'country' => null, 'tax_bps' => (int) $plan->tax_bps,
                'original_number' => $row->number, 'invoice_reference' => $invoice->number,
                'subtotal_minor' => (int) $row->subtotal_minor, 'tax_minor' => (int) $row->tax_minor,
                'total_minor' => $credit ? (int) $row->amount_minor : (int) $row->total_minor,
                'currency' => $invoice->currency, 'minor_units' => (int) $invoice->minor_units,
                'evidence' => $row->evidence,
                'description_en' => ($credit ? 'Subscription credit note — ' : 'Subscription — ').$plan->name,
                'description_ar' => ($credit ? 'إشعار دائن للاشتراك — ' : 'اشتراك — ').$plan->name,
                'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end,
            ];
        }
        abort_unless($kind === 'corporate_statement' || $actor->id === $user || ($actor->role === 'vendor' && $actor->id === $vendor) || $actor->role === 'admin', 404);
        abort_unless($values['minor_units'] >= 0 && $values['minor_units'] <= 3 && $values['total_minor'] === $values['subtotal_minor'] + $values['tax_minor'], 409, 'Source financial record does not reconcile.');
        $buyer = User::findOrFail($user);

        return $values + ['source_kind' => $kind, 'source_id' => $id, 'user_id' => $user, 'vendor_id' => $vendor, 'issued_at' => $row->created_at, 'reason' => $credit ? $row->reason : null, 'recipient' => ['id' => $user, 'name' => $buyer->name, 'email' => $buyer->email]];
    }
}

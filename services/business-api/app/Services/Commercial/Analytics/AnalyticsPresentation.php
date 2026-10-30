<?php

namespace App\Services\Commercial\Analytics;

use App\Services\Commercial\Documents\DocumentRenderer;

class AnalyticsPresentation
{
    public const CATEGORIES = [
        'charging_invoiced' => ['Charging invoices issued', 'فواتير الشحن الصادرة'],
        'charging_collected' => ['Charging receipts posted', 'مقبوضات الشحن المسجلة'],
        'charging_refunded' => ['Charging refunds posted', 'مبالغ الشحن المستردة'],
        'commission_allocated' => ['Commission allocations', 'تخصيصات العمولة'],
        'commission_reversed' => ['Commission allocations reversed', 'عكس تخصيصات العمولة'],
        'vendor_allocated' => ['Vendor allocations', 'تخصيصات المورد'],
        'vendor_allocation_reversed' => ['Vendor allocations reversed', 'عكس تخصيصات المورد'],
        'settlement_instruction' => ['Settlement instructions', 'تعليمات التسوية'],
        'payout_instruction' => ['Payout instructions', 'تعليمات التحويل'],
        'subscription_invoiced' => ['Subscription invoices issued', 'فواتير الاشتراكات الصادرة'],
        'subscription_collected' => ['Subscription receipts posted', 'مقبوضات الاشتراكات المسجلة'],
        'subscription_refunded' => ['Subscription credits posted', 'الإشعارات الدائنة للاشتراكات'],
        'provider_dispute' => ['Provider disputes', 'نزاعات مزود الدفع'],
        'corporate_invoiced' => ['Consolidated company references', 'مراجع فواتير الشركات المجمعة'],
        'corporate_receivable' => ['Company amounts outstanding', 'مبالغ الشركات المستحقة'],
    ];

    public const STATES = [
        'issued' => ['Issued', 'صادر'], 'posted' => ['Posted', 'مسجل'], 'allocated' => ['Allocated', 'مخصص'],
        'pending' => ['Pending', 'قيد الانتظار'], 'approved' => ['Approved', 'معتمد'], 'rejected' => ['Rejected', 'مرفوض'],
        'simulated_paid' => ['Simulated payment recorded', 'دفع محاكى مسجل'], 'provider_paid' => ['TEST provider payout recorded', 'تحويل مزود اختبار مسجل'],
        'prepared' => ['Prepared', 'مُعد'], 'executing' => ['Executing', 'قيد التنفيذ'], 'paid' => ['Provider reports paid (TEST)', 'دفع مسجل لدى مزود الاختبار'],
        'failed' => ['Failed', 'فشل'], 'uncertain' => ['Outcome uncertain', 'النتيجة غير مؤكدة'], 'needs_review' => ['Needs investigation', 'يحتاج مراجعة'],
        'reversed' => ['Reversed', 'معكوس'], 'cancelled' => ['Cancelled', 'ملغى'],
        'needs_response' => ['Evidence required', 'الأدلة مطلوبة'], 'warning_needs_response' => ['Provider inquiry needs evidence', 'استفسار المزود يحتاج أدلة'],
        'under_review' => ['Under provider review', 'قيد مراجعة المزود'], 'warning_under_review' => ['Inquiry under review', 'الاستفسار قيد المراجعة'],
        'warning_closed' => ['Inquiry closed', 'الاستفسار مغلق'], 'won' => ['Provider case won', 'حالة المزود محسومة لصالح التاجر'], 'lost' => ['Provider case lost; accounting review required', 'حالة المزود خاسرة؛ المراجعة المحاسبية مطلوبة'],
        'consolidated_reference' => ['References existing charging invoices', 'مرجع لفواتير شحن موجودة'],
        'not_due' => ['Not yet due', 'لم يستحق بعد'], 'overdue_1_30' => ['Overdue1–30days', 'متأخر١–٣٠ يوماً'], 'overdue_31_60' => ['Overdue31–60days', 'متأخر٣١–٦٠ يوماً'],
        'overdue_61_90' => ['Overdue61–90days', 'متأخر٦١–٩٠ يوماً'], 'overdue_over_90' => ['Overdue over90days', 'متأخر أكثر من٩٠ يوماً'],
    ];

    public function pdf(array $report, string $locale): string
    {
        abort_unless(in_array($locale, ['en', 'ar'], true), 422);
        abort_if(count($report['groups']) + count($report['points']['groups']) + count($report['recurring_run_rates']) > 600, 409, 'Narrow the report period/currency for a PDF of at most600groups. Full CSV remains available.');
        if (! defined('K_TCPDF_EXTERNAL_CONFIG')) {
            define('K_TCPDF_EXTERNAL_CONFIG', true);
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', resource_path('financial-fonts').'/');
        }
        if (! defined('PDF_FONT_NAME_MAIN')) {
            define('PDF_FONT_NAME_MAIN', 'dejavusans');
        }
        $ar = $locale === 'ar';
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Crea-Biz EV');
        $pdf->SetTitle('Crea-Biz financial analytics');
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->setFontSubsetting(true);
        $pdf->setRTL($ar);
        $pdf->AddPage();
        $html = view('financial.analytics', compact('report', 'ar'))->render();
        $pdf->writeHTML($html, true, false, true, false, $ar ? 'R' : 'L');
        $bytes = $pdf->Output('financial-analytics.pdf', 'S');
        abort_unless(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 503, 'Financial report rendering failed.');

        return $bytes;
    }

    public static function label(string $category, bool $ar): string
    {
        return (self::CATEGORIES[$category] ?? [$category, $category])[$ar ? 1 : 0];
    }

    public static function state(string $state, bool $ar): string
    {
        return (self::STATES[$state] ?? [$state, $state])[$ar ? 1 : 0];
    }

    public static function pointKind(string $kind, bool $ar): string
    {
        return (['earn' => ['Earned', 'مكتسب'], 'redeem' => ['Redeemed', 'مستبدل'], 'release' => ['Released', 'محرر'], 'expiry' => ['Expired', 'منتهٍ'], 'reversal' => ['Reversed', 'معكوس']][$kind] ?? [$kind, $kind])[$ar ? 1 : 0];
    }

    public static function amount(int $amount, int $precision): string
    {
        return DocumentRenderer::signedMoney($amount, $precision);
    }
}

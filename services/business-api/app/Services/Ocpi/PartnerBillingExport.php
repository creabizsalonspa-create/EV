<?php

namespace App\Services\Ocpi;

/** Review artifacts only: each CDR remains a separate reference, never a payable. */
class PartnerBillingExport
{
    private const HEADERS = ['source_id', 'country', 'party', 'kind', 'source_currency', 'source_status', 'billing_status', 'invoice', 'invoice_currency', 'minor_units', 'invoice_total_minor', 'paid_minor', 'refunded_minor', 'noncash_credit_minor', 'due_minor', 'ledger_balanced', 'evidence'];

    private function values(array $row): array
    {
        $billing = $row['billing'];
        $invoice = $billing['invoice'] ?? [];

        return [$row['external_id'], $row['country_code'], $row['party_id'], $row['credit'] ? 'credit_reference' : 'charge', $row['currency'], $row['source']['status'] ?? 'requires_review', $billing['status'], $invoice['number'] ?? '', $invoice['currency'] ?? '', $invoice['minor_units'] ?? '', $invoice['total_minor'] ?? '', $billing['paid_minor'] ?? '', $billing['refunded_minor'] ?? '', $billing['unpaid_credited_minor'] ?? '', $billing['due_minor'] ?? '', isset($billing['ledger_balanced']) ? ($billing['ledger_balanced'] ? 'yes' : 'no') : '', 'READ_ONLY_TEST_RECONCILIATION'];
    }

    public function csv(array $rows): string
    {
        $file = fopen('php://temp', 'w+');
        try {
            fputcsv($file, self::HEADERS, ',', '"', '');
            foreach ($rows as $row) {
                $values = array_map(function ($value) {
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s\p{C}]*[=+\-@]/u', $text) ? "'".$text : $text;
                }, $this->values($row));
                fputcsv($file, $values, ',', '"', '');
            }
            rewind($file);

            return stream_get_contents($file);
        } finally {
            fclose($file);
        }
    }

    public function pdf(array $rows, int $provider, array $filters): string
    {
        if (! defined('K_TCPDF_EXTERNAL_CONFIG')) {
            define('K_TCPDF_EXTERNAL_CONFIG', true);
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', resource_path('financial-fonts').'/');
        }
        if (! defined('PDF_FONT_NAME_MAIN')) {
            define('PDF_FONT_NAME_MAIN', 'dejavusans');
        }
        $ar = ($filters['locale'] ?? 'en') === 'ar';
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('Partner billing reconciliation');
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->setFontSubsetting(true);
        $pdf->setRTL($ar);
        $pdf->AddPage();
        $notice = $ar
            ? 'مطابقة الشريك للعرض فقط. سجل اختبار وليس إثبات دفع مصرفي أو تسوية. الإشعار الدائن مرجع للفاتورة الأصلية؛ لا تجمع السجلات كإيراد.'
            : 'Read-only partner reconciliation. TEST ledger, not bank payment or partner settlement. Credits reference the original invoice; do not sum rows as revenue.';
        $pdf->MultiCell(0, 6, $notice, 0, 'L');
        $pdf->MultiCell(0, 6, 'Provider: '.$provider.' | UTC: '.now()->toIso8601String().' | Records: '.count($rows), 0, 'L');
        $pdf->MultiCell(0, 6, 'Filters: '.json_encode(array_diff_key($filters, ['locale' => true]), JSON_UNESCAPED_UNICODE), 0, 'L');
        foreach ($rows as $row) {
            $pdf->Ln(3);
            foreach (array_combine(self::HEADERS, $this->values($row)) as $label => $value) {
                // Plain text only; partner strings never become HTML, links or paths.
                $pdf->MultiCell(0, 5, $label.': '.preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($value ?? '')), 0, 'L');
            }
        }
        $result = $pdf->Output('partner-billing.pdf', 'S');
        if (! is_string($result) || ! str_starts_with($result, '%PDF-')) {
            throw new \RuntimeException('Partner report rendering failed.');
        }

        return $result;
    }
}

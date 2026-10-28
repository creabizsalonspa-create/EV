<?php

namespace App\Services\Commercial\Documents;

class DocumentRenderer
{
    public static function money(int $minor, int $precision): string
    {
        if ($minor < 0 || $precision < 0 || $precision > 3) {
            throw new \InvalidArgumentException('Invalid document money.');
        }
        $scale = 10 ** $precision;

        return (string) intdiv($minor, $scale).($precision ? '.'.str_pad((string) ($minor % $scale), $precision, '0', STR_PAD_LEFT) : '');
    }

    public static function signedMoney(int $minor, int $precision): string
    {
        if ($minor === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Statement amount cannot be formatted safely.');
        }

        return ($minor < 0 ? '-' : '').self::money(abs($minor), $precision);
    }

    public function html(array $snapshot): string
    {
        return view('financial.document', ['d' => $snapshot, 'ar' => $snapshot['locale'] === 'ar'])->render();
    }

    public function pdf(array $snapshot): string
    {
        // TCPDF 7 is the compatibility facade over the modern tc-lib-pdf engine.
        // Only the maintained static template receives escaped strings. No HTML,
        // image, URL, JavaScript, attachment or font path is accepted from users.
        if (! defined('K_TCPDF_EXTERNAL_CONFIG')) {
            define('K_TCPDF_EXTERNAL_CONFIG', true);
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', resource_path('financial-fonts').'/');
        }
        if (! defined('PDF_FONT_NAME_MAIN')) {
            define('PDF_FONT_NAME_MAIN', 'dejavusans');
        }
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Crea-Biz EV');
        $pdf->SetAuthor('Crea-Biz EV');
        $pdf->SetTitle($snapshot['number']);
        $pdf->SetMargins(18, 18, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->setFontSubsetting(true);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->setRTL($snapshot['locale'] === 'ar');
        $pdf->AddPage();
        $pdf->writeHTML($this->html($snapshot), true, false, true, false, $snapshot['locale'] === 'ar' ? 'R' : 'L');
        $result = $pdf->Output($snapshot['number'].'.pdf', 'S');
        if (! is_string($result) || ! str_starts_with($result, '%PDF-') || ! str_contains(substr($result, -64), '%%EOF')) {
            throw new \RuntimeException('Financial PDF rendering failed.');
        }

        return $result;
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        // Spreadsheet clients may strip whitespace, control and bidi formatting
        // before evaluating a formula. Quote dangerous text before that happens.
        return preg_match('/^[\s\p{C}]*[=+\-@]/u', $text) ? "'".$text : $text;
    }

    public function csv(array $d): string
    {
        $ar = $d['locale'] === 'ar';
        $headers = $ar ? ['رقم المستند', 'المرجع الأصلي', 'مرجع الفاتورة', 'النوع', 'تاريخ الإصدار', 'الجهة المصدرة', 'المستلم', 'العملة', 'المنازل العشرية', 'المجموع الفرعي', 'الضريبة', 'الإجمالي', 'دليل المصدر', 'المراجعة القانونية', 'نسخة الجهة المصدرة', 'السبب'] : ['Document number', 'Original reference', 'Invoice reference', 'Type', 'Issued at UTC', 'Issuer', 'Recipient', 'Currency', 'Minor units', 'Subtotal', 'Tax', 'Total', 'Source evidence', 'Legal review', 'Issuer version', 'Reason'];
        $values = [$d['number'], $d['source']['original_number'], $d['source']['invoice_reference'], $d['source']['source_kind'], $d['source']['issued_at'], $d['issuer'][$ar ? 'legal_name_ar' : 'legal_name'], $d['source']['recipient']['name'], $d['source']['currency'], $d['source']['minor_units'], self::money($d['source']['subtotal_minor'], $d['source']['minor_units']), self::money($d['source']['tax_minor'], $d['source']['minor_units']), self::money($d['source']['total_minor'], $d['source']['minor_units']), $d['source']['evidence'], 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED', $d['issuer']['version'], $d['source']['reason']];
        $headers = array_merge($headers, $ar ? ['فترة الفوترة', 'تاريخ الاستحقاق — UTC', 'المعاملة الضريبية', 'الحالة وقت الإنشاء'] : ['Billing period', 'Due at UTC', 'Tax treatment', 'State at generation']);
        $values = array_merge($values, [$d['source']['period'] ?? null, $d['source']['due_at'] ?? null, $d['source']['tax_treatment'] ?? 'IMMUTABLE_SOURCE_TAX', $d['source']['state_at_generation'] ?? null]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ([$headers, $values] as $row) {
            fputcsv($stream, array_map(fn ($v) => $this->csvCell($v), $row), ',', '"', '');
        }
        if (isset($d['source']['lines'])) {
            fputcsv($stream, [], ',', '"', '');
            fputcsv($stream, $ar ? ['مرجع السطر', 'المبلغ', 'العملة', 'السبب', 'الحالة وقت الإنشاء'] : ['Line reference', 'Amount', 'Currency', 'Reason', 'State at generation'], ',', '"', '');
            foreach ($d['source']['lines'] as $line) {
                $row = [$line['reference'], self::signedMoney($line['amount_minor'], $d['source']['minor_units']), $d['source']['currency'], $line['reason'], $d['source']['state_at_generation']];
                fputcsv($stream, array_map(fn ($v) => $this->csvCell($v), $row), ',', '"', '');
            }
        }
        rewind($stream);
        $result = stream_get_contents($stream);
        fclose($stream);

        return $result;
    }
}

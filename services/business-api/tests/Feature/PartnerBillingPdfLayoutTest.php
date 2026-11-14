<?php

namespace Tests\Feature;

use App\Services\Ocpi\PartnerBillingExport;
use Tests\TestCase;

class PartnerBillingPdfLayoutTest extends TestCase
{
    public function test_both_report_languages_paint_all_expected_lines_inside_the_a4_page(): void
    {
        $row = ['external_id' => 'SYNTHETIC-CDR-251', 'country_code' => 'KW', 'party_id' => 'TST', 'credit' => true, 'currency' => 'KWD',
            'source' => ['status' => 'matched'], 'billing' => ['status' => 'test_invoice_linked',
                'invoice' => ['number' => 'SIM-TEST-ORIGINAL-251', 'currency' => 'KWD', 'minor_units' => 3, 'total_minor' => 251],
                'paid_minor' => 0, 'refunded_minor' => 0, 'unpaid_credited_minor' => 251, 'due_minor' => 0, 'ledger_balanced' => true]];
        foreach (['en', 'ar'] as $locale) {
            $pdf = (new PartnerBillingExport)->pdf([$row], 7, ['locale' => $locale, 'currency' => 'KWD', 'kind' => 'credits']);
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            $positions = [];
            foreach ($streams[1] as $stream) {
                $decoded = @gzuncompress($stream);
                $content = is_string($decoded) ? $decoded : $stream;
                preg_match_all('/BT [^\r\n]*? (-?[0-9]+\.[0-9]+) (-?[0-9]+\.[0-9]+) Td [\s\S]*? Tj ET/', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $positions[] = [(float) $match[1], (float) $match[2]];
                }
            }
            // A valid header alone did not detect the previous blank Arabic
            // report. Count real painted text and reject off-page placement.
            $this->assertGreaterThanOrEqual(20, count($positions), $locale.': expected title, notices, context and all 17 record fields');
            foreach ($positions as [$x, $y]) {
                $this->assertGreaterThanOrEqual(0, $x, $locale.': text starts left of the page');
                $this->assertLessThan(595.3, $x, $locale.': text starts beyond the A4 right edge');
                $this->assertGreaterThanOrEqual(0, $y, $locale.': text starts below the page');
                $this->assertLessThan(841.9, $y, $locale.': text starts above the A4 top edge');
            }
        }
    }
}
